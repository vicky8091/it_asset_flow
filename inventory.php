<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_login();

$STATUSES = ['Available', 'Issued', 'Damaged', 'Missing', 'Under Repair', 'Retired'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $purchase = iso_date($_POST['purchase_date'] ?? null);
        $warranty = iso_date($_POST['warranty_expiry'] ?? null);
        $costRaw = str_replace(',', '', trim($_POST['cost'] ?? ''));
        $cost = $costRaw === '' ? null : (is_numeric($costRaw) && (float)$costRaw >= 0 ? round((float)$costRaw, 2) : false);
        // Inline category creation: the form's select offers "+ Add new category…" to admins.
        $category = trim($_POST['category'] ?? '');
        if ($category === '__new__') $category = trim($_POST['new_category'] ?? '');
        $data = [
            'asset_number' => strtoupper(trim($_POST['asset_number'] ?? '')),
            'asset_name' => trim($_POST['asset_name'] ?? ''),
            'category' => $category,
            'brand' => trim($_POST['brand'] ?? ''),
            'model' => trim($_POST['model'] ?? ''),
            'serial_number' => trim($_POST['serial_number'] ?? ''),
            'status' => in_array($_POST['status'] ?? '', $STATUSES, true) ? $_POST['status'] : 'Available',
            'condition_note' => trim($_POST['condition_note'] ?? ''),
            'remarks' => trim($_POST['remarks'] ?? ''),
            'purchase_date' => $purchase,
            'cost' => $cost === false ? null : $cost,
            'vendor' => trim($_POST['vendor'] ?? ''),
            'invoice_no' => trim($_POST['invoice_no'] ?? ''),
            'warranty_expiry' => $warranty,
        ];
        $currentPhoto = trim($_POST['current_photo'] ?? '');
        $errs = [];
        if ($data['asset_number'] === '' || $data['asset_name'] === '') $errs[] = 'Asset number and name are required.';
        if (($_POST['category'] ?? '') === '__new__' && trim($_POST['new_category'] ?? '') === '') $errs[] = 'Enter the new category name, or pick an existing one.';
        if (($_POST['purchase_date'] ?? '') !== '' && $purchase === null) $errs[] = 'Purchase date is not a valid date.';
        if (($_POST['warranty_expiry'] ?? '') !== '' && $warranty === null) $errs[] = 'Warranty expiry is not a valid date.';
        if ($cost === false) $errs[] = 'Cost must be a number (0 or more).';
        if ($errs) {
            foreach ($errs as $e) flash($e, 'danger');
        } else {
            try {
                ensure_category($data['category']);
                if ($id) {
                    $cur = db()->prepare('SELECT status FROM inventory WHERE id = ?');
                    $cur->execute([$id]);
                    if ((string)$cur->fetchColumn() === 'Issued' && $data['status'] !== 'Issued') {
                        flash("Asset {$data['asset_number']} is still out on loan — mark its item returned before changing the status.", 'warning');
                    } else {
                        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($data)));
                        db()->prepare("UPDATE inventory SET $set WHERE id = ?")
                            ->execute([...array_values($data), $id]);
                        $photoVal = asset_photo_from_form($id, $_FILES['photo'] ?? null, isset($_POST['remove_photo']), $currentPhoto);
                        if ($photoVal !== $currentPhoto) {
                            db()->prepare('UPDATE inventory SET photo = ? WHERE id = ?')->execute([$photoVal, $id]);
                        }
                        log_action('asset_edited', $data['asset_number']);
                        flash('Asset updated.');
                        redirect('inventory.php');
                    }
                } else {
                    $cols = implode(', ', array_keys($data));
                    $ph = implode(', ', array_fill(0, count($data), '?'));
                    db()->prepare("INSERT INTO inventory ($cols) VALUES ($ph)")
                        ->execute(array_values($data));
                    $newId = (int)db()->lastInsertId();
                    $photoVal = asset_photo_from_form($newId, $_FILES['photo'] ?? null, false, '');
                    if ($photoVal !== '') {
                        db()->prepare('UPDATE inventory SET photo = ? WHERE id = ?')->execute([$photoVal, $newId]);
                    }
                    log_action('asset_added', $data['asset_number']);
                    flash('Asset added.');
                    redirect('inventory.php');
                }
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    flash('Duplicate asset number or serial number.', 'danger');
                } else {
                    throw $e;
                }
            }
        }
        $edit = ['id' => $id, 'photo' => $currentPhoto] + $data;
    } elseif ($action === 'delete') {
        require_admin();
        try {
            db()->prepare('DELETE FROM inventory WHERE id = ?')->execute([(int)$_POST['id']]);
            log_action('asset_deleted', 'inventory id ' . (int)$_POST['id']);
            flash('Asset deleted.');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                flash('Asset has assignment history and cannot be deleted. Mark it Retired instead.', 'warning');
            } else {
                throw $e;
            }
        }
        redirect('inventory.php');
    } elseif ($action === 'bulk') {
        $bulkIds = array_values(array_filter(array_map('intval', $_POST['bulk_ids'] ?? []), fn($v) => $v > 0));
        $bulkAction = $_POST['bulk_action'] ?? '';
        if (!$bulkIds) {
            flash('Select at least one asset first.', 'warning');
        } elseif ($bulkAction === 'retire') {
            $ph = implode(',', array_fill(0, count($bulkIds), '?'));
            $st = db()->prepare("UPDATE inventory SET status = 'Retired' WHERE id IN ($ph) AND status <> 'Issued'");
            $st->execute($bulkIds);
            $done = $st->rowCount();
            $skipped = count($bulkIds) - $done;
            log_action('asset_bulk_retired', "$done asset(s) marked Retired");
            flash("$done asset(s) marked Retired." . ($skipped ? " $skipped skipped — still out on loan; return the item first." : ''), $skipped ? 'warning' : 'success');
        } elseif ($bulkAction === 'delete') {
            require_admin();
            $done = $skipped = 0;
            $del = db()->prepare('DELETE FROM inventory WHERE id = ?');
            foreach ($bulkIds as $bid) {
                try {
                    $del->execute([$bid]);
                    $done += $del->rowCount();
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') $skipped++;
                    else throw $e;
                }
            }
            log_action('asset_deleted', "bulk: $done deleted, $skipped skipped");
            flash("$done asset(s) deleted." . ($skipped ? " $skipped skipped — they have assignment history; mark them Retired instead." : ''), $skipped ? 'warning' : 'success');
        } else {
            flash('Choose a bulk action first.', 'warning');
        }
        redirect('inventory.php');
    }
}

