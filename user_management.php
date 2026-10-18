<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_admin();

$meId = (int)$_SESSION['admin']['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (($_POST['action'] ?? '') === 'create_user') {
        $u = trim($_POST['username'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $employeeId = trim($_POST['employee_id'] ?? '');
        $pass = $_POST['password'] ?? '';
        $role = ($_POST['role'] ?? '') === 'user' ? 'user' : 'admin';
        $status = ($_POST['status'] ?? '') === 'Inactive' ? 'Inactive' : 'Active';
        if ($u === '' || $name === '') {
            flash('Username and full name are required.', 'danger');
        } elseif (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $u)) {
            flash('Username must be 3–30 characters (letters, digits, dot, dash, underscore).', 'danger');
        } elseif (strlen($pass) < 8) {
            flash('Password must be at least 8 characters.', 'danger');
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Enter a valid email address.', 'danger');
        } else {
            $dup = db()->prepare('SELECT id FROM admins WHERE username = ? OR (? <> \'\' AND email = ?)');
            $dup->execute([$u, $email, $email]);
            if ($dup->fetch()) {
                flash('That username or email is already in use.', 'danger');
            } else {
                db()->prepare('INSERT INTO admins (username, password_hash, name, email, department, employee_id, role, status, must_change_pwd) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$u, password_hash($pass, PASSWORD_DEFAULT), $name, $email, $department, $employeeId, $role, $status, isset($_POST['force_pwd']) ? 1 : 0]);
                $newId = (int)db()->lastInsertId();
                $pic = save_account_pic($newId, $_FILES['pic'] ?? null);
                if ($pic) db()->prepare('UPDATE admins SET profile_pic = ? WHERE id = ?')->execute([$pic, $newId]);
                log_action('account_created', "$u ($role)");
                flash("Account \"$u\" created as " . ($role === 'admin' ? 'Admin' : 'User') . (isset($_POST['force_pwd']) ? ' — they set their own password on first login.' : ''));
            }
        }
        redirect('user_management.php');
    }

    if (($_POST['action'] ?? '') === 'update_user') {
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT * FROM admins WHERE id = ?');
        $st->execute([$id]);
        $acc = $st->fetch();
        if (!$acc) {
            flash('Account not found.', 'danger');
        } else {
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $department = trim($_POST['department'] ?? '');
            $employeeId = trim($_POST['employee_id'] ?? '');
            $role = ($_POST['role'] ?? $acc['role']) === 'user' ? 'user' : 'admin';
            $status = ($_POST['status'] ?? $acc['status']) === 'Inactive' ? 'Inactive' : 'Active';
            $new = $_POST['password'] ?? '';
            $self = $id === $meId;
            if ($name === '') {
                flash('Full name is required.', 'danger');
            } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('Enter a valid email address.', 'danger');
            } elseif ($acc['role'] === 'admin' && $role === 'user' && (int)db()->query("SELECT COUNT(*) FROM admins WHERE role = 'admin'")->fetchColumn() <= 1) {
                flash('At least one Admin account must remain.', 'danger');
            } elseif ($self && $status === 'Inactive') {
                flash('You cannot deactivate your own account.', 'danger');
            } elseif ($new !== '' && strlen($new) < 8) {
                flash('New password must be at least 8 characters.', 'danger');
            } else {
                $dup = db()->prepare('SELECT id FROM admins WHERE email = ? AND email <> \'\' AND id <> ?');
                $dup->execute([$email, $id]);
                if ($dup->fetch()) {
                    flash('That email is already in use by another account.', 'danger');
                } else {
                    db()->prepare('UPDATE admins SET name = ?, email = ?, department = ?, employee_id = ?, role = ?, status = ? WHERE id = ?')
                        ->execute([$name, $email, $department, $employeeId, $role, $status, $id]);
                    if ($new !== '') {
                        db()->prepare('UPDATE admins SET password_hash = ?, must_change_pwd = ? WHERE id = ?')
                            ->execute([password_hash($new, PASSWORD_DEFAULT), isset($_POST['force_pwd']) ? 1 : 0, $id]);
                    }
                    if (!empty($_POST['remove_pic'])) {
                        remove_account_pic($id);
                        db()->prepare('UPDATE admins SET profile_pic = ? WHERE id = ?')->execute(['', $id]);
                    } else {
                        $pic = save_account_pic($id, $_FILES['pic'] ?? null);
                        if ($pic) db()->prepare('UPDATE admins SET profile_pic = ? WHERE id = ?')->execute([$pic, $id]);
                    }
                    if ($self) {
                        $_SESSION['admin']['name'] = $name;
                        $_SESSION['admin']['email'] = $email;
                        $_SESSION['admin']['role'] = $role;
                        $st2 = db()->prepare('SELECT profile_pic FROM admins WHERE id = ?');
                        $st2->execute([$id]);
                        $_SESSION['admin']['pic'] = (string)$st2->fetchColumn();
                    }
                    log_action('account_updated', $acc['username'] . ($new !== '' ? ' (password reset)' : ''));
                    flash('Account "' . $acc['username'] . '" updated.');
                }
            }
        }
        redirect('user_management.php');
    }

    if (($_POST['action'] ?? '') === 'toggle_user') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === $meId) {
            flash('You cannot deactivate your own account.', 'danger');
        } else {
            $st = db()->prepare('SELECT * FROM admins WHERE id = ?');
            $st->execute([$id]);
            $acc = $st->fetch();
            if (!$acc) {
                flash('Account not found.', 'danger');
            } else {
                $to = $acc['status'] === 'Active' ? 'Inactive' : 'Active';
                db()->prepare('UPDATE admins SET status = ? WHERE id = ?')->execute([$to, $id]);
                log_action('account_' . ($to === 'Active' ? 'activated' : 'deactivated'), $acc['username']);
                flash('"' . $acc['username'] . '" is now ' . $to . '.', $to === 'Active' ? 'success' : 'warning');
            }
        }
        redirect('user_management.php');
    }

    if (($_POST['action'] ?? '') === 'delete_user') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === $meId) {
            flash('You cannot delete the account you are logged in with.', 'danger');
        } else {
            $st = db()->prepare('SELECT * FROM admins WHERE id = ?');
            $st->execute([$id]);
            $acc = $st->fetch();
            if (!$acc) {
                flash('Account not found.', 'danger');
            } elseif ($acc['role'] === 'admin' && (int)db()->query("SELECT COUNT(*) FROM admins WHERE role = 'admin'")->fetchColumn() <= 1) {
                flash('At least one Admin account must remain.', 'danger');
            } else {
                db()->prepare('DELETE FROM admins WHERE id = ?')->execute([$id]);
                remove_account_pic($id);
                log_action('account_deleted', $acc['username']);
                flash('Account "' . $acc['username'] . '" deleted.');
            }
        }
        redirect('user_management.php');
    }
}

