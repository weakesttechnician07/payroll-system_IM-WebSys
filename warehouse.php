<?php
// ============================================================
// Data Warehouse – warehouse.php
// Demonstrates:
//   • Star Schema (fact_payroll + dim_date + dim_employee + dim_department)
//   • ETL via stored procedure sp_run_etl()
//   • Data Mart view (vw_dept_payroll_summary)
// ============================================================
require_once 'includes/db.php';
require_once 'includes/auth.php';
requireAdmin();   // Only Admins can run ETL (Manager + Employee denied)
$page_title = 'Data Warehouse';
$db = getDB();

$msg = '';
$msg_type = '';

// ── Run ETL ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_etl') {
    try {
        $db->exec("CALL sp_run_etl()");
        auditLog('Run ETL', 'fact_payroll', 'ETL stored procedure executed');
        $msg = 'ETL completed successfully. Fact and dimension tables have been refreshed.';
        $msg_type = 'success';
    } catch (PDOException $e) {
        $msg = 'ETL Error: ' . $e->getMessage();
        $msg_type = 'danger';
    }
}

// ── Load Warehouse Data ──────────────────────────────────────
// Fact table summary
$fact_count = $db->query("SELECT COUNT(*) FROM fact_payroll")->fetchColumn();
$fact_total = $db->query("SELECT COALESCE(SUM(net_pay),0) FROM fact_payroll")->fetchColumn();

// Dimension counts
$dim_emp_count  = $db->query("SELECT COUNT(*) FROM dim_employee")->fetchColumn();
$dim_dept_count = $db->query("SELECT COUNT(*) FROM dim_department")->fetchColumn();
$dim_date_count = $db->query("SELECT COUNT(*) FROM dim_date")->fetchColumn();

