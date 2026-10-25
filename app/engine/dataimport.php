<?php
/**
 * CSV import facility shown on the forms themselves (Company Parameters Setup tables, Payroll System,
 * Personnel and Attendance forms). Every import works the same way, inside the form it loads:
 *
 *   1. Download the format (or the current data of the table, to edit it in a spreadsheet).
 *   2. Choose the CSV file and press Load and check: every row is read and checked; every wrong cell
 *      is marked with the reason. Nothing is written yet.
 *   3. Correct the cells on the screen (or the file, and load it again), remove rows, Check data again.
 *   4. Save to database, possible only when every row is valid.
 *
 * Three families of imports share the screen:
 *   crud    the reference tables edited with crud_page() (Company, Location, Job, Shift, Transaction,
 *           Benefits, Fixed Transaction, SSS, PhilHealth, Lates / Undertime, Competencies, Merit Matrix):
 *           the columns, checks and defaults come from the form definition itself; rows are matched
 *           on the code (or the key of the table) and update the existing record or add a new one.
 *   csvi    the payroll imports of app/engine/csvimport.php (Payroll Transaction, Attendance Summary,
 *           Employee's Transactions, Fixed Transaction Setup, Employee Deductions, Employee Benefits).
 *   custom  Department Table, Code of Conduct & Penalties, Transaction Payment, Retroactive Adjustments,
 *           YTD Info (Previous Employer), Leave Balances manual adjustment, Employee Shift Entry.
 *
 * The loaded rows are kept in the session between the steps: $_SESSION['dimp'][kind].
 */

const DIMP_MAX_ROWS = 5000;
const DIMP_PER_PAGE = 120;

/** YTD figures of the previous employer: key => [label, used by the tax computation]. */
const PREV_YTD_FIELDS = [
    'gross' => ['YTD Gross', true], 'sss' => ['YTD SSS', true], 'other_benefits' => ['Other Benefits (13th & other, non-taxable)', false],
    'deminimis' => ['YTD De Minimis', false], 'nontax' => ['YTD Non-Tax', false], 'taxable' => ['YTD Taxable', true],
    'tax' => ['YTD Tax', true], 'hdmf' => ['YTD HDMF', true], 'bonus' => ['YTD Bonus', false], 'leave_pay' => ['YTD Leave', false],
    'absences' => ['YTD ABS', false], 'basic' => ['YTD Basic', false], 'phic' => ['YTD PhilHealth', true], 'thirteenth' => ['YTD 13th Mo.', true],
    'holidays' => ['YTD Holidays', false], 'np' => ['YTD NP', false],
];

/* =========================================================================== shared helpers */

/** Read a CSV file into rows keyed by the column keys (header = column key or column name). */
function dimp_read(string $path, array $cols, array $alias = []): array
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
    $map = [];
    foreach ($cols as $k => $c) {
        $map[csvi_key($k)] = $k;
        $map[csvi_key($c[0])] = $k;
    }
    foreach ($alias as $a => $k) {
        $map[csvi_key($a)] = $k;
    }
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
                if (isset($map[$k])) {
                    $header[$i] = $map[$k];
                } elseif ($k !== '') {
                    $unknown[] = trim((string)$c);
                }
            }
            $missing = [];
            foreach ($cols as $k => $c) {
                if ($c[1] && !in_array($k, $header, true)) {
                    $missing[] = $k;
                }
            }
            if (!$header || $missing) {
                fclose($fh);
                throw new RuntimeException('The first line of the file must be the column headers of the format' . ($missing ? '; missing: ' . implode(', ', $missing) : '') . '. Download the format and copy your data into it.');
            }
            continue;
        }
        $data = array_fill_keys(array_keys($cols), '');
        foreach ($header as $i => $k) {
            $data[$k] = trim((string)($cells[$i] ?? ''));
        }
        $rows[] = ['line' => $n, 'data' => $data, 'errors' => [], 'row_errors' => [], 'notes' => [], 'status' => 'unchecked', 'norm' => []];
        if (count($rows) > DIMP_MAX_ROWS) {
            fclose($fh);
            throw new RuntimeException('The file has more than ' . number_format(DIMP_MAX_ROWS) . ' rows. Split it and import the parts one after the other.');
        }
    }
    fclose($fh);
    if ($header === null) {
        throw new RuntimeException('The file is empty.');
    }
    return ['rows' => $rows, 'unknown' => $unknown];
}

/** Status and counters after a check: error, new, update, skip. */
function dimp_finalize(array &$rows): array
{
    $c = ['total' => count($rows), 'ok' => 0, 'error' => 0, 'new' => 0, 'update' => 0, 'skip' => 0, 'replace' => 0, 'keep' => 0];
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
    return $c;
}

/** Clear the messages of a row before it is checked again; returns the error callback. */
function dimp_reset_row(array &$r): callable
{
    $r['errors'] = [];
    $r['row_errors'] = [];
    $r['notes'] = [];
    $r['norm'] = [];
    return function (string $col, string $msg) use (&$r) {
        $r['errors'][$col] = isset($r['errors'][$col]) ? $r['errors'][$col] . ' ' . $msg : $msg;
    };
}

/** Employee of a row by employee_no (active or not). */
function dimp_employee(string $no): ?array
{
    $L = csvi_lookups();
    $no = strtoupper(trim($no));
    return $no !== '' ? ($L['emp'][$no] ?? null) : null;
}

/** Why an employee number of a file is refused: unknown, or outside the user's assignment. */
function dimp_unknown_employee(string $no): string
{
    $no = strtoupper(trim($no));
    return $no !== '' && (int)val('SELECT COUNT(*) FROM employees WHERE employee_no = ?', [$no])
        ? 'This employee is outside your company / branch and department assignment.' : 'No employee has this number.';
}

/** Match a text against a list of options (key => label): by key, label, the code part or the name part of "CODE — Name". */
function dimp_match_option(string $v, array $options)
{
    $v = trim($v);
    if ($v === '') {
        return '';
    }
    $lv = mb_strtolower($v);
    foreach ($options as $k => $lbl) {
        if (mb_strtolower((string)$k) === $lv) {
            return $k;
        }
    }
    foreach ($options as $k => $lbl) {
        $lbl = (string)$lbl;
        if (mb_strtolower($lbl) === $lv) {
            return $k;
        }
        $parts = preg_split('/\s+[—–-]\s+/u', $lbl, 2);
        if (count($parts) === 2 && (mb_strtolower(trim($parts[0])) === $lv || mb_strtolower(trim($parts[1])) === $lv)) {
            return $k;
        }
    }
    foreach ($options as $k => $lbl) {
        if (mb_strtolower(str_replace('_', ' ', (string)$k)) === mb_strtolower(str_replace('_', ' ', $v))) {
            return $k;
        }
    }
    // a unique beginning of a label ("verbal" = Verbal Warning)
    if (mb_strlen($lv) >= 3) {
        $hits = array_keys(array_filter($options, fn($lbl) => mb_strpos(mb_strtolower((string)$lbl), $lv) === 0));
        if (count($hits) === 1) {
            return $hits[0];
        }
    }
    return null;
}

/** Value of a select option for the export: the key, or the code of an id-keyed "CODE — Name" option. */
function dimp_option_out($v, array $options, bool $idKeyed = false): string
{
    if ($v === null || $v === '') {
        return '';
    }
    $lbl = $options[$v] ?? null;
    if ($lbl !== null && $idKeyed) {
        $parts = preg_split('/\s+[—–-]\s+/u', (string)$lbl, 2);
        return trim($parts[0]);   // the code of "CODE — Name", or the name
    }
    return (string)$v;
}

/** Time in the file: 8:00, 08:00, 8:00 AM, 0800, 17:30:00. '' when blank, null when unreadable. */
function dimp_time(string $v): ?string
{
    $v = strtoupper(trim($v));
    if ($v === '') {
        return '';
    }
    if (preg_match('/^(\d{1,2})(\d{2})$/', $v, $m)) {
        $v = $m[1] . ':' . $m[2];
    }
    if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?\s*(AM|PM)?$/', $v, $m)) {
        $h = (int)$m[1];
        if (!empty($m[4])) {
            if ($h < 1 || $h > 12) {
                return null;
            }
            $h = $h % 12 + ($m[4] === 'PM' ? 12 : 0);
        }
        if ($h > 23 || (int)$m[2] > 59) {
            return null;
        }
        return sprintf('%02d:%02d:%02d', $h, (int)$m[2], (int)($m[3] ?? 0));
    }
    if (is_numeric($v) && (float)$v >= 0 && (float)$v < 1) {   // Excel time fraction
        $s = (int)round((float)$v * 86400);
        return sprintf('%02d:%02d:00', intdiv($s, 3600), intdiv($s % 3600, 60));
    }
    return null;
}

/* =========================================================================== registry */

/** Every import: kind => [title, permission page, family, audit module, what it loads]. */
function dimp_registry(): array
{
    return [
        // Company Parameters Setup (crud tables)
        'branches' => ['Company Table', 'branches', 'crud', 'setup', 'companies and branches (employers of record) with their government employer numbers, signatories, bank and geofence. Rows are matched on the unique short code.'],
        'locations' => ['Location Table', 'locations', 'crud', 'setup', 'work locations. Rows are matched on the code.'],
        'jobs' => ['Job Table', 'jobs', 'crud', 'setup', 'job titles with category, sub-category and the OT/OL flag. Rows are matched on the code.'],
        'shifts' => ['Shift Table', 'shifts', 'crud', 'ams', 'shift definitions: times in and out, breaks, logs per day, required hours, rest days. Rows are matched on the shift code.'],
        'earning_types' => ['Transaction Table (earnings)', 'earning_types', 'crud', 'payroll', 'earning transaction codes with their tax treatment and GL code. Rows are matched on the transaction code.'],
        'deduction_types' => ['Transaction Table (deductions)', 'deduction_types', 'crud', 'payroll', 'deduction codes with their category, computation, frequency, priority and register column. Rows are matched on the code.'],
        'benefit_types' => ['Benefits Table', 'benefit_types', 'crud', 'payroll', 'benefit types with their tax treatment, ceiling, default amount and frequency. Rows are matched on the code.'],
        'fixed_trans_codes' => ['Fixed Transaction Table', 'fixed_trans_codes', 'crud', 'payroll', 'fixed transaction codes (earning or deduction, statutory replacement role, default terms). Rows are matched on the code.'],
        'sss_table' => ['SSS Table', 'sss_table', 'crud', 'payroll', 'the SSS contribution schedule: salary range, monthly salary credit, employee, employer and EC shares. Rows are matched on the effectivity date and the range start.'],
        'philhealth' => ['Health Table (PhilHealth)', 'philhealth', 'crud', 'payroll', 'the PhilHealth premium rate, salary floor and ceiling and employee share. Rows are matched on the effectivity date.'],
        'late_brackets' => ['Lates / Undertime Table', 'late_brackets', 'crud', 'payroll', 'the late and undertime brackets: from and to minutes and the minutes deducted. Rows are matched on the From minutes.'],
        'competencies' => ['Competencies', 'competencies', 'crud', 'pis', 'competencies rated in the evaluations. Rows are matched on the competency name.'],
        'merit_matrix' => ['Merit Matrix', 'competencies', 'crud', 'pis', 'the rating bands: minimum score, rating label, merit increase, step increase, promotion and improvement-plan flags. Rows are matched on the minimum score.'],
        // custom
        'departments' => ['Department Table', 'departments', 'custom', 'setup', 'the organizational units (company, division, department, section) with their parent unit, head and default pay code. Rows are matched on the unit code; a parent can be a unit of the same file.'],
        'conduct' => ['Code of Conduct & Penalties', 'conduct', 'custom', 'pis', 'offenses with their progressive penalties: one row per offense number (1st, 2nd, 3rd ... offense) of each offense code.'],
        'trans_payments' => ['Transaction Payment', 'trans_payments', 'custom', 'payroll', 'payments made outside the payroll against the loans, cash advances and accountabilities of the employees. Each payment reduces the ledger balance.'],
        'retro' => ['Retroactive Adjustments', 'retro', 'custom', 'payroll', 'rate changes with a past effectivity date: the differential of the finalized periods paid at the old rate is computed and queued for the next payroll.'],
        'prev_ytd' => ['YTD Info (Previous Employer)', 'prev_ytd', 'custom', 'payroll', 'the year-to-date earnings and taxes of new employees at their previous employer, one row per employee and year.'],
        'leave_adj' => ['Leave Balances manual adjustment', 'leave_balances', 'custom', 'pis', 'manual adjustments of leave balances: days added (or deducted with a minus sign) per employee, leave type and year, with the reason.'],
        'schedules' => ['Employee Shift Entry', 'schedules', 'custom', 'ams', 'per-date shift, rest day and branch overrides of employees; a row can cover a range of dates.'],
        // payroll imports of app/engine/csvimport.php
        'pay_trans' => ['Payroll Transaction', 'pay_trans', 'csvi', 'payroll', ''],
        'att_batch' => ['Attendance Summary Entry', 'att_summary', 'csvi', 'ams', ''],
        'emp_trans' => ["Employee's Transactions", 'emp_transactions', 'csvi', 'payroll', ''],
        'fixed_trans' => ['Fixed Transaction Setup', 'fixed_trans', 'csvi', 'payroll', ''],
        'deductions' => ['Employee Term Loan/Payables: Employee Deductions', 'emp_deductions', 'csvi', 'payroll', ''],
        'benefits' => ['Employee Benefits', 'emp_benefits', 'csvi', 'payroll', ''],
    ];
}

