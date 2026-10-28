<?php
/**
 * Database upgrades (MySQL 5.5 compatible).
 *
 * index.php calls db_upgrade() automatically when settings.db_version is lower than DB_VERSION,
 * so copying the new files over an existing installation and opening the site is enough.
 * Every step checks information_schema first, so an upgrade can safely run more than once
 * and never drops existing data. database/upgrade_to_v2_mysql55.sql and
 * database/upgrade_to_v3_mysql55.sql are generated from the same definitions for
 * administrators who prefer to run the SQL themselves (php -r or the generator below).
 *
 *   v2  M.A.D.A.S. attendance & payroll integration (setup tables, hour transactions, periods)
 *   v3  Employee's transactions with terms (amortized earnings / deductions), shift log count
 *       (2 / 4 / 6 logs per day), employee CSV import batches
 */
require_once __DIR__ . '/engine/paytrans.php';

const DB_VERSION = 19;

function db_version(): int
{
    try {
        $v = val("SELECT svalue FROM settings WHERE skey = 'db_version'");
    } catch (PDOException $e) {
        return 1;
    }
    return $v === null ? 1 : (int)$v;
}

function pt_fields_ddl(): string
{
    $cols = [];
    foreach (pt_hour_fields() as $f) {
        $cols[] = "  `$f` DECIMAL(9,2) NOT NULL DEFAULT 0";
    }
    return implode(",\n", $cols);
}

/** Definitions of upgrade v2 (M.A.D.A.S. attendance & payroll integration). */
function migration_v2(): array
{
    $E = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $hours = pt_fields_ddl();
    $tables = [
        'emp_types' => "CREATE TABLE IF NOT EXISTS emp_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  conversion_factor DECIMAL(10,4) NOT NULL DEFAULT 26 COMMENT 'Days per month: daily rate = monthly rate / factor',
  hours_per_day DECIMAL(5,2) NOT NULL DEFAULT 8,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) $E",
        'emp_groups' => "CREATE TABLE IF NOT EXISTS emp_groups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  log_group TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Attendance logged by group',
  cost_center VARCHAR(40) NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) $E",
        'class_groups' => "CREATE TABLE IF NOT EXISTS class_groups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) $E",
        'rank_categories' => "CREATE TABLE IF NOT EXISTS rank_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) $E",
        'classifications' => "CREATE TABLE IF NOT EXISTS classifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) $E",
        'jobs' => "CREATE TABLE IF NOT EXISTS jobs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  category VARCHAR(80) NULL,
  sub_category VARCHAR(80) NULL,
  ot_offset TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'OT/OL: overtime is credited as offset leave instead of paid',
  is_active TINYINT(1) NOT NULL DEFAULT 1
) $E",
        'exemption_codes' => "CREATE TABLE IF NOT EXISTS exemption_codes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  tax_exempt TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Compensation exempt from withholding tax (e.g. MWE)',
  is_active TINYINT(1) NOT NULL DEFAULT 1
) $E",
        'locations' => "CREATE TABLE IF NOT EXISTS locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) $E",
        'gl_accounts' => "CREATE TABLE IF NOT EXISTS gl_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  account_type ENUM('asset','liability','equity','income','expense') NOT NULL DEFAULT 'expense',
  is_active TINYINT(1) NOT NULL DEFAULT 1
) $E",
        'earning_types' => "CREATE TABLE IF NOT EXISTS earning_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  int_rate DECIMAL(6,2) NOT NULL DEFAULT 0,
  gl_code VARCHAR(20) NULL,
  taxable TINYINT(1) NOT NULL DEFAULT 1,
  tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'Separate withholding rate on this earning (0 = none)',
  special TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Special earning: non-taxable up to the special earnings ceiling',
  de_minimis TINYINT(1) NOT NULL DEFAULT 0,
  bir2316_box VARCHAR(20) NULL,
  ref_ext TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Reference only: shown but not added to pay',
  is_active TINYINT(1) NOT NULL DEFAULT 1
) $E",
        'fixed_trans_codes' => "CREATE TABLE IF NOT EXISTS fixed_trans_codes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  trans_type ENUM('earning','deduction') NOT NULL DEFAULT 'earning',
  role ENUM('none','sss','hdmf','phic','tax') NOT NULL DEFAULT 'none' COMMENT 'Fixed amount replaces the computed statutory deduction',
  default_terms ENUM('every_pay','monthly_split','first_pay','second_pay') NOT NULL DEFAULT 'every_pay',
  gl_code VARCHAR(20) NULL,
  de_minimis TINYINT(1) NOT NULL DEFAULT 0,
  bir2316_box VARCHAR(20) NULL,
  taxable TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) $E",
        'employee_fixed_trans' => "CREATE TABLE IF NOT EXISTS employee_fixed_trans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  code_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  terms ENUM('every_pay','monthly_split','first_pay','second_pay') NULL,
  start_date DATE NULL,
  end_date DATE NULL,
  status ENUM('active','stopped') NOT NULL DEFAULT 'active',
  remarks VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_eft_emp (employee_id),
  CONSTRAINT fk_eft_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_eft_code FOREIGN KEY (code_id) REFERENCES fixed_trans_codes(id)
) $E",
        'employee_transactions' => "CREATE TABLE IF NOT EXISTS employee_transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cutoff_id INT NULL,
  pay_group_id INT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  employee_id INT NOT NULL,
  trans_type ENUM('earning','deduction') NOT NULL,
  earning_type_id INT NULL,
  deduction_type_id INT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  remarks VARCHAR(255) NULL,
  status ENUM('pending','included','cancelled') NOT NULL DEFAULT 'pending',
  payroll_run_id INT NULL,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_et_emp (employee_id, period_start),
  KEY idx_et_cut (cutoff_id),
  CONSTRAINT fk_et_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) $E",
        'transaction_payments' => "CREATE TABLE IF NOT EXISTS transaction_payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ledger_id INT NOT NULL,
  pay_date DATE NOT NULL,
  reference_no VARCHAR(40) NULL,
  amount DECIMAL(12,2) NOT NULL,
  remarks VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tp_led (ledger_id),
  CONSTRAINT fk_tp_led FOREIGN KEY (ledger_id) REFERENCES ledgers(id) ON DELETE CASCADE
) $E",
        'bio_devices' => "CREATE TABLE IF NOT EXISTS bio_devices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dev_no INT NOT NULL DEFAULT 1,
  name VARCHAR(80) NOT NULL,
  conn_type ENUM('NET','COM') NOT NULL DEFAULT 'NET',
  comm_address VARCHAR(60) NOT NULL,
  comm_port VARCHAR(20) NOT NULL DEFAULT '4370',
  dev_password VARCHAR(20) NOT NULL DEFAULT '0',
  protocol ENUM('udp','tcp') NOT NULL DEFAULT 'udp',
  branch_id INT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_sync_at DATETIME NULL,
  last_status VARCHAR(255) NULL
) $E",
        'log_staging' => "CREATE TABLE IF NOT EXISTS log_staging (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  batch_id INT NULL,
  line_no INT NOT NULL DEFAULT 0,
  log_time DATETIME NULL,
  bio_id VARCHAR(20) NULL,
  employee_id INT NULL,
  punch_type ENUM('in','out','break_out','break_in','unknown') NOT NULL DEFAULT 'unknown',
  co_code VARCHAR(10) NULL,
  branch_code VARCHAR(10) NULL,
  branch_id INT NULL,
  device_id VARCHAR(40) NULL,
  source ENUM('file','log_table','device') NOT NULL DEFAULT 'file',
  raw_text VARCHAR(255) NULL,
  remarks VARCHAR(255) NULL,
  status ENUM('pending','posted','error','duplicate') NOT NULL DEFAULT 'pending',
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ls_status (status),
  KEY idx_ls_batch (batch_id)
) $E",
        'attendance_summary_branches' => "CREATE TABLE IF NOT EXISTS attendance_summary_branches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  summary_id INT NOT NULL,
  branch_id INT NOT NULL,
