<?php
// Public acknowledgement page — token is the only credential here, no login.
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php'; // session only (CSRF) — never require_login()
require __DIR__ . '/includes/functions.php';

$token = $_POST['token'] ?? $_GET['token'] ?? '';
$st = db()->prepare('SELECT * FROM assigns WHERE ack_token = ?');
$st->execute([trim((string)$token)]);
$assign = $st->fetch();
$items = $assign ? db()->prepare('SELECT * FROM assign_items WHERE assign_id = ? ORDER BY id') : null;
if ($items) {
    $items->execute([$assign['id']]);
    $items = $items->fetchAll();
}

$expired = $assign && $assign['ack_expires'] && $assign['ack_expires'] < date('Y-m-d H:i:s');
$support = h(get_setting('it_support_email', IT_SUPPORT_EMAIL));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $assign && $assign['ack_status'] !== 'Acknowledged' && !$expired) {
    csrf_check();
    db()->prepare("UPDATE assigns SET ack_status = 'Acknowledged', ack_at = NOW(), ack_ip = ? WHERE id = ?")
        ->execute([$_SERVER['REMOTE_ADDR'] ?? '', $assign['id']]);
    log_action('acknowledgement_confirmed', $assign['assign_no'] . " — {$assign['user_name']}");
    send_ack_confirmation((int)$assign['id']);
    $assign['ack_status'] = 'Acknowledged';
    $assign['ack_at'] = date('Y-m-d H:i:s');
}

$done = $assign && $assign['ack_status'] === 'Acknowledged';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>IT Equipment Acknowledgement</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📦</text></svg>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
body { background: #eef3f9; font-family: Inter, system-ui, sans-serif; color: #0f172a; font-size: 14px; }
.wrap { max-width: 760px; margin: 28px auto 48px; padding: 0 14px; }
.page-icon { width: 52px; height: 52px; border-radius: 14px; background: linear-gradient(135deg,#60a5fa,#2563eb);
  color:#fff; font-size:24px; display:inline-flex; align-items:center; justify-content:center; box-shadow:0 8px 18px rgba(37,99,235,.28); }
.card { border: 1px solid #e3eaf3; border-radius: 14px; box-shadow: 0 1px 2px rgba(16,24,40,.05); }
.card-header { background:#fff; border-bottom:1px solid #e3eaf3; font-weight:700; padding:.9rem 1.2rem; }
.badge { border-radius:999px; padding:.42em .8em; font-weight:600; font-size:11.5px; }
.badge::before { content:''; display:inline-block; width:6px; height:6px; border-radius:50%; background:currentColor; margin-right:6px; vertical-align:1px; }
.badge.bg-success { background:#e6f6ec !important; color:#16a34a !important; }
.btn-primary { background:#2563eb; border-color:#2563eb; font-weight:600; border-radius:10px; }
.btn-primary:hover { background:#1d4ed8; }
.btn-outline-secondary { border-radius:10px; font-weight:600; }
.form-control, .table { font-size: 13.5px; }
.detail-ic { width:34px; height:34px; border-radius:9px; background:#e8f0fe; color:#2563eb;
  display:inline-flex; align-items:center; justify-content:center; flex-shrink:0; }
</style>
</head>
<body>
<div class="wrap">
  <div class="text-center mb-4">
    <span class="page-icon"><i class="bi bi-box-seam-fill"></i></span>
    <h4 class="mt-3 mb-1" style="font-weight:800;">IT Equipment Acknowledgement</h4>
    <div class="text-muted"><?= h(get_setting('company_name', APP_NAME)) ?></div>
  </div>

<?php if (!$assign): ?>
  <div class="alert alert-danger text-center">This acknowledgement link is invalid.<div class="small mt-2">Please contact IT Support: <a href="mailto:<?= $support ?>"><?= $support ?></a></div></div>

<?php elseif ($done): ?>
  <div class="card">
    <div class="card-body text-center p-5">
      <i class="bi bi-check-circle-fill text-success" style="font-size:52px;"></i>
      <h5 class="mt-3" style="font-weight:800;">Thank you. Your equipment acknowledgement has been recorded.</h5>
      <p class="text-muted mb-1"><?= h($assign['assign_no']) ?> — <?= h($assign['user_name']) ?></p>
      <p class="text-muted small">Recorded on <?= h(date('d M Y, H:i', strtotime($assign['ack_at']))) ?></p>
      <p class="mb-0">Expected return date: <strong><?= h(date('d M Y', strtotime($assign['expected_return']))) ?></strong>. Please return all items in good condition by this date.</p>
    </div>
  </div>

<?php elseif ($expired): ?>
  <div class="alert alert-warning text-center">This acknowledgement link has expired.<div class="small mt-2">Please contact IT Support: <a href="mailto:<?= $support ?>"><?= $support ?></a></div></div>

<?php else: ?>
  <div class="card mb-3">
      <div class="card-header"><i class="bi bi-clipboard-data text-primary"></i> Assignment Details</div>
    <div class="card-body">
      <div class="row g-2">
        <?php
        $det = [
          ['Assign ID', $assign['assign_no'], 'upc-scan'],
          ['Name', $assign['user_name'], 'person'],
          ['Project / Event Code', $assign['project_code'], 'code-slash'],
          ['Location', $assign['location'] ?: '—', 'geo-alt'],
          ['Assign Date', date('d M Y', strtotime($assign['assign_date'])), 'calendar3'],
          ['Expected Return', date('d M Y', strtotime($assign['expected_return'])), 'calendar-check'],
        ];
        foreach ($det as [$l, $v, $ic]): ?>
          <div class="col-sm-6">
            <div class="d-flex align-items-center gap-3 py-2">
              <span class="detail-ic"><i class="bi bi-<?= $ic ?>"></i></span>
              <div><div class="text-muted" style="font-size:11px;"><?= $l ?></div><div style="font-weight:700;"><?= h($v) ?></div></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="card mb-3">
      <div class="card-header"><i class="bi bi-box-seam text-primary"></i> Equipment assigned to you</div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr><th>Item</th><th>Asset Number</th><th>Serial Number</th><th>Condition</th></tr>
        </thead>
        <tbody>
          <?php foreach ($items as $it): ?>
            <tr>
              <td style="font-weight:600;"><?= h($it['item_name']) ?></td>
              <td><?= h($it['asset_number']) ?></td>
              <td><?= h($it['serial_number']) ?></td>
              <td><?= h($it['assign_condition']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-body p-4">
      <div class="alert alert-light border small">
        <strong>Important:</strong> You are responsible for this equipment. Do not hand it to anyone else. Report any fault, loss or
        damage to IT Support immediately. Return all items in good condition by the expected return date.
      </div>

      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= h($token) ?>">
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" id="confirm" required style="width:1.3em;height:1.3em;margin-top:.15em;">
          <label class="form-check-label" for="confirm" style="font-weight:600;">
            I confirm that I have received the equipment listed above.
          </label>
        </div>
        <p class="small text-muted">
          "I confirm that I have received the above-mentioned IT equipment and accept responsibility for returning it in good
          condition by the expected return date."
        </p>
        <div class="d-grid gap-2 d-md-flex">
          <button class="btn btn-primary btn-lg">YES, I ACKNOWLEDGE — CONFIRM RECEIPT</button>
          <a class="btn btn-outline-secondary btn-lg" href="mailto:<?= $support ?>?subject=<?= rawurlencode('Query regarding ' . $assign['assign_no']) ?>">I HAVE A QUERY</a>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>
</div>
</body>
</html>
