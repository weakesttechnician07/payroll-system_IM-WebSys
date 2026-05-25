<?php
// ============================================================
// download_csv_template.php
// Streams a blank CSV template for bulk employee import
// ============================================================
require_once 'includes/db.php';
require_once 'includes/auth.php';
requireLogin();
if (isEmployee()) { header('Location: index.php'); exit; }

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="employee_import_template.csv"');

$out = fopen('php://output', 'w');

// Header row
fputcsv($out, ['first_name','last_name','email','phone','department_name','position_title','hire_date']);

// Example rows
fputcsv($out, ['Juan','Dela Cruz','juan.delacruz@company.com','09171234567','Information Technology','Software Engineer','2024-01-15']);
fputcsv($out, ['Maria','Santos','maria.santos@company.com','09182345678','Human Resources','HR Specialist','2024-02-01']);
fputcsv($out, ['Carlos','Reyes','carlos.reyes@company.com','','Finance','Accountant','2024-03-10']);

fclose($out);
exit;