$hours,
  UNIQUE KEY uq_asb (summary_id, branch_id),
  CONSTRAINT fk_asb_sum FOREIGN KEY (summary_id) REFERENCES attendance_summaries(id) ON DELETE CASCADE
) $E",
        'user_scopes' => "CREATE TABLE IF NOT EXISTS user_scopes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  scope_type ENUM('branch','org_unit') NOT NULL,
  ref_id INT NOT NULL,
  UNIQUE KEY uq_scope (user_id, scope_type, ref_id)
) $E",
        'pay_rates' => "CREATE TABLE IF NOT EXISTS pay_rates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  label VARCHAR(80) NOT NULL,
  group_name VARCHAR(40) NOT NULL,
  day_pct DECIMAL(7,3) NOT NULL DEFAULT 100 COMMENT '% of hourly rate per hour (Hourly Rate x Hr x Rate)',
  night_pct DECIMAL(7,3) NOT NULL DEFAULT 0 COMMENT 'Night premium % of hourly rate per night hour in this category',
  sort_order INT NOT NULL DEFAULT 0
) $E",
    ];

    // new columns: [table, column, definition]
    $columns = [
        ['attendance_cutoffs', 'pay_date', 'DATE NULL'],
        ['attendance_cutoffs', 'period_no', 'TINYINT NULL COMMENT \'1 = 1st pay of the month, 2 = 2nd pay...\''],
        ['attendance_cutoffs', 'period_month', 'TINYINT NULL'],
        ['attendance_cutoffs', 'period_year', 'SMALLINT NULL'],
        ['attendance_cutoffs', 'description', 'VARCHAR(60) NULL'],
        ['attendance_summaries', 'source', "VARCHAR(10) NOT NULL DEFAULT 'ams'"],
        ['attendance_summaries', 'remarks', 'VARCHAR(255) NULL'],
        ['attendance_summaries', 'entered_by', 'INT NULL'],
        ['attendance_summaries', 'updated_at', 'DATETIME NULL'],
        ['dtr', 'break_out2', 'DATETIME NULL'],
        ['dtr', 'break_in2', 'DATETIME NULL'],
        ['dtr', 'absent_minutes', 'INT NOT NULL DEFAULT 0'],
        ['dtr', 'leave_minutes', 'INT NOT NULL DEFAULT 0'],
        ['dtr', 'leave_bucket', 'VARCHAR(4) NULL'],
        ['dtr', 'holiday_minutes', 'INT NOT NULL DEFAULT 0'],
        ['dtr', 'penalty_minutes', 'INT NOT NULL DEFAULT 0'],
        ['dtr', 'log_status', 'VARCHAR(40) NULL'],
        ['shifts', 'break_out1', 'TIME NULL'],
        ['shifts', 'break_in1', 'TIME NULL'],
        ['shifts', 'break_out2', 'TIME NULL'],
        ['shifts', 'break_in2', 'TIME NULL'],
        ['shifts', 'required_hours', 'DECIMAL(5,2) NULL COMMENT \'Total required duty hours (NULL = normal hours per day)\''],
        ['shifts', 'np_hours', 'DECIMAL(5,2) NULL COMMENT \'Total night premium hours within the shift\''],
        ['shifts', 'flex_minutes', 'INT NOT NULL DEFAULT 0 COMMENT \'Flexible time-in allowance (+/- minutes)\''],
        ['shifts', 'strict_time', 'TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'No grace period on this shift\''],
        ['shifts', 'whole_week', 'TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'Whole-week duty: rest days only from employee schedules\''],
        ['shifts', 'is_default', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['pay_groups', 'sss_mode', "ENUM('every','first','second') NOT NULL DEFAULT 'every'"],
        ['pay_groups', 'hdmf_mode', "ENUM('every','first','second') NOT NULL DEFAULT 'every'"],
        ['pay_groups', 'phic_mode', "ENUM('every','first','second') NOT NULL DEFAULT 'every'"],
        ['pay_groups', 'tax_mode', "ENUM('every','first','second') NOT NULL DEFAULT 'every'"],
        ['pay_groups', 'tax_type', "ENUM('daily','weekly','semi_monthly','monthly') NULL COMMENT 'Default tax table (NULL = pay frequency)'"],
        ['branches', 'company_code', 'VARCHAR(10) NULL'],
        ['branches', 'branch_code', 'VARCHAR(10) NULL'],
        ['branches', 'init_code', 'VARCHAR(10) NULL'],
        ['branches', 'location_id', 'INT NULL'],
        ['branches', 'approver_name', 'VARCHAR(120) NULL'],
        ['branches', 'checker_name', 'VARCHAR(120) NULL'],
        ['branches', 'is_default', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['employees', 'employee_type_id', 'INT NULL'],
        ['employees', 'employee_group_id', 'INT NULL'],
        ['employees', 'class_group_id', 'INT NULL'],
        ['employees', 'rank_category_id', 'INT NULL'],
        ['employees', 'classification_id', 'INT NULL'],
        ['employees', 'job_id', 'INT NULL'],
        ['employees', 'exemption_code_id', 'INT NULL'],
        ['employees', 'location_id', 'INT NULL'],
        ['employees', 'bank_account_type', 'VARCHAR(20) NULL'],
        ['benefit_types', 'gl_code', 'VARCHAR(20) NULL'],
        ['benefit_types', 'no_deduction', "TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Not reduced by absences'"],
        ['benefit_types', 'ref_ext', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['benefit_types', 'given_at', "ENUM('fixed','duty_day_hol','duty_hour_hol','duty_week_hol','duty_day','duty_hour','duty_week') NOT NULL DEFAULT 'fixed'"],
        ['benefit_types', 'bir2316_box', 'VARCHAR(20) NULL'],
        ['deduction_types', 'int_rate', 'DECIMAL(6,2) NOT NULL DEFAULT 0'],
        ['deduction_types', 'gl_code', 'VARCHAR(20) NULL'],
        ['deduction_types', 'bir2316_box', 'VARCHAR(20) NULL'],
        ['deduction_types', 'ref_ext', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['deduction_types', 'register_group', "ENUM('sss_loan','hdmf_loan','company_loan','other') NOT NULL DEFAULT 'other'"],
        ['leave_types', 'summary_bucket', "ENUM('vl','sl','el','ol','pm','none') NOT NULL DEFAULT 'ol' COMMENT 'Column of the attendance transaction'"],
        ['payroll_items', 'summary_id', 'INT NULL'],
        ['payroll_items', 'print_count', 'INT NOT NULL DEFAULT 0'],
        ['payroll_items', 'last_printed_at', 'DATETIME NULL'],
        ['payroll_item_lines', 'reg_col', 'VARCHAR(20) NULL'],
        ['users', 'module_access', 'VARCHAR(500) NULL'],
    ];
    foreach (pt_all_fields() as $f) {
        $columns[] = ['attendance_summaries', $f, $f === 'tickets' ? 'INT NOT NULL DEFAULT 0' : 'DECIMAL(12,2) NOT NULL DEFAULT 0'];
    }

    // Customized Import Attendance Log: fixed-width layouts (Import Log Configuration)
    $columns[] = ['import_profiles', 'layout', "ENUM('delimited','fixed') NOT NULL DEFAULT 'delimited' COMMENT 'Column layout of the log file'"];
    foreach (['id' => 'Employee / biometric ID', 'date' => 'Date', 'time' => 'Time', 'type' => 'Log type', 'dev' => 'Device / bio ID'] as $k => $what) {
        $columns[] = ['import_profiles', 'fw_' . $k . '_start', 'SMALLINT NULL COMMENT ' . db()->quote($what . ' start position (fixed width)')];
        $columns[] = ['import_profiles', 'fw_' . $k . '_width', 'SMALLINT NULL COMMENT ' . db()->quote($what . ' width (fixed width)')];
    }
    $columns[] = ['import_profiles', 'is_default', 'TINYINT(1) NOT NULL DEFAULT 0'];

    // enum extensions: [table, column, new definition, marker value that must exist afterwards]
    $modify = [
        ['import_batches', 'batch_type', "ENUM('biometric','csv','xlsx','device','log_table') NOT NULL", 'device'],
    ];

    $S = [];
    $add = function (string $k, string $v, string $label, string $grp, string $type = 'text', ?string $help = null, int $sort = 0) use (&$S) {
        $S[] = [$k, $v, $label, $grp, $type, $help, $sort];
    };
    $yn = 'bool';
    // --- AMS policies: Calculation tab
    $add('ams_duty_mode', 'first_last', 'Duty hours rendered', 'ams_policy', 'select:first_last=TIMEIN and TIMEOUT log,pairs=Every IN and OUT log', null, 1);
    $add('ams_late_mode', 'every', 'Late and undertime', 'ams_policy', 'select:in_out=Late at TIMEIN and Undertime at TIMEOUT log,every=Late at every IN log and Undertime on every OUT log', null, 2);
    $add('ams_break_excess_late', '0', 'BREAK rendered above allocated break time as LATE', 'ams_policy', $yn, null, 3);
    $add('ams_nolate_holiday', '0', 'No late and undertime on HOLIDAYS', 'ams_policy', $yn, null, 4);
    $add('ams_nolate_restday', '0', 'No late and undertime during Day-Off on duty', 'ams_policy', $yn, null, 5);
    $add('ams_nolate_leave', '1', 'No late and undertime fall on LEAVE schedule', 'ams_policy', $yn, null, 6);
    $add('ams_short_absent', '0', 'Rendered hrs below allocated SHIFT hrs as ABSENT', 'ams_policy', $yn, null, 7);
    $add('ams_short_ut', '0', 'Rendered hrs below allocated SHIFT hrs as UNDERTIME', 'ams_policy', $yn, null, 8);
    $add('ams_nobreak_absent', '0', 'No BREAK log on SHIFT w/ break as ABSENT', 'ams_policy', $yn, null, 9);
    $add('ams_nobreak_ut', '1', 'No BREAK log on SHIFT w/ break as UNDERTIME', 'ams_policy', $yn, null, 10);
    // --- AMS policies: Configuration tab
    $add('ams_abs_week_total', '0', 'Total all absences per WEEK per cut-off period', 'ams_config', $yn, null, 1);
    $add('ams_abs_period_total', '0', 'Total all absences per cut-off period', 'ams_config', $yn, null, 2);
    $add('ams_holiday_absent_prior', '0', 'Holiday absent when absent on prior non-holiday day', 'ams_config', $yn, null, 3);
    $add('ams_tardy_week_total', '0', 'Total all tardiness per WEEK per cut-off period', 'ams_config', $yn, null, 4);
    $add('ams_tardy_period_total', '0', 'Total all tardiness per cut-off period', 'ams_config', $yn, null, 5);
    $add('ams_incomplete_absent', '0', 'Incomplete log as ABSENT', 'ams_config', $yn, null, 6);
    $add('ams_incomplete_ut', '1', 'Incomplete log as UNDERTIME', 'ams_config', $yn, null, 7);
    $add('ams_incomplete_penalty_on', '0', 'Incomplete log penalty', 'ams_config', $yn, null, 8);
    $add('ams_incomplete_penalty', '0', 'Incomplete log penalty (minutes)', 'ams_config', 'number', null, 9);
    $add('ams_early_break_penalty_on', '0', 'LogIn 20 mins. before BREAKTIME-IN penalty', 'ams_config', $yn, null, 10);
    $add('ams_early_break_penalty', '0', 'LogIn before BREAKTIME-IN penalty (minutes)', 'ams_config', 'number', null, 11);
    $add('ams_np_from_logs', '0', 'TimeIN and TimeOUT computation for Night Prem.', 'ams_config', $yn, null, 12);
    $add('ams_invalid_hours', '17', 'No. of hours work as INVALID', 'ams_config', 'number', null, 13);
    $add('ams_dup_minutes', '15', '# of minutes between LOGS w/ same TYPE as INVALID', 'ams_config', 'number', null, 14);
    $add('ams_late_lookup', 'E', 'Late table lookup code [D,E,P]', 'ams_config', 'select:E=E - each late occurrence,D=D - total per day,P=P - total per cut-off period', null, 15);
    $add('ams_six_logs', '0', 'Use 6 logs per day (Time In, Break Out 1, Break In 1, Break Out 2, Break In 2, Time Out)', 'ams_config', $yn, null, 16);
    // --- Payroll parameters: processing config
    $add('default_conversion_factor', '26', 'Monthly - daily conversion factor (default)', 'payroll_params', 'number', null, 1);
    $add('hdmf_min_gross', '0', 'HDMF: minimum monthly gross salary subject to contribution', 'payroll_params', 'number', null, 2);
    $add('hdmf_rate_pct', '2', 'HDMF: employee percent rate', 'payroll_params', 'number', null, 3);
    $add('hdmf_max_ee', '200', 'HDMF: maximum employee contribution (monthly)', 'payroll_params', 'number', null, 4);
    $add('hdmf_global_ee_pct', '0', 'HDMF: global additional employee contribution rate (%)', 'payroll_params', 'number', null, 5);
    $add('payroll_bank_code', '', 'Bank account code (company payroll account)', 'payroll_params', 'text', null, 6);
    $add('ot_taxable', '1', 'Overtime earnings taxable', 'payroll_params', $yn, null, 7);
    $add('taxable_base', 'gross_less_govt', 'Taxable earnings computed based on', 'payroll_params', 'select:gross_less_govt=Gross - Govt. deductions,basic_less_govt=Basic - Govt. deductions,gross=Gross', null, 8);
    $add('sss_lookup', 'monthly_rate', 'SSS premium contribution lookup', 'payroll_params', 'select:monthly_rate=Monthly rate,gross=Monthly gross earnings', null, 9);
    $add('min_net_pay_amount', '1', "Employee's take-home pay: earning not below (PHP)", 'payroll_params', 'number', null, 10);
    $add('special_max_nontax', '0', 'Special earnings maximum non-taxable amount (per period)', 'payroll_params', 'number', null, 11);
    $add('meal_comp_rate', '0', 'Meal compensation rate (per count)', 'payroll_params', 'number', null, 12);
    $add('meal_comp_ot_rate', '0', 'Meal compensation OT rate (per count)', 'payroll_params', 'number', null, 13);
    $add('ticket_rate', '0', 'Meal ticket price (deducted per ticket)', 'payroll_params', 'number', null, 14);
    // --- Annual / accounting config
    $add('thirteenth_include_leave', '1', '13th month basis includes paid leaves', 'payroll_annual', $yn, null, 1);
    $add('year_end_adjust', '1', 'Year-end tax annualization on the last regular payroll of December', 'payroll_annual', $yn, null, 2);
    foreach ([
        ['gl_salaries', '5000', 'GL: Salaries and wages'], ['gl_ot', '5010', 'GL: Overtime pay'], ['gl_premium', '5020', 'GL: Holiday / premium pay'],
        ['gl_np', '5030', 'GL: Night premium'], ['gl_13th', '5040', 'GL: 13th month pay'], ['gl_allow', '5050', 'GL: Allowances & benefits'],
        ['gl_er_contrib', '5060', 'GL: Employer contributions expense'], ['gl_sss_pay', '2100', 'GL: SSS payable'], ['gl_phic_pay', '2110', 'GL: PhilHealth payable'],
        ['gl_hdmf_pay', '2120', 'GL: Pag-IBIG payable'], ['gl_wtax_pay', '2130', 'GL: Withholding tax payable'], ['gl_sss_loan', '2140', 'GL: SSS loans payable'],
        ['gl_hdmf_loan', '2150', 'GL: Pag-IBIG loans payable'], ['gl_advances', '1150', 'GL: Advances / receivables from employees'],
        ['gl_net_pay', '2200', 'GL: Accrued payroll (net pay)'],
    ] as $i => $g) {
        $add($g[0], $g[1], $g[2], 'payroll_annual', 'text', null, 10 + $i);
    }
    // --- Payslip parameters
    $add('payslip_title', '', 'Payslip heading (blank = company name)', 'payslip', 'text', null, 1);
    $add('payslip_show_ytd', '1', 'Show YTD information', 'payslip', $yn, null, 2);
    $add('payslip_show_balances', '1', 'Show outstanding loan balances', 'payslip', $yn, null, 3);
    $add('payslip_show_hours', '1', 'Show hours beside earnings', 'payslip', $yn, null, 4);
    $add('payslip_copy_label', '1', 'Mark reprinted payslips as ( COPY )', 'payslip', $yn, null, 5);
    $add('payslip_per_page', '2', 'Payslips per printed page (batch printing)', 'payslip', 'select:1=1 per page,2=2 per page', null, 6);
    $add('payslip_sig_label', 'HR Manager', 'Signature label', 'payslip', 'text', null, 7);
    $add('payslip_sig_name', '', 'Signatory name (optional)', 'payslip', 'text', null, 8);
    $add('payslip_disclaimer', 'Please examine your payslip. You are responsible for checking and validating the details contained in this payslip. Failure to report any discrepancy in the information above within 30 days shall mean that you acknowledge that all information herein is correct.',
        'Disclaimer', 'payslip', 'textarea', null, 9);
    // --- Signatories
    foreach ([['prepared', 'Prepared by', 'Payroll Officer'], ['checked', 'Checked by', 'HR Manager'], ['approved', 'Approved by', 'General Manager']] as $i => $sg) {
        $add('sig_' . $sg[0] . '_name', '', $sg[1] . ' — name', 'signatory', 'text', null, $i * 2 + 1);
        $add('sig_' . $sg[0] . '_title', $sg[2], $sg[1] . ' — position', 'signatory', 'text', null, $i * 2 + 2);
    }

    // reference data seeded only when the table is empty
    $seeds = [
        'emp_types' => "INSERT INTO emp_types (code, name, conversion_factor, hours_per_day, is_default) VALUES
('AA','Monthly Staff',26,8,1),('BB','Daily Staff 30',30,8,0),('CC','Daily Staff 26',26,8,0)",
        'emp_groups' => "INSERT INTO emp_groups (code, name, log_group, is_default) VALUES
('00','Executives',0,0),('01','Rank & File - Monthly',0,1),('02','Rank & File - Daily',0,0),('07','OJT',0,0),('08','Apprentice',0,0),('99','Piecework (Pakyaw)',0,0)",
        'class_groups' => "INSERT INTO class_groups (code, name) VALUES ('ADM','Administration'),('OPS','Operations'),('SAL','Sales & Marketing')",
        'rank_categories' => "INSERT INTO rank_categories (code, name) VALUES ('RF','Rank and File'),('SUP','Supervisory'),('MGR','Managerial'),('EXE','Executive')",
        'classifications' => "INSERT INTO classifications (code, name) VALUES ('DL','Direct Labor'),('IDL','Indirect Labor'),('ADM','Administrative'),('CONF','Confidential')",
        'jobs' => "INSERT INTO jobs (code, name, category, sub_category, ot_offset) VALUES
('1000','General Manager','Management',NULL,0),('1100','HR Officer','Administration',NULL,0),('1200','Accountant','Finance',NULL,0),
('1300','Cashier','Finance',NULL,0),('1400','Sales Associate','Sales',NULL,0),('1500','Production Worker','Operations',NULL,0),
('1600','Driver','Operations',NULL,0),('1700','Security Guard','Operations',NULL,0)",
        'exemption_codes' => "INSERT INTO exemption_codes (code, name, tax_exempt) VALUES
('Z','Taxable (TRAIN law, no personal exemption)',0),('MWE','Minimum wage earner (exempt)',1),('EXEMP','Tax exempt',1)",
        'locations' => "INSERT INTO locations (code, name) VALUES ('MKT','Makati'),('QC','Quezon City'),('CEB','Cebu')",
        'gl_accounts' => "INSERT INTO gl_accounts (code, name, account_type) VALUES
('1010','Cash in bank - payroll','asset'),('1150','Advances / receivables from employees','asset'),
('2100','SSS premiums payable','liability'),('2110','PhilHealth premiums payable','liability'),('2120','Pag-IBIG contributions payable','liability'),
('2130','Withholding tax on compensation payable','liability'),('2140','SSS loans payable','liability'),('2150','Pag-IBIG loans payable','liability'),
('2200','Accrued payroll (net pay payable)','liability'),('5000','Salaries and wages','expense'),('5010','Overtime pay','expense'),
('5020','Holiday and premium pay','expense'),('5030','Night premium','expense'),('5040','13th month pay','expense'),
('5050','Allowances and benefits','expense'),('5060','Employer contributions (SSS, PhilHealth, Pag-IBIG)','expense')",
        'earning_types' => "INSERT INTO earning_types (code, name, gl_code, taxable, tax_rate, special, de_minimis) VALUES
('1000','Fixed overtime','5010',1,0,0,0),('1001','Consultancy fee','5000',1,0,0,0),('1002','Salary adjustment (add)','5000',1,0,0,0),
('1003','Shortage allowance','5050',1,0,0,0),('1004','Productivity bonus','5050',0,0,0,0),('1005','Sales incentive','5050',1,0,0,0)",
        'fixed_trans_codes' => "INSERT INTO fixed_trans_codes (code, name, trans_type, role, default_terms, gl_code, taxable) VALUES
('01','SSS contribution (fixed)','deduction','sss','monthly_split','2100',0),('02','Pag-IBIG contribution (fixed)','deduction','hdmf','monthly_split','2120',0),
('03','PhilHealth contribution (fixed)','deduction','phic','monthly_split','2110',0),('04','Withholding tax (fixed)','deduction','tax','every_pay','2130',0),
('10','COLA','earning','none','every_pay','5050',1),('11','Position allowance','earning','none','every_pay','5050',1),
('12','Consultancy allowance','earning','none','every_pay','5050',1),('13','Job allowance','earning','none','every_pay','5050',1)",
        'pay_rates' => "INSERT INTO pay_rates (code, label, group_name, day_pct, night_pct, sort_order) VALUES
('reg','Regular day (regular hours)','Regular Day Rates',100,10,1),
('reg_ot','Regular OT rate','Regular Day Rates',125,12.5,2),
('rd','Day-off rate','Regular Day Rates',130,13,3),
('rd_ot','Day-off OT rate','Regular Day Rates',169,16.9,4),
('lh','Legal holiday rate','Legal Holiday Rates',200,20,5),
('lh_ot','Legal holiday overtime rate','Legal Holiday Rates',260,26,6),
('lh_rd','Legal holiday falling on day-off rate','Legal Holiday Rates',260,26,7),
('lh_rd_ot','Legal holiday on day-off OT rate','Legal Holiday Rates',338,33.8,8),
('sh','Special holiday rate','Special Holiday Rates',130,13,9),
('sh_ot','Special holiday overtime rate','Special Holiday Rates',169,16.9,10),
('sh_rd','Special holiday falling on day-off rate','Special Holiday Rates',150,15,11),
('sh_rd_ot','Special holiday on day-off OT rate','Special Holiday Rates',195,19.5,12)",
    ];

    // one-time data conversions (idempotent)
    $data = [
        "UPDATE pay_groups SET sss_mode = 'first', hdmf_mode = 'first', phic_mode = 'first' WHERE contrib_mode = 'first' AND sss_mode = 'every' AND hdmf_mode = 'every' AND phic_mode = 'every'",
        "UPDATE pay_groups SET sss_mode = 'second', hdmf_mode = 'second', phic_mode = 'second' WHERE contrib_mode = 'last' AND sss_mode = 'every' AND hdmf_mode = 'every' AND phic_mode = 'every'",
        "UPDATE leave_types SET summary_bucket = 'vl' WHERE code IN ('SIL','VL') AND summary_bucket = 'ol'",
        "UPDATE leave_types SET summary_bucket = 'sl' WHERE code = 'SL' AND summary_bucket = 'ol'",
        "UPDATE leave_types SET summary_bucket = 'el' WHERE code = 'EL' AND summary_bucket = 'ol'",
        "UPDATE leave_types SET summary_bucket = 'pm' WHERE code IN ('ML','ML-MC','PL') AND summary_bucket = 'ol'",
        "UPDATE leave_types SET summary_bucket = 'none' WHERE is_paid = 0 AND summary_bucket = 'ol'",
        "UPDATE deduction_types SET register_group = 'sss_loan' WHERE code IN ('SSS-SL','SSS-CL') AND register_group = 'other'",
        "UPDATE deduction_types SET register_group = 'hdmf_loan' WHERE code IN ('HDMF-MPL','HDMF-CAL') AND register_group = 'other'",
        "UPDATE deduction_types SET register_group = 'company_loan' WHERE code IN ('CO-LOAN','CA') AND register_group = 'other'",
        "UPDATE deduction_types SET gl_code = '2100' WHERE code = 'SSS' AND gl_code IS NULL",
        "UPDATE deduction_types SET gl_code = '2110' WHERE code = 'PHIC' AND gl_code IS NULL",
        "UPDATE deduction_types SET gl_code = '2120' WHERE code = 'HDMF' AND gl_code IS NULL",
        "UPDATE deduction_types SET gl_code = '2130' WHERE code = 'WTAX' AND gl_code IS NULL",
        "UPDATE deduction_types SET gl_code = '2140' WHERE code IN ('SSS-SL','SSS-CL') AND gl_code IS NULL",
        "UPDATE deduction_types SET gl_code = '2150' WHERE code IN ('HDMF-MPL','HDMF-CAL') AND gl_code IS NULL",
        "UPDATE deduction_types SET gl_code = '1150' WHERE gl_code IS NULL AND category <> 'statutory'",
        "UPDATE benefit_types SET gl_code = '5050' WHERE gl_code IS NULL",
        "UPDATE attendance_cutoffs SET period_year = YEAR(period_end), period_month = MONTH(period_end),
            period_no = IF(pay_group_id IN (SELECT id FROM pay_groups WHERE frequency = 'monthly'), 1, IF(DAY(period_end) <= 15, 1, 2))
         WHERE period_year IS NULL",
        "UPDATE employees e JOIN exemption_codes x ON x.code = 'MWE' SET e.exemption_code_id = x.id WHERE e.is_mwe = 1 AND e.exemption_code_id IS NULL",
        "UPDATE employees e JOIN exemption_codes x ON x.code = 'Z' SET e.exemption_code_id = x.id WHERE e.is_mwe = 0 AND e.exemption_code_id IS NULL",
        "UPDATE branches SET is_default = 1 WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM branches) t) AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM branches WHERE is_default = 1) d)",
        "UPDATE shifts SET is_default = 1 WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM shifts) t) AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM shifts WHERE is_default = 1) d)",
        // offset leave credited from overtime of OT/OL jobs
        "INSERT INTO leave_types (code, name, legal_basis, accrual_method, days_per_year, count_basis, is_paid, approval_chain, is_statutory, is_active, description, summary_bucket)
         SELECT 'OFL', 'Offset Leave (OT/OL)', 'Company policy', 'per_event', 0, 'workdays', 1, 'supervisor,hr', 0, 1,
                'Credited from approved overtime of employees whose job is marked OT/OL; used as other/offset leave (OL).', 'ol'
         FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM leave_types WHERE code = 'OFL')",
    ];

    return ['tables' => $tables, 'columns' => $columns, 'modify' => $modify, 'settings' => $S, 'seeds' => $seeds, 'data' => $data];
}


/** Definitions of upgrade v3 (term transactions, shift log count, employee import). */
function migration_v3(): array
{
    $E = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $tables = [
        'employee_term_trans' => "CREATE TABLE IF NOT EXISTS employee_term_trans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  trans_type ENUM('earning','deduction') NOT NULL DEFAULT 'deduction',
  earning_type_id INT NULL,
  deduction_type_id INT NULL,
  doc_no VARCHAR(40) NULL COMMENT 'Document / voucher / agency reference',
  trans_date DATE NOT NULL,
  principal DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Transaction amount (principal)',
  monthly_rate DECIMAL(7,3) NOT NULL DEFAULT 0 COMMENT 'Monthly interest rate in percent',
  interest_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Principal + interest',
  terms INT NOT NULL DEFAULT 1 COMMENT 'Number of paydays',
  per_pay_principal DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Trans/PD (Pri)',
  per_pay_interest DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Trans/PD (Int)',
  trans_term ENUM('every_pay','first_pay','second_pay','third_pay','fourth_pay') NOT NULL DEFAULT 'every_pay',
  start_date DATE NOT NULL COMMENT 'First payday the installment may be taken',
  next_date DATE NULL COMMENT 'Projected next installment',
  branch_id INT NULL,
  remarks VARCHAR(255) NULL,
  paid_terms INT NOT NULL DEFAULT 0,
  total_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
  balance DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  KEY idx_ett_emp (employee_id, status),
  KEY idx_ett_status (status, start_date)
) $E",
        'employee_term_schedule' => "CREATE TABLE IF NOT EXISTS employee_term_schedule (
  id INT AUTO_INCREMENT PRIMARY KEY,
  term_id INT NOT NULL,
  seq_no INT NOT NULL,
  due_date DATE NULL COMMENT 'Projected payday',
  principal DECIMAL(12,2) NOT NULL DEFAULT 0,
  interest DECIMAL(12,2) NOT NULL DEFAULT 0,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('pending','posted','paid','skipped','cancelled') NOT NULL DEFAULT 'pending',
  payroll_run_id INT NULL,
  cutoff_id INT NULL,
  posted_at DATETIME NULL,
  reference VARCHAR(80) NULL COMMENT 'Run no. or receipt of a payment outside payroll',
  KEY idx_ets_term (term_id, seq_no),
  KEY idx_ets_run (payroll_run_id)
) $E",
        'employee_import_batches' => "CREATE TABLE IF NOT EXISTS employee_import_batches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  file_name VARCHAR(255) NOT NULL,
  total_rows INT NOT NULL DEFAULT 0,
  created_rows INT NOT NULL DEFAULT 0,
  updated_rows INT NOT NULL DEFAULT 0,
  rejected_rows INT NOT NULL DEFAULT 0,
  uploaded_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) $E",
    ];
    $columns = [
        ['shifts', 'logs_per_day', "TINYINT NOT NULL DEFAULT 0 COMMENT 'Logs per day: 0 = from the breaks, 2, 4 or 6'"],
        ['employees', 'import_batch_id', 'INT NULL'],
    ];
    $S = [];
    $S[] = ['emp_import_default_password', 'Welcome' . date('Y'), 'Temporary password of portal accounts created by the employee CSV import', 'general', 'text', 'Employees must change it at their first sign-in.', 20];
    return ['tables' => $tables, 'columns' => $columns, 'modify' => [], 'settings' => $S, 'seeds' => [], 'data' => [
        // shifts keep working as before: the log count is derived from the breaks until set explicitly
        "UPDATE shifts SET logs_per_day = 6 WHERE logs_per_day = 0 AND break_out2 IS NOT NULL",
        "UPDATE shifts SET logs_per_day = 4 WHERE logs_per_day = 0",
    ]];
}

