<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_login();

const DRAFT = 'assign_draft';

function draft(): array {
    return $_SESSION[DRAFT] ?? ['items' => [], 'form' => []];
}

function steps_html(int $current): void {
    $steps = [1 => 'Select Product', 2 => 'Assignment Details', 3 => 'Confirm & Submit'];
    echo '<div class="steps">';
    foreach ($steps as $n => $label) {
        if ($n > 1) echo '<span class="s-line"></span>';
        $cls = $n < $current ? 'done' : ($n === $current ? 'current' : '');
        echo '<span class="step ' . $cls . '"><span class="s-dot">' . ($n < $current ? '<i class="bi bi-check-lg"></i>' : $n) . '</span> ' . $label . '</span>';
    }
    echo '</div>';
}

function draft_items_data(array $ids): array {
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT * FROM inventory WHERE id IN ($ph) ORDER BY asset_number");
    $st->execute($ids);
    return $st->fetchAll();
}

if (isset($_GET['cancel'])) {
    unset($_SESSION[DRAFT]);
    redirect('assign.php');
}

$step = max(1, min(3, (int)($_GET['step'] ?? 1)));
$d = draft();
$errors = [];

/* ---------------- POST: wizard navigation ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // Step 1 → 2: persist selection
    if ($action === 'step1') {
        $ids = array_values(array_unique(array_map('intval', $_POST['items'] ?? [])));
        if (!$ids) {
            $errors[] = 'Select at least one equipment item.';
        } else {
            $_SESSION[DRAFT]['items'] = $ids;
            redirect('assign.php?step=2');
        }
        $step = 1;
    }

    // Step 2 → 3: persist assign details
    if ($action === 'step2') {
        $f = [
            'user_name' => trim($_POST['user_name'] ?? ''),
            'user_email' => trim($_POST['user_email'] ?? ''),
            'employee_id' => trim($_POST['employee_id'] ?? ''),
            'department' => trim($_POST['department'] ?? ''),
            'collected_by' => trim($_POST['collected_by'] ?? '') ?: trim($_POST['user_name'] ?? ''),
            'purpose' => ($_POST['purpose'] ?? '') === 'Office' ? 'Office' : 'Event',
            'project_code' => trim($_POST['project_code'] ?? ''),
            'event_name' => trim($_POST['event_name'] ?? ''),
            'location' => trim($_POST['location'] ?? ''),
            'assign_date' => trim($_POST['assign_date'] ?? ''),
            'expected_return' => trim($_POST['expected_return'] ?? ''),
            'assign_condition' => trim($_POST['assign_condition'] ?? '') ?: 'Good',
            'assign_remarks' => trim($_POST['assign_remarks'] ?? ''),
        ];
        if ($f['user_name'] === '') $errors[] = 'User name is required.';
        if (!filter_var($f['user_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid user email is required.';
        if ($f['project_code'] === '') $errors[] = 'Project/Event code is required.';
        $validDate = fn(string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1
            && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
        if (!$validDate($f['assign_date'])) $errors[] = 'assign date is required.';
        if (!$validDate($f['expected_return'])) $errors[] = 'Expected return date is required.';
        if (!$errors && $f['expected_return'] < $f['assign_date']) $errors[] = 'Expected return date cannot be before the assign date.';
        if (!$errors) {
            $_SESSION[DRAFT]['form'] = $f;
            redirect('assign.php?step=3');
        }
        $d['form'] = $f;
        $step = 2;
    }

    // Step 3 → create the assign (same transactional core as before)
    if ($action === 'confirm' && !empty($d['items']) && !empty($d['form'])) {
        $form = $d['form'];
        $itemIds = $d['items'];
        $assignId = 0;
        $err = null;
        // ponytail: retry on duplicate assign_no (unique index) instead of a lock/sequence table.
        for ($try = 0; $try < 3 && !$assignId; $try++) {
            try {
                $pdo = db();
                $pdo->beginTransaction();
                $assignNo = next_assign_no();
                $pdo->prepare(
                    'INSERT INTO assigns (assign_no, user_name, user_email, employee_id, project_code, event_name, location,
                                         purpose, collected_by, assign_date, expected_return, status, assign_remarks, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,"Issued",?,?)'
                )->execute([
                    $assignNo, $form['user_name'], $form['user_email'], $form['employee_id'], $form['project_code'],
                    $form['event_name'], $form['location'], $form['purpose'], $form['collected_by'],
                    $form['assign_date'], $form['expected_return'],
                    $form['assign_remarks'], $_SESSION['admin']['username'],
                ]);
                $assignId = (int)$pdo->lastInsertId();

                $sel = $pdo->prepare('SELECT * FROM inventory WHERE id = ?');
                $upd = $pdo->prepare("UPDATE inventory SET status = 'Issued' WHERE id = ? AND status = 'Available'");
                $ins = $pdo->prepare(
                    'INSERT INTO assign_items (assign_id, inventory_id, item_name, asset_number, serial_number, assign_condition)
                     VALUES (?,?,?,?,?,?)'
                );
                foreach ($itemIds as $invId) {
                    $sel->execute([$invId]);
                    $a = $sel->fetch();
                    if (!$a) throw new RuntimeException("Asset #$invId not found.");
                    // Conditional UPDATE makes the availability check race-safe without locks.
                    $upd->execute([$invId]);
                    if ($upd->rowCount() !== 1) {
                        throw new RuntimeException("Asset {$a['asset_number']} is no longer available (status: {$a['status']}).");
                    }
                    $ins->execute([$assignId, $invId, $a['asset_name'], $a['asset_number'], $a['serial_number'], $form['assign_condition']]);
                }
                $pdo->commit();
                upsert_user($form['user_name'], $form['user_email'], $form['employee_id'], $form['department']);
                log_action('equipment_assigned', "$assignNo — {$form['user_name']} — project {$form['project_code']} — " . count($itemIds) . ' item(s)');
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $err = $e->getMessage();
                break; // business error — retrying won't help
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($e->getCode() === '23000') continue; // assign_no race — retry
                throw $e;
            }
        }

        if ($err) {
            $errors[] = $err;
            $step = 3;
        } elseif ($assignId) {
            if (isset($_POST['send_email'])) {
                $ok = send_ack_email($assignId);
                flash($ok
                    ? 'Equipment assigned. Acknowledgement email sent to ' . $form['user_email'] . '.'
                    : 'Equipment assigned, but the acknowledgement email could not be sent. Check Settings/SMTP, then resend from the assignment page.',
                    $ok ? 'success' : 'warning');
            } else {
                flash('Equipment assigned. Remember to send the acknowledgement email.');
            }
            unset($_SESSION[DRAFT]);
            redirect('assign_view.php?id=' . $assignId);
        } else {
            $errors[] = 'Could not create the assign (database conflict). Please try again.';
            $step = 3;
        }
    }
}

/* Guard: can't be on a later step without the prior step's data. */
if ($step >= 2 && empty($d['items'])) $step = 1;
if ($step >= 3 && empty($d['form'])) $step = 2;

