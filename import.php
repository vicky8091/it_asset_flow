<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/xlsx.php';
require_login();

$IMPORT_STATUSES = ['Available', 'Damaged', 'Missing', 'Under Repair', 'Retired']; // "Issued" only comes from issuing

if (isset($_GET['download'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="inventory_template.csv"');
    echo "asset_name,asset_category,asset_number,serial_number,brand,model,status,remarks,purchase_date,cost,vendor,invoice_no,warranty_expiry\n";
    echo "Laptop,Dell Laptop,IT-LAP-001,ABC123456,Dell,Latitude 5420,Available,,2026-01-15,54999,Dell India,INV-1001,2029-01-15\n";
    echo "PPT Remote,Presentation Remote,IT-REM-001,SN001,Logitech,R400,Available,,,,,,\n";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Step 1: upload + validate + preview (accepts .csv and .xlsx)
    if (($_POST['action'] ?? '') === 'upload') {
        $f = $_FILES['csv'] ?? null;
        $ext = strtolower(pathinfo($f['name'] ?? '', PATHINFO_EXTENSION));
        $mime = $f && is_uploaded_file($f['tmp_name']) ? (string)(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) : '';
        $mimeOk = preg_match('#^(text/plain|text/csv|application/csv|text/comma-separated-values|application/vnd\.ms-excel|application/zip|application/x-zip-compressed|application/vnd\.openxmlformats-officedocument\.spreadsheetml\.sheet)#', $mime);

        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            flash('Upload failed. Please try again.', 'danger');
        } elseif (!in_array($ext, ['csv', 'xlsx'], true)) {
            flash('Only .csv or .xlsx files are accepted.', 'danger');
        } elseif ($f['size'] > UPLOAD_MAX_BYTES) {
            flash('File too large (max ' . (UPLOAD_MAX_BYTES / 1048576) . ' MB).', 'danger');
        } elseif (!$mimeOk) {
            flash('File does not look like a CSV or Excel file.', 'danger');
        } else {
            // Parse into [header (lowercased), rows (numeric-keyed arrays)]
            $header = $dataRows = [];
            try {
                if ($ext === 'xlsx') {
                    [$header, $dataRows] = xlsx_rows($f['tmp_name']);
                } else {
                    $fh = fopen($f['tmp_name'], 'r');
                    $header = array_map(fn($v) => strtolower(trim((string)$v)), fgetcsv($fh) ?: []);
                    if (isset($header[0])) $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]); // Excel "CSV UTF-8" BOM
                    while (($r = fgetcsv($fh)) !== false) {
                        $dataRows[] = $r;
                    }
                    fclose($fh);
                }
            } catch (RuntimeException $e) {
                flash(h($e->getMessage()), 'danger');
                redirect('import.php');
            }

            $catKey = in_array('asset_category', $header, true) ? 'asset_category' : (in_array('category', $header, true) ? 'category' : null);
            if (!in_array('asset_number', $header, true) || !in_array('asset_name', $header, true) || !$catKey) {
                flash('The file must contain columns: asset_name, asset_category, asset_number, serial_number, brand, model, status, remarks.', 'danger');
            } else {
                // Existing keys for duplicate detection (two queries beat a row-per-row query).
                $existingAssets = array_flip(db()->query('SELECT asset_number FROM inventory')->fetchAll(PDO::FETCH_COLUMN));
                $existingSerials = array_flip(db()->query("SELECT serial_number FROM inventory WHERE serial_number <> ''")->fetchAll(PDO::FETCH_COLUMN));

                $rows = [];
                $seenAssets = [];
                $seenSerials = [];
                $line = 1;
                foreach ($dataRows as $r) {
                    $line++;
                    $row = [];
                    foreach ($header as $i => $key) {
                        $row[$key] = trim((string)($r[$i] ?? ''));
                    }
                    $row += array_fill_keys(
                        ['asset_name', 'asset_number', 'serial_number', 'brand', 'model', 'status', 'remarks',
                         'purchase_date', 'cost', 'vendor', 'invoice_no', 'warranty_expiry'], ''
                    );
                    if (implode('', $row) === '') continue; // blank row

                    $errors = [];
                    $assetNo = strtoupper($row['asset_number']);
                    if ($assetNo === '') $errors[] = 'missing asset_number';
                    if ($row['asset_name'] === '') $errors[] = 'missing asset_name';
                    $statusIn = $row['status'] === '' ? '' : ucwords(strtolower($row['status']));
                    if ($statusIn === '') $statusIn = 'Available';
                    if (!in_array($statusIn, $IMPORT_STATUSES, true)) $errors[] = "invalid status '$row[status]'";
                    if ($assetNo !== '' && (isset($existingAssets[$assetNo]) || isset($seenAssets[$assetNo]))) $errors[] = 'duplicate asset_number';
                    if ($row['serial_number'] !== '' && (isset($existingSerials[$row['serial_number']]) || isset($seenSerials[$row['serial_number']]))) $errors[] = 'duplicate serial_number';

                    $purchase = iso_date($row['purchase_date']);
                    if ($row['purchase_date'] !== '' && !$purchase) $errors[] = 'invalid purchase_date';
                    $warranty = iso_date($row['warranty_expiry']);
                    if ($row['warranty_expiry'] !== '' && !$warranty) $errors[] = 'invalid warranty_expiry';
                    $costRaw = str_replace(',', '', $row['cost']);
                    $cost = null;
                    if ($costRaw !== '') {
                        if (!is_numeric($costRaw) || (float)$costRaw < 0) {
                            $errors[] = "invalid cost '$row[cost]'";
                        } else {
                            $cost = round((float)$costRaw, 2);
                        }
                    }
                    $seenAssets[$assetNo] = $seenSerials[$row['serial_number']] = true;

                    $rows[] = [
                        'line' => $line,
                        'asset_number' => $assetNo,
                        'asset_name' => $row['asset_name'],
                        'category' => $row[$catKey],
                        'brand' => $row['brand'],
                        'model' => $row['model'],
                        'serial_number' => $row['serial_number'],
                        'status' => $statusIn,
                        'remarks' => $row['remarks'],
                        'purchase_date' => $purchase,
                        'cost' => $cost,
                        'vendor' => $row['vendor'],
                        'invoice_no' => $row['invoice_no'],
                        'warranty_expiry' => $warranty,
                        'errors' => $errors,
                    ];
                }

                if (!$rows) {
                    flash('No data rows found in the file.', 'danger');
                } else {
                    $_SESSION['import_rows'] = $rows;
                }
            }
        }
        redirect('import.php');
    }

    // Step 2: confirm — insert the valid rows staged in the session
    if (($_POST['action'] ?? '') === 'confirm') {
        $rows = $_SESSION['import_rows'] ?? [];
        $imported = $dup = $failed = $skipped = 0;
        $ins = db()->prepare(
            'INSERT INTO inventory (asset_number, asset_name, category, brand, model, serial_number, status, remarks,
                                    purchase_date, cost, vendor, invoice_no, warranty_expiry)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($rows as $row) {
            if (!empty($row['errors'])) { $skipped++; continue; }
            try {
                $ins->execute([
                    $row['asset_number'], $row['asset_name'], $row['category'], $row['brand'], $row['model'],
                    $row['serial_number'], $row['status'], $row['remarks'],
                    $row['purchase_date'], $row['cost'], $row['vendor'], $row['invoice_no'], $row['warranty_expiry'],
                ]);
                ensure_category($row['category']);
                $imported++;
            } catch (PDOException $e) {
                $e->getCode() === '23000' ? $dup++ : $failed++;
            }
        }
        unset($_SESSION['import_rows']);
        log_action('inventory_imported', "imported $imported, duplicates $dup, invalid skipped $skipped, failed $failed");
        flash("Import finished: $imported imported, $dup duplicates, $skipped invalid skipped, $failed failed.", $failed || $dup || $skipped ? 'warning' : 'success');
        redirect('inventory.php');
    }
}