/** The definition of an import. $crudCfg: the crud_page() configuration of a crud table. $ctx: page context (cutoff_id, year). */
function dimp_def(string $kind, ?array $crudCfg = null, array $ctx = []): array
{
    $reg = dimp_registry()[$kind] ?? null;
    if (!$reg) {
        throw new RuntimeException('Unknown import.');
    }
    [$title, $page, $family, $module, $what] = $reg;
    $def = ['kind' => $kind, 'title' => $title, 'page' => $page, 'family' => $family, 'module' => $module, 'what' => $what, 'ctx' => $ctx,
        'opts' => [], 'alias' => [], 'export' => null];
    if ($family === 'crud') {
        return dimp_crud_def($def, $crudCfg ?? []);
    }
    if ($family === 'csvi') {
        return dimp_csvi_def($def);
    }
    $fn = 'dimp_def_' . $kind;
    return $fn($def);
}

/* =========================================================================== crud tables */

/** Unique key of each crud table (the code when it has one). */
function dimp_crud_keys(string $kind): array
{
    return ['sss_table' => ['effective_from', 'range_from'], 'philhealth' => ['effective_from'], 'late_brackets' => ['from_minutes'],
        'competencies' => ['name'], 'merit_matrix' => ['min_score']][$kind] ?? ['code'];
}

function dimp_crud_def(array $def, array $cfg): array
{
    $table = $cfg['table'];
    $fields = [];
    foreach ($cfg['fields'] as $col => $f) {
        if (($f['type'] ?? '') === 'heading' || !empty($f['readonly']) || $col === 'id') {
            continue;
        }
        $fields[$col] = $f;
    }
    $keys = array_values(array_filter(dimp_crud_keys($def['kind']), fn($k) => isset($fields[$k])));
    if (!$keys) {
        $keys = [array_key_first($fields)];
    }
    $cols = [];
    foreach ($fields as $col => $f) {
        $type = $f['type'] ?? 'text';
        $help = trim((string)($f['help'] ?? ''));
        $ex = '';
        switch ($type) {
            case 'checkbox':
                $hint = 'Y or N.';
                $ex = 'Y';
                break;
            case 'number':
            case 'money':
                $hint = 'Number.';
                $ex = isset($f['default']) ? (string)$f['default'] : '0';
                break;
            case 'date':
                $hint = 'YYYY-MM-DD or MM/DD/YYYY.';
                $ex = date('Y') . '-01-01';
                break;
            case 'time':
                $hint = 'HH:MM (24-hour) or 8:00 AM.';
                $ex = '08:00';
                break;
            case 'select':
            case 'multicheck':
                $opts = $f['options'] ?? [];
                $list = array_map(fn($k, $l) => substr($col, -3) === '_id' ? dimp_option_out($k, $opts, true) : (string)$k, array_keys($opts), $opts);
                $hint = ($type === 'multicheck' ? 'Comma-separated values, ' : '') . (count($list) <= 14 ? 'one of: ' . implode(', ', array_filter($list, 'strlen')) . '.' : 'the code or the name.');
                $ex = $list[0] ?? '';
                break;
            default:
                $hint = '';
        }
        $cols[$col] = [$f['label'], !empty($f['required']), trim($hint . ' ' . $help), $ex];
    }
    $def['columns'] = $cols;
    $def['what'] = $def['what'] ?: 'the rows of the ' . $cfg['title'] . '.';
    $def['opts'] = ['update' => ['type' => 'checkbox', 'label' => 'Update existing records: a row whose ' . implode(' + ', array_map(fn($k) => $cols[$k][0], $keys)) . ' already exists replaces its values (blank cells keep the current value)', 'default' => true]];
    $keyOf = function (array $vals) use ($keys): string {
        return implode('|', array_map(fn($k) => mb_strtolower(trim((string)($vals[$k] ?? ''))), $keys));
    };
    $parse = function (array $d, callable $err) use ($fields): array {
        $out = [];
        foreach ($fields as $col => $f) {
            $type = $f['type'] ?? 'text';
            $v = trim((string)($d[$col] ?? ''));
            if ($v === '') {
                continue;   // blank: default (new) or current value (update)
            }
            switch ($type) {
                case 'checkbox':
                    $b = csvi_bool($v);
                    if ($b === null) {
                        $err($col, 'Use Y or N.');
                    } else {
                        $out[$col] = $b;
                    }
                    break;
                case 'number':
                case 'money':
                    $n = csvi_num($v);
                    if ($n === null) {
                        $err($col, 'Not a number.');
                    } else {
                        $out[$col] = $n;
                    }
                    break;
                case 'date':
                    $dt = csvi_date($v);
                    if ($dt === null) {
                        $err($col, 'Not a date.');
                    } else {
                        $out[$col] = $dt;
                    }
                    break;
                case 'time':
                    $t = dimp_time($v);
                    if ($t === null) {
                        $err($col, 'Not a time (HH:MM).');
                    } else {
                        $out[$col] = $t;
                    }
                    break;
                case 'select':
                    $m = dimp_match_option($v, $f['options'] ?? []);
                    if ($m === null) {
                        $err($col, 'Unknown value "' . $v . '".');
                    } else {
                        $out[$col] = $m;
                    }
                    break;
                case 'multicheck':
                    $vals = [];
                    foreach (preg_split('/[,;|]+/', $v) as $part) {
                        if (trim($part) === '') {
                            continue;
                        }
                        $m = dimp_match_option($part, $f['options'] ?? []);
                        if ($m === null) {
                            $err($col, 'Unknown value "' . trim($part) . '".');
                        } else {
                            $vals[] = (string)$m;
                        }
                    }
                    $out[$col] = implode(',', array_unique($vals));
                    break;
                default:
                    $max = (int)($f['maxlength'] ?? 255);
                    $out[$col] = mb_substr($v, 0, $max);
            }
        }
        return $out;
    };
    $complete = function (array $vals, ?array $old) use ($fields, $cfg): array {
        $data = [];
        foreach ($fields as $col => $f) {
            if (array_key_exists($col, $vals)) {
                $data[$col] = $vals[$col];
            } elseif ($old && array_key_exists($col, $old)) {
                $data[$col] = $old[$col];
            } elseif (array_key_exists($col, $cfg['defaults'] ?? [])) {
                $data[$col] = $cfg['defaults'][$col];
            } elseif (array_key_exists('default', $f)) {
                $data[$col] = $f['default'];
            } else {
                $data[$col] = ($f['type'] ?? '') === 'checkbox' ? 0 : null;
            }
        }
        return $data;
    };
    $def['check'] = function (array &$rows, array $opt) use ($table, $fields, $keys, $keyOf, $parse, $complete, $cfg, $cols): array {
        $existing = [];
        foreach (rows("SELECT * FROM `$table`") as $x) {
            $existing[$keyOf($x)] = $x;
        }
        $seen = [];
        foreach ($rows as &$r) {
            $err = dimp_reset_row($r);
            $vals = $parse($r['data'], $err);
            foreach ($keys as $k) {
                if (!isset($vals[$k]) || $vals[$k] === '') {
                    $err($k, $cols[$k][0] . ' is required (it identifies the record).');
                }
            }
            $key = $keyOf($vals);
            $old = $existing[$key] ?? null;
            foreach ($fields as $col => $f) {
                if (!empty($f['required']) && !in_array($col, $keys, true) && (!isset($vals[$col]) || $vals[$col] === '') && !($old && $old[$col] !== null && $old[$col] !== '')) {
                    $err($col, $f['label'] . ' is required.');
                }
            }
            if (isset($seen[$key]) && !$r['errors']) {
                $r['row_errors'][] = 'The same ' . implode(' + ', array_map(fn($k) => $cols[$k][0], $keys)) . ' is already on line ' . $seen[$key] . '.';
            }
            $seen[$key] = $seen[$key] ?? $r['line'];
            if ($r['errors']) {
                continue;
            }
            $data = $complete($vals, $old);
            if (!empty($cfg['validate'])) {
                foreach ((array)($cfg['validate'])($data, $old ? (int)$old['id'] : 0) as $m) {
                    $r['row_errors'][] = $m;
                }
            }
            if ($old) {
                if (!empty($opt['update'])) {
                    $r['norm'] = ['action' => 'update', 'id' => (int)$old['id'], 'data' => $data];
                    $changed = array_filter(array_keys($data), fn($c) => (string)$data[$c] !== (string)$old[$c]);
                    $r['notes'][] = $changed ? 'Updates the existing record: ' . implode(', ', array_map(fn($c) => $fields[$c]['label'], array_slice($changed, 0, 6))) . (count($changed) > 6 ? ' …' : '') . '.' : 'Same as the existing record (no change).';
                } else {
                    $r['norm'] = ['action' => 'skip'];
                    $r['notes'][] = 'Already exists; skipped (tick Update existing records to overwrite it).';
                }
            } else {
                $r['norm'] = ['action' => 'new', 'data' => $data];
            }
        }
        unset($r);
        return dimp_finalize($rows);
    };
    $def['commit'] = function (array $rows, array $opt) use ($table, $cfg, $def): array {
        $out = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        foreach ($rows as $r) {
            $n = $r['norm'];
            if ($n['action'] === 'update') {
                $old = row("SELECT * FROM `$table` WHERE id = ?", [$n['id']]);
                update($table, $n['data'], 'id = ?', [$n['id']]);
                audit($def['module'], 'update', $table, $n['id'], diff_fields($old ?: [], $n['data']) + ['source' => 'csv import']);
                $id = $n['id'];
                $out['updated']++;
            } elseif ($n['action'] === 'new') {
                $id = insert($table, $n['data']);
                audit($def['module'], 'create', $table, $id, $n['data'] + ['source' => 'csv import']);
                $out['created']++;
            } else {
                $out['skipped']++;
                continue;
            }
            if (!empty($cfg['after_save'])) {
                ($cfg['after_save'])($id, $n['data']);
            }
        }
        return $out;
    };
    $def['template'] = function () use (&$def, $fields): array {
        [$h, $rows] = ($def['export'])([]);
        return [$h, $rows ? [$rows[0]] : [array_map(fn($c) => $c[3], array_values($def['columns']))]];
    };
    $def['export'] = function () use ($table, $fields, $cfg): array {
        $out = [];
        foreach (rows("SELECT * FROM `$table`" . (!empty($cfg['where']) ? ' WHERE ' . $cfg['where'] : '') . ' ORDER BY ' . ($cfg['order'] ?? 'id'), $cfg['where_params'] ?? []) as $x) {
            $row = [];
            foreach ($fields as $col => $f) {
                $v = $x[$col] ?? null;
                $type = $f['type'] ?? 'text';
                $row[$col] = match ($type) {
                    'checkbox' => $v ? 'Y' : 'N',
                    'select' => dimp_option_out($v, $f['options'] ?? [], substr($col, -3) === '_id'),
                    'time' => $v ? substr((string)$v, 0, 5) : '',
                    default => (string)($v ?? ''),
                };
            }
            $out[] = $row;
        }
        return [array_keys($fields), $out];
    };
    return $def;
}

