<?php
/**
 * Company provisioning (multi-company): creates a company's own database from the current
 * G-SMaRT HRIS structure, puts in the clean default data, creates its Company Owner and registers the
 * company <-> database mapping in the master database.
 *
 * What a new company database receives
 *   - every table, column, key and index of database/ihris_mysql55.sql plus the upgrades (DB_VERSION)
 *   - the reference data of a clean installation: settings and policies, statutory tables (BIR tax,
 *     SSS, PhilHealth, Pag-IBIG, premium / pay rates, late brackets, national holidays), leave types,
 *     shifts, pay codes, salary grades, competencies, code of conduct, deduction / benefit / earning
 *     types, GL accounts, lookup tables (employee types, groups, jobs, exemption codes...), import
 *     profiles
 *   - its own identity: one Head Office branch and one top organization unit named after the company,
 *     the company name / address in System Settings, the Company Owner account
 * What it never receives: anything of another company (employees, users, attendance, leave, overtime,
 * payroll, payslips, ledgers, evaluations, cases, audit, e-mails, imports), nor the sample branches,
 * departments and locations of the clean installation script.
 */

/** Tables whose rows are statutory setup that may be copied from an existing company instead of the defaults. */
const PROVISION_STATUTORY_TABLES = ['tax_brackets', 'sss_table', 'philhealth_params', 'pagibig_table', 'premium_rates', 'pay_rates', 'late_brackets', 'holidays'];

/** Split an SQL script into statements (quotes, backticks and comments aware). */
function sql_split(string $sql): array
{
    $out = [];
    $buf = '';
    $len = strlen($sql);
    $q = null;
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        if ($q !== null) {
            $buf .= $ch;
            if ($ch === '\\' && $q !== '`' && $i + 1 < $len) {
                $buf .= $sql[++$i];
            } elseif ($ch === $q) {
                if ($i + 1 < $len && $sql[$i + 1] === $q) {
                    $buf .= $sql[++$i];
                } else {
                    $q = null;
                }
            }
            continue;
        }
        if ($ch === '-' && substr($sql, $i, 2) === '--') {
            $nl = strpos($sql, "\n", $i);
            $i = $nl === false ? $len : $nl;
            $buf .= "\n";
            continue;
        }
        if ($ch === '#') {
            $nl = strpos($sql, "\n", $i);
            $i = $nl === false ? $len : $nl;
            continue;
        }
        if ($ch === '/' && substr($sql, $i, 2) === '/*') {
            $end = strpos($sql, '*/', $i + 2);
            $i = $end === false ? $len : $end + 1;
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $q = $ch;
            $buf .= $ch;
            continue;
        }
        if ($ch === ';') {
            if (trim($buf) !== '') {
                $out[] = trim($buf);
            }
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }
    if (trim($buf) !== '') {
        $out[] = trim($buf);
    }
    return $out;
}

/** Statements that build a clean company database (structure + reference data, no account, no database switch). */
function provision_schema_statements(): array
{
    $file = APP_ROOT . '/database/ihris_mysql55.sql';
    if (!is_file($file)) {
        throw new RuntimeException('database/ihris_mysql55.sql (the structure template) is missing.');
    }
    $keep = [];
    foreach (sql_split((string)file_get_contents($file)) as $st) {
        if (preg_match('/^(CREATE\s+DATABASE|USE\s)/i', $st) || preg_match('/^INSERT\s+INTO\s+`?users`?\s/i', $st)) {
            continue;
        }
        $keep[] = $st;
    }
    return $keep;
}

/** Every table a complete company database has (template script + upgrades). */
function provision_required_tables(): array
{
    $t = [];
    preg_match_all('/CREATE TABLE `?([a-z0-9_]+)`?/i', (string)@file_get_contents(APP_ROOT . '/database/ihris_mysql55.sql'), $m);
    foreach ($m[1] as $n) {
        $t[$n] = true;
    }
    foreach (migrations_all() as $fn) {
        foreach (array_keys($fn()['tables']) as $n) {
            $t[$n] = true;
        }
    }
    return array_keys($t);
}

