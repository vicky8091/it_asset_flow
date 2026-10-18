<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/xlsx.php';
require_login();

// type => [title, sql(fn(params,filters)), columns]
$REPORTS = [
    'inventory' => [
        'title' => 'Inventory Report',
        'cols' => [['Asset #', 'asset_number'], ['Name', 'asset_name'], ['Category', 'category'], ['Brand', 'brand'], ['Model', 'model'], ['Serial #', 'serial_number'], ['Status', 'status'], ['Holder', 'holder'], ['Purchase Date', 'purchase_date'], ['Cost', 'cost'], ['Vendor', 'vendor'], ['Invoice #', 'invoice_no'], ['Warranty Expiry', 'warranty_expiry'], ['Remarks', 'remarks']],
    ],
    'assigns' => [
        'title' => 'Assignment Report',
        'cols' => [['Assign ID', 'assign_no'], ['User', 'user_name'], ['Email', 'user_email'], ['Project', 'project_code'], ['Event', 'event_name'], ['Location', 'location'], ['Assign Date', 'assign_date'], ['Expected Return', 'expected_return'], ['Status', 'status'], ['Ack', 'ack_status'], ['Assigned By', 'created_by']],
    ],
    'returns' => [
        'title' => 'Return Report',
        'cols' => [['Assign ID', 'assign_no'], ['User', 'user_name'], ['Project', 'project_code'], ['Item', 'item_name'], ['Asset #', 'asset_number'], ['Serial #', 'serial_number'], ['Return Date', 'return_date'], ['Status', 'return_status'], ['Condition', 'return_condition'], ['Remarks', 'return_remarks']],
    ],
    'missing' => [
        'title' => 'Missing Equipment Report',
        'cols' => [['Asset #', 'asset_number'], ['Name', 'asset_name'], ['Serial #', 'serial_number'], ['Last User', 'holder'], ['Project', 'project_code'], ['Marked Missing', 'updated_at'], ['Remarks', 'remarks']],
    ],
    'damaged' => [
        'title' => 'Damaged Equipment Report',
        'cols' => [['Asset #', 'asset_number'], ['Name', 'asset_name'], ['Serial #', 'serial_number'], ['Last User', 'holder'], ['Project', 'project_code'], ['Status', 'status'], ['Condition Note', 'condition_note'], ['Remarks', 'remarks']],
    ],
    'users' => [
        'title' => 'User Equipment History',
        'cols' => [['Name', 'user_name'], ['Email', 'user_email'], ['Total Assignments', 'total'], ['Active', 'active'], ['Returned Assignments', 'returned'], ['Last Assignment', 'last_issue']],
    ],
];

$type = $_GET['type'] ?? 'inventory';
if (!isset($REPORTS[$type])) $type = 'inventory';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$q = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';

$where = [];
$params = [];
$dateCol = ['assigns' => 'i.assign_date', 'returns' => 'ii.return_date'][$type] ?? null;

if ($dateFrom !== '' && $dateCol) {
    $where[] = "$dateCol >= ?";
    $params[] = $dateFrom;
}
if ($dateTo !== '' && $dateCol) {
    $where[] = "$dateCol <= ?";
    $params[] = $dateTo;
}
if ($q !== '') {
    $like = "%$q%";
    switch ($type) {
        case 'inventory':
        case 'missing':
        case 'damaged':
            $where[] = '(iv.asset_number LIKE ? OR iv.asset_name LIKE ? OR iv.serial_number LIKE ?)';
            array_push($params, $like, $like, $like);
            break;
        case 'assigns':
            $where[] = '(i.assign_no LIKE ? OR i.user_name LIKE ? OR i.user_email LIKE ? OR i.project_code LIKE ?)';
            array_push($params, $like, $like, $like, $like);
            break;
        case 'returns':
            $where[] = '(i.assign_no LIKE ? OR i.user_name LIKE ? OR ii.asset_number LIKE ? OR i.project_code LIKE ?)';
            array_push($params, $like, $like, $like, $like);
            break;
    }
}
if ($status !== '' && $type === 'inventory') {
    $where[] = 'iv.status = ?';
    $params[] = $status;
}