/* =========================================================================== payroll imports (csvimport.php) */

function dimp_csvi_def(array $def): array
{
    $k = $def['kind'];
    $kd = csvi_kinds()[$k];
    $def['columns'] = csvi_columns($k);
    $def['what'] = $kd['what'];
    if ($kd['period']) {
        $def['opts']['cutoff_id'] = ['type' => 'period', 'label' => 'Pay period of the file', 'default' => $def['ctx']['cutoff_id'] ?? null];
    }
    if ($kd['replace']) {
        $def['opts']['mode'] = ['type' => 'select', 'label' => 'Existing entries of the period', 'default' => 'keep', 'options' => [
            'keep' => 'Keep them: employees who already have a transaction are skipped',
            'replace' => 'Replace the entries of the employees in the file',
            'replace_all' => 'Replace every entry of the period (employees not in the file are removed)']];
    }
    if ($kd['update']) {
        $def['opts']['update'] = ['type' => 'checkbox', 'label' => 'Update existing: a row for an employee who already has an active entry of the same code overwrites it', 'default' => false];
    }
    $def['read'] = fn(string $path) => csvi_read($path, $k);
    $def['check'] = function (array &$rows, array $opt) use ($k): array {
        $c = csvi_check($k, $rows, $opt);
        $c['skip'] = $c['keep'] ?? 0;
        return $c;
    };
    $def['commit'] = function (array $rows, array $opt, string $file) use ($k): array {
        $r = csvi_commit($k, $rows, $opt, $file);
        return ['created' => $r['created'], 'updated' => $r['updated'], 'replaced' => $r['replaced'], 'skipped' => $r['kept'] + ($r['locked'] ?? 0), 'removed' => $r['removed'], 'batch' => $r['batch']];
    };
    $def['template'] = function (array $opt) use ($k, $kd) {
        $period = $kd['period'] ? pt_default_period((int)($opt['cutoff_id'] ?? 0) ?: null) : null;
        return csvi_template($k, $period, false);
    };
    if (in_array($k, ['pay_trans', 'att_batch', 'emp_trans'], true)) {
        $def['export_label'] = in_array($k, ['pay_trans', 'att_batch'], true) ? 'Format with the employees and figures of the period' : 'Format with the employees of the period';
        $def['export'] = function (array $opt) use ($k) {
            $period = pt_default_period((int)($opt['cutoff_id'] ?? 0) ?: null);
            return csvi_template($k, $period, true);
        };
    }
    return $def;
}

/* =========================================================================== custom: Department Table */

