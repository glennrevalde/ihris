<?php
/**
 * Payroll / attendance TRANSACTIONS (hours summary per employee per period).
 *
 * One record per employee per period (table attendance_summaries). It can be
 *   - generated from the Attendance Monitoring System (DTR -> "Auto-Generate Attendance Transaction"), or
 *   - encoded manually by the timekeeper / payroll master (single entry, entry II, batch entry).
 * The payroll engine computes pay from these hour fields, so both paths feed payroll the same way.
 *
 * Field groups follow the M.A.D.A.S. attendance / payroll batch transaction forms.
 */

/** key => [long label, short column label, group] */
const PT_FIELDS = [
    'hours_work'    => ['Hours work', 'Work', 'regular'],
    'absent_hrs'    => ['Absent', 'Absent', 'regular'],
    'late_hrs'      => ['Lates', 'Late', 'regular'],
    'undertime_hrs' => ['Undertime', 'Under', 'regular'],
    'vl_hrs'        => ['Vacation leave', 'VL', 'regular'],
    'sl_hrs'        => ['Sick leave', 'SL', 'regular'],
    'el_hrs'        => ['Emergency leave', 'EL', 'regular'],
    'ol_hrs'        => ['Other / offset leave', 'OL', 'regular'],
    'pm_hrs'        => ['Maternity / paternity leave', 'P/M', 'regular'],
    'holiday_hrs'   => ['Total holiday hrs. in period (unworked, paid)', 'Hol', 'regular'],

    'ot_reg'        => ['Regular day overtime', 'Reg', 'ot_reg'],
    'rd_hrs'        => ['Day-off duty', 'Off', 'ot_reg'],
    'lh_hrs'        => ['Legal holiday duty', 'Leg', 'ot_reg'],
    'sh_hrs'        => ['Special holiday duty', 'Spe', 'ot_reg'],
    'rd_ot'         => ['Day-off overtime', 'Off', 'ot_ext'],
    'lh_ot'         => ['Legal holiday overtime', 'Leg', 'ot_ext'],
    'sh_ot'         => ['Special holiday overtime', 'Spe', 'ot_ext'],
    'lh_rd'         => ['Legal holiday falling on day-off', 'Leg', 'off_hol'],
    'sh_rd'         => ['Special holiday falling on day-off', 'Spe', 'off_hol'],
    'lh_rd_ot'      => ['Legal holiday on day-off overtime', 'Leg', 'off_hol_ot'],
    'sh_rd_ot'      => ['Special holiday on day-off overtime', 'Spe', 'off_hol_ot'],

    'np_reg'        => ['Night premium — regular day', 'Reg', 'np'],
    'np_rd'         => ['Night premium — day-off', 'Off', 'np'],
    'np_lh'         => ['Night premium — legal holiday duty', 'Leg', 'np'],
    'np_sh'         => ['Night premium — special holiday duty', 'Spe', 'np'],
    'np_reg_ot'     => ['Night premium — regular day overtime', 'Reg', 'np_ext'],
    'np_rd_ot'      => ['Night premium — day-off overtime', 'Off', 'np_ext'],
    'np_lh_ot'      => ['Night premium — legal holiday overtime', 'Leg', 'np_ext'],
    'np_sh_ot'      => ['Night premium — special holiday overtime', 'Spe', 'np_ext'],
    'np_lh_rd'      => ['Night premium — legal holiday on day-off', 'Leg', 'np_off_hol'],
    'np_sh_rd'      => ['Night premium — special holiday on day-off', 'Spe', 'np_off_hol'],
    'np_lh_rd_ot'   => ['Night premium — legal holiday on day-off OT', 'Leg', 'np_off_hol_ot'],
    'np_sh_rd_ot'   => ['Night premium — special holiday on day-off OT', 'Spe', 'np_off_hol_ot'],

    'amt_13th'      => ['13th month (amount)', '13th Month', 'others'],
    'service_charge'=> ['Service charge (amount)', 'Svc. Chg.', 'others'],
    'sl_conversion' => ['SL conversion (amount)', 'SL Conv.', 'others'],
    'vl_conversion' => ['VL conversion (amount)', 'VL Conv.', 'others'],
    'other_ded'     => ['Other deduction (amount)', 'Other Ded.', 'others'],
    'tickets'       => ['Meal tickets availed (count)', 'Tickets', 'others'],
    'meal_comp'     => ['Meal compensation (count)', 'Meal Comp', 'others'],
    'meal_comp_ot'  => ['Meal compensation OT (count)', 'Meal Comp OT', 'others'],
];

/** Column groups of the batch transaction grid (label, css tone). */
const PT_GROUPS = [
    'regular'       => 'Regular Hours',
    'ot_reg'        => 'Overtime Reg. Hours',
    'ot_ext'        => 'Overtime Ext. Hours',
    'off_hol'       => 'Day-Off Holiday',
    'off_hol_ot'    => 'Day-Off Holiday OT',
    'np'            => 'Night Premium',
    'np_ext'        => 'Night Premium Ext.',
    'np_off_hol'    => 'Night Premium Off Holiday',
    'np_off_hol_ot' => 'Night Premium Off Holiday OT',
    'others'        => 'Others',
];