/**
 * Version 4: CSV imports of transactions, deductions and benefits; the manner of posting of an
 * employee benefit; the deduction source of a multi-location transaction; corrected log types.
 */
function migration_v4(): array
{
    $E = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $tables = [
        'csv_import_batches' => "CREATE TABLE IF NOT EXISTS csv_import_batches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(30) NOT NULL COMMENT 'pay_trans, att_batch, emp_trans, fixed_trans, deductions, benefits',
  file_name VARCHAR(255) NOT NULL,
  cutoff_id INT NULL,
  total_rows INT NOT NULL DEFAULT 0,
  created_rows INT NOT NULL DEFAULT 0,
  updated_rows INT NOT NULL DEFAULT 0,
  replaced_rows INT NOT NULL DEFAULT 0,
  rejected_rows INT NOT NULL DEFAULT 0,
  uploaded_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cib_kind (kind, created_at)
) $E",
    ];
    $columns = [
        ['employee_benefits', 'post_term', "VARCHAR(12) NULL COMMENT 'every_pay, first_pay .. fourth_pay; NULL = the frequency of the benefit type'"],
        ['employee_benefits', 'import_batch_id', 'INT NULL'],
        ['attendance_summaries', 'deduction_branch_id', "INT NULL COMMENT 'Set Deduction Source: branch / division / segment carrying the earnings and deductions'"],
        ['payroll_items', 'deduction_branch_id', 'INT NULL'],
        ['payroll_branch_items', 'is_deduction_source', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['payroll_branch_items', 'deductions', 'DECIMAL(12,2) NOT NULL DEFAULT 0'],
        ['payroll_branch_items', 'net_pay', 'DECIMAL(12,2) NOT NULL DEFAULT 0'],
        ['attendance_logs', 'orig_punch_type', "VARCHAR(10) NULL COMMENT 'Type read from the device / file before a manual correction'"],
        ['employee_transactions', 'import_batch_id', 'INT NULL'],
        ['employee_fixed_trans', 'import_batch_id', 'INT NULL'],
        ['employee_deductions', 'import_batch_id', 'INT NULL'],
        ['dtr', 'calc_version', "TINYINT NOT NULL DEFAULT 0 COMMENT 'Engine version that computed the row; older rows are rebuilt when shown'"],
    ];
    return ['tables' => $tables, 'columns' => $columns, 'modify' => [], 'settings' => [], 'seeds' => [], 'data' => [
        // runs computed before version 4: the default branch carried every deduction
        "UPDATE payroll_branch_items pb LEFT JOIN payroll_branch_items x ON x.item_id = pb.item_id AND x.is_deduction_source = 1
         SET pb.is_deduction_source = 1 WHERE pb.is_default = 1 AND x.id IS NULL",
    ]];
}

