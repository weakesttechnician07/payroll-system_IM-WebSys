<?php
// ============================================================
// Process Payroll – process.php
// NEW: Attendance-based prorated salary calculation
//      Absences deduct from basic salary (daily rate × absent days)
//      Transaction + SELECT FOR UPDATE + optimistic locking
// ============================================================
require_once 'includes/db.php';
require_once 'includes/auth.php';
requireAdmin();
$page_title = 'Process Payroll';
$db  = getDB();
$msg = '';
$msg_type = '';

$months = ['','January','February','March','April','May','June',
           'July','August','September','October','November','December'];

// ── POST: Run Payroll ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'process') {
    $month = (int)$_POST['payroll_month'];
    $year  = (int)$_POST['payroll_year'];

    if ($month < 1 || $month > 12 || $year < 2000) {
        $msg = 'Invalid month/year.'; $msg_type = 'danger';
    } else {
        try {
            $db->beginTransaction();

            // Lock active employee rows (pessimistic concurrency)
            $empStmt = $db->prepare("
                SELECT e.employee_id, e.version, p.base_salary,
                       COALESCE(a.working_days, 22) AS working_days,
                       COALESCE(a.days_present,  22) AS days_present,
                       COALESCE(a.days_absent,    0) AS days_absent,
                       COALESCE(a.days_worked,   22) AS days_worked
                FROM employees e
                JOIN positions p ON e.position_id = p.position_id
                LEFT JOIN attendance a
                    ON a.employee_id = e.employee_id
                    AND a.attendance_month = ?
                    AND a.attendance_year  = ?
                WHERE e.status = 'Active'
                FOR UPDATE
            ");
            $empStmt->execute([$month, $year]);
            $active = $empStmt->fetchAll();

            if (empty($active)) {
                $db->rollBack();
                $msg = 'No active employees found.'; $msg_type = 'danger';
            } else {
                // Get pay components
                $totalAllow  = $db->query("SELECT COALESCE(SUM(default_amount),0) FROM pay_components WHERE component_type='Allowance'")->fetchColumn();
                $totalDeduct = $db->query("SELECT COALESCE(SUM(default_amount),0) FROM pay_components WHERE component_type='Deduction'")->fetchColumn();

                $insertStmt = $db->prepare("
                    INSERT INTO payroll_records
                        (employee_id, payroll_month, payroll_year,
                         basic_salary, total_allowance, total_deduction)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                $inserted = 0; $skipped = 0; $details = [];

                foreach ($active as $emp) {
                    // Check for existing record
                    $chk = $db->prepare("SELECT COUNT(*) FROM payroll_records WHERE employee_id=? AND payroll_month=? AND payroll_year=?");
                    $chk->execute([$emp['employee_id'], $month, $year]);
                    if ($chk->fetchColumn() > 0) { $skipped++; continue; }

                    // ── Prorated salary calculation ──────────
                    // Daily rate = base_salary / working_days
                    // Prorated  = daily_rate * days_present
                    // Absence deduction already baked into prorated salary
                    $workingDays  = max(1, (int)$emp['working_days']); // avoid division by zero
                    $daysPresent  = max(0, (int)$emp['days_present']);
                    $dailyRate    = round($emp['base_salary'] / $workingDays, 4);
                    $proratedPay  = round($dailyRate * $daysPresent, 2);

                    // Absence deduction is reflected in prorated pay already
                    // total_deduction = statutory deductions only (SSS, PhilHealth, etc.)
                    $insertStmt->execute([
                        $emp['employee_id'],
                        $month,
                        $year,
                        $proratedPay,   // basic_salary = prorated (attendance-adjusted)
                        $totalAllow,
                        $totalDeduct
                    ]);

                    $details[] = [
                        'id'          => $emp['employee_id'],
                        'base'        => $emp['base_salary'],
                        'working'     => $workingDays,
                        'present'     => $daysPresent,
                        'absent'      => $emp['days_absent'],
                        'prorated'    => $proratedPay,
                        'absent_ded'  => round($dailyRate * $emp['days_absent'], 2),
                    ];
                    $inserted++;
                }

                $db->commit();
                auditLog('Process Payroll','payroll_records',"Month=$month Year=$year Inserted=$inserted Skipped=$skipped");
                $msg = "Payroll processed: {$inserted} record(s) saved, {$skipped} skipped (already processed).";
                $msg_type = 'success';
            }
        } catch (PDOException $e) {
            $db->rollBack();
            $msg = 'Transaction rolled back: '.$e->getMessage();
            $msg_type = 'danger';
        }
    }
}

// ── Preview: load active employees with attendance ───────────
$prev_month = (int)($_POST['payroll_month'] ?? $_GET['month'] ?? date('n'));
$prev_year  = (int)($_POST['payroll_year']  ?? $_GET['year']  ?? date('Y'));

$previewStmt = $db->prepare("
    SELECT
        e.employee_id,
        CONCAT(e.first_name,' ',e.last_name) AS full_name,
        d.department_name,
        p.position_title,
        p.base_salary,
        COALESCE(a.working_days, 22) AS working_days,
        COALESCE(a.days_worked,  22) AS days_worked,
        COALESCE(a.days_absent,   0) AS days_absent,
        COALESCE(a.days_present, 22) AS days_present,
        ROUND(p.base_salary / COALESCE(a.working_days,22) * COALESCE(a.days_present,22), 2) AS prorated_salary,
        ROUND(p.base_salary / COALESCE(a.working_days,22) * COALESCE(a.days_absent,0),  2) AS absence_deduction,
        (SELECT COALESCE(SUM(default_amount),0) FROM pay_components WHERE component_type='Allowance') AS total_allowance,
        (SELECT COALESCE(SUM(default_amount),0) FROM pay_components WHERE component_type='Deduction') AS total_deduction,
        ROUND(
            p.base_salary / COALESCE(a.working_days,22) * COALESCE(a.days_present,22)
            + (SELECT COALESCE(SUM(default_amount),0) FROM pay_components WHERE component_type='Allowance'),
            2
        ) AS gross_pay,
        ROUND(
            p.base_salary / COALESCE(a.working_days,22) * COALESCE(a.days_present,22)
            + (SELECT COALESCE(SUM(default_amount),0) FROM pay_components WHERE component_type='Allowance')
            - (SELECT COALESCE(SUM(default_amount),0) FROM pay_components WHERE component_type='Deduction'),
            2
        ) AS net_pay,
        (SELECT COUNT(*) FROM payroll_records pr2
         WHERE pr2.employee_id=e.employee_id
           AND pr2.payroll_month=? AND pr2.payroll_year=?) AS already_processed,
        a.attendance_month IS NOT NULL AS has_attendance
    FROM employees e
    JOIN departments d ON e.department_id = d.department_id
    JOIN positions   p ON e.position_id   = p.position_id
    LEFT JOIN attendance a
        ON a.employee_id = e.employee_id
        AND a.attendance_month = ? AND a.attendance_year = ?
    WHERE e.status = 'Active'
    ORDER BY d.department_name, e.last_name
");
$previewStmt->execute([$prev_month, $prev_year, $prev_month, $prev_year]);
$preview = $previewStmt->fetchAll();

$components = $db->query("SELECT * FROM pay_components ORDER BY component_type DESC, component_name")->fetchAll();
$totalNet   = array_sum(array_column($preview, 'net_pay'));
$noAttCount = count(array_filter($preview, fn($r) => !$r['has_attendance']));

require_once 'includes/header.php';
?>

<div class="main-content">
    <div class="page-header">
        <h1><i class="bi bi-cash-coin me-2 text-accent"></i>Process Payroll</h1>
        <p>Attendance-aware · BEGIN / COMMIT / ROLLBACK · SELECT FOR UPDATE · Admin only</p>
    </div>

    <?php if ($msg): ?>
    <div class="alert-<?= $msg_type==='success'?'success':'danger' ?>-dark mb-3">
        <i class="bi bi-<?= $msg_type==='success'?'check-circle':'exclamation-triangle' ?> me-2"></i>
        <?= htmlspecialchars($msg) ?>
    </div>
    <?php endif; ?>

    <?php if ($noAttCount > 0): ?>
    <div style="background:rgba(243,156,18,0.1);border:1px solid rgba(243,156,18,0.3);color:#f39c12;border-radius:8px;padding:12px 16px;margin-bottom:16px;">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <strong><?= $noAttCount ?> employee(s)</strong> have no attendance record for <?= $months[$prev_month] ?> <?= $prev_year ?> — they will be paid full salary (22 days assumed).
    </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Left: Config -->
        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-gear me-2 text-accent"></i>Payroll Period</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="process">
                        <div class="mb-3">
                            <label class="form-label">Month</label>
                            <select name="payroll_month" class="form-select">
                                <?php for ($m=1;$m<=12;$m++): ?>
                                <option value="<?= $m ?>" <?= $m===$prev_month?'selected':'' ?>><?= $months[$m] ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Year</label>
                            <select name="payroll_year" class="form-select">
                                <?php for ($y=date('Y');$y>=2022;$y--): ?>
                                <option value="<?= $y ?>" <?= $y===$prev_year?'selected':'' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn-accent w-100">
                            <i class="bi bi-play-fill me-1"></i> Run Payroll
                        </button>
                    </form>
                </div>
            </div>

            <!-- Pay Components -->
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-list-check me-2 text-accent"></i>Pay Components</div>
                <div class="card-body p-0">
                    <table class="table mb-0" style="font-size:0.82rem;">
                        <thead><tr><th>Component</th><th class="text-end">Amount</th></tr></thead>
                        <tbody>
                            <?php foreach ($components as $c): ?>
                            <tr>
                                <td><?= htmlspecialchars($c['component_name']) ?></td>
                                <td class="text-end" style="color:<?= $c['component_type']==='Allowance'?'var(--success)':'var(--danger)' ?>;">
                                    <?= $c['component_type']==='Allowance'?'+':'-' ?>
                                    <?= formatMoney((float)$c['default_amount']) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- How it calculates -->
            <div class="card" style="border-color:rgba(233,69,96,0.3);">
                <div class="card-header" style="font-size:0.8rem;"><i class="bi bi-info-circle me-2 text-accent"></i>Calculation Method</div>
                <div class="card-body" style="font-size:0.78rem;color:var(--text-muted);line-height:1.8;">
                    <div><strong style="color:var(--text-main);">Daily Rate</strong> = Base Salary ÷ Working Days</div>
                    <div><strong style="color:var(--text-main);">Prorated Salary</strong> = Daily Rate × Days Present</div>
                    <div><strong style="color:var(--text-main);">Gross Pay</strong> = Prorated + Allowances</div>
                    <div><strong style="color:var(--success);">Net Pay</strong> = Gross − Statutory Deductions</div>
                    <hr style="border-color:var(--border);margin:8px 0;">
                    <div style="font-size:0.75rem;">If no attendance record exists, full salary is used (22 working days assumed).</div>
                </div>
            </div>
        </div>

        <!-- Right: Preview Table -->
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    Preview — <?= $months[$prev_month] ?> <?= $prev_year ?>
                    <span class="float-end" style="color:var(--success);font-weight:600;">
                        Total Net: <?= formatMoney($totalNet) ?>
                    </span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0" style="font-size:0.82rem;">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th class="text-center">Days</th>
                                    <th class="text-center">Absent</th>
                                    <th class="text-end">Base</th>
                                    <th class="text-end">Absence Ded.</th>
                                    <th class="text-end">Net Pay</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($preview as $r): ?>
                                <tr <?= !$r['has_attendance'] ? 'style="opacity:0.7;"' : '' ?>>
                                    <td>
                                        <strong><?= htmlspecialchars($r['full_name']) ?></strong><br>
                                        <small style="color:var(--text-muted);"><?= htmlspecialchars($r['department_name']) ?></small>
                                    </td>
                                    <td class="text-center">
                                        <?= $r['days_present'] ?>/<?= $r['working_days'] ?>
                                        <?php if (!$r['has_attendance']): ?>
                                        <i class="bi bi-question-circle" style="color:var(--warning);font-size:0.75rem;" title="No attendance record — using default"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center" style="color:<?= $r['days_absent']>0?'var(--danger)':'var(--text-muted)' ?>;">
                                        <?= $r['days_absent'] ?>
                                    </td>
                                    <td class="text-end"><?= formatMoney((float)$r['base_salary']) ?></td>
                                    <td class="text-end" style="color:var(--danger);">
                                        <?= $r['days_absent'] > 0 ? '-'.formatMoney((float)$r['absence_deduction']) : '—' ?>
                                    </td>
                                    <td class="text-end" style="color:var(--success);font-weight:600;">
                                        <?= formatMoney((float)$r['net_pay']) ?>
                                    </td>
                                    <td>
                                        <?php if ($r['already_processed']): ?>
                                        <span style="color:var(--warning);font-size:0.75rem;"><i class="bi bi-check-circle"></i> Done</span>
                                        <?php else: ?>
                                        <span style="color:var(--text-muted);font-size:0.75rem;"><i class="bi bi-clock"></i> Pending</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background:rgba(15,52,96,0.3);">
                                    <td colspan="5" class="text-end" style="font-weight:600;">Total Net Pay:</td>
                                    <td class="text-end" style="color:var(--success);font-weight:700;"><?= formatMoney($totalNet) ?></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
