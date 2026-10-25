-- =====================================================================
--  G-SMaRT HRIS upgrade to database version 10 (ZKTeco connection methods (built-in protocol, zkemkeeper SDK, office PC agent) and saved layouts of customizable reports)
--  Target: MySQL 5.5+ / MariaDB 5.5+.  Safe to run more than once; keeps all existing data.
--  Select your HRIS database first (e.g. hris_web), then import this file.
--  Not needed if you simply open the updated site: index.php upgrades the database automatically.
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '+08:00';
SET FOREIGN_KEY_CHECKS = 0;

-- report_layouts
CREATE TABLE IF NOT EXISTS report_layouts (
  id INT NOT NULL AUTO_INCREMENT,
  report_key VARCHAR(40) NOT NULL,
  name VARCHAR(80) NOT NULL,
  col_list TEXT NOT NULL,
  opts TEXT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_rlayout_report (report_key)
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

CALL ihris_add_column('bio_devices', 'conn_method', 'VARCHAR(10) NOT NULL DEFAULT \'native\'');
CALL ihris_add_column('bio_devices', 'push_token_hash', 'VARCHAR(64) NULL');
CALL ihris_add_column('bio_devices', 'push_token_hint', 'VARCHAR(12) NULL');
CALL ihris_add_column('bio_devices', 'auto_post', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL ihris_add_column('bio_devices', 'last_push_at', 'DATETIME NULL');
CALL ihris_add_column('bio_devices', 'device_info', 'VARCHAR(255) NULL');
DROP PROCEDURE IF EXISTS ihris_add_column;



INSERT INTO settings (skey, svalue, label, grp, input_type, sort_order) VALUES ('db_version', '10', 'Database version', 'system', 'hidden', 0)
  ON DUPLICATE KEY UPDATE svalue = GREATEST(svalue + 0, VALUES(svalue) + 0);
SET FOREIGN_KEY_CHECKS = 1;
