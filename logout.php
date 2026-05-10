<?php
// ============================================================
// Logout – logout.php
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

require_once 'includes/db.php';
require_once 'includes/auth.php';

// Log the logout before destroying session
auditLog('Logout', 'System', 'User logged out');

session_unset();
session_destroy();

header('Location: login.php');
exit;
