<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (($_POST['action'] ?? '') === 'save_settings') {
        foreach (['company_name', 'it_support_email', 'mail_host', 'mail_username', 'from_name', 'from_email'] as $k) {
            set_setting($k, trim($_POST[$k] ?? ''));
        }
        $port = trim($_POST['mail_port'] ?? '');
        set_setting('mail_port', $port !== '' && ctype_digit($port) ? $port : '587');
        $enc = $_POST['mail_encryption'] ?? '';
        set_setting('mail_encryption', in_array($enc, ['tls', 'ssl', 'none'], true) ? $enc : 'tls');
        $days = trim($_POST['ack_token_days'] ?? '');
        set_setting('ack_token_days', ctype_digit($days) && (int)$days >= 1 && (int)$days <= 365 ? $days : '30');
        $rmax = trim($_POST['reminder_max'] ?? '');
        set_setting('reminder_max', ctype_digit($rmax) && (int)$rmax >= 1 && (int)$rmax <= 50 ? $rmax : '3');
        $currency = trim($_POST['currency_symbol'] ?? '');
        set_setting('currency_symbol', $currency !== '' ? mb_substr($currency, 0, 4) : '₹');
        $dueSoonDays = trim($_POST['due_soon_days'] ?? '');
        set_setting('due_soon_days', ctype_digit($dueSoonDays) && (int)$dueSoonDays <= 30 ? $dueSoonDays : '3');
        set_setting('due_soon_auto', isset($_POST['due_soon_auto']) ? '1' : '0');
        $warrDays = trim($_POST['warranty_alert_days'] ?? '');
        set_setting('warranty_alert_days', ctype_digit($warrDays) && (int)$warrDays <= 365 ? $warrDays : '30');
        // The stored password is never echoed back — a blank field keeps the saved value.
        if (trim($_POST['mail_password'] ?? '') !== '') {
            set_setting('mail_password', trim($_POST['mail_password']));
        }
        set_setting('mail_verify_ssl', isset($_POST['mail_verify_ssl']) ? '1' : '0');
        set_setting('auto_reminder', isset($_POST['auto_reminder']) ? '1' : '0');
        log_action('settings_updated', 'System settings changed');
        flash('Settings saved.');
        redirect('settings.php');
    }
}

layout_start('Settings', 'settings', 'Manage your application preferences and system configuration.', 'gear');
$enc = get_setting('mail_encryption', 'tls');
$sm = db()->query('SELECT VERSION()')->fetchColumn();
?>
<?php if (get_setting('mail_host') === ''): ?>
  <div class="alert alert-warning no-print">
    <i class="bi bi-exclamation-triangle"></i> SMTP is not configured. Emails will use PHP <code>mail()</code>, which is unreliable on
    shared hosting. Fill in the SMTP details below (your cPanel email account or a service like Gmail/Brevo).
  </div>