switch ($type) {
    case 'inventory':
        $sql = "SELECT iv.asset_number, iv.asset_name, iv.category, iv.brand, iv.model, iv.serial_number, iv.status, iv.remarks,
                  iv.purchase_date, iv.cost, iv.vendor, iv.invoice_no, iv.warranty_expiry,
                  (SELECT i.user_name FROM assign_items ii JOIN assigns i ON i.id = ii.assign_id
                   WHERE ii.inventory_id = iv.id AND ii.return_status = 'Pending' LIMIT 1) AS holder
                FROM inventory iv" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY iv.asset_number';
        break;
    case 'assigns':
        $sql = 'SELECT i.* FROM assigns i' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY i.assign_date DESC';
        break;
    case 'returns':
        $sql = 'SELECT i.id, i.assign_no, i.user_name, i.project_code, ii.item_name, ii.asset_number, ii.serial_number,
                       ii.return_date, ii.return_status, ii.return_condition, ii.return_remarks
                FROM assign_items ii JOIN assigns i ON i.id = ii.assign_id
                WHERE ii.return_status <> \'Pending\'' . ($where ? ' AND ' . implode(' AND ', $where) : '') . '
                ORDER BY ii.return_date DESC';
        break;
    case 'missing':
    case 'damaged':
        $statuses = $type === 'missing' ? ['Missing'] : ['Damaged', 'Under Repair'];
        $ph = implode(',', array_fill(0, count($statuses), '?'));
        $where[] = "iv.status IN ($ph)";
        $params = [...$params, ...$statuses];
        $sql = "SELECT iv.asset_number, iv.asset_name, iv.serial_number, iv.status, iv.condition_note, iv.remarks, iv.updated_at,
                  (SELECT i.user_name FROM assign_items ii JOIN assigns i ON i.id = ii.assign_id
                   WHERE ii.inventory_id = iv.id ORDER BY ii.id DESC LIMIT 1) AS holder,
                  (SELECT i.project_code FROM assign_items ii JOIN assigns i ON i.id = ii.assign_id
                   WHERE ii.inventory_id = iv.id ORDER BY ii.id DESC LIMIT 1) AS project_code
                FROM inventory iv WHERE " . implode(' AND ', $where) . ' ORDER BY iv.updated_at DESC';
        break;
    case 'users':
        $sql = "SELECT user_email, MAX(user_name) user_name, COUNT(*) total,
                  SUM(status <> 'Returned') active, SUM(status = 'Returned') returned, MAX(assign_date) last_issue
                FROM assigns GROUP BY user_email" . ($q !== '' ? ' HAVING user_email LIKE ? OR user_name LIKE ?' : '') . ' ORDER BY user_name';
        if ($q !== '') $params = ["%$q%", "%$q%"];
        break;
}

$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();
$cols = $REPORTS[$type]['cols'];
$keepCols = array_map(fn($c) => $c[1], $cols);

// Export rows: same query, formula-injection-safe cells, in header column order.
$headLabels = array_map(fn($c) => $c[0], $cols);
$exportRows = array_map(
    fn($r) => array_map(fn($k) => export_safe(status_label($r[$k] ?? '')), $keepCols),
    $rows
);

// CSV export — streamed, BOM so Excel opens UTF-8 correctly.
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $type . '_report_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, $headLabels);
    foreach ($exportRows as $r) {
        fputcsv($out, $r);
    }
    fclose($out);
    exit;
}

// Excel (.xlsx) export.
if (isset($_GET['xlsx'])) {
    $tmp = tempnam(sys_get_temp_dir(), 'rpt');
    try {
        xlsx_write($tmp, $headLabels, $exportRows);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $type . '_report_' . date('Y-m-d') . '.xlsx"');
        header('Content-Length: ' . (string)filesize($tmp));
        readfile($tmp);
    } finally {
        @unlink($tmp);
    }
    exit;
}

const REPORT_META = [
    'inventory' => ['Asset Inventory Report', 'Complete list of all assets with details, status and current holder.', 'box-seam', 'blue'],
    'assigns'    => ['Assignment Report', 'All equipment assignments with user, project, dates and acknowledgement.', 'box-arrow-up', 'green'],
    'returns'   => ['Return Report', 'Every returned, damaged or missing item with condition and remarks.', 'arrow-left-right', 'orange'],
    'missing'   => ['Missing Equipment Report', 'All equipment currently marked as missing.', 'question-circle', 'red'],
    'damaged'   => ['Damaged Equipment Report', 'Equipment reported damaged or under repair.', 'hammer', 'purple'],
    'users'     => ['User Equipment Report', 'Assignment history aggregated per user.', 'people', 'teal'],
];

