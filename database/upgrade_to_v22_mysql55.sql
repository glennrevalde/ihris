-- =====================================================================
--  G-SMaRT HRIS upgrade to database version 22 (Super Admin re-opening of closed periods, ZKTeco attlog.xlsx import configuration)
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

CALL ihris_add_column('period_closings', 'status', 'VARCHAR(12) NOT NULL DEFAULT \'closed\' COMMENT \'closed | reopened\'');
CALL ihris_add_column('period_closings', 'cutoff_status_before', 'VARCHAR(12) NULL COMMENT \'Status of the period before the closing locked it\'');
CALL ihris_add_column('period_closings', 'reopened_by', 'INT NULL');
CALL ihris_add_column('period_closings', 'reopened_at', 'DATETIME NULL');
CALL ihris_add_column('period_closings', 'reopen_reason', 'VARCHAR(255) NULL');
CALL ihris_add_column('period_closings', 'reopen_log', 'TEXT NULL');
DROP PROCEDURE IF EXISTS ihris_add_column;


INSERT INTO import_profiles (name, layout, delimiter, has_header, col_bio_id, datetime_format, notes, rp_id_label, rp_name_label, rp_period_label, rp_date_format, rp_day_row, rp_time_row, rp_sheet, min_gap, is_default, sort_order) SELECT 'ZKTeco attlog.xlsx', 'report', 'tab', 0, 1, 'Y-m-d H:i:s', 'ZKTeco Attendance Record Report (.xls or .xlsx): Att. Time period, one row of day numbers at the top, ID / Name / Dept. blocks, the times of each day run together; every sheet of the workbook is read', 'ID:', 'Name:', 'Att. Time', 'Y-m-d', 0, 1, 0, 5, 0, m.so FROM (SELECT COALESCE(MAX(sort_order), 0) + 1 AS so, SUM(name = 'ZKTeco attlog.xlsx') AS n FROM import_profiles) m WHERE COALESCE(m.n, 0) = 0;

INSERT INTO settings (skey, svalue, label, grp, input_type, sort_order) VALUES ('db_version', '22', 'Database version', 'system', 'hidden', 0)
  ON DUPLICATE KEY UPDATE svalue = GREATEST(svalue + 0, VALUES(svalue) + 0);
SET FOREIGN_KEY_CHECKS = 1;
