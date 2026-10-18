<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_login();

const TABS = [
    'all'      => ['', 'All'],
    'active'   => ["i.status IN ('Issued','Partial')", 'Active'],
    'overdue'  => ["i.status IN ('Issued','Partial') AND i.expected_return < CURDATE()", 'Overdue'],
    'partial'  => ["i.status = 'Partial'", 'Partially Returned'],
    'returned' => ["i.status = 'Returned'", 'Returned'],
    'unacked'  => ["i.ack_status <> 'Acknowledged'", 'Not Acknowledged'],
];

$tab = $_GET['status'] ?? 'active';
if (!isset(TABS[$tab])) $tab = 'active';
$q = trim($_GET['q'] ?? '');

$where = [TABS[$tab][0]];
$params = [];
if ($q !== '') {
    $where[] = '(i.assign_no LIKE ? OR i.user_name LIKE ? OR i.user_email LIKE ? OR i.project_code LIKE ?'
             . ' OR EXISTS (SELECT 1 FROM assign_items sq WHERE sq.assign_id = i.id'
             . ' AND (sq.item_name LIKE ? OR sq.asset_number LIKE ? OR sq.serial_number LIKE ?)))';
    $like = "%$q%";
    $params = array_fill(0, 7, $like);
}
$whereSql = $where[0] === '' && count($where) === 1 ? '' : ' WHERE ' . implode(' AND ', array_filter($where, fn($w) => $w !== ''));

$st = db()->prepare(
    "SELECT i.*,
       (SELECT COUNT(*) FROM assign_items ii WHERE ii.assign_id = i.id) AS items_total,
       (SELECT COUNT(*) FROM assign_items ii WHERE ii.assign_id = i.id AND ii.return_status <> 'Pending') AS items_done,
       (SELECT ii.item_name FROM assign_items ii WHERE ii.assign_id = i.id ORDER BY ii.id LIMIT 1) AS first_item,
       (SELECT iv.category FROM assign_items ii JOIN inventory iv ON iv.id = ii.inventory_id WHERE ii.assign_id = i.id ORDER BY ii.id LIMIT 1) AS first_cat
     FROM assigns i $whereSql ORDER BY i.id DESC"
);
[$assigns, $page, $pages, $total] = paginate($st->queryString, $params, 15);

// Active view: equipment grouped by employee + event/project. Records of the same
// employee for the same event merge into one expandable group; every item keeps a
// pointer to its own assign record so per-item View/Return act on the right one.
$groups = [];
if ($tab === 'active') {
    $ids = array_column($assigns, 'id');
    $itemsByAssign = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $ist = db()->prepare(
            "SELECT ii.*, COALESCE(NULLIF(iv.serial_number, ''), ii.serial_number) AS serial,
                    COALESCE(NULLIF(iv.category, ''), '—') AS category, iv.brand, iv.model
             FROM assign_items ii LEFT JOIN inventory iv ON iv.id = ii.inventory_id
             WHERE ii.assign_id IN ($in) ORDER BY ii.assign_id, ii.id"
        );
        $ist->execute($ids);
        foreach ($ist->fetchAll() as $it) $itemsByAssign[$it['assign_id']][] = $it;
    }
    foreach ($assigns as $a) {
        $key = strtolower($a['user_email']) . '|' . $a['project_code'] . '|' . $a['event_name'];
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'user_name' => $a['user_name'], 'user_email' => $a['user_email'], 'employee_id' => $a['employee_id'],
                'project_code' => $a['project_code'], 'event_name' => $a['event_name'], 'purpose' => $a['purpose'],
                'records' => [], 'items' => [],
            ];
        }
        $groups[$key]['records'][] = $a;
        foreach ($itemsByAssign[$a['id']] ?? [] as $it) {
            $it['_aid'] = (int)$a['id'];
            $it['_due'] = $a['expected_return'];
            $groups[$key]['items'][] = $it;
        }
    }
}