/**
 * Version 5: the system name (G-SMaRT HRIS) as an editable setting, the explanation of the revised
 * break-log policy, and the branch views of multi-location pay (no schema change for those).
 */
function migration_v5(): array
{
    $S = [];
    $S[] = ['system_name', 'G-SMaRT HRIS', 'System name', 'general', 'text', 'Shown on the sign-in page, the sidebar, the browser tab, the footer, the manual and the e-mails.', 1];
    $q = fn(string $v) => "'" . str_replace("'", "''", $v) . "'";
    return ['tables' => [], 'columns' => [], 'modify' => [], 'settings' => $S, 'seeds' => [], 'data' => [
        "UPDATE settings SET svalue = 'G-SMaRT HRIS Notifications' WHERE skey = 'mail_from_name' AND svalue IN ('I-HRIS Notifications', 'I-HRIS', '')",
        "UPDATE settings SET help = " . $q('Every break must be logged. A work segment missing its opening or closing log is absent: shift 8-12 / 1-5, logs 8:00 12:01 5:05 = 1:00-5:00 absent (4 hrs); logs 7:57 5:15 = 8 hrs absent.') . " WHERE skey = 'ams_nobreak_absent'",
        "UPDATE settings SET help = " . $q('Same rule as No BREAK log as ABSENT, but the uncredited segment counts as undertime. When both are ticked, ABSENT applies.') . " WHERE skey = 'ams_nobreak_ut'",
        "UPDATE settings SET help = " . $q('A log repeating the previous log of the same type within this many minutes (a double tap) is not counted. Logs of different types always count.') . " WHERE skey = 'ams_dup_minutes'",
    ]];
}

/**
 * Version 6 (multi-company): join requests made with a Company Code wait in the users table
 * (reg_status pending / approved / rejected) until the Company Owner decides, and the hidden support
 * account a Super Admin uses inside a company is marked is_support. Existing accounts are unchanged
 * (reg_status stays empty, is_support 0).
 */
function migration_v6(): array
{
    return ['tables' => [], 'columns' => [
        ['users', 'reg_status', 'VARCHAR(10) NULL'],
        ['users', 'registered_at', 'DATETIME NULL'],
        ['users', 'reg_note', 'VARCHAR(255) NULL'],
        ['users', 'reviewed_by', 'INT NULL'],
        ['users', 'reviewed_at', 'DATETIME NULL'],
        ['users', 'is_support', 'TINYINT(1) NOT NULL DEFAULT 0'],
    ], 'modify' => [], 'settings' => [], 'seeds' => [], 'data' => []];
}

