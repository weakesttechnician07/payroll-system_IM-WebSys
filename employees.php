<?php
// ============================================================
// Employee Management – employees.php
// NEW: AJAX live search, CSV bulk import, pagination (20/page)
// Admin: full CRUD | Manager: view+add+edit | Employee: own profile
// ============================================================
require_once 'includes/db.php';
require_once 'includes/auth.php';
requireLogin();
$page_title = 'Employees';
$db  = getDB();
$cu  = currentUser();
$msg = '';
$msg_type = '';

// ── AJAX: live search endpoint ───────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');
    $q    = trim($_GET['q']    ?? '');
    $dept = (int)($_GET['dept'] ?? 0);
    $where  = ['1=1'];
    $params = [];
    if ($q)    { $where[]='(e.first_name LIKE ? OR e.last_name LIKE ? OR e.email LIKE ?)'; $params[]="%$q%"; $params[]="%$q%"; $params[]="%$q%"; }
    if ($dept) { $where[]='e.department_id=?'; $params[]=$dept; }
    $sql = "SELECT e.employee_id,e.first_name,e.last_name,e.email,e.phone,e.hire_date,e.status,e.version,d.department_name,d.department_id,p.position_title,p.position_id FROM employees e JOIN departments d ON e.department_id=d.department_id JOIN positions p ON e.position_id=p.position_id WHERE ".implode(' AND ',$where)." ORDER BY e.last_name,e.first_name LIMIT 20";
    $st = $db->prepare($sql); $st->execute($params);
    echo json_encode(['data'=>$st->fetchAll()]);
    exit;
}

