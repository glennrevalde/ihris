<?php
/**
 * Payroll reports of the M.A.D.A.S. report menus, from one engine:
 *
 *   Periodical Reports  the open payroll period: employee shares only (no employer share), deduction
 *                       and earning listings, bank deposit and cash payment listings. A period that is
 *                       closed (Period Closing) is no longer offered here: it moves to the Monthly Reports.
 *   Monthly Reports     SSS / ECC, Pag-IBIG, PhilHealth (employee and employer shares), SSS loans,
 *                       Pag-IBIG loans, withholding tax, from the closed periods of a month. The
 *                       employer share is computed only when every period of the month is closed.
 *   YTD Reports         any report per payroll period and per employee over a date coverage (detail),
 *                       or one line per employee (summary), from the finalized payroll runs.
 *   v16: AUL Frequency Listing: how many times each employee was absent, late and undertime in each
 *   payroll period (from the daily time records), in the YTD Reports (date coverage) and, with the
 *   AUL Transaction Listing, in the Periodical Reports (the open payroll period selected).
 *   Bank Deposit / Cash Payment Listing   net pay per employee of a payroll run, paid by bank or in cash.
 *
 * Filters: Company / Branch, Division / Department (organizational unit and everything under it),
 * Employee Group, Class Group, Employee Type, an employee no. range and a code.
 */

const PRPT_TYPES = [
    'sss' => 'SSS / ECC Contributions',
    'hdmf' => 'Pag-IBIG Contributions',
    'phic' => 'Health Care (PhilHealth) Contributions',
    'sss_loan' => 'SSS Loan Report',
    'hdmf_loan' => 'Pag-IBIG Loan Report',
    'wtax' => 'Withholding Tax Report',
    'deductions' => 'Deduction Listing',
    'earnings' => 'Earning Listing',
    'register' => 'Payroll Register',
    'transaction' => 'Payroll Transaction (hours)',
    'aul' => 'AUL Transaction Listing (Absent, Undertime, Late)',
    'aul_freq' => 'AUL Frequency Listing (times Absent, Late, Undertime)',
    'bank' => 'Bank Deposit Listing',
    'cash' => 'Cash Payment Listing',
];

/** Report types of each report menu. */
const PRPT_MENU = [
    'periodical' => ['wtax', 'sss', 'phic', 'hdmf', 'sss_loan', 'hdmf_loan', 'deductions', 'earnings', 'bank', 'cash', 'aul', 'aul_freq'],
    'monthly' => ['sss', 'hdmf', 'phic', 'sss_loan', 'hdmf_loan', 'wtax'],
    'ytd' => ['register', 'sss', 'hdmf', 'phic', 'sss_loan', 'hdmf_loan', 'wtax', 'deductions', 'earnings', 'transaction', 'aul', 'aul_freq'],
];

/** Source of a non-salary payroll line (what the Earnings & Deductions listings are made of). */
const PRPT_SOURCES = [
    'fixed_trans' => 'Fixed Transaction', 'employee_transaction' => "Employee's Transaction", 'term_trans' => "Employee's Transaction (terms)",
    'employee_deduction' => 'Employee Deduction', 'ledger' => 'Loan / Subsidiary Ledger', 'benefit_type' => 'Employee Benefit', 'pending_earning' => 'Queued earning',
];

/** Filters read from the request. */
function prpt_filters(): array
{
    $f = [];
    foreach (['branch_id', 'org_unit_id', 'employee_group_id', 'class_group_id', 'employee_type_id'] as $k) {
        $f[$k] = (int)get($k, 0) ?: null;
    }
    $f['emp_from'] = trim((string)get('emp_from', '')) ?: null;
    $f['emp_to'] = trim((string)get('emp_to', '')) ?: null;
    $f['code'] = trim((string)get('code', '')) ?: null;
    return $f;
}

/**
 * Payroll items of a scope with their employee, run, branch and unit.
 *   ['period' => cutoff id]      runs of an open period (computed or finalized, not yet closed)
 *   ['month' => 'YYYY-MM']       closed (posted) items of the finalized runs whose period ends in the month
 *   ['from' => d, 'to' => d]     finalized runs whose period ends in the date coverage ('any' => true: computed ones too)
 *   ['run' => run id]            one payroll run
 */
