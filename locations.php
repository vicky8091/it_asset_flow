<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $name = trim($_POST['name'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        if ($name === '' || mb_strlen($name) > 100) {
            flash('Location name is required (max 100 characters).', 'danger');
        } elseif ($action === 'add') {
            try {
                db()->prepare('INSERT INTO locations (name, notes) VALUES (?,?)')->execute([$name, $notes]);
                log_action('location_added', $name);
                flash("Location \"$name\" added.");
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    flash("Location \"$name\" already exists.", 'warning');
                } else {
                    throw $e;
                }
            }
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $st = db()->prepare('SELECT name FROM locations WHERE id = ?');
            $st->execute([$id]);
            $old = (string)$st->fetchColumn();
            if (!$old) {
                flash('Location not found.', 'danger');
            } elseif ($old !== $name) {
                try {
                    // Rename propagates to the assignments that reference the location by name.
                    db()->prepare('UPDATE locations SET name = ?, notes = ? WHERE id = ?')->execute([$name, $notes, $id]);
                    db()->prepare('UPDATE assigns SET location = ? WHERE location = ?')->execute([$name, $old]);
                    log_action('location_edited', "$old → $name");
                    flash("Location renamed to \"$name\" — its assignments were updated too.");
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        flash("Location \"$name\" already exists.", 'warning');
                    } else {
                        throw $e;
                    }
                }
            } else {
                db()->prepare('UPDATE locations SET notes = ? WHERE id = ?')->execute([$notes, $id]);
                log_action('location_edited', $name);
                flash('Location updated.');
            }
        }
        redirect('locations.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT name FROM locations WHERE id = ?');
        $st->execute([$id]);
        $name = (string)$st->fetchColumn();
        if (!$name) {
            flash('Location not found.', 'danger');
        } else {
            // Past assignments keep the name as history text; only the managed entry goes away.
            $used = db()->prepare('SELECT COUNT(*) FROM assigns WHERE location = ?');
            $used->execute([$name]);
            $usedTotal = (int)$used->fetchColumn();
            db()->prepare('DELETE FROM locations WHERE id = ?')->execute([$id]);
            log_action('location_deleted', $name . ($usedTotal ? " (still referenced by $usedTotal assignment(s) as history)" : ''));
            flash("Location \"$name\" deleted." . ($usedTotal ? " Past assignments still show it as free text." : ''));
        }
        redirect('locations.php');
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($editId) {
    $st = db()->prepare('SELECT * FROM locations WHERE id = ?');
    $st->execute([$editId]);
    $edit = $st->fetch() ?: null;
}

$locations = db()->query(
    'SELECT l.id, l.name, l.notes,
            SUM(a.status IN (\'Issued\',\'Partial\')) active, COUNT(a.id) total
     FROM locations l LEFT JOIN assigns a ON a.location = l.name
     GROUP BY l.id, l.name, l.notes
     ORDER BY l.name'
)->fetchAll();

layout_start('Locations', 'locations', 'Manage event and office locations — they appear as suggestions in the assign wizard.', 'geo-alt', true, 'Search assets, users, assignments…');
?>
<div class="row g-3 mb-4">
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-<?= $edit ? 'pencil' : 'plus-circle' ?> text-primary"></i> <?= $edit ? 'Edit Location' : 'Add Location' ?></div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="<?= $edit ? 'edit' : 'add' ?>">
          <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
          <div class="mb-3">
            <label class="form-label">Location Name <span class="req">*</span></label>
            <input class="form-control" name="name" value="<?= h($edit['name'] ?? '') ?>" required maxlength="100" placeholder="e.g. Mumbai Office, Delhi Expo Hall…">
            <?php if ($edit): ?><div class="form-text">Renaming also updates past assignments that point at this location.</div><?php endif; ?>
          </div>
          <div class="mb-3">
            <label class="form-label">Notes</label>
            <input class="form-control" name="notes" value="<?= h($edit['notes'] ?? '') ?>" maxlength="255" placeholder="Optional — address, contact, access notes…">
          </div>
          <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= $edit ? 'Save Changes' : 'Add Location' ?></button>
          <?php if ($edit): ?><a class="btn btn-outline-secondary" href="locations.php">Cancel</a><?php endif; ?>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center">
        <span><i class="bi bi-geo-alt text-primary"></i> Locations (<?= count($locations) ?>)</span>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead><tr><th>Location</th><th>Notes</th><th>Assignments</th><th class="no-print">Actions</th></tr></thead>
          <tbody>
            <?php if (!$locations): ?>
              <tr><td colspan="4" class="empty"><i class="bi bi-inbox"></i>No locations yet — add the venues and offices your equipment travels to.</td></tr>
            <?php endif; ?>
            <?php foreach ($locations as $l): ?>
              <tr>
                <td>
                  <div class="entity">
                    <span class="icon-chip"><i class="bi bi-geo-alt"></i></span>
                    <div class="t"><?= h($l['name']) ?></div>
                  </div>
                </td>
                <td class="small text-muted"><?= h($l['notes'] ?: '—') ?></td>
                <td class="small">
                  <a href="assigns.php?q=<?= urlencode($l['name']) ?>"><?= (int)$l['total'] ?> total</a>
                  <?= $l['active'] ? ' · <span class="text-primary" style="font-weight:600">' . (int)$l['active'] . ' active</span>' : '' ?>
                </td>
                <td class="no-print">
                  <div class="d-flex gap-2">
                    <a class="btn btn-sm btn-outline-secondary icon-btn" href="locations.php?edit=<?= (int)$l['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></a>
                    <form method="post" data-confirm="Delete location '<?= h($l['name']) ?>'?">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                      <button class="btn btn-sm btn-outline-danger icon-btn" title="Delete"><i class="bi bi-trash"></i></button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php layout_end(); ?>
