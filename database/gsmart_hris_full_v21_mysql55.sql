-- =====================================================================
--  G-SMaRT HRIS : complete database (clean install), database version 21
--  Company database + multi-company MASTER database, in one file
--  Target: MySQL 5.5.3 or newer (also MySQL 5.6 / 5.7 / 8.x, MariaDB 5.5+)
--  Generated: 2026-11-11 20:24
--
--  WHAT IT CONTAINS
--   1. The company database `ihris`: every table of version 21 (flexible shifts,
--      import configurations incl. JJM Logs and NBJ Logs, other biometric IDs,
--      roles and permissions of every page, Attendance Logs / Attendance Summary
--      Detailed listings ...), the reference data (statutory tables SSS,
--      PhilHealth, Pag-IBIG and tax, leave types, transaction codes, holidays,
--      sample company / branches, departments and shifts, settings) and the
--      administrator account. No employees and no transactions.
--   2. The master database `ihris_master`: the multi-company tables (companies,
--      Company Codes, memberships, Super Admin accounts, linked accounts, logs),
--      empty. On the first page load the application registers `ihris` as
--      Company 1 with its Company Code and creates the Super Admin account of
--      config/config.php (super_admin_username / super_admin_password).
--   Other company databases (ihris_company_002 ...) are created by the Super Admin
--   (Create Company); they are not part of this file.
--
--  !! IT DROPS AND RE-CREATES EVERY TABLE OF BOTH DATABASES. Do not import it over
--  !! databases with live data (use database/upgrade_to_v21_mysql55.sql there).
--
--  DATABASE NAMES
--   Company database : `ihris`        (the 2 lines marked COMPANY DATABASE NAME)
--   Master database  : `ihris_master` (the 2 lines marked MASTER DATABASE NAME)
--   Use the names of config/config.php ('db_name' and 'master_db_name'), e.g.
--   replace `ihris` with `hris_web` if that is your db_name.
--
--  HOW TO IMPORT
--   Command    : mysql -u root -p < gsmart_hris_full_v21_mysql55.sql
--   phpMyAdmin : Import tab > choose this file > Go (no database selected)
--   The MySQL account of config/config.php needs every right on both databases
--   (and CREATE to add companies), e.g.
--     GRANT ALL PRIVILEGES ON *.* TO 'youruser'@'localhost';
--
--  AFTER IMPORTING
--   Open the site and sign in:  admin / admin123  (you are asked to change it).
--   Super Admin: the username and password set in config/config.php.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+08:00';
SET FOREIGN_KEY_CHECKS = 0;
SET UNIQUE_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- =====================================================================
--  1. COMPANY DATABASE
-- =====================================================================
CREATE DATABASE IF NOT EXISTS `ihris` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;  -- COMPANY DATABASE NAME
USE `ihris`;  -- COMPANY DATABASE NAME

