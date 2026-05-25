<?php
// ============================================================
// Requests – requests.php
// Employee:  submit requests (leave, absence excuse, name/email change)
// Manager:   approve/reject leave + absence requests
// Admin:     approve/reject name/email change requests + see all
// ============================================================
require_once 'includes/db.php';
require_once 'includes/auth.php';
requireLogin();
$page_title = 'Requests';
$db  = getDB();
$cu  = currentUser();
$msg = '';
$msg_type = '';

// ── Who handles what ─────────────────────────────────────────
// Manager → Leave - Sick, Leave - Vacation, Absence Excuse
// Admin   → Name Change, Email Change, Other
function handledBy(string $type): string {
    return in_array($type, ['Name Change','Email Change','Other']) ? 'Admin' : 'Manager';
}

// ── Get linked employee record (for Employee role) ───────────
$myEmp = null;
if (isEmployee()) {
    $es = $db->prepare("SELECT * FROM employees WHERE email=? LIMIT 1");
    $es->execute([$cu['username']]);
    $myEmp = $es->fetch();
}

// ── POST: Submit request (Employee) ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'submit' && isEmployee()) {
        if (!$myEmp) { $msg='No employee record linked to your account.'; $msg_type='danger'; }
        else {
            $type    = $_POST['request_type'] ?? '';
            $details = trim($_POST['details'] ?? '');
            $valid_types = ['Leave - Sick','Leave - Vacation','Absence Excuse','Name Change','Email Change','Other'];

            if (!in_array($type, $valid_types)) { $msg='Invalid request type.'; $msg_type='danger'; }
            elseif (strlen($details) < 10) { $msg='Please provide more detail (at least 10 characters).'; $msg_type='danger'; }
            else {
                $stmt = $db->prepare("
                    INSERT INTO requests (employee_id, request_type, details, handled_by)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$myEmp['employee_id'], $type, $details, handledBy($type)]);
                auditLog('Submit Request','requests',"Type=$type emp_id=".$myEmp['employee_id']);
                $msg = 'Request submitted successfully! Your '.handledBy($type).' will review it.';
                $msg_type = 'success';
            }
        }
    }

    // ── POST: Approve/Reject (Manager or Admin) ──────────────
    if (in_array($action, ['approve','reject']) && !isEmployee()) {
        $req_id     = (int)$_POST['request_id'];
        $note       = trim($_POST['review_note'] ?? '');
        $new_status = $action === 'approve' ? 'Approved' : 'Rejected';

        // Fetch request
        $fetchStmt = $db->prepare("SELECT * FROM requests WHERE request_id=?");
        $fetchStmt->execute([$req_id]);
        $req = $fetchStmt->fetch();

        if (!$req) { $msg='Request not found.'; $msg_type='danger'; }
        elseif ($req['status'] !== 'Pending') { $msg='This request has already been reviewed.'; $msg_type='danger'; }
        elseif (isManager() && $req['handled_by'] !== 'Manager') { $msg='This request requires Admin approval.'; $msg_type='danger'; }
        else {
            $db->beginTransaction();
            try {
                // Update request status
                $upd = $db->prepare("
                    UPDATE requests SET
                        status=?, reviewed_by=?, reviewer_name=?,
                        review_note=?, reviewed_at=NOW()
                    WHERE request_id=?
                ");
                $upd->execute([$new_status, $cu['user_id'], $cu['full_name'], $note, $req_id]);

                // If approved name change → update employee record
                if ($new_status === 'Approved' && $req['request_type'] === 'Name Change') {
                    // Parse "First: Juan Last: Dela Cruz" style or just log for manual action
                    // Since name format is freetext, we log it for the admin to action manually
                    // (A full implementation would parse structured fields)
                }

                // If approved email change → update employee email
                if ($new_status === 'Approved' && $req['request_type'] === 'Email Change') {
                    // Similarly log — admin should verify new email uniqueness manually
                }

                // If approved leave/absence → update attendance record
                if ($new_status === 'Approved' &&
                    in_array($req['request_type'], ['Leave - Sick','Leave - Vacation','Absence Excuse'])) {
                    // Reduce days_absent by 1 as an excuse (if attendance record exists)
                    $attUpd = $db->prepare("
                        UPDATE attendance
                        SET days_absent = GREATEST(0, days_absent - 1)
                        WHERE employee_id = ?
                        AND attendance_month = MONTH(NOW())
                        AND attendance_year  = YEAR(NOW())
                    ");
                    $attUpd->execute([$req['employee_id']]);
                }

                $db->commit();
                auditLog('Review Request','requests',"req_id=$req_id status=$new_status by=".$cu['username']);
                $msg = "Request #{$req_id} has been {$new_status}.";
                $msg_type = 'success';
            } catch (PDOException $e) {
                $db->rollBack();
                $msg = 'Error: '.$e->getMessage(); $msg_type = 'danger';
            }
        }
    }
}

// ── Fetch requests based on role ─────────────────────────────
$tab    = $_GET['tab'] ?? 'pending';
$status = match($tab) { 'approved'=>'Approved', 'rejected'=>'Rejected', default=>'Pending' };

if (isEmployee() && $myEmp) {
    // Employee: own requests only
    $rStmt = $db->prepare("
        SELECT r.*, CONCAT(e.first_name,' ',e.last_name) AS employee_name,
               d.department_name
        FROM requests r
        JOIN employees e ON r.employee_id = e.employee_id
        JOIN departments d ON e.department_id = d.department_id
        WHERE r.employee_id = ? AND r.status = ?
        ORDER BY r.created_at DESC
    ");
    $rStmt->execute([$myEmp['employee_id'], $status]);
} elseif (isManager()) {
    // Manager: only requests handled_by = Manager
    $rStmt = $db->prepare("SELECT * FROM vw_requests WHERE handled_by='Manager' AND status=?");
    $rStmt->execute([$status]);
} else {
    // Admin: all requests
    $rStmt = $db->prepare("SELECT * FROM vw_requests WHERE status=?");
    $rStmt->execute([$status]);
}
$requests = $rStmt->fetchAll();

// Pending count badge for sidebar
$pendingCount = 0;
if (!isEmployee()) {
    $pc = isManager()
        ? $db->query("SELECT COUNT(*) FROM requests WHERE status='Pending' AND handled_by='Manager'")->fetchColumn()
        : $db->query("SELECT COUNT(*) FROM requests WHERE status='Pending'")->fetchColumn();
    $pendingCount = (int)$pc;
}

// My pending count (Employee)
$myPendingCount = 0;
if (isEmployee() && $myEmp) {
    $myPendingCount = (int)$db->prepare("SELECT COUNT(*) FROM requests WHERE employee_id=? AND status='Pending'")->execute([$myEmp['employee_id']]);
}

require_once 'includes/header.php';
?>

<div class="main-content">
    <div class="page-header d-flex justify-content-between align-items-start">
        <div>
            <h1><i class="bi bi-inbox me-2 text-accent"></i>
                <?= isEmployee() ? 'My Requests' : 'Request Management' ?>
            </h1>
            <p>
                <?php if (isEmployee()): ?>Submit leave, absence excuses, and profile change requests<?php
                elseif (isManager()): ?>Review and approve employee leave and absence requests<?php
                else: ?>Review all employee requests — name/email changes require Admin approval<?php endif; ?>
            </p>
        </div>
        <?php if (isEmployee() && $myEmp): ?>
        <button class="btn-accent" data-bs-toggle="modal" data-bs-target="#submitModal">
            <i class="bi bi-plus-lg me-1"></i> New Request
        </button>
        <?php endif; ?>
    </div>

    <?php if ($msg): ?>
    <div class="alert-<?= $msg_type==='success'?'success':'danger' ?>-dark mb-3">
        <i class="bi bi-<?= $msg_type==='success'?'check-circle':'exclamation-triangle' ?> me-2"></i>
        <?= htmlspecialchars($msg) ?>
    </div>
    <?php endif; ?>

    <?php if (!isEmployee() && $pendingCount > 0): ?>
    <div style="background:rgba(243,156,18,0.1);border:1px solid rgba(243,156,18,0.3);color:#f39c12;border-radius:8px;padding:10px 16px;margin-bottom:16px;font-size:0.875rem;">
        <i class="bi bi-bell me-2"></i>
        <strong><?= $pendingCount ?></strong> pending request<?= $pendingCount!==1?'s':'' ?> awaiting your review.
    </div>
    <?php endif; ?>

    <!-- Status Tabs -->
    <ul class="nav nav-pills mb-4" style="gap:8px;">
        <li><a href="?tab=pending"  class="nav-link <?= $tab==='pending' ?'active':'text-muted' ?>" style="<?= $tab==='pending'?'background:var(--accent);':'border:1px solid var(--border);' ?>border-radius:8px;font-size:0.85rem;">
            <i class="bi bi-clock me-1"></i>Pending
            <?php if (!isEmployee() && $pendingCount > 0): ?>
            <span style="background:#fff;color:var(--accent);border-radius:10px;padding:1px 7px;font-size:0.75rem;font-weight:700;margin-left:4px;"><?= $pendingCount ?></span>
            <?php endif; ?>
        </a></li>
        <li><a href="?tab=approved" class="nav-link <?= $tab==='approved'?'active':'text-muted' ?>" style="<?= $tab==='approved'?'background:var(--accent);':'border:1px solid var(--border);' ?>border-radius:8px;font-size:0.85rem;"><i class="bi bi-check-circle me-1"></i>Approved</a></li>
        <li><a href="?tab=rejected" class="nav-link <?= $tab==='rejected'?'active':'text-muted' ?>" style="<?= $tab==='rejected'?'background:var(--accent);':'border:1px solid var(--border);' ?>border-radius:8px;font-size:0.85rem;"><i class="bi bi-x-circle me-1"></i>Rejected</a></li>
    </ul>

    <!-- Request Cards -->
    <?php if (empty($requests)): ?>
    <div class="card">
        <div class="card-body text-center py-5" style="color:var(--text-muted);">
            <i class="bi bi-inbox" style="font-size:3rem;"></i>
            <p class="mt-3">No <?= $status ?> requests found.</p>
            <?php if (isEmployee() && $myEmp && $tab==='pending'): ?>
            <button class="btn-accent mt-2" data-bs-toggle="modal" data-bs-target="#submitModal">Submit a Request</button>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="row g-3">
        <?php foreach ($requests as $r): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card h-100" style="border-color:<?= $r['status']==='Pending'?'rgba(243,156,18,0.4)':($r['status']==='Approved'?'rgba(46,204,113,0.4)':'rgba(231,76,60,0.4)') ?>;">
                <div class="card-body">
                    <!-- Type badge -->
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span style="font-size:0.75rem;font-weight:600;padding:3px 10px;border-radius:20px;
                            background:<?= str_contains($r['request_type'],'Leave')||$r['request_type']==='Absence Excuse'?'rgba(74,158,255,0.15)':'rgba(163,155,254,0.15)' ?>;
                            color:<?= str_contains($r['request_type'],'Leave')||$r['request_type']==='Absence Excuse'?'#4a9eff':'#a29bfe' ?>;">
                            <?= htmlspecialchars($r['request_type']) ?>
                        </span>
                        <span style="font-size:0.72rem;color:var(--text-muted);">
                            <?= date('M d, Y', strtotime($r['created_at'])) ?>
                        </span>
                    </div>

                    <?php if (!isEmployee()): ?>
                    <!-- Employee info (for Manager/Admin view) -->
                    <div style="font-weight:600;margin-bottom:2px;"><?= htmlspecialchars($r['employee_name']) ?></div>
                    <div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:8px;"><?= htmlspecialchars($r['department_name']) ?></div>
                    <?php endif; ?>

                    <!-- Details -->
                    <div style="font-size:0.85rem;color:var(--text-main);margin-bottom:10px;background:rgba(255,255,255,0.04);border-radius:6px;padding:8px 10px;">
                        <?= htmlspecialchars($r['details']) ?>
                    </div>

                    <!-- Handled by badge -->
                    <div style="font-size:0.72rem;color:var(--text-muted);margin-bottom:10px;">
                        <i class="bi bi-person-badge me-1"></i>
                        Reviewed by: <strong><?= htmlspecialchars($r['handled_by']) ?></strong>
                    </div>

                    <!-- Status -->
                    <?php if ($r['status'] === 'Pending' && !isEmployee()): ?>
                    <!-- Approve/Reject form -->
                    <form method="POST">
                        <input type="hidden" name="request_id" value="<?= $r['request_id'] ?>">
                        <div class="mb-2">
                            <input type="text" name="review_note" class="form-control" placeholder="Optional note…" style="font-size:0.8rem;">
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" name="action" value="approve" class="btn-accent flex-fill" style="font-size:0.82rem;">
                                <i class="bi bi-check-lg me-1"></i>Approve
                            </button>
                            <button type="submit" name="action" value="reject"
                                class="btn-outline-accent flex-fill"
                                style="font-size:0.82rem;border-color:var(--danger);color:var(--danger);"
                                onclick="return confirm('Reject this request?')">
                                <i class="bi bi-x-lg me-1"></i>Reject
                            </button>
                        </div>
                    </form>
                    <?php elseif ($r['status'] !== 'Pending'): ?>
                    <!-- Review result -->
                    <div style="border-top:1px solid var(--border);padding-top:8px;font-size:0.8rem;">
                        <span style="color:<?= $r['status']==='Approved'?'var(--success)':'var(--danger)' ?>;font-weight:600;">
                            <i class="bi bi-<?= $r['status']==='Approved'?'check-circle':'x-circle' ?> me-1"></i>
                            <?= $r['status'] ?>
                        </span>
                        <?php if ($r['reviewer_name']): ?>
                        <span style="color:var(--text-muted);"> by <?= htmlspecialchars($r['reviewer_name']) ?></span>
                        <?php endif; ?>
                        <?php if ($r['review_note']): ?>
                        <div style="color:var(--text-muted);margin-top:4px;font-style:italic;">"<?= htmlspecialchars($r['review_note']) ?>"</div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <!-- Employee pending view -->
                    <div style="color:var(--warning);font-size:0.8rem;">
                        <i class="bi bi-hourglass-split me-1"></i>Awaiting <?= $r['handled_by'] ?> review
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Submit Request Modal (Employee only) -->
<?php if (isEmployee() && $myEmp): ?>
<div class="modal fade" id="submitModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="background:var(--card-bg);border:1px solid var(--border);color:var(--text-main);">
            <div class="modal-header" style="border-color:var(--border);">
                <h5 class="modal-title" style="font-family:'Syne',sans-serif;">New Request</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="submit">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Request Type</label>
                        <select name="request_type" id="reqType" class="form-select" onchange="updateHandledBy(this.value)" required>
                            <option value="">Select type…</option>
                            <optgroup label="Leave & Attendance (→ Manager reviews)">
                                <option value="Leave - Sick">Leave — Sick</option>
                                <option value="Leave - Vacation">Leave — Vacation</option>
                                <option value="Absence Excuse">Absence Excuse</option>
                            </optgroup>
                            <optgroup label="Profile Changes (→ Admin reviews)">
                                <option value="Name Change">Name Change</option>
                                <option value="Email Change">Email Change</option>
                                <option value="Other">Other</option>
                            </optgroup>
                        </select>
                    </div>
                    <div id="handledByNote" style="font-size:0.8rem;color:var(--text-muted);margin-bottom:12px;display:none;">
                        <i class="bi bi-info-circle me-1"></i>
                        This request will be reviewed by your <strong id="handledByText"></strong>.
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Details</label>
                        <textarea name="details" class="form-control" rows="4"
                            placeholder="Please describe your request in detail. For name changes, write: 'New name: Juan Cruz'. For leave, include the date(s) needed."
                            required minlength="10"></textarea>
                    </div>
                </div>
                <div class="modal-footer" style="border-color:var(--border);">
                    <button type="button" class="btn-outline-accent" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-accent">Submit Request</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function updateHandledBy(type) {
    const adminTypes = ['Name Change','Email Change','Other'];
    const note = document.getElementById('handledByNote');
    const txt  = document.getElementById('handledByText');
    if (type) {
        note.style.display = 'block';
        txt.textContent = adminTypes.includes(type) ? 'Admin' : 'Manager';
    } else {
        note.style.display = 'none';
    }
}
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
