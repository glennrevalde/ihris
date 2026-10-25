<?php
/**
 * CSV imports of the Payroll System (PAY) and the Attendance Monitoring System (AMS).
 *
 *   pay_trans    Payroll Transaction: the hours of each employee for a period      -> attendance_summaries
 *   att_batch    Attendance Batch Transaction: the same record, from the AMS side  -> attendance_summaries
 *   emp_trans    Employee's Transactions: one-time items, or items with terms       -> employee_transactions / employee_term_trans
 *   fixed_trans  Employee's Fixed Transaction Setup                                -> employee_fixed_trans
 *   deductions   Employee Deductions                                               -> employee_deductions
 *   benefits     Employee Benefits                                                 -> employee_benefits
 *
 * The flow is the same for all of them: download the format, load the file, Check data (every row
 * is validated and every wrong cell is reported), correct the cells on screen or in the file, check
 * again, then Save to database. Nothing is written until the whole file checks clean. Transactions
 * of a period can keep, or replace, the entries already encoded for that period.
 *
 * The loaded rows live in the session between the steps: $_SESSION['csvi'][kind].
 */

const CSVI_MAX_ROWS = 3000;

/** Every import: title, route, the screen it returns to, access module and the options it offers. */
function csvi_kinds(): array
{
    return [
        'pay_trans' => ['title' => 'Import Payroll Transaction', 'route' => 'import_pay_trans', 'return' => 'pay_trans', 'return_label' => 'Payroll Transaction',
            'period' => true, 'replace' => true, 'update' => false,
            'what' => 'the hours transaction of each employee for one pay period: hours work, absences, lates, leaves, overtime, holiday and night premium hours, and the amounts under Others. It is the same record as Payroll Transaction, Payroll Transaction II and Payroll Batch Transaction.'],
        'att_batch' => ['title' => 'Import Attendance Batch Transaction', 'route' => 'import_att_batch', 'return' => 'att_batch', 'return_label' => 'Attendance Batch Transaction Entry',
            'period' => true, 'replace' => true, 'update' => false,
            'what' => 'the attendance summary of each employee for one period, in the columns of the Attendance Batch Transaction Entry. It is the same record payroll reads, so it also appears in Payroll Transaction, Payroll Transaction II and Payroll Batch Transaction.'],
        'emp_trans' => ['title' => "Import Employee's Transactions", 'route' => 'import_emp_trans', 'return' => 'emp_transactions', 'return_label' => "Employee's Transactions",
            'period' => true, 'replace' => false, 'update' => false,
            'what' => 'earnings and deductions per employee: a one-time item for the selected period (Terms blank or 1), or a transaction with terms spread over several paydays with interest (Terms 2 or more).'],
        'fixed_trans' => ['title' => "Import Employee's Fixed Transaction Setup", 'route' => 'import_fixed_trans', 'return' => 'fixed_trans', 'return_label' => 'Fixed Transaction Setup',
            'period' => false, 'replace' => false, 'update' => true,
            'what' => 'recurring fixed amounts per employee (allowances, fixed contributions, fixed tax) with the terms that decide on which pay they are taken.'],
        'deductions' => ['title' => 'Import Employee Deductions', 'route' => 'import_deductions', 'return' => 'emp_deductions', 'return_label' => 'Employee Deductions',
            'period' => false, 'replace' => false, 'update' => false,
            'what' => 'recurring and ad hoc deductions per employee (cooperative, union dues, uniform, canteen and similar). Loans and accountabilities that run on a subsidiary ledger are created in Employee Term Loan/Payables instead.'],
        'benefits' => ['title' => 'Import Employee Benefits', 'route' => 'import_benefits', 'return' => 'emp_benefits', 'return_label' => 'Employee Benefits',
            'period' => false, 'replace' => false, 'update' => true,
            'what' => 'benefit enrollments per employee (rice subsidy, allowances, company benefits) with the amount per payment and the manner of posting: every pay, or the 1st, 2nd, 3rd or 4th pay of the month.'],
    ];
}

function csvi_kind_of_route(string $route): ?string
{
    foreach (csvi_kinds() as $k => $d) {
        if ($d['route'] === $route) {
            return $k;
        }
    }
    return null;
}

/**
 * Columns of each import: key => [label, required, help, example]. The key is the header of the
 * column in the file (case and spaces do not matter).
 */
