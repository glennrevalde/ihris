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

/* ===================================================================== Basic Salary rights (v13) */

/** May the user see ('view') or change ('edit') the basic salary of the employees? */
function salary_can(string $action = 'view'): bool
{
    if (!current_user()) {
        return false;
    }
    if (is_super_user()) {
        return true;
    }
    if ($action === 'edit') {
        return can('employee_salary.edit') && can('employee_salary.view');
    }
    return can('employee_salary.view');
}

/** The basic salary as shown to the user: the amount, or a hidden mark. */
function salary_show($amount, string $suffix = ''): string
{
    return salary_can('view') ? money($amount) . $suffix : '<span class="muted salary-hidden" title="Hidden: no Basic Salary view right">Hidden</span>';
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
function salary_mask_details(?string $details): string
{
    $details = (string)$details;
    if ($details === '' || salary_can('view') || !preg_match('/basic_rate|_rate"/', $details)) {
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
