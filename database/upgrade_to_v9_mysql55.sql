-- =====================================================================
--  G-SMaRT HRIS upgrade to database version 9 (employee family, education and previous employment, previous employer YTD, linked accounts of multi-company users)
--  Target: MySQL 5.5+ / MariaDB 5.5+.  Safe to run more than once; keeps all existing data.
--  Select your HRIS database first (e.g. hris_web), then import this file.
--  Not needed if you simply open the updated site: index.php upgrades the database automatically.
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '+08:00';
SET FOREIGN_KEY_CHECKS = 0;

-- employee_children
CREATE TABLE IF NOT EXISTS employee_children (
  id INT NOT NULL AUTO_INCREMENT,
  employee_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  birth_date DATE NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_child_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- employee_education
CREATE TABLE IF NOT EXISTS employee_education (
  id INT NOT NULL AUTO_INCREMENT,
  employee_id INT NOT NULL,
  level VARCHAR(30) NULL,
  school VARCHAR(150) NOT NULL,
  course VARCHAR(150) NULL,
  year_graduated VARCHAR(10) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_educ_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- employee_prev_employment
CREATE TABLE IF NOT EXISTS employee_prev_employment (
  id INT NOT NULL AUTO_INCREMENT,
  employee_id INT NOT NULL,
  company VARCHAR(150) NOT NULL,
  position VARCHAR(100) NULL,
  date_from DATE NULL,
  date_to DATE NULL,
  reason VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_prevemp_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- employee_prev_ytd
CREATE TABLE IF NOT EXISTS employee_prev_ytd (
  id INT NOT NULL AUTO_INCREMENT,
  employee_id INT NOT NULL,
  year SMALLINT NOT NULL,
  employer_name VARCHAR(150) NULL,
  employer_tin VARCHAR(20) NULL,
  date_from DATE NULL,
  date_to DATE NULL,
  gross DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  basic DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  sss DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  hdmf DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  phic DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  other_benefits DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  bonus DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  thirteenth DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  deminimis DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  leave_pay DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  holidays DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  nontax DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  absences DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  np DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  taxable DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  tax DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  remarks VARCHAR(255) NULL,
  created_by INT NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_prevytd (employee_id, year)
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

CALL ihris_add_column('employees', 'eye_color', 'VARCHAR(30) NULL');
CALL ihris_add_column('employees', 'religion', 'VARCHAR(60) NULL');
CALL ihris_add_column('employees', 'weight', 'VARCHAR(20) NULL');
CALL ihris_add_column('employees', 'height', 'VARCHAR(20) NULL');
CALL ihris_add_column('employees', 'spouse_name', 'VARCHAR(150) NULL');
CALL ihris_add_column('employees', 'spouse_address', 'VARCHAR(255) NULL');
CALL ihris_add_column('employees', 'spouse_contact', 'VARCHAR(40) NULL');
CALL ihris_add_column('employees', 'father_name', 'VARCHAR(150) NULL');
CALL ihris_add_column('employees', 'mother_name', 'VARCHAR(150) NULL');
CALL ihris_add_column('users', 'linked_from', 'VARCHAR(80) NULL');
DROP PROCEDURE IF EXISTS ihris_add_column;



INSERT INTO settings (skey, svalue, label, grp, input_type, sort_order) VALUES ('db_version', '9', 'Database version', 'system', 'hidden', 0)
  ON DUPLICATE KEY UPDATE svalue = GREATEST(svalue + 0, VALUES(svalue) + 0);
SET FOREIGN_KEY_CHECKS = 1;
