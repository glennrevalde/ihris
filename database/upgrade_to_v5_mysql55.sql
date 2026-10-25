-- =====================================================================
--  G-SMaRT HRIS upgrade to database version 5 (system name G-SMaRT HRIS, break-log and duplicate-log policy notes)
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


INSERT IGNORE INTO settings (skey, svalue, label, grp, input_type, help, sort_order) VALUES
('system_name','G-SMaRT HRIS','System name','general','text','Shown on the sign-in page, the sidebar, the browser tab, the footer, the manual and the e-mails.','1');

UPDATE settings SET svalue = 'G-SMaRT HRIS Notifications' WHERE skey = 'mail_from_name' AND svalue IN ('I-HRIS Notifications', 'I-HRIS', '');
UPDATE settings SET help = 'Every break must be logged. A work segment missing its opening or closing log is absent: shift 8-12 / 1-5, logs 8:00 12:01 5:05 = 1:00-5:00 absent (4 hrs); logs 7:57 5:15 = 8 hrs absent.' WHERE skey = 'ams_nobreak_absent';
UPDATE settings SET help = 'Same rule as No BREAK log as ABSENT, but the uncredited segment counts as undertime. When both are ticked, ABSENT applies.' WHERE skey = 'ams_nobreak_ut';
UPDATE settings SET help = 'A log repeating the previous log of the same type within this many minutes (a double tap) is not counted. Logs of different types always count.' WHERE skey = 'ams_dup_minutes';

INSERT INTO settings (skey, svalue, label, grp, input_type, sort_order) VALUES ('db_version', '5', 'Database version', 'system', 'hidden', 0)
  ON DUPLICATE KEY UPDATE svalue = GREATEST(svalue + 0, VALUES(svalue) + 0);
SET FOREIGN_KEY_CHECKS = 1;