function csvi_columns(string $kind): array
{
    $emp = [
        'employee_no'   => ['Employee No.', true, 'As in the Employee Masterfile.', 'EMP-0001'],
        'employee_name' => ['Employee name', false, 'For your reference only; not read.', 'DELA CRUZ, JUAN'],
    ];
    switch ($kind) {
        case 'pay_trans':
        case 'att_batch':
            $c = $emp + [
                'branch_code' => ['Company / branch / division', false, 'Code of the location where these hours were rendered. Put the same employee on several rows with different codes for a multi-location transaction; blank = a single location.', ''],
                'deduction_source' => ['Deduction source', false, 'Y on the row of the location that carries the deductions (multi-location only). Blank = the default employer.', ''],
            ];
            foreach (PT_FIELDS as $k => $meta) {
                $c[$k] = [$meta[0], false, $meta[2] === 'others' ? ($k === 'tickets' ? 'Count.' : 'Amount or count.') : 'Hours. Blank = 0.', $k === 'hours_work' ? '88' : ''];
            }
            $c['remarks'] = ['Remarks', false, 'Reason / reference of the figures.', 'Imported'];
            return $c;
        case 'emp_trans':
            return $emp + [
                'trans_type'        => ['Type', true, 'E or Earning, D or Deduction.', 'D'],
                'code'              => ['Transaction code', true, "Earning code (Transaction Table) or deduction code (Deduction Types).", 'ADHOC'],
                'amount'            => ['Amount', true, 'One-time amount, or the principal of a transaction with terms. More than zero.', '1500.00'],
                'remarks'           => ['Remarks', false, '', 'Salary deduction for shortage'],
                'terms'             => ['Terms (paydays)', false, 'Blank or 1 = one-time for the selected period. 2 or more = a transaction with terms.', '1'],
                'trans_term'        => ['Trans. term', false, 'every, 1st, 2nd, 3rd or 4th (pay of the month). Blank = every pay.', ''],
                'monthly_rate'      => ['Monthly interest rate (%)', false, 'Transactions with terms only.', ''],
                'interest_amount'   => ['Interest amount', false, 'Blank = computed from the rate.', ''],
                'per_pay_principal' => ['Trans/PD (principal)', false, 'Blank = computed.', ''],
                'per_pay_interest'  => ['Trans/PD (interest)', false, 'Blank = computed.', ''],
                'trans_date'        => ['Trans. date', false, 'YYYY-MM-DD or MM/DD/YYYY. Blank = today.', ''],
                'start_date'        => ['Start date', false, 'First payday on or after it takes an installment. Blank = start of the period.', ''],
                'doc_no'            => ['Document no.', false, 'Voucher or agency reference.', ''],
                'branch_code'       => ['Co. / Branch', false, 'Blank = the employee\'s branch.', ''],
            ];
        case 'fixed_trans':
            return $emp + [
                'code'       => ['Fixed transaction code', true, 'Code of the Fixed Transaction Table.', '11'],
                'amount'     => ['Amount', true, 'Zero or more.', '1500.00'],
                'terms'      => ['Terms', false, 'every_pay, 1st, 2nd, 3rd or 4th (pay of the month). Blank = the default of the code.', 'every_pay'],
                'start_date' => ['Start date', false, 'First payroll it applies to. Blank = today. There is no end date: it runs while the employee works.', ''],
                'status'     => ['Status', false, 'active, hold (paused) or stopped. Blank = active.', ''],
                'remarks'    => ['Remarks', false, '', ''],
            ];
        case 'deductions':
            return $emp + [
                'deduction_code'  => ['Deduction code', true, 'Code of the Deduction Types (not statutory, not a ledger loan).', 'COOP'],
                'amount'          => ['Amount per payroll', false, 'Blank = the default amount of the type; percent types take the percentage.', '500.00'],
                'start_date'      => ['Start date', false, 'Blank = today.', ''],
                'end_date'        => ['End date', false, 'Blank = no end.', ''],
                'written_consent' => ['Written consent on file', false, 'Y or N. Needed under Art. 113 unless the deduction is authorized by law.', 'Y'],
                'remarks'         => ['Remarks', false, '', ''],
            ];
        case 'benefits':
            return $emp + [
                'benefit_code' => ['Benefit code', true, 'Code of the Benefits table.', 'RICE'],
                'amount'       => ['Amount per payment', false, 'Blank = the default amount of the benefit.', ''],
                'post_term'    => ['Manner of posting', false, 'every, 1st, 2nd, 3rd or 4th (pay of the month). Blank = the frequency of the benefit.', '2nd'],
                'start_date'   => ['Start date', false, 'Blank = today.', ''],
                'end_date'     => ['End date', false, 'Blank = no end.', ''],
                'remarks'      => ['Remarks', false, '', ''],
            ];
    }
    return [];
}

/* ----------------------------------------------------------------------------------- reading */

/** Read a CSV file (UTF-8 or Windows-1252; comma, semicolon or tab) into rows keyed by the column keys. */
function csvi_read(string $path, string $kind): array
{
    $text = (string)file_get_contents($path);
    if (substr($text, 0, 3) === "\xEF\xBB\xBF") {
        $text = substr($text, 3);
    }
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }
    $first = '';
    foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $l) {
        if (trim($l) !== '') {
            $first = $l;
            break;
        }
    }
    $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
    $cols = csvi_columns($kind);
    // accept the key, or the label of a column, as the header
    $alias = [];
    foreach ($cols as $k => $c) {
        $alias[csvi_key($k)] = $k;
        $alias[csvi_key($c[0])] = $k;
    }
    $alias += ['emp_no' => 'employee_no', 'employee_number' => 'employee_no', 'id_no' => 'employee_no', 'name' => 'employee_name', 'branch' => 'branch_code',
        'type' => 'trans_type', 'deduction' => 'deduction_code', 'benefit' => 'benefit_code', 'consent' => 'written_consent', 'posting' => 'post_term'];
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $text);
    rewind($fh);
    $header = null;
    $rows = [];
    $unknown = [];
    $n = 0;
    while (($cells = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
        $n++;
        if ($cells === [null] || !array_filter($cells, fn($c) => trim((string)$c) !== '')) {
            continue;
        }
        if ($header === null) {
            $header = [];
            foreach ($cells as $i => $c) {
                $k = csvi_key((string)$c);
                if (isset($alias[$k])) {
                    $header[$i] = $alias[$k];
                } elseif ($k !== '') {
                    $unknown[] = trim((string)$c);
                }
            }
            if (!in_array('employee_no', $header, true)) {
                fclose($fh);
                throw new RuntimeException('The first line of the file must be the column headers of the format, starting with employee_no. Download the format and copy your data into it.');
            }
            continue;
        }
        $data = array_fill_keys(array_keys($cols), '');
        foreach ($header as $i => $k) {
            $data[$k] = trim((string)($cells[$i] ?? ''));
        }
        $rows[] = ['line' => $n, 'data' => $data, 'errors' => [], 'row_errors' => [], 'notes' => [], 'status' => 'unchecked'];
        if (count($rows) > CSVI_MAX_ROWS) {
            fclose($fh);
            throw new RuntimeException('The file has more than ' . number_format(CSVI_MAX_ROWS) . ' rows. Split it and import the parts one after the other.');
        }
    }
    fclose($fh);
    if ($header === null) {
        throw new RuntimeException('The file is empty.');
    }
    return ['rows' => $rows, 'unknown' => $unknown];
}

function csvi_key(string $s): string
{
    return strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '_', $s), '_'));
}

/** Date in the file: YYYY-MM-DD, MM/DD/YYYY, DD-Mon-YYYY or an Excel serial number. '' when blank, null when unreadable. */
function csvi_date(string $v): ?string
{
    $v = trim($v);
    if ($v === '') {
        return '';
    }
    if (preg_match('/^\d{5}(\.\d+)?$/', $v)) {
        return gmdate('Y-m-d', (int)(((float)$v - 25569) * 86400));
    }
    foreach (['Y-m-d', 'm/d/Y', 'n/j/Y', 'm/d/y', 'n/j/y', 'd-M-Y', 'd-M-y', 'Y/m/d', 'M d, Y'] as $f) {
        $d = DateTime::createFromFormat('!' . $f, $v);
        if ($d && $d->format($f) === $v) {
            return $d->format('Y-m-d');
        }
    }
    $t = strtotime($v);
    return $t ? date('Y-m-d', $t) : null;
}