/** Problems found in a company database (empty = complete). */
function company_db_check(string $dbName): array
{
    $issues = [];
    try {
        $have = array_flip(db_server_pdo()->query('SELECT table_name FROM information_schema.tables WHERE table_schema = ' . db_server_pdo()->quote($dbName))->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return ['The database server could not be queried: ' . $e->getMessage()];
    }
    if (!$have) {
        return ['The database does not exist or has no table.'];
    }
    $missing = array_values(array_filter(provision_required_tables(), fn($t) => !isset($have[$t])));
    if ($missing) {
        $issues[] = count($missing) . ' table(s) missing: ' . implode(', ', array_slice($missing, 0, 12)) . (count($missing) > 12 ? '…' : '');
    }
    try {
        $ver = with_company_db($dbName, fn() => db_version());
        if ($ver < DB_VERSION) {
            $issues[] = "Database version $ver, the application expects " . DB_VERSION . ' (it upgrades itself on the next sign-in).';
        }
        $cnt = with_company_db($dbName, fn() => [(int)val('SELECT COUNT(*) FROM settings'), (int)val('SELECT COUNT(*) FROM branches'), (int)val('SELECT COUNT(*) FROM tax_brackets')]);
        if (!$cnt[0]) {
            $issues[] = 'No system settings.';
        }
        if (!$cnt[1]) {
            $issues[] = 'No company / branch record.';
        }
        if (!$cnt[2]) {
            $issues[] = 'The tax table is empty.';
        }
    } catch (Throwable $e) {
        $issues[] = $e->getMessage();
    }
    return $issues;
}

/** Owner fields of a company form, validated (username and e-mail free across all companies). */
function provision_owner_input(array $in, bool $new = true): array
{
    $o = [
        'name' => trim((string)($in['owner_name'] ?? '')),
        'email' => trim((string)($in['owner_email'] ?? '')),
        'username' => strtolower(trim((string)($in['owner_username'] ?? ''))),
        'password' => (string)($in['owner_password'] ?? ''),
    ];
    if ($o['name'] === '' || $o['username'] === '' || $o['email'] === '') {
        throw new RuntimeException('Enter the Company Owner\'s name, e-mail and username.');
    }
    if (!filter_var($o['email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid e-mail address for the Company Owner.');
    }
    if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,59}$/', $o['username'])) {
        throw new RuntimeException('The owner username must be 3 to 60 characters: letters, digits, dot, dash or underscore.');
    }
    if ($new && mc_username_taken($o['username'])) {
        throw new RuntimeException('The username "' . $o['username'] . '" is already used by another account.');
    }
    if ($new && mc_email_taken($o['email'])) {
        throw new RuntimeException('The e-mail address ' . $o['email'] . ' is already used by another account.');
    }
    if ($o['password'] !== '' && strlen($o['password']) < 8) {
        throw new RuntimeException('The owner password must be at least 8 characters (or leave it blank to generate one).');
    }
    if ($o['password'] === '') {
        $o['password'] = provision_temp_password();
        $o['generated'] = true;
    }
    return $o;
}

function provision_temp_password(): string
{
    $a = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $p = '';
    for ($i = 0; $i < 10; $i++) {
        $p .= $a[random_int(0, strlen($a) - 1)];
    }
    return $p . random_int(10, 99);
}

/**
 * Create a company: master record, Company Code, database, structure, defaults, Company Owner,
 * validation, then Active. Returns ['id' => company id, 'owner' => [username, password]].
 * On failure nothing half-made stays usable: the new database is dropped and the company is Failed.
 */
