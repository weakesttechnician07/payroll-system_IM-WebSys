<?php
// ============================================================
// Dashboard – index.php
// ============================================================
require_once 'includes/db.php';
require_once 'includes/auth.php';
requireLogin();
$page_title = 'Dashboard';

$db = getDB();

// Total employees
$total_employees = $db->query("SELECT COUNT(*) FROM employees WHERE status='Active'")->fetchColumn();

// Total payroll records
$total_records = $db->query("SELECT COUNT(*) FROM payroll_records")->fetchColumn();

// Total amount paid (net pay)
$total_paid = $db->query("SELECT COALESCE(SUM(net_pay),0) FROM payroll_records")->fetchColumn();

// Last payroll date
$last_payroll = $db->query("SELECT MAX(processed_at) FROM payroll_records")->fetchColumn();

// Department breakdown (subquery example)
$dept_stats = $db->query("
    SELECT d.department_name,
           COUNT(e.employee_id) AS emp_count,
           (SELECT COUNT(*) FROM payroll_records pr WHERE pr.employee_id IN
                (SELECT employee_id FROM employees WHERE department_id = d.department_id)
           ) AS payroll_count
    FROM departments d
    LEFT JOIN employees e ON e.department_id = d.department_id AND e.status = 'Active'
    GROUP BY d.department_id, d.department_name
    ORDER BY emp_count DESC
")->fetchAll();

// Recent payroll (with JOIN)
$recent = $db->query("
    SELECT pd.employee_name, pd.department_name, pd.payroll_year, pd.payroll_month,
           pd.net_pay, pd.processed_at
    FROM vw_payroll_detail pd
    ORDER BY pd.processed_at DESC
    LIMIT 5
")->fetchAll();

require_once 'includes/header.php';
?>

<div class="main-content">
    <div class="page-header">
        <h1><i class="bi bi-speedometer2 me-2 text-accent"></i>Dashboard</h1>
        <p>Overview of your payroll system activity</p>
    </div>

    <!-- Stat Cards -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
                <div class="stat-value"><?= number_format($total_employees) ?></div>
                <div class="stat-label">Active Employees</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card" style="--accent:#0f3460;">
                <div class="stat-icon" style="background:rgba(15,52,96,0.3);color:#4a9eff;"><i class="bi bi-receipt"></i></div>
                <div class="stat-value"><?= number_format($total_records) ?></div>
                <div class="stat-label">Payroll Records</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card" style="--accent:#2ecc71;">
                <div class="stat-icon" style="background:rgba(46,204,113,0.15);color:#2ecc71;"><i class="bi bi-cash-stack"></i></div>
                <div class="stat-value">₱<?= number_format($total_paid, 0) ?></div>
                <div class="stat-label">Total Net Pay</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card" style="--accent:#f39c12;">
                <div class="stat-icon" style="background:rgba(243,156,18,0.15);color:#f39c12;"><i class="bi bi-calendar-check"></i></div>
                <div class="stat-value" style="font-size:1.1rem;margin-top:6px;">
                    <?= $last_payroll ? date('M d, Y', strtotime($last_payroll)) : 'No records' ?>
                </div>
                <div class="stat-label">Last Payroll Run</div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Department Breakdown -->
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-building me-2 text-accent"></i>Department Breakdown</div>
                <div class="card-body p-0">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>Department</th>
                                <th class="text-end">Employees</th>
                                <th class="text-end">Payroll Runs</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dept_stats as $d): ?>
                            <tr>
                                <td><?= htmlspecialchars($d['department_name']) ?></td>
                                <td class="text-end"><?= $d['emp_count'] ?></td>
                                <td class="text-end"><?= $d['payroll_count'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Recent Payroll -->
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-clock-history me-2 text-accent"></i>Recent Payroll Records</div>
                <div class="card-body p-0">
                    <?php if (empty($recent)): ?>
                        <div class="p-4 text-center" style="color:var(--text-muted)">
                            <i class="bi bi-inbox" style="font-size:2rem;"></i>
                            <p class="mt-2">No payroll records yet. <a href="process.php" class="text-accent">Process payroll →</a></p>
                        </div>
                    <?php else: ?>
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Dept</th>
                                <th>Period</th>
                                <th class="text-end">Net Pay</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['employee_name']) ?></td>
                                <td style="color:var(--text-muted)"><?= htmlspecialchars($r['department_name']) ?></td>
                                <td><?= date('M Y', mktime(0,0,0,$r['payroll_month'],1,$r['payroll_year'])) ?></td>
                                <td class="text-end" style="color:var(--success);font-weight:600;">₱<?= number_format($r['net_pay'],2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
