<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_admin();

const ICON_EXTS = ['png', 'jpg', 'jpeg', 'webp'];

// Rename the saved icon files along with the category (they are keyed by the category slug).
function rename_category_icons(string $oldName, string $newName): void {
    $oldSlug = category_slug($oldName);
    $newSlug = category_slug($newName);
    if ($oldSlug === $newSlug) return;
    foreach (ICON_EXTS as $ext) {
        $old = dirname(__DIR__) . '/uploads/icons/' . $oldSlug . '.' . $ext;
        if (is_file($old)) {
            @rename($old, dirname(__DIR__) . '/uploads/icons/' . $newSlug . '.' . $ext);
        }
    }
}

function delete_category_icons(string $name): void {
    foreach (ICON_EXTS as $ext) {
        @unlink(dirname(__DIR__) . '/uploads/icons/' . category_slug($name) . '.' . $ext);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $name = trim($_POST['name'] ?? '');
        $color = trim($_POST['color'] ?? '#64748b');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#64748b';
        if ($name === '' || mb_strlen($name) > 100) {
            flash('Category name is required (max 100 characters).', 'danger');
        } elseif ($action === 'add') {
            try {
                db()->prepare('INSERT INTO categories (name, color) VALUES (?,?)')->execute([$name, $color]);
                log_action('category_added', $name);
                flash("Category \"$name\" added.");
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    flash("Category \"$name\" already exists.", 'warning');
                } else {
                    throw $e;
                }
            }
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $st = db()->prepare('SELECT name FROM categories WHERE id = ?');
            $st->execute([$id]);
            $old = (string)$st->fetchColumn();
            if (!$old) {
                flash('Category not found.', 'danger');
            } elseif ($old !== $name) {
                try {
                    // Rename propagates to the assets, and the icon files follow the slug.
                    db()->prepare('UPDATE categories SET name = ?, color = ? WHERE id = ?')->execute([$name, $color, $id]);
                    db()->prepare('UPDATE inventory SET category = ? WHERE category = ?')->execute([$name, $old]);
                    rename_category_icons($old, $name);
                    log_action('category_edited', "$old → $name");
                    flash("Category renamed to \"$name\" — its assets were updated too.");
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        flash("Category \"$name\" already exists.", 'warning');
                    } else {
                        throw $e;
                    }
                }
            } else {
                db()->prepare('UPDATE categories SET color = ? WHERE id = ?')->execute([$color, $id]);
                log_action('category_edited', $name);
                flash('Category updated.');
            }
        }
        redirect('categories.php' . ($action === 'edit' ? '' : ''));
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT name FROM categories WHERE id = ?');
        $st->execute([$id]);
        $name = (string)$st->fetchColumn();
        if (!$name) {
            flash('Category not found.', 'danger');
        } else {
            $used = db()->prepare('SELECT COUNT(*) FROM inventory WHERE category = ?');
            $used->execute([$name]);
            if ((int)$used->fetchColumn() > 0) {
                flash("Category \"$name\" still has assets — move them to another category first (or delete the assets).", 'warning');
            } else {
                db()->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
                delete_category_icons($name);
                log_action('category_deleted', $name);
                flash("Category \"$name\" deleted.");
            }
        }
        redirect('categories.php');
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($editId) {
    $st = db()->prepare('SELECT * FROM categories WHERE id = ?');
    $st->execute([$editId]);
    $edit = $st->fetch() ?: null;
}

$categories = db()->query(
    'SELECT c.id, c.name, c.color, COUNT(iv.id) items
     FROM categories c LEFT JOIN inventory iv ON iv.category = c.name
     GROUP BY c.id, c.name, c.color
     ORDER BY c.name'
)->fetchAll();
$uncategorised = db()->query(
    "SELECT category name, COUNT(*) items FROM inventory
     WHERE category <> '' AND category NOT IN (SELECT name FROM categories)
     GROUP BY category ORDER BY category"
)->fetchAll();

