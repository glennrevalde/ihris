<?php
/**
 * Which employees a user may see: the Company / branch and Department assignment of
 * System > User Access & Permissions (v13).
 *
 *   - Nothing ticked (no company / branch, no department, no "All" box): the user sees NO employee.
 *   - Companies / branches ticked: only the employees of those companies / branches.
 *   - Departments ticked: only the employees of those departments and the units under them.
 *   - Both ticked: the employees of the ticked departments inside the ticked companies / branches.
 *   - "All companies / branches" or "All departments": no limit on that side (including the
 *     companies and departments added later).
 *   - The Super Admin (System Administrator role, Company Owner, platform support) sees everyone.
 *   - The employee linked to the account still opens his or her own records (self-service, own 201 file).
 *
 * Tables: user_scopes (user_id, scope_type branch|org_unit, ref_id) and the flags
 * users.scope_all_branches / users.scope_all_units.
 */

/** The assignment of a user: ['all' => bool, 'none' => bool, 'branch' => ids|null, 'unit' => ids|null]. Null = no limit on that side. */
function scope_def(?array $u = null): array
{
    $u = $u ?? current_user();
    if (!$u || is_super_user($u === current_user() ? null : $u)) {
        return ['all' => true, 'none' => false, 'branch' => null, 'unit' => null];
    }
    $uid = (int)$u['id'];
    $key = (function_exists('active_db_name') ? active_db_name() : '') . '|' . $uid;
    if (isset($GLOBALS['__scope'][$key])) {
        return $GLOBALS['__scope'][$key];
    }
    $sc = ['branch' => [], 'org_unit' => []];
    foreach (rows('SELECT scope_type, ref_id FROM user_scopes WHERE user_id = ?', [$uid]) as $r) {
        $sc[$r['scope_type']][] = (int)$r['ref_id'];
    }
    $flags = scope_flags_supported() ? (row('SELECT scope_all_branches, scope_all_units FROM users WHERE id = ?', [$uid]) ?: []) : ['scope_all_branches' => 1, 'scope_all_units' => 1];
    $allB = !empty($flags['scope_all_branches']);
    $allU = !empty($flags['scope_all_units']);
    $none = !$allB && !$allU && !$sc['branch'] && !$sc['org_unit'];
    $unit = null;
    if (!$allU && $sc['org_unit']) {
        $unit = [];
        foreach ($sc['org_unit'] as $ou) {
            $unit = array_merge($unit, org_descendant_ids($ou));
        }
        $unit = array_values(array_unique(array_map('intval', $unit))) ?: [0];
    }
    $def = [
        'all' => !$none && ($allB || !$sc['branch']) && ($allU || !$sc['org_unit']),
        'none' => $none,
        'branch' => !$allB && $sc['branch'] ? $sc['branch'] : null,
        'unit' => $unit,
        'all_branches' => $allB, 'all_units' => $allU, 'branches_ticked' => $sc['branch'], 'units_ticked' => $sc['org_unit'],
    ];
    return $GLOBALS['__scope'][$key] = $def;
}

/** Before the version-12 upgrade the flags do not exist yet: the old rule (nothing ticked = everyone) applies. */
function scope_flags_supported(): bool
{
    static $ok = [];
    $db = function_exists('active_db_name') ? active_db_name() : '';
    if (!isset($ok[$db])) {
        try {
            $ok[$db] = (bool)val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'scope_all_branches'");
        } catch (Throwable $e) {
            $ok[$db] = false;
        }
    }
    return $ok[$db];
}

/** " AND ..." condition limiting an employees alias to the user's assignment ('' = no limit). */
function scope_sql(string $a = 'e', ?array $u = null): string
{
    $d = scope_def($u);
    if ($d['all']) {
        return '';
    }
    if ($d['none']) {
        return ' AND 1 = 0';
    }
    $sql = '';
    if ($d['branch'] !== null) {
        $sql .= " AND $a.default_branch_id IN (" . implode(',', array_map('intval', $d['branch'])) . ')';
    }
    if ($d['unit'] !== null) {
        $sql .= " AND $a.org_unit_id IN (" . implode(',', array_map('intval', $d['unit'])) . ')';
    }
    return $sql;
}

/** May the user see this employee? */
function scope_allows(int $empId): bool
{
    if ($empId <= 0) {
        return true;
    }
    $d = scope_def();
    if ($d['all'] || $empId === (int)(current_user()['employee_id'] ?? 0)) {
        return true;
    }
    static $memo = [];
    if (!array_key_exists($empId, $memo)) {
        $memo[$empId] = (bool)val('SELECT COUNT(*) FROM employees e WHERE e.id = ?' . scope_sql('e'), [$empId]);
    }
    return $memo[$empId];
}

