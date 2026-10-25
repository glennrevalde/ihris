-- =====================================================================
--  G-SMaRT HRIS : Integrated Human Resource Information System
--  Database (clean install: tables + reference data + admin account)
--  Target: MySQL 5.5.3 or newer (also MySQL 5.6 / 5.7 / 8.x, MariaDB 5.5+)
--  Generated: 2026-09-19 12:56
--
--  HOW TO IMPORT
--   phpMyAdmin : Import tab > choose this file > Go
--   Command    : mysql -u root -p < this_file.sql
--
--  This script creates the database `ihris` (change the two lines marked
--  "DATABASE NAME" to use another name) and DROPS and re-creates every
--  G-SMaRT HRIS table in it. Do not import it over a database with live data.
--
--  AFTER IMPORTING
--   1. The app connects with host 127.0.0.1, port 3306, user root, blank
--      password, database ihris. If yours differ, edit config/config.php.
--   2. Open http://localhost/ihris/ (the G-SMaRT HRIS folder, or its index.php).
--   3. Sign in:
--   admin / admin123 (you will be asked to change it on first sign-in)
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+08:00';
SET FOREIGN_KEY_CHECKS = 0;
SET UNIQUE_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `ihris` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;  -- DATABASE NAME
USE `ihris`;  -- DATABASE NAME

-- ---------------------------------------------------------------------
--  CORE / ADMIN
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  skey        VARCHAR(80) NOT NULL PRIMARY KEY,
  svalue      TEXT NULL,
  label       VARCHAR(160) NULL,
  grp         VARCHAR(40) NOT NULL DEFAULT 'general',
  input_type  VARCHAR(255) NOT NULL DEFAULT 'text',
  help        VARCHAR(255) NULL,
  sort_order  INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  username             VARCHAR(60) NOT NULL UNIQUE,
  password_hash        VARCHAR(255) NOT NULL,
  full_name            VARCHAR(150) NOT NULL,
  email                VARCHAR(150) NULL,
  role                 ENUM('admin','hr','payroll','supervisor','employee') NOT NULL DEFAULT 'employee',
  employee_id          INT NULL,
  is_active            TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  last_login           DATETIME NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_users_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `audit_log`;
