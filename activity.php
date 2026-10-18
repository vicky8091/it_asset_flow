<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_login();

const GROUPS = [
    '' => ['All Activity', ''],
    'email' => ['Emails', "action LIKE 'email%' OR action = 'due_soon_sent' OR action = 'warranty_digest'"],
    'login' => ['Logins', "action IN ('login','login_failed','logout','password_changed')"],
    'equipment' => ['Equipment', "action IN ('equipment_assigned','equipment_returned','equipment_damaged','equipment_missing','equipment_found','equipment_repaired','ack_sent','reminder_sent','due_soon_sent','acknowledgement_confirmed')"],
    'inventory' => ['Inventory', "action LIKE 'asset%' OR action = 'inventory_imported' OR action LIKE 'category%' OR action LIKE 'location%' OR action = 'icon_uploaded' OR action = 'icon_removed' OR action = 'warranty_digest'"],
];
$group = $_GET['group'] ?? '';
if (!isset(GROUPS[$group])) $group = '';

$sql = 'SELECT * FROM activity_log';
if (GROUPS[$group][1] !== '') $sql .= ' WHERE ' . GROUPS[$group][1];
$sql .= ' ORDER BY id DESC';
[$logs, $page, $pages, $total] = paginate($sql, [], 20);

layout_start('Logs', 'logs', 'Audit trail of all activity and emails.', 'clock-history');
?>
<div class="card">
  <div class="card-header bg-white d-flex flex-wrap gap-2 align-items-center">
    <strong><i class="bi bi-clock-history"></i> Activity &amp; Email Logs</strong>
    <div class="d-flex gap-2 ms-auto">
      <?php foreach (GROUPS as $key => [$label]): ?>
        <a class="btn btn-sm btn-<?= $group === $key ? 'primary' : 'outline-secondary' ?>" href="activity.php?group=<?= $key ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light">
        <tr><th style="width:170px">Time</th><th style="width:130px">Admin</th><th style="width:180px">Action</th><th>Details</th><th style="width:130px">IP</th></tr>
      </thead>
      <tbody>
        <?php if (!$logs): ?>
          <tr><td colspan="5" class="empty"><i class="bi bi-inbox"></i>No log entries.</td></tr>
        <?php endif; ?>
        <?php foreach ($logs as $log): ?>
          <tr>
            <td class="small text-nowrap"><?= h($log['created_at']) ?></td>
            <td style="font-weight:600"><?= h($log['admin']) ?></td>
            <td><span class="badge bg-light border"><?= h($log['action']) ?></span></td>
            <td class="small"><?= h($log['details']) ?></td>
            <td class="small text-muted"><?= h($log['ip']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pager_nav($page, $pages, $total, 20, count($logs)) ?>
</div>
<?php layout_end(); ?>