/** Plain-language summary of a user's assignment (User Access & Permissions, User Accounts). */
function scope_summary(array $u): string
{
    $d = scope_def($u);
    if (is_super_user($u)) {
        return 'All employees (Super Admin)';
    }
    if ($d['none']) {
        return 'No employee: no company / branch or department assigned';
    }
    $b = $d['all_branches'] ? 'all companies / branches' : ($d['branches_ticked'] ? count($d['branches_ticked']) . ' company / branch(es)' : 'all companies / branches');
    $o = $d['all_units'] ? 'all departments' : ($d['units_ticked'] ? count($d['units_ticked']) . ' department(s)' : 'all departments');
    return ucfirst($b) . ', ' . $o;
}

/**
 * Employees a request names (employee_id, emp, emp_id, employee_ids[] in the address or the form,
 * and the record of the detail pages) must be inside the user's assignment. Called by index.php
 * after the permission check. Self-service pages and approvals by the reporting line are not limited.
 */
function scope_authorize(string $route): void
{
    salary_authorize($route);   // v32
    if (scope_def()['all']) {
        return;
    }
    $free = ['dashboard', 'account', 'logout', 'login', 'approvals', 'manual', 'manual_topic', 'file', 'switch_company', 'help'];
    if (in_array($route, $free, true) || strpos($route, 'my_') === 0 || strpos($route, 'sa_') === 0) {
        return;
    }
    $ids = [];
    foreach ([$_GET, $_POST] as $src) {
        foreach (['employee_id', 'emp', 'emp_id', 'employee_ids', 'evaluatee_id'] as $k) {
            if (!isset($src[$k])) {
                continue;
            }
            foreach ((array)$src[$k] as $v) {
                if (is_scalar($v) && (int)$v > 0) {
                    $ids[] = (int)$v;
                }
            }
        }
    }
    // detail pages addressed by the record id
    $recordOf = [
        'employee_view' => null, 'employee_edit' => null,
        'leave_view' => 'SELECT employee_id FROM leave_applications WHERE id = ?',
        'case_view' => 'SELECT employee_id FROM disciplinary_cases WHERE id = ?',
        'case_print' => 'SELECT employee_id FROM disciplinary_cases WHERE id = ?',
        'evaluation_edit' => 'SELECT employee_id FROM evaluations WHERE id = ?',
        'dtr_edit' => 'SELECT employee_id FROM dtr WHERE id = ?',
        'payslip' => 'SELECT employee_id FROM payroll_items WHERE id = ?',
        'ledger_view' => 'SELECT employee_id FROM ledgers WHERE id = ?',
        'ledger_print' => 'SELECT employee_id FROM ledgers WHERE id = ?',
        'emp_term_view' => 'SELECT employee_id FROM employee_term_trans WHERE id = ?',
        'final_pay' => 'SELECT employee_id FROM final_pays WHERE id = ?',
    ];
    if (array_key_exists($route, $recordOf)) {
        $rid = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($rid > 0) {
            if ($recordOf[$route] === null) {
                $ids[] = $rid;
            } else {
                try {
                    $e = val($recordOf[$route], [$rid]);
                    if ($e) {
                        $ids[] = (int)$e;
                    }
                } catch (Throwable $x) {
                }
            }
        }
    }
    foreach (array_unique($ids) as $id) {
        if (!scope_allows($id)) {
            scope_deny();
        }
    }
}

function scope_deny(): void
{
    if (function_exists('wants_json') && wants_json()) {
        json_response(['error' => 'This employee is outside your company / branch and department assignment.', 'status' => 403], 403);
    }
    http_response_code(403);
    $GLOBALS['__perm_denied'] = true;
    page_header('Access denied');
    echo '<div class="card denied-card"><div class="denied-code">403</div><div><h2>Employee outside your assignment</h2>'
        . '<p class="muted">This employee is not in the companies / branches and departments assigned to your account (System, User Access &amp; Permissions). Ask your system administrator if you need access.</p>'
        . '<p><a class="btn" href="' . url('dashboard') . '">Go to the dashboard</a></p></div></div>';
    page_footer();
    exit;
}

/* ===================================================================== Basic Salary rights (v13, v32) */

/**
 * May the user see ('view') or change ('edit') the basic salary of the employees?
 * v32: with an employee id, the answer is also No for an employee on the user's salary restriction list
 * (System > User Access & Permissions, "Salary restriction").
 */
