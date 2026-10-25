<?php
/**
 * Authentication.
 *
 * Who may do what is decided by dynamic roles and per-user overrides (app/permissions.php). The five
 * built-in roles below are seeded as editable roles; their codes stay in users.role as the legacy
 * profile of an account:
 *   admin      – System Administrator, the protected Super Admin role (everything, manages roles)
 *   hr         – HR Officer
 *   payroll    – Payroll Officer
 *   supervisor – Supervisor / Dept. Head (approves and evaluates their own team)
 *   employee   – Employee (self-service)
 *
 * Above the company roles (multi-company, see app/tenancy.php):
 *   Super Admin    – platform level, in the master database: companies, Company Owners, codes
 *   Company Owner  – the primary account of one company (an admin of that company, marked in the
 *                    master database); manages that company's users and join requests only
 */

const ROLE_LABELS = [
    'admin' => 'System Administrator',
    'hr' => 'HR Officer',
    'payroll' => 'Payroll Officer',
    'supervisor' => 'Supervisor / Dept. Head',
    'employee' => 'Employee',
];

function current_user(): ?array
{
    // inside with_company_db() (provisioning, registration, sign-in checks) nobody is signed in
    if (!empty($GLOBALS['__system_ctx'])) {
        return null;
    }
    $uid = (int)($_SESSION['uid'] ?? 0);
    if (!$uid) {
        return null;
    }
    $key = active_db_name() . ':' . $uid;   // a user id only means something inside its own company database
    if (!isset($GLOBALS['__cu_cache']) || !array_key_exists($key, $GLOBALS['__cu_cache'])) {
        $GLOBALS['__cu_cache'][$key] = row('SELECT u.*, e.employee_no, e.first_name, e.last_name, e.photo_path
                            FROM users u LEFT JOIN employees e ON e.id = u.employee_id
                            WHERE u.id = ? AND u.is_active = 1', [$uid]);
    }
    return $GLOBALS['__cu_cache'][$key];
}

function user_id(): ?int
{
    $u = current_user();
    return $u ? (int)$u['id'] : null;
}

function my_employee_id(): ?int
{
    $u = current_user();
    return $u && $u['employee_id'] ? (int)$u['employee_id'] : null;
}

/**
 * Legacy check kept for backward compatibility of older code: true for a Super Admin, or when the
 * account's legacy role profile is one of $roles. New code uses can('page.action').
 */
function has_role(...$roles): bool
{
    $u = current_user();
    if (!$u) {
        return false;
    }
    if (count($roles) === 1 && is_array($roles[0])) {
        $roles = $roles[0];
    }
    return is_super_user() || in_array($u['role'], $roles, true);
}

function require_login(): void
{
    if (!current_user()) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? null;
        redirect('login');
    }
}

/** Refuse the request (403) unless the user has one of these permissions. */
function require_permission(string ...$keys): void
{
    require_login();
    if (!can_any($keys)) {
        perm_deny();
    }
}

/**
 * Sign in with a username (or an e-mail address used by one account) and password.
 * Multi-company: the master database tells which company the account belongs to, the password is
 * checked in that company's database, and the session remembers the company. The Company Code is
 * only needed once, when registering. A Super Admin signs in on the same form.
 * On failure login_message() explains why (pending approval, inactive company) or stays generic.
 */
