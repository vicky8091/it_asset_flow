<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_login();

$email = trim($_GET['email'] ?? '');
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('Invalid user.', 'danger');
    redirect('users.php');
}

$st = db()->prepare('SELECT *, (SELECT COUNT(*) FROM assign_items ii WHERE ii.assign_id = assigns.id) items_total,
        (SELECT COUNT(*) FROM assign_items ii WHERE ii.assign_id = assigns.id AND ii.return_status <> \'Pending\') items_done,
        (SELECT MAX(return_date) FROM assign_items ii WHERE ii.assign_id = assigns.id) last_return,
        (SELECT GROUP_CONCAT(DISTINCT ii.item_name SEPARATOR \', \') FROM assign_items ii WHERE ii.assign_id = assigns.id) items
     FROM assigns WHERE user_email = ? ORDER BY id DESC');
$st->execute([$email]);
$assigns = $st->fetchAll();

$st = db()->prepare("SELECT return_status, COUNT(*) c FROM assign_items ii JOIN assigns i ON i.id = ii.assign_id
                     WHERE i.user_email = ? GROUP BY return_status");
$st->execute([$email]);
$itemCounts = $st->fetchAll(PDO::FETCH_KEY_PAIR);
$name = $assigns[0]['user_name'] ?? $email;

layout_start($name, 'users', $email, 'person-circle');
?>
<div class="row g-3 mb-4">
  <?php
  $cards = [
    ['Total Assignments', count($assigns), 'clipboard-check', 'blue'],
    ['Active Assignments', count(array_filter($assigns, fn($i) => $i['status'] !== 'Returned')), 'arrow-up-right-circle', 'orange'],
    ['Items Returned', (int)($itemCounts['Returned'] ?? 0), 'check2-circle', 'green'],
    ['Items Missing', (int)($itemCounts['Missing'] ?? 0), 'question-circle', 'purple'],
    ['Items Damaged', (int)($itemCounts['Damaged'] ?? 0), 'hammer', 'red'],
  ];
  foreach ($cards as [$label, $num, $icon, $color]): ?>
    <div class="col-6 col-md">
      <div class="card stat-card h-100"><div class="card-body">
        <span class="tile tile-<?= $color ?>"><i class="bi bi-<?= $icon ?>"></i></span>
        <div class="mt-2">
          <div class="num"><?= $num ?></div>
          <div class="lbl"><?= $label ?></div>
        </div>
      </div></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center">
    <span><i class="bi bi-clock-history text-primary"></i> Transaction History</span>
    <a class="btn btn-sm btn-light ms-auto no-print" href="users.php"><i class="bi bi-arrow-left"></i> All Users</a>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead>
        <tr><th>Assign ID</th><th>Project</th><th>Equipment</th><th>Assign Date</th><th>Expected Return</th><th>Returned</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$assigns): ?>
          <tr><td colspan="8" class="empty"><i class="bi bi-inbox"></i>No assignments for this user.</td></tr>
        <?php endif; ?>
        <?php foreach ($assigns as $i): $overdue = $i['status'] !== 'Returned' && $i['expected_return'] < date('Y-m-d'); ?>
          <tr>
            <td style="font-weight:700"><?= h($i['assign_no']) ?></td>
            <td><span class="badge bg-light border"><?= h($i['project_code']) ?></span></td>
            <td class="small" style="max-width:260px">
              <span class="d-inline-block text-truncate w-100" title="<?= h($i['items']) ?>"><?= h($i['items'] ?: '—') ?></span>
              <span class="text-muted"><?= h($i['items_done']) ?>/<?= h($i['items_total']) ?> returned</span>
            </td>
            <td class="small text-nowrap"><?= h(date('d M Y', strtotime($i['assign_date']))) ?></td>
            <td class="small text-nowrap <?= $overdue ? 'text-danger fw-bold' : '' ?>"><?= h(date('d M Y', strtotime($i['expected_return']))) ?></td>
            <td class="small text-nowrap"><?= $i['last_return'] ? h(date('d M Y', strtotime($i['last_return']))) : '—' ?></td>
            <td><?= badge($i['status']) ?> <?= badge($i['ack_status']) ?></td>
            <td><a class="btn btn-sm btn-outline-primary" href="assign_view.php?id=<?= $i['id'] ?>"><i class="bi bi-eye"></i> View</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php layout_end(); ?>