function salary_can(string $action = 'view', ?int $empId = null): bool
{
    if (!current_user()) {
        return false;
    }
    if (is_super_user()) {
        return true;
    }
    if ($empId && salary_restricted($empId)) {
        return false;
    }
    if ($action === 'edit') {
        return can('employee_salary.edit') && can('employee_salary.view');
    }
    return can('employee_salary.view');
}

/** The basic salary as shown to the user: the amount, or a hidden mark (v32: per employee when an id is given). */
function salary_show($amount, string $suffix = '', ?int $empId = null): string
{
    if (salary_can('view', $empId)) {
        return money($amount) . $suffix;
    }
    $why = $empId && salary_restricted($empId) ? 'Hidden: salary restricted for your account' : 'Hidden: no Basic Salary view right';
    return '<span class="muted salary-hidden" title="' . h($why) . '">Hidden</span>';
}

/* --------------------------------------------------------------------- Salary restriction (v32)
 * A Super Admin lists, per user, the employees whose pay that user may not see (typically the top
 * management). Table user_salary_exclusions (user_id, employee_id). For the user, these employees:
 *   - stay in the employee lists and 201 files (the assignment above decides who is listed),
 *   - show "Hidden" in place of the basic salary and the rates (201 file, employee list, 201 Masterlisting,
 *     batch edit, import, salary scaling, evaluation), and their salary cannot be changed by the user,
 *   - are left out of the payslips, the payroll registers and every report that shows pay per employee,
 *     with a note saying how many were left out.
 * The employee linked to the user's own account is never restricted (self-service). The Super Admin
 * sees every salary.
 */

/** Ids of the employees whose pay the user may not see ([] = no restriction). */
function salary_restricted_ids(?array $u = null): array
{
    $u = $u ?? current_user();
    if (!$u || is_super_user($u === current_user() ? null : $u)) {
        return [];
    }
    $uid = (int)$u['id'];
    $key = (function_exists('active_db_name') ? active_db_name() : '') . '|' . $uid;
    if (!isset($GLOBALS['__salx'][$key])) {
        $ids = [];
        if (salary_restriction_supported()) {
            $ids = array_map('intval', q('SELECT employee_id FROM user_salary_exclusions WHERE user_id = ?', [$uid])->fetchAll(PDO::FETCH_COLUMN));
        }
        $own = (int)($u['employee_id'] ?? 0);
        $GLOBALS['__salx'][$key] = array_values(array_diff($ids, [$own]));
    }
    return $GLOBALS['__salx'][$key];
}

/** Is this employee on the user's salary restriction list? */
function salary_restricted(int $empId, ?array $u = null): bool
{
    return $empId > 0 && in_array($empId, salary_restricted_ids($u), true);
}

/** " AND col NOT IN (...)" leaving out the restricted employees ('' = no restriction). $col is the employee id column. */
function salary_excl_sql(string $col): string
{
    $ids = salary_restricted_ids();
    return $ids ? " AND $col NOT IN (" . implode(',', $ids) . ')' : '';
}

/** The assignment limit (scope_sql) plus the salary restriction, for lists that show pay per employee. */
function salary_scope_sql(string $a = 'e'): string
{
    return scope_sql($a) . salary_excl_sql("$a.id");
}

/** Employee picker of a pay page: employee_options() without the restricted employees. */
function salary_employee_options(bool $activeOnly = true): array
{
    $o = employee_options($activeOnly);
    $ids = salary_restricted_ids();
    return $ids ? array_diff_key($o, array_flip($ids)) : $o;
}

/** Drop the restricted employees from rows; $hidden receives how many were dropped. */
function salary_filter_rows(array $rows, string $empKey = 'employee_id', ?int &$hidden = null): array
{
    $ids = salary_restricted_ids();
    $hidden = 0;
    if (!$ids) {
        return $rows;
    }
    $out = [];
    foreach ($rows as $k => $r) {
        if (in_array((int)($r[$empKey] ?? 0), $ids, true)) {
            $hidden++;
            continue;
        }
        $out[$k] = $r;
    }
    return array_is_list_compat($rows) ? array_values($out) : $out;
}

/** array_is_list() for PHP 7. */
function array_is_list_compat(array $a): bool
{
    $i = 0;
    foreach ($a as $k => $_) {
        if ($k !== $i++) {
            return false;
        }
    }
    return true;
}

/** How many restricted employees an employees query would have returned (for the "left out" note). */
function salary_hidden_count(string $fromWhere, array $params = [], string $col = 'e.id'): int
{
    $ids = salary_restricted_ids();
    if (!$ids) {
        return 0;
    }
    return (int)val("SELECT COUNT(DISTINCT $col) $fromWhere AND $col IN (" . implode(',', $ids) . ')', $params);
}