/** Number in the file (thousand separators and a peso sign are allowed). '' when blank, null when not a number. */
function csvi_num(string $v): ?string
{
    $v = trim(str_replace([',', '₱', 'PHP', ' '], '', $v));
    if ($v === '') {
        return '';
    }
    return is_numeric($v) ? $v : null;
}

/** Y / N in the file. Returns 1, 0, or null when unreadable ('' = blank). */
function csvi_bool(string $v)
{
    $v = strtolower(trim($v));
    if ($v === '') {
        return '';
    }
    if (in_array($v, ['y', 'yes', '1', 'true', 'x'], true)) {
        return 1;
    }
    if (in_array($v, ['n', 'no', '0', 'false'], true)) {
        return 0;
    }
    return null;
}

/** "every", "1st", "2nd pay", "first_pay"... => a TERM_TRANS_TERMS key, '' when blank, null when unreadable. */
function csvi_pay_term(string $v): ?string
{
    $v = strtolower(trim($v));
    if ($v === '') {
        return '';
    }
    $v = preg_replace('/\s+(pay|payday|payroll|cutoff|cut-off)$/', '', $v);
    $map = ['every' => 'every_pay', 'every_pay' => 'every_pay', 'all' => 'every_pay', 'e' => 'every_pay',
        '1' => 'first_pay', '1st' => 'first_pay', 'first' => 'first_pay', 'first_pay' => 'first_pay',
        '2' => 'second_pay', '2nd' => 'second_pay', 'second' => 'second_pay', 'second_pay' => 'second_pay',
        '3' => 'third_pay', '3rd' => 'third_pay', 'third' => 'third_pay', 'third_pay' => 'third_pay',
        '4' => 'fourth_pay', '4th' => 'fourth_pay', 'fourth' => 'fourth_pay', 'fourth_pay' => 'fourth_pay'];
    return $map[$v] ?? null;
}

/* ----------------------------------------------------------------------------------- lookups */

function csvi_lookups(): array
{
    static $L = null;
    if ($L === null) {
        $L = ['emp' => [], 'branch' => [], 'earn' => [], 'ded' => [], 'fixed' => [], 'benefit' => []];
        // only the employees of the user's company / branch and department assignment (app/engine/scope.php)
        foreach (rows('SELECT e.* FROM employees e WHERE 1=1' . user_scope_sql('e')) as $e) {
            $L['emp'][strtoupper(trim((string)$e['employee_no']))] = $e;
        }
        foreach (rows('SELECT id, code, branch_code, name, is_active FROM branches') as $b) {
            foreach (array_filter([$b['code'], $b['branch_code']]) as $c) {
                $L['branch'][strtoupper(trim((string)$c))] = $b;
            }
        }
        foreach (rows('SELECT id, code, name, is_active FROM earning_types') as $r) {
            $L['earn'][strtoupper($r['code'])] = $r;
        }
        foreach (rows('SELECT * FROM deduction_types') as $r) {
            $L['ded'][strtoupper($r['code'])] = $r;
        }
        foreach (rows('SELECT * FROM fixed_trans_codes') as $r) {
            $L['fixed'][strtoupper($r['code'])] = $r;
        }
        foreach (rows('SELECT * FROM benefit_types') as $r) {
            $L['benefit'][strtoupper($r['code'])] = $r;
        }
    }
    return $L;
}

/* ----------------------------------------------------------------------------------- checking */

/**
 * Check data: validate every row and fill in errors (per column), row errors, notes, the status
 * (new, update, replace, keep, error) and the normalized values used by the save. Returns counters.
 * $opt: cutoff_id (period imports), mode (keep | replace | replace_all), update (bool).
 */
