<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'includes/db.php';
require_once 'includes/auth.php';
requireLogin();
$page_title = 'Access Denied';
require_once 'includes/header.php';
?>
<div class="main-content" style="display:flex;align-items:center;justify-content:center;min-height:80vh;">
    <div style="text-align:center;">
        <div style="font-size:4rem;margin-bottom:16px;">🔒</div>
        <h2 style="font-family:'Syne',sans-serif;color:var(--accent);margin-bottom:8px;">Access Denied</h2>
        <p style="color:var(--text-muted);margin-bottom:24px;">You don't have permission to view this page.<br>Admin role is required.</p>
        <a href="index.php" class="btn-accent">← Back to Dashboard</a>
    </div>
</div>
<?php require_once 'includes/footer.php'; ?>
