-- =====================================================================
--  G-SMaRT HRIS upgrade to database version 25 (Salary restriction: employees whose pay a user may not see)
--  Target: MySQL 5.5+ / MariaDB 5.5+.  Safe to run more than once; keeps all existing data.
--  Select your HRIS database first (e.g. hris_web), then import this file.
--  Not needed if you simply open the updated site: index.php upgrades the database automatically.
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '+08:00';
SET FOREIGN_KEY_CHECKS = 0;

-- user_salary_exclusions
CREATE TABLE IF NOT EXISTS user_salary_exclusions (
  user_id INT NOT NULL,
  employee_id INT NOT NULL,
  created_by INT NULL,
  created_at DATETIME NULL,
  PRIMARY KEY (user_id, employee_id),
  KEY idx_use_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='v32: employees whose pay the user may not see';

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

DROP PROCEDURE IF EXISTS ihris_add_column;



INSERT INTO settings (skey, svalue, label, grp, input_type, sort_order) VALUES ('db_version', '25', 'Database version', 'system', 'hidden', 0)
  ON DUPLICATE KEY UPDATE svalue = GREATEST(svalue + 0, VALUES(svalue) + 0);
SET FOREIGN_KEY_CHECKS = 1;
