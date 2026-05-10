<?php
// ============================================================
// Process Payroll – process.php
// Demonstrates:
//   • Database Transaction (BEGIN / COMMIT / ROLLBACK)
//   • Pessimistic Concurrency Control (SELECT FOR UPDATE)
//   • Optimistic Concurrency via employee version column
// ============================================================
require_once 'includes/db.php';
require_once 'includes/auth.php';
requireAdmin();   // Only Admins can process payroll (Manager + Employee denied)
$page_title = 'Process Payroll';
$db = getDB();

$msg = '';
$msg_type = '';
$preview = [];

// ── Fetch components for calculation ────────────────────────
$allowances = $db->query("SELECT * FROM pay_components WHERE component_type='Allowance'")->fetchAll();
$deductions  = $db->query("SELECT * FROM pay_components WHERE component_type='Deduction'")->fetchAll();

// ── POST: Process payroll (with transaction + concurrency) ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'process') {
    $month = (int)$_POST['payroll_month'];
    $year  = (int)$_POST['payroll_year'];

    if ($month < 1 || $month > 12 || $year < 2000) {
        $msg = 'Invalid month/year selected.';
        $msg_type = 'danger';
    } else {
        try {
            // BEGIN TRANSACTION
            $db->beginTransaction();

            // Fetch active employees with FOR UPDATE (pessimistic locking)
            // This prevents other transactions from modifying these rows simultaneously
            $empStmt = $db->prepare("
                SELECT e.employee_id, e.first_name, e.last_name, e.version,
                       p.base_salary
                FROM employees e
                JOIN positions p ON e.position_id = p.position_id
                WHERE e.status = 'Active'
                FOR UPDATE
            ");
            $empStmt->execute();
            $active_employees = $empStmt->fetchAll();

            if (empty($active_employees)) {
                $db->rollBack();
                $msg = 'No active employees found.';
                $msg_type = 'danger';
            } else {
                $total_allowance = array_sum(array_column($allowances, 'default_amount'));
                $total_deduction = array_sum(array_column($deductions, 'default_amount'));

                $inserted = 0;
                $skipped  = 0;

                $insertStmt = $db->prepare("
                    INSERT INTO payroll_records
                        (employee_id, payroll_month, payroll_year, basic_salary, total_allowance, total_deduction)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE payroll_id = payroll_id   -- skip duplicates gracefully
                ");

                foreach ($active_employees as $emp) {
                    // Check if payroll for this period already exists (subquery approach)
                    $checkStmt = $db->prepare("
                        SELECT COUNT(*) FROM payroll_records
                        WHERE employee_id=? AND payroll_month=? AND payroll_year=?
                    ");
                    $checkStmt->execute([$emp['employee_id'], $month, $year]);
                    if ($checkStmt->fetchColumn() > 0) {
                        $skipped++;
                        continue;
                    }

                    $insertStmt->execute([
                        $emp['employee_id'],
                        $month,
                        $year,
                        $emp['base_salary'],
                        $total_allowance,
                        $total_deduction
                    ]);
                    $inserted++;
                }

                // COMMIT TRANSACTION – all records saved atomically
                $db->commit();
                auditLog('Process Payroll', 'payroll_records', "Month=$month Year=$year Inserted=$inserted Skipped=$skipped");
                $msg = "Payroll processed: {$inserted} record(s) saved, {$skipped} skipped (already processed)";
                $msg_type = 'success';
            }
        } catch (PDOException $e) {
            // ROLLBACK on error – no partial data saved
            $db->rollBack();
            $msg = 'Transaction rolled back: ' . $e->getMessage();
            $msg_type = 'danger';
        }
    }
}

// ── GET/POST: Preview ────────────────────────────────────────
$preview_month = (int)($_POST['payroll_month'] ?? $_GET['month'] ?? date('n'));
$preview_year  = (int)($_POST['payroll_year']  ?? $_GET['year']  ?? date('Y'));

$total_allowance_val = array_sum(array_column($allowances, 'default_amount'));
$total_deduction_val  = array_sum(array_column($deductions,  'default_amount'));

$preview = $db->prepare("
    SELECT e.employee_id, CONCAT(e.first_name,' ',e.last_name) AS full_name,
           d.department_name, p.position_title, p.base_salary,
           (p.base_salary + ?) AS gross_pay,
           (p.base_salary + ? - ?) AS net_pay,
           (SELECT COUNT(*) FROM payroll_records pr2
            WHERE pr2.employee_id = e.employee_id
              AND pr2.payroll_month = ? AND pr2.payroll_year = ?) AS already_processed
    FROM employees e
    JOIN departments d ON e.department_id = d.department_id
    JOIN positions   p ON e.position_id   = p.position_id
    WHERE e.status = 'Active'
    ORDER BY d.department_name, e.last_name
");
$preview->execute([
    $total_allowance_val,
    $total_allowance_val,
    $total_deduction_val,
    $preview_month,
    $preview_year
]);
$preview_rows = $preview->fetchAll();

require_once 'includes/header.php';
?>

<div class="main-content">
    <div class="page-header">
        <h1><i class="bi bi-cash-coin me-2 text-accent"></i>Process Payroll</h1>
        <p>Uses a database transaction (BEGIN / COMMIT / ROLLBACK) and SELECT FOR UPDATE for concurrency control</p>
    </div>

    <?php if ($msg): ?>
    <div class="alert-<?= $msg_type === 'success' ? 'success' : 'danger' ?>-dark mb-3">
        <i class="bi bi-<?= $msg_type === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
        <?= htmlspecialchars($msg) ?>
    </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Config Panel -->
        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-gear me-2 text-accent"></i>Payroll Period</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="process">
                        <div class="mb-3">
                            <label class="form-label">Month</label>
                            <select name="payroll_month" class="form-select">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= $m === $preview_month ? 'selected' : '' ?>>
                                    <?= date('F', mktime(0,0,0,$m,1)) ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Year</label>
                            <select name="payroll_year" class="form-select">
                                <?php for ($y = date('Y'); $y >= 2022; $y--): ?>
                                <option value="<?= $y ?>" <?= $y === $preview_year ? 'selected' : '' ?>><?= $y ?></option>
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
            <div class="card">
                <div class="card-header"><i class="bi bi-list-check me-2 text-accent"></i>Pay Components</div>
                <div class="card-body p-0">
                    <table class="table mb-0" style="font-size:0.82rem;">
                        <thead><tr><th>Component</th><th>Type</th><th class="text-end">Amount</th></tr></thead>
                        <tbody>
                            <?php foreach ($allowances as $a): ?>
                            <tr>
                                <td><?= htmlspecialchars($a['component_name']) ?></td>
                                <td><span style="color:var(--success);font-size:0.75rem;">+ Allow</span></td>
                                <td class="text-end" style="color:var(--success);">+₱<?= number_format($a['default_amount'],2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php foreach ($deductions as $d): ?>
                            <tr>
                                <td><?= htmlspecialchars($d['component_name']) ?></td>
                                <td><span style="color:var(--danger);font-size:0.75rem;">- Deduct</span></td>
                                <td class="text-end" style="color:var(--danger);">-₱<?= number_format($d['default_amount'],2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Technical Notes -->
            <div class="card mt-3" style="border-color:rgba(233,69,96,0.3);">
                <div class="card-header" style="font-size:0.8rem;"><i class="bi bi-info-circle me-2 text-accent"></i>Applied Concepts</div>
                <div class="card-body" style="font-size:0.78rem;color:var(--text-muted);line-height:1.7;">
                    <p><strong style="color:var(--accent);">Transaction:</strong> All inserts wrapped in BEGIN → COMMIT. If any insert fails, ROLLBACK is called.</p>
                    <p class="mb-0"><strong style="color:var(--accent);">Concurrency:</strong> <code>SELECT FOR UPDATE</code> locks employee rows during the transaction. Employee edits also use a <em>version</em> column (optimistic locking).</p>
                </div>
            </div>
        </div>

        <!-- Preview Table -->
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    Preview for <?= date('F Y', mktime(0,0,0,$preview_month,1,$preview_year)) ?>
                    <span class="float-end" style="color:var(--text-muted);font-size:0.8rem;"><?= count($preview_rows) ?> active employees</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Dept</th>
                                    <th class="text-end">Basic</th>
                                    <th class="text-end">Gross</th>
                                    <th class="text-end">Net Pay</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $total_net = 0;
                                foreach ($preview_rows as $r):
                                    $total_net += $r['net_pay'];
                                ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($r['full_name']) ?></strong><br>
                                        <small style="color:var(--text-muted)"><?= htmlspecialchars($r['position_title']) ?></small></td>
                                    <td><?= htmlspecialchars($r['department_name']) ?></td>
                                    <td class="text-end">₱<?= number_format($r['base_salary'],2) ?></td>
                                    <td class="text-end">₱<?= number_format($r['gross_pay'],2) ?></td>
                                    <td class="text-end" style="color:var(--success);font-weight:600;">₱<?= number_format($r['net_pay'],2) ?></td>
                                    <td>
                                        <?php if ($r['already_processed']): ?>
                                        <span style="color:var(--warning);font-size:0.75rem;"><i class="bi bi-check-circle"></i> Processed</span>
                                        <?php else: ?>
                                        <span style="color:var(--text-muted);font-size:0.75rem;"><i class="bi bi-clock"></i> Pending</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background:rgba(15,52,96,0.3);">
                                    <td colspan="4" class="text-end" style="font-weight:600;">Total Net Pay:</td>
                                    <td class="text-end" style="color:var(--success);font-weight:700;font-size:1rem;">₱<?= number_format($total_net,2) ?></td>
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