function csvi_check(string $kind, array &$rows, array $opt): array
{
    $L = csvi_lookups();
    $period = !empty($opt['cutoff_id']) ? pt_period((int)$opt['cutoff_id']) : null;
    $def = csvi_kinds()[$kind];
    if ($def['period'] && !$period) {
        throw new RuntimeException('Select the pay period of the file first.');
    }
    $existing = [];
    $periodEmp = [];
    if ($period && in_array($kind, ['pay_trans', 'att_batch'], true)) {
        foreach (rows('SELECT employee_id, id, source, is_closed FROM attendance_summaries WHERE cutoff_id = ?', [$period['id']]) as $x) {
            $existing[(int)$x['employee_id']] = $x;
        }
        foreach (pt_period_employees($period) as $e) {
            $periodEmp[(int)$e['id']] = true;
        }
    }
    $seen = [];
    foreach ($rows as $i => &$r) {
        $r['errors'] = [];
        $r['row_errors'] = [];
        $r['notes'] = [];
        $r['norm'] = [];
        $d = $r['data'];
        $err = function (string $col, string $msg) use (&$r) {
            $r['errors'][$col] = isset($r['errors'][$col]) ? $r['errors'][$col] . ' ' . $msg : $msg;
        };
        // employee
        $no = strtoupper(trim((string)$d['employee_no']));
        $e = $no !== '' ? ($L['emp'][$no] ?? null) : null;
        if ($no === '') {
            $err('employee_no', 'Employee No. is required.');
        } elseif (!$e) {
            $err('employee_no', (int)val('SELECT COUNT(*) FROM employees WHERE employee_no = ?', [$no]) ? 'This employee is outside your company / branch and department assignment.' : 'No employee has this number.');
        } elseif ($e['status'] !== 'active' && !($period && $e['date_separated'] && $e['date_separated'] >= $period['period_start'])) {
            $err('employee_no', 'This employee is ' . $e['status'] . '.');
        }
        $r['norm']['employee_id'] = $e ? (int)$e['id'] : null;
        $r['norm']['employee_name'] = $e ? full_name($e) : '';

        switch ($kind) {
            case 'pay_trans':
            case 'att_batch':
                csvi_check_pay($r, $d, $e, $period, $periodEmp, $L, $err);
                break;
            case 'emp_trans':
                csvi_check_emp_trans($r, $d, $e, $period, $L, $err);
                break;
            case 'fixed_trans':
                csvi_check_fixed($r, $d, $e, $L, $err, !empty($opt['update']));
                break;
            case 'deductions':
                csvi_check_deduction($r, $d, $e, $L, $err);
                break;
            case 'benefits':
                csvi_check_benefit($r, $d, $e, $L, $err, !empty($opt['update']));
                break;
        }
        // duplicates inside the file
        if ($e) {
            $dupKey = match ($kind) {
                'pay_trans', 'att_batch' => $e['id'] . '|' . ($r['norm']['branch_id'] ?? 0),
                'fixed_trans' => $e['id'] . '|' . ($r['norm']['code_id'] ?? ''),
                'benefits' => $e['id'] . '|' . ($r['norm']['benefit_type_id'] ?? ''),
                default => null,
            };
            if ($dupKey !== null) {
                if (isset($seen[$dupKey])) {
                    $r['row_errors'][] = in_array($kind, ['pay_trans', 'att_batch'], true)
                        ? 'The same employee' . (($r['norm']['branch_id'] ?? 0) ? ' and location' : '') . ' is already on line ' . $seen[$dupKey] . '. Put different locations on different rows, or merge the rows.'
                        : 'The same employee and code is already on line ' . $seen[$dupKey] . '.';
                } else {
                    $seen[$dupKey] = $r['line'];
                }
            }
        }
    }
    unset($r);

    // period transactions: several rows of one employee = a multi-location transaction
    if (in_array($kind, ['pay_trans', 'att_batch'], true)) {
        $byEmp = [];
        foreach ($rows as $i => $r) {
            if (!empty($r['norm']['employee_id'])) {
                $byEmp[$r['norm']['employee_id']][] = $i;
            }
        }
        foreach ($byEmp as $eid => $idx) {
            $src = 0;
            foreach ($idx as $i) {
                if (count($idx) > 1 && empty($rows[$i]['norm']['branch_id'])) {
                    $rows[$i]['errors']['branch_code'] = 'The employee is on ' . count($idx) . ' rows: give every row its company / branch code.';
                }
                if (!empty($rows[$i]['norm']['deduction_source'])) {
                    $src++;
                }
            }
            if ($src > 1) {
                foreach ($idx as $i) {
                    if (!empty($rows[$i]['norm']['deduction_source'])) {
                        $rows[$i]['errors']['deduction_source'] = 'Only one location of the employee can be the deduction source.';
                    }
                }
            }
            $has = $existing[$eid] ?? null;
            foreach ($idx as $i) {
                if ($has) {
                    if (($opt['mode'] ?? 'keep') === 'keep') {
                        $rows[$i]['notes'][] = 'Already has a transaction for this period (' . ($has['source'] === 'manual' ? 'encoded' : 'from attendance') . '). It is kept and this row is skipped; choose Replace to overwrite it.';
                        $rows[$i]['norm']['action'] = 'keep';
                    } else {
                        $rows[$i]['notes'][] = 'Replaces the transaction already encoded for this period.';
                        $rows[$i]['norm']['action'] = 'replace';
                    }
                } else {
                    $rows[$i]['norm']['action'] = 'new';
                }
                // the attendance side changes open transactions only, the payroll side closed ones only
                if ($period && $rows[$i]['norm']['action'] !== 'keep' && !pt_row_editable($period, $has, $kind === 'pay_trans' ? 'pay' : 'att')) {
                    $rows[$i]['row_errors'][] = pt_locked_reason($period, $has, $kind === 'pay_trans' ? 'pay' : 'att');
                }
            }
        }
    }

    $c = ['total' => count($rows), 'ok' => 0, 'error' => 0, 'new' => 0, 'update' => 0, 'replace' => 0, 'keep' => 0, 'removed_existing' => 0];
    foreach ($rows as &$r) {
        if ($r['errors'] || $r['row_errors']) {
            $r['status'] = 'error';
            $c['error']++;
            continue;
        }
        $r['status'] = $r['norm']['action'] ?? 'new';
        $c['ok']++;
        $c[$r['status']] = ($c[$r['status']] ?? 0) + 1;
    }
    unset($r);
    if (($opt['mode'] ?? '') === 'replace_all' && $period && in_array($kind, ['pay_trans', 'att_batch'], true)) {
        $inFile = array_filter(array_map(fn($r) => $r['norm']['employee_id'] ?? null, $rows));
        $c['removed_existing'] = count(array_diff(array_keys($existing), $inFile));
    }
    return $c;
}

function csvi_check_pay(array &$r, array $d, ?array $e, ?array $period, array $periodEmp, array $L, callable $err): void
{
    if ($e && $period && !isset($periodEmp[(int)$e['id']])) {
        $err('employee_no', 'Not in pay code ' . $period['pay_group_code'] . ' for this period.');
    }
    $maxHours = (days_between($period['period_start'], $period['period_end']) + 1) * 24;
    $vals = [];
    foreach (PT_FIELDS as $k => $meta) {
        $n = csvi_num((string)$d[$k]);
        if ($n === null) {
            $err($k, 'Not a number.');
            continue;
        }
        $v = $n === '' ? 0.0 : ($k === 'tickets' ? (float)(int)$n : round((float)$n, 2));
        if ($v < 0) {
            $err($k, 'Cannot be negative.');
            continue;
        }
        if ($meta[2] !== 'others' && $v > $maxHours) {
            $err($k, 'More than the ' . $maxHours . ' hours of the period.');
            continue;
        }
        $vals[$k] = $v;
    }
    if (isset($vals['absent_hrs'], $vals['hours_work']) && $vals['hours_work'] > 0 && $vals['absent_hrs'] > $vals['hours_work']) {
        $err('absent_hrs', 'Absent hours cannot exceed the hours work.');
    }
    $bc = strtoupper(trim((string)$d['branch_code']));
    $r['norm']['branch_id'] = 0;
    if ($bc !== '') {
        $b = $L['branch'][$bc] ?? null;
        if (!$b) {
            $err('branch_code', 'Unknown company / branch code.');
        } else {
            $r['norm']['branch_id'] = (int)$b['id'];
        }
    }
    $ds = csvi_bool((string)$d['deduction_source']);
    if ($ds === null) {
        $err('deduction_source', 'Use Y or N.');
    }
    $r['norm']['deduction_source'] = $ds === 1 && $r['norm']['branch_id'] ? 1 : 0;
    if ($ds === 1 && !$r['norm']['branch_id']) {
        $err('deduction_source', 'Give the company / branch code of the deduction source.');
    }
    $r['norm']['values'] = $vals;
    $r['norm']['remarks'] = mb_substr((string)$d['remarks'], 0, 255);
    if ($e && $period && !$r['errors'] && ($e['rate_type'] ?? '') === 'monthly' && empty($e['timekeeping_exempt'])) {
        $leaves = 0.0;
        foreach (pt_leave_fields() as $lf) {
            $leaves += (float)($vals[$lf] ?? 0);
        }
        if ($leaves > 0) {
            $r['notes'][] = 'Monthly-salaried: the leave hours are deducted from the perfect-attendance hours work when saved.';
        }
    }
}

