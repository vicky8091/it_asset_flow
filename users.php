<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            'name' => trim($_POST['name'] ?? ''),
            'email' => strtolower(trim($_POST['email'] ?? '')),
            'employee_id' => trim($_POST['employee_id'] ?? ''),
            'department' => trim($_POST['department'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'status' => ($_POST['status'] ?? '') === 'Inactive' ? 'Inactive' : 'Active',
        ];
        if ($data['name'] === '' || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            flash('Name and a valid email are required.', 'danger');
        } else {
            try {
                if ($id) {
                    // assigns reference users by email snapshot — keep history attached when the email changes.
                    $old = db()->prepare('SELECT email FROM users WHERE id = ?');
                    $old->execute([$id]);
                    $oldEmail = (string)$old->fetchColumn();
                    if ($oldEmail !== '' && $oldEmail !== $data['email']) {
                        db()->prepare('UPDATE assigns SET user_email = ? WHERE user_email = ?')
                            ->execute([$data['email'], $oldEmail]);
                    }
                    db()->prepare('UPDATE users SET name = ?, email = ?, employee_id = ?, department = ?, phone = ?, status = ? WHERE id = ?')
                        ->execute([...array_values($data), $id]);
                    log_action('user_edited', $data['name'] . ' (' . $data['email'] . ')');
                    flash('User updated.');
                } else {
                    db()->prepare('INSERT INTO users (name, email, employee_id, department, phone, status) VALUES (?,?,?,?,?,?)')
                        ->execute(array_values($data));
                    log_action('user_added', $data['name'] . ' (' . $data['email'] . ')');
                    flash('User added.');
                }
                redirect('users.php');
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    flash('A user with this email already exists.', 'danger');
                } else {
                    throw $e;
                }
            }
        }
        $edit = ['id' => $id] + $data;
    } elseif ($action === 'toggle') {
        $st = db()->prepare('SELECT name, status FROM users WHERE id = ?');
        $st->execute([(int)$_POST['id']]);
        if ($u = $st->fetch()) {
            $to = $u['status'] === 'Active' ? 'Inactive' : 'Active';
            db()->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$to, (int)$_POST['id']]);
            log_action('user_' . ($to === 'Active' ? 'activated' : 'deactivated'), $u['name']);
            flash($to === 'Active' ? "{$u['name']} activated." : "{$u['name']} deactivated — they can still return equipment, but flag their history before issuing.", $to === 'Active' ? 'success' : 'warning');
        }
        redirect('users.php');
    }
}

