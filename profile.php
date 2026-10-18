<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_login();

$meId = (int)$_SESSION['admin']['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (($_POST['action'] ?? '') === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $employeeId = trim($_POST['employee_id'] ?? '');
        if ($name === '') {
            flash('Name is required.', 'danger');
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Enter a valid email address.', 'danger');
        } else {
            $dup = db()->prepare('SELECT id FROM admins WHERE email = ? AND email <> \'\' AND id <> ?');
            $dup->execute([$email, $meId]);
            if ($dup->fetch()) {
                flash('That email is already in use by another account.', 'danger');
            } else {
                $pic = save_account_pic($meId, $_FILES['pic'] ?? null);
                if (!empty($_POST['remove_pic'])) {
                    remove_account_pic($meId);
                    db()->prepare('UPDATE admins SET profile_pic = ? WHERE id = ?')->execute(['', $meId]);
                    $pic = '';
                }
                db()->prepare('UPDATE admins SET name = ?, email = ?, department = ?, employee_id = ? WHERE id = ?')
                    ->execute([$name, $email, $department, $employeeId, $meId]);
                if ($pic) db()->prepare('UPDATE admins SET profile_pic = ? WHERE id = ?')->execute([$pic, $meId]);
                $_SESSION['admin']['name'] = $name;
                $_SESSION['admin']['email'] = $email;
                $st = db()->prepare('SELECT profile_pic FROM admins WHERE id = ?');
                $st->execute([$meId]);
                $_SESSION['admin']['pic'] = (string)$st->fetchColumn();
                log_action('profile_updated', $_SESSION['admin']['username']);
                flash('Profile updated.');
            }
        }
        redirect('profile.php');
    }

    if (($_POST['action'] ?? '') === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $st = db()->prepare('SELECT * FROM admins WHERE id = ?');
        $st->execute([$meId]);
        $acc = $st->fetch();

        if (!$acc || !password_verify($current, $acc['password_hash'])) {
            flash('Current password is incorrect.', 'danger');
        } elseif (strlen($new) < 8) {
            flash('New password must be at least 8 characters.', 'danger');
        } elseif ($new !== $confirm) {
            flash('New passwords do not match.', 'danger');
        } elseif ($current === $new) {
            flash('The new password must be different from the current one.', 'warning');
        } else {
            db()->prepare('UPDATE admins SET password_hash = ?, must_change_pwd = 0 WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $meId]);
            unset($_SESSION['admin']['must_change']);
            log_action('password_changed', 'Own password changed');
            flash('Password changed successfully.');
        }
        redirect('profile.php');
    }
}

$st = db()->prepare('SELECT * FROM admins WHERE id = ?');
$st->execute([$meId]);
$me = $st->fetch();
if (!$me) { session_unset(); session_destroy(); redirect('login.php'); }

layout_start('My Profile', 'profile', 'View and update your personal details and password.', 'person', false);
?>
<div class="page-head">
  <span class="page-icon"><i class="bi bi-person"></i></span>
  <div>
    <h1>My Profile</h1>
    <div class="sub">Manage your personal details — signed in as <strong><?= h($me['username']) ?></strong></div>
  </div>
  <span class="ms-auto"><?= badge($me['role'] === 'admin' ? 'Admin' : 'User') ?></span>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-person text-primary"></i> Profile Details</div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_profile">
          <div class="d-flex align-items-center gap-3 mb-4">
            <?= account_avatar($me) ?>
            <div class="flex-grow-1">
              <label class="form-label mb-1">Profile Picture</label>
              <input class="form-control form-control-sm" type="file" name="pic" accept=".png,.jpg,.jpeg,.webp">
              <div class="form-text">PNG, JPG or WebP · max <?= round(UPLOAD_MAX_BYTES / 1048576) ?> MB · up to 1024×1024px.</div>
              <?php if ($me['profile_pic']): ?>
                <div class="form-check mt-1">
                  <input class="form-check-input" type="checkbox" name="remove_pic" value="1" id="rmPic">
                  <label class="form-check-label small text-muted" for="rmPic">Remove current picture</label>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Full Name <span class="req">*</span></label>
              <input class="form-control" name="name" required maxlength="100" value="<?= h($me['name']) ?>"></div>
            <div class="col-md-6"><label class="form-label">Email</label>
              <input class="form-control" type="email" name="email" value="<?= h($me['email']) ?>" placeholder="name@company.com"></div>
            <div class="col-md-6"><label class="form-label">Department</label>
              <input class="form-control" name="department" maxlength="100" value="<?= h($me['department']) ?>"></div>
            <div class="col-md-6"><label class="form-label">Employee ID</label>
              <input class="form-control" name="employee_id" maxlength="50" value="<?= h($me['employee_id']) ?>"></div>
            <div class="col-md-6"><label class="form-label">Username</label>
              <input class="form-control" value="<?= h($me['username']) ?>" disabled>
              <div class="form-text">Usernames cannot be changed.</div></div>
            <div class="col-md-6"><label class="form-label">Role</label>
              <input class="form-control" value="<?= $me['role'] === 'admin' ? 'Admin' : 'User' ?>" disabled>
              <div class="form-text">Roles are managed by Admin accounts.</div></div>
          </div>
          <button class="btn btn-primary mt-4"><i class="bi bi-check-lg"></i> Save Profile</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card" id="password">
      <div class="card-header"><i class="bi bi-key text-primary"></i> Change Password</div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="change_password">
          <div class="mb-3"><label class="form-label">Current Password</label>
            <input class="form-control" type="password" name="current_password" required autocomplete="current-password"></div>
          <div class="mb-3"><label class="form-label">New Password (min 8 chars)</label>
            <input class="form-control" type="password" name="new_password" minlength="8" required autocomplete="new-password"></div>
          <div class="mb-3"><label class="form-label">Confirm New Password</label>
            <input class="form-control" type="password" name="confirm_password" minlength="8" required autocomplete="new-password"></div>
          <button class="btn btn-primary w-100"><i class="bi bi-key"></i> Update Password</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php layout_end(); ?>
