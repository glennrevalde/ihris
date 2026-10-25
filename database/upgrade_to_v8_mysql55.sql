-- =====================================================================
--  G-SMaRT HRIS upgrade to database version 8 (dynamic roles and permissions, user permission overrides, period closing, editable ledger entries)
--  Target: MySQL 5.5+ / MariaDB 5.5+.  Safe to run more than once; keeps all existing data.
--  Select your HRIS database first (e.g. hris_web), then import this file.
--  Not needed if you simply open the updated site: index.php upgrades the database automatically.
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '+08:00';
SET FOREIGN_KEY_CHECKS = 0;

-- roles
CREATE TABLE IF NOT EXISTS roles (
  id INT NOT NULL AUTO_INCREMENT,
  code VARCHAR(40) NOT NULL,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(255) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  is_super TINYINT(1) NOT NULL DEFAULT 0,
  base_role VARCHAR(20) NOT NULL DEFAULT 'employee',
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  created_by INT NULL,
  updated_by INT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_role_code (code),
  UNIQUE KEY uq_role_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- permissions
CREATE TABLE IF NOT EXISTS permissions (
  perm_key VARCHAR(80) NOT NULL,
  module VARCHAR(20) NOT NULL,
  page VARCHAR(40) NOT NULL,
  action VARCHAR(20) NOT NULL,
  label VARCHAR(120) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (perm_key),
  KEY idx_perm_page (page)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- role_permissions
CREATE TABLE IF NOT EXISTS role_permissions (
  role_id INT NOT NULL,
  perm_key VARCHAR(80) NOT NULL,
  PRIMARY KEY (role_id, perm_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- user_permission_overrides
CREATE TABLE IF NOT EXISTS user_permission_overrides (
  user_id INT NOT NULL,
  perm_key VARCHAR(80) NOT NULL,
  effect ENUM('allow','deny') NOT NULL,
  created_at DATETIME NULL,
  created_by INT NULL,
  PRIMARY KEY (user_id, perm_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- period_closings
CREATE TABLE IF NOT EXISTS period_closings (
  id INT NOT NULL AUTO_INCREMENT,
  closing_no VARCHAR(20) NOT NULL,
  pay_group_id INT NOT NULL,
  cutoff_id INT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  branch_id INT NULL,
  employee_group_id INT NULL,
  employee_type_id INT NULL,
  runs INT NOT NULL DEFAULT 0,
  employees INT NOT NULL DEFAULT 0,
  gross DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  deductions DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  net DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  sss_ee DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  sss_er DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  phic_ee DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  phic_er DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  hdmf_ee DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  hdmf_er DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  tax DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  ledger_entries INT NOT NULL DEFAULT 0,
  period_locked TINYINT(1) NOT NULL DEFAULT 0,
  log TEXT NULL,
  backup_confirmed TINYINT(1) NOT NULL DEFAULT 0,
  closed_by INT NULL,
  closed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_closing_no (closing_no),
  KEY idx_closing_pg (pay_group_id, period_end)
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

CALL ihris_add_column('users', 'role_id', 'INT NULL');
CALL ihris_add_column('payroll_runs', 'posted_at', 'DATETIME NULL');
CALL ihris_add_column('payroll_runs', 'posted_by', 'INT NULL');
CALL ihris_add_column('payroll_items', 'posted_at', 'DATETIME NULL');
CALL ihris_add_column('payroll_items', 'closing_id', 'INT NULL');
CALL ihris_add_column('ledger_entries', 'edited_at', 'DATETIME NULL');
CALL ihris_add_column('ledger_entries', 'edited_by', 'INT NULL');
CALL ihris_add_column('ledger_entries', 'edit_note', 'VARCHAR(255) NULL');
DROP PROCEDURE IF EXISTS ihris_add_column;


INSERT IGNORE INTO roles (code, name, description, status, is_system, is_super, base_role, created_at, updated_at) VALUES ('admin', 'System Administrator', 'Super Admin role: every page and every action, manages roles and user permissions. Protected: it cannot be edited, deactivated or deleted.', 'active', 1, 1, 'admin', NOW(), NOW()), ('hr', 'HR Officer', 'Personnel, attendance, leave and the shared setup tables.', 'active', 1, 0, 'hr', NOW(), NOW()), ('payroll', 'Payroll Officer', 'Payroll transactions, processing, registers, reports and the payroll setup.', 'active', 1, 0, 'payroll', NOW(), NOW()), ('supervisor', 'Supervisor / Dept. Head', 'Approves leave and overtime and evaluates their own team, plus self-service.', 'active', 1, 0, 'supervisor', NOW(), NOW()), ('employee', 'Employee', 'Self-service: time clock, own records, leave and overtime requests, payslips.', 'active', 1, 0, 'employee', NOW(), NOW());
UPDATE users u JOIN roles r ON r.code = u.role AND r.is_system = 1 SET u.role_id = r.id WHERE u.role_id IS NULL;
UPDATE payroll_runs SET posted_at = IFNULL(finalized_at, NOW()), posted_by = finalized_by WHERE status = 'finalized' AND posted_at IS NULL;
UPDATE payroll_items pi JOIN payroll_runs r ON r.id = pi.run_id SET pi.posted_at = r.posted_at WHERE r.status = 'finalized' AND pi.posted_at IS NULL;

INSERT INTO settings (skey, svalue, label, grp, input_type, sort_order) VALUES ('db_version', '8', 'Database version', 'system', 'hidden', 0)
  ON DUPLICATE KEY UPDATE svalue = GREATEST(svalue + 0, VALUES(svalue) + 0);
SET FOREIGN_KEY_CHECKS = 1;