function dimp_def_departments(array $def): array
{
    $def['columns'] = [
        'code' => ['Unit code', true, 'Unique code of the unit.', 'PROD'],
        'name' => ['Unit name', true, '', 'Production Department'],
        'unit_type' => ['Type', false, 'Company, Division, Department, Section or Unit. Blank = Department.', 'Department'],
        'parent_code' => ['Parent unit code', false, 'Code of the unit above it (already in the table or in this file). Blank = top level.', 'OPS'],
        'head_employee_no' => ['Head (employee no.)', false, 'Unit head, who receives the approvals.', ''],
        'default_pay_code' => ['Default pay code', false, 'Code of the Pay Code Table.', ''],
        'sort_order' => ['Sort order', false, 'Number. Blank = 0.', '0'],
        'is_active' => ['Active', false, 'Y or N. Blank = Y.', 'Y'],
    ];
    $def['opts']['update'] = ['type' => 'checkbox', 'label' => 'Update existing units: a row whose unit code exists replaces its values (blank cells keep the current value)', 'default' => true];
    $def['check'] = function (array &$rows, array $opt): array {
        $units = [];
        foreach (rows('SELECT * FROM org_units') as $u) {
            $units[strtoupper($u['code'])] = $u;
        }
        $pg = [];
        foreach (rows('SELECT id, code FROM pay_groups') as $g) {
            $pg[strtoupper($g['code'])] = (int)$g['id'];
        }
        $inFile = [];
        foreach ($rows as $r) {
            $c = strtoupper(trim((string)$r['data']['code']));
            if ($c !== '') {
                $inFile[$c] = $inFile[$c] ?? $r['line'];
            }
        }
        $parents = [];
        foreach ($rows as &$r) {
            $err = dimp_reset_row($r);
            $d = $r['data'];
            $code = strtoupper(trim((string)$d['code']));
            if ($code === '') {
                $err('code', 'Unit code is required.');
            } elseif (strlen($code) > 20) {
                $err('code', 'At most 20 characters.');
            } elseif ($inFile[$code] !== $r['line']) {
                $r['row_errors'][] = 'The unit code ' . $code . ' is already on line ' . $inFile[$code] . '.';
            }
            $old = $units[$code] ?? null;
            if (trim((string)$d['name']) === '' && !$old) {
                $err('name', 'Unit name is required.');
            }
            $parent = strtoupper(trim((string)$d['parent_code']));
            if ($parent !== '') {
                if ($parent === $code) {
                    $err('parent_code', 'A unit cannot be its own parent.');
                } elseif (!isset($units[$parent]) && !isset($inFile[$parent])) {
                    $err('parent_code', 'No unit has the code ' . $parent . ' (in the table or in this file).');
                }
            }
            $head = null;
            if (trim((string)$d['head_employee_no']) !== '') {
                $e = dimp_employee((string)$d['head_employee_no']);
                if (!$e) {
                    $err('head_employee_no', 'No employee has this number.');
                } else {
                    $head = (int)$e['id'];
                }
            }
            $pgId = null;
            if (trim((string)$d['default_pay_code']) !== '') {
                $pgId = $pg[strtoupper(trim((string)$d['default_pay_code']))] ?? null;
                if (!$pgId) {
                    $err('default_pay_code', 'Unknown pay code.');
                }
            }
            $sort = csvi_num((string)$d['sort_order']);
            if ($sort === null) {
                $err('sort_order', 'Not a number.');
            }
            $act = csvi_bool((string)$d['is_active']);
            if ($act === null) {
                $err('is_active', 'Use Y or N.');
            }
            $parents[$code] = $parent !== '' ? $parent : ($old && $old['parent_id'] ? null : '');
            if ($r['errors'] || $r['row_errors']) {
                continue;
            }
            $data = ['code' => $code, 'name' => trim((string)$d['name']) !== '' ? mb_substr(trim((string)$d['name']), 0, 120) : $old['name'],
                'unit_type' => trim((string)$d['unit_type']) !== '' ? mb_substr(ucfirst(strtolower(trim((string)$d['unit_type']))), 0, 40) : ($old['unit_type'] ?? 'Department'),
                'head_employee_id' => $head ?? ($old['head_employee_id'] ?? null), 'default_pay_group_id' => $pgId ?? ($old['default_pay_group_id'] ?? null),
                'sort_order' => $sort !== '' ? (int)$sort : (int)($old['sort_order'] ?? 0), 'is_active' => $act !== '' ? $act : (int)($old['is_active'] ?? 1)];
            $r['norm'] = ['action' => $old ? (!empty($opt['update']) ? 'update' : 'skip') : 'new', 'id' => $old ? (int)$old['id'] : null, 'data' => $data, 'parent' => $parent];
            if ($old && empty($opt['update'])) {
                $r['notes'][] = 'Already exists; skipped (tick Update existing units to overwrite it).';
            } elseif ($old) {
                $r['notes'][] = 'Updates the existing unit.';
            }
        }
        unset($r);
        // loops in the parent chain (inside the file or with the table)
        $parentOf = [];
        foreach ($units as $c => $u) {
            $parentOf[$c] = $u['parent_id'] ? (string)(array_values(array_filter($units, fn($x) => (int)$x['id'] === (int)$u['parent_id']))[0]['code'] ?? '') : '';
        }
        foreach ($rows as $r) {
            if (!empty($r['norm']['data']) && $r['norm']['action'] !== 'skip') {
                $parentOf[$r['norm']['data']['code']] = $r['norm']['parent'];
            }
        }
        foreach ($rows as &$r) {
            if (empty($r['norm']['data'])) {
                continue;
            }
            $c = $r['norm']['data']['code'];
            $seen = [$c => true];
            for ($p = $parentOf[$c] ?? '', $g = 0; $p !== '' && $g < 50; $p = $parentOf[$p] ?? '', $g++) {
                if (isset($seen[$p])) {
                    $r['row_errors'][] = 'The parent units make a loop (' . $c . ' is under itself).';
                    break;
                }
                $seen[$p] = true;
            }
        }
        unset($r);
        return dimp_finalize($rows);
    };
    $def['commit'] = function (array $rows, array $opt) use ($def): array {
        $out = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $ids = [];
        foreach ($rows as $r) {
            $n = $r['norm'];
            if ($n['action'] === 'skip') {
                $out['skipped']++;
                continue;
            }
            if ($n['action'] === 'update') {
                update('org_units', $n['data'], 'id = ?', [$n['id']]);
                $ids[$n['data']['code']] = $n['id'];
                $out['updated']++;
            } else {
                $ids[$n['data']['code']] = insert('org_units', $n['data']);
                $out['created']++;
            }
        }
        foreach (rows('SELECT id, code FROM org_units') as $u) {
            $ids[strtoupper($u['code'])] = $ids[strtoupper($u['code'])] ?? (int)$u['id'];
        }
        foreach ($rows as $r) {
            $n = $r['norm'];
            if ($n['action'] === 'skip') {
                continue;
            }
            if ($n['parent'] !== '') {
                q('UPDATE org_units SET parent_id = ? WHERE code = ?', [$ids[$n['parent']] ?? null, $n['data']['code']]);
            } elseif ($n['action'] === 'new') {
                q('UPDATE org_units SET parent_id = NULL WHERE code = ?', [$n['data']['code']]);
            }
        }
        audit('setup', 'csv_import_departments', 'org_units', null, $out);
        unset($GLOBALS['__org_units_all']);
        return $out;
    };
    $def['export'] = function (): array {
        $units = rows('SELECT u.*, p.code AS parent_code, e.employee_no AS head_no, g.code AS pay_code FROM org_units u LEFT JOIN org_units p ON p.id = u.parent_id
                       LEFT JOIN employees e ON e.id = u.head_employee_id LEFT JOIN pay_groups g ON g.id = u.default_pay_group_id ORDER BY u.sort_order, u.code');
        return [['code', 'name', 'unit_type', 'parent_code', 'head_employee_no', 'default_pay_code', 'sort_order', 'is_active'],
            array_map(fn($u) => [$u['code'], $u['name'], $u['unit_type'], $u['parent_code'], $u['head_no'], $u['pay_code'], $u['sort_order'], $u['is_active'] ? 'Y' : 'N'], $units)];
    };
    return $def;
}

/* =========================================================================== custom: Code of Conduct & Penalties */

const DIMP_PENALTIES = ['verbal_warning' => 'Verbal Warning', 'written_warning' => 'Written Warning', 'suspension' => 'Suspension', 'dismissal' => 'Dismissal'];

function dimp_def_conduct(array $def): array
{
    $def['columns'] = [
        'code' => ['Offense code', true, 'Same code on every row of the offense.', 'ATT-01'],
        'category' => ['Offense category', true, 'On the first row of the offense at least.', 'Tardiness'],
        'severity' => ['Severity', false, 'light, less grave or grave. Blank = light.', 'light'],
        'description' => ['Description / covered acts', false, '', 'Habitual tardiness'],
        'is_active' => ['Active', false, 'Y or N. Blank = Y.', 'Y'],
        'offense_no' => ['Offense no.', true, '1 for the 1st offense, 2 for the 2nd ...', '1'],
        'penalty' => ['Penalty', true, 'verbal warning, written warning, suspension or dismissal.', 'written warning'],
        'min_days' => ['Min days', false, 'Suspension days.', ''],
        'max_days' => ['Max days', false, '', ''],
        'notes' => ['Notes', false, '', ''],
        'reset_days' => ['No. of Days to reset Penalty', false, 'Optional: an offense more than this many days after the last one counts as a first offense. Blank = no reset.', ''],
    ];
    $def['opts']['update'] = ['type' => 'checkbox', 'label' => 'Update existing offenses: an offense code already in the table gets the values and the penalties of the file', 'default' => true];
    $def['check'] = function (array &$rows, array $opt): array {
        $have = pairs('SELECT UPPER(code), id FROM conduct_offenses');
        $groups = [];
        foreach ($rows as $i => &$r) {
            $err = dimp_reset_row($r);
            $d = $r['data'];
            $code = strtoupper(trim((string)$d['code']));
            if ($code === '') {
                $err('code', 'Offense code is required.');
            }
            $no = csvi_num((string)$d['offense_no']);
            if ($no === null || $no === '' || (int)$no < 1 || (int)$no > 20 || (float)$no != (int)$no) {
                $err('offense_no', 'A whole number from 1 (1st offense).');
            }
            $pen = dimp_match_option((string)$d['penalty'], DIMP_PENALTIES);
            if (!$pen) {
                $err('penalty', 'Use verbal warning, written warning, suspension or dismissal.');
            }
            foreach (['min_days', 'max_days'] as $k) {
                $n = csvi_num((string)$d[$k]);
                if ($n === null || ($n !== '' && ((int)$n < 0 || (int)$n > 127))) {
                    $err($k, 'Days from 0 to 127.');
                }
            }
            if ($d['min_days'] !== '' && $d['max_days'] !== '' && (int)$d['max_days'] < (int)$d['min_days']) {
                $err('max_days', 'Smaller than Min days.');
            }
            if (trim((string)$d['severity']) !== '' && !dimp_match_option((string)$d['severity'], ['light' => 'Light', 'less_grave' => 'Less grave', 'grave' => 'Grave'])) {
                $err('severity', 'Use light, less grave or grave.');
            }
            if (csvi_bool((string)$d['is_active']) === null) {
                $err('is_active', 'Use Y or N.');
            }
            $rdv = csvi_num((string)($d['reset_days'] ?? ''));
            if ($rdv === null || ($rdv !== '' && ((int)$rdv < 1 || (int)$rdv > 3650))) {
                $err('reset_days', 'Days from 1 to 3650, or blank.');
            }
            if ($code !== '') {
                $groups[$code][] = $i;
            }
        }
        unset($r);
        foreach ($groups as $code => $idx) {
            $first = $rows[$idx[0]]['data'];
            $cat = '';
            $nos = [];
            foreach ($idx as $i) {
                $cat = $cat ?: trim((string)$rows[$i]['data']['category']);
                $no = (int)$rows[$i]['data']['offense_no'];
                if (isset($nos[$no])) {
                    $rows[$i]['row_errors'][] = 'Offense no. ' . $no . ' of ' . $code . ' is already on line ' . $nos[$no] . '.';
                }
                $nos[$no] = $nos[$no] ?? $rows[$i]['line'];
                foreach (['category', 'severity', 'description'] as $k) {
                    $v = trim((string)$rows[$i]['data'][$k]);
                    $f = trim((string)$first[$k]);
                    if ($v !== '' && $f !== '' && $v !== $f) {
                        $rows[$i]['errors'][$k] = 'Differs from line ' . $rows[$idx[0]]['line'] . ' of the same offense.';
                    }
                }
            }
            if ($cat === '') {
                $rows[$idx[0]]['errors']['category'] = 'Offense category is required.';
            }
            $exists = isset($have[$code]);
            foreach ($idx as $j => $i) {
                $rows[$i]['norm'] = ['action' => $exists ? (!empty($opt['update']) ? 'update' : 'skip') : 'new', 'code' => $code, 'lead' => $j === 0];
                if ($j === 0) {
                    $rows[$i]['notes'][] = ($exists ? (!empty($opt['update']) ? 'Updates offense ' . $code . ' and replaces its penalties with the ' : 'Offense ' . $code . ' exists; skipped with its ') : 'New offense with ') . count($idx) . ' penalty step(s).';
                }
            }
        }
        return dimp_finalize($rows);
    };
    $def['commit'] = function (array $rows, array $opt): array {
        $out = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $g = [];
        foreach ($rows as $r) {
            $g[$r['norm']['code']][] = $r;
        }
        foreach ($g as $code => $list) {
            $act = $list[0]['norm']['action'];
            if ($act === 'skip') {
                $out['skipped']++;
                continue;
            }
            $pick = function (string $k) use ($list) {
                foreach ($list as $r) {
                    if (trim((string)$r['data'][$k]) !== '') {
                        return trim((string)$r['data'][$k]);
                    }
                }
                return '';
            };
            $d = ['code' => $code, 'category' => mb_substr($pick('category'), 0, 120), 'description' => mb_substr($pick('description'), 0, 500) ?: null,
                'severity' => dimp_match_option($pick('severity'), ['light' => 'Light', 'less_grave' => 'Less grave', 'grave' => 'Grave']) ?: 'light',
                'is_active' => ($b = csvi_bool($pick('is_active'))) === '' ? 1 : $b];
            if (val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'conduct_offenses' AND column_name = 'reset_days'")) {
                $d['reset_days'] = $pick('reset_days') === '' ? null : (int)$pick('reset_days');
            }
            $id = (int)val('SELECT id FROM conduct_offenses WHERE code = ?', [$code]);
            if ($id) {
                update('conduct_offenses', $d, 'id = ?', [$id]);
                $out['updated']++;
            } else {
                $id = insert('conduct_offenses', $d);
                $out['created']++;
            }
            q('DELETE FROM conduct_penalties WHERE offense_id = ?', [$id]);
            usort($list, fn($a, $b) => (int)$a['data']['offense_no'] <=> (int)$b['data']['offense_no']);
            $n = 0;
            foreach ($list as $r) {
                insert('conduct_penalties', ['offense_id' => $id, 'offense_no' => ++$n, 'penalty_type' => dimp_match_option((string)$r['data']['penalty'], DIMP_PENALTIES),
                    'min_days' => $r['data']['min_days'] === '' ? null : (int)$r['data']['min_days'], 'max_days' => $r['data']['max_days'] === '' ? null : (int)$r['data']['max_days'],
                    'notes' => trim((string)$r['data']['notes']) ?: null]);
            }
            audit('pis', 'csv_import', 'conduct_offenses', $id, $d + ['penalties' => $n]);
        }
        return $out;
    };
    $def['export'] = function (): array {
        $out = [];
        foreach (rows('SELECT o.*, p.offense_no, p.penalty_type, p.min_days, p.max_days, p.notes FROM conduct_offenses o LEFT JOIN conduct_penalties p ON p.offense_id = o.id ORDER BY o.id, p.offense_no') as $x) {
            $out[] = [$x['code'], $x['category'], str_replace('_', ' ', $x['severity']), $x['description'], $x['is_active'] ? 'Y' : 'N', $x['offense_no'],
                $x['penalty_type'] ? strtolower(DIMP_PENALTIES[$x['penalty_type']]) : '', $x['min_days'], $x['max_days'], $x['notes'], $x['reset_days'] ?? ''];
        }
        return [['code', 'category', 'severity', 'description', 'is_active', 'offense_no', 'penalty', 'min_days', 'max_days', 'notes', 'reset_days'], $out];
    };
    return $def;
}

/* =========================================================================== custom: Transaction Payment */

function dimp_def_trans_payments(array $def): array
{
    $def['columns'] = [
        'employee_no' => ['Employee No.', true, 'As in the Employee Masterfile.', 'EMP-0001'],
        'employee_name' => ['Employee name', false, 'For your reference only; not read.', ''],
        'ledger_no' => ['Ledger no.', false, 'Number of the ledger (Employee Term Loan/Payables). Blank = the only open ledger of the employee of the Ledger type.', 'LG-2026-0001'],
        'ledger_type' => ['Ledger type', false, 'gov_loan, company_loan, cash_advance, payable or accountability; or the loan name (e.g. SSS Salary Loan). Used when Ledger no. is blank.', ''],
        'pay_date' => ['Payment date', true, 'YYYY-MM-DD or MM/DD/YYYY.', date('Y-m-d')],
        'amount' => ['Amount paid', true, 'More than zero, not more than the balance.', '1000.00'],
        'reference_no' => ['Reference no.', false, 'Official receipt or deposit reference.', 'OR-1001'],
        'remarks' => ['Remarks', false, '', ''],
    ];
    $def['check'] = function (array &$rows, array $opt): array {
        $byEmp = [];
        foreach (rows("SELECT * FROM ledgers WHERE status = 'active' AND ledger_type <> 'accrued_benefit' AND balance > 0") as $l) {
            $byEmp[(int)$l['employee_id']][] = $l;
        }
        $byNo = [];
        foreach (rows("SELECT * FROM ledgers WHERE ledger_type <> 'accrued_benefit'") as $l) {
            $byNo[strtoupper($l['ledger_no'])] = $l;
        }
        $used = [];
        foreach ($rows as &$r) {
            $err = dimp_reset_row($r);
            $d = $r['data'];
            $e = dimp_employee((string)$d['employee_no']);
            if (trim((string)$d['employee_no']) === '') {
                $err('employee_no', 'Employee No. is required.');
            } elseif (!$e) {
                $err('employee_no', dimp_unknown_employee((string)$d['employee_no']));
            }
            $r['norm']['employee_name'] = $e ? full_name($e) : '';
            $l = null;
            if ($e) {
                $no = strtoupper(trim((string)$d['ledger_no']));
                if ($no !== '') {
                    $l = $byNo[$no] ?? null;
                    if (!$l) {
                        $err('ledger_no', 'No ledger has this number.');
                    } elseif ((int)$l['employee_id'] !== (int)$e['id']) {
                        $err('ledger_no', 'This ledger belongs to another employee.');
                        $l = null;
                    } elseif ($l['status'] !== 'active' || (float)$l['balance'] <= 0) {
                        $err('ledger_no', 'This ledger is ' . ($l['status'] !== 'active' ? $l['status'] : 'fully paid') . '.');
                        $l = null;
                    }
                } else {
                    $t = mb_strtolower(trim((string)$d['ledger_type']));
                    $cand = array_values(array_filter($byEmp[(int)$e['id']] ?? [], fn($x) => $t === '' || $x['ledger_type'] === str_replace(' ', '_', $t) || mb_strtolower((string)$x['sub_type']) === $t));
                    if (count($cand) === 1) {
                        $l = $cand[0];
                        $r['notes'][] = 'Ledger ' . $l['ledger_no'] . ' (' . ($l['sub_type'] ?: label($l['ledger_type'])) . ').';
                    } elseif (!$cand) {
                        $err($t === '' ? 'ledger_no' : 'ledger_type', 'The employee has no open ledger' . ($t !== '' ? ' of this type' : '') . '.');
                    } else {
                        $err('ledger_no', 'The employee has ' . count($cand) . ' open ledgers' . ($t !== '' ? ' of this type' : '') . ': enter the ledger no. (' . implode(', ', array_map(fn($x) => $x['ledger_no'], $cand)) . ').');
                    }
                }
            }
            $date = csvi_date((string)$d['pay_date']);
            if ($date === null || $date === '') {
                $err('pay_date', 'Payment date is required (YYYY-MM-DD).');
            } elseif ($date > today()) {
                $err('pay_date', 'In the future.');
            }
            $amt = csvi_num((string)$d['amount']);
            if ($amt === null || $amt === '' || (float)$amt <= 0) {
                $err('amount', 'An amount more than zero.');
            } elseif ($l) {
                $left = (float)$l['balance'] - ($used[(int)$l['id']] ?? 0);
                if ((float)$amt > $left + 0.004) {
                    $err('amount', 'More than the balance of the ledger (' . money($left) . ($used[(int)$l['id']] ?? 0 ? ' after the rows above' : '') . ').');
                } else {
                    $used[(int)$l['id']] = ($used[(int)$l['id']] ?? 0) + (float)$amt;
                    $r['notes'][] = 'Balance after the payment: ' . money($left - (float)$amt) . '.';
                }
            }
            if ($l && $date && $amt && !$r['errors'] && (int)val('SELECT COUNT(*) FROM transaction_payments WHERE ledger_id = ? AND pay_date = ? AND amount = ? AND COALESCE(reference_no, \'\') = ?',
                    [$l['id'], $date, round((float)$amt, 2), trim((string)$d['reference_no'])])) {
                $r['row_errors'][] = 'This payment (same ledger, date, amount and reference) is already recorded.';
            }
            if (!$r['errors'] && !$r['row_errors']) {
                $r['norm'] += ['action' => 'new', 'ledger_id' => (int)$l['id'], 'date' => $date, 'amount' => round((float)$amt, 2),
                    'ref' => mb_substr(trim((string)$d['reference_no']), 0, 40) ?: null, 'remarks' => mb_substr(trim((string)$d['remarks']), 0, 255) ?: null];
            }
        }
        unset($r);
        return dimp_finalize($rows);
    };
    $def['commit'] = function (array $rows): array {
        $out = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        foreach ($rows as $r) {
            $n = $r['norm'];
            ledger_post($n['ledger_id'], $n['date'], 'Payment' . ($n['ref'] ? ' ' . $n['ref'] : '') . ($n['remarks'] ? ' — ' . $n['remarks'] : ''), 0, $n['amount']);
            insert('transaction_payments', ['ledger_id' => $n['ledger_id'], 'pay_date' => $n['date'], 'reference_no' => $n['ref'], 'amount' => $n['amount'], 'remarks' => $n['remarks'], 'created_by' => user_id()]);
            audit('payroll', 'transaction_payment', 'ledgers', $n['ledger_id'], ['amount' => $n['amount'], 'date' => $n['date'], 'ref' => $n['ref'], 'source' => 'csv import']);
            $out['created']++;
        }
        return $out;
    };
    $def['export_label'] = 'Format with the open ledgers';
    $def['export'] = function (): array {
        $out = [];
        foreach (rows("SELECT l.*, e.employee_no, e.last_name, e.first_name, e.middle_name, e.suffix FROM ledgers l JOIN employees e ON e.id = l.employee_id
                       WHERE l.status = 'active' AND l.ledger_type <> 'accrued_benefit' AND l.balance > 0" . user_scope_sql('e') . ' ORDER BY e.last_name, e.first_name, l.id') as $l) {
            $out[] = [$l['employee_no'], full_name($l), $l['ledger_no'], $l['sub_type'] ?: $l['ledger_type'], '', '', '', 'balance ' . number_format((float)$l['balance'], 2, '.', '')];
        }
        return [['employee_no', 'employee_name', 'ledger_no', 'ledger_type', 'pay_date', 'amount', 'reference_no', 'remarks'], $out];
    };
    return $def;
}

/* =========================================================================== custom: Retroactive Adjustments */

function dimp_def_retro(array $def): array
{
    $def['columns'] = [
        'employee_no' => ['Employee No.', true, 'As in the Employee Masterfile.', 'EMP-0001'],
        'employee_name' => ['Employee name', false, 'For your reference only; not read.', ''],
        'effective_date' => ['Effectivity date', true, 'The date the new rate takes effect (in the past).', date('Y-01-01')],
        'old_rate' => ['Old rate (as paid)', true, 'Monthly or daily, as the rate type of the employee.', '20000.00'],
        'new_rate' => ['New rate', true, '', '21000.00'],
        'update_masterfile' => ['Update the masterfile rate', false, 'Y or N. Blank = Y (only when the masterfile still has the old rate).', 'Y'],
        'remarks' => ['Remarks / authority', false, 'e.g. Wage Order NCR-26.', ''],
    ];
    $def['check'] = function (array &$rows, array $opt): array {
        $seen = [];
        foreach ($rows as &$r) {
            $err = dimp_reset_row($r);
            $d = $r['data'];
            $e = dimp_employee((string)$d['employee_no']);
            if (!$e) {
                $err('employee_no', trim((string)$d['employee_no']) === '' ? 'Employee No. is required.' : dimp_unknown_employee((string)$d['employee_no']));
            } elseif ($e['status'] !== 'active') {
                $err('employee_no', 'This employee is ' . $e['status'] . '.');
            }
            $r['norm']['employee_name'] = $e ? full_name($e) : '';
            $date = csvi_date((string)$d['effective_date']);
            if (!$date) {
                $err('effective_date', 'Effectivity date is required (YYYY-MM-DD).');
            } elseif ($date > today()) {
                $err('effective_date', 'In the future: save the new rate on the employee record instead.');
            }
            $old = csvi_num((string)$d['old_rate']);
            $new = csvi_num((string)$d['new_rate']);
            if ($old === null || $old === '' || (float)$old <= 0) {
                $err('old_rate', 'A rate more than zero.');
            }
            if ($new === null || $new === '' || (float)$new <= 0) {
                $err('new_rate', 'A rate more than zero.');
            } elseif ($old && abs((float)$new - (float)$old) < 0.005) {
                $err('new_rate', 'Same as the old rate.');
            }
            $upd = csvi_bool((string)$d['update_masterfile']);
            if ($upd === null) {
                $err('update_masterfile', 'Use Y or N.');
            }
            if ($e && isset($seen[$e['id']])) {
                $r['row_errors'][] = 'The same employee is already on line ' . $seen[$e['id']] . '.';
            }
            if ($e) {
                $seen[$e['id']] = $seen[$e['id']] ?? $r['line'];
            }
            if ($r['errors'] || $r['row_errors']) {
                continue;
            }
            $upd = $upd === '' ? 1 : $upd;
            $match = abs((float)$e['basic_rate'] - (float)$old) < 0.005;
            if ($upd && !$match) {
                $r['notes'][] = 'The masterfile rate is ' . money($e['basic_rate']) . ', not the old rate: only the differential is computed, the masterfile is not changed.';
            } elseif ($upd) {
                $r['notes'][] = 'The masterfile rate becomes ' . money($new) . ' from ' . fmt_date($date) . '.';
            }
            $r['norm'] += ['action' => 'new', 'employee_id' => (int)$e['id'], 'date' => $date, 'old' => (float)$old, 'new' => (float)$new, 'update' => $upd && $match,
                'rate_type' => $e['rate_type'], 'remarks' => trim((string)$d['remarks']) ?: 'Retroactive rate adjustment (CSV import)'];
        }
        unset($r);
        return dimp_finalize($rows);
    };
    $def['commit'] = function (array $rows): array {
        $out = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'amount' => 0.0];
        foreach ($rows as $r) {
            $n = $r['norm'];
            if ($n['update']) {
                $res = employee_apply_changes($n['employee_id'], ['basic_rate' => $n['new']], $n['date'], $n['remarks'], 'salary_change');
                $x = $res['retro'] ?? null;
                $out['updated']++;
            } else {
                $x = retro_compute($n['employee_id'], $n['date'], $n['old'], $n['new'], $n['rate_type']);
            }
            if ($x) {
                $out['created']++;
                $out['amount'] += (float)$x['amount'];
            } else {
                $out['skipped']++;
            }
        }
        $out['message'] = $out['created'] . ' differential(s) computed and queued for the next payroll (' . peso($out['amount']) . ')' . ($out['updated'] ? ', ' . $out['updated'] . ' masterfile rate(s) updated' : '')
            . ($out['skipped'] ? ', ' . $out['skipped'] . ' with no finalized period paid at the old rate' : '');
        audit('payroll', 'csv_import_retro', 'retro_adjustments', null, $out);
        return $out;
    };
    return $def;
}

/* =========================================================================== custom: YTD Info (Previous Employer) */

function dimp_def_prev_ytd(array $def): array
{
    $cols = [
        'employee_no' => ['Employee No.', true, 'As in the Employee Masterfile.', 'EMP-0001'],
        'employee_name' => ['Employee name', false, 'For your reference only; not read.', ''],
        'year' => ['Year', true, '', date('Y')],
        'employer_name' => ['Previous employer', false, '', 'ABC Corporation'],
        'employer_tin' => ['TIN of previous employer', false, '', ''],
        'date_from' => ['Employed from', false, 'YYYY-MM-DD.', ''],
        'date_to' => ['To', false, 'YYYY-MM-DD.', ''],
    ];
    foreach (PREV_YTD_FIELDS as $k => [$lbl]) {
        $cols[$k] = [$lbl, false, 'Amount. Blank = 0.', $k === 'taxable' ? '150000.00' : ($k === 'tax' ? '5000.00' : '')];
    }
    $cols['remarks'] = ['Remarks', false, '', ''];
    $def['columns'] = $cols;
    $def['opts']['update'] = ['type' => 'checkbox', 'label' => 'Update existing: a row for an employee and year already encoded replaces those figures', 'default' => true];
    $def['check'] = function (array &$rows, array $opt): array {
        $have = [];
        foreach (rows('SELECT id, employee_id, year FROM employee_prev_ytd') as $x) {
            $have[$x['employee_id'] . '|' . $x['year']] = (int)$x['id'];
        }
        $seen = [];
        foreach ($rows as &$r) {
            $err = dimp_reset_row($r);
            $d = $r['data'];
            $e = dimp_employee((string)$d['employee_no']);
            if (!$e) {
                $err('employee_no', trim((string)$d['employee_no']) === '' ? 'Employee No. is required.' : dimp_unknown_employee((string)$d['employee_no']));
            }
            $r['norm']['employee_name'] = $e ? full_name($e) : '';
            $y = (int)$d['year'];
            if ($y < 2000 || $y > (int)date('Y') + 1 || (string)$y !== trim((string)$d['year'])) {
                $err('year', 'A year from 2000 to ' . ((int)date('Y') + 1) . '.');
            }
            $vals = [];
            foreach (array_keys(PREV_YTD_FIELDS) as $k) {
                $n = csvi_num((string)$d[$k]);
                if ($n === null || ($n !== '' && (float)$n < 0)) {
                    $err($k, 'An amount of zero or more.');
                }
                $vals[$k] = round((float)$n, 2);
            }
            if ($vals['gross'] > 0 && $vals['taxable'] > $vals['gross']) {
                $err('taxable', 'More than YTD Gross.');
            }
            $from = csvi_date((string)$d['date_from']);
            $to = csvi_date((string)$d['date_to']);
            if ($from === null) {
                $err('date_from', 'Not a date.');
            }
            if ($to === null) {
                $err('date_to', 'Not a date.');
            } elseif ($from && $to && $to < $from) {
                $err('date_to', 'Before Employed from.');
            }
            $key = ($e['id'] ?? 0) . '|' . $y;
            if ($e && isset($seen[$key])) {
                $r['row_errors'][] = 'The same employee and year is already on line ' . $seen[$key] . '.';
            }
            $seen[$key] = $seen[$key] ?? $r['line'];
            if ($r['errors'] || $r['row_errors']) {
                continue;
            }
            $data = $vals + ['employer_name' => mb_substr(trim((string)$d['employer_name']), 0, 150) ?: null, 'employer_tin' => mb_substr(trim((string)$d['employer_tin']), 0, 20) ?: null,
                'date_from' => $from ?: null, 'date_to' => $to ?: null, 'remarks' => mb_substr(trim((string)$d['remarks']), 0, 255) ?: null];
            $exists = $have[$key] ?? null;
            $r['norm'] += ['action' => $exists ? (!empty($opt['update']) ? 'update' : 'skip') : 'new', 'id' => $exists, 'employee_id' => (int)$e['id'], 'year' => $y, 'data' => $data];
            if ($exists) {
                $r['notes'][] = !empty($opt['update']) ? 'Replaces the figures already encoded for ' . $y . '.' : 'Already encoded for ' . $y . '; skipped.';
            }
        }
        unset($r);
        return dimp_finalize($rows);
    };
    $def['commit'] = function (array $rows): array {
        $out = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        foreach ($rows as $r) {
            $n = $r['norm'];
            if ($n['action'] === 'skip') {
                $out['skipped']++;
            } elseif ($n['action'] === 'update') {
                update('employee_prev_ytd', $n['data'] + ['updated_at' => now()], 'id = ?', [$n['id']]);
                audit('payroll', 'prev_ytd_update', 'employees', $n['employee_id'], ['year' => $n['year'], 'source' => 'csv import']);
                $out['updated']++;
            } else {
                insert('employee_prev_ytd', $n['data'] + ['employee_id' => $n['employee_id'], 'year' => $n['year'], 'created_by' => user_id(), 'created_at' => now(), 'updated_at' => now()]);
                audit('payroll', 'prev_ytd_create', 'employees', $n['employee_id'], ['year' => $n['year'], 'taxable' => $n['data']['taxable'], 'tax' => $n['data']['tax'], 'source' => 'csv import']);
                $out['created']++;
            }
        }
        return $out;
    };
    $def['export'] = function (array $opt) use ($cols): array {
        $out = [];
        foreach (rows('SELECT y.*, e.employee_no, e.last_name, e.first_name, e.middle_name, e.suffix FROM employee_prev_ytd y JOIN employees e ON e.id = y.employee_id' . ' WHERE 1=1' . user_scope_sql('e') . ' ORDER BY y.year DESC, e.last_name') as $x) {
            $row = [];
            foreach (array_keys($cols) as $k) {
                $row[$k] = $k === 'employee_name' ? full_name($x) : (string)($x[$k] ?? '');
            }
            $out[] = $row;
        }
        return [array_keys($cols), $out];
    };
    return $def;
}

/* =========================================================================== custom: Leave Balances manual adjustment */

function dimp_def_leave_adj(array $def): array
{
    $def['columns'] = [
        'employee_no' => ['Employee No.', true, 'As in the Employee Masterfile.', 'EMP-0001'],
        'employee_name' => ['Employee name', false, 'For your reference only; not read.', ''],
        'leave_code' => ['Leave type code', true, 'Code of the Leave Category Table (e.g. VL, SL, SIL).', 'VL'],
        'year' => ['Year', false, 'Blank = the year chosen below.', ''],
        'days' => ['Days', true, 'Days added; a minus sign deducts (e.g. -1.5).', '2'],
        'reason' => ['Reason', true, 'Why the balance is adjusted.', 'Beginning balance from the old system'],
    ];
    $def['opts']['year'] = ['type' => 'number', 'label' => 'Year of the balances', 'default' => $def['ctx']['year'] ?? (int)date('Y')];
    $def['opts']['adj_mode'] = ['type' => 'select', 'label' => 'How the days are applied', 'default' => 'add', 'options' => [
        'add' => 'Add to the adjustment already on the balance', 'set' => 'Set the adjustment of the balance to these days (replaces the earlier manual adjustments)']];
    $def['check'] = function (array &$rows, array $opt): array {
        $types = [];
        foreach (rows("SELECT * FROM leave_types WHERE accrual_method <> 'per_event'") as $t) {
            $types[strtoupper($t['code'])] = $t;
        }
        $seen = [];
        foreach ($rows as &$r) {
            $err = dimp_reset_row($r);
            $d = $r['data'];
            $e = dimp_employee((string)$d['employee_no']);
            if (!$e) {
                $err('employee_no', trim((string)$d['employee_no']) === '' ? 'Employee No. is required.' : dimp_unknown_employee((string)$d['employee_no']));
            } elseif ($e['status'] !== 'active') {
                $err('employee_no', 'This employee is ' . $e['status'] . '.');
            }
            $r['norm']['employee_name'] = $e ? full_name($e) : '';
            $t = $types[strtoupper(trim((string)$d['leave_code']))] ?? null;
            if (!$t) {
                $err('leave_code', trim((string)$d['leave_code']) === '' ? 'Leave type code is required.' : 'Unknown leave type, or a type without a balance (per event).');
            }
            $y = trim((string)$d['year']) === '' ? (int)($opt['year'] ?? date('Y')) : (int)$d['year'];
            if ($y < 2000 || $y > (int)date('Y') + 1) {
                $err('year', 'A year from 2000 to ' . ((int)date('Y') + 1) . '.');
            }
            $days = csvi_num((string)$d['days']);
            if ($days === null || $days === '' || (($opt['adj_mode'] ?? 'add') === 'add' && (float)$days == 0)) {
                $err('days', 'A number of days (not zero).');
            } elseif (abs((float)$days) > 365) {
                $err('days', 'More than 365 days.');
            }
            if (trim((string)$d['reason']) === '') {
                $err('reason', 'The reason is required.');
            }
            $key = ($e['id'] ?? 0) . '|' . ($t['id'] ?? 0) . '|' . $y;
            if (($opt['adj_mode'] ?? 'add') === 'set' && isset($seen[$key])) {
                $r['row_errors'][] = 'The same employee, leave type and year is already on line ' . $seen[$key] . '.';
            }
            $seen[$key] = $seen[$key] ?? $r['line'];
            if ($r['errors'] || $r['row_errors']) {
                continue;
            }
            $b = row('SELECT * FROM leave_balances WHERE employee_id = ? AND leave_type_id = ? AND year = ?', [$e['id'], $t['id'], $y]);
            if ($b && $b['closed']) {
                $r['row_errors'][] = 'The ' . $y . ' ' . $t['code'] . ' balance of this employee is closed (year-end).';
                continue;
            }
            $cur = $b ? (float)$b['adjustment'] : 0.0;
            $newAdj = ($opt['adj_mode'] ?? 'add') === 'set' ? (float)$days : $cur + (float)$days;
            $r['notes'][] = $t['code'] . ' ' . $y . ': adjustment ' . rtrim(rtrim(number_format($cur, 2), '0'), '.') . ' → ' . rtrim(rtrim(number_format($newAdj, 2), '0'), '.') . ' day(s).';
            $r['norm'] += ['action' => $b ? 'update' : 'new', 'employee_id' => (int)$e['id'], 'type_id' => (int)$t['id'], 'year' => $y, 'days' => (float)$days, 'reason' => trim((string)$d['reason'])];
        }
        unset($r);
        return dimp_finalize($rows);
    };
    $def['commit'] = function (array $rows, array $opt): array {
        $out = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        foreach ($rows as $r) {
            $n = $r['norm'];
            $b = leave_balance_row($n['employee_id'], $n['type_id'], $n['year']);
            if (($opt['adj_mode'] ?? 'add') === 'set') {
                q('UPDATE leave_balances SET adjustment = ? WHERE id = ?', [$n['days'], $b['id']]);
            } else {
                q('UPDATE leave_balances SET adjustment = adjustment + ? WHERE id = ?', [$n['days'], $b['id']]);
            }
            audit('pis', 'leave_adjust', 'leave_balances', $b['id'], ['employee_id' => $n['employee_id'], 'type' => $n['type_id'], 'days' => $n['days'], 'mode' => $opt['adj_mode'] ?? 'add', 'reason' => $n['reason'], 'source' => 'csv import']);
            $out[$n['action'] === 'update' ? 'updated' : 'created']++;
        }
        return $out;
    };
    $def['export_label'] = 'Format with the active employees';
    $def['export'] = function (array $opt): array {
        $out = [];
        foreach (rows("SELECT employee_no, last_name, first_name, middle_name, suffix FROM employees WHERE status = 'active'" . user_scope_sql('employees') . ' ORDER BY last_name, first_name') as $e) {
            $out[] = [$e['employee_no'], full_name($e), '', (string)($opt['year'] ?? date('Y')), '', ''];
        }
        return [['employee_no', 'employee_name', 'leave_code', 'year', 'days', 'reason'], $out];
    };
    return $def;
}

/* =========================================================================== custom: Employee Shift Entry */

function dimp_def_schedules(array $def): array
{
    $def['columns'] = [
        'employee_no' => ['Employee No.', true, 'As in the Employee Masterfile.', 'EMP-0001'],
        'employee_name' => ['Employee name', false, 'For your reference only; not read.', ''],
        'work_date' => ['Date', true, 'YYYY-MM-DD or MM/DD/YYYY.', date('Y-m-d')],
        'date_to' => ['To date', false, 'Fills every date from Date to this date. Blank = the one date.', ''],
        'shift_code' => ['Shift code', false, 'Code of the Shift Table. Blank = the default shift of the employee.', 'DAY-MF'],
        'rest_day' => ['Rest day', false, 'Y = rest day, N = working day. Blank = follow the shift (its rest days, or the default day-off of the employee on a 7-day shift).', ''],
        'branch_code' => ['Company / branch code', false, 'Blank = the default branch of the employee.', ''],
    ];
    $def['check'] = function (array &$rows, array $opt): array {
        $L = csvi_lookups();
        $shifts = [];
        foreach (rows('SELECT id, code, is_active FROM shifts') as $s) {
            $shifts[strtoupper($s['code'])] = $s;
        }
        $seen = [];
        foreach ($rows as &$r) {
            $err = dimp_reset_row($r);
            $d = $r['data'];
            $e = dimp_employee((string)$d['employee_no']);
            if (!$e) {
                $err('employee_no', trim((string)$d['employee_no']) === '' ? 'Employee No. is required.' : dimp_unknown_employee((string)$d['employee_no']));
            } elseif ($e['status'] !== 'active') {
                $err('employee_no', 'This employee is ' . $e['status'] . '.');
            }
            $r['norm']['employee_name'] = $e ? full_name($e) : '';
            $from = csvi_date((string)$d['work_date']);
            $to = csvi_date((string)$d['date_to']);
            if (!$from) {
                $err('work_date', 'Date is required (YYYY-MM-DD).');
            }
            if ($to === null) {
                $err('date_to', 'Not a date.');
            } elseif ($from && $to && $to < $from) {
                $err('date_to', 'Before Date.');
            } elseif ($from && $to && days_between($from, $to) > 366) {
                $err('date_to', 'More than one year.');
            }
            $shift = null;
            if (trim((string)$d['shift_code']) !== '') {
                $shift = $shifts[strtoupper(trim((string)$d['shift_code']))] ?? null;
                if (!$shift) {
                    $err('shift_code', 'Unknown shift code.');
                } elseif (!$shift['is_active']) {
                    $err('shift_code', 'This shift is inactive.');
                }
            }
            $rest = csvi_bool((string)$d['rest_day']);
            if ($rest === null) {
                $err('rest_day', 'Use Y or N.');
            }
            $branch = null;
            if (trim((string)$d['branch_code']) !== '') {
                $branch = $L['branch'][strtoupper(trim((string)$d['branch_code']))] ?? null;
                if (!$branch) {
                    $err('branch_code', 'Unknown company / branch code.');
                }
            }
            if ($e && $from && !$r['errors']) {
                foreach (date_range($from, $to ?: $from) as $dt) {
                    $k = $e['id'] . '|' . $dt;
                    if (isset($seen[$k])) {
                        $r['row_errors'][] = fmt_date($dt) . ' of this employee is already on line ' . $seen[$k] . '.';
                        break;
                    }
                    $seen[$k] = $r['line'];
                }
            }
            if ($r['errors'] || $r['row_errors']) {
                continue;
            }
            $dates = date_range($from, $to ?: $from);
            $have = (int)val('SELECT COUNT(*) FROM employee_schedules WHERE employee_id = ? AND work_date BETWEEN ? AND ?', [$e['id'], $from, $to ?: $from]);
            $r['notes'][] = count($dates) . ' date(s)' . ($have ? ', ' . $have . ' already with an override (replaced)' : '') . '.';
            $r['norm'] += ['action' => $have ? 'update' : 'new', 'employee_id' => (int)$e['id'], 'dates' => $dates, 'shift_id' => $shift ? (int)$shift['id'] : null,
                'rest' => $rest === '' ? null : $rest, 'branch_id' => $branch ? (int)$branch['id'] : null];
        }
        unset($r);
        return dimp_finalize($rows);
    };
    $def['commit'] = function (array $rows): array {
        $out = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'dates' => 0];
        foreach ($rows as $r) {
            $n = $r['norm'];
            foreach ($n['dates'] as $dt) {
                q('INSERT INTO employee_schedules (employee_id, work_date, shift_id, is_rest_day, branch_id) VALUES (?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE shift_id = VALUES(shift_id), is_rest_day = VALUES(is_rest_day), branch_id = VALUES(branch_id)',
                    [$n['employee_id'], $dt, $n['shift_id'], $n['rest'], $n['branch_id']]);
                if ($dt <= today()) {
                    process_dtr($n['employee_id'], $dt);
                }
                $out['dates']++;
            }
            $out[$n['action'] === 'update' ? 'updated' : 'created']++;
        }
        audit('ams', 'schedule_import', 'employee_schedules', null, $out);
        $out['message'] = $out['dates'] . ' schedule override(s) saved for ' . count($rows) . ' row(s).';
        return $out;
    };
    return $def;
}

