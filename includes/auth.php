<?php
// ============================================================
// Authentication & Audit Helper – auth.php
// Roles: Admin > Manager > Employee
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function requireLogin(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

function requireAdmin(): void {
    requireLogin();
    if ($_SESSION['role'] !== 'Admin') {
        header('Location: access_denied.php');
        exit;
    }
}

function requireAdminOrManager(): void {
    requireLogin();
    if (!in_array($_SESSION['role'], ['Admin', 'Manager'])) {
        header('Location: access_denied.php');
        exit;
    }
}

function currentUser(): array {
    return [
        'user_id'   => $_SESSION['user_id']   ?? null,
        'username'  => $_SESSION['username']  ?? '',
        'full_name' => $_SESSION['full_name'] ?? '',
        'role'      => $_SESSION['role']      ?? '',
    ];
}

function isAdmin(): bool   { return ($_SESSION['role'] ?? '') === 'Admin'; }
function isManager(): bool { return ($_SESSION['role'] ?? '') === 'Manager'; }
function isEmployee(): bool{ return ($_SESSION['role'] ?? '') === 'Employee'; }

function auditLog(string $action, string $target = '', string $detail = ''): void {
    if (empty($_SESSION['user_id'])) return;
    try {
        $db   = getDB();
        $stmt = $db->prepare("
            INSERT INTO audit_log (user_id, username, action, target, detail, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $_SESSION['username'],
            $action,
            $target,
            $detail,
            $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
    } catch (PDOException $e) {}
}