$q = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$edit = $edit ?? null;
if (!$edit && isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch() ?: null;
}
if (isset($_GET['add'])) $edit = ['id' => 0] + array_fill_keys(['name', 'email', 'employee_id', 'department', 'phone', 'status'], '');

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(u.name LIKE ? OR u.email LIKE ? OR u.employee_id LIKE ? OR u.department LIKE ? OR u.phone LIKE ?)';
    $like = "%$q%";
    $params = array_fill(0, 5, $like);
}
if (in_array($status, ['Active', 'Inactive'], true)) {
    $where[] = 'u.status = ?';
    $params[] = $status;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$sql = "SELECT u.*,
          (SELECT COUNT(*) FROM assigns i WHERE i.user_email = u.email) AS total_issues,
          (SELECT COUNT(*) FROM assigns i WHERE i.user_email = u.email AND i.status <> 'Returned') AS active_issues,
          (SELECT COUNT(*) FROM assign_items ii JOIN assigns i ON i.id = ii.assign_id
             WHERE i.user_email = u.email AND ii.return_status = 'Pending' AND i.expected_return < CURDATE()) AS overdue_items
        FROM users u" . $whereSql . ' ORDER BY u.name';
[$users, $page, $pages, $total] = paginate($sql, $params, 15);

layout_start('Users', 'users', 'View and manage all registered users in the system.', 'people', true, 'Search assets, serial number…');
?>
<?php if ($edit): ?>
<div class="card mb-4">
  <div class="card-header d-flex align-items-center">
    <span><i class="bi bi-<?= $edit['id'] ? 'pencil' : 'person-plus' ?> text-primary"></i> <?= $edit['id'] ? 'Edit User' : 'Add New User' ?></span>
    <a class="btn btn-sm btn-light ms-auto" href="users.php">Cancel</a>
  </div>
  <div class="card-body">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
      <div class="row g-3">
        <div class="col-md-4"><label class="form-label">Name <span class="req">*</span></label>
          <input class="form-control" name="name" value="<?= h($edit['name']) ?>" required></div>
        <div class="col-md-4"><label class="form-label">Email ID <span class="req">*</span></label>
          <input class="form-control" type="email" name="email" value="<?= h($edit['email']) ?>" required></div>
        <div class="col-md-4"><label class="form-label">Employee ID</label>
          <input class="form-control" name="employee_id" value="<?= h($edit['employee_id']) ?>"></div>
        <div class="col-md-4"><label class="form-label">Department</label>
          <input class="form-control" name="department" value="<?= h($edit['department']) ?>"></div>
        <div class="col-md-4"><label class="form-label">Phone</label>
          <input class="form-control" name="phone" value="<?= h($edit['phone']) ?>"></div>
        <div class="col-md-4"><label class="form-label">Status</label>
          <select class="form-select" name="status">
            <option <?= $edit['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
            <option <?= $edit['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
          </select></div>
      </div>
      <button class="btn btn-primary mt-3"><i class="bi bi-check-lg"></i> <?= $edit['id'] ? 'Save Changes' : 'Add User' ?></button>
    </form>
  </div>
</div>
<?php endif; ?>

<form class="card mb-4 filter-bar" method="get">
  <div class="card-body">
    <div class="row g-3 align-items-end">
      <div class="col-md-5">
        <label class="f-label">Search</label>
        <div class="position-relative">
          <i class="bi bi-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:13px;"></i>
          <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Search by name, email, department, or employee ID…" style="padding-left:34px">
        </div>
      </div>
      <div class="col-md-3">
        <label class="f-label">Status</label>
        <select class="form-select" name="status">
          <option value="">All Statuses</option>
          <option <?= $status === 'Active' ? 'selected' : '' ?>>Active</option>
          <option <?= $status === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Apply</button></div>
      <div class="col-md-2 text-md-end">
        <a class="btn btn-primary w-100" href="users.php?add=1"><i class="bi bi-plus-lg"></i> Add User</a>
      </div>
    </div>
  </div>
</form>

<div class="card">
  <div class="card-header d-flex align-items-center">
    <span><i class="bi bi-people text-primary"></i> Users (<?= $total ?>)</span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead>
        <tr><th>#</th><th>Name</th><th>Email ID</th><th>Department</th><th>Employee ID</th><th>Phone</th><th>Assignments</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php if (!$users): ?>
          <tr><td colspan="9" class="empty"><i class="bi bi-people"></i>No users found. Users are added here or automatically when equipment is assigned to them.</td></tr>
        <?php endif; ?>
        <?php foreach ($users as $n => $u): ?>
          <tr>
            <td class="text-muted"><?= ($page - 1) * 15 + $n + 1 ?></td>
            <td>
              <div class="entity">
                <?= avatar($u['name']) ?>
                <div class="t"><?= h($u['name']) ?></div>
              </div>
            </td>
            <td class="small"><?= h($u['email']) ?></td>
            <td class="small"><?= h($u['department'] ?: '—') ?></td>
            <td class="small"><?= h($u['employee_id'] ?: '—') ?></td>
            <td class="small"><?= h($u['phone'] ?: '—') ?></td>
            <td class="small"><?= (int)$u['total_issues'] ?> total<?php if ((int)$u['overdue_items']): ?> · <span class="text-danger fw-bold"><?= (int)$u['overdue_items'] ?> overdue</span><?php endif; ?></td>
            <td><?= badge($u['status']) ?></td>
            <td>
              <div class="d-flex gap-2">
                <a class="btn btn-sm btn-outline-primary" href="user.php?email=<?= urlencode($u['email']) ?>"><i class="bi bi-eye"></i> History</a>
                <a class="btn btn-sm btn-outline-secondary icon-btn" href="users.php?edit=<?= $u['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></a>
                <form method="post" data-confirm="<?= $u['status'] === 'Active' ? 'Deactivate' : 'Activate' ?> <?= h($u['name']) ?>?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= $u['id'] ?>">
                  <button class="btn btn-sm <?= $u['status'] === 'Active' ? 'btn-outline-warning' : 'btn-outline-success' ?> icon-btn"
                          title="<?= $u['status'] === 'Active' ? 'Deactivate' : 'Activate' ?>">
                    <i class="bi bi-<?= $u['status'] === 'Active' ? 'person-slash' : 'person-check' ?>"></i>
                  </button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pager_nav($page, $pages, $total, 15, count($users)) ?>
</div>
<?php layout_end(); ?>