/**
 * Version 7:
 *  - attendance closing per transaction (Attendance Transaction Closing, Close Attendance Entry): only closed
 *    transactions appear in the Payroll Transaction screens and are paid; periods already approved / paid
 *    count as closed
 *  - the Payroll Computation parameters of a run (filters, tax table, annualization, regular processing,
 *    13th month, batch / selection)
 *  - fixed transactions on the 1st to 4th pay of the month (no end date: they run while the employee works)
 *  - page-level User Access (view, add, edit, delete, print / export, approve per page)
 */
function migration_v7(): array
{
    $e = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $terms = "ENUM('every_pay','monthly_split','first_pay','second_pay','third_pay','fourth_pay')";
    return ['tables' => [
        'user_page_access' => "CREATE TABLE IF NOT EXISTS user_page_access (
  user_id INT NOT NULL,
  route VARCHAR(40) NOT NULL,
  can_view TINYINT(1) NOT NULL DEFAULT 0,
  can_add TINYINT(1) NOT NULL DEFAULT 0,
  can_edit TINYINT(1) NOT NULL DEFAULT 0,
  can_delete TINYINT(1) NOT NULL DEFAULT 0,
  can_print TINYINT(1) NOT NULL DEFAULT 0,
  can_approve TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, route)
)$e",
    ], 'columns' => [
        ['attendance_summaries', 'is_closed', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['attendance_summaries', 'closed_at', 'DATETIME NULL'],
        ['attendance_summaries', 'closed_by', 'INT NULL'],
        ['payroll_runs', 'calc_branch_id', 'INT NULL'],
        ['payroll_runs', 'calc_group_id', 'INT NULL'],
        ['payroll_runs', 'calc_type_id', 'INT NULL'],
        ['payroll_runs', 'calc_tax_table', 'VARCHAR(20) NULL'],
        ['payroll_runs', 'calc_annualize', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['payroll_runs', 'calc_regular', 'TINYINT(1) NOT NULL DEFAULT 1'],
        ['payroll_runs', 'calc_13th', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['payroll_runs', 'calc_selection', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['payroll_runs', 'calc_emp_from', 'VARCHAR(30) NULL'],
        ['payroll_runs', 'calc_emp_to', 'VARCHAR(30) NULL'],
        ['users', 'page_access', 'TINYINT(1) NOT NULL DEFAULT 0'],
    ], 'modify' => [
        ['employee_fixed_trans', 'terms', "$terms NULL", 'fourth_pay'],
        ['fixed_trans_codes', 'default_terms', "$terms NOT NULL DEFAULT 'every_pay'", 'fourth_pay'],
    ], 'settings' => [], 'seeds' => [], 'data' => [
        "UPDATE attendance_summaries s JOIN attendance_cutoffs c ON c.id = s.cutoff_id SET s.is_closed = 1, s.closed_at = IFNULL(c.approved_at, NOW())
         WHERE c.status IN ('approved', 'locked') AND s.is_closed = 0",
    ]];
}

/**
 * Version 8:
 *  - dynamic roles and permissions: roles (built-in roles seeded, the System Administrator role is the
 *    protected Super Admin role), permissions (synchronized from the code catalog, app/permissions.php),
 *    role_permissions, user_permission_overrides (Allow / Deny per user), users.role_id; the module and
 *    page access of version 7 is converted into Deny overrides the first time the site opens
 *  - Period Closing / Payroll Closing: postings (ledgers, deductions, transactions, accruals, leave credits,
 *    attendance lock) move from Finalize to the closing of the period; runs finalized before stay posted
 *  - editable subsidiary ledger entries (who and why recorded on the entry)
 */
function migration_v8(): array
{
    $e = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $q = fn(string $v) => "'" . str_replace("'", "''", $v) . "'";
    $roles = [
        ['admin', 'System Administrator', 'Super Admin role: every page and every action, manages roles and user permissions. Protected: it cannot be edited, deactivated or deleted.', 1, 'admin'],
        ['hr', 'HR Officer', 'Personnel, attendance, leave and the shared setup tables.', 0, 'hr'],
        ['payroll', 'Payroll Officer', 'Payroll transactions, processing, registers, reports and the payroll setup.', 0, 'payroll'],
        ['supervisor', 'Supervisor / Dept. Head', 'Approves leave and overtime and evaluates their own team, plus self-service.', 0, 'supervisor'],
        ['employee', 'Employee', 'Self-service: time clock, own records, leave and overtime requests, payslips.', 0, 'employee'],
    ];
    $vals = [];
    foreach ($roles as $r) {
        $vals[] = '(' . implode(', ', [$q($r[0]), $q($r[1]), $q($r[2]), "'active'", '1', (string)$r[3], $q($r[4]), 'NOW()', 'NOW()']) . ')';
    }
    return ['tables' => [
        'roles' => "CREATE TABLE IF NOT EXISTS roles (
  id INT NOT NULL AUTO_INCREMENT,
  code VARCHAR(40) NOT NULL,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(255) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  is_super TINYINT(1) NOT NULL DEFAULT 0,
  base_role VARCHAR(20) NOT NULL DEFAULT 'employee',
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  created_by INT NULL,
  updated_by INT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_role_code (code),
  UNIQUE KEY uq_role_name (name)
)$e",
        'permissions' => "CREATE TABLE IF NOT EXISTS permissions (
  perm_key VARCHAR(80) NOT NULL,
  module VARCHAR(20) NOT NULL,
  page VARCHAR(40) NOT NULL,
  action VARCHAR(20) NOT NULL,
  label VARCHAR(120) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (perm_key),
  KEY idx_perm_page (page)
)$e",
        'role_permissions' => "CREATE TABLE IF NOT EXISTS role_permissions (
  role_id INT NOT NULL,
  perm_key VARCHAR(80) NOT NULL,
  PRIMARY KEY (role_id, perm_key)
)$e",
        'user_permission_overrides' => "CREATE TABLE IF NOT EXISTS user_permission_overrides (
  user_id INT NOT NULL,
  perm_key VARCHAR(80) NOT NULL,
  effect ENUM('allow','deny') NOT NULL,
  created_at DATETIME NULL,
  created_by INT NULL,
  PRIMARY KEY (user_id, perm_key)
)$e",
        'period_closings' => "CREATE TABLE IF NOT EXISTS period_closings (
  id INT NOT NULL AUTO_INCREMENT,
  closing_no VARCHAR(20) NOT NULL,
  pay_group_id INT NOT NULL,
  cutoff_id INT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  branch_id INT NULL,
  employee_group_id INT NULL,
  employee_type_id INT NULL,
  runs INT NOT NULL DEFAULT 0,
  employees INT NOT NULL DEFAULT 0,
  gross DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  deductions DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  net DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  sss_ee DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  sss_er DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  phic_ee DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  phic_er DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  hdmf_ee DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  hdmf_er DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  tax DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  ledger_entries INT NOT NULL DEFAULT 0,
  period_locked TINYINT(1) NOT NULL DEFAULT 0,
  log TEXT NULL,
  backup_confirmed TINYINT(1) NOT NULL DEFAULT 0,
  closed_by INT NULL,
  closed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_closing_no (closing_no),
  KEY idx_closing_pg (pay_group_id, period_end)
)$e",
    ], 'columns' => [
        ['users', 'role_id', 'INT NULL'],
        ['payroll_runs', 'posted_at', 'DATETIME NULL'],
        ['payroll_runs', 'posted_by', 'INT NULL'],
        ['payroll_items', 'posted_at', 'DATETIME NULL'],
        ['payroll_items', 'closing_id', 'INT NULL'],
        ['ledger_entries', 'edited_at', 'DATETIME NULL'],
        ['ledger_entries', 'edited_by', 'INT NULL'],
        ['ledger_entries', 'edit_note', 'VARCHAR(255) NULL'],
    ], 'modify' => [], 'settings' => [], 'seeds' => [], 'data' => [
        'INSERT IGNORE INTO roles (code, name, description, status, is_system, is_super, base_role, created_at, updated_at) VALUES ' . implode(",\n  ", $vals),
        'UPDATE users u JOIN roles r ON r.code = u.role AND r.is_system = 1 SET u.role_id = r.id WHERE u.role_id IS NULL',
        // runs finalized before version 8 were posted when they were finalized
        "UPDATE payroll_runs SET posted_at = IFNULL(finalized_at, NOW()), posted_by = finalized_by WHERE status = 'finalized' AND posted_at IS NULL",
        "UPDATE payroll_items pi JOIN payroll_runs r ON r.id = pi.run_id SET pi.posted_at = r.posted_at WHERE r.status = 'finalized' AND pi.posted_at IS NULL",
    ]];
}

/**
 * Version 9:
 *  - Employee Masterfile: eye color, religion, weight, height, spouse (name, address, contact no.),
 *    father's and mother's name; children with birthdays, educational background and previous
 *    employment history as lists
 *  - YTD Information for Previous Employer (per employee and year), used by the annualized tax,
 *    BIR 2316 and the alphalist
 *  - users.linked_from: an account of this company that belongs to a user of another company who may
 *    switch between the two companies (the link itself is kept in the master database)
 */
function migration_v9(): array
{
    $e = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $m = fn(string $c) => "$c DECIMAL(14,2) NOT NULL DEFAULT 0.00";
    return ['tables' => [
        'employee_children' => "CREATE TABLE IF NOT EXISTS employee_children (
  id INT NOT NULL AUTO_INCREMENT,
  employee_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  birth_date DATE NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_child_emp (employee_id)
)$e",
        'employee_education' => "CREATE TABLE IF NOT EXISTS employee_education (
  id INT NOT NULL AUTO_INCREMENT,
  employee_id INT NOT NULL,
  level VARCHAR(30) NULL,
  school VARCHAR(150) NOT NULL,
  course VARCHAR(150) NULL,
  year_graduated VARCHAR(10) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_educ_emp (employee_id)
)$e",
        'employee_prev_employment' => "CREATE TABLE IF NOT EXISTS employee_prev_employment (
  id INT NOT NULL AUTO_INCREMENT,
  employee_id INT NOT NULL,
  company VARCHAR(150) NOT NULL,
  position VARCHAR(100) NULL,
  date_from DATE NULL,
  date_to DATE NULL,
  reason VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_prevemp_emp (employee_id)
)$e",
        'employee_prev_ytd' => "CREATE TABLE IF NOT EXISTS employee_prev_ytd (
  id INT NOT NULL AUTO_INCREMENT,
  employee_id INT NOT NULL,
  year SMALLINT NOT NULL,
  employer_name VARCHAR(150) NULL,
  employer_tin VARCHAR(20) NULL,
  date_from DATE NULL,
  date_to DATE NULL,
  " . implode(",\n  ", array_map($m, ['gross', 'basic', 'sss', 'hdmf', 'phic', 'other_benefits', 'bonus', 'thirteenth', 'deminimis', 'leave_pay', 'holidays', 'nontax', 'absences', 'np', 'taxable', 'tax'])) . ",
  remarks VARCHAR(255) NULL,
  created_by INT NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_prevytd (employee_id, year)
)$e",
    ], 'columns' => [
        ['employees', 'eye_color', 'VARCHAR(30) NULL'],
        ['employees', 'religion', 'VARCHAR(60) NULL'],
        ['employees', 'weight', 'VARCHAR(20) NULL'],
        ['employees', 'height', 'VARCHAR(20) NULL'],
        ['employees', 'spouse_name', 'VARCHAR(150) NULL'],
        ['employees', 'spouse_address', 'VARCHAR(255) NULL'],
        ['employees', 'spouse_contact', 'VARCHAR(40) NULL'],
        ['employees', 'father_name', 'VARCHAR(150) NULL'],
        ['employees', 'mother_name', 'VARCHAR(150) NULL'],
        ['users', 'linked_from', 'VARCHAR(80) NULL'],
    ], 'modify' => [], 'settings' => [], 'seeds' => [], 'data' => []];
}