$titles = [
    'active' => ['Active Assignments', 'View and manage all currently assigned equipment.', 'clipboard-check'],
    'overdue' => ['Overdue', 'All equipment past its expected return date.', 'exclamation-triangle'],
    'partial' => ['Partially Returned', 'Assignments where some items are still pending.', 'arrow-left-right'],
    'returned' => ['Returned', 'Fully closed assignment records.', 'check2-circle'],
    'unacked' => ['Not Acknowledged', 'Assigned equipment awaiting user acknowledgement.', 'envelope-x'],
    'all' => ['All Assignments', 'Every assignment record in the system.', 'clipboard-data'],
];
[$pageTitle, $pageSub, $pageIcon] = $titles[$tab];

layout_start($pageTitle, $tab === 'overdue' ? 'overdue' : ($tab === 'returned' ? 'returns' : 'assigns'), $pageSub, $pageIcon, true, 'Search assets, serial number…');
?>
<form class="card mb-4 filter-bar" method="get">
  <div class="card-body">
    <div class="row g-3 align-items-end">
      <div class="col-md-6">
        <label class="f-label">Search</label>
        <div class="position-relative">
          <i class="bi bi-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:13px;"></i>
          <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Search by name, equipment, asset ID, project…" style="padding-left:34px">
        </div>
      </div>
      <div class="col-md-3">
        <label class="f-label">Status</label>
        <select class="form-select" onchange="this.form.submit()" name="status">
          <?php foreach (TABS as $key => [$_, $label]): ?>
            <option value="<?= $key ?>" <?= $tab === $key ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Apply</button>
      </div>
    </div>
  </div>
</form>

<div class="card">
  <div class="card-header d-flex align-items-center flex-wrap gap-2">
    <span><i class="bi bi-clipboard-check text-primary"></i> Assignment Records
      <?php if ($tab === 'active'): ?><span class="text-muted small">— grouped by employee &amp; event</span><?php endif; ?>
    </span>
    <a class="btn btn-sm btn-primary ms-auto no-print" href="assign.php"><i class="bi bi-plus-lg"></i> Assign Equipment</a>
  </div>

