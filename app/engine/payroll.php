<?php
/**
 * Payroll engine.
 *
 * Pay is computed from the hours-based attendance / payroll TRANSACTION of each employee for the period
 * (generated from the AMS or encoded manually), the salary structure from the PIS (rates via the
 * employee-type conversion factor), the Comp. Rates table, the statutory tables, the pay-code deduction
 * modes, benefits, fixed transactions, employee transactions, loans and deductions (bound by the Labor
 * Code wage-protection limits).
 *
 * Multi-branch / multi-location: earnings are attributed per branch worked (per-branch payslips + cost
 * allocation) while statutory deductions are computed ONCE, against the employee's default employer.
 * Every payslip line carries the payroll-register column it belongs to (reg_col).
 */

const RUN_TYPES = ['regular' => 'Regular Payroll', 'thirteenth' => '13th Month Pay', 'special' => 'Special / Bonus Run', 'final_pay' => 'Final Pay'];

/** Line code of each premium / overtime / night hour field. */
const PT_LINE_CODE = [
    'ot_reg' => 'OT_REG', 'rd_hrs' => 'RD', 'rd_ot' => 'RD_OT', 'lh_hrs' => 'LH', 'lh_ot' => 'LH_OT', 'lh_rd' => 'LH_RD', 'lh_rd_ot' => 'LH_RD_OT',
    'sh_hrs' => 'SH', 'sh_ot' => 'SH_OT', 'sh_rd' => 'SH_RD', 'sh_rd_ot' => 'SH_RD_OT',
    'np_reg' => 'NP_REG', 'np_reg_ot' => 'NP_REG_OT', 'np_rd' => 'NP_RD', 'np_rd_ot' => 'NP_RD_OT', 'np_lh' => 'NP_LH', 'np_lh_ot' => 'NP_LH_OT',
    'np_lh_rd' => 'NP_LH_RD', 'np_lh_rd_ot' => 'NP_LH_RD_OT', 'np_sh' => 'NP_SH', 'np_sh_ot' => 'NP_SH_OT', 'np_sh_rd' => 'NP_SH_RD', 'np_sh_rd_ot' => 'NP_SH_RD_OT',
];
/** Overtime hour fields (credited as offset leave for OT/OL jobs). */
const PT_OT_FIELDS = ['ot_reg', 'rd_ot', 'lh_ot', 'lh_rd_ot', 'sh_ot', 'sh_rd_ot'];

/** Payroll register columns (M.A.D.A.S. Payroll Batch Register). */
const REG_EARN_COLS = ['basic' => 'Basic', 'vl' => 'VL', 'sl' => 'SL', 'elol' => 'EL+OL', 'overtime' => 'Overtime', 'np' => 'Night Prem', 'legal' => 'Legal',
    'special' => 'Special', 'incadj' => 'Inc. Adj.', '13th' => '13th Pay', 'others' => 'Others', 'absent' => 'Less: Absent', 'lateut' => 'Less: Late+Under'];
const REG_DED_COLS = ['sss' => 'SSS Contrib', 'phic' => 'PHIC', 'hdmf' => 'HDMF', 'sss_loan' => 'SSS Loans', 'hdmf_loan' => 'HDMF Loans', 'wtax' => 'Withholding Tax',
    'company_loan' => 'Adv./AR/Sal Loans', 'others' => 'Others'];

function payroll_run(int $id): ?array
{
    return row('SELECT r.*, pg.name AS pay_group_name, pg.frequency, pg.contrib_mode, pg.code AS pay_group_code
                FROM payroll_runs r JOIN pay_groups pg ON pg.id = r.pay_group_id WHERE r.id = ?', [$id]);
}

/** Is this run the first / last pay period of its month (for statutory deduction timing)? */
function run_period_flags(array $run, string $freq): array
{
    $end = $run['period_end'];
    $day = (int)date('j', strtotime($end));
    switch ($freq) {
        case 'monthly':
            return ['first' => true, 'last' => true];
        case 'semi_monthly':
            return ['first' => $day <= 15, 'last' => $day > 15];
        case 'weekly':
            return ['first' => $day <= 7, 'last' => date('m', strtotime($end . ' +7 days')) !== date('m', strtotime($end))];
        default:
            return ['first' => $day === 1, 'last' => date('m', strtotime($end . ' +1 day')) !== date('m', strtotime($end))];
    }
}

function frequency_applies(string $frequency, array $flags): bool
{
    switch ($frequency) {
        case 'first_cutoff':
            return $flags['first'];
        case 'second_cutoff':
        case 'monthly_last':
            return $flags['last'];
        default:
            return true;
    }
}

/** Suggest the next pay period for a pay group (the next defined period when periods exist). */
function payroll_suggest_period(int $payGroupId, ?string $after = null): array
{
    $pg = row('SELECT * FROM pay_groups WHERE id = ?', [$payGroupId]);
    if ($after === null) {
        $after = val("SELECT MAX(period_end) FROM payroll_runs WHERE pay_group_id = ? AND run_type = 'regular' AND status <> 'cancelled'", [$payGroupId]);
    }
    $next = row('SELECT * FROM attendance_cutoffs WHERE pay_group_id = ? AND period_start > ? ORDER BY period_start LIMIT 1', [$payGroupId, $after ?: '1900-01-01']);
    if ($next) {
        return ['start' => $next['period_start'], 'end' => $next['period_end'], 'pay_date' => $next['pay_date'] ?: add_days($next['period_end'], 5)];
    }
    $start = $after ? add_days($after, 1) : date('Y-m-01');
    switch ($pg['frequency']) {
        case 'monthly':
            $start = date('Y-m-01', strtotime($start));
            $end = date('Y-m-t', strtotime($start));
            break;
        case 'weekly':
            $end = add_days($start, 6);
            break;
        case 'daily':
            $end = $start;
            break;
        default:
            $d = (int)date('j', strtotime($start));
            if ($d <= 15) {
                $start = date('Y-m-01', strtotime($start));
                $end = date('Y-m-15', strtotime($start));
            } else {
                $start = date('Y-m-16', strtotime($start));
                $end = date('Y-m-t', strtotime($start));
            }
    }
    return ['start' => $start, 'end' => $end, 'pay_date' => add_days($end, $pg['frequency'] === 'daily' ? 0 : 5)];
}

function payroll_create_run(int $payGroupId, string $type, string $start, string $end, string $payDate, ?string $notes = null): int
{
    if ($end < $start) {
        throw new RuntimeException('Period end must be on or after the period start.');
    }
    if ($type === 'regular') {
        $dup = val("SELECT run_no FROM payroll_runs WHERE pay_group_id = ? AND run_type = 'regular' AND status <> 'cancelled' AND period_start <= ? AND period_end >= ?",
            [$payGroupId, $end, $start]);
        if ($dup) {
            throw new RuntimeException("An overlapping regular payroll run already exists ($dup).");
        }
    }
    $cutoff = val('SELECT id FROM attendance_cutoffs WHERE pay_group_id = ? AND period_start = ? AND period_end = ?', [$payGroupId, $start, $end]);
    $id = insert('payroll_runs', [
        'run_no' => next_number('PR', 'payroll_runs', 'run_no'), 'pay_group_id' => $payGroupId, 'run_type' => $type,
        'period_start' => $start, 'period_end' => $end, 'pay_date' => $payDate, 'cutoff_id' => $cutoff ?: null,
        'notes' => $notes, 'created_by' => user_id(),
    ]);
    audit('payroll', 'run_create', 'payroll_runs', $id, compact('payGroupId', 'type', 'start', 'end', 'payDate'));
    return $id;
}

/** Sums of finalized payroll items for an employee in a calendar year. */
function payroll_ytd(int $empId, int $year, ?int $excludeRunId = null): array
{
    $r = row("SELECT COALESCE(SUM(pi.gross_pay),0) gross, COALESCE(SUM(pi.basic_earned),0) basic_earned, COALESCE(SUM(pi.benefits_other),0) other_nontax,
                     COALESCE(SUM(pi.benefits_nontax),0) nontax, COALESCE(SUM(pi.taxable_income),0) taxable, COALESCE(SUM(pi.withholding_tax),0) tax,
                     COALESCE(SUM(pi.sss_ee + pi.phic_ee + pi.hdmf_ee),0) contrib, COALESCE(SUM(pi.net_pay),0) net
              FROM payroll_items pi JOIN payroll_runs pr ON pr.id = pi.run_id
              WHERE pi.employee_id = ? AND pr.status = 'finalized' AND YEAR(pr.period_end) = ?" . ($excludeRunId ? ' AND pr.id <> ' . (int)$excludeRunId : ''),
        [$empId, $year]);
    $r['thirteenth_paid'] = (float)val("SELECT COALESCE(SUM(l.amount),0) FROM payroll_item_lines l JOIN payroll_items pi ON pi.id = l.item_id
                                         JOIN payroll_runs pr ON pr.id = pi.run_id
                                         WHERE pi.employee_id = ? AND pr.status = 'finalized' AND YEAR(pr.period_end) = ? AND l.code = '13TH'"
        . ($excludeRunId ? ' AND pr.id <> ' . (int)$excludeRunId : ''), [$empId, $year]);
    return array_map('floatval', $r);
}

/* ===================================================================== 13th month basis (v17) */

const THIRTEENTH_BASES = ['calendar' => 'Calendar Year (January to December of the current year)', 'fiscal' => 'Fiscal Year (From date to To date, exactly one year)'];

/**
 * Coverage of the 13th month pay of a year: [from, to, basis, label].
 *   Calendar Year  January 1 to December 31 of the year.
 *   Fiscal Year    the From / To dates of the Payroll Parameters (exactly one year, e.g. Dec 1 of the previous
 *                  year to Nov 30 of the current year), moved to the year asked: the To date falls in that year.
 */
function thirteenth_period(int $year): array
{
    $basis = (string)setting('thirteenth_basis', 'calendar');
    $from = (string)setting('thirteenth_fy_from', '');
    $to = (string)setting('thirteenth_fy_to', '');
    if ($basis === 'fiscal' && thirteenth_fy_valid($from, $to) === null) {
        $shift = $year - (int)substr($to, 0, 4);
        $f = date('Y-m-d', strtotime("$from $shift year"));
        $t = date('Y-m-d', strtotime("$f +1 year -1 day"));
        return [$f, $t, 'fiscal', 'Fiscal Year ' . fmt_date($f) . ' to ' . fmt_date($t)];
    }
    return ["$year-01-01", "$year-12-31", 'calendar', 'Calendar Year ' . $year];
}

/** Error of a Fiscal Year coverage (null when valid): exactly one year, the To date being one year after the From date less a day. */
function thirteenth_fy_valid(string $from, string $to): ?string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        return 'Enter the From date and the To date of the fiscal year.';
    }
    $expect = date('Y-m-d', strtotime("$from +1 year -1 day"));
    if ($to < $expect) {
        return 'The fiscal year is shorter than one year (' . (days_between($from, $to) + 1) . ' days): below one year is not accepted. From ' . fmt_date($from) . ' the To date is ' . fmt_date($expect) . '.';
    }
    if ($to > $expect) {
        return 'The fiscal year must be exactly one year (365 days, 366 with February 29): from ' . fmt_date($from) . ' the To date is ' . fmt_date($expect) . '.';
    }
    return null;
}

/**
 * Basic salary earned by an employee in the 13th month coverage (finalized payroll runs whose period ends
 * in it) and the 13th month already paid for that coverage.
 */
