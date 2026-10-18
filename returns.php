<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_login();

$STATUSES = ['Returned', 'Damaged', 'Missing'];
$status = $_GET['status'] ?? '';
if (!in_array($status, $STATUSES, true)) $status = '';

$q = trim($_GET['q'] ?? '');
$where = ["ii.return_status <> 'Pending'"];
$params = [];
if ($status !== '') {
    $where[] = 'ii.return_status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where[] = '(ii.item_name LIKE ? OR ii.asset_number LIKE ? OR i.user_name LIKE ? OR i.user_email LIKE ? OR i.project_code LIKE ? OR i.assign_no LIKE ?)';
    $like = "%$q%";
    $params = array_fill(0, 6, $like);
}

$sql = 'SELECT ii.*, i.assign_no, i.user_name, i.user_email, i.project_code, i.assign_date, i.expected_return
        FROM assign_items ii JOIN assigns i ON i.id = ii.assign_id
        WHERE ' . implode(' AND ', $where) . ' ORDER BY ii.return_date DESC, ii.id DESC';
[$rows, $page, $pages, $total] = paginate($sql, $params, 15);

$counts = db()->query("SELECT return_status, COUNT(*) c FROM assign_items WHERE return_status <> 'Pending' GROUP BY return_status")->fetchAll(PDO::FETCH_KEY_PAIR);
$allCount = (int)array_sum($counts);

layout_start('Return History', 'returns', 'Every item that has come back — returned, damaged or missing.', 'arrow-return-left', true, 'Search assets, serial number…');
?>
<form class="card mb-4 filter-bar" method="get">
  <div class="card-body">
    <div class="row g-3 align-items-end">
      <div class="col-md-6">
        <label class="f-label">Search</label>
        <div class="position-relative">
          <i class="bi bi-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:13px;"></i>
          <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Search by item, asset ID, employee, project…" style="padding-left:34px">
        </div>
      </div>
      <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Apply</button></div>
      <div class="col-md-4 text-md-end text-muted small">Showing <?= $total ?> resolved item(s)</div>
    </div>
  </div>
</form>

<div class="card">
  <div class="card-body py-2 border-bottom">
    <div class="d-flex flex-wrap gap-2">
      <a class="chip <?= $status === '' ? 'active' : '' ?>" href="returns.php<?= $q !== '' ? '?q=' . urlencode($q) : '' ?>">All <span class="n"><?= $allCount ?></span></a>
      <?php foreach ($STATUSES as $s): ?>
        <a class="chip <?= $status === $s ? 'active' : '' ?>" href="returns.php?status=<?= $s ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">
          <?= $s ?> <span class="n"><?= (int)($counts[$s] ?? 0) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead>
        <tr><th>#</th><th>Equipment</th><th>Asset ID</th><th>Assigned To</th><th>Project</th><th>Assign Date</th><th>Resolved On</th><th>Status</th><th>Condition / Remarks</th><th class="no-print">Actions</th></tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="10" class="empty"><i class="bi bi-inbox"></i>No return records found.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $n => $r): ?>
          <tr>
            <td class="text-muted"><?= ($page - 1) * 15 + $n + 1 ?></td>
            <td>
              <div class="entity">
                <?= asset_thumb('', $r['item_name']) ?>
                <div><div class="t"><?= h($r['item_name']) ?></div><div class="s"><?= h($r['assign_no']) ?></div></div>
              </div>
            </td>
            <td><a href="asset.php?id=<?= (int)$r['inventory_id'] ?>" style="font-weight:600"><?= h($r['asset_number']) ?></a></td>
            <td>
              <div class="entity">
                <?= avatar($r['user_name']) ?>
                <div><div class="t"><?= h($r['user_name']) ?></div><div class="s"><?= h($r['user_email']) ?></div></div>
              </div>
            </td>
            <td><span class="badge bg-light border"><?= h($r['project_code']) ?></span></td>
            <td class="small text-nowrap"><?= h(date('d M Y', strtotime($r['assign_date']))) ?></td>
            <td class="small text-nowrap"><?= $r['return_date'] ? h(date('d M Y', strtotime($r['return_date']))) : '—' ?></td>
            <td><?= badge($r['return_status']) ?></td>
            <td class="small"><?= h(trim(($r['return_condition'] ?: '') . ' ' . ($r['return_remarks'] ?: '')) ?: '—') ?></td>
            <td class="no-print">
              <a class="btn btn-sm btn-outline-primary icon-btn" href="assign_view.php?id=<?= (int)$r['assign_id'] ?>" title="View assignment"><i class="bi bi-eye"></i></a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pager_nav($page, $pages, $total, 15, count($rows)) ?>
</div>
<?php layout_end(); ?>
