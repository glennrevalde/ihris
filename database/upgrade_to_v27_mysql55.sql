-- =====================================================================
--  G-SMaRT HRIS upgrade to database version 27 (Per Count: benefits paid per count and deductions per Penalty Ticket of the Payroll Transaction)
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

CALL ihris_add_column('benefit_types', 'count_field', 'VARCHAR(20) NULL COMMENT \'v34: Given at Per Count: the count of the Payroll Transaction (meal_comp, meal_comp_ot)\'');
DROP PROCEDURE IF EXISTS ihris_add_column;

ALTER TABLE `benefit_types` MODIFY COLUMN `given_at` ENUM('fixed','duty_day_hol','duty_hour_hol','duty_week_hol','duty_day','duty_hour','duty_week','count') NOT NULL DEFAULT 'fixed';
ALTER TABLE `deduction_types` MODIFY COLUMN `computation` ENUM('fixed','percent_basic','ledger','per_count') NOT NULL DEFAULT 'fixed';

UPDATE settings SET label = 'Meal compensation rate (per count), when no Per Count benefit is set' WHERE skey = 'meal_comp_rate';
UPDATE settings SET label = 'Meal compensation OT rate (per count), when no Per Count benefit is set' WHERE skey = 'meal_comp_ot_rate';
UPDATE settings SET label = 'Penalty ticket amount (per ticket), when no Per Count deduction code is set' WHERE skey = 'ticket_rate';

INSERT INTO settings (skey, svalue, label, grp, input_type, sort_order) VALUES ('db_version', '27', 'Database version', 'system', 'hidden', 0)
  ON DUPLICATE KEY UPDATE svalue = GREATEST(svalue + 0, VALUES(svalue) + 0);
SET FOREIGN_KEY_CHECKS = 1;