/** Hour fields (everything except the "others" amounts/counts). */
function pt_hour_fields(): array
{
    return array_keys(array_filter(PT_FIELDS, fn($f) => $f[2] !== 'others'));
}

/** All numeric transaction fields. */
function pt_all_fields(): array
{
    return array_keys(PT_FIELDS);
}

/** Fields that are split per branch in multi-location entry (hours only). */
function pt_location_fields(): array
{
    return pt_hour_fields();
}

/**
 * Pay-rate category of each premium / overtime hour field (table pay_rates).
 * np fields use the night % of the same category; np_reg uses the base night premium rate.
 */
const PT_RATE_OF = [
    'ot_reg' => 'reg_ot', 'rd_hrs' => 'rd', 'rd_ot' => 'rd_ot',
    'lh_hrs' => 'lh', 'lh_ot' => 'lh_ot', 'lh_rd' => 'lh_rd', 'lh_rd_ot' => 'lh_rd_ot',
    'sh_hrs' => 'sh', 'sh_ot' => 'sh_ot', 'sh_rd' => 'sh_rd', 'sh_rd_ot' => 'sh_rd_ot',
    'np_reg' => 'reg', 'np_reg_ot' => 'reg_ot', 'np_rd' => 'rd', 'np_rd_ot' => 'rd_ot',
    'np_lh' => 'lh', 'np_lh_ot' => 'lh_ot', 'np_lh_rd' => 'lh_rd', 'np_lh_rd_ot' => 'lh_rd_ot',
    'np_sh' => 'sh', 'np_sh_ot' => 'sh_ot', 'np_sh_rd' => 'sh_rd', 'np_sh_rd_ot' => 'sh_rd_ot',
];

/** Payslip abbreviations in the M.A.D.A.S. style: (REG) (OFF) (OFF) XT (LEG) REG ... */
const PT_SLIP_LABEL = [
    'ot_reg' => '(Reg)', 'rd_hrs' => '(OFF)', 'rd_ot' => '(OFF) XT',
    'lh_hrs' => '(LEG) REG', 'lh_ot' => '(LEG) XT', 'lh_rd' => '(LEG) OFF', 'lh_rd_ot' => '(LEG) OFF XT',
    'sh_hrs' => '(SPE) REG', 'sh_ot' => '(SPE) XT', 'sh_rd' => '(SPE) OFF', 'sh_rd_ot' => '(SPE) OFF XT',
    'np_reg' => '(Reg)', 'np_reg_ot' => '(OT) XT', 'np_rd' => '(OFF)', 'np_rd_ot' => '(OFF) XT',
    'np_lh' => '(LEG) REG', 'np_lh_ot' => '(LEG) XT', 'np_lh_rd' => '(LEG) OFF', 'np_lh_rd_ot' => '(LEG) OFF XT',
    'np_sh' => '(SPE) REG', 'np_sh_ot' => '(SPE) XT', 'np_sh_rd' => '(SPE) OFF', 'np_sh_rd_ot' => '(SPE) OFF XT',
];

/**
 * Terms of fixed transactions (employee_fixed_trans / fixed_trans_codes): on which pay of the month the
 * fixed amount is added or deducted. A fixed transaction has no end: it runs while the employee works.
 */
const FIXED_TERMS = [
    'every_pay'  => 'Every pay',
    'first_pay'  => '1st pay of the month',
    'second_pay' => '2nd pay of the month',
    'third_pay'  => '3rd pay of the month',
    'fourth_pay' => '4th pay of the month',
];

/** Terms kept only for setups made before version 7 (shown and still computed, not offered for new ones). */
const FIXED_TERMS_LEGACY = ['monthly_split' => 'Monthly split (old setting)'];

/** Label of a fixed transaction term, legacy ones included. */
function fixed_term_label(?string $t): string
{
    return FIXED_TERMS[$t] ?? FIXED_TERMS_LEGACY[$t] ?? (string)$t;
}

/** Options of a terms dropdown: the five terms, plus the legacy one when the record still uses it. */
function fixed_term_options(?string $current = null): array
{
    return FIXED_TERMS + ($current && isset(FIXED_TERMS_LEGACY[$current]) ? [$current => FIXED_TERMS_LEGACY[$current]] : []);
}

/** Benefits table "Given at": fixed amount per pay, or amount per day / hour / week of duty (-Hol: paid holidays count as duty). */
const BENEFIT_GIVEN_AT = [
    'fixed'         => 'Fixed (per pay)',
    'duty_day_hol'  => 'Duty(Day)-Hol',
    'duty_hour_hol' => 'Duty(Hour)-Hol',
    'duty_week_hol' => 'Duty(Week)-Hol',
    'duty_day'      => 'Duty(Day)',
    'duty_hour'     => 'Duty(Hour)',
    'duty_week'     => 'Duty(Week)',
];

/* ======================================================================= periods */