function prpt_items(array $scope, array $f): array
{
    $w = ["r.status <> 'cancelled'"];
    $p = [];
    if (!empty($scope['run'])) {
        $w[] = 'r.id = ?';
        $p[] = (int)$scope['run'];
    } elseif (!empty($scope['period'])) {
        $w[] = "r.cutoff_id = ? AND r.status IN ('computed','finalized')";
        $p[] = (int)$scope['period'];
    } elseif (!empty($scope['month'])) {
        $w[] = "r.status = 'finalized' AND pi.posted_at IS NOT NULL AND r.period_end BETWEEN ? AND ?";
        $p[] = $scope['month'] . '-01';
        $p[] = date('Y-m-t', strtotime($scope['month'] . '-01'));
    } else {
        $w[] = (!empty($scope['any']) ? "r.status IN ('computed','finalized')" : "r.status = 'finalized'") . ' AND r.period_end BETWEEN ? AND ?';
        $p[] = $scope['from'];
        $p[] = $scope['to'];
    }
    if (!empty($f['branch_id'])) {
        $w[] = 'pi.default_branch_id = ?';
        $p[] = (int)$f['branch_id'];
    }
    if (!empty($f['org_unit_id'])) {
        $w[] = 'pi.org_unit_id IN (' . implode(',', array_map('intval', org_descendant_ids((int)$f['org_unit_id']))) . ')';
    }
    foreach (['employee_group_id', 'class_group_id', 'employee_type_id'] as $k) {
        if (!empty($f[$k])) {
            $w[] = "e.$k = ?";
            $p[] = (int)$f[$k];
        }
    }
    if (!empty($f['emp_from'])) {
        $w[] = 'e.employee_no >= ?';
        $p[] = $f['emp_from'];
    }
    if (!empty($f['emp_to'])) {
        $w[] = 'e.employee_no <= ?';
        $p[] = $f['emp_to'];
    }
    return rows('SELECT pi.*, r.run_no, r.run_type, r.period_start, r.period_end, r.pay_date, r.cutoff_id, r.pay_group_id, r.status AS run_status,
                        e.employee_no, e.first_name, e.last_name, e.middle_name, e.suffix, e.sss_no, e.philhealth_no, e.pagibig_no, e.tin,
                        e.bank_name, e.bank_account_no, e.bank_account_type,
                        b.code AS branch_code, b.name AS branch_name, b.company_name, ou.name AS unit_name
                 FROM payroll_items pi JOIN payroll_runs r ON r.id = pi.run_id JOIN employees e ON e.id = pi.employee_id
                 LEFT JOIN branches b ON b.id = pi.default_branch_id LEFT JOIN org_units ou ON ou.id = pi.org_unit_id
                 WHERE ' . implode(' AND ', $w) . user_scope_sql('e') . '
                 ORDER BY b.company_name, b.code, ou.name, e.last_name, e.first_name, e.employee_no, r.period_end, r.id', $p);
}

/** Lines of the items, keyed by item id. */
function prpt_lines(array $items): array
{
    $ids = array_map(fn($i) => (int)$i['id'], $items);
    $out = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        foreach (rows('SELECT * FROM payroll_item_lines WHERE item_id IN (' . in_list($chunk) . ') ORDER BY item_id, sort_order, id', $chunk) as $l) {
            $out[(int)$l['item_id']][] = $l;
        }
    }
    return $out;
}

/** Document / reference number of a line (term transaction doc no., ledger reference, ...). */
function prpt_line_doc(array $l): string
{
    static $cache = [];
    $key = $l['ref_type'] . ':' . $l['ref_id'];
    if (!$l['ref_id']) {
        return '';
    }
    if (!array_key_exists($key, $cache)) {
        switch ($l['ref_type']) {
            case 'term_trans':
                $cache[$key] = (string)val('SELECT doc_no FROM employee_term_trans WHERE id = ?', [$l['ref_id']]);
                break;
            case 'ledger':
                $cache[$key] = (string)(val("SELECT COALESCE(NULLIF(reference_no, ''), ledger_no) FROM ledgers WHERE id = ?", [$l['ref_id']]) ?: '');
                break;
            case 'employee_deduction':
                $cache[$key] = (string)(val("SELECT COALESCE(NULLIF(l.reference_no, ''), l.ledger_no) FROM employee_deductions d LEFT JOIN ledgers l ON l.id = d.ledger_id WHERE d.id = ?", [$l['ref_id']]) ?: '');
                break;
            default:
                $cache[$key] = '';
        }
    }
    return $cache[$key];
}

/** Description of a listing line without the installment counter and without a repeated name ("Company Loan — Company Loan"). */
function prpt_clean_desc(string $d): string
{
    $d = trim(preg_replace('/\s+[—-]\s+\d+(st|nd|rd|th) of \d+.*$/u', '', $d));
    $parts = array_values(array_unique(array_map('trim', preg_split('/\s+—\s+/u', $d))));
    return implode(' — ', array_filter($parts, fn($x) => $x !== ''));
}

