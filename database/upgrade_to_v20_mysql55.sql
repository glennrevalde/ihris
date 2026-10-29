-- =====================================================================
--  G-SMaRT HRIS upgrade to database version 20 (NBJ Logs import configuration and the other biometric IDs of an employee)
--  Target: MySQL 5.5+ / MariaDB 5.5+.  Safe to run more than once; keeps all existing data.
--  Select your HRIS database first (e.g. hris_web), then import this file.
--  Not needed if you simply open the updated site: index.php upgrades the database automatically.
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '+08:00';
SET FOREIGN_KEY_CHECKS = 0;

-- employee_bio_ids
CREATE TABLE IF NOT EXISTS employee_bio_ids (
  id INT NOT NULL AUTO_INCREMENT,
  employee_id INT NOT NULL,
  bio_id VARCHAR(20) NOT NULL COMMENT 'Another biometric / device user ID of the employee',
  note VARCHAR(80) NULL,
  created_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_emp_bio_id (bio_id),
  KEY idx_emp_bio_emp (employee_id)
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


INSERT INTO import_profiles (name, layout, delimiter, has_header, col_bio_id, datetime_format, notes, rp_id_label, rp_name_label, rp_period_label, rp_date_format, rp_day_row, rp_time_row, rp_sheet, min_gap, is_default, sort_order) SELECT 'NBJ Logs', 'report', 'tab', 0, 1, 'Y-m-d H:i:s', 'Attendance Record Report (.xls): Att. Time period, one row of day numbers at the top, ID / Name / Dept. blocks, the times of each day run together', 'ID:', 'Name:', 'Att. Time', 'Y-m-d', 0, 1, 1, 5, 0, m.so FROM (SELECT COALESCE(MAX(sort_order), 0) + 1 AS so, SUM(name = 'NBJ Logs') AS n FROM import_profiles) m WHERE COALESCE(m.n, 0) = 0;

INSERT INTO settings (skey, svalue, label, grp, input_type, sort_order) VALUES ('db_version', '20', 'Database version', 'system', 'hidden', 0)
  ON DUPLICATE KEY UPDATE svalue = GREATEST(svalue + 0, VALUES(svalue) + 0);
SET FOREIGN_KEY_CHECKS = 1;