<?php if ($tab === 'active'): ?>
  <?php if (!$groups): ?>
    <div class="empty"><i class="bi bi-inbox"></i>No active assignments right now.</div>
  <?php endif; ?>
  <?php foreach ($groups as $g):
    $gid = 'ag' . $g['records'][0]['id'];
    $evName = $g['event_name'] !== '' ? $g['event_name'] : $g['project_code'];
    $gTotal = count($g['items']);
    $gDone = count(array_filter($g['items'], fn($x) => $x['return_status'] !== 'Pending'));
    $pendingDue = null;
    foreach ($g['items'] as $it) {
        if ($it['return_status'] === 'Pending' && ($pendingDue === null || $it['_due'] < $pendingDue)) $pendingDue = $it['_due'];
    }
    $overdueDays = ($pendingDue !== null && $pendingDue < date('Y-m-d')) ? days_overdue($pendingDue) : 0;
    $assignedOn = min(array_column($g['records'], 'assign_date'));
    $expBack = $pendingDue ?? max(array_column($g['records'], 'expected_return'));
    $anyPartial = in_array('Partial', array_column($g['records'], 'status'), true);
    $collected = array_values(array_unique(array_filter(array_column($g['records'], 'collected_by'))));
  ?>
  <div class="ag-item">
    <button type="button" class="ag-head" aria-expanded="false" aria-controls="<?= $gid ?>">
      <span class="ag-emp">
        <?= avatar($g['user_name']) ?>
        <span>
          <span class="t d-block"><?= h($g['user_name']) ?></span>
          <span class="s d-block"><?= h($g['user_email']) ?><?= $g['employee_id'] !== '' ? ' · ID ' . h($g['employee_id']) : '' ?></span>
        </span>
      </span>
      <span class="ag-ev">
        <span class="i"><i class="bi bi-<?= $g['purpose'] === 'Office' ? 'briefcase' : 'calendar-event' ?>"></i></span>
        <span>
          <span class="t d-block"><?= h($evName !== '' ? $evName : '—') ?></span>
          <span class="s d-block"><?= h($g['project_code']) ?> · <?= h($g['purpose']) ?></span>
        </span>
      </span>
      <span class="ag-facts">
        <span class="ag-count"><?= $gTotal ?> Item<?= $gTotal === 1 ? '' : 's' ?> Assigned</span>
        <?php if ($gDone): ?><span class="small text-muted fw-semibold"><?= $gDone ?> returned</span><?php endif; ?>
        <span class="ag-due <?= $overdueDays ? 'over' : '' ?>"><i class="bi bi-calendar3"></i> Expected back <?= h(date('d M Y', strtotime((string)$expBack))) ?></span>
        <?php if ($overdueDays): ?><span class="badge bg-danger"><?= $overdueDays ?> days overdue</span><?php endif; ?>
        <?= badge($anyPartial ? 'Partial' : 'Issued') ?>
        <i class="bi bi-chevron-down ag-chev"></i>
      </span>
    </button>
    <div class="ag-body" id="<?= $gid ?>">
      <div class="ag-ctx">
        <span>Issued to <b><?= h($g['user_name']) ?></b> for <b><?= h($evName !== '' ? $evName : $g['project_code']) ?></b> (<?= h($g['project_code']) ?>)</span>
        <span><i class="bi bi-hash"></i> <?= h(implode(', ', array_column($g['records'], 'assign_no'))) ?></span>
        <span><i class="bi bi-calendar-event"></i> Assigned <?= h(date('d M Y', strtotime($assignedOn))) ?></span>
        <?php if ($collected): ?><span><i class="bi bi-person-check"></i> Collected by <?= h(implode(', ', $collected)) ?></span><?php endif; ?>
        <?php if (count($g['records']) === 1): ?>
          <a class="ag-open ms-auto" href="assign_view.php?id=<?= (int)$g['records'][0]['id'] ?>">Open full record <i class="bi bi-arrow-right"></i></a>
        <?php endif; ?>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr><th>Asset ID</th><th>Equipment</th><th>Category</th><th>Serial Number</th><th>Status</th><th class="no-print">Actions</th></tr>
          </thead>
          <tbody>
            <?php if (!$g['items']): ?>
              <tr><td colspan="6" class="empty">No items on this record.</td></tr>
            <?php endif; ?>
            <?php foreach ($g['items'] as $it): ?>
              <tr>
                <td class="small nw" style="font-weight:600"><?= h($it['asset_number']) ?></td>
                <td>
                  <div class="entity">
                    <?= asset_thumb($it['category'] ?? '', $it['item_name']) ?>
                    <div>
                      <div class="t"><?= h($it['item_name']) ?></div>
                      <?php $bm = trim(($it['brand'] ?? '') . ' ' . ($it['model'] ?? '')); ?>
                      <?php if ($bm !== ''): ?><div class="s"><?= h($bm) ?></div><?php endif; ?>
                    </div>
                  </div>
                </td>
                <td class="small"><?= h($it['category'] ?? '—') ?></td>
                <td class="small text-muted"><?= $it['serial'] !== '' ? h($it['serial']) : '—' ?></td>
                <td><?= badge($it['return_status']) ?></td>
                <td class="no-print">
                  <div class="d-flex gap-2">
                    <a class="btn btn-sm btn-outline-primary" href="assign_view.php?id=<?= $it['_aid'] ?>#item-<?= (int)$it['id'] ?>"><i class="bi bi-eye"></i> View</a>
                    <?php if ($it['return_status'] === 'Pending'): ?>
                      <form method="post" action="assign_view.php" data-confirm="Mark '<?= h($it['item_name']) ?> (<?= h($it['asset_number']) ?>)' as returned in good condition?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="return_item">
                        <input type="hidden" name="assign_id" value="<?= $it['_aid'] ?>">
                        <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                        <input type="hidden" name="return_status" value="Returned">
                        <input type="hidden" name="return_condition" value="Good">
                        <input type="hidden" name="return_date" value="<?= date('Y-m-d') ?>">
                        <input type="hidden" name="back" value="assigns.php?status=active">
                        <button class="btn btn-sm btn-outline-success"><i class="bi bi-arrow-return-left"></i> Return</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
