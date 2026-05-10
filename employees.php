<?php
// ============================================================
// Employee Management – employees.php
// Admin:    View + Add + Edit + Delete
// Manager:  View + Add + Edit (no delete)
// Employee: View own profile + Edit own profile only
// ============================================================
require_once 'includes/db.php';
require_once 'includes/auth.php';
requireLogin();
$page_title = 'Employees';
$db  = getDB();
$cu  = currentUser();
$msg = '';
$msg_type = '';

// ── Handle POST actions ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ADD — Admin and Manager only
    if ($action === 'add') {
        if (!in_array($cu['role'], ['Admin','Manager'])) {
            $msg = 'Access denied.'; $msg_type = 'danger';
        } else {
            $stmt = $db->prepare("
                INSERT INTO employees (first_name, last_name, email, phone, department_id, position_id, hire_date)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            try {
                $stmt->execute([trim($_POST['first_name']),trim($_POST['last_name']),trim($_POST['email']),trim($_POST['phone']),(int)$_POST['department_id'],(int)$_POST['position_id'],$_POST['hire_date']]);
                auditLog('Add Employee','employees',"Added: ".trim($_POST['first_name']).' '.trim($_POST['last_name']));
                $msg = 'Employee added successfully.'; $msg_type = 'success';
            } catch (PDOException $e) { $msg = 'Error: '.$e->getMessage(); $msg_type = 'danger'; }
        }
    }

    // EDIT — Admin and Manager can edit any; Employee can only edit own profile
    if ($action === 'edit') {
        $emp_id = (int)$_POST['employee_id'];
        $isOwnProfile = false;

        // Check if Employee is editing own profile
        // Match by email (since employee table != users table, link by email)
        if (isEmployee()) {
            $own = $db->prepare("SELECT employee_id FROM employees WHERE email = ?");
            $own->execute([$cu['username']]); // username stored as email for employees
            $ownRow = $own->fetch();
            // Also try matching by full_name as fallback
            if (!$ownRow) {
                $own2 = $db->prepare("SELECT employee_id FROM employees WHERE CONCAT(first_name,' ',last_name) = ?");
                $own2->execute([$cu['full_name']]);
                $ownRow = $own2->fetch();
            }
            $isOwnProfile = $ownRow && ((int)$ownRow['employee_id'] === $emp_id);
            if (!$isOwnProfile) {
                $msg = 'Employees can only edit their own profile.'; $msg_type = 'danger';
            }
        }

        if (!isEmployee() || $isOwnProfile) {
            // Admins/Managers: can change everything; Employees: limited fields
            if (isEmployee()) {
                // Employee: only phone allowed to change
                $stmt = $db->prepare("UPDATE employees SET phone=?, version=version+1 WHERE employee_id=? AND version=?");
                try {
                    $stmt->execute([trim($_POST['phone']), $emp_id, (int)$_POST['version']]);
                    auditLog('Edit Own Profile','employees',"Employee updated own phone, employee_id=$emp_id");
                    $msg = 'Your profile updated successfully.'; $msg_type = 'success';
                } catch (PDOException $e) { $msg = 'Error: '.$e->getMessage(); $msg_type = 'danger'; }
            } else {
                $stmt = $db->prepare("
                    UPDATE employees
                    SET first_name=?, last_name=?, email=?, phone=?,
                        department_id=?, position_id=?, hire_date=?,
                        status=?, version=version+1
                    WHERE employee_id=? AND version=?
                ");
                try {
                    $rows = $stmt->execute([trim($_POST['first_name']),trim($_POST['last_name']),trim($_POST['email']),trim($_POST['phone']),(int)$_POST['department_id'],(int)$_POST['position_id'],$_POST['hire_date'],$_POST['status'],$emp_id,(int)$_POST['version']]);
                    if ($stmt->rowCount() === 0) { $msg = 'Concurrency conflict: record modified by another user. Reload and try again.'; $msg_type = 'danger'; }
                    else { auditLog('Edit Employee','employees',"Updated employee_id=$emp_id"); $msg = 'Employee updated.'; $msg_type = 'success'; }
                } catch (PDOException $e) { $msg = 'Error: '.$e->getMessage(); $msg_type = 'danger'; }
            }
        }
    }

    // DELETE — Admin only
    if ($action === 'delete') {
        if (!isAdmin()) { $msg = 'Access denied: only Admins can delete employees.'; $msg_type = 'danger'; }
        else {
            $emp_id = (int)$_POST['employee_id'];
            $count  = $db->prepare("SELECT COUNT(*) FROM payroll_records WHERE employee_id=?");
            $count->execute([$emp_id]);
            if ($count->fetchColumn() > 0) { $msg = 'Cannot delete: employee has existing payroll records.'; $msg_type = 'danger'; }
            else {
                $del = $db->prepare("DELETE FROM employees WHERE employee_id=?");
                $del->execute([$emp_id]);
                auditLog('Delete Employee','employees',"Deleted employee_id=$emp_id");
                $msg = 'Employee deleted.'; $msg_type = 'success';
            }
        }
    }
}

// ── Fetch data ───────────────────────────────────────────────
$departments = $db->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();
$positions   = $db->query("SELECT * FROM positions ORDER BY position_title")->fetchAll();
$dept_filter = isset($_GET['dept']) ? (int)$_GET['dept'] : 0;
$search      = isset($_GET['q'])    ? trim($_GET['q'])    : '';

$where  = ['1=1'];
$params = [];

// Employees only see their own record
if (isEmployee()) {
    $where[]  = "(CONCAT(e.first_name,' ',e.last_name) = ? OR e.email = ?)";
    $params[] = $cu['full_name'];
    $params[] = $cu['username'];
} else {
    if ($dept_filter) { $where[] = 'e.department_id = ?'; $params[] = $dept_filter; }
    if ($search)      { $where[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.email LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
}

$sql = "SELECT e.*, d.department_name, p.position_title FROM employees e
        JOIN departments d ON e.department_id=d.department_id
        JOIN positions p   ON e.position_id=p.position_id
        WHERE ".implode(' AND ',$where)." ORDER BY e.last_name, e.first_name";
$stmt = $db->prepare($sql); $stmt->execute($params);
$employees = $stmt->fetchAll();

require_once 'includes/header.php';
?>
<div class="main-content">
    <div class="page-header d-flex justify-content-between align-items-start">
        <div>
            <h1><i class="bi bi-people me-2 text-accent"></i>Employees</h1>
            <p>
                <?php if (isAdmin()): ?>Full access — view, add, edit, delete
                <?php elseif (isManager()): ?>Manager access — view, add, edit (no delete)
                <?php else: ?>Your profile — view and update your phone number
                <?php endif; ?>
            </p>
        </div>
        <?php if (!isEmployee()): ?>
        <button class="btn-accent" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="bi bi-plus-lg me-1"></i> Add Employee
        </button>
        <?php endif; ?>
    </div>

    <?php if ($msg): ?>
    <div class="alert-<?= $msg_type === 'success' ? 'success' : 'danger' ?>-dark mb-3">
        <i class="bi bi-<?= $msg_type === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
        <?= htmlspecialchars($msg) ?>
    </div>
    <?php endif; ?>

    <?php if (!isEmployee()): ?>
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-2">
                <div class="col-sm-6 col-md-4"><input type="text" name="q" class="form-control" placeholder="Search name or email…" value="<?= htmlspecialchars($search) ?>"></div>
                <div class="col-sm-4 col-md-3">
                    <select name="dept" class="form-select">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['department_id'] ?>" <?= $dept_filter==$d['department_id']?'selected':'' ?>><?= htmlspecialchars($d['department_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto"><button type="submit" class="btn-accent">Filter</button><a href="employees.php" class="btn-outline-accent ms-2">Reset</a></div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><?= count($employees) ?> employee<?= count($employees)!==1?'s':'' ?> found</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>Name</th><th>Email</th><th>Department</th><th>Position</th><th>Hired</th><th>Status</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employees as $emp): ?>
                        <?php
                            // Determine if this is the current user's own employee record
                            $isOwn = isEmployee() && (strtolower($emp['email']) === strtolower($cu['username']) || ($emp['first_name'].' '.$emp['last_name']) === $cu['full_name']);
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($emp['first_name'].' '.$emp['last_name']) ?></strong>
                                <?php if ($isOwn): ?><span style="font-size:0.7rem;color:var(--accent);margin-left:4px;">(You)</span><?php endif; ?>
                            </td>
                            <td style="color:var(--text-muted)"><?= htmlspecialchars($emp['email']) ?></td>
                            <td><?= htmlspecialchars($emp['department_name']) ?></td>
                            <td><?= htmlspecialchars($emp['position_title']) ?></td>
                            <td><?= date('M d, Y',strtotime($emp['hire_date'])) ?></td>
                            <td><span class="badge-<?= strtolower($emp['status']) ?>"><?= $emp['status'] ?></span></td>
                            <td>
                                <?php if (isAdmin() || isManager()): ?>
                                    <button class="btn-outline-accent btn-sm" onclick="openEditModal(<?= htmlspecialchars(json_encode($emp)) ?>)"><i class="bi bi-pencil"></i></button>
                                    <?php if (isAdmin()): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this employee?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="employee_id" value="<?= $emp['employee_id'] ?>">
                                        <button type="submit" class="btn-outline-accent btn-sm ms-1" style="border-color:var(--danger);color:var(--danger);"><i class="bi bi-trash"></i></button>
                                    </form>
                                    <?php endif; ?>
                                <?php elseif ($isOwn): ?>
                                    <button class="btn-outline-accent btn-sm" onclick="openEditOwnProfile(<?= htmlspecialchars(json_encode($emp)) ?>)"><i class="bi bi-pencil"></i> Edit Profile</button>
                                <?php else: ?>
                                    <span style="font-size:0.75rem;color:var(--text-muted);">View only</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($employees)): ?>
                        <tr><td colspan="7" class="text-center py-4" style="color:var(--text-muted)">No employees found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Modal (Admin + Manager) -->
<?php if (!isEmployee()): ?>
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="background:var(--card-bg);border:1px solid var(--border);color:var(--text-main);">
            <div class="modal-header" style="border-color:var(--border);"><h5 class="modal-title" style="font-family:'Syne',sans-serif;">Add New Employee</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <form method="POST"><input type="hidden" name="action" value="add">
                <div class="modal-body"><div class="row g-3">
                    <div class="col-sm-6"><label class="form-label">First Name</label><input type="text" name="first_name" class="form-control" required></div>
                    <div class="col-sm-6"><label class="form-label">Last Name</label><input type="text" name="last_name" class="form-control" required></div>
                    <div class="col-sm-6"><label class="form-label">Email</label><input type="email" name="email" class="form-control" required></div>
                    <div class="col-sm-6"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control"></div>
                    <div class="col-sm-6"><label class="form-label">Department</label><select name="department_id" class="form-select" required><option value="">Select…</option><?php foreach($departments as $d): ?><option value="<?= $d['department_id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-sm-6"><label class="form-label">Position</label><select name="position_id" class="form-select" required><option value="">Select…</option><?php foreach($positions as $p): ?><option value="<?= $p['position_id'] ?>"><?= htmlspecialchars($p['position_title']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-sm-6"><label class="form-label">Hire Date</label><input type="date" name="hire_date" class="form-control" required></div>
                </div></div>
                <div class="modal-footer" style="border-color:var(--border);"><button type="button" class="btn-outline-accent" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn-accent">Save Employee</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Full Edit Modal (Admin + Manager) -->
<?php if (!isEmployee()): ?>
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="background:var(--card-bg);border:1px solid var(--border);color:var(--text-main);">
            <div class="modal-header" style="border-color:var(--border);"><h5 class="modal-title" style="font-family:'Syne',sans-serif;">Edit Employee</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="employee_id" id="edit_employee_id"><input type="hidden" name="version" id="edit_version">
                <div class="modal-body"><div class="row g-3">
                    <div class="col-sm-6"><label class="form-label">First Name</label><input type="text" name="first_name" id="edit_first_name" class="form-control" required></div>
                    <div class="col-sm-6"><label class="form-label">Last Name</label><input type="text" name="last_name" id="edit_last_name" class="form-control" required></div>
                    <div class="col-sm-6"><label class="form-label">Email</label><input type="email" name="email" id="edit_email" class="form-control" required></div>
                    <div class="col-sm-6"><label class="form-label">Phone</label><input type="text" name="phone" id="edit_phone" class="form-control"></div>
                    <div class="col-sm-6"><label class="form-label">Department</label><select name="department_id" id="edit_department_id" class="form-select" required><?php foreach($departments as $d): ?><option value="<?= $d['department_id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-sm-6"><label class="form-label">Position</label><select name="position_id" id="edit_position_id" class="form-select" required><?php foreach($positions as $p): ?><option value="<?= $p['position_id'] ?>"><?= htmlspecialchars($p['position_title']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-sm-6"><label class="form-label">Hire Date</label><input type="date" name="hire_date" id="edit_hire_date" class="form-control" required></div>
                    <div class="col-sm-6"><label class="form-label">Status</label><select name="status" id="edit_status" class="form-select"><option value="Active">Active</option><option value="Inactive">Inactive</option></select></div>
                </div></div>
                <div class="modal-footer" style="border-color:var(--border);"><button type="button" class="btn-outline-accent" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn-accent">Update Employee</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Own Profile Edit Modal (Employee only — phone only) -->
<?php if (isEmployee()): ?>
<div class="modal fade" id="ownProfileModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="background:var(--card-bg);border:1px solid var(--border);color:var(--text-main);">
            <div class="modal-header" style="border-color:var(--border);"><h5 class="modal-title" style="font-family:'Syne',sans-serif;">✏️ Edit Your Profile</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="employee_id" id="op_employee_id"><input type="hidden" name="version" id="op_version">
                <div class="modal-body">
                    <div class="alert-danger-dark mb-3" style="font-size:0.82rem;"><i class="bi bi-info-circle me-1"></i> As an Employee, you can only update your phone number. Contact your Manager or Admin for other changes.</div>
                    <label class="form-label">Phone Number</label>
                    <input type="text" name="phone" id="op_phone" class="form-control">
                </div>
                <div class="modal-footer" style="border-color:var(--border);"><button type="button" class="btn-outline-accent" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn-accent">Update Phone</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function openEditModal(emp) {
    document.getElementById('edit_employee_id').value  = emp.employee_id;
    document.getElementById('edit_version').value      = emp.version;
    document.getElementById('edit_first_name').value   = emp.first_name;
    document.getElementById('edit_last_name').value    = emp.last_name;
    document.getElementById('edit_email').value        = emp.email;
    document.getElementById('edit_phone').value        = emp.phone || '';
    document.getElementById('edit_department_id').value= emp.department_id;
    document.getElementById('edit_position_id').value  = emp.position_id;
    document.getElementById('edit_hire_date').value    = emp.hire_date;
    document.getElementById('edit_status').value       = emp.status;
    new bootstrap.Modal(document.getElementById('editModal')).show();
}
function openEditOwnProfile(emp) {
    document.getElementById('op_employee_id').value = emp.employee_id;
    document.getElementById('op_version').value     = emp.version;
    document.getElementById('op_phone').value       = emp.phone || '';
    new bootstrap.Modal(document.getElementById('ownProfileModal')).show();
}
</script>
<?php require_once 'includes/footer.php'; ?>