<?php endif; ?>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_settings">
  <div class="card mb-3">
    <div class="card-header"><i class="bi bi-building text-primary"></i> General Settings <div class="card-sub">Company identity shown in the app and emails.</div></div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Organization Name</label>
          <input class="form-control" name="company_name" value="<?= h(get_setting('company_name')) ?>"></div>
        <div class="col-md-6"><label class="form-label">IT Support Email (shown to users)</label>
          <input class="form-control" type="email" name="it_support_email" value="<?= h(get_setting('it_support_email')) ?>"></div>
        <div class="col-md-6"><label class="form-label">Currency Symbol</label>
          <input class="form-control" name="currency_symbol" value="<?= h(get_setting('currency_symbol', '₹')) ?>" maxlength="4" placeholder="₹ / $ / €"></div>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header"><i class="bi bi-envelope-at text-primary"></i> Email — SMTP <div class="card-sub">Used for acknowledgement, reminder and alert emails.</div></div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-8"><label class="form-label">SMTP Host</label>
          <input class="form-control" name="mail_host" value="<?= h(get_setting('mail_host')) ?>" placeholder="mail.yourdomain.com"></div>
        <div class="col-md-4"><label class="form-label">SMTP Port</label>
          <input class="form-control" name="mail_port" value="<?= h(get_setting('mail_port', '587')) ?>"></div>
        <div class="col-md-6"><label class="form-label">SMTP Username</label>
          <input class="form-control" name="mail_username" value="<?= h(get_setting('mail_username')) ?>" placeholder="usually the email address"></div>
        <div class="col-md-6"><label class="form-label">SMTP Password</label>
          <input class="form-control" type="password" name="mail_password" value=""
                 placeholder="<?= get_setting('mail_password') !== '' ? 'Saved — leave blank to keep' : '' ?>" autocomplete="new-password"></div>
        <div class="col-md-4"><label class="form-label">Encryption</label>
          <select class="form-select" name="mail_encryption">
            <?php foreach (['tls' => 'TLS (STARTTLS, usually 587)', 'ssl' => 'SSL (usually 465)', 'none' => 'None (25)'] as $v => $label): ?>
              <option value="<?= $v ?>" <?= $enc === $v ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="col-md-4"><label class="form-label">Verify SSL Certificate</label>
          <div class="form-check form-switch mt-2">
            <input class="form-check-input" type="checkbox" name="mail_verify_ssl" value="1" id="mailVerify" <?= get_setting('mail_verify_ssl', '1') === '1' ? 'checked' : '' ?>>
            <label class="form-check-label small text-muted" for="mailVerify">Keep on — disable only if your mail server has a mismatched certificate</label>
          </div></div>
        <div class="col-md-4"><label class="form-label">From Name</label>
          <input class="form-control" name="from_name" value="<?= h(get_setting('from_name')) ?>"></div>
        <div class="col-md-4"><label class="form-label">From Email</label>
          <input class="form-control" type="email" name="from_email" value="<?= h(get_setting('from_email')) ?>"></div>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header"><i class="bi bi-bell text-primary"></i> Acknowledgement &amp; Reminders <div class="card-sub">Token lifetime, overdue and warranty alert behavior.</div></div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-4"><label class="form-label">Acknowledgement Link Valid For (days)</label>
          <input class="form-control" type="number" min="1" max="365" name="ack_token_days" value="<?= h(get_setting('ack_token_days', '30')) ?>"></div>
        <div class="col-md-4"><label class="form-label">Max Reminders per Assignment</label>
          <input class="form-control" type="number" min="1" max="50" name="reminder_max" value="<?= h(get_setting('reminder_max', '3')) ?>"></div>
        <div class="col-md-4"><label class="form-label">Automatic Reminders</label>
          <div class="form-check form-switch mt-2" style="font-size:15px;">
            <input class="form-check-input" type="checkbox" name="auto_reminder" value="1" id="autoRem" <?= get_setting('auto_reminder') === '1' ? 'checked' : '' ?>>
            <label class="form-check-label small text-muted" for="autoRem">Send overdue reminders via daily cron (see README)</label>
          </div></div>
        <div class="col-md-4"><label class="form-label">Due-Soon Reminder (days before return, 0 = off)</label>
          <input class="form-control" type="number" min="0" max="30" name="due_soon_days" value="<?= h(get_setting('due_soon_days', '3')) ?>">
          <div class="form-check form-switch mt-2" style="font-size:15px;">
            <input class="form-check-input" type="checkbox" name="due_soon_auto" value="1" id="dueSoonAuto" <?= get_setting('due_soon_auto') === '1' ? 'checked' : '' ?>>
            <label class="form-check-label small text-muted" for="dueSoonAuto">Email users once before the return date (via daily cron)</label>
          </div></div>
        <div class="col-md-4"><label class="form-label">Warranty Alert Window (days ahead, 0 = off)</label>
          <input class="form-control" type="number" min="0" max="365" name="warranty_alert_days" value="<?= h(get_setting('warranty_alert_days', '30')) ?>">
          <div class="form-text">Daily cron emails IT Support one digest listing assets whose warranty expires inside this window.</div></div>
      </div>
    </div>
  </div>

  <button class="btn btn-primary"><i class="bi bi-check-lg"></i> Save Changes</button>
</form>

<div class="card mt-4">
  <div class="card-header"><i class="bi bi-hdd-network text-primary"></i> System Info</div>
  <div class="card-body">
    <?php
    $rows = [
      ['Application', APP_NAME],
      ['PHP Version', PHP_VERSION],
      ['Database', 'MySQL / MariaDB — ' . h((string)$sm)],
      ['Server Time', date('d M Y H:i')],
    ];
    foreach ($rows as [$l, $v]): ?>
      <div class="d-flex justify-content-between py-2 line-row" style="font-size:13px;">
        <span class="text-muted"><?= $l ?></span><span style="font-weight:600;"><?= $v ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php layout_end(); ?>
