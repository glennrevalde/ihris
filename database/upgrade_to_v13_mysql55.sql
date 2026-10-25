-- =====================================================================
--  G-SMaRT HRIS upgrade to database version 13 (hold of fixed transactions, payroll reprocessing filters, memoranda / announcements, company logo on reports)
--  Target: MySQL 5.5+ / MariaDB 5.5+.  Safe to run more than once; keeps all existing data.
--  Select your HRIS database first (e.g. hris_web), then import this file.
--  Not needed if you simply open the updated site: index.php upgrades the database automatically.
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '+08:00';
SET FOREIGN_KEY_CHECKS = 0;

-- memo_templates
CREATE TABLE IF NOT EXISTS memo_templates (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- memos
CREATE TABLE IF NOT EXISTS memos (
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

CALL ihris_add_column('employee_fixed_trans', 'held_at', 'DATETIME NULL');
CALL ihris_add_column('employee_fixed_trans', 'held_by', 'INT NULL');
CALL ihris_add_column('payroll_runs', 'calc_emp_ids', 'TEXT NULL');
CALL ihris_add_column('payroll_runs', 'calc_branch_ids', 'VARCHAR(255) NULL');
CALL ihris_add_column('payroll_runs', 'calc_unit_ids', 'VARCHAR(255) NULL');
DROP PROCEDURE IF EXISTS ihris_add_column;

ALTER TABLE `employee_fixed_trans` MODIFY COLUMN `status` ENUM('active','stopped','hold') NOT NULL DEFAULT 'active';

INSERT IGNORE INTO settings (skey, svalue, label, grp, input_type, help, sort_order) VALUES
('report_logo_enabled','0','Add Company Logo to Reports','branding','bool','System Settings, Company logo on reports','1'),
('report_logo_reports','','Reports with the company logo','branding','text','all, or the routes of the reports','2'),
('report_logo_path','','Company logo file','branding','text','','3');


INSERT INTO settings (skey, svalue, label, grp, input_type, sort_order) VALUES ('db_version', '13', 'Database version', 'system', 'hidden', 0)
  ON DUPLICATE KEY UPDATE svalue = GREATEST(svalue + 0, VALUES(svalue) + 0);
SET FOREIGN_KEY_CHECKS = 1;