/** Period number within the month (1 = 1st pay, 2 = 2nd pay...), month and year of a period ending on $end. */
function pt_period_meta(string $start, string $end, string $frequency): array
{
    $y = (int)substr($end, 0, 4);
    $m = (int)substr($end, 5, 2);
    if ($frequency === 'monthly') {
        $no = 1;
    } elseif ($frequency === 'semi_monthly') {
        $no = (int)substr($end, 8, 2) <= 15 ? 1 : 2;
    } else {
        // weekly / daily: count the periods of the same pay group that end earlier in the same month
        $no = 1 + (int)floor(((int)substr($end, 8, 2) - 1) / ($frequency === 'weekly' ? 7 : 1));
    }
    return ['period_no' => $no, 'period_month' => $m, 'period_year' => $y];
}

/** Label of a period (cutoff) row: "SM-RF: 09/28/25 - 10/12/25". */
function pt_period_label(array $c, bool $withGroup = true): string
{
    $l = date('m/d/y', strtotime($c['period_start'])) . ' - ' . date('m/d/y', strtotime($c['period_end']));
    if ($withGroup && !empty($c['pay_group_code'])) {
        $l = $c['pay_group_code'] . ': ' . $l;
    }
    if (!empty($c['description'])) {
        $l .= ' (' . $c['description'] . ')';
    }
    return $l;
}

/**
 * Attendance screens (Attendance Summary Entry, Entry II, Batch, Closing, Attendance Entry, Auto-Generate,
 * Leave / Shift entry, attendance CSV import) list only periods whose payroll is not yet closed / paid:
 * a paid period is never offered there again.
 */
function pt_open_only_route(?string $route = null): bool
{
    $route = $route ?? preg_replace('/[^a-z0-9_]/', '', (string)($_GET['p'] ?? ''));
    if (in_array($route, ['att_summary', 'att_summary2', 'att_batch', 'att_close', 'att_entry', 'att_generate', 'att_process', 'att_leave_entry', 'batch_shift', 'import_att_batch'], true)) {
        return true;
    }
    // shared screens opened from an attendance screen
    if (in_array($route, ['trans_multiloc', 'import_pay_trans'], true)) {
        $br = function_exists('back_route') ? (string)back_route() : '';
        return (string)get('side') === 'att' || strpos($br, 'att_') === 0;
    }
    return false;
}

/** Period options for dropdowns, newest first (only the periods not yet paid on the attendance screens). */
function pt_period_options(?int $payGroupId = null, int $limit = 60, ?bool $openOnly = null): array
{
    $openOnly = $openOnly ?? pt_open_only_route();
    $w = [];
    if ($payGroupId) {
        $w[] = 'c.pay_group_id = ' . (int)$payGroupId;
    }
    if ($openOnly) {
        $w[] = "c.status <> 'locked'";
    }
    $sql = 'SELECT c.*, pg.code AS pay_group_code FROM attendance_cutoffs c JOIN pay_groups pg ON pg.id = c.pay_group_id'
        . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY c.period_end DESC, pg.code LIMIT ' . (int)$limit;
    $o = [];
    foreach (rows($sql) as $c) {
        $o[$c['id']] = pt_period_label($c) . ($c['status'] === 'locked' ? ' — paid' : ($c['status'] === 'approved' ? ' — closed' : ''));
    }
    return $o;
}

function pt_period(int $id): ?array
{
    return row('SELECT c.*, pg.code AS pay_group_code, pg.name AS pay_group_name, pg.frequency FROM attendance_cutoffs c JOIN pay_groups pg ON pg.id = c.pay_group_id WHERE c.id = ?', [$id]);
}

/** The period to preselect: requested, else the latest open one. */
function pt_default_period(?int $requested, ?bool $openOnly = null): ?array
{
    $openOnly = $openOnly ?? pt_open_only_route();
    if ($requested && ($p = pt_period($requested)) && (!$openOnly || $p['status'] !== 'locked')) {
        return $p;
    }
    $id = val("SELECT id FROM attendance_cutoffs WHERE status IN ('open','summarized') ORDER BY period_end DESC LIMIT 1")
        ?: val('SELECT id FROM attendance_cutoffs' . ($openOnly ? " WHERE status <> 'locked'" : '') . ' ORDER BY period_end DESC LIMIT 1');
    return $id ? pt_period((int)$id) : null;
}

/**
 * Can the transactions of this period still be edited?
 * Attendance side (att_*): while the period is open / summarized. Payroll side (pay_*): the closed
 * transactions, until the payroll of the period is finalized (period locked).
 */
function pt_period_editable(?array $period, string $side = 'att'): bool
{
    if (!$period) {
        return false;
    }
    // v19: the attendance side stays editable in an 'approved' period too: each transaction is locked by its own
    // closing (a closed company / branch), so the employees not closed yet can still be encoded and summarized
    return in_array($period['status'], ['open', 'summarized', 'approved'], true);
}

/* ======================================================================= attendance closing */

/** Side of a transaction screen: 'pay' (Payroll Transaction screens) or 'att' (Attendance Summary screens). */
function pt_side(string $route): string
{
    return strpos($route, 'pay_') === 0 || $route === 'import_pay_trans' ? 'pay' : 'att';
}

/**
 * May this employee's transaction be changed on this side?
 * Attendance side: only while it is not closed. Payroll side: only once it is closed (it then belongs to payroll).
 */
