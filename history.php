<?php
// ============================================================
// Payroll History – history.php
// Admin/Manager: all records + CSV export + Print
// Employee:      own records only + CSV + Print
// ============================================================
require_once 'includes/db.php';
require_once 'includes/auth.php';
requireLogin();
$page_title = 'Payroll History';
$db = getDB();
$cu = currentUser();

$departments = $db->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();
$years = $db->query("SELECT DISTINCT payroll_year FROM payroll_records ORDER BY payroll_year DESC")->fetchAll(PDO::FETCH_COLUMN);

$dept_filter  = isset($_GET['dept'])  ? (int)$_GET['dept']  : 0;
$year_filter  = isset($_GET['year'])  ? (int)$_GET['year']  : 0;
$month_filter = isset($_GET['month']) ? (int)$_GET['month'] : 0;

$where  = ['1=1'];
$params = [];

// Employee: restrict to own records only
if (isEmployee()) {
    $where[]  = "(pd.employee_name = ? OR pd.email = ?)";
    $params[] = $cu['full_name'];
    $params[] = $cu['username'];
}

if (!isEmployee() && $dept_filter) {
    $dname = $db->prepare("SELECT department_name FROM departments WHERE department_id=?");
    $dname->execute([$dept_filter]);
    $dn = $dname->fetchColumn();
    if ($dn) { $where[] = "pd.department_name = ?"; $params[] = $dn; }
}
if ($year_filter)  { $where[] = "pd.payroll_year = ?";  $params[] = $year_filter;  }
if ($month_filter) { $where[] = "pd.payroll_month = ?"; $params[] = $month_filter; }

$sql = "SELECT * FROM vw_payroll_detail pd WHERE ".implode(' AND ',$where)." ORDER BY pd.payroll_year DESC, pd.payroll_month DESC, pd.employee_name";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

// Dept summary (Admin/Manager only)
$dept_summary = [];
$ranking      = [];
if (!isEmployee()) {
    $ds_params = [];
    $ds_where  = ['1=1'];
    if ($year_filter) { $ds_where[] = "payroll_year=?"; $ds_params[] = $year_filter; }
    $ds = $db->prepare("SELECT * FROM vw_dept_payroll_summary WHERE ".implode(' AND ',$ds_where)." ORDER BY payroll_year DESC, payroll_month DESC, department_name");
    $ds->execute($ds_params);
    $dept_summary = $ds->fetchAll();

    $latest = $db->query("SELECT payroll_year, payroll_month FROM payroll_records ORDER BY payroll_year DESC, payroll_month DESC LIMIT 1")->fetch();
    if ($latest) {
        $rk = $db->prepare("SELECT * FROM vw_payroll_ranking WHERE payroll_year=? AND payroll_month=? ORDER BY pay_rank");
        $rk->execute([$latest['payroll_year'], $latest['payroll_month']]);
        $ranking = $rk->fetchAll();
    }
}