/**
 * Version 10: how each biometric device is reached (built-in ZKTeco protocol, the ZKTeco SDK
 * zkemkeeper.dll on a Windows server, or the office PC agent that pushes the logs with a device
 * token), and the saved column layouts of customizable reports (Employee 201 File Masterlisting).
 */
function migration_v10(): array
{
    $e = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    return ['tables' => [
        'report_layouts' => "CREATE TABLE IF NOT EXISTS report_layouts (
  id INT NOT NULL AUTO_INCREMENT,
  report_key VARCHAR(40) NOT NULL,
  name VARCHAR(80) NOT NULL,
  col_list TEXT NOT NULL,
  opts TEXT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_rlayout_report (report_key)
)$e",
    ], 'columns' => [
        ['bio_devices', 'conn_method', "VARCHAR(10) NOT NULL DEFAULT 'native'"],
        ['bio_devices', 'push_token_hash', 'VARCHAR(64) NULL'],
        ['bio_devices', 'push_token_hint', 'VARCHAR(12) NULL'],
        ['bio_devices', 'auto_post', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['bio_devices', 'last_push_at', 'DATETIME NULL'],
        ['bio_devices', 'device_info', 'VARCHAR(255) NULL'],
    ], 'modify' => [], 'settings' => [], 'seeds' => [], 'data' => []];
}

/** Version 11 (v12 requests): default weekly day-off of the employee. */
function migration_v11(): array
{
    return ['tables' => [], 'columns' => [
        ['employees', 'day_off', "VARCHAR(20) NULL COMMENT 'Default weekly day-off: weekdays 0 (Sunday) to 6, comma separated; applied on 7-day shifts'"],
    ], 'modify' => [], 'settings' => [], 'seeds' => [], 'data' => []];
}

/**
 * Version 12 (v13 requests): "All companies / branches" and "All departments" of the user's employee
 * assignment. From now on an account with nothing ticked sees no employee; the accounts that had
 * nothing ticked before (= all employees under the old rule) get both "All" boxes, so they keep
 * exactly what they saw. The update runs once (only while the database is below version 12).
 */
function migration_v12(): array
{
    return ['tables' => [], 'columns' => [
        ['users', 'scope_all_branches', "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Sees the employees of every company / branch'"],
        ['users', 'scope_all_units', "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Sees the employees of every department'"],
    ], 'modify' => [], 'settings' => [], 'seeds' => [], 'data' => [
        "UPDATE users u SET u.scope_all_branches = 1, u.scope_all_units = 1
         WHERE NOT EXISTS (SELECT 1 FROM user_scopes s WHERE s.user_id = u.id)
           AND (SELECT COUNT(*) FROM settings v WHERE v.skey = 'db_version' AND v.svalue + 0 < 12) > 0",
    ]];
}

/**
 * Version 13 (v14 requests): Hold of fixed transactions, payroll reprocessing by employees / companies /
 * departments, Memoranda / Announcements (templates, memos, design) and the company logo on reports.
 */
function migration_v13(): array
{
    $e = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    return ['tables' => [
        'memo_templates' => "CREATE TABLE IF NOT EXISTS memo_templates (
  id INT NOT NULL AUTO_INCREMENT,
  code VARCHAR(40) NOT NULL,
  name VARCHAR(120) NOT NULL,
  kind VARCHAR(20) NOT NULL DEFAULT 'memo',
  category VARCHAR(60) NOT NULL DEFAULT 'General',
  subject VARCHAR(200) NULL,
  body MEDIUMTEXT NULL,
  is_sample TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memo_tpl_code (code)
)$e",
        'memos' => "CREATE TABLE IF NOT EXISTS memos (
  id INT NOT NULL AUTO_INCREMENT,
  memo_no VARCHAR(30) NOT NULL,
  kind VARCHAR(20) NOT NULL DEFAULT 'memo',
  template_id INT NULL,
  subject VARCHAR(200) NOT NULL,
  to_line VARCHAR(255) NULL,
  from_line VARCHAR(255) NULL,
  cc_line VARCHAR(255) NULL,
  memo_date DATE NOT NULL,
  body MEDIUMTEXT NULL,
  signatory_name VARCHAR(120) NULL,
  signatory_title VARCHAR(120) NULL,
  branch_id INT NULL,
  audience VARCHAR(20) NOT NULL DEFAULT 'internal',
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  issued_at DATETIME NULL,
  issued_by INT NULL,
  copied_from INT NULL,
  created_by INT NULL,
  created_at DATETIME NULL,
  updated_by INT NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_memo_no (memo_no),
  KEY idx_memo_status (status, memo_date),
  KEY idx_memo_date (memo_date)
)$e",
    ], 'columns' => [
        ['employee_fixed_trans', 'held_at', 'DATETIME NULL'],
        ['employee_fixed_trans', 'held_by', 'INT NULL'],
        ['payroll_runs', 'calc_emp_ids', 'TEXT NULL'],
        ['payroll_runs', 'calc_branch_ids', 'VARCHAR(255) NULL'],
        ['payroll_runs', 'calc_unit_ids', 'VARCHAR(255) NULL'],
    ], 'modify' => [
        ['employee_fixed_trans', 'status', "ENUM('active','stopped','hold') NOT NULL DEFAULT 'active'", 'hold'],
    ], 'settings' => [
        ['report_logo_enabled', '0', 'Add Company Logo to Reports', 'branding', 'bool', 'System Settings, Company logo on reports', 1],
        ['report_logo_reports', '', 'Reports with the company logo', 'branding', 'text', 'all, or the routes of the reports', 2],
        ['report_logo_path', '', 'Company logo file', 'branding', 'text', '', 3],
    ], 'seeds' => [], 'data' => []];
}

/**
 * Version 14: pre-arranged overtime of the Shift Table (Overtime In / Overtime Out, approved
 * automatically), the overtime logs and minutes of the daily time record, and the NTE to-do's
 * (employees flagged for HR review / NTE, listed to the immediate superior and HR).
 */
function migration_v14(): array
{
    $e = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    return ['tables' => [
        'nte_todos' => "CREATE TABLE IF NOT EXISTS nte_todos (
  id INT NOT NULL AUTO_INCREMENT,
  employee_id INT NOT NULL,
  period_month DATE NOT NULL,
  reason VARCHAR(40) NOT NULL,
  late_count INT NOT NULL DEFAULT 0,
  late_minutes INT NOT NULL DEFAULT 0,
  supervisor_id INT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  case_id INT NULL,
  note VARCHAR(255) NULL,
  handled_by INT NULL,
  handled_at DATETIME NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nte_todo (employee_id, period_month, reason),
  KEY idx_nte_status (status),
  KEY idx_nte_sup (supervisor_id, status)
)$e",
    ], 'columns' => [
        ['shifts', 'ot_in', "TIME NULL COMMENT 'Overtime In: start of the pre-arranged overtime after the shift'"],
        ['shifts', 'ot_out', "TIME NULL COMMENT 'Overtime Out: end of the pre-arranged overtime'"],
        ['dtr', 'ot_in', 'DATETIME NULL'],
        ['dtr', 'ot_out', 'DATETIME NULL'],
        ['dtr', 'pre_ot_minutes', "INT NOT NULL DEFAULT 0 COMMENT 'Pre-arranged overtime credited (approved automatically)'"],
        ['dtr', 'ot_late_minutes', 'INT NOT NULL DEFAULT 0'],
        ['dtr', 'ot_ut_minutes', 'INT NOT NULL DEFAULT 0'],
    ], 'modify' => [], 'settings' => [], 'seeds' => [], 'data' => [
        "UPDATE settings SET help = 'Flags employee for HR review / NTE, and lists the employee in the To-do''s of the dashboard of the immediate superior and HR.' WHERE skey = 'habitual_tardy_count'",
        "UPDATE settings SET help = 'Also listed in the NTE To-do''s of the dashboard of the immediate superior and HR.' WHERE skey = 'tardy_cap_minutes' AND (help IS NULL OR help = '')",
    ]];
}

/**
 * Version 15: employment status Resigned / Terminated / Retired, the edited and deleted items of the final pay,
 * the No. of Days to reset Penalty of the Code of Conduct, the 13th month basis (Calendar or Fiscal Year) and
 * the company events of the employee calendar.
 */
function migration_v15(): array
{
    $e = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    return ['tables' => [
        'final_pay_overrides' => "CREATE TABLE IF NOT EXISTS final_pay_overrides (
  id INT NOT NULL AUTO_INCREMENT,
  final_pay_id INT NOT NULL,
  line_key VARCHAR(120) NOT NULL,
  action VARCHAR(10) NOT NULL DEFAULT 'edit',
  description VARCHAR(255) NULL,
  quantity DECIMAL(10,2) NULL,
  amount DECIMAL(12,2) NULL,
  original_description VARCHAR(255) NULL,
  original_amount DECIMAL(12,2) NULL,
  created_by INT NULL,
  created_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fp_line (final_pay_id, line_key)
)$e",
        'company_events' => "CREATE TABLE IF NOT EXISTS company_events (
  id INT NOT NULL AUTO_INCREMENT,
  title VARCHAR(150) NOT NULL,
  kind VARCHAR(20) NOT NULL DEFAULT 'event',
  event_date DATE NOT NULL,
  end_date DATE NULL,
  start_time TIME NULL,
  end_time TIME NULL,
  location VARCHAR(150) NULL,
  description TEXT NULL,
  branch_id INT NULL,
  org_unit_id INT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_event_date (event_date, end_date)
)$e",
    ], 'columns' => [
        ['conduct_offenses', 'reset_days', "INT NULL COMMENT 'No. of days to reset the penalty: an offense later than this after the last one counts as a first offense'"],
    ], 'modify' => [
        ['employees', 'employment_status', "ENUM('probationary','regular','project_based','contractual','resigned','terminated','retired') NOT NULL DEFAULT 'probationary'", 'retired'],
    ], 'settings' => [
        ['thirteenth_basis', 'calendar', 'Basis of the 13th month computation', 'payroll_13th', 'select:calendar=Calendar Year,fiscal=Fiscal Year', 'Calendar Year: January to December; Fiscal Year: From / To dates of exactly one year', 1],
        ['thirteenth_fy_from', '', 'Fiscal year of the 13th month: From date', 'payroll_13th', 'text', 'YYYY-MM-DD', 2],
        ['thirteenth_fy_to', '', 'Fiscal year of the 13th month: To date', 'payroll_13th', 'text', 'YYYY-MM-DD, exactly one year after the From date less a day', 3],
    ], 'seeds' => [], 'data' => [
        // separated employees take the employment status of their separation type
        "UPDATE employees SET employment_status = 'resigned' WHERE status = 'separated' AND separation_type = 'resignation'",
        "UPDATE employees SET employment_status = 'retired' WHERE status = 'separated' AND separation_type = 'retirement'",
        "UPDATE employees SET employment_status = 'terminated' WHERE status = 'separated' AND separation_type IN ('termination', 'dismissal')",
    ]];
}