/** Principal / interest split of a deduction line (installments of a transaction with terms, add-on interest of a ledger). */
function prpt_line_split(array $l, array $item): array
{
    $amt = (float)$l['amount'];
    if ($l['ref_type'] === 'term_trans' && $l['ref_id']) {
        $s = row('SELECT principal, interest, amount FROM employee_term_schedule WHERE term_id = ? AND payroll_run_id = ? ORDER BY seq_no LIMIT 1', [$l['ref_id'], $item['run_id']]);
        if ($s && (float)$s['amount'] > 0) {
            $int = round($amt * (float)$s['interest'] / (float)$s['amount'], 2);
            return [round($amt - $int, 2), $int];
        }
    }
    if ($l['ref_type'] === 'ledger' && $l['ref_id']) {
        $g = row('SELECT principal, total_payable FROM ledgers WHERE id = ?', [$l['ref_id']]);
        if ($g && (float)$g['total_payable'] > (float)$g['principal'] && (float)$g['total_payable'] > 0) {
            $int = round($amt * ((float)$g['total_payable'] - (float)$g['principal']) / (float)$g['total_payable'], 2);
            return [round($amt - $int, 2), $int];
        }
    }
    return [$amt, 0.0];
}

/** Is a payroll line a non-salary item of the listings (fixed transaction, employee's transaction, deduction, loan, benefit)? */
function prpt_is_listing_line(array $l, string $type): bool
{
    if (empty($l['ref_type']) || !isset(PRPT_SOURCES[$l['ref_type']])) {
        return false;
    }
    if ($l['line_type'] !== $type || (float)$l['amount'] == 0.0 || $l['code'] === 'WTAX_X') {
        return false;
    }
    return true;
}

