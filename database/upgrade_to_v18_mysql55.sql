-- =====================================================================
--  G-SMaRT HRIS upgrade to database version 18 (Employee Loan / Deduction Request and its approval)
--  Target: MySQL 5.5+ / MariaDB 5.5+.  Safe to run more than once; keeps all existing data.
--  Select your HRIS database first (e.g. hris_web), then import this file.
--  Not needed if you simply open the updated site: index.php upgrades the database automatically.
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '+08:00';
SET FOREIGN_KEY_CHECKS = 0;

-- loan_requests
CREATE TABLE IF NOT EXISTS loan_requests (
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

CALL ihris_add_column('deduction_types', 'ess_request', 'TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'Offered in the Loan / Deduction Request of the employee portal\'');
DROP PROCEDURE IF EXISTS ihris_add_column;


INSERT IGNORE INTO settings (skey, svalue, label, grp, input_type, help, sort_order) VALUES
('loan_request_max_terms','48','Loan / Deduction Request: longest term an employee may apply for (paydays)','payroll','number','Paydays; the approver may still approve another term.','40');

UPDATE deduction_types SET ess_request = 1 WHERE ess_request = 0 AND is_system = 0 AND category IN ('loan', 'adhoc', 'recurring');

INSERT INTO settings (skey, svalue, label, grp, input_type, sort_order) VALUES ('db_version', '18', 'Database version', 'system', 'hidden', 0)
  ON DUPLICATE KEY UPDATE svalue = GREATEST(svalue + 0, VALUES(svalue) + 0);
SET FOREIGN_KEY_CHECKS = 1;