function csvi_check_emp_trans(array &$r, array $d, ?array $e, ?array $period, array $L, callable $err): void
{
    $t = strtolower(trim((string)$d['trans_type']));
    $type = in_array($t, ['e', 'earning', 'earnings', 'income'], true) ? 'earning' : (in_array($t, ['d', 'deduction', 'deductions'], true) ? 'deduction' : null);
    if (!$type) {
        $err('trans_type', 'Use E (earning) or D (deduction).');
    }
    $code = strtoupper(trim((string)$d['code']));
    $codeRow = null;
    if ($code === '') {
        $err('code', 'Transaction code is required.');
    } elseif ($type === 'earning') {
        $codeRow = $L['earn'][$code] ?? null;
        if (!$codeRow || !$codeRow['is_active']) {
            $err('code', 'No active earning code "' . $code . '" in the Transaction Table.');
        }
    } elseif ($type === 'deduction') {
        $codeRow = $L['ded'][$code] ?? null;
        if (!$codeRow || !$codeRow['is_active']) {
            $err('code', 'No active deduction code "' . $code . '".');
        } elseif ($codeRow['category'] === 'statutory') {
            $err('code', 'Statutory deductions are computed by payroll and cannot be imported.');
        }
    }
    $amt = csvi_num((string)$d['amount']);
    if ($amt === null || $amt === '' || (float)$amt <= 0) {
        $err('amount', 'Amount must be a number greater than zero.');
    }
    $terms = csvi_num((string)$d['terms']);
    if ($terms === null || ($terms !== '' && ((int)$terms < 1 || (int)$terms > 520 || (float)$terms != (int)$terms))) {
        $err('terms', 'Terms must be a whole number from 1 to 520.');
        $terms = '1';
    }
    $terms = $terms === '' ? 1 : (int)$terms;
    $tt = csvi_pay_term((string)$d['trans_term']);
    if ($tt === null) {
        $err('trans_term', 'Use every, 1st, 2nd, 3rd or 4th.');
    }
    foreach (['monthly_rate', 'interest_amount', 'per_pay_principal', 'per_pay_interest'] as $k) {
        $v = csvi_num((string)$d[$k]);
        if ($v === null || ($v !== '' && (float)$v < 0)) {
            $err($k, 'Must be a number, zero or more.');
        } elseif ($v !== '' && $terms <= 1) {
            $err($k, 'Only for a transaction with terms (Terms 2 or more).');
        }
    }
    $dates = [];
    foreach (['trans_date', 'start_date'] as $k) {
        $v = csvi_date((string)$d[$k]);
        if ($v === null) {
            $err($k, 'Unreadable date.');
        }
        $dates[$k] = $v ?: null;
    }
    $bc = strtoupper(trim((string)$d['branch_code']));
    $branch = $bc !== '' ? ($L['branch'][$bc] ?? null) : null;
    if ($bc !== '' && !$branch) {
        $err('branch_code', 'Unknown company / branch code.');
    }
    if ($e && $period && (int)$e['pay_group_id'] !== (int)$period['pay_group_id'] && $terms <= 1) {
        $r['notes'][] = 'The employee is not in pay code ' . $period['pay_group_code'] . '; the item is taken by the payroll run of the employee\'s own pay code for the same dates.';
    }
    $r['norm'] += [
        'trans_type' => $type, 'code_id' => $codeRow ? (int)$codeRow['id'] : null, 'amount' => $amt !== null && $amt !== '' ? round((float)$amt, 2) : 0,
        'terms' => $terms, 'trans_term' => $tt ?: 'every_pay', 'remarks' => mb_substr((string)$d['remarks'], 0, 255) ?: null,
        'monthly_rate' => (float)csvi_num((string)$d['monthly_rate']),
        'interest_amount' => ($x = csvi_num((string)$d['interest_amount'])) !== '' && $x !== null ? (float)$x : null,
        'per_pay_principal' => ($x = csvi_num((string)$d['per_pay_principal'])) !== '' && $x !== null ? (float)$x : null,
        'per_pay_interest' => ($x = csvi_num((string)$d['per_pay_interest'])) !== '' && $x !== null ? (float)$x : null,
        'trans_date' => $dates['trans_date'] ?: today(), 'start_date' => $dates['start_date'] ?: ($period['period_start'] ?? today()),
        'doc_no' => mb_substr(trim((string)$d['doc_no']), 0, 40) ?: null, 'branch_id' => $branch ? (int)$branch['id'] : null,
        'action' => 'new',
    ];
    if ($terms > 1 && $e && $type && !$r['errors']) {
        $freq = (string)val('SELECT frequency FROM pay_groups WHERE id = ?', [(int)$e['pay_group_id']]) ?: 'semi_monthly';
        $calc = term_compute(['principal' => $r['norm']['amount'], 'monthly_rate' => $r['norm']['monthly_rate'], 'terms' => $terms, 'trans_term' => $r['norm']['trans_term'],
            'interest_amount' => $r['norm']['interest_amount'], 'per_pay_principal' => $r['norm']['per_pay_principal'], 'per_pay_interest' => $r['norm']['per_pay_interest']], $freq);
        $r['notes'][] = sprintf('With terms: %d paydays (%s), total %s, %s per payday.', $terms, strtolower(TERM_TRANS_TERMS[$r['norm']['trans_term']]),
            number_format((float)$calc['total_amount'], 2), number_format((float)$calc['per_pay_principal'] + (float)$calc['per_pay_interest'], 2));
    }
}