function company_create(array $in): array
{
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 150) {
        throw new RuntimeException('Enter the company name (up to 150 characters).');
    }
    if ((int)mval('SELECT COUNT(*) FROM companies WHERE name = ? AND status <> \'failed\'', [$name])) {
        throw new RuntimeException('A company named "' . $name . '" already exists.');
    }
    $email = trim((string)($in['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid company e-mail address.');
    }
    $owner = provision_owner_input($in);
    $stat = (string)($in['statutory_source'] ?? 'defaults');
    if ($stat !== 'defaults' && !ctype_digit($stat)) {
        $stat = 'defaults';
    }
    $sa = super_admin();
    // 1-3. reserve the company record, generate the database name and the Company Code
    $cid = minsert('companies', [
        'name' => $name, 'address' => trim((string)($in['address'] ?? '')) ?: null, 'contact_no' => trim((string)($in['contact_no'] ?? '')) ?: null,
        'email' => $email ?: null, 'db_name' => '__pending_' . bin2hex(random_bytes(6)), 'status' => 'provisioning', 'db_status' => 'pending',
        'owner_name' => $owner['name'], 'owner_email' => $owner['email'], 'owner_username' => $owner['username'],
        'statutory_source' => $stat, 'created_by' => $sa ? (int)$sa['id'] : null, 'created_at' => now(),
    ]);
    $dbName = company_db_prefix() . str_pad((string)$cid, 3, '0', STR_PAD_LEFT);
    mupdate('companies', ['db_name' => $dbName], 'id = ?', [$cid]);
    provision_log($cid, 'reserve', 'ok', 'Company record reserved; database name ' . $dbName . '.');
    $code = company_code_generate($name, master_db());
    minsert('company_codes', ['company_id' => $cid, 'code' => $code, 'status' => 'active', 'created_by' => $sa['username'] ?? 'system', 'created_at' => now()]);
    provision_log($cid, 'company_code', 'ok', 'Company Code generated.');
    master_audit('company_created', $cid, ['name' => $name, 'database' => $dbName, 'owner' => $owner['username']]);
    company_provision($cid, $owner);
    return ['id' => $cid, 'code' => $code, 'owner' => ['username' => $owner['username'], 'password' => $owner['password'], 'generated' => !empty($owner['generated'])]];
}