function pt_row_editable(?array $period, ?array $row, string $side): bool
{
    if (!pt_period_editable($period, $side)) {
        return false;
    }
    if ($side === 'pay') {
        return $row !== null && !empty($row['is_closed']);
    }
    return $row === null || empty($row['is_closed']);
}

/** Message for a transaction that cannot be changed on this side. */
function pt_locked_reason(?array $period, ?array $row, string $side): string
{
    if (!$period) {
        return 'The period does not exist.';
    }
    if (!pt_period_editable($period, $side)) {
        return $period['status'] === 'locked' ? 'The payroll of this period is closed and paid (Period Closing); its transactions can no longer be changed.'
            : 'This period is ' . $period['status'] . ' and can no longer be changed here.';
    }
    if ($side === 'pay') {
        return 'This attendance transaction is not closed yet. Close it in Attendance > Attendance Transaction Closing; only closed transactions are processed by payroll.';
    }
    return 'This attendance transaction is closed and now belongs to the Payroll Transaction. Re-open it in Attendance > Attendance Transaction Closing to change it here.';
}

/**
 * Employees in scope of a closing (period + Company / Branch, Employee Group, Employee Type, Department,
 * or an explicit list) with their transaction of the period.
 */
function att_close_scope(array $period, array $f, ?array $empIds = null): array
{
    $emps = pt_period_employees($period, array_intersect_key($f, array_flip(['branch_id', 'employee_group_id', 'employee_type_id', 'org_unit_id', 'q'])));
    if ($empIds !== null) {
        $emps = array_values(array_filter($emps, fn($e) => in_array((int)$e['id'], $empIds, true)));
    }
    $trans = [];
    foreach (rows('SELECT * FROM attendance_summaries WHERE cutoff_id = ?', [$period['id']]) as $r) {
        $trans[(int)$r['employee_id']] = $r;
    }
    $out = [];
    foreach ($emps as $e) {
        $out[] = ['emp' => $e, 'trans' => $trans[(int)$e['id']] ?? null];
    }
    return $out;
}

/** Counts of a closing scope: employees, transactions, closed, open, open DTR exceptions of the open ones. */
function att_close_counts(array $scope): array
{
    $c = ['employees' => count($scope), 'transactions' => 0, 'closed' => 0, 'open' => 0, 'missing' => 0, 'exceptions' => 0, 'manual' => 0];
    foreach ($scope as $s) {
        if (!$s['trans']) {
            $c['missing']++;
            continue;
        }
        $c['transactions']++;
        if ($s['trans']['is_closed']) {
            $c['closed']++;
        } else {
            $c['open']++;
            $c['exceptions'] += (int)$s['trans']['open_exceptions'];
            $c['manual'] += $s['trans']['source'] === 'manual' ? 1 : 0;
        }
    }
    return $c;
}

/**
 * Close the attendance transactions in scope, whether they come from summarized logs or were encoded by hand.
 * Closed transactions appear in the Payroll Transaction screens and are what the payroll run pays.
 */
function att_close(int $cutoffId, array $f = [], bool $exceptionsReviewed = false, ?array $empIds = null): array
{
    $period = pt_period($cutoffId);
    if (!$period) {
        throw new RuntimeException('Period not found.');
    }
    if ($period['status'] === 'locked') {
        throw new RuntimeException('The payroll of this period is already closed and paid.');
    }
    $scope = att_close_scope($period, $f, $empIds);
    $cnt = att_close_counts($scope);
    if (!$cnt['open']) {
        throw new RuntimeException($cnt['transactions'] ? 'Every attendance transaction in this selection is already closed.'
            : 'There is no attendance transaction in this selection yet. Process the attendance or encode it first.');
    }
    if ($cnt['exceptions'] > 0 && !$exceptionsReviewed) {
        throw new RuntimeException('The selection still has ' . $cnt['exceptions'] . ' unresolved attendance exception(s). Resolve them in the DTR, or tick "Exceptions reviewed" to close the transactions as encoded.');
    }
    $ids = [];
    foreach ($scope as $s) {
        if ($s['trans'] && !$s['trans']['is_closed']) {
            $ids[] = (int)$s['trans']['id'];
        }
    }
    tx(function () use ($ids, $cutoffId) {
        q('UPDATE attendance_summaries SET is_closed = 1, closed_at = ?, closed_by = ? WHERE id IN (' . implode(',', $ids) . ')', [now(), user_id()]);
        att_cutoff_sync_status($cutoffId);
    });
    audit('ams', 'attendance_close', 'attendance_cutoffs', $cutoffId, ['closed' => count($ids), 'filters' => array_filter($f), 'exceptions_accepted' => $cnt['exceptions'],
        'employees' => $empIds]);
    return ['closed' => count($ids), 'missing' => $cnt['missing'], 'already' => $cnt['closed'], 'exceptions' => $cnt['exceptions']];
}