// Fact table with dimension joins
$fact_data = $db->query("
    SELECT fp.fact_id,
           dd.month_name, dd.year, dd.quarter,
           de.full_name, de.department, de.position,
           fp.basic_salary, fp.total_allowance, fp.total_deduction,
           fp.gross_pay, fp.net_pay,
           fp.etl_loaded_at
    FROM fact_payroll fp
    JOIN dim_date       dd ON fp.date_key = dd.date_key
    JOIN dim_employee   de ON fp.emp_key  = de.emp_key
    JOIN dim_department dm ON fp.dept_key = dm.dept_key
    ORDER BY dd.year DESC, dd.month DESC, de.department, de.full_name
    LIMIT 50
")->fetchAll();

// Data mart – department summary from view
$mart_data = $db->query("
    SELECT department_name, payroll_year, payroll_month,
           employee_count, total_gross, total_net, avg_net
    FROM vw_dept_payroll_summary
    ORDER BY payroll_year DESC, payroll_month DESC, department_name
")->fetchAll();

// Quarterly aggregation (advanced SQL on fact table)
$quarterly = $db->query("
    SELECT dd.year, dd.quarter,
           COUNT(fp.fact_id)  AS record_count,
           SUM(fp.net_pay)    AS total_net,
           AVG(fp.net_pay)    AS avg_net
    FROM fact_payroll fp
    JOIN dim_date dd ON fp.date_key = dd.date_key
    GROUP BY dd.year, dd.quarter
    ORDER BY dd.year DESC, dd.quarter DESC
")->fetchAll();

require_once 'includes/header.php';
?>

<div class="main-content">
    <div class="page-header d-flex justify-content-between align-items-start">
        <div>
            <h1><i class="bi bi-database me-2 text-accent"></i>Data Warehouse</h1>
            <p>Star schema: <code>fact_payroll</code> + <code>dim_date</code> + <code>dim_employee</code> + <code>dim_department</code></p>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="run_etl">
            <button type="submit" class="btn-accent" onclick="return confirm('Run ETL? This will reload all dimension and fact tables.')">
                <i class="bi bi-arrow-repeat me-1"></i> Run ETL
            </button>
        </form>
    </div>

    <?php if ($msg): ?>
    <div class="alert-<?= $msg_type === 'success' ? 'success' : 'danger' ?>-dark mb-3">
        <i class="bi bi-<?= $msg_type === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
        <?= htmlspecialchars($msg) ?>
    </div>
    <?php endif; ?>

    <!-- Schema Overview -->
    <div class="card mb-4" style="border-color:rgba(233,69,96,0.3);">
        <div class="card-header"><i class="bi bi-diagram-3 me-2 text-accent"></i>Star Schema Structure</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-sm-6 col-lg-3">
                    <div style="border:1px solid var(--accent);border-radius:8px;padding:14px;text-align:center;">
                        <div style="font-size:1.4rem;margin-bottom:6px;">📊</div>
                        <div style="font-family:'Syne',sans-serif;font-weight:700;color:var(--accent);">fact_payroll</div>
                        <div style="font-size:0.75rem;color:var(--text-muted);margin-top:4px;"><?= number_format($fact_count) ?> rows · FACT TABLE<br>Net total: ₱<?= number_format($fact_total,0) ?></div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div style="border:1px solid var(--border);border-radius:8px;padding:14px;text-align:center;">
                        <div style="font-size:1.4rem;margin-bottom:6px;">👤</div>
                        <div style="font-family:'Syne',sans-serif;font-weight:700;">dim_employee</div>
                        <div style="font-size:0.75rem;color:var(--text-muted);margin-top:4px;"><?= $dim_emp_count ?> rows · DIMENSION<br>Historical snapshot</div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div style="border:1px solid var(--border);border-radius:8px;padding:14px;text-align:center;">
                        <div style="font-size:1.4rem;margin-bottom:6px;">🏢</div>
                        <div style="font-family:'Syne',sans-serif;font-weight:700;">dim_department</div>
                        <div style="font-size:0.75rem;color:var(--text-muted);margin-top:4px;"><?= $dim_dept_count ?> rows · DIMENSION<br>Dept snapshots</div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div style="border:1px solid var(--border);border-radius:8px;padding:14px;text-align:center;">
                        <div style="font-size:1.4rem;margin-bottom:6px;">📅</div>
                        <div style="font-family:'Syne',sans-serif;font-weight:700;">dim_date</div>
                        <div style="font-size:0.75rem;color:var(--text-muted);margin-top:4px;"><?= $dim_date_count ?> rows · DIMENSION<br>Year / Month / Quarter</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-pills mb-4" style="gap:8px;">
        <li><a class="nav-link active" data-bs-toggle="pill" href="#tab-fact" style="background:var(--accent);border-radius:8px;font-size:0.85rem;">Fact Table</a></li>
        <li><a class="nav-link" data-bs-toggle="pill" href="#tab-mart" style="color:var(--text-muted);border-radius:8px;font-size:0.85rem;">Data Mart</a></li>
        <li><a class="nav-link" data-bs-toggle="pill" href="#tab-quarterly" style="color:var(--text-muted);border-radius:8px;font-size:0.85rem;">Quarterly View</a></li>
    </ul>

    <div class="tab-content">
        <!-- Fact Table -->
        <div class="tab-pane fade show active" id="tab-fact">
            <div class="card">
                <div class="card-header"><code>fact_payroll</code> joined with dimensions (up to 50 rows)</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0" style="font-size:0.82rem;">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Employee</th>
                                    <th>Department</th>
                                    <th>Period</th>
                                    <th>Quarter</th>
                                    <th class="text-end">Gross</th>
                                    <th class="text-end">Net Pay</th>
                                    <th>ETL Loaded</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($fact_data)): ?>
                                <tr><td colspan="8" class="text-center py-4" style="color:var(--text-muted)">
                                    No data in warehouse. <a href="process.php" class="text-accent">Process payroll</a> then click <strong>Run ETL</strong>.
                                </td></tr>
                                <?php endif; ?>
                                <?php foreach ($fact_data as $i => $f): ?>
                                <tr>
                                    <td style="color:var(--text-muted)"><?= $f['fact_id'] ?></td>
                                    <td><?= htmlspecialchars($f['full_name']) ?><br><small style="color:var(--text-muted)"><?= htmlspecialchars($f['position']) ?></small></td>
                                    <td><?= htmlspecialchars($f['department']) ?></td>
                                    <td><?= $f['month_name'] ?> <?= $f['year'] ?></td>
                                    <td>Q<?= $f['quarter'] ?></td>
                                    <td class="text-end">₱<?= number_format($f['gross_pay'],2) ?></td>
                                    <td class="text-end" style="color:var(--success);font-weight:600;">₱<?= number_format($f['net_pay'],2) ?></td>
                                    <td style="color:var(--text-muted);font-size:0.75rem;"><?= date('M d H:i', strtotime($f['etl_loaded_at'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Data Mart -->
        <div class="tab-pane fade" id="tab-mart">
            <div class="card">
                <div class="card-header"><code>vw_dept_payroll_summary</code> – Department Data Mart</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>Department</th>
                                    <th>Period</th>
                                    <th class="text-end">Headcount</th>
                                    <th class="text-end">Total Gross</th>
                                    <th class="text-end">Total Net</th>
                                    <th class="text-end">Avg Net</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($mart_data)): ?>
                                <tr><td colspan="6" class="text-center py-4" style="color:var(--text-muted)">Run payroll and ETL first.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($mart_data as $m): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($m['department_name']) ?></strong></td>
                                    <td><?= date('M Y', mktime(0,0,0,$m['payroll_month'],1,$m['payroll_year'])) ?></td>
                                    <td class="text-end"><?= $m['employee_count'] ?></td>
                                    <td class="text-end">₱<?= number_format($m['total_gross'],2) ?></td>
                                    <td class="text-end" style="color:var(--success);font-weight:600;">₱<?= number_format($m['total_net'],2) ?></td>
                                    <td class="text-end">₱<?= number_format($m['avg_net'],2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quarterly Aggregation -->
        <div class="tab-pane fade" id="tab-quarterly">
            <div class="card">
                <div class="card-header">Quarterly Payroll Aggregation – <code>fact_payroll JOIN dim_date GROUP BY quarter</code></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>Year</th>
                                    <th>Quarter</th>
                                    <th class="text-end">Records</th>
                                    <th class="text-end">Total Net Pay</th>
                                    <th class="text-end">Avg Net Pay</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($quarterly)): ?>
                                <tr><td colspan="5" class="text-center py-4" style="color:var(--text-muted)">Run ETL first.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($quarterly as $q): ?>
                                <tr>
                                    <td><?= $q['year'] ?></td>
                                    <td><span style="color:var(--accent);font-weight:600;">Q<?= $q['quarter'] ?></span></td>
                                    <td class="text-end"><?= $q['record_count'] ?></td>
                                    <td class="text-end" style="color:var(--success);font-weight:600;">₱<?= number_format($q['total_net'],2) ?></td>
                                    <td class="text-end">₱<?= number_format($q['avg_net'],2) ?></td>
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