/** Steps 4-12 of the creation (also used by Retry on a Failed company). Throws after cleaning up. */
function company_provision(int $cid, array $owner): void
{
    $co = company_by_id($cid);
    $dbName = (string)$co['db_name'];
    $created = false;
    $step = 'create_database';
    try {
        if (!mc_valid_db_name($dbName) || strpos($dbName, company_db_prefix()) !== 0) {
            throw new RuntimeException('Unsafe database name.');
        }
        $srv = db_server_pdo();
        $exists = (int)$srv->query('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ' . $srv->quote($dbName))->fetchColumn();
        if ($exists) {
            throw new RuntimeException("A database named $dbName already exists on the server. It was not touched; remove or rename it, then retry.");
        }
        // 4. the database
        $srv->exec('CREATE DATABASE ' . mc_quote_ident($dbName) . ' DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $created = true;
        mupdate('companies', ['db_status' => 'created'], 'id = ?', [$cid]);
        provision_log($cid, $step, 'ok', "Database $dbName created.");

        // 5. structure and reference data of a clean installation
        $step = 'install_schema';
        $pdo = db_pdo($dbName);
        $n = 0;
        foreach (provision_schema_statements() as $st) {
            $pdo->exec($st);
            $n++;
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        provision_log($cid, $step, 'ok', "$n statements of the MySQL 5.5 structure template applied.");

        // 6. upgrades to the current database version
        $step = 'upgrade';
        $log = with_company_db($dbName, fn() => db_upgrade());
        provision_log($cid, $step, 'ok', 'Upgraded to database version ' . DB_VERSION . ' (' . count($log) . ' step(s)).');

        // 7. clean defaults with the company's own identity
        $step = 'defaults';
        $src = ctype_digit((string)$co['statutory_source']) ? company_by_id((int)$co['statutory_source']) : null;
        with_company_db($dbName, fn() => provision_defaults($co, $owner, $src));
        provision_log($cid, $step, 'ok', 'Company identity set (Head Office branch, top organization unit, System Settings); statutory tables '
            . ($src ? 'copied from ' . $src['name'] : 'from the clean installation defaults') . '.');

        // 8-9. the Company Owner
        $step = 'owner';
        $uid = with_company_db($dbName, function () use ($owner) {
            return insert('users', ['username' => $owner['username'], 'password_hash' => password_hash($owner['password'], PASSWORD_DEFAULT),
                'full_name' => $owner['name'], 'email' => $owner['email'], 'role' => 'admin', 'is_active' => 1, 'must_change_password' => 1]);
        });
        $u = with_company_db($dbName, fn() => row('SELECT * FROM users WHERE id = ?', [$uid]));
        if (!mc_sync_user_row(master_db(), $cid, $u)) {
            throw new RuntimeException('The owner username "' . $owner['username'] . '" was taken by another account in the meantime.');
        }
        mupdate('companies', ['owner_user_id' => $uid], 'id = ?', [$cid]);
        provision_log($cid, $step, 'ok', 'Company Owner account ' . $owner['username'] . ' created (administrator of this company).');
        with_company_db($dbName, fn() => audit('system', 'company_provisioned', 'users', $uid, ['owner' => $owner['username']]));

        // 10-11. mapping and validation
        $step = 'validate';
        $issues = company_db_check($dbName);
        if ($issues) {
            throw new RuntimeException('Validation failed: ' . implode(' ', $issues));
        }
        provision_log($cid, $step, 'ok', 'All ' . count(provision_required_tables()) . ' tables present, database version ' . DB_VERSION . ', defaults in place.');

        // 12. active
        mupdate('companies', ['status' => 'active', 'db_status' => 'ready', 'db_version' => DB_VERSION, 'provision_error' => null, 'activated_at' => now(), 'updated_at' => now()], 'id = ?', [$cid]);
        mq("UPDATE company_codes SET status = 'active' WHERE company_id = ? AND status = 'disabled' AND id = (SELECT id FROM (SELECT MAX(id) AS id FROM company_codes WHERE company_id = ?) t)", [$cid, $cid]);
        provision_log($cid, 'activate', 'ok', 'Company is Active.');
        master_audit('company_provisioning_completed', $cid, ['database' => $dbName]);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        provision_log($cid, $step, 'failed', $msg);
        $dropped = false;
        if ($created) {
            try {
                db_forget($dbName);
                db_server_pdo()->exec('DROP DATABASE IF EXISTS ' . mc_quote_ident($dbName));
                $dropped = true;
                provision_log($cid, 'cleanup', 'info', "Incomplete database $dbName dropped.");
            } catch (Throwable $e2) {
                provision_log($cid, 'cleanup', 'failed', 'The incomplete database could not be dropped: ' . $e2->getMessage());
            }
        }
        mq('DELETE FROM company_memberships WHERE company_id = ?', [$cid]);
        mq("UPDATE company_codes SET status = 'disabled' WHERE company_id = ? AND status = 'active'", [$cid]);
        mupdate('companies', ['status' => 'failed', 'db_status' => $created ? ($dropped ? 'dropped' : 'failed') : 'none', 'owner_user_id' => null,
            'provision_error' => mb_substr("[$step] $msg", 0, 2000), 'updated_at' => now()], 'id = ?', [$cid]);
        master_audit('company_provisioning_failed', $cid, ['step' => $step, 'error' => mb_substr($msg, 0, 500)]);
        throw new RuntimeException('Company provisioning failed at step "' . $step . '": ' . $msg . ' The company was not activated'
            . ($dropped ? ' and the incomplete database was removed.' : '.'));
    }
}

/** Inside the new company database: its own identity instead of the sample company of the template. */
function provision_defaults(array $co, array $owner, ?array $src): void
{
    // the clean installation script carries a sample organization; a new company starts with its own
    q('SET FOREIGN_KEY_CHECKS = 0');
    q('DELETE FROM org_units');
    q('DELETE FROM branches');
    q('DELETE FROM locations');
    q('SET FOREIGN_KEY_CHECKS = 1');
    insert('branches', ['id' => 1, 'code' => 'HO', 'name' => 'Head Office', 'company_name' => $co['name'], 'address' => $co['address'],
        'company_code' => 'HO', 'branch_code' => 'HO', 'is_active' => 1, 'is_default' => 1, 'geofence_radius_m' => 200]);
    insert('org_units', ['id' => 1, 'parent_id' => null, 'code' => 'CORP', 'name' => $co['name'], 'unit_type' => 'Company', 'sort_order' => 0, 'default_pay_group_id' => 1]);
    $set = [
        'company_name' => $co['name'], 'company_address' => (string)$co['address'], 'company_tin' => '',
        'hr_notify_email' => $owner['email'], 'mail_from' => $co['email'] ?: 'hris@example.com',
    ];
    foreach ($set as $k => $v) {
        q('UPDATE settings SET svalue = ? WHERE skey = ?', [$v, $k]);
    }
    if ($src && mc_valid_db_name($src['db_name']) && $src['db_name'] !== active_db_name()) {
        // bring the source up to date first so both have the same columns
        with_company_db($src['db_name'], fn() => db_upgrade());
        foreach (PROVISION_STATUTORY_TABLES as $t) {
            $cols = q('SELECT a.column_name FROM information_schema.columns a JOIN information_schema.columns b ON b.table_name = a.table_name AND b.column_name = a.column_name
                       WHERE a.table_schema = ? AND b.table_schema = ? AND a.table_name = ? ORDER BY a.ordinal_position', [active_db_name(), $src['db_name'], $t])->fetchAll(PDO::FETCH_COLUMN);
            if (!$cols) {
                continue;
            }
            $list = '`' . implode('`,`', $cols) . '`';
            q("DELETE FROM `$t`");
            q("INSERT INTO `$t` ($list) SELECT $list FROM " . mc_quote_ident($src['db_name']) . ".`$t`");
        }
    }
    setting('__reload__');
}

/** Retry a Failed company with its stored owner details (a new temporary password is generated). */
function company_retry(int $cid): array
{
    $co = company_by_id($cid);
    if (!$co || $co['status'] !== 'failed') {
        throw new RuntimeException('Only a company whose provisioning failed can be retried.');
    }
    $owner = provision_owner_input(['owner_name' => $co['owner_name'], 'owner_email' => $co['owner_email'], 'owner_username' => $co['owner_username']]);
    mupdate('companies', ['status' => 'provisioning', 'db_status' => 'pending', 'provision_error' => null, 'updated_at' => now()], 'id = ?', [$cid]);
    provision_log($cid, 'retry', 'info', 'Provisioning retried.');
    company_provision($cid, $owner);
    return ['username' => $owner['username'], 'password' => $owner['password']];
}

/** Remove the master record of a Failed company (its database was already dropped). */
function company_discard_failed(int $cid): void
{
    $co = company_by_id($cid);
    if (!$co || $co['status'] !== 'failed' || $co['db_status'] === 'failed') {
        throw new RuntimeException('Only a failed company whose database was removed can be discarded.');
    }
    mq('DELETE FROM company_memberships WHERE company_id = ?', [$cid]);
    mq('DELETE FROM company_codes WHERE company_id = ?', [$cid]);
    mq('DELETE FROM companies WHERE id = ?', [$cid]);
    provision_log($cid, 'discard', 'info', 'Failed company record discarded by ' . (super_admin()['username'] ?? 'system') . '.');
    master_audit('company_discarded', $cid, ['name' => $co['name']]);
}

/** Activate / deactivate. Deactivation never deletes anything: data stays, sign-in and the code stop. */
function company_set_status(int $cid, string $status): void
{
    $co = company_by_id($cid);
    if (!$co || !in_array($status, ['active', 'inactive'], true) || !in_array($co['status'], ['active', 'inactive'], true)) {
        throw new RuntimeException('This company cannot be changed to ' . $status . '.');
    }
    if ($status === 'active') {
        $issues = company_db_check($co['db_name']);
        $blocking = array_filter($issues, fn($i) => strpos($i, 'upgrades itself') === false);
        if ($blocking) {
            throw new RuntimeException('The company database is not complete: ' . implode(' ', $blocking));
        }
    }
    mupdate('companies', ['status' => $status, 'updated_at' => now(), $status === 'active' ? 'activated_at' : 'deactivated_at' => now()], 'id = ?', [$cid]);
    master_audit($status === 'active' ? 'company_activated' : 'company_deactivated', $cid, ['name' => $co['name']]);
}

/**
 * Make a user of the company its Company Owner (existing account, or a new account created here).
 * The owner is an administrator of that company only.
 */
function company_assign_owner(int $cid, array $in): array
{
    $co = company_by_id($cid);
    if (!$co || $co['db_status'] !== 'ready') {
        throw new RuntimeException('The company database is not ready.');
    }
    $result = ['created' => null];
    if (!empty($in['user_id'])) {
        $uid = (int)$in['user_id'];
        $u = with_company_db($co['db_name'], fn() => row('SELECT * FROM users WHERE id = ? AND is_support = 0', [$uid]));
        if (!$u || !$u['is_active']) {
            throw new RuntimeException('Choose an active user account of this company.');
        }
        if ($u['role'] !== 'admin' || !empty($u['role_id'])) {
            with_company_db($co['db_name'], function () use ($uid, $u) {
                // the Company Owner holds the protected System Administrator (Super Admin) role
                update('users', ['role' => 'admin'] + (mig_column_exists('users', 'role_id') ? ['role_id' => null] : []), 'id = ?', [$uid]);
                audit('admin', 'user_update', 'users', $uid, ['role' => [$u['role'], 'admin'], 'reason' => 'Assigned Company Owner']);
            });
        }
    } else {
        $o = provision_owner_input($in);
        $uid = with_company_db($co['db_name'], function () use ($o) {
            $id = insert('users', ['username' => $o['username'], 'password_hash' => password_hash($o['password'], PASSWORD_DEFAULT), 'full_name' => $o['name'],
                'email' => $o['email'], 'role' => 'admin', 'is_active' => 1, 'must_change_password' => 1]);
            audit('admin', 'user_create', 'users', $id, ['username' => $o['username'], 'role' => 'admin', 'reason' => 'Company Owner']);
            return $id;
        });
        $result['created'] = ['username' => $o['username'], 'password' => $o['password']];
    }
    $u = with_company_db($co['db_name'], fn() => row('SELECT * FROM users WHERE id = ?', [$uid]));
    if (!mc_sync_user_row(master_db(), $cid, $u)) {
        throw new RuntimeException('The username "' . $u['username'] . '" belongs to an account of another company; rename it first.');
    }
    $old = $co['owner_user_id'];
    mupdate('companies', ['owner_user_id' => $uid, 'owner_name' => $u['full_name'], 'owner_email' => $u['email'], 'owner_username' => $u['username'], 'updated_at' => now()], 'id = ?', [$cid]);
    master_audit($old ? 'company_owner_changed' : 'company_owner_assigned', $cid, ['from_user_id' => $old, 'to' => $u['username']]);
    return $result;
}

/**
 * The hidden support account a Super Admin works under inside a company (an administrator of that
 * company, is_support = 1, no usable password, never listed or mapped for sign-in). Every change a
 * Super Admin makes in the company is therefore recorded in that company's own audit trail.
 */
function company_support_user(array $co, array $sa): int
{
    return with_company_db($co['db_name'], function () use ($sa) {
        db_upgrade();
        $uname = substr('support.' . $sa['username'], 0, 60);
        $u = row('SELECT * FROM users WHERE username = ?', [$uname]);
        if ($u && !$u['is_support']) {
            $uname = substr('support.' . $sa['username'], 0, 52) . '.' . (int)$sa['id'];
            $u = row('SELECT * FROM users WHERE username = ?', [$uname]);
        }
        if ($u) {
            update('users', ['is_active' => 1, 'role' => 'admin', 'is_support' => 1, 'must_change_password' => 0, 'module_access' => null]
                + (mig_column_exists('users', 'role_id') ? ['role_id' => null] : []), 'id = ?', [$u['id']]);
            return (int)$u['id'];
        }
        return insert('users', ['username' => $uname, 'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
            'full_name' => $sa['full_name'] . ' (Super Admin)', 'role' => 'admin', 'is_active' => 1, 'is_support' => 1, 'must_change_password' => 0]);
    });
}

/** Figures of one company, read from its own database (company detail page only, never in lists). */
function company_stats(array $co): array
{
    $s = ['employees' => null, 'users' => null, 'pending' => null, 'last_run' => null, 'tables' => null, 'size_mb' => null, 'db_version' => null, 'error' => null];
    try {
        $srv = db_server_pdo();
        $r = $srv->query('SELECT COUNT(*) AS n, SUM(data_length + index_length) AS sz FROM information_schema.tables WHERE table_schema = ' . $srv->quote($co['db_name']))->fetch();
        $s['tables'] = (int)$r['n'];
        $s['size_mb'] = round((float)$r['sz'] / 1048576, 2);
        if ($s['tables']) {
            $x = with_company_db($co['db_name'], function () {
                return [
                    (int)val("SELECT COUNT(*) FROM employees WHERE status = 'active'"),
                    (int)val('SELECT COUNT(*) FROM users WHERE is_active = 1' . (mig_column_exists('users', 'is_support') ? ' AND is_support = 0' : '')),
                    mig_column_exists('users', 'reg_status') ? (int)val("SELECT COUNT(*) FROM users WHERE reg_status = 'pending'") : 0,
                    row("SELECT run_no, period_start, period_end, status FROM payroll_runs WHERE status <> 'cancelled' ORDER BY period_end DESC, id DESC LIMIT 1"),
                    db_version(),
                ];
            });
            [$s['employees'], $s['users'], $s['pending'], $s['last_run'], $s['db_version']] = $x;
        }
    } catch (Throwable $e) {
        $s['error'] = $e->getMessage();
    }
    return $s;
}