/**
 * Version 16 (system v18): Cancel Calculation of the final pay (status cancelled, who / when / why) and the
 * day-off of a schedule override that only changes the shift (is_rest_day NULL = follow the rest days of
 * the shift, or the employee's default day-off on a 7-day shift).
 */
function migration_v16(): array
{
    return ['tables' => [], 'columns' => [
        ['final_pays', 'cancelled_at', 'DATETIME NULL'],
        ['final_pays', 'cancelled_by', 'INT NULL'],
        ['final_pays', 'cancel_reason', 'VARCHAR(255) NULL'],
    ], 'modify' => [
        ['final_pays', 'status', "ENUM('draft','approved','released','cancelled') NOT NULL DEFAULT 'draft'", 'cancelled'],
        ['employee_schedules', 'is_rest_day', "TINYINT(1) NULL DEFAULT NULL COMMENT 'NULL = follow the shift / default day-off, 1 = rest day, 0 = working day'", 'NULL'],
    ], 'settings' => [], 'seeds' => [], 'data' => [
        // a per-date shift change never meant to cancel the day-off: those dates follow the day-off of the shift / the masterfile
        "UPDATE employee_schedules SET is_rest_day = NULL WHERE is_rest_day = 0 AND shift_id IS NOT NULL",
    ]];
}

/**
 * Version 17 (system v19): the order of the import configurations (ZKTeco attlog.dat, Generic CSV, Space-delimited).
 */
function migration_v17(): array
{
    return ['tables' => [], 'columns' => [
        ['import_profiles', 'sort_order', "SMALLINT NOT NULL DEFAULT 0 COMMENT 'Position in the Import configuration lists'"],
    ], 'modify' => [], 'settings' => [], 'seeds' => [], 'data' => [
        "UPDATE import_profiles SET sort_order = 1 WHERE sort_order = 0 AND name LIKE 'ZKTeco attlog%'",
        "UPDATE import_profiles SET sort_order = 2 WHERE sort_order = 0 AND name LIKE 'Generic CSV%'",
        "UPDATE import_profiles SET sort_order = 3 WHERE sort_order = 0 AND name LIKE 'Space-delimited%'",
        "UPDATE import_profiles SET sort_order = 10 + id WHERE sort_order = 0",
    ]];
}

/**
 * Version 18 (system v20): the Employee Loan / Deduction Request of the employee portal and its approval
 * (immediate superior, HR manager or administrator); an approved request becomes a transaction with terms.
 */
function migration_v18(): array
{
    $e = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    return ['tables' => [
        'loan_requests' => "CREATE TABLE IF NOT EXISTS loan_requests (
  id INT NOT NULL AUTO_INCREMENT,
  request_no VARCHAR(20) NOT NULL,
  employee_id INT NOT NULL,
  deduction_type_id INT NOT NULL,
  date_applied DATE NOT NULL,
  amount_applied DECIMAL(12,2) NOT NULL DEFAULT 0,
  terms INT NOT NULL DEFAULT 1 COMMENT 'Term in paydays',
  int_rate DECIMAL(7,3) NOT NULL DEFAULT 0 COMMENT 'Monthly interest rate (%), from the Employee Transactions Table',
  interest_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  trans_term VARCHAR(12) NOT NULL DEFAULT 'every_pay' COMMENT 'Mode of payment: every_pay, first_pay ... fourth_pay',
  pp_principal DECIMAL(12,2) NOT NULL DEFAULT 0,
  pp_interest DECIMAL(12,2) NOT NULL DEFAULT 0,
  purpose VARCHAR(255) NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'draft' COMMENT 'draft, submitted, approved, rejected, cancelled',
  submitted_at DATETIME NULL,
  amount_approved DECIMAL(12,2) NULL,
  terms_approved INT NULL,
  int_rate_approved DECIMAL(7,3) NULL,
  interest_approved DECIMAL(12,2) NULL,
  trans_term_approved VARCHAR(12) NULL,
  start_date DATE NULL COMMENT 'Start of payment, set by the approver',
  decided_by INT NULL,
  decided_at DATETIME NULL,
  decision_remarks VARCHAR(255) NULL,
  term_trans_id INT NULL COMMENT 'The transaction with terms created on approval',
  created_by INT NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_loan_req_no (request_no),
  KEY idx_loan_req_emp (employee_id, status),
  KEY idx_loan_req_status (status)
)$e",
    ], 'columns' => [
        ['deduction_types', 'ess_request', "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Offered in the Loan / Deduction Request of the employee portal'"],
    ], 'modify' => [], 'settings' => [
        ['loan_request_max_terms', '48', 'Loan / Deduction Request: longest term an employee may apply for (paydays)', 'payroll', 'number', 'Paydays; the approver may still approve another term.', 40],
    ], 'seeds' => [], 'data' => [
        // the loans and the cash advance / other deductions an employee asks for (not the statutory contributions, tax or accountabilities)
        "UPDATE deduction_types SET ess_request = 1 WHERE ess_request = 0 AND is_system = 0 AND category IN ('loan', 'adhoc', 'recurring')",
    ]];
}

/**
 * Version 19 (v22 requests): import of an attendance record report in Excel (layout "report", e.g. the JJM Logs:
 * one block per employee, the punches under the day numbers) and, for every import configuration, the minimum
 * minutes between two logs of the same employee-day. Adds the "JJM Logs" configuration.
 */
function migration_v19(): array
{
    return ['tables' => [], 'columns' => [
        ['import_profiles', 'rp_id_label', "VARCHAR(40) NULL COMMENT 'Report layout: label of the employee ID (the ID is the next cell)'"],
        ['import_profiles', 'rp_name_label', "VARCHAR(40) NULL COMMENT 'Report layout: label of the employee name'"],
        ['import_profiles', 'rp_period_label', "VARCHAR(40) NULL COMMENT 'Report layout: label of the period (gives the year and month)'"],
        ['import_profiles', 'rp_date_format', "VARCHAR(20) NULL COMMENT 'Report layout: date format of the period'"],
        ['import_profiles', 'rp_day_row', "TINYINT NOT NULL DEFAULT 1 COMMENT 'Report layout: rows below the ID row with the day numbers'"],
        ['import_profiles', 'rp_time_row', "TINYINT NOT NULL DEFAULT 2 COMMENT 'Report layout: rows below the ID row of the first times'"],
        ['import_profiles', 'rp_sheet', "TINYINT NOT NULL DEFAULT 1 COMMENT 'Report layout: worksheet number'"],
        ['import_profiles', 'min_gap', "SMALLINT NOT NULL DEFAULT 0 COMMENT 'Minimum minutes between two logs of the same employee-day (0 = keep all)'"],
    ], 'modify' => [
        ['import_profiles', 'layout', "ENUM('delimited','fixed','report') NOT NULL DEFAULT 'delimited' COMMENT 'Column layout of the log file'", 'report'],
    ], 'settings' => [], 'seeds' => [], 'data' => [
        "INSERT INTO import_profiles (name, layout, delimiter, has_header, col_bio_id, datetime_format, notes, rp_id_label, rp_name_label, rp_period_label, rp_date_format,
                                      rp_day_row, rp_time_row, rp_sheet, min_gap, is_default, sort_order)
         SELECT 'JJM Logs', 'report', 'tab', 0, 1, 'Y-m-d H:i:s', 'Employee Attendance Record (.xls): User ID / Name / Department, the day numbers, then the times of each day',
                'User ID:', 'Name:', 'Attendance date:', 'Y-m-d', 1, 2, 1, 5, 0, m.so
         FROM (SELECT COALESCE(MAX(sort_order), 0) + 1 AS so, SUM(name = 'JJM Logs') AS n FROM import_profiles) m WHERE COALESCE(m.n, 0) = 0",
    ]];
}

/** Every upgrade step in order: version => definitions. */
function migrations_all(): array
{
    return [2 => 'migration_v2', 3 => 'migration_v3', 4 => 'migration_v4', 5 => 'migration_v5', 6 => 'migration_v6', 7 => 'migration_v7', 8 => 'migration_v8', 9 => 'migration_v9', 10 => 'migration_v10', 11 => 'migration_v11', 12 => 'migration_v12', 13 => 'migration_v13', 14 => 'migration_v14', 15 => 'migration_v15', 16 => 'migration_v16', 17 => 'migration_v17', 18 => 'migration_v18', 19 => 'migration_v19'];
}