$status = $_GET['status'] ?? '';
$q = trim($_GET['q'] ?? '');
$catFilter = trim($_GET['category'] ?? '');
$edit = $edit ?? null;
if (!$edit && isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM inventory WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch() ?: null;
}
if (!$edit && isset($_GET['add'])) {
    $edit = ['id' => 0, 'photo' => ''] + array_fill_keys(
        ['asset_number', 'asset_name', 'category', 'brand', 'model', 'serial_number', 'status', 'condition_note', 'remarks',
         'purchase_date', 'cost', 'vendor', 'invoice_no', 'warranty_expiry'], ''
    );
    // Duplicate an existing asset into the add form — number and serial stay blank (they must be unique).
    if (isset($_GET['clone'])) {
        $st = db()->prepare('SELECT * FROM inventory WHERE id = ?');
        $st->execute([(int)$_GET['clone']]);
        if ($src = $st->fetch()) {
            foreach (['asset_name', 'category', 'brand', 'model', 'condition_note', 'remarks',
                      'purchase_date', 'cost', 'vendor', 'invoice_no', 'warranty_expiry'] as $k) {
                $edit[$k] = $src[$k];
            }
            $edit['status'] = 'Available';
        }
    }
}

$where = [];
$params = [];
if ($status !== '' && in_array($status, $STATUSES, true)) {
    $where[] = 'iv.status = ?';
    $params[] = $status;
}
if ($catFilter !== '') {
    $where[] = 'iv.category = ?';
    $params[] = $catFilter;
}
if ($q !== '') {
    $where[] = '(iv.asset_number LIKE ? OR iv.asset_name LIKE ? OR iv.serial_number LIKE ? OR iv.brand LIKE ? OR iv.model LIKE ? OR iv.category LIKE ?)';
    $like = "%$q%";
    $params = [...$params, ...array_fill(0, 6, $like)];
}
$sql = 'SELECT iv.*,
          (SELECT i.user_name FROM assign_items ii JOIN assigns i ON i.id = ii.assign_id
           WHERE ii.inventory_id = iv.id AND ii.return_status = \'Pending\' LIMIT 1) AS holder
        FROM inventory iv' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY iv.asset_number';
[$assets, $page, $pages, $totalInv] = paginate($sql, $params, 15);

$tabCounts = db()->query('SELECT status, COUNT(*) c FROM inventory GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$nav = nav_counts();
$statCards = [
    ['Total Assets', array_sum($tabCounts), 'box-seam', 'blue', ''],
    ['Available', (int)($tabCounts['Available'] ?? 0), 'check-circle', 'green', 'Available'],
    ['Assigned', (int)($tabCounts['Issued'] ?? 0), 'arrow-up-right-circle', 'orange', 'Issued'],
    ['Overdue', $nav['overdue'], 'exclamation-triangle', 'red', null],
    ['Missing', (int)($tabCounts['Missing'] ?? 0), 'question-circle', 'purple', 'Missing'],
    ['Damaged', (int)($tabCounts['Damaged'] ?? 0) + (int)($tabCounts['Under Repair'] ?? 0), 'hammer', 'slate', 'Damaged'],
];

layout_start('Inventory', 'inventory', 'View and manage all IT equipment and assets.', 'box-seam', true, 'Search equipment, asset ID, serial number…');

$categories = db()->query('SELECT DISTINCT category FROM inventory WHERE category <> \'\' ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);
?>
<?php if ($edit): ?>
<div class="card mb-4">
  <div class="card-header d-flex align-items-center">
    <span><i class="bi bi-<?= $edit['id'] ? 'pencil' : 'plus-circle' ?> text-primary"></i> <?= $edit['id'] ? 'Edit Asset' : 'Add New Asset' ?><?= isset($_GET['clone']) ? ' <span class="badge bg-info text-dark ms-1">Duplicated — set a new asset number</span>' : '' ?></span>
    <a class="btn btn-sm btn-light ms-auto" href="inventory.php">Cancel</a>
  </div>
  <div class="card-body">
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
      <div class="row g-3">
        <div class="col-md-3"><label class="form-label">Asset Number <span class="req">*</span></label>
          <input class="form-control" name="asset_number" value="<?= h($edit['asset_number']) ?>" <?= $edit['id'] || !isset($_GET['clone']) ? 'required' : 'required placeholder="e.g. IT-LAP-034"' ?>></div>
        <div class="col-md-4"><label class="form-label">Asset Name <span class="req">*</span></label>
          <input class="form-control" name="asset_name" value="<?= h($edit['asset_name']) ?>" required></div>
        <div class="col-md-3"><label class="form-label">Category</label>
          <?php $catOptions = category_names();
          if ($edit['category'] !== '' && !in_array($edit['category'], $catOptions, true)) $catOptions[] = $edit['category'];
          sort($catOptions, SORT_NATURAL | SORT_FLAG_CASE); ?>
          <select class="form-select" name="category" id="categorySel">
            <option value="">— No category —</option>
            <?php foreach ($catOptions as $cg): ?>
              <option value="<?= h($cg) ?>" <?= $edit['category'] === $cg ? 'selected' : '' ?>><?= h($cg) ?></option>
            <?php endforeach; ?>
            <?php if (($_SESSION['admin']['role'] ?? 'admin') === 'admin'): ?>
              <option value="__new__" <?= ($_POST['category'] ?? '') === '__new__' ? 'selected' : '' ?>>+ Add new category…</option>
            <?php endif; ?>
          </select>
          <div id="newCatWrap" class="mt-2" style="display:none;">
            <input class="form-control" name="new_category" value="<?= h(($_POST['category'] ?? '') === '__new__' ? ($_POST['new_category'] ?? '') : '') ?>"
                   maxlength="100" placeholder="New category name, e.g. Tablet">
            <div class="form-text">Created together with the asset.</div>
          </div></div>
        <div class="col-md-2"><label class="form-label">Status</label>
          <select class="form-select" name="status">
            <?php foreach ($STATUSES as $s): ?>
              <option value="<?= $s ?>" <?= $edit['status'] === $s ? 'selected' : '' ?>><?= status_label($s) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="col-md-3"><label class="form-label">Brand</label>
          <input class="form-control" name="brand" value="<?= h($edit['brand']) ?>"></div>
        <div class="col-md-3"><label class="form-label">Model</label>
          <input class="form-control" name="model" value="<?= h($edit['model']) ?>"></div>
        <div class="col-md-3"><label class="form-label">Serial Number</label>
          <input class="form-control" name="serial_number" value="<?= h($edit['serial_number']) ?>"></div>
        <div class="col-md-3"><label class="form-label">Condition Note</label>
          <input class="form-control" name="condition_note" value="<?= h($edit['condition_note']) ?>"></div>
        <div class="col-12"><label class="form-label">Remarks</label>
          <input class="form-control" name="remarks" value="<?= h($edit['remarks']) ?>"></div>
        <div class="col-12"><div class="fw-bold small text-muted mt-2 mb-0" style="letter-spacing:.4px;">PURCHASE &amp; WARRANTY</div><hr class="mt-1 mb-0"></div>
        <div class="col-md-2"><label class="form-label">Purchase Date</label>
          <input class="form-control" type="date" name="purchase_date" value="<?= h($edit['purchase_date'] ?? '') ?>"></div>
        <div class="col-md-2"><label class="form-label">Cost (<?= h(get_setting('currency_symbol', '₹')) ?>)</label>
          <input class="form-control" type="number" step="0.01" min="0" name="cost" value="<?= h($edit['cost'] ?? '') ?>" placeholder="0.00"></div>
        <div class="col-md-3"><label class="form-label">Vendor / Supplier</label>
          <input class="form-control" name="vendor" value="<?= h($edit['vendor'] ?? '') ?>" placeholder="Where it was bought"></div>
        <div class="col-md-2"><label class="form-label">Invoice #</label>
          <input class="form-control" name="invoice_no" value="<?= h($edit['invoice_no'] ?? '') ?>"></div>
        <div class="col-md-3"><label class="form-label">Warranty Expiry</label>
          <input class="form-control" type="date" name="warranty_expiry" value="<?= h($edit['warranty_expiry'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label">Photo</label>
          <input class="form-control" type="file" name="photo" accept=".png,.jpg,.jpeg,.webp">
          <div class="form-text">PNG, JPG or WebP · max <?= round(ICON_MAX_BYTES / 1048576) ?> MB · up to <?= ICON_MAX_DIM ?>×<?= ICON_MAX_DIM ?>px.</div>
          <?php $photoFile = trim($edit['photo'] ?? '') !== '' && is_file(dirname(__DIR__) . '/' . $edit['photo']) ? $edit['photo'] : ''; ?>
          <?php if ($photoFile): ?>
            <div class="d-flex align-items-center gap-2 mt-2">
              <img class="asset-photo" src="<?= h($photoFile) ?>?v=<?= filemtime(dirname(__DIR__) . '/' . $photoFile) ?>" alt="" style="width:44px;height:44px;">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="remove_photo" value="1" id="removePhoto">
                <label class="form-check-label small" for="removePhoto">Remove photo</label>
              </div>
            </div>
            <input type="hidden" name="current_photo" value="<?= h($edit['photo']) ?>">
          <?php endif; ?></div>
      </div>
      <div class="mt-3">
        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= $edit['id'] ? 'Save Changes' : 'Add Asset' ?></button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <?php foreach ($statCards as [$label, $num, $icon, $color, $stab]): ?>
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
          <?php if ($stab !== null): ?>
            <a class="more text-primary" href="inventory.php<?= $stab !== '' ? '?status=' . urlencode($stab) : '' ?>">View All <i class="bi bi-arrow-right"></i></a>
          <?php else: ?>
            <a class="more text-primary" href="assigns.php?status=overdue">View All <i class="bi bi-arrow-right"></i></a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<form class="card mb-4 filter-bar" method="get">
  <div class="card-body">
    <div class="row g-3 align-items-end">
      <div class="col-md-4">
        <label class="f-label">Search</label>
        <div class="position-relative">
          <i class="bi bi-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:13px;"></i>
          <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Search by asset name, serial number, brand…" style="padding-left:34px">
        </div>
      </div>
      <div class="col-md-3">
        <label class="f-label">Category</label>
        <select class="form-select" name="category">
          <option value="">All Categories</option>
          <?php foreach ($categories as $cg): ?>
            <option <?= $catFilter === $cg ? 'selected' : '' ?>><?= h($cg) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="f-label">Status</label>
        <select class="form-select" name="status">
          <option value="">All Statuses</option>
          <?php foreach ($STATUSES as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= status_label($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-1">
        <button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Apply</button>
      </div>
      <div class="col-md-3">
        <div class="d-flex gap-2">
          <a class="btn btn-outline-success flex-grow-1" href="reports.php?type=inventory&csv=1"><i class="bi bi-download"></i> CSV</a>
          <a class="btn btn-outline-primary flex-grow-1" href="labels.php<?= ($lq = http_build_query(array_filter(['status' => $status, 'category' => $catFilter, 'q' => $q], fn($v) => $v !== ''))) ? '?' . $lq : '' ?>"><i class="bi bi-upc-scan"></i> Labels</a>
          <a class="btn btn-outline-secondary flex-grow-1" href="reports.php?type=inventory&xlsx=1"><i class="bi bi-file-earmark-excel"></i> Excel</a>
        </div>
      </div>
    </div>
  </div>
</form>

<div class="card">
  <div class="card-header d-flex align-items-center flex-wrap gap-2">
    <span><i class="bi bi-box-seam text-primary"></i> Assets (<?= array_sum($tabCounts) ?>)</span>
    <div class="ms-auto d-flex gap-2 no-print">
      <a class="btn btn-sm btn-primary" href="inventory.php?add=1"><i class="bi bi-plus-lg"></i> Add Asset</a>
      <a class="btn btn-sm btn-outline-primary" href="import.php"><i class="bi bi-upload"></i> Import CSV</a>
    </div>
  </div>
  <div class="card-body py-2 border-bottom">
    <div class="d-flex flex-wrap gap-2">
      <a class="chip <?= $status === '' ? 'active' : '' ?>" href="inventory.php">All <span class="n"><?= array_sum($tabCounts) ?></span></a>
      <?php foreach ($STATUSES as $s): ?>
        <a class="chip <?= $status === $s ? 'active' : '' ?>" href="inventory.php?status=<?= urlencode($s) ?>">
          <?= status_label($s) ?> <span class="n"><?= (int)($tabCounts[$s] ?? 0) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead>
        <tr>
          <th class="no-print" style="width:34px"><input type="checkbox" class="form-check-input" id="bulkAll" title="Select all on this page"></th>
          <th>Asset #</th><th>Name</th><th>Category</th><th>Brand / Model</th><th>Serial #</th><th>Cost</th><th>Status</th><th>Assigned To</th><th class="no-print">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$assets): ?>
          <tr><td colspan="10" class="empty"><i class="bi bi-inbox"></i>No assets found.</td></tr>
        <?php endif; ?>
        <?php foreach ($assets as $n => $a): ?>
          <tr>
            <td class="no-print"><input type="checkbox" class="form-check-input bulk-row" value="<?= (int)$a['id'] ?>" form="bulkForm" aria-label="Select <?= h($a['asset_number']) ?>"></td>
            <td class="nw" style="font-weight:700"><?= h($a['asset_number']) ?></td>
            <td>
              <div class="entity">
                <?= asset_media($a) ?>
                <div class="t"><?= h($a['asset_name']) ?></div>
              </div>
            </td>
            <td class="small text-muted"><?= h($a['category']) ?></td>
            <td class="small"><?= h(trim($a['brand'] . ' ' . $a['model'])) ?: '—' ?></td>
            <td class="small text-muted"><?= h($a['serial_number'] ?: '—') ?></td>
            <td class="small text-nowrap"><?= money($a['cost']) ?></td>
            <td><?= badge($a['status']) ?></td>
            <td class="small"><?= $a['holder'] ? h($a['holder']) : '<span class="text-muted">—</span>' ?></td>
            <td class="no-print">
              <div class="d-flex gap-2">
                <a class="btn btn-sm btn-outline-primary icon-btn" href="asset.php?id=<?= $a['id'] ?>" title="History"><i class="bi bi-eye"></i></a>
                <a class="btn btn-sm btn-outline-secondary icon-btn" href="inventory.php?edit=<?= $a['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></a>
                <a class="btn btn-sm btn-outline-secondary icon-btn" href="inventory.php?add=1&clone=<?= $a['id'] ?>" title="Duplicate"><i class="bi bi-copy"></i></a>
                <form method="post" data-confirm="Delete asset <?= h($a['asset_number']) ?>?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $a['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger icon-btn" title="Delete"><i class="bi bi-trash"></i></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pager_nav($page, $pages, $totalInv, 15, count($assets)) ?>
</div>

<form method="post" id="bulkForm" class="card bulk-bar no-print" hidden>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="bulk">
  <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
    <span class="small" style="font-weight:600"><span id="bulkCount">0</span> selected</span>
    <select name="bulk_action" class="form-select form-select-sm w-auto">
      <option value="">Choose action…</option>
      <option value="retire">Mark Retired</option>
      <option value="labels">Print Labels</option>
      <?php if (($_SESSION['admin']['role'] ?? 'admin') === 'admin'): ?><option value="delete">Delete</option><?php endif; ?>
    </select>
    <button class="btn btn-sm btn-primary">Apply</button>
    <span class="small text-muted ms-auto d-none d-md-inline">Labels opens a printable QR sheet for the selected assets.</span>
  </div>
</form>

<script>
// Inline category creation: reveal the name input when "+ Add new category…" is picked.
var catSel = document.getElementById('categorySel');
if (catSel) {
  var toggleNewCat = function () {
    var w = document.getElementById('newCatWrap');
    if (w) w.style.display = catSel.value === '__new__' ? '' : 'none';
  };
  catSel.addEventListener('change', toggleNewCat);
  toggleNewCat();
}
(function () {
  var bulkForm = document.getElementById('bulkForm');
  if (!bulkForm) return;
  var boxes = document.querySelectorAll('.bulk-row');
  var all = document.getElementById('bulkAll');
  var count = document.getElementById('bulkCount');
  var sel = bulkForm.querySelector('select');
  var sync = function () {
    var checked = document.querySelectorAll('.bulk-row:checked');
    count.textContent = checked.length;
    bulkForm.hidden = checked.length === 0;
    if (all) all.checked = checked.length > 0 && checked.length === boxes.length;
  };
  boxes.forEach(function (b) { b.addEventListener('change', sync); });
  if (all) all.addEventListener('change', function () {
    boxes.forEach(function (b) { b.checked = all.checked; });
    sync();
  });
  bulkForm.addEventListener('submit', function (e) {
    var ids = Array.prototype.map.call(document.querySelectorAll('.bulk-row:checked'), function (b) { return b.value; });
    if (sel.value === 'labels') {
      e.preventDefault();
      location.href = 'labels.php?ids=' + ids.join(',');
    } else if (sel.value === 'delete') {
      if (!confirm('Delete the selected asset(s)? Assets with assignment history are skipped.')) e.preventDefault();
    } else if (sel.value === 'retire') {
      if (!confirm('Mark the selected asset(s) as Retired?')) e.preventDefault();
    } else {
      e.preventDefault();
      alert('Choose a bulk action first.');
    }
  });
  sync();
})();
</script>
<?php layout_end(); ?>
