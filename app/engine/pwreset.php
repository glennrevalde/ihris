<?php
/**
 * Password resets by the platform Super Admin (v21).
 *
 * Every account that signs in by itself is listed in the master database: company accounts in
 * company_memberships (one row per sign-in account of every company) and the platform accounts in
 * super_admins. A reset gives the account a temporary password and forces a new password at the next
 * sign-in (index.php sends the user to My Account until it is changed; the new password must differ from
 * the temporary one). The temporary password is shown once to the Super Admin and never stored or logged
 * in clear: the audit trails of the platform and of the company record who reset which account and why.
 *
 * Not listed: support accounts (the Super Admin inside a company) and linked accounts (reached by
 * switching company): they have no password of their own; the home account is reset instead.
 */

const PWRESET_MIN_USER = 8;     // the same rule as My Account
const PWRESET_MIN_SUPER = 10;   // the same rule as the Super Admin's My Account

/** Companies whose database can be opened, keyed by id. */
function pwreset_companies(): array
{
    $out = [];
    foreach (mrows("SELECT * FROM companies WHERE db_status = 'ready' AND status IN ('active', 'inactive') ORDER BY is_primary DESC, name") as $c) {
        if (mc_valid_db_name((string)$c['db_name'])) {
            $out[(int)$c['id']] = $c;
        }
    }
    return $out;
}

/** Reconcile the sign-in list with every company's users table (accounts added outside the application). */
function pwreset_refresh(): int
{
    $n = 0;
    foreach (pwreset_companies() as $c) {
        try {
            mc_sync_company_pdo(master_db(), (int)$c['id'], db_pdo($c['db_name']));
            $n++;
        } catch (Throwable $e) {
            // a company database that cannot be opened keeps its last list
        }
    }
    return $n;
}

/**
 * Accounts matching the search. $company: 0 = every company, -1 = Super Admin accounts only.
 * $status: '' (all), active, inactive, pending. Each row: kind (user / super), key, company_id, id,
 * username, full_name, email, status, company_name.
 */