function mig_table_exists(string $t): bool
{
    return (bool)val('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
}

function mig_column_exists(string $t, string $c): bool
{
    return (bool)val('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
}

/** Apply one set of definitions (idempotent). */
function mig_apply(array $m, array &$log): void
{
    foreach ($m['tables'] as $t => $sql) {
        if (!mig_table_exists($t)) {
            db()->exec($sql);
            $log[] = "Created table $t";
        }
    }
    // add columns table by table (one ALTER per table keeps MySQL 5.5 fast)
    $byTable = [];
    foreach ($m['columns'] as $c) {
        if (!mig_column_exists($c[0], $c[1])) {
            $byTable[$c[0]][] = 'ADD COLUMN `' . $c[1] . '` ' . $c[2];
        }
    }
    foreach ($byTable as $t => $adds) {
        db()->exec("ALTER TABLE `$t` " . implode(', ', $adds));
        $log[] = "Added " . count($adds) . " column(s) to $t";
    }
    foreach ($m['modify'] as $x) {
        $type = (string)val('SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$x[0], $x[1]]);
        if ($type !== '' && strpos($type, "'" . $x[3] . "'") === false) {
            db()->exec("ALTER TABLE `{$x[0]}` MODIFY COLUMN `{$x[1]}` {$x[2]}");
            $log[] = "Extended {$x[0]}.{$x[1]}";
        }
    }
    if ($m['settings']) {
        $st = db()->prepare('INSERT IGNORE INTO settings (skey, svalue, label, grp, input_type, help, sort_order) VALUES (?,?,?,?,?,?,?)');
        foreach ($m['settings'] as $s) {
            $st->execute($s);
        }
    }
    foreach ($m['seeds'] as $t => $sql) {
        if (!(int)val("SELECT COUNT(*) FROM `$t`")) {
            db()->exec($sql);
            $log[] = "Seeded $t";
        }
    }
    foreach ($m['data'] as $sql) {
        db()->exec($sql);
    }
}

/** Apply pending upgrades. Returns a log of what was done. */
function db_upgrade(): array
{
    $log = [];
    $from = db_version();
    if ($from >= DB_VERSION) {
        return $log;
    }
    // one upgrade at a time per database (MySQL named lock; every company database upgrades on its own)
    $lock = 'ihris_upg_' . substr(md5(active_db_name()), 0, 16);
    $got = (int)val('SELECT GET_LOCK(?, 30)', [$lock]);
    try {
        if (db_version() >= DB_VERSION) {
            return $log;
        }
        foreach (migrations_all() as $ver => $fn) {
            if ($ver <= $from) {
                continue;
            }
            mig_apply($fn(), $log);
            if ($ver === 2) {
                $n = legacy_summaries_to_hours();
                if ($n) {
                    $log[] = "Converted $n existing attendance summaries to hours";
                }
            }
            q("INSERT INTO settings (skey, svalue, label, grp, input_type, sort_order) VALUES ('db_version', ?, 'Database version', 'system', 'hidden', 0)
               ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)", [(string)$ver]);
            $log[] = 'Database upgraded to version ' . $ver;
        }
        setting('__reload__');
        audit('system', 'db_upgrade', 'settings', 'db_version', ['from' => $from, 'to' => DB_VERSION, 'steps' => count($log)]);
    } finally {
        if ($got) {
            val('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
    return $log;
}

/**
 * Summaries created by version 1 (days / minutes) get their hour fields filled so that
 * existing cutoffs keep working with the hours-based payroll engine.
 */
function legacy_summaries_to_hours(): int
{
    $hpd = (float)(val("SELECT svalue FROM settings WHERE skey = 'hours_per_day'") ?: 8);
    $rows = rows("SELECT * FROM attendance_summaries WHERE hours_work = 0 AND absent_hrs = 0 AND vl_hrs = 0 AND ot_reg = 0 AND rd_hrs = 0
                  AND lh_hrs = 0 AND sh_hrs = 0 AND (days_worked > 0 OR absent_days > 0 OR leave_paid_days > 0 OR reg_minutes > 0)");
    $map = [
        'ordinary' => ['reg' => null, 'ot' => 'ot_reg', 'nd' => 'np_reg', 'ndot' => 'np_reg_ot'],
        'rest_day' => ['reg' => 'rd_hrs', 'ot' => 'rd_ot', 'nd' => 'np_rd', 'ndot' => 'np_rd_ot'],
        'regular_holiday' => ['reg' => 'lh_hrs', 'ot' => 'lh_ot', 'nd' => 'np_lh', 'ndot' => 'np_lh_ot'],
        'double_holiday' => ['reg' => 'lh_hrs', 'ot' => 'lh_ot', 'nd' => 'np_lh', 'ndot' => 'np_lh_ot'],
        'regular_rest' => ['reg' => 'lh_rd', 'ot' => 'lh_rd_ot', 'nd' => 'np_lh_rd', 'ndot' => 'np_lh_rd_ot'],
        'special_holiday' => ['reg' => 'sh_hrs', 'ot' => 'sh_ot', 'nd' => 'np_sh', 'ndot' => 'np_sh_ot'],
        'special_rest' => ['reg' => 'sh_rd', 'ot' => 'sh_rd_ot', 'nd' => 'np_sh_rd', 'ndot' => 'np_sh_rd_ot'],
    ];
    $n = 0;
    foreach ($rows as $s) {
        $u = [
            'hours_work' => ((float)$s['days_worked'] + (float)$s['absent_days'] + (float)$s['suspended_days']) * $hpd,
            'absent_hrs' => ((float)$s['absent_days'] + (float)$s['suspended_days'] + (float)$s['leave_unpaid_days']) * $hpd,
            'late_hrs' => round((int)$s['late_minutes'] / 60, 2),
            'undertime_hrs' => round((int)$s['undertime_minutes'] / 60, 2),
            'vl_hrs' => (float)$s['leave_paid_days'] * $hpd,
            'holiday_hrs' => (float)$s['reg_holiday_unworked'] * $hpd,
        ];
        $u['hours_work'] += (float)$s['leave_unpaid_days'] * $hpd;
        foreach (rows('SELECT * FROM attendance_summary_lines WHERE summary_id = ?', [$s['id']]) as $L) {
            $mm = $map[$L['day_type']] ?? $map['ordinary'];
            if ($mm['reg']) {
                $u[$mm['reg']] = ($u[$mm['reg']] ?? 0) + (int)$L['reg_minutes'] / 60;
            }
            $u[$mm['ot']] = ($u[$mm['ot']] ?? 0) + (int)$L['ot_minutes'] / 60;
            $u[$mm['nd']] = ($u[$mm['nd']] ?? 0) + (int)$L['nd_minutes'] / 60;
            $u[$mm['ndot']] = ($u[$mm['ndot']] ?? 0) + (int)$L['nd_ot_minutes'] / 60;
        }
        foreach ($u as $k => $v) {
            $u[$k] = round($v, 2);
        }
        $u['source'] = 'ams';
        update('attendance_summaries', $u, 'id = ?', [$s['id']]);
        $n++;
    }
    return $n;
}

/**
 * Stand-alone SQL script of the upgrade (for Navicat / phpMyAdmin), MySQL 5.5 compatible.
 * Column additions go through a temporary stored procedure that checks information_schema.
 */
function db_upgrade_sql(int $version = DB_VERSION): string
{
    $fn = migrations_all()[$version] ?? 'migration_v2';
    $m = $fn();
    $title = ['2' => 'M.A.D.A.S. attendance & payroll integration', '3' => "employee's transactions with terms, shift log count, employee CSV import",
        '4' => 'CSV imports of transactions, deductions and benefits, benefit posting term, deduction source, log type correction',
        '5' => 'system name G-SMaRT HRIS, break-log and duplicate-log policy notes',
        '6' => 'multi-company: join requests by Company Code, Super Admin support account',
        '7' => 'attendance closing, payroll computation parameters, fixed transaction terms, page-level user access',
        '8' => 'dynamic roles and permissions, user permission overrides, period closing, editable ledger entries',
        '9' => 'employee family, education and previous employment, previous employer YTD, linked accounts of multi-company users',
        '10' => 'ZKTeco connection methods (built-in protocol, zkemkeeper SDK, office PC agent) and saved layouts of customizable reports',
        '11' => 'default weekly day-off of the employee (applied on 7-day shifts)',
        '12' => '"All companies / branches" and "All departments" of the employee assignment of the users',
        '13' => 'hold of fixed transactions, payroll reprocessing filters, memoranda / announcements, company logo on reports',
        '14' => 'pre-arranged overtime of the shifts (Overtime In / Overtime Out), NTE to-do list of the immediate superior and HR',
        '15' => 'employment status Resigned / Terminated / Retired, final pay item edits, penalty reset days, 13th month basis, company events',
        '16' => 'Cancel Calculation of the final pay, day-off of the shift on per-date shift changes',
        '17' => 'order of the import configurations',
        '18' => 'Employee Loan / Deduction Request and its approval',
        '19' => 'Attendance record report import (JJM Logs) and the minimum minutes between logs'][(string)$version] ?? '';
    $q = fn($v) => $v === null ? 'NULL' : "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$v) . "'";
    $out = [];
    $out[] = "-- =====================================================================";
    $out[] = "--  G-SMaRT HRIS upgrade to database version " . $version . " ($title)";
    $out[] = "--  Target: MySQL 5.5+ / MariaDB 5.5+.  Safe to run more than once; keeps all existing data.";
    $out[] = "--  Select your HRIS database first (e.g. hris_web), then import this file.";
    $out[] = "--  Not needed if you simply open the updated site: index.php upgrades the database automatically.";
    $out[] = "-- =====================================================================";
    $out[] = "SET NAMES utf8mb4;";
    $out[] = "SET time_zone = '+08:00';";
    $out[] = "SET FOREIGN_KEY_CHECKS = 0;";
    $out[] = "";
    foreach ($m['tables'] as $t => $sql) {
        $out[] = "-- $t";
        $out[] = $sql . ';';
        $out[] = '';
    }
    $out[] = "DROP PROCEDURE IF EXISTS ihris_add_column;";
    $out[] = "DELIMITER $$";
    $out[] = "CREATE PROCEDURE ihris_add_column(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_def TEXT)";
    $out[] = "BEGIN";
    $out[] = "  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_column) THEN";
    $out[] = "    SET @ihris_sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_def);";
    $out[] = "    PREPARE ihris_stmt FROM @ihris_sql;";
    $out[] = "    EXECUTE ihris_stmt;";
    $out[] = "    DEALLOCATE PREPARE ihris_stmt;";
    $out[] = "  END IF;";
    $out[] = "END$$";
    $out[] = "DELIMITER ;";
    $out[] = "";
    foreach ($m['columns'] as $c) {
        $out[] = 'CALL ihris_add_column(' . $q($c[0]) . ', ' . $q($c[1]) . ', ' . $q($c[2]) . ');';
    }
    $out[] = "DROP PROCEDURE IF EXISTS ihris_add_column;";
    $out[] = "";
    foreach ($m['modify'] as $x) {
        $out[] = "ALTER TABLE `{$x[0]}` MODIFY COLUMN `{$x[1]}` {$x[2]};";
    }
    $out[] = "";
    if ($m['settings']) {
        $out[] = "INSERT IGNORE INTO settings (skey, svalue, label, grp, input_type, help, sort_order) VALUES";
        $vals = [];
        foreach ($m['settings'] as $s) {
            $vals[] = '(' . implode(',', array_map($q, $s)) . ')';
        }
        $out[] = implode(",\n", $vals) . ';';
        $out[] = "";
    }
    // reference data: inserted only into empty tables (guarded inside a temporary procedure)
    if ($m['seeds']) {
        $out[] = "DROP PROCEDURE IF EXISTS ihris_seed;";
        $out[] = "DELIMITER $$";
        $out[] = "CREATE PROCEDURE ihris_seed()";
        $out[] = "BEGIN";
        foreach ($m['seeds'] as $t => $sql) {
            $out[] = "  IF NOT EXISTS (SELECT 1 FROM `$t`) THEN";
            $out[] = '    ' . str_replace("\n", "\n    ", $sql) . ';';
            $out[] = "  END IF;";
        }
        $out[] = "END$$";
        $out[] = "DELIMITER ;";
        $out[] = "CALL ihris_seed();";
        $out[] = "DROP PROCEDURE IF EXISTS ihris_seed;";
        $out[] = '';
    }
    foreach ($m['data'] as $sql) {
        $out[] = preg_replace('/\s+/', ' ', $sql) . ';';
    }
    $out[] = "";
    $out[] = "INSERT INTO settings (skey, svalue, label, grp, input_type, sort_order) VALUES ('db_version', '" . $version . "', 'Database version', 'system', 'hidden', 0)";
    $out[] = "  ON DUPLICATE KEY UPDATE svalue = GREATEST(svalue + 0, VALUES(svalue) + 0);";
    $out[] = "SET FOREIGN_KEY_CHECKS = 1;";
    if ($version === 2) {
        $out[] = "-- Existing attendance summaries are converted to hours automatically the first time the site is opened.";
    }
    return implode("\n", $out) . "\n";
}