/* =========================================================================== the panel on the form */

/** Options of an import from the request (or the defaults). */
function dimp_read_opt(array $def, ?array $src = null): array
{
    $out = [];
    foreach ($def['opts'] as $k => $o) {
        if ($src === null) {
            $out[$k] = $o['default'] ?? null;
            continue;
        }
        switch ($o['type']) {
            case 'checkbox':
                $out[$k] = !empty($src['dimp_opt'][$k]);
                break;
            case 'select':
                $v = (string)($src['dimp_opt'][$k] ?? '');
                $out[$k] = array_key_exists($v, $o['options']) ? $v : ($o['default'] ?? array_key_first($o['options']));
                break;
            default:
                $out[$k] = isset($src['dimp_opt'][$k]) && $src['dimp_opt'][$k] !== '' ? (int)$src['dimp_opt'][$k] : ($o['default'] ?? null);
        }
    }
    return $out;
}

/** Address of the page the panel is on (without the import parameters). */
function dimp_back_url(array $extra = []): string
{
    $qs = $_GET;
    foreach (['dimp', 'dimp_kind', 'dimp_pg', 'dimp_show'] as $k) {
        unset($qs[$k]);
    }
    return 'index.php?' . http_build_query(array_merge($qs, $extra));
}

/**
 * Handle the requests of the panel: downloads (GET dimp=format|export|errors) and the steps (POST
 * _dimp=kind, _action=import_load|import_check|import_save|import_discard). Returns when the request
 * is not for this import.
 */
