<?php
require_once __DIR__ . '/config/app.php';

// If currently impersonating a user, "logout" returns to the admin account
// instead of ending the session.
if (!empty($_SESSION['impersonator'])) {
    $_SESSION['user'] = $_SESSION['impersonator'];
    unset($_SESSION['impersonator']);
    header('Location: ' . APP_URL . '/app.php?view=users');
    exit;
}

session_destroy();
header('Location: ' . APP_URL . '/auth.php');
exit;
