-- =====================================================================
--  I-HRIS upgrade to database version 2 (M.A.D.A.S. attendance & payroll integration)
--  Target: MySQL 5.5+ / MariaDB 5.5+.  Safe to run more than once; keeps all existing data.
--  Select your I-HRIS database first (e.g. hris_web), then import this file.
--  Not needed if you simply open the updated site: index.php upgrades the database automatically.
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '+08:00';
SET FOREIGN_KEY_CHECKS = 0;

-- emp_types
CREATE TABLE IF NOT EXISTS emp_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  conversion_factor DECIMAL(10,4) NOT NULL DEFAULT 26 COMMENT 'Days per month: daily rate = monthly rate / factor',
  hours_per_day DECIMAL(5,2) NOT NULL DEFAULT 8,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- emp_groups
CREATE TABLE IF NOT EXISTS emp_groups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  log_group TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Attendance logged by group',
  cost_center VARCHAR(40) NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- class_groups
CREATE TABLE IF NOT EXISTS class_groups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- rank_categories
CREATE TABLE IF NOT EXISTS rank_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- classifications
CREATE TABLE IF NOT EXISTS classifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- jobs
CREATE TABLE IF NOT EXISTS jobs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  category VARCHAR(80) NULL,
  sub_category VARCHAR(80) NULL,
  ot_offset TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'OT/OL: overtime is credited as offset leave instead of paid',
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- exemption_codes
CREATE TABLE IF NOT EXISTS exemption_codes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  tax_exempt TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Compensation exempt from withholding tax (e.g. MWE)',
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- locations
CREATE TABLE IF NOT EXISTS locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- gl_accounts
CREATE TABLE IF NOT EXISTS gl_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  account_type ENUM('asset','liability','equity','income','expense') NOT NULL DEFAULT 'expense',
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- earning_types
CREATE TABLE IF NOT EXISTS earning_types (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- fixed_trans_codes
CREATE TABLE IF NOT EXISTS fixed_trans_codes (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- employee_fixed_trans
CREATE TABLE IF NOT EXISTS employee_fixed_trans (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- employee_transactions
CREATE TABLE IF NOT EXISTS employee_transactions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- transaction_payments
CREATE TABLE IF NOT EXISTS transaction_payments (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- bio_devices
CREATE TABLE IF NOT EXISTS bio_devices (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- log_staging
CREATE TABLE IF NOT EXISTS log_staging (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- attendance_summary_branches
CREATE TABLE IF NOT EXISTS attendance_summary_branches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  summary_id INT NOT NULL,
  branch_id INT NOT NULL,
  `hours_work` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `absent_hrs` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `late_hrs` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `undertime_hrs` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `vl_hrs` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `sl_hrs` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `el_hrs` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `ol_hrs` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `pm_hrs` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `holiday_hrs` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `ot_reg` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `rd_hrs` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `lh_hrs` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `sh_hrs` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `rd_ot` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `lh_ot` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `sh_ot` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `lh_rd` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `sh_rd` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `lh_rd_ot` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `sh_rd_ot` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `np_reg` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `np_rd` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `np_lh` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `np_sh` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `np_reg_ot` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `np_rd_ot` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `np_lh_ot` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `np_sh_ot` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `np_lh_rd` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `np_sh_rd` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `np_lh_rd_ot` DECIMAL(9,2) NOT NULL DEFAULT 0,
  `np_sh_rd_ot` DECIMAL(9,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_asb (summary_id, branch_id),
  CONSTRAINT fk_asb_sum FOREIGN KEY (summary_id) REFERENCES attendance_summaries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- user_scopes
CREATE TABLE IF NOT EXISTS user_scopes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  scope_type ENUM('branch','org_unit') NOT NULL,
  ref_id INT NOT NULL,
  UNIQUE KEY uq_scope (user_id, scope_type, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- pay_rates
CREATE TABLE IF NOT EXISTS pay_rates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  label VARCHAR(80) NOT NULL,
  group_name VARCHAR(40) NOT NULL,
  day_pct DECIMAL(7,3) NOT NULL DEFAULT 100 COMMENT '% of hourly rate per hour (Hourly Rate x Hr x Rate)',
  night_pct DECIMAL(7,3) NOT NULL DEFAULT 0 COMMENT 'Night premium % of hourly rate per night hour in this category',
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP PROCEDURE IF EXISTS ihris_add_column;
DELIMITER $$
CREATE PROCEDURE ihris_add_column(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_def TEXT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_column) THEN
    SET @ihris_sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_def);
    PREPARE ihris_stmt FROM @ihris_sql;
    EXECUTE ihris_stmt;
    DEALLOCATE PREPARE ihris_stmt;
  END IF;
END$$
DELIMITER ;

CALL ihris_add_column('attendance_cutoffs', 'pay_date', 'DATE NULL');
CALL ihris_add_column('attendance_cutoffs', 'period_no', 'TINYINT NULL COMMENT \'1 = 1st pay of the month, 2 = 2nd pay...\'');
CALL ihris_add_column('attendance_cutoffs', 'period_month', 'TINYINT NULL');
CALL ihris_add_column('attendance_cutoffs', 'period_year', 'SMALLINT NULL');
CALL ihris_add_column('attendance_cutoffs', 'description', 'VARCHAR(60) NULL');
CALL ihris_add_column('attendance_summaries', 'source', 'VARCHAR(10) NOT NULL DEFAULT \'ams\'');
CALL ihris_add_column('attendance_summaries', 'remarks', 'VARCHAR(255) NULL');
CALL ihris_add_column('attendance_summaries', 'entered_by', 'INT NULL');
CALL ihris_add_column('attendance_summaries', 'updated_at', 'DATETIME NULL');
CALL ihris_add_column('dtr', 'break_out2', 'DATETIME NULL');
CALL ihris_add_column('dtr', 'break_in2', 'DATETIME NULL');
CALL ihris_add_column('dtr', 'absent_minutes', 'INT NOT NULL DEFAULT 0');
CALL ihris_add_column('dtr', 'leave_minutes', 'INT NOT NULL DEFAULT 0');
CALL ihris_add_column('dtr', 'leave_bucket', 'VARCHAR(4) NULL');
CALL ihris_add_column('dtr', 'holiday_minutes', 'INT NOT NULL DEFAULT 0');
CALL ihris_add_column('dtr', 'penalty_minutes', 'INT NOT NULL DEFAULT 0');
CALL ihris_add_column('dtr', 'log_status', 'VARCHAR(40) NULL');
CALL ihris_add_column('shifts', 'break_out1', 'TIME NULL');
CALL ihris_add_column('shifts', 'break_in1', 'TIME NULL');
CALL ihris_add_column('shifts', 'break_out2', 'TIME NULL');
CALL ihris_add_column('shifts', 'break_in2', 'TIME NULL');
CALL ihris_add_column('shifts', 'required_hours', 'DECIMAL(5,2) NULL COMMENT \'Total required duty hours (NULL = normal hours per day)\'');
CALL ihris_add_column('shifts', 'np_hours', 'DECIMAL(5,2) NULL COMMENT \'Total night premium hours within the shift\'');
CALL ihris_add_column('shifts', 'flex_minutes', 'INT NOT NULL DEFAULT 0 COMMENT \'Flexible time-in allowance (+/- minutes)\'');
CALL ihris_add_column('shifts', 'strict_time', 'TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'No grace period on this shift\'');
CALL ihris_add_column('shifts', 'whole_week', 'TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'Whole-week duty: rest days only from employee schedules\'');
CALL ihris_add_column('shifts', 'is_default', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL ihris_add_column('pay_groups', 'sss_mode', 'ENUM(\'every\',\'first\',\'second\') NOT NULL DEFAULT \'every\'');
CALL ihris_add_column('pay_groups', 'hdmf_mode', 'ENUM(\'every\',\'first\',\'second\') NOT NULL DEFAULT \'every\'');
CALL ihris_add_column('pay_groups', 'phic_mode', 'ENUM(\'every\',\'first\',\'second\') NOT NULL DEFAULT \'every\'');
CALL ihris_add_column('pay_groups', 'tax_mode', 'ENUM(\'every\',\'first\',\'second\') NOT NULL DEFAULT \'every\'');
CALL ihris_add_column('pay_groups', 'tax_type', 'ENUM(\'daily\',\'weekly\',\'semi_monthly\',\'monthly\') NULL COMMENT \'Default tax table (NULL = pay frequency)\'');
CALL ihris_add_column('branches', 'company_code', 'VARCHAR(10) NULL');
CALL ihris_add_column('branches', 'branch_code', 'VARCHAR(10) NULL');
CALL ihris_add_column('branches', 'init_code', 'VARCHAR(10) NULL');
CALL ihris_add_column('branches', 'location_id', 'INT NULL');
CALL ihris_add_column('branches', 'approver_name', 'VARCHAR(120) NULL');
CALL ihris_add_column('branches', 'checker_name', 'VARCHAR(120) NULL');
CALL ihris_add_column('branches', 'is_default', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL ihris_add_column('employees', 'employee_type_id', 'INT NULL');
CALL ihris_add_column('employees', 'employee_group_id', 'INT NULL');
CALL ihris_add_column('employees', 'class_group_id', 'INT NULL');
CALL ihris_add_column('employees', 'rank_category_id', 'INT NULL');
CALL ihris_add_column('employees', 'classification_id', 'INT NULL');
CALL ihris_add_column('employees', 'job_id', 'INT NULL');
CALL ihris_add_column('employees', 'exemption_code_id', 'INT NULL');
CALL ihris_add_column('employees', 'location_id', 'INT NULL');
CALL ihris_add_column('employees', 'bank_account_type', 'VARCHAR(20) NULL');
CALL ihris_add_column('benefit_types', 'gl_code', 'VARCHAR(20) NULL');
CALL ihris_add_column('benefit_types', 'no_deduction', 'TINYINT(1) NOT NULL DEFAULT 1 COMMENT \'Not reduced by absences\'');
CALL ihris_add_column('benefit_types', 'ref_ext', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL ihris_add_column('benefit_types', 'given_at', 'ENUM(\'fixed\',\'duty_day_hol\',\'duty_hour_hol\',\'duty_week_hol\',\'duty_day\',\'duty_hour\',\'duty_week\') NOT NULL DEFAULT \'fixed\'');
CALL ihris_add_column('benefit_types', 'bir2316_box', 'VARCHAR(20) NULL');
CALL ihris_add_column('deduction_types', 'int_rate', 'DECIMAL(6,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('deduction_types', 'gl_code', 'VARCHAR(20) NULL');
CALL ihris_add_column('deduction_types', 'bir2316_box', 'VARCHAR(20) NULL');
CALL ihris_add_column('deduction_types', 'ref_ext', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL ihris_add_column('deduction_types', 'register_group', 'ENUM(\'sss_loan\',\'hdmf_loan\',\'company_loan\',\'other\') NOT NULL DEFAULT \'other\'');
CALL ihris_add_column('leave_types', 'summary_bucket', 'ENUM(\'vl\',\'sl\',\'el\',\'ol\',\'pm\',\'none\') NOT NULL DEFAULT \'ol\' COMMENT \'Column of the attendance transaction\'');
CALL ihris_add_column('payroll_items', 'summary_id', 'INT NULL');
CALL ihris_add_column('payroll_items', 'print_count', 'INT NOT NULL DEFAULT 0');
CALL ihris_add_column('payroll_items', 'last_printed_at', 'DATETIME NULL');
CALL ihris_add_column('payroll_item_lines', 'reg_col', 'VARCHAR(20) NULL');
CALL ihris_add_column('users', 'module_access', 'VARCHAR(500) NULL');
CALL ihris_add_column('attendance_summaries', 'hours_work', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'absent_hrs', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'late_hrs', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'undertime_hrs', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'vl_hrs', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'sl_hrs', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'el_hrs', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'ol_hrs', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'pm_hrs', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'holiday_hrs', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'ot_reg', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'rd_hrs', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'lh_hrs', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'sh_hrs', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'rd_ot', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'lh_ot', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'sh_ot', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'lh_rd', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'sh_rd', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'lh_rd_ot', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'sh_rd_ot', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'np_reg', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'np_rd', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'np_lh', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'np_sh', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'np_reg_ot', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'np_rd_ot', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'np_lh_ot', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'np_sh_ot', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'np_lh_rd', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'np_sh_rd', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'np_lh_rd_ot', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'np_sh_rd_ot', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'amt_13th', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'service_charge', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'sl_conversion', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'vl_conversion', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'other_ded', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'tickets', 'INT NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'meal_comp', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'meal_comp_ot', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('import_profiles', 'layout', 'ENUM(\'delimited\',\'fixed\') NOT NULL DEFAULT \'delimited\' COMMENT \'Column layout of the log file\'');
CALL ihris_add_column('import_profiles', 'fw_id_start', 'SMALLINT NULL COMMENT \'Employee / biometric ID start position (fixed width)\'');
CALL ihris_add_column('import_profiles', 'fw_id_width', 'SMALLINT NULL COMMENT \'Employee / biometric ID width (fixed width)\'');
CALL ihris_add_column('import_profiles', 'fw_date_start', 'SMALLINT NULL COMMENT \'Date start position (fixed width)\'');
CALL ihris_add_column('import_profiles', 'fw_date_width', 'SMALLINT NULL COMMENT \'Date width (fixed width)\'');
CALL ihris_add_column('import_profiles', 'fw_time_start', 'SMALLINT NULL COMMENT \'Time start position (fixed width)\'');
CALL ihris_add_column('import_profiles', 'fw_time_width', 'SMALLINT NULL COMMENT \'Time width (fixed width)\'');
CALL ihris_add_column('import_profiles', 'fw_type_start', 'SMALLINT NULL COMMENT \'Log type start position (fixed width)\'');
CALL ihris_add_column('import_profiles', 'fw_type_width', 'SMALLINT NULL COMMENT \'Log type width (fixed width)\'');
CALL ihris_add_column('import_profiles', 'fw_dev_start', 'SMALLINT NULL COMMENT \'Device / bio ID start position (fixed width)\'');
CALL ihris_add_column('import_profiles', 'fw_dev_width', 'SMALLINT NULL COMMENT \'Device / bio ID width (fixed width)\'');
CALL ihris_add_column('import_profiles', 'is_default', 'TINYINT(1) NOT NULL DEFAULT 0');
DROP PROCEDURE IF EXISTS ihris_add_column;

ALTER TABLE `import_batches` MODIFY COLUMN `batch_type` ENUM('biometric','csv','xlsx','device','log_table') NOT NULL;

INSERT IGNORE INTO settings (skey, svalue, label, grp, input_type, help, sort_order) VALUES
('ams_duty_mode','first_last','Duty hours rendered','ams_policy','select:first_last=TIMEIN and TIMEOUT log,pairs=Every IN and OUT log',NULL,'1'),
('ams_late_mode','every','Late and undertime','ams_policy','select:in_out=Late at TIMEIN and Undertime at TIMEOUT log,every=Late at every IN log and Undertime on every OUT log',NULL,'2'),
('ams_break_excess_late','0','BREAK rendered above allocated break time as LATE','ams_policy','bool',NULL,'3'),
('ams_nolate_holiday','0','No late and undertime on HOLIDAYS','ams_policy','bool',NULL,'4'),
('ams_nolate_restday','0','No late and undertime during Day-Off on duty','ams_policy','bool',NULL,'5'),
('ams_nolate_leave','1','No late and undertime fall on LEAVE schedule','ams_policy','bool',NULL,'6'),
('ams_short_absent','0','Rendered hrs below allocated SHIFT hrs as ABSENT','ams_policy','bool',NULL,'7'),
('ams_short_ut','0','Rendered hrs below allocated SHIFT hrs as UNDERTIME','ams_policy','bool',NULL,'8'),
('ams_nobreak_absent','0','No BREAK log on SHIFT w/ break as ABSENT','ams_policy','bool',NULL,'9'),
('ams_nobreak_ut','1','No BREAK log on SHIFT w/ break as UNDERTIME','ams_policy','bool',NULL,'10'),
('ams_abs_week_total','0','Total all absences per WEEK per cut-off period','ams_config','bool',NULL,'1'),
('ams_abs_period_total','0','Total all absences per cut-off period','ams_config','bool',NULL,'2'),
('ams_holiday_absent_prior','0','Holiday absent when absent on prior non-holiday day','ams_config','bool',NULL,'3'),
('ams_tardy_week_total','0','Total all tardiness per WEEK per cut-off period','ams_config','bool',NULL,'4'),
('ams_tardy_period_total','0','Total all tardiness per cut-off period','ams_config','bool',NULL,'5'),
('ams_incomplete_absent','0','Incomplete log as ABSENT','ams_config','bool',NULL,'6'),
('ams_incomplete_ut','1','Incomplete log as UNDERTIME','ams_config','bool',NULL,'7'),
('ams_incomplete_penalty_on','0','Incomplete log penalty','ams_config','bool',NULL,'8'),
('ams_incomplete_penalty','0','Incomplete log penalty (minutes)','ams_config','number',NULL,'9'),
('ams_early_break_penalty_on','0','LogIn 20 mins. before BREAKTIME-IN penalty','ams_config','bool',NULL,'10'),
('ams_early_break_penalty','0','LogIn before BREAKTIME-IN penalty (minutes)','ams_config','number',NULL,'11'),
('ams_np_from_logs','0','TimeIN and TimeOUT computation for Night Prem.','ams_config','bool',NULL,'12'),
('ams_invalid_hours','17','No. of hours work as INVALID','ams_config','number',NULL,'13'),
('ams_dup_minutes','15','# of minutes between LOGS w/ same TYPE as INVALID','ams_config','number',NULL,'14'),
('ams_late_lookup','E','Late table lookup code [D,E,P]','ams_config','select:E=E - each late occurrence,D=D - total per day,P=P - total per cut-off period',NULL,'15'),
('ams_six_logs','0','Use 6 logs per day (Time In, Break Out 1, Break In 1, Break Out 2, Break In 2, Time Out)','ams_config','bool',NULL,'16'),
('default_conversion_factor','26','Monthly - daily conversion factor (default)','payroll_params','number',NULL,'1'),
('hdmf_min_gross','0','HDMF: minimum monthly gross salary subject to contribution','payroll_params','number',NULL,'2'),
('hdmf_rate_pct','2','HDMF: employee percent rate','payroll_params','number',NULL,'3'),
('hdmf_max_ee','200','HDMF: maximum employee contribution (monthly)','payroll_params','number',NULL,'4'),
('hdmf_global_ee_pct','0','HDMF: global additional employee contribution rate (%)','payroll_params','number',NULL,'5'),
('payroll_bank_code','','Bank account code (company payroll account)','payroll_params','text',NULL,'6'),
('ot_taxable','1','Overtime earnings taxable','payroll_params','bool',NULL,'7'),
('taxable_base','gross_less_govt','Taxable earnings computed based on','payroll_params','select:gross_less_govt=Gross - Govt. deductions,basic_less_govt=Basic - Govt. deductions,gross=Gross',NULL,'8'),
('sss_lookup','monthly_rate','SSS premium contribution lookup','payroll_params','select:monthly_rate=Monthly rate,gross=Monthly gross earnings',NULL,'9'),
('min_net_pay_amount','1','Employee\'s take-home pay: earning not below (PHP)','payroll_params','number',NULL,'10'),
('special_max_nontax','0','Special earnings maximum non-taxable amount (per period)','payroll_params','number',NULL,'11'),
('meal_comp_rate','0','Meal compensation rate (per count)','payroll_params','number',NULL,'12'),
('meal_comp_ot_rate','0','Meal compensation OT rate (per count)','payroll_params','number',NULL,'13'),
('ticket_rate','0','Meal ticket price (deducted per ticket)','payroll_params','number',NULL,'14'),
('thirteenth_include_leave','1','13th month basis includes paid leaves','payroll_annual','bool',NULL,'1'),
('year_end_adjust','1','Year-end tax annualization on the last regular payroll of December','payroll_annual','bool',NULL,'2'),
('gl_salaries','5000','GL: Salaries and wages','payroll_annual','text',NULL,'10'),
('gl_ot','5010','GL: Overtime pay','payroll_annual','text',NULL,'11'),
('gl_premium','5020','GL: Holiday / premium pay','payroll_annual','text',NULL,'12'),
('gl_np','5030','GL: Night premium','payroll_annual','text',NULL,'13'),
('gl_13th','5040','GL: 13th month pay','payroll_annual','text',NULL,'14'),
('gl_allow','5050','GL: Allowances & benefits','payroll_annual','text',NULL,'15'),
('gl_er_contrib','5060','GL: Employer contributions expense','payroll_annual','text',NULL,'16'),
('gl_sss_pay','2100','GL: SSS payable','payroll_annual','text',NULL,'17'),
('gl_phic_pay','2110','GL: PhilHealth payable','payroll_annual','text',NULL,'18'),
('gl_hdmf_pay','2120','GL: Pag-IBIG payable','payroll_annual','text',NULL,'19'),
('gl_wtax_pay','2130','GL: Withholding tax payable','payroll_annual','text',NULL,'20'),
('gl_sss_loan','2140','GL: SSS loans payable','payroll_annual','text',NULL,'21'),
('gl_hdmf_loan','2150','GL: Pag-IBIG loans payable','payroll_annual','text',NULL,'22'),
('gl_advances','1150','GL: Advances / receivables from employees','payroll_annual','text',NULL,'23'),
('gl_net_pay','2200','GL: Accrued payroll (net pay)','payroll_annual','text',NULL,'24'),
('payslip_title','','Payslip heading (blank = company name)','payslip','text',NULL,'1'),
('payslip_show_ytd','1','Show YTD information','payslip','bool',NULL,'2'),
('payslip_show_balances','1','Show outstanding loan balances','payslip','bool',NULL,'3'),
('payslip_show_hours','1','Show hours beside earnings','payslip','bool',NULL,'4'),
('payslip_copy_label','1','Mark reprinted payslips as ( COPY )','payslip','bool',NULL,'5'),
('payslip_per_page','2','Payslips per printed page (batch printing)','payslip','select:1=1 per page,2=2 per page',NULL,'6'),
('payslip_sig_label','HR Manager','Signature label','payslip','text',NULL,'7'),
('payslip_sig_name','','Signatory name (optional)','payslip','text',NULL,'8'),
('payslip_disclaimer','Please examine your payslip. You are responsible for checking and validating the details contained in this payslip. Failure to report any discrepancy in the information above within 30 days shall mean that you acknowledge that all information herein is correct.','Disclaimer','payslip','textarea',NULL,'9'),
('sig_prepared_name','','Prepared by — name','signatory','text',NULL,'1'),
('sig_prepared_title','Payroll Officer','Prepared by — position','signatory','text',NULL,'2'),
('sig_checked_name','','Checked by — name','signatory','text',NULL,'3'),
('sig_checked_title','HR Manager','Checked by — position','signatory','text',NULL,'4'),
('sig_approved_name','','Approved by — name','signatory','text',NULL,'5'),
('sig_approved_title','General Manager','Approved by — position','signatory','text',NULL,'6');

DROP PROCEDURE IF EXISTS ihris_seed;
DELIMITER $$
CREATE PROCEDURE ihris_seed()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM `emp_types`) THEN
    INSERT INTO emp_types (code, name, conversion_factor, hours_per_day, is_default) VALUES
    ('AA','Monthly Staff',26,8,1),('BB','Daily Staff 30',30,8,0),('CC','Daily Staff 26',26,8,0);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM `emp_groups`) THEN
    INSERT INTO emp_groups (code, name, log_group, is_default) VALUES
    ('00','Executives',0,0),('01','Rank & File - Monthly',0,1),('02','Rank & File - Daily',0,0),('07','OJT',0,0),('08','Apprentice',0,0),('99','Piecework (Pakyaw)',0,0);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM `class_groups`) THEN
    INSERT INTO class_groups (code, name) VALUES ('ADM','Administration'),('OPS','Operations'),('SAL','Sales & Marketing');
  END IF;
  IF NOT EXISTS (SELECT 1 FROM `rank_categories`) THEN
    INSERT INTO rank_categories (code, name) VALUES ('RF','Rank and File'),('SUP','Supervisory'),('MGR','Managerial'),('EXE','Executive');
  END IF;
  IF NOT EXISTS (SELECT 1 FROM `classifications`) THEN
    INSERT INTO classifications (code, name) VALUES ('DL','Direct Labor'),('IDL','Indirect Labor'),('ADM','Administrative'),('CONF','Confidential');
  END IF;
  IF NOT EXISTS (SELECT 1 FROM `jobs`) THEN
    INSERT INTO jobs (code, name, category, sub_category, ot_offset) VALUES
    ('1000','General Manager','Management',NULL,0),('1100','HR Officer','Administration',NULL,0),('1200','Accountant','Finance',NULL,0),
    ('1300','Cashier','Finance',NULL,0),('1400','Sales Associate','Sales',NULL,0),('1500','Production Worker','Operations',NULL,0),
    ('1600','Driver','Operations',NULL,0),('1700','Security Guard','Operations',NULL,0);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM `exemption_codes`) THEN
    INSERT INTO exemption_codes (code, name, tax_exempt) VALUES
    ('Z','Taxable (TRAIN law, no personal exemption)',0),('MWE','Minimum wage earner (exempt)',1),('EXEMP','Tax exempt',1);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM `locations`) THEN
    INSERT INTO locations (code, name) VALUES ('MKT','Makati'),('QC','Quezon City'),('CEB','Cebu');
  END IF;
  IF NOT EXISTS (SELECT 1 FROM `gl_accounts`) THEN
    INSERT INTO gl_accounts (code, name, account_type) VALUES
    ('1010','Cash in bank - payroll','asset'),('1150','Advances / receivables from employees','asset'),
    ('2100','SSS premiums payable','liability'),('2110','PhilHealth premiums payable','liability'),('2120','Pag-IBIG contributions payable','liability'),
    ('2130','Withholding tax on compensation payable','liability'),('2140','SSS loans payable','liability'),('2150','Pag-IBIG loans payable','liability'),
    ('2200','Accrued payroll (net pay payable)','liability'),('5000','Salaries and wages','expense'),('5010','Overtime pay','expense'),
    ('5020','Holiday and premium pay','expense'),('5030','Night premium','expense'),('5040','13th month pay','expense'),
    ('5050','Allowances and benefits','expense'),('5060','Employer contributions (SSS, PhilHealth, Pag-IBIG)','expense');
  END IF;
  IF NOT EXISTS (SELECT 1 FROM `earning_types`) THEN
    INSERT INTO earning_types (code, name, gl_code, taxable, tax_rate, special, de_minimis) VALUES
    ('1000','Fixed overtime','5010',1,0,0,0),('1001','Consultancy fee','5000',1,0,0,0),('1002','Salary adjustment (add)','5000',1,0,0,0),
    ('1003','Shortage allowance','5050',1,0,0,0),('1004','Productivity bonus','5050',0,0,0,0),('1005','Sales incentive','5050',1,0,0,0);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM `fixed_trans_codes`) THEN
    INSERT INTO fixed_trans_codes (code, name, trans_type, role, default_terms, gl_code, taxable) VALUES
    ('01','SSS contribution (fixed)','deduction','sss','monthly_split','2100',0),('02','Pag-IBIG contribution (fixed)','deduction','hdmf','monthly_split','2120',0),
    ('03','PhilHealth contribution (fixed)','deduction','phic','monthly_split','2110',0),('04','Withholding tax (fixed)','deduction','tax','every_pay','2130',0),
    ('10','COLA','earning','none','every_pay','5050',1),('11','Position allowance','earning','none','every_pay','5050',1),
    ('12','Consultancy allowance','earning','none','every_pay','5050',1),('13','Job allowance','earning','none','every_pay','5050',1);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM `pay_rates`) THEN
    INSERT INTO pay_rates (code, label, group_name, day_pct, night_pct, sort_order) VALUES
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
    ('sh_rd_ot','Special holiday on day-off OT rate','Special Holiday Rates',195,19.5,12);
  END IF;
END$$
DELIMITER ;
CALL ihris_seed();
DROP PROCEDURE IF EXISTS ihris_seed;

UPDATE pay_groups SET sss_mode = 'first', hdmf_mode = 'first', phic_mode = 'first' WHERE contrib_mode = 'first' AND sss_mode = 'every' AND hdmf_mode = 'every' AND phic_mode = 'every';
UPDATE pay_groups SET sss_mode = 'second', hdmf_mode = 'second', phic_mode = 'second' WHERE contrib_mode = 'last' AND sss_mode = 'every' AND hdmf_mode = 'every' AND phic_mode = 'every';
UPDATE leave_types SET summary_bucket = 'vl' WHERE code IN ('SIL','VL') AND summary_bucket = 'ol';
UPDATE leave_types SET summary_bucket = 'sl' WHERE code = 'SL' AND summary_bucket = 'ol';
UPDATE leave_types SET summary_bucket = 'el' WHERE code = 'EL' AND summary_bucket = 'ol';
UPDATE leave_types SET summary_bucket = 'pm' WHERE code IN ('ML','ML-MC','PL') AND summary_bucket = 'ol';
UPDATE leave_types SET summary_bucket = 'none' WHERE is_paid = 0 AND summary_bucket = 'ol';
UPDATE deduction_types SET register_group = 'sss_loan' WHERE code IN ('SSS-SL','SSS-CL') AND register_group = 'other';
UPDATE deduction_types SET register_group = 'hdmf_loan' WHERE code IN ('HDMF-MPL','HDMF-CAL') AND register_group = 'other';
UPDATE deduction_types SET register_group = 'company_loan' WHERE code IN ('CO-LOAN','CA') AND register_group = 'other';
UPDATE deduction_types SET gl_code = '2100' WHERE code = 'SSS' AND gl_code IS NULL;
UPDATE deduction_types SET gl_code = '2110' WHERE code = 'PHIC' AND gl_code IS NULL;
UPDATE deduction_types SET gl_code = '2120' WHERE code = 'HDMF' AND gl_code IS NULL;
UPDATE deduction_types SET gl_code = '2130' WHERE code = 'WTAX' AND gl_code IS NULL;
UPDATE deduction_types SET gl_code = '2140' WHERE code IN ('SSS-SL','SSS-CL') AND gl_code IS NULL;
UPDATE deduction_types SET gl_code = '2150' WHERE code IN ('HDMF-MPL','HDMF-CAL') AND gl_code IS NULL;
UPDATE deduction_types SET gl_code = '1150' WHERE gl_code IS NULL AND category <> 'statutory';
UPDATE benefit_types SET gl_code = '5050' WHERE gl_code IS NULL;
UPDATE attendance_cutoffs SET period_year = YEAR(period_end), period_month = MONTH(period_end), period_no = IF(pay_group_id IN (SELECT id FROM pay_groups WHERE frequency = 'monthly'), 1, IF(DAY(period_end) <= 15, 1, 2)) WHERE period_year IS NULL;
UPDATE employees e JOIN exemption_codes x ON x.code = 'MWE' SET e.exemption_code_id = x.id WHERE e.is_mwe = 1 AND e.exemption_code_id IS NULL;
UPDATE employees e JOIN exemption_codes x ON x.code = 'Z' SET e.exemption_code_id = x.id WHERE e.is_mwe = 0 AND e.exemption_code_id IS NULL;
UPDATE branches SET is_default = 1 WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM branches) t) AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM branches WHERE is_default = 1) d);
UPDATE shifts SET is_default = 1 WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM shifts) t) AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM shifts WHERE is_default = 1) d);
INSERT INTO leave_types (code, name, legal_basis, accrual_method, days_per_year, count_basis, is_paid, approval_chain, is_statutory, is_active, description, summary_bucket) SELECT 'OFL', 'Offset Leave (OT/OL)', 'Company policy', 'per_event', 0, 'workdays', 1, 'supervisor,hr', 0, 1, 'Credited from approved overtime of employees whose job is marked OT/OL; used as other/offset leave (OL).', 'ol' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM leave_types WHERE code = 'OFL');

INSERT INTO settings (skey, svalue, label, grp, input_type, sort_order) VALUES ('db_version', '2', 'Database version', 'system', 'hidden', 0)
  ON DUPLICATE KEY UPDATE svalue = VALUES(svalue);
SET FOREIGN_KEY_CHECKS = 1;
-- Existing attendance summaries are converted to hours automatically the first time the site is opened.