$selected = draft_items_data($d['items'] ?? []);
$f = $d['form'] ?? [];

// Known users for the Assign To autocomplete — Users directory rows win over bare assign-history rows.
$known = [];
foreach (db()->query(
    "SELECT name user_name, email user_email, employee_id, department, status FROM users
     UNION ALL
     SELECT user_name, user_email, MAX(employee_id), '', '' FROM assigns GROUP BY user_name, user_email
     LIMIT 500"
)->fetchAll() as $r) {
    $n = $r['user_name'];
    if (!isset($known[$n]) || ($r['department'] !== '' && $known[$n]['department'] === '')) {
        $known[$n] = $r;
    }
}

layout_start('Assign Equipment', 'assign', '', '', false, 'Search assets, serial number…');
?>
<div class="page-head">
  <span class="page-icon"><i class="bi bi-box-arrow-up"></i></span>
  <div>
    <h1>Assign Equipment</h1>
    <div class="sub">Assign available equipment to a user — <?= $step === 1 ? 'choose the products.' : ($step === 2 ? 'fill in the assign details.' : 'review and submit.') ?></div>
  </div>
  <div class="right no-print"><?php steps_html($step); ?></div>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($step === 1): ?>
  <?php
  $q = trim($_GET['q'] ?? '');
  $cat = trim($_GET['category'] ?? '');
  $w = ["status = 'Available'"];
  $p = [];
  if ($q !== '') {
      $w[] = '(asset_number LIKE ? OR asset_name LIKE ? OR serial_number LIKE ? OR brand LIKE ? OR model LIKE ? OR category LIKE ?)';
      $like = "%$q%";
      $p = array_fill(0, 6, $like);
  }
  if ($cat !== '') {
      $w[] = 'category = ?';
      $p[] = $cat;
  }
  $st = db()->prepare('SELECT * FROM inventory WHERE ' . implode(' AND ', $w) . ' ORDER BY asset_number LIMIT 100');
  $st->execute($p);
  $available = $st->fetchAll();
  // Categories that exist as assets (so the filter never offers an empty bucket) + managed ones.
  $categories = db()->query(
      "SELECT DISTINCT category FROM inventory WHERE status = 'Available' AND category <> ''
       UNION SELECT name FROM categories ORDER BY category"
  )->fetchAll(PDO::FETCH_COLUMN);
  $checked = array_flip($d['items'] ?? []);
  ?>
  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card mb-3 filter-bar">
        <form method="get">
          <div class="card-body">
            <div class="row g-3 align-items-end">
              <div class="col-md-7">
                <label class="f-label">Search</label>
                <div class="position-relative">
                  <i class="bi bi-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:13px;"></i>
                  <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Search product, brand, or asset ID…" style="padding-left:34px">
                </div>
              </div>
              <div class="col-md-3">
                <label class="f-label">Category</label>
                <select class="form-select" name="category">
                  <option value="">All Categories</option>
                  <?php foreach ($categories as $cg): ?>
                    <option <?= $cat === $cg ? 'selected' : '' ?>><?= h($cg) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <button class="btn btn-primary w-100"><i class="bi bi-search"></i> Search</button>
              </div>
            </div>
          </div>
        </form>
      </div>

      <div class="card">
        <div class="card-header d-flex align-items-center">
          <span><i class="bi bi-box-seam text-primary"></i> Available Products (<?= count($available) ?>)</span>
          <span class="badge bg-success ms-2">Available only</span>
        </div>
        <div class="table-responsive" style="max-height:520px;overflow-y:auto;">
          <table class="table table-hover align-middle">
            <thead><tr><th style="width:36px"></th><th>Product</th><th>Brand / Model</th><th>Asset ID</th><th>Status</th></tr></thead>
            <tbody>
              <?php if (!$available): ?>
                <tr><td colspan="5" class="empty"><i class="bi bi-inbox"></i>No available products match.</td></tr>
              <?php endif; ?>
              <?php foreach ($available as $a): ?>
                <tr>
                  <td><input class="form-check-input" type="checkbox" name="items[]" value="<?= $a['id'] ?>" <?= isset($checked[$a['id']]) ? 'checked' : '' ?> form="step1form"></td>
                  <td>
                    <div class="entity">
                      <?= asset_thumb($a['category'], $a['asset_name']) ?>
                      <div><div class="t"><?= h($a['asset_name']) ?></div><div class="s"><?= h($a['category']) ?></div></div>
                    </div>
                  </td>
                  <td class="small"><?= h(trim($a['brand'] . ' / ' . $a['model'], ' /')) ?: '—' ?></td>
                  <td style="font-weight:600"><?= h($a['asset_number']) ?></td>
                  <td><?= badge($a['status']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card sticky-top" style="top:86px;">
        <div class="card-header d-flex align-items-center">
          <span><i class="bi bi-bag-check text-primary"></i> Selected Product(s)</span>
          <span class="badge bg-primary ms-auto" id="selCount" <?= $selected ? '' : 'hidden' ?>><?= count($selected) ?></span>
        </div>
        <div class="card-body">
          <div id="selEmpty" class="text-center text-muted py-4" <?= $selected ? 'hidden' : '' ?>>
            <i class="bi bi-inbox" style="font-size:30px;opacity:.4;display:block;margin-bottom:8px;"></i>
            <div style="font-weight:600;">No product selected</div>
            <div class="small mt-1">Choose products from the list — they appear here instantly.</div>
          </div>
          <div id="selList">
            <?php foreach ($selected as $a): ?>
              <div class="entity py-2 border-bottom" data-sel="<?= (int)$a['id'] ?>">
                <?= asset_thumb($a['category'], $a['asset_name']) ?>
                <div>
                  <div class="t"><?= h($a['asset_name']) ?> <span class="badge bg-light border"><?= h($a['category']) ?></span></div>
                  <div class="s">Asset ID: <?= h($a['asset_number']) ?><?= $a['serial_number'] ? ' · Serial: ' . h($a['serial_number']) : '' ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <div id="selTemplates" hidden>
            <?php foreach ($available as $a): ?>
              <div class="entity py-2 border-bottom" data-sel="<?= (int)$a['id'] ?>">
                <?= asset_thumb($a['category'], $a['asset_name']) ?>
                <div>
                  <div class="t"><?= h($a['asset_name']) ?> <span class="badge bg-light border"><?= h($a['category']) ?></span></div>
                  <div class="s">Asset ID: <?= h($a['asset_number']) ?><?= $a['serial_number'] ? ' · Serial: ' . h($a['serial_number']) : '' ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="small text-muted mt-2" id="selHint" <?= $selected ? '' : 'hidden' ?>>Untick a row to remove it, then press Continue.</div>
          <div class="d-grid gap-2 mt-3">
            <button class="btn btn-primary btn-lg" id="step1form-btn" form="step1form" <?= $selected ? '' : 'disabled' ?>>Continue <i class="bi bi-arrow-right"></i></button>
            <a class="btn btn-outline-secondary" href="assign.php?cancel=1">Cancel</a>
          </div>
        </div>
      </div>
    </div>
  </div>
  <form method="post" id="step1form"><?= csrf_field() ?><input type="hidden" name="action" value="step1"></form>

<?php elseif ($step === 2): ?>
  <div class="card mb-3">
    <div class="card-body">
      <?php foreach ($selected as $a): ?>
        <div class="entity py-2">
          <?= asset_thumb($a['category'], $a['asset_name']) ?>
          <div class="flex-grow-1">
            <div class="t"><?= h($a['asset_name']) ?> <span class="badge bg-light border"><?= h($a['category']) ?></span></div>
            <div class="s">Asset ID: <?= h($a['asset_number']) ?><?= $a['serial_number'] ? ' &nbsp;|&nbsp; Serial No: ' . h($a['serial_number']) : '' ?><?= $a['brand'] ? ' &nbsp;|&nbsp; Brand: ' . h($a['brand']) : '' ?></div>
          </div>
          <?= badge($a['status']) ?>
        </div>
      <?php endforeach; ?>
      <div class="text-end no-print"><a class="btn btn-sm btn-outline-secondary" href="assign.php?step=1"><i class="bi bi-arrow-left"></i> Change Product</a></div>
    </div>
  </div>

  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="step2">
    <div class="row g-3">
      <div class="col-12">
        <div class="card">
          <div class="card-header"><i class="bi bi-clipboard-data text-primary"></i> Assignment Details</div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-3"><label class="form-label">Assign Date <span class="req">*</span></label>
                <input class="form-control" type="date" name="assign_date" value="<?= h($f['assign_date'] ?? date('Y-m-d')) ?>" required></div>
              <div class="col-md-3"><label class="form-label">Expected Return Date <span class="req">*</span></label>
                <input class="form-control" type="date" name="expected_return" value="<?= h($f['expected_return'] ?? '') ?>" required></div>
              <div class="col-md-3"><label class="form-label">Purpose <span class="req">*</span></label>
                <select class="form-select" name="purpose" id="purposeSel">
                  <option value="Event" <?= ($f['purpose'] ?? 'Event') === 'Event' ? 'selected' : '' ?>>Event</option>
                  <option value="Office" <?= ($f['purpose'] ?? '') === 'Office' ? 'selected' : '' ?>>Office</option>
                </select></div>
              <div class="col-md-3"><label class="form-label">Condition at Assignment</label>
                <input class="form-control" name="assign_condition" value="<?= h($f['assign_condition'] ?? 'Good') ?>"></div>
              <div id="eventFields">
                <div class="col-md-4"><label class="form-label">Event Name</label>
                  <input class="form-control" name="event_name" value="<?= h($f['event_name'] ?? '') ?>" placeholder="e.g. Google LC Event"></div>
                <div class="col-md-4"><label class="form-label">Event Location</label>
                  <input class="form-control" name="location" value="<?= h($f['location'] ?? '') ?>" list="locationList" placeholder="e.g. Delhi">
                  <datalist id="locationList">
                    <?php foreach (location_names() as $ln): ?><option value="<?= h($ln) ?>"><?php endforeach; ?>
                  </datalist>
                  <?php if (($_SESSION['admin']['role'] ?? '') === 'admin'): ?>
                    <div class="form-text"><a href="locations.php" target="_blank" rel="noopener"><i class="bi bi-geo-alt"></i> Manage locations</a></div>
                  <?php endif; ?></div>
              </div>
              <div class="col-md-4"><label class="form-label" id="codeLabel">Project / Event Code <span class="req">*</span></label>
                <input class="form-control" name="project_code" value="<?= h($f['project_code'] ?? '') ?>" placeholder="EMC-2026-105" required></div>
              <div class="col-12"><label class="form-label">Remarks</label>
                <input class="form-control" name="assign_remarks" value="<?= h($f['assign_remarks'] ?? '') ?>" placeholder="Any additional notes or special instructions…"></div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-12">
        <div class="card">
          <div class="card-header"><i class="bi bi-person text-primary"></i> Assign To</div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-4"><label class="form-label">User Name <span class="req">*</span></label>
                <input class="form-control" name="user_name" id="userName" list="userList" value="<?= h($f['user_name'] ?? '') ?>" placeholder="Type user name…" required>
                <datalist id="userList">
                  <?php foreach ($known as $k): ?><option value="<?= h($k['user_name']) ?>"><?= h($k['user_email']) ?></option><?php endforeach; ?>
                </datalist>
              </div>
              <div class="col-md-4"><label class="form-label">User Email ID <span class="req">*</span></label>
                <input class="form-control" type="email" name="user_email" id="userEmail" value="<?= h($f['user_email'] ?? '') ?>" placeholder="name@company.com" required></div>
              <div class="col-md-4"><label class="form-label">Employee ID</label>
                <input class="form-control" name="employee_id" id="employeeId" value="<?= h($f['employee_id'] ?? '') ?>" placeholder="Auto-filled for known users"></div>
            </div>
            <div id="userInfo" class="small mt-2 text-muted" style="display:none;"></div>
            <hr class="my-3">
            <div class="row g-3">
              <div class="col-md-4"><label class="form-label">Collected By</label>
                <input class="form-control" name="collected_by" id="collectedBy" list="userList" value="<?= h($f['collected_by'] ?? $f['user_name'] ?? '') ?>" placeholder="Defaults to the user; type to change">
                <div class="form-text">Fill this if someone else physically collects the equipment on behalf of the user.</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="d-flex gap-2 mt-4">
      <a class="btn btn-outline-secondary" href="assign.php?step=1"><i class="bi bi-arrow-left"></i> Back</a>
      <button class="btn btn-primary ms-auto">Next: Review &amp; Confirm <i class="bi bi-arrow-right"></i></button>
    </div>
  </form>

<?php else: ?>
  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card mb-3">
        <div class="card-header"><i class="bi bi-box-seam text-primary"></i> Selected Product(s)</div>
        <div class="card-body">
          <?php foreach ($selected as $a): ?>
            <div class="entity py-2 border-bottom">
              <?= asset_thumb($a['category'], $a['asset_name']) ?>
              <div>
                <div class="t"><?= h($a['asset_name']) ?> <span class="badge bg-light border"><?= h($a['category']) ?></span></div>
                <div class="s">Asset ID: <?= h($a['asset_number']) ?><?= $a['serial_number'] ? ' &nbsp;|&nbsp; Serial No: ' . h($a['serial_number']) : '' ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card mb-3">
      <div class="card-header"><i class="bi bi-clipboard-data text-primary"></i> Assignment Details</div>
      <div class="card-body">
          <div class="row g-3">
            <?php
            $review = [
              ['Assign Date', date('d M Y', strtotime($f['assign_date'] ?? 'now')), 'calendar3'],
              ['Expected Return Date', !empty($f['expected_return']) ? date('d M Y', strtotime($f['expected_return'])) : '—', 'calendar3'],
              ['Purpose', $f['purpose'] ?? 'Event', 'flag'],
              ['Project Code', $f['project_code'] ?? '—', 'code-slash'],
              ['Event Name', ($f['purpose'] ?? '') === 'Office' ? '—' : ($f['event_name'] ?: '—'), 'calendar-event'],
              ['Event Location', ($f['purpose'] ?? '') === 'Office' ? '—' : ($f['location'] ?: '—'), 'geo-alt'],
              ['Condition at assign', $f['assign_condition'] ?? 'Good', 'patch-check'],
              ['Collected By', $f['collected_by'] ?: ($f['user_name'] ?? ''), 'person-check'],
            ];
            foreach ($review as [$l, $v, $ic]): ?>
              <div class="col-md-6">
                <div class="d-flex gap-3 align-items-center p-2 rounded soft-tile">
                  <span class="sys-li-i"><i class="bi bi-<?= $ic ?>"></i></span>
                  <div><div class="text-muted small"><?= $l ?></div><div style="font-weight:700;"><?= h($v) ?></div></div>
                </div>
              </div>
            <?php endforeach; ?>
            <?php if (!empty($f['assign_remarks'])): ?>
              <div class="col-12"><div class="p-2 rounded small soft-tile"><span class="text-muted">Remarks:</span> <?= h($f['assign_remarks']) ?></div></div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><i class="bi bi-person text-primary"></i> Assign To</div>
        <div class="card-body">
          <div class="entity py-1">
            <?= avatar($f['user_name'] ?? '?') ?>
            <div><div class="t"><?= h($f['user_name'] ?? '') ?></div><div class="s"><?= h($f['user_email'] ?? '') ?><?= !empty($f['employee_id']) ? ' · ' . h($f['employee_id']) : '' ?></div></div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card sticky-top" style="top:86px;">
        <div class="card-header"><i class="bi bi-card-checklist text-primary"></i> Summary</div>
        <div class="card-body">
          <table class="table table-sm table-borderless mb-3">
            <?php
            $sum = [
              'Product(s)' => count($selected) . ' item(s)',
              'User' => ($f['user_name'] ?? ''),
              'Purpose' => $f['purpose'] ?? 'Event',
              'Project Code' => $f['project_code'] ?? '',
              'Collected By' => $f['collected_by'] ?: ($f['user_name'] ?? ''),
              'Assign Date' => date('d M Y', strtotime($f['assign_date'] ?? 'now')),
              'Expected Return' => !empty($f['expected_return']) ? date('d M Y', strtotime($f['expected_return'])) : '',
            ];
            foreach ($sum as $l => $v): ?>
              <tr><td class="text-muted" style="width:45%"><?= h($l) ?></td><td style="font-weight:600"><?= h($v) ?></td></tr>
            <?php endforeach; ?>
          </table>
          <div class="alert alert-success d-flex align-items-center gap-2 small py-2 px-3">
            <i class="bi bi-check-circle-fill"></i> All details are verified and ready to be submitted.
          </div>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm">
            <div class="form-check mb-3">
              <input class="form-check-input" type="checkbox" name="send_email" value="1" id="sendEmail" checked>
              <label class="form-check-label small" for="sendEmail">Send acknowledgement email immediately after issuing</label>
            </div>
            <div class="d-grid gap-2">
              <button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> Confirm &amp; Assign Equipment</button>
              <a class="btn btn-outline-secondary" href="assign.php?step=2"><i class="bi bi-arrow-left"></i> Back</a>
              <a class="btn btn-link text-muted small" href="assign.php?cancel=1">Start over</a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<script>
// Known-user autocomplete: fill email/employee ID/department, flag inactive users.
const known = <?= json_encode($known, JSON_UNESCAPED_UNICODE) ?: '{}' ?>;
const nameEl = document.getElementById('userName');
if (nameEl) {
  const fillUser = () => {
    const u = known[nameEl.value];
    const info = document.getElementById('userInfo');
    if (!u) { info.style.display = 'none'; return; }
    // Overwrite on every change: picking a different person must not leave the previous person's email behind.
    const e = document.getElementById('userEmail'), d = document.getElementById('employeeId');
    if (e) e.value = u.user_email;
    if (d && u.employee_id) d.value = u.employee_id;
    info.style.display = '';
    info.textContent = '';
    const icon = document.createElement('i');
    icon.className = 'bi bi-person-badge';
    info.appendChild(icon);
    [['Department: ', u.department || '—'], ['Email: ', u.user_email]].forEach(([label, value]) => {
      const span = document.createElement('span');
      span.className = 'me-3';
      span.textContent = label + value;
      info.appendChild(span);
    });
    if (u.status === 'Inactive') {
      const warn = document.createElement('span');
      warn.className = 'text-danger fw-bold';
      warn.textContent = 'This user is marked Inactive — review before issuing.';
      info.appendChild(warn);
    }
  };
  nameEl.addEventListener('change', fillUser);
  fillUser();
}
// Purpose toggle: hide event fields for Office assigns, relabel the code field.
const purposeSel = document.getElementById('purposeSel');
if (purposeSel) {
  const applyPurpose = () => {
    const office = purposeSel.value === 'Office';
    document.getElementById('eventFields').style.display = office ? 'none' : '';
    document.getElementById('codeLabel').innerHTML = (office ? 'Reference Code' : 'Project / Event Code') + ' <span class="req">*</span>';
  };
  purposeSel.addEventListener('change', applyPurpose);
  applyPurpose();
}
// Step 1: live "Selected Product(s)" — ticking a row adds it to the panel immediately.
const selChecks = document.querySelectorAll('input[name="items[]"]');
if (selChecks.length) {
  const selList = document.getElementById('selList');
  const selTpl = document.getElementById('selTemplates');
  const selEmpty = document.getElementById('selEmpty');
  const selHint = document.getElementById('selHint');
  const selCount = document.getElementById('selCount');
  const syncSel = () => {
    selChecks.forEach(cb => {
      cb.closest('tr').classList.toggle('sel', cb.checked);
      const row = selList.querySelector('[data-sel="' + cb.value + '"]');
      if (cb.checked && !row) {
        const tpl = selTpl.querySelector('[data-sel="' + cb.value + '"]');
        if (tpl) selList.appendChild(tpl.cloneNode(true));
      } else if (!cb.checked && row) {
        row.remove();
      }
    });
    const n = selList.querySelectorAll('[data-sel]').length;
    selEmpty.hidden = n > 0;
    selHint.hidden = n === 0;
    selCount.hidden = n === 0;
    selCount.textContent = n;
    const cont = document.getElementById('step1form-btn');
    if (cont) cont.disabled = n === 0;
  };
  selChecks.forEach(cb => cb.addEventListener('change', syncSel));
  // Whole-row click toggles selection; clicks on the checkbox itself stay native.
  selChecks.forEach(cb => {
    const tr = cb.closest('tr');
    tr.classList.add('product-row');
    tr.addEventListener('click', function (e) {
      if (e.target.closest('input, button, a')) return;
      cb.checked = !cb.checked;
      cb.dispatchEvent(new Event('change'));
    });
  });
  syncSel();
}
</script>
<?php layout_end(); ?>
