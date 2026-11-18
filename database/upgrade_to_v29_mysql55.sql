-- =====================================================================
--  G-SMaRT HRIS upgrade to database version 29 (Undertime Form: undertime applications (Approved / Unauthorized undertime))
--  Target: MySQL 5.5+ / MariaDB 5.5+.  Safe to run more than once; keeps all existing data.
--  Select your HRIS database first (e.g. hris_web), then import this file.
--  Not needed if you simply open the updated site: index.php upgrades the database automatically.
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '+08:00';
SET FOREIGN_KEY_CHECKS = 0;

-- undertime_apps
CREATE TABLE IF NOT EXISTS undertime_apps (
  id INT NOT NULL AUTO_INCREMENT,
  ut_no VARCHAR(30) NOT NULL,
  employee_id INT NOT NULL,
  date_filed DATE NOT NULL,
  first_date DATE NULL COMMENT 'Earliest date of the application',
  last_date DATE NULL COMMENT 'Latest date of the application',
  entries TEXT NOT NULL COMMENT 'JSON: [{d: date, t: [in, out, in, out ...], m: undertime minutes, r: reason}]',
  total_minutes INT NOT NULL DEFAULT 0,
  reason VARCHAR(500) NULL,
  status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  approved_by INT NULL,
  approved_at DATETIME NULL,
  remarks VARCHAR(255) NULL,
  created_by INT NULL,
  created_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_undertime_no (ut_no),
  KEY idx_undertime_emp (employee_id, first_date),
  KEY idx_undertime_status (status, first_date)
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

DROP PROCEDURE IF EXISTS ihris_add_column;



INSERT INTO settings (skey, svalue, label, grp, input_type, sort_order) VALUES ('db_version', '29', 'Database version', 'system', 'hidden', 0)
  ON DUPLICATE KEY UPDATE svalue = GREATEST(svalue + 0, VALUES(svalue) + 0);
SET FOREIGN_KEY_CHECKS = 1;
