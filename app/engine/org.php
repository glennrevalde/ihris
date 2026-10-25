<?php
/**
 * Organizational structure helpers (multi-level tree: Division > Department > Section > Unit ...).
 */

function org_units_all(bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = [];
        foreach (rows('SELECT * FROM org_units ORDER BY sort_order, name') as $r) {
            $cache[(int)$r['id']] = $r;
        }
    }
    return $cache;
}

function org_children(?int $parentId): array
{
    return array_values(array_filter(org_units_all(), fn($u) => (int)$u['parent_id'] === (int)$parentId && ($parentId !== null || $u['parent_id'] === null)));
}

/** The unit id plus all of its descendants. */
function org_descendant_ids(int $id): array
{
    $all = org_units_all();
    $ids = [$id];
    $queue = [$id];
    while ($queue) {
        $p = array_shift($queue);
        foreach ($all as $u) {
            if ((int)$u['parent_id'] === $p && !in_array((int)$u['id'], $ids, true)) {
                $ids[] = (int)$u['id'];
                $queue[] = (int)$u['id'];
            }
        }
    }
    return $ids;
}

function org_path(?int $id, string $sep = ' › '): string
{
    if (!$id) {
        return '';
    }
    $all = org_units_all();
    $parts = [];
    $guard = 0;
    while ($id && isset($all[$id]) && $guard++ < 20) {
        array_unshift($parts, $all[$id]['name']);
        $id = $all[$id]['parent_id'] ? (int)$all[$id]['parent_id'] : null;
    }
    return implode($sep, $parts);
}

/** Nearest department head walking up the tree (excluding an employee, e.g. the requester). */
function dept_head_of_unit(int $unitId, ?int $excludeEmployeeId = null): ?int
{
    $all = org_units_all();
    $id = $unitId;
    $guard = 0;
    while ($id && isset($all[$id]) && $guard++ < 20) {
        $head = $all[$id]['head_employee_id'] ? (int)$all[$id]['head_employee_id'] : null;
        if ($head && $head !== $excludeEmployeeId) {
            return $head;
        }
        $id = $all[$id]['parent_id'] ? (int)$all[$id]['parent_id'] : null;
    }
    return null;
}

/** Indented <select> options reflecting the tree. */
function org_unit_options(): array
{
    $out = [];
    $walk = function (?int $parent, int $depth) use (&$walk, &$out) {
        foreach (org_units_all() as $u) {
            if (($parent === null && $u['parent_id'] === null) || ($parent !== null && (int)$u['parent_id'] === $parent)) {
                $out[$u['id']] = str_repeat('— ', $depth) . $u['name'] . ' (' . $u['unit_type'] . ')';
                $walk((int)$u['id'], $depth + 1);
            }
        }
    };
    $walk(null, 0);
    return $out;
}