layout_start('Categories', 'categories', 'Manage asset categories — renaming a category updates every asset in it.', 'tags', true, 'Search assets, users, assignments…');
?>
<div class="row g-3 mb-4">
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-<?= $edit ? 'pencil' : 'plus-circle' ?> text-primary"></i> <?= $edit ? 'Edit Category' : 'Add Category' ?></div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="<?= $edit ? 'edit' : 'add' ?>">
          <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
          <div class="mb-3">
            <label class="form-label">Category Name <span class="req">*</span></label>
            <input class="form-control" name="name" value="<?= h($edit['name'] ?? '') ?>" required maxlength="100" placeholder="e.g. Laptop, Cable, Headphones…">
            <?php if ($edit): ?><div class="form-text">Renaming also renames the category on every asset that uses it.</div><?php endif; ?>
          </div>
          <div class="mb-3">
            <label class="form-label">Colour</label>
            <input type="color" class="form-control form-control-color w-auto" name="color" value="<?= h($edit['color'] ?? '#3b82f6') ?>">
          </div>
          <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= $edit ? 'Save Changes' : 'Add Category' ?></button>
          <?php if ($edit): ?><a class="btn btn-outline-secondary" href="categories.php">Cancel</a><?php endif; ?>
        </form>
        <hr>
        <a class="small" href="category_icons.php"><i class="bi bi-collection"></i> Upload per-category icons on the Item Icons page →</a>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center">
        <span><i class="bi bi-tags text-primary"></i> Categories (<?= count($categories) ?>)</span>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead><tr><th style="width:44px"></th><th>Category</th><th>Assets</th><th>Icon</th><th class="no-print">Actions</th></tr></thead>
          <tbody>
            <?php if (!$categories && !$uncategorised): ?>
              <tr><td colspan="5" class="empty"><i class="bi bi-inbox"></i>No categories yet — add one above or import assets.</td></tr>
            <?php endif; ?>
            <?php foreach ($categories as $c): ?>
              <tr>
                <td><span class="cat-dot" style="background:<?= h($c['color']) ?>"></span></td>
                <td>
                  <div class="entity">
                    <?= asset_thumb($c['name'], $c['name']) ?>
                    <div class="t"><?= h($c['name']) ?></div>
                  </div>
                </td>
                <td class="small"><a href="inventory.php?category=<?= urlencode($c['name']) ?>"><?= (int)$c['items'] ?> asset(s)</a></td>
                <td><?= category_icon($c['name']) ? '<span class="badge bg-success">Custom</span>' : '<span class="badge bg-light border">Default</span>' ?></td>
                <td class="no-print">
                  <div class="d-flex gap-2">
                    <a class="btn btn-sm btn-outline-secondary icon-btn" href="categories.php?edit=<?= (int)$c['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></a>
                    <form method="post" data-confirm="Delete category '<?= h($c['name']) ?>'?<?= (int)$c['items'] ? ' It still has assets — this will be blocked.' : '' ?>">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                      <button class="btn btn-sm btn-outline-danger icon-btn" title="Delete"><i class="bi bi-trash"></i></button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php foreach ($uncategorised as $c): ?>
              <tr class="table-warning">
                <td><span class="cat-dot" style="background:#94a3b8"></span></td>
                <td><div class="entity"><?= asset_thumb($c['name'], $c['name']) ?><div class="t"><?= h($c['name']) ?></div></div></td>
                <td class="small"><a href="inventory.php?category=<?= urlencode($c['name']) ?>"><?= (int)$c['items'] ?> asset(s)</a></td>
                <td><?= category_icon($c['name']) ? '<span class="badge bg-success">Custom</span>' : '<span class="badge bg-light border">Default</span>' ?></td>
                <td class="no-print">
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="name" value="<?= h($c['name']) ?>">
                    <button class="btn btn-sm btn-outline-primary" title="Register this category so it can be renamed and managed"><i class="bi bi-plus-lg"></i> Register</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($uncategorised): ?>
        <div class="card-body border-top small text-muted">
          <i class="bi bi-info-circle"></i> Highlighted rows are categories that exist only on assets (typed manually or imported). Register them to manage them here.
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php layout_end(); ?>