$rows = $_SESSION['import_rows'] ?? null;
layout_start('Import Inventory', 'inventory', 'Bulk import assets from CSV or Excel with validation.', 'upload');
?>
<?php if (!is_array($rows)): ?>
  <div class="card" style="max-width:680px;">
    <div class="card-header"><i class="bi bi-upload text-primary"></i> Upload File</div>
    <div class="card-body">
      <h5><i class="bi bi-upload"></i> Import Inventory from CSV or Excel</h5>
      <p class="text-muted">Upload your existing inventory as <strong>.csv</strong> or <strong>.xlsx</strong>. You will see a preview
        with validation errors before anything is saved. The first row must be the header
        (<code>asset_name, asset_category, asset_number, serial_number, brand, model, status, remarks</code> — optional columns:
        <code>purchase_date, cost, vendor, invoice_no, warranty_expiry</code>). Dates accept <code>YYYY-MM-DD</code> or <code>DD/MM/YYYY</code>.</p>
      <p><a href="import.php?download=1" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-arrow-down"></i> Download CSV template</a></p>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload">
        <div class="mb-3">
          <input class="form-control" type="file" name="csv" accept=".csv,.xlsx" required>
        </div>
        <button class="btn btn-primary">Upload &amp; Preview</button>
      </form>
    </div>
  </div>
<?php else: ?>
  <?php
  $valid = array_filter($rows, fn($r) => !$r['errors']);
  $invalid = count($rows) - count($valid);
  ?>
  <div class="alert alert-info">
    <strong>Preview:</strong> <?= count($rows) ?> rows — <?= count($valid) ?> valid, <?= $invalid ?> with errors.
    Only valid rows will be imported. <a href="import.php">Cancel / upload a different file</a>.
  </div>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light">
          <tr><th>Line</th><th>Asset #</th><th>Name</th><th>Category</th><th>Brand</th><th>Model</th><th>Serial #</th><th>Status</th><th>Cost</th><th>Warranty</th><th>Remarks</th><th>Validation</th></tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr class="<?= $r['errors'] ? 'table-danger' : '' ?>">
              <td><?= $r['line'] ?></td>
              <td><?= h($r['asset_number']) ?></td>
              <td><?= h($r['asset_name']) ?></td>
              <td><?= h($r['category']) ?></td>
              <td><?= h($r['brand']) ?></td>
              <td><?= h($r['model']) ?></td>
              <td><?= h($r['serial_number']) ?></td>
              <td><?= h($r['status']) ?></td>
              <td><?= $r['cost'] === null ? '—' : h(number_format((float)$r['cost'], 2)) ?></td>
              <td><?= $r['warranty_expiry'] ? h($r['warranty_expiry']) : '—' ?></td>
              <td><?= h($r['remarks']) ?></td>
              <td><?= $r['errors'] ? '<span class="text-danger small">' . h(implode('; ', $r['errors'])) . '</span>' : '<span class="text-success">OK</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <form method="post" class="mt-3">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="confirm">
    <button class="btn btn-primary" <?= count($valid) ? '' : 'disabled' ?>>Confirm Import (<?= count($valid) ?> rows)</button>
    <a class="btn btn-outline-secondary" href="import.php">Cancel</a>
  </form>
<?php endif; ?>
<?php layout_end(); ?>
