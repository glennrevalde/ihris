-- =====================================================================
--  G-SMaRT HRIS upgrade to database version 23 (invalid-log window for every type of log, flexible shift types 4-6 without required hours)
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

DROP PROCEDURE IF EXISTS ihris_add_column;


UPDATE settings SET label = '# of minutes between LOGS as INVALID', help = 'Every log inside this many minutes after the previous counted log is the same log and is not counted, whatever its type: with 15, a Time in at 07:14 and a Break out at 07:17 = one log at 07:14.' WHERE skey = 'ams_dup_minutes';
UPDATE shifts SET required_hours = NULL WHERE flex_type IN (4, 5, 6);

INSERT INTO settings (skey, svalue, label, grp, input_type, sort_order) VALUES ('db_version', '23', 'Database version', 'system', 'hidden', 0)
  ON DUPLICATE KEY UPDATE svalue = GREATEST(svalue + 0, VALUES(svalue) + 0);
SET FOREIGN_KEY_CHECKS = 1;
