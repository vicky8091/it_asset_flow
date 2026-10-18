<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_login();

$q = trim($_GET['q'] ?? '');
$limit = 8;

$assets = $users = $assigns = [];
$assetTotal = $userTotal = $assignTotal = 0;
if ($q !== '') {
    $like = "%$q%";

    $st = db()->prepare(
        "SELECT COUNT(*) FROM inventory
         WHERE asset_number LIKE ? OR asset_name LIKE ? OR serial_number LIKE ? OR brand LIKE ? OR model LIKE ? OR category LIKE ?"
    );
    $st->execute(array_fill(0, 6, $like));
    $assetTotal = (int)$st->fetchColumn();
    $st = db()->prepare(
        "SELECT * FROM inventory
         WHERE asset_number LIKE ? OR asset_name LIKE ? OR serial_number LIKE ? OR brand LIKE ? OR model LIKE ? OR category LIKE ?
         ORDER BY asset_number LIMIT $limit"
    );
    $st->execute(array_fill(0, 6, $like));
    $assets = $st->fetchAll();

    $st = db()->prepare(
        "SELECT COUNT(*) FROM users WHERE name LIKE ? OR email LIKE ? OR employee_id LIKE ? OR department LIKE ? OR phone LIKE ?"
    );
    $st->execute(array_fill(0, 5, $like));
    $userTotal = (int)$st->fetchColumn();
    $st = db()->prepare(
        "SELECT * FROM users WHERE name LIKE ? OR email LIKE ? OR employee_id LIKE ? OR department LIKE ? OR phone LIKE ?
         ORDER BY name LIMIT $limit"
    );
    $st->execute(array_fill(0, 5, $like));
    $users = $st->fetchAll();

    $st = db()->prepare(
        "SELECT COUNT(*) FROM assigns
         WHERE assign_no LIKE ? OR user_name LIKE ? OR user_email LIKE ? OR project_code LIKE ? OR event_name LIKE ? OR location LIKE ?"
    );
    $st->execute(array_fill(0, 6, $like));
    $assignTotal = (int)$st->fetchColumn();
    $st = db()->prepare(
        "SELECT a.*, (SELECT COUNT(*) FROM assign_items ii WHERE ii.assign_id = a.id) item_count
         FROM assigns a
         WHERE a.assign_no LIKE ? OR a.user_name LIKE ? OR a.user_email LIKE ? OR a.project_code LIKE ? OR a.event_name LIKE ? OR a.location LIKE ?
         ORDER BY a.assign_date DESC LIMIT $limit"
    );
    $st->execute(array_fill(0, 6, $like));
    $assigns = $st->fetchAll();
}

layout_start('Search', '', $q !== '' ? "Results for \"$q\"" : '', 'search', true, 'Search assets, users, assignments…');
?>
<?php if ($q === ''): ?>
  <div class="card"><div class="card-body empty">
    <i class="bi bi-search"></i>
    <div style="font-weight:700;">Type something to search</div>
    <div class="small mt-1">Use the top search bar (Ctrl + K) — it looks through assets, users and assignments at once.</div>
  </div></div>
<?php else: ?>
  <?php $nothing = !$assetTotal && !$userTotal && !$assignTotal; ?>
  <?php if ($nothing): ?>
    <div class="card"><div class="card-body empty">
      <i class="bi bi-emoji-neutral"></i>
      <div style="font-weight:700;">Nothing found for "<?= h($q) ?>"</div>
      <div class="small mt-1">Try an asset number, serial number, user name, email, project/event code or location.</div>
    </div></div>
  <?php endif; ?>

  <?php if ($assets): ?>
    <div class="card mb-4">
      <div class="card-header d-flex align-items-center">
        <span><i class="bi bi-box-seam text-primary"></i> Assets (<?= $assetTotal ?>)</span>
        <?php if ($assetTotal > $limit): ?><a class="btn btn-sm btn-light ms-auto" href="inventory.php?q=<?= urlencode($q) ?>">View all in Inventory</a><?php endif; ?>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <tbody>
            <?php foreach ($assets as $a): ?>
              <tr>
                <td style="width:56px"><?= asset_media($a) ?></td>
                <td style="font-weight:700;white-space:nowrap"><?= h($a['asset_number']) ?></td>
                <td><?= h($a['asset_name']) ?><div class="small text-muted"><?= h($a['category']) ?><?= $a['brand'] ? ' · ' . h(trim($a['brand'] . ' ' . $a['model'])) : '' ?></div></td>
                <td class="small text-muted"><?= h($a['serial_number'] ?: '—') ?></td>
                <td><?= badge($a['status']) ?></td>
                <td class="no-print text-end"><a class="btn btn-sm btn-outline-primary" href="asset.php?id=<?= (int)$a['id'] ?>">Open</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($users): ?>
    <div class="card mb-4">
      <div class="card-header d-flex align-items-center">
        <span><i class="bi bi-people text-primary"></i> Users (<?= $userTotal ?>)</span>
        <?php if ($userTotal > $limit): ?><a class="btn btn-sm btn-light ms-auto" href="users.php?q=<?= urlencode($q) ?>">View all in Users</a><?php endif; ?>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <tbody>
            <?php foreach ($users as $u): ?>
              <tr>
                <td style="width:56px"><?= avatar($u['name']) ?></td>
                <td><?= h($u['name']) ?><div class="small text-muted"><?= h($u['email']) ?></div></td>
                <td class="small text-muted"><?= h($u['employee_id'] ?: '—') ?> · <?= h($u['department'] ?: '—') ?></td>
                <td><?= badge($u['status']) ?></td>
                <td class="no-print text-end"><a class="btn btn-sm btn-outline-primary" href="user.php?id=<?= (int)$u['id'] ?>">History</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($assigns): ?>
    <div class="card mb-4">
      <div class="card-header d-flex align-items-center">
        <span><i class="bi bi-clipboard-check text-primary"></i> Assignments (<?= $assignTotal ?>)</span>
        <?php if ($assignTotal > $limit): ?><a class="btn btn-sm btn-light ms-auto" href="assigns.php?q=<?= urlencode($q) ?>">View all in Assignments</a><?php endif; ?>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <tbody>
            <?php foreach ($assigns as $as): ?>
              <tr>
                <td style="font-weight:700;white-space:nowrap"><a href="assign_view.php?id=<?= (int)$as['id'] ?>"><?= h($as['assign_no']) ?></a></td>
                <td>
                  <div class="entity">
                    <?= avatar($as['user_name']) ?>
                    <div><div class="t"><?= h($as['user_name']) ?></div><div class="s"><?= h($as['user_email']) ?></div></div>
                  </div>
                </td>
                <td><span class="badge bg-light border"><?= h($as['project_code']) ?></span></td>
                <td class="small text-muted"><?= (int)$as['item_count'] ?> item(s) · <?= h(date('d M Y', strtotime($as['assign_date']))) ?></td>
                <td><?= badge($as['status']) ?></td>
                <td class="no-print text-end"><a class="btn btn-sm btn-outline-primary" href="assign_view.php?id=<?= (int)$as['id'] ?>">Open</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php layout_end(); ?>