/** Re-open closed transactions in scope so the attendance side can change them again. */
function att_reopen(int $cutoffId, array $f = [], ?array $empIds = null): array
{
    $period = pt_period($cutoffId);
    if (!$period) {
        throw new RuntimeException('Period not found.');
    }
    if ($period['status'] === 'locked') {
        throw new RuntimeException('The payroll of this period is closed and paid; its attendance can no longer be re-opened.');
    }
    $ids = [];
    foreach (att_close_scope($period, $f, $empIds) as $s) {
        if ($s['trans'] && $s['trans']['is_closed']) {
            $ids[] = (int)$s['trans']['id'];
        }
    }
    if (!$ids) {
        throw new RuntimeException('No closed attendance transaction in this selection.');
    }
    tx(function () use ($ids, $cutoffId) {
        q('UPDATE attendance_summaries SET is_closed = 0, closed_at = NULL, closed_by = NULL WHERE id IN (' . implode(',', $ids) . ')');
        att_cutoff_sync_status($cutoffId);
    });
    audit('ams', 'attendance_reopen', 'attendance_cutoffs', $cutoffId, ['reopened' => count($ids), 'filters' => array_filter($f), 'employees' => $empIds]);
    $run = val("SELECT run_no FROM payroll_runs WHERE cutoff_id = ? AND status = 'computed'", [$cutoffId]);
    return ['reopened' => count($ids), 'run' => $run];
}

/** Period status follows its transactions: every one closed = approved (closed for payroll), else summarized. */
function att_cutoff_sync_status(int $cutoffId): void
{
    $c = row('SELECT * FROM attendance_cutoffs WHERE id = ?', [$cutoffId]);
    if (!$c || $c['status'] === 'locked') {
        return;
    }
    $n = row('SELECT COUNT(*) AS n, COALESCE(SUM(is_closed), 0) AS closed FROM attendance_summaries WHERE cutoff_id = ?', [$cutoffId]);
    if ((int)$n['n'] > 0 && (int)$n['closed'] === (int)$n['n']) {
        if ($c['status'] !== 'approved') {
            update('attendance_cutoffs', ['status' => 'approved', 'approved_by' => user_id(), 'approved_at' => now()], 'id = ?', [$cutoffId]);
        }
    } elseif ($c['status'] === 'approved') {
        update('attendance_cutoffs', ['status' => (int)$n['n'] ? 'summarized' : 'open', 'approved_by' => null, 'approved_at' => null], 'id = ?', [$cutoffId]);
    }
}

/** Closed / total transactions of a period. */
function att_close_progress(int $cutoffId): array
{
    $n = row('SELECT COUNT(*) AS n, COALESCE(SUM(is_closed), 0) AS closed FROM attendance_summaries WHERE cutoff_id = ?', [$cutoffId]);
    return ['total' => (int)$n['n'], 'closed' => (int)$n['closed']];
}

/* ======================================================================= employee selection */

/** Limit of the employees the user may see (company / branch and department assignment): app/engine/scope.php. */
function user_scope_sql(string $a = 'e'): string
{
    return scope_sql($a);
}

/**
 * Employees of a period, filtered like the M.A.D.A.S. batch forms:
 * Co/Branch, Employee Group, Employee Type, Pay Code (= the period's pay group), Department.
 */
function pt_period_employees(array $period, array $f = []): array
{
    // Everyone of the pay code who was employed during the period, plus every active employee
    // (a newly added employee must appear at once so the payroll master can encode their hours).
    $where = ['e.pay_group_id = ?', "(e.status = 'active' OR e.date_hired <= ?)", '(e.date_separated IS NULL OR e.date_separated >= ?)'];
    $params = [$period['pay_group_id'], $period['period_end'], $period['period_start']];
    $sel = '0 AS split_here';
    // v20: Co. / Branch, Department and Employee group take one, two or more values
    $branches = id_list($f['branch_id'] ?? null);
    if ($branches && empty($f['with_split'])) {
        $where[] = sql_in('e.default_branch_id', $branches);
    } elseif ($branches) {
        // batch entry: the employees of those companies / branches, plus everyone with hours recorded there on a
        // multi-location transaction of this period (split_here = 1 for those)
        $inSplit = 'EXISTS (SELECT 1 FROM attendance_summaries xs JOIN attendance_summary_branches xb ON xb.summary_id = xs.id
                    WHERE xs.cutoff_id = ' . (int)$period['id'] . ' AND xs.employee_id = e.id AND ' . sql_in('xb.branch_id', $branches) . ')';
        $where[] = '(' . sql_in('e.default_branch_id', $branches) . ' OR ' . $inSplit . ')';
        $sel = '(CASE WHEN ' . $inSplit . ' THEN 1 ELSE 0 END) AS split_here';
    }
    if ($groups = id_list($f['employee_group_id'] ?? null)) {
        $where[] = sql_in('e.employee_group_id', $groups);
    }
    if (!empty($f['employee_type_id'])) {
        $where[] = 'e.employee_type_id = ?';
        $params[] = (int)$f['employee_type_id'];
    }
    if ($units = org_units_with_descendants($f['org_unit_id'] ?? null)) {
        $where[] = sql_in('e.org_unit_id', $units);
    }
    if (!empty($f['q'])) {
        // every word typed must match the name, the employee no. or the biometric ID (v16 incremental search)
        $where[] = search_words_sql(['e.employee_no', 'e.last_name', 'e.first_name', 'e.middle_name', 'e.biometric_id'], (string)$f['q'], $params);
    }
    return rows('SELECT e.*, ou.name AS unit_name, b.code AS branch_code, ' . $sel . ' FROM employees e LEFT JOIN org_units ou ON ou.id = e.org_unit_id LEFT JOIN branches b ON b.id = e.default_branch_id WHERE '
        . implode(' AND ', $where) . user_scope_sql('e') . ' ORDER BY e.last_name, e.first_name', $params);
}