/** Is the month complete: every period of the pay codes paid in it is closed (paid)? Returns [complete, open periods]. */
function prpt_month_status(string $ym, array $items): array
{
    $pgs = array_unique(array_map(fn($i) => (int)$i['pay_group_id'], array_filter($items, fn($i) => isset($i['pay_group_id']))));
    $first = $ym . '-01';
    $last = date('Y-m-t', strtotime($first));
    $open = rows("SELECT c.*, pg.code AS pay_group_code FROM attendance_cutoffs c JOIN pay_groups pg ON pg.id = c.pay_group_id
                  WHERE c.period_end BETWEEN ? AND ? AND c.status <> 'locked'" . ($pgs ? ' AND c.pay_group_id IN (' . implode(',', $pgs) . ')' : '') . ' ORDER BY c.period_end', [$first, $last]);
    $any = (int)val('SELECT COUNT(*) FROM attendance_cutoffs WHERE period_end BETWEEN ? AND ?', [$first, $last]);
    return [$any > 0 && !$open && $items, $open];
}

/** Columns of a report type: key => [label, kind]; kind num (money), int, txt. */
function prpt_columns(string $type, bool $withEr): array
{
    switch ($type) {
        case 'sss':
            return ['idno' => ['SSS Number', 'txt'], 'ee' => ['Employee', 'num']] + ($withEr ? ['er' => ['Employer', 'num'], 'ec' => ['ECC', 'num'], 'total' => ['Total', 'num']] : []);
        case 'hdmf':
            return ['idno' => ['Pag-IBIG No.', 'txt'], 'ee' => ['Employee', 'num']] + ($withEr ? ['er' => ['Employer', 'num'], 'total' => ['Total', 'num']] : []);
        case 'phic':
            return ['idno' => ['PhilHealth No.', 'txt'], 'ee' => ['Employee', 'num']] + ($withEr ? ['er' => ['Employer', 'num'], 'total' => ['Total', 'num']] : []);
        case 'wtax':
            return ['idno' => ['TIN', 'txt'], 'gross' => ['Gross pay', 'num'], 'taxable' => ['Taxable income', 'num'], 'tax' => ['Tax withheld', 'num']];
        case 'sss_loan': case 'hdmf_loan':
            return ['desc' => ['Loan', 'txt'], 'doc' => ['Reference', 'txt'], 'principal' => ['Principal', 'num'], 'interest' => ['Interest', 'num'], 'amount' => ['Amount', 'num']];
        case 'deductions':
            return ['desc' => ['Deduction', 'txt'], 'source' => ['Source', 'txt'], 'doc' => ['Doc. No.', 'txt'], 'principal' => ['Principal', 'num'], 'interest' => ['Interest', 'num'], 'amount' => ['Amount', 'num']];
        case 'earnings':
            return ['desc' => ['Earning', 'txt'], 'source' => ['Source', 'txt'], 'doc' => ['Doc. No.', 'txt'], 'amount' => ['Amount', 'num']];
        case 'register':
            return ['basic' => ['Basic', 'num'], 'otnp' => ['OT / NP', 'num'], 'hol' => ['Legal / Spcl holidays', 'num'], 'other' => ['Other income', 'num'], 'thirteenth' => ['13th month', 'num'],
                'gross' => ['Gross pay', 'num'], 'aul' => ['Abs / Late / UT', 'num'], 'phic' => ['PhilHealth', 'num'], 'sss' => ['SSS', 'num'], 'sss_loan' => ['SSS loan', 'num'],
                'hdmf' => ['Pag-IBIG', 'num'], 'hdmf_loan' => ['Pag-IBIG loan', 'num'], 'other_ded' => ['Other deductions', 'num'], 'tax' => ['Tax', 'num'], 'total_ded' => ['Total deductions', 'num'], 'net' => ['Net pay', 'num']];
        case 'transaction':
            return ['work' => ['Hours work', 'num'], 'absent' => ['Absent', 'num'], 'late' => ['Late', 'num'], 'ut' => ['Undertime', 'num'], 'vl' => ['VL', 'num'], 'sl' => ['SL', 'num'],
                'oleave' => ['Other leave', 'num'], 'hol' => ['Holiday', 'num'], 'ot' => ['OT', 'num'], 'np' => ['NP', 'num']];
        case 'aul':
            return ['abs_days' => ['Absent (days)', 'num'], 'abs_amt' => ['Absent amount', 'num'], 'late_min' => ['Late (min)', 'int'], 'late_amt' => ['Late amount', 'num'],
                'ut_min' => ['Undertime (min)', 'int'], 'ut_amt' => ['Undertime amount', 'num'], 'total' => ['Total AUL', 'num']];
        case 'aul_freq':
            return ['abs_freq' => ['Absent (frequency)', 'int'], 'abs_days' => ['Absent (days)', 'num'], 'late_freq' => ['Late (frequency)', 'int'], 'late_min' => ['Late (min)', 'int'],
                'ut_freq' => ['Undertime (frequency)', 'int'], 'ut_min' => ['Undertime (min)', 'int'], 'total_freq' => ['Total frequency', 'int']];
        case 'bank':
            return ['bank' => ['Bank', 'txt'], 'acct' => ['Bank Acct', 'txt'], 'net' => ['Net pay', 'num']];
        case 'cash':
            return ['net' => ['Net pay', 'num']];
    }
    return [];
}

/** Is a report type a listing of items (several lines per employee) rather than one line per employee? */
function prpt_is_listing(string $type): bool
{
    return in_array($type, ['sss_loan', 'hdmf_loan', 'deductions', 'earnings'], true);
}

/** Is an employee paid through the bank (an account number not marked cash)? */
function prpt_is_bank(array $i): bool
{
    return trim((string)$i['bank_account_no']) !== '' && strtolower((string)$i['bank_account_type']) !== 'cash';
}

/** Values of one payroll item for the per-employee report types. */
function prpt_item_values(string $type, array $i, array $lines, bool $withEr): array
{
    $sum = function (string $col) use ($lines) {
        $t = 0.0;
        foreach ($lines as $l) {
            if ($l['line_type'] === 'deduction' && reg_col_of_line($l) === $col) {
                $t += (float)$l['amount'];
            }
        }
        return round($t, 2);
    };
    switch ($type) {
        case 'sss':
            $v = ['idno' => (string)$i['sss_no'], 'ee' => (float)$i['sss_ee']];
            return $v + ($withEr ? ['er' => (float)$i['sss_er'], 'ec' => (float)$i['sss_ec'], 'total' => round((float)$i['sss_ee'] + (float)$i['sss_er'] + (float)$i['sss_ec'], 2)] : []);
        case 'hdmf':
            $ee = round((float)$i['hdmf_ee'] + (float)$i['hdmf_ee_voluntary'], 2);
            return ['idno' => (string)$i['pagibig_no'], 'ee' => $ee] + ($withEr ? ['er' => (float)$i['hdmf_er'], 'total' => round($ee + (float)$i['hdmf_er'], 2)] : []);
        case 'phic':
            return ['idno' => (string)$i['philhealth_no'], 'ee' => (float)$i['phic_ee']] + ($withEr ? ['er' => (float)$i['phic_er'], 'total' => round((float)$i['phic_ee'] + (float)$i['phic_er'], 2)] : []);
        case 'wtax':
            return ['idno' => (string)$i['tin'], 'gross' => (float)$i['gross_pay'], 'taxable' => (float)$i['taxable_income'], 'tax' => (float)$i['withholding_tax']];
        case 'register':
            $aul = round((float)$i['absence_deduction'] + (float)$i['late_deduction'] + (float)$i['undertime_deduction'], 2);
            $th = 0.0;
            foreach ($lines as $l) {
                if ($l['line_type'] === 'earning' && $l['code'] === '13TH') {
                    $th += (float)$l['amount'];
                }
            }
            $gross = round((float)$i['gross_pay'] + $aul, 2);
            $otnp = round((float)$i['ot_pay'] + (float)$i['nd_pay'], 2);
            $hol = round((float)$i['holiday_pay'] + (float)$i['premium_pay'], 2);
            $sl = $sum('sss_loan');
            $hl = $sum('hdmf_loan');
            $hdmf = round((float)$i['hdmf_ee'] + (float)$i['hdmf_ee_voluntary'], 2);
            $other = round($gross - (float)$i['basic_pay'] - $otnp - $hol - $th, 2);
            $otherDed = round((float)$i['total_deductions'] - (float)$i['sss_ee'] - (float)$i['phic_ee'] - $hdmf - (float)$i['withholding_tax'] - $sl - $hl, 2);
            return ['basic' => (float)$i['basic_pay'], 'otnp' => $otnp, 'hol' => $hol, 'other' => $other, 'thirteenth' => round($th, 2), 'gross' => $gross, 'aul' => $aul,
                'phic' => (float)$i['phic_ee'], 'sss' => (float)$i['sss_ee'], 'sss_loan' => $sl, 'hdmf' => $hdmf, 'hdmf_loan' => $hl, 'other_ded' => $otherDed,
                'tax' => (float)$i['withholding_tax'], 'total_ded' => round((float)$i['total_deductions'] + $aul, 2), 'net' => (float)$i['net_pay']];
        case 'transaction':
            $s = $i['summary_id'] ? row('SELECT * FROM attendance_summaries WHERE id = ?', [$i['summary_id']]) : null;
            if (!$s && $i['cutoff_id']) {
                $s = row('SELECT * FROM attendance_summaries WHERE cutoff_id = ? AND employee_id = ?', [$i['cutoff_id'], $i['employee_id']]);
            }
            $g = fn(array $keys) => $s ? round(array_sum(array_map(fn($k) => (float)($s[$k] ?? 0), $keys)), 2) : 0.0;
            return ['work' => $g(['hours_work']), 'absent' => $g(['absent_hrs']), 'late' => $g(['late_hrs']), 'ut' => $g(['undertime_hrs']), 'vl' => $g(['vl_hrs']), 'sl' => $g(['sl_hrs']),
                'oleave' => $g(['el_hrs', 'ol_hrs', 'pm_hrs']), 'hol' => $g(['holiday_hrs']), 'ot' => $g(['ot_reg', 'rd_hrs', 'rd_ot', 'lh_ot', 'sh_ot', 'lh_rd', 'sh_rd', 'lh_rd_ot', 'sh_rd_ot', 'lh_hrs', 'sh_hrs']),
                'np' => $g(['np_reg', 'np_rd', 'np_lh', 'np_sh', 'np_reg_ot', 'np_rd_ot', 'np_lh_ot', 'np_sh_ot', 'np_lh_rd', 'np_sh_rd', 'np_lh_rd_ot', 'np_sh_rd_ot'])];
        case 'aul':
            return ['abs_days' => (float)$i['absent_days'], 'abs_amt' => (float)$i['absence_deduction'], 'late_min' => (int)$i['late_minutes'], 'late_amt' => (float)$i['late_deduction'],
                'ut_min' => (int)$i['undertime_minutes'], 'ut_amt' => (float)$i['undertime_deduction'],
                'total' => round((float)$i['absence_deduction'] + (float)$i['late_deduction'] + (float)$i['undertime_deduction'], 2)];
        case 'aul_freq':
            return prpt_aul_frequency((int)$i['employee_id'], (string)$i['period_start'], (string)$i['period_end']);
        case 'bank':
            return ['bank' => (string)$i['bank_name'], 'acct' => (string)$i['bank_account_no'], 'net' => (float)$i['net_pay']];
        case 'cash':
            return ['net' => (float)$i['net_pay']];
    }
    return [];
}

/**
 * How many times an employee was absent, late and undertime in a date range, from the daily time
 * records: a day with absent minutes (whole or part of the day) counts once as absent, a day late
 * beyond the grace period once as late, a day with undertime once as undertime.
 */
function prpt_aul_frequency(int $empId, string $from, string $to): array
{
    static $cache = [];
    $k = "$empId|$from|$to";
    if (!isset($cache[$k])) {
        $grace = (int)setting('grace_minutes', 0);
        $hpd = max(1, (float)setting('hours_per_day', 8));
        $r = row("SELECT SUM(d.absent_minutes > 0) AS abs_freq, COALESCE(SUM(d.absent_minutes), 0) AS abs_min,
                         SUM(d.status = 'present' AND d.late_minutes > IF(s.strict_time = 1, 0, COALESCE(s.grace_minutes, ?))) AS late_freq,
                         COALESCE(SUM(IF(d.status = 'present', d.late_minutes, 0)), 0) AS late_min,
                         SUM(d.status = 'present' AND d.undertime_minutes > 0) AS ut_freq, COALESCE(SUM(IF(d.status = 'present', d.undertime_minutes, 0)), 0) AS ut_min
                  FROM dtr d LEFT JOIN shifts s ON s.id = d.shift_id WHERE d.employee_id = ? AND d.work_date BETWEEN ? AND ?", [$grace, $empId, $from, $to]) ?: [];
        $v = ['abs_freq' => (int)($r['abs_freq'] ?? 0), 'abs_days' => round((float)($r['abs_min'] ?? 0) / ($hpd * 60), 2), 'late_freq' => (int)($r['late_freq'] ?? 0),
            'late_min' => (int)($r['late_min'] ?? 0), 'ut_freq' => (int)($r['ut_freq'] ?? 0), 'ut_min' => (int)($r['ut_min'] ?? 0)];
        $v['total_freq'] = $v['abs_freq'] + $v['late_freq'] + $v['ut_freq'];
        $cache[$k] = $v;
    }
    return $cache[$k];
}

/** Listing lines of one payroll item (loans, deductions, earnings). */
function prpt_item_listing(string $type, array $i, array $lines, ?string $code): array
{
    $out = [];
    foreach ($lines as $l) {
        if ($code !== null && strcasecmp((string)$l['code'], $code) !== 0) {
            continue;
        }
        if (in_array($type, ['sss_loan', 'hdmf_loan'], true)) {
            if ($l['line_type'] !== 'deduction' || reg_col_of_line($l) !== $type || (float)$l['amount'] == 0.0) {
                continue;
            }
        } elseif (!prpt_is_listing_line($l, $type === 'earnings' ? 'earning' : 'deduction')) {
            continue;
        }
        $desc = prpt_clean_desc((string)$l['description']);
        $row = ['desc' => $desc !== '' ? $desc : (string)$l['code'], 'code' => (string)$l['code'], 'source' => PRPT_SOURCES[$l['ref_type']] ?? '', 'doc' => prpt_line_doc($l), 'amount' => round((float)$l['amount'], 2)];
        if ($type !== 'earnings') {
            [$row['principal'], $row['interest']] = prpt_line_split($l, $i);
        }
        $out[] = $row;
    }
    return $out;
}

/**
 * Build a report: employees grouped by company / branch and department, each with lines (one per
 * payroll period in detail mode, one per item in the listings) and totals.
 * $mode: summary (one line per employee) | detail (one line per payroll period).
 */
function prpt_build(string $type, array $items, array $opt = []): array
{
    $withEr = !empty($opt['with_er']);
    $detail = ($opt['mode'] ?? 'summary') === 'detail';
    $code = $opt['code'] ?? null;
    $cols = prpt_columns($type, $withEr);
    $numCols = array_keys(array_filter($cols, fn($c) => $c[1] !== 'txt'));
    $lines = prpt_lines($items);
    $listing = prpt_is_listing($type);
    $emps = [];
    foreach ($items as $i) {
        if ($type === 'bank' && !prpt_is_bank($i)) {
            continue;
        }
        if ($type === 'cash' && prpt_is_bank($i)) {
            continue;
        }
        $eid = (int)$i['employee_id'];
        $gk = trim(($i['company_name'] ?: $i['branch_name'] ?: 'No company')) . "\x1f" . ($i['branch_code'] ? $i['branch_code'] . ' ' . $i['branch_name'] : '') . "\x1f" . ($i['unit_name'] ?: 'Unassigned');
        if (!isset($emps[$gk][$eid])) {
            $emps[$gk][$eid] = ['emp' => $i, 'lines' => [], 'tot' => array_fill_keys($numCols, 0.0), 'text' => []];
        }
        $e = &$emps[$gk][$eid];
        $period = $i['run_no'] . ', ' . fmt_date($i['period_start'], 'm/d/y') . ' - ' . fmt_date($i['period_end'], 'm/d/y');
        if ($listing) {
            foreach (prpt_item_listing($type, $i, $lines[(int)$i['id']] ?? [], $code) as $r) {
                $r['period'] = $period;
                $e['lines'][] = $r;
                foreach ($numCols as $k) {
                    $e['tot'][$k] += (float)($r[$k] ?? 0);
                }
            }
        } else {
            $v = prpt_item_values($type, $i, $lines[(int)$i['id']] ?? [], $withEr);
            foreach ($cols as $k => $c) {
                if ($c[1] === 'txt') {
                    $e['text'][$k] = $v[$k] ?? '';
                } else {
                    $e['tot'][$k] += (float)($v[$k] ?? 0);
                }
            }
            if ($detail) {
                $v['period'] = $period;
                $e['lines'][] = $v;
            }
        }
        unset($e);
    }
    // drop employees without anything to show, round, group totals
    $groups = [];
    $grand = array_fill_keys($numCols, 0.0);
    $count = 0;
    foreach ($emps as $gk => $list) {
        foreach ($list as $eid => $e) {
            if (($listing && !$e['lines']) || (!$listing && !array_filter($e['tot'], fn($x) => abs($x) > 0.0001) && !in_array($type, ['bank', 'cash'], true))) {
                continue;
            }
            $e['tot'] = array_map(fn($x) => round($x, 2), $e['tot']);
            [$co, $br, $unit] = explode("\x1f", $gk);
            $key = $co . "\x1f" . $br;
            $groups[$key]['company'] = $co;
            $groups[$key]['branch'] = $br;
            $groups[$key]['units'][$unit]['emps'][] = $e;
            foreach ($numCols as $k) {
                $groups[$key]['units'][$unit]['tot'][$k] = round(($groups[$key]['units'][$unit]['tot'][$k] ?? 0) + $e['tot'][$k], 2);
                $groups[$key]['tot'][$k] = round(($groups[$key]['tot'][$k] ?? 0) + $e['tot'][$k], 2);
                $grand[$k] = round($grand[$k] + $e['tot'][$k], 2);
            }
            $count++;
        }
    }
    return ['type' => $type, 'title' => PRPT_TYPES[$type] ?? $type, 'cols' => $cols, 'num' => $numCols, 'groups' => $groups, 'grand' => $grand,
        'employees' => $count, 'listing' => $listing, 'detail' => $detail || $listing, 'with_er' => $withEr];
}

/** Format a report value. */
function prpt_fmt($v, string $kind): string
{
    if ($kind === 'txt') {
        return h((string)$v);
    }
    if ($kind === 'int') {
        return (int)$v ? number_format((int)$v) : '';
    }
    return abs((float)$v) > 0.004 ? number_format((float)$v, 2) : '0.00';
}

/** HTML of a built report (screen and print). */
function prpt_html(array $rep, string $id = ''): string
{
    $cols = $rep['cols'];
    $textCols = array_filter($cols, fn($c) => $c[1] === 'txt');
    $h = '<div class="table-wrap prpt-wrap"><table class="table prpt-table' . ($rep['detail'] ? ' prpt-detail' : '') . '"' . ($id !== '' ? ' id="' . h($id) . '"' : '') . '><thead><tr><th class="nowrap">Emp. ID</th><th>Employee\'s Name</th>';
    if ($rep['detail'] && !$rep['listing']) {
        $h .= '<th>Payroll period</th>';
    } elseif ($rep['listing']) {
        $h .= '<th>Payroll</th>';
    }
    foreach ($cols as $k => $c) {
        $h .= '<th class="' . ($c[1] === 'txt' ? '' : 'num') . '">' . h($c[0]) . '</th>';
    }
    $h .= '</tr></thead><tbody>';
    $span = 2 + ($rep['detail'] ? 1 : 0) + count($cols);
    $totRow = function (string $label, array $tot, string $cls) use ($cols, $rep) {
        $r = '<tr class="' . $cls . '"><td colspan="' . (2 + ($rep['detail'] ? 1 : 0)) . '">' . h($label) . '</td>';
        foreach ($cols as $k => $c) {
            $r .= '<td class="' . ($c[1] === 'txt' ? '' : 'num') . '">' . ($c[1] === 'txt' ? '' : prpt_fmt($tot[$k] ?? 0, $c[1])) . '</td>';
        }
        return $r . '</tr>';
    };
    foreach ($rep['groups'] as $g) {
        $h .= '<tr class="prpt-co"><td colspan="' . $span . '">' . h($g['company']) . ($g['branch'] ? ' <span class="muted">' . h($g['branch']) . '</span>' : '') . '</td></tr>';
        foreach ($g['units'] as $unit => $u) {
            $h .= '<tr class="prpt-unit"><td colspan="' . $span . '">' . h($unit) . '</td></tr>';
            foreach ($u['emps'] as $e) {
                $emp = $e['emp'];
                $name = h(strtoupper($emp['last_name']) . ', ' . $emp['first_name'] . ($emp['middle_name'] ? ' ' . mb_substr($emp['middle_name'], 0, 1) . '.' : ''));
                if (!$rep['detail']) {
                    $h .= '<tr><td class="nowrap">' . h($emp['employee_no']) . '</td><td>' . $name . '</td>';
                    foreach ($cols as $k => $c) {
                        $h .= '<td class="' . ($c[1] === 'txt' ? 'nowrap' : 'num') . '">' . ($c[1] === 'txt' ? h($e['text'][$k] ?? '') : prpt_fmt($e['tot'][$k], $c[1])) . '</td>';
                    }
                    $h .= '</tr>';
                    continue;
                }
                $h .= '<tr class="prpt-emp"><td class="nowrap">' . h($emp['employee_no']) . '</td><td colspan="' . ($span - 1) . '">' . $name;
                foreach ($textCols as $k => $c) {
                    if (!empty($e['text'][$k])) {
                        $h .= ' <span class="muted small">' . h($c[0]) . ' ' . h($e['text'][$k]) . '</span>';
                    }
                }
                $h .= '</td></tr>';
                foreach ($e['lines'] as $l) {
                    $h .= '<tr class="prpt-line"><td></td><td></td><td class="small nowrap">' . h($l['period'] ?? '') . '</td>';
                    foreach ($cols as $k => $c) {
                        $h .= '<td class="' . ($c[1] === 'txt' ? 'small' : 'num') . '">' . prpt_fmt($l[$k] ?? ($c[1] === 'txt' ? '' : 0), $c[1]) . '</td>';
                    }
                    $h .= '</tr>';
                }
                $h .= $totRow('Totals', $e['tot'], 'prpt-etot');
            }
            if (count($rep['groups']) > 1 || count($g['units']) > 1) {
                $h .= $totRow('Sub-total ' . $unit, $u['tot'], 'prpt-utot');
            }
        }
        $h .= $totRow('Company totals ' . $g['company'], $g['tot'], 'prpt-ctot');
    }
    if (!$rep['groups']) {
        $h .= '<tr><td colspan="' . $span . '" class="empty">Nothing to report for this selection.</td></tr>';
    }
    $h .= '</tbody><tfoot>' . $totRow('Grand totals (' . $rep['employees'] . ' employee' . ($rep['employees'] === 1 ? '' : 's') . ')', $rep['grand'], 'prpt-grand') . '</tfoot></table></div>';
    return $h;
}

/** CSV of a built report: one row per line (or per employee in summary). */
function prpt_csv(array $rep): array
{
    $head = ['Company', 'Branch', 'Department', 'Emp. ID', 'Employee'];
    if ($rep['detail']) {
        $head[] = 'Payroll period';
    }
    foreach ($rep['cols'] as $c) {
        $head[] = $c[0];
    }
    $out = [];
    foreach ($rep['groups'] as $g) {
        foreach ($g['units'] as $unit => $u) {
            foreach ($u['emps'] as $e) {
                $base = [$g['company'], $g['branch'], $unit, $e['emp']['employee_no'], full_name($e['emp'])];
                if (!$rep['detail']) {
                    $r = $base;
                    foreach ($rep['cols'] as $k => $c) {
                        $r[] = $c[1] === 'txt' ? ($e['text'][$k] ?? '') : $e['tot'][$k];
                    }
                    $out[] = $r;
                    continue;
                }
                foreach ($e['lines'] as $l) {
                    $r = array_merge($base, [$l['period'] ?? '']);
                    foreach ($rep['cols'] as $k => $c) {
                        $r[] = $l[$k] ?? '';
                    }
                    $out[] = $r;
                }
            }
        }
    }
    return [$head, $out];
}