function csvi_check_fixed(array &$r, array $d, ?array $e, array $L, callable $err, bool $update): void
{
    $code = strtoupper(trim((string)$d['code']));
    $c = $code !== '' ? ($L['fixed'][$code] ?? null) : null;
    if ($code === '') {
        $err('code', 'Fixed transaction code is required.');
    } elseif (!$c || !$c['is_active']) {
        $err('code', 'No active code "' . $code . '" in the Fixed Transaction Table.');
    }
    $amt = csvi_num((string)$d['amount']);
    if ($amt === null || $amt === '' || (float)$amt < 0) {
        $err('amount', 'Amount must be a number, zero or more.');
    }
    $terms = csvi_pay_term((string)$d['terms']);
    if ($terms === null) {
        $err('terms', 'Use every_pay, 1st, 2nd, 3rd or 4th.');
        $terms = '';
    }
    $dates = [];
    $v = csvi_date((string)$d['start_date']);
    if ($v === null) {
        $err('start_date', 'Unreadable date.');
    }
    $dates['start_date'] = $v ?: today();
    $st = strtolower(trim((string)$d['status']));
    if ($st !== '' && !in_array($st, ['active', 'stopped', 'hold'], true)) {
        $err('status', 'Use active, hold or stopped.');
    }
    $action = 'new';
    if ($e && $c) {
        $dup = val('SELECT id FROM employee_fixed_trans WHERE employee_id = ? AND code_id = ? AND status IN ("active", "hold")', [$e['id'], $c['id']]);
        if ($dup) {
            if ($update) {
                $action = 'update';
                $r['notes'][] = 'Updates the active setup of this code already on the employee.';
                $r['norm']['existing_id'] = (int)$dup;
            } else {
                $r['row_errors'][] = 'The employee already has an active setup of code ' . $code . '. Tick "Update existing" to overwrite it.';
            }
        }
    }
    $r['norm'] += ['code_id' => $c ? (int)$c['id'] : null, 'amount' => $amt !== null && $amt !== '' ? round((float)$amt, 2) : 0,
        'terms' => $terms ?: ($c['default_terms'] ?? 'every_pay'), 'start_date' => $dates['start_date'], 'end_date' => null,
        'status' => $st ?: 'active', 'remarks' => mb_substr((string)$d['remarks'], 0, 255) ?: null, 'action' => $action];
}

function csvi_check_deduction(array &$r, array $d, ?array $e, array $L, callable $err): void
{
    $code = strtoupper(trim((string)$d['deduction_code']));
    $t = $code !== '' ? ($L['ded'][$code] ?? null) : null;
    if ($code === '') {
        $err('deduction_code', 'Deduction code is required.');
    } elseif (!$t || !$t['is_active']) {
        $err('deduction_code', 'No active deduction type "' . $code . '".');
    } elseif ($t['category'] === 'statutory') {
        $err('deduction_code', 'Statutory deductions are computed by payroll.');
    } elseif ($t['computation'] === 'ledger') {
        $err('deduction_code', $t['name'] . ' runs on a subsidiary ledger: create the loan in Employee Term Loan/Payables (its deduction is set up there).');
    }
    $amt = csvi_num((string)$d['amount']);
    if ($amt === null || ($amt !== '' && (float)$amt < 0)) {
        $err('amount', 'Amount must be a number, zero or more.');
    }
    $value = $amt === '' || $amt === null ? (float)($t['default_amount'] ?? 0) : round((float)$amt, 2);
    if ($t && $value <= 0 && !isset($r['errors']['amount'])) {
        $err('amount', 'No amount, and the deduction type has no default amount.');
    }
    if ($t && $t['computation'] === 'percent_basic' && $value > 100) {
        $err('amount', 'A percent-of-basic deduction cannot exceed 100.');
    }
    $dates = [];
    foreach (['start_date', 'end_date'] as $k) {
        $v = csvi_date((string)$d[$k]);
        if ($v === null) {
            $err($k, 'Unreadable date.');
        }
        $dates[$k] = $v ?: null;
    }
    if ($dates['start_date'] && $dates['end_date'] && $dates['end_date'] < $dates['start_date']) {
        $err('end_date', 'Before the start date.');
    }
    $consent = csvi_bool((string)$d['written_consent']);
    if ($consent === null) {
        $err('written_consent', 'Use Y or N.');
    }
    if ($t && !$t['authorized_by_law'] && $consent !== 1) {
        $r['notes'][] = 'No written consent: under Art. 113 the deduction is not applied until the employee\'s written authorization is on file.';
    }
    if ($t && $t['requires_approval']) {
        $r['notes'][] = 'This type needs approval: the deduction is created as pending approval.';
    }
    if ($e && $t && val("SELECT COUNT(*) FROM employee_deductions WHERE employee_id = ? AND deduction_type_id = ? AND status IN ('active','pending')", [$e['id'], $t['id']])) {
        $r['notes'][] = 'The employee already has an active ' . $t['name'] . ' deduction; this row adds another one.';
    }
    $r['norm'] += ['deduction_type_id' => $t ? (int)$t['id'] : null, 'amount' => $value, 'start_date' => $dates['start_date'] ?: today(), 'end_date' => $dates['end_date'],
        'written_consent' => $consent === 1 ? 1 : 0, 'status' => $t && $t['requires_approval'] ? 'pending' : 'active', 'remarks' => mb_substr((string)$d['remarks'], 0, 255) ?: null, 'action' => 'new'];
}

function csvi_check_benefit(array &$r, array $d, ?array $e, array $L, callable $err, bool $update): void
{
    $code = strtoupper(trim((string)$d['benefit_code']));
    $t = $code !== '' ? ($L['benefit'][$code] ?? null) : null;
    if ($code === '') {
        $err('benefit_code', 'Benefit code is required.');
    } elseif (!$t || !$t['is_active']) {
        $err('benefit_code', 'No active benefit "' . $code . '".');
    }
    $amt = csvi_num((string)$d['amount']);
    if ($amt === null || ($amt !== '' && (float)$amt < 0)) {
        $err('amount', 'Amount must be a number, zero or more.');
    }
    $value = $amt === '' || $amt === null ? (float)($t['default_amount'] ?? 0) : round((float)$amt, 2);
    $pt = csvi_pay_term((string)$d['post_term']);
    if ($pt === null) {
        $err('post_term', 'Use every, 1st, 2nd, 3rd or 4th.');
    }
    $dates = [];
    foreach (['start_date', 'end_date'] as $k) {
        $v = csvi_date((string)$d[$k]);
        if ($v === null) {
            $err($k, 'Unreadable date.');
        }
        $dates[$k] = $v ?: null;
    }
    if ($dates['start_date'] && $dates['end_date'] && $dates['end_date'] < $dates['start_date']) {
        $err('end_date', 'Before the start date.');
    }
    if ($e && $t) {
        if ($t['eligibility_regular_only'] && $e['employment_status'] !== 'regular') {
            $r['row_errors'][] = $t['name'] . ' is for regular employees only; this employee is ' . label($e['employment_status']) . '.';
        }
        if ((int)$t['eligibility_min_months'] > 0 && months_of_service($e['date_hired']) < (int)$t['eligibility_min_months']) {
            $r['row_errors'][] = $t['name'] . ' needs ' . (int)$t['eligibility_min_months'] . ' months of service.';
        }
    }
    $action = 'new';
    if ($e && $t) {
        $dup = val("SELECT id FROM employee_benefits WHERE employee_id = ? AND benefit_type_id = ? AND status = 'active'", [$e['id'], $t['id']]);
        if ($dup) {
            if ($update) {
                $action = 'update';
                $r['norm']['existing_id'] = (int)$dup;
                $r['notes'][] = 'Updates the active enrollment of this benefit.';
            } else {
                $r['row_errors'][] = 'Already enrolled in ' . $t['name'] . '. Tick "Update existing" to overwrite the enrollment.';
            }
        }
    }
    if ($t && $value <= 0) {
        $r['notes'][] = 'Amount is zero: nothing is paid until an amount is set.';
    }
    $r['norm'] += ['benefit_type_id' => $t ? (int)$t['id'] : null, 'amount' => $value, 'post_term' => $pt ?: null,
        'start_date' => $dates['start_date'] ?: today(), 'end_date' => $dates['end_date'], 'remarks' => mb_substr((string)$d['remarks'], 0, 255) ?: null, 'action' => $action];
}

