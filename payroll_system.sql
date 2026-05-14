-- ============================================================
-- IT221 – Information Management | Final Term PIT
-- Payroll System – Full Database Script
-- ============================================================

CREATE DATABASE IF NOT EXISTS payroll_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE payroll_system;

-- ============================================================
-- SECTION 1: CORE TABLES (with Referential Integrity)
-- ============================================================

-- Departments
CREATE TABLE IF NOT EXISTS departments (
    department_id   INT AUTO_INCREMENT PRIMARY KEY,
    department_name VARCHAR(100) NOT NULL UNIQUE,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Positions / Job Titles
CREATE TABLE IF NOT EXISTS positions (
    position_id    INT AUTO_INCREMENT PRIMARY KEY,
    position_title VARCHAR(100) NOT NULL,
    base_salary    DECIMAL(12,2) NOT NULL CHECK (base_salary >= 0),
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Employees (with version column for optimistic concurrency control)
CREATE TABLE IF NOT EXISTS employees (
    employee_id   INT AUTO_INCREMENT PRIMARY KEY,
    first_name    VARCHAR(60) NOT NULL,
    last_name     VARCHAR(60) NOT NULL,
    email         VARCHAR(150) NOT NULL UNIQUE,
    phone         VARCHAR(20),
    department_id INT NOT NULL,
    position_id   INT NOT NULL,
    hire_date     DATE NOT NULL,
    status        ENUM('Active','Inactive') DEFAULT 'Active',
    version       INT NOT NULL DEFAULT 0,           -- optimistic locking
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_emp_dept FOREIGN KEY (department_id)
        REFERENCES departments(department_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_emp_pos  FOREIGN KEY (position_id)
        REFERENCES positions(position_id)   ON UPDATE CASCADE ON DELETE RESTRICT
);

-- Allowances / Deduction types
CREATE TABLE IF NOT EXISTS pay_components (
    component_id   INT AUTO_INCREMENT PRIMARY KEY,
    component_name VARCHAR(80) NOT NULL,
    component_type ENUM('Allowance','Deduction') NOT NULL,
    default_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00
);

-- Payroll Records (fact table for OLTP)
CREATE TABLE IF NOT EXISTS payroll_records (
    payroll_id      INT AUTO_INCREMENT PRIMARY KEY,
    employee_id     INT NOT NULL,
    payroll_month   TINYINT NOT NULL CHECK (payroll_month BETWEEN 1 AND 12),
    payroll_year    SMALLINT NOT NULL,
    basic_salary    DECIMAL(12,2) NOT NULL,
    total_allowance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_deduction DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    gross_pay       DECIMAL(12,2) GENERATED ALWAYS AS (basic_salary + total_allowance) STORED,
    net_pay         DECIMAL(12,2) GENERATED ALWAYS AS (basic_salary + total_allowance - total_deduction) STORED,
    processed_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_pay_emp FOREIGN KEY (employee_id)
        REFERENCES employees(employee_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    UNIQUE KEY uq_payroll_period (employee_id, payroll_month, payroll_year)
);

-- ============================================================
-- SECTION 2: DATA WAREHOUSE – STAR SCHEMA
-- ============================================================

-- Dimension: Date
CREATE TABLE IF NOT EXISTS dim_date (
    date_key    INT PRIMARY KEY,          -- YYYYMM
    year        SMALLINT NOT NULL,
    month       TINYINT  NOT NULL,
    quarter     TINYINT  NOT NULL,
    month_name  VARCHAR(20) NOT NULL
);

-- Dimension: Employee (snapshot for historical accuracy)
CREATE TABLE IF NOT EXISTS dim_employee (
    emp_key       INT AUTO_INCREMENT PRIMARY KEY,
    employee_id   INT NOT NULL,
    full_name     VARCHAR(130) NOT NULL,
    department    VARCHAR(100) NOT NULL,
    position      VARCHAR(100) NOT NULL,
    snapshot_date DATE NOT NULL
);

-- Dimension: Department
CREATE TABLE IF NOT EXISTS dim_department (
    dept_key        INT AUTO_INCREMENT PRIMARY KEY,
    department_id   INT NOT NULL,
    department_name VARCHAR(100) NOT NULL,
    snapshot_date   DATE NOT NULL
);

-- Fact: Payroll
CREATE TABLE IF NOT EXISTS fact_payroll (
    fact_id         INT AUTO_INCREMENT PRIMARY KEY,
    date_key        INT NOT NULL,
    emp_key         INT NOT NULL,
    dept_key        INT NOT NULL,
    employee_id     INT NOT NULL,
    basic_salary    DECIMAL(12,2) NOT NULL,
    total_allowance DECIMAL(12,2) NOT NULL,
    total_deduction DECIMAL(12,2) NOT NULL,
    gross_pay       DECIMAL(12,2) NOT NULL,
    net_pay         DECIMAL(12,2) NOT NULL,
    etl_loaded_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_fact_date FOREIGN KEY (date_key)  REFERENCES dim_date(date_key),
    CONSTRAINT fk_fact_emp  FOREIGN KEY (emp_key)   REFERENCES dim_employee(emp_key),
    CONSTRAINT fk_fact_dept FOREIGN KEY (dept_key)  REFERENCES dim_department(dept_key)
);

-- ============================================================
-- SECTION 3: VIEWS (Advanced SQL)
-- ============================================================

-- View 1: Full payroll detail with JOINs
CREATE OR REPLACE VIEW vw_payroll_detail AS
SELECT
    pr.payroll_id,
    pr.payroll_year,
    pr.payroll_month,
    CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
    e.email,
    d.department_name,
    p.position_title,
    pr.basic_salary,
    pr.total_allowance,
    pr.total_deduction,
    pr.gross_pay,
    pr.net_pay,
    pr.processed_at
FROM payroll_records pr
JOIN employees   e ON pr.employee_id   = e.employee_id
JOIN departments d ON e.department_id  = d.department_id
JOIN positions   p ON e.position_id    = p.position_id;

-- View 2: Department payroll summary (data mart)
CREATE OR REPLACE VIEW vw_dept_payroll_summary AS
SELECT
    d.department_name,
    pr.payroll_year,
    pr.payroll_month,
    COUNT(pr.payroll_id)    AS employee_count,
    SUM(pr.gross_pay)       AS total_gross,
    SUM(pr.net_pay)         AS total_net,
    AVG(pr.net_pay)         AS avg_net,
    MAX(pr.net_pay)         AS max_net,
    MIN(pr.net_pay)         AS min_net
FROM payroll_records pr
JOIN employees   e ON pr.employee_id  = e.employee_id
JOIN departments d ON e.department_id = d.department_id
GROUP BY d.department_name, pr.payroll_year, pr.payroll_month;

-- View 3: Employee payroll ranking using WINDOW FUNCTION
CREATE OR REPLACE VIEW vw_payroll_ranking AS
SELECT
    pr.payroll_year,
    pr.payroll_month,
    CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
    d.department_name,
    pr.net_pay,
    RANK() OVER (
        PARTITION BY pr.payroll_year, pr.payroll_month
        ORDER BY pr.net_pay DESC
    ) AS pay_rank,
    SUM(pr.net_pay) OVER (
        PARTITION BY pr.payroll_year, pr.payroll_month, d.department_id
    ) AS dept_total_net,
    ROUND(pr.net_pay / SUM(pr.net_pay) OVER (
        PARTITION BY pr.payroll_year, pr.payroll_month
    ) * 100, 2) AS pct_of_total
FROM payroll_records pr
JOIN employees   e ON pr.employee_id  = e.employee_id
JOIN departments d ON e.department_id = d.department_id;

-- ============================================================
-- SECTION 4: STORED PROCEDURE – ETL
-- ============================================================

DELIMITER $$

CREATE PROCEDURE sp_run_etl()
BEGIN
    DECLARE v_date_key INT;
    DECLARE v_year     SMALLINT;
    DECLARE v_month    TINYINT;
    DECLARE v_quarter  TINYINT;
    DECLARE v_month_name VARCHAR(20);
    DECLARE v_emp_key  INT;
    DECLARE v_dept_key INT;

    -- Cursor variables
    DECLARE done INT DEFAULT FALSE;
    DECLARE v_payroll_id   INT;
    DECLARE v_employee_id  INT;
    DECLARE v_payroll_year SMALLINT;
    DECLARE v_payroll_month TINYINT;
    DECLARE v_basic        DECIMAL(12,2);
    DECLARE v_allowance    DECIMAL(12,2);
    DECLARE v_deduction    DECIMAL(12,2);
    DECLARE v_gross        DECIMAL(12,2);
    DECLARE v_net          DECIMAL(12,2);
    DECLARE v_dept_id      INT;
    DECLARE v_full_name    VARCHAR(130);
    DECLARE v_dept_name    VARCHAR(100);
    DECLARE v_pos_title    VARCHAR(100);

    -- Cursor over payroll records not yet loaded into fact table
    DECLARE cur CURSOR FOR
        SELECT pr.payroll_id, pr.employee_id, pr.payroll_year, pr.payroll_month,
               pr.basic_salary, pr.total_allowance, pr.total_deduction,
               pr.gross_pay, pr.net_pay,
               e.department_id,
               CONCAT(e.first_name,' ',e.last_name),
               d.department_name,
               p.position_title
        FROM payroll_records pr
        JOIN employees   e ON pr.employee_id  = e.employee_id
        JOIN departments d ON e.department_id = d.department_id
        JOIN positions   p ON e.position_id   = p.position_id
        WHERE pr.payroll_id NOT IN (SELECT employee_id FROM fact_payroll WHERE employee_id = pr.employee_id AND date_key = pr.payroll_year * 100 + pr.payroll_month);

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

    START TRANSACTION;

    -- Re-load everything for simplicity (truncate fact + dims first)
    DELETE FROM fact_payroll;
    DELETE FROM dim_employee;
    DELETE FROM dim_department;
    DELETE FROM dim_date;

    -- Populate dim_date
    INSERT INTO dim_date (date_key, year, month, quarter, month_name)
    SELECT DISTINCT
        payroll_year * 100 + payroll_month,
        payroll_year,
        payroll_month,
        CEIL(payroll_month / 3),
        MONTHNAME(STR_TO_DATE(payroll_month, '%m'))
    FROM payroll_records;

    -- Populate dim_department
    INSERT INTO dim_department (department_id, department_name, snapshot_date)
    SELECT department_id, department_name, CURDATE()
    FROM departments;

    -- Populate dim_employee
    INSERT INTO dim_employee (employee_id, full_name, department, position, snapshot_date)
    SELECT e.employee_id,
           CONCAT(e.first_name,' ',e.last_name),
           d.department_name,
           p.position_title,
           CURDATE()
    FROM employees e
    JOIN departments d ON e.department_id = d.department_id
    JOIN positions   p ON e.position_id   = p.position_id;

    -- Populate fact_payroll
    INSERT INTO fact_payroll (date_key, emp_key, dept_key, employee_id,
                               basic_salary, total_allowance, total_deduction,
                               gross_pay, net_pay)
    SELECT
        pr.payroll_year * 100 + pr.payroll_month AS date_key,
        de.emp_key,
        dd.dept_key,
        pr.employee_id,
        pr.basic_salary,
        pr.total_allowance,
        pr.total_deduction,
        pr.gross_pay,
        pr.net_pay
    FROM payroll_records pr
    JOIN dim_employee   de ON de.employee_id  = pr.employee_id
    JOIN employees       e ON e.employee_id   = pr.employee_id
    JOIN dim_department dd ON dd.department_id = e.department_id;

    COMMIT;
END$$

DELIMITER ;

-- ============================================================
-- SECTION 5: SEED DATA
-- ============================================================

INSERT INTO departments (department_name) VALUES
('Human Resources'),('Information Technology'),('Finance'),('Operations'),('Marketing');

INSERT INTO positions (position_title, base_salary) VALUES
('HR Manager',        55000.00),
('HR Specialist',     35000.00),
('Software Engineer', 65000.00),
('Junior Developer',  42000.00),
('Accountant',        48000.00),
('Finance Manager',   60000.00),
('Operations Lead',   50000.00),
('Marketing Analyst', 40000.00);

INSERT INTO pay_components (component_name, component_type, default_amount) VALUES
('Transportation Allowance', 'Allowance', 2000.00),
('Meal Allowance',           'Allowance', 1500.00),
('PhilHealth',               'Deduction', 1000.00),
('SSS',                      'Deduction', 1200.00),
('Pag-IBIG',                 'Deduction',  200.00),
('Income Tax',               'Deduction', 2500.00);

INSERT INTO employees (first_name, last_name, email, phone, department_id, position_id, hire_date) VALUES
('Juan',     'Dela Cruz',  'juan.delacruz@company.com',  '09171234567', 1, 1, '2020-03-15'),
('Maria',    'Santos',     'maria.santos@company.com',   '09182345678', 1, 2, '2021-06-01'),
('Jose',     'Reyes',      'jose.reyes@company.com',     '09193456789', 2, 3, '2019-11-20'),
('Ana',      'Garcia',     'ana.garcia@company.com',     '09204567890', 2, 4, '2022-01-10'),
('Carlos',   'Mendoza',    'carlos.mendoza@company.com', '09215678901', 3, 5, '2020-08-05'),
('Luisa',    'Torres',     'luisa.torres@company.com',   '09226789012', 3, 6, '2018-04-22'),
('Roberto',  'Flores',     'roberto.flores@company.com', '09237890123', 4, 7, '2021-09-14'),
('Carla',    'Ramos',      'carla.ramos@company.com',    '09248901234', 5, 8, '2023-02-28');

-- ============================================================
-- SECTION 6: USER ACCESS & AUDIT TRAIL (Module 5)
-- ============================================================

-- Users table (Admin / Staff roles)
CREATE TABLE IF NOT EXISTS users (
    user_id     INT AUTO_INCREMENT PRIMARY KEY,
    username    VARCHAR(80) NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,       -- bcrypt hash
    full_name   VARCHAR(130) NOT NULL,
    role        ENUM('Admin','Staff') NOT NULL DEFAULT 'Staff',
    status      ENUM('Active','Inactive') DEFAULT 'Active',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Audit log table
CREATE TABLE IF NOT EXISTS audit_log (
    log_id      INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT,
    username    VARCHAR(80),
    action      VARCHAR(100) NOT NULL,
    target      VARCHAR(100),
    detail      TEXT,
    ip_address  VARCHAR(45),
    logged_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_audit_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE SET NULL ON UPDATE CASCADE
);

-- View: audit log with user details
CREATE OR REPLACE VIEW vw_audit_log AS
SELECT al.log_id, al.username, u.role, al.action, al.target,
       al.detail, al.ip_address, al.logged_at
FROM audit_log al
LEFT JOIN users u ON al.user_id = u.user_id
ORDER BY al.logged_at DESC;

-- Seed: default admin account (password: Admin@1234)
INSERT INTO users (username, password, full_name, role) VALUES
('admin', '$2y$10$2Mz4ytO1/9ZSonIpfTDcOe3kkYgTzCjEMqCre0fcRV4zvuQClg9zu', 'System Administrator', 'Admin'),
('staff1', '$2y$10$2Mz4ytO1/9ZSonIpfTDcOe3kkYgTzCjEMqCre0fcRV4zvuQClg9zu', 'Staff Member One', 'Staff')
ON DUPLICATE KEY UPDATE user_id = user_id;

-- ============================================================
-- SECTION 7: ROLE UPDATE — Admin > Manager > Employee
-- Run this block after the initial import to update roles
-- ============================================================

-- 1. Update ENUM to include new roles
ALTER TABLE users MODIFY COLUMN role ENUM('Admin','Manager','Employee') NOT NULL DEFAULT 'Employee';

-- 2. Rename old 'Staff' to 'Manager' if any exist
UPDATE users SET role = 'Manager' WHERE role = 'Staff';

-- 3. Add seed Employee user (password: password)
INSERT INTO users (username, password, full_name, role) VALUES
('employee1', '$2y$10$2Mz4ytO1/9ZSonIpfTDcOe3kkYgTzCjEMqCre0fcRV4zvuQClg9zu', 'Sample Employee', 'Employee')
ON DUPLICATE KEY UPDATE user_id = user_id;

-- 4. Update manager seed (rename staff1 → manager1)
UPDATE users SET username='manager1', full_name='Sample Manager', role='Manager'
WHERE username='staff1';

-- ============================================================
-- SECTION 8: EMPLOYEE USER ACCOUNTS (linked by email)
-- + CURRENCY PREFERENCE + BENEFITS TABLE
-- ============================================================

-- Add currency preference to users
ALTER TABLE users ADD COLUMN IF NOT EXISTS currency ENUM('PHP','USD') NOT NULL DEFAULT 'PHP';

-- Benefits / pay components view per employee (for Benefits page)
CREATE OR REPLACE VIEW vw_employee_benefits AS
SELECT
    e.employee_id,
    CONCAT(e.first_name,' ',e.last_name) AS employee_name,
    e.email,
    p.base_salary,
    pc.component_name,
    pc.component_type,
    pc.default_amount,
    CASE pc.component_type
        WHEN 'Allowance' THEN 'Benefit / Addition'
        WHEN 'Deduction' THEN 'Statutory Deduction'
    END AS benefit_label
FROM employees e
JOIN positions p ON e.position_id = p.position_id
CROSS JOIN pay_components pc
WHERE e.status = 'Active'
ORDER BY e.employee_id, pc.component_type DESC, pc.component_name;

-- Attendance / days worked table
CREATE TABLE IF NOT EXISTS attendance (
    attendance_id   INT AUTO_INCREMENT PRIMARY KEY,
    employee_id     INT NOT NULL,
    attendance_month TINYINT NOT NULL CHECK (attendance_month BETWEEN 1 AND 12),
    attendance_year  SMALLINT NOT NULL,
    days_worked     TINYINT NOT NULL DEFAULT 0,
    days_absent     TINYINT NOT NULL DEFAULT 0,
    days_present    TINYINT GENERATED ALWAYS AS (days_worked - days_absent) STORED,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_att_emp FOREIGN KEY (employee_id)
        REFERENCES employees(employee_id) ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE KEY uq_attendance (employee_id, attendance_month, attendance_year)
);

-- Seed: sample attendance for existing employees (April + May 2025)
INSERT IGNORE INTO attendance (employee_id, attendance_month, attendance_year, days_worked, days_absent)
SELECT employee_id, 4, 2025, 22, 0 FROM employees;
INSERT IGNORE INTO attendance (employee_id, attendance_month, attendance_year, days_worked, days_absent)
SELECT employee_id, 5, 2025, 23, 1 FROM employees;

-- Employee user accounts (username = employee email, password = password)
INSERT INTO users (username, password, full_name, role)
SELECT
    e.email,
    '$2y$10$2Mz4ytO1/9ZSonIpfTDcOe3kkYgTzCjEMqCre0fcRV4zvuQClg9zu',
    CONCAT(e.first_name, ' ', e.last_name),
    'Employee'
FROM employees e
WHERE NOT EXISTS (
    SELECT 1 FROM users u WHERE u.username = e.email
);

-- Manager user account
INSERT INTO users (username, password, full_name, role)
VALUES ('manager1','$2y$10$2Mz4ytO1/9ZSonIpfTDcOe3kkYgTzCjEMqCre0fcRV4zvuQClg9zu','Sample Manager','Manager')
ON DUPLICATE KEY UPDATE role='Manager';