CREATE TABLE `audit_log` (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NULL,
  username    VARCHAR(60) NULL,
  module      VARCHAR(30) NOT NULL DEFAULT 'system',
  action      VARCHAR(60) NOT NULL,
  entity      VARCHAR(60) NULL,
  entity_id   VARCHAR(40) NULL,
  details     TEXT NULL,
  ip_address  VARCHAR(45) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_entity (entity, entity_id),
  KEY idx_audit_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `email_log`;
CREATE TABLE `email_log` (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  to_email      VARCHAR(150) NOT NULL,
  to_name       VARCHAR(150) NULL,
  subject       VARCHAR(255) NOT NULL,
  body_html     MEDIUMTEXT NOT NULL,
  related_type  VARCHAR(40) NULL,
  related_id    INT NULL,
  status        ENUM('queued','sent','failed','logged') NOT NULL DEFAULT 'queued',
  error_message VARCHAR(500) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at       DATETIME NULL,
  KEY idx_email_rel (related_type, related_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
--  ORGANIZATION
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `branches`;
CREATE TABLE `branches` (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  code              VARCHAR(20) NOT NULL UNIQUE,
  name              VARCHAR(120) NOT NULL,
  company_name      VARCHAR(150) NOT NULL COMMENT 'Employer-of-record name',
  address           VARCHAR(255) NULL,
  tin               VARCHAR(30) NULL,
  rdo_code          VARCHAR(10) NULL,
  sss_er_no         VARCHAR(30) NULL,
  philhealth_er_no  VARCHAR(30) NULL,
  pagibig_er_no     VARCHAR(30) NULL,
  bank_name         VARCHAR(80) NULL,
  bank_account_no   VARCHAR(40) NULL,
  bank_company_code VARCHAR(20) NULL,
  latitude          DECIMAL(10,7) NULL,
  longitude         DECIMAL(10,7) NULL,
  geofence_radius_m INT NOT NULL DEFAULT 150,
  is_active         TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `pay_groups`;
CREATE TABLE `pay_groups` (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(20) NOT NULL UNIQUE,
  name          VARCHAR(100) NOT NULL,
  frequency     ENUM('daily','weekly','semi_monthly','monthly') NOT NULL DEFAULT 'semi_monthly',
  contrib_mode  ENUM('split','first','last') NOT NULL DEFAULT 'split' COMMENT 'How monthly statutory contributions are spread across cutoffs',
  description   VARCHAR(255) NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `org_units`;
CREATE TABLE `org_units` (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  parent_id             INT NULL,
  code                  VARCHAR(20) NOT NULL UNIQUE,
  name                  VARCHAR(120) NOT NULL,
  unit_type             VARCHAR(40) NOT NULL DEFAULT 'Department' COMMENT 'Division, Department, Section, Unit, ...',
  head_employee_id      INT NULL,
  default_pay_group_id  INT NULL,
  sort_order            INT NOT NULL DEFAULT 0,
  is_active             TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_ou_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `department_budgets`;
CREATE TABLE `department_budgets` (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  org_unit_id    INT NOT NULL,
  year           SMALLINT NOT NULL,
  month          TINYINT NOT NULL,
  budget_amount  DECIMAL(14,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_budget (org_unit_id, year, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
--  MODULE 1 : PERSONNEL INFORMATION SYSTEM
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `salary_grades`;
CREATE TABLE `salary_grades` (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(20) NOT NULL UNIQUE,
  position_band  VARCHAR(120) NOT NULL,
  minimum        DECIMAL(12,2) NOT NULL,
  midpoint       DECIMAL(12,2) NOT NULL,
  maximum        DECIMAL(12,2) NOT NULL,
  num_steps      TINYINT NOT NULL DEFAULT 5,
  sort_order     INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `salary_grade_steps`;
CREATE TABLE `salary_grade_steps` (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  grade_id  INT NOT NULL,
  step_no   TINYINT NOT NULL,
  amount    DECIMAL(12,2) NOT NULL,
  UNIQUE KEY uq_grade_step (grade_id, step_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `shifts`;
CREATE TABLE `shifts` (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(20) NOT NULL UNIQUE,
  name           VARCHAR(100) NOT NULL,
  time_in        TIME NOT NULL,
  time_out       TIME NOT NULL,
  break_minutes  INT NOT NULL DEFAULT 60,
  grace_minutes  INT NULL COMMENT 'NULL = use global grace period',
  rest_days      VARCHAR(20) NOT NULL DEFAULT '0' COMMENT 'Comma list of weekday numbers, 0=Sun .. 6=Sat',
  is_active      TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `employees`;
CREATE TABLE `employees` (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  employee_no         VARCHAR(20) NOT NULL UNIQUE,
  biometric_id        VARCHAR(20) NULL UNIQUE,
  last_name           VARCHAR(60) NOT NULL,
  first_name          VARCHAR(60) NOT NULL,
  middle_name         VARCHAR(60) NULL,
  suffix              VARCHAR(10) NULL,
  gender              ENUM('male','female') NOT NULL,
  birth_date          DATE NULL,
  civil_status        ENUM('single','married','widowed','separated','annulled') NOT NULL DEFAULT 'single',
  address             VARCHAR(255) NULL,
  contact_no          VARCHAR(40) NULL,
  email               VARCHAR(150) NULL,
  emergency_contact   VARCHAR(150) NULL,
  emergency_phone     VARCHAR(40) NULL,
  sss_no              VARCHAR(20) NULL,
  philhealth_no       VARCHAR(20) NULL,
  pagibig_no          VARCHAR(20) NULL,
  tin                 VARCHAR(20) NULL,
  date_hired          DATE NOT NULL,
  date_regularized    DATE NULL,
  employment_status   ENUM('probationary','regular','project_based','contractual') NOT NULL DEFAULT 'probationary',
  position            VARCHAR(100) NOT NULL,
  rank_level          VARCHAR(40) NULL,
  org_unit_id         INT NULL,
  salary_grade_id     INT NULL,
  salary_step         TINYINT NULL,
  rate_type           ENUM('monthly','daily') NOT NULL DEFAULT 'monthly',
  basic_rate          DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Monthly salary or daily rate depending on rate_type',
  days_per_year       SMALLINT NOT NULL DEFAULT 261 COMMENT 'Factor for daily/monthly rate conversion',
  pay_group_id        INT NOT NULL,
  default_branch_id   INT NOT NULL COMMENT 'Employer-of-record for statutory deductions',
  shift_id            INT NULL,
  supervisor_id       INT NULL,
  attendance_method   ENUM('biometric','web','both','import') NOT NULL DEFAULT 'biometric',
  timekeeping_exempt  TINYINT(1) NOT NULL DEFAULT 0,
  is_mwe              TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Minimum wage earner (tax exempt)',
  is_solo_parent      TINYINT(1) NOT NULL DEFAULT 0,
  solo_parent_id_no   VARCHAR(40) NULL,
  pagibig_voluntary   DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Voluntary additional EE contribution per month',
  bank_name           VARCHAR(80) NULL,
  bank_account_no     VARCHAR(40) NULL,
  photo_path          VARCHAR(255) NULL,
  status              ENUM('active','separated') NOT NULL DEFAULT 'active',
  date_separated      DATE NULL,
  separation_type     VARCHAR(40) NULL,
  separation_reason   VARCHAR(255) NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NULL,
  KEY idx_emp_ou (org_unit_id),
  KEY idx_emp_pg (pay_group_id),
  KEY idx_emp_branch (default_branch_id),
  KEY idx_emp_sup (supervisor_id),
  CONSTRAINT fk_emp_pg FOREIGN KEY (pay_group_id) REFERENCES pay_groups(id),
  CONSTRAINT fk_emp_branch FOREIGN KEY (default_branch_id) REFERENCES branches(id),
  CONSTRAINT fk_emp_ou FOREIGN KEY (org_unit_id) REFERENCES org_units(id) ON DELETE SET NULL,
  CONSTRAINT fk_emp_grade FOREIGN KEY (salary_grade_id) REFERENCES salary_grades(id) ON DELETE SET NULL,
  CONSTRAINT fk_emp_shift FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `employee_history`;
CREATE TABLE `employee_history` (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  employee_id     INT NOT NULL,
  change_type     ENUM('hire','promotion','transfer','salary_change','grade_change','status_change','branch_change','separation','other') NOT NULL,
  effective_date  DATE NOT NULL,
  old_position    VARCHAR(100) NULL,
  new_position    VARCHAR(100) NULL,
  old_org_unit    VARCHAR(120) NULL,
  new_org_unit    VARCHAR(120) NULL,
  old_rate        DECIMAL(12,2) NULL,
  new_rate        DECIMAL(12,2) NULL,
  rate_type       ENUM('monthly','daily') NULL,
  old_grade       VARCHAR(30) NULL,
  new_grade       VARCHAR(30) NULL,
  remarks         VARCHAR(500) NULL,
  created_by      INT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_hist_emp (employee_id, effective_date),
  CONSTRAINT fk_hist_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `employee_documents`;
CREATE TABLE `employee_documents` (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  employee_id    INT NOT NULL,
  doc_type       ENUM('pre_employment','contract','appraisal','government_id','clearance','memo','other') NOT NULL DEFAULT 'other',
  title          VARCHAR(150) NOT NULL,
  file_path      VARCHAR(255) NOT NULL,
  original_name  VARCHAR(255) NOT NULL,
  uploaded_by    INT NULL,
  uploaded_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_doc_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Leave management ------------------------------------------------------
DROP TABLE IF EXISTS `leave_types`;
CREATE TABLE `leave_types` (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  code                    VARCHAR(20) NOT NULL UNIQUE,
  name                    VARCHAR(100) NOT NULL,
  legal_basis             VARCHAR(150) NULL,
  accrual_method          ENUM('annual','monthly','service_based','per_event') NOT NULL DEFAULT 'annual',
  days_per_year           DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT 'Annual credit, or days per occurrence for per_event',
  count_basis             ENUM('workdays','calendar') NOT NULL DEFAULT 'workdays',
  service_step_years      TINYINT NULL COMMENT 'service_based: every N years of service ...',
  service_step_days       DECIMAL(5,2) NULL COMMENT '... add this many days',
  max_days                DECIMAL(6,2) NULL COMMENT 'service_based cap',
  max_carry_over          DECIMAL(6,2) NOT NULL DEFAULT 0,
  convertible             TINYINT(1) NOT NULL DEFAULT 0,
  max_convertible_days    DECIMAL(6,2) NULL,
  is_paid                 TINYINT(1) NOT NULL DEFAULT 1,
  gender                  ENUM('any','female','male') NOT NULL DEFAULT 'any',
  civil_status_req        VARCHAR(20) NULL COMMENT 'e.g. married for paternity',
  requires_solo_parent    TINYINT(1) NOT NULL DEFAULT 0,
  solo_parent_extra_days  DECIMAL(6,2) NOT NULL DEFAULT 0,
  max_events              SMALLINT NULL COMMENT 'Lifetime occurrences (e.g. paternity: 4)',
  min_service_months      SMALLINT NOT NULL DEFAULT 0,
  approval_chain          VARCHAR(60) NOT NULL DEFAULT 'supervisor,dept_head,hr',
  is_statutory            TINYINT(1) NOT NULL DEFAULT 0,
  is_active               TINYINT(1) NOT NULL DEFAULT 1,
  description             VARCHAR(500) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `leave_balances`;
CREATE TABLE `leave_balances` (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  employee_id     INT NOT NULL,
  leave_type_id   INT NOT NULL,
  year            SMALLINT NOT NULL,
  carried_over    DECIMAL(6,2) NOT NULL DEFAULT 0,
  earned          DECIMAL(6,2) NOT NULL DEFAULT 0,
  used            DECIMAL(6,2) NOT NULL DEFAULT 0,
  converted       DECIMAL(6,2) NOT NULL DEFAULT 0,
  forfeited       DECIMAL(6,2) NOT NULL DEFAULT 0,
  adjustment      DECIMAL(6,2) NOT NULL DEFAULT 0,
  closed          TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_bal (employee_id, leave_type_id, year),
  CONSTRAINT fk_bal_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_bal_type FOREIGN KEY (leave_type_id) REFERENCES leave_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `leave_accrual_log`;
CREATE TABLE `leave_accrual_log` (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  employee_id    INT NOT NULL,
  leave_type_id  INT NOT NULL,
  year           SMALLINT NOT NULL,
  month          TINYINT NOT NULL DEFAULT 0 COMMENT '0 = annual credit',
  days           DECIMAL(6,2) NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_accrual (employee_id, leave_type_id, year, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `leave_applications`;
CREATE TABLE `leave_applications` (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  app_no          VARCHAR(20) NOT NULL UNIQUE,
  employee_id     INT NOT NULL,
  leave_type_id   INT NOT NULL,
  date_from       DATE NOT NULL,
  date_to         DATE NOT NULL,
  days            DECIMAL(6,2) NOT NULL,
  reason          VARCHAR(500) NULL,
  attachment      VARCHAR(255) NULL,
  status          ENUM('pending','approved','returned','disallowed','cancelled') NOT NULL DEFAULT 'pending',
  current_step    TINYINT NOT NULL DEFAULT 1,
  decision_reason VARCHAR(500) NULL,
  filed_by        INT NULL,
  filed_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at      DATETIME NULL,
  KEY idx_la_emp (employee_id, date_from),
  CONSTRAINT fk_la_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_la_type FOREIGN KEY (leave_type_id) REFERENCES leave_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `leave_approval_steps`;
CREATE TABLE `leave_approval_steps` (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  application_id    INT NOT NULL,
  step_no           TINYINT NOT NULL,
  approver_role     ENUM('supervisor','dept_head','hr') NOT NULL,
  approver_user_id  INT NULL,
  approver_name     VARCHAR(150) NULL,
  approver_email    VARCHAR(150) NULL,
  token             VARCHAR(64) NULL UNIQUE,
  token_expires     DATETIME NULL,
  status            ENUM('waiting','pending','approved','returned','disallowed','skipped','cancelled') NOT NULL DEFAULT 'waiting',
  remarks           VARCHAR(500) NULL,
  emailed_at        DATETIME NULL,
  acted_at          DATETIME NULL,
  acted_via         ENUM('email','system') NULL,
  CONSTRAINT fk_las_app FOREIGN KEY (application_id) REFERENCES leave_applications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Evaluation -------------------------------------------------------------
DROP TABLE IF EXISTS `evaluation_cycles`;
CREATE TABLE `evaluation_cycles` (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  name               VARCHAR(100) NOT NULL,
  cycle_type         ENUM('semi_annual','annual') NOT NULL DEFAULT 'semi_annual',
  period_start       DATE NOT NULL,
  period_end         DATE NOT NULL,
  quant_weight       DECIMAL(5,2) NOT NULL DEFAULT 60,
  qual_weight        DECIMAL(5,2) NOT NULL DEFAULT 40,
  self_weight        DECIMAL(5,2) NOT NULL DEFAULT 20,
  supervisor_weight  DECIMAL(5,2) NOT NULL DEFAULT 60,
  peer_weight        DECIMAL(5,2) NOT NULL DEFAULT 20,
  status             ENUM('open','closed') NOT NULL DEFAULT 'open',
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `competencies`;
CREATE TABLE `competencies` (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(100) NOT NULL,
  description  VARCHAR(255) NULL,
  sort_order   INT NOT NULL DEFAULT 0,
  is_active    TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `merit_matrix`;
CREATE TABLE `merit_matrix` (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  min_score           DECIMAL(4,2) NOT NULL,
  rating_label        VARCHAR(40) NOT NULL,
  increase_pct        DECIMAL(5,2) NOT NULL DEFAULT 0,
  step_increase       TINYINT NOT NULL DEFAULT 0,
  promotion_eligible  TINYINT(1) NOT NULL DEFAULT 0,
  disciplinary_flag   TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `evaluations`;
CREATE TABLE `evaluations` (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  cycle_id              INT NOT NULL,
  employee_id           INT NOT NULL,
  evaluator_employee_id INT NULL,
  status                ENUM('draft','submitted','finalized') NOT NULL DEFAULT 'draft',
  quant_pct             DECIMAL(7,2) NULL,
  quant_score           DECIMAL(4,2) NULL,
  qual_score            DECIMAL(4,2) NULL,
  overall_score         DECIMAL(4,2) NULL,
  rating_label          VARCHAR(40) NULL,
  merit_increase_pct    DECIMAL(5,2) NULL,
  step_increase         TINYINT NULL,
  promotion_eligible    TINYINT(1) NULL,
  disciplinary_referral TINYINT(1) NOT NULL DEFAULT 0,
  recommendation        VARCHAR(1000) NULL,
  remarks               VARCHAR(1000) NULL,
  applied_at            DATETIME NULL,
  finalized_at          DATETIME NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_eval (cycle_id, employee_id),
  CONSTRAINT fk_eval_cycle FOREIGN KEY (cycle_id) REFERENCES evaluation_cycles(id) ON DELETE CASCADE,
  CONSTRAINT fk_eval_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `evaluation_kpis`;
CREATE TABLE `evaluation_kpis` (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  evaluation_id    INT NOT NULL,
  kpi_name         VARCHAR(150) NOT NULL,
  weight           DECIMAL(6,2) NOT NULL,
  target           DECIMAL(14,2) NOT NULL,
  actual           DECIMAL(14,2) NULL,
  lower_is_better  TINYINT(1) NOT NULL DEFAULT 0,
  achievement_pct  DECIMAL(7,2) NULL,
  CONSTRAINT fk_kpi_eval FOREIGN KEY (evaluation_id) REFERENCES evaluations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `evaluation_ratings`;
CREATE TABLE `evaluation_ratings` (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  evaluation_id  INT NOT NULL,
  competency_id  INT NOT NULL,
  rater_type     ENUM('self','supervisor','peer') NOT NULL,
  rater_name     VARCHAR(150) NULL,
  rating         TINYINT NOT NULL,
  comment        VARCHAR(500) NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rat_eval FOREIGN KEY (evaluation_id) REFERENCES evaluations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Code of conduct & discipline -------------------------------------------
DROP TABLE IF EXISTS `conduct_offenses`;
CREATE TABLE `conduct_offenses` (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(20) NOT NULL UNIQUE,
  category     VARCHAR(120) NOT NULL,
  description  VARCHAR(500) NULL,
  severity     ENUM('light','less_grave','grave') NOT NULL DEFAULT 'light',
  is_active    TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `conduct_penalties`;
CREATE TABLE `conduct_penalties` (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  offense_id    INT NOT NULL,
  offense_no    TINYINT NOT NULL,
  penalty_type  ENUM('verbal_warning','written_warning','suspension','dismissal') NOT NULL,
  min_days      TINYINT NULL,
  max_days      TINYINT NULL,
  notes         VARCHAR(255) NULL,
  UNIQUE KEY uq_pen (offense_id, offense_no),
  CONSTRAINT fk_pen_off FOREIGN KEY (offense_id) REFERENCES conduct_offenses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `disciplinary_cases`;
CREATE TABLE `disciplinary_cases` (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  case_no              VARCHAR(20) NOT NULL UNIQUE,
  employee_id          INT NOT NULL,
  offense_id           INT NOT NULL,
  incident_date        DATE NOT NULL,
  incident_details     TEXT NOT NULL,
  policy_provision     VARCHAR(500) NULL,
  offense_count        TINYINT NOT NULL DEFAULT 1,
  suggested_penalty    VARCHAR(150) NULL,
  source               ENUM('manual','attendance','evaluation') NOT NULL DEFAULT 'manual',
  nte_date             DATE NULL,
  explanation_due      DATE NULL,
  explanation_text     TEXT NULL,
  explanation_date     DATE NULL,
  hearing_date         DATETIME NULL,
  hearing_venue        VARCHAR(150) NULL,
  hearing_minutes      TEXT NULL,
  evaluation_notes     TEXT NULL,
  decision             ENUM('exonerated','verbal_warning','written_warning','suspension','dismissal') NULL,
  decision_grounds     TEXT NULL,
  suspension_days      TINYINT NULL,
  suspension_start     DATE NULL,
  effectivity_date     DATE NULL,
  nod_date             DATE NULL,
  status               ENUM('draft','nte_issued','explanation_received','hearing_scheduled','for_decision','decided','closed') NOT NULL DEFAULT 'draft',
  created_by           INT NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_case_emp (employee_id, offense_id),
  CONSTRAINT fk_case_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_case_off FOREIGN KEY (offense_id) REFERENCES conduct_offenses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `case_events`;
CREATE TABLE `case_events` (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  case_id     INT NOT NULL,
  stage       VARCHAR(40) NOT NULL,
  notes       TEXT NULL,
  user_id     INT NULL,
  username    VARCHAR(60) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ce_case FOREIGN KEY (case_id) REFERENCES disciplinary_cases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `case_attachments`;
CREATE TABLE `case_attachments` (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  case_id        INT NOT NULL,
  title          VARCHAR(150) NOT NULL,
  file_path      VARCHAR(255) NOT NULL,
  original_name  VARCHAR(255) NOT NULL,
  uploaded_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ca_case FOREIGN KEY (case_id) REFERENCES disciplinary_cases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
--  MODULE 2 : PAYROLL — statutory reference tables (editable data)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `tax_brackets`;
CREATE TABLE `tax_brackets` (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  frequency       ENUM('daily','weekly','semi_monthly','monthly','annual') NOT NULL,
  bracket_no      TINYINT NOT NULL,
  comp_from       DECIMAL(14,2) NOT NULL,
  comp_to         DECIMAL(14,2) NULL COMMENT 'NULL = and above',
  fixed_tax       DECIMAL(14,2) NOT NULL DEFAULT 0,
  rate_pct        DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'Rate on the excess over comp_from',
  effective_from  DATE NOT NULL,
  KEY idx_tax (frequency, effective_from, comp_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `sss_table`;
CREATE TABLE `sss_table` (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  range_from      DECIMAL(12,2) NOT NULL,
  range_to        DECIMAL(12,2) NULL,
  msc_regular     DECIMAL(12,2) NOT NULL,
  msc_mpf         DECIMAL(12,2) NOT NULL DEFAULT 0,
  ee_regular      DECIMAL(10,2) NOT NULL,
  ee_mpf          DECIMAL(10,2) NOT NULL DEFAULT 0,
  er_regular      DECIMAL(10,2) NOT NULL,
  er_mpf          DECIMAL(10,2) NOT NULL DEFAULT 0,
  ec              DECIMAL(10,2) NOT NULL DEFAULT 0,
  effective_from  DATE NOT NULL,
  KEY idx_sss (effective_from, range_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `philhealth_params`;
CREATE TABLE `philhealth_params` (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  effective_from  DATE NOT NULL,
  rate_pct        DECIMAL(5,2) NOT NULL,
  floor_salary    DECIMAL(12,2) NOT NULL,
  ceiling_salary  DECIMAL(12,2) NOT NULL,
  ee_share_pct    DECIMAL(5,2) NOT NULL DEFAULT 50,
  legal_basis     VARCHAR(150) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `pagibig_table`;
CREATE TABLE `pagibig_table` (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  range_from      DECIMAL(12,2) NOT NULL,
  range_to        DECIMAL(12,2) NULL,
  ee_rate_pct     DECIMAL(5,2) NOT NULL,
  er_rate_pct     DECIMAL(5,2) NOT NULL,
  max_comp        DECIMAL(12,2) NOT NULL COMMENT 'Maximum fund salary',
  effective_from  DATE NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `premium_rates`;
CREATE TABLE `premium_rates` (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  day_type         ENUM('ordinary','rest_day','special_holiday','special_rest','regular_holiday','regular_rest','double_holiday') NOT NULL UNIQUE,
  label            VARCHAR(100) NOT NULL,
  work_multiplier  DECIMAL(5,2) NOT NULL COMMENT 'Applied to hourly rate for the first 8 hours',
  ot_multiplier    DECIMAL(5,2) NOT NULL COMMENT 'Applied on top of the day rate for hours beyond 8',
  legal_basis      VARCHAR(150) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `holidays`;
CREATE TABLE `holidays` (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  holiday_date  DATE NOT NULL,
  name          VARCHAR(120) NOT NULL,
  holiday_type  ENUM('regular','special') NOT NULL,
  branch_id     INT NULL COMMENT 'NULL = nationwide, otherwise local holiday',
  KEY idx_hol_date (holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `late_brackets`;
CREATE TABLE `late_brackets` (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  from_minutes    INT NOT NULL,
  to_minutes      INT NOT NULL,
  deduct_minutes  INT NOT NULL,
  remarks         VARCHAR(100) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Deductions & benefits ---------------------------------------------------
DROP TABLE IF EXISTS `deduction_types`;
CREATE TABLE `deduction_types` (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  code                VARCHAR(20) NOT NULL UNIQUE,
  name                VARCHAR(100) NOT NULL,
  category            ENUM('statutory','loan','recurring','adhoc','accountability') NOT NULL,
  computation         ENUM('fixed','percent_basic','ledger') NOT NULL DEFAULT 'fixed',
  default_amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
  frequency           ENUM('every_payroll','first_cutoff','second_cutoff','monthly_last') NOT NULL DEFAULT 'every_payroll',
  authorized_by_law   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Art. 113: no written consent needed',
  requires_approval   TINYINT(1) NOT NULL DEFAULT 0,
  priority            INT NOT NULL DEFAULT 50,
  is_system           TINYINT(1) NOT NULL DEFAULT 0,
  is_active           TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `ledgers`;
CREATE TABLE `ledgers` (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  ledger_no          VARCHAR(20) NOT NULL UNIQUE,
  employee_id        INT NOT NULL,
  ledger_type        ENUM('gov_loan','company_loan','cash_advance','payable','accrued_benefit') NOT NULL,
  sub_type           VARCHAR(80) NOT NULL,
  reference_no       VARCHAR(40) NULL,
  principal          DECIMAL(12,2) NOT NULL DEFAULT 0,
  interest_rate_pct  DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT 'Add-on interest for the whole term',
  term_months        SMALLINT NULL,
  total_payable      DECIMAL(12,2) NOT NULL DEFAULT 0,
  amortization       DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Per payroll deduction',
  start_date         DATE NULL,
  balance            DECIMAL(12,2) NOT NULL DEFAULT 0,
  status             ENUM('active','paid','cancelled') NOT NULL DEFAULT 'active',
  full_payment_date  DATE NULL,
  deduction_type_id  INT NULL,
  agency_balance     DECIMAL(12,2) NULL,
  reconciled_at      DATE NULL,
  remarks            VARCHAR(500) NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_led_emp (employee_id, ledger_type),
  CONSTRAINT fk_led_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `ledger_entries`;
CREATE TABLE `ledger_entries` (
  id              BIGINT AUTO_INCREMENT PRIMARY KEY,
  ledger_id       INT NOT NULL,
  entry_date      DATE NOT NULL,
  payroll_run_id  INT NULL,
  description     VARCHAR(255) NOT NULL,
  increase        DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Charge / accrual',
  decrease        DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Payment / disbursement',
  balance         DECIMAL(12,2) NOT NULL,
  created_by      INT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_le_led (ledger_id, entry_date),
  CONSTRAINT fk_le_led FOREIGN KEY (ledger_id) REFERENCES ledgers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `ledger_reconciliations`;
CREATE TABLE `ledger_reconciliations` (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  ledger_id        INT NOT NULL,
  statement_date   DATE NOT NULL,
  agency_balance   DECIMAL(12,2) NOT NULL,
  system_balance   DECIMAL(12,2) NOT NULL,
  variance         DECIMAL(12,2) NOT NULL,
  remarks          VARCHAR(255) NULL,
  created_by       INT NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_lr_led FOREIGN KEY (ledger_id) REFERENCES ledgers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `employee_deductions`;
CREATE TABLE `employee_deductions` (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  employee_id        INT NOT NULL,
  deduction_type_id  INT NOT NULL,
  ledger_id          INT NULL,
  amount             DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Per-payroll amount (or % if percent_basic)',
  start_date         DATE NOT NULL,
  end_date           DATE NULL,
  written_consent    TINYINT(1) NOT NULL DEFAULT 0,
  status             ENUM('pending','active','stopped','completed','rejected') NOT NULL DEFAULT 'active',
  approved_by        INT NULL,
  approved_at        DATETIME NULL,
  remarks            VARCHAR(255) NULL,
  created_by         INT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ed_emp (employee_id, status),
  CONSTRAINT fk_ed_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_ed_type FOREIGN KEY (deduction_type_id) REFERENCES deduction_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `benefit_types`;
CREATE TABLE `benefit_types` (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  code                    VARCHAR(20) NOT NULL UNIQUE,
  name                    VARCHAR(100) NOT NULL,
  category                ENUM('de_minimis','company','statutory') NOT NULL DEFAULT 'company',
  tax_treatment           ENUM('de_minimis','other_benefit','taxable','non_taxable') NOT NULL DEFAULT 'taxable',
  ceiling_amount          DECIMAL(12,2) NULL COMMENT 'De minimis non-taxable ceiling',
  ceiling_period          ENUM('monthly','annual','none') NOT NULL DEFAULT 'none',
  default_amount          DECIMAL(12,2) NOT NULL DEFAULT 0,
  frequency               ENUM('every_payroll','first_cutoff','second_cutoff','monthly_last') NOT NULL DEFAULT 'every_payroll',
  eligibility_regular_only TINYINT(1) NOT NULL DEFAULT 0,
  eligibility_min_months  SMALLINT NOT NULL DEFAULT 0,
  legal_basis             VARCHAR(150) NULL,
  is_active               TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `employee_benefits`;
CREATE TABLE `employee_benefits` (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  employee_id      INT NOT NULL,
  benefit_type_id  INT NOT NULL,
  amount           DECIMAL(12,2) NOT NULL,
  start_date       DATE NOT NULL,
  end_date         DATE NULL,
  status           ENUM('active','stopped') NOT NULL DEFAULT 'active',
  remarks          VARCHAR(255) NULL,
  CONSTRAINT fk_eb_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_eb_type FOREIGN KEY (benefit_type_id) REFERENCES benefit_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `pending_earnings`;
CREATE TABLE `pending_earnings` (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  employee_id     INT NOT NULL,
  source          ENUM('retro','leave_conversion','bonus','other') NOT NULL DEFAULT 'other',
  source_id       INT NULL,
  description     VARCHAR(255) NOT NULL,
  amount          DECIMAL(12,2) NOT NULL,
  tax_treatment   ENUM('taxable','other_benefit','de_minimis','non_taxable') NOT NULL DEFAULT 'taxable',
  nontax_portion  DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'For de minimis split',
  status          ENUM('pending','included','cancelled') NOT NULL DEFAULT 'pending',
  payroll_run_id  INT NULL,
  created_by      INT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pe_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `retro_adjustments`;
CREATE TABLE `retro_adjustments` (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  employee_id     INT NOT NULL,
  history_id      INT NULL,
  effective_date  DATE NOT NULL,
  old_rate        DECIMAL(12,2) NOT NULL,
  new_rate        DECIMAL(12,2) NOT NULL,
  rate_type       ENUM('monthly','daily') NOT NULL,
  amount          DECIMAL(12,2) NOT NULL,
  breakdown       TEXT NULL,
  status          ENUM('pending','included','cancelled') NOT NULL DEFAULT 'pending',
  pending_earning_id INT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_retro_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payroll processing -------------------------------------------------------
DROP TABLE IF EXISTS `payroll_runs`;
CREATE TABLE `payroll_runs` (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  run_no         VARCHAR(30) NOT NULL UNIQUE,
  pay_group_id   INT NOT NULL,
  run_type       ENUM('regular','thirteenth','final_pay','special') NOT NULL DEFAULT 'regular',
  period_start   DATE NOT NULL,
  period_end     DATE NOT NULL,
  pay_date       DATE NOT NULL,
  cutoff_id      INT NULL,
  status         ENUM('draft','computed','finalized','cancelled') NOT NULL DEFAULT 'draft',
  notes          VARCHAR(500) NULL,
  created_by     INT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  computed_at    DATETIME NULL,
  finalized_at   DATETIME NULL,
  finalized_by   INT NULL,
  KEY idx_run_pg (pay_group_id, period_start),
  CONSTRAINT fk_run_pg FOREIGN KEY (pay_group_id) REFERENCES pay_groups(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `payroll_items`;
CREATE TABLE `payroll_items` (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  run_id                INT NOT NULL,
  employee_id           INT NOT NULL,
  default_branch_id     INT NOT NULL,
  org_unit_id           INT NULL,
  rate_type             ENUM('monthly','daily') NOT NULL,
  monthly_rate          DECIMAL(12,2) NOT NULL DEFAULT 0,
  daily_rate            DECIMAL(12,4) NOT NULL DEFAULT 0,
  hourly_rate           DECIMAL(12,4) NOT NULL DEFAULT 0,
  days_worked           DECIMAL(6,2) NOT NULL DEFAULT 0,
  absent_days           DECIMAL(6,2) NOT NULL DEFAULT 0,
  leave_paid_days       DECIMAL(6,2) NOT NULL DEFAULT 0,
  leave_unpaid_days     DECIMAL(6,2) NOT NULL DEFAULT 0,
  late_minutes          INT NOT NULL DEFAULT 0,
  undertime_minutes     INT NOT NULL DEFAULT 0,
  ot_hours              DECIMAL(8,2) NOT NULL DEFAULT 0,
  basic_pay             DECIMAL(12,2) NOT NULL DEFAULT 0,
  absence_deduction     DECIMAL(12,2) NOT NULL DEFAULT 0,
  late_deduction        DECIMAL(12,2) NOT NULL DEFAULT 0,
  undertime_deduction   DECIMAL(12,2) NOT NULL DEFAULT 0,
  holiday_pay           DECIMAL(12,2) NOT NULL DEFAULT 0,
  premium_pay           DECIMAL(12,2) NOT NULL DEFAULT 0,
  ot_pay                DECIMAL(12,2) NOT NULL DEFAULT 0,
  nd_pay                DECIMAL(12,2) NOT NULL DEFAULT 0,
  benefits_nontax       DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'De minimis within ceiling / non-taxable',
  benefits_other        DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '13th month & other benefits within 90k',
  benefits_taxable      DECIMAL(12,2) NOT NULL DEFAULT 0,
  other_earnings        DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Retro, adjustments (taxable)',
  gross_pay             DECIMAL(12,2) NOT NULL DEFAULT 0,
  contrib_basis         DECIMAL(12,2) NOT NULL DEFAULT 0,
  sss_ee                DECIMAL(10,2) NOT NULL DEFAULT 0,
  sss_er                DECIMAL(10,2) NOT NULL DEFAULT 0,
  sss_ec                DECIMAL(10,2) NOT NULL DEFAULT 0,
  phic_ee               DECIMAL(10,2) NOT NULL DEFAULT 0,
  phic_er               DECIMAL(10,2) NOT NULL DEFAULT 0,
  hdmf_ee               DECIMAL(10,2) NOT NULL DEFAULT 0,
  hdmf_ee_voluntary     DECIMAL(10,2) NOT NULL DEFAULT 0,
  hdmf_er               DECIMAL(10,2) NOT NULL DEFAULT 0,
  taxable_income        DECIMAL(12,2) NOT NULL DEFAULT 0,
  withholding_tax       DECIMAL(12,2) NOT NULL DEFAULT 0,
  other_deductions      DECIMAL(12,2) NOT NULL DEFAULT 0,
  deferred_deductions   DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_deductions      DECIMAL(12,2) NOT NULL DEFAULT 0,
  net_pay               DECIMAL(12,2) NOT NULL DEFAULT 0,
  is_mwe                TINYINT(1) NOT NULL DEFAULT 0,
  basic_earned          DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Basic net of absences/lates, base for 13th month',
  remarks               VARCHAR(500) NULL,
  UNIQUE KEY uq_item (run_id, employee_id),
  KEY idx_item_emp (employee_id),
  CONSTRAINT fk_item_run FOREIGN KEY (run_id) REFERENCES payroll_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_item_emp FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `payroll_item_lines`;
CREATE TABLE `payroll_item_lines` (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  item_id      INT NOT NULL,
  branch_id    INT NULL,
  line_type    ENUM('earning','deduction','employer','info') NOT NULL,
  code         VARCHAR(30) NOT NULL,
  description  VARCHAR(255) NOT NULL,
  quantity     DECIMAL(10,2) NULL,
  amount       DECIMAL(12,2) NOT NULL,
  taxable      TINYINT(1) NOT NULL DEFAULT 1,
  ref_type     VARCHAR(30) NULL,
  ref_id       INT NULL,
  sort_order   INT NOT NULL DEFAULT 0,
  KEY idx_line_item (item_id),
  CONSTRAINT fk_line_item FOREIGN KEY (item_id) REFERENCES payroll_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `payroll_branch_items`;
CREATE TABLE `payroll_branch_items` (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  item_id           INT NOT NULL,
  run_id            INT NOT NULL,
  employee_id       INT NOT NULL,
  branch_id         INT NOT NULL,
  is_default        TINYINT(1) NOT NULL DEFAULT 0,
  days_worked       DECIMAL(6,2) NOT NULL DEFAULT 0,
  reg_hours         DECIMAL(8,2) NOT NULL DEFAULT 0,
  ot_hours          DECIMAL(8,2) NOT NULL DEFAULT 0,
  basic_alloc       DECIMAL(12,2) NOT NULL DEFAULT 0,
  premium_pay       DECIMAL(12,2) NOT NULL DEFAULT 0,
  ot_pay            DECIMAL(12,2) NOT NULL DEFAULT 0,
  nd_pay            DECIMAL(12,2) NOT NULL DEFAULT 0,
  other_earnings    DECIMAL(12,2) NOT NULL DEFAULT 0,
  late_ut_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
  gross             DECIMAL(12,2) NOT NULL DEFAULT 0,
  er_share_alloc    DECIMAL(12,2) NOT NULL DEFAULT 0,
  KEY idx_pbi_run (run_id, branch_id),
  CONSTRAINT fk_pbi_item FOREIGN KEY (item_id) REFERENCES payroll_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `payroll_adjustments`;
CREATE TABLE `payroll_adjustments` (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  run_id       INT NOT NULL,
  employee_id  INT NOT NULL,
  adj_type     ENUM('earning','deduction') NOT NULL,
  description  VARCHAR(255) NOT NULL,
  amount       DECIMAL(12,2) NOT NULL,
  taxable      TINYINT(1) NOT NULL DEFAULT 1,
  reason       VARCHAR(500) NOT NULL,
  created_by   INT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_adj_run FOREIGN KEY (run_id) REFERENCES payroll_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `payroll_exceptions`;
CREATE TABLE `payroll_exceptions` (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  run_id          INT NOT NULL,
  employee_id     INT NULL,
  exception_type  VARCHAR(40) NOT NULL,
  message         VARCHAR(500) NOT NULL,
  blocking        TINYINT(1) NOT NULL DEFAULT 1,
  status          ENUM('open','resolved') NOT NULL DEFAULT 'open',
  resolution      VARCHAR(500) NULL,
  resolved_by     INT NULL,
  resolved_at     DATETIME NULL,
  CONSTRAINT fk_pex_run FOREIGN KEY (run_id) REFERENCES payroll_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `final_pays`;
CREATE TABLE `final_pays` (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  employee_id      INT NOT NULL,
  separation_date  DATE NOT NULL,
  last_salary      DECIMAL(12,2) NOT NULL DEFAULT 0,
  prorated_13th    DECIMAL(12,2) NOT NULL DEFAULT 0,
  leave_conversion DECIMAL(12,2) NOT NULL DEFAULT 0,
  other_earnings   DECIMAL(12,2) NOT NULL DEFAULT 0,
  loan_offsets     DECIMAL(12,2) NOT NULL DEFAULT 0,
  other_deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax_adjustment   DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Positive = tax payable, negative = refund',
  net_final_pay    DECIMAL(12,2) NOT NULL DEFAULT 0,
  due_date         DATE NOT NULL,
  release_date     DATE NULL,
  status           ENUM('draft','approved','released') NOT NULL DEFAULT 'draft',
  breakdown        MEDIUMTEXT NULL,
  payroll_run_id   INT NULL,
  created_by       INT NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fp_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
--  MODULE 3 : ATTENDANCE MONITORING SYSTEM
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `employee_schedules`;
CREATE TABLE `employee_schedules` (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  employee_id  INT NOT NULL,
  work_date    DATE NOT NULL,
  shift_id     INT NULL,
  is_rest_day  TINYINT(1) NOT NULL DEFAULT 0,
  branch_id    INT NULL,
  UNIQUE KEY uq_sched (employee_id, work_date),
  CONSTRAINT fk_sched_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `import_profiles`;
CREATE TABLE `import_profiles` (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  name               VARCHAR(100) NOT NULL,
  delimiter          VARCHAR(10) NOT NULL DEFAULT 'tab' COMMENT 'tab, comma, pipe, semicolon, space',
  has_header         TINYINT(1) NOT NULL DEFAULT 0,
  col_bio_id         TINYINT NOT NULL DEFAULT 1,
  col_datetime       TINYINT NULL COMMENT 'Combined date-time column',
  col_date           TINYINT NULL,
  col_time           TINYINT NULL,
  datetime_format    VARCHAR(30) NOT NULL DEFAULT 'Y-m-d H:i:s',
  col_punch          TINYINT NULL,
  punch_map          VARCHAR(150) NULL COMMENT 'e.g. 0=in,1=out,2=break_out,3=break_in',
  col_device         TINYINT NULL,
  default_branch_id  INT NULL,
  notes              VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `import_batches`;
CREATE TABLE `import_batches` (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  batch_type   ENUM('biometric','csv','xlsx') NOT NULL,
  profile_id   INT NULL,
  file_name    VARCHAR(255) NOT NULL,
  branch_id    INT NULL,
  total_rows   INT NOT NULL DEFAULT 0,
  imported     INT NOT NULL DEFAULT 0,
  duplicates   INT NOT NULL DEFAULT 0,
  flagged      INT NOT NULL DEFAULT 0,
  uploaded_by  INT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `import_exceptions`;
CREATE TABLE `import_exceptions` (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  batch_id     INT NOT NULL,
  row_no       INT NOT NULL,
  raw_data     VARCHAR(1000) NULL,
  employee_no  VARCHAR(40) NULL,
  employee_id  INT NULL,
  work_date    DATE NULL,
  time_in      DATETIME NULL,
  time_out     DATETIME NULL,
  branch_id    INT NULL,
  remarks      VARCHAR(255) NULL,
  issue        VARCHAR(500) NOT NULL,
  status       ENUM('open','posted','rejected') NOT NULL DEFAULT 'open',
  resolved_by  INT NULL,
  resolved_at  DATETIME NULL,
  CONSTRAINT fk_ie_batch FOREIGN KEY (batch_id) REFERENCES import_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `attendance_logs`;
CREATE TABLE `attendance_logs` (
  id               BIGINT AUTO_INCREMENT PRIMARY KEY,
  employee_id      INT NULL,
  bio_id           VARCHAR(20) NULL,
  log_time         DATETIME NOT NULL,
  punch_type       ENUM('in','out','break_out','break_in','unknown') NOT NULL DEFAULT 'unknown',
  source           ENUM('biometric','import_txt','import_csv','web','manual') NOT NULL,
  branch_id        INT NULL,
  batch_id         INT NULL,
  device_id        VARCHAR(40) NULL,
  latitude         DECIMAL(10,7) NULL,
  longitude        DECIMAL(10,7) NULL,
  accuracy_m       DECIMAL(8,2) NULL,
  distance_m       DECIMAL(10,2) NULL,
  within_geofence  TINYINT(1) NULL,
  photo_path       VARCHAR(255) NULL,
  face_detected    TINYINT(1) NULL,
  verify_status    ENUM('auto','pending','verified','rejected') NOT NULL DEFAULT 'auto',
  verified_by      INT NULL,
  ip_address       VARCHAR(45) NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_log (employee_id, log_time, punch_type),
  KEY idx_log_emp (employee_id, log_time),
  KEY idx_log_bio (bio_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `overtime_requests`;
CREATE TABLE `overtime_requests` (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  employee_id  INT NOT NULL,
  ot_date      DATE NOT NULL,
  hours        DECIMAL(5,2) NOT NULL,
  reason       VARCHAR(255) NOT NULL,
  status       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  approved_by  INT NULL,
  approved_at  DATETIME NULL,
  remarks      VARCHAR(255) NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ot_emp (employee_id, ot_date),
  CONSTRAINT fk_ot_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `dtr`;
CREATE TABLE `dtr` (
  id                   BIGINT AUTO_INCREMENT PRIMARY KEY,
  employee_id          INT NOT NULL,
  work_date            DATE NOT NULL,
  branch_id            INT NULL,
  shift_id             INT NULL,
  sched_in             DATETIME NULL,
  sched_out            DATETIME NULL,
  time_in              DATETIME NULL,
  break_out            DATETIME NULL,
  break_in             DATETIME NULL,
  time_out             DATETIME NULL,
  break_minutes        INT NOT NULL DEFAULT 0,
  worked_minutes       INT NOT NULL DEFAULT 0,
  regular_minutes      INT NOT NULL DEFAULT 0,
  late_minutes         INT NOT NULL DEFAULT 0,
  undertime_minutes    INT NOT NULL DEFAULT 0,
  ot_minutes           INT NOT NULL DEFAULT 0,
  approved_ot_minutes  INT NOT NULL DEFAULT 0,
  nd_minutes           INT NOT NULL DEFAULT 0,
  nd_ot_minutes        INT NOT NULL DEFAULT 0,
  day_type             ENUM('ordinary','rest_day','special_holiday','special_rest','regular_holiday','regular_rest','double_holiday') NOT NULL DEFAULT 'ordinary',
  is_workday           TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Scheduled working day',
  status               ENUM('present','absent','on_leave','rest_day','holiday','suspended','incomplete','no_schedule','pending') NOT NULL DEFAULT 'pending',
  leave_application_id INT NULL,
  leave_paid           TINYINT(1) NOT NULL DEFAULT 0,
  source               VARCHAR(20) NULL,
  exceptions           VARCHAR(255) NULL,
  exception_status     ENUM('none','open','resolved') NOT NULL DEFAULT 'none',
  exception_note       VARCHAR(255) NULL,
  manual_override      TINYINT(1) NOT NULL DEFAULT 0,
  remarks              VARCHAR(255) NULL,
  updated_by           INT NULL,
  updated_at           DATETIME NULL,
  UNIQUE KEY uq_dtr (employee_id, work_date),
  KEY idx_dtr_date (work_date),
  CONSTRAINT fk_dtr_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `attendance_cutoffs`;
CREATE TABLE `attendance_cutoffs` (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  pay_group_id   INT NOT NULL,
  period_start   DATE NOT NULL,
  period_end     DATE NOT NULL,
  status         ENUM('open','summarized','approved','locked') NOT NULL DEFAULT 'open',
  summarized_at  DATETIME NULL,
  approved_by    INT NULL,
  approved_at    DATETIME NULL,
  notes          VARCHAR(255) NULL,
  UNIQUE KEY uq_cutoff (pay_group_id, period_start, period_end),
  CONSTRAINT fk_cut_pg FOREIGN KEY (pay_group_id) REFERENCES pay_groups(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `attendance_summaries`;
CREATE TABLE `attendance_summaries` (
  id                     INT AUTO_INCREMENT PRIMARY KEY,
  cutoff_id              INT NOT NULL,
  employee_id            INT NOT NULL,
  scheduled_days         DECIMAL(6,2) NOT NULL DEFAULT 0,
  days_worked            DECIMAL(6,2) NOT NULL DEFAULT 0,
  absent_days            DECIMAL(6,2) NOT NULL DEFAULT 0,
  leave_paid_days        DECIMAL(6,2) NOT NULL DEFAULT 0,
  leave_unpaid_days      DECIMAL(6,2) NOT NULL DEFAULT 0,
  suspended_days         DECIMAL(6,2) NOT NULL DEFAULT 0,
  reg_holiday_unworked   DECIMAL(6,2) NOT NULL DEFAULT 0,
  spl_holiday_unworked   DECIMAL(6,2) NOT NULL DEFAULT 0,
  late_minutes           INT NOT NULL DEFAULT 0,
  late_count             INT NOT NULL DEFAULT 0,
  undertime_minutes      INT NOT NULL DEFAULT 0,
  reg_minutes            INT NOT NULL DEFAULT 0,
  ot_minutes             INT NOT NULL DEFAULT 0,
  unauthorized_ot_minutes INT NOT NULL DEFAULT 0,
  nd_minutes             INT NOT NULL DEFAULT 0,
  open_exceptions        INT NOT NULL DEFAULT 0,
  flags                  VARCHAR(255) NULL,
  UNIQUE KEY uq_sum (cutoff_id, employee_id),
  CONSTRAINT fk_sum_cut FOREIGN KEY (cutoff_id) REFERENCES attendance_cutoffs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `attendance_summary_lines`;
CREATE TABLE `attendance_summary_lines` (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  summary_id         INT NOT NULL,
  branch_id          INT NOT NULL,
  day_type           ENUM('ordinary','rest_day','special_holiday','special_rest','regular_holiday','regular_rest','double_holiday') NOT NULL,
  days               DECIMAL(6,2) NOT NULL DEFAULT 0,
  reg_minutes        INT NOT NULL DEFAULT 0,
  ot_minutes         INT NOT NULL DEFAULT 0,
  nd_minutes         INT NOT NULL DEFAULT 0,
  nd_ot_minutes      INT NOT NULL DEFAULT 0,
  late_minutes       INT NOT NULL DEFAULT 0,
  undertime_minutes  INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_sl_sum FOREIGN KEY (summary_id) REFERENCES attendance_summaries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =====================================================================
--  REFERENCE DATA
-- =====================================================================
--  G-SMaRT HRIS reference data (seed)
--  All statutory tables below are EDITABLE DATA, not program logic.
--  Rates verified current as of 2026:
--   * BIR withholding tax: TRAIN Law (RA 10963) Annex E table, effective 2023 onwards
--   * SSS: RA 11199 schedule effective Jan 2025 (15%: EE 5%, ER 10%; MSC 5,000-35,000; MPF above 20,000)
--   * PhilHealth: RA 11223, 5% premium, floor 10,000, ceiling 100,000, 50/50 split
--   * Pag-IBIG: RA 9679 / HDMF Circular 460, max fund salary 10,000
--   * De minimis ceilings: RR 29-2025 (effective Jan 6, 2026)
--   * Holidays: Proclamation No. 1006 (2026)
-- =====================================================================

INSERT INTO settings (skey, svalue, label, grp, input_type, help, sort_order) VALUES
('company_name','Sample Holdings Corporation','Company name','general','text',NULL,1),
('company_address','Makati City, Metro Manila','Company address','general','text',NULL,2),
('company_tin','000-000-000-000','Company TIN','general','text',NULL,3),
('mail_mode','log','Email delivery mode','mail','select:log=Log only (Outbox page),smtp=SMTP server,mail=PHP mail()','"Log only" records emails in Admin > Email Outbox without sending (for local testing).',1),
('smtp_host','smtp.gmail.com','SMTP host','mail','text',NULL,2),
('smtp_port','587','SMTP port','mail','number',NULL,3),
('smtp_secure','tls','SMTP encryption','mail','select:tls=STARTTLS,ssl=SSL/TLS,none=None',NULL,4),
('smtp_user','','SMTP username','mail','text',NULL,5),
('smtp_pass','','SMTP password / app password','mail','password',NULL,6),
('mail_from','hris@example.com','Sender email','mail','text',NULL,7),
('mail_from_name','G-SMaRT HRIS Notifications','Sender name','mail','text',NULL,8),
('hr_notify_email','hr@example.com','HR approver email (leave chain HR step)','mail','text','Used when no HR user account has an email address.',9),
('leave_token_days','7','Email approval link validity (days)','mail','number',NULL,10),
('grace_minutes','5','Grace period (minutes)','attendance','number','Tardiness within the grace period is not deducted.',1),
('late_grace_mode','full','When late beyond grace, deduct','attendance','select:full=All minutes late,excess=Only minutes beyond grace',NULL,2),
('late_deduction_mode','per_minute','Late/undertime deduction basis','attendance','select:per_minute=Per minute (hourly rate / 60 x minutes),bracket=Lates/Undertime bracket table',NULL,3),
('ot_min_minutes','30','Minimum overtime counted (minutes)','attendance','number',NULL,4),
('ot_requires_approval','1','Overtime requires approved OT authorization','attendance','bool',NULL,5),
('nd_start','22:00','Night differential window start','attendance','time',NULL,6),
('nd_end','06:00','Night differential window end','attendance','time',NULL,7),
('auto_break_deduct','1','Deduct shift break when no break punches','attendance','bool',NULL,8),
('habitual_tardy_count','5','Habitual tardiness: times late per month','attendance','number','Flags employee for HR review / NTE.',9),
('tardy_cap_minutes','120','Tardiness cap per month (minutes) before disciplinary referral','attendance','number',NULL,10),
('geofence_mode','flag','Web check-in outside geofence','attendance','select:flag=Accept but flag for HR review,reject=Reject check-in',NULL,11),
('selfie_required','1','Require selfie (facial capture) on web check-in','attendance','bool',NULL,12),
('punch_min_interval','2','Ignore repeated web punches within (minutes)','attendance','number',NULL,13),
('hours_per_day','8','Normal hours per day','payroll','number',NULL,1),
('default_days_per_year','261','Default days-per-year factor','payroll','number','261 (5-day week), 313 (6-day week), 365 (paid rest days).',2),
('nd_rate_pct','10','Night shift differential (% of hourly rate)','payroll','number','Labor Code Art. 86: not less than 10%.',3),
('holiday_pay_daily_rated','1','Pay unworked regular holidays to daily-rated employees','payroll','bool','Labor Code Art. 94.',4),
('nontax_benefit_ceiling','90000','Non-taxable 13th month & other benefits ceiling','payroll','number','TRAIN Law: PHP 90,000.',5),
('min_net_pay_pct','20','Minimum take-home pay (% of gross) before voluntary deductions are deferred','payroll','number','Wage protection policy (Labor Code Art. 113). Statutory deductions are never deferred.',6),
('accountability_max_pct','20','Max deduction for loss/damage per payroll (% of wages)','payroll','number','Labor Code Art. 114 & IRR Book III Rule VIII.',7),
('pagibig_voluntary_allowed','1','Allow voluntary higher Pag-IBIG contributions','payroll','bool',NULL,8),
('de_minimis_leave_days','12','Non-taxable monetized unused VL (days/year)','payroll','number','RR 29-2025.',9),
('thirteenth_deadline','12-24','13th month pay deadline (MM-DD)','payroll','text','PD 851: on or before December 24.',10),
('final_pay_days','30','Final pay release period (days from separation)','payroll','number','DOLE Labor Advisory No. 06-2020.',11),
('payslip_note','This is a system-generated payslip.','Payslip footer note','payroll','text',NULL,12),
('discipline_lookback_months','12','Rolling period for counting prior offenses (months)','discipline','number',NULL,1),
('nte_min_days','5','Minimum days to submit written explanation','discipline','number','Twin-notice rule: reasonable opportunity, not less than 5 calendar days.',2),
('underperform_threshold','2.50','Underperformance threshold (overall rating)','discipline','number','Two consecutive cycles below this refer the employee to the disciplinary workflow.',3);

INSERT INTO pay_groups (id, code, name, frequency, contrib_mode, description) VALUES
(1,'SM-RF','Rank-and-File (Semi-Monthly)','semi_monthly','split','1st-15th and 16th-end of month'),
(2,'M-MGR','Managerial (Monthly)','monthly','split','Paid at month-end'),
(3,'W-FLD','Field / Project Personnel (Weekly)','weekly','last','Monday to Sunday'),
(4,'D-PRJ','Daily-Paid Project Workers','daily','last','Paid daily');

INSERT INTO branches (id, code, name, company_name, address, tin, rdo_code, sss_er_no, philhealth_er_no, pagibig_er_no, bank_name, bank_account_no, bank_company_code, latitude, longitude, geofence_radius_m) VALUES
(1,'HO','Head Office - Makati','Sample Holdings Corporation','Ayala Ave., Makati City','000-123-456-000','047','03-1234567-8','01-234567890-1','2000-1234-5678','BDO Unibank','0012-3456-7890','SHC01',14.5547000,121.0244000,200),
(2,'QC','Quezon City Branch','Sample Holdings Corporation','Tomas Morato Ave., Quezon City','000-123-456-001','038','03-1234567-8','01-234567890-1','2000-1234-5678','BDO Unibank','0012-3456-7891','SHC02',14.6349000,121.0331000,200),
(3,'CEB','Cebu Branch','Sample Visayas Corp.','IT Park, Lahug, Cebu City','000-765-432-000','081','06-7654321-0','01-765432109-8','2000-7654-3210','BPI','1234-5678-90','SVC01',10.3292000,123.9055000,250);

INSERT INTO org_units (id, parent_id, code, name, unit_type, sort_order, default_pay_group_id) VALUES
(1,NULL,'CORP','Sample Holdings Corporation','Company',0,1),
(2,1,'DIV-OPS','Operations Division','Division',1,1),
(3,1,'DIV-ADM','Administration Division','Division',2,1),
(4,2,'DEP-SAL','Sales Department','Department',1,1),
(5,2,'DEP-PRD','Production Department','Department',2,1),
(6,3,'DEP-HR','Human Resources Department','Department',1,1),
(7,3,'DEP-FIN','Finance Department','Department',2,2),
(8,4,'SEC-FLD','Field Sales Section','Section',1,3),
(9,5,'SEC-ASM','Assembly Section','Section',1,1),
(10,7,'SEC-PAY','Payroll Section','Section',1,1);

INSERT INTO salary_grades (id, code, position_band, minimum, midpoint, maximum, num_steps, sort_order) VALUES
(1,'SG-1','Entry-Level / Rank-and-File',15000,17500,20000,5,1),
(2,'SG-2','Skilled / Senior Staff',20000,24000,28000,5,2),
(3,'SG-3','Supervisory',28000,34000,40000,5,3),
(4,'SG-4','Assistant Manager / Manager',40000,50000,60000,5,4),
(5,'SG-5','Senior Manager / Department Head',60000,75000,90000,5,5),
(6,'SG-6','Executive',90000,115000,140000,5,6);

INSERT INTO salary_grade_steps (grade_id, step_no, amount) VALUES
(1,1,15000),(1,2,16250),(1,3,17500),(1,4,18750),(1,5,20000),
(2,1,20000),(2,2,22000),(2,3,24000),(2,4,26000),(2,5,28000),
(3,1,28000),(3,2,31000),(3,3,34000),(3,4,37000),(3,5,40000),
(4,1,40000),(4,2,45000),(4,3,50000),(4,4,55000),(4,5,60000),
(5,1,60000),(5,2,67500),(5,3,75000),(5,4,82500),(5,5,90000),
(6,1,90000),(6,2,102500),(6,3,115000),(6,4,127500),(6,5,140000);

INSERT INTO shifts (id, code, name, time_in, time_out, break_minutes, rest_days) VALUES
(1,'DAY-MF','Day Shift 8AM-5PM (Mon-Fri)','08:00:00','17:00:00',60,'0,6'),
(2,'DAY-MS','Day Shift 8AM-5PM (Mon-Sat)','08:00:00','17:00:00',60,'0'),
(3,'MID-MF','Mid Shift 2PM-11PM (Mon-Fri)','14:00:00','23:00:00',60,'0,6'),
(4,'NGT-MF','Night Shift 10PM-7AM (Mon-Fri)','22:00:00','07:00:00',60,'0,6');

INSERT INTO leave_types (code,name,legal_basis,accrual_method,days_per_year,count_basis,service_step_years,service_step_days,max_days,max_carry_over,convertible,max_convertible_days,is_paid,gender,civil_status_req,requires_solo_parent,solo_parent_extra_days,max_events,min_service_months,approval_chain,is_statutory,description) VALUES
('SIL','Service Incentive Leave','Labor Code Art. 95','annual',5,'workdays',NULL,NULL,NULL,0,1,5,1,'any',NULL,0,0,NULL,12,'supervisor,hr',1,'Statutory minimum of 5 days/year after at least one year of service. Unused SIL is commutable to cash.'),
('VL','Vacation Leave','Company policy','service_based',10,'workdays',3,1,15,5,1,10,1,'any',NULL,0,0,NULL,6,'supervisor,dept_head,hr',0,'Company-granted; 10 days + 1 day every 3 years of service, max 15. Carry-over up to 5 days.'),
('SL','Sick Leave','Company policy','monthly',10,'workdays',NULL,NULL,NULL,5,0,NULL,1,'any',NULL,0,0,NULL,0,'supervisor,hr',0,'Company-granted; accrues 10/12 day per month.'),
('ML','Maternity Leave','RA 11210 (Expanded Maternity Leave Law)','per_event',105,'calendar',NULL,NULL,NULL,0,0,NULL,1,'female',NULL,0,15,NULL,0,'supervisor,hr',1,'105 days for live childbirth (+15 days for solo parents); 60 days for miscarriage or emergency termination of pregnancy.'),
('ML-MC','Maternity Leave (Miscarriage/ETP)','RA 11210','per_event',60,'calendar',NULL,NULL,NULL,0,0,NULL,1,'female',NULL,0,0,NULL,0,'supervisor,hr',1,'60 days for miscarriage or emergency termination of pregnancy.'),
('PL','Paternity Leave','RA 8187 (Paternity Leave Act)','per_event',7,'workdays',NULL,NULL,NULL,0,0,NULL,1,'male','married',0,0,4,0,'supervisor,hr',1,'7 days for married male employees, first four deliveries of the legitimate spouse.'),
('SPL','Solo Parent Leave','RA 8972 as amended by RA 11861','annual',7,'workdays',NULL,NULL,NULL,0,0,NULL,1,'any',NULL,1,0,NULL,0,'supervisor,hr',1,'7 working days per year for qualified solo parents with a Solo Parent ID.'),
('SLW','Special Leave for Women','RA 9710 (Magna Carta of Women)','per_event',60,'calendar',NULL,NULL,NULL,0,0,NULL,1,'female',NULL,0,0,NULL,6,'supervisor,dept_head,hr',1,'Up to two (2) months for surgery caused by gynecological disorders.'),
('VAWC','VAWC Leave','RA 9262 (Anti-VAWC Act)','annual',10,'workdays',NULL,NULL,NULL,0,0,NULL,1,'female',NULL,0,0,NULL,0,'hr',1,'Up to 10 days for victims of violence against women and their children.'),
('BL','Bereavement Leave','Company policy','per_event',3,'workdays',NULL,NULL,NULL,0,0,NULL,1,'any',NULL,0,0,NULL,0,'supervisor,hr',0,'Death of an immediate family member.'),
('EL','Emergency Leave','Company policy','annual',3,'workdays',NULL,NULL,NULL,0,0,NULL,1,'any',NULL,0,0,NULL,0,'supervisor,hr',0,'Company-specific emergency leave.'),
('LWOP','Leave Without Pay','Company policy','per_event',30,'workdays',NULL,NULL,NULL,0,0,NULL,0,'any',NULL,0,0,NULL,0,'supervisor,dept_head,hr',0,'Unpaid leave; not counted as unauthorized absence.');

INSERT INTO competencies (name, description, sort_order) VALUES
('Teamwork','Works cooperatively and supports team goals',1),
('Initiative','Acts without being told; proposes improvements',2),
('Communication','Clear, timely and respectful communication',3),
('Adherence to Code of Conduct','Observes company rules, policies and ethics',4),
('Leadership Potential','Influences, guides and develops others',5),
('Quality of Work','Accuracy, thoroughness and reliability of output',6);

INSERT INTO merit_matrix (min_score, rating_label, increase_pct, step_increase, promotion_eligible, disciplinary_flag) VALUES
(4.50,'Outstanding',8.00,2,1,0),
(3.50,'Very Satisfactory',5.00,1,1,0),
(2.50,'Satisfactory',3.00,1,0,0),
(1.50,'Unsatisfactory',0.00,0,0,1),
(0.00,'Needs Improvement',0.00,0,0,1);

INSERT INTO conduct_offenses (id, code, category, description, severity) VALUES
(1,'TARDY','Tardiness / Undertime','Habitual tardiness or undertime without valid reason','light'),
(2,'AWOL','Unauthorized Absence (AWOL)','Absence without official leave or prior approval','less_grave'),
(3,'INSUB','Insubordination','Willful disobedience of lawful orders of superiors','less_grave'),
(4,'DISH','Dishonesty / Falsification','Dishonesty, falsification of records or documents','grave'),
(5,'MISC','Serious Misconduct / Fraud / Theft','Serious misconduct, fraud, theft or willful breach of trust','grave'),
(6,'PERF','Poor Performance','Consistently below-standard performance ratings','light');

INSERT INTO conduct_penalties (offense_id, offense_no, penalty_type, min_days, max_days, notes) VALUES
(1,1,'verbal_warning',NULL,NULL,NULL),(1,2,'written_warning',NULL,NULL,NULL),(1,3,'suspension',1,3,NULL),(1,4,'dismissal',NULL,NULL,NULL),
(2,1,'written_warning',NULL,NULL,NULL),(2,2,'suspension',3,5,NULL),(2,3,'dismissal',NULL,NULL,NULL),
(3,1,'suspension',1,3,NULL),(3,2,'suspension',5,10,NULL),(3,3,'dismissal',NULL,NULL,NULL),
(4,1,'suspension',5,10,'or Dismissal, depending on gravity'),(4,2,'dismissal',NULL,NULL,NULL),(4,3,'dismissal',NULL,NULL,NULL),
(5,1,'dismissal',NULL,NULL,NULL),
(6,1,'written_warning',NULL,NULL,'Performance improvement plan'),(6,2,'suspension',1,3,NULL),(6,3,'dismissal',NULL,NULL,'Gross inefficiency');

INSERT INTO tax_brackets (frequency, bracket_no, comp_from, comp_to, fixed_tax, rate_pct, effective_from) VALUES
('daily',1,0,685,0,0,'2023-01-01'),
('daily',2,685,1095,0,15,'2023-01-01'),
('daily',3,1096,2191,61.65,20,'2023-01-01'),
('daily',4,2192,5478,280.85,25,'2023-01-01'),
('daily',5,5479,21917,1102.60,30,'2023-01-01'),
('daily',6,21918,NULL,6034.30,35,'2023-01-01'),
('weekly',1,0,4808,0,0,'2023-01-01'),
('weekly',2,4808,7691,0,15,'2023-01-01'),
('weekly',3,7692,15384,432.60,20,'2023-01-01'),
('weekly',4,15385,38461,1971.20,25,'2023-01-01'),
('weekly',5,38462,153845,7740.45,30,'2023-01-01'),
('weekly',6,153846,NULL,42355.65,35,'2023-01-01'),
('semi_monthly',1,0,10417,0,0,'2023-01-01'),
('semi_monthly',2,10417,16666,0,15,'2023-01-01'),
('semi_monthly',3,16667,33332,937.50,20,'2023-01-01'),
('semi_monthly',4,33333,83332,4270.70,25,'2023-01-01'),
('semi_monthly',5,83333,333332,16770.70,30,'2023-01-01'),
('semi_monthly',6,333333,NULL,91770.70,35,'2023-01-01'),
('monthly',1,0,20833,0,0,'2023-01-01'),
('monthly',2,20833,33332,0,15,'2023-01-01'),
('monthly',3,33333,66666,1875.00,20,'2023-01-01'),
('monthly',4,66667,166666,8541.80,25,'2023-01-01'),
('monthly',5,166667,666666,33541.80,30,'2023-01-01'),
('monthly',6,666667,NULL,183541.80,35,'2023-01-01'),
('annual',1,0,250000,0,0,'2023-01-01'),
('annual',2,250000,400000,0,15,'2023-01-01'),
('annual',3,400000,800000,22500,20,'2023-01-01'),
('annual',4,800000,2000000,102500,25,'2023-01-01'),
('annual',5,2000000,8000000,402500,30,'2023-01-01'),
('annual',6,8000000,NULL,2202500,35,'2023-01-01');

INSERT INTO philhealth_params (effective_from, rate_pct, floor_salary, ceiling_salary, ee_share_pct, legal_basis) VALUES
('2024-01-01',5.00,10000,100000,50,'RA 11223 (UHC Act); PhilHealth Circular 2020-0005; no increase for 2026');

INSERT INTO pagibig_table (range_from, range_to, ee_rate_pct, er_rate_pct, max_comp, effective_from) VALUES
(0,1500,1.00,2.00,10000,'2024-02-01'),
(1500.01,NULL,2.00,2.00,10000,'2024-02-01');

INSERT INTO premium_rates (day_type, label, work_multiplier, ot_multiplier, legal_basis) VALUES
('ordinary','Ordinary working day',1.00,1.25,'Labor Code Art. 87'),
('rest_day','Rest day',1.30,1.30,'Labor Code Art. 93'),
('special_holiday','Special non-working day',1.30,1.30,'Labor Code Art. 93; DOLE holiday pay rules'),
('special_rest','Special non-working day falling on rest day',1.50,1.30,'DOLE holiday pay rules'),
('regular_holiday','Regular holiday',2.00,1.30,'Labor Code Art. 94'),
('regular_rest','Regular holiday falling on rest day',2.60,1.30,'DOLE holiday pay rules'),
('double_holiday','Double regular holiday',3.00,1.30,'DOLE holiday pay rules');

INSERT INTO holidays (holiday_date, name, holiday_type) VALUES
('2026-01-01','New Year''s Day','regular'),
('2026-02-17','Chinese New Year','special'),
('2026-04-02','Maundy Thursday','regular'),
('2026-04-03','Good Friday','regular'),
('2026-04-04','Black Saturday','special'),
('2026-04-09','Araw ng Kagitingan','regular'),
('2026-05-01','Labor Day','regular'),
('2026-06-12','Independence Day','regular'),
('2026-08-21','Ninoy Aquino Day','special'),
('2026-08-31','National Heroes Day','regular'),
('2026-11-01','All Saints'' Day','special'),
('2026-11-02','All Souls'' Day','special'),
('2026-11-30','Bonifacio Day','regular'),
('2026-12-08','Feast of the Immaculate Conception','special'),
('2026-12-24','Christmas Eve','special'),
('2026-12-25','Christmas Day','regular'),
('2026-12-30','Rizal Day','regular'),
('2026-12-31','Last Day of the Year','special');

INSERT INTO late_brackets (from_minutes, to_minutes, deduct_minutes, remarks) VALUES
(1,15,15,'Rounded up to 15 minutes'),
(16,30,30,'Rounded up to 30 minutes'),
(31,60,60,'Rounded up to 1 hour'),
(61,240,240,'Half-day'),
(241,480,480,'Whole day');

INSERT INTO deduction_types (code, name, category, computation, default_amount, frequency, authorized_by_law, requires_approval, priority, is_system) VALUES
('SSS','SSS Contribution (EE share)','statutory','fixed',0,'every_payroll',1,0,1,1),
('PHIC','PhilHealth Contribution (EE share)','statutory','fixed',0,'every_payroll',1,0,2,1),
('HDMF','Pag-IBIG Contribution (EE share)','statutory','fixed',0,'every_payroll',1,0,3,1),
('WTAX','Withholding Tax on Compensation','statutory','fixed',0,'every_payroll',1,0,4,1),
('SSS-SL','SSS Salary Loan','loan','ledger',0,'every_payroll',1,0,10,0),
('SSS-CL','SSS Calamity Loan','loan','ledger',0,'every_payroll',1,0,11,0),
('HDMF-MPL','Pag-IBIG Multi-Purpose Loan','loan','ledger',0,'every_payroll',1,0,12,0),
('HDMF-CAL','Pag-IBIG Calamity Loan','loan','ledger',0,'every_payroll',1,0,13,0),
('CO-LOAN','Company Loan','loan','ledger',0,'every_payroll',0,1,20,0),
('CA','Cash Advance','loan','ledger',0,'every_payroll',0,1,21,0),
('COOP','Cooperative Contribution','recurring','fixed',500,'second_cutoff',0,0,30,0),
('UNION','Union Dues','recurring','fixed',100,'first_cutoff',1,0,31,0),
('UNIF','Uniform Cost Recovery','recurring','fixed',250,'every_payroll',0,0,32,0),
('CANTEEN','Canteen Charges','recurring','fixed',0,'every_payroll',0,0,33,0),
('DAMAGE','Loss / Damage Accountability','accountability','ledger',0,'every_payroll',0,1,40,0),
('ADHOC','Ad hoc / One-time Deduction','adhoc','fixed',0,'every_payroll',0,1,50,0);

INSERT INTO benefit_types (code, name, category, tax_treatment, ceiling_amount, ceiling_period, default_amount, frequency, eligibility_regular_only, eligibility_min_months, legal_basis) VALUES
('RICE','Rice Subsidy','de_minimis','de_minimis',2500,'monthly',2500,'second_cutoff',0,0,'RR 29-2025: PHP 2,500/month'),
('UNIFORM','Uniform & Clothing Allowance','de_minimis','de_minimis',8000,'annual',8000,'monthly_last',0,0,'RR 29-2025: PHP 8,000/year'),
('MEDCASH','Medical Cash Allowance to Dependents','de_minimis','de_minimis',333,'monthly',333,'second_cutoff',1,0,'RR 29-2025: PHP 2,000/semester (PHP 333/month)'),
('LAUNDRY','Laundry Allowance','de_minimis','de_minimis',400,'monthly',400,'second_cutoff',0,0,'RR 29-2025: PHP 400/month'),
('MEAL-OT','Meal Allowance (OT / Night Shift)','de_minimis','de_minimis',NULL,'none',0,'every_payroll',0,0,'RR 29-2025: up to 30% of basic minimum wage'),
('TRANSPO','Transportation Allowance','company','taxable',NULL,'none',1000,'every_payroll',0,0,'Taxable fixed allowance'),
('HMO','HMO Coverage (company-paid premium)','company','non_taxable',NULL,'none',0,'every_payroll',1,0,'Company policy (information only)'),
('PERF','Performance Bonus','company','other_benefit',NULL,'none',0,'monthly_last',1,6,'Counts toward PHP 90,000 exemption'),
('PROVIDENT','Provident Fund (ER contribution)','company','non_taxable',NULL,'none',0,'every_payroll',1,12,'Company policy');

INSERT INTO import_profiles (name, delimiter, has_header, col_bio_id, col_datetime, col_date, col_time, datetime_format, col_punch, punch_map, col_device, notes) VALUES
('ZKTeco attlog.dat (tab)','tab',0,1,2,NULL,NULL,'Y-m-d H:i:s',4,'0=in,1=out,2=break_out,3=break_in,4=in,5=out',NULL,'Columns: UserID, DateTime, DeviceNo, PunchState, VerifyMode, WorkCode'),
('Generic CSV (ID,Date,Time,Type)','comma',1,1,NULL,2,3,'Y-m-d H:i',4,'I=in,O=out,BO=break_out,BI=break_in,IN=in,OUT=out',NULL,'Header row; date YYYY-MM-DD; time HH:MM'),
('Space-delimited, no punch type','space',0,1,NULL,2,3,'Y-m-d H:i:s',NULL,NULL,NULL,'Punch types inferred from sequence: first=in, last=out');

-- SSS Contribution Schedule (RA 11199) effective January 2025, unchanged for 2026
INSERT INTO sss_table (range_from,range_to,msc_regular,msc_mpf,ee_regular,ee_mpf,er_regular,er_mpf,ec,effective_from) VALUES
(0.00,5249.99,5000.00,0.00,250.00,0.00,500.00,0.00,10.00,'2025-01-01'),
(5250.00,5749.99,5500.00,0.00,275.00,0.00,550.00,0.00,10.00,'2025-01-01'),
(5750.00,6249.99,6000.00,0.00,300.00,0.00,600.00,0.00,10.00,'2025-01-01'),
(6250.00,6749.99,6500.00,0.00,325.00,0.00,650.00,0.00,10.00,'2025-01-01'),
(6750.00,7249.99,7000.00,0.00,350.00,0.00,700.00,0.00,10.00,'2025-01-01'),
(7250.00,7749.99,7500.00,0.00,375.00,0.00,750.00,0.00,10.00,'2025-01-01'),
(7750.00,8249.99,8000.00,0.00,400.00,0.00,800.00,0.00,10.00,'2025-01-01'),
(8250.00,8749.99,8500.00,0.00,425.00,0.00,850.00,0.00,10.00,'2025-01-01'),
(8750.00,9249.99,9000.00,0.00,450.00,0.00,900.00,0.00,10.00,'2025-01-01'),
(9250.00,9749.99,9500.00,0.00,475.00,0.00,950.00,0.00,10.00,'2025-01-01'),
(9750.00,10249.99,10000.00,0.00,500.00,0.00,1000.00,0.00,10.00,'2025-01-01'),
(10250.00,10749.99,10500.00,0.00,525.00,0.00,1050.00,0.00,10.00,'2025-01-01'),
(10750.00,11249.99,11000.00,0.00,550.00,0.00,1100.00,0.00,10.00,'2025-01-01'),
(11250.00,11749.99,11500.00,0.00,575.00,0.00,1150.00,0.00,10.00,'2025-01-01'),
(11750.00,12249.99,12000.00,0.00,600.00,0.00,1200.00,0.00,10.00,'2025-01-01'),
(12250.00,12749.99,12500.00,0.00,625.00,0.00,1250.00,0.00,10.00,'2025-01-01'),
(12750.00,13249.99,13000.00,0.00,650.00,0.00,1300.00,0.00,10.00,'2025-01-01'),
(13250.00,13749.99,13500.00,0.00,675.00,0.00,1350.00,0.00,10.00,'2025-01-01'),
(13750.00,14249.99,14000.00,0.00,700.00,0.00,1400.00,0.00,10.00,'2025-01-01'),
(14250.00,14749.99,14500.00,0.00,725.00,0.00,1450.00,0.00,10.00,'2025-01-01'),
(14750.00,15249.99,15000.00,0.00,750.00,0.00,1500.00,0.00,30.00,'2025-01-01'),
(15250.00,15749.99,15500.00,0.00,775.00,0.00,1550.00,0.00,30.00,'2025-01-01'),
(15750.00,16249.99,16000.00,0.00,800.00,0.00,1600.00,0.00,30.00,'2025-01-01'),
(16250.00,16749.99,16500.00,0.00,825.00,0.00,1650.00,0.00,30.00,'2025-01-01'),
(16750.00,17249.99,17000.00,0.00,850.00,0.00,1700.00,0.00,30.00,'2025-01-01'),
(17250.00,17749.99,17500.00,0.00,875.00,0.00,1750.00,0.00,30.00,'2025-01-01'),
(17750.00,18249.99,18000.00,0.00,900.00,0.00,1800.00,0.00,30.00,'2025-01-01'),
(18250.00,18749.99,18500.00,0.00,925.00,0.00,1850.00,0.00,30.00,'2025-01-01'),
(18750.00,19249.99,19000.00,0.00,950.00,0.00,1900.00,0.00,30.00,'2025-01-01'),
(19250.00,19749.99,19500.00,0.00,975.00,0.00,1950.00,0.00,30.00,'2025-01-01'),
(19750.00,20249.99,20000.00,0.00,1000.00,0.00,2000.00,0.00,30.00,'2025-01-01'),
(20250.00,20749.99,20000.00,500.00,1000.00,25.00,2000.00,50.00,30.00,'2025-01-01'),
(20750.00,21249.99,20000.00,1000.00,1000.00,50.00,2000.00,100.00,30.00,'2025-01-01'),
(21250.00,21749.99,20000.00,1500.00,1000.00,75.00,2000.00,150.00,30.00,'2025-01-01'),
(21750.00,22249.99,20000.00,2000.00,1000.00,100.00,2000.00,200.00,30.00,'2025-01-01'),
(22250.00,22749.99,20000.00,2500.00,1000.00,125.00,2000.00,250.00,30.00,'2025-01-01'),
(22750.00,23249.99,20000.00,3000.00,1000.00,150.00,2000.00,300.00,30.00,'2025-01-01'),
(23250.00,23749.99,20000.00,3500.00,1000.00,175.00,2000.00,350.00,30.00,'2025-01-01'),
(23750.00,24249.99,20000.00,4000.00,1000.00,200.00,2000.00,400.00,30.00,'2025-01-01'),
(24250.00,24749.99,20000.00,4500.00,1000.00,225.00,2000.00,450.00,30.00,'2025-01-01'),
(24750.00,25249.99,20000.00,5000.00,1000.00,250.00,2000.00,500.00,30.00,'2025-01-01'),
(25250.00,25749.99,20000.00,5500.00,1000.00,275.00,2000.00,550.00,30.00,'2025-01-01'),
(25750.00,26249.99,20000.00,6000.00,1000.00,300.00,2000.00,600.00,30.00,'2025-01-01'),
(26250.00,26749.99,20000.00,6500.00,1000.00,325.00,2000.00,650.00,30.00,'2025-01-01'),
(26750.00,27249.99,20000.00,7000.00,1000.00,350.00,2000.00,700.00,30.00,'2025-01-01'),
(27250.00,27749.99,20000.00,7500.00,1000.00,375.00,2000.00,750.00,30.00,'2025-01-01'),
(27750.00,28249.99,20000.00,8000.00,1000.00,400.00,2000.00,800.00,30.00,'2025-01-01'),
(28250.00,28749.99,20000.00,8500.00,1000.00,425.00,2000.00,850.00,30.00,'2025-01-01'),
(28750.00,29249.99,20000.00,9000.00,1000.00,450.00,2000.00,900.00,30.00,'2025-01-01'),
(29250.00,29749.99,20000.00,9500.00,1000.00,475.00,2000.00,950.00,30.00,'2025-01-01'),
(29750.00,30249.99,20000.00,10000.00,1000.00,500.00,2000.00,1000.00,30.00,'2025-01-01'),
(30250.00,30749.99,20000.00,10500.00,1000.00,525.00,2000.00,1050.00,30.00,'2025-01-01'),
(30750.00,31249.99,20000.00,11000.00,1000.00,550.00,2000.00,1100.00,30.00,'2025-01-01'),
(31250.00,31749.99,20000.00,11500.00,1000.00,575.00,2000.00,1150.00,30.00,'2025-01-01'),
(31750.00,32249.99,20000.00,12000.00,1000.00,600.00,2000.00,1200.00,30.00,'2025-01-01'),
(32250.00,32749.99,20000.00,12500.00,1000.00,625.00,2000.00,1250.00,30.00,'2025-01-01'),
(32750.00,33249.99,20000.00,13000.00,1000.00,650.00,2000.00,1300.00,30.00,'2025-01-01'),
(33250.00,33749.99,20000.00,13500.00,1000.00,675.00,2000.00,1350.00,30.00,'2025-01-01'),
(33750.00,34249.99,20000.00,14000.00,1000.00,700.00,2000.00,1400.00,30.00,'2025-01-01'),
(34250.00,34749.99,20000.00,14500.00,1000.00,725.00,2000.00,1450.00,30.00,'2025-01-01'),
(34750.00,NULL,20000.00,15000.00,1000.00,750.00,2000.00,1500.00,30.00,'2025-01-01');

-- ---------------------------------------------------------------------
--  ADMINISTRATOR ACCOUNT (admin / admin123, must be changed at first sign-in)
-- ---------------------------------------------------------------------
INSERT INTO `users` (`username`, `password_hash`, `full_name`, `role`, `is_active`, `must_change_password`) VALUES
('admin', '$2y$12$eJWqe8IXj4M2e1NaOLAhjewDaAbVyCrzNounwmkVIOGAYp/u38Cz6', 'System Administrator', 'admin', 1, 1);

SET UNIQUE_CHECKS = 1;
SET FOREIGN_KEY_CHECKS = 1;