/* ----------------------------------------------------------------------------------- saving */

/**
 * Save the checked rows to the database (all or nothing). Every row is checked again first;
 * any error stops the save. Returns the counters and records the import batch.
 */
function csvi_commit(string $kind, array $rows, array $opt, string $fileName): array
{
    $c = csvi_check($kind, $rows, $opt);
    if ($c['error']) {
        throw new RuntimeException($c['error'] . ' row(s) still have errors. Correct them (or remove the rows) and check the data again.');
    }
    if (!$rows) {
        throw new RuntimeException('There is no row to save.');
    }
    $period = !empty($opt['cutoff_id']) ? pt_period((int)$opt['cutoff_id']) : null;
    if ($period && in_array($kind, ['pay_trans', 'att_batch'], true) && !pt_period_editable($period, $kind === 'pay_trans' ? 'pay' : 'att')) {
        throw new RuntimeException('The period is ' . $period['status'] . '; its transactions can no longer be changed. Reopen it first.');
    }
    $out = ['created' => 0, 'updated' => 0, 'replaced' => 0, 'kept' => 0, 'removed' => 0, 'batch' => 0];
    tx(function () use ($kind, $rows, $opt, $period, $fileName, &$out) {
        $batch = insert('csv_import_batches', ['kind' => $kind, 'file_name' => mb_substr($fileName, 0, 250), 'cutoff_id' => $period['id'] ?? null,
            'total_rows' => count($rows), 'uploaded_by' => user_id()]);
        $out['batch'] = $batch;
        switch ($kind) {
            case 'pay_trans':
            case 'att_batch':
                csvi_commit_pay($rows, $opt + ['side' => $kind === 'pay_trans' ? 'pay' : 'att'], $period, $out);
                break;
            case 'emp_trans':
                foreach ($rows as $r) {
                    $n = $r['norm'];
                    if ($n['terms'] > 1) {
                        term_create(['employee_id' => $n['employee_id'], 'trans_type' => $n['trans_type'], 'code_id' => $n['code_id'], 'principal' => $n['amount'],
                            'monthly_rate' => $n['monthly_rate'], 'terms' => $n['terms'], 'trans_term' => $n['trans_term'], 'interest_amount' => $n['interest_amount'],
                            'per_pay_principal' => $n['per_pay_principal'], 'per_pay_interest' => $n['per_pay_interest'], 'trans_date' => $n['trans_date'],
                            'start_date' => $n['start_date'], 'doc_no' => $n['doc_no'], 'branch_id' => $n['branch_id'], 'remarks' => $n['remarks']]);
                    } else {
                        insert('employee_transactions', ['cutoff_id' => (int)$period['id'], 'pay_group_id' => (int)$period['pay_group_id'], 'period_start' => $period['period_start'],
                            'period_end' => $period['period_end'], 'employee_id' => $n['employee_id'], 'trans_type' => $n['trans_type'],
                            'earning_type_id' => $n['trans_type'] === 'earning' ? $n['code_id'] : null, 'deduction_type_id' => $n['trans_type'] === 'deduction' ? $n['code_id'] : null,
                            'amount' => $n['amount'], 'remarks' => $n['remarks'], 'created_by' => user_id(), 'import_batch_id' => $out['batch']]);
                    }
                    $out['created']++;
                }
                break;
            case 'fixed_trans':
                foreach ($rows as $r) {
                    $n = $r['norm'];
                    $data = ['code_id' => $n['code_id'], 'amount' => $n['amount'], 'terms' => $n['terms'], 'start_date' => $n['start_date'], 'end_date' => $n['end_date'],
                        'status' => $n['status'], 'remarks' => $n['remarks']];
                    if ($n['action'] === 'update') {
                        update('employee_fixed_trans', $data, 'id = ?', [$n['existing_id']]);
                        $out['updated']++;
                    } else {
                        insert('employee_fixed_trans', $data + ['employee_id' => $n['employee_id'], 'created_by' => user_id(), 'import_batch_id' => $out['batch']]);
                        $out['created']++;
                    }
                }
                break;
            case 'deductions':
                foreach ($rows as $r) {
                    $n = $r['norm'];
                    insert('employee_deductions', ['employee_id' => $n['employee_id'], 'deduction_type_id' => $n['deduction_type_id'], 'amount' => $n['amount'],
                        'start_date' => $n['start_date'], 'end_date' => $n['end_date'], 'written_consent' => $n['written_consent'], 'status' => $n['status'],
                        'remarks' => $n['remarks'], 'created_by' => user_id(), 'import_batch_id' => $out['batch']]);
                    $out['created']++;
                }
                break;
            case 'benefits':
                foreach ($rows as $r) {
                    $n = $r['norm'];
                    $data = ['amount' => $n['amount'], 'post_term' => $n['post_term'], 'start_date' => $n['start_date'], 'end_date' => $n['end_date'], 'remarks' => $n['remarks']];
                    if ($n['action'] === 'update') {
                        update('employee_benefits', $data, 'id = ?', [$n['existing_id']]);
                        $out['updated']++;
                    } else {
                        insert('employee_benefits', $data + ['employee_id' => $n['employee_id'], 'benefit_type_id' => $n['benefit_type_id'], 'import_batch_id' => $out['batch']]);
                        $out['created']++;
                    }
                }
                break;
        }
        update('csv_import_batches', ['created_rows' => $out['created'], 'updated_rows' => $out['updated'], 'replaced_rows' => $out['replaced'] + $out['removed']], 'id = ?', [$batch]);
    });
    audit(in_array($kind, ['att_batch'], true) ? 'ams' : 'payroll', 'csv_import_' . $kind, 'csv_import_batches', $out['batch'],
        ['file' => $fileName, 'rows' => count($rows), 'cutoff_id' => $opt['cutoff_id'] ?? null, 'mode' => $opt['mode'] ?? null] + $out);
    return $out;
}

