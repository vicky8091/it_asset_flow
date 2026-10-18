<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_login();

$counts = db()->query('SELECT status, COUNT(*) c FROM inventory GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$c = fn(string $s): int => (int)($counts[$s] ?? 0);
$total = array_sum($counts);

$overdueItems = db()->query(
    "SELECT ii.id item_id, ii.item_name, ii.asset_number, i.id assign_id, i.assign_no, i.user_name, i.user_email,
            i.project_code, i.expected_return
     FROM assign_items ii JOIN assigns i ON i.id = ii.assign_id
     WHERE ii.return_status = 'Pending' AND i.expected_return < CURDATE()
     ORDER BY i.expected_return"
)->fetchAll();

// Assets still out on loan that are overdue — the donut splits Assigned into "not overdue" + "overdue"
// so nothing is counted twice.
$overdueAssets = (int)db()->query(
    "SELECT COUNT(DISTINCT ii.inventory_id)
     FROM assign_items ii JOIN assigns i ON i.id = ii.assign_id JOIN inventory iv ON iv.id = ii.inventory_id
     WHERE ii.return_status = 'Pending' AND i.expected_return < CURDATE() AND iv.status = 'Issued'"
)->fetchColumn();

$dueToday = (int)db()->query(
    "SELECT COUNT(*) FROM assign_items ii JOIN assigns i ON i.id = ii.assign_id
     WHERE ii.return_status = 'Pending' AND i.expected_return = CURDATE()"
)->fetchColumn();
$unacked = (int)db()->query("SELECT COUNT(*) FROM assigns WHERE ack_status <> 'Acknowledged'")->fetchColumn();
$recent = db()->query('SELECT * FROM activity_log ORDER BY id DESC LIMIT 7')->fetchAll();

// Financial / upcoming-deadline overview lines.
$invValue = (float)db()->query('SELECT COALESCE(SUM(cost), 0) FROM inventory')->fetchColumn();
$dueSoonDays = max(0, (int)get_setting('due_soon_days', '3'));
$dueSoon = (int)db()->query(
    "SELECT COUNT(*) FROM assign_items ii JOIN assigns i ON i.id = ii.assign_id
     WHERE ii.return_status = 'Pending' AND i.expected_return >= CURDATE()
       AND i.expected_return <= DATE_ADD(CURDATE(), INTERVAL $dueSoonDays DAY)"
)->fetchColumn();
$warrDays = max(0, (int)get_setting('warranty_alert_days', '30'));
$warrSoon = $warrDays > 0 ? (int)db()->query(
    "SELECT COUNT(*) FROM inventory WHERE warranty_expiry IS NOT NULL AND warranty_expiry <= DATE_ADD(CURDATE(), INTERVAL $warrDays DAY)"
)->fetchColumn() : 0;

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
$adminName = $_SESSION['admin']['name'] ?: $_SESSION['admin']['username'];

// Donut segments (status -> hex), built from real counts.
$segSpec = [
    ['Available', '#22c55e'], ['Assigned', '#3b82f6'], ['Overdue', '#ef4444'],
    ['Missing', '#0f172a'], ['Damaged', '#f59e0b'],
];
$segValues = [
    'Available' => $c('Available'),
    'Assigned' => max(0, $c('Issued') - $overdueAssets),
    'Overdue' => $overdueAssets,
    'Missing' => $c('Missing'),
    'Damaged' => $c('Damaged') + $c('Under Repair'),
];
$grad = [];
$acc = 0;
foreach ($segSpec as [$name, $hex]) {
    $v = $segValues[$name];
    if (!$v) continue;
    $from = round($acc / max($total, 1) * 360, 1);
    $acc += $v;
    $to = round($acc / max($total, 1) * 360, 1);
    $grad[] = "$hex $from% $to%";
}
$donutStyle = 'background: conic-gradient(' . implode(', ', $grad) . ');';

layout_start('Dashboard', 'dashboard', '', '', false);
?>
<?php if (get_setting('mail_host') === ''): ?>
  <div class="alert alert-warning no-print">
    <i class="bi bi-exclamation-triangle"></i> SMTP is not configured — emails fall back to PHP <code>mail()</code>.
    Configure it under <a href="settings.php">Settings</a>.
  </div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-xl-8">
    <div class="hero">
      <img class="hero-art" src="assets/img/hero-illustration.svg" alt="" aria-hidden="true">
      <span class="wave">👋</span>
      <div class="flex-grow-1">
        <h2><?= $greeting ?>,<br><?= h($adminName) ?></h2>
        <div class="sub">Here's what's happening with your assets today.</div>
      </div>
      <a class="btn btn-primary ms-auto no-print" href="assign.php" style="box-shadow:0 6px 16px rgba(37,99,235,.3);">
        <i class="bi bi-box-arrow-up"></i> Assign Equipment
      </a>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="card h-100"><div class="card-body d-flex align-items-center gap-3">
      <span class="page-icon mb-0" style="width:46px;height:46px;font-size:20px;"><i class="bi bi-calendar3"></i></span>
      <div>
        <div style="font-weight:800;font-size:16px;"><?= date('d M Y') ?></div>
        <div class="text-muted small"><?= date('H:i') ?> · <span class="text-success">● System Online</span></div>
      </div>
    </div></div>
  </div>
</div>

<?php if ($overdueItems): ?>
  <div class="alert alert-danger no-print">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <strong><?= count($overdueItems) ?> equipment item(s) overdue.</strong> See the table below or <a href="assigns.php?status=overdue">open the overdue list</a>.
  </div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <?php
  $cards = [
    ['Total Inventory', $total, 'box-seam', 'blue', 'inventory.php', ''],
    ['Available', $c('Available'), 'check-circle', 'green', 'inventory.php?status=Available', '+0%'],
    ['Assigned', $c('Issued'), 'arrow-up-right-circle', 'orange', 'assigns.php?status=active', ''],
    ['Overdue', count($overdueItems), 'exclamation-triangle', 'red', 'assigns.php?status=overdue', ''],
    ['Missing', $c('Missing'), 'question-circle', 'purple', 'missing.php', ''],
    ['Damaged', $c('Damaged') + $c('Under Repair'), 'hammer', 'slate', 'damaged.php', ''],
  ];
  foreach ($cards as [$label, $num, $icon, $color, $href, $trend]): ?>
    <div class="col-6 col-md-4 col-xl-2">
      <div class="card stat-card h-100">
        <div class="card-body">
          <div class="d-flex align-items-center gap-3">
            <span class="tile tile-<?= $color ?>"><i class="bi bi-<?= $icon ?>"></i></span>
            <div>
              <div class="lbl"><?= $label ?></div>
              <div class="num"><?= $num ?></div>
            </div>
          </div>
          <a class="more text-primary" href="<?= $href ?>">View All <i class="bi bi-arrow-right"></i></a>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card mb-4">
  <div class="card-header d-flex align-items-center flex-wrap gap-2">
    <span><i class="bi bi-exclamation-triangle text-danger"></i> Overdue Equipment</span>
    <div class="ms-auto d-flex gap-2 no-print">
      <a class="btn btn-sm btn-light" href="assigns.php?status=overdue">View All</a>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead>
        <tr><th>#</th><th>Employee</th><th>Project</th><th>Equipment</th><th>Asset #</th><th>Expected Return</th><th>Overdue</th><th class="no-print">Actions</th></tr>
      </thead>
      <tbody>
        <?php if (!$overdueItems): ?>
          <tr><td colspan="8" class="empty"><i class="bi bi-emoji-smile"></i>Nothing overdue. 🎉</td></tr>
        <?php endif; ?>
        <?php foreach ($overdueItems as $n => $r): ?>
          <tr>
            <td class="text-muted"><?= $n + 1 ?></td>
            <td>
              <div class="entity">
                <?= avatar($r['user_name']) ?>
                <div><div class="t"><?= h($r['user_name']) ?></div><div class="s"><?= h($r['user_email']) ?></div></div>
              </div>
            </td>
            <td><span class="badge bg-light border"><?= h($r['project_code']) ?></span></td>
            <td>
              <div class="entity"><?= asset_thumb('', $r['item_name']) ?>
                <div class="t"><?= h($r['item_name']) ?></div>
              </div>
            </td>
            <td class="small nw" style="font-weight:600"><?= h($r['asset_number']) ?></td>
            <td class="text-danger" style="font-weight:600"><?= h(date('d M Y', strtotime($r['expected_return']))) ?></td>
            <td><span class="badge bg-danger"><?= days_overdue($r['expected_return']) ?> days</span></td>
            <td class="no-print">
              <div class="d-flex gap-2 align-items-center">
                <a class="btn btn-sm btn-outline-primary icon-btn" href="assign_view.php?id=<?= $r['assign_id'] ?>" title="View"><i class="bi bi-eye"></i></a>
                <form method="post" action="assign_view.php">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="send_reminder">
                  <input type="hidden" name="assign_id" value="<?= $r['assign_id'] ?>">
                  <input type="hidden" name="back" value="dashboard.php">
                  <button class="btn btn-sm btn-outline-warning icon-btn" title="Send reminder email"><i class="bi bi-bell"></i></button>
                </form>
                <form method="post" action="assign_view.php" data-confirm="Mark '<?= h($r['item_name']) ?> (<?= h($r['asset_number']) ?>)' as returned in good condition?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="return_item">
                  <input type="hidden" name="assign_id" value="<?= $r['assign_id'] ?>">
                  <input type="hidden" name="item_id" value="<?= $r['item_id'] ?>">
                  <input type="hidden" name="return_status" value="Returned">
                  <input type="hidden" name="return_condition" value="Good">
                  <input type="hidden" name="return_date" value="<?= date('Y-m-d') ?>">
                  <input type="hidden" name="back" value="dashboard.php">
                  <button class="btn btn-sm btn-outline-success icon-btn" title="Mark returned"><i class="bi bi-check2"></i></button>
                </form>
                <form method="post" action="assign_view.php" data-confirm="Mark '<?= h($r['item_name']) ?> (<?= h($r['asset_number']) ?>)' as MISSING?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="return_item">
                  <input type="hidden" name="assign_id" value="<?= $r['assign_id'] ?>">
                  <input type="hidden" name="item_id" value="<?= $r['item_id'] ?>">
                  <input type="hidden" name="return_status" value="Missing">
                  <input type="hidden" name="back" value="dashboard.php">
                  <button class="btn btn-sm btn-outline-danger icon-btn" title="Mark missing"><i class="bi bi-question-lg"></i></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-box-seam text-primary"></i> Inventory Overview</div>
      <div class="card-body">
        <?php if ($total === 0): ?>
          <div class="empty">
            <i class="bi bi-box-seam"></i>
            <div style="font-weight:700;">No inventory yet</div>
            <div class="small mb-3">Add your first assets or import your existing list to see the overview here.</div>
            <a class="btn btn-sm btn-primary" href="inventory.php?add=1"><i class="bi bi-plus-lg"></i> Add Asset</a>
            <a class="btn btn-sm btn-outline-primary ms-1" href="import.php"><i class="bi bi-upload"></i> Import CSV/Excel</a>
          </div>
        <?php else: ?>
        <div class="donut-wrap">
          <div class="donut" style="<?= $donutStyle ?>">
            <div class="c"><b><?= $total ?></b><span>Total Assets</span></div>
          </div>
          <div class="legend">
            <?php foreach ($segSpec as [$name, $hex]): $v = $segValues[$name]; ?>
              <div class="li">
                <span class="d" style="background:<?= $hex ?>"></span>
                <span class="nm"><?= $name ?></span>
                <span class="v"><?= $v ?> · <?= $total ? round($v / $total * 100) : 0 ?>%</span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-lightning-charge text-primary"></i> Quick Actions</div>
      <div class="card-body">
        <div class="row g-3">
          <?php
          $qa = [
            ['Add Asset', 'Register new equipment', 'plus-circle', 'blue', 'inventory.php?add=1'],
            ['Import CSV/Excel', 'Bulk import assets', 'upload', 'purple', 'import.php'],
            ['Assign Equipment', 'Assign to a user', 'box-arrow-up', 'green', 'assign.php'],
            ['Export Data', 'Download inventory CSV', 'download', 'teal', 'reports.php?type=inventory&csv=1'],
            ['Print Report', 'Generate asset report', 'printer', 'orange', 'reports.php'],
            ['Send Reminders', 'Notify overdue users', 'bell', 'red', 'assigns.php?status=overdue'],
            ['Print QR Labels', 'Printable label sheets', 'upc-scan', 'teal', 'labels.php'],
            ['Categories & Locations', 'Organise your catalogue', 'tags', 'slate', 'categories.php'],
          ];
          foreach ($qa as [$t, $s, $ic, $color, $href]): ?>
            <div class="col-sm-6 col-xl-4">
              <a class="qa" href="<?= $href ?>">
                <span class="tile tile-<?= $color ?>"><i class="bi bi-<?= $ic ?>"></i></span>
                <span><span class="t d-block"><?= $t ?></span><span class="s"><?= $s ?></span></span>
              </a>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-activity text-primary"></i> System Overview</div>
      <div class="card-body">
        <div class="sys-li">
          <span class="i"><i class="bi bi-calendar-x"></i></span>
          <span><strong><?= $dueToday ?></strong> due today</span>
          <a class="v" href="assigns.php?status=active">View</a>
        </div>
        <div class="sys-li">
          <span class="i"><i class="bi bi-calendar2-week"></i></span>
          <span><strong><?= $dueSoon ?></strong> due within <?= $dueSoonDays ?> day(s)</span>
          <a class="v" href="assigns.php?status=active">View</a>
        </div>
        <div class="sys-li">
          <span class="i"><i class="bi bi-envelope-x"></i></span>
          <span><strong><?= $unacked ?></strong> not acknowledged</span>
          <a class="v" href="assigns.php?status=unacked">View</a>
        </div>
        <div class="sys-li">
          <span class="i"><i class="bi bi-shield-exclamation"></i></span>
          <span><strong><?= $warrSoon ?></strong> warranties expiring/expired</span>
          <a class="v" href="reports.php?type=inventory">View</a>
        </div>
        <div class="sys-li">
          <span class="i"><i class="bi bi-cash-coin"></i></span>
          <span>Inventory value</span>
          <span class="v"><?= money($invValue) ?></span>
        </div>
        <div class="sys-li">
          <span class="i"><i class="bi bi-database"></i></span>
          <span>Database</span>
          <span class="ok"><i class="bi bi-check-circle-fill"></i></span>
        </div>
        <div class="sys-li">
          <span class="i"><i class="bi bi-hdd-network"></i></span>
          <span>PHP <?= h(PHP_VERSION) ?> · MySQL</span>
          <span class="ok"><i class="bi bi-check-circle-fill"></i></span>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center">
        <span><i class="bi bi-clock-history text-primary"></i> Recent Activity &amp; Email Logs</span>
        <a class="btn btn-sm btn-light ms-auto" href="activity.php">View All Logs <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead><tr><th>Time</th><th>Admin</th><th>Action</th><th>Details</th></tr></thead>
          <tbody>
            <?php if (!$recent): ?>
              <tr><td colspan="4" class="empty"><i class="bi bi-inbox"></i>No activity yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($recent as $log): ?>
              <tr>
                <td class="text-muted text-nowrap small"><?= h(date('d M H:i', strtotime($log['created_at']))) ?></td>
                <td style="font-weight:600"><?= h($log['admin']) ?></td>
                <td><span class="badge bg-light border"><?= h($log['action']) ?></span></td>
                <td class="small text-muted"><?= h($log['details']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php layout_end(); ?>
