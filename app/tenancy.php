<?php
/**
 * Multi-company / multi-database layer.
 *
 *   ONE application  +  ONE master database (ihris_master)  +  ONE operational database per company.
 *
 * The master database only holds platform data: the companies, their database mapping, the Company
 * Codes, the global login identity (username / email -> company) of every company account, the
 * Super Admin accounts and the platform audit / provisioning logs. Every HR, attendance and payroll
 * record stays in the company's own database (Company 1 = the existing hris_web database).
 *
 * The active company is resolved once per request from the server-side session (never from a URL or
 * form value) and db() in app/db.php then points at that company's database, so the existing modules
 * keep calling q() / row() / rows() / insert() / update() / tx() unchanged.
 *
 * Session keys: uid (user id inside the company database), cid (company id), sa (Super Admin id),
 * sa_cid (company a Super Admin has entered for support).
 */

const MASTER_VERSION = 2;
const COMPANY_STATUSES = ['provisioning' => 'Provisioning', 'active' => 'Active', 'inactive' => 'Inactive', 'failed' => 'Failed'];

/* ================================================================== configuration */

/** Multi-company mode is on unless config sets 'multi_company' => false (then the app runs as before). */
function mc_enabled(): bool
{
    return cfg('multi_company', true) !== false;
}

function master_db_name(): string
{
    return (string)cfg('master_db_name', 'ihris_master');
}

function company_db_prefix(): string
{
    return (string)cfg('company_db_prefix', 'ihris_company_');
}

/** Database identifiers are never built from user input; they are validated before any use in SQL. */
function mc_valid_db_name(string $n): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_$\-]{1,64}$/', $n);
}

function mc_quote_ident(string $n): string
{
    if (!mc_valid_db_name($n)) {
        throw new RuntimeException('Invalid database name.');
    }
    return '`' . $n . '`';
}

/* ================================================================== master database */

/**
 * Connection to the master database, created (with Company 1 = the configured database) on first use.
 * Returns null when multi-company is switched off or the master database cannot be reached / created;
 * the application then keeps working as a single-company installation.
 */
function master_db(): ?PDO
{
    static $pdo = null, $tried = false;
    if ($tried) {
        return $pdo;
    }
    $tried = true;
    if (!mc_enabled()) {
        return null;
    }
    $name = master_db_name();
    if (!mc_valid_db_name($name)) {
        $GLOBALS['__master_error'] = 'The master database name in the configuration is not valid.';
        return null;
    }
    try {
        try {
            $pdo = db_pdo($name);
        } catch (PDOException $e) {
            if ((int)$e->getCode() !== 1049 && stripos($e->getMessage(), 'Unknown database') === false) {
                throw $e;
            }
            // unknown database: create it (needs CREATE privilege on the server)
            db_server_pdo()->exec('CREATE DATABASE IF NOT EXISTS ' . mc_quote_ident($name) . ' DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo = db_pdo($name);
        }
        master_upgrade($pdo);
    } catch (Throwable $e) {
        $pdo = null;
        $GLOBALS['__master_error'] = $e->getMessage();
    }
    return $pdo;
}

/** Why the master database is not available (shown to administrators only). */
function master_error(): ?string
{
    return $GLOBALS['__master_error'] ?? null;
}

/** MySQL 5.5 compatible definitions of the master tables. */
function master_schema(): array
{
    $e = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        'master_meta' => "CREATE TABLE IF NOT EXISTS master_meta (
  mkey VARCHAR(40) NOT NULL,
  mvalue VARCHAR(255) NULL,
  PRIMARY KEY (mkey)
)$e",
        'companies' => "CREATE TABLE IF NOT EXISTS companies (
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
)$e",
        'company_codes' => "CREATE TABLE IF NOT EXISTS company_codes (
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
)$e",
        'company_memberships' => "CREATE TABLE IF NOT EXISTS company_memberships (
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
)$e",
        'super_admins' => "CREATE TABLE IF NOT EXISTS super_admins (
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
)$e",
        'company_provisioning_logs' => "CREATE TABLE IF NOT EXISTS company_provisioning_logs (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  step VARCHAR(60) NOT NULL,
  status ENUM('ok','failed','info') NOT NULL DEFAULT 'ok',
  message TEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_prov_company (company_id)
)$e",
        'master_audit_log' => "CREATE TABLE IF NOT EXISTS master_audit_log (
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
)$e",
        // a user of one company who may switch to another company (an account of their own there, with its
        // own role and permissions), without being a Super Admin
        'company_user_links' => "CREATE TABLE IF NOT EXISTS company_user_links (
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
)$e",
        'join_attempts' => "CREATE TABLE IF NOT EXISTS join_attempts (
  id INT NOT NULL AUTO_INCREMENT,
  ip_address VARCHAR(45) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_join_ip (ip_address, created_at)
)$e",
    ];
}

/**
 * Stand-alone script that creates the master database and its tables (MySQL 5.5), for servers where
 * the application's MySQL account may not create databases. Company 1, its Company Code and the first
 * Super Admin are registered by the application on the next page load.
 */
