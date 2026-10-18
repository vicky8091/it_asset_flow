<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';

if (!empty($_SESSION['admin'])) redirect('dashboard.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = db()->prepare('SELECT * FROM admins WHERE username = ?');
    $st->execute([trim($_POST['username'] ?? '')]);
    $admin = $st->fetch();

    if ($admin && password_verify($_POST['password'] ?? '', $admin['password_hash'])) {
        if ($admin['status'] !== 'Active') {
            flash('This account is inactive. Please contact an administrator.', 'danger');
            redirect('login.php');
        }
        session_regenerate_id(true);
        $_SESSION['admin'] = [
            'id' => $admin['id'],
            'username' => $admin['username'],
            'name' => $admin['name'],
            'email' => $admin['email'],
            'role' => $admin['role'],
            'pic' => $admin['profile_pic'],
            'must_change' => (bool)$admin['must_change_pwd'],
        ];
        log_action('login', 'Admin logged in');
        redirect('dashboard.php');
    }
    // ponytail: sleep + log instead of a lockout table — good enough to blunt brute force.
    usleep(random_int(200000, 500000));
    log_action('login_failed', 'username: ' . substr(trim($_POST['username'] ?? ''), 0, 50));
    flash('Invalid username or password.', 'danger');
    redirect('login.php');
}

$flashes = get_flashes();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login — <?= h(APP_NAME) ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📦</text></svg>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/css/style.css?v=7" rel="stylesheet">
</head>
<body class="bg-light login-page">
<div class="container" style="max-width:410px;margin-top:9vh;">
  <div class="text-center mb-4 login-brand">
    <span class="logo"><i class="bi bi-box-seam-fill"></i></span>
    <h4 class="mt-3 mb-0 text-white" style="font-weight:800;"><?= h(APP_NAME) ?></h4>
    <div class="mt-1" style="color:#7fa3c8;font-size:12.5px;letter-spacing:.4px;">Track · Manage · Support</div>
  </div>
  <?php foreach ($flashes as $f): ?>
    <div class="alert alert-<?= h($f['type']) ?>"><?= h($f['msg']) ?></div>
  <?php endforeach; ?>
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <form method="post">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label">Username</label>
          <input class="form-control" name="username" required autofocus>
        </div>
        <div class="mb-3">
          <label class="form-label">Password</label>
          <input class="form-control" type="password" name="password" required>
        </div>
        <button class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-right"></i> Login</button>
      </form>
    </div>
  </div>
</div>
</body>
</html>
