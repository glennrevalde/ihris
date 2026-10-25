-- =====================================================================
--  G-SMaRT HRIS upgrade to database version 17 (order of the import configurations)
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

CALL ihris_add_column('import_profiles', 'sort_order', 'SMALLINT NOT NULL DEFAULT 0 COMMENT \'Position in the Import configuration lists\'');
DROP PROCEDURE IF EXISTS ihris_add_column;


UPDATE import_profiles SET sort_order = 1 WHERE sort_order = 0 AND name LIKE 'ZKTeco attlog%';
UPDATE import_profiles SET sort_order = 2 WHERE sort_order = 0 AND name LIKE 'Generic CSV%';
UPDATE import_profiles SET sort_order = 3 WHERE sort_order = 0 AND name LIKE 'Space-delimited%';
UPDATE import_profiles SET sort_order = 10 + id WHERE sort_order = 0;

INSERT INTO settings (skey, svalue, label, grp, input_type, sort_order) VALUES ('db_version', '17', 'Database version', 'system', 'hidden', 0)
  ON DUPLICATE KEY UPDATE svalue = GREATEST(svalue + 0, VALUES(svalue) + 0);
SET FOREIGN_KEY_CHECKS = 1;
