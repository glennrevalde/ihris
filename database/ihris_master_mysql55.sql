-- =====================================================================
--  G-SMaRT HRIS multi-company MASTER database (MySQL 5.5.3+ / MariaDB 5.5+)
--  Only needed when the MySQL account in config/config.php cannot create databases.
--  Run it as a MySQL administrator, then open the site: the configured database
--  (hris_web) is registered as Company 1 and the Super Admin account is created.
--  Change `ihris_master` below if config sets another master_db_name.
-- =====================================================================
CREATE DATABASE IF NOT EXISTS `ihris_master` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `ihris_master`;

-- master_meta
CREATE TABLE IF NOT EXISTS master_meta (
  mkey VARCHAR(40) NOT NULL,
  mvalue VARCHAR(255) NULL,
  PRIMARY KEY (mkey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- companies
CREATE TABLE IF NOT EXISTS companies (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  address VARCHAR(255) NULL,
  contact_no VARCHAR(60) NULL,
  email VARCHAR(150) NULL,
  db_name VARCHAR(64) NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('provisioning','active','inactive','failed') NOT NULL DEFAULT 'provisioning',
  db_status VARCHAR(20) NOT NULL DEFAULT 'pending',
  db_version INT NULL,
  owner_user_id INT NULL,
  owner_name VARCHAR(150) NULL,
  owner_email VARCHAR(150) NULL,
  owner_username VARCHAR(60) NULL,
  owner_can_regen TINYINT(1) NOT NULL DEFAULT 1,
  statutory_source VARCHAR(20) NULL,
  provision_error TEXT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  activated_at DATETIME NULL,
  deactivated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_companies_db (db_name),
  KEY idx_companies_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- company_codes
CREATE TABLE IF NOT EXISTS company_codes (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  code VARCHAR(16) NOT NULL,
  status ENUM('active','replaced','disabled') NOT NULL DEFAULT 'active',
  created_by VARCHAR(80) NULL,
  created_at DATETIME NOT NULL,
  replaced_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_company_code (code),
  KEY idx_codes_company (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- company_memberships
CREATE TABLE IF NOT EXISTS company_memberships (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  user_id INT NOT NULL,
  username VARCHAR(60) NOT NULL,
  email VARCHAR(150) NULL,
  full_name VARCHAR(150) NULL,
  status ENUM('pending','active','inactive','rejected') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_member_username (username),
  UNIQUE KEY uq_member_user (company_id, user_id),
  KEY idx_member_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- super_admins
CREATE TABLE IF NOT EXISTS super_admins (
  id INT NOT NULL AUTO_INCREMENT,
  username VARCHAR(60) NOT NULL,
  email VARCHAR(150) NULL,
  full_name VARCHAR(150) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  last_login DATETIME NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sa_username (username),
  UNIQUE KEY uq_sa_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- company_provisioning_logs
CREATE TABLE IF NOT EXISTS company_provisioning_logs (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  step VARCHAR(60) NOT NULL,
  status ENUM('ok','failed','info') NOT NULL DEFAULT 'ok',
  message TEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_prov_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- master_audit_log
CREATE TABLE IF NOT EXISTS master_audit_log (
  id INT NOT NULL AUTO_INCREMENT,
  actor_type VARCHAR(20) NOT NULL,
  actor_id INT NULL,
  actor_name VARCHAR(80) NULL,
  company_id INT NULL,
  action VARCHAR(60) NOT NULL,
  details TEXT NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_maudit_company (company_id),
  KEY idx_maudit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- company_user_links
CREATE TABLE IF NOT EXISTS company_user_links (
  id INT NOT NULL AUTO_INCREMENT,
  home_company_id INT NOT NULL,
  home_user_id INT NOT NULL,
  username VARCHAR(60) NOT NULL,
  company_id INT NOT NULL,
  user_id INT NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_by VARCHAR(80) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_link_target (home_company_id, home_user_id, company_id),
  KEY idx_link_company (company_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- join_attempts
CREATE TABLE IF NOT EXISTS join_attempts (
  id INT NOT NULL AUTO_INCREMENT,
  ip_address VARCHAR(45) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_join_ip (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The application account needs every right on the master database and on the company databases:
-- GRANT ALL PRIVILEGES ON `ihris\_company\_%`.* TO 'your_mysql_user'@'localhost';
-- GRANT ALL PRIVILEGES ON `ihris_master`.* TO 'your_mysql_user'@'localhost';
-- GRANT ALL PRIVILEGES ON `hris_web`.* TO 'your_mysql_user'@'localhost';
-- FLUSH PRIVILEGES;