layout_start('Reports', 'reports', 'View and download detailed reports for better tracking.', 'file-earmark-bar-graph', true, 'Search assets, serial number…');

$qs = fn(string $extra): string => 'reports.php?type=' . h($type) . '&' . $extra . '&date_from=' . h($dateFrom) . '&date_to=' . h($dateTo) . '&q=' . urlencode($q) . '&status=' . urlencode($status);
?>
<div class="row g-3 mb-4">
  <?php foreach ($REPORTS as $key => $r): $m = REPORT_META[$key]; ?>
    <div class="col-md-6 col-xl-4">
      <a class="qa h-100 d-flex <?= $type === $key ? 'border-primary' : '' ?>" href="reports.php?type=<?= $key ?>" style="border-width:1px;<?= $type === $key ? 'box-shadow:0 6px 16px rgba(37,99,235,.12);' : '' ?>">
        <span class="tile tile-<?= $m[3] ?>"><i class="bi bi-<?= $m[2] ?>"></i></span>
        <span class="flex-grow-1">
          <span class="t d-block"><?= $m[0] ?></span>
          <span class="s d-block mt-1" style="white-space:normal;"><?= h($m[1]) ?></span>
        </span>
        <i class="bi bi-chevron-right text-muted"></i>
      </a>
    </div>
  <?php endforeach; ?>
</div>

<form class="card mb-4 filter-bar no-print" method="get">
  <input type="hidden" name="type" value="<?= h($type) ?>">
  <div class="card-body">
    <div class="row g-3 align-items-end">
      <?php if ($dateCol): ?>
        <div class="col-md-2"><label class="f-label">From</label>
          <input class="form-control" type="date" name="date_from" value="<?= h($dateFrom) ?>"></div>
        <div class="col-md-2"><label class="f-label">To</label>
          <input class="form-control" type="date" name="date_to" value="<?= h($dateTo) ?>"></div>
      <?php endif; ?>
      <?php if ($type === 'inventory'): ?>
        <div class="col-md-2"><label class="f-label">Status</label>
          <select class="form-select" name="status">
            <option value="">All</option>
            <?php foreach (['Available', 'Issued', 'Damaged', 'Missing', 'Under Repair', 'Retired'] as $s): ?>
              <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= status_label($s) ?></option>
            <?php endforeach; ?>
          </select></div>
      <?php endif; ?>
      <div class="col-md-3"><label class="f-label">Search</label>
        <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Filter results…"></div>
      <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Apply Filters</button></div>
      <div class="col-md-3 text-md-end d-flex gap-2 justify-content-md-end">
        <a class="btn btn-outline-success" href="<?= $qs('xlsx=1') ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
        <a class="btn btn-outline-secondary" href="<?= $qs('csv=1') ?>"><i class="bi bi-filetype-csv"></i> CSV</a>
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
      </div>
    </div>
  </div>
</form>

<div class="card">
  <div class="card-header d-flex align-items-center">
    <span><i class="bi bi-table text-primary"></i> <?= REPORT_META[$type][0] ?></span>
    <span class="text-muted small ms-2">(<?= count($rows) ?> rows)</span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle">
      <thead>
        <tr><?php foreach ($cols as [$label]): ?><th><?= $label ?></th><?php endforeach; ?></tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="<?= count($cols) ?>" class="empty"><i class="bi bi-inbox"></i>No data for this report.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr><?php foreach ($keepCols as $ci => $k): ?>
              <?php if ($k === 'assign_no' && !empty($r['id'])): ?>
                <td class="small" style="font-weight:600;white-space:nowrap"><a href="assign_view.php?id=<?= (int)$r['id'] ?>" title="Open full assignment"><?= h($r['assign_no']) ?></a></td>
              <?php else: ?>
                <td class="small<?= $ci === 0 ? ' nw' : '' ?>"><?= h(status_label($r[$k] ?? '')) ?></td>
              <?php endif; ?>
            <?php endforeach; ?></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php layout_end(); ?>
