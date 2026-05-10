<?php
// ============================================================
// User Access & Audit Trail – users.php  (Module 5)
// Admin only. Demonstrates: bcrypt hashing, role-based access,
// session security, audit logging.
// ============================================================
require_once 'includes/db.php';
require_once 'includes/auth.php';
requireAdmin();   // ← only Admins may access this page

$page_title = 'User Access & Audit Trail';
$db  = getDB();
$msg = '';
$msg_type = '';

// ── Handle POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ADD user
    if ($action === 'add') {
        $uname = trim($_POST['username']);
        $pass  = $_POST['password'];
        $fname = trim($_POST['full_name']);
        $role  = $_POST['role'];

        if (strlen($pass) < 6) {
            $msg = 'Password must be at least 6 characters.'; $msg_type = 'danger';
        } else {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            try {
                $stmt = $db->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?,?,?,?)");
                $stmt->execute([$uname, $hash, $fname, $role]);
                auditLog('Add User', 'users', "Created user: $uname ($role)");
                $msg = "User '$uname' created successfully."; $msg_type = 'success';
            } catch (PDOException $e) {
                $msg = 'Error: ' . $e->getMessage(); $msg_type = 'danger';
            }
        }
    }

    // EDIT user
    if ($action === 'edit') {
        $uid   = (int)$_POST['user_id'];
        $fname = trim($_POST['full_name']);
        $role  = $_POST['role'];
        $status= $_POST['status'];

        // Prevent admin from demoting themselves
        $cu = currentUser();
        if ($uid === (int)$cu['user_id'] && $role !== 'Admin') {
            $msg = 'You cannot change your own role.'; $msg_type = 'danger';
        } else {
            $stmt = $db->prepare("UPDATE users SET full_name=?, role=?, status=? WHERE user_id=?");
            $stmt->execute([$fname, $role, $status, $uid]);
            auditLog('Edit User', 'users', "Updated user_id=$uid role=$role status=$status");
            $msg = 'User updated.'; $msg_type = 'success';
        }
    }

    // RESET password
    if ($action === 'reset_password') {
        $uid  = (int)$_POST['user_id'];
        $pass = $_POST['new_password'];
        if (strlen($pass) < 6) {
            $msg = 'Password must be at least 6 characters.'; $msg_type = 'danger';
        } else {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            $stmt = $db->prepare("UPDATE users SET password=? WHERE user_id=?");
            $stmt->execute([$hash, $uid]);
            auditLog('Reset Password', 'users', "Password reset for user_id=$uid");
            $msg = 'Password reset successfully.'; $msg_type = 'success';
        }
    }
}

// ── Fetch data ───────────────────────────────────────────────
$users = $db->query("SELECT * FROM users ORDER BY role, username")->fetchAll();

// Audit log filters
$log_user   = isset($_GET['log_user']) ? trim($_GET['log_user']) : '';
$log_action = isset($_GET['log_action']) ? trim($_GET['log_action']) : '';
$log_where  = ['1=1'];
$log_params = [];
if ($log_user)   { $log_where[] = 'al.username LIKE ?'; $log_params[] = "%$log_user%"; }
if ($log_action) { $log_where[] = 'al.action LIKE ?';   $log_params[] = "%$log_action%"; }