function dimp_handle(array $def): void
{
    $kind = $def['kind'];
    $S = &$_SESSION['dimp'][$kind];
    if (!is_post() && get('dimp_kind') === $kind && in_array(get('dimp'), ['format', 'export', 'errors'], true)) {
        $opt = $S['opt'] ?? dimp_read_opt($def);
        if (get('cutoff_id') && isset($def['opts']['cutoff_id'])) {
            $opt['cutoff_id'] = (int)get('cutoff_id');
        }
        $what = (string)get('dimp');
        if ($what === 'errors' && !empty($S['rows'])) {
            $cols = array_keys($def['columns']);
            $out = [];
            foreach ($S['rows'] as $r) {
                if ($r['status'] !== 'error') {
                    continue;
                }
                $msgs = $r['row_errors'];
                foreach ($r['errors'] as $k => $m) {
                    $msgs[] = ($def['columns'][$k][0] ?? $k) . ': ' . $m;
                }
                $out[] = array_merge(array_intersect_key($r['data'], array_flip($cols)), ['errors' => implode(' | ', $msgs)]);
            }
            csv_download($kind . '_import_errors.csv', array_merge($cols, ['errors']), $out);
        }
        if ($what === 'export' && $def['export']) {
            [$h, $rows] = ($def['export'])($opt);
            audit($def['module'], 'csv_export_' . $kind, null, null, ['rows' => count($rows)]);
            csv_download($kind . '_' . date('Ymd') . '.csv', $h, $rows);
        }
        if (!empty($def['template'])) {
            [$h, $rows] = ($def['template'])($opt);
        } else {
            $h = array_keys($def['columns']);
            $rows = [array_map(fn($c) => $c[3], array_values($def['columns']))];
        }
        csv_download($kind . '_import_format.csv', $h, $rows);
    }
    if (!is_post() || post('_dimp') !== $kind) {
        return;
    }
    $act = (string)post('_action');
    $show = null;
    try {
        if ($act === 'import_load') {
            if (empty($_FILES['dimp_file']['tmp_name']) || !is_uploaded_file($_FILES['dimp_file']['tmp_name'])) {
                throw new RuntimeException('Choose the CSV file first.');
            }
            $ext = strtolower((string)pathinfo((string)$_FILES['dimp_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['csv', 'txt'], true)) {
                throw new RuntimeException('Save the spreadsheet as CSV (comma separated) and load that file.');
            }
            $read = !empty($def['read']) ? ($def['read'])((string)$_FILES['dimp_file']['tmp_name']) : dimp_read((string)$_FILES['dimp_file']['tmp_name'], $def['columns'], $def['alias']);
            if (!$read['rows']) {
                throw new RuntimeException('The file has the headers but no data row.');
            }
            $S = ['file' => (string)$_FILES['dimp_file']['name'], 'rows' => $read['rows'], 'opt' => dimp_read_opt($def, $_POST), 'checked' => false, 'counts' => null];
            $S['counts'] = ($def['check'])($S['rows'], $S['opt']);
            $S['checked'] = true;
            $c = $S['counts'];
            flash($c['error'] ? 'warning' : 'success', count($read['rows']) . ' row(s) read from ' . $S['file'] . '. '
                . ($c['error'] ? $c['error'] . ' row(s) have errors, marked in red: correct them below and press Check data again.' : 'Every row is valid: press Save to database to write them.')
                . ($read['unknown'] ? ' Columns not in the format were ignored: ' . implode(', ', array_slice($read['unknown'], 0, 8)) . '.' : ''));
            $show = $c['error'] ? 'errors' : null;
        } elseif ($act === 'import_check' || $act === 'import_save') {
            if (empty($S['rows'])) {
                throw new RuntimeException('Load a file first.');
            }
            $edits = json_decode((string)post('dimp_edits', '{}'), true) ?: [];
            foreach ($edits as $i => $cells) {
                if (isset($S['rows'][(int)$i]) && is_array($cells)) {
                    foreach ($cells as $k => $v) {
                        if (array_key_exists($k, $def['columns'])) {
                            $S['rows'][(int)$i]['data'][$k] = trim(mb_substr((string)$v, 0, 500));
                        }
                    }
                }
            }
            $remove = array_map('intval', json_decode((string)post('dimp_remove', '[]'), true) ?: []);
            if ($remove) {
                foreach ($remove as $i) {
                    unset($S['rows'][$i]);
                }
                $S['rows'] = array_values($S['rows']);
            }
            if (!$S['rows']) {
                $S = null;
                unset($_SESSION['dimp'][$kind]);
                throw new RuntimeException('Every row was removed; nothing to import.');
            }
            $S['opt'] = dimp_read_opt($def, $_POST);
            $S['counts'] = ($def['check'])($S['rows'], $S['opt']);
            $S['checked'] = true;
            $c = $S['counts'];
            if ($act === 'import_check' || $c['error']) {
                if ($act === 'import_save') {
                    throw new RuntimeException('Not saved: ' . $c['error'] . ' row(s) have errors. Correct them and check again.');
                }
                flash($c['error'] ? 'warning' : 'success', $c['error'] ? $c['error'] . ' of ' . $c['total'] . ' row(s) have errors, marked in red.' : 'All ' . $c['total'] . ' row(s) are valid. Press Save to database to write them.');
                $show = $c['error'] ? 'errors' : null;
            } else {
                $res = $def['family'] === 'csvi'
                    ? ($def['commit'])($S['rows'], $S['opt'], (string)$S['file'])
                    : tx(fn() => ($def['commit'])(array_values(array_filter($S['rows'], fn($r) => $r['status'] !== 'error')), $S['opt'], (string)$S['file']));
                if ($def['family'] !== 'csvi') {
                    insert('csv_import_batches', ['kind' => $kind, 'file_name' => mb_substr((string)$S['file'], 0, 250), 'cutoff_id' => null, 'total_rows' => count($S['rows']),
                        'created_rows' => (int)($res['created'] ?? 0), 'updated_rows' => (int)($res['updated'] ?? 0), 'rejected_rows' => (int)($res['skipped'] ?? 0), 'uploaded_by' => user_id()]);
                    audit($def['module'], 'csv_import_' . $kind, null, null, ['file' => $S['file'], 'rows' => count($S['rows'])] + array_diff_key($res, ['message' => 1]));
                }
                $S = null;
                unset($_SESSION['dimp'][$kind]);
                $msg = [];
                foreach (['created' => 'added', 'updated' => 'updated', 'replaced' => 'replaced', 'removed' => 'removed from the period', 'skipped' => 'skipped'] as $k => $l) {
                    if (!empty($res[$k])) {
                        $msg[] = $res[$k] . ' ' . $l;
                    }
                }
                flash('success', $def['title'] . ' import saved: ' . rtrim(!empty($res['message']) ? $res['message'] : implode(', ', $msg ?: ['nothing to change']), '.') . '.');
            }
        } elseif ($act === 'import_discard') {
            $S = null;
            unset($_SESSION['dimp'][$kind]);
            flash('success', 'The loaded file was discarded.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    } catch (PDOException $e) {
        flash('error', 'Not saved: ' . ($e->getCode() == 23000 ? 'a duplicate or invalid reference (' . $e->errorInfo[2] . ').' : $e->getMessage()));
    }
    header('Location: ' . dimp_back_url(array_filter(['dimp_show' => $show])) . '#dimp-' . $kind);
    exit;
}

/** The import panel shown on the form. */
function dimp_panel(array $def): string
{
    $kind = $def['kind'];
    if (!can_page($def['page'], 'import')) {
        return '';
    }
    $S = $_SESSION['dimp'][$kind] ?? null;
    $loaded = !empty($S['rows']);
    $opt = $S['opt'] ?? dimp_read_opt($def);
    if (isset($def['opts']['cutoff_id']) && empty($S) && !empty($def['ctx']['cutoff_id'])) {
        $opt['cutoff_id'] = (int)$def['ctx']['cutoff_id'];
    }
    $cols = $def['columns'];
    $dl = function (string $what) use ($kind, $opt) {
        return h(dimp_back_url(array_filter(['dimp' => $what, 'dimp_kind' => $kind, 'cutoff_id' => $opt['cutoff_id'] ?? null])));
    };
    $optHtml = function () use ($def, $opt): string {
        $h = '';
        foreach ($def['opts'] as $k => $o) {
            $name = 'dimp_opt[' . h($k) . ']';
            switch ($o['type']) {
                case 'checkbox':
                    $h .= '<label class="check dimp-opt-check"><input type="checkbox" name="' . $name . '" value="1"' . (!empty($opt[$k]) ? ' checked' : '') . '> ' . h($o['label']) . '</label>';
                    break;
                case 'select':
                    $h .= '<label class="dimp-opt">' . h($o['label']) . '<select name="' . $name . '">' . options($o['options'], $opt[$k] ?? null) . '</select></label>';
                    break;
                case 'period':
                    $h .= '<label class="dimp-opt">' . h($o['label']) . '<select name="' . $name . '" required>' . options(pt_period_options(), $opt[$k] ?? null) . '</select></label>';
                    break;
                default:
                    $h .= '<label class="dimp-opt">' . h($o['label']) . '<input type="number" name="' . $name . '" value="' . h((string)($opt[$k] ?? '')) . '"></label>';
            }
        }
        return $h;
    };
    $colTable = '<details class="dimp-cols"><summary>Columns of the format (' . count($cols) . ')</summary><div class="table-wrap"><table class="table compact"><thead><tr><th>Column</th><th>Name</th><th>What to enter</th><th>Example</th></tr></thead><tbody>';
    foreach ($cols as $k => $c) {
        $colTable .= '<tr><td class="mono nowrap">' . h($k) . ($c[1] ? ' *' : '') . '</td><td>' . h($c[0]) . '</td><td class="small">' . h($c[2]) . '</td><td class="mono small">' . h($c[3]) . '</td></tr>';
    }
    $colTable .= '</tbody></table></div><p class="muted small">* required. The first line of the file holds the column names (the key or the name). Up to ' . number_format($def['family'] === 'csvi' ? CSVI_MAX_ROWS : DIMP_MAX_ROWS) . ' rows.</p></details>';

    ob_start();
    if (!$loaded): ?>
<section class="card dimp" id="dimp-<?= h($kind) ?>">
  <div class="dimp-head">
    <div class="dimp-title"><span class="dimp-ico" aria-hidden="true"><?= icon('upload') ?></span><div><h2>Import from CSV</h2><div class="sub">Loads <?= h($def['what']) ?> The file is checked first; nothing is saved until every row is valid.</div></div></div>
    <div class="dimp-links">
      <a class="btn btn-sm" href="<?= $dl('format') ?>">Download format</a>
      <?php if ($def['export']): ?><a class="btn btn-sm" href="<?= $dl('export') ?>"><?= h($def['export_label'] ?? 'Current data (CSV)') ?></a><?php endif; ?>
    </div>
  </div>
  <form method="post" enctype="multipart/form-data" class="dimp-load">
    <?= csrf_field() ?><input type="hidden" name="_dimp" value="<?= h($kind) ?>">
    <label class="dimp-file"><span>CSV file</span><input type="file" name="dimp_file" accept=".csv,.txt,text/csv" required></label>
    <?= $optHtml() ?>
    <button class="btn btn-primary" name="_action" value="import_load">Load and check</button>
  </form>
  <?= $colTable ?>
</section>
<?php else:
    $rowsAll = $S['rows'];
    $c = $S['counts'] ?? ['total' => count($rowsAll), 'ok' => 0, 'error' => 0];
    $show = in_array(get('dimp_show'), ['all', 'errors', 'notes'], true) ? get('dimp_show') : (!empty($c['error']) ? 'errors' : 'all');
    $idx = array_keys($rowsAll);
    if ($show === 'errors') {
        $idx = array_values(array_filter($idx, fn($i) => $rowsAll[$i]['status'] === 'error'));
    } elseif ($show === 'notes') {
        $idx = array_values(array_filter($idx, fn($i) => !empty($rowsAll[$i]['notes'])));
    }
    $pages = max(1, (int)ceil(count($idx) / DIMP_PER_PAGE));
    $pg = min(max(1, (int)get('dimp_pg', 1)), $pages);
    $pageIdx = array_slice($idx, ($pg - 1) * DIMP_PER_PAGE, DIMP_PER_PAGE);
    $canSave = !empty($S['checked']) && empty($c['error']) && $c['total'] > 0;
    $statusLabel = ['unchecked' => 'Not checked', 'error' => 'Error', 'new' => 'New', 'update' => 'Update', 'replace' => 'Replace', 'keep' => 'Skip (kept)', 'skip' => 'Skip'];
    $tabUrl = fn($s) => h(dimp_back_url(['dimp_show' => $s])) . '#dimp-' . h($kind);
    $parts = [];
    foreach (['new' => 'new', 'update' => 'to update', 'replace' => 'to replace', 'skip' => 'skipped', 'keep' => 'kept'] as $k => $l) {
        if (!empty($c[$k])) {
            $parts[] = $c[$k] . ' ' . $l;
        }
    }
?>
<section class="card dimp is-loaded" id="dimp-<?= h($kind) ?>">
  <div class="dimp-head">
    <div class="dimp-title"><span class="dimp-ico" aria-hidden="true"><?= icon('upload') ?></span><div><h2>Import from CSV: <?= h(mb_strimwidth((string)$S['file'], 0, 48, '…')) ?></h2>
      <div class="sub"><?= number_format($c['total']) ?> row(s): <strong class="<?= $c['error'] ? 'text-bad' : 'text-good' ?>"><?= $c['error'] ? number_format($c['error']) . ' with errors' : 'all valid' ?></strong><?= $parts ? ', ' . h(implode(', ', $parts)) : '' ?>.</div></div></div>
    <div class="dimp-links">
      <?php if (!empty($c['error'])): ?><a class="btn btn-sm" href="<?= $dl('errors') ?>">Error report (CSV)</a><?php endif; ?>
      <a class="btn btn-sm" href="<?= $dl('format') ?>">Download format</a>
    </div>
  </div>
  <form method="post" class="dimp-grid-form" data-dimp-grid>
    <?= csrf_field() ?><input type="hidden" name="_dimp" value="<?= h($kind) ?>"><input type="hidden" name="dimp_edits" value=""><input type="hidden" name="dimp_remove" value="">
    <?php if ($def['opts']): ?><div class="dimp-opts"><?= $optHtml() ?></div><?php endif; ?>
    <div class="dimp-bar">
      <button class="btn btn-primary" name="_action" value="import_check">Check data</button>
      <button class="btn btn-success" name="_action" value="import_save"<?= $canSave ? '' : ' disabled title="Available when every row is valid"' ?> data-confirm="Save the <?= (int)$c['ok'] ?> valid row(s) to the database?">Save to database</button>
      <button class="btn btn-danger-outline" name="_action" value="import_discard" formnovalidate data-confirm="Discard the loaded file?">Discard</button>
      <span class="spacer"></span>
      <nav class="seg" aria-label="Rows shown">
        <a class="<?= $show === 'all' ? 'active' : '' ?>" href="<?= $tabUrl('all') ?>">All rows</a>
        <a class="<?= $show === 'errors' ? 'active' : '' ?>" href="<?= $tabUrl('errors') ?>">With errors (<?= (int)$c['error'] ?>)</a>
        <a class="<?= $show === 'notes' ? 'active' : '' ?>" href="<?= $tabUrl('notes') ?>">With notes</a>
      </nav>
    </div>
    <div class="grid-wrap dimp-wrap">
      <table class="grid-entry dimp-table csvi-grid">
        <thead><tr><th class="sticky">Line</th><th class="sticky-2">Status</th>
          <?php foreach ($cols as $k => $col): ?><th title="<?= h($col[2]) ?>"><?= h($col[0]) ?><?= $col[1] ? ' *' : '' ?></th><?php endforeach; ?><th>Remove</th></tr></thead>
        <tbody>
        <?php if (!$pageIdx): ?><tr><td class="txt" colspan="<?= count($cols) + 3 ?>"><div class="empty-state"><h3><?= $show === 'errors' ? 'No row with an error' : 'No row to show' ?></h3></div></td></tr><?php endif; ?>
        <?php foreach ($pageIdx as $i):
            $r = $rowsAll[$i];
            $msgs = $r['row_errors'];
            foreach ($r['errors'] as $k => $m) {
                $msgs[] = ($cols[$k][0] ?? $k) . ': ' . $m;
            } ?>
          <tr class="<?= $r['status'] === 'error' ? 'is-error' : '' ?>">
            <td class="txt sticky"><?= (int)$r['line'] ?></td>
            <td class="txt sticky-2 dimp-status st-<?= h($r['status']) ?>"><?= h($statusLabel[$r['status']] ?? $r['status']) ?><?php if (!empty($r['norm']['employee_name'])): ?><small class="d-block muted"><?= h($r['norm']['employee_name']) ?></small><?php endif; ?></td>
            <?php foreach ($cols as $k => $col): $bad = isset($r['errors'][$k]); ?>
              <td class="<?= $bad ? 'cell-bad' : '' ?>"<?= $bad ? ' title="' . h($r['errors'][$k]) . '"' : '' ?>><input class="csv-cell" type="text" value="<?= h($r['data'][$k] ?? '') ?>" data-r="<?= (int)$i ?>" data-k="<?= h($k) ?>" aria-label="<?= h($col[0]) ?>, line <?= (int)$r['line'] ?>" size="<?= max(6, min(24, mb_strlen((string)($r['data'][$k] ?? '')) + 2, mb_strlen($col[0]))) ?>"></td>
            <?php endforeach; ?>
            <td class="center"><input type="checkbox" data-remove="<?= (int)$i ?>" aria-label="Remove line <?= (int)$r['line'] ?>"></td>
          </tr>
          <?php if ($msgs || $r['notes']): ?>
            <tr class="csvi-msg <?= $msgs ? 'is-error' : '' ?>"><td class="sticky"></td><td class="txt" colspan="<?= count($cols) + 2 ?>"><div class="dimp-msgs">
              <?php foreach ($msgs as $m): ?><div class="text-bad"><?= h($m) ?></div><?php endforeach; ?>
              <?php foreach ($r['notes'] as $m): ?><div class="muted"><?= h($m) ?></div><?php endforeach; ?>
            </div></td></tr>
          <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($pages > 1): ?>
      <div class="pager"><?php for ($p = 1; $p <= $pages; $p++): ?><a class="btn btn-sm<?= $p === $pg ? ' btn-soft' : '' ?>" href="<?= h(dimp_back_url(['dimp_show' => $show, 'dimp_pg' => $p])) ?>#dimp-<?= h($kind) ?>"><?= $p ?></a><?php endfor; ?>
        <span class="muted small">Press Check data before changing page to keep the corrections of this page.</span></div>
    <?php endif; ?>
  </form>
  <?= $colTable ?>
</section>
<?php endif;
    return (string)ob_get_clean();
}