/* ---------- list + filters ---------- */
$q = trim($_GET['q'] ?? '');
$fRole = in_array($_GET['role'] ?? '', ['admin', 'user'], true) ? $_GET['role'] : '';
$fStatus = in_array($_GET['status'] ?? '', ['Active', 'Inactive'], true) ? $_GET['status'] : '';

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(name LIKE ? OR username LIKE ? OR email LIKE ? OR department LIKE ? OR employee_id LIKE ?)';
    $params = array_fill(0, 5, "%$q%");
}
if ($fRole !== '') { $where[] = 'role = ?'; $params[] = $fRole; }
if ($fStatus !== '') { $where[] = 'status = ?'; $params[] = $fStatus; }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$sql = 'SELECT * FROM admins' . $whereSql . ' ORDER BY role DESC, name';
[$accounts, $page, $pages, $total] = paginate($sql, $params, 10);
$adminCount = (int)db()->query("SELECT COUNT(*) FROM admins WHERE role = 'admin'")->fetchColumn();

$editAcc = null;
if (isset($_GET['edit'])) {
    foreach ($accounts as $a) {
        if ($a['id'] === (int)$_GET['edit']) $editAcc = $a;
    }
    if (!$editAcc) {
        $st = db()->prepare('SELECT * FROM admins WHERE id = ?');
        $st->execute([(int)$_GET['edit']]);
        $editAcc = $st->fetch() ?: null;
    }
}
$adding = isset($_GET['add']);
$formAcc = $editAcc; // null on add mode

$fq = $q !== '' ? '&q=' . urlencode($q) : '';
$ff = ($fRole !== '' ? '&role=' . urlencode($fRole) : '') . ($fStatus !== '' ? '&status=' . urlencode($fStatus) : '');

