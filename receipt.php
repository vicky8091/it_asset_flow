<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT * FROM assigns WHERE id = ?');
$st->execute([$id]);
$assign = $st->fetch();
if (!$assign) redirect('assigns.php');
$st = db()->prepare('SELECT * FROM assign_items WHERE assign_id = ? ORDER BY id');
$st->execute([$id]);
$items = $st->fetchAll();

// The receipt date is when everything was back — the LATEST return among the items (today if none recorded).
$returnDate = '';
foreach ($items as $it) {
    if ($it['return_date'] && $it['return_date'] > $returnDate) {
        $returnDate = $it['return_date'];
    }
}
$returnDate = $returnDate ? date('d M Y', strtotime($returnDate)) : date('d M Y');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Return Receipt <?= h($assign['assign_no']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body { background: #f5f7fa; }
  .receipt { max-width: 720px; margin: 24px auto; background: #fff; border: 1px solid #dee2e6; padding: 40px; }
  @media print { body { background: #fff; } .no-print { display: none; } .receipt { border: none; margin: 0; padding: 0; } }
</style>
</head>
<body>
<div class="receipt">
  <div class="text-center border-bottom pb-3 mb-4">
    <h5 class="mb-0"><?= h(get_setting('company_name', APP_NAME)) ?></h5>
    <div class="text-muted">IT EQUIPMENT RETURN RECEIPT</div>
  </div>

  <table class="table table-sm table-borderless mb-4">
    <tr><td class="text-muted" style="width:30%">Assign ID</td><td><strong><?= h($assign['assign_no']) ?></strong></td></tr>
    <tr><td class="text-muted">User</td><td><?= h($assign['user_name']) ?> (<?= h($assign['user_email']) ?>)</td></tr>
    <tr><td class="text-muted">Project / Event</td><td><?= h($assign['project_code']) ?><?= $assign['event_name'] ? ' — ' . h($assign['event_name']) : '' ?></td></tr>
    <tr><td class="text-muted">Location</td><td><?= h($assign['location'] ?: '—') ?></td></tr>
    <tr><td class="text-muted">Assign Date</td><td><?= h(date('d M Y', strtotime($assign['assign_date']))) ?></td></tr>
  </table>

  <table class="table table-bordered table-sm">
    <thead class="table-light">
      <tr><th>Equipment</th><th>Asset Number</th><th>Serial Number</th><th>Status</th><th>Condition</th></tr>
    </thead>
    <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?= h($it['item_name']) ?></td>
          <td><?= h($it['asset_number']) ?></td>
          <td><?= h($it['serial_number']) ?></td>
          <td><?= h($it['return_status']) ?></td>
          <td><?= h($it['return_condition'] ?: '—') ?><?= $it['return_remarks'] ? ' — ' . h($it['return_remarks']) : '' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <table class="table table-sm table-borderless mb-4">
    <tr><td class="text-muted" style="width:30%">Return Date</td><td><?= h($returnDate) ?></td></tr>
    <tr><td class="text-muted">IT Support</td><td><?= h($assign['created_by']) ?></td></tr>
  </table>

  <div class="row text-center small text-muted">
    <div class="col-6">____________________________<br>Received by (User)</div>
    <div class="col-6">____________________________<br>IT Support</div>
  </div>
</div>

<div class="text-center mb-4 no-print">
  <button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print Return Receipt</button>
  <a class="btn btn-outline-secondary" href="assign_view.php?id=<?= $id ?>">Back</a>
</div>
</body>
</html>