function thirteenth_totals(int $empId, int $year, ?int $excludeRunId = null): array
{
    [$f, $t] = thirteenth_period($year);
    // the 13th month paid in the year (January to December, or to the end of the coverage) belongs to the coverage of that year
    $paidFrom = "$year-01-01";
    $paidTo = max($t, "$year-12-31");
    $ex = $excludeRunId ? ' AND pr.id <> ' . (int)$excludeRunId : '';
    $basic = (float)val("SELECT COALESCE(SUM(pi.basic_earned),0) FROM payroll_items pi JOIN payroll_runs pr ON pr.id = pi.run_id
                         WHERE pi.employee_id = ? AND pr.status = 'finalized' AND pr.period_end BETWEEN ? AND ?$ex", [$empId, $f, $t]);
    $paid = (float)val("SELECT COALESCE(SUM(l.amount),0) FROM payroll_item_lines l JOIN payroll_items pi ON pi.id = l.item_id JOIN payroll_runs pr ON pr.id = pi.run_id
                        WHERE pi.employee_id = ? AND pr.status = 'finalized' AND l.code = '13TH' AND pr.period_end BETWEEN ? AND ?$ex", [$empId, $paidFrom, $paidTo]);
    return ['from' => $f, 'to' => $t, 'basic_earned' => $basic, 'thirteenth_paid' => $paid];
}

/** YTD Information for Previous Employer of an employee for a year (all zero when none was encoded). */
function prev_employer_ytd(int $empId, int $year): array
{
    $keys = ['gross', 'basic', 'sss', 'hdmf', 'phic', 'other_benefits', 'bonus', 'thirteenth', 'deminimis', 'leave_pay', 'holidays', 'nontax', 'absences', 'np', 'taxable', 'tax'];
    $r = null;
    try {
        $r = row('SELECT * FROM employee_prev_ytd WHERE employee_id = ? AND year = ?', [$empId, $year]);
    } catch (PDOException $e) {
        $r = null;   // before the version 9 upgrade
    }
    $out = ['employer_name' => $r['employer_name'] ?? null, 'employer_tin' => $r['employer_tin'] ?? null, 'exists' => (bool)$r];
    foreach ($keys as $k) {
        $out[$k] = (float)($r[$k] ?? 0);
    }
    return $out;
}

/** Amount of a payroll-item field already recorded for an employee in the month of $date (other computed / finalized runs). */
function month_statutory_deducted(int $empId, string $date, string $field, int $excludeRunId): float
{
    return (float)val("SELECT COALESCE(SUM(pi.$field),0) FROM payroll_items pi JOIN payroll_runs pr ON pr.id = pi.run_id
                       WHERE pi.employee_id = ? AND pr.status IN ('finalized','computed') AND pr.id <> ?
                         AND YEAR(pr.period_end) = ? AND MONTH(pr.period_end) = ?",
        [$empId, $excludeRunId, (int)substr($date, 0, 4), (int)substr($date, 5, 2)]);
}

/** Sum of payslip lines with a code already paid / deducted in the month of $date (other runs). */
function month_line_amount(int $empId, string $date, string $code, int $excludeRunId): float
{
    return (float)val("SELECT COALESCE(SUM(l.amount),0) FROM payroll_item_lines l JOIN payroll_items pi ON pi.id = l.item_id JOIN payroll_runs pr ON pr.id = pi.run_id
                       WHERE pi.employee_id = ? AND pr.status IN ('finalized','computed') AND pr.id <> ? AND l.code = ?
                         AND YEAR(pr.period_end) = ? AND MONTH(pr.period_end) = ?",
        [$empId, $excludeRunId, $code, (int)substr($date, 0, 4), (int)substr($date, 5, 2)]);
}

/** Employees to include in a run. */
function payroll_run_employees(array $run): array
{
    if ($run['run_type'] === 'final_pay') {
        $fp = row('SELECT employee_id FROM final_pays WHERE payroll_run_id = ?', [$run['id']]);
        return $fp ? rows('SELECT * FROM employees WHERE id = ?', [$fp['employee_id']]) : [];
    }
    $excludeFinal = " AND id NOT IN (SELECT fp.employee_id FROM final_pays fp WHERE fp.status = 'released')";
    if ($run['run_type'] === 'thirteenth') {
        $y = (int)substr($run['period_end'], 0, 4);
        return rows("SELECT * FROM employees WHERE pay_group_id = ? AND date_hired <= ? AND (date_separated IS NULL OR YEAR(date_separated) >= ?)$excludeFinal ORDER BY last_name, first_name",
            [$run['pay_group_id'], $run['period_end'], $y]);
    }
    if ($run['run_type'] === 'special') {
        return rows("SELECT * FROM employees WHERE pay_group_id = ? AND status = 'active' ORDER BY last_name, first_name", [$run['pay_group_id']]);
    }
    return rows("SELECT * FROM employees WHERE pay_group_id = ? AND date_hired <= ? AND (date_separated IS NULL OR date_separated >= ?)$excludeFinal ORDER BY last_name, first_name",
        [$run['pay_group_id'], $run['period_end'], $run['period_start']]);
}

/** Tax tables that can be chosen on the Payroll Computation form. */
const CALC_TAX_TABLES = ['daily' => 'Daily', 'weekly' => 'Weekly', 'semi_monthly' => 'Semi-Monthly', 'monthly' => 'Monthly', 'annual' => 'Annually'];

/** The Payroll Computation parameters saved on a run (defaults for runs computed before version 7). */
function payroll_calc_options(array $run): array
{
    return [
        'branch_id' => $run['calc_branch_id'] ?? null ? (int)$run['calc_branch_id'] : null,
        'employee_group_id' => $run['calc_group_id'] ?? null ? (int)$run['calc_group_id'] : null,
        'employee_type_id' => $run['calc_type_id'] ?? null ? (int)$run['calc_type_id'] : null,
        'tax_table' => isset(CALC_TAX_TABLES[$run['calc_tax_table'] ?? '']) ? $run['calc_tax_table'] : null,
        'annualize' => !empty($run['calc_annualize']),
        'regular' => !array_key_exists('calc_regular', $run) || (int)$run['calc_regular'] === 1,
        'thirteenth' => !empty($run['calc_13th']),
        'selection' => !empty($run['calc_selection']),
        'emp_from' => (string)($run['calc_emp_from'] ?? ''),
        'emp_to' => (string)($run['calc_emp_to'] ?? ''),
        // v14: reprocess only some employees, companies / branches or departments (one, two or more of each)
        'emp_ids' => payroll_id_list($run['calc_emp_ids'] ?? ''),
        'branch_ids' => payroll_id_list($run['calc_branch_ids'] ?? '') ?: (!empty($run['calc_branch_id']) ? [(int)$run['calc_branch_id']] : []),
        'unit_ids' => payroll_id_list($run['calc_unit_ids'] ?? ''),
    ];
}

/** "3,7,9" (or an array) => [3, 7, 9]. */
function payroll_id_list($v): array
{
    $v = is_array($v) ? $v : explode(',', (string)$v);
    return array_values(array_unique(array_filter(array_map('intval', $v), fn($x) => $x > 0)));
}

/**
 * Compute (or re-compute) the items of a payroll run (Payroll Computation).
 * $opt (from the Payroll Computation form) is saved on the run: Company / Branch, Employee Group and
 * Employee Type filters, tax table to use, annualization, regular processing (all incomes and all
 * deductions; off = incomes only), 13th month, and Batch / Selection (employee no. from - to).
 * With a filter or a selection only those employees are (re)computed; the others keep their figures.
 * Without $opt the saved processing options are reused and every employee is computed.
 */
function payroll_compute(int $runId, ?array $opt = null): array
{
    $run = payroll_run($runId);
    if (!$run || !in_array($run['status'], ['draft', 'computed'], true)) {
        throw new RuntimeException('Only draft or computed runs can be (re)computed.');
    }
    // the previous payroll of the pay code must be posted (Period Closing) first: its loan payments,
    // installments and deductions decide what this payroll takes
    if (in_array($run['run_type'], ['regular', 'special', 'thirteenth'], true)) {
        $prev = row("SELECT run_no, period_start, period_end FROM payroll_runs WHERE pay_group_id = ? AND status = 'finalized' AND posted_at IS NULL AND id <> ?
                     AND run_type <> 'final_pay' AND period_end < ? ORDER BY period_end LIMIT 1", [$run['pay_group_id'], $runId, $run['period_start']]);
        if ($prev) {
            throw new RuntimeException('Close the period of payroll run ' . $prev['run_no'] . ' (' . fmt_date($prev['period_start']) . ' – ' . fmt_date($prev['period_end'])
                . ') in Period Closing before processing this payroll: its loans, installments and deductions must be posted first.');
        }
    }
    if ($opt !== null) {
        $tax = isset(CALC_TAX_TABLES[$opt['tax_table'] ?? '']) ? $opt['tax_table'] : null;
        $bIds = payroll_id_list($opt['branch_ids'] ?? (!empty($opt['branch_id']) ? [$opt['branch_id']] : []));
        $multi = mig_column_exists('payroll_runs', 'calc_emp_ids') ? [
            'calc_emp_ids' => implode(',', payroll_id_list($opt['emp_ids'] ?? [])) ?: null,
            'calc_branch_ids' => implode(',', $bIds) ?: null,
            'calc_unit_ids' => implode(',', payroll_id_list($opt['unit_ids'] ?? [])) ?: null,
        ] : [];
        update('payroll_runs', $multi + [
            'calc_branch_id' => count($bIds) === 1 ? $bIds[0] : null,
            'calc_group_id' => !empty($opt['employee_group_id']) ? (int)$opt['employee_group_id'] : null,
            'calc_type_id' => !empty($opt['employee_type_id']) ? (int)$opt['employee_type_id'] : null,
            'calc_tax_table' => $tax, 'calc_annualize' => !empty($opt['annualize']) ? 1 : 0, 'calc_regular' => array_key_exists('regular', $opt) && !$opt['regular'] ? 0 : 1,
            'calc_13th' => !empty($opt['thirteenth']) ? 1 : 0, 'calc_selection' => !empty($opt['selection']) ? 1 : 0,
            'calc_emp_from' => !empty($opt['selection']) ? (trim((string)($opt['emp_from'] ?? '')) ?: null) : null,
            'calc_emp_to' => !empty($opt['selection']) ? (trim((string)($opt['emp_to'] ?? '')) ?: null) : null,
        ], 'id = ?', [$runId]);
        $run = payroll_run($runId);
    }
    $calc = payroll_calc_options($run);
    if ($opt === null) {
        // reprocess: every employee, with the saved processing options
        $calc['branch_id'] = $calc['employee_group_id'] = $calc['employee_type_id'] = null;
        $calc['selection'] = false;
        $calc['emp_ids'] = $calc['branch_ids'] = $calc['unit_ids'] = [];
    }
    $pg = row('SELECT * FROM pay_groups WHERE id = ?', [$run['pay_group_id']]);
    $flags = run_period_flags($run, $pg['frequency']);
    $ctx = ['flags' => $flags, 'summaries' => [], 'cutoff' => null, 'live' => false, 'exceptions' => [], 'has_transactions' => false, 'opt' => $calc];

    if ($run['run_type'] === 'regular') {
        $cut = row('SELECT * FROM attendance_cutoffs WHERE pay_group_id = ? AND period_start = ? AND period_end = ?', [$run['pay_group_id'], $run['period_start'], $run['period_end']]);
        if ($cut && (int)$run['cutoff_id'] !== (int)$cut['id']) {
            update('payroll_runs', ['cutoff_id' => $cut['id']], 'id = ?', [$runId]);
        }
        $ctx['cutoff'] = $cut;
        if ($cut) {
            foreach (rows('SELECT * FROM attendance_summaries WHERE cutoff_id = ?', [$cut['id']]) as $s) {
                $ctx['summaries'][(int)$s['employee_id']] = ['header' => $s, 'branches' => pt_branches((int)$s['id'])];
            }
            $ctx['has_transactions'] = (bool)$ctx['summaries'];
        }
        if (!$cut || (!in_array($cut['status'], ['approved', 'locked'], true) && !$ctx['has_transactions'])) {
            $ctx['exceptions'][] = ['employee_id' => null, 'exception_type' => 'cutoff_not_approved', 'blocking' => 1,
                'message' => 'The attendance / payroll transactions of this period are ' . ($cut ? 'still ' . $cut['status'] : 'not yet created')
                    . '. Figures use live DTR data. Encode or process the attendance and close it (Attendance › Attendance Transaction Closing), then recompute.'];
            $ctx['live'] = true;
        }
    }
    $emps = payroll_run_employees($run);
    $partial = false;
    // Employee / ID, Company / Branch and Department filters (any number of each; together they narrow each other)
    if ($calc['emp_ids'] || $calc['branch_ids'] || $calc['unit_ids']) {
        $units = [];
        foreach ($calc['unit_ids'] as $u) {
            $units = array_merge($units, org_descendant_ids($u));
        }
        $units = array_map('intval', $units);
        $emps = array_values(array_filter($emps, fn($e) => (!$calc['emp_ids'] || in_array((int)$e['id'], $calc['emp_ids'], true))
            && (!$calc['branch_ids'] || in_array((int)$e['default_branch_id'], $calc['branch_ids'], true))
            && (!$calc['unit_ids'] || in_array((int)$e['org_unit_id'], $units, true))));
        $partial = true;
        $calc['branch_id'] = null;   // already applied by the list above
    }
    if ($calc['branch_id'] || $calc['employee_group_id'] || $calc['employee_type_id']) {
        $emps = array_values(array_filter($emps, fn($e) => (!$calc['branch_id'] || (int)$e['default_branch_id'] === $calc['branch_id'])
            && (!$calc['employee_group_id'] || (int)$e['employee_group_id'] === $calc['employee_group_id'])
            && (!$calc['employee_type_id'] || (int)$e['employee_type_id'] === $calc['employee_type_id'])));
        $partial = true;
    }
    if ($calc['selection'] && ($calc['emp_from'] !== '' || $calc['emp_to'] !== '')) {
        $from = strtoupper($calc['emp_from']);
        $to = strtoupper($calc['emp_to'] !== '' ? $calc['emp_to'] : $calc['emp_from']);
        if ($from === '') {
            $from = $to;
        }
        if (strcmp($from, $to) > 0) {
            [$from, $to] = [$to, $from];
        }
        $emps = array_values(array_filter($emps, fn($e) => strcmp(strtoupper($e['employee_no']), $from) >= 0 && strcmp(strtoupper($e['employee_no']), $to) <= 0));
        $partial = true;
    }
    if ($partial && !$emps) {
        throw new RuntimeException('No employee of this pay code matches the Payroll Computation filters.');
    }
    $stats = ['employees' => 0, 'gross' => 0.0, 'net' => 0.0, 'partial' => $partial];
    tx(function () use ($runId, $run, $pg, $emps, $partial, &$ctx, &$stats) {
        if ($partial) {
            $ids = implode(',', array_map(fn($e) => (int)$e['id'], $emps));
            q("DELETE FROM payroll_items WHERE run_id = ? AND employee_id IN ($ids)", [$runId]);
            q("DELETE FROM payroll_exceptions WHERE run_id = ? AND (employee_id IN ($ids) OR employee_id IS NULL)", [$runId]);
        } else {
            q('DELETE FROM payroll_items WHERE run_id = ?', [$runId]);
            q('DELETE FROM payroll_exceptions WHERE run_id = ?', [$runId]);
        }
        foreach ($emps as $emp) {
            $res = payroll_build_item($run, $pg, $emp, $ctx);
            if ($res === null) {
                continue;
            }
            payroll_save_item($runId, $res);
            $stats['employees']++;
            $stats['gross'] += $res['item']['gross_pay'];
            $stats['net'] += $res['item']['net_pay'];
        }
        foreach ($ctx['exceptions'] as $ex) {
            insert('payroll_exceptions', array_merge(['run_id' => $runId], $ex));
        }
        update('payroll_runs', ['status' => 'computed', 'computed_at' => now()], 'id = ?', [$runId]);
    });
    audit('payroll', 'run_compute', 'payroll_runs', $runId, $stats + ['options' => array_filter($calc)]);
    return $stats;
}

/** Amount of a recurring / fixed item due in this pay according to its terms. */
function payroll_terms_amount(string $terms, float $amount, array $flags, float $ppm, callable $alreadyThisMonth, bool $isFinal = false): float
{
    switch ($terms) {
        case 'monthly_split':
            if ($isFinal || $flags['last']) {
                return max(0, $amount - $alreadyThisMonth());
            }
            return $amount / $ppm;
        case 'first_pay':
            return $flags['first'] || $isFinal ? max(0, $amount - $alreadyThisMonth()) : 0;
        case 'second_pay':
            return $flags['last'] || $isFinal ? max(0, $amount - $alreadyThisMonth()) : 0;
        default:
            return $amount;
    }
}

/**
 * Build one employee's payroll item. Returns null when there is nothing to pay.
 */
function payroll_build_item(array $run, array $pg, array $emp, array &$ctx): ?array
{
    $empId = (int)$emp['id'];
    $freq = $pg['frequency'];
    $flags = $ctx['flags'];
    $type = $run['run_type'];
    $isFinal = $type === 'final_pay';
    $rates = employee_rates($emp);
    $H = (float)$rates['hourly'];
    $hpd = (float)$rates['hpd'];
    $ppm = periods_per_month($freq, (int)round($rates['factor'] * 12));
    $defaultBranch = (int)$emp['default_branch_id'];
    // Set Deduction Source (multi-location): the branch / division / segment that carries the earnings not
    // tied to worked hours and every deduction of the pay. The default employer unless the transaction says otherwise.
    $srcBranch = $defaultBranch;
    $taxExempt = employee_tax_exempt($emp);
    $year = (int)substr($run['period_end'], 0, 4);
    $PR = pay_rates();
    $ex = function (string $t, string $msg, int $blocking = 1) use (&$ctx, $empId) {
        $ctx['exceptions'][] = ['employee_id' => $empId, 'exception_type' => $t, 'message' => $msg, 'blocking' => $blocking];
    };

    $lines = [];
    $sort = 0;
    // $class: comp (regular compensation), taxable, nontax, other (90K pool), special (special earnings ceiling)
    $earn = function (string $code, string $desc, float $amount, string $class, ?int $branch = null, $qty = null, ?string $refType = null, ?int $refId = null, ?string $reg = 'others') use (&$lines, &$sort) {
        $amount = round($amount, 2);
        if (abs($amount) < 0.005) {
            return;
        }
        $lines[] = ['line_type' => 'earning', 'code' => $code, 'description' => $desc, 'amount' => $amount, 'branch_id' => $branch, 'quantity' => $qty,
            'taxable' => ['comp' => 1, 'taxable' => 1, 'nontax' => 0, 'other' => 2, 'special' => 3][$class], 'class' => $class, 'ref_type' => $refType, 'ref_id' => $refId,
            'sort_order' => $sort++, 'reg_col' => $reg];
    };
    $ded = function (string $code, string $desc, float $amount, ?string $refType = null, ?int $refId = null, $qty = null, string $reg = 'others') use (&$lines, &$sort, &$srcBranch, &$defaultBranch) {
        $amount = round($amount, 2);
        if ($amount < 0.005) {
            return;
        }
        $lines[] = ['line_type' => 'deduction', 'code' => $code, 'description' => $desc, 'amount' => $amount, 'branch_id' => $srcBranch !== $defaultBranch ? $srcBranch : null, 'quantity' => $qty,
            'taxable' => 0, 'ref_type' => $refType, 'ref_id' => $refId, 'sort_order' => $sort++, 'reg_col' => $reg];
    };
    $info = function (string $code, string $desc, float $amount, string $lineType = 'info', $qty = null) use (&$lines, &$sort) {
        $lines[] = ['line_type' => $lineType, 'code' => $code, 'description' => $desc, 'amount' => round($amount, 2), 'branch_id' => null, 'quantity' => $qty,
            'taxable' => 0, 'ref_type' => null, 'ref_id' => null, 'sort_order' => $sort++, 'reg_col' => $lineType === 'employer' ? 'er' : null];
    };

    $item = [
        'employee_id' => $empId, 'default_branch_id' => $defaultBranch, 'org_unit_id' => $emp['org_unit_id'], 'rate_type' => $emp['rate_type'],
        'monthly_rate' => $rates['monthly'], 'daily_rate' => $rates['daily'], 'hourly_rate' => $rates['hourly'], 'is_mwe' => $taxExempt ? 1 : 0,
        'days_worked' => 0, 'absent_days' => 0, 'leave_paid_days' => 0, 'leave_unpaid_days' => 0, 'late_minutes' => 0, 'undertime_minutes' => 0, 'ot_hours' => 0,
        'basic_pay' => 0, 'absence_deduction' => 0, 'late_deduction' => 0, 'undertime_deduction' => 0, 'holiday_pay' => 0, 'premium_pay' => 0,
        'ot_pay' => 0, 'nd_pay' => 0, 'benefits_nontax' => 0, 'benefits_other' => 0, 'benefits_taxable' => 0, 'other_earnings' => 0,
        'contrib_basis' => 0, 'sss_ee' => 0, 'sss_er' => 0, 'sss_ec' => 0, 'phic_ee' => 0, 'phic_er' => 0, 'hdmf_ee' => 0, 'hdmf_ee_voluntary' => 0, 'hdmf_er' => 0,
        'taxable_income' => 0, 'withholding_tax' => 0, 'other_deductions' => 0, 'deferred_deductions' => 0, 'total_deductions' => 0, 'net_pay' => 0, 'basic_earned' => 0,
        'remarks' => null, 'summary_id' => null,
    ];
    $branchAgg = [];
    $B = function (int $b) use (&$branchAgg) {
        if (!isset($branchAgg[$b])) {
            $branchAgg[$b] = ['days_worked' => 0, 'reg_hours' => 0, 'ot_hours' => 0, 'basic_alloc' => 0, 'premium_pay' => 0, 'ot_pay' => 0, 'nd_pay' => 0,
                'other_earnings' => 0, 'late_ut_deduction' => 0];
        }
        return $b;
    };
    $B($defaultBranch);
    $srcId = (int)($ctx['summaries'][$empId]['header']['deduction_branch_id'] ?? 0);
    if ($srcId && $srcId !== $defaultBranch && in_array($type, ['regular', 'final_pay'], true) && val('SELECT COUNT(*) FROM branches WHERE id = ?', [$srcId])) {
        $srcBranch = $srcId;
        $B($srcBranch);
    }
    $item['deduction_branch_id'] = $srcBranch !== $defaultBranch ? $srcBranch : null;
    $T = null;         // hours transaction
    $TB = [];          // per-branch hours
    $otOffsetHours = 0.0;
    $pendingDed = [];  // deductions applied after tax (wage-protection bound)
    $fixedStat = [];   // fixed statutory amounts replacing the computed ones
    $extraTax = [];    // separate withholding on specific earnings

    /* ------------------------------------------------ 1. Basic pay, attendance, premiums (hours transaction) */
    if (in_array($type, ['regular', 'final_pay'], true)) {
        $partial = $isFinal || $emp['date_hired'] > $run['period_start'] || ($emp['date_separated'] && $emp['date_separated'] < $run['period_end']);
        $useDaily = $emp['rate_type'] === 'daily' || $partial || $freq === 'daily';
        $exempt = (bool)$emp['timekeeping_exempt'];
        $salaryFrom = max($run['period_start'], $emp['date_hired']);
        $salaryTo = $emp['date_separated'] ? min($run['period_end'], $emp['date_separated']) : $run['period_end'];
        if ($isFinal) {
            $lastPaid = val("SELECT MAX(pr.period_end) FROM payroll_items pi JOIN payroll_runs pr ON pr.id = pi.run_id
                             WHERE pi.employee_id = ? AND pr.status = 'finalized' AND pr.run_type = 'regular'", [$empId]);
            if ($lastPaid) {
                $salaryFrom = max($salaryFrom, add_days($lastPaid, 1));
            }
        }
        if (!$exempt && $salaryFrom <= $salaryTo) {
            $s = $ctx['summaries'][$empId] ?? null;
            if ($s && !$isFinal) {
                $T = $s['header'];
                $TB = $s['branches'];
                $item['summary_id'] = (int)$T['id'];
                if (empty($T['is_closed'])) {
                    // only closed attendance transactions are processed for payment
                    $ex('attendance_not_closed', 'The attendance transaction of this employee is not closed yet (figures use the transaction as encoded). Close it in Attendance › Attendance Transaction Closing, then recompute.', 1);
                }
            } elseif ($isFinal || !empty($ctx['live'])) {
                process_dtr_range([$empId], $salaryFrom, $salaryTo);
                $sd = attendance_summary_data($emp, $salaryFrom, $salaryTo);
                $T = $sd['header'];
                $TB = count($sd['branches']) > 1 ? $sd['branches'] : [];
            } elseif (!$useDaily) {
                $T = pt_blank();
                $ex('no_transaction', 'No attendance / payroll transaction for this period — paid the full period salary with no absences.', 0);
            } else {
                $T = pt_blank();
                $ex('no_transaction', 'No attendance / payroll transaction (hours work) was encoded for this daily-paid employee. Encode it in Payroll › Payroll Transactions, then recompute.', 1);
            }
            if ($T && (int)($T['unauthorized_ot_minutes'] ?? 0) > 0) {
                if (!empty($ctx['live'])) {
                    $ex('unauthorized_ot', sprintf('%s of logged overtime has no approved OT authorization. Approve the OT (the DTR is re-processed) or resolve the DTR exception to confirm non-payment.', fmt_minutes($T['unauthorized_ot_minutes'])), 1);
                } else {
                    $ex('unauthorized_ot', sprintf('%s of logged overtime without OT authorization was reviewed by HR and is NOT paid.', fmt_minutes($T['unauthorized_ot_minutes'])), 0);
                }
            }
            if ($T && !empty($ctx['live']) && (int)($T['open_exceptions'] ?? 0) > 0) {
                $ex('attendance_exceptions', $T['open_exceptions'] . ' open DTR exception(s) (missing punches, unverified check-ins, etc.).', 1);
            }
        }

        if ($exempt) {
            if ($useDaily) {
                $days = 0;
                foreach ($salaryFrom <= $salaryTo ? date_range($salaryFrom, $salaryTo) : [] as $d) {
                    $sc = employee_schedule($emp, $d);
                    if (!$sc['is_rest']) {
                        $days++;
                    }
                }
                $basic = $rates['daily'] * $days;
                $item['days_worked'] = $days;
            } else {
                $basic = $rates['monthly'] / $ppm;
                $item['days_worked'] = 0;
            }
            $item['basic_pay'] = round($basic, 2);
            $earn('BASIC', 'Basic pay (time-keeping exempt)', $item['basic_pay'], 'comp', $defaultBranch, null, null, null, 'basic');
            $branchAgg[$defaultBranch]['basic_alloc'] += $item['basic_pay'];
        } elseif ($T) {
            $Tv = fn(string $k) => round((float)($T[$k] ?? 0), 2);
            $item['days_worked'] = round(max(0, $Tv('hours_work') - $Tv('absent_hrs')) / $hpd, 2);
            $item['absent_days'] = round($Tv('absent_hrs') / $hpd, 2);
            $item['leave_paid_days'] = round(($Tv('vl_hrs') + $Tv('sl_hrs') + $Tv('el_hrs') + $Tv('ol_hrs') + $Tv('pm_hrs')) / $hpd, 2);
            $item['late_minutes'] = (int)round($Tv('late_hrs') * 60);
            $item['undertime_minutes'] = (int)round($Tv('undertime_hrs') * 60);
            // rows used for hour-based lines: per branch (multi-location) or everything at the default branch
            $rowsB = $TB ?: [$defaultBranch => $T];
            foreach (array_keys($rowsB) as $b) {
                $B((int)$b);
                $branchAgg[$b]['reg_hours'] += max(0, (float)($rowsB[$b]['hours_work'] ?? 0) - (float)($rowsB[$b]['absent_hrs'] ?? 0));
                $branchAgg[$b]['days_worked'] += max(0, (float)($rowsB[$b]['hours_work'] ?? 0) - (float)($rowsB[$b]['absent_hrs'] ?? 0)) / $hpd;
            }
            $hrsTxt = fn($h) => rtrim(rtrim(number_format($h, 2), '0'), '.');
            $leaveCols = ['vl' => ['VL', 'Vacation leave', 'vl'], 'sl' => ['SL', 'Sick leave', 'sl'], 'el' => ['EL', 'Emergency leave', 'elol'],
                'ol' => ['OL', 'Other / offset leave', 'elol'], 'pm' => ['PM', 'Maternity / paternity leave', 'elol']];
            $leaveVals = [];
            foreach ($leaveCols as $k => $lc) {
                if ($Tv($k . '_hrs') > 0) {
                    $leaveVals[$k] = round($Tv($k . '_hrs') * $H, 2);
                }
            }
            if (!$useDaily) {
                // monthly-rated: the period salary, split into time worked (Basic) and paid leave (VL / SL / EL+OL)
                $salary = round($rates['monthly'] / $ppm, 2);
                $leaveTotal = array_sum($leaveVals);
                if ($leaveTotal > $salary && $leaveTotal > 0) {
                    foreach ($leaveVals as $k => $v) {
                        $leaveVals[$k] = round($v * $salary / $leaveTotal, 2);
                    }
                    $leaveTotal = array_sum($leaveVals);
                }
                $basic = round($salary - $leaveTotal, 2);
                $item['basic_pay'] = $salary;
                $shares = [];
                foreach ($rowsB as $b => $rb) {
                    $shares[$b] = max(0, (float)($rb['hours_work'] ?? 0) - (float)($rb['absent_hrs'] ?? 0));
                }
                $totShare = array_sum($shares);
                if ($totShare <= 0) {
                    $shares = [$defaultBranch => 1.0];
                    $totShare = 1.0;
                }
                $keys = array_keys($shares);
                $acc = 0.0;
                foreach ($keys as $i => $b) {
                    $amt = $i === count($keys) - 1 ? round($basic - $acc, 2) : round($basic * $shares[$b] / $totShare, 2);
                    $acc += $amt;
                    $B((int)$b);
                    $branchAgg[$b]['basic_alloc'] += $amt;
                    $earn('BASIC', count($keys) > 1 ? 'Basic pay — branch share' : 'Basic pay (' . label($freq) . ')', $amt, 'comp', (int)$b, null, null, null, 'basic');
                }
                foreach ($leaveVals as $k => $v) {
                    $earn($leaveCols[$k][0], sprintf('%s — %s hrs', $leaveCols[$k][1], $hrsTxt($Tv($k . '_hrs'))), $v, 'comp', $srcBranch, $Tv($k . '_hrs'), null, null, $leaveCols[$k][2]);
                    $branchAgg[$srcBranch]['basic_alloc'] += $v;
                }
                $cap = $salary;
            } else {
                // daily-rated (or partial period): paid by the hours work
                $item['basic_pay'] = 0.0;
                foreach ($rowsB as $b => $rb) {
                    $hw = round((float)($rb['hours_work'] ?? 0), 2);
                    if ($hw <= 0) {
                        continue;
                    }
                    $amt = round($hw * $H, 2);
                    $B((int)$b);
                    $branchAgg[$b]['basic_alloc'] += $amt;
                    $item['basic_pay'] += $amt;
                    $earn('BASIC', sprintf('Basic pay — %s hrs @ %s', $hrsTxt($hw), number_format($H, 2)), $amt, 'comp', (int)$b, $hw, null, null, 'basic');
                }
                foreach ($leaveVals as $k => $v) {
                    $earn($leaveCols[$k][0], sprintf('%s — %s hrs', $leaveCols[$k][1], $hrsTxt($Tv($k . '_hrs'))), $v, 'comp', $srcBranch, $Tv($k . '_hrs'), null, null, $leaveCols[$k][2]);
                    $branchAgg[$srcBranch]['basic_alloc'] += $v;
                    $item['basic_pay'] += $v;
                }
                if ($Tv('holiday_hrs') > 0 && ($emp['rate_type'] === 'monthly' || setting('holiday_pay_daily_rated', '1') === '1')) {
                    $item['holiday_pay'] = round($Tv('holiday_hrs') * $H, 2);
                    $earn('HOL', sprintf('Holiday pay (unworked legal holidays) — %s hrs', $hrsTxt($Tv('holiday_hrs'))), $item['holiday_pay'], 'comp', $srcBranch, $Tv('holiday_hrs'), null, null, 'legal');
                    $branchAgg[$srcBranch]['other_earnings'] += $item['holiday_pay'];
                }
                $item['basic_pay'] = round($item['basic_pay'], 2);
                $cap = $item['basic_pay'];
            }
            // absences, lates and undertime (never more than the basic pay)
            $abs = min($cap, round($Tv('absent_hrs') * $H, 2));
            $late = min(max(0, $cap - $abs), round($Tv('late_hrs') * $H, 2));
            $ut = min(max(0, $cap - $abs - $late), round($Tv('undertime_hrs') * $H, 2));
            $item['absence_deduction'] = $abs;
            $item['late_deduction'] = $late;
            $item['undertime_deduction'] = $ut;
            $spread = function (float $total, string $field, string $code, string $desc, string $reg, bool $lateUt) use ($rowsB, $defaultBranch, $earn, &$branchAgg, $B, $Tv) {
                if ($total <= 0) {
                    return;
                }
                $w = [];
                foreach ($rowsB as $b => $rb) {
                    if ((float)($rb[$field] ?? 0) > 0) {
                        $w[$b] = (float)$rb[$field];
                    }
                }
                if (!$w) {
                    $w = [$defaultBranch => 1.0];
                }
                $sum = array_sum($w);
                $keys = array_keys($w);
                $acc = 0.0;
                foreach ($keys as $i => $b) {
                    $amt = $i === count($keys) - 1 ? round($total - $acc, 2) : round($total * $w[$b] / $sum, 2);
                    $acc += $amt;
                    $B((int)$b);
                    if ($lateUt) {
                        $branchAgg[$b]['late_ut_deduction'] += $amt;
                    } else {
                        $branchAgg[$b]['basic_alloc'] -= $amt;
                    }
                    $earn($code, $desc . (count($keys) > 1 ? ' — branch share' : ''), -$amt, 'comp', (int)$b, $Tv($field), null, null, $reg);
                }
            };
            $spread($abs, 'absent_hrs', 'ABSENT', sprintf('Less: absent — %s hrs', $hrsTxt($Tv('absent_hrs'))), 'absent', false);
            $spread($late, 'late_hrs', 'LATE', sprintf('Less: late — %s hrs', $hrsTxt($Tv('late_hrs'))), 'lateut', true);
            $spread($ut, 'undertime_hrs', 'UT', sprintf('Less: undertime — %s hrs', $hrsTxt($Tv('undertime_hrs'))), 'lateut', true);

            // premiums, overtime and night premium per branch (Comp. Rates: hourly rate x hours x rate)
            $otOffset = $emp['job_id'] ? (bool)val('SELECT ot_offset FROM jobs WHERE id = ?', [$emp['job_id']]) : false;
            foreach (PT_RATE_OF as $f => $rc) {
                $isNp = strpos($f, 'np_') === 0;
                foreach ($rowsB as $b => $rb) {
                    $hrs = round((float)($rb[$f] ?? 0), 2);
                    if ($hrs <= 0) {
                        continue;
                    }
                    $pct = $isNp ? $PR[$rc]['night'] : $PR[$rc]['day'];
                    if (!$isNp && !$useDaily && in_array($f, ['lh_hrs', 'sh_hrs'], true)) {
                        $pct -= 100; // the monthly salary already pays the day itself
                    }
                    $isOt = in_array($f, PT_OT_FIELDS, true);
                    if ($isOt && $otOffset) {
                        $otOffsetHours += $hrs;
                        continue;
                    }
                    if ($pct <= 0) {
                        continue;
                    }
                    $amt = round($hrs * $H * $pct / 100, 2);
                    $B((int)$b);
                    $pctTxt = rtrim(rtrim(number_format($pct, 3), '0'), '.');
                    if ($isNp) {
                        $item['nd_pay'] += $amt;
                        $branchAgg[$b]['nd_pay'] += $amt;
                        $earn(PT_LINE_CODE[$f], sprintf('N. Prem. %s — %s hrs × %s%%', PT_SLIP_LABEL[$f], $hrsTxt($hrs), $pctTxt), $amt, 'comp', (int)$b, $hrs, null, null, 'np');
                    } else {
                        $reg = in_array($f, ['lh_hrs', 'lh_rd'], true) ? 'legal' : (in_array($f, ['sh_hrs', 'sh_rd'], true) ? 'special' : 'overtime');
                        if ($isOt || $f === 'rd_hrs') {
                            $item['ot_pay'] += $amt;
                            $branchAgg[$b]['ot_pay'] += $amt;
                        } else {
                            $item['premium_pay'] += $amt;
                            $branchAgg[$b]['premium_pay'] += $amt;
                        }
                        if ($isOt) {
                            $item['ot_hours'] += $hrs;
                            $branchAgg[$b]['ot_hours'] += $hrs;
                        }
                        $earn(PT_LINE_CODE[$f], sprintf('%s %s — %s hrs × %s%%', $f === 'lh_hrs' || $f === 'lh_rd' ? 'Legal holiday' : ($f === 'sh_hrs' || $f === 'sh_rd' ? 'Special holiday' : 'Overtime'),
                            PT_SLIP_LABEL[$f], $hrsTxt($hrs), $pctTxt), $amt, 'comp', (int)$b, $hrs, null, null, $reg);
                    }
                }
            }
            if ($otOffsetHours > 0) {
                $info('OT_OFFSET', sprintf('Overtime credited as offset leave (OT/OL job): %s hrs', $hrsTxt($otOffsetHours)), 0, 'info', round($otOffsetHours, 2));
            }
            // "Others" encoded in the payroll transaction
            if ($type === 'regular') {
                if ($Tv('amt_13th') > 0) {
                    $earn('13TH', '13th month pay', $Tv('amt_13th'), 'other', $srcBranch, null, null, null, '13th');
                }
                if ($Tv('service_charge') > 0) {
                    $earn('SVC', 'Service charge', $Tv('service_charge'), 'taxable', $srcBranch, null, null, null, 'others');
                }
                if ($Tv('sl_conversion') > 0) {
                    $earn('SLCONV', 'Sick leave conversion', $Tv('sl_conversion'), 'taxable', $srcBranch, null, null, null, 'others');
                }
                if ($Tv('vl_conversion') > 0) {
                    $limit = round(setting_num('de_minimis_leave_days', 12) * $rates['daily'], 2);
                    $used = (float)val("SELECT COALESCE(SUM(l.amount),0) FROM payroll_item_lines l JOIN payroll_items pi ON pi.id = l.item_id JOIN payroll_runs pr ON pr.id = pi.run_id
                                        WHERE pi.employee_id = ? AND pr.status = 'finalized' AND YEAR(pr.period_end) = ? AND l.code IN ('VLCONV','LEAVECONV') AND pr.id <> ?", [$empId, $year, $run['id']]);
                    $nt = min($Tv('vl_conversion'), max(0, $limit - $used));
                    $earn('VLCONV', 'Vacation leave conversion (de minimis)', $nt, 'nontax', $srcBranch, null, null, null, 'others');
                    $earn('VLCONV_X', 'Vacation leave conversion — excess over de minimis', $Tv('vl_conversion') - $nt, 'other', $srcBranch, null, null, null, 'others');
                }
                $mc = (float)($T['meal_comp'] ?? 0);
                if ($mc > 0 && setting_num('meal_comp_rate', 0) > 0) {
                    $earn('MEAL', sprintf('Meal compensation — %s × %s', $hrsTxt($mc), number_format(setting_num('meal_comp_rate', 0), 2)), $mc * setting_num('meal_comp_rate', 0), 'taxable', $srcBranch, $mc, null, null, 'others');
                }
                $mco = (float)($T['meal_comp_ot'] ?? 0);
                if ($mco > 0 && setting_num('meal_comp_ot_rate', 0) > 0) {
                    $earn('MEAL_OT', sprintf('Meal compensation OT — %s × %s (de minimis)', $hrsTxt($mco), number_format(setting_num('meal_comp_ot_rate', 0), 2)), $mco * setting_num('meal_comp_ot_rate', 0), 'nontax', $srcBranch, $mco, null, null, 'others');
                }
                $tk = (int)($T['tickets'] ?? 0);
                if ($tk > 0 && setting_num('ticket_rate', 0) > 0) {
                    $pendingDed[] = ['code' => 'TICKETS', 'desc' => sprintf('Tickets — %d × %s', $tk, number_format(setting_num('ticket_rate', 0), 2)), 'amount' => $tk * setting_num('ticket_rate', 0), 'ref_type' => null, 'ref_id' => null, 'reg' => 'others', 'qty' => $tk, 'consent' => true];
                }
                if ($Tv('other_ded') > 0) {
                    $pendingDed[] = ['code' => 'OTHER_DED', 'desc' => 'Other deduction' . (!empty($T['remarks']) ? ' — ' . mb_strimwidth($T['remarks'], 0, 60, '…') : ''), 'amount' => $Tv('other_ded'), 'ref_type' => null, 'ref_id' => null, 'reg' => 'others', 'qty' => null, 'consent' => true];
                }
            }
        }
        $item['ot_hours'] = round($item['ot_hours'], 2);
        $item['basic_earned'] = round($item['basic_pay'] - $item['absence_deduction'] - $item['late_deduction'] - $item['undertime_deduction']
            - (setting('thirteenth_include_leave', '1') === '1' ? 0 : array_sum(array_map(fn($l) => $l['amount'], array_filter($lines, fn($l) => in_array($l['code'], ['VL', 'SL', 'EL', 'OL', 'PM'], true))))), 2);
    }

    /* ------------------------------------------------ 2. Benefits, fixed & employee transactions, pending earnings, adjustments */
    if (in_array($type, ['regular', 'final_pay'], true)) {
        $Tv0 = fn(string $k) => (float)($T[$k] ?? 0);
        $dutyHours = max(0, $Tv0('hours_work') - $Tv0('absent_hrs') - $Tv0('late_hrs') - $Tv0('undertime_hrs')) + $Tv0('rd_hrs') + $Tv0('lh_rd') + $Tv0('sh_rd');
        $holHours = $Tv0('holiday_hrs') + $Tv0('lh_hrs') + $Tv0('sh_hrs');
        $daysPerWeek = max(1, $rates['factor'] * 12 / 52);
        $absRatio = $Tv0('hours_work') > 0 ? min(1, $Tv0('absent_hrs') / $Tv0('hours_work')) : 0;
        $bens = rows("SELECT eb.*, bt.code, bt.name, bt.tax_treatment, bt.ceiling_amount, bt.ceiling_period, bt.frequency, bt.eligibility_regular_only, bt.eligibility_min_months,
                             bt.given_at, bt.no_deduction, bt.ref_ext
                      FROM employee_benefits eb JOIN benefit_types bt ON bt.id = eb.benefit_type_id
                      WHERE eb.employee_id = ? AND eb.status = 'active' AND bt.is_active = 1 AND eb.start_date <= ? AND (eb.end_date IS NULL OR eb.end_date >= ?)",
            [$empId, $run['period_end'], $run['period_start']]);
        $benPeriodNo = (int)pt_period_meta($run['period_start'], $run['period_end'], $freq)['period_no'];
        foreach ($bens as $bn) {
            if ($type === 'regular') {
                // manner of posting set on the enrollment (1st / 2nd / 3rd / 4th pay of the month, or every pay);
                // left blank, the benefit type's own frequency applies
                $postTerm = (string)($bn['post_term'] ?? '');
                if ($postTerm !== '') {
                    $no = term_period_no($postTerm);
                    if ($no !== null && $no !== $benPeriodNo) {
                        continue;
                    }
                } elseif (!frequency_applies($bn['frequency'], $flags)) {
                    continue;
                }
            }
            if ($bn['eligibility_regular_only'] && $emp['employment_status'] !== 'regular') {
                continue;
            }
            if ((int)$bn['eligibility_min_months'] > 0 && months_of_service($emp['date_hired'], $run['period_end']) < (int)$bn['eligibility_min_months']) {
                continue;
            }
            $rate = (float)$bn['amount'];
            $givenAt = $bn['given_at'] ?: 'fixed';
            $withHol = substr($givenAt, -4) === '_hol';
            $dh = $dutyHours + ($withHol ? $holHours : 0);
            $qty = null;
            switch (str_replace('_hol', '', $givenAt)) {
                case 'duty_day':
                    $qty = round($dh / $hpd, 2);
                    $amt = $rate * $qty;
                    break;
                case 'duty_hour':
                    $qty = round($dh, 2);
                    $amt = $rate * $qty;
                    break;
                case 'duty_week':
                    $qty = round($dh / $hpd / $daysPerWeek, 2);
                    $amt = $rate * $qty;
                    break;
                default:
                    $amt = empty($bn['no_deduction']) ? $rate * (1 - $absRatio) : $rate;
            }
            $amt = round($amt, 2);
            if ($amt <= 0) {
                continue;
            }
            $label = $bn['name'] . ($qty !== null ? sprintf(' (%s × %s)', rtrim(rtrim(number_format($qty, 2), '0'), '.'), number_format($rate, 2)) : '');
            if (!empty($bn['ref_ext'])) {
                $info('BEN_' . $bn['code'], $label . ' — reference only', $amt, 'info', $qty);
                continue;
            }
            switch ($bn['tax_treatment']) {
                case 'de_minimis':
                    if ($bn['ceiling_amount'] === null || $bn['ceiling_period'] === 'none') {
                        $earn('BEN_' . $bn['code'], $label, $amt, 'nontax', $srcBranch, $qty, 'benefit_type', (int)$bn['benefit_type_id'], 'others');
                        break;
                    }
                    $periodSql = $bn['ceiling_period'] === 'monthly' ? ' AND MONTH(pr.period_end) = ' . (int)substr($run['period_end'], 5, 2) : '';
                    $used = (float)val("SELECT COALESCE(SUM(l.amount),0) FROM payroll_item_lines l JOIN payroll_items pi ON pi.id = l.item_id JOIN payroll_runs pr ON pr.id = pi.run_id
                                        WHERE pi.employee_id = ? AND pr.status = 'finalized' AND l.ref_type = 'benefit_type' AND l.ref_id = ? AND l.taxable = 0
                                          AND YEAR(pr.period_end) = ? $periodSql AND pr.id <> ?", [$empId, $bn['benefit_type_id'], $year, $run['id']]);
                    $room = max(0, (float)$bn['ceiling_amount'] - $used);
                    $nt = min($amt, $room);
                    $earn('BEN_' . $bn['code'], $label . ' (de minimis)', $nt, 'nontax', $srcBranch, $qty, 'benefit_type', (int)$bn['benefit_type_id'], 'others');
                    if ($amt - $nt > 0) {
                        $earn('BEN_' . $bn['code'] . '_X', $bn['name'] . ' — excess over de minimis ceiling', $amt - $nt, 'other', $srcBranch, null, 'benefit_type_excess', (int)$bn['benefit_type_id'], 'others');
                    }
                    break;
                case 'other_benefit':
                    $earn('BEN_' . $bn['code'], $label, $amt, 'other', $srcBranch, $qty, 'benefit_type', (int)$bn['benefit_type_id'], 'others');
                    break;
                case 'non_taxable':
                    $earn('BEN_' . $bn['code'], $label, $amt, 'nontax', $srcBranch, $qty, 'benefit_type', (int)$bn['benefit_type_id'], 'others');
                    break;
                default:
                    $earn('BEN_' . $bn['code'], $label, $amt, 'taxable', $srcBranch, $qty, 'benefit_type', (int)$bn['benefit_type_id'], 'others');
            }
        }

        // fixed transactions: a start date, an amount and the pay of the month it applies to (every pay, 1st to 4th
        // pay); no end — they keep adding (earnings) or deducting (deductions) while the employee works.
        // An end date set before version 7 is still honoured. Statutory roles replace the computed deduction.
        $fxPayNo = (int)pt_period_meta($run['period_start'], $run['period_end'], $freq)['period_no'];
        $fx = rows("SELECT ef.*, fc.code, fc.name, fc.trans_type, fc.role, fc.default_terms, fc.taxable, fc.de_minimis
                    FROM employee_fixed_trans ef JOIN fixed_trans_codes fc ON fc.id = ef.code_id
                    WHERE ef.employee_id = ? AND ef.status = 'active' AND fc.is_active = 1
                      AND (ef.start_date IS NULL OR ef.start_date <= ?) AND (ef.end_date IS NULL OR ef.end_date >= ?) ORDER BY fc.code",
            [$empId, $run['period_end'], $run['period_start']]);
        foreach ($fx as $f) {
            $code = ($f['trans_type'] === 'earning' ? 'FX_' : 'FXD_') . $f['code'];
            $terms = $f['terms'] ?: $f['default_terms'];
            $no = term_period_no($terms);
            if ($terms === 'monthly_split') {
                $amt = round(payroll_terms_amount($terms, (float)$f['amount'], $flags, $ppm,
                    fn() => month_line_amount($empId, $run['period_end'], $code, (int)$run['id']), $isFinal), 2);
            } else {
                // every pay: the amount on each payroll; Nth pay: the amount on the Nth pay of the month (final pay: always)
                $amt = $no === null || $isFinal || $no === $fxPayNo ? round((float)$f['amount'], 2) : 0.0;
            }
            if ($f['role'] !== 'none') {
                $fixedStat[$f['role']] = ['amount' => $amt, 'name' => $f['name'], 'id' => (int)$f['id']];
                continue;
            }
            if ($amt <= 0) {
                continue;
            }
            if ($f['trans_type'] === 'earning') {
                $class = $f['de_minimis'] ? 'nontax' : ($f['taxable'] ? 'taxable' : 'other');
                $earn($code, $f['name'], $amt, $class, $srcBranch, null, 'fixed_trans', (int)$f['id'], 'others');
            } else {
                $pendingDed[] = ['code' => $code, 'desc' => $f['name'], 'amount' => $amt, 'ref_type' => 'fixed_trans', 'ref_id' => (int)$f['id'], 'reg' => 'others', 'qty' => null, 'consent' => true];
            }
        }

        // employee's transactions encoded for this period (one-time earnings / deductions)
        $ets = rows("SELECT et.*, ea.code AS e_code, ea.name AS e_name, ea.taxable, ea.tax_rate, ea.special, ea.de_minimis, ea.ref_ext AS e_ref,
                            dt.code AS d_code, dt.name AS d_name, dt.register_group, dt.ref_ext AS d_ref, dt.authorized_by_law
                     FROM employee_transactions et LEFT JOIN earning_types ea ON ea.id = et.earning_type_id LEFT JOIN deduction_types dt ON dt.id = et.deduction_type_id
                     WHERE et.employee_id = ? AND et.status = 'pending'
                       AND ((et.cutoff_id IS NOT NULL AND et.cutoff_id = ?) OR (et.period_start <= ? AND et.period_end >= ?)" . ($isFinal ? ' OR et.period_end <= ?' : '') . ')
                     ORDER BY et.id', $isFinal ? [$empId, (int)$run['cutoff_id'], $run['period_end'], $run['period_start'], $run['period_end']] : [$empId, (int)$run['cutoff_id'], $run['period_end'], $run['period_start']]);
        foreach ($ets as $t) {
            $amt = round((float)$t['amount'], 2);
            if ($amt <= 0) {
                continue;
            }
            $rem = $t['remarks'] ? ' — ' . mb_strimwidth($t['remarks'], 0, 50, '…') : '';
            if ($t['trans_type'] === 'earning') {
                if ($t['e_ref']) {
                    $info('ET_' . $t['e_code'], $t['e_name'] . $rem . ' — reference only', $amt);
                    continue;
                }
                $class = $t['de_minimis'] ? 'nontax' : ($t['special'] ? 'special' : ($t['taxable'] ? 'taxable' : 'other'));
                $earn('ET_' . $t['e_code'], $t['e_name'] . $rem, $amt, $class, $srcBranch, null, 'employee_transaction', (int)$t['id'], 'others');
                if ((float)$t['tax_rate'] > 0) {
                    $extraTax[] = ['desc' => sprintf('Tax on %s (%s%%)', $t['e_name'], rtrim(rtrim(number_format((float)$t['tax_rate'], 2), '0'), '.')), 'amount' => round($amt * (float)$t['tax_rate'] / 100, 2), 'id' => (int)$t['id']];
                }
            } else {
                if ($t['d_ref']) {
                    $info('EDT_' . $t['d_code'], $t['d_name'] . $rem . ' — reference only', $amt);
                    continue;
                }
                $pendingDed[] = ['code' => 'EDT_' . $t['d_code'], 'desc' => $t['d_name'] . $rem, 'amount' => $amt, 'ref_type' => 'employee_transaction', 'ref_id' => (int)$t['id'],
                    'reg' => $t['register_group'] ?: 'others', 'qty' => null, 'consent' => true];
            }
        }

        // employee's transactions WITH TERMS: the next installment of every amortized earning / deduction
        // whose term matches this pay (every pay, 1st / 2nd / 3rd / 4th pay of the month)
        foreach (term_due_for_run($empId, $run, $freq) as $due) {
            $t = $due['term'];
            $sc = $due['sched'];
            $amt = round((float)$sc['amount'], 2);
            if ($amt <= 0) {
                continue;
            }
            $tag = sprintf(' — %s of %d%s', ordinal((int)$sc['seq_no']), (int)$t['terms'], $t['doc_no'] ? ', ' . $t['doc_no'] : '');
            if ($t['trans_type'] === 'earning') {
                if ($t['e_ref']) {
                    $info('TT_' . $t['e_code'], $t['e_name'] . $tag . ' — reference only', $amt);
                    continue;
                }
                $class = $t['de_minimis'] ? 'nontax' : ($t['special'] ? 'special' : ($t['taxable'] ? 'taxable' : 'other'));
                $earn('TT_' . $t['e_code'], $t['e_name'] . $tag, $amt, $class, $srcBranch, null, 'term_trans', (int)$sc['id'], 'others');
            } else {
                if ($t['d_ref']) {
                    $info('TTD_' . $t['d_code'], $t['d_name'] . $tag . ' — reference only', $amt);
                    continue;
                }
                $pendingDed[] = ['code' => 'TTD_' . $t['d_code'], 'desc' => $t['d_name'] . $tag, 'amount' => $amt, 'ref_type' => 'term_trans', 'ref_id' => (int)$sc['id'],
                    'reg' => $t['register_group'] ?: 'others', 'qty' => null, 'consent' => true];
            }
        }
    }
    if ($type !== 'thirteenth') {
        foreach (rows("SELECT * FROM pending_earnings WHERE employee_id = ? AND status = 'pending' AND (payroll_run_id IS NULL OR payroll_run_id = ?)", [$empId, $run['id']]) as $pe) {
            $amt = (float)$pe['amount'];
            $reg = in_array($pe['source'], ['retro', 'other'], true) ? 'incadj' : 'others';
            if ($pe['tax_treatment'] === 'de_minimis') {
                $nt = min($amt, (float)$pe['nontax_portion']);
                $earn('PE_NT', $pe['description'] . ' (non-taxable portion)', $nt, 'nontax', $srcBranch, null, 'pending_earning', (int)$pe['id'], $reg);
                $earn('PE_OB', $pe['description'] . ' (excess)', $amt - $nt, 'other', $srcBranch, null, 'pending_earning_x', (int)$pe['id'], $reg);
            } elseif ($pe['tax_treatment'] === 'other_benefit') {
                $earn('PE', $pe['description'], $amt, 'other', $srcBranch, null, 'pending_earning', (int)$pe['id'], $reg);
            } elseif ($pe['tax_treatment'] === 'non_taxable') {
                $earn('PE', $pe['description'], $amt, 'nontax', $srcBranch, null, 'pending_earning', (int)$pe['id'], $reg);
            } else {
                $earn($pe['source'] === 'retro' ? 'RETRO' : 'PE', $pe['description'], $amt, 'taxable', $srcBranch, null, 'pending_earning', (int)$pe['id'], $reg);
            }
        }
    }
    $adjDed = [];
    foreach (rows('SELECT * FROM payroll_adjustments WHERE run_id = ? AND employee_id = ?', [$run['id'], $empId]) as $a) {
        if ($a['adj_type'] === 'earning') {
            $earn('ADJ', 'Adjustment: ' . $a['description'], (float)$a['amount'], $a['taxable'] ? 'taxable' : 'nontax', $srcBranch, null, 'adjustment', (int)$a['id'], 'incadj');
        } else {
            $adjDed[] = $a;
        }
    }

    /* ------------------------------------------------ 3. 13th month pay (13th-month runs, final pay, or "13th Month Process" on a regular run) */
    $with13th = $type === 'regular' && !empty($ctx['opt']['thirteenth']);
    if ($type === 'thirteenth' || $isFinal || $with13th) {
        // v17: basic salary earned over the 13th month coverage of the Payroll Parameters (Calendar or Fiscal Year)
        $ytd = thirteenth_totals($empId, $year, (int)$run['id']);
        $inCover = $run['period_end'] >= $ytd['from'] && $run['period_end'] <= $ytd['to'];
        $basicYtd = $ytd['basic_earned'] + (($isFinal || $with13th) && $inCover ? $item['basic_earned'] : 0);
        $amount = round(max(0, $basicYtd / 12 - $ytd['thirteenth_paid']), 2);
        if ($amount > 0) {
            $cover = setting('thirteenth_basis', 'calendar') === 'fiscal' ? ', ' . fmt_date($ytd['from'], 'M d, Y') . ' – ' . fmt_date($ytd['to'], 'M d, Y') : '';
            $earn('13TH', sprintf('13th month pay (basic earned %s ÷ 12%s%s)', number_format($basicYtd, 2), $cover, $ytd['thirteenth_paid'] > 0 ? ' less ' . number_format($ytd['thirteenth_paid'], 2) . ' paid' : ''), $amount, 'other', $srcBranch, null, null, null, '13th');
        }
        if ($type === 'thirteenth') {
            $deadline = substr($run['period_end'], 0, 4) . '-' . setting('thirteenth_deadline', '12-24');
            if ($run['pay_date'] > $deadline) {
                $ex('thirteenth_deadline', 'Pay date is later than the PD 851 deadline (' . fmt_date($deadline) . ').', 0);
            }
        }
    }
    if ($isFinal) {
        // unused convertible leave credits
        foreach (rows("SELECT lt.* FROM leave_types lt WHERE lt.convertible = 1 AND lt.accrual_method <> 'per_event'") as $lt) {
            $bal = leave_balance($empId, (int)$lt['id'], $year);
            if ($bal['available'] > 0) {
                $amt = round($bal['available'] * $rates['daily'], 2);
                $nt = in_array($lt['code'], ['VL', 'SIL'], true) ? round(min($bal['available'], setting_num('de_minimis_leave_days', 12)) * $rates['daily'], 2) : 0;
                $earn('LEAVECONV', sprintf('Unused %s converted to cash (%s day(s))', $lt['code'], $bal['available']), $nt, 'nontax', $srcBranch, $bal['available'], 'leave_type', (int)$lt['id'], 'others');
                $earn('LEAVECONV_X', sprintf('Unused %s converted to cash (%s day(s)) — excess', $lt['code'], $bal['available']), $amt - $nt, 'other', $srcBranch, null, 'leave_type', (int)$lt['id'], 'others');
            }
        }
    }

    /* ------------------------------------------------ 4. Totals by tax class; special earnings and 90K ceilings */
    $sum = ['comp' => 0.0, 'taxable' => 0.0, 'nontax' => 0.0, 'other' => 0.0, 'special' => 0.0];
    foreach ($lines as $l) {
        if ($l['line_type'] === 'earning') {
            $sum[$l['class']] += $l['amount'];
        }
    }
    $specialNontax = round(min($sum['special'], max(0, setting_num('special_max_nontax', 0))), 2);
    $specialTaxable = round($sum['special'] - $specialNontax, 2);
    $ceiling = setting_num('nontax_benefit_ceiling', 90000);
    $ytdAll = payroll_ytd($empId, $year, (int)$run['id']);
    // a new employee's previous employer of the same year (YTD Information for Previous Employer):
    // its 13th month & other benefits use up the same non-taxable ceiling, its taxable pay and tax
    // withheld enter the year-end annualization
    $prevEmp = prev_employer_ytd($empId, $year);
    $ytdAll['other_nontax'] += $prevEmp['other_benefits'] + $prevEmp['thirteenth'];
    $ytdAll['taxable'] += $prevEmp['taxable'];
    $ytdAll['tax'] += $prevEmp['tax'];
    $room = max(0, $ceiling - $ytdAll['other_nontax']);
    $otherNontax = round(min($sum['other'], $room), 2);
    $otherTaxable = round($sum['other'] - $otherNontax, 2);
    if ($otherTaxable > 0) {
        $info('OB_TAXABLE', sprintf('13th month & other benefits in excess of the ₱%s non-taxable ceiling (taxable)', number_format($ceiling, 0)), $otherTaxable);
    }
    if ($specialTaxable > 0) {
        $info('SPC_TAXABLE', sprintf('Special earnings in excess of the ₱%s non-taxable amount (taxable)', number_format(setting_num('special_max_nontax', 0), 2)), $specialTaxable);
    }
    $item['benefits_nontax'] = round($sum['nontax'] + $specialNontax, 2);
    $item['benefits_other'] = $otherNontax;
    $item['benefits_taxable'] = round($otherTaxable, 2);
    $item['other_earnings'] = round($sum['taxable'] + $specialTaxable, 2);
    $item['gross_pay'] = round($sum['comp'] + $sum['taxable'] + $sum['nontax'] + $sum['other'] + $sum['special'], 2);
    $item['premium_pay'] = round($item['premium_pay'], 2);
    $item['ot_pay'] = round($item['ot_pay'], 2);
    $item['nd_pay'] = round($item['nd_pay'], 2);

    if ($item['gross_pay'] <= 0 && $type !== 'regular') {
        return null;
    }
    if ($type === 'regular' && $item['gross_pay'] <= 0 && !$adjDed) {
        $ex('zero_pay', 'No earnings for this period (no attendance / fully absent). No statutory deductions were taken.', 0);
    }

    // Regular Processing off (Payroll Computation): incomes only; no tax, contributions, loans or other deductions
    $regularProc = !isset($ctx['opt']['regular']) || $ctx['opt']['regular'] || $type !== 'regular';
    if (!$regularProc) {
        $info('NO_DEDUCTIONS', 'Regular processing off: incomes only, no tax, contributions, loans or company deductions', 0);
    }

    /* ------------------------------------------------ 5. Statutory contributions (default employer only; pay-code deduction modes) */
    if (in_array($type, ['regular', 'final_pay'], true) && $item['gross_pay'] > 0 && $regularProc) {
        $basis = $rates['monthly'];
        $item['contrib_basis'] = $basis;
        // SSS lookup: monthly rate, or the month's gross earnings
        $sssBasis = $basis;
        if (setting('sss_lookup', 'monthly_rate') === 'gross') {
            $monthGross = month_statutory_deducted($empId, $run['period_end'], 'gross_pay', (int)$run['id']) + $item['gross_pay'];
            $paysSoFar = 1 + (int)val("SELECT COUNT(*) FROM payroll_items pi JOIN payroll_runs pr ON pr.id = pi.run_id WHERE pi.employee_id = ? AND pr.status IN ('finalized','computed')
                                      AND pr.run_type = 'regular' AND pr.id <> ? AND YEAR(pr.period_end) = ? AND MONTH(pr.period_end) = ?",
                    [$empId, $run['id'], $year, (int)substr($run['period_end'], 5, 2)]);
            $sssBasis = ($flags['last'] || $isFinal) ? $monthGross : $monthGross * $ppm / $paysSoFar;
        }
        $sss = sss_contribution($sssBasis, $run['period_end']);
        $ph = philhealth_contribution($basis, $run['period_end']);
        $hd = pagibig_contribution($basis, $run['period_end'], (float)$emp['pagibig_voluntary']);
        // HDMF processing configuration
        $hdEe = min(setting_num('hdmf_max_ee', 200), round($basis * setting_num('hdmf_rate_pct', 2) / 100, 2));
        if ($basis < setting_num('hdmf_min_gross', 0)) {
            $hdEe = 0;
        }
        $hdVol = round($hd['voluntary'] + $basis * setting_num('hdmf_global_ee_pct', 0) / 100, 2);
        $monthly = ['sss_ee' => $sss['ee'], 'sss_er' => $sss['er'], 'sss_ec' => $sss['ec'], 'phic_ee' => $ph['ee'], 'phic_er' => $ph['er'],
            'hdmf_ee' => $hdEe, 'hdmf_er' => $hd['er'], 'hdmf_ee_voluntary' => $hdVol];
        $modeOf = function (string $f) use ($pg) {
            $legacy = ['split' => 'every', 'first' => 'first', 'last' => 'second'][$pg['contrib_mode'] ?? 'split'] ?? 'every';
            if (strpos($f, 'sss') === 0) {
                return $pg['sss_mode'] ?? $legacy;
            }
            if (strpos($f, 'phic') === 0) {
                return $pg['phic_mode'] ?? $legacy;
            }
            return $pg['hdmf_mode'] ?? $legacy;
        };
        foreach ($monthly as $f => $m) {
            $mode = $modeOf($f);
            $already = fn() => month_statutory_deducted($empId, $run['period_end'], $f, (int)$run['id']);
            if ($isFinal || ($mode === 'every' && $flags['last']) || ($mode === 'first' && $flags['first']) || ($mode === 'second' && $flags['last'])) {
                $amt = max(0, $m - $already());
            } elseif ($mode === 'every') {
                $amt = $m / $ppm;
            } else {
                $amt = 0;
            }
            $item[$f] = round($amt, 2);
        }
        foreach (['sss' => 'sss_ee', 'hdmf' => 'hdmf_ee', 'phic' => 'phic_ee'] as $role => $f) {
            if (isset($fixedStat[$role])) {
                $item[$f] = $fixedStat[$role]['amount'];
            }
        }
        $ded('SSS', sprintf('SSS contribution (MSC ₱%s)', number_format($sss['msc'], 0)) . (isset($fixedStat['sss']) ? ' — fixed' : ''), $item['sss_ee'], null, null, null, 'sss');
        $ded('PHIC', 'PhilHealth contribution' . (isset($fixedStat['phic']) ? ' — fixed' : ''), $item['phic_ee'], null, null, null, 'phic');
        $ded('HDMF', 'Pag-IBIG contribution' . (isset($fixedStat['hdmf']) ? ' — fixed' : ''), $item['hdmf_ee'], null, null, null, 'hdmf');
        $ded('HDMF_VOL', 'Pag-IBIG voluntary / additional contribution', $item['hdmf_ee_voluntary'], null, null, null, 'hdmf');
        $info('SSS_ER', 'SSS employer share', $item['sss_er'], 'employer');
        $info('SSS_EC', 'SSS Employees\' Compensation (EC)', $item['sss_ec'], 'employer');
        $info('PHIC_ER', 'PhilHealth employer share', $item['phic_er'], 'employer');
        $info('HDMF_ER', 'Pag-IBIG employer share', $item['hdmf_er'], 'employer');
    }
    $mandatory = $item['sss_ee'] + $item['phic_ee'] + $item['hdmf_ee'];

    /* ------------------------------------------------ 6. Withholding tax (taxable base, OT taxable, pay-code tax mode & table) */
    $compTaxable = $taxExempt ? 0 : $sum['comp'];
    if (!$taxExempt && setting('ot_taxable', '1') !== '1') {
        $compTaxable -= $item['ot_pay'] + $item['premium_pay'] + $item['nd_pay'];
    }
    switch (setting('taxable_base', 'gross_less_govt')) {
        case 'basic_less_govt':
            $taxable = ($taxExempt ? 0 : $item['basic_earned']) - ($taxExempt ? 0 : $mandatory);
            break;
        case 'gross':
            $taxable = $compTaxable + $sum['taxable'] + $specialTaxable + $otherTaxable;
            break;
        default:
            $taxable = $compTaxable + $sum['taxable'] + $specialTaxable + $otherTaxable - ($taxExempt ? 0 : $mandatory);
    }
    $item['taxable_income'] = round(max(0, $taxable), 2);
    // tax table: the one chosen on the Payroll Computation form, else the pay code's
    $table = !empty($ctx['opt']['tax_table']) ? $ctx['opt']['tax_table'] : ($pg['tax_type'] ?? null);
    $table = $table ?: $freq;
    $taxMode = $pg['tax_mode'] ?? 'every';
    $isDecemberLast = $type === 'regular' && (int)substr($run['period_end'], 5, 2) === 12 && $flags['last'] && setting('year_end_adjust', '1') === '1';
    $annualize = $type === 'regular' && !empty($ctx['opt']['annualize']);
    if (!$regularProc) {
        $item['withholding_tax'] = 0;
        $taxNote = '';
    } elseif (isset($fixedStat['tax'])) {
        $item['withholding_tax'] = $fixedStat['tax']['amount'];
        $taxNote = ' — fixed';
    } elseif ($isFinal || $isDecemberLast || $annualize) {
        // annualize: total taxable for the year vs tax withheld
        $annualTaxable = $ytdAll['taxable'] + $item['taxable_income'];
        $due = compute_annual_tax($annualTaxable, $run['period_end']);
        $tax = round($due - $ytdAll['tax'], 2);
        if ($tax < 0) {
            $earn('TAXREFUND', 'Refund of excess withholding tax (year-end annualization)', -$tax, 'nontax', $srcBranch, null, null, null, 'others');
            $item['gross_pay'] = round($item['gross_pay'] - $tax, 2);
            $item['benefits_nontax'] = round($item['benefits_nontax'] - $tax, 2);
            $tax = 0;
        }
        $item['withholding_tax'] = $tax;
        $info('ANNUALIZED', sprintf('Annualized taxable ₱%s; tax due ₱%s; withheld YTD ₱%s%s', number_format($annualTaxable, 2), number_format($due, 2), number_format($ytdAll['tax'], 2),
            $prevEmp['taxable'] > 0 || $prevEmp['tax'] > 0 ? sprintf(' (previous employer: taxable ₱%s, tax ₱%s)', number_format($prevEmp['taxable'], 2), number_format($prevEmp['tax'], 2)) : ''), $due);
        $taxNote = ' (annualized)';
    } elseif ($type === 'thirteenth' || $type === 'special') {
        // marginal method: extra tax caused by adding this taxable amount to the latest regular period
        $lastTaxable = (float)val("SELECT pi.taxable_income FROM payroll_items pi JOIN payroll_runs pr ON pr.id = pi.run_id
                                   WHERE pi.employee_id = ? AND pr.status = 'finalized' AND pr.run_type = 'regular' ORDER BY pr.period_end DESC LIMIT 1", [$empId]);
        $item['withholding_tax'] = $taxExempt && $sum['taxable'] + $otherTaxable <= 0 ? 0 :
            round(max(0, compute_withholding_tax($lastTaxable + $item['taxable_income'], $freq, $run['period_end']) - compute_withholding_tax($lastTaxable, $freq, $run['period_end'])), 2);
        $taxNote = '';
    } else {
        $pt = tax_table_ppm($table);
        $taxOnMonth = fn(float $monthTaxable) => compute_withholding_tax($monthTaxable / $pt, $table, $run['period_end']) * $pt;
        $mtdTaxable = month_statutory_deducted($empId, $run['period_end'], 'taxable_income', (int)$run['id']) + $item['taxable_income'];
        $mtdWithheld = month_statutory_deducted($empId, $run['period_end'], 'withholding_tax', (int)$run['id']);
        if ($taxMode === 'every' && $table === $freq) {
            $tax = compute_withholding_tax($item['taxable_income'], $freq, $run['period_end']);
        } elseif ($taxMode === 'every') {
            // other table: project the pay to the table's period; true-up on the last pay of the month
            $tax = $flags['last'] ? $taxOnMonth($mtdTaxable) - $mtdWithheld : $taxOnMonth($item['taxable_income'] * $ppm) / $ppm;
        } elseif (($taxMode === 'second' && $flags['last']) || ($taxMode === 'first' && $flags['first'])) {
            $tax = $taxMode === 'second' ? $taxOnMonth($mtdTaxable) - $mtdWithheld : $taxOnMonth($item['taxable_income'] * $ppm) - $mtdWithheld;
        } else {
            $tax = 0;
        }
        $item['withholding_tax'] = round(max(0, $tax), 2);
        $taxNote = $taxMode !== 'every' || $table !== $freq ? ' (' . label($table) . ' table, ' . ['every' => 'every pay', 'first' => '1st pay', 'second' => '2nd pay'][$taxMode] . ')' : '';
    }
    if (!$regularProc) {
        $extraTax = [];
    }
    $ded('WTAX', 'Withholding tax' . ($taxExempt ? ' (exempt on statutory minimum wage / exemption code)' : '') . $taxNote, $item['withholding_tax'], null, null, null, 'wtax');
    foreach ($extraTax as $xt) {
        $ded('WTAX_X', $xt['desc'], $xt['amount'], 'employee_transaction', $xt['id'], null, 'wtax');
    }
    $extraTaxTotal = array_sum(array_column($extraTax, 'amount'));

    /* ------------------------------------------------ 7. Other deductions (wage-protection bound) */
    $gross = $item['gross_pay'];
    $statTotal = $mandatory + $item['hdmf_ee_voluntary'] + $item['withholding_tax'] + $extraTaxTotal;
    $floor = $isFinal ? 0 : max(round($gross * setting_num('min_net_pay_pct', 0) / 100, 2), $type === 'regular' ? setting_num('min_net_pay_amount', 0) : 0);
    $available = round($gross - $statTotal - $floor, 2);
    $other = $extraTaxTotal;
    $deferred = 0.0;
    if (!$regularProc) {
        $adjDed = [];
        $pendingDed = [];
    }
    foreach ($adjDed as $a) {
        $amt = round((float)$a['amount'], 2);
        $ded('ADJ', 'Adjustment: ' . $a['description'], $amt, 'adjustment', (int)$a['id']);
        $other += $amt;
        $available -= $amt;
    }
    $takeDeduction = function (string $code, string $desc, float $amt, ?string $refType, ?int $refId, string $reg, $qty = null) use (&$available, &$deferred, &$other, $ded, $ex) {
        $amt = round($amt, 2);
        if ($amt > $available) {
            $apply = max(0, round($available, 2));
            $def = round($amt - $apply, 2);
            $deferred += $def;
            $ex('deduction_deferred', sprintf('%s: %s deferred to keep take-home pay above the minimum.', $desc, number_format($def, 2)), 0);
            $amt = $apply;
        }
        if ($amt <= 0) {
            return;
        }
        $ded($code, $desc, $amt, $refType, $refId, $qty, $reg);
        $other += $amt;
        $available -= $amt;
    };
    if (in_array($type, ['regular', 'final_pay'], true) && $regularProc) {
        $eds = rows("SELECT ed.*, dt.code, dt.name, dt.category, dt.computation, dt.frequency, dt.authorized_by_law, dt.default_amount, dt.register_group, dt.ref_ext,
                            l.balance AS ledger_balance, l.amortization AS ledger_amort, l.status AS ledger_status, l.sub_type
                     FROM employee_deductions ed JOIN deduction_types dt ON dt.id = ed.deduction_type_id
                     LEFT JOIN ledgers l ON l.id = ed.ledger_id
                     WHERE ed.employee_id = ? AND ed.status = 'active' AND dt.is_active = 1 AND dt.category <> 'statutory'
                       AND ed.start_date <= ? AND (ed.end_date IS NULL OR ed.end_date >= ?)
                     ORDER BY dt.priority, ed.id", [$empId, $run['period_end'], $run['period_start']]);
        $acctCap = round($gross * setting_num('accountability_max_pct', 20) / 100, 2);
        foreach ($eds as $d) {
            if ($type === 'regular' && !frequency_applies($d['frequency'], $flags)) {
                continue;
            }
            if (!$d['authorized_by_law'] && !$d['written_consent']) {
                $ex('no_consent', sprintf('%s was NOT deducted: no written authorization on file (Labor Code Art. 113).', $d['name']), 0);
                continue;
            }
            if ($d['ledger_id']) {
                if ($d['ledger_status'] !== 'active' || (float)$d['ledger_balance'] <= 0) {
                    continue;
                }
                $amt = $isFinal ? (float)$d['ledger_balance'] : min((float)($d['amount'] > 0 ? $d['amount'] : $d['ledger_amort']), (float)$d['ledger_balance']);
            } elseif ($d['computation'] === 'percent_basic') {
                $amt = $item['basic_pay'] * (float)$d['amount'] / 100;
            } else {
                $amt = (float)($d['amount'] > 0 ? $d['amount'] : $d['default_amount']);
            }
            $amt = round($amt, 2);
            if ($amt <= 0) {
                continue;
            }
            if (!empty($d['ref_ext'])) {
                $info('DED_' . $d['code'], $d['name'] . ' — reference only', $amt);
                continue;
            }
            if ($d['category'] === 'accountability' && !$isFinal && $amt > $acctCap) {
                $info('DEFER', sprintf('%s limited to %s%% of wages (Art. 114): %s deferred', $d['name'], setting('accountability_max_pct', 20), number_format($amt - $acctCap, 2)), $amt - $acctCap);
                $deferred += $amt - $acctCap;
                $amt = $acctCap;
            }
            $takeDeduction('DED_' . $d['code'], $d['name'] . ($d['sub_type'] ? ' — ' . $d['sub_type'] : ''), $amt, $d['ledger_id'] ? 'ledger' : 'employee_deduction', (int)($d['ledger_id'] ?: $d['id']), $d['register_group'] ?: 'others');
        }
        foreach ($pendingDed as $pd) {
            $takeDeduction($pd['code'], $pd['desc'], (float)$pd['amount'], $pd['ref_type'], $pd['ref_id'], $pd['reg'], $pd['qty']);
        }
        if ($isFinal) {
            // offsets of ledgers without an active payroll deduction (e.g. accountabilities)
            foreach (rows("SELECT * FROM ledgers WHERE employee_id = ? AND status = 'active' AND ledger_type <> 'accrued_benefit' AND balance > 0
                           AND id NOT IN (SELECT ledger_id FROM employee_deductions WHERE ledger_id IS NOT NULL AND status = 'active')", [$empId]) as $l) {
                $reg = $l['ledger_type'] === 'gov_loan' ? (stripos($l['sub_type'], 'pag') !== false || stripos($l['sub_type'], 'hdmf') !== false ? 'hdmf_loan' : 'sss_loan') : 'company_loan';
                $ded('OFFSET', 'Offset: ' . $l['sub_type'] . ' (' . $l['ledger_no'] . ')', (float)$l['balance'], 'ledger', (int)$l['id'], null, $reg);
                $other += (float)$l['balance'];
            }
        }
    }
    $item['other_deductions'] = round($other, 2);
    $item['deferred_deductions'] = round($deferred, 2);
    $item['total_deductions'] = round($statTotal - $extraTaxTotal + $other, 2);
    $item['net_pay'] = round($item['gross_pay'] - $item['total_deductions'], 2);
    if ($item['net_pay'] < 0) {
        $ex('negative_net', 'Net pay is negative (' . number_format($item['net_pay'], 2) . '). Review deductions or add an adjustment.', 1);
    }

    /* ------------------------------------------------ 8. Branch allocation */
    $item['gross_pay'] = round($item['gross_pay'], 2);
    $branches = [];
    $sumGross = 0;
    foreach ($branchAgg as $b => $v) {
        $g = round($v['basic_alloc'] + $v['premium_pay'] + $v['ot_pay'] + $v['nd_pay'] + $v['other_earnings'] - $v['late_ut_deduction'], 2);
        $branches[$b] = array_merge($v, ['branch_id' => $b, 'is_default' => $b === $defaultBranch ? 1 : 0, 'is_deduction_source' => $b === $srcBranch ? 1 : 0, 'gross' => $g]);
        $sumGross += $g;
    }
    // everything not attributed to a worked branch (benefits, adjustments, 13th month...) goes to the default branch
    $diff = round($item['gross_pay'] - $sumGross, 2);
    if (abs($diff) >= 0.01) {
        $branches[$srcBranch]['other_earnings'] = round($branches[$srcBranch]['other_earnings'] + $diff, 2);
        $branches[$srcBranch]['gross'] = round($branches[$srcBranch]['gross'] + $diff, 2);
    }
    $er = $item['sss_er'] + $item['sss_ec'] + $item['phic_er'] + $item['hdmf_er'];
    $alloc = 0;
    $keys = array_keys($branches);
    foreach ($keys as $i => $b) {
        $share = $item['gross_pay'] > 0 ? $branches[$b]['gross'] / $item['gross_pay'] : ($b === $defaultBranch ? 1 : 0);
        $a = $i === count($keys) - 1 ? round($er - $alloc, 2) : round($er * $share, 2);
        $branches[$b]['er_share_alloc'] = $a;
        $alloc += $a;
        foreach (['days_worked', 'reg_hours', 'ot_hours', 'basic_alloc', 'premium_pay', 'ot_pay', 'nd_pay', 'other_earnings', 'late_ut_deduction'] as $f) {
            $branches[$b][$f] = round($branches[$b][$f], 2);
        }
    }
    // every deduction is taken from the deduction-source branch: its payslip carries them and the net pay;
    // the other branch payslips are paid in full
    foreach ($branches as $b => $v) {
        $branches[$b]['deductions'] = $b === $srcBranch ? round($item['total_deductions'], 2) : 0.0;
        $branches[$b]['net_pay'] = round($v['gross'] - $branches[$b]['deductions'], 2);
    }
    // drop empty branches other than the employer and the deduction source
    $branches = array_filter($branches, fn($v) => $v['is_default'] || $v['is_deduction_source'] || abs($v['gross']) >= 0.01 || $v['reg_hours'] > 0);

    return ['item' => $item, 'lines' => $lines, 'branches' => array_values($branches)];
}

function payroll_save_item(int $runId, array $res): int
{
    $item = $res['item'];
    $item['run_id'] = $runId;
    $itemId = insert('payroll_items', $item);
    foreach ($res['lines'] as $l) {
        unset($l['class']);
        $l['item_id'] = $itemId;
        insert('payroll_item_lines', $l);
    }
    foreach ($res['branches'] as $b) {
        insert('payroll_branch_items', array_merge($b, ['item_id' => $itemId, 'run_id' => $runId, 'employee_id' => $item['employee_id']]));
    }
    return $itemId;
}

/** Offset leave type (credited with the overtime of OT/OL jobs). */
function offset_leave_type_id(): ?int
{
    $id = val("SELECT id FROM leave_types WHERE code = 'OFL'");
    return $id ? (int)$id : null;
}

/**
 * Finalize a computed run: the figures are approved and locked, payslips are released and the bank file
 * can be made. The postings (ledgers, deductions, transactions, accruals, leave credits, attendance lock)
 * are done by Period Closing (app/engine/closing.php), the last step of the payroll cycle. A final pay
 * run is posted at once (releasing the final pay settles the employee's balances).
 */
function payroll_finalize(int $runId): void
{
    $run = payroll_run($runId);
    if (!$run || $run['status'] !== 'computed') {
        throw new RuntimeException('Only computed runs can be finalized.');
    }
    $open = (int)val("SELECT COUNT(*) FROM payroll_exceptions WHERE run_id = ? AND blocking = 1 AND status = 'open'", [$runId]);
    if ($open) {
        throw new RuntimeException("$open blocking exception(s) must be resolved before finalizing this payroll run.");
    }
    tx(function () use ($run, $runId) {
        update('payroll_runs', ['status' => 'finalized', 'finalized_at' => now(), 'finalized_by' => user_id()], 'id = ?', [$runId]);
        if ($run['run_type'] === 'final_pay') {
            payroll_post_items($runId, null, null);
        }
    });
    audit('payroll', 'run_finalize', 'payroll_runs', $runId, ['run_no' => $run['run_no']]);
}

/**
 * Post the payroll items of a finalized run (all of them, or $itemIds): loan and ledger payments,
 * one-off deductions completed, transactions / queued earnings / retroactive pay included, installments
 * of transactions with terms posted, 13th month paid and accrued, offset leave credited. Each item is
 * posted once (payroll_items.posted_at). Returns counts for the closing log.
 */
function payroll_post_items(int $runId, ?array $itemIds, ?int $closingId): array
{
    $run = payroll_run($runId);
    if (!$run || $run['status'] !== 'finalized') {
        throw new RuntimeException('Only finalized runs can be posted.');
    }
    $date = $run['pay_date'];
    $n = ['items' => 0, 'ledger' => 0, 'deductions' => 0, 'transactions' => 0, 'installments' => 0, 'earnings' => 0, 'accruals' => 0, 'leave' => 0];
    $where = 'run_id = ? AND posted_at IS NULL';
    $params = [$runId];
    if ($itemIds !== null) {
        if (!$itemIds) {
            return $n;
        }
        $where .= ' AND id IN (' . in_list($itemIds) . ')';
        $params = array_merge($params, array_values($itemIds));
    }
    foreach (rows("SELECT * FROM payroll_items WHERE $where", $params) as $it) {
        foreach (rows('SELECT * FROM payroll_item_lines WHERE item_id = ?', [$it['id']]) as $l) {
            if ($l['line_type'] === 'deduction' && $l['ref_type'] === 'ledger') {
                ledger_post((int)$l['ref_id'], $date, 'Payroll deduction ' . $run['run_no'], 0, (float)$l['amount'], $runId);
                $n['ledger']++;
            } elseif ($l['line_type'] === 'deduction' && $l['ref_type'] === 'employee_deduction') {
                $cat = val('SELECT dt.category FROM employee_deductions ed JOIN deduction_types dt ON dt.id = ed.deduction_type_id WHERE ed.id = ?', [$l['ref_id']]);
                if ($cat === 'adhoc') {
                    q("UPDATE employee_deductions SET status = 'completed' WHERE id = ?", [$l['ref_id']]);
                    $n['deductions']++;
                }
            } elseif ($l['ref_type'] === 'employee_transaction' && $l['code'] !== 'WTAX_X') {
                q("UPDATE employee_transactions SET status = 'included', payroll_run_id = ? WHERE id = ? AND status = 'pending'", [$runId, $l['ref_id']]);
                $n['transactions']++;
            } elseif ($l['ref_type'] === 'term_trans' && $l['line_type'] !== 'info') {
                term_post_installment((int)$l['ref_id'], $runId, $run['run_no'], $date, (float)$l['amount']);
                $n['installments']++;
            } elseif ($l['line_type'] === 'earning' && in_array($l['ref_type'], ['pending_earning'], true)) {
                $pe = row('SELECT * FROM pending_earnings WHERE id = ?', [$l['ref_id']]);
                if ($pe && $pe['status'] === 'pending') {
                    update('pending_earnings', ['status' => 'included', 'payroll_run_id' => $runId], 'id = ?', [$pe['id']]);
                    if ($pe['source'] === 'retro') {
                        q("UPDATE retro_adjustments SET status = 'included' WHERE id = ?", [$pe['source_id']]);
                    } elseif ($pe['source'] === 'leave_conversion' && $pe['source_id']) {
                        ledger_post((int)$pe['source_id'], $date, 'Paid in ' . $run['run_no'], 0, (float)$pe['amount'], $runId);
                    }
                    $n['earnings']++;
                }
            } elseif ($l['line_type'] === 'earning' && $l['code'] === '13TH') {
                $led = ledger_accrual((int)$it['employee_id'], '13th Month Pay');
                ledger_post((int)$led['id'], $date, '13th month paid ' . $run['run_no'], 0, (float)$l['amount'], $runId);
                $n['ledger']++;
            } elseif ($l['code'] === 'OT_OFFSET' && (float)$l['quantity'] > 0 && ($olt = offset_leave_type_id())) {
                $hpd = employee_hours_per_day(att_employee((int)$it['employee_id']) ?: []);
                $bal = leave_balance_row((int)$it['employee_id'], $olt, (int)substr($run['period_end'], 0, 4));
                q('UPDATE leave_balances SET adjustment = adjustment + ? WHERE id = ?', [round((float)$l['quantity'] / $hpd, 2), $bal['id']]);
                $n['leave']++;
            }
        }
        if (in_array($run['run_type'], ['regular'], true) && (float)$it['basic_earned'] > 0) {
            $led = ledger_accrual((int)$it['employee_id'], '13th Month Pay');
            ledger_post((int)$led['id'], $date, '13th month accrual ' . $run['run_no'], round((float)$it['basic_earned'] / 12, 2), 0, $runId);
            $n['accruals']++;
        }
        update('payroll_items', ['posted_at' => now(), 'closing_id' => $closingId], 'id = ?', [$it['id']]);
        $n['items']++;
    }
    if (!(int)val('SELECT COUNT(*) FROM payroll_items WHERE run_id = ? AND posted_at IS NULL', [$runId])) {
        update('payroll_runs', ['posted_at' => now(), 'posted_by' => user_id()], 'id = ?', [$runId]);
    }
    return $n;
}

function payslip_item(int $itemId): ?array
{
    $it = row('SELECT pi.*, pr.run_no, pr.run_type, pr.period_start, pr.period_end, pr.pay_date, pr.status AS run_status, pg.name AS pay_group_name, pg.frequency,
                      e.employee_no, e.first_name, e.last_name, e.middle_name, e.suffix, e.position, e.tin, e.sss_no, e.philhealth_no, e.pagibig_no,
                      e.bank_name, e.bank_account_no, e.biometric_id, ou.name AS org_unit_name, x.code AS exemption_code
               FROM payroll_items pi JOIN payroll_runs pr ON pr.id = pi.run_id JOIN pay_groups pg ON pg.id = pr.pay_group_id
               JOIN employees e ON e.id = pi.employee_id LEFT JOIN org_units ou ON ou.id = pi.org_unit_id
               LEFT JOIN exemption_codes x ON x.id = e.exemption_code_id WHERE pi.id = ?', [$itemId]);
    if (!$it) {
        return null;
    }
    $it['lines'] = rows('SELECT * FROM payroll_item_lines WHERE item_id = ? ORDER BY sort_order, id', [$itemId]);
    $it['branches'] = rows('SELECT pb.*, b.name AS branch_name, b.code AS branch_code, b.company_name FROM payroll_branch_items pb JOIN branches b ON b.id = pb.branch_id WHERE pb.item_id = ? ORDER BY pb.is_default DESC, b.code', [$itemId]);
    $it['default_branch'] = row('SELECT * FROM branches WHERE id = ?', [$it['default_branch_id']]);
    return $it;
}

/**
 * Delete a cancelled payroll run for good (Payroll Runs > Delete). Only cancelled runs: a computed run is
 * cancelled first, a finalized run is never deleted. Items, exceptions and adjustments of the run go with it;
 * transactions that pointed to it become pending again for the next run.
 */
function payroll_delete_run(int $runId): string
{
    $run = payroll_run($runId);
    if (!$run) {
        throw new RuntimeException('Payroll run not found.');
    }
    if ($run['status'] !== 'cancelled') {
        throw new RuntimeException('Only a cancelled payroll run can be deleted. Cancel the run first.');
    }
    if ((int)val('SELECT COUNT(*) FROM ledger_entries WHERE payroll_run_id = ?', [$runId])) {
        throw new RuntimeException('Ledger entries were posted by this run; it cannot be deleted.');
    }
    tx(function () use ($runId) {
        q('DELETE l FROM payroll_item_lines l JOIN payroll_items pi ON pi.id = l.item_id WHERE pi.run_id = ?', [$runId]);
        q('DELETE FROM payroll_branch_items WHERE run_id = ?', [$runId]);
        q('DELETE FROM payroll_items WHERE run_id = ?', [$runId]);
        q('DELETE FROM payroll_exceptions WHERE run_id = ?', [$runId]);
        q('DELETE FROM payroll_adjustments WHERE run_id = ?', [$runId]);
        q("UPDATE pending_earnings SET payroll_run_id = NULL WHERE payroll_run_id = ? AND status = 'pending'", [$runId]);
        q("UPDATE employee_transactions SET payroll_run_id = NULL WHERE payroll_run_id = ? AND status = 'pending'", [$runId]);
        q("UPDATE employee_term_schedule SET payroll_run_id = NULL WHERE payroll_run_id = ? AND status = 'pending'", [$runId]);
        q('UPDATE final_pays SET payroll_run_id = NULL WHERE payroll_run_id = ?', [$runId]);
        q('DELETE FROM payroll_runs WHERE id = ?', [$runId]);
    });
    audit('payroll', 'run_delete', 'payroll_runs', $runId, ['run_no' => $run['run_no'], 'period' => $run['period_start'] . ' - ' . $run['period_end']]);
    return (string)$run['run_no'];
}
