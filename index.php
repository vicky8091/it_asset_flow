<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';

redirect(empty($_SESSION['admin']) ? 'login.php' : 'dashboard.php');