function pwreset_search(string $q, int $company, string $status, int $limit = 200): array
{
    $q = trim($q);
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $out = [];
    if ($company >= 0) {
        $ids = array_keys(pwreset_companies());
        if ($company > 0) {
            $ids = in_array($company, $ids, true) ? [$company] : [];
        }
        if ($ids) {
            $w = ['m.company_id IN (' . implode(',', array_map('intval', $ids)) . ')', "m.status <> 'rejected'"];
            $p = [];
            if ($q !== '') {
                $w[] = '(m.username LIKE ? OR m.full_name LIKE ? OR m.email LIKE ?)';
                array_push($p, $like, $like, $like);
            }
            if (in_array($status, ['active', 'inactive', 'pending'], true)) {
                $w[] = 'm.status = ?';
                $p[] = $status;
            }
            foreach (mrows('SELECT m.*, c.name AS company_name FROM company_memberships m JOIN companies c ON c.id = m.company_id
                            WHERE ' . implode(' AND ', $w) . ' ORDER BY m.full_name, m.username LIMIT ' . (int)$limit, $p) as $r) {
                $out[] = ['kind' => 'user', 'key' => 'u' . (int)$r['company_id'] . '-' . (int)$r['user_id'], 'company_id' => (int)$r['company_id'], 'id' => (int)$r['user_id'],
                    'username' => $r['username'], 'full_name' => (string)($r['full_name'] ?: $r['username']), 'email' => $r['email'], 'status' => $r['status'],
                    'company_name' => $r['company_name']];
            }
        }
    }
    if ($company <= 0 && $status !== 'pending') {
        $w = ['1=1'];
        $p = [];
        if ($q !== '') {
            $w[] = '(username LIKE ? OR full_name LIKE ? OR email LIKE ?)';
            array_push($p, $like, $like, $like);
        }
        if ($status !== '') {
            $w[] = 'is_active = ' . ($status === 'active' ? 1 : 0);
        }
        foreach (mrows('SELECT * FROM super_admins WHERE ' . implode(' AND ', $w) . ' ORDER BY full_name LIMIT 50', $p) as $r) {
            $out[] = ['kind' => 'super', 'key' => 's' . (int)$r['id'], 'company_id' => 0, 'id' => (int)$r['id'], 'username' => $r['username'], 'full_name' => $r['full_name'],
                'email' => $r['email'], 'status' => $r['is_active'] ? 'active' : 'inactive', 'company_name' => 'Platform (Super Admin)',
                'must_change_password' => (int)$r['must_change_password'], 'last_login' => $r['last_login'], 'role_name' => 'Super Admin'];
        }
    }
    usort($out, fn($a, $b) => strcasecmp($a['full_name'], $b['full_name']) ?: strcmp($a['key'], $b['key']));
    return array_slice($out, 0, $limit);
}

/** Add what only the company databases know (role, last sign-in, temporary password pending). */
function pwreset_details(array $list): array
{
    $byCo = [];
    foreach ($list as $i => $r) {
        if ($r['kind'] === 'user') {
            $byCo[$r['company_id']][$i] = $r['id'];
        }
    }
    $cos = pwreset_companies();
    foreach ($byCo as $cid => $map) {
        try {
            $rows = with_company_db($cos[$cid]['db_name'], function () use ($map) {
                $res = [];
                foreach (rows('SELECT u.id, u.role, u.role_id, u.last_login, u.must_change_password, u.is_active, u.employee_id, e.employee_no
                               FROM users u LEFT JOIN employees e ON e.id = u.employee_id WHERE u.id IN (' . implode(',', array_map('intval', $map)) . ')') as $u) {
                    $u['role_name'] = user_role_name($u);
                    $res[(int)$u['id']] = $u;
                }
                return $res;
            });
        } catch (Throwable $e) {
            $rows = [];
        }
        foreach ($map as $i => $uid) {
            $u = $rows[$uid] ?? null;
            $list[$i] += $u ? ['role_name' => $u['role_name'], 'last_login' => $u['last_login'], 'must_change_password' => (int)$u['must_change_password'], 'employee_no' => $u['employee_no']]
                : ['role_name' => '', 'last_login' => null, 'must_change_password' => 0, 'employee_no' => null, 'unreachable' => true];
        }
    }
    return $list;
}

/** One account for the reset panel (null when it cannot be reset here). */
function pwreset_account(string $kind, int $companyId, int $id): ?array
{
    if ($kind === 'super') {
        $r = mrow('SELECT * FROM super_admins WHERE id = ?', [$id]);
        return $r ? ['kind' => 'super', 'company_id' => 0, 'id' => (int)$r['id'], 'username' => $r['username'], 'full_name' => $r['full_name'], 'email' => $r['email'],
            'status' => $r['is_active'] ? 'active' : 'inactive', 'company_name' => 'Platform (Super Admin)', 'role_name' => 'Super Admin', 'last_login' => $r['last_login'],
            'must_change_password' => (int)$r['must_change_password'], 'employee_no' => null, 'mail_ready' => false, 'company' => null] : null;
    }
    $co = pwreset_companies()[$companyId] ?? null;
    if (!$co) {
        return null;
    }
    $u = with_company_db($co['db_name'], function () use ($id) {
        $u = row('SELECT u.*, e.employee_no FROM users u LEFT JOIN employees e ON e.id = u.employee_id WHERE u.id = ?', [$id]);
        if ($u) {
            unset($u['password_hash']);
            $u['role_name'] = user_role_name($u);
            $u['mail_mode'] = (string)setting('mail_mode', 'log');
        }
        return $u;
    });
    if (!$u || !empty($u['is_support']) || !empty($u['linked_from'])) {
        return null;
    }
    return ['kind' => 'user', 'company_id' => (int)$co['id'], 'id' => (int)$u['id'], 'username' => $u['username'], 'full_name' => $u['full_name'], 'email' => $u['email'],
        'status' => mc_member_status($u), 'company_name' => $co['name'], 'role_name' => $u['role_name'], 'last_login' => $u['last_login'],
        'must_change_password' => (int)$u['must_change_password'], 'employee_no' => $u['employee_no'], 'mail_ready' => $u['mail_mode'] !== 'log', 'company' => $co,
        'is_owner' => (int)$co['owner_user_id'] === (int)$u['id']];
}

/**
 * Reset the password. $opt: mode (generate / typed), password, email (bool), reason.
 * Returns the account, the temporary password and the e-mail result (true sent, false failed, null not asked).
 */
function pwreset_apply(string $kind, int $companyId, int $id, array $opt): array
{
    $sa = super_admin();
    if (!$sa) {
        throw new RuntimeException('Only the platform Super Admin resets passwords here.');
    }
    $acct = pwreset_account($kind, $companyId, $id);
    if (!$acct) {
        throw new RuntimeException('This account cannot be reset here (not found, a support account, or an account reached by switching company: reset its home account).');
    }
    if ($kind === 'super' && $id === (int)$sa['id']) {
        throw new RuntimeException('Change your own password on My Account.');
    }
    $min = $kind === 'super' ? PWRESET_MIN_SUPER : PWRESET_MIN_USER;
    if (($opt['mode'] ?? 'generate') === 'typed') {
        $pw = (string)($opt['password'] ?? '');
        if (strlen($pw) < $min) {
            throw new RuntimeException('The temporary password must have at least ' . $min . ' characters.');
        }
        if (strcasecmp($pw, (string)$acct['username']) === 0) {
            throw new RuntimeException('The temporary password cannot be the username.');
        }
    } else {
        $pw = provision_temp_password();
    }
    $reason = mb_substr(trim((string)($opt['reason'] ?? '')), 0, 200);
    $hash = password_hash($pw, PASSWORD_DEFAULT);
    $by = $sa['username'] . ' (Super Admin)';
    $mailed = null;

    if ($kind === 'super') {
        mupdate('super_admins', ['password_hash' => $hash, 'must_change_password' => 1], 'id = ?', [$id]);
        master_audit('super_admin_password_reset', null, ['username' => $acct['username'], 'reason' => $reason ?: null]);
    } else {
        $co = $acct['company'];
        $wantMail = !empty($opt['email']) && $acct['email'] && $acct['mail_ready'];
        $mailed = with_company_db($co['db_name'], function () use ($id, $hash, $pw, $acct, $co, $by, $reason, $wantMail) {
            q('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?', [$hash, $id]);
            $sent = null;
            if ($wantMail) {
                $sent = pwreset_mail($acct, $co, $pw);
            }
            try {
                insert('audit_log', ['user_id' => null, 'username' => $by, 'module' => 'admin', 'action' => 'password_reset', 'entity' => 'users', 'entity_id' => (string)$id,
                    'details' => json_encode(['username' => $acct['username'], 'reason' => $reason ?: null, 'emailed' => $sent, 'must_change_password' => 1], JSON_UNESCAPED_UNICODE),
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null]);
            } catch (Throwable $e) {
                // the audit never blocks the reset
            }
            return $sent;
        });
        master_audit('user_password_reset', (int)$co['id'], ['username' => $acct['username'], 'reason' => $reason ?: null, 'emailed' => $mailed]);
    }
    return ['account' => $acct, 'password' => $pw, 'emailed' => $mailed];
}

/** E-mail the temporary password (inside the company database: its mail settings and e-mail log). */
function pwreset_mail(array $acct, array $co, string $pw): bool
{
    try {
        $ok = send_mail((string)$acct['email'], (string)$acct['full_name'], 'Your ' . app_name() . ' password was reset',
            '<p>Hello ' . h($acct['full_name']) . ',</p>'
            . '<p>Your password for <strong>' . h($co['name']) . '</strong> was reset by the system administrator.</p>'
            . '<p>Username: <strong>' . h($acct['username']) . '</strong><br>Temporary password: <strong>' . h($pw) . '</strong></p>'
            . '<p>Sign in at <a href="' . h(abs_url('login')) . '">' . h(abs_url('login')) . '</a>. You will be asked to choose a new password right away.</p>'
            . '<p>If you did not ask for this, contact your administrator.</p>', 'password_reset', (int)$acct['id']);
    } catch (Throwable $e) {
        $ok = false;
    }
    try {
        // the e-mail log keeps the message, never the password
        q("UPDATE email_log SET body_html = REPLACE(body_html, ?, '(hidden)') WHERE related_type = 'password_reset' AND related_id = ? ORDER BY id DESC LIMIT 1", [h($pw), (int)$acct['id']]);
    } catch (Throwable $e) {
    }
    return $ok;
}

/** The latest resets (platform audit), for the page. */
function pwreset_recent(int $limit = 12): array
{
    return mrows("SELECT * FROM master_audit_log WHERE action IN ('user_password_reset', 'super_admin_password_reset') ORDER BY id DESC LIMIT " . (int)$limit);
}
