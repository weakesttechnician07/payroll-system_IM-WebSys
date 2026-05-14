<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
requireLogin();
$page_title = 'Dashboard';
$db  = getDB();
$cu  = currentUser();

if (isEmployee()) {
    $empStmt = $db->prepare("SELECT e.*,d.department_name,p.position_title,p.base_salary FROM employees e JOIN departments d ON e.department_id=d.department_id JOIN positions p ON e.position_id=p.position_id WHERE e.email=? AND e.status='Active' LIMIT 1");
    $empStmt->execute([$cu['username']]);
    $myEmp = $empStmt->fetch();
    $latestPay = null;
    if ($myEmp) {
        $ps=$db->prepare("SELECT * FROM payroll_records WHERE employee_id=? ORDER BY payroll_year DESC,payroll_month DESC LIMIT 1"); $ps->execute([$myEmp['employee_id']]); $latestPay=$ps->fetch();
    }
    $as=$db->prepare("SELECT * FROM attendance WHERE employee_id=? ORDER BY attendance_year DESC,attendance_month DESC LIMIT 1"); $as->execute([$myEmp['employee_id']??0]); $myAtt=$as->fetch();
    $tc=$db->prepare("SELECT COUNT(*) FROM payroll_records WHERE employee_id=?"); $tc->execute([$myEmp['employee_id']??0]); $totalPayCount=$tc->fetchColumn();
    $benefits=$db->query("SELECT component_name,component_type,default_amount FROM pay_components ORDER BY component_type DESC,component_name")->fetchAll();
    $totalAllow=array_sum(array_column(array_filter($benefits,fn($b)=>$b['component_type']==='Allowance'),'default_amount'));
    $totalDeduct=array_sum(array_column(array_filter($benefits,fn($b)=>$b['component_type']==='Deduction'),'default_amount'));
    $rp=$db->prepare("SELECT * FROM vw_payroll_detail WHERE email=? ORDER BY payroll_year DESC,payroll_month DESC LIMIT 5"); $rp->execute([$cu['username']]); $recentPay=$rp->fetchAll();
} else {
    $total_employees=$db->query("SELECT COUNT(*) FROM employees WHERE status='Active'")->fetchColumn();
    $total_records=$db->query("SELECT COUNT(*) FROM payroll_records")->fetchColumn();
    $total_paid=$db->query("SELECT COALESCE(SUM(net_pay),0) FROM payroll_records")->fetchColumn();
    $last_payroll=$db->query("SELECT MAX(processed_at) FROM payroll_records")->fetchColumn();
    $dept_stats=$db->query("SELECT d.department_name,COUNT(e.employee_id) AS emp_count,(SELECT COUNT(*) FROM payroll_records pr JOIN employees e2 ON pr.employee_id=e2.employee_id WHERE e2.department_id=d.department_id) AS payroll_count FROM departments d LEFT JOIN employees e ON e.department_id=d.department_id AND e.status='Active' GROUP BY d.department_id,d.department_name ORDER BY emp_count DESC")->fetchAll();
    $recent=$db->query("SELECT * FROM vw_payroll_detail ORDER BY processed_at DESC LIMIT 5")->fetchAll();
}
require_once 'includes/header.php';
?>
<div class="main-content">
<?php if (isEmployee()): ?>
<div class="page-header">
    <h1><i class="bi bi-speedometer2 me-2 text-accent"></i>Welcome, <?= htmlspecialchars(explode(' ',$cu['full_name'])[0]) ?>!</h1>
    <p><?= $myEmp ? htmlspecialchars($myEmp['position_title'].' — '.$myEmp['department_name']) : '<span style="color:var(--warning);">⚠ No employee record linked. Contact Admin.</span>' ?></p>
</div>
<?php if ($myEmp): ?>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-cash-stack"></i></div>
            <div class="stat-value" style="font-size:1.25rem;"><?= $latestPay ? formatMoney((float)$latestPay['net_pay']) : '—' ?></div>
            <div class="stat-label">Latest Net Pay</div>
            <?php if($latestPay): ?><div style="font-size:0.72rem;color:var(--text-muted);margin-top:4px;"><?= date('F Y',mktime(0,0,0,$latestPay['payroll_month'],1,$latestPay['payroll_year'])) ?></div><?php endif; ?>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card" style="--accent:#4a9eff;">
            <div class="stat-icon" style="background:rgba(74,158,255,0.15);color:#4a9eff;"><i class="bi bi-calendar-check"></i></div>
            <div class="stat-value"><?= $myAtt ? $myAtt['days_worked'] : '—' ?></div>
            <div class="stat-label">Days Worked</div>
            <?php if($myAtt): ?><div style="font-size:0.72rem;color:var(--text-muted);margin-top:4px;"><?= date('F Y',mktime(0,0,0,$myAtt['attendance_month'],1,$myAtt['attendance_year'])) ?></div><?php endif; ?>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card" style="--accent:#e74c3c;">
            <div class="stat-icon" style="background:rgba(231,76,60,0.15);color:#e74c3c;"><i class="bi bi-calendar-x"></i></div>
            <div class="stat-value"><?= $myAtt ? $myAtt['days_absent'] : '—' ?></div>
            <div class="stat-label">Absences</div>
            <?php if($myAtt): ?><div style="font-size:0.72rem;color:var(--text-muted);margin-top:4px;"><?= $myAtt['days_present'] ?> days present</div><?php endif; ?>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card" style="--accent:#2ecc71;">
            <div class="stat-icon" style="background:rgba(46,204,113,0.15);color:#2ecc71;"><i class="bi bi-receipt"></i></div>
            <div class="stat-value"><?= $totalPayCount ?></div>
            <div class="stat-label">Total Payroll Records</div>
        </div>
    </div>
