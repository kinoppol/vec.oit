<?php
require_once __DIR__ . '/config/app.php';
session_destroy();
header('Location: ' . APP_URL . '/auth.php');
exit;
