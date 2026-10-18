<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_login();

/* ---------------- POST actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $assignId = (int)($_POST['assign_id'] ?? 0);
    $st = db()->prepare('SELECT * FROM assigns WHERE id = ?');
    $st->execute([$assignId]);
    $assign = $st->fetch();
    if (!$assign) redirect('assigns.php');
    $back = trim($_POST['back'] ?? '') ?: 'assign_view.php?id=' . $assignId;
    // Same-site relative URLs only — never redirect to an absolute/off-site target.
    if (!preg_match('#^[A-Za-z0-9_.\-]+\.php(\?[A-Za-z0-9_=&%.\-]*)?$#', $back)) $back = 'assign_view.php?id=' . $assignId;
    $action = $_POST['action'] ?? '';

    if ($action === 'send_ack' && $assign['ack_status'] !== 'Acknowledged') {
        $ok = send_ack_email($assignId);
        if ($ok) log_action('ack_sent', $assign['assign_no']);
        flash($ok
            ? 'Acknowledgement email sent to ' . $assign['user_email'] . '.'
            : 'Email sending failed — check SMTP settings or the Logs page.',
            $ok ? 'success' : 'danger');
    } elseif ($action === 'send_reminder') {
        $ok = send_reminder_email($assignId);
        if ($ok) log_action('reminder_sent', $assign['assign_no']);
        flash($ok ? 'Reminder email sent.' : 'Email sending failed — check SMTP settings or the Logs page.',
            $ok ? 'success' : 'danger');
    } elseif ($action === 'return_item') {
        $st = db()->prepare('SELECT * FROM assign_items WHERE id = ? AND assign_id = ?');
        $st->execute([(int)($_POST['item_id'] ?? 0), $assignId]);
        $item = $st->fetch();
        $rs = $_POST['return_status'] ?? '';
        if (!$item || $item['return_status'] !== 'Pending') {
            flash('That item has already been resolved.', 'warning');
        } elseif (!in_array($rs, ['Returned', 'Damaged', 'Missing'], true)) {
            flash('Invalid return status.', 'danger');
        } else {
            $rdate = $_POST['return_date'] ?? date('Y-m-d');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$rdate)
                || !checkdate((int)substr($rdate, 5, 2), (int)substr($rdate, 8, 2), (int)substr($rdate, 0, 4))) $rdate = date('Y-m-d');

            db()->prepare('UPDATE assign_items SET return_status = ?, return_date = ?, return_condition = ?, return_remarks = ? WHERE id = ?')
                ->execute([$rs, $rdate, trim($_POST['return_condition'] ?? ''), trim($_POST['return_remarks'] ?? ''), $item['id']]);

            db()->prepare('UPDATE inventory SET status = ? WHERE id = ?')
                ->execute([$rs === 'Returned' ? 'Available' : $rs, $item['inventory_id']]);

            log_action('equipment_' . strtolower($rs), "{$item['asset_number']} — {$assign['assign_no']}");
            if ($rs === 'Missing') send_missing_email($item['id']);

            // Roll up the assign status from its items.
            $c = db()->prepare("SELECT SUM(return_status = 'Pending') p, SUM(return_status <> 'Pending') d
                                FROM assign_items WHERE assign_id = ?");
            $c->execute([$assignId]);
            $c = $c->fetch();
            $newStatus = (int)$c['p'] === 0 ? 'Returned' : ((int)$c['d'] > 0 ? 'Partial' : 'Issued');
            db()->prepare('UPDATE assigns SET status = ? WHERE id = ?')->execute([$newStatus, $assignId]);

            flash("Item '{$item['item_name']}' marked as $rs." . ($rs === 'Missing' ? ' IT Support has been notified.' : ''));
        }
    }
    redirect($back);
}

/* ---------------- view ---------------- */
$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT * FROM assigns WHERE id = ?');
$st->execute([$id]);
$assign = $st->fetch();
if (!$assign) {
    flash('assign record not found.', 'danger');
    redirect('assigns.php');
}
$st = db()->prepare('SELECT ii.*, iv.category FROM assign_items ii
                     LEFT JOIN inventory iv ON iv.id = ii.inventory_id
                     WHERE ii.assign_id = ? ORDER BY ii.id');
$st->execute([$id]);
$items = $st->fetchAll();

$pending = array_filter($items, fn($i) => $i['return_status'] === 'Pending');
$overdue = $assign['status'] !== 'Returned' && $assign['expected_return'] < date('Y-m-d');
$ackLink = $assign['ack_token'] ? APP_URL . '/acknowledge.php?token=' . $assign['ack_token'] : '';

layout_start($assign['assign_no'], 'assigns', $assign['user_name'] . ' · ' . $assign['project_code'], 'box-arrow-up-right');
?>
<?php if ($overdue): ?>
  <div class="alert alert-danger no-print"><i class="bi bi-exclamation-triangle-fill"></i>
    Overdue by <strong><?= days_overdue($assign['expected_return']) ?> day(s)</strong> — <?= count($pending) ?> item(s) still pending.
  </div>
<?php endif; ?>
<?php if ($assign['status'] === 'Returned'): ?>
  <div class="alert alert-success no-print"><i class="bi bi-check-circle"></i> All equipment returned. You can print the <a href="receipt.php?id=<?= $id ?>">return receipt</a>.</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-clipboard-data text-primary"></i> Assignment Details</div>
      <div class="card-body">
        <div class="entity mb-3 pb-3 border-bottom">
          <?= avatar($assign['user_name']) ?>
          <div>
            <div class="t"><?= h($assign['user_name']) ?></div>
            <div class="s"><?= h($assign['user_email']) ?><?= $assign['employee_id'] ? ' · ' . h($assign['employee_id']) : '' ?></div>
          </div>
          <div class="ms-auto text-end">
            <?= badge($overdue && $assign['status'] !== 'Partial' ? 'Overdue' : $assign['status']) ?>
            <div class="mt-1"><?= badge($assign['ack_status']) ?></div>
          </div>
        </div>
        <?php
        $rows = [
          ['Project / Event Code', $assign['project_code'], 'code-slash'],
          ['Purpose', $assign['purpose'] ?? 'Event', 'flag'],
          ['Event Name', $assign['purpose'] === 'Office' ? '—' : ($assign['event_name'] ?: '—'), 'calendar-event'],
          ['Event Location', $assign['purpose'] === 'Office' ? '—' : ($assign['location'] ?: '—'), 'geo-alt'],
          ['Assign Date', date('d M Y', strtotime($assign['assign_date'])), 'calendar3'],
          ['Expected Return', date('d M Y', strtotime($assign['expected_return'])), 'calendar-check'],
          ['Collected By', $assign['collected_by'] ?: $assign['user_name'], 'person-check'],
          ['Assigned By', $assign['created_by'], 'person-badge'],
        ];
        foreach ($rows as [$l, $v, $ic]): ?>
          <div class="d-flex align-items-center gap-3 py-2 line-row">
            <span class="icon-chip"><i class="bi bi-<?= $ic ?>"></i></span>
            <div>
              <div class="text-muted" style="font-size:11px;"><?= $l ?></div>
              <div style="font-weight:600;"><?= h($v) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if ($assign['assign_remarks']): ?>
          <div class="mt-3 p-2 rounded small" style="background:#f8fafc;"><span class="text-muted">Remarks:</span> <?= h($assign['assign_remarks']) ?></div>
        <?php endif; ?>
        <?php if ($assign['reminders_sent']): ?>
          <div class="mt-2 small text-muted"><i class="bi bi-bell"></i> <?= $assign['reminders_sent'] ?> reminder(s) sent — last <?= h(date('d M Y H:i', strtotime($assign['last_reminder_at']))) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><i class="bi bi-envelope-check text-primary"></i> Acknowledgement</div>
      <div class="card-body">
        <p class="mb-2 small">
          Status: <?= badge($assign['ack_status']) ?>
          <?php if ($assign['ack_at']): ?>
            <span class="text-muted">on <?= h(date('d M Y H:i', strtotime($assign['ack_at']))) ?> from <?= h($assign['ack_ip']) ?></span>
          <?php endif; ?>
        </p>
        <?php if ($ackLink && $assign['ack_status'] !== 'Acknowledged'): ?>
          <label class="f-label">Acknowledgement link (valid until <?= h(date('d M Y', strtotime($assign['ack_expires']))) ?>)</label>
          <div class="input-group input-group-sm">
            <input class="form-control" id="ackLink" value="<?= h($ackLink) ?>" readonly>
            <button class="btn btn-outline-secondary" type="button" data-copy="#ackLink" title="Copy"><i class="bi bi-clipboard"></i></button>
            <a class="btn btn-outline-secondary" href="<?= h($ackLink) ?>" target="_blank" title="Open"><i class="bi bi-box-arrow-up-right"></i></a>
          </div>
        <?php endif; ?>
        <div class="d-flex gap-2 flex-wrap mt-3 no-print">
          <?php if ($assign['ack_status'] !== 'Acknowledged'): ?>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="send_ack">
              <input type="hidden" name="assign_id" value="<?= $id ?>">
              <button class="btn btn-primary btn-sm"><i class="bi bi-envelope"></i> <?= $assign['ack_status'] === 'Sent' ? 'Resend Email' : 'Send Acknowledgement Email' ?></button>
            </form>
          <?php endif; ?>
          <?php if ($assign['status'] !== 'Returned'): ?>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="send_reminder">
              <input type="hidden" name="assign_id" value="<?= $id ?>">
              <button class="btn btn-outline-warning btn-sm"><i class="bi bi-bell"></i> Send Reminder</button>
            </form>
          <?php endif; ?>
          <a class="btn btn-outline-secondary btn-sm" href="receipt.php?id=<?= $id ?>"><i class="bi bi-printer"></i> Return Receipt</a>
          <a class="btn btn-outline-secondary btn-sm" href="assigns.php"><i class="bi bi-arrow-left"></i> Back</a>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card">
      <div class="card-header d-flex align-items-center">
        <span><i class="bi bi-box-seam text-primary"></i> Equipment</span>
        <span class="badge bg-light border ms-2"><?= count($items) - count($pending) ?> of <?= count($items) ?> returned</span>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr><th>Item</th><th>Condition</th><th>Return Status</th><th class="no-print">Return Action</th></tr>
          </thead>
          <tbody>
            <?php foreach ($items as $it): ?>
              <tr id="item-<?= (int)$it['id'] ?>">
                <td>
                  <div class="entity">
                    <?= asset_thumb($it['category'] ?? '', $it['item_name']) ?>
                    <div>
                      <div class="t"><?= h($it['item_name']) ?></div>
                      <div class="s"><?= h($it['asset_number']) ?><?= $it['serial_number'] ? ' · Serial ' . h($it['serial_number']) : '' ?></div>
                    </div>
                  </div>
                </td>
                <td class="small"><?= h($it['assign_condition']) ?></td>
                <td>
                  <?= badge($it['return_status']) ?>
                  <?php if ($it['return_date']): ?>
                    <div class="small text-muted mt-1"><?= h(date('d M Y', strtotime($it['return_date']))) ?></div>
                  <?php endif; ?>
                  <?php if ($it['return_condition'] || $it['return_remarks']): ?>
                    <div class="small text-muted"><?= h(trim($it['return_condition'] . ' ' . $it['return_remarks'])) ?></div>
                  <?php endif; ?>
                </td>
                <td class="no-print">
                  <?php if ($it['return_status'] === 'Pending'): ?>
                    <form method="post" class="ret-form">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="return_item">
                      <input type="hidden" name="assign_id" value="<?= $id ?>">
                      <input type="hidden" name="item_id" value="<?= $it['id'] ?>">
                      <select class="form-select form-select-sm" name="return_status">
                        <option>Returned</option><option>Damaged</option><option>Missing</option>
                      </select>
                      <input class="form-control form-control-sm" type="date" name="return_date" value="<?= date('Y-m-d') ?>">
                      <input class="form-control form-control-sm" name="return_condition" placeholder="Condition" value="Good">
                      <input class="form-control form-control-sm" name="return_remarks" placeholder="Remarks">
                      <button class="btn btn-sm btn-primary">Save</button>
                    </form>
                  <?php else: ?>
                    <span class="text-muted small">Resolved</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php layout_end(); ?>