</div>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-gift me-2 text-accent"></i>Pay Components <a href="benefits.php" style="float:right;font-size:0.75rem;color:var(--accent);">View all →</a></div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead><tr><th>Component</th><th>Type</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                        <?php foreach($benefits as $b): ?>
                        <tr>
                            <td><?= htmlspecialchars($b['component_name']) ?></td>
                            <td><span style="color:<?= $b['component_type']==='Allowance'?'var(--success)':'var(--danger)' ?>;font-size:0.78rem;"><?= $b['component_type']==='Allowance'?'+ Allow':'- Deduct' ?></span></td>
                            <td class="text-end" style="color:<?= $b['component_type']==='Allowance'?'var(--success)':'var(--danger)' ?>;"><?= $b['component_type']==='Allowance'?'+':'-' ?><?= formatMoney((float)$b['default_amount']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="background:rgba(15,52,96,0.3);">
                            <td colspan="2" style="font-weight:600;">Net Adjustment</td>
                            <td class="text-end" style="font-weight:700;color:<?= ($totalAllow-$totalDeduct)>=0?'var(--success)':'var(--danger)' ?>;"><?= ($totalAllow-$totalDeduct)>=0?'+':'' ?><?= formatMoney($totalAllow-$totalDeduct) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-clock-history me-2 text-accent"></i>My Recent Payroll <a href="history.php" style="float:right;font-size:0.75rem;color:var(--accent);">Full history →</a></div>
            <div class="card-body p-0">
                <?php if(empty($recentPay)): ?>
                <div class="p-4 text-center" style="color:var(--text-muted)"><i class="bi bi-inbox" style="font-size:2rem;"></i><p class="mt-2">No payroll records yet.</p></div>
                <?php else: ?>
                <table class="table mb-0">
                    <thead><tr><th>Period</th><th class="text-end">Basic</th><th class="text-end">Gross</th><th class="text-end">Net Pay</th></tr></thead>
                    <tbody>
                        <?php foreach($recentPay as $r): ?>
                        <tr>
                            <td><?= date('F Y',mktime(0,0,0,$r['payroll_month'],1,$r['payroll_year'])) ?></td>
                            <td class="text-end"><?= formatMoney((float)$r['basic_salary']) ?></td>
                            <td class="text-end"><?= formatMoney((float)$r['gross_pay']) ?></td>
                            <td class="text-end" style="color:var(--success);font-weight:600;"><?= formatMoney((float)$r['net_pay']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php else: ?>
<div class="page-header"><h1><i class="bi bi-speedometer2 me-2 text-accent"></i>Dashboard</h1><p>Overview of your payroll system activity</p></div>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon"><i class="bi bi-people-fill"></i></div><div class="stat-value"><?= number_format($total_employees) ?></div><div class="stat-label">Active Employees</div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="stat-card" style="--accent:#0f3460;"><div class="stat-icon" style="background:rgba(15,52,96,0.3);color:#4a9eff;"><i class="bi bi-receipt"></i></div><div class="stat-value"><?= number_format($total_records) ?></div><div class="stat-label">Payroll Records</div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="stat-card" style="--accent:#2ecc71;"><div class="stat-icon" style="background:rgba(46,204,113,0.15);color:#2ecc71;"><i class="bi bi-cash-stack"></i></div><div class="stat-value" style="font-size:1.2rem;"><?= formatMoney((float)$total_paid) ?></div><div class="stat-label">Total Net Pay</div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="stat-card" style="--accent:#f39c12;"><div class="stat-icon" style="background:rgba(243,156,18,0.15);color:#f39c12;"><i class="bi bi-calendar-check"></i></div><div class="stat-value" style="font-size:1.1rem;margin-top:6px;"><?= $last_payroll ? date('M d, Y',strtotime($last_payroll)) : 'No records' ?></div><div class="stat-label">Last Payroll Run</div></div></div>
</div>
<div class="row g-4">
    <div class="col-lg-5"><div class="card h-100"><div class="card-header"><i class="bi bi-building me-2 text-accent"></i>Department Breakdown</div><div class="card-body p-0"><table class="table mb-0"><thead><tr><th>Department</th><th class="text-end">Employees</th><th class="text-end">Payroll Runs</th></tr></thead><tbody><?php foreach($dept_stats as $d): ?><tr><td><?= htmlspecialchars($d['department_name']) ?></td><td class="text-end"><?= $d['emp_count'] ?></td><td class="text-end"><?= $d['payroll_count'] ?></td></tr><?php endforeach; ?></tbody></table></div></div></div>
    <div class="col-lg-7"><div class="card h-100"><div class="card-header"><i class="bi bi-clock-history me-2 text-accent"></i>Recent Payroll Records</div><div class="card-body p-0">
        <?php if(empty($recent)): ?><div class="p-4 text-center" style="color:var(--text-muted)"><i class="bi bi-inbox" style="font-size:2rem;"></i><p class="mt-2">No records yet.<?= isAdmin()?' <a href="process.php" class="text-accent">Process payroll →</a>':'' ?></p></div>
        <?php else: ?><table class="table mb-0"><thead><tr><th>Employee</th><th>Dept</th><th>Period</th><th class="text-end">Net Pay</th></tr></thead><tbody><?php foreach($recent as $r): ?><tr><td><?= htmlspecialchars($r['employee_name']) ?></td><td style="color:var(--text-muted)"><?= htmlspecialchars($r['department_name']) ?></td><td><?= date('M Y',mktime(0,0,0,$r['payroll_month'],1,$r['payroll_year'])) ?></td><td class="text-end" style="color:var(--success);font-weight:600;"><?= formatMoney((float)$r['net_pay']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
    </div></div></div>
</div>
<?php endif; ?>
</div>
<?php require_once 'includes/footer.php'; ?>
