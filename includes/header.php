<?php
// ============================================================
// Shared Layout – Header
// Roles: Admin > Manager > Employee
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/auth.php';
requireLogin();
$current_page = basename($_SERVER['PHP_SELF'], '.php');
$__user = currentUser();

// ── Currency toggle ──────────────────────────────────────────
if (isset($_GET['currency'])) {
    $_SESSION['currency'] = $_GET['currency'] === 'USD' ? 'USD' : 'PHP';
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query(array_diff_key($_GET, ['currency' => ''])));
    exit;
}
$currency = $_SESSION['currency'] ?? 'PHP';

// Live USD rate (fallback 56 if fetch fails)
function getUsdRate(): float {
    static $rate = null;
    if ($rate !== null) return $rate;
    $rate = 56.00; // fallback
    $ctx = @stream_context_create(['http'=>['timeout'=>2]]);
    $r   = @file_get_contents('https://open.er-api.com/v6/latest/USD', false, $ctx);
    if ($r) {
        $d = json_decode($r, true);
        if (isset($d['rates']['PHP'])) $rate = (float)$d['rates']['PHP'];
    }
    return $rate;
}

function formatMoney(float $amount): string {
    global $currency;
    if ($currency === 'USD') {
        $usd = $amount / getUsdRate();
        return '$' . number_format($usd, 2);
    }
    return '₱' . number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PayrollPH – <?= htmlspecialchars($page_title ?? 'System') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary:#1a1a2e;--accent:#e94560;--accent2:#0f3460;
            --surface:#16213e;--card-bg:#1a2744;--text-main:#e8eaf0;
            --text-muted:#8892a4;--border:#2a3a5c;--success:#2ecc71;
            --warning:#f39c12;--danger:#e74c3c;
        }
        *{box-sizing:border-box;margin:0;padding:0;}
        body{background:var(--primary);color:var(--text-main);font-family:'DM Sans',sans-serif;min-height:100vh;}
        .sidebar{width:240px;min-height:100vh;background:var(--surface);border-right:1px solid var(--border);position:fixed;top:0;left:0;display:flex;flex-direction:column;z-index:100;}
        .sidebar-brand{padding:28px 24px 20px;border-bottom:1px solid var(--border);}
        .sidebar-brand .logo-mark{font-family:'Syne',sans-serif;font-weight:800;font-size:1.4rem;color:var(--accent);}
        .sidebar-brand small{display:block;font-size:0.7rem;color:var(--text-muted);letter-spacing:2px;text-transform:uppercase;margin-top:2px;}
        .nav-section-label{font-size:0.65rem;letter-spacing:2px;text-transform:uppercase;color:var(--text-muted);padding:20px 24px 8px;}
        .sidebar .nav-link{display:flex;align-items:center;gap:12px;padding:10px 24px;color:var(--text-muted);font-size:0.88rem;font-weight:400;border-left:3px solid transparent;transition:all 0.2s;text-decoration:none;}
        .sidebar .nav-link:hover,.sidebar .nav-link.active{color:var(--text-main);background:rgba(233,69,96,0.08);border-left-color:var(--accent);}
        .sidebar .nav-link i{font-size:1rem;min-width:20px;}
        .main-content{margin-left:240px;padding:32px 36px;min-height:100vh;}
        .page-header{margin-bottom:28px;}
        .page-header h1{font-family:'Syne',sans-serif;font-weight:700;font-size:1.8rem;color:var(--text-main);}
        .page-header p{color:var(--text-muted);font-size:0.9rem;}
        .card{background:var(--card-bg);border:1px solid var(--border);border-radius:12px;color:var(--text-main);}
        .card-header{background:transparent;border-bottom:1px solid var(--border);font-family:'Syne',sans-serif;font-weight:600;font-size:0.95rem;padding:16px 20px;color:var(--text-main);}
        .card-body{padding:20px;}
        .stat-card{background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:22px;position:relative;overflow:hidden;}
        .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--accent);}
        .stat-card .stat-icon{width:44px;height:44px;border-radius:10px;background:rgba(233,69,96,0.15);display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:var(--accent);margin-bottom:14px;}
        .stat-card .stat-value{font-family:'Syne',sans-serif;font-size:1.7rem;font-weight:700;color:var(--text-main);}
        .stat-card .stat-label{font-size:0.78rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;}
        .table{color:var(--text-main);font-size:0.875rem;}
        .table thead th{background:rgba(15,52,96,0.5);color:var(--text-muted);font-size:0.7rem;text-transform:uppercase;letter-spacing:1.5px;border-color:var(--border);padding:12px 16px;font-weight:500;}
        .table tbody td{border-color:var(--border);padding:12px 16px;vertical-align:middle;}
        .table tbody tr:hover{background:rgba(255,255,255,0.03);}
        .form-control,.form-select{background:var(--primary);border:1px solid var(--border);color:var(--text-main);border-radius:8px;font-size:0.875rem;}
        .form-control:focus,.form-select:focus{background:var(--primary);border-color:var(--accent);color:var(--text-main);box-shadow:0 0 0 3px rgba(233,69,96,0.15);}
        .form-control::placeholder{color:var(--text-muted);}
        .form-label{font-size:0.8rem;font-weight:500;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:6px;}
        .btn-accent{background:var(--accent);color:#fff;border:none;border-radius:8px;font-weight:500;font-size:0.875rem;padding:9px 20px;transition:all 0.2s;}
        .btn-accent:hover{background:#c73652;color:#fff;transform:translateY(-1px);}
        .btn-outline-accent{border:1px solid var(--accent);color:var(--accent);background:transparent;border-radius:8px;font-size:0.875rem;padding:7px 16px;transition:all 0.2s;}
        .btn-outline-accent:hover{background:var(--accent);color:#fff;}
        .badge-active{background:rgba(46,204,113,0.15);color:var(--success);border-radius:20px;padding:4px 10px;font-size:0.75rem;}
        .badge-inactive{background:rgba(231,76,60,0.15);color:var(--danger);border-radius:20px;padding:4px 10px;font-size:0.75rem;}
        .alert-success-dark{background:rgba(46,204,113,0.1);border:1px solid rgba(46,204,113,0.3);color:var(--success);border-radius:8px;padding:12px 16px;}
        .alert-danger-dark{background:rgba(231,76,60,0.1);border:1px solid rgba(231,76,60,0.3);color:var(--danger);border-radius:8px;padding:12px 16px;}
        .divider{border-top:1px solid var(--border);margin:24px 0;}
        .text-accent{color:var(--accent);}
        .text-muted{color:var(--text-muted)!important;}
        /* Currency toggle */
        .currency-toggle{display:flex;gap:4px;align-items:center;}
        .currency-toggle a{font-size:0.72rem;padding:3px 8px;border-radius:6px;text-decoration:none;color:var(--text-muted);border:1px solid var(--border);transition:all 0.2s;}
        .currency-toggle a.active{background:var(--accent);color:#fff;border-color:var(--accent);}
        ::-webkit-scrollbar{width:6px;}
        ::-webkit-scrollbar-track{background:var(--primary);}
        ::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px;}
    </style>
</head>
<body>
<nav class="sidebar">
    <div class="sidebar-brand">
        <div class="logo-mark">PayrollPH</div>
        <small>IT221 – Info Management</small>
    </div>

    <?php if (isEmployee()): ?>
    <!-- ── EMPLOYEE SIDEBAR ── -->
    <div class="nav-section-label">My Info</div>
    <a href="index.php"    class="nav-link <?= $current_page==='index'    ?'active':'' ?>"><i class="bi bi-speedometer2"></i> My Dashboard</a>
    <a href="employees.php"class="nav-link <?= $current_page==='employees'?'active':'' ?>"><i class="bi bi-person-circle"></i> My Profile</a>
    <a href="benefits.php" class="nav-link <?= $current_page==='benefits' ?'active':'' ?>"><i class="bi bi-gift"></i> My Benefits</a>
    <div class="nav-section-label">Payroll</div>
    <a href="history.php"  class="nav-link <?= $current_page==='history'  ?'active':'' ?>"><i class="bi bi-clock-history"></i> My Payroll History</a>

    <?php elseif (isManager()): ?>
    <!-- ── MANAGER SIDEBAR ── -->
    <div class="nav-section-label">Main</div>
    <a href="index.php"    class="nav-link <?= $current_page==='index'    ?'active':'' ?>"><i class="bi bi-speedometer2"></i> Dashboard</a>
    <a href="employees.php"class="nav-link <?= $current_page==='employees'?'active':'' ?>"><i class="bi bi-people"></i> Employees</a>
    <div class="nav-section-label">Payroll</div>
    <a href="history.php"  class="nav-link <?= $current_page==='history'  ?'active':'' ?>"><i class="bi bi-clock-history"></i> Payroll History</a>

    <?php else: ?>
    <!-- ── ADMIN SIDEBAR ── -->
    <div class="nav-section-label">Main</div>
    <a href="index.php"    class="nav-link <?= $current_page==='index'    ?'active':'' ?>"><i class="bi bi-speedometer2"></i> Dashboard</a>
    <a href="employees.php"class="nav-link <?= $current_page==='employees'?'active':'' ?>"><i class="bi bi-people"></i> Employees</a>
    <div class="nav-section-label">Payroll</div>
    <a href="process.php"  class="nav-link <?= $current_page==='process'  ?'active':'' ?>"><i class="bi bi-cash-coin"></i> Process Payroll</a>
    <a href="history.php"  class="nav-link <?= $current_page==='history'  ?'active':'' ?>"><i class="bi bi-clock-history"></i> Payroll History</a>
    <div class="nav-section-label">Warehouse</div>
    <a href="warehouse.php"class="nav-link <?= $current_page==='warehouse'?'active':'' ?>"><i class="bi bi-database"></i> Data Warehouse</a>
    <div class="nav-section-label">Admin</div>
    <a href="users.php"    class="nav-link <?= $current_page==='users'    ?'active':'' ?>"><i class="bi bi-shield-lock"></i> User Access</a>
    <?php endif; ?>

    <!-- ── Footer: user info + currency toggle ── -->
    <div style="margin-top:auto;padding:16px 24px;border-top:1px solid var(--border);">
        <div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:2px;">Logged in as</div>
        <div style="font-size:0.88rem;font-weight:600;color:var(--text-main);"><?= htmlspecialchars($__user['full_name']) ?></div>
        <?php
        $roleColor = match($__user['role']) { 'Admin'=>'var(--accent)', 'Manager'=>'#4a9eff', 'Employee'=>'#2ecc71', default=>'var(--text-muted)' };
        $roleIcon  = match($__user['role']) { 'Admin'=>'👑', 'Manager'=>'🏢', 'Employee'=>'👤', default=>'' };
        ?>
        <div style="font-size:0.75rem;color:<?= $roleColor ?>;margin-bottom:10px;"><?= $roleIcon ?> <?= $__user['role'] ?></div>
        <!-- Currency toggle -->
        <div class="currency-toggle mb-2">
            <span style="font-size:0.7rem;color:var(--text-muted);margin-right:4px;">Currency:</span>
            <a href="?currency=PHP" class="<?= ($currency==='PHP')?'active':'' ?>">₱ PHP</a>
            <a href="?currency=USD" class="<?= ($currency==='USD')?'active':'' ?>">$ USD</a>
        </div>
        <a href="logout.php" style="font-size:0.8rem;color:var(--text-muted);text-decoration:none;">
            <i class="bi bi-box-arrow-left me-1"></i> Sign Out
        </a>
    </div>
</nav>
