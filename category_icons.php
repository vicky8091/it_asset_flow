<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_login();

const ICON_EXTS = ['png', 'jpg', 'jpeg', 'webp'];
const ICON_DIR = __DIR__ . '/uploads/icons/';

function icon_dir(): void {
    if (!is_dir(ICON_DIR)) mkdir(ICON_DIR, 0775, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (($_POST['action'] ?? '') === 'upload') {
        $category = trim($_POST['category'] ?? '');
        $f = $_FILES['icon'] ?? null;
        $ext = strtolower(pathinfo($f['name'] ?? '', PATHINFO_EXTENSION));

        if ($category === '' || mb_strlen($category) > 100) {
            flash('Enter the category name (max 100 characters).', 'danger');
        } elseif (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            flash($f && $f['error'] === UPLOAD_ERR_INI_SIZE
                ? 'Image exceeds the server upload limit (' . ini_get('upload_max_filesize') . ') — use a smaller file.'
                : 'Upload failed. Please try again.', 'danger');
        } elseif (!in_array($ext, ICON_EXTS, true)) {
            flash('Only PNG, JPG or WebP images are accepted.', 'danger');
        } elseif ($f['size'] > ICON_MAX_BYTES) {
            $max = ICON_MAX_BYTES >= 1048576 ? round(ICON_MAX_BYTES / 1048576) . ' MB' : round(ICON_MAX_BYTES / 1024) . ' KB';
            flash("Icon is too large (max $max).", 'danger');
        } elseif (!(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) || !getimagesize($f['tmp_name'])) {
            flash('That file is not a valid image.', 'danger');
        } else {
            [$w, $h] = getimagesize($f['tmp_name']);
            if ($w > ICON_MAX_DIM || $h > ICON_MAX_DIM) {
                flash("Image is {$w}×{$h}px — the maximum is " . ICON_MAX_DIM . '×' . ICON_MAX_DIM . 'px.', 'danger');
            } else {
                icon_dir();
                // Remove any previous icon for this category, then save the new one.
                foreach (ICON_EXTS as $old) @unlink(ICON_DIR . category_slug($category) . '.' . $old);
                $dest = ICON_DIR . category_slug($category) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
                move_uploaded_file($f['tmp_name'], $dest);
                log_action('icon_uploaded', "$category ({$f['size']} bytes)");
                flash("Icon saved for \"$category\".");
            }
        }
        redirect('category_icons.php');
    }

    if (($_POST['action'] ?? '') === 'remove') {
        foreach (ICON_EXTS as $ext) @unlink(ICON_DIR . category_slug($_POST['category'] ?? '') . '.' . $ext);
        log_action('icon_removed', $_POST['category'] ?? '');
        flash('Icon removed — the default icon is shown again.');
        redirect('category_icons.php');
    }
}

// Categories that exist in inventory or are managed, with item counts.
$categories = db()->query(
    "SELECT c.name category, COUNT(iv.id) n
     FROM categories c LEFT JOIN inventory iv ON iv.category = c.name
     GROUP BY c.name
     UNION ALL
     SELECT DISTINCT category, 0 FROM inventory
     WHERE category <> '' AND category NOT IN (SELECT name FROM categories)
     ORDER BY category"
)->fetchAll();
$catsWithIcons = array_filter($categories, fn($c) => category_icon($c['category']) !== null);

layout_start('Item Icons', 'icons', 'Upload an icon per category — it appears in front of every item of that category.', 'collection', true, 'Search assets, serial number…');
?>
<div class="row g-3 mb-4">
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-upload text-primary"></i> Upload Icon</div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="upload">
          <div class="mb-3">
            <label class="form-label">Category</label>
            <input class="form-control" name="category" list="catList" placeholder="e.g. Laptop, Cable, Headphones…" required maxlength="100">
            <datalist id="catList">
              <?php foreach ($categories as $c): ?><option value="<?= h($c['category']) ?>"><?php endforeach; ?>
            </datalist>
            <div class="form-text">Pick an existing category or type a new one. The icon is saved for the whole category.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Image</label>
            <input class="form-control" type="file" name="icon" accept=".png,.jpg,.jpeg,.webp" required>
            <div class="form-text">PNG, JPG or WebP · max <?= ICON_MAX_BYTES >= 1048576 ? round(ICON_MAX_BYTES / 1048576) . ' MB' : round(ICON_MAX_BYTES / 1024) . ' KB' ?> · up to <?= ICON_MAX_DIM ?>×<?= ICON_MAX_DIM ?>px · square images look best.</div>
          </div>
          <button class="btn btn-primary"><i class="bi bi-check-lg"></i> Save Icon</button>
        </form>
        <?php if (($_SESSION['admin']['role'] ?? '') === 'admin'): ?>
          <hr>
          <a class="small" href="categories.php"><i class="bi bi-tags"></i> Manage categories — rename, colour, delete →</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-stars text-primary"></i> How it appears</div>
      <div class="card-body">
        <p class="text-muted small mb-3">Every item of the category shows its icon in the tables, the assign wizard and the dashboard. Categories without an uploaded icon keep their built-in symbol.</p>
        <div class="d-flex align-items-center gap-3 p-2 rounded mb-2" style="background:#f8fafc;">
          <?= asset_thumb('Laptop', 'Laptop') ?>
          <div><div class="entity"><div><div class="t">Laptop</div><div class="s">any item of this category</div></div></div></div>
          <span class="ms-auto"><?= category_icon('Laptop') ? '<span class="badge bg-success">Custom</span>' : '<span class="badge bg-light border">Default</span>' ?></span>
        </div>
        <div class="d-flex align-items-center gap-3 p-2 rounded" style="background:#f8fafc;">
          <?= asset_thumb('Cable', 'HDMI Cable') ?>
          <div><div class="entity"><div><div class="t">Cable</div><div class="s">any item of this category</div></div></div></div>
          <span class="ms-auto"><?= category_icon('Cable') ? '<span class="badge bg-success">Custom</span>' : '<span class="badge bg-light border">Default</span>' ?></span>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center">
    <span><i class="bi bi-folder2-open text-primary"></i> Categories (<?= count($categories) ?>) — <?= count($catsWithIcons) ?> custom icon(s)</span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead>
        <tr><th>Preview</th><th>Category</th><th>Items</th><th>Icon</th><th class="no-print">Actions</th></tr>
      </thead>
      <tbody>
        <?php if (!$categories): ?>
          <tr><td colspan="5" class="empty"><i class="bi bi-inbox"></i>No categories yet — add assets or import inventory first.</td></tr>
        <?php endif; ?>
        <?php foreach ($categories as $c): $icon = category_icon($c['category']); ?>
          <tr>
            <td style="width:70px"><?= asset_thumb($c['category'], $c['category']) ?></td>
            <td style="font-weight:600"><?= h($c['category']) ?></td>
            <td class="small"><?= (int)$c['n'] ?> item(s)</td>
            <td>
              <?php if ($icon): ?>
                <span class="badge bg-success">Custom</span>
                <span class="small text-muted ms-1"><?= h(str_replace('\\', '/', parse_url($icon, PHP_URL_PATH))) ?></span>
              <?php else: ?>
                <span class="badge bg-light border">Default</span>
              <?php endif; ?>
            </td>
            <td class="no-print">
              <?php if ($icon): ?>
                <form method="post" data-confirm="Remove the custom icon for <?= h($c['category']) ?>? The default symbol comes back.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="remove">
                  <input type="hidden" name="category" value="<?= h($c['category']) ?>">
                  <button class="btn btn-sm btn-outline-danger icon-btn" title="Remove icon"><i class="bi bi-trash"></i></button>
                </form>
              <?php else: ?>
                <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php layout_end(); ?>
