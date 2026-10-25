-- =====================================================================
--  G-SMaRT HRIS upgrade to database version 7 (attendance closing, payroll computation parameters, fixed transaction terms, page-level user access)
--  Target: MySQL 5.5+ / MariaDB 5.5+.  Safe to run more than once; keeps all existing data.
--  Select your HRIS database first (e.g. hris_web), then import this file.
--  Not needed if you simply open the updated site: index.php upgrades the database automatically.
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '+08:00';
SET FOREIGN_KEY_CHECKS = 0;

-- user_page_access
CREATE TABLE IF NOT EXISTS user_page_access (
  user_id INT NOT NULL,
  route VARCHAR(40) NOT NULL,
  can_view TINYINT(1) NOT NULL DEFAULT 0,
  can_add TINYINT(1) NOT NULL DEFAULT 0,
  can_edit TINYINT(1) NOT NULL DEFAULT 0,
  can_delete TINYINT(1) NOT NULL DEFAULT 0,
  can_print TINYINT(1) NOT NULL DEFAULT 0,
  can_approve TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, route)
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

CALL ihris_add_column('attendance_summaries', 'is_closed', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL ihris_add_column('attendance_summaries', 'closed_at', 'DATETIME NULL');
CALL ihris_add_column('attendance_summaries', 'closed_by', 'INT NULL');
CALL ihris_add_column('payroll_runs', 'calc_branch_id', 'INT NULL');
CALL ihris_add_column('payroll_runs', 'calc_group_id', 'INT NULL');
CALL ihris_add_column('payroll_runs', 'calc_type_id', 'INT NULL');
CALL ihris_add_column('payroll_runs', 'calc_tax_table', 'VARCHAR(20) NULL');
CALL ihris_add_column('payroll_runs', 'calc_annualize', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL ihris_add_column('payroll_runs', 'calc_regular', 'TINYINT(1) NOT NULL DEFAULT 1');
CALL ihris_add_column('payroll_runs', 'calc_13th', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL ihris_add_column('payroll_runs', 'calc_selection', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL ihris_add_column('payroll_runs', 'calc_emp_from', 'VARCHAR(30) NULL');
CALL ihris_add_column('payroll_runs', 'calc_emp_to', 'VARCHAR(30) NULL');
CALL ihris_add_column('users', 'page_access', 'TINYINT(1) NOT NULL DEFAULT 0');
DROP PROCEDURE IF EXISTS ihris_add_column;

ALTER TABLE `employee_fixed_trans` MODIFY COLUMN `terms` ENUM('every_pay','monthly_split','first_pay','second_pay','third_pay','fourth_pay') NULL;
ALTER TABLE `fixed_trans_codes` MODIFY COLUMN `default_terms` ENUM('every_pay','monthly_split','first_pay','second_pay','third_pay','fourth_pay') NOT NULL DEFAULT 'every_pay';

UPDATE attendance_summaries s JOIN attendance_cutoffs c ON c.id = s.cutoff_id SET s.is_closed = 1, s.closed_at = IFNULL(c.approved_at, NOW()) WHERE c.status IN ('approved', 'locked') AND s.is_closed = 0;

INSERT INTO settings (skey, svalue, label, grp, input_type, sort_order) VALUES ('db_version', '7', 'Database version', 'system', 'hidden', 0)
  ON DUPLICATE KEY UPDATE svalue = GREATEST(svalue + 0, VALUES(svalue) + 0);
SET FOREIGN_KEY_CHECKS = 1;