function csvi_commit_pay(array $rows, array $opt, array $period, array &$out): void
{
    $mode = $opt['mode'] ?? 'keep';
    $side = $opt['side'] ?? 'att';   // attendance import: open transactions only; payroll import: closed ones only
    $out['locked'] = 0;
    $byEmp = [];
    foreach ($rows as $r) {
        $byEmp[$r['norm']['employee_id']][] = $r['norm'];
    }
    if ($mode === 'replace_all') {
        foreach (rows('SELECT employee_id, is_closed FROM attendance_summaries WHERE cutoff_id = ?', [$period['id']]) as $x) {
            if (!isset($byEmp[(int)$x['employee_id']]) && (int)$x['is_closed'] === ($side === 'pay' ? 1 : 0)) {
                pt_delete((int)$period['id'], (int)$x['employee_id']);
                $out['removed']++;
            }
        }
    }
    foreach ($byEmp as $eid => $parts) {
        $existing = pt_get((int)$period['id'], (int)$eid);
        if (!pt_row_editable($period, $existing, $side)) {
            $out['locked']++;
            continue;
        }
        if ($existing && $mode === 'keep') {
            $out['kept']++;
            continue;
        }
        $values = array_fill_keys(pt_all_fields(), 0.0);
        $byBranch = [];
        $src = null;
        $remarks = [];
        foreach ($parts as $n) {
            foreach ($n['values'] as $k => $v) {
                $values[$k] += $v;
            }
            if ($n['branch_id']) {
                $byBranch[$n['branch_id']] = array_intersect_key($n['values'], array_flip(pt_location_fields()));
            }
            if (!empty($n['deduction_source'])) {
                $src = (int)$n['branch_id'];
            }
            if ($n['remarks'] !== '') {
                $remarks[] = $n['remarks'];
            }
        }
        $values = array_map(fn($v) => round($v, 2), $values);
        $emp = row('SELECT * FROM employees WHERE id = ?', [$eid]);
        [$values] = pt_apply_leave_rule($values, $emp, $period);
        $id = pt_save((int)$period['id'], (int)$eid, $values, implode('; ', array_unique($remarks)) ?: 'Imported from CSV', 'manual');
        // a single-location row replaces any old split; several rows become the multi-location split
        if (count($byBranch) > 1 || ($byBranch && (int)array_key_first($byBranch) !== (int)$emp['default_branch_id'])) {
            pt_save_branches($id, $byBranch);
        } elseif ($existing) {
            q('DELETE FROM attendance_summary_branches WHERE summary_id = ?', [$id]);
        }
        update('attendance_summaries', ['deduction_branch_id' => $src && $src !== (int)$emp['default_branch_id'] ? $src : null], 'id = ?', [$id]);
        $existing ? $out['replaced']++ : $out['created']++;
    }
}

/* ----------------------------------------------------------------------------------- formats */

/** The format as CSV: header row plus an example row, or the employees of a period with their current figures. */
function csvi_template(string $kind, ?array $period = null, bool $withEmployees = false): array
{
    $cols = csvi_columns($kind);
    $header = array_keys($cols);
    $out = [];
    if ($withEmployees && $period && in_array($kind, ['pay_trans', 'att_batch'], true)) {
        $cur = [];
        foreach (rows('SELECT * FROM attendance_summaries WHERE cutoff_id = ?', [$period['id']]) as $s) {
            $cur[(int)$s['employee_id']] = $s;
        }
        foreach (pt_period_employees($period) as $e) {
            $s = $cur[(int)$e['id']] ?? null;
            $row = [];
            foreach ($header as $k) {
                $row[$k] = match (true) {
                    $k === 'employee_no' => $e['employee_no'],
                    $k === 'employee_name' => full_name($e),
                    isset(PT_FIELDS[$k]) => $s && (float)$s[$k] != 0 ? ($k === 'tickets' ? (string)(int)$s[$k] : number_format((float)$s[$k], 2, '.', '')) : '',
                    $k === 'remarks' => $s['remarks'] ?? '',
                    default => '',
                };
            }
            $out[] = $row;
        }
        return [$header, $out];
    }
    if ($withEmployees && $period && $kind === 'emp_trans') {
        foreach (pt_period_employees($period) as $e) {
            $out[] = array_merge(array_fill_keys($header, ''), ['employee_no' => $e['employee_no'], 'employee_name' => full_name($e)]);
        }
        return [$header, $out];
    }
    $ex = rows("SELECT employee_no, first_name, last_name, middle_name, suffix FROM employees WHERE status = 'active' ORDER BY id LIMIT 1")[0] ?? null;
    $row = [];
    foreach ($cols as $k => $c) {
        $row[$k] = $c[3];
    }
    if ($ex) {
        $row['employee_no'] = $ex['employee_no'];
        $row['employee_name'] = full_name($ex);
    }
    return [$header, [$row]];
}

/** Error report: the rows with their errors, to correct in a spreadsheet and load again. */
function csvi_error_report(string $kind, array $rows): array
{
    $cols = array_keys(csvi_columns($kind));
    $out = [];
    foreach ($rows as $r) {
        if ($r['status'] !== 'error') {
            continue;
        }
        $msgs = $r['row_errors'];
        foreach ($r['errors'] as $k => $m) {
            $msgs[] = (csvi_columns($kind)[$k][0] ?? $k) . ': ' . $m;
        }
        $out[] = array_merge(array_intersect_key($r['data'], array_flip($cols)), ['errors' => implode(' | ', $msgs)]);
    }
    return [array_merge($cols, ['errors']), $out];
}