function master_sql_script(): string
{
    $out = [
        '-- =====================================================================',
        '--  G-SMaRT HRIS multi-company MASTER database (MySQL 5.5.3+ / MariaDB 5.5+)',
        '--  Only needed when the MySQL account in config/config.php cannot create databases.',
        '--  Run it as a MySQL administrator, then open the site: the configured database',
        '--  (hris_web) is registered as Company 1 and the Super Admin account is created.',
        '--  Change `' . master_db_name() . '` below if config sets another master_db_name.',
        '-- =====================================================================',
        'CREATE DATABASE IF NOT EXISTS `' . master_db_name() . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
        'USE `' . master_db_name() . '`;',
        '',
    ];
    foreach (master_schema() as $t => $sql) {
        $out[] = "-- $t";
        $out[] = $sql . ';';
        $out[] = '';
    }
    $out[] = '-- The application account needs every right on the master database and on the company databases:';
    foreach (explode("\n", mc_required_grants_sql()) as $l) {
        $out[] = '-- ' . $l;
    }
    return implode("\n", $out) . "\n";
}

/** Create the master tables when missing and register the existing database as Company 1 (idempotent). */
function master_upgrade(PDO $m): void
{
    $ver = 0;
    try {
        $ver = (int)$m->query("SELECT mvalue FROM master_meta WHERE mkey = 'version'")->fetchColumn();
    } catch (PDOException $e) {
        $ver = 0;
    }
    if ($ver >= MASTER_VERSION) {
        return;
    }
    foreach (master_schema() as $sql) {
        $m->exec($sql);
    }
    master_register_primary($m);
    master_seed_super_admin($m);
    $m->prepare("INSERT INTO master_meta (mkey, mvalue) VALUES ('version', ?) ON DUPLICATE KEY UPDATE mvalue = VALUES(mvalue)")->execute([(string)MASTER_VERSION]);
}

/**
 * The database named in config/config.php (hris_web) becomes Company 1: registered in place, nothing is
 * copied, moved or changed in it. Its user accounts are mapped to Company 1 for sign-in.
 */
function master_register_primary(PDO $m): void
{
    $pid = (int)$m->query('SELECT id FROM companies WHERE is_primary = 1 ORDER BY id LIMIT 1')->fetchColumn();
    if ($pid) {
        // registered by hand: make sure it has a Company Code
        if (!(int)$m->query('SELECT COUNT(*) FROM company_codes WHERE company_id = ' . $pid)->fetchColumn()) {
            $name = (string)$m->query('SELECT name FROM companies WHERE id = ' . $pid)->fetchColumn();
            $m->prepare("INSERT INTO company_codes (company_id, code, status, created_by, created_at) VALUES (?, ?, 'active', 'system', ?)")
              ->execute([$pid, company_code_generate($name, $m), date('Y-m-d H:i:s')]);
        }
        return;
    }
    $dbName = (string)cfg('db_name');
    $name = 'Company 1';
    $address = null;
    try {
        $p = db_pdo($dbName);
        $st = $p->query("SELECT skey, svalue FROM settings WHERE skey IN ('company_name', 'company_address')");
        foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
            if ($k === 'company_name' && trim((string)$v) !== '') {
                $name = trim((string)$v);
            } elseif ($k === 'company_address') {
                $address = $v;
            }
        }
    } catch (Throwable $e) {
        // not imported yet: the name can be set later by the Super Admin
    }
    $now = date('Y-m-d H:i:s');
    $m->prepare("INSERT IGNORE INTO companies (id, name, address, db_name, is_primary, status, db_status, created_at, activated_at)
                 VALUES (1, ?, ?, ?, 1, 'active', 'ready', ?, ?)")->execute([$name, $address, $dbName, $now, $now]);
    $cid = (int)$m->query('SELECT id FROM companies WHERE is_primary = 1 LIMIT 1')->fetchColumn();
    if (!$cid) {
        // id 1 was taken by an unrelated row: register with the next id
        $m->prepare("INSERT INTO companies (name, address, db_name, is_primary, status, db_status, created_at, activated_at) VALUES (?, ?, ?, 1, 'active', 'ready', ?, ?)")
          ->execute([$name, $address, $dbName, $now, $now]);
        $cid = (int)$m->lastInsertId();
    }
    if (!(int)$m->query('SELECT COUNT(*) FROM company_codes WHERE company_id = ' . $cid)->fetchColumn()) {
        $code = company_code_generate($name, $m);
        $m->prepare("INSERT INTO company_codes (company_id, code, status, created_by, created_at) VALUES (?, ?, 'active', 'system', ?)")->execute([$cid, $code, $now]);
    }
    $m->prepare("INSERT INTO company_provisioning_logs (company_id, step, status, message, created_at) VALUES (?, 'register_existing', 'ok', ?, ?)")
      ->execute([$cid, 'Existing database registered as Company ' . $cid . '. No record was copied, moved or changed.', $now]);
    $m->prepare("INSERT INTO master_audit_log (actor_type, actor_name, company_id, action, details, created_at) VALUES ('system', 'system', ?, 'company_registered', ?, ?)")
      ->execute([$cid, 'Existing installation registered as Company ' . $cid, $now]);
    try {
        mc_sync_company_pdo($m, $cid, db_pdo($dbName));
    } catch (Throwable $e) {
        // the users are mapped on their first sign-in instead
    }
}