<?= pager_nav($page, $pages, $total, 15, count($assigns)) ?>
</div>

<?php else: ?>

  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead>
        <tr><th>#</th><th>Employee</th><th>Equipment</th><th>Assigned On</th><th>Expected Return</th><th>Days Overdue</th><th>Items</th><th>Project</th><th>Purpose</th><th>Ack</th><th>Status</th><th class="no-print">Actions</th></tr>
      </thead>
      <tbody>
        <?php if (!$assigns): ?>
          <tr><td colspan="12" class="empty"><i class="bi bi-inbox"></i>No assign records found.</td></tr>
        <?php endif; ?>
        <?php foreach ($assigns as $n => $i): $overdue = $i['status'] !== 'Returned' && $i['expected_return'] < date('Y-m-d'); ?>
          <tr>
            <td class="text-muted"><?= ($page - 1) * 15 + $n + 1 ?></td>
            <td>
              <div class="entity">
                <?= avatar($i['user_name']) ?>
                <div><div class="t"><?= h($i['user_name']) ?></div><div class="s"><?= h($i['user_email']) ?></div></div>
              </div>
            </td>
            <td>
              <div class="entity">
                <?= asset_thumb($i['first_cat'] ?? '', $i['first_item'] ?? '') ?>
                <div><div class="t"><?= h($i['first_item'] ?: '—') ?></div>
                  <div class="s"><?= $i['items_total'] > 1 ? '+' . ($i['items_total'] - 1) . ' more item(s)' : h($i['first_cat'] ?? '') ?></div></div>
              </div>
            </td>
            <td class="small text-nowrap"><?= h(date('d M Y', strtotime($i['assign_date']))) ?></td>
            <td class="small text-nowrap <?= $overdue ? 'text-danger fw-bold' : '' ?>"><?= h(date('d M Y', strtotime($i['expected_return']))) ?></td>
            <td><?= $overdue ? '<span class="badge bg-danger">' . days_overdue($i['expected_return']) . ' days</span>' : '<span class="text-muted">—</span>' ?></td>
            <td><span class="badge bg-light border"><?= $i['items_done'] ?>/<?= $i['items_total'] ?></span></td>
            <td><span class="badge bg-light border"><?= h($i['project_code']) ?></span></td>
            <td><span class="badge <?= ($i['purpose'] ?? 'Event') === 'Office' ? 'bg-light border' : 'bg-primary' ?>"><?= h($i['purpose'] ?? 'Event') ?></span></td>
            <td><?= badge($i['ack_status']) ?></td>
            <td><?= badge($overdue && $i['status'] !== 'Partial' ? 'Overdue' : ($i['status'] === 'Partial' ? 'Partial' : ($i['status'] === 'Returned' ? 'Returned' : 'Issued'))) ?></td>
            <td class="no-print">
              <div class="d-flex gap-2">
                <a class="btn btn-sm btn-outline-primary" href="assign_view.php?id=<?= $i['id'] ?>"><i class="bi bi-eye"></i> View</a>
                <?php if ($i['status'] !== 'Returned'): ?>
                  <a class="btn btn-sm btn-outline-success" href="assign_view.php?id=<?= $i['id'] ?>"><i class="bi bi-arrow-return-left"></i> Return</a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pager_nav($page, $pages, $total, 15, count($assigns)) ?>
</div>

<?php endif; ?>
<?php layout_end(); ?>
