-- =====================================================================
--  G-SMaRT HRIS upgrade to database version 19 (Attendance record report import (JJM Logs) and the minimum minutes between logs)
--  Target: MySQL 5.5+ / MariaDB 5.5+.  Safe to run more than once; keeps all existing data.
--  Select your HRIS database first (e.g. hris_web), then import this file.
--  Not needed if you simply open the updated site: index.php upgrades the database automatically.
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '+08:00';
SET FOREIGN_KEY_CHECKS = 0;

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

CALL ihris_add_column('import_profiles', 'rp_id_label', 'VARCHAR(40) NULL COMMENT \'Report layout: label of the employee ID (the ID is the next cell)\'');
CALL ihris_add_column('import_profiles', 'rp_name_label', 'VARCHAR(40) NULL COMMENT \'Report layout: label of the employee name\'');
CALL ihris_add_column('import_profiles', 'rp_period_label', 'VARCHAR(40) NULL COMMENT \'Report layout: label of the period (gives the year and month)\'');
CALL ihris_add_column('import_profiles', 'rp_date_format', 'VARCHAR(20) NULL COMMENT \'Report layout: date format of the period\'');
CALL ihris_add_column('import_profiles', 'rp_day_row', 'TINYINT NOT NULL DEFAULT 1 COMMENT \'Report layout: rows below the ID row with the day numbers\'');
CALL ihris_add_column('import_profiles', 'rp_time_row', 'TINYINT NOT NULL DEFAULT 2 COMMENT \'Report layout: rows below the ID row of the first times\'');
CALL ihris_add_column('import_profiles', 'rp_sheet', 'TINYINT NOT NULL DEFAULT 1 COMMENT \'Report layout: worksheet number\'');
CALL ihris_add_column('import_profiles', 'min_gap', 'SMALLINT NOT NULL DEFAULT 0 COMMENT \'Minimum minutes between two logs of the same employee-day (0 = keep all)\'');
DROP PROCEDURE IF EXISTS ihris_add_column;

ALTER TABLE `import_profiles` MODIFY COLUMN `layout` ENUM('delimited','fixed','report') NOT NULL DEFAULT 'delimited' COMMENT 'Column layout of the log file';

INSERT INTO import_profiles (name, layout, delimiter, has_header, col_bio_id, datetime_format, notes, rp_id_label, rp_name_label, rp_period_label, rp_date_format, rp_day_row, rp_time_row, rp_sheet, min_gap, is_default, sort_order) SELECT 'JJM Logs', 'report', 'tab', 0, 1, 'Y-m-d H:i:s', 'Employee Attendance Record (.xls): User ID / Name / Department, the day numbers, then the times of each day', 'User ID:', 'Name:', 'Attendance date:', 'Y-m-d', 1, 2, 1, 5, 0, m.so FROM (SELECT COALESCE(MAX(sort_order), 0) + 1 AS so, SUM(name = 'JJM Logs') AS n FROM import_profiles) m WHERE COALESCE(m.n, 0) = 0;

INSERT INTO settings (skey, svalue, label, grp, input_type, sort_order) VALUES ('db_version', '19', 'Database version', 'system', 'hidden', 0)
  ON DUPLICATE KEY UPDATE svalue = GREATEST(svalue + 0, VALUES(svalue) + 0);
SET FOREIGN_KEY_CHECKS = 1;