/** Filter values read from the request (shared by all transaction forms). */
function pt_filters(): array
{
    // v20: Co. / Branch, Department and Employee group are lists (one, two or more; empty = all)
    return [
        'branch_id' => id_list(get('branch_id')), 'employee_group_id' => id_list(get('employee_group_id')),
        'employee_type_id' => get('employee_type_id') ?: null, 'org_unit_id' => id_list(get('org_unit_id')), 'q' => trim((string)get('q', '')),
    ];
}

/* ======================================================================= transactions */

function pt_get(int $cutoffId, int $empId): ?array
{
    return row('SELECT * FROM attendance_summaries WHERE cutoff_id = ? AND employee_id = ?', [$cutoffId, $empId]);
}

/** Zero-filled transaction (for new entries). */
function pt_blank(): array
{
    return array_fill_keys(pt_all_fields(), 0);
}

/** Validate & normalize posted values of the transaction fields. Returns [values, errors]. */
function pt_clean(array $in, array $period): array
{
    $maxHours = (days_between($period['period_start'], $period['period_end']) + 1) * 24;
    $out = [];
    $err = [];
    foreach (PT_FIELDS as $k => $meta) {
        if (!array_key_exists($k, $in)) {
            continue;
        }
        $raw = trim(str_replace(',', '', (string)$in[$k]));
        if ($raw === '') {
            $raw = '0';
        }
        if (!is_numeric($raw)) {
            $err[] = $meta[0] . ' must be a number.';
            continue;
        }
        $v = $k === 'tickets' ? (int)$raw : round((float)$raw, 2);
        if ($v < 0) {
            $err[] = $meta[0] . ' cannot be negative.';
            continue;
        }
        if ($meta[2] !== 'others' && $v > $maxHours) {
            $err[] = sprintf('%s (%s hrs) is more than the hours in the period.', $meta[0], $v);
            continue;
        }
        $out[$k] = $v;
    }
    if (isset($out['absent_hrs'], $out['hours_work']) && $out['absent_hrs'] > $out['hours_work'] && $out['hours_work'] > 0) {
        $err[] = 'Absent hours cannot exceed the hours work.';
    }
    return [$out, $err];
}

/**
 * Save (insert or update) the transaction of an employee for a period.
 * $source: 'manual' for encoded / edited transactions.
 */
function pt_save(int $cutoffId, int $empId, array $values, ?string $remarks = null, string $source = 'manual'): int
{
    $old = pt_get($cutoffId, $empId);
    $data = $values;
    $data['source'] = $source;
    $data['remarks'] = $remarks;
    $data['entered_by'] = user_id();
    $data['updated_at'] = now();
    if ($old) {
        update('attendance_summaries', $data, 'id = ?', [$old['id']]);
        $changes = diff_fields($old, $data);
        unset($changes['updated_at'], $changes['entered_by']);
        if ($changes) {
            audit('payroll', 'transaction_update', 'attendance_summaries', $old['id'], $changes);
        }
        return (int)$old['id'];
    }
    $id = insert('attendance_summaries', array_merge(['cutoff_id' => $cutoffId, 'employee_id' => $empId], $data));
    audit('payroll', 'transaction_create', 'attendance_summaries', $id, array_filter($values));
    return $id;
}

function pt_delete(int $cutoffId, int $empId): bool
{
    $old = pt_get($cutoffId, $empId);
    if (!$old) {
        return false;
    }
    q('DELETE FROM attendance_summaries WHERE id = ?', [$old['id']]);
    audit('payroll', 'transaction_delete', 'attendance_summaries', $old['id'], array_intersect_key($old, array_flip(pt_all_fields())));
    return true;
}

/** Multi-location rows of a transaction: [branch_id => [field => hours]]. */
function pt_branches(int $summaryId): array
{
    $o = [];
    foreach (rows('SELECT * FROM attendance_summary_branches WHERE summary_id = ? ORDER BY branch_id', [$summaryId]) as $r) {
        $o[(int)$r['branch_id']] = array_intersect_key($r, array_flip(pt_location_fields()));
    }
    return $o;
}

/** Multi-location rows of every transaction of a period: employee id => branch id => hour values. */
function pt_period_splits(int $cutoffId): array
{
    $o = [];
    foreach (rows('SELECT s.employee_id, sb.* FROM attendance_summary_branches sb JOIN attendance_summaries s ON s.id = sb.summary_id WHERE s.cutoff_id = ?', [$cutoffId]) as $r) {
        $o[(int)$r['employee_id']][(int)$r['branch_id']] = array_intersect_key($r, array_flip(pt_location_fields()));
    }
    return $o;
}

/**
 * The part of one employee's transaction that belongs to a company / branch: the hours recorded
 * there on the multi-location entry, plus the non-hour fields (Others) when that branch is the
 * deduction source. For a transaction without a split, the whole transaction belongs to the
 * employee's default branch. Returns [values, is_split, is_source].
 */
