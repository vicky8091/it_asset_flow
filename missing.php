<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_found') {
    csrf_check();
    $id = (int)$_POST['id'];
    $st = db()->prepare("SELECT asset_number FROM inventory WHERE id = ? AND status = 'Missing'");
    $st->execute([$id]);
    if ($a = $st->fetch()) {
        db()->prepare("UPDATE inventory SET status = 'Available', condition_note = 'Found — inspect before issuing' WHERE id = ?")->execute([$id]);
        log_action('equipment_found', $a['asset_number']);
        flash("Asset {$a['asset_number']} marked as found. Inspect it before issuing.");
    }
    redirect('missing.php');
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
    "SELECT iv.*, ii.return_date missing_date, ii.return_remarks, ii.id item_id,
            i.id assign_id, i.assign_no, i.user_name, i.user_email, i.project_code, i.assign_date issued_on
     FROM inventory iv
     LEFT JOIN assign_items ii ON ii.inventory_id = iv.id AND ii.return_status = 'Missing'
         AND ii.id = (SELECT MAX(ii2.id) FROM assign_items ii2 WHERE ii2.inventory_id = iv.id AND ii2.return_status = 'Missing')
     LEFT JOIN assigns i ON i.id = ii.assign_id
     WHERE iv.status = 'Missing' $where
     ORDER BY COALESCE(ii.return_date, iv.updated_at) DESC"
);
$st->execute($params);
$rows = $st->fetchAll();

layout_start('Missing Equipment', 'missing', 'View and track all equipment that is reported missing.', 'question-circle');
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
      <div class="col-md-2 text-md-end text-muted small">Showing <?= count($rows) ?> missing record(s)</div>
    </div>
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead>
        <tr><th>#</th><th>Employee</th><th>Equipment</th><th>Asset ID</th><th>Assigned On</th><th>Missing Since</th><th>Project</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="9" class="empty"><i class="bi bi-emoji-smile"></i>No missing equipment. 🎉</td></tr>
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
              <?php else: ?><span class="text-muted small">— (no assignment record)</span><?php endif; ?>
            </td>
            <td>
              <div class="entity">
                <?= asset_thumb($r['category'], $r['asset_name']) ?>
                <div><div class="t"><?= h($r['asset_name']) ?></div><div class="s"><?= h($r['category']) ?></div></div>
              </div>
            </td>
            <td style="font-weight:600"><?= h($r['asset_number']) ?></td>
            <td class="small text-nowrap"><?= $r['issued_on'] ? h(date('d M Y', strtotime($r['issued_on']))) : '—' ?></td>
            <td class="small text-nowrap"><?= h(date('d M Y', strtotime($r['missing_date'] ?: $r['updated_at']))) ?></td>
            <td><?= $r['project_code'] ? '<span class="badge bg-light border">' . h($r['project_code']) . '</span>' : '<span class="text-muted small">—</span>' ?></td>
            <td><?= badge('Missing') ?></td>
            <td>
              <div class="d-flex gap-2">
                <?php if ($r['assign_id']): ?>
                  <a class="btn btn-sm btn-outline-primary" href="assign_view.php?id=<?= $r['assign_id'] ?>"><i class="bi bi-eye"></i> View</a>
                <?php endif; ?>
                <a class="btn btn-sm btn-outline-secondary icon-btn" href="asset.php?id=<?= $r['id'] ?>" title="History"><i class="bi bi-clock-history"></i></a>
                <form method="post" data-confirm="Mark <?= h($r['asset_number']) ?> as FOUND and Available? Inspect it before issuing again.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="mark_found">
                  <input type="hidden" name="id" value="<?= $r['id'] ?>">
                  <button class="btn btn-sm btn-outline-success" title="Mark found"><i class="bi bi-check2-circle"></i> Found</button>
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
