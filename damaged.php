<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if (!in_array($action, ['mark_repaired', 'mark_retired'], true)) {
        flash('Unknown action.', 'danger');
        redirect('damaged.php');
    }
    $id = (int)$_POST['id'];
    $to = $action === 'mark_repaired' ? 'Available' : 'Retired';
    $st = db()->prepare("SELECT asset_number FROM inventory WHERE id = ? AND status IN ('Damaged','Under Repair')");
    $st->execute([$id]);
    if ($a = $st->fetch()) {
        db()->prepare('UPDATE inventory SET status = ? WHERE id = ?')->execute([$to, $id]);
        log_action($to === 'Available' ? 'equipment_repaired' : 'equipment_retired', $a['asset_number']);
        flash($to === 'Available'
            ? "Asset {$a['asset_number']} marked repaired and back to Available."
            : "Asset {$a['asset_number']} retired.");
    }
    redirect('damaged.php');
}

$q = trim($_GET['q'] ?? '');
$where = '';
$params = [];
if ($q !== '') {
    $where = ' AND (iv.asset_number LIKE ? OR iv.asset_name LIKE ? OR i.user_name LIKE ? OR i.project_code LIKE ?)';
    $like = "%$q%";
    $params = array_fill(0, 4, $like);
}
$st = db()->prepare(
    "SELECT iv.*, ii.return_date damage_date, ii.return_remarks, ii.return_condition, ii.id item_id,
            i.id assign_id, i.assign_no, i.user_name, i.user_email, i.project_code, i.assign_date issued_on
     FROM inventory iv
     LEFT JOIN assign_items ii ON ii.inventory_id = iv.id AND ii.return_status = 'Damaged'
         AND ii.id = (SELECT MAX(ii2.id) FROM assign_items ii2 WHERE ii2.inventory_id = iv.id AND ii2.return_status = 'Damaged')
     LEFT JOIN assigns i ON i.id = ii.assign_id
     WHERE iv.status IN ('Damaged','Under Repair') $where
     ORDER BY iv.updated_at DESC"
);
$st->execute($params);
$rows = $st->fetchAll();

layout_start('Damaged Equipment', 'damaged', 'View and track all equipment that is reported as damaged.', 'hammer');
?>
<form class="card mb-4 filter-bar" method="get">
  <div class="card-body">
    <div class="row g-3 align-items-end">
      <div class="col-md-8">
        <label class="f-label">Search</label>
        <div class="position-relative">
          <i class="bi bi-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:13px;"></i>
          <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Search by employee, equipment, asset ID, project…" style="padding-left:34px">
        </div>
      </div>
      <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Apply</button></div>
      <div class="col-md-2 text-md-end text-muted small">Showing <?= count($rows) ?> damaged record(s)</div>
    </div>
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead>
        <tr><th>#</th><th>Employee</th><th>Equipment</th><th>Asset ID</th><th>Assigned On</th><th>Reported</th><th>Damage Details</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="9" class="empty"><i class="bi bi-emoji-smile"></i>No damaged equipment. 🎉</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $n => $r): ?>
          <tr>
            <td class="text-muted"><?= $n + 1 ?></td>
            <td>
              <?php if ($r['user_name']): ?>
                <div class="entity">
                  <?= avatar($r['user_name']) ?>
                  <div><div class="t"><?= h($r['user_name']) ?></div><div class="s"><?= h($r['user_email']) ?></div></div>
                </div>
              <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
            </td>
            <td>
              <div class="entity">
                <?= asset_thumb($r['category'], $r['asset_name']) ?>
                <div><div class="t"><?= h($r['asset_name']) ?></div><div class="s"><?= h($r['category']) ?></div></div>
              </div>
            </td>
            <td style="font-weight:600"><?= h($r['asset_number']) ?></td>
            <td class="small text-nowrap"><?= $r['issued_on'] ? h(date('d M Y', strtotime($r['issued_on']))) : '—' ?></td>
            <td class="small text-nowrap"><?= $r['damage_date'] ? h(date('d M Y', strtotime($r['damage_date']))) : h(date('d M Y', strtotime($r['updated_at']))) ?></td>
            <td class="small"><?= h(trim(($r['return_condition'] ?: '') . ' ' . ($r['return_remarks'] ?: $r['condition_note'] ?: '')) ?: '—') ?></td>
            <td><?= badge($r['status']) ?></td>
            <td>
              <div class="d-flex gap-2">
                <?php if ($r['assign_id']): ?>
                  <a class="btn btn-sm btn-outline-primary" href="assign_view.php?id=<?= $r['assign_id'] ?>"><i class="bi bi-eye"></i> View</a>
                <?php endif; ?>
                <a class="btn btn-sm btn-outline-secondary icon-btn" href="asset.php?id=<?= $r['id'] ?>" title="History"><i class="bi bi-clock-history"></i></a>
                <form method="post" data-confirm="Mark <?= h($r['asset_number']) ?> as REPAIRED and Available?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="mark_repaired">
                  <input type="hidden" name="id" value="<?= $r['id'] ?>">
                  <button class="btn btn-sm btn-outline-success"><i class="bi bi-wrench-adjustable"></i> Repaired</button>
                </form>
                <form method="post" data-confirm="Retire <?= h($r['asset_number']) ?> permanently?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="mark_retired">
                  <input type="hidden" name="id" value="<?= $r['id'] ?>">
                  <button class="btn btn-sm btn-outline-secondary icon-btn" title="Retire"><i class="bi bi-archive"></i></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php layout_end(); ?>