require_once 'includes/header.php';
?>
<div class="main-content">
    <div class="page-header d-flex justify-content-between align-items-start">
        <div>
            <h1><i class="bi bi-clock-history me-2 text-accent"></i>
                <?= isEmployee() ? 'My Payroll History' : 'Payroll History' ?>
            </h1>
            <p><?= isEmployee() ? 'Your personal payroll records' : 'All records · dept summaries · pay rankings' ?></p>
        </div>
        <!-- Export buttons -->
        <div class="d-flex gap-2">
            <a href="export_payroll.php?format=csv&<?= http_build_query(array_filter(['dept'=>$dept_filter,'year'=>$year_filter,'month'=>$month_filter])) ?>"
               class="btn-outline-accent" style="text-decoration:none;">
                <i class="bi bi-download me-1"></i> Export CSV
            </a>
            <button class="btn-accent" onclick="printPayroll()">
                <i class="bi bi-printer me-1"></i> Print
            </button>
        </div>
    </div>

    <!-- Filter bar (Admin + Manager only) -->
    <?php if (!isEmployee()): ?>
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-sm-4 col-md-3">
                    <label class="form-label">Department</label>
                    <select name="dept" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['department_id'] ?>" <?= $dept_filter==$d['department_id']?'selected':'' ?>><?= htmlspecialchars($d['department_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-3 col-md-2">
                    <label class="form-label">Year</label>
                    <select name="year" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($years as $y): ?><option value="<?= $y ?>" <?= $year_filter==$y?'selected':'' ?>><?= $y ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-3 col-md-2">
                    <label class="form-label">Month</label>
                    <select name="month" class="form-select">
                        <option value="">All</option>
                        <?php for ($m=1;$m<=12;$m++): ?><option value="<?= $m ?>" <?= $month_filter==$m?'selected':'' ?>><?= date('F',mktime(0,0,0,$m,1)) ?></option><?php endfor; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn-accent">Filter</button>
                    <a href="history.php" class="btn-outline-accent ms-2">Reset</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tabs -->
    <ul class="nav nav-pills mb-4" style="gap:8px;">
        <li><a class="nav-link active" data-bs-toggle="pill" href="#tab-records" style="background:var(--accent);border-radius:8px;font-size:0.85rem;">
            <i class="bi bi-list me-1"></i><?= isEmployee() ? 'My Records' : 'All Records' ?>
        </a></li>
        <?php if (!isEmployee()): ?>
        <li><a class="nav-link" data-bs-toggle="pill" href="#tab-dept" style="color:var(--text-muted);border-radius:8px;font-size:0.85rem;">Dept Summary</a></li>
        <li><a class="nav-link" data-bs-toggle="pill" href="#tab-rank" style="color:var(--text-muted);border-radius:8px;font-size:0.85rem;">Pay Rankings</a></li>
        <?php endif; ?>
    </ul>

    <div class="tab-content">
        <!-- Tab 1: Records -->
        <div class="tab-pane fade show active" id="tab-records">
            <div class="card" id="printArea">
                <div class="card-header">
                    <?= count($records) ?> record(s)
                    <?php if (isEmployee()): ?> — <?= htmlspecialchars($cu['full_name']) ?><?php endif; ?>
                    <span class="float-end" style="font-size:0.78rem;color:var(--text-muted);">from vw_payroll_detail</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <?php if (!isEmployee()): ?><th>Employee</th><th>Dept</th><?php endif; ?>
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
                                <tr><td colspan="9" class="text-center py-4" style="color:var(--text-muted)">No records found.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($records as $r): ?>
                                <tr>
                                    <?php if (!isEmployee()): ?>
                                    <td><strong><?= htmlspecialchars($r['employee_name']) ?></strong></td>
                                    <td style="color:var(--text-muted)"><?= htmlspecialchars($r['department_name']) ?></td>
                                    <?php endif; ?>
                                    <td><?= date('M Y',mktime(0,0,0,$r['payroll_month'],1,$r['payroll_year'])) ?></td>
                                    <td class="text-end"><?= formatMoney((float)$r['basic_salary']) ?></td>
                                    <td class="text-end" style="color:var(--success);">+<?= formatMoney((float)$r['total_allowance']) ?></td>
                                    <td class="text-end" style="color:var(--danger);">-<?= formatMoney((float)$r['total_deduction']) ?></td>
                                    <td class="text-end"><?= formatMoney((float)$r['gross_pay']) ?></td>
                                    <td class="text-end" style="color:var(--success);font-weight:600;"><?= formatMoney((float)$r['net_pay']) ?></td>
                                    <td style="color:var(--text-muted);font-size:0.78rem;"><?= date('M d, Y',strtotime($r['processed_at'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <?php if (!empty($records)): ?>
                            <tfoot>
                                <tr style="background:rgba(15,52,96,0.3);">
                                    <td colspan="<?= isEmployee()?5:7 ?>" class="text-end" style="font-weight:600;">Total Net Pay:</td>
                                    <td class="text-end" style="color:var(--success);font-weight:700;"><?= formatMoney(array_sum(array_column($records,'net_pay'))) ?></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!isEmployee()): ?>
        <!-- Tab 2: Dept Summary -->
        <div class="tab-pane fade" id="tab-dept">
            <div class="card">
                <div class="card-header">Department Summary — vw_dept_payroll_summary</div>
                <div class="card-body p-0">
                    <table class="table mb-0">
                        <thead><tr><th>Department</th><th>Period</th><th class="text-end">Employees</th><th class="text-end">Total Gross</th><th class="text-end">Total Net</th><th class="text-end">Avg Net</th><th class="text-end">Max</th><th class="text-end">Min</th></tr></thead>
                        <tbody>
                            <?php if(empty($dept_summary)): ?><tr><td colspan="8" class="text-center py-4" style="color:var(--text-muted)">No records.</td></tr><?php endif; ?>
                            <?php foreach($dept_summary as $s): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($s['department_name']) ?></strong></td>
                                <td><?= date('M Y',mktime(0,0,0,$s['payroll_month'],1,$s['payroll_year'])) ?></td>
                                <td class="text-end"><?= $s['employee_count'] ?></td>
                                <td class="text-end"><?= formatMoney((float)$s['total_gross']) ?></td>
                                <td class="text-end" style="color:var(--success);font-weight:600;"><?= formatMoney((float)$s['total_net']) ?></td>
                                <td class="text-end"><?= formatMoney((float)$s['avg_net']) ?></td>
                                <td class="text-end"><?= formatMoney((float)$s['max_net']) ?></td>
                                <td class="text-end"><?= formatMoney((float)$s['min_net']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab 3: Pay Rankings -->
        <div class="tab-pane fade" id="tab-rank">
            <div class="card">
                <div class="card-header">Pay Rankings — RANK() OVER · SUM() OVER PARTITION</div>
                <div class="card-body p-0">
                    <table class="table mb-0">
                        <thead><tr><th>Rank</th><th>Employee</th><th>Department</th><th class="text-end">Net Pay</th><th class="text-end">Dept Total</th><th class="text-end">% of Payroll</th></tr></thead>
                        <tbody>
                            <?php if(empty($ranking)): ?><tr><td colspan="6" class="text-center py-4" style="color:var(--text-muted)">Run payroll first.</td></tr><?php endif; ?>
                            <?php foreach($ranking as $r): ?>
                            <tr>
                                <td><?php
                                    if($r['pay_rank']==1) echo '<span style="color:#ffd700;font-weight:700;">🥇 1</span>';
                                    elseif($r['pay_rank']==2) echo '<span style="color:#c0c0c0;font-weight:700;">🥈 2</span>';
                                    elseif($r['pay_rank']==3) echo '<span style="color:#cd7f32;font-weight:700;">🥉 3</span>';
                                    else echo '<span style="color:var(--text-muted);">#'.$r['pay_rank'].'</span>';
                                ?></td>
                                <td><strong><?= htmlspecialchars($r['employee_name']) ?></strong></td>
                                <td><?= htmlspecialchars($r['department_name']) ?></td>
                                <td class="text-end" style="color:var(--success);font-weight:600;"><?= formatMoney((float)$r['net_pay']) ?></td>
                                <td class="text-end"><?= formatMoney((float)$r['dept_total_net']) ?></td>
                                <td class="text-end"><span style="color:var(--accent);"><?= $r['pct_of_total'] ?>%</span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Print styles -->
<style>
@media print {
    .sidebar, .page-header .d-flex > a, .page-header .btn-accent,
    .nav-pills, form, .btn-accent, .btn-outline-accent { display: none !important; }
    .main-content { margin-left: 0 !important; padding: 16px !important; }
    .card { border: 1px solid #ccc !important; }
    body { background: #fff !important; color: #000 !important; }
    .table thead th, .table tbody td { color: #000 !important; border-color: #ccc !important; }
    .stat-value, .stat-label { color: #000 !important; }
}
</style>

<script>
function printPayroll() {
    window.print();
}
</script>

<?php require_once 'includes/footer.php'; ?>
