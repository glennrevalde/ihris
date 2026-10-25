-- =====================================================================
--  G-SMaRT HRIS upgrade to database version 12 ("All companies / branches" and "All departments" of the employee assignment of the users)
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

CALL ihris_add_column('users', 'scope_all_branches', 'TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'Sees the employees of every company / branch\'');
CALL ihris_add_column('users', 'scope_all_units', 'TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'Sees the employees of every department\'');
DROP PROCEDURE IF EXISTS ihris_add_column;


UPDATE users u SET u.scope_all_branches = 1, u.scope_all_units = 1 WHERE NOT EXISTS (SELECT 1 FROM user_scopes s WHERE s.user_id = u.id) AND (SELECT COUNT(*) FROM settings v WHERE v.skey = 'db_version' AND v.svalue + 0 < 12) > 0;

INSERT INTO settings (skey, svalue, label, grp, input_type, sort_order) VALUES ('db_version', '12', 'Database version', 'system', 'hidden', 0)
  ON DUPLICATE KEY UPDATE svalue = GREATEST(svalue + 0, VALUES(svalue) + 0);
SET FOREIGN_KEY_CHECKS = 1;
