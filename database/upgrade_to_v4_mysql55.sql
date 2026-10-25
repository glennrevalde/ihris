-- =====================================================================
--  I-HRIS upgrade to database version 4 (CSV imports of transactions, deductions and benefits, benefit posting term, deduction source, log type correction)
--  Target: MySQL 5.5+ / MariaDB 5.5+.  Safe to run more than once; keeps all existing data.
--  Select your I-HRIS database first (e.g. hris_web), then import this file.
--  Not needed if you simply open the updated site: index.php upgrades the database automatically.
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '+08:00';
SET FOREIGN_KEY_CHECKS = 0;

-- csv_import_batches
CREATE TABLE IF NOT EXISTS csv_import_batches (
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

CALL ihris_add_column('employee_benefits', 'post_term', 'VARCHAR(12) NULL COMMENT \'every_pay, first_pay .. fourth_pay; NULL = the frequency of the benefit type\'');
CALL ihris_add_column('employee_benefits', 'import_batch_id', 'INT NULL');
CALL ihris_add_column('attendance_summaries', 'deduction_branch_id', 'INT NULL COMMENT \'Set Deduction Source: branch / division / segment carrying the earnings and deductions\'');
CALL ihris_add_column('payroll_items', 'deduction_branch_id', 'INT NULL');
CALL ihris_add_column('payroll_branch_items', 'is_deduction_source', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL ihris_add_column('payroll_branch_items', 'deductions', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('payroll_branch_items', 'net_pay', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_logs', 'orig_punch_type', 'VARCHAR(10) NULL COMMENT \'Type read from the device / file before a manual correction\'');
CALL ihris_add_column('employee_transactions', 'import_batch_id', 'INT NULL');
CALL ihris_add_column('employee_fixed_trans', 'import_batch_id', 'INT NULL');
CALL ihris_add_column('employee_deductions', 'import_batch_id', 'INT NULL');
CALL ihris_add_column('dtr', 'calc_version', 'TINYINT NOT NULL DEFAULT 0 COMMENT \'Engine version that computed the row; older rows are rebuilt when shown\'');
DROP PROCEDURE IF EXISTS ihris_add_column;


UPDATE payroll_branch_items pb LEFT JOIN payroll_branch_items x ON x.item_id = pb.item_id AND x.is_deduction_source = 1 SET pb.is_deduction_source = 1 WHERE pb.is_default = 1 AND x.id IS NULL;

INSERT INTO settings (skey, svalue, label, grp, input_type, sort_order) VALUES ('db_version', '4', 'Database version', 'system', 'hidden', 0)
  ON DUPLICATE KEY UPDATE svalue = GREATEST(svalue + 0, VALUES(svalue) + 0);
SET FOREIGN_KEY_CHECKS = 1;
