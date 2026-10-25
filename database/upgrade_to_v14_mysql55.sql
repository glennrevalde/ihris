-- =====================================================================
--  G-SMaRT HRIS upgrade to database version 14 (pre-arranged overtime of the shifts (Overtime In / Overtime Out), NTE to-do list of the immediate superior and HR)
--  Target: MySQL 5.5+ / MariaDB 5.5+.  Safe to run more than once; keeps all existing data.
--  Select your HRIS database first (e.g. hris_web), then import this file.
--  Not needed if you simply open the updated site: index.php upgrades the database automatically.
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '+08:00';
SET FOREIGN_KEY_CHECKS = 0;

-- nte_todos
CREATE TABLE IF NOT EXISTS nte_todos (
  id INT NOT NULL AUTO_INCREMENT,
  employee_id INT NOT NULL,
  period_month DATE NOT NULL,
  reason VARCHAR(40) NOT NULL,
  late_count INT NOT NULL DEFAULT 0,
  late_minutes INT NOT NULL DEFAULT 0,
  supervisor_id INT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  case_id INT NULL,
  note VARCHAR(255) NULL,
  handled_by INT NULL,
  handled_at DATETIME NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nte_todo (employee_id, period_month, reason),
  KEY idx_nte_status (status),
  KEY idx_nte_sup (supervisor_id, status)
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

CALL ihris_add_column('shifts', 'ot_in', 'TIME NULL COMMENT \'Overtime In: start of the pre-arranged overtime after the shift\'');
CALL ihris_add_column('shifts', 'ot_out', 'TIME NULL COMMENT \'Overtime Out: end of the pre-arranged overtime\'');
CALL ihris_add_column('dtr', 'ot_in', 'DATETIME NULL');
CALL ihris_add_column('dtr', 'ot_out', 'DATETIME NULL');
CALL ihris_add_column('dtr', 'pre_ot_minutes', 'INT NOT NULL DEFAULT 0 COMMENT \'Pre-arranged overtime credited (approved automatically)\'');
CALL ihris_add_column('dtr', 'ot_late_minutes', 'INT NOT NULL DEFAULT 0');
CALL ihris_add_column('dtr', 'ot_ut_minutes', 'INT NOT NULL DEFAULT 0');
DROP PROCEDURE IF EXISTS ihris_add_column;


UPDATE settings SET help = 'Flags employee for HR review / NTE, and lists the employee in the To-do''s of the dashboard of the immediate superior and HR.' WHERE skey = 'habitual_tardy_count';
UPDATE settings SET help = 'Also listed in the NTE To-do''s of the dashboard of the immediate superior and HR.' WHERE skey = 'tardy_cap_minutes' AND (help IS NULL OR help = '');

INSERT INTO settings (skey, svalue, label, grp, input_type, sort_order) VALUES ('db_version', '14', 'Database version', 'system', 'hidden', 0)
  ON DUPLICATE KEY UPDATE svalue = GREATEST(svalue + 0, VALUES(svalue) + 0);
SET FOREIGN_KEY_CHECKS = 1;