/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `attendance_cutoffs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_cutoffs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pay_group_id` int(11) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `status` enum('open','summarized','approved','locked') NOT NULL DEFAULT 'open',
  `summarized_at` datetime DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `pay_date` date DEFAULT NULL,
  `period_no` tinyint(4) DEFAULT NULL COMMENT '1 = 1st pay of the month, 2 = 2nd pay...',
  `period_month` tinyint(4) DEFAULT NULL,
  `period_year` smallint(6) DEFAULT NULL,
  `description` varchar(60) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cutoff` (`pay_group_id`,`period_start`,`period_end`),
  CONSTRAINT `fk_cut_pg` FOREIGN KEY (`pay_group_id`) REFERENCES `pay_groups` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `attendance_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) DEFAULT NULL,
  `bio_id` varchar(20) DEFAULT NULL,
  `log_time` datetime NOT NULL,
  `punch_type` enum('in','out','break_out','break_in','unknown') NOT NULL DEFAULT 'unknown',
  `source` enum('biometric','import_txt','import_csv','web','manual') NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `device_id` varchar(40) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `accuracy_m` decimal(8,2) DEFAULT NULL,
  `distance_m` decimal(10,2) DEFAULT NULL,
  `within_geofence` tinyint(1) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `face_detected` tinyint(1) DEFAULT NULL,
  `verify_status` enum('auto','pending','verified','rejected') NOT NULL DEFAULT 'auto',
  `verified_by` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `orig_punch_type` varchar(10) DEFAULT NULL COMMENT 'Type read from the device / file before a manual correction',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_log` (`employee_id`,`log_time`,`punch_type`),
  KEY `idx_log_emp` (`employee_id`,`log_time`),
  KEY `idx_log_bio` (`bio_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `attendance_summaries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_summaries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cutoff_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `scheduled_days` decimal(6,2) NOT NULL DEFAULT 0.00,
  `days_worked` decimal(6,2) NOT NULL DEFAULT 0.00,
  `absent_days` decimal(6,2) NOT NULL DEFAULT 0.00,
  `leave_paid_days` decimal(6,2) NOT NULL DEFAULT 0.00,
  `leave_unpaid_days` decimal(6,2) NOT NULL DEFAULT 0.00,
  `suspended_days` decimal(6,2) NOT NULL DEFAULT 0.00,
  `reg_holiday_unworked` decimal(6,2) NOT NULL DEFAULT 0.00,
  `spl_holiday_unworked` decimal(6,2) NOT NULL DEFAULT 0.00,
  `late_minutes` int(11) NOT NULL DEFAULT 0,
  `late_count` int(11) NOT NULL DEFAULT 0,
  `undertime_minutes` int(11) NOT NULL DEFAULT 0,
  `reg_minutes` int(11) NOT NULL DEFAULT 0,
  `ot_minutes` int(11) NOT NULL DEFAULT 0,
  `unauthorized_ot_minutes` int(11) NOT NULL DEFAULT 0,
  `nd_minutes` int(11) NOT NULL DEFAULT 0,
  `open_exceptions` int(11) NOT NULL DEFAULT 0,
  `flags` varchar(255) DEFAULT NULL,
  `source` varchar(10) NOT NULL DEFAULT 'ams',
  `remarks` varchar(255) DEFAULT NULL,
  `entered_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `hours_work` decimal(12,2) NOT NULL DEFAULT 0.00,
  `absent_hrs` decimal(12,2) NOT NULL DEFAULT 0.00,
  `late_hrs` decimal(12,2) NOT NULL DEFAULT 0.00,
  `undertime_hrs` decimal(12,2) NOT NULL DEFAULT 0.00,
  `vl_hrs` decimal(12,2) NOT NULL DEFAULT 0.00,
  `sl_hrs` decimal(12,2) NOT NULL DEFAULT 0.00,
  `el_hrs` decimal(12,2) NOT NULL DEFAULT 0.00,
  `ol_hrs` decimal(12,2) NOT NULL DEFAULT 0.00,
  `pm_hrs` decimal(12,2) NOT NULL DEFAULT 0.00,
  `holiday_hrs` decimal(12,2) NOT NULL DEFAULT 0.00,
  `ot_reg` decimal(12,2) NOT NULL DEFAULT 0.00,
  `rd_hrs` decimal(12,2) NOT NULL DEFAULT 0.00,
  `lh_hrs` decimal(12,2) NOT NULL DEFAULT 0.00,
  `sh_hrs` decimal(12,2) NOT NULL DEFAULT 0.00,
  `rd_ot` decimal(12,2) NOT NULL DEFAULT 0.00,
  `lh_ot` decimal(12,2) NOT NULL DEFAULT 0.00,
  `sh_ot` decimal(12,2) NOT NULL DEFAULT 0.00,
  `lh_rd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `sh_rd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `lh_rd_ot` decimal(12,2) NOT NULL DEFAULT 0.00,
  `sh_rd_ot` decimal(12,2) NOT NULL DEFAULT 0.00,
  `np_reg` decimal(12,2) NOT NULL DEFAULT 0.00,
  `np_rd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `np_lh` decimal(12,2) NOT NULL DEFAULT 0.00,
  `np_sh` decimal(12,2) NOT NULL DEFAULT 0.00,
  `np_reg_ot` decimal(12,2) NOT NULL DEFAULT 0.00,
  `np_rd_ot` decimal(12,2) NOT NULL DEFAULT 0.00,
  `np_lh_ot` decimal(12,2) NOT NULL DEFAULT 0.00,
  `np_sh_ot` decimal(12,2) NOT NULL DEFAULT 0.00,
  `np_lh_rd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `np_sh_rd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `np_lh_rd_ot` decimal(12,2) NOT NULL DEFAULT 0.00,
  `np_sh_rd_ot` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amt_13th` decimal(12,2) NOT NULL DEFAULT 0.00,
  `service_charge` decimal(12,2) NOT NULL DEFAULT 0.00,
  `sl_conversion` decimal(12,2) NOT NULL DEFAULT 0.00,
  `vl_conversion` decimal(12,2) NOT NULL DEFAULT 0.00,
  `other_ded` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tickets` int(11) NOT NULL DEFAULT 0,
  `meal_comp` decimal(12,2) NOT NULL DEFAULT 0.00,
  `meal_comp_ot` decimal(12,2) NOT NULL DEFAULT 0.00,
  `deduction_branch_id` int(11) DEFAULT NULL COMMENT 'Set Deduction Source: branch / division / segment carrying the earnings and deductions',
  `is_closed` tinyint(1) NOT NULL DEFAULT 0,
  `closed_at` datetime DEFAULT NULL,
  `closed_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sum` (`cutoff_id`,`employee_id`),
  CONSTRAINT `fk_sum_cut` FOREIGN KEY (`cutoff_id`) REFERENCES `attendance_cutoffs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `attendance_summary_branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_summary_branches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `summary_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `hours_work` decimal(9,2) NOT NULL DEFAULT 0.00,
  `absent_hrs` decimal(9,2) NOT NULL DEFAULT 0.00,
  `late_hrs` decimal(9,2) NOT NULL DEFAULT 0.00,
  `undertime_hrs` decimal(9,2) NOT NULL DEFAULT 0.00,
  `vl_hrs` decimal(9,2) NOT NULL DEFAULT 0.00,
  `sl_hrs` decimal(9,2) NOT NULL DEFAULT 0.00,
  `el_hrs` decimal(9,2) NOT NULL DEFAULT 0.00,
  `ol_hrs` decimal(9,2) NOT NULL DEFAULT 0.00,
  `pm_hrs` decimal(9,2) NOT NULL DEFAULT 0.00,
  `holiday_hrs` decimal(9,2) NOT NULL DEFAULT 0.00,
  `ot_reg` decimal(9,2) NOT NULL DEFAULT 0.00,
  `rd_hrs` decimal(9,2) NOT NULL DEFAULT 0.00,
  `lh_hrs` decimal(9,2) NOT NULL DEFAULT 0.00,
  `sh_hrs` decimal(9,2) NOT NULL DEFAULT 0.00,
  `rd_ot` decimal(9,2) NOT NULL DEFAULT 0.00,
  `lh_ot` decimal(9,2) NOT NULL DEFAULT 0.00,
  `sh_ot` decimal(9,2) NOT NULL DEFAULT 0.00,
  `lh_rd` decimal(9,2) NOT NULL DEFAULT 0.00,
  `sh_rd` decimal(9,2) NOT NULL DEFAULT 0.00,
  `lh_rd_ot` decimal(9,2) NOT NULL DEFAULT 0.00,
  `sh_rd_ot` decimal(9,2) NOT NULL DEFAULT 0.00,
  `np_reg` decimal(9,2) NOT NULL DEFAULT 0.00,
  `np_rd` decimal(9,2) NOT NULL DEFAULT 0.00,
  `np_lh` decimal(9,2) NOT NULL DEFAULT 0.00,
  `np_sh` decimal(9,2) NOT NULL DEFAULT 0.00,
  `np_reg_ot` decimal(9,2) NOT NULL DEFAULT 0.00,
  `np_rd_ot` decimal(9,2) NOT NULL DEFAULT 0.00,
  `np_lh_ot` decimal(9,2) NOT NULL DEFAULT 0.00,
  `np_sh_ot` decimal(9,2) NOT NULL DEFAULT 0.00,
  `np_lh_rd` decimal(9,2) NOT NULL DEFAULT 0.00,
  `np_sh_rd` decimal(9,2) NOT NULL DEFAULT 0.00,
  `np_lh_rd_ot` decimal(9,2) NOT NULL DEFAULT 0.00,
  `np_sh_rd_ot` decimal(9,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_asb` (`summary_id`,`branch_id`),
  CONSTRAINT `fk_asb_sum` FOREIGN KEY (`summary_id`) REFERENCES `attendance_summaries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `attendance_summary_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_summary_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `summary_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `day_type` enum('ordinary','rest_day','special_holiday','special_rest','regular_holiday','regular_rest','double_holiday') NOT NULL,
  `days` decimal(6,2) NOT NULL DEFAULT 0.00,
  `reg_minutes` int(11) NOT NULL DEFAULT 0,
  `ot_minutes` int(11) NOT NULL DEFAULT 0,
  `nd_minutes` int(11) NOT NULL DEFAULT 0,
  `nd_ot_minutes` int(11) NOT NULL DEFAULT 0,
  `late_minutes` int(11) NOT NULL DEFAULT 0,
  `undertime_minutes` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `fk_sl_sum` (`summary_id`),
  CONSTRAINT `fk_sl_sum` FOREIGN KEY (`summary_id`) REFERENCES `attendance_summaries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(60) DEFAULT NULL,
  `module` varchar(30) NOT NULL DEFAULT 'system',
  `action` varchar(60) NOT NULL,
  `entity` varchar(60) DEFAULT NULL,
  `entity_id` varchar(40) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_entity` (`entity`,`entity_id`),
  KEY `idx_audit_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `benefit_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `benefit_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `category` enum('de_minimis','company','statutory') NOT NULL DEFAULT 'company',
  `tax_treatment` enum('de_minimis','other_benefit','taxable','non_taxable') NOT NULL DEFAULT 'taxable',
  `ceiling_amount` decimal(12,2) DEFAULT NULL COMMENT 'De minimis non-taxable ceiling',
  `ceiling_period` enum('monthly','annual','none') NOT NULL DEFAULT 'none',
  `default_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `frequency` enum('every_payroll','first_cutoff','second_cutoff','monthly_last') NOT NULL DEFAULT 'every_payroll',
  `eligibility_regular_only` tinyint(1) NOT NULL DEFAULT 0,
  `eligibility_min_months` smallint(6) NOT NULL DEFAULT 0,
  `legal_basis` varchar(150) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `gl_code` varchar(20) DEFAULT NULL,
  `no_deduction` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Not reduced by absences',
  `ref_ext` tinyint(1) NOT NULL DEFAULT 0,
  `given_at` enum('fixed','duty_day_hol','duty_hour_hol','duty_week_hol','duty_day','duty_hour','duty_week') NOT NULL DEFAULT 'fixed',
  `bir2316_box` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `benefit_types` VALUES
(1,'RICE','Rice Subsidy','de_minimis','de_minimis',2500.00,'monthly',2500.00,'second_cutoff',0,0,'RR 29-2025: PHP 2,500/month',1,'5050',1,0,'fixed',NULL),
(2,'UNIFORM','Uniform & Clothing Allowance','de_minimis','de_minimis',8000.00,'annual',8000.00,'monthly_last',0,0,'RR 29-2025: PHP 8,000/year',1,'5050',1,0,'fixed',NULL),
(3,'MEDCASH','Medical Cash Allowance to Dependents','de_minimis','de_minimis',333.00,'monthly',333.00,'second_cutoff',1,0,'RR 29-2025: PHP 2,000/semester (PHP 333/month)',1,'5050',1,0,'fixed',NULL),
(4,'LAUNDRY','Laundry Allowance','de_minimis','de_minimis',400.00,'monthly',400.00,'second_cutoff',0,0,'RR 29-2025: PHP 400/month',1,'5050',1,0,'fixed',NULL),
(5,'MEAL-OT','Meal Allowance (OT / Night Shift)','de_minimis','de_minimis',NULL,'none',0.00,'every_payroll',0,0,'RR 29-2025: up to 30% of basic minimum wage',1,'5050',1,0,'fixed',NULL),
(6,'TRANSPO','Transportation Allowance','company','taxable',NULL,'none',1000.00,'every_payroll',0,0,'Taxable fixed allowance',1,'5050',1,0,'fixed',NULL),
(7,'HMO','HMO Coverage (company-paid premium)','company','non_taxable',NULL,'none',0.00,'every_payroll',1,0,'Company policy (information only)',1,'5050',1,0,'fixed',NULL),
(8,'PERF','Performance Bonus','company','other_benefit',NULL,'none',0.00,'monthly_last',1,6,'Counts toward PHP 90,000 exemption',1,'5050',1,0,'fixed',NULL),
(9,'PROVIDENT','Provident Fund (ER contribution)','company','non_taxable',NULL,'none',0.00,'every_payroll',1,12,'Company policy',1,'5050',1,0,'fixed',NULL);
DROP TABLE IF EXISTS `bio_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bio_devices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dev_no` int(11) NOT NULL DEFAULT 1,
  `name` varchar(80) NOT NULL,
  `conn_type` enum('NET','COM') NOT NULL DEFAULT 'NET',
  `comm_address` varchar(60) NOT NULL,
  `comm_port` varchar(20) NOT NULL DEFAULT '4370',
  `dev_password` varchar(20) NOT NULL DEFAULT '0',
  `protocol` enum('udp','tcp') NOT NULL DEFAULT 'udp',
  `branch_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_sync_at` datetime DEFAULT NULL,
  `last_status` varchar(255) DEFAULT NULL,
  `conn_method` varchar(10) NOT NULL DEFAULT 'native',
  `push_token_hash` varchar(64) DEFAULT NULL,
  `push_token_hint` varchar(12) DEFAULT NULL,
  `auto_post` tinyint(1) NOT NULL DEFAULT 0,
  `last_push_at` datetime DEFAULT NULL,
  `device_info` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `branches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(120) NOT NULL,
  `company_name` varchar(150) NOT NULL COMMENT 'Employer-of-record name',
  `address` varchar(255) DEFAULT NULL,
  `tin` varchar(30) DEFAULT NULL,
  `rdo_code` varchar(10) DEFAULT NULL,
  `sss_er_no` varchar(30) DEFAULT NULL,
  `philhealth_er_no` varchar(30) DEFAULT NULL,
  `pagibig_er_no` varchar(30) DEFAULT NULL,
  `bank_name` varchar(80) DEFAULT NULL,
  `bank_account_no` varchar(40) DEFAULT NULL,
  `bank_company_code` varchar(20) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `geofence_radius_m` int(11) NOT NULL DEFAULT 150,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `company_code` varchar(10) DEFAULT NULL,
  `branch_code` varchar(10) DEFAULT NULL,
  `init_code` varchar(10) DEFAULT NULL,
  `location_id` int(11) DEFAULT NULL,
  `approver_name` varchar(120) DEFAULT NULL,
  `checker_name` varchar(120) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `branches` VALUES
(1,'HO','Head Office - Makati','Sample Holdings Corporation','Ayala Ave., Makati City','000-123-456-000','047','03-1234567-8','01-234567890-1','2000-1234-5678','BDO Unibank','0012-3456-7890','SHC01',14.5547000,121.0244000,200,1,NULL,NULL,NULL,NULL,NULL,NULL,1),
(2,'QC','Quezon City Branch','Sample Holdings Corporation','Tomas Morato Ave., Quezon City','000-123-456-001','038','03-1234567-8','01-234567890-1','2000-1234-5678','BDO Unibank','0012-3456-7891','SHC02',14.6349000,121.0331000,200,1,NULL,NULL,NULL,NULL,NULL,NULL,0),
(3,'CEB','Cebu Branch','Sample Visayas Corp.','IT Park, Lahug, Cebu City','000-765-432-000','081','06-7654321-0','01-765432109-8','2000-7654-3210','BPI','1234-5678-90','SVC01',10.3292000,123.9055000,250,1,NULL,NULL,NULL,NULL,NULL,NULL,0);
DROP TABLE IF EXISTS `case_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `case_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `case_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_ca_case` (`case_id`),
  CONSTRAINT `fk_ca_case` FOREIGN KEY (`case_id`) REFERENCES `disciplinary_cases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `case_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `case_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `case_id` int(11) NOT NULL,
  `stage` varchar(40) NOT NULL,
  `notes` text DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(60) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_ce_case` (`case_id`),
  CONSTRAINT `fk_ce_case` FOREIGN KEY (`case_id`) REFERENCES `disciplinary_cases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `class_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `class_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(10) NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `class_groups` VALUES
(1,'ADM','Administration',1),
(2,'OPS','Operations',1),
(3,'SAL','Sales & Marketing',1);
DROP TABLE IF EXISTS `classifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `classifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(10) NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `classifications` VALUES
(1,'DL','Direct Labor',1),
(2,'IDL','Indirect Labor',1),
(3,'ADM','Administrative',1),
(4,'CONF','Confidential',1);
DROP TABLE IF EXISTS `company_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `kind` varchar(20) NOT NULL DEFAULT 'event',
  `event_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `location` varchar(150) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `org_unit_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_event_date` (`event_date`,`end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `competencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `competencies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `competencies` VALUES
(1,'Teamwork','Works cooperatively and supports team goals',1,1),
(2,'Initiative','Acts without being told; proposes improvements',2,1),
(3,'Communication','Clear, timely and respectful communication',3,1),
(4,'Adherence to Code of Conduct','Observes company rules, policies and ethics',4,1),
(5,'Leadership Potential','Influences, guides and develops others',5,1),
(6,'Quality of Work','Accuracy, thoroughness and reliability of output',6,1);
DROP TABLE IF EXISTS `conduct_offenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `conduct_offenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `category` varchar(120) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `severity` enum('light','less_grave','grave') NOT NULL DEFAULT 'light',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `reset_days` int(11) DEFAULT NULL COMMENT 'No. of days to reset the penalty: an offense later than this after the last one counts as a first offense',
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `conduct_offenses` VALUES
(1,'TARDY','Tardiness / Undertime','Habitual tardiness or undertime without valid reason','light',1,NULL),
(2,'AWOL','Unauthorized Absence (AWOL)','Absence without official leave or prior approval','less_grave',1,NULL),
(3,'INSUB','Insubordination','Willful disobedience of lawful orders of superiors','less_grave',1,NULL),
(4,'DISH','Dishonesty / Falsification','Dishonesty, falsification of records or documents','grave',1,NULL),
(5,'MISC','Serious Misconduct / Fraud / Theft','Serious misconduct, fraud, theft or willful breach of trust','grave',1,NULL),
(6,'PERF','Poor Performance','Consistently below-standard performance ratings','light',1,NULL);
DROP TABLE IF EXISTS `conduct_penalties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `conduct_penalties` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `offense_id` int(11) NOT NULL,
  `offense_no` tinyint(4) NOT NULL,
  `penalty_type` enum('verbal_warning','written_warning','suspension','dismissal') NOT NULL,
  `min_days` tinyint(4) DEFAULT NULL,
  `max_days` tinyint(4) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pen` (`offense_id`,`offense_no`),
  CONSTRAINT `fk_pen_off` FOREIGN KEY (`offense_id`) REFERENCES `conduct_offenses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `conduct_penalties` VALUES
(1,1,1,'verbal_warning',NULL,NULL,NULL),
(2,1,2,'written_warning',NULL,NULL,NULL),
(3,1,3,'suspension',1,3,NULL),
(4,1,4,'dismissal',NULL,NULL,NULL),
(5,2,1,'written_warning',NULL,NULL,NULL),
(6,2,2,'suspension',3,5,NULL),
(7,2,3,'dismissal',NULL,NULL,NULL),
(8,3,1,'suspension',1,3,NULL),
(9,3,2,'suspension',5,10,NULL),
(10,3,3,'dismissal',NULL,NULL,NULL),
(11,4,1,'suspension',5,10,'or Dismissal, depending on gravity'),
(12,4,2,'dismissal',NULL,NULL,NULL),
(13,4,3,'dismissal',NULL,NULL,NULL),
(14,5,1,'dismissal',NULL,NULL,NULL),
(15,6,1,'written_warning',NULL,NULL,'Performance improvement plan'),
(16,6,2,'suspension',1,3,NULL),
(17,6,3,'dismissal',NULL,NULL,'Gross inefficiency');
DROP TABLE IF EXISTS `csv_import_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `csv_import_batches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kind` varchar(30) NOT NULL COMMENT 'pay_trans, att_batch, emp_trans, fixed_trans, deductions, benefits',
  `file_name` varchar(255) NOT NULL,
  `cutoff_id` int(11) DEFAULT NULL,
  `total_rows` int(11) NOT NULL DEFAULT 0,
  `created_rows` int(11) NOT NULL DEFAULT 0,
  `updated_rows` int(11) NOT NULL DEFAULT 0,
  `replaced_rows` int(11) NOT NULL DEFAULT 0,
  `rejected_rows` int(11) NOT NULL DEFAULT 0,
  `uploaded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cib_kind` (`kind`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `deduction_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `deduction_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `category` enum('statutory','loan','recurring','adhoc','accountability') NOT NULL,
  `computation` enum('fixed','percent_basic','ledger') NOT NULL DEFAULT 'fixed',
  `default_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `frequency` enum('every_payroll','first_cutoff','second_cutoff','monthly_last') NOT NULL DEFAULT 'every_payroll',
  `authorized_by_law` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Art. 113: no written consent needed',
  `requires_approval` tinyint(1) NOT NULL DEFAULT 0,
  `priority` int(11) NOT NULL DEFAULT 50,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `int_rate` decimal(6,2) NOT NULL DEFAULT 0.00,
  `gl_code` varchar(20) DEFAULT NULL,
  `bir2316_box` varchar(20) DEFAULT NULL,
  `ref_ext` tinyint(1) NOT NULL DEFAULT 0,
  `register_group` enum('sss_loan','hdmf_loan','company_loan','other') NOT NULL DEFAULT 'other',
  `ess_request` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Offered in the Loan / Deduction Request of the employee portal',
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `deduction_types` VALUES
(1,'SSS','SSS Contribution (EE share)','statutory','fixed',0.00,'every_payroll',1,0,1,1,1,0.00,'2100',NULL,0,'other',0),
(2,'PHIC','PhilHealth Contribution (EE share)','statutory','fixed',0.00,'every_payroll',1,0,2,1,1,0.00,'2110',NULL,0,'other',0),
(3,'HDMF','Pag-IBIG Contribution (EE share)','statutory','fixed',0.00,'every_payroll',1,0,3,1,1,0.00,'2120',NULL,0,'other',0),
(4,'WTAX','Withholding Tax on Compensation','statutory','fixed',0.00,'every_payroll',1,0,4,1,1,0.00,'2130',NULL,0,'other',0),
(5,'SSS-SL','SSS Salary Loan','loan','ledger',0.00,'every_payroll',1,0,10,0,1,0.00,'2140',NULL,0,'sss_loan',1),
(6,'SSS-CL','SSS Calamity Loan','loan','ledger',0.00,'every_payroll',1,0,11,0,1,0.00,'2140',NULL,0,'sss_loan',1),
(7,'HDMF-MPL','Pag-IBIG Multi-Purpose Loan','loan','ledger',0.00,'every_payroll',1,0,12,0,1,0.00,'2150',NULL,0,'hdmf_loan',1),
(8,'HDMF-CAL','Pag-IBIG Calamity Loan','loan','ledger',0.00,'every_payroll',1,0,13,0,1,0.00,'2150',NULL,0,'hdmf_loan',1),
(9,'CO-LOAN','Company Loan','loan','ledger',0.00,'every_payroll',0,1,20,0,1,0.00,'1150',NULL,0,'company_loan',1),
(10,'CA','Cash Advance','loan','ledger',0.00,'every_payroll',0,1,21,0,1,0.00,'1150',NULL,0,'company_loan',1),
(11,'COOP','Cooperative Contribution','recurring','fixed',500.00,'second_cutoff',0,0,30,0,1,0.00,'1150',NULL,0,'other',1),
(12,'UNION','Union Dues','recurring','fixed',100.00,'first_cutoff',1,0,31,0,1,0.00,'1150',NULL,0,'other',1),
(13,'UNIF','Uniform Cost Recovery','recurring','fixed',250.00,'every_payroll',0,0,32,0,1,0.00,'1150',NULL,0,'other',1),
(14,'CANTEEN','Canteen Charges','recurring','fixed',0.00,'every_payroll',0,0,33,0,1,0.00,'1150',NULL,0,'other',1),
(15,'DAMAGE','Loss / Damage Accountability','accountability','ledger',0.00,'every_payroll',0,1,40,0,1,0.00,'1150',NULL,0,'other',0),
(16,'ADHOC','Ad hoc / One-time Deduction','adhoc','fixed',0.00,'every_payroll',0,1,50,0,1,0.00,'1150',NULL,0,'other',1);
DROP TABLE IF EXISTS `department_budgets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `department_budgets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `org_unit_id` int(11) NOT NULL,
  `year` smallint(6) NOT NULL,
  `month` tinyint(4) NOT NULL,
  `budget_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_budget` (`org_unit_id`,`year`,`month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `disciplinary_cases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `disciplinary_cases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `case_no` varchar(20) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `offense_id` int(11) NOT NULL,
  `incident_date` date NOT NULL,
  `incident_details` text NOT NULL,
  `policy_provision` varchar(500) DEFAULT NULL,
  `offense_count` tinyint(4) NOT NULL DEFAULT 1,
  `suggested_penalty` varchar(150) DEFAULT NULL,
  `source` enum('manual','attendance','evaluation') NOT NULL DEFAULT 'manual',
  `nte_date` date DEFAULT NULL,
  `explanation_due` date DEFAULT NULL,
  `explanation_text` text DEFAULT NULL,
  `explanation_date` date DEFAULT NULL,
  `hearing_date` datetime DEFAULT NULL,
  `hearing_venue` varchar(150) DEFAULT NULL,
  `hearing_minutes` text DEFAULT NULL,
  `evaluation_notes` text DEFAULT NULL,
  `decision` enum('exonerated','verbal_warning','written_warning','suspension','dismissal') DEFAULT NULL,
  `decision_grounds` text DEFAULT NULL,
  `suspension_days` tinyint(4) DEFAULT NULL,
  `suspension_start` date DEFAULT NULL,
  `effectivity_date` date DEFAULT NULL,
  `nod_date` date DEFAULT NULL,
  `status` enum('draft','nte_issued','explanation_received','hearing_scheduled','for_decision','decided','closed') NOT NULL DEFAULT 'draft',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `case_no` (`case_no`),
  KEY `idx_case_emp` (`employee_id`,`offense_id`),
  KEY `fk_case_off` (`offense_id`),
  CONSTRAINT `fk_case_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_case_off` FOREIGN KEY (`offense_id`) REFERENCES `conduct_offenses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `dtr`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dtr` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `work_date` date NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `shift_id` int(11) DEFAULT NULL,
  `sched_in` datetime DEFAULT NULL,
  `sched_out` datetime DEFAULT NULL,
  `time_in` datetime DEFAULT NULL,
  `break_out` datetime DEFAULT NULL,
  `break_in` datetime DEFAULT NULL,
  `time_out` datetime DEFAULT NULL,
  `break_minutes` int(11) NOT NULL DEFAULT 0,
  `worked_minutes` int(11) NOT NULL DEFAULT 0,
  `regular_minutes` int(11) NOT NULL DEFAULT 0,
  `late_minutes` int(11) NOT NULL DEFAULT 0,
  `undertime_minutes` int(11) NOT NULL DEFAULT 0,
  `ot_minutes` int(11) NOT NULL DEFAULT 0,
  `approved_ot_minutes` int(11) NOT NULL DEFAULT 0,
  `nd_minutes` int(11) NOT NULL DEFAULT 0,
  `nd_ot_minutes` int(11) NOT NULL DEFAULT 0,
  `day_type` enum('ordinary','rest_day','special_holiday','special_rest','regular_holiday','regular_rest','double_holiday') NOT NULL DEFAULT 'ordinary',
  `is_workday` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Scheduled working day',
  `status` enum('present','absent','on_leave','rest_day','holiday','suspended','incomplete','no_schedule','pending') NOT NULL DEFAULT 'pending',
  `leave_application_id` int(11) DEFAULT NULL,
  `leave_paid` tinyint(1) NOT NULL DEFAULT 0,
  `source` varchar(20) DEFAULT NULL,
  `exceptions` varchar(255) DEFAULT NULL,
  `exception_status` enum('none','open','resolved') NOT NULL DEFAULT 'none',
  `exception_note` varchar(255) DEFAULT NULL,
  `manual_override` tinyint(1) NOT NULL DEFAULT 0,
  `remarks` varchar(255) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `break_out2` datetime DEFAULT NULL,
  `break_in2` datetime DEFAULT NULL,
  `absent_minutes` int(11) NOT NULL DEFAULT 0,
  `leave_minutes` int(11) NOT NULL DEFAULT 0,
  `leave_bucket` varchar(4) DEFAULT NULL,
  `holiday_minutes` int(11) NOT NULL DEFAULT 0,
  `penalty_minutes` int(11) NOT NULL DEFAULT 0,
  `log_status` varchar(40) DEFAULT NULL,
  `calc_version` tinyint(4) NOT NULL DEFAULT 0 COMMENT 'Engine version that computed the row; older rows are rebuilt when shown',
  `ot_in` datetime DEFAULT NULL,
  `ot_out` datetime DEFAULT NULL,
  `pre_ot_minutes` int(11) NOT NULL DEFAULT 0 COMMENT 'Pre-arranged overtime credited (approved automatically)',
  `ot_late_minutes` int(11) NOT NULL DEFAULT 0,
  `ot_ut_minutes` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dtr` (`employee_id`,`work_date`),
  KEY `idx_dtr_date` (`work_date`),
  CONSTRAINT `fk_dtr_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `earning_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `earning_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(120) NOT NULL,
  `int_rate` decimal(6,2) NOT NULL DEFAULT 0.00,
  `gl_code` varchar(20) DEFAULT NULL,
  `taxable` tinyint(1) NOT NULL DEFAULT 1,
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Separate withholding rate on this earning (0 = none)',
  `special` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Special earning: non-taxable up to the special earnings ceiling',
  `de_minimis` tinyint(1) NOT NULL DEFAULT 0,
  `bir2316_box` varchar(20) DEFAULT NULL,
  `ref_ext` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Reference only: shown but not added to pay',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `earning_types` VALUES
(1,'1000','Fixed overtime',0.00,'5010',1,0.00,0,0,NULL,0,1),
(2,'1001','Consultancy fee',0.00,'5000',1,0.00,0,0,NULL,0,1),
(3,'1002','Salary adjustment (add)',0.00,'5000',1,0.00,0,0,NULL,0,1),
(4,'1003','Shortage allowance',0.00,'5050',1,0.00,0,0,NULL,0,1),
(5,'1004','Productivity bonus',0.00,'5050',0,0.00,0,0,NULL,0,1),
(6,'1005','Sales incentive',0.00,'5050',1,0.00,0,0,NULL,0,1);
DROP TABLE IF EXISTS `email_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `to_email` varchar(150) NOT NULL,
  `to_name` varchar(150) DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `body_html` mediumtext NOT NULL,
  `related_type` varchar(40) DEFAULT NULL,
  `related_id` int(11) DEFAULT NULL,
  `status` enum('queued','sent','failed','logged') NOT NULL DEFAULT 'queued',
  `error_message` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_email_rel` (`related_type`,`related_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `emp_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `emp_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(10) NOT NULL,
  `name` varchar(100) NOT NULL,
  `log_group` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Attendance logged by group',
  `cost_center` varchar(40) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `emp_groups` VALUES
(1,'00','Executives',0,NULL,0,1),
(2,'01','Rank & File - Monthly',0,NULL,1,1),
(3,'02','Rank & File - Daily',0,NULL,0,1),
(4,'07','OJT',0,NULL,0,1),
(5,'08','Apprentice',0,NULL,0,1),
(6,'99','Piecework (Pakyaw)',0,NULL,0,1);
DROP TABLE IF EXISTS `emp_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `emp_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(10) NOT NULL,
  `name` varchar(100) NOT NULL,
  `conversion_factor` decimal(10,4) NOT NULL DEFAULT 26.0000 COMMENT 'Days per month: daily rate = monthly rate / factor',
  `hours_per_day` decimal(5,2) NOT NULL DEFAULT 8.00,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `emp_types` VALUES
(1,'AA','Monthly Staff',26.0000,8.00,1,1),
(2,'BB','Daily Staff 30',30.0000,8.00,0,1),
(3,'CC','Daily Staff 26',26.0000,8.00,0,1);
DROP TABLE IF EXISTS `employee_benefits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_benefits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `benefit_type_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','stopped') NOT NULL DEFAULT 'active',
  `remarks` varchar(255) DEFAULT NULL,
  `post_term` varchar(12) DEFAULT NULL COMMENT 'every_pay, first_pay .. fourth_pay; NULL = the frequency of the benefit type',
  `import_batch_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_eb_emp` (`employee_id`),
  KEY `fk_eb_type` (`benefit_type_id`),
  CONSTRAINT `fk_eb_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_eb_type` FOREIGN KEY (`benefit_type_id`) REFERENCES `benefit_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `employee_bio_ids`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_bio_ids` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `bio_id` varchar(20) NOT NULL COMMENT 'Another biometric / device user ID of the employee',
  `note` varchar(80) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_emp_bio_id` (`bio_id`),
  KEY `idx_emp_bio_emp` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `employee_children`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_children` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `birth_date` date DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_child_emp` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `employee_deductions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_deductions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `deduction_type_id` int(11) NOT NULL,
  `ledger_id` int(11) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Per-payroll amount (or % if percent_basic)',
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `written_consent` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('pending','active','stopped','completed','rejected') NOT NULL DEFAULT 'active',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `import_batch_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ed_emp` (`employee_id`,`status`),
  KEY `fk_ed_type` (`deduction_type_id`),
  CONSTRAINT `fk_ed_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ed_type` FOREIGN KEY (`deduction_type_id`) REFERENCES `deduction_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `employee_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `doc_type` enum('pre_employment','contract','appraisal','government_id','clearance','memo','other') NOT NULL DEFAULT 'other',
  `title` varchar(150) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_doc_emp` (`employee_id`),
  CONSTRAINT `fk_doc_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `employee_education`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_education` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `level` varchar(30) DEFAULT NULL,
  `school` varchar(150) NOT NULL,
  `course` varchar(150) DEFAULT NULL,
  `year_graduated` varchar(10) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_educ_emp` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `employee_fixed_trans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_fixed_trans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `code_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `terms` enum('every_pay','monthly_split','first_pay','second_pay','third_pay','fourth_pay') DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','stopped','hold') NOT NULL DEFAULT 'active',
  `remarks` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `import_batch_id` int(11) DEFAULT NULL,
  `held_at` datetime DEFAULT NULL,
  `held_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_eft_emp` (`employee_id`),
  KEY `fk_eft_code` (`code_id`),
  CONSTRAINT `fk_eft_code` FOREIGN KEY (`code_id`) REFERENCES `fixed_trans_codes` (`id`),
  CONSTRAINT `fk_eft_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `employee_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `change_type` enum('hire','promotion','transfer','salary_change','grade_change','status_change','branch_change','separation','other') NOT NULL,
  `effective_date` date NOT NULL,
  `old_position` varchar(100) DEFAULT NULL,
  `new_position` varchar(100) DEFAULT NULL,
  `old_org_unit` varchar(120) DEFAULT NULL,
  `new_org_unit` varchar(120) DEFAULT NULL,
  `old_rate` decimal(12,2) DEFAULT NULL,
  `new_rate` decimal(12,2) DEFAULT NULL,
  `rate_type` enum('monthly','daily') DEFAULT NULL,
  `old_grade` varchar(30) DEFAULT NULL,
  `new_grade` varchar(30) DEFAULT NULL,
  `remarks` varchar(500) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hist_emp` (`employee_id`,`effective_date`),
  CONSTRAINT `fk_hist_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `employee_import_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_import_batches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `file_name` varchar(255) NOT NULL,
  `total_rows` int(11) NOT NULL DEFAULT 0,
  `created_rows` int(11) NOT NULL DEFAULT 0,
  `updated_rows` int(11) NOT NULL DEFAULT 0,
  `rejected_rows` int(11) NOT NULL DEFAULT 0,
  `uploaded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `employee_prev_employment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_prev_employment` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `company` varchar(150) NOT NULL,
  `position` varchar(100) DEFAULT NULL,
  `date_from` date DEFAULT NULL,
  `date_to` date DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_prevemp_emp` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `employee_prev_ytd`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_prev_ytd` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `year` smallint(6) NOT NULL,
  `employer_name` varchar(150) DEFAULT NULL,
  `employer_tin` varchar(20) DEFAULT NULL,
  `date_from` date DEFAULT NULL,
  `date_to` date DEFAULT NULL,
  `gross` decimal(14,2) NOT NULL DEFAULT 0.00,
  `basic` decimal(14,2) NOT NULL DEFAULT 0.00,
  `sss` decimal(14,2) NOT NULL DEFAULT 0.00,
  `hdmf` decimal(14,2) NOT NULL DEFAULT 0.00,
  `phic` decimal(14,2) NOT NULL DEFAULT 0.00,
  `other_benefits` decimal(14,2) NOT NULL DEFAULT 0.00,
  `bonus` decimal(14,2) NOT NULL DEFAULT 0.00,
  `thirteenth` decimal(14,2) NOT NULL DEFAULT 0.00,
  `deminimis` decimal(14,2) NOT NULL DEFAULT 0.00,
  `leave_pay` decimal(14,2) NOT NULL DEFAULT 0.00,
  `holidays` decimal(14,2) NOT NULL DEFAULT 0.00,
  `nontax` decimal(14,2) NOT NULL DEFAULT 0.00,
  `absences` decimal(14,2) NOT NULL DEFAULT 0.00,
  `np` decimal(14,2) NOT NULL DEFAULT 0.00,
  `taxable` decimal(14,2) NOT NULL DEFAULT 0.00,
  `tax` decimal(14,2) NOT NULL DEFAULT 0.00,
  `remarks` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prevytd` (`employee_id`,`year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `employee_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `work_date` date NOT NULL,
  `shift_id` int(11) DEFAULT NULL,
  `is_rest_day` tinyint(1) DEFAULT NULL COMMENT 'NULL = follow the shift / default day-off, 1 = rest day, 0 = working day',
  `branch_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sched` (`employee_id`,`work_date`),
  CONSTRAINT `fk_sched_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `employee_term_schedule`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_term_schedule` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `term_id` int(11) NOT NULL,
  `seq_no` int(11) NOT NULL,
  `due_date` date DEFAULT NULL COMMENT 'Projected payday',
  `principal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `interest` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','posted','paid','skipped','cancelled') NOT NULL DEFAULT 'pending',
  `payroll_run_id` int(11) DEFAULT NULL,
  `cutoff_id` int(11) DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `reference` varchar(80) DEFAULT NULL COMMENT 'Run no. or receipt of a payment outside payroll',
  PRIMARY KEY (`id`),
  KEY `idx_ets_term` (`term_id`,`seq_no`),
  KEY `idx_ets_run` (`payroll_run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `employee_term_trans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_term_trans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `trans_type` enum('earning','deduction') NOT NULL DEFAULT 'deduction',
  `earning_type_id` int(11) DEFAULT NULL,
  `deduction_type_id` int(11) DEFAULT NULL,
  `doc_no` varchar(40) DEFAULT NULL COMMENT 'Document / voucher / agency reference',
  `trans_date` date NOT NULL,
  `principal` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Transaction amount (principal)',
  `monthly_rate` decimal(7,3) NOT NULL DEFAULT 0.000 COMMENT 'Monthly interest rate in percent',
  `interest_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Principal + interest',
  `terms` int(11) NOT NULL DEFAULT 1 COMMENT 'Number of paydays',
  `per_pay_principal` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Trans/PD (Pri)',
  `per_pay_interest` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Trans/PD (Int)',
  `trans_term` enum('every_pay','first_pay','second_pay','third_pay','fourth_pay') NOT NULL DEFAULT 'every_pay',
  `start_date` date NOT NULL COMMENT 'First payday the installment may be taken',
  `next_date` date DEFAULT NULL COMMENT 'Projected next installment',
  `branch_id` int(11) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `paid_terms` int(11) NOT NULL DEFAULT 0,
  `total_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','completed','cancelled') NOT NULL DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ett_emp` (`employee_id`,`status`),
  KEY `idx_ett_status` (`status`,`start_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `employee_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cutoff_id` int(11) DEFAULT NULL,
  `pay_group_id` int(11) DEFAULT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `employee_id` int(11) NOT NULL,
  `trans_type` enum('earning','deduction') NOT NULL,
  `earning_type_id` int(11) DEFAULT NULL,
  `deduction_type_id` int(11) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `remarks` varchar(255) DEFAULT NULL,
  `status` enum('pending','included','cancelled') NOT NULL DEFAULT 'pending',
  `payroll_run_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `import_batch_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_et_emp` (`employee_id`,`period_start`),
  KEY `idx_et_cut` (`cutoff_id`),
  CONSTRAINT `fk_et_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_no` varchar(20) NOT NULL,
  `biometric_id` varchar(20) DEFAULT NULL,
  `last_name` varchar(60) NOT NULL,
  `first_name` varchar(60) NOT NULL,
  `middle_name` varchar(60) DEFAULT NULL,
  `suffix` varchar(10) DEFAULT NULL,
  `gender` enum('male','female') NOT NULL,
  `birth_date` date DEFAULT NULL,
  `civil_status` enum('single','married','widowed','separated','annulled') NOT NULL DEFAULT 'single',
  `address` varchar(255) DEFAULT NULL,
  `contact_no` varchar(40) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `emergency_contact` varchar(150) DEFAULT NULL,
  `emergency_phone` varchar(40) DEFAULT NULL,
  `sss_no` varchar(20) DEFAULT NULL,
  `philhealth_no` varchar(20) DEFAULT NULL,
  `pagibig_no` varchar(20) DEFAULT NULL,
  `tin` varchar(20) DEFAULT NULL,
  `date_hired` date NOT NULL,
  `date_regularized` date DEFAULT NULL,
  `employment_status` enum('probationary','regular','project_based','contractual','resigned','terminated','retired') NOT NULL DEFAULT 'probationary',
  `position` varchar(100) NOT NULL,
  `rank_level` varchar(40) DEFAULT NULL,
  `org_unit_id` int(11) DEFAULT NULL,
  `salary_grade_id` int(11) DEFAULT NULL,
  `salary_step` tinyint(4) DEFAULT NULL,
  `rate_type` enum('monthly','daily') NOT NULL DEFAULT 'monthly',
  `basic_rate` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Monthly salary or daily rate depending on rate_type',
  `days_per_year` smallint(6) NOT NULL DEFAULT 261 COMMENT 'Factor for daily/monthly rate conversion',
  `pay_group_id` int(11) NOT NULL,
  `default_branch_id` int(11) NOT NULL COMMENT 'Employer-of-record for statutory deductions',
  `shift_id` int(11) DEFAULT NULL,
  `supervisor_id` int(11) DEFAULT NULL,
  `attendance_method` enum('biometric','web','both','import') NOT NULL DEFAULT 'biometric',
  `timekeeping_exempt` tinyint(1) NOT NULL DEFAULT 0,
  `is_mwe` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Minimum wage earner (tax exempt)',
  `is_solo_parent` tinyint(1) NOT NULL DEFAULT 0,
  `solo_parent_id_no` varchar(40) DEFAULT NULL,
  `pagibig_voluntary` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Voluntary additional EE contribution per month',
  `bank_name` varchar(80) DEFAULT NULL,
  `bank_account_no` varchar(40) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `status` enum('active','separated') NOT NULL DEFAULT 'active',
  `date_separated` date DEFAULT NULL,
  `separation_type` varchar(40) DEFAULT NULL,
  `separation_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  `employee_type_id` int(11) DEFAULT NULL,
  `employee_group_id` int(11) DEFAULT NULL,
  `class_group_id` int(11) DEFAULT NULL,
  `rank_category_id` int(11) DEFAULT NULL,
  `classification_id` int(11) DEFAULT NULL,
  `job_id` int(11) DEFAULT NULL,
  `exemption_code_id` int(11) DEFAULT NULL,
  `location_id` int(11) DEFAULT NULL,
  `bank_account_type` varchar(20) DEFAULT NULL,
  `import_batch_id` int(11) DEFAULT NULL,
  `eye_color` varchar(30) DEFAULT NULL,
  `religion` varchar(60) DEFAULT NULL,
  `weight` varchar(20) DEFAULT NULL,
  `height` varchar(20) DEFAULT NULL,
  `spouse_name` varchar(150) DEFAULT NULL,
  `spouse_address` varchar(255) DEFAULT NULL,
  `spouse_contact` varchar(40) DEFAULT NULL,
  `father_name` varchar(150) DEFAULT NULL,
  `mother_name` varchar(150) DEFAULT NULL,
  `day_off` varchar(20) DEFAULT NULL COMMENT 'Default weekly day-off: weekdays 0 (Sunday) to 6, comma separated; applied on 7-day shifts',
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_no` (`employee_no`),
  UNIQUE KEY `biometric_id` (`biometric_id`),
  KEY `idx_emp_ou` (`org_unit_id`),
  KEY `idx_emp_pg` (`pay_group_id`),
  KEY `idx_emp_branch` (`default_branch_id`),
  KEY `idx_emp_sup` (`supervisor_id`),
  KEY `fk_emp_grade` (`salary_grade_id`),
  KEY `fk_emp_shift` (`shift_id`),
  CONSTRAINT `fk_emp_branch` FOREIGN KEY (`default_branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_emp_grade` FOREIGN KEY (`salary_grade_id`) REFERENCES `salary_grades` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_emp_ou` FOREIGN KEY (`org_unit_id`) REFERENCES `org_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_emp_pg` FOREIGN KEY (`pay_group_id`) REFERENCES `pay_groups` (`id`),
  CONSTRAINT `fk_emp_shift` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `evaluation_cycles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `evaluation_cycles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `cycle_type` enum('semi_annual','annual') NOT NULL DEFAULT 'semi_annual',
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `quant_weight` decimal(5,2) NOT NULL DEFAULT 60.00,
  `qual_weight` decimal(5,2) NOT NULL DEFAULT 40.00,
  `self_weight` decimal(5,2) NOT NULL DEFAULT 20.00,
  `supervisor_weight` decimal(5,2) NOT NULL DEFAULT 60.00,
  `peer_weight` decimal(5,2) NOT NULL DEFAULT 20.00,
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `evaluation_kpis`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `evaluation_kpis` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `evaluation_id` int(11) NOT NULL,
  `kpi_name` varchar(150) NOT NULL,
  `weight` decimal(6,2) NOT NULL,
  `target` decimal(14,2) NOT NULL,
  `actual` decimal(14,2) DEFAULT NULL,
  `lower_is_better` tinyint(1) NOT NULL DEFAULT 0,
  `achievement_pct` decimal(7,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_kpi_eval` (`evaluation_id`),
  CONSTRAINT `fk_kpi_eval` FOREIGN KEY (`evaluation_id`) REFERENCES `evaluations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `evaluation_ratings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `evaluation_ratings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `evaluation_id` int(11) NOT NULL,
  `competency_id` int(11) NOT NULL,
  `rater_type` enum('self','supervisor','peer') NOT NULL,
  `rater_name` varchar(150) DEFAULT NULL,
  `rating` tinyint(4) NOT NULL,
  `comment` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_rat_eval` (`evaluation_id`),
  CONSTRAINT `fk_rat_eval` FOREIGN KEY (`evaluation_id`) REFERENCES `evaluations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `evaluations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `evaluations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cycle_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `evaluator_employee_id` int(11) DEFAULT NULL,
  `status` enum('draft','submitted','finalized') NOT NULL DEFAULT 'draft',
  `quant_pct` decimal(7,2) DEFAULT NULL,
  `quant_score` decimal(4,2) DEFAULT NULL,
  `qual_score` decimal(4,2) DEFAULT NULL,
  `overall_score` decimal(4,2) DEFAULT NULL,
  `rating_label` varchar(40) DEFAULT NULL,
  `merit_increase_pct` decimal(5,2) DEFAULT NULL,
  `step_increase` tinyint(4) DEFAULT NULL,
  `promotion_eligible` tinyint(1) DEFAULT NULL,
  `disciplinary_referral` tinyint(1) NOT NULL DEFAULT 0,
  `recommendation` varchar(1000) DEFAULT NULL,
  `remarks` varchar(1000) DEFAULT NULL,
  `applied_at` datetime DEFAULT NULL,
  `finalized_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_eval` (`cycle_id`,`employee_id`),
  KEY `fk_eval_emp` (`employee_id`),
  CONSTRAINT `fk_eval_cycle` FOREIGN KEY (`cycle_id`) REFERENCES `evaluation_cycles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_eval_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `exemption_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `exemption_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(10) NOT NULL,
  `name` varchar(100) NOT NULL,
  `tax_exempt` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Compensation exempt from withholding tax (e.g. MWE)',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `exemption_codes` VALUES
(1,'Z','Taxable (TRAIN law, no personal exemption)',0,1),
(2,'MWE','Minimum wage earner (exempt)',1,1),
(3,'EXEMP','Tax exempt',1,1);
DROP TABLE IF EXISTS `final_pay_overrides`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `final_pay_overrides` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `final_pay_id` int(11) NOT NULL,
  `line_key` varchar(120) NOT NULL,
  `action` varchar(10) NOT NULL DEFAULT 'edit',
  `description` varchar(255) DEFAULT NULL,
  `quantity` decimal(10,2) DEFAULT NULL,
  `amount` decimal(12,2) DEFAULT NULL,
  `original_description` varchar(255) DEFAULT NULL,
  `original_amount` decimal(12,2) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fp_line` (`final_pay_id`,`line_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `final_pays`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `final_pays` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `separation_date` date NOT NULL,
  `last_salary` decimal(12,2) NOT NULL DEFAULT 0.00,
  `prorated_13th` decimal(12,2) NOT NULL DEFAULT 0.00,
  `leave_conversion` decimal(12,2) NOT NULL DEFAULT 0.00,
  `other_earnings` decimal(12,2) NOT NULL DEFAULT 0.00,
  `loan_offsets` decimal(12,2) NOT NULL DEFAULT 0.00,
  `other_deductions` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_adjustment` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Positive = tax payable, negative = refund',
  `net_final_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `due_date` date NOT NULL,
  `release_date` date DEFAULT NULL,
  `status` enum('draft','approved','released','cancelled') NOT NULL DEFAULT 'draft',
  `breakdown` mediumtext DEFAULT NULL,
  `payroll_run_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_fp_emp` (`employee_id`),
  CONSTRAINT `fk_fp_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `fixed_trans_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fixed_trans_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(10) NOT NULL,
  `name` varchar(120) NOT NULL,
  `trans_type` enum('earning','deduction') NOT NULL DEFAULT 'earning',
  `role` enum('none','sss','hdmf','phic','tax') NOT NULL DEFAULT 'none' COMMENT 'Fixed amount replaces the computed statutory deduction',
  `default_terms` enum('every_pay','monthly_split','first_pay','second_pay','third_pay','fourth_pay') NOT NULL DEFAULT 'every_pay',
  `gl_code` varchar(20) DEFAULT NULL,
  `de_minimis` tinyint(1) NOT NULL DEFAULT 0,
  `bir2316_box` varchar(20) DEFAULT NULL,
  `taxable` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `fixed_trans_codes` VALUES
(1,'01','SSS contribution (fixed)','deduction','sss','monthly_split','2100',0,NULL,0,1),
(2,'02','Pag-IBIG contribution (fixed)','deduction','hdmf','monthly_split','2120',0,NULL,0,1),
(3,'03','PhilHealth contribution (fixed)','deduction','phic','monthly_split','2110',0,NULL,0,1),
(4,'04','Withholding tax (fixed)','deduction','tax','every_pay','2130',0,NULL,0,1),
(5,'10','COLA','earning','none','every_pay','5050',0,NULL,1,1),
(6,'11','Position allowance','earning','none','every_pay','5050',0,NULL,1,1),
(7,'12','Consultancy allowance','earning','none','every_pay','5050',0,NULL,1,1),
(8,'13','Job allowance','earning','none','every_pay','5050',0,NULL,1,1);
DROP TABLE IF EXISTS `gl_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `gl_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(120) NOT NULL,
  `account_type` enum('asset','liability','equity','income','expense') NOT NULL DEFAULT 'expense',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `gl_accounts` VALUES
(1,'1010','Cash in bank - payroll','asset',1),
(2,'1150','Advances / receivables from employees','asset',1),
(3,'2100','SSS premiums payable','liability',1),
(4,'2110','PhilHealth premiums payable','liability',1),
(5,'2120','Pag-IBIG contributions payable','liability',1),
(6,'2130','Withholding tax on compensation payable','liability',1),
(7,'2140','SSS loans payable','liability',1),
(8,'2150','Pag-IBIG loans payable','liability',1),
(9,'2200','Accrued payroll (net pay payable)','liability',1),
(10,'5000','Salaries and wages','expense',1),
(11,'5010','Overtime pay','expense',1),
(12,'5020','Holiday and premium pay','expense',1),
(13,'5030','Night premium','expense',1),
(14,'5040','13th month pay','expense',1),
(15,'5050','Allowances and benefits','expense',1),
(16,'5060','Employer contributions (SSS, PhilHealth, Pag-IBIG)','expense',1);
DROP TABLE IF EXISTS `holidays`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `holidays` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `holiday_date` date NOT NULL,
  `name` varchar(120) NOT NULL,
  `holiday_type` enum('regular','special') NOT NULL,
  `branch_id` int(11) DEFAULT NULL COMMENT 'NULL = nationwide, otherwise local holiday',
  PRIMARY KEY (`id`),
  KEY `idx_hol_date` (`holiday_date`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `holidays` VALUES
(1,'2026-01-01','New Year\'s Day','regular',NULL),
(2,'2026-02-17','Chinese New Year','special',NULL),
(3,'2026-04-02','Maundy Thursday','regular',NULL),
(4,'2026-04-03','Good Friday','regular',NULL),
(5,'2026-04-04','Black Saturday','special',NULL),
(6,'2026-04-09','Araw ng Kagitingan','regular',NULL),
(7,'2026-05-01','Labor Day','regular',NULL),
(8,'2026-06-12','Independence Day','regular',NULL),
(9,'2026-08-21','Ninoy Aquino Day','special',NULL),
(10,'2026-08-31','National Heroes Day','regular',NULL),
(11,'2026-11-01','All Saints\' Day','special',NULL),
(12,'2026-11-02','All Souls\' Day','special',NULL),
(13,'2026-11-30','Bonifacio Day','regular',NULL),
(14,'2026-12-08','Feast of the Immaculate Conception','special',NULL),
(15,'2026-12-24','Christmas Eve','special',NULL),
(16,'2026-12-25','Christmas Day','regular',NULL),
(17,'2026-12-30','Rizal Day','regular',NULL),
(18,'2026-12-31','Last Day of the Year','special',NULL);
DROP TABLE IF EXISTS `import_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `import_batches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_type` enum('biometric','csv','xlsx','device','log_table') NOT NULL,
  `profile_id` int(11) DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `total_rows` int(11) NOT NULL DEFAULT 0,
  `imported` int(11) NOT NULL DEFAULT 0,
  `duplicates` int(11) NOT NULL DEFAULT 0,
  `flagged` int(11) NOT NULL DEFAULT 0,
  `uploaded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `import_exceptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `import_exceptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `row_no` int(11) NOT NULL,
  `raw_data` varchar(1000) DEFAULT NULL,
  `employee_no` varchar(40) DEFAULT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `work_date` date DEFAULT NULL,
  `time_in` datetime DEFAULT NULL,
  `time_out` datetime DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `issue` varchar(500) NOT NULL,
  `status` enum('open','posted','rejected') NOT NULL DEFAULT 'open',
  `resolved_by` int(11) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_ie_batch` (`batch_id`),
  CONSTRAINT `fk_ie_batch` FOREIGN KEY (`batch_id`) REFERENCES `import_batches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `import_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `import_profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `delimiter` varchar(10) NOT NULL DEFAULT 'tab' COMMENT 'tab, comma, pipe, semicolon, space',
  `has_header` tinyint(1) NOT NULL DEFAULT 0,
  `col_bio_id` tinyint(4) NOT NULL DEFAULT 1,
  `col_datetime` tinyint(4) DEFAULT NULL COMMENT 'Combined date-time column',
  `col_date` tinyint(4) DEFAULT NULL,
  `col_time` tinyint(4) DEFAULT NULL,
  `datetime_format` varchar(30) NOT NULL DEFAULT 'Y-m-d H:i:s',
  `col_punch` tinyint(4) DEFAULT NULL,
  `punch_map` varchar(150) DEFAULT NULL COMMENT 'e.g. 0=in,1=out,2=break_out,3=break_in',
  `col_device` tinyint(4) DEFAULT NULL,
  `default_branch_id` int(11) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `layout` enum('delimited','fixed','report') NOT NULL DEFAULT 'delimited' COMMENT 'Column layout of the log file',
  `fw_id_start` smallint(6) DEFAULT NULL COMMENT 'Employee / biometric ID start position (fixed width)',
  `fw_id_width` smallint(6) DEFAULT NULL COMMENT 'Employee / biometric ID width (fixed width)',
  `fw_date_start` smallint(6) DEFAULT NULL COMMENT 'Date start position (fixed width)',
  `fw_date_width` smallint(6) DEFAULT NULL COMMENT 'Date width (fixed width)',
  `fw_time_start` smallint(6) DEFAULT NULL COMMENT 'Time start position (fixed width)',
  `fw_time_width` smallint(6) DEFAULT NULL COMMENT 'Time width (fixed width)',
  `fw_type_start` smallint(6) DEFAULT NULL COMMENT 'Log type start position (fixed width)',
  `fw_type_width` smallint(6) DEFAULT NULL COMMENT 'Log type width (fixed width)',
  `fw_dev_start` smallint(6) DEFAULT NULL COMMENT 'Device / bio ID start position (fixed width)',
  `fw_dev_width` smallint(6) DEFAULT NULL COMMENT 'Device / bio ID width (fixed width)',
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` smallint(6) NOT NULL DEFAULT 0 COMMENT 'Position in the Import configuration lists',
  `rp_id_label` varchar(40) DEFAULT NULL COMMENT 'Report layout: label of the employee ID (the ID is the next cell)',
  `rp_name_label` varchar(40) DEFAULT NULL COMMENT 'Report layout: label of the employee name',
  `rp_period_label` varchar(40) DEFAULT NULL COMMENT 'Report layout: label of the period (gives the year and month)',
  `rp_date_format` varchar(20) DEFAULT NULL COMMENT 'Report layout: date format of the period',
  `rp_day_row` tinyint(4) NOT NULL DEFAULT 1 COMMENT 'Report layout: rows below the ID row with the day numbers',
  `rp_time_row` tinyint(4) NOT NULL DEFAULT 2 COMMENT 'Report layout: rows below the ID row of the first times',
  `rp_sheet` tinyint(4) NOT NULL DEFAULT 1 COMMENT 'Report layout: worksheet number',
  `min_gap` smallint(6) NOT NULL DEFAULT 0 COMMENT 'Minimum minutes between two logs of the same employee-day (0 = keep all)',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `import_profiles` VALUES
(1,'ZKTeco attlog.dat (tab)','tab',0,1,2,NULL,NULL,'Y-m-d H:i:s',4,'0=in,1=out,2=break_out,3=break_in,4=in,5=out',NULL,NULL,'Columns: UserID, DateTime, DeviceNo, PunchState, VerifyMode, WorkCode','delimited',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,1,NULL,NULL,NULL,NULL,1,2,1,0),
(2,'Generic CSV (ID,Date,Time,Type)','comma',1,1,NULL,2,3,'Y-m-d H:i',4,'I=in,O=out,BO=break_out,BI=break_in,IN=in,OUT=out',NULL,NULL,'Header row; date YYYY-MM-DD; time HH:MM','delimited',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,2,NULL,NULL,NULL,NULL,1,2,1,0),
(3,'Space-delimited, no punch type','space',0,1,NULL,2,3,'Y-m-d H:i:s',NULL,NULL,NULL,NULL,'Punch types inferred from sequence: first=in, last=out','delimited',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,3,NULL,NULL,NULL,NULL,1,2,1,0),
(4,'JJM Logs','tab',0,1,NULL,NULL,NULL,'Y-m-d H:i:s',NULL,NULL,NULL,NULL,'Employee Attendance Record (.xls): User ID / Name / Department, the day numbers, then the times of each day','report',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,4,'User ID:','Name:','Attendance date:','Y-m-d',1,2,1,5),
(5,'NBJ Logs','tab',0,1,NULL,NULL,NULL,'Y-m-d H:i:s',NULL,NULL,NULL,NULL,'Attendance Record Report (.xls): Att. Time period, one row of day numbers at the top, ID / Name / Dept. blocks, the times of each day run together','report',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,5,'ID:','Name:','Att. Time','Y-m-d',0,1,1,5);
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(10) NOT NULL,
  `name` varchar(120) NOT NULL,
  `category` varchar(80) DEFAULT NULL,
  `sub_category` varchar(80) DEFAULT NULL,
  `ot_offset` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'OT/OL: overtime is credited as offset leave instead of paid',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `jobs` VALUES
(1,'1000','General Manager','Management',NULL,0,1),
(2,'1100','HR Officer','Administration',NULL,0,1),
(3,'1200','Accountant','Finance',NULL,0,1),
(4,'1300','Cashier','Finance',NULL,0,1),
(5,'1400','Sales Associate','Sales',NULL,0,1),
(6,'1500','Production Worker','Operations',NULL,0,1),
(7,'1600','Driver','Operations',NULL,0,1),
(8,'1700','Security Guard','Operations',NULL,0,1);
DROP TABLE IF EXISTS `late_brackets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `late_brackets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `from_minutes` int(11) NOT NULL,
  `to_minutes` int(11) NOT NULL,
  `deduct_minutes` int(11) NOT NULL,
  `remarks` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `late_brackets` VALUES
(1,1,15,15,'Rounded up to 15 minutes'),
(2,16,30,30,'Rounded up to 30 minutes'),
(3,31,60,60,'Rounded up to 1 hour'),
(4,61,240,240,'Half-day'),
(5,241,480,480,'Whole day');
DROP TABLE IF EXISTS `leave_accrual_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_accrual_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `leave_type_id` int(11) NOT NULL,
  `year` smallint(6) NOT NULL,
  `month` tinyint(4) NOT NULL DEFAULT 0 COMMENT '0 = annual credit',
  `days` decimal(6,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_accrual` (`employee_id`,`leave_type_id`,`year`,`month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `leave_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_applications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `app_no` varchar(20) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `leave_type_id` int(11) NOT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `days` decimal(6,2) NOT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `attachment` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','returned','disallowed','cancelled') NOT NULL DEFAULT 'pending',
  `current_step` tinyint(4) NOT NULL DEFAULT 1,
  `decision_reason` varchar(500) DEFAULT NULL,
  `filed_by` int(11) DEFAULT NULL,
  `filed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `decided_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `app_no` (`app_no`),
  KEY `idx_la_emp` (`employee_id`,`date_from`),
  KEY `fk_la_type` (`leave_type_id`),
  CONSTRAINT `fk_la_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_la_type` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `leave_approval_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_approval_steps` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `application_id` int(11) NOT NULL,
  `step_no` tinyint(4) NOT NULL,
  `approver_role` enum('supervisor','dept_head','hr') NOT NULL,
  `approver_user_id` int(11) DEFAULT NULL,
  `approver_name` varchar(150) DEFAULT NULL,
  `approver_email` varchar(150) DEFAULT NULL,
  `token` varchar(64) DEFAULT NULL,
  `token_expires` datetime DEFAULT NULL,
  `status` enum('waiting','pending','approved','returned','disallowed','skipped','cancelled') NOT NULL DEFAULT 'waiting',
  `remarks` varchar(500) DEFAULT NULL,
  `emailed_at` datetime DEFAULT NULL,
  `acted_at` datetime DEFAULT NULL,
  `acted_via` enum('email','system') DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `fk_las_app` (`application_id`),
  CONSTRAINT `fk_las_app` FOREIGN KEY (`application_id`) REFERENCES `leave_applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `leave_balances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_balances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `leave_type_id` int(11) NOT NULL,
  `year` smallint(6) NOT NULL,
  `carried_over` decimal(6,2) NOT NULL DEFAULT 0.00,
  `earned` decimal(6,2) NOT NULL DEFAULT 0.00,
  `used` decimal(6,2) NOT NULL DEFAULT 0.00,
  `converted` decimal(6,2) NOT NULL DEFAULT 0.00,
  `forfeited` decimal(6,2) NOT NULL DEFAULT 0.00,
  `adjustment` decimal(6,2) NOT NULL DEFAULT 0.00,
  `closed` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bal` (`employee_id`,`leave_type_id`,`year`),
  KEY `fk_bal_type` (`leave_type_id`),
  CONSTRAINT `fk_bal_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bal_type` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `leave_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `legal_basis` varchar(150) DEFAULT NULL,
  `accrual_method` enum('annual','monthly','service_based','per_event') NOT NULL DEFAULT 'annual',
  `days_per_year` decimal(6,2) NOT NULL DEFAULT 0.00 COMMENT 'Annual credit, or days per occurrence for per_event',
  `count_basis` enum('workdays','calendar') NOT NULL DEFAULT 'workdays',
  `service_step_years` tinyint(4) DEFAULT NULL COMMENT 'service_based: every N years of service ...',
  `service_step_days` decimal(5,2) DEFAULT NULL COMMENT '... add this many days',
  `max_days` decimal(6,2) DEFAULT NULL COMMENT 'service_based cap',
  `max_carry_over` decimal(6,2) NOT NULL DEFAULT 0.00,
  `convertible` tinyint(1) NOT NULL DEFAULT 0,
  `max_convertible_days` decimal(6,2) DEFAULT NULL,
  `is_paid` tinyint(1) NOT NULL DEFAULT 1,
  `gender` enum('any','female','male') NOT NULL DEFAULT 'any',
  `civil_status_req` varchar(20) DEFAULT NULL COMMENT 'e.g. married for paternity',
  `requires_solo_parent` tinyint(1) NOT NULL DEFAULT 0,
  `solo_parent_extra_days` decimal(6,2) NOT NULL DEFAULT 0.00,
  `max_events` smallint(6) DEFAULT NULL COMMENT 'Lifetime occurrences (e.g. paternity: 4)',
  `min_service_months` smallint(6) NOT NULL DEFAULT 0,
  `approval_chain` varchar(60) NOT NULL DEFAULT 'supervisor,dept_head,hr',
  `is_statutory` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `description` varchar(500) DEFAULT NULL,
  `summary_bucket` enum('vl','sl','el','ol','pm','none') NOT NULL DEFAULT 'ol' COMMENT 'Column of the attendance transaction',
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `leave_types` VALUES
(1,'SIL','Service Incentive Leave','Labor Code Art. 95','annual',5.00,'workdays',NULL,NULL,NULL,0.00,1,5.00,1,'any',NULL,0,0.00,NULL,12,'supervisor,hr',1,1,'Statutory minimum of 5 days/year after at least one year of service. Unused SIL is commutable to cash.','vl'),
(2,'VL','Vacation Leave','Company policy','service_based',10.00,'workdays',3,1.00,15.00,5.00,1,10.00,1,'any',NULL,0,0.00,NULL,6,'supervisor,dept_head,hr',0,1,'Company-granted; 10 days + 1 day every 3 years of service, max 15. Carry-over up to 5 days.','vl'),
(3,'SL','Sick Leave','Company policy','monthly',10.00,'workdays',NULL,NULL,NULL,5.00,0,NULL,1,'any',NULL,0,0.00,NULL,0,'supervisor,hr',0,1,'Company-granted; accrues 10/12 day per month.','sl'),
(4,'ML','Maternity Leave','RA 11210 (Expanded Maternity Leave Law)','per_event',105.00,'calendar',NULL,NULL,NULL,0.00,0,NULL,1,'female',NULL,0,15.00,NULL,0,'supervisor,hr',1,1,'105 days for live childbirth (+15 days for solo parents); 60 days for miscarriage or emergency termination of pregnancy.','pm'),
(5,'ML-MC','Maternity Leave (Miscarriage/ETP)','RA 11210','per_event',60.00,'calendar',NULL,NULL,NULL,0.00,0,NULL,1,'female',NULL,0,0.00,NULL,0,'supervisor,hr',1,1,'60 days for miscarriage or emergency termination of pregnancy.','pm'),
(6,'PL','Paternity Leave','RA 8187 (Paternity Leave Act)','per_event',7.00,'workdays',NULL,NULL,NULL,0.00,0,NULL,1,'male','married',0,0.00,4,0,'supervisor,hr',1,1,'7 days for married male employees, first four deliveries of the legitimate spouse.','pm'),
(7,'SPL','Solo Parent Leave','RA 8972 as amended by RA 11861','annual',7.00,'workdays',NULL,NULL,NULL,0.00,0,NULL,1,'any',NULL,1,0.00,NULL,0,'supervisor,hr',1,1,'7 working days per year for qualified solo parents with a Solo Parent ID.','ol'),
(8,'SLW','Special Leave for Women','RA 9710 (Magna Carta of Women)','per_event',60.00,'calendar',NULL,NULL,NULL,0.00,0,NULL,1,'female',NULL,0,0.00,NULL,6,'supervisor,dept_head,hr',1,1,'Up to two (2) months for surgery caused by gynecological disorders.','ol'),
(9,'VAWC','VAWC Leave','RA 9262 (Anti-VAWC Act)','annual',10.00,'workdays',NULL,NULL,NULL,0.00,0,NULL,1,'female',NULL,0,0.00,NULL,0,'hr',1,1,'Up to 10 days for victims of violence against women and their children.','ol'),
(10,'BL','Bereavement Leave','Company policy','per_event',3.00,'workdays',NULL,NULL,NULL,0.00,0,NULL,1,'any',NULL,0,0.00,NULL,0,'supervisor,hr',0,1,'Death of an immediate family member.','ol'),
(11,'EL','Emergency Leave','Company policy','annual',3.00,'workdays',NULL,NULL,NULL,0.00,0,NULL,1,'any',NULL,0,0.00,NULL,0,'supervisor,hr',0,1,'Company-specific emergency leave.','el'),
(12,'LWOP','Leave Without Pay','Company policy','per_event',30.00,'workdays',NULL,NULL,NULL,0.00,0,NULL,0,'any',NULL,0,0.00,NULL,0,'supervisor,dept_head,hr',0,1,'Unpaid leave; not counted as unauthorized absence.','none'),
(13,'OFL','Offset Leave (OT/OL)','Company policy','per_event',0.00,'workdays',NULL,NULL,NULL,0.00,0,NULL,1,'any',NULL,0,0.00,NULL,0,'supervisor,hr',0,1,'Credited from approved overtime of employees whose job is marked OT/OL; used as other/offset leave (OL).','ol');
DROP TABLE IF EXISTS `ledger_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ledger_entries` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `ledger_id` int(11) NOT NULL,
  `entry_date` date NOT NULL,
  `payroll_run_id` int(11) DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `increase` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Charge / accrual',
  `decrease` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Payment / disbursement',
  `balance` decimal(12,2) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `edited_at` datetime DEFAULT NULL,
  `edited_by` int(11) DEFAULT NULL,
  `edit_note` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_le_led` (`ledger_id`,`entry_date`),
  CONSTRAINT `fk_le_led` FOREIGN KEY (`ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `ledger_reconciliations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ledger_reconciliations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ledger_id` int(11) NOT NULL,
  `statement_date` date NOT NULL,
  `agency_balance` decimal(12,2) NOT NULL,
  `system_balance` decimal(12,2) NOT NULL,
  `variance` decimal(12,2) NOT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_lr_led` (`ledger_id`),
  CONSTRAINT `fk_lr_led` FOREIGN KEY (`ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `ledgers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ledgers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ledger_no` varchar(20) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `ledger_type` enum('gov_loan','company_loan','cash_advance','payable','accrued_benefit') NOT NULL,
  `sub_type` varchar(80) NOT NULL,
  `reference_no` varchar(40) DEFAULT NULL,
  `principal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `interest_rate_pct` decimal(6,2) NOT NULL DEFAULT 0.00 COMMENT 'Add-on interest for the whole term',
  `term_months` smallint(6) DEFAULT NULL,
  `total_payable` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amortization` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Per payroll deduction',
  `start_date` date DEFAULT NULL,
  `balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','paid','cancelled') NOT NULL DEFAULT 'active',
  `full_payment_date` date DEFAULT NULL,
  `deduction_type_id` int(11) DEFAULT NULL,
  `agency_balance` decimal(12,2) DEFAULT NULL,
  `reconciled_at` date DEFAULT NULL,
  `remarks` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ledger_no` (`ledger_no`),
  KEY `idx_led_emp` (`employee_id`,`ledger_type`),
  CONSTRAINT `fk_led_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `loan_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_no` varchar(20) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `deduction_type_id` int(11) NOT NULL,
  `date_applied` date NOT NULL,
  `amount_applied` decimal(12,2) NOT NULL DEFAULT 0.00,
  `terms` int(11) NOT NULL DEFAULT 1 COMMENT 'Term in paydays',
  `int_rate` decimal(7,3) NOT NULL DEFAULT 0.000 COMMENT 'Monthly interest rate (%), from the Employee Transactions Table',
  `interest_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `trans_term` varchar(12) NOT NULL DEFAULT 'every_pay' COMMENT 'Mode of payment: every_pay, first_pay ... fourth_pay',
  `pp_principal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `pp_interest` decimal(12,2) NOT NULL DEFAULT 0.00,
  `purpose` varchar(255) DEFAULT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'draft' COMMENT 'draft, submitted, approved, rejected, cancelled',
  `submitted_at` datetime DEFAULT NULL,
  `amount_approved` decimal(12,2) DEFAULT NULL,
  `terms_approved` int(11) DEFAULT NULL,
  `int_rate_approved` decimal(7,3) DEFAULT NULL,
  `interest_approved` decimal(12,2) DEFAULT NULL,
  `trans_term_approved` varchar(12) DEFAULT NULL,
  `start_date` date DEFAULT NULL COMMENT 'Start of payment, set by the approver',
  `decided_by` int(11) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `decision_remarks` varchar(255) DEFAULT NULL,
  `term_trans_id` int(11) DEFAULT NULL COMMENT 'The transaction with terms created on approval',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_loan_req_no` (`request_no`),
  KEY `idx_loan_req_emp` (`employee_id`,`status`),
  KEY `idx_loan_req_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `locations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(10) NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `locations` VALUES
(1,'MKT','Makati',1),
(2,'QC','Quezon City',1),
(3,'CEB','Cebu',1);
DROP TABLE IF EXISTS `log_staging`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `log_staging` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) DEFAULT NULL,
  `line_no` int(11) NOT NULL DEFAULT 0,
  `log_time` datetime DEFAULT NULL,
  `bio_id` varchar(20) DEFAULT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `punch_type` enum('in','out','break_out','break_in','unknown') NOT NULL DEFAULT 'unknown',
  `co_code` varchar(10) DEFAULT NULL,
  `branch_code` varchar(10) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `device_id` varchar(40) DEFAULT NULL,
  `source` enum('file','log_table','device') NOT NULL DEFAULT 'file',
  `raw_text` varchar(255) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `status` enum('pending','posted','error','duplicate') NOT NULL DEFAULT 'pending',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ls_status` (`status`),
  KEY `idx_ls_batch` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `memo_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `memo_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `name` varchar(120) NOT NULL,
  `kind` varchar(20) NOT NULL DEFAULT 'memo',
  `category` varchar(60) NOT NULL DEFAULT 'General',
  `subject` varchar(200) DEFAULT NULL,
  `body` mediumtext DEFAULT NULL,
  `is_sample` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_memo_tpl_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `memos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `memos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `memo_no` varchar(30) NOT NULL,
  `kind` varchar(20) NOT NULL DEFAULT 'memo',
  `template_id` int(11) DEFAULT NULL,
  `subject` varchar(200) NOT NULL,
  `to_line` varchar(255) DEFAULT NULL,
  `from_line` varchar(255) DEFAULT NULL,
  `cc_line` varchar(255) DEFAULT NULL,
  `memo_date` date NOT NULL,
  `body` mediumtext DEFAULT NULL,
  `signatory_name` varchar(120) DEFAULT NULL,
  `signatory_title` varchar(120) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `audience` varchar(20) NOT NULL DEFAULT 'internal',
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `issued_at` datetime DEFAULT NULL,
  `issued_by` int(11) DEFAULT NULL,
  `copied_from` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_memo_no` (`memo_no`),
  KEY `idx_memo_status` (`status`,`memo_date`),
  KEY `idx_memo_date` (`memo_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `merit_matrix`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `merit_matrix` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `min_score` decimal(4,2) NOT NULL,
  `rating_label` varchar(40) NOT NULL,
  `increase_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `step_increase` tinyint(4) NOT NULL DEFAULT 0,
  `promotion_eligible` tinyint(1) NOT NULL DEFAULT 0,
  `disciplinary_flag` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `merit_matrix` VALUES
(1,4.50,'Outstanding',8.00,2,1,0),
(2,3.50,'Very Satisfactory',5.00,1,1,0),
(3,2.50,'Satisfactory',3.00,1,0,0),
(4,1.50,'Unsatisfactory',0.00,0,0,1),
(5,0.00,'Needs Improvement',0.00,0,0,1);
DROP TABLE IF EXISTS `nte_todos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `nte_todos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `period_month` date NOT NULL,
  `reason` varchar(40) NOT NULL,
  `late_count` int(11) NOT NULL DEFAULT 0,
  `late_minutes` int(11) NOT NULL DEFAULT 0,
  `supervisor_id` int(11) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'open',
  `case_id` int(11) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `handled_by` int(11) DEFAULT NULL,
  `handled_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nte_todo` (`employee_id`,`period_month`,`reason`),
  KEY `idx_nte_status` (`status`),
  KEY `idx_nte_sup` (`supervisor_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `org_units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `org_units` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `parent_id` int(11) DEFAULT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(120) NOT NULL,
  `unit_type` varchar(40) NOT NULL DEFAULT 'Department' COMMENT 'Division, Department, Section, Unit, ...',
  `head_employee_id` int(11) DEFAULT NULL,
  `default_pay_group_id` int(11) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_ou_parent` (`parent_id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `org_units` VALUES
(1,NULL,'CORP','Sample Holdings Corporation','Company',NULL,1,0,1),
(2,1,'DIV-OPS','Operations Division','Division',NULL,1,1,1),
(3,1,'DIV-ADM','Administration Division','Division',NULL,1,2,1),
(4,2,'DEP-SAL','Sales Department','Department',NULL,1,1,1),
(5,2,'DEP-PRD','Production Department','Department',NULL,1,2,1),
(6,3,'DEP-HR','Human Resources Department','Department',NULL,1,1,1),
(7,3,'DEP-FIN','Finance Department','Department',NULL,2,2,1),
(8,4,'SEC-FLD','Field Sales Section','Section',NULL,3,1,1),
(9,5,'SEC-ASM','Assembly Section','Section',NULL,1,1,1),
(10,7,'SEC-PAY','Payroll Section','Section',NULL,1,1,1);
DROP TABLE IF EXISTS `overtime_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `overtime_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `ot_date` date NOT NULL,
  `hours` decimal(5,2) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ot_emp` (`employee_id`,`ot_date`),
  CONSTRAINT `fk_ot_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `pagibig_table`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pagibig_table` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `range_from` decimal(12,2) NOT NULL,
  `range_to` decimal(12,2) DEFAULT NULL,
  `ee_rate_pct` decimal(5,2) NOT NULL,
  `er_rate_pct` decimal(5,2) NOT NULL,
  `max_comp` decimal(12,2) NOT NULL COMMENT 'Maximum fund salary',
  `effective_from` date NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `pagibig_table` VALUES
(1,0.00,1500.00,1.00,2.00,10000.00,'2024-02-01'),
(2,1500.01,NULL,2.00,2.00,10000.00,'2024-02-01');
DROP TABLE IF EXISTS `pay_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pay_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `frequency` enum('daily','weekly','semi_monthly','monthly') NOT NULL DEFAULT 'semi_monthly',
  `contrib_mode` enum('split','first','last') NOT NULL DEFAULT 'split' COMMENT 'How monthly statutory contributions are spread across cutoffs',
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sss_mode` enum('every','first','second') NOT NULL DEFAULT 'every',
  `hdmf_mode` enum('every','first','second') NOT NULL DEFAULT 'every',
  `phic_mode` enum('every','first','second') NOT NULL DEFAULT 'every',
  `tax_mode` enum('every','first','second') NOT NULL DEFAULT 'every',
  `tax_type` enum('daily','weekly','semi_monthly','monthly') DEFAULT NULL COMMENT 'Default tax table (NULL = pay frequency)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `pay_groups` VALUES
(1,'SM-RF','Rank-and-File (Semi-Monthly)','semi_monthly','split','1st-15th and 16th-end of month',1,'every','every','every','every',NULL),
(2,'M-MGR','Managerial (Monthly)','monthly','split','Paid at month-end',1,'every','every','every','every',NULL),
(3,'W-FLD','Field / Project Personnel (Weekly)','weekly','last','Monday to Sunday',1,'second','second','second','every',NULL),
(4,'D-PRJ','Daily-Paid Project Workers','daily','last','Paid daily',1,'second','second','second','every',NULL);
DROP TABLE IF EXISTS `pay_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pay_rates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `label` varchar(80) NOT NULL,
  `group_name` varchar(40) NOT NULL,
  `day_pct` decimal(7,3) NOT NULL DEFAULT 100.000 COMMENT '% of hourly rate per hour (Hourly Rate x Hr x Rate)',
  `night_pct` decimal(7,3) NOT NULL DEFAULT 0.000 COMMENT 'Night premium % of hourly rate per night hour in this category',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `pay_rates` VALUES
(1,'reg','Regular day (regular hours)','Regular Day Rates',100.000,10.000,1),
(2,'reg_ot','Regular OT rate','Regular Day Rates',125.000,12.500,2),
(3,'rd','Day-off rate','Regular Day Rates',130.000,13.000,3),
(4,'rd_ot','Day-off OT rate','Regular Day Rates',169.000,16.900,4),
(5,'lh','Legal holiday rate','Legal Holiday Rates',200.000,20.000,5),
(6,'lh_ot','Legal holiday overtime rate','Legal Holiday Rates',260.000,26.000,6),
(7,'lh_rd','Legal holiday falling on day-off rate','Legal Holiday Rates',260.000,26.000,7),
(8,'lh_rd_ot','Legal holiday on day-off OT rate','Legal Holiday Rates',338.000,33.800,8),
(9,'sh','Special holiday rate','Special Holiday Rates',130.000,13.000,9),
(10,'sh_ot','Special holiday overtime rate','Special Holiday Rates',169.000,16.900,10),
(11,'sh_rd','Special holiday falling on day-off rate','Special Holiday Rates',150.000,15.000,11),
(12,'sh_rd_ot','Special holiday on day-off OT rate','Special Holiday Rates',195.000,19.500,12);
DROP TABLE IF EXISTS `payroll_adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_adjustments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `run_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `adj_type` enum('earning','deduction') NOT NULL,
  `description` varchar(255) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `taxable` tinyint(1) NOT NULL DEFAULT 1,
  `reason` varchar(500) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_adj_run` (`run_id`),
  CONSTRAINT `fk_adj_run` FOREIGN KEY (`run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `payroll_branch_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_branch_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL,
  `run_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `days_worked` decimal(6,2) NOT NULL DEFAULT 0.00,
  `reg_hours` decimal(8,2) NOT NULL DEFAULT 0.00,
  `ot_hours` decimal(8,2) NOT NULL DEFAULT 0.00,
  `basic_alloc` decimal(12,2) NOT NULL DEFAULT 0.00,
  `premium_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `ot_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `nd_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `other_earnings` decimal(12,2) NOT NULL DEFAULT 0.00,
  `late_ut_deduction` decimal(12,2) NOT NULL DEFAULT 0.00,
  `gross` decimal(12,2) NOT NULL DEFAULT 0.00,
  `er_share_alloc` decimal(12,2) NOT NULL DEFAULT 0.00,
  `is_deduction_source` tinyint(1) NOT NULL DEFAULT 0,
  `deductions` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_pbi_run` (`run_id`,`branch_id`),
  KEY `fk_pbi_item` (`item_id`),
  CONSTRAINT `fk_pbi_item` FOREIGN KEY (`item_id`) REFERENCES `payroll_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `payroll_exceptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_exceptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `run_id` int(11) NOT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `exception_type` varchar(40) NOT NULL,
  `message` varchar(500) NOT NULL,
  `blocking` tinyint(1) NOT NULL DEFAULT 1,
  `status` enum('open','resolved') NOT NULL DEFAULT 'open',
  `resolution` varchar(500) DEFAULT NULL,
  `resolved_by` int(11) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_pex_run` (`run_id`),
  CONSTRAINT `fk_pex_run` FOREIGN KEY (`run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `payroll_item_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_item_lines` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `line_type` enum('earning','deduction','employer','info') NOT NULL,
  `code` varchar(30) NOT NULL,
  `description` varchar(255) NOT NULL,
  `quantity` decimal(10,2) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `taxable` tinyint(1) NOT NULL DEFAULT 1,
  `ref_type` varchar(30) DEFAULT NULL,
  `ref_id` int(11) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `reg_col` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_line_item` (`item_id`),
  CONSTRAINT `fk_line_item` FOREIGN KEY (`item_id`) REFERENCES `payroll_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `payroll_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `run_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `default_branch_id` int(11) NOT NULL,
  `org_unit_id` int(11) DEFAULT NULL,
  `rate_type` enum('monthly','daily') NOT NULL,
  `monthly_rate` decimal(12,2) NOT NULL DEFAULT 0.00,
  `daily_rate` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `hourly_rate` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `days_worked` decimal(6,2) NOT NULL DEFAULT 0.00,
  `absent_days` decimal(6,2) NOT NULL DEFAULT 0.00,
  `leave_paid_days` decimal(6,2) NOT NULL DEFAULT 0.00,
  `leave_unpaid_days` decimal(6,2) NOT NULL DEFAULT 0.00,
  `late_minutes` int(11) NOT NULL DEFAULT 0,
  `undertime_minutes` int(11) NOT NULL DEFAULT 0,
  `ot_hours` decimal(8,2) NOT NULL DEFAULT 0.00,
  `basic_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `absence_deduction` decimal(12,2) NOT NULL DEFAULT 0.00,
  `late_deduction` decimal(12,2) NOT NULL DEFAULT 0.00,
  `undertime_deduction` decimal(12,2) NOT NULL DEFAULT 0.00,
  `holiday_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `premium_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `ot_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `nd_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `benefits_nontax` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'De minimis within ceiling / non-taxable',
  `benefits_other` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '13th month & other benefits within 90k',
  `benefits_taxable` decimal(12,2) NOT NULL DEFAULT 0.00,
  `other_earnings` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Retro, adjustments (taxable)',
  `gross_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `contrib_basis` decimal(12,2) NOT NULL DEFAULT 0.00,
  `sss_ee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sss_er` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sss_ec` decimal(10,2) NOT NULL DEFAULT 0.00,
  `phic_ee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `phic_er` decimal(10,2) NOT NULL DEFAULT 0.00,
  `hdmf_ee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `hdmf_ee_voluntary` decimal(10,2) NOT NULL DEFAULT 0.00,
  `hdmf_er` decimal(10,2) NOT NULL DEFAULT 0.00,
  `taxable_income` decimal(12,2) NOT NULL DEFAULT 0.00,
  `withholding_tax` decimal(12,2) NOT NULL DEFAULT 0.00,
  `other_deductions` decimal(12,2) NOT NULL DEFAULT 0.00,
  `deferred_deductions` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_deductions` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `is_mwe` tinyint(1) NOT NULL DEFAULT 0,
  `basic_earned` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Basic net of absences/lates, base for 13th month',
  `remarks` varchar(500) DEFAULT NULL,
  `summary_id` int(11) DEFAULT NULL,
  `print_count` int(11) NOT NULL DEFAULT 0,
  `last_printed_at` datetime DEFAULT NULL,
  `deduction_branch_id` int(11) DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `closing_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_item` (`run_id`,`employee_id`),
  KEY `idx_item_emp` (`employee_id`),
  CONSTRAINT `fk_item_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  CONSTRAINT `fk_item_run` FOREIGN KEY (`run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `payroll_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_runs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `run_no` varchar(30) NOT NULL,
  `pay_group_id` int(11) NOT NULL,
  `run_type` enum('regular','thirteenth','final_pay','special') NOT NULL DEFAULT 'regular',
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `pay_date` date NOT NULL,
  `cutoff_id` int(11) DEFAULT NULL,
  `status` enum('draft','computed','finalized','cancelled') NOT NULL DEFAULT 'draft',
  `notes` varchar(500) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `computed_at` datetime DEFAULT NULL,
  `finalized_at` datetime DEFAULT NULL,
  `finalized_by` int(11) DEFAULT NULL,
  `calc_branch_id` int(11) DEFAULT NULL,
  `calc_group_id` int(11) DEFAULT NULL,
  `calc_type_id` int(11) DEFAULT NULL,
  `calc_tax_table` varchar(20) DEFAULT NULL,
  `calc_annualize` tinyint(1) NOT NULL DEFAULT 0,
  `calc_regular` tinyint(1) NOT NULL DEFAULT 1,
  `calc_13th` tinyint(1) NOT NULL DEFAULT 0,
  `calc_selection` tinyint(1) NOT NULL DEFAULT 0,
  `calc_emp_from` varchar(30) DEFAULT NULL,
  `calc_emp_to` varchar(30) DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `posted_by` int(11) DEFAULT NULL,
  `calc_emp_ids` text DEFAULT NULL,
  `calc_branch_ids` varchar(255) DEFAULT NULL,
  `calc_unit_ids` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `run_no` (`run_no`),
  KEY `idx_run_pg` (`pay_group_id`,`period_start`),
  CONSTRAINT `fk_run_pg` FOREIGN KEY (`pay_group_id`) REFERENCES `pay_groups` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `pending_earnings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pending_earnings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `source` enum('retro','leave_conversion','bonus','other') NOT NULL DEFAULT 'other',
  `source_id` int(11) DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `tax_treatment` enum('taxable','other_benefit','de_minimis','non_taxable') NOT NULL DEFAULT 'taxable',
  `nontax_portion` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'For de minimis split',
  `status` enum('pending','included','cancelled') NOT NULL DEFAULT 'pending',
  `payroll_run_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_pe_emp` (`employee_id`),
  CONSTRAINT `fk_pe_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `period_closings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `period_closings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `closing_no` varchar(20) NOT NULL,
  `pay_group_id` int(11) NOT NULL,
  `cutoff_id` int(11) DEFAULT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `employee_group_id` int(11) DEFAULT NULL,
  `employee_type_id` int(11) DEFAULT NULL,
  `runs` int(11) NOT NULL DEFAULT 0,
  `employees` int(11) NOT NULL DEFAULT 0,
  `gross` decimal(14,2) NOT NULL DEFAULT 0.00,
  `deductions` decimal(14,2) NOT NULL DEFAULT 0.00,
  `net` decimal(14,2) NOT NULL DEFAULT 0.00,
  `sss_ee` decimal(14,2) NOT NULL DEFAULT 0.00,
  `sss_er` decimal(14,2) NOT NULL DEFAULT 0.00,
  `phic_ee` decimal(14,2) NOT NULL DEFAULT 0.00,
  `phic_er` decimal(14,2) NOT NULL DEFAULT 0.00,
  `hdmf_ee` decimal(14,2) NOT NULL DEFAULT 0.00,
  `hdmf_er` decimal(14,2) NOT NULL DEFAULT 0.00,
  `tax` decimal(14,2) NOT NULL DEFAULT 0.00,
  `ledger_entries` int(11) NOT NULL DEFAULT 0,
  `period_locked` tinyint(1) NOT NULL DEFAULT 0,
  `log` text DEFAULT NULL,
  `backup_confirmed` tinyint(1) NOT NULL DEFAULT 0,
  `closed_by` int(11) DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_closing_no` (`closing_no`),
  KEY `idx_closing_pg` (`pay_group_id`,`period_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `perm_key` varchar(80) NOT NULL,
  `module` varchar(20) NOT NULL,
  `page` varchar(40) NOT NULL,
  `action` varchar(20) NOT NULL,
  `label` varchar(120) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`perm_key`),
  KEY `idx_perm_page` (`page`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `permissions` VALUES
('ams_policies.configure','ams','ams_policies','configure','Configure — AMS Policies & Configuration',95),
('ams_policies.view','ams','ams_policies','view','View — AMS Policies & Configuration',94),
('approvals.approve','approvals','approvals','approve','Approve — Pending Approvals',21),
('approvals.reject','approvals','approvals','reject','Reject — Pending Approvals',22),
('approvals.view','approvals','approvals','view','View — Pending Approvals',20),
('att_batch.edit','ams','att_batch','edit','Edit — Attendance Batch Transaction Entry',160),
('att_batch.import','ams','att_batch','import','Import — Attendance Batch Transaction Entry',161),
('att_batch.view','ams','att_batch','view','View — Attendance Batch Transaction Entry',159),
('att_close.approve','ams','att_close','approve','Approve — Attendance Transaction Closing',168),
('att_close.view','ams','att_close','view','View — Attendance Transaction Closing',167),
('att_entry.edit','ams','att_entry','edit','Edit — Attendance Entry (logs)',140),
('att_entry.generate','ams','att_entry','generate','Generate — Attendance Entry (logs)',141),
('att_entry.view','ams','att_entry','view','View — Attendance Entry (logs)',139),
('att_exceptions.approve','ams','att_exceptions','approve','Approve — Attendance Exceptions',136),
('att_exceptions.view','ams','att_exceptions','view','View — Attendance Exceptions',135),
('att_generate.approve','ams','att_generate','approve','Approve — Auto-Generate Transactions',145),
('att_generate.generate','ams','att_generate','generate','Generate — Auto-Generate Transactions',146),
('att_generate.view','ams','att_generate','view','View — Auto-Generate Transactions',144),
('att_leave_entry.create','ams','att_leave_entry','create','Create — Employee Leave Entry',110),
('att_leave_entry.delete','ams','att_leave_entry','delete','Delete — Employee Leave Entry',111),
('att_leave_entry.view','ams','att_leave_entry','view','View — Employee Leave Entry',109),
('att_logs.create','ams','att_logs','create','Create — Raw Punch Logs',133),
('att_logs.edit','ams','att_logs','edit','Edit — Raw Punch Logs',134),
('att_logs.view','ams','att_logs','view','View — Raw Punch Logs',132),
('att_process.generate','ams','att_process','generate','Generate — Process Attendance',138),
('att_process.view','ams','att_process','view','View — Process Attendance',137),
('att_summary.approve','ams','att_summary','approve','Approve — Attendance Summary Entry',150),
('att_summary.delete','ams','att_summary','delete','Delete — Attendance Summary Entry',149),
('att_summary.edit','ams','att_summary','edit','Edit — Attendance Summary Entry',148),
('att_summary.generate','ams','att_summary','generate','Generate — Attendance Summary Entry',152),
('att_summary.import','ams','att_summary','import','Import — Attendance Summary Entry',151),
('att_summary.view','ams','att_summary','view','View — Attendance Summary Entry',147),
('att_summary2.approve','ams','att_summary2','approve','Approve — Attendance Summary Entry II',156),
('att_summary2.delete','ams','att_summary2','delete','Delete — Attendance Summary Entry II',155),
('att_summary2.edit','ams','att_summary2','edit','Edit — Attendance Summary Entry II',154),
('att_summary2.generate','ams','att_summary2','generate','Generate — Attendance Summary Entry II',158),
('att_summary2.import','ams','att_summary2','import','Import — Attendance Summary Entry II',157),
('att_summary2.view','ams','att_summary2','view','View — Attendance Summary Entry II',153),
('audit.export','reports','audit','export','Export — Audit Trail',303),
('audit.view','reports','audit','view','View — Audit Trail',302),
('bank_file.export','process','bank_file','export','Export — Bank File / Payroll ATM',243),
('bank_file.view','process','bank_file','view','View — Bank File / Payroll ATM',242),
('bank_setup.edit','setup','bank_setup','edit','Edit — Employee\'s Bank Setup',379),
('bank_setup.view','setup','bank_setup','view','View — Employee\'s Bank Setup',378),
('batch_shift.create','ams','batch_shift','create','Create — Batch Shift Define',117),
('batch_shift.view','ams','batch_shift','view','View — Batch Shift Define',116),
('benefit_types.create','setup','benefit_types','create','Create — Benefits Table',360),
('benefit_types.delete','setup','benefit_types','delete','Delete — Benefits Table',362),
('benefit_types.edit','setup','benefit_types','edit','Edit — Benefits Table',361),
('benefit_types.import','setup','benefit_types','import','Import — Benefits Table',363),
('benefit_types.view','setup','benefit_types','view','View — Benefits Table',359),
('bio_devices.create','ams','bio_devices','create','Create — Biometric Device Setup',97),
('bio_devices.delete','ams','bio_devices','delete','Delete — Biometric Device Setup',99),
('bio_devices.edit','ams','bio_devices','edit','Edit — Biometric Device Setup',98),
('bio_devices.import','ams','bio_devices','import','Import — Biometric Device Setup',100),
('bio_devices.view','ams','bio_devices','view','View — Biometric Device Setup',96),
('branches.create','setup','branches','create','Create — Company Table',306),
('branches.delete','setup','branches','delete','Delete — Company Table',308),
('branches.edit','setup','branches','edit','Edit — Company Table',307),
('branches.import','setup','branches','import','Import — Company Table',309),
('branches.view','setup','branches','view','View — Company Table',305),
('budgets.edit','system','budgets','edit','Edit — Department Budgets',411),
('budgets.view','system','budgets','view','View — Department Budgets',410),
('cases.approve','pis','cases','approve','Approve — Disciplinary Cases',72),
('cases.create','pis','cases','create','Create — Disciplinary Cases',70),
('cases.edit','pis','cases','edit','Edit — Disciplinary Cases',71),
('cases.print','pis','cases','print','Print — Disciplinary Cases',73),
('cases.view','pis','cases','view','View — Disciplinary Cases',69),
('classifications.create','setup','classifications','create','Create — Classification Table',339),
('classifications.delete','setup','classifications','delete','Delete — Classification Table',341),
('classifications.edit','setup','classifications','edit','Edit — Classification Table',340),
('classifications.view','setup','classifications','view','View — Classification Table',338),
('class_groups.create','setup','class_groups','create','Create — Class Group Table',331),
('class_groups.delete','setup','class_groups','delete','Delete — Class Group Table',333),
('class_groups.edit','setup','class_groups','edit','Edit — Class Group Table',332),
('class_groups.view','setup','class_groups','view','View — Class Group Table',330),
('company_events.create','pis','company_events','create','Create — Company Events',91),
('company_events.delete','pis','company_events','delete','Delete — Company Events',93),
('company_events.edit','pis','company_events','edit','Edit — Company Events',92),
('company_events.view','pis','company_events','view','View — Company Events',90),
('competencies.create','pis','competencies','create','Create — Competencies & Merit Matrix',59),
('competencies.delete','pis','competencies','delete','Delete — Competencies & Merit Matrix',61),
('competencies.edit','pis','competencies','edit','Edit — Competencies & Merit Matrix',60),
('competencies.import','pis','competencies','import','Import — Competencies & Merit Matrix',62),
('competencies.view','pis','competencies','view','View — Competencies & Merit Matrix',58),
('conduct.create','pis','conduct','create','Create — Code of Conduct & Penalties',75),
('conduct.delete','pis','conduct','delete','Delete — Code of Conduct & Penalties',77),
('conduct.edit','pis','conduct','edit','Edit — Code of Conduct & Penalties',76),
('conduct.import','pis','conduct','import','Import — Code of Conduct & Penalties',78),
('conduct.view','pis','conduct','view','View — Code of Conduct & Penalties',74),
('cutoffs.approve','ams','cutoffs','approve','Approve — Periods & Cutoffs',107),
('cutoffs.create','ams','cutoffs','create','Create — Periods & Cutoffs',106),
('cutoffs.generate','ams','cutoffs','generate','Generate — Periods & Cutoffs',108),
('cutoffs.view','ams','cutoffs','view','View — Periods & Cutoffs',105),
('deduction_types.create','setup','deduction_types','create','Create — Deduction Type Table',422),
('deduction_types.delete','setup','deduction_types','delete','Delete — Deduction Type Table',424),
('deduction_types.edit','setup','deduction_types','edit','Edit — Deduction Type Table',423),
('deduction_types.import','setup','deduction_types','import','Import — Deduction Type Table',425),
('deduction_types.view','setup','deduction_types','view','View — Deduction Type Table',421),
('departments.import','setup','departments','import','Import — Department Table',311),
('departments.view','setup','departments','view','View — Department Table',310),
('dtr.approve','ams','dtr','approve','Approve — Daily Time Records',164),
('dtr.edit','ams','dtr','edit','Edit — Daily Time Records',163),
('dtr.generate','ams','dtr','generate','Generate — Daily Time Records',166),
('dtr.print','ams','dtr','print','Print — Daily Time Records',165),
('dtr.view','ams','dtr','view','View — Daily Time Records',162),
('earning_types.create','setup','earning_types','create','Create — Transaction Table',365),
('earning_types.delete','setup','earning_types','delete','Delete — Transaction Table',367),
('earning_types.edit','setup','earning_types','edit','Edit — Transaction Table',366),
('earning_types.import','setup','earning_types','import','Import — Transaction Table',368),
('earning_types.view','setup','earning_types','view','View — Transaction Table',364),
('employees.create','pis','employees','create','Create — Employee Masterfile',30),
('employees.edit','pis','employees','edit','Edit — Employee Masterfile',31),
('employees.export','pis','employees','export','Export — Employee Masterfile',33),
('employees.import','pis','employees','import','Import — Employee Masterfile',32),
('employees.print','pis','employees','print','Print — Employee Masterfile',34),
('employees.view','pis','employees','view','View — Employee Masterfile',29),
('employee_batch.edit','pis','employee_batch','edit','Edit — Masterfile Batch Edit',36),
('employee_batch.view','pis','employee_batch','view','View — Masterfile Batch Edit',35),
('employee_salary.edit','pis','employee_salary','edit','Edit — Basic Salary (employee basic rate)',415),
('employee_salary.view','pis','employee_salary','view','View — Basic Salary (employee basic rate)',414),
('emp_benefits.create','pay','emp_benefits','create','Create — Employee Benefits',192),
('emp_benefits.delete','pay','emp_benefits','delete','Delete — Employee Benefits',194),
('emp_benefits.edit','pay','emp_benefits','edit','Edit — Employee Benefits',193),
('emp_benefits.import','pay','emp_benefits','import','Import — Employee Benefits',195),
('emp_benefits.view','pay','emp_benefits','view','View — Employee Benefits',191),
('emp_deductions.approve','pay','emp_deductions','approve','Approve — Employee Term Loan/Payables: Employee Deductions',419),
('emp_deductions.create','pay','emp_deductions','create','Create — Employee Term Loan/Payables: Employee Deductions',417),
('emp_deductions.edit','pay','emp_deductions','edit','Edit — Employee Term Loan/Payables: Employee Deductions',418),
('emp_deductions.import','pay','emp_deductions','import','Import — Employee Term Loan/Payables: Employee Deductions',420),
('emp_deductions.view','pay','emp_deductions','view','View — Employee Term Loan/Payables: Employee Deductions',416),
('emp_groups.create','setup','emp_groups','create','Create — Employee Group Table',327),
('emp_groups.delete','setup','emp_groups','delete','Delete — Employee Group Table',329),
('emp_groups.edit','setup','emp_groups','edit','Edit — Employee Group Table',328),
('emp_groups.view','setup','emp_groups','view','View — Employee Group Table',326),
('emp_transactions.create','pay','emp_transactions','create','Create — Employee\'s Transactions',176),
('emp_transactions.delete','pay','emp_transactions','delete','Delete — Employee\'s Transactions',178),
('emp_transactions.edit','pay','emp_transactions','edit','Edit — Employee\'s Transactions',177),
('emp_transactions.import','pay','emp_transactions','import','Import — Employee\'s Transactions',179),
('emp_transactions.view','pay','emp_transactions','view','View — Employee\'s Transactions',175),
('emp_types.create','setup','emp_types','create','Create — Employee Type Table',323),
('emp_types.delete','setup','emp_types','delete','Delete — Employee Type Table',325),
('emp_types.edit','setup','emp_types','edit','Edit — Employee Type Table',324),
('emp_types.view','setup','emp_types','view','View — Employee Type Table',322),
('evaluations.approve','approvals','evaluations','approve','Approve — Team Evaluations',28),
('evaluations.edit','approvals','evaluations','edit','Edit — Team Evaluations',27),
('evaluations.view','approvals','evaluations','view','View — Team Evaluations',26),
('eval_cycles.create','pis','eval_cycles','create','Create — Evaluation Cycles',54),
('eval_cycles.delete','pis','eval_cycles','delete','Delete — Evaluation Cycles',56),
('eval_cycles.edit','pis','eval_cycles','edit','Edit — Evaluation Cycles',55),
('eval_cycles.generate','pis','eval_cycles','generate','Generate — Evaluation Cycles',57),
('eval_cycles.view','pis','eval_cycles','view','View — Evaluation Cycles',53),
('exemption_codes.create','setup','exemption_codes','create','Create — Exemption Code Table',343),
('exemption_codes.delete','setup','exemption_codes','delete','Delete — Exemption Code Table',345),
('exemption_codes.edit','setup','exemption_codes','edit','Edit — Exemption Code Table',344),
('exemption_codes.view','setup','exemption_codes','view','View — Exemption Code Table',342),
('final_pay.approve','pay','final_pay','approve','Approve — Final Pay',223),
('final_pay.generate','pay','final_pay','generate','Generate — Final Pay',225),
('final_pay.print','pay','final_pay','print','Print — Final Pay',224),
('final_pay.view','pay','final_pay','view','View — Final Pay',222),
('fixed_trans.create','pay','fixed_trans','create','Create — Fixed Transaction Setup',181),
('fixed_trans.delete','pay','fixed_trans','delete','Delete — Fixed Transaction Setup',183),
('fixed_trans.edit','pay','fixed_trans','edit','Edit — Fixed Transaction Setup',182),
('fixed_trans.import','pay','fixed_trans','import','Import — Fixed Transaction Setup',184),
('fixed_trans.view','pay','fixed_trans','view','View — Fixed Transaction Setup',180),
('fixed_trans_codes.create','setup','fixed_trans_codes','create','Create — Fixed Transaction Table',370),
('fixed_trans_codes.delete','setup','fixed_trans_codes','delete','Delete — Fixed Transaction Table',372),
('fixed_trans_codes.edit','setup','fixed_trans_codes','edit','Edit — Fixed Transaction Table',371),
('fixed_trans_codes.import','setup','fixed_trans_codes','import','Import — Fixed Transaction Table',373),
('fixed_trans_codes.view','setup','fixed_trans_codes','view','View — Fixed Transaction Table',369),
('gl_accounts.create','setup','gl_accounts','create','Create — Payroll Code Table (GL)',375),
('gl_accounts.delete','setup','gl_accounts','delete','Delete — Payroll Code Table (GL)',377),
('gl_accounts.edit','setup','gl_accounts','edit','Edit — Payroll Code Table (GL)',376),
('gl_accounts.view','setup','gl_accounts','view','View — Payroll Code Table (GL)',374),
('holidays.create','setup','holidays','create','Create — Annual Holidays',356),
('holidays.delete','setup','holidays','delete','Delete — Annual Holidays',358),
('holidays.edit','setup','holidays','edit','Edit — Annual Holidays',357),
('holidays.view','setup','holidays','view','View — Annual Holidays',355),
('import_config.create','ams','import_config','create','Create — Import Log Configuration',102),
('import_config.delete','ams','import_config','delete','Delete — Import Log Configuration',104);
INSERT INTO `permissions` VALUES
('import_config.edit','ams','import_config','edit','Edit — Import Log Configuration',103),
('import_config.view','ams','import_config','view','View — Import Log Configuration',101),
('import_csv.import','ams','import_csv','import','Import — Excel / CSV Import',143),
('import_csv.view','ams','import_csv','view','View — Excel / CSV Import',142),
('import_logs.delete','ams','import_logs','delete','Delete — Import Attendance Logs',127),
('import_logs.edit','ams','import_logs','edit','Edit — Import Attendance Logs',126),
('import_logs.import','ams','import_logs','import','Import — Import Attendance Logs',128),
('import_logs.view','ams','import_logs','view','View — Import Attendance Logs',125),
('import_review.approve','ams','import_review','approve','Approve — Import Review Queue',130),
('import_review.reject','ams','import_review','reject','Reject — Import Review Queue',131),
('import_review.view','ams','import_review','view','View — Import Review Queue',129),
('jobs.create','setup','jobs','create','Create — Job Table',318),
('jobs.delete','setup','jobs','delete','Delete — Job Table',320),
('jobs.edit','setup','jobs','edit','Edit — Job Table',319),
('jobs.import','setup','jobs','import','Import — Job Table',321),
('jobs.view','setup','jobs','view','View — Job Table',317),
('late_brackets.create','setup','late_brackets','create','Create — Lates / Undertime Table',400),
('late_brackets.delete','setup','late_brackets','delete','Delete — Lates / Undertime Table',402),
('late_brackets.edit','setup','late_brackets','edit','Edit — Lates / Undertime Table',401),
('late_brackets.import','setup','late_brackets','import','Import — Lates / Undertime Table',403),
('late_brackets.view','setup','late_brackets','view','View — Lates / Undertime Table',399),
('leave_applications.approve','pis','leave_applications','approve','Approve — Leave Applications',43),
('leave_applications.create','pis','leave_applications','create','Create — Leave Applications',42),
('leave_applications.reject','pis','leave_applications','reject','Reject — Leave Applications',44),
('leave_applications.view','pis','leave_applications','view','View — Leave Applications',41),
('leave_balances.edit','pis','leave_balances','edit','Edit — Leave Balances & Accrual',46),
('leave_balances.generate','pis','leave_balances','generate','Generate — Leave Balances & Accrual',48),
('leave_balances.import','pis','leave_balances','import','Import — Leave Balances & Accrual',47),
('leave_balances.view','pis','leave_balances','view','View — Leave Balances & Accrual',45),
('leave_types.create','pis','leave_types','create','Create — Leave Category Table',50),
('leave_types.delete','pis','leave_types','delete','Delete — Leave Category Table',52),
('leave_types.edit','pis','leave_types','edit','Edit — Leave Category Table',51),
('leave_types.view','pis','leave_types','view','View — Leave Category Table',49),
('ledgers.approve','pay','ledgers','approve','Approve — Employee Term Loan/Payables',189),
('ledgers.create','pay','ledgers','create','Create — Employee Term Loan/Payables',186),
('ledgers.delete','pay','ledgers','delete','Delete — Employee Term Loan/Payables',188),
('ledgers.edit','pay','ledgers','edit','Edit — Employee Term Loan/Payables',187),
('ledgers.print','pay','ledgers','print','Print — Employee Term Loan/Payables',190),
('ledgers.view','pay','ledgers','view','View — Employee Term Loan/Payables',185),
('loan_approvals.approve','approvals','loan_approvals','approve','Approve — Loan / Deduction Approvals',24),
('loan_approvals.reject','approvals','loan_approvals','reject','Reject — Loan / Deduction Approvals',25),
('loan_approvals.view','approvals','loan_approvals','view','View — Loan / Deduction Approvals',23),
('locations.create','setup','locations','create','Create — Location Table',313),
('locations.delete','setup','locations','delete','Delete — Location Table',315),
('locations.edit','setup','locations','edit','Edit — Location Table',314),
('locations.import','setup','locations','import','Import — Location Table',316),
('locations.view','setup','locations','view','View — Location Table',312),
('memos.approve','pis','memos','approve','Approve — Memoranda / Announcements',83),
('memos.create','pis','memos','create','Create — Memoranda / Announcements',80),
('memos.delete','pis','memos','delete','Delete — Memoranda / Announcements',82),
('memos.edit','pis','memos','edit','Edit — Memoranda / Announcements',81),
('memos.print','pis','memos','print','Print — Memoranda / Announcements',84),
('memos.view','pis','memos','view','View — Memoranda / Announcements',79),
('memo_templates.configure','pis','memo_templates','configure','Configure — Memo Templates & Design',89),
('memo_templates.create','pis','memo_templates','create','Create — Memo Templates & Design',86),
('memo_templates.delete','pis','memo_templates','delete','Delete — Memo Templates & Design',88),
('memo_templates.edit','pis','memo_templates','edit','Edit — Memo Templates & Design',87),
('memo_templates.view','pis','memo_templates','view','View — Memo Templates & Design',85),
('my_calendar.view','ess','my_calendar','view','View — My Calendar',3),
('my_clock.create','ess','my_clock','create','Create — Time Clock (GPS / Selfie)',2),
('my_clock.view','ess','my_clock','view','View — Time Clock (GPS / Selfie)',1),
('my_dtr.print','ess','my_dtr','print','Print — My Daily Time Record',5),
('my_dtr.view','ess','my_dtr','view','View — My Daily Time Record',4),
('my_evaluations.edit','ess','my_evaluations','edit','Edit — My Evaluations',13),
('my_evaluations.view','ess','my_evaluations','view','View — My Evaluations',12),
('my_leaves.create','ess','my_leaves','create','Create — My Leaves',7),
('my_leaves.view','ess','my_leaves','view','View — My Leaves',6),
('my_ledgers.view','ess','my_ledgers','view','View — My Loans & Ledgers',14),
('my_loan_requests.create','ess','my_loan_requests','create','Create — Loan / Deduction Request',16),
('my_loan_requests.view','ess','my_loan_requests','view','View — Loan / Deduction Request',15),
('my_memos.view','ess','my_memos','view','View — Memos & Announcements',19),
('my_overtime.create','ess','my_overtime','create','Create — My Overtime Requests',9),
('my_overtime.view','ess','my_overtime','view','View — My Overtime Requests',8),
('my_payslips.print','ess','my_payslips','print','Print — My Payslips',11),
('my_payslips.view','ess','my_payslips','view','View — My Payslips',10),
('my_profile.edit','ess','my_profile','edit','Edit — My 201 Profile',18),
('my_profile.view','ess','my_profile','view','View — My 201 Profile',17),
('org_units.create','pis','org_units','create','Create — Organization Structure',38),
('org_units.delete','pis','org_units','delete','Delete — Organization Structure',40),
('org_units.edit','pis','org_units','edit','Edit — Organization Structure',39),
('org_units.view','pis','org_units','view','View — Organization Structure',37),
('ot_requests.approve','ams','ot_requests','approve','Approve — Overtime Authorizations',123),
('ot_requests.create','ams','ot_requests','create','Create — Overtime Authorizations',122),
('ot_requests.reject','ams','ot_requests','reject','Reject — Overtime Authorizations',124),
('ot_requests.view','ams','ot_requests','view','View — Overtime Authorizations',121),
('outbox.edit','system','outbox','edit','Edit — Email Outbox',413),
('outbox.view','system','outbox','view','View — Email Outbox',412),
('pagibig.create','setup','pagibig','create','Create — Pag-IBIG Table',396),
('pagibig.delete','setup','pagibig','delete','Delete — Pag-IBIG Table',398),
('pagibig.edit','setup','pagibig','edit','Edit — Pag-IBIG Table',397),
('pagibig.view','setup','pagibig','view','View — Pag-IBIG Table',395),
('payroll_params.edit','system','payroll_params','edit','Edit — Payroll Parameters',405),
('payroll_params.view','system','payroll_params','view','View — Payroll Parameters',404),
('payroll_runs.approve','process','payroll_runs','approve','Approve — Payroll Runs (Process)',230),
('payroll_runs.create','process','payroll_runs','create','Create — Payroll Runs (Process)',227),
('payroll_runs.delete','process','payroll_runs','delete','Delete — Payroll Runs (Process)',229),
('payroll_runs.edit','process','payroll_runs','edit','Edit — Payroll Runs (Process)',228),
('payroll_runs.generate','process','payroll_runs','generate','Generate — Payroll Runs (Process)',231),
('payroll_runs.view','process','payroll_runs','view','View — Payroll Runs (Process)',226),
('payroll_setup.view','setup','payroll_setup','view','View — All parameter tables',304),
('payslips_print.print','process','payslips_print','print','Print — Print Payslips',241),
('payslips_print.view','process','payslips_print','view','View — Print Payslips',240),
('pay_batch.edit','pay','pay_batch','edit','Edit — Payroll Batch Transaction',211),
('pay_batch.import','pay','pay_batch','import','Import — Payroll Batch Transaction',212),
('pay_batch.view','pay','pay_batch','view','View — Payroll Batch Transaction',210),
('pay_groups.create','setup','pay_groups','create','Create — Pay Code Table',347),
('pay_groups.delete','setup','pay_groups','delete','Delete — Pay Code Table',349),
('pay_groups.edit','setup','pay_groups','edit','Edit — Pay Code Table',348),
('pay_groups.view','setup','pay_groups','view','View — Pay Code Table',346),
('pay_inquiry.view','process','pay_inquiry','view','View — Payroll Inquiry',244),
('pay_register.export','process','pay_register','export','Export — Payroll Batch Register',233),
('pay_register.generate','process','pay_register','generate','Generate — Payroll Batch Register',235),
('pay_register.print','process','pay_register','print','Print — Payroll Batch Register',234),
('pay_register.view','process','pay_register','view','View — Payroll Batch Register',232),
('pay_register2.export','process','pay_register2','export','Export — Payroll Batch Register II',237),
('pay_register2.generate','process','pay_register2','generate','Generate — Payroll Batch Register II',239),
('pay_register2.print','process','pay_register2','print','Print — Payroll Batch Register II',238),
('pay_register2.view','process','pay_register2','view','View — Payroll Batch Register II',236),
('pay_trans.delete','pay','pay_trans','delete','Delete — Payroll Transaction',202),
('pay_trans.edit','pay','pay_trans','edit','Edit — Payroll Transaction',201),
('pay_trans.generate','pay','pay_trans','generate','Generate — Payroll Transaction',204),
('pay_trans.import','pay','pay_trans','import','Import — Payroll Transaction',203),
('pay_trans.view','pay','pay_trans','view','View — Payroll Transaction',200),
('pay_trans2.delete','pay','pay_trans2','delete','Delete — Payroll Transaction II',207),
('pay_trans2.edit','pay','pay_trans2','edit','Edit — Payroll Transaction II',206),
('pay_trans2.generate','pay','pay_trans2','generate','Generate — Payroll Transaction II',209),
('pay_trans2.import','pay','pay_trans2','import','Import — Payroll Transaction II',208),
('pay_trans2.view','pay','pay_trans2','view','View — Payroll Transaction II',205),
('periods.approve','pay','periods','approve','Approve — Period Parameters',173),
('periods.create','pay','periods','create','Create — Period Parameters',170),
('periods.delete','pay','periods','delete','Delete — Period Parameters',172),
('periods.edit','pay','periods','edit','Edit — Period Parameters',171),
('periods.generate','pay','periods','generate','Generate — Period Parameters',174),
('periods.view','pay','periods','view','View — Period Parameters',169),
('period_closing.approve','process','period_closing','approve','Approve — Period Closing / Payroll Closing',246),
('period_closing.export','process','period_closing','export','Export — Period Closing / Payroll Closing',247),
('period_closing.print','process','period_closing','print','Print — Period Closing / Payroll Closing',248),
('period_closing.view','process','period_closing','view','View — Period Closing / Payroll Closing',245),
('philhealth.create','setup','philhealth','create','Create — Health Table (PhilHealth)',391),
('philhealth.delete','setup','philhealth','delete','Delete — Health Table (PhilHealth)',393),
('philhealth.edit','setup','philhealth','edit','Edit — Health Table (PhilHealth)',392),
('philhealth.import','setup','philhealth','import','Import — Health Table (PhilHealth)',394),
('philhealth.view','setup','philhealth','view','View — Health Table (PhilHealth)',390),
('premium_rates.create','setup','premium_rates','create','Create — Overtime / Premium Table',427),
('premium_rates.delete','setup','premium_rates','delete','Delete — Overtime / Premium Table',429),
('premium_rates.edit','setup','premium_rates','edit','Edit — Overtime / Premium Table',428),
('premium_rates.view','setup','premium_rates','view','View — Overtime / Premium Table',426),
('prev_ytd.create','pay','prev_ytd','create','Create — YTD Info (Previous Employer)',218),
('prev_ytd.delete','pay','prev_ytd','delete','Delete — YTD Info (Previous Employer)',220),
('prev_ytd.edit','pay','prev_ytd','edit','Edit — YTD Info (Previous Employer)',219),
('prev_ytd.import','pay','prev_ytd','import','Import — YTD Info (Previous Employer)',221),
('prev_ytd.view','pay','prev_ytd','view','View — YTD Info (Previous Employer)',217),
('rank_categories.create','setup','rank_categories','create','Create — Rank Category Table',335),
('rank_categories.delete','setup','rank_categories','delete','Delete — Rank Category Table',337),
('rank_categories.edit','setup','rank_categories','edit','Edit — Rank Category Table',336),
('rank_categories.view','setup','rank_categories','view','View — Rank Category Table',334),
('retro.delete','pay','retro','delete','Delete — Retroactive Adjustments',214),
('retro.generate','pay','retro','generate','Generate — Retroactive Adjustments',216),
('retro.import','pay','retro','import','Import — Retroactive Adjustments',215),
('retro.view','pay','retro','view','View — Retroactive Adjustments',213),
('rpt_13th.export','reports','rpt_13th','export','Export — 13th Month Report',291),
('rpt_13th.print','reports','rpt_13th','print','Print — 13th Month Report',292),
('rpt_13th.view','reports','rpt_13th','view','View — 13th Month Report',290),
('rpt_201_master.configure','reports','rpt_201_master','configure','Configure — Employee 201 File Masterlisting',252),
('rpt_201_master.export','reports','rpt_201_master','export','Export — Employee 201 File Masterlisting',250),
('rpt_201_master.print','reports','rpt_201_master','print','Print — Employee 201 File Masterlisting',251),
('rpt_201_master.view','reports','rpt_201_master','view','View — Employee 201 File Masterlisting',249),
('rpt_2316.print','reports','rpt_2316','print','Print — BIR 2316 / Alphalist',297),
('rpt_2316.view','reports','rpt_2316','view','View — BIR 2316 / Alphalist',296),
('rpt_attendance.export','reports','rpt_attendance','export','Export — Attendance Summary Report',272),
('rpt_attendance.print','reports','rpt_attendance','print','Print — Attendance Summary Report',273),
('rpt_attendance.view','reports','rpt_attendance','view','View — Attendance Summary Report',271),
('rpt_att_detail.export','reports','rpt_att_detail','export','Export — Attendance Summary per Employee Detailed Listing',278),
('rpt_att_detail.print','reports','rpt_att_detail','print','Print — Attendance Summary per Employee Detailed Listing',279),
('rpt_att_detail.view','reports','rpt_att_detail','view','View — Attendance Summary per Employee Detailed Listing',277),
('rpt_att_logs.export','reports','rpt_att_logs','export','Export — Attendance Logs per Employee Listing',275),
('rpt_att_logs.print','reports','rpt_att_logs','print','Print — Attendance Logs per Employee Listing',276),
('rpt_att_logs.view','reports','rpt_att_logs','view','View — Attendance Logs per Employee Listing',274),
('rpt_bank_listing.export','reports','rpt_bank_listing','export','Export — Bank Deposit Listing',263),
('rpt_bank_listing.print','reports','rpt_bank_listing','print','Print — Bank Deposit Listing',264);
INSERT INTO `permissions` VALUES
('rpt_bank_listing.view','reports','rpt_bank_listing','view','View — Bank Deposit Listing',262),
('rpt_branch.view','reports','rpt_branch','view','View — Branch Cost Allocation',300),
('rpt_cash_listing.export','reports','rpt_cash_listing','export','Export — Cash Payment Listing',266),
('rpt_cash_listing.print','reports','rpt_cash_listing','print','Print — Cash Payment Listing',267),
('rpt_cash_listing.view','reports','rpt_cash_listing','view','View — Cash Payment Listing',265),
('rpt_departments.view','reports','rpt_departments','view','View — Department Reports',301),
('rpt_earn_ded.export','reports','rpt_earn_ded','export','Export — Earnings & Deductions',281),
('rpt_earn_ded.print','reports','rpt_earn_ded','print','Print — Earnings & Deductions',282),
('rpt_earn_ded.view','reports','rpt_earn_ded','view','View — Earnings & Deductions',280),
('rpt_journal.export','reports','rpt_journal','export','Export — Payroll Journal (GL)',285),
('rpt_journal.print','reports','rpt_journal','print','Print — Payroll Journal (GL)',286),
('rpt_journal.view','reports','rpt_journal','view','View — Payroll Journal (GL)',284),
('rpt_loans.export','reports','rpt_loans','export','Export — Loan & Ledger Balances',288),
('rpt_loans.print','reports','rpt_loans','print','Print — Loan & Ledger Balances',289),
('rpt_loans.view','reports','rpt_loans','view','View — Loan & Ledger Balances',287),
('rpt_monthly.export','reports','rpt_monthly','export','Export — Monthly Payroll Summary',299),
('rpt_monthly.view','reports','rpt_monthly','view','View — Monthly Payroll Summary',298),
('rpt_monthly_contrib.export','reports','rpt_monthly_contrib','export','Export — Monthly Reports',257),
('rpt_monthly_contrib.print','reports','rpt_monthly_contrib','print','Print — Monthly Reports',258),
('rpt_monthly_contrib.view','reports','rpt_monthly_contrib','view','View — Monthly Reports',256),
('rpt_periodical.export','reports','rpt_periodical','export','Export — Periodical Reports',254),
('rpt_periodical.print','reports','rpt_periodical','print','Print — Periodical Reports',255),
('rpt_periodical.view','reports','rpt_periodical','view','View — Periodical Reports',253),
('rpt_register.export','reports','rpt_register','export','Export — Payroll Register (summary)',269),
('rpt_register.print','reports','rpt_register','print','Print — Payroll Register (summary)',270),
('rpt_register.view','reports','rpt_register','view','View — Payroll Register (summary)',268),
('rpt_remittance.export','reports','rpt_remittance','export','Export — Government Remittances',294),
('rpt_remittance.print','reports','rpt_remittance','print','Print — Government Remittances',295),
('rpt_remittance.view','reports','rpt_remittance','view','View — Government Remittances',293),
('rpt_ytd.view','reports','rpt_ytd','view','View — Year-to-Date Report',283),
('rpt_ytd_reports.export','reports','rpt_ytd_reports','export','Export — YTD Reports',260),
('rpt_ytd_reports.print','reports','rpt_ytd_reports','print','Print — YTD Reports',261),
('rpt_ytd_reports.view','reports','rpt_ytd_reports','view','View — YTD Reports',259),
('salary_grades.create','pis','salary_grades','create','Create — Salary Grade Table',64),
('salary_grades.delete','pis','salary_grades','delete','Delete — Salary Grade Table',66),
('salary_grades.edit','pis','salary_grades','edit','Edit — Salary Grade Table',65),
('salary_grades.view','pis','salary_grades','view','View — Salary Grade Table',63),
('salary_scaling.approve','pis','salary_scaling','approve','Approve — Salary Scaling Simulator',68),
('salary_scaling.view','pis','salary_scaling','view','View — Salary Scaling Simulator',67),
('schedules.create','ams','schedules','create','Create — Employee Shift Entry',113),
('schedules.delete','ams','schedules','delete','Delete — Employee Shift Entry',114),
('schedules.import','ams','schedules','import','Import — Employee Shift Entry',115),
('schedules.view','ams','schedules','view','View — Employee Shift Entry',112),
('settings.edit','system','settings','edit','Edit — System Settings',409),
('settings.view','system','settings','view','View — System Settings',408),
('shifts.create','setup','shifts','create','Create — Shift Table',351),
('shifts.delete','setup','shifts','delete','Delete — Shift Table',353),
('shifts.edit','setup','shifts','edit','Edit — Shift Table',352),
('shifts.import','setup','shifts','import','Import — Shift Table',354),
('shifts.view','setup','shifts','view','View — Shift Table',350),
('signatories.edit','system','signatories','edit','Edit — Signatory',407),
('signatories.view','system','signatories','view','View — Signatory',406),
('sss_table.create','setup','sss_table','create','Create — SSS Table',381),
('sss_table.delete','setup','sss_table','delete','Delete — SSS Table',383),
('sss_table.edit','setup','sss_table','edit','Edit — SSS Table',382),
('sss_table.generate','setup','sss_table','generate','Generate — SSS Table',385),
('sss_table.import','setup','sss_table','import','Import — SSS Table',384),
('sss_table.view','setup','sss_table','view','View — SSS Table',380),
('tax_table.create','setup','tax_table','create','Create — Tax Table',387),
('tax_table.delete','setup','tax_table','delete','Delete — Tax Table',389),
('tax_table.edit','setup','tax_table','edit','Edit — Tax Table',388),
('tax_table.view','setup','tax_table','view','View — Tax Table',386),
('trans_payments.create','pay','trans_payments','create','Create — Transaction Payment',197),
('trans_payments.delete','pay','trans_payments','delete','Delete — Transaction Payment',198),
('trans_payments.import','pay','trans_payments','import','Import — Transaction Payment',199),
('trans_payments.view','pay','trans_payments','view','View — Transaction Payment',196),
('web_verify.approve','ams','web_verify','approve','Approve — Web Check-in Verification',119),
('web_verify.reject','ams','web_verify','reject','Reject — Web Check-in Verification',120),
('web_verify.view','ams','web_verify','view','View — Web Check-in Verification',118);
DROP TABLE IF EXISTS `philhealth_params`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `philhealth_params` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `effective_from` date NOT NULL,
  `rate_pct` decimal(5,2) NOT NULL,
  `floor_salary` decimal(12,2) NOT NULL,
  `ceiling_salary` decimal(12,2) NOT NULL,
  `ee_share_pct` decimal(5,2) NOT NULL DEFAULT 50.00,
  `legal_basis` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `philhealth_params` VALUES
(1,'2024-01-01',5.00,10000.00,100000.00,50.00,'RA 11223 (UHC Act); PhilHealth Circular 2020-0005; no increase for 2026');
DROP TABLE IF EXISTS `premium_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `premium_rates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `day_type` enum('ordinary','rest_day','special_holiday','special_rest','regular_holiday','regular_rest','double_holiday') NOT NULL,
  `label` varchar(100) NOT NULL,
  `work_multiplier` decimal(5,2) NOT NULL COMMENT 'Applied to hourly rate for the first 8 hours',
  `ot_multiplier` decimal(5,2) NOT NULL COMMENT 'Applied on top of the day rate for hours beyond 8',
  `legal_basis` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `day_type` (`day_type`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `premium_rates` VALUES
(1,'ordinary','Ordinary working day',1.00,1.25,'Labor Code Art. 87'),
(2,'rest_day','Rest day',1.30,1.30,'Labor Code Art. 93'),
(3,'special_holiday','Special non-working day',1.30,1.30,'Labor Code Art. 93; DOLE holiday pay rules'),
(4,'special_rest','Special non-working day falling on rest day',1.50,1.30,'DOLE holiday pay rules'),
(5,'regular_holiday','Regular holiday',2.00,1.30,'Labor Code Art. 94'),
(6,'regular_rest','Regular holiday falling on rest day',2.60,1.30,'DOLE holiday pay rules'),
(7,'double_holiday','Double regular holiday',3.00,1.30,'DOLE holiday pay rules');
DROP TABLE IF EXISTS `rank_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rank_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(10) NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `rank_categories` VALUES
(1,'RF','Rank and File',1),
(2,'SUP','Supervisory',1),
(3,'MGR','Managerial',1),
(4,'EXE','Executive',1);
DROP TABLE IF EXISTS `report_layouts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `report_layouts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `report_key` varchar(40) NOT NULL,
  `name` varchar(80) NOT NULL,
  `col_list` text NOT NULL,
  `opts` text DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rlayout_report` (`report_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `retro_adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `retro_adjustments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `history_id` int(11) DEFAULT NULL,
  `effective_date` date NOT NULL,
  `old_rate` decimal(12,2) NOT NULL,
  `new_rate` decimal(12,2) NOT NULL,
  `rate_type` enum('monthly','daily') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `breakdown` text DEFAULT NULL,
  `status` enum('pending','included','cancelled') NOT NULL DEFAULT 'pending',
  `pending_earning_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_retro_emp` (`employee_id`),
  CONSTRAINT `fk_retro_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_permissions` (
  `role_id` int(11) NOT NULL,
  `perm_key` varchar(80) NOT NULL,
  PRIMARY KEY (`role_id`,`perm_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `role_permissions` VALUES
(2,'ams_policies.configure'),
(2,'ams_policies.view'),
(2,'approvals.approve'),
(2,'approvals.reject'),
(2,'approvals.view'),
(2,'att_batch.edit'),
(2,'att_batch.import'),
(2,'att_batch.view'),
(2,'att_close.approve'),
(2,'att_close.view'),
(2,'att_entry.edit'),
(2,'att_entry.generate'),
(2,'att_entry.view'),
(2,'att_exceptions.approve'),
(2,'att_exceptions.view'),
(2,'att_generate.approve'),
(2,'att_generate.generate'),
(2,'att_generate.view'),
(2,'att_leave_entry.create'),
(2,'att_leave_entry.delete'),
(2,'att_leave_entry.view'),
(2,'att_logs.create'),
(2,'att_logs.edit'),
(2,'att_logs.view'),
(2,'att_process.generate'),
(2,'att_process.view'),
(2,'att_summary.approve'),
(2,'att_summary.delete'),
(2,'att_summary.edit'),
(2,'att_summary.generate'),
(2,'att_summary.import'),
(2,'att_summary.view'),
(2,'att_summary2.approve'),
(2,'att_summary2.delete'),
(2,'att_summary2.edit'),
(2,'att_summary2.generate'),
(2,'att_summary2.import'),
(2,'att_summary2.view'),
(2,'audit.export'),
(2,'audit.view'),
(2,'batch_shift.create'),
(2,'batch_shift.view'),
(2,'bio_devices.create'),
(2,'bio_devices.delete'),
(2,'bio_devices.edit'),
(2,'bio_devices.import'),
(2,'bio_devices.view'),
(2,'branches.create'),
(2,'branches.delete'),
(2,'branches.edit'),
(2,'branches.import'),
(2,'branches.view'),
(2,'cases.approve'),
(2,'cases.create'),
(2,'cases.edit'),
(2,'cases.print'),
(2,'cases.view'),
(2,'classifications.create'),
(2,'classifications.delete'),
(2,'classifications.edit'),
(2,'classifications.view'),
(2,'class_groups.create'),
(2,'class_groups.delete'),
(2,'class_groups.edit'),
(2,'class_groups.view'),
(2,'company_events.create'),
(2,'company_events.delete'),
(2,'company_events.edit'),
(2,'company_events.view'),
(2,'competencies.create'),
(2,'competencies.delete'),
(2,'competencies.edit'),
(2,'competencies.import'),
(2,'competencies.view'),
(2,'conduct.create'),
(2,'conduct.delete'),
(2,'conduct.edit'),
(2,'conduct.import'),
(2,'conduct.view'),
(2,'cutoffs.approve'),
(2,'cutoffs.create'),
(2,'cutoffs.generate'),
(2,'cutoffs.view'),
(2,'departments.import'),
(2,'departments.view'),
(2,'dtr.approve'),
(2,'dtr.edit'),
(2,'dtr.generate'),
(2,'dtr.print'),
(2,'dtr.view'),
(2,'employees.create'),
(2,'employees.edit'),
(2,'employees.export'),
(2,'employees.import'),
(2,'employees.print'),
(2,'employees.view'),
(2,'employee_batch.edit'),
(2,'employee_batch.view'),
(2,'employee_salary.edit'),
(2,'employee_salary.view'),
(2,'emp_groups.create'),
(2,'emp_groups.delete'),
(2,'emp_groups.edit'),
(2,'emp_groups.view'),
(2,'emp_types.create'),
(2,'emp_types.delete'),
(2,'emp_types.edit'),
(2,'emp_types.view'),
(2,'evaluations.approve'),
(2,'evaluations.edit'),
(2,'evaluations.view'),
(2,'eval_cycles.create'),
(2,'eval_cycles.delete'),
(2,'eval_cycles.edit'),
(2,'eval_cycles.generate'),
(2,'eval_cycles.view'),
(2,'exemption_codes.create'),
(2,'exemption_codes.delete'),
(2,'exemption_codes.edit'),
(2,'exemption_codes.view'),
(2,'final_pay.approve'),
(2,'final_pay.generate'),
(2,'final_pay.print'),
(2,'final_pay.view'),
(2,'holidays.create'),
(2,'holidays.delete'),
(2,'holidays.edit'),
(2,'holidays.view'),
(2,'import_config.create'),
(2,'import_config.delete'),
(2,'import_config.edit'),
(2,'import_config.view'),
(2,'import_csv.import'),
(2,'import_csv.view'),
(2,'import_logs.delete'),
(2,'import_logs.edit'),
(2,'import_logs.import'),
(2,'import_logs.view'),
(2,'import_review.approve'),
(2,'import_review.reject'),
(2,'import_review.view'),
(2,'jobs.create'),
(2,'jobs.delete'),
(2,'jobs.edit'),
(2,'jobs.import'),
(2,'jobs.view'),
(2,'leave_applications.approve'),
(2,'leave_applications.create'),
(2,'leave_applications.reject'),
(2,'leave_applications.view'),
(2,'leave_balances.edit'),
(2,'leave_balances.generate'),
(2,'leave_balances.import'),
(2,'leave_balances.view'),
(2,'leave_types.create'),
(2,'leave_types.delete'),
(2,'leave_types.edit'),
(2,'leave_types.view'),
(2,'loan_approvals.approve'),
(2,'loan_approvals.reject'),
(2,'loan_approvals.view'),
(2,'locations.create'),
(2,'locations.delete'),
(2,'locations.edit'),
(2,'locations.import'),
(2,'locations.view'),
(2,'memos.approve'),
(2,'memos.create'),
(2,'memos.delete'),
(2,'memos.edit'),
(2,'memos.print'),
(2,'memos.view'),
(2,'memo_templates.configure'),
(2,'memo_templates.create'),
(2,'memo_templates.delete'),
(2,'memo_templates.edit'),
(2,'memo_templates.view'),
(2,'my_calendar.view'),
(2,'my_clock.create'),
(2,'my_clock.view'),
(2,'my_dtr.print'),
(2,'my_dtr.view'),
(2,'my_evaluations.edit'),
(2,'my_evaluations.view'),
(2,'my_leaves.create'),
(2,'my_leaves.view'),
(2,'my_ledgers.view'),
(2,'my_loan_requests.create'),
(2,'my_loan_requests.view'),
(2,'my_memos.view'),
(2,'my_overtime.create'),
(2,'my_overtime.view'),
(2,'my_payslips.print'),
(2,'my_payslips.view'),
(2,'my_profile.edit'),
(2,'my_profile.view'),
(2,'org_units.create'),
(2,'org_units.delete'),
(2,'org_units.edit'),
(2,'org_units.view'),
(2,'ot_requests.approve'),
(2,'ot_requests.create'),
(2,'ot_requests.reject'),
(2,'ot_requests.view'),
(2,'outbox.edit'),
(2,'outbox.view'),
(2,'payroll_setup.view'),
(2,'pay_batch.edit'),
(2,'pay_batch.import'),
(2,'pay_batch.view'),
(2,'pay_inquiry.view'),
(2,'pay_trans.delete'),
(2,'pay_trans.edit'),
(2,'pay_trans.generate'),
(2,'pay_trans.import'),
(2,'pay_trans.view'),
(2,'pay_trans2.delete'),
(2,'pay_trans2.edit'),
(2,'pay_trans2.generate'),
(2,'pay_trans2.import'),
(2,'pay_trans2.view'),
(2,'periods.approve'),
(2,'periods.create'),
(2,'periods.delete'),
(2,'periods.edit'),
(2,'periods.generate'),
(2,'periods.view'),
(2,'rank_categories.create'),
(2,'rank_categories.delete'),
(2,'rank_categories.edit'),
(2,'rank_categories.view'),
(2,'rpt_201_master.configure'),
(2,'rpt_201_master.export'),
(2,'rpt_201_master.print'),
(2,'rpt_201_master.view'),
(2,'rpt_attendance.export'),
(2,'rpt_attendance.print'),
(2,'rpt_attendance.view'),
(2,'rpt_att_detail.export'),
(2,'rpt_att_detail.print'),
(2,'rpt_att_detail.view'),
(2,'rpt_att_logs.export'),
(2,'rpt_att_logs.print'),
(2,'rpt_att_logs.view'),
(2,'rpt_departments.view'),
(2,'rpt_monthly.export'),
(2,'rpt_monthly.view'),
(2,'salary_grades.create'),
(2,'salary_grades.delete'),
(2,'salary_grades.edit'),
(2,'salary_grades.view'),
(2,'salary_scaling.approve'),
(2,'salary_scaling.view'),
(2,'schedules.create'),
(2,'schedules.delete'),
(2,'schedules.import'),
(2,'schedules.view'),
(2,'shifts.create'),
(2,'shifts.delete'),
(2,'shifts.edit'),
(2,'shifts.import'),
(2,'shifts.view'),
(2,'signatories.edit'),
(2,'signatories.view'),
(2,'web_verify.approve'),
(2,'web_verify.reject'),
(2,'web_verify.view'),
(3,'att_batch.edit'),
(3,'att_batch.import'),
(3,'att_batch.view'),
(3,'att_close.approve'),
(3,'att_close.view'),
(3,'att_entry.edit'),
(3,'att_entry.generate'),
(3,'att_entry.view'),
(3,'att_generate.approve'),
(3,'att_generate.generate'),
(3,'att_generate.view'),
(3,'att_process.generate'),
(3,'att_process.view'),
(3,'att_summary.approve'),
(3,'att_summary.delete'),
(3,'att_summary.edit'),
(3,'att_summary.generate'),
(3,'att_summary.import'),
(3,'att_summary.view'),
(3,'att_summary2.approve'),
(3,'att_summary2.delete'),
(3,'att_summary2.edit'),
(3,'att_summary2.generate'),
(3,'att_summary2.import'),
(3,'att_summary2.view'),
(3,'audit.export'),
(3,'audit.view'),
(3,'bank_file.export'),
(3,'bank_file.view'),
(3,'bank_setup.edit'),
(3,'bank_setup.view'),
(3,'benefit_types.create'),
(3,'benefit_types.delete'),
(3,'benefit_types.edit'),
(3,'benefit_types.import'),
(3,'benefit_types.view'),
(3,'branches.create'),
(3,'branches.delete'),
(3,'branches.edit'),
(3,'branches.import'),
(3,'branches.view'),
(3,'budgets.edit'),
(3,'budgets.view'),
(3,'classifications.create'),
(3,'classifications.delete'),
(3,'classifications.edit'),
(3,'classifications.view'),
(3,'class_groups.create'),
(3,'class_groups.delete'),
(3,'class_groups.edit'),
(3,'class_groups.view'),
(3,'cutoffs.approve'),
(3,'cutoffs.create'),
(3,'cutoffs.generate'),
(3,'cutoffs.view'),
(3,'deduction_types.create'),
(3,'deduction_types.delete'),
(3,'deduction_types.edit'),
(3,'deduction_types.import'),
(3,'deduction_types.view'),
(3,'departments.import'),
(3,'departments.view'),
(3,'dtr.generate'),
(3,'dtr.print'),
(3,'dtr.view'),
(3,'earning_types.create'),
(3,'earning_types.delete'),
(3,'earning_types.edit'),
(3,'earning_types.import'),
(3,'earning_types.view'),
(3,'employees.export'),
(3,'employees.print'),
(3,'employees.view'),
(3,'employee_salary.view'),
(3,'emp_benefits.create'),
(3,'emp_benefits.delete'),
(3,'emp_benefits.edit'),
(3,'emp_benefits.import'),
(3,'emp_benefits.view'),
(3,'emp_deductions.approve'),
(3,'emp_deductions.create'),
(3,'emp_deductions.edit'),
(3,'emp_deductions.import'),
(3,'emp_deductions.view'),
(3,'emp_groups.create'),
(3,'emp_groups.delete'),
(3,'emp_groups.edit'),
(3,'emp_groups.view'),
(3,'emp_transactions.create'),
(3,'emp_transactions.delete'),
(3,'emp_transactions.edit'),
(3,'emp_transactions.import'),
(3,'emp_transactions.view'),
(3,'emp_types.create'),
(3,'emp_types.delete'),
(3,'emp_types.edit'),
(3,'emp_types.view'),
(3,'exemption_codes.create'),
(3,'exemption_codes.delete'),
(3,'exemption_codes.edit'),
(3,'exemption_codes.view'),
(3,'final_pay.approve'),
(3,'final_pay.generate'),
(3,'final_pay.print'),
(3,'final_pay.view'),
(3,'fixed_trans.create'),
(3,'fixed_trans.delete'),
(3,'fixed_trans.edit'),
(3,'fixed_trans.import'),
(3,'fixed_trans.view'),
(3,'fixed_trans_codes.create'),
(3,'fixed_trans_codes.delete'),
(3,'fixed_trans_codes.edit'),
(3,'fixed_trans_codes.import'),
(3,'fixed_trans_codes.view'),
(3,'gl_accounts.create'),
(3,'gl_accounts.delete'),
(3,'gl_accounts.edit'),
(3,'gl_accounts.view'),
(3,'holidays.create'),
(3,'holidays.delete'),
(3,'holidays.edit'),
(3,'holidays.view'),
(3,'jobs.create'),
(3,'jobs.delete'),
(3,'jobs.edit'),
(3,'jobs.import'),
(3,'jobs.view'),
(3,'late_brackets.create'),
(3,'late_brackets.delete'),
(3,'late_brackets.edit'),
(3,'late_brackets.import'),
(3,'late_brackets.view'),
(3,'ledgers.approve'),
(3,'ledgers.create'),
(3,'ledgers.delete'),
(3,'ledgers.edit'),
(3,'ledgers.print'),
(3,'ledgers.view'),
(3,'loan_approvals.approve'),
(3,'loan_approvals.reject'),
(3,'loan_approvals.view'),
(3,'locations.create'),
(3,'locations.delete'),
(3,'locations.edit'),
(3,'locations.import'),
(3,'locations.view'),
(3,'my_calendar.view'),
(3,'my_clock.create'),
(3,'my_clock.view'),
(3,'my_dtr.print'),
(3,'my_dtr.view'),
(3,'my_evaluations.edit'),
(3,'my_evaluations.view'),
(3,'my_leaves.create'),
(3,'my_leaves.view'),
(3,'my_ledgers.view'),
(3,'my_loan_requests.create'),
(3,'my_loan_requests.view'),
(3,'my_memos.view'),
(3,'my_overtime.create'),
(3,'my_overtime.view'),
(3,'my_payslips.print'),
(3,'my_payslips.view'),
(3,'my_profile.edit'),
(3,'my_profile.view'),
(3,'pagibig.create'),
(3,'pagibig.delete'),
(3,'pagibig.edit'),
(3,'pagibig.view'),
(3,'payroll_params.edit'),
(3,'payroll_params.view'),
(3,'payroll_runs.approve'),
(3,'payroll_runs.create'),
(3,'payroll_runs.delete'),
(3,'payroll_runs.edit'),
(3,'payroll_runs.generate'),
(3,'payroll_runs.view'),
(3,'payroll_setup.view'),
(3,'payslips_print.print'),
(3,'payslips_print.view'),
(3,'pay_batch.edit'),
(3,'pay_batch.import'),
(3,'pay_batch.view'),
(3,'pay_groups.create'),
(3,'pay_groups.delete'),
(3,'pay_groups.edit'),
(3,'pay_groups.view'),
(3,'pay_inquiry.view'),
(3,'pay_register.export'),
(3,'pay_register.generate'),
(3,'pay_register.print'),
(3,'pay_register.view'),
(3,'pay_register2.export'),
(3,'pay_register2.generate'),
(3,'pay_register2.print'),
(3,'pay_register2.view'),
(3,'pay_trans.delete'),
(3,'pay_trans.edit'),
(3,'pay_trans.generate'),
(3,'pay_trans.import'),
(3,'pay_trans.view'),
(3,'pay_trans2.delete'),
(3,'pay_trans2.edit'),
(3,'pay_trans2.generate'),
(3,'pay_trans2.import'),
(3,'pay_trans2.view'),
(3,'periods.approve'),
(3,'periods.create'),
(3,'periods.delete'),
(3,'periods.edit'),
(3,'periods.generate'),
(3,'periods.view'),
(3,'period_closing.approve'),
(3,'period_closing.export'),
(3,'period_closing.print'),
(3,'period_closing.view'),
(3,'philhealth.create'),
(3,'philhealth.delete'),
(3,'philhealth.edit'),
(3,'philhealth.import'),
(3,'philhealth.view'),
(3,'premium_rates.create'),
(3,'premium_rates.delete'),
(3,'premium_rates.edit'),
(3,'premium_rates.view'),
(3,'prev_ytd.create'),
(3,'prev_ytd.delete'),
(3,'prev_ytd.edit'),
(3,'prev_ytd.import'),
(3,'prev_ytd.view'),
(3,'rank_categories.create'),
(3,'rank_categories.delete'),
(3,'rank_categories.edit'),
(3,'rank_categories.view'),
(3,'retro.delete'),
(3,'retro.generate'),
(3,'retro.import'),
(3,'retro.view'),
(3,'rpt_13th.export'),
(3,'rpt_13th.print'),
(3,'rpt_13th.view'),
(3,'rpt_201_master.export'),
(3,'rpt_201_master.print'),
(3,'rpt_201_master.view'),
(3,'rpt_2316.print'),
(3,'rpt_2316.view'),
(3,'rpt_attendance.export'),
(3,'rpt_attendance.print'),
(3,'rpt_attendance.view'),
(3,'rpt_att_detail.export'),
(3,'rpt_att_detail.print'),
(3,'rpt_att_detail.view'),
(3,'rpt_att_logs.export'),
(3,'rpt_att_logs.print'),
(3,'rpt_att_logs.view'),
(3,'rpt_bank_listing.export'),
(3,'rpt_bank_listing.print'),
(3,'rpt_bank_listing.view'),
(3,'rpt_branch.view'),
(3,'rpt_cash_listing.export'),
(3,'rpt_cash_listing.print'),
(3,'rpt_cash_listing.view'),
(3,'rpt_departments.view'),
(3,'rpt_earn_ded.export'),
(3,'rpt_earn_ded.print'),
(3,'rpt_earn_ded.view'),
(3,'rpt_journal.export'),
(3,'rpt_journal.print'),
(3,'rpt_journal.view'),
(3,'rpt_loans.export'),
(3,'rpt_loans.print'),
(3,'rpt_loans.view'),
(3,'rpt_monthly.export'),
(3,'rpt_monthly.view'),
(3,'rpt_monthly_contrib.export'),
(3,'rpt_monthly_contrib.print'),
(3,'rpt_monthly_contrib.view'),
(3,'rpt_periodical.export'),
(3,'rpt_periodical.print'),
(3,'rpt_periodical.view'),
(3,'rpt_register.export'),
(3,'rpt_register.print'),
(3,'rpt_register.view'),
(3,'rpt_remittance.export'),
(3,'rpt_remittance.print'),
(3,'rpt_remittance.view'),
(3,'rpt_ytd.view'),
(3,'rpt_ytd_reports.export'),
(3,'rpt_ytd_reports.print'),
(3,'rpt_ytd_reports.view'),
(3,'signatories.edit'),
(3,'signatories.view'),
(3,'sss_table.create'),
(3,'sss_table.delete'),
(3,'sss_table.edit'),
(3,'sss_table.generate'),
(3,'sss_table.import'),
(3,'sss_table.view'),
(3,'tax_table.create'),
(3,'tax_table.delete'),
(3,'tax_table.edit'),
(3,'tax_table.view'),
(3,'trans_payments.create'),
(3,'trans_payments.delete'),
(3,'trans_payments.import'),
(3,'trans_payments.view'),
(4,'approvals.approve'),
(4,'approvals.reject'),
(4,'approvals.view'),
(4,'evaluations.edit'),
(4,'evaluations.view'),
(4,'loan_approvals.approve'),
(4,'loan_approvals.reject'),
(4,'loan_approvals.view'),
(4,'my_calendar.view'),
(4,'my_clock.create'),
(4,'my_clock.view'),
(4,'my_dtr.print'),
(4,'my_dtr.view'),
(4,'my_evaluations.edit'),
(4,'my_evaluations.view'),
(4,'my_leaves.create'),
(4,'my_leaves.view'),
(4,'my_ledgers.view'),
(4,'my_loan_requests.create'),
(4,'my_loan_requests.view'),
(4,'my_memos.view'),
(4,'my_overtime.create'),
(4,'my_overtime.view'),
(4,'my_payslips.print'),
(4,'my_payslips.view'),
(4,'my_profile.edit'),
(4,'my_profile.view'),
(4,'ot_requests.approve'),
(4,'ot_requests.create'),
(4,'ot_requests.reject'),
(4,'ot_requests.view'),
(5,'my_calendar.view'),
(5,'my_clock.create'),
(5,'my_clock.view'),
(5,'my_dtr.print'),
(5,'my_dtr.view'),
(5,'my_evaluations.edit'),
(5,'my_evaluations.view'),
(5,'my_leaves.create'),
(5,'my_leaves.view'),
(5,'my_ledgers.view'),
(5,'my_loan_requests.create'),
(5,'my_loan_requests.view'),
(5,'my_memos.view'),
(5,'my_overtime.create'),
(5,'my_overtime.view'),
(5,'my_payslips.print'),
(5,'my_payslips.view'),
(5,'my_profile.edit'),
(5,'my_profile.view');
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `name` varchar(80) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_super` tinyint(1) NOT NULL DEFAULT 0,
  `base_role` varchar(20) NOT NULL DEFAULT 'employee',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_code` (`code`),
  UNIQUE KEY `uq_role_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `roles` VALUES
(1,'admin','System Administrator','Super Admin role: every page and every action, manages roles and user permissions. Protected: it cannot be edited, deactivated or deleted.','active',1,1,'admin','2026-11-11 20:23:48','2026-11-11 20:23:48',NULL,NULL),
(2,'hr','HR Officer','Personnel, attendance, leave and the shared setup tables.','active',1,0,'hr','2026-11-11 20:23:48','2026-11-11 20:23:48',NULL,NULL),
(3,'payroll','Payroll Officer','Payroll transactions, processing, registers, reports and the payroll setup.','active',1,0,'payroll','2026-11-11 20:23:48','2026-11-11 20:23:48',NULL,NULL),
(4,'supervisor','Supervisor / Dept. Head','Approves leave and overtime and evaluates their own team, plus self-service.','active',1,0,'supervisor','2026-11-11 20:23:48','2026-11-11 20:23:48',NULL,NULL),
(5,'employee','Employee','Self-service: time clock, own records, leave and overtime requests, payslips.','active',1,0,'employee','2026-11-11 20:23:48','2026-11-11 20:23:48',NULL,NULL);
DROP TABLE IF EXISTS `salary_grade_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `salary_grade_steps` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `grade_id` int(11) NOT NULL,
  `step_no` tinyint(4) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_grade_step` (`grade_id`,`step_no`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `salary_grade_steps` VALUES
(1,1,1,15000.00),
(2,1,2,16250.00),
(3,1,3,17500.00),
(4,1,4,18750.00),
(5,1,5,20000.00),
(6,2,1,20000.00),
(7,2,2,22000.00),
(8,2,3,24000.00),
(9,2,4,26000.00),
(10,2,5,28000.00),
(11,3,1,28000.00),
(12,3,2,31000.00),
(13,3,3,34000.00),
(14,3,4,37000.00),
(15,3,5,40000.00),
(16,4,1,40000.00),
(17,4,2,45000.00),
(18,4,3,50000.00),
(19,4,4,55000.00),
(20,4,5,60000.00),
(21,5,1,60000.00),
(22,5,2,67500.00),
(23,5,3,75000.00),
(24,5,4,82500.00),
(25,5,5,90000.00),
(26,6,1,90000.00),
(27,6,2,102500.00),
(28,6,3,115000.00),
(29,6,4,127500.00),
(30,6,5,140000.00);
DROP TABLE IF EXISTS `salary_grades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `salary_grades` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `position_band` varchar(120) NOT NULL,
  `minimum` decimal(12,2) NOT NULL,
  `midpoint` decimal(12,2) NOT NULL,
  `maximum` decimal(12,2) NOT NULL,
  `num_steps` tinyint(4) NOT NULL DEFAULT 5,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `salary_grades` VALUES
(1,'SG-1','Entry-Level / Rank-and-File',15000.00,17500.00,20000.00,5,1),
(2,'SG-2','Skilled / Senior Staff',20000.00,24000.00,28000.00,5,2),
(3,'SG-3','Supervisory',28000.00,34000.00,40000.00,5,3),
(4,'SG-4','Assistant Manager / Manager',40000.00,50000.00,60000.00,5,4),
(5,'SG-5','Senior Manager / Department Head',60000.00,75000.00,90000.00,5,5),
(6,'SG-6','Executive',90000.00,115000.00,140000.00,5,6);
DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `skey` varchar(80) NOT NULL,
  `svalue` text DEFAULT NULL,
  `label` varchar(160) DEFAULT NULL,
  `grp` varchar(40) NOT NULL DEFAULT 'general',
  `input_type` varchar(255) NOT NULL DEFAULT 'text',
  `help` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `settings` VALUES
('accountability_max_pct','20','Max deduction for loss/damage per payroll (% of wages)','payroll','number','Labor Code Art. 114 & IRR Book III Rule VIII.',7),
('ams_abs_period_total','0','Total all absences per cut-off period','ams_config','bool',NULL,2),
('ams_abs_week_total','0','Total all absences per WEEK per cut-off period','ams_config','bool',NULL,1),
('ams_break_excess_late','0','BREAK rendered above allocated break time as LATE','ams_policy','bool',NULL,3),
('ams_dup_minutes','15','# of minutes between LOGS w/ same TYPE as INVALID','ams_config','number','A log repeating the previous log of the same type within this many minutes (a double tap) is not counted. Logs of different types always count.',14),
('ams_duty_mode','first_last','Duty hours rendered','ams_policy','select:first_last=TIMEIN and TIMEOUT log,pairs=Every IN and OUT log',NULL,1),
('ams_early_break_penalty','0','LogIn before BREAKTIME-IN penalty (minutes)','ams_config','number',NULL,11),
('ams_early_break_penalty_on','0','LogIn 20 mins. before BREAKTIME-IN penalty','ams_config','bool',NULL,10),
('ams_holiday_absent_prior','0','Holiday absent when absent on prior non-holiday day','ams_config','bool',NULL,3),
('ams_incomplete_absent','0','Incomplete log as ABSENT','ams_config','bool',NULL,6),
('ams_incomplete_penalty','0','Incomplete log penalty (minutes)','ams_config','number',NULL,9),
('ams_incomplete_penalty_on','0','Incomplete log penalty','ams_config','bool',NULL,8),
('ams_incomplete_ut','1','Incomplete log as UNDERTIME','ams_config','bool',NULL,7),
('ams_invalid_hours','17','No. of hours work as INVALID','ams_config','number',NULL,13),
('ams_late_lookup','E','Late table lookup code [D,E,P]','ams_config','select:E=E - each late occurrence,D=D - total per day,P=P - total per cut-off period',NULL,15),
('ams_late_mode','every','Late and undertime','ams_policy','select:in_out=Late at TIMEIN and Undertime at TIMEOUT log,every=Late at every IN log and Undertime on every OUT log',NULL,2),
('ams_nobreak_absent','0','No BREAK log on SHIFT w/ break as ABSENT','ams_policy','bool','Every break must be logged. A work segment missing its opening or closing log is absent: shift 8-12 / 1-5, logs 8:00 12:01 5:05 = 1:00-5:00 absent (4 hrs); logs 7:57 5:15 = 8 hrs absent.',9),
('ams_nobreak_ut','1','No BREAK log on SHIFT w/ break as UNDERTIME','ams_policy','bool','Same rule as No BREAK log as ABSENT, but the uncredited segment counts as undertime. When both are ticked, ABSENT applies.',10),
('ams_nolate_holiday','0','No late and undertime on HOLIDAYS','ams_policy','bool',NULL,4),
('ams_nolate_leave','1','No late and undertime fall on LEAVE schedule','ams_policy','bool',NULL,6),
('ams_nolate_restday','0','No late and undertime during Day-Off on duty','ams_policy','bool',NULL,5),
('ams_np_from_logs','0','TimeIN and TimeOUT computation for Night Prem.','ams_config','bool',NULL,12),
('ams_short_absent','0','Rendered hrs below allocated SHIFT hrs as ABSENT','ams_policy','bool',NULL,7),
('ams_short_ut','0','Rendered hrs below allocated SHIFT hrs as UNDERTIME','ams_policy','bool',NULL,8),
('ams_six_logs','0','Use 6 logs per day (Time In, Break Out 1, Break In 1, Break Out 2, Break In 2, Time Out)','ams_config','bool',NULL,16),
('ams_tardy_period_total','0','Total all tardiness per cut-off period','ams_config','bool',NULL,5),
('ams_tardy_week_total','0','Total all tardiness per WEEK per cut-off period','ams_config','bool',NULL,4),
('auto_break_deduct','1','Deduct shift break when no break punches','attendance','bool',NULL,8),
('company_address','Makati City, Metro Manila','Company address','general','text',NULL,2),
('company_name','Sample Holdings Corporation','Company name','general','text',NULL,1),
('company_tin','000-000-000-000','Company TIN','general','text',NULL,3),
('db_version','21','Database version','system','hidden',NULL,0),
('default_conversion_factor','26','Monthly - daily conversion factor (default)','payroll_params','number',NULL,1),
('default_days_per_year','261','Default days-per-year factor','payroll','number','261 (5-day week), 313 (6-day week), 365 (paid rest days).',2),
('de_minimis_leave_days','12','Non-taxable monetized unused VL (days/year)','payroll','number','RR 29-2025.',9),
('discipline_lookback_months','12','Rolling period for counting prior offenses (months)','discipline','number',NULL,1),
('emp_import_default_password','Welcome2026','Temporary password of portal accounts created by the employee CSV import','general','text','Employees must change it at their first sign-in.',20),
('final_pay_days','30','Final pay release period (days from separation)','payroll','number','DOLE Labor Advisory No. 06-2020.',11),
('geofence_mode','flag','Web check-in outside geofence','attendance','select:flag=Accept but flag for HR review,reject=Reject check-in',NULL,11),
('gl_13th','5040','GL: 13th month pay','payroll_annual','text',NULL,14),
('gl_advances','1150','GL: Advances / receivables from employees','payroll_annual','text',NULL,23),
('gl_allow','5050','GL: Allowances & benefits','payroll_annual','text',NULL,15),
('gl_er_contrib','5060','GL: Employer contributions expense','payroll_annual','text',NULL,16),
('gl_hdmf_loan','2150','GL: Pag-IBIG loans payable','payroll_annual','text',NULL,22),
('gl_hdmf_pay','2120','GL: Pag-IBIG payable','payroll_annual','text',NULL,19),
('gl_net_pay','2200','GL: Accrued payroll (net pay)','payroll_annual','text',NULL,24),
('gl_np','5030','GL: Night premium','payroll_annual','text',NULL,13),
('gl_ot','5010','GL: Overtime pay','payroll_annual','text',NULL,11),
('gl_phic_pay','2110','GL: PhilHealth payable','payroll_annual','text',NULL,18),
('gl_premium','5020','GL: Holiday / premium pay','payroll_annual','text',NULL,12),
('gl_salaries','5000','GL: Salaries and wages','payroll_annual','text',NULL,10),
('gl_sss_loan','2140','GL: SSS loans payable','payroll_annual','text',NULL,21),
('gl_sss_pay','2100','GL: SSS payable','payroll_annual','text',NULL,17),
('gl_wtax_pay','2130','GL: Withholding tax payable','payroll_annual','text',NULL,20),
('grace_minutes','5','Grace period (minutes)','attendance','number','Tardiness within the grace period is not deducted.',1),
('habitual_tardy_count','5','Habitual tardiness: times late per month','attendance','number','Flags employee for HR review / NTE, and lists the employee in the To-do\'s of the dashboard of the immediate superior and HR.',9),
('hdmf_global_ee_pct','0','HDMF: global additional employee contribution rate (%)','payroll_params','number',NULL,5),
('hdmf_max_ee','200','HDMF: maximum employee contribution (monthly)','payroll_params','number',NULL,4),
('hdmf_min_gross','0','HDMF: minimum monthly gross salary subject to contribution','payroll_params','number',NULL,2),
('hdmf_rate_pct','2','HDMF: employee percent rate','payroll_params','number',NULL,3),
('holiday_pay_daily_rated','1','Pay unworked regular holidays to daily-rated employees','payroll','bool','Labor Code Art. 94.',4),
('hours_per_day','8','Normal hours per day','payroll','number',NULL,1),
('hr_notify_email','hr@example.com','HR approver email (leave chain HR step)','mail','text','Used when no HR user account has an email address.',9),
('late_deduction_mode','per_minute','Late/undertime deduction basis','attendance','select:per_minute=Per minute (hourly rate / 60 x minutes),bracket=Lates/Undertime bracket table',NULL,3),
('late_grace_mode','full','When late beyond grace, deduct','attendance','select:full=All minutes late,excess=Only minutes beyond grace',NULL,2),
('leave_token_days','7','Email approval link validity (days)','mail','number',NULL,10),
('loan_request_max_terms','48','Loan / Deduction Request: longest term an employee may apply for (paydays)','payroll','number','Paydays; the approver may still approve another term.',40),
('mail_from','hris@example.com','Sender email','mail','text',NULL,7),
('mail_from_name','G-SMaRT HRIS Notifications','Sender name','mail','text',NULL,8),
('mail_mode','log','Email delivery mode','mail','select:log=Log only (Outbox page),smtp=SMTP server,mail=PHP mail()','\"Log only\" records emails in Admin > Email Outbox without sending (for local testing).',1),
('meal_comp_ot_rate','0','Meal compensation OT rate (per count)','payroll_params','number',NULL,13),
('meal_comp_rate','0','Meal compensation rate (per count)','payroll_params','number',NULL,12),
('min_net_pay_amount','1','Employee\'s take-home pay: earning not below (PHP)','payroll_params','number',NULL,10),
('min_net_pay_pct','20','Minimum take-home pay (% of gross) before voluntary deductions are deferred','payroll','number','Wage protection policy (Labor Code Art. 113). Statutory deductions are never deferred.',6),
('nd_end','06:00','Night differential window end','attendance','time',NULL,7),
('nd_rate_pct','10','Night shift differential (% of hourly rate)','payroll','number','Labor Code Art. 86: not less than 10%.',3),
('nd_start','22:00','Night differential window start','attendance','time',NULL,6),
('nontax_benefit_ceiling','90000','Non-taxable 13th month & other benefits ceiling','payroll','number','TRAIN Law: PHP 90,000.',5),
('nte_min_days','5','Minimum days to submit written explanation','discipline','number','Twin-notice rule: reasonable opportunity, not less than 5 calendar days.',2),
('ot_min_minutes','30','Minimum overtime counted (minutes)','attendance','number',NULL,4),
('ot_requires_approval','1','Overtime requires approved OT authorization','attendance','bool',NULL,5),
('ot_taxable','1','Overtime earnings taxable','payroll_params','bool',NULL,7),
('pagibig_voluntary_allowed','1','Allow voluntary higher Pag-IBIG contributions','payroll','bool',NULL,8),
('payroll_bank_code','','Bank account code (company payroll account)','payroll_params','text',NULL,6),
('payslip_copy_label','1','Mark reprinted payslips as ( COPY )','payslip','bool',NULL,5),
('payslip_disclaimer','Please examine your payslip. You are responsible for checking and validating the details contained in this payslip. Failure to report any discrepancy in the information above within 30 days shall mean that you acknowledge that all information herein is correct.','Disclaimer','payslip','textarea',NULL,9),
('payslip_note','This is a system-generated payslip.','Payslip footer note','payroll','text',NULL,12),
('payslip_per_page','2','Payslips per printed page (batch printing)','payslip','select:1=1 per page,2=2 per page',NULL,6),
('payslip_show_balances','1','Show outstanding loan balances','payslip','bool',NULL,3),
('payslip_show_hours','1','Show hours beside earnings','payslip','bool',NULL,4),
('payslip_show_ytd','1','Show YTD information','payslip','bool',NULL,2),
('payslip_sig_label','HR Manager','Signature label','payslip','text',NULL,7),
('payslip_sig_name','','Signatory name (optional)','payslip','text',NULL,8),
('payslip_title','','Payslip heading (blank = company name)','payslip','text',NULL,1),
('perm_catalog_hash','62d46e3bc6e10509b3b66a60b8fc8883','Permission catalog','system','hidden',NULL,0),
('perm_legacy_converted','1','Legacy access converted','system','hidden',NULL,0),
('punch_min_interval','2','Ignore repeated web punches within (minutes)','attendance','number',NULL,13),
('report_logo_enabled','0','Add Company Logo to Reports','branding','bool','System Settings, Company logo on reports',1),
('report_logo_path','','Company logo file','branding','text','',3),
('report_logo_reports','','Reports with the company logo','branding','text','all, or the routes of the reports',2),
('selfie_required','1','Require selfie (facial capture) on web check-in','attendance','bool',NULL,12),
('sig_approved_name','','Approved by — name','signatory','text',NULL,5),
('sig_approved_title','General Manager','Approved by — position','signatory','text',NULL,6),
('sig_checked_name','','Checked by — name','signatory','text',NULL,3),
('sig_checked_title','HR Manager','Checked by — position','signatory','text',NULL,4),
('sig_prepared_name','','Prepared by — name','signatory','text',NULL,1),
('sig_prepared_title','Payroll Officer','Prepared by — position','signatory','text',NULL,2),
('smtp_host','smtp.gmail.com','SMTP host','mail','text',NULL,2),
('smtp_pass','','SMTP password / app password','mail','password',NULL,6),
('smtp_port','587','SMTP port','mail','number',NULL,3),
('smtp_secure','tls','SMTP encryption','mail','select:tls=STARTTLS,ssl=SSL/TLS,none=None',NULL,4),
('smtp_user','','SMTP username','mail','text',NULL,5),
('special_max_nontax','0','Special earnings maximum non-taxable amount (per period)','payroll_params','number',NULL,11),
('sss_lookup','monthly_rate','SSS premium contribution lookup','payroll_params','select:monthly_rate=Monthly rate,gross=Monthly gross earnings',NULL,9),
('system_name','G-SMaRT HRIS','System name','general','text','Shown on the sign-in page, the sidebar, the browser tab, the footer, the manual and the e-mails.',1),
('tardy_cap_minutes','120','Tardiness cap per month (minutes) before disciplinary referral','attendance','number','Also listed in the NTE To-do\'s of the dashboard of the immediate superior and HR.',10),
('taxable_base','gross_less_govt','Taxable earnings computed based on','payroll_params','select:gross_less_govt=Gross - Govt. deductions,basic_less_govt=Basic - Govt. deductions,gross=Gross',NULL,8),
('thirteenth_basis','calendar','Basis of the 13th month computation','payroll_13th','select:calendar=Calendar Year,fiscal=Fiscal Year','Calendar Year: January to December; Fiscal Year: From / To dates of exactly one year',1),
('thirteenth_deadline','12-24','13th month pay deadline (MM-DD)','payroll','text','PD 851: on or before December 24.',10),
('thirteenth_fy_from','','Fiscal year of the 13th month: From date','payroll_13th','text','YYYY-MM-DD',2),
('thirteenth_fy_to','','Fiscal year of the 13th month: To date','payroll_13th','text','YYYY-MM-DD, exactly one year after the From date less a day',3),
('thirteenth_include_leave','1','13th month basis includes paid leaves','payroll_annual','bool',NULL,1),
('ticket_rate','0','Meal ticket price (deducted per ticket)','payroll_params','number',NULL,14),
('underperform_threshold','2.50','Underperformance threshold (overall rating)','discipline','number','Two consecutive cycles below this refer the employee to the disciplinary workflow.',3),
('year_end_adjust','1','Year-end tax annualization on the last regular payroll of December','payroll_annual','bool',NULL,2);
DROP TABLE IF EXISTS `shifts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `shifts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `time_in` time DEFAULT NULL COMMENT 'NULL on a flexible shift',
  `time_out` time DEFAULT NULL COMMENT 'NULL on a flexible shift',
  `break_minutes` int(11) NOT NULL DEFAULT 60,
  `grace_minutes` int(11) DEFAULT NULL COMMENT 'NULL = use global grace period',
  `rest_days` varchar(20) NOT NULL DEFAULT '0' COMMENT 'Comma list of weekday numbers, 0=Sun .. 6=Sat',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `break_out1` time DEFAULT NULL,
  `break_in1` time DEFAULT NULL,
  `break_out2` time DEFAULT NULL,
  `break_in2` time DEFAULT NULL,
  `required_hours` decimal(5,2) DEFAULT NULL COMMENT 'Total required duty hours (NULL = normal hours per day)',
  `np_hours` decimal(5,2) DEFAULT NULL COMMENT 'Total night premium hours within the shift',
  `flex_minutes` int(11) NOT NULL DEFAULT 0 COMMENT 'Flexible time-in allowance (+/- minutes)',
  `strict_time` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'No grace period on this shift',
  `whole_week` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Whole-week duty: rest days only from employee schedules',
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `logs_per_day` tinyint(4) NOT NULL DEFAULT 0 COMMENT 'Logs per day: 0 = from the breaks, 2, 4 or 6',
  `ot_in` time DEFAULT NULL COMMENT 'Overtime In: start of the pre-arranged overtime after the shift',
  `ot_out` time DEFAULT NULL COMMENT 'Overtime Out: end of the pre-arranged overtime',
  `flex_type` tinyint(4) NOT NULL DEFAULT 0 COMMENT 'Shift type: 0 fixed, 1 flexible 4 logs, 2 flexible 2 logs with break minutes, 3 flexible 2 logs no break',
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `shifts` VALUES
(1,'DAY-MF','Day Shift 8AM-5PM (Mon-Fri)','08:00:00','17:00:00',60,NULL,'0,6',1,NULL,NULL,NULL,NULL,NULL,NULL,0,0,0,1,4,NULL,NULL,0),
(2,'DAY-MS','Day Shift 8AM-5PM (Mon-Sat)','08:00:00','17:00:00',60,NULL,'0',1,NULL,NULL,NULL,NULL,NULL,NULL,0,0,0,0,4,NULL,NULL,0),
(3,'MID-MF','Mid Shift 2PM-11PM (Mon-Fri)','14:00:00','23:00:00',60,NULL,'0,6',1,NULL,NULL,NULL,NULL,NULL,NULL,0,0,0,0,4,NULL,NULL,0),
(4,'NGT-MF','Night Shift 10PM-7AM (Mon-Fri)','22:00:00','07:00:00',60,NULL,'0,6',1,NULL,NULL,NULL,NULL,NULL,NULL,0,0,0,0,4,NULL,NULL,0);
DROP TABLE IF EXISTS `sss_table`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sss_table` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `range_from` decimal(12,2) NOT NULL,
  `range_to` decimal(12,2) DEFAULT NULL,
  `msc_regular` decimal(12,2) NOT NULL,
  `msc_mpf` decimal(12,2) NOT NULL DEFAULT 0.00,
  `ee_regular` decimal(10,2) NOT NULL,
  `ee_mpf` decimal(10,2) NOT NULL DEFAULT 0.00,
  `er_regular` decimal(10,2) NOT NULL,
  `er_mpf` decimal(10,2) NOT NULL DEFAULT 0.00,
  `ec` decimal(10,2) NOT NULL DEFAULT 0.00,
  `effective_from` date NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sss` (`effective_from`,`range_from`)
) ENGINE=InnoDB AUTO_INCREMENT=62 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `sss_table` VALUES
(1,0.00,5249.99,5000.00,0.00,250.00,0.00,500.00,0.00,10.00,'2025-01-01'),
(2,5250.00,5749.99,5500.00,0.00,275.00,0.00,550.00,0.00,10.00,'2025-01-01'),
(3,5750.00,6249.99,6000.00,0.00,300.00,0.00,600.00,0.00,10.00,'2025-01-01'),
(4,6250.00,6749.99,6500.00,0.00,325.00,0.00,650.00,0.00,10.00,'2025-01-01'),
(5,6750.00,7249.99,7000.00,0.00,350.00,0.00,700.00,0.00,10.00,'2025-01-01'),
(6,7250.00,7749.99,7500.00,0.00,375.00,0.00,750.00,0.00,10.00,'2025-01-01'),
(7,7750.00,8249.99,8000.00,0.00,400.00,0.00,800.00,0.00,10.00,'2025-01-01'),
(8,8250.00,8749.99,8500.00,0.00,425.00,0.00,850.00,0.00,10.00,'2025-01-01'),
(9,8750.00,9249.99,9000.00,0.00,450.00,0.00,900.00,0.00,10.00,'2025-01-01'),
(10,9250.00,9749.99,9500.00,0.00,475.00,0.00,950.00,0.00,10.00,'2025-01-01'),
(11,9750.00,10249.99,10000.00,0.00,500.00,0.00,1000.00,0.00,10.00,'2025-01-01'),
(12,10250.00,10749.99,10500.00,0.00,525.00,0.00,1050.00,0.00,10.00,'2025-01-01'),
(13,10750.00,11249.99,11000.00,0.00,550.00,0.00,1100.00,0.00,10.00,'2025-01-01'),
(14,11250.00,11749.99,11500.00,0.00,575.00,0.00,1150.00,0.00,10.00,'2025-01-01'),
(15,11750.00,12249.99,12000.00,0.00,600.00,0.00,1200.00,0.00,10.00,'2025-01-01'),
(16,12250.00,12749.99,12500.00,0.00,625.00,0.00,1250.00,0.00,10.00,'2025-01-01'),
(17,12750.00,13249.99,13000.00,0.00,650.00,0.00,1300.00,0.00,10.00,'2025-01-01'),
(18,13250.00,13749.99,13500.00,0.00,675.00,0.00,1350.00,0.00,10.00,'2025-01-01'),
(19,13750.00,14249.99,14000.00,0.00,700.00,0.00,1400.00,0.00,10.00,'2025-01-01'),
(20,14250.00,14749.99,14500.00,0.00,725.00,0.00,1450.00,0.00,10.00,'2025-01-01'),
(21,14750.00,15249.99,15000.00,0.00,750.00,0.00,1500.00,0.00,30.00,'2025-01-01'),
(22,15250.00,15749.99,15500.00,0.00,775.00,0.00,1550.00,0.00,30.00,'2025-01-01'),
(23,15750.00,16249.99,16000.00,0.00,800.00,0.00,1600.00,0.00,30.00,'2025-01-01'),
(24,16250.00,16749.99,16500.00,0.00,825.00,0.00,1650.00,0.00,30.00,'2025-01-01'),
(25,16750.00,17249.99,17000.00,0.00,850.00,0.00,1700.00,0.00,30.00,'2025-01-01'),
(26,17250.00,17749.99,17500.00,0.00,875.00,0.00,1750.00,0.00,30.00,'2025-01-01'),
(27,17750.00,18249.99,18000.00,0.00,900.00,0.00,1800.00,0.00,30.00,'2025-01-01'),
(28,18250.00,18749.99,18500.00,0.00,925.00,0.00,1850.00,0.00,30.00,'2025-01-01'),
(29,18750.00,19249.99,19000.00,0.00,950.00,0.00,1900.00,0.00,30.00,'2025-01-01'),
(30,19250.00,19749.99,19500.00,0.00,975.00,0.00,1950.00,0.00,30.00,'2025-01-01'),
(31,19750.00,20249.99,20000.00,0.00,1000.00,0.00,2000.00,0.00,30.00,'2025-01-01'),
(32,20250.00,20749.99,20000.00,500.00,1000.00,25.00,2000.00,50.00,30.00,'2025-01-01'),
(33,20750.00,21249.99,20000.00,1000.00,1000.00,50.00,2000.00,100.00,30.00,'2025-01-01'),
(34,21250.00,21749.99,20000.00,1500.00,1000.00,75.00,2000.00,150.00,30.00,'2025-01-01'),
(35,21750.00,22249.99,20000.00,2000.00,1000.00,100.00,2000.00,200.00,30.00,'2025-01-01'),
(36,22250.00,22749.99,20000.00,2500.00,1000.00,125.00,2000.00,250.00,30.00,'2025-01-01'),
(37,22750.00,23249.99,20000.00,3000.00,1000.00,150.00,2000.00,300.00,30.00,'2025-01-01'),
(38,23250.00,23749.99,20000.00,3500.00,1000.00,175.00,2000.00,350.00,30.00,'2025-01-01'),
(39,23750.00,24249.99,20000.00,4000.00,1000.00,200.00,2000.00,400.00,30.00,'2025-01-01'),
(40,24250.00,24749.99,20000.00,4500.00,1000.00,225.00,2000.00,450.00,30.00,'2025-01-01'),
(41,24750.00,25249.99,20000.00,5000.00,1000.00,250.00,2000.00,500.00,30.00,'2025-01-01'),
(42,25250.00,25749.99,20000.00,5500.00,1000.00,275.00,2000.00,550.00,30.00,'2025-01-01'),
(43,25750.00,26249.99,20000.00,6000.00,1000.00,300.00,2000.00,600.00,30.00,'2025-01-01'),
(44,26250.00,26749.99,20000.00,6500.00,1000.00,325.00,2000.00,650.00,30.00,'2025-01-01'),
(45,26750.00,27249.99,20000.00,7000.00,1000.00,350.00,2000.00,700.00,30.00,'2025-01-01'),
(46,27250.00,27749.99,20000.00,7500.00,1000.00,375.00,2000.00,750.00,30.00,'2025-01-01'),
(47,27750.00,28249.99,20000.00,8000.00,1000.00,400.00,2000.00,800.00,30.00,'2025-01-01'),
(48,28250.00,28749.99,20000.00,8500.00,1000.00,425.00,2000.00,850.00,30.00,'2025-01-01'),
(49,28750.00,29249.99,20000.00,9000.00,1000.00,450.00,2000.00,900.00,30.00,'2025-01-01'),
(50,29250.00,29749.99,20000.00,9500.00,1000.00,475.00,2000.00,950.00,30.00,'2025-01-01'),
(51,29750.00,30249.99,20000.00,10000.00,1000.00,500.00,2000.00,1000.00,30.00,'2025-01-01'),
(52,30250.00,30749.99,20000.00,10500.00,1000.00,525.00,2000.00,1050.00,30.00,'2025-01-01'),
(53,30750.00,31249.99,20000.00,11000.00,1000.00,550.00,2000.00,1100.00,30.00,'2025-01-01'),
(54,31250.00,31749.99,20000.00,11500.00,1000.00,575.00,2000.00,1150.00,30.00,'2025-01-01'),
(55,31750.00,32249.99,20000.00,12000.00,1000.00,600.00,2000.00,1200.00,30.00,'2025-01-01'),
(56,32250.00,32749.99,20000.00,12500.00,1000.00,625.00,2000.00,1250.00,30.00,'2025-01-01'),
(57,32750.00,33249.99,20000.00,13000.00,1000.00,650.00,2000.00,1300.00,30.00,'2025-01-01'),
(58,33250.00,33749.99,20000.00,13500.00,1000.00,675.00,2000.00,1350.00,30.00,'2025-01-01'),
(59,33750.00,34249.99,20000.00,14000.00,1000.00,700.00,2000.00,1400.00,30.00,'2025-01-01'),
(60,34250.00,34749.99,20000.00,14500.00,1000.00,725.00,2000.00,1450.00,30.00,'2025-01-01'),
(61,34750.00,NULL,20000.00,15000.00,1000.00,750.00,2000.00,1500.00,30.00,'2025-01-01');
DROP TABLE IF EXISTS `tax_brackets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tax_brackets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `frequency` enum('daily','weekly','semi_monthly','monthly','annual') NOT NULL,
  `bracket_no` tinyint(4) NOT NULL,
  `comp_from` decimal(14,2) NOT NULL,
  `comp_to` decimal(14,2) DEFAULT NULL COMMENT 'NULL = and above',
  `fixed_tax` decimal(14,2) NOT NULL DEFAULT 0.00,
  `rate_pct` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Rate on the excess over comp_from',
  `effective_from` date NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tax` (`frequency`,`effective_from`,`comp_from`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `tax_brackets` VALUES
(1,'daily',1,0.00,685.00,0.00,0.00,'2023-01-01'),
(2,'daily',2,685.00,1095.00,0.00,15.00,'2023-01-01'),
(3,'daily',3,1096.00,2191.00,61.65,20.00,'2023-01-01'),
(4,'daily',4,2192.00,5478.00,280.85,25.00,'2023-01-01'),
(5,'daily',5,5479.00,21917.00,1102.60,30.00,'2023-01-01'),
(6,'daily',6,21918.00,NULL,6034.30,35.00,'2023-01-01'),
(7,'weekly',1,0.00,4808.00,0.00,0.00,'2023-01-01'),
(8,'weekly',2,4808.00,7691.00,0.00,15.00,'2023-01-01'),
(9,'weekly',3,7692.00,15384.00,432.60,20.00,'2023-01-01'),
(10,'weekly',4,15385.00,38461.00,1971.20,25.00,'2023-01-01'),
(11,'weekly',5,38462.00,153845.00,7740.45,30.00,'2023-01-01'),
(12,'weekly',6,153846.00,NULL,42355.65,35.00,'2023-01-01'),
(13,'semi_monthly',1,0.00,10417.00,0.00,0.00,'2023-01-01'),
(14,'semi_monthly',2,10417.00,16666.00,0.00,15.00,'2023-01-01'),
(15,'semi_monthly',3,16667.00,33332.00,937.50,20.00,'2023-01-01'),
(16,'semi_monthly',4,33333.00,83332.00,4270.70,25.00,'2023-01-01'),
(17,'semi_monthly',5,83333.00,333332.00,16770.70,30.00,'2023-01-01'),
(18,'semi_monthly',6,333333.00,NULL,91770.70,35.00,'2023-01-01'),
(19,'monthly',1,0.00,20833.00,0.00,0.00,'2023-01-01'),
(20,'monthly',2,20833.00,33332.00,0.00,15.00,'2023-01-01'),
(21,'monthly',3,33333.00,66666.00,1875.00,20.00,'2023-01-01'),
(22,'monthly',4,66667.00,166666.00,8541.80,25.00,'2023-01-01'),
(23,'monthly',5,166667.00,666666.00,33541.80,30.00,'2023-01-01'),
(24,'monthly',6,666667.00,NULL,183541.80,35.00,'2023-01-01'),
(25,'annual',1,0.00,250000.00,0.00,0.00,'2023-01-01'),
(26,'annual',2,250000.00,400000.00,0.00,15.00,'2023-01-01'),
(27,'annual',3,400000.00,800000.00,22500.00,20.00,'2023-01-01'),
(28,'annual',4,800000.00,2000000.00,102500.00,25.00,'2023-01-01'),
(29,'annual',5,2000000.00,8000000.00,402500.00,30.00,'2023-01-01'),
(30,'annual',6,8000000.00,NULL,2202500.00,35.00,'2023-01-01');
DROP TABLE IF EXISTS `transaction_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transaction_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ledger_id` int(11) NOT NULL,
  `pay_date` date NOT NULL,
  `reference_no` varchar(40) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tp_led` (`ledger_id`),
  CONSTRAINT `fk_tp_led` FOREIGN KEY (`ledger_id`) REFERENCES `ledgers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `user_page_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_page_access` (
  `user_id` int(11) NOT NULL,
  `route` varchar(40) NOT NULL,
  `can_view` tinyint(1) NOT NULL DEFAULT 0,
  `can_add` tinyint(1) NOT NULL DEFAULT 0,
  `can_edit` tinyint(1) NOT NULL DEFAULT 0,
  `can_delete` tinyint(1) NOT NULL DEFAULT 0,
  `can_print` tinyint(1) NOT NULL DEFAULT 0,
  `can_approve` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`,`route`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `user_permission_overrides`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_permission_overrides` (
  `user_id` int(11) NOT NULL,
  `perm_key` varchar(80) NOT NULL,
  `effect` enum('allow','deny') NOT NULL,
  `created_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`user_id`,`perm_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `user_scopes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_scopes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `scope_type` enum('branch','org_unit') NOT NULL,
  `ref_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scope` (`user_id`,`scope_type`,`ref_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(60) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `role` enum('admin','hr','payroll','supervisor','employee') NOT NULL DEFAULT 'employee',
  `employee_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `module_access` varchar(500) DEFAULT NULL,
  `reg_status` varchar(10) DEFAULT NULL,
  `registered_at` datetime DEFAULT NULL,
  `reg_note` varchar(255) DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `is_support` tinyint(1) NOT NULL DEFAULT 0,
  `page_access` tinyint(1) NOT NULL DEFAULT 0,
  `role_id` int(11) DEFAULT NULL,
  `linked_from` varchar(80) DEFAULT NULL,
  `scope_all_branches` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Sees the employees of every company / branch',
  `scope_all_units` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Sees the employees of every department',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_users_emp` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `users` VALUES
(1,'admin','$2y$12$eJWqe8IXj4M2e1NaOLAhjewDaAbVyCrzNounwmkVIOGAYp/u38Cz6','System Administrator',NULL,'admin',NULL,1,1,NULL,'2026-11-11 20:23:48',NULL,NULL,NULL,NULL,NULL,NULL,0,0,1,NULL,1,1);

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


-- =====================================================================
--  2. MASTER DATABASE (multi-company)
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 0;
CREATE DATABASE IF NOT EXISTS `ihris_master` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;  -- MASTER DATABASE NAME
USE `ihris_master`;  -- MASTER DATABASE NAME

-- master_meta
DROP TABLE IF EXISTS `master_meta`;
CREATE TABLE master_meta (
  mkey VARCHAR(40) NOT NULL,
  mvalue VARCHAR(255) NULL,
  PRIMARY KEY (mkey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- companies
DROP TABLE IF EXISTS `companies`;
CREATE TABLE companies (
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
DROP TABLE IF EXISTS `company_codes`;
CREATE TABLE company_codes (
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
DROP TABLE IF EXISTS `company_memberships`;
CREATE TABLE company_memberships (
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
DROP TABLE IF EXISTS `super_admins`;
CREATE TABLE super_admins (
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
DROP TABLE IF EXISTS `company_provisioning_logs`;
CREATE TABLE company_provisioning_logs (
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
DROP TABLE IF EXISTS `master_audit_log`;
CREATE TABLE master_audit_log (
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
DROP TABLE IF EXISTS `company_user_links`;
CREATE TABLE company_user_links (
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
DROP TABLE IF EXISTS `join_attempts`;
CREATE TABLE join_attempts (
  id INT NOT NULL AUTO_INCREMENT,
  ip_address VARCHAR(45) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_join_ip (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
SET UNIQUE_CHECKS = 1;
-- end of file
