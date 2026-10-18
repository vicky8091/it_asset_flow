<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';

// Confirm-then-logout: GET shows the confirmation card, POST performs the logout.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    log_action('logout', 'Admin logged out');
    session_unset();
    session_destroy();
    session_start();
    flash('You have been logged out.', 'info');
    redirect('login.php');
}

if (empty($_SESSION['admin'])) {
    redirect('login.php');
}

layout_start('Logout', '', '', 'box-arrow-right', false);
?>
<div class="row justify-content-center" style="min-height:50vh;" >
  <div class="col-md-5 col-xl-4">
    <div class="card mt-5">
      <div class="card-body p-4 text-center">
        <span class="page-icon mb-3" style="background:linear-gradient(135deg,#f87171,#dc2626);box-shadow:0 8px 18px rgba(220,38,38,.28);">
          <i class="bi bi-box-arrow-right"></i>
        </span>
        <h4 style="font-weight:800;">Logout</h4>
        <p class="text-muted mb-4">Are you sure you want to log out?<br>
          You will be signed out of your account and redirected to the login page.</p>
        <form method="post" class="d-grid gap-2 d-sm-flex justify-content-center">
          <?= csrf_field() ?>
          <a class="btn btn-outline-secondary px-4" href="dashboard.php">Cancel</a>
          <button class="btn btn-danger px-4"><i class="bi bi-box-arrow-right"></i> Log Out</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php layout_end(); ?>