function pt_branch_part(?array $summary, array $splitRows, int $branchId, int $defaultBranch): array
{
    $vals = array_fill_keys(pt_all_fields(), 0.0);
    if (!$summary) {
        return [$vals, false, $branchId === $defaultBranch];
    }
    $src = !empty($summary['deduction_branch_id']) ? (int)$summary['deduction_branch_id'] : $defaultBranch;
    if (!$splitRows) {
        if ($branchId === $defaultBranch) {
            foreach ($vals as $k => $v) {
                $vals[$k] = (float)$summary[$k];
            }
        }
        return [$vals, false, $branchId === $src];
    }
    foreach (pt_location_fields() as $k) {
        $vals[$k] = (float)($splitRows[$branchId][$k] ?? 0);
    }
    if ($branchId === $src) {
        foreach (array_diff(pt_all_fields(), pt_location_fields()) as $k) {
            $vals[$k] = (float)$summary[$k];
        }
    }
    return [$vals, true, $branchId === $src];
}

/** Replace the multi-location rows and set the transaction totals to their sums. */
function pt_save_branches(int $summaryId, array $byBranch): void
{
    q('DELETE FROM attendance_summary_branches WHERE summary_id = ?', [$summaryId]);
    $tot = array_fill_keys(pt_location_fields(), 0.0);
    $n = 0;
    foreach ($byBranch as $b => $vals) {
        $vals = array_intersect_key($vals, $tot);
        if (!array_filter($vals, fn($v) => (float)$v != 0.0)) {
            continue;
        }
        insert('attendance_summary_branches', array_merge(['summary_id' => $summaryId, 'branch_id' => (int)$b], array_map(fn($v) => round((float)$v, 2), $vals)));
        foreach ($vals as $k => $v) {
            $tot[$k] += (float)$v;
        }
        $n++;
    }
    if ($n) {
        update('attendance_summaries', array_merge(array_map(fn($v) => round($v, 2), $tot), ['source' => 'manual', 'updated_at' => now(), 'entered_by' => user_id()]), 'id = ?', [$summaryId]);
    }
    audit('payroll', 'transaction_locations', 'attendance_summaries', $summaryId, ['locations' => $n]);
}

/** Totals of all transactions of a period (per field). */
function pt_period_totals(int $cutoffId): array
{
    $sel = implode(', ', array_map(fn($f) => "COALESCE(SUM(`$f`),0) AS `$f`", pt_all_fields()));
    return row("SELECT COUNT(*) AS employees, $sel FROM attendance_summaries WHERE cutoff_id = ?", [$cutoffId]) ?: [];
}

/** Format an hours / amount value for grids: blank for zero. */
function pt_fmt($v, string $field = ''): string
{
    $v = (float)$v;
    if (abs($v) < 0.005) {
        return '';
    }
    return $field === 'tickets' ? (string)(int)$v : number_format($v, 2);
}


/* ======================================================================= perfect attendance & leaves */

/** Leave hour fields that reduce the hours work of a monthly-salaried employee. */
function pt_leave_fields(): array
{
    return ['vl_hrs', 'sl_hrs', 'el_hrs', 'ol_hrs', 'pm_hrs'];
}

/**
 * "Perfect attendance" hours of an employee for a period: the required hours of every
 * scheduled working day that is not a rest day or a holiday (the same yardstick the
 * Auto-Generate uses for hours work).
 */
function pt_perfect_hours(array $period, array $emp): float
{
    $total = 0;
    foreach (date_range($period['period_start'], $period['period_end']) as $d) {
        $sched = employee_schedule($emp, $d);
        if ($sched['is_rest'] || $sched['no_schedule']) {
            continue;
        }
        if (holiday_on($d, (int)($sched['branch_id'] ?: $emp['default_branch_id'])) !== null) {
            continue;
        }
        $total += (int)$sched['required_min'];
    }
    return round($total / 60, 2);
}

/**
 * Monthly-salaried employees: leaves are deducted from the gross (perfect attendance)
 * hours work. hours_work = perfect hours - leave hours, never below zero. Returns the
 * adjusted values and whether anything changed.
 */
function pt_apply_leave_rule(array $values, array $emp, array $period, ?float $perfect = null): array
{
    if (($emp['rate_type'] ?? 'monthly') !== 'monthly' || !empty($emp['timekeeping_exempt'])) {
        return [$values, false];
    }
    $perfect = $perfect ?? pt_perfect_hours($period, $emp);
    if ($perfect <= 0) {
        return [$values, false];
    }
    $leave = 0.0;
    foreach (pt_leave_fields() as $f) {
        $leave += (float)($values[$f] ?? 0);
    }
    $target = round(max(0, $perfect - $leave), 2);
    $current = round((float)($values['hours_work'] ?? 0), 2);
    // only trim: a smaller figure typed on purpose (part period, unpaid days) is kept
    if ($leave > 0 && $current > $target) {
        $values['hours_work'] = $target;
        return [$values, true];
    }
    return [$values, false];
}

/* ======================================================================= period parameters */