function attempt_login(string $username, string $password): bool
{
    $GLOBALS['__login_msg'] = null;
    $login = trim($username);
    if (!master_db()) {
        return attempt_login_local($login, $password);
    }
    $r = mc_resolve_login($login);
    if ($r['kind'] === 'super') {
        $sa = $r['row'];
        if (!$sa['is_active'] || !password_verify($password, $sa['password_hash'])) {
            master_audit('super_admin_login_failed', null, ['username' => $login]);
            return false;
        }
        session_regenerate_id(true);
        unset($_SESSION['uid'], $_SESSION['cid'], $_SESSION['sa_cid'], $_SESSION['link_home']);
        $_SESSION['sa'] = (int)$sa['id'];
        mupdate('super_admins', ['last_login' => now()], 'id = ?', [$sa['id']]);
        unset($GLOBALS['__sa']);
        tenant_reset();
        master_audit('super_admin_login');
        return true;
    }
    if ($r['kind'] === 'ambiguous') {
        $GLOBALS['__login_msg'] = 'This e-mail address belongs to more than one account. Please sign in with your username.';
        return false;
    }
    $member = $r['kind'] === 'member' ? $r['row'] : null;
    if (!$member) {
        // an account of Company 1 created before it was mapped: look it up there (never in other companies)
        $p = primary_company();
        $local = $p ? with_company_db($p['db_name'], fn() => row('SELECT * FROM users WHERE username = ?', [strtolower($login)])) : null;
        if (!$local || !empty($local['linked_from']) || !empty($local['is_support']) || !password_verify($password, $local['password_hash'])) {
            master_audit('login_failed', null, ['username' => $login]);
            return false;
        }
        if (!mc_sync_user_row(master_db(), (int)$p['id'], $local)) {
            return false;
        }
        $member = mrow('SELECT * FROM company_memberships WHERE company_id = ? AND user_id = ?', [(int)$p['id'], (int)$local['id']]);
        if (!$member) {
            return false;
        }
    }
    $co = company_by_id((int)$member['company_id']);
    if (!$co || !mc_valid_db_name($co['db_name'])) {
        return false;
    }
    try {
        $u = with_company_db($co['db_name'], fn() => row('SELECT * FROM users WHERE id = ?', [(int)$member['user_id']]));
    } catch (PDOException $e) {
        $GLOBALS['__login_msg'] = 'Your company\'s database cannot be reached right now. Please try again later or contact your Company Owner.';
        return false;
    }
    if (!$u || !empty($u['linked_from']) || !password_verify($password, $u['password_hash'])) {
        with_company_db($co['db_name'], fn() => login_audit($co, $u ?: null, 'login_failed', ['username' => $login]));
        return false;
    }
    $status = mc_member_status($u);
    if ($status === 'pending') {
        $GLOBALS['__login_msg'] = 'Your registration with ' . $co['name'] . ' is waiting for approval by the Company Owner.';
        return false;
    }
    if ($status === 'rejected') {
        $GLOBALS['__login_msg'] = 'Your registration with ' . $co['name'] . ' was not approved. Please contact your Company Owner.';
        return false;
    }
    if ($status !== 'active') {
        return false;
    }
    if ($co['status'] !== 'active' || $co['db_status'] !== 'ready') {
        $GLOBALS['__login_msg'] = 'Your company\'s access to ' . app_name() . ' is currently inactive. Please contact your Company Owner.';
        return false;
    }
    session_regenerate_id(true);
    unset($_SESSION['sa'], $_SESSION['sa_cid']);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['cid'] = (int)$co['id'];
    $_SESSION['link_home'] = ['cid' => (int)$co['id'], 'uid' => (int)$u['id']];   // the account signed in with (company switching)
    with_company_db($co['db_name'], function () use ($co, $u) {
        q('UPDATE users SET last_login = NOW() WHERE id = ?', [$u['id']]);
        login_audit($co, $u, 'login');
    });
    tenant_reset();
    return true;
}

/** Single-company sign-in (multi-company switched off or the master database unavailable). */
function attempt_login_local(string $username, string $password): bool
{
    $u = row('SELECT * FROM users WHERE username = ? AND is_active = 1', [$username]);
    if (!$u || !password_verify($password, $u['password_hash'])) {
        audit('auth', 'login_failed', 'user', null, ['username' => $username]);
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    q('UPDATE users SET last_login = NOW() WHERE id = ?', [$u['id']]);
    audit('auth', 'login', 'user', $u['id']);
    return true;
}

/** Sign-in entry in the company's own audit trail (written inside with_company_db). */
function login_audit(array $co, ?array $u, string $action, ?array $details = null): void
{
    try {
        insert('audit_log', ['user_id' => $u['id'] ?? null, 'username' => $u['username'] ?? ($details['username'] ?? 'guest'), 'module' => 'auth', 'action' => $action,
            'entity' => 'user', 'entity_id' => isset($u['id']) ? (string)$u['id'] : null, 'details' => $details ? json_encode($details) : null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) {
    }
}

/** Why the last sign-in was refused, when it is safe to say (null = invalid username or password). */
function login_message(): ?string
{
    return $GLOBALS['__login_msg'] ?? null;
}

function logout(): void
{
    if (super_admin() && !current_user()) {
        master_audit('super_admin_logout');
    } else {
        audit('auth', 'logout', 'user', user_id());
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    tenant_reset();
}

/** Is the current user the direct supervisor or department head of an employee? */
function is_manager_of(int $employeeId): bool
{
    $me = my_employee_id();
    if (!$me) {
        return false;
    }
    $e = row('SELECT supervisor_id, org_unit_id FROM employees WHERE id = ?', [$employeeId]);
    if (!$e) {
        return false;
    }
    if ((int)$e['supervisor_id'] === $me) {
        return true;
    }
    return $e['org_unit_id'] && dept_head_of_unit((int)$e['org_unit_id']) === $me;
}

/** IDs of employees supervised directly or via department headship by the current user. */
function my_team_ids(): array
{
    $me = my_employee_id();
    if (!$me) {
        return [];
    }
    $ids = array_map('intval', q("SELECT id FROM employees WHERE supervisor_id = ? AND status = 'active'", [$me])->fetchAll(PDO::FETCH_COLUMN));
    $units = q('SELECT id FROM org_units WHERE head_employee_id = ?', [$me])->fetchAll(PDO::FETCH_COLUMN);
    foreach ($units as $u) {
        foreach (org_descendant_ids((int)$u) as $uid) {
            foreach (q("SELECT id FROM employees WHERE org_unit_id = ? AND status = 'active'", [$uid])->fetchAll(PDO::FETCH_COLUMN) as $eid) {
                $ids[] = (int)$eid;
            }
        }
    }
    $ids = array_values(array_unique(array_diff($ids, [$me])));
    return $ids;
}

/* Access control (roles, permissions, user overrides, the server gate) lives in app/permissions.php. */