layout_start('User Management', 'user_management', 'Create team accounts, assign roles and keep access up to date.', 'people', false, 'Search assets, serial number…');
?>
<div class="page-head">
  <span class="page-icon"><i class="bi bi-people"></i></span>
  <div>
    <h1>User Management</h1>
    <div class="sub">Create team accounts, assign roles and keep access up to date.</div>
  </div>
  <div class="right no-print">
    <a class="btn btn-primary" href="user_management.php?add=1<?= $fq . $ff ?>"><i class="bi bi-plus-lg"></i> Add User</a>
  </div>
</div>

<form class="card mb-4 filter-bar" method="get">
  <div class="card-body">
    <div class="row g-3 align-items-end">
      <div class="col-md-5">
        <label class="f-label">Search</label>
        <div class="position-relative">
          <i class="bi bi-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:13px;"></i>
          <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Search by name, email, department, employee ID…" style="padding-left:34px">
        </div>
      </div>
      <div class="col-md-2"><label class="f-label">Role</label>
        <select class="form-select" name="role">
          <option value="">All Roles</option>
          <option value="admin" <?= $fRole === 'admin' ? 'selected' : '' ?>>Admin</option>
          <option value="user" <?= $fRole === 'user' ? 'selected' : '' ?>>User</option>
        </select></div>
      <div class="col-md-2"><label class="f-label">Status</label>
        <select class="form-select" name="status">
          <option value="">All Statuses</option>
          <option value="Active" <?= $fStatus === 'Active' ? 'selected' : '' ?>>Active</option>
          <option value="Inactive" <?= $fStatus === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
        </select></div>
      <div class="col-md-3"><button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Apply Filters</button></div>
    </div>
  </div>
</form>

