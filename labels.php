<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_login();

$STATUSES = ['Available', 'Issued', 'Damaged', 'Missing', 'Under Repair', 'Retired'];

// Which assets to print: explicit ids (from the inventory bulk action / per-row button),
// or the filter set (status / category / search).
$ids = array_values(array_filter(array_map('intval', explode(',', $_GET['ids'] ?? '')), fn($v) => $v > 0));
$where = [];
$params = [];
if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $where[] = "iv.id IN ($ph)";
    $params = $ids;
} else {
    $status = in_array($_GET['status'] ?? '', $STATUSES, true) ? $_GET['status'] : '';
    $cat = trim($_GET['category'] ?? '');
    $q = trim($_GET['q'] ?? '');
    if ($status !== '') { $where[] = 'iv.status = ?'; $params[] = $status; }
    if ($cat !== '') { $where[] = 'iv.category = ?'; $params[] = $cat; }
    if ($q !== '') {
        $where[] = '(iv.asset_number LIKE ? OR iv.asset_name LIKE ? OR iv.serial_number LIKE ?)';
        $like = "%$q%";
        array_push($params, $like, $like, $like);
    }
}
if (!$where) $where[] = 'iv.status <> \'Retired\''; // default: label everything that can still be scanned

$sql = 'SELECT iv.* FROM inventory iv WHERE ' . implode(' AND ', $where) . ' ORDER BY iv.asset_number LIMIT 500';
$st = db()->prepare($sql);
$st->execute($params);
$assets = $st->fetchAll();

// Scan target: the asset page — APP_URL is the same absolute URL used in emails.
$qrBase = rtrim(APP_URL, '/') . '/asset.php?id=';

$categories = category_names();
$company = get_setting('company_name', APP_NAME);

layout_start('Asset Labels', 'labels', 'Printable QR label sheets — each QR opens the asset page when scanned.', 'upc-scan', true, 'Search assets, users, assignments…');
?>
<div class="card mb-4 no-print">
  <div class="card-body">
    <div class="row g-3 align-items-end">
      <div class="col-md-4">
        <label class="f-label">Search</label>
        <input class="form-control" form="labelFilter" name="q" value="<?= h($_GET['q'] ?? '') ?>" placeholder="Asset #, name or serial…">
      </div>
      <div class="col-md-3">
        <label class="f-label">Category</label>
        <select class="form-select" form="labelFilter" name="category">
          <option value="">All Categories</option>
          <?php foreach ($categories as $cg): ?>
            <option <?= ($_GET['category'] ?? '') === $cg ? 'selected' : '' ?>><?= h($cg) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="f-label">Status</label>
        <select class="form-select" form="labelFilter" name="status">
          <option value="">All Statuses</option>
          <?php foreach ($STATUSES as $s): ?>
            <option value="<?= $s ?>" <?= ($_GET['status'] ?? '') === $s ? 'selected' : '' ?>><?= status_label($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 d-flex gap-2">
        <button class="btn btn-primary flex-grow-0" form="labelFilter"><i class="bi bi-funnel"></i> Apply</button>
        <button type="button" class="btn btn-success flex-grow-1" onclick="window.print()"><i class="bi bi-printer"></i> Print (<?= count($assets) ?>)</button>
      </div>
    </div>
    <div class="form-text mt-2">Scanning a QR opens that asset's page on <?= h(parse_url(APP_URL, PHP_URL_HOST) ?: 'this server') ?> — the phone must be able to reach the same address.</div>
  </div>
</div>
<form id="labelFilter" method="get" hidden></form>

<?php if (!$assets): ?>
  <div class="card"><div class="card-body empty"><i class="bi bi-inbox"></i>No assets match — adjust the filter above.</div></div>
<?php else: ?>
  <div class="label-sheet">
    <?php foreach ($assets as $a): ?>
      <div class="label">
        <div class="qr" data-qr="<?= h($qrBase . (int)$a['id']) ?>"></div>
        <div class="meta">
          <div class="no"><?= h($a['asset_number']) ?></div>
          <div class="nm"><?= h($a['asset_name']) ?></div>
          <div class="co"><?= h($company) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
  <script>
  document.querySelectorAll('[data-qr]').forEach(function (el) {
    try {
      new QRCode(el, { text: el.dataset.qr, width: 84, height: 84, correctLevel: QRCode.CorrectLevel.M });
    } catch (e) {
      el.textContent = el.dataset.qr.replace(/^.*\?/, '?');
    }
  });
  </script>
<?php endif; ?>
<?php layout_end(); ?>