$log_stmt = $db->prepare("
    SELECT * FROM vw_audit_log al
    WHERE " . implode(' AND ', $log_where) . "
    ORDER BY logged_at DESC
    LIMIT 100
");
$log_stmt->execute($log_params);
$logs = $log_stmt->fetchAll();

// Stats
$total_users  = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$total_admins = $db->query("SELECT COUNT(*) FROM users WHERE role='Admin'")->fetchColumn();
$total_logs   = $db->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();
$last_login   = $db->query("SELECT logged_at FROM audit_log WHERE action='Login' ORDER BY logged_at DESC LIMIT 1")->fetchColumn();

require_once 'includes/header.php';
?>

<div class="main-content">
    <div class="page-header d-flex justify-content-between align-items-start">
        <div>
            <h1><i class="bi bi-shield-lock me-2 text-accent"></i>User Access & Audit Trail</h1>
            <p>Role-based access control · bcrypt password hashing · session security · audit logging</p>
        </div>
        <button class="btn-accent" data-bs-toggle="modal" data-bs-target="#addUserModal">
            <i class="bi bi-person-plus me-1"></i> Add User
        </button>
    </div>

    <?php if ($msg): ?>
    <div class="alert-<?= $msg_type === 'success' ? 'success' : 'danger' ?>-dark mb-3">
        <i class="bi bi-<?= $msg_type === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
        <?= htmlspecialchars($msg) ?>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-people"></i></div>
                <div class="stat-value"><?= $total_users ?></div>
                <div class="stat-label">Total Users</div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="stat-card" style="--accent:#0f3460;">
                <div class="stat-icon" style="background:rgba(15,52,96,0.3);color:#4a9eff;"><i class="bi bi-shield-check"></i></div>
                <div class="stat-value"><?= $total_admins ?></div>
                <div class="stat-label">Admin Users</div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="stat-card" style="--accent:#2ecc71;">
                <div class="stat-icon" style="background:rgba(46,204,113,0.15);color:#2ecc71;"><i class="bi bi-journal-text"></i></div>
                <div class="stat-value"><?= number_format($total_logs) ?></div>
                <div class="stat-label">Audit Log Entries</div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="stat-card" style="--accent:#f39c12;">
                <div class="stat-icon" style="background:rgba(243,156,18,0.15);color:#f39c12;"><i class="bi bi-box-arrow-in-right"></i></div>
                <div class="stat-value" style="font-size:0.95rem;margin-top:8px;">
                    <?= $last_login ? date('M d, H:i', strtotime($last_login)) : 'None' ?>
                </div>
                <div class="stat-label">Last Login</div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-pills mb-4" style="gap:8px;">
        <li><a class="nav-link active" data-bs-toggle="pill" href="#tab-users" style="background:var(--accent);border-radius:8px;font-size:0.85rem;"><i class="bi bi-people me-1"></i>Users</a></li>
        <li><a class="nav-link" data-bs-toggle="pill" href="#tab-audit" style="color:var(--text-muted);border-radius:8px;font-size:0.85rem;"><i class="bi bi-journal-text me-1"></i>Audit Log</a></li>
        <li><a class="nav-link" data-bs-toggle="pill" href="#tab-security" style="color:var(--text-muted);border-radius:8px;font-size:0.85rem;"><i class="bi bi-shield me-1"></i>Security Notes</a></li>
    </ul>

    <div class="tab-content">
        <!-- Users Tab -->
        <div class="tab-pane fade show active" id="tab-users">
            <div class="card">
                <div class="card-header"><?= count($users) ?> registered users</div>
                <div class="card-body p-0">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                                <td><?= htmlspecialchars($u['full_name']) ?></td>
                                <td>
                                    <?php if ($u['role'] === 'Admin'): ?>
                                    <span style="color:#4a9eff;font-size:0.8rem;"><i class="bi bi-shield-fill me-1"></i>Admin</span>
                                    <?php elseif ($u['role'] === 'Manager'): ?>
                                    <span style="color:#f39c12;font-size:0.8rem;"><i class="bi bi-briefcase-fill me-1"></i>Manager</span>
                                    <?php else: ?>
                                    <span style="color:#2ecc71;font-size:0.8rem;"><i class="bi bi-person me-1"></i>Employee</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge-<?= strtolower($u['status']) ?>"><?= $u['status'] ?></span></td>
                                <td style="color:var(--text-muted);font-size:0.8rem;"><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                                <td>
                                    <button class="btn-outline-accent btn-sm"
                                        onclick="openEditUser(<?= htmlspecialchars(json_encode($u)) ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn-outline-accent btn-sm ms-1"
                                        onclick="openResetPw(<?= $u['user_id'] ?>, '<?= htmlspecialchars($u['username']) ?>')"
                                        style="border-color:var(--warning);color:var(--warning);">
                                        <i class="bi bi-key"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Audit Log Tab -->
        <div class="tab-pane fade" id="tab-audit">
            <div class="card mb-3">
                <div class="card-body">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-sm-4">
                            <label class="form-label">Filter by User</label>
                            <input type="text" name="log_user" class="form-control" placeholder="Username…" value="<?= htmlspecialchars($log_user) ?>">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label">Filter by Action</label>
                            <input type="text" name="log_action" class="form-control" placeholder="Login, Add, Edit…" value="<?= htmlspecialchars($log_action) ?>">
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn-accent">Filter</button>
                            <a href="users.php#tab-audit" class="btn-outline-accent ms-2">Reset</a>
                        </div>
                        <input type="hidden" name="tab" value="audit">
                    </form>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><code>vw_audit_log</code> – last 100 entries</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0" style="font-size:0.82rem;">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Action</th>
                                    <th>Target</th>
                                    <th>Detail</th>
                                    <th>IP</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($logs)): ?>
                                <tr><td colspan="8" class="text-center py-4" style="color:var(--text-muted)">No audit logs yet.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($logs as $l): ?>
                                <tr>
                                    <td style="color:var(--text-muted)"><?= $l['log_id'] ?></td>
                                    <td><strong><?= htmlspecialchars($l['username']) ?></strong></td>
                                    <td style="color:var(--text-muted)"><?= $l['role'] ?? '—' ?></td>
                                    <td>
                                        <?php
                                        $actionColor = match($l['action']) {
                                            'Login'          => '#2ecc71',
                                            'Logout'         => '#f39c12',
                                            'Add User','Add Employee'      => '#4a9eff',
                                            'Edit User','Edit Employee'    => '#f39c12',
                                            'Delete Employee'              => '#e74c3c',
                                            'Process Payroll'              => '#a29bfe',
                                            'Run ETL'        => '#fd79a8',
                                            default          => '#8892a4'
                                        };
                                        ?>
                                        <span style="color:<?= $actionColor ?>;font-weight:500;"><?= htmlspecialchars($l['action']) ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($l['target']) ?></td>
                                    <td style="color:var(--text-muted);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($l['detail']) ?>">
                                        <?= htmlspecialchars($l['detail']) ?>
                                    </td>
                                    <td style="color:var(--text-muted)"><?= htmlspecialchars($l['ip_address']) ?></td>
                                    <td style="color:var(--text-muted)"><?= date('M d H:i:s', strtotime($l['logged_at'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Security Notes Tab -->
        <div class="tab-pane fade" id="tab-security">
            <div class="card">
                <div class="card-header"><i class="bi bi-shield me-2 text-accent"></i>Security Implementation Details</div>
                <div class="card-body">
                    <div class="row g-4">
                        <?php
                        $notes = [
                            ['icon'=>'bi-lock','title'=>'Password Hashing','color'=>'#4a9eff','text'=>'All passwords are hashed using PHP\'s password_hash() with PASSWORD_BCRYPT (cost factor 12). Plain-text passwords are never stored. Verification uses password_verify() which is timing-safe.'],
                            ['icon'=>'bi-person-badge','title'=>'Role-Based Access Control','color'=>'#2ecc71','text'=>'Two roles exist: Admin and Staff. Admin has full access to all pages including User Management. Staff cannot access User Management or process payroll deletions. requireAdmin() and requireLogin() guards are placed at the top of each page.'],
                            ['icon'=>'bi-key','title'=>'Session Security','color'=>'#f39c12','text'=>'On login, session_regenerate_id(true) is called to prevent session fixation attacks. Sessions are destroyed completely on logout. All protected pages call requireLogin() to redirect unauthenticated users to login.php.'],
                            ['icon'=>'bi-journal-check','title'=>'Audit Logging','color'=>'#fd79a8','text'=>'Every significant action (login, logout, add/edit/delete employee, process payroll, run ETL, manage users) is recorded in the audit_log table with the user\'s ID, username, action type, target, detail, IP address, and timestamp.'],
                            ['icon'=>'bi-shield-exclamation','title'=>'SQL Injection Prevention','color'=>'#a29bfe','text'=>'All database queries use PDO prepared statements with parameterized queries. No user input is ever directly concatenated into SQL strings. htmlspecialchars() is applied to all output to prevent XSS.'],
                            ['icon'=>'bi-incognito','title'=>'Unauthorized Access Protection','color'=>'#e94560','text'=>'Every page begins with requireLogin(). Admin-only pages additionally call requireAdmin(). Attempting to access these pages without the correct session redirects to login.php or access_denied.php respectively.'],
                        ];
                        foreach ($notes as $n): ?>
                        <div class="col-md-6">
                            <div style="border:1px solid var(--border);border-radius:10px;padding:18px;">
                                <div style="color:<?= $n['color'] ?>;font-size:1.3rem;margin-bottom:8px;"><i class="bi <?= $n['icon'] ?>"></i></div>
                                <div style="font-family:'Syne',sans-serif;font-weight:600;margin-bottom:6px;"><?= $n['title'] ?></div>
                                <div style="font-size:0.82rem;color:var(--text-muted);line-height:1.6;"><?= $n['text'] ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="background:var(--card-bg);border:1px solid var(--border);color:var(--text-main);">
            <div class="modal-header" style="border-color:var(--border);">
                <h5 class="modal-title" style="font-family:'Syne',sans-serif;">Add New User</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="full_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password (min. 6 characters)</label>
                        <input type="password" name="password" class="form-control" required minlength="6">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select">
                            <option value="Employee">Employee</option>
                            <option value="Manager">Manager</option>
                            <option value="Admin">Admin</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer" style="border-color:var(--border);">
                    <button type="button" class="btn-outline-accent" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-accent">Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="background:var(--card-bg);border:1px solid var(--border);color:var(--text-main);">
            <div class="modal-header" style="border-color:var(--border);">
                <h5 class="modal-title" style="font-family:'Syne',sans-serif;">Edit User</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="user_id" id="eu_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="full_name" id="eu_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <select name="role" id="eu_role" class="form-select">
                            <option value="Employee">Employee</option>
                            <option value="Manager">Manager</option>
                            <option value="Admin">Admin</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" id="eu_status" class="form-select">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer" style="border-color:var(--border);">
                    <button type="button" class="btn-outline-accent" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-accent">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPwModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="background:var(--card-bg);border:1px solid var(--border);color:var(--text-main);">
            <div class="modal-header" style="border-color:var(--border);">
                <h5 class="modal-title" style="font-family:'Syne',sans-serif;">Reset Password</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="rp_id">
                <div class="modal-body">
                    <p style="color:var(--text-muted);font-size:0.875rem;">Resetting password for: <strong id="rp_username" style="color:var(--text-main);"></strong></p>
                    <div class="mb-3">
                        <label class="form-label">New Password (min. 6 characters)</label>
                        <input type="password" name="new_password" class="form-control" required minlength="6">
                    </div>
                </div>
                <div class="modal-footer" style="border-color:var(--border);">
                    <button type="button" class="btn-outline-accent" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-accent" style="background:var(--warning);">Reset Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openEditUser(u) {
    document.getElementById('eu_id').value     = u.user_id;
    document.getElementById('eu_name').value   = u.full_name;
    document.getElementById('eu_role').value   = u.role;
    document.getElementById('eu_status').value = u.status;
    new bootstrap.Modal(document.getElementById('editUserModal')).show();
}
function openResetPw(id, uname) {
    document.getElementById('rp_id').value       = id;
    document.getElementById('rp_username').textContent = uname;
    new bootstrap.Modal(document.getElementById('resetPwModal')).show();
}
</script>

<?php require_once 'includes/footer.php'; ?>
