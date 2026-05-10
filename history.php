<?php
// ============================================================
// Payroll History – history.php
// Demonstrates:
//   • JOIN via vw_payroll_detail VIEW
//   • Window function via vw_payroll_ranking VIEW
//   • Filtering with prepared statements
// ============================================================
require_once 'includes/db.php';
require_once 'includes/auth.php';
requireLogin();
$page_title = 'Payroll History';
$db = getDB();

// Fetch filter options
$departments = $db->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();
$years = $db->query("
    SELECT DISTINCT payroll_year FROM payroll_records ORDER BY payroll_year DESC
")->fetchAll(PDO::FETCH_COLUMN);

// Build filter query
$dept_filter = isset($_GET['dept']) ? (int)$_GET['dept']      : 0;
$year_filter = isset($_GET['year']) ? (int)$_GET['year']      : 0;
$month_filter= isset($_GET['month'])? (int)$_GET['month']     : 0;

$where  = ['1=1'];
$params = [];

if ($dept_filter)  { $where[] = "pd.department_name = (SELECT department_name FROM departments WHERE department_id=?)"; $params[] = $dept_filter; }
if ($year_filter)  { $where[] = "pd.payroll_year = ?";  $params[] = $year_filter;  }
if ($month_filter) { $where[] = "pd.payroll_month = ?"; $params[] = $month_filter; }

// Employee: only see their own payroll records
if (isEmployee()) {
    $cu = currentUser();
    $where[] = "(pd.employee_name = ? OR pd.email = ?)";
    $params[] = $cu['full_name'];
    $params[] = $cu['username'];
}

$sql = "
    SELECT * FROM vw_payroll_detail pd
    WHERE " . implode(' AND ', $where) . "
    ORDER BY pd.payroll_year DESC, pd.payroll_month DESC, pd.employee_name
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

// Department summary (using vw_dept_payroll_summary view)
$dept_summary_sql = "SELECT * FROM vw_dept_payroll_summary";
$summary_params = [];
if ($year_filter) {
    $dept_summary_sql .= " WHERE payroll_year=?";
    $summary_params[] = $year_filter;
}
$dept_summary_sql .= " ORDER BY payroll_year DESC, payroll_month DESC, department_name";
$dept_stmt = $db->prepare($dept_summary_sql);
$dept_stmt->execute($summary_params);
$dept_summary = $dept_stmt->fetchAll();

// Payroll ranking (window function view) for latest period
$latest = $db->query("SELECT payroll_year, payroll_month FROM payroll_records ORDER BY payroll_year DESC, payroll_month DESC LIMIT 1")->fetch();
$ranking = [];
if ($latest) {
    $rank_stmt = $db->prepare("
        SELECT * FROM vw_payroll_ranking
        WHERE payroll_year=? AND payroll_month=?
        ORDER BY pay_rank
    ");
    $rank_stmt->execute([$latest['payroll_year'], $latest['payroll_month']]);
    $ranking = $rank_stmt->fetchAll();
}

require_once 'includes/header.php';
?>

<div class="main-content">
    <div class="page-header">
        <h1><i class="bi bi-clock-history me-2 text-accent"></i>Payroll History</h1>
        <p>Records from <code>vw_payroll_detail</code> (JOIN view) · Rankings from <code>vw_payroll_ranking</code> (window functions)</p>
    </div>

    <!-- Filter Bar -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-sm-4 col-md-3">
                    <label class="form-label">Department</label>
                    <select name="dept" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['department_id'] ?>" <?= $dept_filter == $d['department_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($d['department_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-3 col-md-2">
                    <label class="form-label">Year</label>
                    <select name="year" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($years as $y): ?>
                        <option value="<?= $y ?>" <?= $year_filter == $y ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-3 col-md-2">
                    <label class="form-label">Month</label>
                    <select name="month" class="form-select">
                        <option value="">All</option>
                        <?php for ($m=1;$m<=12;$m++): ?>
                        <option value="<?= $m ?>" <?= $month_filter==$m ? 'selected' : '' ?>><?= date('F',mktime(0,0,0,$m,1)) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn-accent">Filter</button>
                    <a href="history.php" class="btn-outline-accent ms-2">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Nav Tabs -->
    <ul class="nav nav-pills mb-4" style="gap:8px;">
        <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="pill" href="#tab-records" style="background:var(--accent);border-radius:8px;font-size:0.85rem;">All Records</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="pill" href="#tab-dept" style="color:var(--text-muted);border-radius:8px;font-size:0.85rem;">Dept Summary</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="pill" href="#tab-rank" style="color:var(--text-muted);border-radius:8px;font-size:0.85rem;">Pay Rankings</a>
        </li>
    </ul>

    <div class="tab-content">
        <!-- Tab 1: All Records -->
        <div class="tab-pane fade show active" id="tab-records">
            <div class="card">
                <div class="card-header"><?= count($records) ?> record(s) – from <code>vw_payroll_detail</code> (JOIN: employees + departments + positions)</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Employee</th>
                                    <th>Department</th>
                                    <th>Period</th>
                                    <th class="text-end">Basic</th>
                                    <th class="text-end">Allowance</th>
                                    <th class="text-end">Deduction</th>
                                    <th class="text-end">Gross</th>
                                    <th class="text-end">Net Pay</th>
                                    <th>Processed</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($records)): ?>
                                <tr><td colspan="10" class="text-center py-4" style="color:var(--text-muted)">No records found.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($records as $i => $r): ?>
                                <tr>
                                    <td style="color:var(--text-muted)"><?= $i+1 ?></td>
                                    <td><strong><?= htmlspecialchars($r['employee_name']) ?></strong></td>
                                    <td><?= htmlspecialchars($r['department_name']) ?></td>
                                    <td><?= date('M Y', mktime(0,0,0,$r['payroll_month'],1,$r['payroll_year'])) ?></td>
                                    <td class="text-end">₱<?= number_format($r['basic_salary'],2) ?></td>
                                    <td class="text-end" style="color:var(--success);">+₱<?= number_format($r['total_allowance'],2) ?></td>
                                    <td class="text-end" style="color:var(--danger);">-₱<?= number_format($r['total_deduction'],2) ?></td>
                                    <td class="text-end">₱<?= number_format($r['gross_pay'],2) ?></td>
                                    <td class="text-end" style="color:var(--success);font-weight:600;">₱<?= number_format($r['net_pay'],2) ?></td>
                                    <td style="color:var(--text-muted);font-size:0.78rem;"><?= date('M d, Y', strtotime($r['processed_at'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 2: Department Summary -->
        <div class="tab-pane fade" id="tab-dept">
            <div class="card">
                <div class="card-header">Department Payroll Summary – from <code>vw_dept_payroll_summary</code> (GROUP BY + aggregates)</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>Department</th>
                                    <th>Period</th>
                                    <th class="text-end">Employees</th>
                                    <th class="text-end">Total Gross</th>
                                    <th class="text-end">Total Net</th>
                                    <th class="text-end">Avg Net</th>
                                    <th class="text-end">Max Net</th>
                                    <th class="text-end">Min Net</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($dept_summary)): ?>
                                <tr><td colspan="8" class="text-center py-4" style="color:var(--text-muted)">No records.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($dept_summary as $s): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($s['department_name']) ?></strong></td>
                                    <td><?= date('M Y', mktime(0,0,0,$s['payroll_month'],1,$s['payroll_year'])) ?></td>
                                    <td class="text-end"><?= $s['employee_count'] ?></td>
                                    <td class="text-end">₱<?= number_format($s['total_gross'],2) ?></td>
                                    <td class="text-end" style="color:var(--success);font-weight:600;">₱<?= number_format($s['total_net'],2) ?></td>
                                    <td class="text-end">₱<?= number_format($s['avg_net'],2) ?></td>
                                    <td class="text-end">₱<?= number_format($s['max_net'],2) ?></td>
                                    <td class="text-end">₱<?= number_format($s['min_net'],2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 3: Window Function Rankings -->
        <div class="tab-pane fade" id="tab-rank">
            <div class="card">
                <div class="card-header">
                    Pay Rankings – from <code>vw_payroll_ranking</code>
                    <?php if ($latest): ?>
                    <span style="color:var(--text-muted);font-weight:400;font-size:0.85rem;"> – <?= date('F Y', mktime(0,0,0,$latest['payroll_month'],1,$latest['payroll_year'])) ?> (latest)</span>
                    <?php endif; ?>
                    <small class="float-end" style="color:var(--text-muted);font-size:0.75rem;">Uses RANK(), SUM() OVER PARTITION</small>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Employee</th>
                                    <th>Department</th>
                                    <th class="text-end">Net Pay</th>
                                    <th class="text-end">Dept Total Net</th>
                                    <th class="text-end">% of Payroll</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($ranking)): ?>
                                <tr><td colspan="6" class="text-center py-4" style="color:var(--text-muted)">Run payroll first to see rankings.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($ranking as $r): ?>
                                <tr>
                                    <td>
                                        <?php if ($r['pay_rank'] == 1): ?>
                                            <span style="color:#ffd700;font-weight:700;">🥇 <?= $r['pay_rank'] ?></span>
                                        <?php elseif ($r['pay_rank'] == 2): ?>
                                            <span style="color:#c0c0c0;font-weight:700;">🥈 <?= $r['pay_rank'] ?></span>
                                        <?php elseif ($r['pay_rank'] == 3): ?>
                                            <span style="color:#cd7f32;font-weight:700;">🥉 <?= $r['pay_rank'] ?></span>
                                        <?php else: ?>
                                            <span style="color:var(--text-muted);">#<?= $r['pay_rank'] ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?= htmlspecialchars($r['employee_name']) ?></strong></td>
                                    <td><?= htmlspecialchars($r['department_name']) ?></td>
                                    <td class="text-end" style="color:var(--success);font-weight:600;">₱<?= number_format($r['net_pay'],2) ?></td>
                                    <td class="text-end">₱<?= number_format($r['dept_total_net'],2) ?></td>
                                    <td class="text-end">
                                        <span style="color:var(--accent)"><?= $r['pct_of_total'] ?>%</span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