/** The note shown above a list or report that left restricted employees out ('' when none). */
function salary_hidden_note(int $n, string $what = 'this list', string $extra = ''): string
{
    if ($n <= 0) {
        return '';
    }
    return '<div class="callout salary-note"><strong>' . $n . ' employee' . ($n === 1 ? ' is' : 's are') . ' not shown in ' . h($what) . '.</strong> '
        . 'Their pay is restricted for your account (System, User Access &amp; Permissions, Salary restriction). Totals cover the employees shown.'
        . ($extra !== '' ? ' ' . h($extra) : '') . '</div>';
}

/**
 * The note of a report that leaves out every restricted employee of the user ('' when the user has no
 * restriction). Used where counting the employees of the selection would take another query.
 */
function salary_restricted_note(string $what = 'this report', bool $print = false): string
{
    $ids = salary_restricted_ids();
    if (!$ids) {
        return '';
    }
    $n = (int)val('SELECT COUNT(*) FROM employees e WHERE e.id IN (' . implode(',', $ids) . ')' . scope_sql('e'));
    if (!$n) {
        return '';
    }
    if ($print) {
        return '<div class="doc-meta"><div><em>The ' . $n . ' employee(s) whose pay is restricted for the user who printed this report are not included.</em></div></div>';
    }
    return '<div class="callout salary-note"><strong>The ' . $n . ' employee' . ($n === 1 ? '' : 's') . ' whose pay is restricted for your account ' . ($n === 1 ? 'is' : 'are') . ' not included in ' . h($what) . '.</strong> '
        . 'System, User Access &amp; Permissions, Salary restriction. Totals cover the employees shown.</div>';
}

/** The same note for a printout ('' when none). */
function salary_print_note(int $n): string
{
    return $n > 0 ? '<div class="doc-meta"><div><em>' . $n . ' employee' . ($n === 1 ? ' is' : 's are') . ' not included: salary restricted for the user who printed this report.</em></div></div>' : '';
}

/** Refuse a page about the pay of a restricted employee. */
function salary_deny(): void
{
    if (function_exists('wants_json') && wants_json()) {
        json_response(['error' => 'The pay of this employee is restricted for your account.', 'status' => 403], 403);
    }
    http_response_code(403);
    $GLOBALS['__perm_denied'] = true;
    page_header('Access denied');
    echo '<div class="card denied-card"><div class="denied-code">403</div><div><h2>Salary restricted</h2>'
        . '<p class="muted">The pay of this employee is restricted for your account (System, User Access &amp; Permissions, Salary restriction). Ask your system administrator if you need access.</p>'
        . '<p><a class="btn" href="' . url('dashboard') . '">Go to the dashboard</a></p></div></div>';
    page_footer();
    exit;
}

/**
 * Replace the salary restriction list of a user (System > User Access & Permissions).
 * Returns the new count when the list changed (audited), null when nothing changed.
 */
function salary_restriction_save(int $uid, array $empIds): ?int
{
    $u = row('SELECT * FROM users WHERE id = ?', [$uid]);
    if (!$u || !salary_restriction_supported()) {
        return null;
    }
    $empIds = array_values(array_unique(array_filter(array_map('intval', $empIds))));
    $own = (int)($u['employee_id'] ?? 0);
    $empIds = array_values(array_diff($empIds, [$own]));   // the user's own record is never restricted
    $ok = $empIds ? array_map('intval', q('SELECT id FROM employees WHERE id IN (' . in_list($empIds) . ')', $empIds)->fetchAll(PDO::FETCH_COLUMN)) : [];
    $old = array_map('intval', q('SELECT employee_id FROM user_salary_exclusions WHERE user_id = ?', [$uid])->fetchAll(PDO::FETCH_COLUMN));
    sort($ok);
    sort($old);
    if ($ok === $old) {
        return null;
    }
    $added = array_values(array_diff($ok, $old));
    $removed = array_values(array_diff($old, $ok));
    if ($removed) {
        q('DELETE FROM user_salary_exclusions WHERE user_id = ? AND employee_id IN (' . in_list($removed) . ')', array_merge([$uid], $removed));
    }
    foreach ($added as $eid) {
        insert('user_salary_exclusions', ['user_id' => $uid, 'employee_id' => $eid, 'created_by' => user_id(), 'created_at' => now()]);
    }
    $nos = function (array $ids) {
        return $ids ? q('SELECT employee_no FROM employees WHERE id IN (' . in_list($ids) . ') ORDER BY employee_no', $ids)->fetchAll(PDO::FETCH_COLUMN) : [];
    };
    audit('admin', 'user_salary_restriction', 'users', $uid, ['user' => $u['username'], 'restricted' => count($ok), 'added' => $nos($added), 'removed' => $nos($removed)]);
    unset($GLOBALS['__salx']);
    return count($ok);
}

