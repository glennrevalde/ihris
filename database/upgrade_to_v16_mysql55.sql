-- =====================================================================
--  G-SMaRT HRIS upgrade to database version 16 (Cancel Calculation of the final pay, day-off of the shift on per-date shift changes)
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

CALL ihris_add_column('final_pays', 'cancelled_at', 'DATETIME NULL');
CALL ihris_add_column('final_pays', 'cancelled_by', 'INT NULL');
CALL ihris_add_column('final_pays', 'cancel_reason', 'VARCHAR(255) NULL');
DROP PROCEDURE IF EXISTS ihris_add_column;

ALTER TABLE `final_pays` MODIFY COLUMN `status` ENUM('draft','approved','released','cancelled') NOT NULL DEFAULT 'draft';
ALTER TABLE `employee_schedules` MODIFY COLUMN `is_rest_day` TINYINT(1) NULL DEFAULT NULL COMMENT 'NULL = follow the shift / default day-off, 1 = rest day, 0 = working day';

UPDATE employee_schedules SET is_rest_day = NULL WHERE is_rest_day = 0 AND shift_id IS NOT NULL;

INSERT INTO settings (skey, svalue, label, grp, input_type, sort_order) VALUES ('db_version', '16', 'Database version', 'system', 'hidden', 0)
  ON DUPLICATE KEY UPDATE svalue = GREATEST(svalue + 0, VALUES(svalue) + 0);
SET FOREIGN_KEY_CHECKS = 1;