/** First Super Admin account (superadmin / SuperAdmin#2026, must be changed at first sign-in). */
function master_seed_super_admin(PDO $m): void
{
    if ((int)$m->query('SELECT COUNT(*) FROM super_admins')->fetchColumn()) {
        return;
    }
    $user = (string)cfg('super_admin_username', 'superadmin');
    $taken = $m->prepare('SELECT COUNT(*) FROM company_memberships WHERE username = ?');
    $base = $user;
    for ($i = 2; ; $i++) {
        $taken->execute([$user]);
        if (!(int)$taken->fetchColumn()) {
            break;
        }
        $user = $base . $i;
    }
    $m->prepare('INSERT IGNORE INTO super_admins (username, full_name, password_hash, is_active, must_change_password, created_at) VALUES (?, ?, ?, 1, 1, ?)')
      ->execute([$user, 'Super Administrator', password_hash((string)cfg('super_admin_password', 'SuperAdmin#2026'), PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
}

/* ---------------------------------------------------------------- master query helpers */

function mq(string $sql, array $params = []): PDOStatement
{
    $m = master_db();
    if (!$m) {
        throw new RuntimeException('The multi-company master database is not available.');
    }
    $st = $m->prepare($sql);
    $st->execute($params);
    return $st;
}

function mrow(string $sql, array $params = []): ?array
{
    $r = mq($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function mrows(string $sql, array $params = []): array
{
    return mq($sql, $params)->fetchAll();
}

function mval(string $sql, array $params = [])
{
    $v = mq($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function mpairs(string $sql, array $params = []): array
{
    return mq($sql, $params)->fetchAll(PDO::FETCH_KEY_PAIR);
}

function minsert(string $table, array $data): int
{
    $cols = array_keys($data);
    mq('INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($data));
    return (int)master_db()->lastInsertId();
}

function mupdate(string $table, array $data, string $where, array $whereParams = []): int
{
    $set = [];
    foreach (array_keys($data) as $c) {
        $set[] = '`' . $c . '` = ?';
    }
    return mq('UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE ' . $where, array_merge(array_values($data), $whereParams))->rowCount();
}

/** Platform audit trail (Super Admin and company-level platform actions). Never stores credentials. */
function master_audit(string $action, ?int $companyId = null, $details = null): void
{
    if (!master_db()) {
        return;
    }
    $sa = super_admin();
    $u = $sa ? null : current_user();
    if (is_array($details)) {
        $details = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    try {
        minsert('master_audit_log', [
            'actor_type' => $sa ? 'super_admin' : ($u ? (is_company_owner() ? 'owner' : 'company_user') : (PHP_SAPI === 'cli' ? 'system' : 'guest')),
            'actor_id' => $sa ? (int)$sa['id'] : ($u ? (int)$u['id'] : null),
            'actor_name' => $sa ? $sa['username'] : ($u['username'] ?? (PHP_SAPI === 'cli' ? 'system' : 'guest')),
            'company_id' => $companyId,
            'action' => $action,
            'details' => $details !== null ? mb_substr((string)$details, 0, 60000) : null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'created_at' => now(),
        ]);
    } catch (Throwable $e) {
        // never block the action because of the audit trail
    }
}

function provision_log(int $companyId, string $step, string $status, string $message = ''): void
{
    try {
        minsert('company_provisioning_logs', ['company_id' => $companyId, 'step' => $step, 'status' => $status, 'message' => $message, 'created_at' => now()]);
    } catch (Throwable $e) {
    }
}

/* ================================================================== companies and codes */

function company_by_id(int $id): ?array
{
    if (!master_db()) {
        return null;
    }
    return mrow('SELECT * FROM companies WHERE id = ?', [$id]);
}

function primary_company(): ?array
{
    if (!master_db()) {
        return null;
    }
    return mrow('SELECT * FROM companies WHERE is_primary = 1 ORDER BY id LIMIT 1');
}

/** The Company Code in force (null when none). */
function company_code(int $companyId): ?string
{
    if (!master_db()) {
        return null;
    }
    $c = mval("SELECT code FROM company_codes WHERE company_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1", [$companyId]);
    return $c !== null ? (string)$c : null;
}

/** A new unique Company Code: up to three letters of the company name and random characters (no 0/O/1/I). */
function company_code_generate(string $name, PDO $m): string
{
    $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $prefix = substr(preg_replace('/[^A-HJ-NP-Z]/', '', strtoupper($name)), 0, 3);
    $st = $m->prepare('SELECT COUNT(*) FROM company_codes WHERE code = ?');
    for ($i = 0; $i < 60; $i++) {
        $len = $i < 30 ? 6 : 8;
        $code = $i < 30 ? $prefix : '';
        while (strlen($code) < $len) {
            $code .= $alpha[random_int(0, strlen($alpha) - 1)];
        }
        // every code must contain a digit so it never reads as a word
        if (!preg_match('/\d/', $code)) {
            $code = substr($code, 0, -1) . $alpha[random_int(24, 31)];
        }
        $st->execute([$code]);
        if (!(int)$st->fetchColumn()) {
            return $code;
        }
    }
    throw new RuntimeException('Could not generate a unique Company Code.');
}

/** Replace the Company Code: the old code stops working for new registrations; existing accounts are untouched. */
function company_code_regenerate(int $companyId): string
{
    $co = company_by_id($companyId);
    if (!$co) {
        throw new RuntimeException('Company not found.');
    }
    $old = company_code($companyId);
    $code = company_code_generate($co['name'], master_db());
    $actor = super_admin()['username'] ?? (current_user()['username'] ?? 'system');
    mq("UPDATE company_codes SET status = 'replaced', replaced_at = ? WHERE company_id = ? AND status = 'active'", [now(), $companyId]);
    minsert('company_codes', ['company_id' => $companyId, 'code' => $code, 'status' => 'active', 'created_by' => $actor, 'created_at' => now()]);
    master_audit('company_code_regenerated', $companyId, ['old_code' => $old, 'new_code' => $code]);
    return $code;
}

/** Company of a Company Code that may take new registrations (active code of an active company). */
function company_by_code(string $code): ?array
{
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    if ($code === '' || strlen($code) > 16 || !master_db()) {
        return null;
    }
    return mrow("SELECT c.* FROM company_codes k JOIN companies c ON c.id = k.company_id
                 WHERE k.code = ? AND k.status = 'active' AND c.status = 'active' AND c.db_status = 'ready'", [$code]);
}

/* ================================================================== request context */

/**
 * Company the current request works in (from the session only). Null in single-company mode.
 *   Super Admin in a company  -> that company;  signed-in user -> their company;
 *   the e-mail leave approval page -> the company named in its link (the single-use token decides);
 *   anything else (sign-in page, registration) -> Company 1.
 */
function tenant_company(): ?array
{
    if (array_key_exists('__tenant', $GLOBALS)) {
        return $GLOBALS['__tenant'];
    }
    $GLOBALS['__tenant'] = null;
    if (!master_db()) {
        return null;
    }
    $cid = null;
    if (PHP_SAPI !== 'cli' || session_status() === PHP_SESSION_ACTIVE) {
        if (!empty($_SESSION['sa'])) {
            $cid = !empty($_SESSION['sa_cid']) ? (int)$_SESSION['sa_cid'] : null;
        } elseif (!empty($_SESSION['uid'])) {
            $cid = !empty($_SESSION['cid']) ? (int)$_SESSION['cid'] : null;
        } elseif (in_array($_GET['p'] ?? '', ['leave_action', 'bio_push'], true) && ctype_digit((string)($_GET['co'] ?? ''))) {
            // the e-mail approval link and the office PC agent of the biometric terminals name their company
            $cid = (int)$_GET['co'];
        }
    }
    $co = $cid ? company_by_id($cid) : null;
    if ($co && (!mc_valid_db_name($co['db_name']) || in_array($co['status'], ['provisioning', 'failed'], true))) {
        $co = null;
    }
    if (!$co && !$cid) {
        $co = primary_company();
    }
    $GLOBALS['__tenant'] = $co;
    return $co;
}

/** Forget the resolved company (after sign-in, entering or leaving a company). */
function tenant_reset(): void
{
    unset($GLOBALS['__tenant']);
    $GLOBALS['__cu_cache'] = [];
    setting('__reload__');
}

/** Database of the active company (the configured database in single-company mode). */
function tenant_db_name(): string
{
    $co = tenant_company();
    if ($co) {
        return (string)$co['db_name'];
    }
    if (master_db() && array_key_exists('__tenant', $GLOBALS) && (!empty($_SESSION['cid']) || !empty($_SESSION['sa_cid']))) {
        // a company is named in the session but is not usable: never fall back to another company's data
        return '__no_company__';
    }
    return (string)$GLOBALS['config']['db_name'];
}

function current_company_id(): ?int
{
    $co = tenant_company();
    return $co ? (int)$co['id'] : null;
}

/** Display name of the active company. */
function current_company_name(): string
{
    $co = tenant_company();
    return $co ? (string)$co['name'] : (string)setting('company_name', 'Company');
}

/**
 * Run $fn against another company database (provisioning, registration, sign-in checks, Super Admin
 * support tools). Inside, there is no signed-in user, so nothing is written under someone else's name.
 */
function with_company_db(string $dbName, callable $fn)
{
    if (!mc_valid_db_name($dbName)) {
        throw new RuntimeException('Invalid database name.');
    }
    $prevDb = $GLOBALS['__db_override'] ?? null;
    $prevCtx = $GLOBALS['__system_ctx'] ?? false;
    $GLOBALS['__db_override'] = $dbName;
    $GLOBALS['__system_ctx'] = true;
    setting('__reload__');
    try {
        return $fn();
    } finally {
        $GLOBALS['__db_override'] = $prevDb;
        $GLOBALS['__system_ctx'] = $prevCtx;
        setting('__reload__');
    }
}

/* ================================================================== Super Admin and Company Owner */

function super_admin(): ?array
{
    if (empty($_SESSION['sa']) || !master_db()) {
        return null;
    }
    if (!array_key_exists('__sa', $GLOBALS)) {
        $GLOBALS['__sa'] = mrow('SELECT * FROM super_admins WHERE id = ? AND is_active = 1', [(int)$_SESSION['sa']]);
    }
    return $GLOBALS['__sa'];
}

/** Super Admin on the platform pages (no company entered). */
function is_super_mode(): bool
{
    return super_admin() !== null && empty($_SESSION['sa_cid']);
}

/** Super Admin working inside a company (support session). */
function in_company_support(): bool
{
    return super_admin() !== null && !empty($_SESSION['sa_cid']);
}

/** Is the signed-in user the Company Owner of the active company? */
function is_company_owner(): bool
{
    $co = tenant_company();
    $u = current_user();
    return $co && $u && empty($u['is_support']) && $co['owner_user_id'] && (int)$co['owner_user_id'] === (int)$u['id'];
}

/** Company Owner pages: the owner, or a Super Admin inside the company. */
function owner_or_support(): bool
{
    return master_db() !== null && (is_company_owner() || in_company_support());
}

/** The account shown in the page frame (a company user, or the Super Admin on the platform pages). */
function layout_user(): ?array
{
    $u = current_user();
    if ($u) {
        return $u;
    }
    $sa = is_super_mode() ? super_admin() : null;
    return $sa ? ['id' => 0, 'username' => $sa['username'], 'full_name' => $sa['full_name'], 'role' => 'super', 'employee_id' => null, 'must_change_password' => $sa['must_change_password']] : null;
}

/**
 * Checks done on every request before the page runs: the session's company must still be usable,
 * a deactivated company signs its users out, and a Super Admin session stays valid.
 */
function tenant_guard(): void
{
    if (!master_db() || session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    if (!empty($_SESSION['sa'])) {
        if (!super_admin()) {
            tenant_signout('Your Super Admin account is no longer active.');
        }
        if (!empty($_SESSION['sa_cid'])) {
            $co = company_by_id((int)$_SESSION['sa_cid']);
            if (!$co || !in_array($co['status'], ['active', 'inactive'], true) || $co['db_status'] !== 'ready') {
                unset($_SESSION['sa_cid'], $_SESSION['uid'], $_SESSION['cid']);
                tenant_reset();
            }
        }
        return;
    }
    if (empty($_SESSION['uid'])) {
        return;
    }
    if (empty($_SESSION['cid'])) {
        // signed in before multi-company was switched on: that session belongs to Company 1
        $p = primary_company();
        $_SESSION['cid'] = $p ? (int)$p['id'] : 0;
        tenant_reset();
    }
    $co = company_by_id((int)$_SESSION['cid']);
    $home = $_SESSION['link_home'] ?? null;
    if ($home && (int)$home['cid'] !== (int)$_SESSION['cid']) {
        // working in a linked company: the link, the home company and the home account must still be active
        $hc = company_by_id((int)$home['cid']);
        $ok = $hc && $hc['status'] === 'active' && (int)mval("SELECT COUNT(*) FROM company_user_links WHERE home_company_id = ? AND home_user_id = ? AND company_id = ? AND user_id = ? AND status = 'active'",
            [(int)$home['cid'], (int)$home['uid'], (int)$_SESSION['cid'], (int)$_SESSION['uid']]);
        if ($ok) {
            try {
                $ok = (bool)with_company_db($hc['db_name'], fn() => val('SELECT is_active FROM users WHERE id = ?', [(int)$home['uid']]));
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        if (!$ok || !$co || $co['status'] !== 'active') {
            if (!$hc || $hc['status'] !== 'active') {
                tenant_signout('Your company\'s access to ' . app_name_plain() . ' is currently inactive. Please contact your Company Owner.');
            }
            // back to the home company
            session_regenerate_id(true);
            $_SESSION['cid'] = (int)$home['cid'];
            $_SESSION['uid'] = (int)$home['uid'];
            unset($GLOBALS['__switch_targets']);
            tenant_reset();
            flash('error', 'Your access to ' . ($co ? $co['name'] : 'that company') . ' was removed. You are back in ' . ($hc ? $hc['name'] : 'your company') . '.');
            redirect('dashboard');
        }
        return;
    }
    if (!$co || $co['status'] !== 'active') {
        tenant_signout('Your company\'s access to ' . app_name_plain() . ' is currently inactive. Please contact your Company Owner.');
    }
}

function app_name_plain(): string
{
    return function_exists('app_name') ? app_name() : 'G-SMaRT HRIS';
}

function tenant_signout(string $message): void
{
    $_SESSION = [];
    session_regenerate_id(true);
    tenant_reset();
    $GLOBALS['__tenant'] = primary_company();
    flash('error', $message);
    redirect('login');
}

/* ================================================================== global login identity */

/** Membership status of a company user row. */
function mc_member_status(array $u): string
{
    $r = (string)($u['reg_status'] ?? '');
    if ($r === 'pending') {
        return 'pending';
    }
    if ($r === 'rejected') {
        return 'rejected';
    }
    return !empty($u['is_active']) ? 'active' : 'inactive';
}

/** Is a username already used by any account of any company, or by a Super Admin? */
function mc_username_taken(string $username, ?int $companyId = null, ?int $userId = null): bool
{
    if (!master_db()) {
        return false;
    }
    $username = strtolower(trim($username));
    if ((int)mval('SELECT COUNT(*) FROM super_admins WHERE username = ?', [$username])) {
        return true;
    }
    $r = mrow('SELECT company_id, user_id FROM company_memberships WHERE username = ?', [$username]);
    return $r && !($companyId && $userId && (int)$r['company_id'] === $companyId && (int)$r['user_id'] === $userId);
}

/** Is an e-mail address already used by an account (registration requires a free address)? */
function mc_email_taken(string $email): bool
{
    if (!master_db() || trim($email) === '') {
        return false;
    }
    return (bool)mval("SELECT COUNT(*) FROM company_memberships WHERE email = ? AND status <> 'rejected'", [trim($email)])
        || (bool)mval('SELECT COUNT(*) FROM super_admins WHERE email = ?', [trim($email)]);
}

/** A username free across all companies, derived from $base (emp-0001, emp-0001.2, emp-0001.2b ...). */
function mc_unique_username(string $base): string
{
    $base = strtolower(trim($base));
    $cid = current_company_id();
    $local = fn(string $u) => (bool)val('SELECT COUNT(*) FROM users WHERE username = ?', [$u]);
    if (!$local($base) && !mc_username_taken($base)) {
        return $base;
    }
    $cand = substr($base, 0, 50) . ($cid ? '.' . $cid : '');
    for ($i = 0; $i < 50; $i++) {
        $u = $i ? $cand . chr(97 + $i % 26) . ($i >= 26 ? (string)intdiv($i, 26) : '') : $cand;
        if (!$local($u) && !mc_username_taken($u)) {
            return $u;
        }
    }
    return $cand . bin2hex(random_bytes(2));
}

/** Map one user of a company database into the master sign-in table (insert, update or remove). */
function mc_sync_user_row(PDO $m, int $companyId, array $u): bool
{
    $now = date('Y-m-d H:i:s');
    if (!empty($u['is_support']) || !empty($u['linked_from'])) {
        // support accounts and linked accounts (reached by switching company) never sign in by themselves
        $m->prepare('DELETE FROM company_memberships WHERE company_id = ? AND user_id = ?')->execute([$companyId, (int)$u['id']]);
        return true;
    }
    $st = $m->prepare('SELECT id FROM company_memberships WHERE company_id = ? AND user_id = ?');
    $st->execute([$companyId, (int)$u['id']]);
    $id = $st->fetchColumn();
    $vals = [strtolower((string)$u['username']), ($u['email'] ?? '') !== '' ? $u['email'] : null, $u['full_name'] ?? null, mc_member_status($u), $now];
    try {
        if ($id) {
            $m->prepare('UPDATE company_memberships SET username = ?, email = ?, full_name = ?, status = ?, updated_at = ? WHERE id = ?')->execute(array_merge($vals, [(int)$id]));
        } else {
            $m->prepare('INSERT INTO company_memberships (username, email, full_name, status, updated_at, company_id, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
              ->execute(array_merge($vals, [$companyId, (int)$u['id'], $now]));
        }
        return true;
    } catch (PDOException $e) {
        // the username belongs to an account of another company: this account cannot sign in until renamed
        return false;
    }
}

/** Reconcile the master sign-in table with a company's users table. Returns usernames that clash with another company. */
function mc_sync_company_pdo(PDO $m, int $companyId, PDO $cdb): array
{
    $hasCols = (bool)$cdb->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'is_support'")->fetchColumn();
    $hasLink = (bool)$cdb->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'linked_from'")->fetchColumn();
    $users = $cdb->query('SELECT id, username, email, full_name, is_active' . ($hasCols ? ', reg_status, is_support' : '') . ($hasLink ? ', linked_from' : '') . ' FROM users')->fetchAll();
    $clash = [];
    $ids = [];
    foreach ($users as $u) {
        $ids[] = (int)$u['id'];
        if (!mc_sync_user_row($m, $companyId, $u)) {
            $clash[] = $u['username'];
        }
    }
    // accounts deleted from the company database
    $st = $m->prepare('SELECT user_id FROM company_memberships WHERE company_id = ?');
    $st->execute([$companyId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        if (!in_array((int)$uid, $ids, true)) {
            $m->prepare('DELETE FROM company_memberships WHERE company_id = ? AND user_id = ?')->execute([$companyId, (int)$uid]);
        }
    }
    return $clash;
}

/** Map one user of the active company (after it was created or changed). */
function mc_sync_user(int $userId): void
{
    $m = master_db();
    $cid = current_company_id();
    if (!$m || !$cid) {
        return;
    }
    $u = row('SELECT * FROM users WHERE id = ?', [$userId]);
    if ($u) {
        mc_sync_user_row($m, $cid, $u);
    } else {
        $m->prepare('DELETE FROM company_memberships WHERE company_id = ? AND user_id = ?')->execute([$cid, $userId]);
    }
}

/** Reconcile every account of the active company. */
function mc_sync_current_company(): array
{
    $m = master_db();
    $cid = current_company_id();
    if (!$m || !$cid) {
        return [];
    }
    try {
        return mc_sync_company_pdo($m, $cid, db());
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Who is signing in: a Super Admin, or a company account found through the master table
 * (username, or an e-mail address used by exactly one account).
 */
function mc_resolve_login(string $login): array
{
    $login = trim($login);
    $sa = mrow('SELECT * FROM super_admins WHERE username = ? OR (email IS NOT NULL AND email = ?) LIMIT 1', [strtolower($login), $login]);
    if ($sa) {
        return ['kind' => 'super', 'row' => $sa];
    }
    $m = mrow('SELECT * FROM company_memberships WHERE username = ?', [strtolower($login)]);
    if ($m) {
        return ['kind' => 'member', 'row' => $m];
    }
    if (strpos($login, '@') !== false) {
        $list = mrows("SELECT * FROM company_memberships WHERE email = ? AND status <> 'rejected'", [$login]);
        if (count($list) === 1) {
            return ['kind' => 'member', 'row' => $list[0]];
        }
        if (count($list) > 1) {
            return ['kind' => 'ambiguous'];
        }
    }
    return ['kind' => 'none'];
}

/* ================================================================== multi-company users (company switching) */

/**
 * A user of one company (the home account) may be given access to other companies without being a
 * Super Admin: a Super Admin links the home account to an account in the other company (Multi-company
 * Users page). That linked account belongs to the other company, has its own role and permissions
 * there (managed by that company on User Accounts) and has no password: it is reached only by signing
 * in with the home account and switching company in the top bar. Every switch is recorded in the
 * audit trail of both companies and of the platform.
 */
function mc_link_home(): ?array
{
    if (!master_db() || !empty($_SESSION['sa']) || empty($_SESSION['uid'])) {
        return null;
    }
    if (!empty($_SESSION['link_home']['cid'])) {
        return $_SESSION['link_home'];
    }
    return !empty($_SESSION['cid']) ? ['cid' => (int)$_SESSION['cid'], 'uid' => (int)$_SESSION['uid']] : null;
}

/** Companies the signed-in user may switch to: the home company first, then the active links. */
function mc_switch_targets(): array
{
    if (array_key_exists('__switch_targets', $GLOBALS)) {
        return $GLOBALS['__switch_targets'];
    }
    $GLOBALS['__switch_targets'] = [];
    $home = mc_link_home();
    if (!$home) {
        return [];
    }
    try {
        $links = mrows("SELECT l.company_id, l.user_id, c.name FROM company_user_links l JOIN companies c ON c.id = l.company_id
                        WHERE l.home_company_id = ? AND l.home_user_id = ? AND l.status = 'active' AND c.status = 'active' AND c.db_status = 'ready'
                        ORDER BY c.name", [(int)$home['cid'], (int)$home['uid']]);
    } catch (Throwable $e) {
        return [];
    }
    if (!$links) {
        return [];
    }
    $hc = company_by_id((int)$home['cid']);
    $out = [['company_id' => (int)$home['cid'], 'user_id' => (int)$home['uid'], 'name' => $hc ? $hc['name'] : 'Home company', 'home' => true]];
    foreach ($links as $l) {
        if ((int)$l['company_id'] !== (int)$home['cid']) {
            $out[] = ['company_id' => (int)$l['company_id'], 'user_id' => (int)$l['user_id'], 'name' => $l['name'], 'home' => false];
        }
    }
    return $GLOBALS['__switch_targets'] = count($out) > 1 ? $out : [];
}

/** Is the session working in a linked company (not the home company)? */
function mc_in_linked_company(): bool
{
    $home = mc_link_home();
    return $home && !empty($_SESSION['cid']) && (int)$_SESSION['cid'] !== (int)$home['cid'];
}

/** Move the session into another company the user may switch to. Throws RuntimeException when not allowed. */
function mc_switch_company(int $companyId): array
{
    $home = mc_link_home();
    if (!$home) {
        throw new RuntimeException('Company switching is not available for this account.');
    }
    $target = null;
    foreach (mc_switch_targets() as $t) {
        if ($t['company_id'] === $companyId) {
            $target = $t;
        }
    }
    if (!$target) {
        throw new RuntimeException('You do not have access to that company.');
    }
    $co = company_by_id($companyId);
    if (!$co || $co['status'] !== 'active' || $co['db_status'] !== 'ready' || !mc_valid_db_name($co['db_name'])) {
        throw new RuntimeException('That company is not available right now.');
    }
    $u = with_company_db($co['db_name'], function () use ($target) {
        db_upgrade();
        return row('SELECT * FROM users WHERE id = ?', [$target['user_id']]);
    });
    $expect = $target['home'] ? null : $home['cid'] . ':' . $home['uid'];
    if (!$u || !$u['is_active'] || (!$target['home'] && (string)$u['linked_from'] !== $expect)) {
        throw new RuntimeException('Your account in ' . $co['name'] . ' is inactive. Please contact its administrator.');
    }
    $from = tenant_company();
    $fromUser = current_user();
    if ($from) {
        with_company_db($from['db_name'], fn() => login_audit($from, $fromUser, 'company_switch_out', ['to' => $co['name']]));
    }
    session_regenerate_id(true);
    $_SESSION['link_home'] = ['cid' => (int)$home['cid'], 'uid' => (int)$home['uid']];
    $_SESSION['cid'] = (int)$co['id'];
    $_SESSION['uid'] = (int)$u['id'];
    unset($GLOBALS['__switch_targets']);
    with_company_db($co['db_name'], function () use ($co, $u, $from) {
        q('UPDATE users SET last_login = NOW() WHERE id = ?', [$u['id']]);
        login_audit($co, $u, 'company_switch_in', ['from' => $from ? $from['name'] : null]);
    });
    tenant_reset();
    master_audit('company_switch', (int)$co['id'], ['user' => $u['username'], 'from_company' => $from ? (int)$from['id'] : null]);
    return $co;
}

/** The home account of a linked account row ("home company id:home user id"), or null. */
function mc_parse_linked_from(?string $v): ?array
{
    return $v && preg_match('/^(\d+):(\d+)$/', $v, $m) ? ['cid' => (int)$m[1], 'uid' => (int)$m[2]] : null;
}

/** Description of a linked account for the company's User Accounts page. */
function mc_linked_label(?string $v): string
{
    $h = mc_parse_linked_from($v);
    if (!$h || !master_db()) {
        return '';
    }
    $l = mrow('SELECT l.username, c.name FROM company_user_links l LEFT JOIN companies c ON c.id = l.home_company_id WHERE l.home_company_id = ? AND l.home_user_id = ? AND l.company_id = ? LIMIT 1',
        [$h['cid'], $h['uid'], (int)current_company_id()]);
    return $l ? $l['username'] . ' of ' . ($l['name'] ?: 'another company') : 'an account of another company';
}

/** Other companies the account of the active company may switch to (for User Accounts). */
function mc_user_links_out(int $userId): array
{
    $cid = current_company_id();
    if (!$cid || !master_db()) {
        return [];
    }
    return mrows('SELECT l.*, c.name AS company_name FROM company_user_links l JOIN companies c ON c.id = l.company_id WHERE l.home_company_id = ? AND l.home_user_id = ? ORDER BY c.name', [$cid, $userId]);
}

/* ================================================================== files */

/**
 * Upload folder of the active company. Company 1 keeps the existing uploads/ folder (every stored
 * path stays valid); other companies get uploads/company_<id>/, so no company can read another's files.
 */
function upload_dir(): string
{
    $co = tenant_company();
    if (!$co || !empty($co['is_primary'])) {
        return UPLOAD_DIR;
    }
    return UPLOAD_DIR . '/company_' . (int)$co['id'];
}

/** Is a stored relative path allowed for the active company (Company 1 may not reach other company folders)? */
function upload_path_allowed(string $rel): bool
{
    $co = tenant_company();
    if ($co && !empty($co['is_primary'])) {
        return !preg_match('#^company_\d+(/|$)#', $rel);
    }
    return true;
}

/* ================================================================== server permissions */

/** Grants of the configured MySQL account and whether it can create company databases. */
function mc_server_grants(): array
{
    $grants = [];
    try {
        $grants = db_server_pdo()->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);
        // MySQL 5.5 prints the password hash in the USAGE grant: never show it
        $grants = array_map(fn($g) => preg_replace("/(IDENTIFIED\\s+(BY|WITH)\\s+(PASSWORD\\s+)?)('[^']*'|\\S+)/i", '$1<hidden>', preg_replace("/\\bAS\\s+'[^']*'/i", 'AS <hidden>', (string)$g)), $grants);
    } catch (Throwable $e) {
        return ['grants' => [], 'can_create' => null, 'error' => $e->getMessage()];
    }
    $prefix = company_db_prefix();
    $can = false;
    foreach ($grants as $g) {
        $g = (string)$g;
        $onAll = preg_match('/ ON \*\.\* /i', $g);
        $onPrefix = stripos($g, str_replace('_', '\\_', $prefix) . '%') !== false || stripos($g, $prefix . '%') !== false;
        if (($onAll || $onPrefix) && (stripos($g, 'ALL PRIVILEGES') !== false || (stripos($g, 'CREATE') !== false && stripos($g, 'INSERT') !== false))) {
            $can = true;
        }
    }
    return ['grants' => $grants, 'can_create' => $can, 'error' => null];
}

/** The GRANT statements an administrator runs when the MySQL account cannot create company databases. */
function mc_required_grants_sql(): string
{
    $c = $GLOBALS['config'];
    $who = "'" . str_replace("'", "''", (string)$c['db_user']) . "'@'" . ($c['db_host'] === '127.0.0.1' || $c['db_host'] === 'localhost' ? 'localhost' : '%') . "'";
    return "GRANT ALL PRIVILEGES ON `" . str_replace('_', '\\_', company_db_prefix()) . "%`.* TO $who;\n"
        . "GRANT ALL PRIVILEGES ON `" . master_db_name() . "`.* TO $who;\n"
        . "GRANT ALL PRIVILEGES ON `" . $c['db_name'] . "`.* TO $who;\n"
        . "FLUSH PRIVILEGES;";
}