/** Create one pay period (attendance cutoff) with its period number / month / year filled in. */
function pt_period_create(int $payGroupId, string $start, string $end, ?string $payDate = null, ?string $description = null): int
{
    if ($end < $start) {
        throw new RuntimeException('The period end must be on or after the period start.');
    }
    $overlap = val('SELECT id FROM attendance_cutoffs WHERE pay_group_id = ? AND period_start <= ? AND period_end >= ?', [$payGroupId, $end, $start]);
    if ($overlap) {
        throw new RuntimeException('A period of this pay code already overlaps ' . fmt_date($start) . ' – ' . fmt_date($end) . '.');
    }
    $pg = row('SELECT * FROM pay_groups WHERE id = ?', [$payGroupId]);
    if (!$pg) {
        throw new RuntimeException('Pay code not found.');
    }
    $meta = pt_period_meta($start, $end, $pg['frequency']);
    $id = insert('attendance_cutoffs', array_merge($meta, [
        'pay_group_id' => $payGroupId, 'period_start' => $start, 'period_end' => $end,
        'pay_date' => $payDate ?: add_days($end, (int)setting('pay_date_offset', 5)), 'description' => $description,
    ]));
    audit('payroll', 'period_create', 'attendance_cutoffs', $id, ['start' => $start, 'end' => $end]);
    return $id;
}

/**
 * Generate the periods of a year for a pay code (Period Parameters generator).
 * $o: start1, start2 (day of month), first_start (weekly / daily), pay_offset (days), description.
 * Existing / overlapping periods are skipped. Returns [created, skipped].
 */
function pt_generate_periods(int $payGroupId, int $year, array $o = []): array
{
    $pg = row('SELECT * FROM pay_groups WHERE id = ?', [$payGroupId]);
    if (!$pg) {
        throw new RuntimeException('Pay code not found.');
    }
    $start1 = max(1, min(28, (int)($o['start1'] ?? 1)));
    $start2 = max(1, min(28, (int)($o['start2'] ?? 16)));
    $offset = (int)($o['pay_offset'] ?? setting('pay_date_offset', 5));
    $desc = trim((string)($o['description'] ?? ''));
    $bounds = [];
    switch ($pg['frequency']) {
        case 'monthly':
            for ($m = 1; $m <= 13; $m++) {
                $y = $year + ($m > 12 ? 1 : 0);
                $bounds[] = sprintf('%04d-%02d-%02d', $y, $m > 12 ? $m - 12 : $m, $start1);
            }
            break;
        case 'semi_monthly':
            for ($m = 1; $m <= 13; $m++) {
                $y = $year + ($m > 12 ? 1 : 0);
                $mm = $m > 12 ? $m - 12 : $m;
                $bounds[] = sprintf('%04d-%02d-%02d', $y, $mm, min($start1, $start2));
                $bounds[] = sprintf('%04d-%02d-%02d', $y, $mm, max($start1, $start2));
            }
            break;
        case 'weekly':
            $d = $o['first_start'] ?? ($year . '-01-01');
            for ($i = 0; $i < 54; $i++) {
                $bounds[] = add_days($d, $i * 7);
            }
            break;
        default: // daily
            $d = $o['first_start'] ?? ($year . '-01-01');
            $last = $o['last_start'] ?? ($year . '-12-31');
            for ($x = $d; $x <= $last; $x = add_days($x, 1)) {
                $bounds[] = $x;
            }
            $bounds[] = add_days($last, 1);
    }
    sort($bounds);
    $created = 0;
    $skipped = 0;
    for ($i = 0; $i < count($bounds) - 1; $i++) {
        $start = $bounds[$i];
        $end = add_days($bounds[$i + 1], -1);
        if ($pg['frequency'] === 'daily') {
            $end = $start;
        }
        if ((int)substr($end, 0, 4) !== $year) {
            continue;
        }
        try {
            pt_period_create($payGroupId, $start, $end, add_days($end, $offset), $desc ?: null);
            $created++;
        } catch (RuntimeException $e) {
            $skipped++;
        }
    }
    return ['created' => $created, 'skipped' => $skipped];
}

/** Re-open a period so its transactions can be edited again. */
function pt_period_reopen(int $id): void
{
    $c = row('SELECT * FROM attendance_cutoffs WHERE id = ?', [$id]);
    if (!$c) {
        throw new RuntimeException('Period not found.');
    }
    $run = val("SELECT run_no FROM payroll_runs WHERE cutoff_id = ? AND status IN ('computed','finalized')", [$id]);
    if ($run && $c['status'] === 'locked') {
        throw new RuntimeException("Payroll run $run was already processed for this period. Cancel or recompute that run first (Payroll Runs).");
    }
    update('attendance_cutoffs', ['status' => (int)val('SELECT COUNT(*) FROM attendance_summaries WHERE cutoff_id = ?', [$id]) ? 'summarized' : 'open',
        'approved_by' => null, 'approved_at' => null], 'id = ?', [$id]);
    // re-opening the period re-opens its attendance transactions too
    q('UPDATE attendance_summaries SET is_closed = 0, closed_at = NULL, closed_by = NULL WHERE cutoff_id = ?', [$id]);
    audit('payroll', 'period_reopen', 'attendance_cutoffs', $id);
}