<?php if ($adding || $editAcc): ?>
<div class="card mb-4">
  <div class="card-header d-flex align-items-center">
    <span><i class="bi bi-<?= $editAcc ? 'pencil' : 'person-plus' ?> text-primary"></i> <?= $editAcc ? 'Edit Account — ' . h($editAcc['username']) : 'Add User' ?></span>
    <a class="btn btn-sm btn-light ms-auto" href="user_management.php<?= $fq . $ff ?>">Cancel</a>
  </div>
  <div class="card-body">
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= $editAcc ? 'update_user' : 'create_user' ?>">
      <input type="hidden" name="id" value="<?= $editAcc ? (int)$editAcc['id'] : 0 ?>">
      <div class="row g-3">
        <div class="col-md-4"><label class="form-label">Profile Picture</label>
          <?php if ($editAcc): ?><?= account_avatar($editAcc) ?><?php endif; ?>
          <input class="form-control form-control-sm mt-1" type="file" name="pic" accept=".png,.jpg,.jpeg,.webp">
          <div class="form-text">PNG, JPG or WebP · max <?= round(UPLOAD_MAX_BYTES / 1048576) ?> MB · up to 1024×1024px.</div>
          <?php if ($editAcc && $editAcc['profile_pic']): ?>
            <div class="form-check mt-1">
              <input class="form-check-input" type="checkbox" name="remove_pic" value="1" id="rmPic">
              <label class="form-check-label small text-muted" for="rmPic">Remove current picture</label>
            </div>
          <?php endif; ?></div>
        <div class="col-md-4"><label class="form-label">Full Name <span class="req">*</span></label>
          <input class="form-control" name="name" required maxlength="100" value="<?= $editAcc ? h($editAcc['name']) : '' ?>"></div>
        <div class="col-md-4"><label class="form-label">Username <span class="req">*</span></label>
          <?php if ($editAcc): ?>
            <input class="form-control" value="<?= h($editAcc['username']) ?>" disabled>
            <div class="form-text">Usernames cannot be changed.</div>
          <?php else: ?>
            <input class="form-control" name="username" required minlength="3" maxlength="30" placeholder="e.g. priya.s">
          <?php endif; ?></div>
        <div class="col-md-4"><label class="form-label">Email</label>
          <input class="form-control" type="email" name="email" maxlength="150" value="<?= $editAcc ? h($editAcc['email']) : '' ?>" placeholder="name@company.com"></div>
        <div class="col-md-4"><label class="form-label">Department</label>
          <input class="form-control" name="department" maxlength="100" value="<?= $editAcc ? h($editAcc['department']) : '' ?>"></div>
        <div class="col-md-4"><label class="form-label">Employee ID</label>
          <input class="form-control" name="employee_id" maxlength="50" value="<?= $editAcc ? h($editAcc['employee_id']) : '' ?>"></div>
        <div class="col-md-3"><label class="form-label">Role <span class="req">*</span></label>
          <select class="form-select" name="role">
            <option value="admin" <?= $editAcc && $editAcc['role'] === 'admin' ? 'selected' : '' ?>>Admin — full access</option>
            <option value="user" <?= !$editAcc || $editAcc['role'] === 'user' ? 'selected' : '' ?>>User — view only, no deletes</option>
          </select></div>
        <div class="col-md-3"><label class="form-label">Account Status</label>
          <select class="form-select" name="status">
            <option value="Active" <?= !$editAcc || $editAcc['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
            <option value="Inactive" <?= $editAcc && $editAcc['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
          </select></div>
        <div class="col-md-6"><label class="form-label"><?= $editAcc ? 'Reset Password (optional)' : 'Password <span class="req">*</span>' ?></label>
          <input class="form-control" type="password" name="password" minlength="8" <?= $editAcc ? '' : 'required' ?> placeholder="<?= $editAcc ? 'Leave blank to keep the current one' : 'Min 8 characters' ?>" autocomplete="new-password">
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" name="force_pwd" value="1" id="forcePwd">
            <label class="form-check-label small text-muted" for="forcePwd"><?= $editAcc ? 'Ask them to set a new password on their next login' : 'Ask them to set their own password on first login' ?></label>
          </div></div>
        <div class="col-12">
          <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= $editAcc ? 'Save Account' : 'Create Account' ?></button>
          <?php if ($editAcc && $editAcc['must_change_pwd']): ?><span class="text-muted small ms-2">This account is asked to set its own password on its next login — untick "ask" above after setting one for it.</span><?php endif; ?>
        </div>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header d-flex align-items-center">
    <span><i class="bi bi-people text-primary"></i> Accounts (<?= $total ?>) — <?= $adminCount ?> Admin(s)</span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead>
        <tr><th>Profile</th><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Created</th><th class="no-print">Actions</th></tr>
      </thead>
      <tbody>
        <?php if (!$accounts): ?>
          <tr><td colspan="7" class="empty"><i class="bi bi-inbox"></i>No accounts found<?= $q !== '' || $fRole !== '' || $fStatus !== '' ? ' for this filter.' : ' — add the first one with "+ Add User".' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($accounts as $a): ?>
          <tr>
            <td>
              <div class="entity">
                <?= account_avatar($a) ?>
                <div><div class="t"><?= h($a['name']) ?><?= $a['id'] === $meId ? ' <span class="badge bg-primary">You</span>' : '' ?></div>
                  <div class="s">@<?= h($a['username']) ?></div></div>
              </div>
            </td>
            <td class="small"><?= $a['email'] ? h($a['email']) : '<span class="text-muted">—</span>' ?></td>
            <td><?= badge($a['role'] === 'admin' ? 'Admin' : 'User') ?></td>
            <td><?= badge($a['status']) ?></td>
            <td class="small"><?= h(date('d M Y', strtotime($a['created_at']))) ?></td>
            <td class="no-print">
              <div class="d-flex gap-2">
                <a class="btn btn-sm btn-outline-primary icon-btn" href="user_management.php?edit=<?= (int)$a['id'] ?><?= $fq . $ff ?>" title="Edit"><i class="bi bi-pencil"></i></a>
                <?php if ($a['id'] !== $meId): ?>
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle_user">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <button class="btn btn-sm <?= $a['status'] === 'Active' ? 'btn-outline-warning' : 'btn-outline-success' ?> icon-btn"
                            title="<?= $a['status'] === 'Active' ? 'Deactivate' : 'Activate' ?>">
                      <i class="bi bi-<?= $a['status'] === 'Active' ? 'person-slash' : 'person-check' ?>"></i>
                    </button>
                  </form>
                  <form method="post" data-confirm="Delete account <?= h($a['username']) ?>?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger icon-btn" title="Delete"><i class="bi bi-trash"></i></button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pager_nav($page, $pages, $total, 10, count($accounts)) ?>
</div>
<?php layout_end(); ?>
