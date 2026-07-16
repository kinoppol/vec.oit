<?php
require_once __DIR__ . '/config/app.php';

if (!empty($_SESSION['user'])) {
    header('Location: ' . APP_URL . '/app.php');
} else {
    header('Location: ' . APP_URL . '/auth.php');
}
exit;
