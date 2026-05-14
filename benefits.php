<?php
// ============================================================
// Benefits – benefits.php (Employee only)
// Shows the employee's pay components, allowances, deductions,
// and a breakdown of how their net pay is calculated.
// ============================================================
require_once 'includes/db.php';
require_once 'includes/auth.php';
requireLogin();
$page_title = 'My Benefits';
$db = getDB();
$cu = currentUser();

// Fetch linked employee record
$empStmt = $db->prepare("
    SELECT e.*, d.department_name, p.position_title, p.base_salary
    FROM employees e
    JOIN departments d ON e.department_id = d.department_id
    JOIN positions   p ON e.position_id   = p.position_id
    WHERE e.email = ? LIMIT 1
");
$empStmt->execute([$cu['username']]);
$myEmp = $empStmt->fetch();

// All pay components
$components = $db->query("
    SELECT * FROM pay_components ORDER BY component_type DESC, component_name
")->fetchAll();

$allowances = array_filter($components, fn($c) => $c['component_type'] === 'Allowance');
$deductions  = array_filter($components, fn($c) => $c['component_type'] === 'Deduction');
$totalAllow  = array_sum(array_column($allowances, 'default_amount'));
$totalDeduct = array_sum(array_column($deductions,  'default_amount'));

$basicSalary = (float)($myEmp['base_salary'] ?? 0);
$grossPay    = $basicSalary + $totalAllow;
$netPay      = $grossPay - $totalDeduct;

// Payroll history summary
$histStmt = $db->prepare("
    SELECT COUNT(*) as total_records,
           COALESCE(SUM(net_pay),0) as total_earned,
           COALESCE(AVG(net_pay),0) as avg_net
    FROM payroll_records WHERE employee_id = ?
");
$histStmt->execute([$myEmp['employee_id'] ?? 0]);
$hist = $histStmt->fetch();

require_once 'includes/header.php';
?>
<div class="main-content">
    <div class="page-header">
        <h1><i class="bi bi-gift me-2 text-accent"></i>My Benefits & Pay Components</h1>
        <p>Breakdown of your salary, allowances, and statutory deductions</p>
    </div>

    <?php if (!$myEmp): ?>
    <div class="alert-danger-dark">
        <i class="bi bi-exclamation-triangle me-2"></i>
        No employee record is linked to your account. Please contact your Admin.
    </div>
    <?php else: ?>

    <!-- Employee Info -->
    <div class="card mb-4" style="border-color:rgba(233,69,96,0.3);">
        <div class="card-body">
            <div class="row g-3 align-items-center">
                <div class="col-auto">
                    <div style="width:56px;height:56px;border-radius:50%;background:rgba(233,69,96,0.15);display:flex;align-items:center;justify-content:center;font-size:1.5rem;color:var(--accent);">
                        <i class="bi bi-person-fill"></i>
                    </div>
                </div>
                <div class="col">
                    <div style="font-family:'Syne',sans-serif;font-weight:700;font-size:1.1rem;"><?= htmlspecialchars($myEmp['first_name'].' '.$myEmp['last_name']) ?></div>
                    <div style="color:var(--text-muted);font-size:0.85rem;"><?= htmlspecialchars($myEmp['position_title']) ?> &mdash; <?= htmlspecialchars($myEmp['department_name']) ?></div>
                </div>
                <div class="col-auto text-end">
                    <div style="font-size:0.75rem;color:var(--text-muted);">Base Salary</div>
                    <div style="font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:700;color:var(--text-main);"><?= formatMoney($basicSalary) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Stat Cards -->
    <div class="row g-3 mb-4">
        <div class="col-sm-4">
            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-wallet2"></i></div>
                <div class="stat-value" style="font-size:1.25rem;"><?= formatMoney($basicSalary) ?></div>
                <div class="stat-label">Basic Salary</div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="stat-card" style="--accent:#2ecc71;">
                <div class="stat-icon" style="background:rgba(46,204,113,0.15);color:#2ecc71;"><i class="bi bi-graph-up-arrow"></i></div>
                <div class="stat-value" style="font-size:1.25rem;"><?= formatMoney($grossPay) ?></div>
                <div class="stat-label">Gross Pay</div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="stat-card" style="--accent:#4a9eff;">
                <div class="stat-icon" style="background:rgba(74,158,255,0.15);color:#4a9eff;"><i class="bi bi-cash-coin"></i></div>
                <div class="stat-value" style="font-size:1.25rem;"><?= formatMoney($netPay) ?></div>
                <div class="stat-label">Estimated Net Pay</div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <!-- Allowances -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header" style="color:var(--success);">
                    <i class="bi bi-plus-circle me-2"></i>Allowances & Benefits
                </div>
                <div class="card-body p-0">
                    <table class="table mb-0">
                        <thead><tr><th>Benefit</th><th class="text-end">Monthly Amount</th></tr></thead>
                        <tbody>
                            <?php foreach ($allowances as $a): ?>
                            <tr>
                                <td>
                                    <div style="font-weight:500;"><?= htmlspecialchars($a['component_name']) ?></div>
                                    <div style="font-size:0.75rem;color:var(--text-muted);">Benefit / Addition</div>
                                </td>
                                <td class="text-end" style="color:var(--success);font-weight:600;">+<?= formatMoney((float)$a['default_amount']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background:rgba(46,204,113,0.1);">
                                <td style="font-weight:700;">Total Allowances</td>
                                <td class="text-end" style="color:var(--success);font-weight:700;">+<?= formatMoney($totalAllow) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- Deductions -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header" style="color:var(--danger);">
                    <i class="bi bi-dash-circle me-2"></i>Statutory Deductions
                </div>
                <div class="card-body p-0">
                    <table class="table mb-0">
                        <thead><tr><th>Deduction</th><th class="text-end">Monthly Amount</th></tr></thead>
                        <tbody>
                            <?php foreach ($deductions as $d): ?>
                            <tr>
                                <td>
                                    <div style="font-weight:500;"><?= htmlspecialchars($d['component_name']) ?></div>
                                    <div style="font-size:0.75rem;color:var(--text-muted);">Statutory Deduction</div>
                                </td>
                                <td class="text-end" style="color:var(--danger);font-weight:600;">-<?= formatMoney((float)$d['default_amount']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background:rgba(231,76,60,0.1);">
                                <td style="font-weight:700;">Total Deductions</td>
                                <td class="text-end" style="color:var(--danger);font-weight:700;">-<?= formatMoney($totalDeduct) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Pay Computation Summary -->
    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-calculator me-2 text-accent"></i>Monthly Pay Computation</div>
        <div class="card-body">
            <div style="max-width:400px;margin:0 auto;">
                <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border);">
                    <span style="color:var(--text-muted);">Basic Salary</span>
                    <span style="font-weight:600;"><?= formatMoney($basicSalary) ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border);">
                    <span style="color:var(--success);">+ Total Allowances</span>
                    <span style="color:var(--success);font-weight:600;"><?= formatMoney($totalAllow) ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:2px solid var(--border);">
                    <span style="color:var(--text-muted);">= Gross Pay</span>
                    <span style="font-weight:600;"><?= formatMoney($grossPay) ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border);">
                    <span style="color:var(--danger);">- Total Deductions</span>
                    <span style="color:var(--danger);font-weight:600;"><?= formatMoney($totalDeduct) ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:14px 0;margin-top:4px;border-top:2px solid var(--accent);">
                    <span style="font-family:'Syne',sans-serif;font-weight:700;font-size:1rem;">Estimated Net Pay</span>
                    <span style="font-family:'Syne',sans-serif;font-weight:700;font-size:1.1rem;color:var(--success);"><?= formatMoney($netPay) ?></span>
                </div>
            </div>
            <p style="text-align:center;font-size:0.75rem;color:var(--text-muted);margin-top:8px;">
                <i class="bi bi-info-circle me-1"></i>Actual amounts may vary. Contact your Manager or Admin for queries.
            </p>
        </div>
    </div>

    <!-- Payroll History Summary -->
    <?php if ($hist['total_records'] > 0): ?>
    <div class="card">
        <div class="card-header"><i class="bi bi-bar-chart me-2 text-accent"></i>My Payroll Summary</div>
        <div class="card-body">
            <div class="row g-3 text-center">
                <div class="col-4">
                    <div style="font-size:1.5rem;font-weight:700;font-family:'Syne',sans-serif;"><?= $hist['total_records'] ?></div>
                    <div style="font-size:0.78rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;">Payroll Records</div>
                </div>
                <div class="col-4">
                    <div style="font-size:1.2rem;font-weight:700;font-family:'Syne',sans-serif;color:var(--success);"><?= formatMoney((float)$hist['total_earned']) ?></div>
                    <div style="font-size:0.78rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;">Total Earned</div>
                </div>
                <div class="col-4">
                    <div style="font-size:1.2rem;font-weight:700;font-family:'Syne',sans-serif;color:#4a9eff;"><?= formatMoney((float)$hist['avg_net']) ?></div>
                    <div style="font-size:0.78rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;">Average Net Pay</div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // $myEmp ?>
</div>
<?php require_once 'includes/footer.php'; ?>