// ── POST: Add employee ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' && !isEmployee()) {
        $fn    = trim($_POST['first_name']);
        $ln    = trim($_POST['last_name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $deptId= (int)$_POST['department_id'];
        $posId = (int)$_POST['position_id'];
        $hired = $_POST['hire_date'];

        // Auto-generate username: firstname.lastname@company.com (lowercase, no spaces)
        $username = strtolower(preg_replace('/\s+/','',$fn).'.'.preg_replace('/\s+/','',$ln)).'@company.com';
        $fullName = $fn.' '.$ln;
        // Default password: "password" — bcrypt hashed
        $passHash = password_hash('password', PASSWORD_BCRYPT);

        $db->beginTransaction();
        try {
            // 1. Insert employee record
            $empStmt = $db->prepare("INSERT INTO employees (first_name,last_name,email,phone,department_id,position_id,hire_date) VALUES (?,?,?,?,?,?,?)");
            $empStmt->execute([$fn,$ln,$email,$phone,$deptId,$posId,$hired]);

            // 2. Create user account (username = auto-generated, linked by email)
            // Check if username already exists — append number if so
            $baseUser = $username;
            $counter  = 1;
            while (true) {
                $chk = $db->prepare("SELECT COUNT(*) FROM users WHERE username=?");
                $chk->execute([$username]);
                if ($chk->fetchColumn() == 0) break;
                // Username taken — try firstname.lastname2@company.com etc.
                $username = str_replace('@company.com', $counter.'@company.com', $baseUser);
                $counter++;
            }

            $userStmt = $db->prepare("INSERT INTO users (username,password,full_name,role) VALUES (?,?,?,'Employee')");
            $userStmt->execute([$username, $passHash, $fullName]);

            $db->commit();
            auditLog('Add Employee','employees',"Added: $fullName | Account: $username");
            $msg = "Employee <strong>$fullName</strong> added successfully!<br>"
                 . "<i class='bi bi-person-check me-1'></i>User account created — "
                 . "Username: <code>$username</code> &nbsp;|&nbsp; Password: <code>password</code>";
            $msg_type = 'success';
        } catch (PDOException $e) {
            $db->rollBack();
            $msg='Error: '.$e->getMessage(); $msg_type='danger';
        }
    }

    if ($action === 'edit') {
        $emp_id = (int)$_POST['employee_id'];
        if (isEmployee()) {
            // Employee: own phone only
            $own = $db->prepare("SELECT employee_id FROM employees WHERE email=? LIMIT 1");
            $own->execute([$cu['username']]); $ownId = $own->fetchColumn();
            if ((int)$ownId !== $emp_id) { $msg='You can only edit your own profile.'; $msg_type='danger'; }
            else {
                $db->prepare("UPDATE employees SET phone=?,version=version+1 WHERE employee_id=? AND version=?")->execute([trim($_POST['phone']),$emp_id,(int)$_POST['version']]);
                auditLog('Edit Own Profile','employees',"Phone updated emp_id=$emp_id");
                $msg='Profile updated.'; $msg_type='success';
            }
        } else {
            $stmt = $db->prepare("UPDATE employees SET first_name=?,last_name=?,email=?,phone=?,department_id=?,position_id=?,hire_date=?,status=?,version=version+1 WHERE employee_id=? AND version=?");
            try {
                $stmt->execute([trim($_POST['first_name']),trim($_POST['last_name']),trim($_POST['email']),trim($_POST['phone']),(int)$_POST['department_id'],(int)$_POST['position_id'],$_POST['hire_date'],$_POST['status'],$emp_id,(int)$_POST['version']]);
                if ($stmt->rowCount()===0) { $msg='Concurrency conflict — reload and try again.'; $msg_type='danger'; }
                else { auditLog('Edit Employee','employees',"Updated emp_id=$emp_id"); $msg='Employee updated.'; $msg_type='success'; }
            } catch (PDOException $e) { $msg='Error: '.$e->getMessage(); $msg_type='danger'; }
        }
    }

    // if ($action === 'delete' && isAdmin()) {
    //     $emp_id = (int)$_POST['employee_id'];
    //     $cnt = $db->prepare("SELECT COUNT(*) FROM payroll_records WHERE employee_id=?"); $cnt->execute([$emp_id]);
    //     if ($cnt->fetchColumn() > 0) { $msg='Cannot delete: employee has payroll records.'; $msg_type='danger'; }
    //     else {
    //         $db->prepare("DELETE FROM employees WHERE employee_id=?")->execute([$emp_id]);
    //         auditLog('Delete Employee','employees',"Deleted emp_id=$emp_id");
    //         $msg='Employee deleted.'; $msg_type='success';
    //     }
    // }

    if ($action === 'delete' && isAdmin()) {

    $emp_id = (int) $_POST['employee_id'];

    $cnt = $db->prepare("
        SELECT COUNT(*)
        FROM payroll_records
        WHERE employee_id = ?
    ");
    $cnt->execute([$emp_id]);

    if ($cnt->fetchColumn() > 0) {

        $msg = 'Cannot delete: employee has payroll records.';
        $msg_type = 'danger';

    } else {

        // Fetch employee email to find the linked user account
        $empRow = $db->prepare("
            SELECT first_name, last_name, email
            FROM employees
            WHERE employee_id = ?
        ");
        $empRow->execute([$emp_id]);

        $empData = $empRow->fetch();

        $db->beginTransaction();

        try {

            // Delete the auto-created user account linked by email/username
            if ($empData) {

                // Auto-generated username format: firstname.lastname@company.com
                $autoUser =
                    strtolower(
                        preg_replace('/\s+/', '', $empData['first_name']) .
                        '.' .
                        preg_replace('/\s+/', '', $empData['last_name'])
                    ) . '@company.com';

                // Delete matching user
                // (only Employee role — never delete Admin/Manager accounts)
                $db->prepare("
                    DELETE FROM users
                    WHERE username LIKE ?
                    AND role = 'Employee'
                ")->execute([$autoUser . '%']);
            }

            // Delete employee record
            $db->prepare("
                DELETE FROM employees
                WHERE employee_id = ?
            ")->execute([$emp_id]);

            $db->commit();

            $fullName = $empData
                ? $empData['first_name'] . ' ' . $empData['last_name']
                : "emp_id=$emp_id";

            auditLog(
                'Delete Employee',
                'employees',
                "Deleted: $fullName (emp_id=$emp_id) + linked user account"
            );

            $msg = 'Employee and linked user account deleted.';
            $msg_type = 'success';

        } catch (PDOException $e) {

            $db->rollBack();

            $msg = 'Error deleting employee: ' . $e->getMessage();
            $msg_type = 'danger';
        }
    }
}

    // ── CSV Import ───────────────────────────────────────────
    if ($action === 'import_csv' && !isEmployee()) {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $msg='No file uploaded or upload error.'; $msg_type='danger';
        } else {
            $file = $_FILES['csv_file']['tmp_name'];
            $handle = fopen($file, 'r');
            // Skip header row
            $header = fgetcsv($handle);
            $inserted = 0; $skipped = 0; $errors = [];
            $insertStmt = $db->prepare("INSERT INTO employees (first_name,last_name,email,phone,department_id,position_id,hire_date) VALUES (?,?,?,?,?,?,?)");
            $deptCache = []; $posCache = [];

            $db->beginTransaction();
            try {
                $row_num = 1;
                while (($row = fgetcsv($handle)) !== false) {
                    $row_num++;
                    if (count($row) < 6) { $errors[]="Row $row_num: not enough columns (need 6 minimum)"; $skipped++; continue; }
                    [$fn,$ln,$email,$phone,$deptName,$posTitle] = array_map('trim', $row);
                    $hireDate = isset($row[6]) ? trim($row[6]) : date('Y-m-d');

                    // Lookup department
                    if (!isset($deptCache[$deptName])) {
                        $ds = $db->prepare("SELECT department_id FROM departments WHERE department_name=? LIMIT 1");
                        $ds->execute([$deptName]); $deptCache[$deptName] = $ds->fetchColumn();
                    }
                    if (!$deptCache[$deptName]) { $errors[]="Row $row_num: department '$deptName' not found"; $skipped++; continue; }

                    // Lookup position
                    if (!isset($posCache[$posTitle])) {
                        $ps = $db->prepare("SELECT position_id FROM positions WHERE position_title=? LIMIT 1");
                        $ps->execute([$posTitle]); $posCache[$posTitle] = $ps->fetchColumn();
                    }
                    if (!$posCache[$posTitle]) { $errors[]="Row $row_num: position '$posTitle' not found"; $skipped++; continue; }

                    // Check duplicate email
                    $ec = $db->prepare("SELECT COUNT(*) FROM employees WHERE email=?"); $ec->execute([$email]);
                    if ($ec->fetchColumn() > 0) { $errors[]="Row $row_num: email '$email' already exists"; $skipped++; continue; }

                    $insertStmt->execute([$fn,$ln,$email,$phone,$deptCache[$deptName],$posCache[$posTitle],$hireDate]);

                    // Auto-create user account for imported employee
                    $csvUsername = strtolower(preg_replace('/\s+/','',$fn).'.'.preg_replace('/\s+/','',$ln)).'@company.com';
                    $csvBase = $csvUsername; $csvCounter = 1;
                    while (true) {
                        $cu2 = $db->prepare("SELECT COUNT(*) FROM users WHERE username=?");
                        $cu2->execute([$csvUsername]);
                        if ($cu2->fetchColumn() == 0) break;
                        $csvUsername = str_replace('@company.com', $csvCounter.'@company.com', $csvBase);
                        $csvCounter++;
                    }
                    $csvPass = password_hash('password', PASSWORD_BCRYPT);
                    $db->prepare("INSERT IGNORE INTO users (username,password,full_name,role) VALUES (?,?,?,'Employee')")
                       ->execute([$csvUsername, $csvPass, "$fn $ln"]);

                    $inserted++;
                }
                $db->commit();
                auditLog('CSV Import','employees',"Imported=$inserted Skipped=$skipped");
                $msg = "CSV Import complete: <strong>$inserted</strong> added, <strong>$skipped</strong> skipped.".(count($errors)?' Issues: '.implode('; ',$errors):'');
                $msg_type = $inserted > 0 ? 'success' : 'danger';
            } catch (PDOException $e) {
                $db->rollBack();
                $msg='Import failed (rolled back): '.$e->getMessage(); $msg_type='danger';
            }
            fclose($handle);
        }
    }
}

// ── Pagination setup ─────────────────────────────────────────
$per_page    = 20;
$page_num    = max(1, (int)($_GET['page'] ?? 1));
$offset      = ($page_num - 1) * $per_page;
$dept_filter = (int)($_GET['dept'] ?? 0);
$search      = trim($_GET['q'] ?? '');

$departments = $db->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();
$positions   = $db->query("SELECT * FROM positions ORDER BY position_title")->fetchAll();

if (isEmployee()) {
    // Employee: only their own record
    $total = 1;
    $sql   = "SELECT e.*,d.department_name,p.position_title FROM employees e JOIN departments d ON e.department_id=d.department_id JOIN positions p ON e.position_id=p.position_id WHERE e.email=? LIMIT 1";
    $stmt  = $db->prepare($sql); $stmt->execute([$cu['username']]);
} else {
    $where  = ['1=1']; $params = [];
    if ($dept_filter) { $where[]='e.department_id=?'; $params[]=$dept_filter; }
    if ($search)      { $where[]='(e.first_name LIKE ? OR e.last_name LIKE ? OR e.email LIKE ?)'; $params[]="%$search%"; $params[]="%$search%"; $params[]="%$search%"; }
    $cntSql= "SELECT COUNT(*) FROM employees e WHERE ".implode(' AND ',$where);
    $cntSt = $db->prepare($cntSql); $cntSt->execute($params); $total = (int)$cntSt->fetchColumn();
    $sql   = "SELECT e.*,d.department_name,p.position_title FROM employees e JOIN departments d ON e.department_id=d.department_id JOIN positions p ON e.position_id=p.position_id WHERE ".implode(' AND ',$where)." ORDER BY e.last_name,e.first_name LIMIT $per_page OFFSET $offset";
    $stmt  = $db->prepare($sql); $stmt->execute($params);
}
$employees  = $stmt->fetchAll();
$total_pages= (int)ceil($total / $per_page);

require_once 'includes/header.php';
?>

<div class="main-content">
    <div class="page-header d-flex justify-content-between align-items-start">
        <div>
            <h1><i class="bi bi-people me-2 text-accent"></i>Employees</h1>
            <p><?= isAdmin()?'Full access':(isManager()?'Manager: view, add, edit':'Your profile — update phone only') ?></p>
        </div>
        <div class="d-flex gap-2">
            <?php if (!isEmployee()): ?>
            <button class="btn-outline-accent" data-bs-toggle="modal" data-bs-target="#importModal">
                <i class="bi bi-upload me-1"></i> Import CSV
            </button>
            <button class="btn-accent" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-lg me-1"></i> Add Employee
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($msg): ?>
    <div class="alert-<?= $msg_type==='success'?'success':'danger' ?>-dark mb-3">
        <i class="bi bi-<?= $msg_type==='success'?'check-circle':'exclamation-triangle' ?> me-2"></i>
        <?= $msg /* contains HTML for import count — sanitized by us */ ?>
    </div>
    <?php endif; ?>

    <!-- Search & Filter (AJAX-powered) -->
    <?php if (!isEmployee()): ?>
    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-sm-6 col-md-4">
                    <label class="form-label">Search</label>
                    <div style="position:relative;">
                        <input type="text" id="searchInput" class="form-control" placeholder="Type to search name or email…"
                               value="<?= htmlspecialchars($search) ?>">
                        <span id="searchSpinner" style="display:none;position:absolute;right:10px;top:50%;transform:translateY(-50%);color:var(--text-muted);">
                            <i class="bi bi-arrow-repeat spin"></i>
                        </span>
                    </div>
                </div>
                <div class="col-sm-4 col-md-3">
                    <label class="form-label">Department</label>
                    <select id="deptFilter" class="form-select">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['department_id'] ?>" <?= $dept_filter==$d['department_id']?'selected':'' ?>><?= htmlspecialchars($d['department_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <a href="employees.php" class="btn-outline-accent">Reset</a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Employee Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span id="resultCount"><?= $total ?> employee<?= $total!==1?'s':'' ?></span>
            <?php if (!isEmployee()): ?>
            <span style="color:var(--text-muted);font-size:0.78rem;">Page <?= $page_num ?> of <?= max(1,$total_pages) ?></span>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0" id="empTable">
                    <thead>
                        <tr><th>Name</th><th>Email</th><th>Department</th><th>Position</th><th>Hired</th><th>Status</th><th>Actions</th></tr>
                    </thead>
                    <tbody id="empTableBody">
                        <!-- <?php include_once 'includes/emp_rows.php'; // reusable row rendering ?> -->
                        <?php foreach ($employees as $emp):
                            $isOwn = isEmployee() && strtolower($emp['email'])===strtolower($cu['username']);
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($emp['first_name'].' '.$emp['last_name']) ?></strong>
                                <?php if($isOwn): ?><span style="font-size:0.7rem;color:var(--accent);margin-left:4px;">(You)</span><?php endif; ?></td>
                            <td style="color:var(--text-muted)"><?= htmlspecialchars($emp['email']) ?></td>
                            <td><?= htmlspecialchars($emp['department_name']) ?></td>
                            <td><?= htmlspecialchars($emp['position_title']) ?></td>
                            <td><?= date('M d, Y',strtotime($emp['hire_date'])) ?></td>
                            <td><span class="badge-<?= strtolower($emp['status']) ?>"><?= $emp['status'] ?></span></td>
                            <td>
                                <?php if(isAdmin()||isManager()): ?>
                                    <button class="btn-outline-accent btn-sm" onclick="openEditModal(<?= htmlspecialchars(json_encode($emp)) ?>)"><i class="bi bi-pencil"></i></button>
                                    <?php if(isAdmin()): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this employee?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="employee_id" value="<?= $emp['employee_id'] ?>">
                                        <button type="submit" class="btn-outline-accent btn-sm ms-1" style="border-color:var(--danger);color:var(--danger);"><i class="bi bi-trash"></i></button>
                                    </form>
                                    <?php endif; ?>
                                <?php elseif($isOwn): ?>
                                    <button class="btn-outline-accent btn-sm" onclick="openOwnProfile(<?= htmlspecialchars(json_encode($emp)) ?>)"><i class="bi bi-pencil"></i> Edit Profile</button>
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

        <!-- Pagination -->
        <?php if (!isEmployee() && $total_pages > 1): ?>
        <div class="card-body border-top" style="border-color:var(--border)!important;">
            <nav><ul class="pagination mb-0 justify-content-center">
                <li class="page-item <?= $page_num<=1?'disabled':'' ?>">
                    <a class="page-link" href="?page=<?= $page_num-1 ?>&q=<?= urlencode($search) ?>&dept=<?= $dept_filter ?>">‹ Prev</a>
                </li>
                <?php
                $start = max(1, $page_num-2);
                $end   = min($total_pages, $page_num+2);
                if ($start > 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif;
                for ($i=$start;$i<=$end;$i++): ?>
                <li class="page-item <?= $i===$page_num?'active':'' ?>">
                    <a class="page-link" href="?page=<?= $i ?>&q=<?= urlencode($search) ?>&dept=<?= $dept_filter ?>"><?= $i ?></a>
                </li>
                <?php endfor;
                if ($end < $total_pages): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                <li class="page-item <?= $page_num>=$total_pages?'disabled':'' ?>">
                    <a class="page-link" href="?page=<?= $page_num+1 ?>&q=<?= urlencode($search) ?>&dept=<?= $dept_filter ?>">Next ›</a>
                </li>
            </ul></nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- CSV Import Modal -->
<?php if (!isEmployee()): ?>
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="background:var(--card-bg);border:1px solid var(--border);color:var(--text-main);">
            <div class="modal-header" style="border-color:var(--border);">
                <h5 class="modal-title" style="font-family:'Syne',sans-serif;"><i class="bi bi-upload me-2 text-accent"></i>Import Employees from CSV</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_csv">
                <div class="modal-body">
                    <div style="background:rgba(233,69,96,0.08);border:1px solid rgba(233,69,96,0.2);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:0.82rem;">
                        <strong style="color:var(--accent);">Required CSV format (with header row):</strong><br>
                        <code style="color:var(--text-main);font-size:0.8rem;">first_name, last_name, email, phone, department_name, position_title, hire_date</code><br>
                        <small style="color:var(--text-muted);">
                            • Department and position names must exactly match existing records<br>
                            • hire_date format: YYYY-MM-DD (optional, defaults to today)<br>
                            • Duplicate emails are skipped with an error message
                        </small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">CSV File</label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                    </div>
                    <!-- Download template link -->
                    <a href="download_csv_template.php" style="font-size:0.82rem;color:var(--accent);">
                        <i class="bi bi-download me-1"></i>Download CSV template
                    </a>
                </div>
                <div class="modal-footer" style="border-color:var(--border);">
                    <button type="button" class="btn-outline-accent" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-accent">Import</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Modal -->
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

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="background:var(--card-bg);border:1px solid var(--border);color:var(--text-main);">
            <div class="modal-header" style="border-color:var(--border);"><h5 class="modal-title" style="font-family:'Syne',sans-serif;">Edit Employee</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="employee_id" id="edit_id"><input type="hidden" name="version" id="edit_ver">
                <div class="modal-body"><div class="row g-3">
                    <div class="col-sm-6"><label class="form-label">First Name</label><input type="text" name="first_name" id="edit_fn" class="form-control" required></div>
                    <div class="col-sm-6"><label class="form-label">Last Name</label><input type="text" name="last_name" id="edit_ln" class="form-control" required></div>
                    <div class="col-sm-6"><label class="form-label">Email</label><input type="email" name="email" id="edit_em" class="form-control" required></div>
                    <div class="col-sm-6"><label class="form-label">Phone</label><input type="text" name="phone" id="edit_ph" class="form-control"></div>
                    <div class="col-sm-6"><label class="form-label">Department</label><select name="department_id" id="edit_dept" class="form-select" required><?php foreach($departments as $d): ?><option value="<?= $d['department_id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-sm-6"><label class="form-label">Position</label><select name="position_id" id="edit_pos" class="form-select" required><?php foreach($positions as $p): ?><option value="<?= $p['position_id'] ?>"><?= htmlspecialchars($p['position_title']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-sm-6"><label class="form-label">Hire Date</label><input type="date" name="hire_date" id="edit_hd" class="form-control" required></div>
                    <div class="col-sm-6"><label class="form-label">Status</label><select name="status" id="edit_st" class="form-select"><option value="Active">Active</option><option value="Inactive">Inactive</option></select></div>
                </div></div>
                <div class="modal-footer" style="border-color:var(--border);"><button type="button" class="btn-outline-accent" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn-accent">Update Employee</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Own Profile Modal (Employee) -->
<?php if (isEmployee()): ?>
<div class="modal fade" id="ownProfileModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="background:var(--card-bg);border:1px solid var(--border);color:var(--text-main);">
            <div class="modal-header" style="border-color:var(--border);"><h5 class="modal-title" style="font-family:'Syne',sans-serif;">Edit Your Profile</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="employee_id" id="op_id"><input type="hidden" name="version" id="op_ver">
                <div class="modal-body">
                    <div class="alert-danger-dark mb-3" style="font-size:0.82rem;"><i class="bi bi-info-circle me-1"></i> You can only update your phone number. Use <a href="requests.php" style="color:var(--accent);">Requests</a> for name or email changes.</div>
                    <label class="form-label">Phone Number</label>
                    <input type="text" name="phone" id="op_ph" class="form-control">
                </div>
                <div class="modal-footer" style="border-color:var(--border);"><button type="button" class="btn-outline-accent" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn-accent">Update Phone</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
@keyframes spin { to { transform: translateY(-50%) rotate(360deg); } }
.spin { animation: spin 1s linear infinite; display:inline-block; }
</style>

<script>
// ── AJAX live search ─────────────────────────────────────────
let searchTimer;
const searchInput = document.getElementById('searchInput');
const deptFilter  = document.getElementById('deptFilter');
const spinner     = document.getElementById('searchSpinner');
const resultCount = document.getElementById('resultCount');
const tableBody   = document.getElementById('empTableBody');

function doSearch() {
    if (!searchInput) return;
    const q    = searchInput.value.trim();
    const dept = deptFilter ? deptFilter.value : '';
    spinner.style.display = 'inline';

    fetch(`employees.php?ajax=1&q=${encodeURIComponent(q)}&dept=${dept}`)
        .then(r => r.json())
        .then(data => {
            spinner.style.display = 'none';
            resultCount.textContent = data.data.length + ' employee' + (data.data.length !== 1 ? 's' : '') + ' found';
            if (data.data.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="7" class="text-center py-4" style="color:var(--text-muted)">No employees found.</td></tr>';
                return;
            }
            tableBody.innerHTML = data.data.map(emp => `
                <tr>
                    <td><strong>${escHtml(emp.first_name+' '+emp.last_name)}</strong></td>
                    <td style="color:var(--text-muted)">${escHtml(emp.email)}</td>
                    <td>${escHtml(emp.department_name)}</td>
                    <td>${escHtml(emp.position_title)}</td>
                    <td>${emp.hire_date}</td>
                    <td><span class="badge-${emp.status.toLowerCase()}">${emp.status}</span></td>
                    <td>
                        <button class="btn-outline-accent btn-sm" onclick='openEditModal(${JSON.stringify(emp)})'>
                            <i class="bi bi-pencil"></i>
                        </button>
                        <?php if(isAdmin()): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this employee?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="employee_id" value="${emp.employee_id}">
                            <button type="submit" class="btn-outline-accent btn-sm ms-1" style="border-color:var(--danger);color:var(--danger);">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            `).join('');
        })
        .catch(() => { spinner.style.display = 'none'; });
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

if (searchInput) {
    searchInput.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(doSearch, 350); });
}
if (deptFilter) {
    deptFilter.addEventListener('change', doSearch);
}

// ── Modal helpers ────────────────────────────────────────────
function openEditModal(emp) {
    document.getElementById('edit_id').value   = emp.employee_id;
    document.getElementById('edit_ver').value  = emp.version;
    document.getElementById('edit_fn').value   = emp.first_name;
    document.getElementById('edit_ln').value   = emp.last_name;
    document.getElementById('edit_em').value   = emp.email;
    document.getElementById('edit_ph').value   = emp.phone || '';
    document.getElementById('edit_dept').value = emp.department_id;
    document.getElementById('edit_pos').value  = emp.position_id;
    document.getElementById('edit_hd').value   = emp.hire_date;
    document.getElementById('edit_st').value   = emp.status;
    new bootstrap.Modal(document.getElementById('editModal')).show();
}
function openOwnProfile(emp) {
    document.getElementById('op_id').value  = emp.employee_id;
    document.getElementById('op_ver').value = emp.version;
    document.getElementById('op_ph').value  = emp.phone || '';
    new bootstrap.Modal(document.getElementById('ownProfileModal')).show();
}
</script>

<?php require_once 'includes/footer.php'; ?>
