<?php
// ============================================================
// CSV Export – export_payroll.php
// Streams payroll records as a downloadable CSV file.
// Respects role — Employee sees own records only.
// ============================================================
require_once 'includes/db.php';
require_once 'includes/auth.php';
requireLogin();

$db = getDB();
$cu = currentUser();

$dept_filter  = isset($_GET['dept'])  ? (int)$_GET['dept']  : 0;
$year_filter  = isset($_GET['year'])  ? (int)$_GET['year']  : 0;
$month_filter = isset($_GET['month']) ? (int)$_GET['month'] : 0;

$where  = ['1=1'];
$params = [];

// Employee: own records only
if (isEmployee()) {
    $where[]  = "(pd.employee_name = ? OR pd.email = ?)";
    $params[] = $cu['full_name'];
    $params[] = $cu['username'];
}

if (!isEmployee() && $dept_filter) {
    $dn = $db->prepare("SELECT department_name FROM departments WHERE department_id=?");
    $dn->execute([$dept_filter]);
    $dname = $dn->fetchColumn();
    if ($dname) { $where[] = "pd.department_name = ?"; $params[] = $dname; }
}
if ($year_filter)  { $where[] = "pd.payroll_year = ?";  $params[] = $year_filter; }
if ($month_filter) { $where[] = "pd.payroll_month = ?"; $params[] = $month_filter; }

$sql  = "SELECT * FROM vw_payroll_detail pd WHERE ".implode(' AND ',$where)." ORDER BY pd.payroll_year DESC, pd.payroll_month DESC, pd.employee_name";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

// Build filename
$suffix = isEmployee() ? '_mine' : '_all';
$filename = 'payroll_history'.$suffix.'_'.date('Ymd').'.csv';

// Stream CSV headers
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');

// CSV header row
if (isEmployee()) {
    fputcsv($out, ['Period', 'Basic Salary (PHP)', 'Total Allowance (PHP)', 'Total Deduction (PHP)', 'Gross Pay (PHP)', 'Net Pay (PHP)', 'Processed Date']);
} else {
    fputcsv($out, ['Employee', 'Email', 'Department', 'Position', 'Period', 'Basic Salary (PHP)', 'Total Allowance (PHP)', 'Total Deduction (PHP)', 'Gross Pay (PHP)', 'Net Pay (PHP)', 'Processed Date']);
}

foreach ($records as $r) {
    $period = date('F Y', mktime(0,0,0,$r['payroll_month'],1,$r['payroll_year']));
    $processed = date('Y-m-d', strtotime($r['processed_at']));

    if (isEmployee()) {
        fputcsv($out, [
            $period,
            number_format((float)$r['basic_salary'],    2, '.', ''),
            number_format((float)$r['total_allowance'], 2, '.', ''),
            number_format((float)$r['total_deduction'], 2, '.', ''),
            number_format((float)$r['gross_pay'],       2, '.', ''),
            number_format((float)$r['net_pay'],         2, '.', ''),
            $processed,
        ]);
    } else {
        fputcsv($out, [
            $r['employee_name'],
            $r['email'],
            $r['department_name'],
            $r['position_title'],
            $period,
            number_format((float)$r['basic_salary'],    2, '.', ''),
            number_format((float)$r['total_allowance'], 2, '.', ''),
            number_format((float)$r['total_deduction'], 2, '.', ''),
            number_format((float)$r['gross_pay'],       2, '.', ''),
            number_format((float)$r['net_pay'],         2, '.', ''),
            $processed,
        ]);
    }
}

// Summary totals row
fputcsv($out, []);
if (isEmployee()) {
    fputcsv($out, ['TOTAL', '', '', '', '', number_format(array_sum(array_column($records,'net_pay')),2,'.',''), '']);
} else {
    fputcsv($out, ['TOTAL', '', '', '', '', '', '', '', '', number_format(array_sum(array_column($records,'net_pay')),2,'.',''), '']);
}

fclose($out);
exit;