function branch_options(bool $activeOnly = true): array
{
    return pairs("SELECT id, CONCAT(code, ' - ', name) FROM branches" . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY code');
}

/**
 * Employee groups offered by the filters of the reports and the transaction forms (v16): only
 * the active ones. An employee group set to Active "No" in the Employee Group Table is not listed.
 */
function emp_group_options(): array
{
    return pairs("SELECT id, CONCAT(code, ' — ', name) FROM emp_groups WHERE is_active = 1 ORDER BY code");
}

function pay_group_options(): array
{
    return pairs('SELECT id, name FROM pay_groups WHERE is_active = 1 ORDER BY id');
}

/**
 * Active shifts for a select (v19): the code and the actual time coverage of the shift (every log of the day,
 * 2, 4, 6 or 8 logs with the pre-arranged overtime) instead of its description, so the user sees what is assigned.
 */
function shift_options(): array
{
    $out = [];
    foreach (rows('SELECT * FROM shifts WHERE is_active = 1 ORDER BY time_in, code') as $s) {
        $out[$s['id']] = shift_option_label($s);
    }
    return $out;
}

function employee_row(int $id): ?array
{
    return row('SELECT e.*, ou.name AS org_unit_name, pg.name AS pay_group_name, pg.frequency, b.name AS branch_name,
                       sg.code AS grade_code, s.name AS shift_name
                FROM employees e
                LEFT JOIN org_units ou ON ou.id = e.org_unit_id
                LEFT JOIN pay_groups pg ON pg.id = e.pay_group_id
                LEFT JOIN branches b ON b.id = e.default_branch_id
                LEFT JOIN salary_grades sg ON sg.id = e.salary_grade_id
                LEFT JOIN shifts s ON s.id = e.shift_id
                WHERE e.id = ?', [$id]);
}

/** Monthly-equivalent and daily rates of an employee. */
function emp_type_row(?int $id): ?array
{
    static $cache = [];
    if (!$id) {
        return null;
    }
    if (!array_key_exists($id, $cache)) {
        $cache[$id] = row('SELECT * FROM emp_types WHERE id = ?', [$id]);
    }
    return $cache[$id];
}

/** Monthly-to-daily conversion factor (days per month) of an employee: Employee Type table, else days-per-year / 12. */
function employee_factor(array $e): float
{
    $t = emp_type_row(!empty($e['employee_type_id']) ? (int)$e['employee_type_id'] : null);
    if ($t && (float)$t['conversion_factor'] > 0) {
        return (float)$t['conversion_factor'];
    }
    return max(1, (int)($e['days_per_year'] ?? 0) ?: 261) / 12;
}

function employee_hours_per_day(array $e): float
{
    $t = emp_type_row(!empty($e['employee_type_id']) ? (int)$e['employee_type_id'] : null);
    if ($t && (float)$t['hours_per_day'] > 0) {
        return (float)$t['hours_per_day'];
    }
    return setting_num('hours_per_day', 8);
}

function employee_rates(array $e): array
{
    $factor = employee_factor($e);
    $hpd = employee_hours_per_day($e);
    if (($e['rate_type'] ?? 'monthly') === 'daily') {
        $daily = (float)$e['basic_rate'];
        $monthly = $daily * $factor;
    } else {
        $monthly = (float)$e['basic_rate'];
        $daily = $monthly / $factor;
    }
    return ['monthly' => round($monthly, 2), 'daily' => round($daily, 4), 'hourly' => round($daily / $hpd, 4), 'minute' => $daily / $hpd / 60,
        'factor' => $factor, 'hpd' => $hpd];
}

/** Code — name options of a simple reference table (active rows). */
function lookup_options(string $table, bool $activeOnly = true): array
{
    static $cache = [];
    $k = $table . (int)$activeOnly;
    if (!isset($cache[$k])) {
        $cache[$k] = pairs("SELECT id, CONCAT(code, ' — ', name) FROM `$table`" . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY code');
    }
    return $cache[$k];
}

/** Id of the row flagged as default in a reference table. */
function lookup_default(string $table): ?int
{
    $v = val("SELECT id FROM `$table` WHERE is_default = 1 ORDER BY id LIMIT 1");
    return $v ? (int)$v : null;
}

/** Is the employee exempt from withholding tax (MWE flag or a tax-exempt exemption code)? */
function employee_tax_exempt(array $e): bool
{
    if (!empty($e['is_mwe'])) {
        return true;
    }
    if (!empty($e['exemption_code_id'])) {
        return (bool)val('SELECT tax_exempt FROM exemption_codes WHERE id = ?', [$e['exemption_code_id']]);
    }
    return false;
}

/** User account linked to an employee (for approvals / emails). */
function employee_user(int $employeeId): ?array
{
    return row('SELECT * FROM users WHERE employee_id = ? AND is_active = 1 ORDER BY id LIMIT 1', [$employeeId]);
}

function employee_email(int $employeeId): ?string
{
    $u = employee_user($employeeId);
    if ($u && $u['email']) {
        return $u['email'];
    }
    return val('SELECT email FROM employees WHERE id = ?', [$employeeId]) ?: null;
}
