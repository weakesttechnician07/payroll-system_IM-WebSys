<?php
// ============================================================
// Login – login.php
// Demonstrates: session handling, password_verify (bcrypt)
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

// Already logged in → go to dashboard
if (!empty($_SESSION['user_id'])) {
    header('Location: index.php'); exit;
}

require_once 'includes/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND status = 'Active' LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Regenerate session ID to prevent session fixation
            session_regenerate_id(true);

            $_SESSION['user_id']   = $user['user_id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = $user['role'];

            // Audit log (inline since auth.php not loaded yet)
            try {
                $log = $db->prepare("
                    INSERT INTO audit_log (user_id, username, action, target, detail, ip_address)
                    VALUES (?, ?, 'Login', 'System', 'Successful login', ?)
                ");
                $log->execute([$user['user_id'], $user['username'], $_SERVER['REMOTE_ADDR'] ?? '']);
            } catch (PDOException $e) {}

            header('Location: index.php'); exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PayrollPH – Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1a1a2e; --accent: #e94560; --surface: #16213e;
            --card-bg: #1a2744; --border: #2a3a5c; --text-main: #e8eaf0;
            --text-muted: #8892a4;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: var(--primary);
            font-family: 'DM Sans', sans-serif;
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
        }
        .login-wrap {
            width: 100%; max-width: 420px; padding: 24px;
        }
        .login-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 40px 36px;
        }
        .brand { text-align: center; margin-bottom: 32px; }
        .brand .logo { font-family: 'Syne', sans-serif; font-weight: 800; font-size: 2rem; color: var(--accent); }
        .brand small { display: block; color: var(--text-muted); font-size: 0.75rem; letter-spacing: 2px; text-transform: uppercase; margin-top: 4px; }
        .form-label { font-size: 0.78rem; font-weight: 500; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px; }
        .form-control {
            background: var(--primary); border: 1px solid var(--border);
            color: var(--text-main); border-radius: 8px; padding: 10px 14px;
        }
        .form-control:focus { background: var(--primary); border-color: var(--accent); color: var(--text-main); box-shadow: 0 0 0 3px rgba(233,69,96,0.15); }
        .form-control::placeholder { color: var(--text-muted); }
        .btn-login {
            width: 100%; background: var(--accent); color: #fff; border: none;
            border-radius: 8px; padding: 11px; font-weight: 600; font-size: 0.95rem;
            cursor: pointer; transition: background 0.2s;
        }
        .btn-login:hover { background: #c73652; }
        .error-box {
            background: rgba(231,76,60,0.1); border: 1px solid rgba(231,76,60,0.3);
            color: #e74c3c; border-radius: 8px; padding: 10px 14px;
            font-size: 0.875rem; margin-bottom: 20px;
        }
        .hint { color: var(--text-muted); font-size: 0.78rem; text-align: center; margin-top: 20px; }
        .hint code { color: var(--accent); background: rgba(233,69,96,0.1); padding: 2px 6px; border-radius: 4px; }
    </style>
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <div class="brand">
            <div class="logo">PayrollPH</div>
            <small>IT221 + WebSys – Payroll System</small>
        </div>

        <?php if ($error): ?>
        <div class="error-box">⚠ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-control" placeholder="Enter username"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
            </div>
            <div class="mb-4">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" placeholder="Enter password" required>
            </div>
            <button type="submit" class="btn-login">Sign In</button>
        </form>

        <div class="hint">
            Default credentials: <code>admin</code> / <code>password</code>
        </div>
    </div>
</div>
</body>
</html>
