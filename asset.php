<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT * FROM inventory WHERE id = ?');
$st->execute([$id]);
$asset = $st->fetch();
if (!$asset) {
    flash('Asset not found.', 'danger');
    redirect('inventory.php');
}

$st = db()->prepare(
    'SELECT ii.*, i.assign_no, i.user_name, i.user_email, i.project_code, i.assign_date, i.expected_return
     FROM assign_items ii JOIN assigns i ON i.id = ii.assign_id
     WHERE ii.inventory_id = ? ORDER BY i.assign_date DESC, ii.id DESC'
);
$st->execute([$id]);
$history = $st->fetchAll();

$warranty = warranty_status($asset['warranty_expiry']);
$photoFile = trim($asset['photo']) !== '' && is_file(dirname(__DIR__) . '/' . $asset['photo']) ? $asset['photo'] : '';

layout_start('Asset ' . $asset['asset_number'], 'inventory', $asset['asset_name'] . ' · complete assignment history', 'clock-history', true, 'Search assets, users, assignments…');
?>
<div class="card mb-4">
  <div class="card-body d-flex align-items-center gap-3 flex-wrap">
    <?= $photoFile
        ? '<img class="asset-photo" src="' . h($photoFile) . '?v=' . filemtime(dirname(__DIR__) . '/' . $photoFile) . '" alt="" style="width:54px;height:54px;">'
        : asset_thumb($asset['category'], $asset['asset_name']) ?>
    <div>
      <div style="font-weight:800;font-size:17px;"><?= h($asset['asset_number']) ?> — <?= h($asset['asset_name']) ?></div>
      <div class="text-muted small">
        <?= h($asset['category']) ?> · <?= h(trim($asset['brand'] . ' ' . $asset['model'])) ?: '—' ?>
        · Serial: <?= h($asset['serial_number'] ?: '—') ?>
        <?php if ($asset['condition_note']): ?> · Condition: <?= h($asset['condition_note']) ?><?php endif; ?>
      </div>
      <?php if ($warranty): ?>
        <div class="small mt-1"><span class="text-muted">Warranty:</span> <?= h(date('d M Y', strtotime($asset['warranty_expiry']))) ?>
          <span class="badge bg-<?= $warranty[1] ?> ms-1"><?= $warranty[0] ?></span></div>
      <?php endif; ?>
    </div>
    <div class="ms-auto d-flex align-items-center gap-2 no-print">
      <a class="btn btn-sm btn-outline-secondary" href="inventory.php?edit=<?= (int)$asset['id'] ?>"><i class="bi bi-pencil"></i> Edit</a>
      <a class="btn btn-sm btn-outline-primary" href="labels.php?ids=<?= (int)$asset['id'] ?>"><i class="bi bi-upc-scan"></i> Print Label</a>
      <span><?= badge($asset['status']) ?></span>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header d-flex align-items-center">
        <span><i class="bi bi-clock-history text-primary"></i> Asset History (<?= count($history) ?>)</span>
        <a class="btn btn-sm btn-light ms-auto no-print" href="inventory.php"><i class="bi bi-arrow-left"></i> Back to Inventory</a>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr><th>Assign ID</th><th>User</th><th>Project</th><th>Assign Date</th><th>Expected Return</th><th>Returned</th><th>Condition</th><th>Status</th></tr>
          </thead>
          <tbody>
            <?php if (!$history): ?>
              <tr><td colspan="8" class="text-center text-muted py-4">This asset has never been assigned.</td></tr>
            <?php endif; ?>
            <?php foreach ($history as $h): ?>
              <tr>
                <td><a href="assign_view.php?id=<?= $h['assign_id'] ?>"><?= h($h['assign_no']) ?></a></td>
                <td><?= h($h['user_name']) ?><div class="small text-muted"><?= h($h['user_email']) ?></div></td>
                <td><?= h($h['project_code']) ?></td>
                <td><?= h(date('d M Y', strtotime($h['assign_date']))) ?></td>
                <td><?= h(date('d M Y', strtotime($h['expected_return']))) ?></td>
                <td><?= $h['return_date'] ? h(date('d M Y', strtotime($h['return_date']))) : '—' ?></td>
                <td class="small"><?= h($h['return_condition'] ?: $h['assign_condition']) ?></td>
                <td><?= badge($h['return_status']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-cash-coin text-primary"></i> Purchase &amp; Warranty</div>
      <div class="card-body py-2">
        <?php
        $wBadge = $warranty ? ' <span class="badge bg-' . $warranty[1] . '">' . $warranty[0] . '</span>' : '';
        $finRows = [
          ['calendar3', 'Purchase Date', $asset['purchase_date'] ? date('d M Y', strtotime($asset['purchase_date'])) : '—'],
          ['cash-coin', 'Cost', money($asset['cost'])],
          ['shop', 'Vendor / Supplier', h($asset['vendor'] ?: '—')],
          ['receipt', 'Invoice #', h($asset['invoice_no'] ?: '—')],
          ['shield-check', 'Warranty Expiry', ($asset['warranty_expiry'] ? date('d M Y', strtotime($asset['warranty_expiry'])) : '—') . $wBadge],
        ];
        foreach ($finRows as [$ic, $l, $v]): ?>
          <div class="sys-li">
            <span class="i"><i class="bi bi-<?= $ic ?>"></i></span>
            <span><?= $l ?></span>
            <span class="v"><?= $v ?></span>
          </div>
        <?php endforeach; ?>
        <?php if (!$asset['purchase_date'] && $asset['cost'] === null && !$asset['warranty_expiry']): ?>
          <div class="small text-muted py-2">No purchase details recorded yet — add them via <a href="inventory.php?edit=<?= (int)$asset['id'] ?>">Edit</a>.</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><i class="bi bi-qr-code text-primary"></i> Asset QR</div>
      <div class="card-body text-center">
        <div id="assetQr" class="d-inline-block p-2 bg-white rounded border" data-qr="<?= h(rtrim(APP_URL, '/') . '/asset.php?id=' . (int)$asset['id']) ?>"></div>
        <div class="small text-muted mt-2">Scan to open this asset — print it as a label from <a href="labels.php?ids=<?= (int)$asset['id'] ?>">Asset Labels</a>.</div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
(function () {
  var el = document.getElementById('assetQr');
  if (el && window.QRCode) {
    try { new QRCode(el, { text: el.dataset.qr, width: 128, height: 128, correctLevel: QRCode.CorrectLevel.M }); }
    catch (e) { el.textContent = el.dataset.qr; }
  }
})();
</script>
<?php layout_end(); ?>
