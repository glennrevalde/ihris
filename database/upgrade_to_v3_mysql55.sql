-- =====================================================================
--  I-HRIS upgrade to database version 3 (employee's transactions with terms, shift log count, employee CSV import)
--  Target: MySQL 5.5+ / MariaDB 5.5+.  Safe to run more than once; keeps all existing data.
--  Select your I-HRIS database first (e.g. hris_web), then import this file.
--  Not needed if you simply open the updated site: index.php upgrades the database automatically.
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '+08:00';
SET FOREIGN_KEY_CHECKS = 0;

-- employee_term_trans
CREATE TABLE IF NOT EXISTS employee_term_trans (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- employee_term_schedule
CREATE TABLE IF NOT EXISTS employee_term_schedule (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- employee_import_batches
CREATE TABLE IF NOT EXISTS employee_import_batches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  file_name VARCHAR(255) NOT NULL,
  total_rows INT NOT NULL DEFAULT 0,
  created_rows INT NOT NULL DEFAULT 0,
  updated_rows INT NOT NULL DEFAULT 0,
  rejected_rows INT NOT NULL DEFAULT 0,
  uploaded_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
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

CALL ihris_add_column('shifts', 'logs_per_day', 'TINYINT NOT NULL DEFAULT 0 COMMENT \'Logs per day: 0 = from the breaks, 2, 4 or 6\'');
CALL ihris_add_column('employees', 'import_batch_id', 'INT NULL');
DROP PROCEDURE IF EXISTS ihris_add_column;


INSERT IGNORE INTO settings (skey, svalue, label, grp, input_type, help, sort_order) VALUES
('emp_import_default_password','Welcome2026','Temporary password of portal accounts created by the employee CSV import','general','text','Employees must change it at their first sign-in.','20');

UPDATE shifts SET logs_per_day = 6 WHERE logs_per_day = 0 AND break_out2 IS NOT NULL;
UPDATE shifts SET logs_per_day = 4 WHERE logs_per_day = 0;

INSERT INTO settings (skey, svalue, label, grp, input_type, sort_order) VALUES ('db_version', '3', 'Database version', 'system', 'hidden', 0)
  ON DUPLICATE KEY UPDATE svalue = GREATEST(svalue + 0, VALUES(svalue) + 0);
SET FOREIGN_KEY_CHECKS = 1;
