-- =====================================================================
--  G-SMaRT HRIS upgrade to database version 15 (employment status Resigned / Terminated / Retired, final pay item edits, penalty reset days, 13th month basis, company events)
--  Target: MySQL 5.5+ / MariaDB 5.5+.  Safe to run more than once; keeps all existing data.
--  Select your HRIS database first (e.g. hris_web), then import this file.
--  Not needed if you simply open the updated site: index.php upgrades the database automatically.
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '+08:00';
SET FOREIGN_KEY_CHECKS = 0;

-- final_pay_overrides
CREATE TABLE IF NOT EXISTS final_pay_overrides (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- company_events
CREATE TABLE IF NOT EXISTS company_events (
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

CALL ihris_add_column('conduct_offenses', 'reset_days', 'INT NULL COMMENT \'No. of days to reset the penalty: an offense later than this after the last one counts as a first offense\'');
DROP PROCEDURE IF EXISTS ihris_add_column;

ALTER TABLE `employees` MODIFY COLUMN `employment_status` ENUM('probationary','regular','project_based','contractual','resigned','terminated','retired') NOT NULL DEFAULT 'probationary';

INSERT IGNORE INTO settings (skey, svalue, label, grp, input_type, help, sort_order) VALUES
('thirteenth_basis','calendar','Basis of the 13th month computation','payroll_13th','select:calendar=Calendar Year,fiscal=Fiscal Year','Calendar Year: January to December; Fiscal Year: From / To dates of exactly one year','1'),
('thirteenth_fy_from','','Fiscal year of the 13th month: From date','payroll_13th','text','YYYY-MM-DD','2'),
('thirteenth_fy_to','','Fiscal year of the 13th month: To date','payroll_13th','text','YYYY-MM-DD, exactly one year after the From date less a day','3');

UPDATE employees SET employment_status = 'resigned' WHERE status = 'separated' AND separation_type = 'resignation';
UPDATE employees SET employment_status = 'retired' WHERE status = 'separated' AND separation_type = 'retirement';
UPDATE employees SET employment_status = 'terminated' WHERE status = 'separated' AND separation_type IN ('termination', 'dismissal');

INSERT INTO settings (skey, svalue, label, grp, input_type, sort_order) VALUES ('db_version', '15', 'Database version', 'system', 'hidden', 0)
  ON DUPLICATE KEY UPDATE svalue = GREATEST(svalue + 0, VALUES(svalue) + 0);
SET FOREIGN_KEY_CHECKS = 1;