/**
 * v32: the pages about pay refuse a request that names an employee on the user's salary restriction list
 * (the employee filter of a report, a payslip, a final pay ...). Called by scope_authorize().
 */
const SALARY_ROUTES = ['payslip', 'payslips_print', 'pay_register', 'pay_register2', 'pay_inquiry', 'bank_file', 'final_pay', 'retro',
    'rpt_periodical', 'rpt_monthly_contrib', 'rpt_ytd_reports', 'rpt_bank_listing', 'rpt_cash_listing', 'rpt_register', 'rpt_earn_ded',
    'rpt_13th', 'rpt_monthly', 'rpt_branch', 'rpt_remittance', 'rpt_ytd', 'rpt_2316'];

function salary_authorize(string $route): void
{
    if (!in_array($route, SALARY_ROUTES, true) || !salary_restricted_ids()) {
        return;
    }
    $ids = [];
    foreach ([$_GET, $_POST] as $src) {
        foreach (['employee_id', 'emp', 'emp_id', 'employee_ids'] as $k) {
            foreach (isset($src[$k]) ? (array)$src[$k] : [] as $v) {
                if (is_scalar($v) && (int)$v > 0) {
                    $ids[] = (int)$v;
                }
            }
        }
    }
    $rid = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($rid > 0 && in_array($route, ['payslip', 'final_pay'], true)) {
        try {
            $e = val($route === 'payslip' ? 'SELECT employee_id FROM payroll_items WHERE id = ?' : 'SELECT employee_id FROM final_pays WHERE id = ?', [$rid]);
            if ($e) {
                $ids[] = (int)$e;
            }
        } catch (Throwable $x) {
        }
    }
    foreach (array_unique($ids) as $id) {
        if (salary_restricted($id)) {
            salary_deny();
        }
    }
}

/** Does the database have the v25 table yet? */
function salary_restriction_supported(): bool
{
    static $ok = [];
    $db = function_exists('active_db_name') ? active_db_name() : '';
    if (!isset($ok[$db])) {
        try {
            $ok[$db] = (bool)val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'user_salary_exclusions'");
        } catch (Throwable $e) {
            $ok[$db] = false;
        }
    }
    return $ok[$db];
}

/** The same limit for a LEFT JOIN of the employees: rows without an employee (unmatched logs ...) stay visible. */
function scope_sql_or_null(string $a, string $idCol): string
{
    $s = scope_sql($a);
    return $s === '' ? '' : " AND ($idCol IS NULL OR (1 = 1$s))";
}

/** Does an employee record (new or changed) fall inside the user's assignment (company / branch and department)? */
function scope_fits(array $d): bool
{
    $s = scope_def();
    if ($s['all']) {
        return true;
    }
    if ($s['none']) {
        return false;
    }
    if ($s['branch'] !== null && !in_array((int)($d['default_branch_id'] ?? 0), $s['branch'], true)) {
        return false;
    }
    if ($s['unit'] !== null && !in_array((int)($d['org_unit_id'] ?? 0), $s['unit'], true)) {
        return false;
    }
    return true;
}

/** Audit details without the salary figures, for a user without the Basic Salary view right. */
function salary_mask_details(?string $details, ?string $entity = null, $entityId = null): string
{
    $details = (string)$details;
    // v32: an employee on the user's salary restriction list is masked too
    $empId = in_array((string)$entity, ['employees', 'employee'], true) && ctype_digit((string)$entityId) ? (int)$entityId : null;
    if ($details === '' || salary_can('view', $empId) || !preg_match('/basic_rate|_rate"/', $details)) {
        return $details;
    }
    $data = json_decode($details, true);
    if (!is_array($data)) {
        return preg_replace('/("(?:basic_rate|old_rate|new_rate|monthly_rate|daily_rate|hourly_rate)"\s*:\s*)("[^"]*"|[-0-9.]+|\{[^}]*\})/', '$1"hidden"', $details);
    }
    $walk = function ($v) use (&$walk) {
        if (!is_array($v)) {
            return $v;
        }
        foreach ($v as $k => $x) {
            $v[$k] = in_array((string)$k, ['basic_rate', 'old_rate', 'new_rate', 'monthly_rate', 'daily_rate', 'hourly_rate'], true) ? 'hidden' : $walk($x);
        }
        return $v;
    };
    return json_encode($walk($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
