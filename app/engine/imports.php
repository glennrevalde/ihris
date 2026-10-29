<?php
/**
 * Attendance imports:
 *  - raw biometric device logs (.txt / .dat) read through configurable column mappings
 *  - summarized attendance from Excel (.xlsx) / CSV using the standard template
 */

/**
 * v24: the attendance report configuration a file is written in: the one whose Employee ID label is found in the
 * sheet (and whose period label too, when it has one). $chosen wins when it fits. Null when none fits.
 */
function import_report_profile_for(string $path, array $profiles, ?array $chosen = null): ?array
{
    try {
        $grid = grid_read_file($path);
    } catch (Throwable $e) {
        return $chosen;
    }
    $norm = fn($v) => mb_strtolower(preg_replace('/\s+/u', '', (string)$v));
    $cells = [];
    foreach ($grid as $row) {
        foreach ($row as $v) {
            $cells[] = $norm($v);
        }
    }
    $score = function (array $p) use ($cells, $norm): int {
        $id = $norm(($p['rp_id_label'] ?? '') ?: 'User ID:');
        $per = $norm($p['rp_period_label'] ?? '');
        $hits = 0;
        $perHit = $per === '';
        foreach ($cells as $c) {
            if ($c === $id || ($id !== '' && strpos($c, $id) === 0 && !ctype_alpha(substr($c, strlen($id), 1)))) {
                $hits++;
            }
            if (!$perHit && strpos($c, $per) === 0) {
                $perHit = true;
            }
        }
        return $hits && $perHit ? $hits : 0;
    };
    if ($chosen && $score($chosen)) {
        return $chosen;
    }
    $best = null;
    $bestScore = 0;
    foreach ($profiles as $p) {
        if (($p['layout'] ?? '') !== 'report') {
            continue;
        }
        $sc = $score($p);
        if ($sc > $bestScore) {
            [$best, $bestScore] = [$p, $sc];
        }
    }
    return $best ?: $chosen;
}

/** v22: layouts of an import configuration (Import Log Configuration). */
const IMPORT_LAYOUTS = ['delimited' => 'delimited', 'fixed' => 'fixed width', 'report' => 'attendance report'];

/** Spreadsheet column letter of a 0-based column (0 = A). */
function import_col_letter(int $c): string
{
    $s = '';
    for ($c++; $c > 0; $c = intdiv($c - 1, 26)) {
        $s = chr(65 + ($c - 1) % 26) . $s;
    }
    return $s;
}

function import_split_line(string $line, string $delimiter): array
{
    $line = rtrim($line, "\r\n");
    switch ($delimiter) {
        case 'tab':
            $parts = explode("\t", $line);
            break;
        case 'comma':
            $parts = str_getcsv($line, ',', '"', '\\');
            break;
        case 'semicolon':
            $parts = str_getcsv($line, ';', '"', '\\');
            break;
        case 'pipe':
            $parts = explode('|', $line);
            break;
        default:
            $parts = preg_split('/\s+/', trim($line));
    }
    return array_map(fn($p) => trim((string)$p), $parts);
}

function import_parse_datetime(string $value, string $format): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('!' . $format, $value);
    if (!$dt) {
        // tolerate seconds / no seconds differences
        foreach ([$format, str_replace(':s', '', $format), $format . ':s', 'Y-m-d H:i:s', 'Y-m-d H:i', 'm/d/Y H:i:s', 'm/d/Y H:i', 'm/d/Y h:i A', 'd/m/Y H:i'] as $f) {
            $dt = DateTime::createFromFormat('!' . $f, $value);
            if ($dt) {
                break;
            }
        }
    }
    if (!$dt) {
        $ts = strtotime($value);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }
    return $dt->format('Y-m-d H:i:s');
}

function import_punch_map(?string $map): array
{
    $out = [];
    foreach (array_filter(array_map('trim', explode(',', (string)$map))) as $pair) {
        [$k, $v] = array_pad(array_map('trim', explode('=', $pair, 2)), 2, '');
        if ($k !== '' && in_array($v, ['in', 'out', 'break_out', 'break_in'], true)) {
            $out[strtoupper($k)] = $v;
        }
    }
    return $out;
}

/** v19: has import_profiles the Display order column (database version 17)? */
function import_profiles_sorted(): bool
{
    static $ok = null;
    return $ok ??= (bool)val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'import_profiles' AND column_name = 'sort_order'");
}

/**
 * Parse a device log file with an import profile. Returns rows with line, bio_id, time, type, device, error.
 * v19: $range [from, to] (YYYY-MM-DD) keeps only the punches of those dates; $outside counts the others, so a
 * whole backup of the device can be read without staging years of logs.
 */
function parse_biometric_file(string $path, array $profile, int $limit = 0, ?array $range = null, int &$outside = 0): array
{
    $map = import_punch_map($profile['punch_map']);
    $rows = [];
    $fh = fopen($path, 'r');
    $n = 0;
    while (($line = fgets($fh)) !== false) {
        $n++;
        if ($n === 1) {
            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
        }
        if (trim($line) === '' || ($n === 1 && $profile['has_header'])) {
            continue;
        }
        $c = import_split_line($line, $profile['delimiter']);
        $get = fn($col) => $col ? ($c[(int)$col - 1] ?? '') : '';
        $bio = ltrim($get($profile['col_bio_id']), '0') ?: $get($profile['col_bio_id']);
        if ($profile['col_datetime']) {
            $raw = $get($profile['col_datetime']);
        } else {
            $raw = $get($profile['col_date']) . ' ' . $get($profile['col_time']);
        }
        $time = import_parse_datetime($raw, $profile['datetime_format']);
        $code = strtoupper($get($profile['col_punch']));
        // the log-type map decides the type; without a map the punch is left untyped and the shift code
        // of the employee places it (In, Break out, Break in, Out), see import_classify_untyped()
        $type = $profile['col_punch'] && $map ? ($map[$code] ?? 'unknown') : 'unknown';
        $err = null;
        if ($bio === '') {
            $err = 'Missing user ID';
        } elseif (!$time) {
            $err = 'Unreadable date/time "' . $raw . '"';
        }
        if ($range && $time && (substr($time, 0, 10) < $range[0] || substr($time, 0, 10) > $range[1])) {
            $outside++;
            continue;
        }
        $rows[] = ['line' => $n, 'bio_id' => $bio, 'time' => $time, 'type' => $type, 'device' => $get($profile['col_device']) ?: null, 'raw' => trim($line), 'error' => $err];
        if ($limit && count($rows) >= $limit) {
            break;
        }
    }
    fclose($fh);
    return $rows;
}

/**
 * Give untyped punches a type from the employee's shift code. When the file (or the device) does
 * not say In / Out / Break, the punches of one employee-day are placed by position according to
 * the Logs per day of the shift: 2 logs = In, Out; 4 = In, Break out, Break in, Out;
 * 6 = In, Break out 1, Break in 1, Break out 2, Break in 2, Out. Rows keep 'unknown' when the
 * employee is not known or the day has more punches than slots.
 * $rows need bio_id (or employee_id), log_time and punch_type; $empOf maps a bio id to an employee id.
 */
function import_classify_untyped(array $rows, callable $empOf): array
{
    $groups = [];
    foreach ($rows as $i => $r) {
        if (($r['punch_type'] ?? 'unknown') !== 'unknown' || empty($r['log_time'])) {
            continue;
        }
        $empId = $r['employee_id'] ?? $empOf((string)($r['bio_id'] ?? ''));
        if (!$empId) {
            continue;
        }
        $groups[$empId][substr($r['log_time'], 0, 10)][] = $i;
    }
    // v19: the other punches of the same employee-day (typed ones of this import, the logs of other files or devices
    // already staged or posted) are counted too, so a Time out logged on the device of another company is not an In
    $known = [];
    foreach ($rows as $r) {
        if (empty($r['log_time'])) {
            continue;
        }
        $e = $r['employee_id'] ?? $empOf((string)($r['bio_id'] ?? ''));
        if ($e && isset($groups[$e][substr($r['log_time'], 0, 10)])) {
            $known[$e][substr($r['log_time'], 0, 10)][] = $r['log_time'];
        }
    }
    foreach ($groups as $empId => $days) {
        $emp = att_employee((int)$empId);
        if (!$emp) {
            continue;
        }
        foreach ($days as $date => $idx) {
            $ctx = array_merge($known[$empId][$date] ?? [],
                q("SELECT log_time FROM attendance_logs WHERE employee_id = ? AND log_time BETWEEN ? AND ?", [$empId, $date . ' 00:00:00', $date . ' 23:59:59'])->fetchAll(PDO::FETCH_COLUMN),
                q("SELECT log_time FROM log_staging WHERE employee_id = ? AND status = 'pending' AND log_time BETWEEN ? AND ?", [$empId, $date . ' 00:00:00', $date . ' 23:59:59'])->fetchAll(PDO::FETCH_COLUMN));
            // the same minute twice (a punch already staged or posted) counts once
            $times = [];
            foreach (array_merge(array_map(fn($i) => $rows[$i]['log_time'], $idx), $ctx) as $t) {
                $times[substr((string)$t, 0, 16)] = (string)$t;
            }
            $typeOf = classify_punches_by_shift($emp, $date, array_values($times));
            foreach ($idx as $i) {
                $k = substr($rows[$i]['log_time'], 0, 16);
                if (!isset($typeOf[$rows[$i]['log_time']]) && isset($times[$k]) && isset($typeOf[$times[$k]])) {
                    $typeOf[$rows[$i]['log_time']] = $typeOf[$times[$k]];
                }
            }
            foreach ($idx as $i) {
                $rows[$i]['punch_type'] = $typeOf[$rows[$i]['log_time']] ?? 'unknown';
            }
        }
    }
    return $rows;
}

/** Import parsed biometric rows. Returns stats. */
function import_biometric_rows(array $rows, array $profile, ?int $branchId, string $fileName): array
{
    $bioMap = bio_employee_map();   // v24: with the Other biometric IDs of the employees
    // punches the file does not type are classified by the employee's shift code
    $rows = import_classify_untyped(array_map(fn($r) => $r + ['log_time' => $r['time'], 'punch_type' => $r['type']], $rows), fn($bio) => $bioMap[ltrim($bio, '0') ?: $bio] ?? ($bioMap[$bio] ?? null));
    foreach ($rows as &$r) {
        $r['type'] = $r['punch_type'];
    }
    unset($r);
    $batch = insert('import_batches', ['batch_type' => 'biometric', 'profile_id' => $profile['id'], 'file_name' => $fileName, 'branch_id' => $branchId, 'total_rows' => count($rows), 'uploaded_by' => user_id()]);
    $st = ['total' => count($rows), 'imported' => 0, 'duplicates' => 0, 'errors' => 0, 'unmatched' => 0, 'batch' => $batch];
    $touched = [];
    $ins = db()->prepare('INSERT IGNORE INTO attendance_logs (employee_id, bio_id, log_time, punch_type, source, branch_id, batch_id, device_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($rows as $r) {
        if ($r['error']) {
            $st['errors']++;
            insert('import_exceptions', ['batch_id' => $batch, 'row_no' => $r['line'], 'raw_data' => mb_substr($r['raw'], 0, 990), 'employee_no' => $r['bio_id'], 'issue' => $r['error'], 'status' => 'rejected']);
            continue;
        }
        $emp = $bioMap[$r['bio_id']] ?? null;
        if (!$emp) {
            $st['unmatched']++;
            // keep unmatched punches (employee_id NULL) so they can be mapped later; avoid exact duplicates
            if (val('SELECT COUNT(*) FROM attendance_logs WHERE employee_id IS NULL AND bio_id = ? AND log_time = ?', [$r['bio_id'], $r['time']])) {
                $st['duplicates']++;
                continue;
            }
        }
        $ins->execute([$emp, $r['bio_id'], $r['time'], $r['type'], 'import_txt', $branchId ?: $profile['default_branch_id'], $batch, $r['device']]);
        if ($ins->rowCount()) {
            $st['imported']++;
            if ($emp) {
                $touched[$emp][substr($r['time'], 0, 10)] = true;
            }
        } else {
            $st['duplicates']++;
        }
    }
    foreach ($touched as $emp => $dates) {
        foreach (array_keys($dates) as $d) {
            process_dtr((int)$emp, $d);
            process_dtr((int)$emp, add_days($d, -1));
        }
    }
    update('import_batches', ['imported' => $st['imported'], 'duplicates' => $st['duplicates'], 'flagged' => $st['errors'] + $st['unmatched']], 'id = ?', [$batch]);
    audit('ams', 'import_biometric', 'import_batches', $batch, $st);
    return $st;
}

/** Read the CSV / XLSX attendance template into associative rows. */
function read_attendance_sheet(string $path, string $ext): array
{
    if ($ext === 'xlsx') {
        $raw = xlsx_read_rows($path);
    } else {
        $raw = [];
        $fh = fopen($path, 'r');
        $first = true;
        while (($r = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            if ($first) {
                $r[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$r[0]);
                $first = false;
            }
            $raw[] = $r;
        }
        fclose($fh);
    }
    if (!$raw) {
        return [];
    }
    $head = array_map(fn($h) => strtolower(trim(str_replace([' ', '-'], '_', (string)$h))), $raw[0]);
    $out = [];
    foreach (array_slice($raw, 1) as $i => $r) {
        if (!array_filter($r, fn($v) => trim((string)$v) !== '')) {
            continue;
        }
        $row = ['_line' => $i + 2];
        foreach ($head as $k => $h) {
            $row[$h] = trim((string)($r[$k] ?? ''));
        }
        $out[] = $row;
    }
    return $out;
}

function sheet_date($v): ?string
{
    if ($v === '' || $v === null) {
        return null;
    }
    if (is_numeric($v) && (float)$v > 20000) {
        return excel_serial_to_date($v);
    }
    $v = trim((string)$v);
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
        return substr($v, 0, 10);
    }
    $ts = strtotime($v); // accepts m/d/Y, "Sep 1, 2026", etc.
    return $ts ? date('Y-m-d', $ts) : null;
}

function sheet_time($v): ?string
{
    if ($v === '' || $v === null) {
        return null;
    }
    if (is_numeric($v) && (float)$v < 1) {
        return excel_fraction_to_time($v);
    }
    $ts = strtotime('2000-01-01 ' . $v);
    return $ts ? date('H:i:s', $ts) : null;
}

/**
 * Validate & import attendance summary rows (employee_no, date, time_in, time_out, remarks, optional branch_code, break_out, break_in).
 * Valid rows post to the logs; unmatched / conflicting rows go to the HR review queue.
 */
function import_attendance_sheet(array $rows, string $fileName, string $type): array
{
    $batch = insert('import_batches', ['batch_type' => $type, 'file_name' => $fileName, 'total_rows' => count($rows), 'uploaded_by' => user_id()]);
    $st = ['total' => count($rows), 'imported' => 0, 'flagged' => 0, 'batch' => $batch];
    $branches = pairs('SELECT UPPER(code), id FROM branches');
    foreach ($rows as $r) {
        $issues = [];
        $key = $r['employee_no'] ?? ($r['employee_id'] ?? ($r['emp_no'] ?? ''));
        $emp = $key !== '' ? row("SELECT * FROM employees WHERE employee_no = ? OR biometric_id = ? LIMIT 1", [$key, $key]) : null;
        $date = sheet_date($r['date'] ?? '');
        $tin = sheet_time($r['time_in'] ?? '');
        $tout = sheet_time($r['time_out'] ?? '');
        $bo = sheet_time($r['break_out'] ?? '');
        $bi = sheet_time($r['break_in'] ?? '');
        $branchId = !empty($r['branch_code']) ? ($branches[strtoupper($r['branch_code'])] ?? null) : null;
        if (!$emp) {
            $issues[] = 'Employee "' . $key . '" not found in the masterfile';
        } elseif ($emp['status'] !== 'active' && $date && $emp['date_separated'] && $date > $emp['date_separated']) {
            $issues[] = 'Date is after the employee\'s separation';
        }
        if (!$date) {
            $issues[] = 'Invalid date';
        }
        if (!$tin && !$tout) {
            $issues[] = 'No time-in or time-out';
        }
        if (!empty($r['branch_code']) && !$branchId) {
            $issues[] = 'Unknown branch code ' . $r['branch_code'];
        }
        $inDt = $date && $tin ? $date . ' ' . $tin : null;
        $outDt = $date && $tout ? $date . ' ' . $tout : null;
        if ($emp && $date && !$issues) {
            if ($date < $emp['date_hired']) {
                $issues[] = 'Date is before the employee was hired';
            }
            $sched = employee_schedule($emp, $date);
            if ($inDt && $outDt && $outDt <= $inDt) {
                if ($sched['sched_out'] && substr($sched['sched_out'], 0, 10) > $date) {
                    $outDt = add_days($date, 1) . ' ' . $tout; // overnight shift
                } else {
                    $issues[] = 'Time-out is earlier than time-in';
                }
            }
            if ($sched['no_schedule']) {
                $issues[] = 'Employee has no shift schedule';
            } elseif ($sched['is_rest']) {
                $issues[] = 'Date is a scheduled rest day (confirm rest-day work)';
            }
            if (dtr_locked($emp, $date)) {
                $issues[] = 'Date belongs to an approved (locked) cutoff';
            }
            $existing = row('SELECT * FROM dtr WHERE employee_id = ? AND work_date = ? AND time_in IS NOT NULL', [$emp['id'], $date]);
            if ($existing && ($existing['source'] !== 'import_csv') && (($inDt && $existing['time_in'] !== $inDt) || ($outDt && $existing['time_out'] !== $outDt))) {
                $issues[] = 'Conflicts with existing attendance (' . fmt_time($existing['time_in']) . '–' . fmt_time($existing['time_out']) . ' from ' . $existing['source'] . ')';
            }
        }
        if ($issues) {
            insert('import_exceptions', ['batch_id' => $batch, 'row_no' => $r['_line'], 'raw_data' => mb_substr(json_encode($r, JSON_UNESCAPED_UNICODE), 0, 990),
                'employee_no' => $key, 'employee_id' => $emp['id'] ?? null, 'work_date' => $date, 'time_in' => $inDt, 'time_out' => $outDt,
                'branch_id' => $branchId, 'remarks' => $r['remarks'] ?? null, 'issue' => implode('; ', $issues)]);
            $st['flagged']++;
            continue;
        }
        post_import_punches((int)$emp['id'], $date, $inDt, $outDt, $bo ? $date . ' ' . $bo : null, $bi ? $date . ' ' . $bi : null, $branchId, $batch, $r['remarks'] ?? null);
        $st['imported']++;
    }
    update('import_batches', ['imported' => $st['imported'], 'flagged' => $st['flagged']], 'id = ?', [$batch]);
    audit('ams', 'import_attendance', 'import_batches', $batch, $st);
    return $st;
}

function post_import_punches(int $empId, string $date, ?string $in, ?string $out, ?string $bo, ?string $bi, ?int $branchId, int $batch, ?string $remarks): void
{
    $emp = att_employee($empId);
    $branchId = $branchId ?: (int)$emp['default_branch_id'];
    $ins = db()->prepare('INSERT IGNORE INTO attendance_logs (employee_id, bio_id, log_time, punch_type, source, branch_id, batch_id, device_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach (['in' => $in, 'break_out' => $bo, 'break_in' => $bi, 'out' => $out] as $t => $v) {
        if ($v) {
            $ins->execute([$empId, $emp['biometric_id'], strlen($v) === 16 ? $v . ':00' : $v, $t, 'import_csv', $branchId, $batch, $remarks ? mb_substr('Import: ' . $remarks, 0, 40) : 'Excel/CSV import']);
        }
    }
    process_dtr($empId, $date);
}

/* =====================================================================
   Customized Import Attendance Log — staging area (log_staging)
   Logs from a file, from the HRIS log table or from a biometric device are
   first staged, checked and only then posted to the attendance logs.
   ===================================================================== */

/**
 * v24: biometric ID => employee id: the Biometric ID of the Employee Masterfile plus the Other biometric IDs of the
 * employee (the user ID of the same person on another terminal or in another log file, e.g. JJM Logs and NBJ Logs).
 * Leading zeros do not count ("007" = "7").
 */
function bio_employee_map(): array
{
    $map = pairs('SELECT biometric_id, id FROM employees WHERE biometric_id IS NOT NULL AND biometric_id <> ""');
    if (bio_alt_supported()) {
        foreach (pairs('SELECT bio_id, employee_id FROM employee_bio_ids') as $k => $v) {
            if (!isset($map[$k])) {
                $map[$k] = $v;   // the masterfile Biometric ID wins
            }
        }
    }
    foreach ($map as $k => $v) {
        $n = ltrim((string)$k, '0') ?: (string)$k;
        if (!isset($map[$n])) {
            $map[$n] = $v;
        }
    }
    return $map;
}

/** Employee of a biometric ID from bio_employee_map(). */
function bio_employee_of(array $map, string $bio): ?int
{
    $k = ltrim($bio, '0') ?: $bio;
    $e = $map[$bio] ?? ($map[$k] ?? null);
    return $e ? (int)$e : null;
}

/** v24: are the Other biometric IDs there (database version 20)? */
function bio_alt_supported(): bool
{
    static $ok = [];
    $db = function_exists('active_db_name') ? active_db_name() : '';
    return $ok[$db] ??= mig_table_exists('employee_bio_ids');
}

/** The Other biometric IDs of an employee. */
function employee_bio_ids(int $empId): array
{
    return bio_alt_supported() ? q('SELECT bio_id FROM employee_bio_ids WHERE employee_id = ? ORDER BY id', [$empId])->fetchAll(PDO::FETCH_COLUMN) : [];
}

/**
 * Save the Other biometric IDs of an employee (comma, space or line separated). An ID already used by another employee
 * (as Biometric ID or other ID) is refused. Returns the error messages (empty = saved).
 */
function employee_bio_ids_save(int $empId, string $text): array
{
    if (!bio_alt_supported()) {
        return [];
    }
    $own = (string)val('SELECT biometric_id FROM employees WHERE id = ?', [$empId]);
    $ids = [];
    foreach (preg_split('/[\s,;]+/', trim($text)) as $b) {
        $b = trim($b);
        if ($b === '' || $b === $own || in_array($b, $ids, true)) {
            continue;
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,20}$/', $b)) {
            return ['Other biometric ID "' . $b . '": letters, digits, - and _ only (up to 20).'];
        }
        $ids[] = $b;
    }
    $errs = [];
    foreach ($ids as $b) {
        $other = row('SELECT id, employee_no FROM employees WHERE biometric_id = ? AND id <> ?', [$b, $empId])
            ?: row('SELECT e.id, e.employee_no FROM employee_bio_ids x JOIN employees e ON e.id = x.employee_id WHERE x.bio_id = ? AND x.employee_id <> ?', [$b, $empId]);
        if ($other) {
            $errs[] = 'Biometric ID ' . $b . ' already belongs to employee ' . $other['employee_no'] . '.';
        }
    }
    if ($errs) {
        return $errs;
    }
    $old = employee_bio_ids($empId);
    if ($old != $ids) {
        q('DELETE FROM employee_bio_ids WHERE employee_id = ?', [$empId]);
        foreach ($ids as $b) {
            insert('employee_bio_ids', ['employee_id' => $empId, 'bio_id' => $b, 'created_at' => now()]);
        }
        audit('pis', 'employee_bio_ids', 'employees', $empId, ['from' => $old, 'to' => $ids]);
    }
    return [];
}

/**
 * Insert parsed rows into the staging area. Returns counters (+ untyped_ids: the staged logs whose type was placed
 * by the shift code, for staging_reclassify()).
 * v24 $opt: 'group' => the other batches of the same upload (files read before this one), 'min_gap' => minutes.
 * A punch is the same punch when the same employee has one in the same minute (the seconds of two exports differ);
 * across the files of one upload, a punch within the Minimum minutes between logs of one staged from another file
 * (JJM Logs and NBJ Logs of the same terminal) is not staged twice.
 */
function staging_insert(array $rows, string $source, ?int $batchId = null, array $opt = []): array
{
    $bioMap = bio_employee_map();
    $empOf = fn($bio) => bio_employee_of($bioMap, (string)$bio);
    $branchByCode = pairs('SELECT COALESCE(branch_code, code), id FROM branches');
    $st = ['staged' => 0, 'duplicates' => 0, 'errors' => 0, 'unmatched' => 0, 'untyped_ids' => []];
    $group = array_values(array_filter(array_map('intval', (array)($opt['group'] ?? []))));
    $gap = max(1, (int)($opt['min_gap'] ?? 0));
    // v22: a terminal that records every punch with the same state (all "Out", or all "In") does not tell In from Out:
    // when two or more punches of one employee-day, an hour or more apart, all carry the same In or Out type, they are
    // placed by the shift code like untyped punches (07:58 Out + 17:05 Out = Time in + Time out)
    $days = [];
    foreach ($rows as $i => $r) {
        if (!empty($r['log_time']) && empty($r['error'])) {
            $days[(string)($r['bio_id'] ?? '') . '|' . substr((string)$r['log_time'], 0, 10)][] = $i;
        }
    }
    foreach ($days as $idx) {
        if (count($idx) < 2) {
            continue;
        }
        $types = array_unique(array_map(fn($i) => (string)($rows[$i]['punch_type'] ?? 'unknown'), $idx));
        $times = array_map(fn($i) => strtotime((string)$rows[$i]['log_time']), $idx);
        if (count($types) === 1 && in_array($types[0], ['in', 'out'], true) && max($times) - min($times) >= 3600) {
            foreach ($idx as $i) {
                $rows[$i]['punch_type'] = 'unknown';
            }
        }
    }
    $untyped = [];
    foreach ($rows as $i => $r) {
        $untyped[$i] = ($r['punch_type'] ?? 'unknown') === 'unknown';
    }
    // untyped punches are placed by the shift code (2, 4 or 6 logs a day) before they are staged
    $rows = import_classify_untyped($rows, $empOf);
    $ins = db()->prepare('INSERT INTO log_staging (batch_id, line_no, log_time, bio_id, employee_id, punch_type, co_code, branch_code, branch_id, device_id, source, raw_text, remarks, status, created_by)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($rows as $i => $r) {
        $bio = (string)($r['bio_id'] ?? '');
        $empId = $empOf($bio);
        $branchId = $r['branch_id'] ?? ($r['branch_code'] ?? null ? ($branchByCode[$r['branch_code']] ?? null) : null);
        $status = 'pending';
        $remark = $r['error'] ?? null;
        $t = !empty($r['log_time']) ? strtotime((string)$r['log_time']) : 0;
        $m0 = $t ? date('Y-m-d H:i:00', $t) : null;   // the minute of the punch
        $m1 = $t ? date('Y-m-d H:i:59', $t) : null;
        if ($remark) {
            $status = 'error';
            $st['errors']++;
        } elseif (!$empId) {
            $status = 'error';
            $remark = 'No employee has biometric ID "' . $bio . '"';
            $st['unmatched']++;
            $st['errors']++;
        } elseif (val('SELECT COUNT(*) FROM attendance_logs WHERE employee_id = ? AND log_time BETWEEN ? AND ?', [$empId, $m0, $m1])) {
            $status = 'duplicate';
            $remark = 'Already in the attendance logs';
            $st['duplicates']++;
        } elseif ($batchId && val("SELECT COUNT(*) FROM log_staging WHERE employee_id = ? AND log_time BETWEEN ? AND ? AND status = 'pending' AND batch_id = ?", [$empId, $m0, $m1, $batchId])) {
            $status = 'duplicate';
            $remark = 'Already staged';
            $st['duplicates']++;
        } elseif ($group && val("SELECT COUNT(*) FROM log_staging WHERE employee_id = ? AND status = 'pending' AND batch_id IN (" . implode(',', $group) . ') AND log_time BETWEEN ? AND ?',
                [$empId, date('Y-m-d H:i:s', $t - ($gap * 60 - 1)), date('Y-m-d H:i:s', $t + ($gap * 60 - 1))])) {
            // v24: the same punch in another file of this upload (the same terminal exported twice, or another format)
            $status = 'duplicate';
            $remark = 'Already staged from another file of this upload';
            $st['duplicates']++;
        } elseif ($batchId && val("SELECT COUNT(*) FROM log_staging WHERE employee_id = ? AND log_time BETWEEN ? AND ? AND status = 'pending'", [$empId, $m0, $m1])) {
            // v22: still pending from an earlier download or file: it joins this batch, so Post logs of this batch posts it
            // (before, it stayed behind in the earlier batch and those days never reached the Attendance Entry)
            // it takes the type read now (with the other punches of the day it is better placed than when it came alone)
            q("UPDATE log_staging SET batch_id = ?, punch_type = ? WHERE employee_id = ? AND log_time BETWEEN ? AND ? AND status = 'pending'", [$batchId, $r['punch_type'] ?? 'unknown', $empId, $m0, $m1]);
            $status = 'duplicate';
            $remark = 'Already staged: moved into this batch';
            $st['staged']++;
        } elseif (!$batchId && val("SELECT COUNT(*) FROM log_staging WHERE employee_id = ? AND log_time BETWEEN ? AND ? AND status = 'pending'", [$empId, $m0, $m1])) {
            $status = 'duplicate';
            $remark = 'Already staged';
            $st['duplicates']++;
        } else {
            $st['staged']++;
        }
        $ins->execute([$batchId, (int)($r['line_no'] ?? 0), $r['log_time'] ?? null, $bio, $empId, $r['punch_type'] ?? 'unknown',
            $r['co_code'] ?? null, $r['branch_code'] ?? null, $branchId, $r['device_id'] ?? null, $source,
            mb_substr((string)($r['raw_text'] ?? ''), 0, 250), $remark ? mb_substr($remark, 0, 250) : null, $status, user_id()]);
        if ($status === 'pending' && $untyped[$i]) {
            $st['untyped_ids'][] = (int)db()->lastInsertId();
        }
    }
    return $st;
}

/**
 * v24: place again, by the shift code, the untyped punches of an upload of several files once every file is staged:
 * the punches of one employee-day read from different files (JJM Logs and NBJ Logs, devices of Company A and B)
 * are placed together, so 08:00 from one file and 12:00, 13:00, 17:00 from another make Time in, Break out, Break in,
 * Time out. Punches typed by their file (In / Out / Break) are counted but keep their type. Returns the logs changed.
 */
function staging_reclassify(array $ids): int
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return 0;
    }
    $days = [];
    foreach (array_chunk($ids, 1000) as $chunk) {
        foreach (rows("SELECT id, employee_id, log_time FROM log_staging WHERE status = 'pending' AND employee_id IS NOT NULL AND id IN (" . implode(',', $chunk) . ')') as $r) {
            $days[(int)$r['employee_id']][substr((string)$r['log_time'], 0, 10)][(int)$r['id']] = (string)$r['log_time'];
        }
    }
    $n = 0;
    foreach ($days as $empId => $dates) {
        $emp = att_employee($empId);
        if (!$emp) {
            continue;
        }
        foreach ($dates as $date => $mine) {
            $all = array_merge(
                q("SELECT log_time FROM log_staging WHERE employee_id = ? AND status = 'pending' AND log_time BETWEEN ? AND ?", [$empId, $date . ' 00:00:00', $date . ' 23:59:59'])->fetchAll(PDO::FETCH_COLUMN),
                q('SELECT log_time FROM attendance_logs WHERE employee_id = ? AND log_time BETWEEN ? AND ?', [$empId, $date . ' 00:00:00', $date . ' 23:59:59'])->fetchAll(PDO::FETCH_COLUMN));
            $times = [];
            foreach ($all as $t) {
                $times[substr((string)$t, 0, 16)] = (string)$t;   // the same minute twice counts once
            }
            $typeOf = classify_punches_by_shift($emp, $date, array_values($times));
            foreach ($mine as $id => $t) {
                $k = $times[substr($t, 0, 16)] ?? $t;
                $type = $typeOf[$t] ?? ($typeOf[$k] ?? 'unknown');
                $n += q("UPDATE log_staging SET punch_type = ? WHERE id = ? AND punch_type <> ?", [$type, $id, $type])->rowCount();
            }
        }
    }
    return $n;
}

/**
 * Post staged rows to the attendance logs and re-process the affected days.
 * v22: $range [from, to] (YYYY-MM-DD) posts only the logs of those dates; $batchIds posts several batches (the files of
 * one upload) at once. The pending logs that a later download of the same punches marked "Already staged" are posted
 * with the batch, so no day stays behind.
 */
function staging_post(?int $batchId = null, array $ids = [], ?array $range = null, array $batchIds = []): array
{
    @set_time_limit(600);   // recomputing many employee-days takes time on a busy server
    $where = ["s.status = 'pending'"];
    $params = [];
    $batches = array_values(array_filter(array_map('intval', $batchId ? [$batchId] : $batchIds)));
    if ($batches) {
        $in = implode(',', $batches);
        $where[] = "(s.batch_id IN ($in) OR EXISTS (SELECT 1 FROM log_staging d WHERE d.batch_id IN ($in) AND d.status = 'duplicate'
                     AND d.bio_id = s.bio_id AND d.log_time = s.log_time AND d.remarks LIKE 'Already staged%'))";
    }
    if ($ids) {
        $where[] = 's.id IN (' . implode(',', array_map('intval', $ids)) . ')';
    }
    if ($range) {
        $where[] = 's.log_time BETWEEN ? AND ?';
        $params[] = $range[0] . ' 00:00:00';
        $params[] = $range[1] . ' 23:59:59';
    }
    $rows = rows('SELECT s.* FROM log_staging s WHERE ' . implode(' AND ', $where) . ' ORDER BY s.log_time LIMIT 50000', $params);
    $st = ['posted' => 0, 'duplicates' => 0, 'errors' => 0, 'days' => 0, 'locked' => 0];
    $touched = [];
    $ins = db()->prepare('INSERT IGNORE INTO attendance_logs (employee_id, bio_id, log_time, punch_type, source, branch_id, batch_id, device_id) VALUES (?,?,?,?,?,?,?,?)');
    foreach ($rows as $r) {
        if (!$r['employee_id'] || !$r['log_time']) {
            update('log_staging', ['status' => 'error', 'remarks' => $r['remarks'] ?: 'Unknown employee or time'], 'id = ?', [$r['id']]);
            $st['errors']++;
            continue;
        }
        if (val('SELECT COUNT(*) FROM attendance_logs WHERE employee_id = ? AND log_time = ?', [$r['employee_id'], $r['log_time']])) {
            update('log_staging', ['status' => 'duplicate', 'remarks' => 'Already in the attendance logs'], 'id = ?', [$r['id']]);
            $st['duplicates']++;
            // the day is computed again all the same (an earlier posting may have been cut short)
            $touched[(int)$r['employee_id']][substr((string)$r['log_time'], 0, 10)] = true;
            continue;
        }
        $ins->execute([$r['employee_id'], $r['bio_id'], $r['log_time'], $r['punch_type'] ?: 'unknown',
            $r['source'] === 'device' ? 'biometric' : 'import_txt', $r['branch_id'], $r['batch_id'], $r['device_id']]);
        update('log_staging', ['status' => 'posted'], 'id = ?', [$r['id']]);
        $st['posted']++;
        $touched[(int)$r['employee_id']][substr((string)$r['log_time'], 0, 10)] = true;
    }
    // the logs of the selection already in the attendance logs (re-staged from the log table or downloaded again): their
    // days are computed again too, so posting again repairs a day that shows nothing although its logs are there
    $dupWhere = $where;
    $dupWhere[0] = "s.status = 'duplicate' AND s.remarks = 'Already in the attendance logs' AND s.employee_id IS NOT NULL";
    foreach (rows('SELECT DISTINCT s.employee_id, DATE(s.log_time) AS d FROM log_staging s WHERE ' . implode(' AND ', $dupWhere) . ' LIMIT 20000', $params) as $x) {
        $touched[(int)$x['employee_id']][(string)$x['d']] = true;
    }
    foreach ($touched as $emp => $dates) {
        // each day once, with the day before (a night shift that ends on the day of the log)
        $set = [];
        foreach (array_keys($dates) as $d) {
            $set[$d] = true;
            $set[add_days($d, -1)] = true;
        }
        $e = att_employee((int)$emp);
        foreach (array_keys($set) as $d) {
            if ($e && dtr_locked($e, $d)) {
                $st['locked']++;
                continue;
            }
            process_dtr((int)$emp, $d);
            $st['days']++;
        }
    }
    audit('ams', 'staging_post', 'log_staging', $batchId, $st + ['from' => $range[0] ?? null, 'to' => $range[1] ?? null, 'batches' => $batches]);
    return $st;
}

/** Counters of the staging area. */
function staging_stats(?int $batchId = null): array
{
    $sql = "SELECT status, COUNT(*) n FROM log_staging" . ($batchId ? ' WHERE batch_id = ' . (int)$batchId : '') . ' GROUP BY status';
    $o = ['pending' => 0, 'posted' => 0, 'error' => 0, 'duplicate' => 0];
    foreach (rows($sql) as $r) {
        $o[$r['status']] = (int)$r['n'];
    }
    return $o;
}

/** Re-stage logs that are already in the HRIS attendance log table (re-upload from the log table). */
function staging_from_log_table(string $from, string $to, ?int $branchId = null, ?int $empId = null): array
{
    $w = ['DATE(l.log_time) BETWEEN ? AND ?'];
    $p = [$from, $to];
    if ($branchId) {
        $w[] = 'l.branch_id = ?';
        $p[] = $branchId;
    }
    if ($empId) {
        $w[] = 'l.employee_id = ?';
        $p[] = $empId;
    }
    $logs = rows('SELECT l.*, e.biometric_id FROM attendance_logs l LEFT JOIN employees e ON e.id = l.employee_id WHERE ' . implode(' AND ', $w) . ' ORDER BY l.log_time LIMIT 20000', $p);
    $batch = insert('import_batches', ['batch_type' => 'log_table', 'file_name' => 'attendance logs ' . $from . ' to ' . $to,
        'branch_id' => $branchId, 'total_rows' => count($logs), 'uploaded_by' => user_id()]);
    $rows = [];
    $n = 0;
    foreach ($logs as $l) {
        $rows[] = ['line_no' => ++$n, 'bio_id' => $l['bio_id'] ?: $l['biometric_id'], 'log_time' => $l['log_time'], 'punch_type' => $l['punch_type'],
            'branch_id' => $l['branch_id'], 'device_id' => $l['device_id'], 'raw_text' => 'attendance_logs #' . $l['id']];
    }
    $st = staging_insert($rows, 'log_table', $batch);
    update('import_batches', ['imported' => $st['staged'], 'duplicates' => $st['duplicates'], 'flagged' => $st['errors']], 'id = ?', [$batch]);
    return array_merge($st, ['read' => count($logs), 'batch' => $batch]);
}

/**
 * Read a log file with an import profile: delimited columns or fixed-width positions.
 * Returns rows with line, bio_id, log_time, punch_type, device_id, co_code, branch_code, raw_text, error.
 */
function parse_log_file(string $path, array $profile, int $limit = 0, ?array $range = null, int &$outside = 0): array
{
    $layout = $profile['layout'] ?? 'delimited';
    if ($layout === 'report') {
        // v22: an attendance record report (Excel), e.g. JJM Logs: one block per employee, the punches under the day numbers
        return import_min_gap(parse_report_file($path, $profile, $range, $outside), (int)($profile['min_gap'] ?? 0), $limit);
    }
    if ($layout !== 'fixed') {
        $out = [];
        foreach (parse_biometric_file($path, $profile, 0, $range, $outside) as $r) {
            $out[] = ['line_no' => $r['line'], 'bio_id' => $r['bio_id'], 'log_time' => $r['time'], 'punch_type' => $r['type'] === 'unknown' ? 'unknown' : $r['type'],
                'device_id' => $r['device'], 'raw_text' => $r['raw'], 'error' => $r['error']];
        }
        return import_min_gap($out, (int)($profile['min_gap'] ?? 0), $limit);
    }
    $cut = function (string $line, $start, $width) {
        $start = (int)$start;
        $width = (int)$width;
        if ($start <= 0 || $width <= 0) {
            return '';
        }
        return trim(substr($line, $start - 1, $width));
    };
    $rows = [];
    $n = 0;
    $fh = fopen($path, 'r');
    while (($line = fgets($fh)) !== false) {
        $n++;
        if ($n === 1) {
            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
            if (!empty($profile['has_header'])) {
                continue;
            }
        }
        $line = rtrim($line, "\r\n");
        if (trim($line) === '') {
            continue;
        }
        $bio = ltrim($cut($line, $profile['fw_id_start'], $profile['fw_id_width']), '0');
        $date = $cut($line, $profile['fw_date_start'], $profile['fw_date_width']);
        $time = $cut($line, $profile['fw_time_start'], $profile['fw_time_width']);
        $typeRaw = $cut($line, $profile['fw_type_start'], $profile['fw_type_width']);
        $dev = $cut($line, $profile['fw_dev_start'], $profile['fw_dev_width']);
        $stamp = import_parse_datetime(trim($date . ' ' . $time), $profile['datetime_format'] ?: 'Y-m-d H:i:s');
        $map = import_punch_map($profile['punch_map']);
        $type = $typeRaw === '' || !$map ? 'unknown' : ($map[strtoupper($typeRaw)] ?? 'unknown');
        $err = null;
        if ($bio === '') {
            $err = 'Missing employee / biometric ID';
        } elseif (!$stamp) {
            $err = 'Unreadable date/time "' . trim($date . ' ' . $time) . '"';
        }
        if ($range && $stamp && (substr($stamp, 0, 10) < $range[0] || substr($stamp, 0, 10) > $range[1])) {
            $outside++;
            continue;
        }
        $rows[] = ['line_no' => $n, 'bio_id' => $bio, 'log_time' => $stamp, 'punch_type' => $type, 'device_id' => $dev ?: null,
            'raw_text' => $line, 'error' => $err];
        if ($limit && !(int)($profile['min_gap'] ?? 0) && count($rows) >= $limit) {
            break;
        }
    }
    fclose($fh);
    return import_min_gap($rows, (int)($profile['min_gap'] ?? 0), $limit);
}

/**
 * v22 "Minimum minutes between logs" of the import configuration: a punch of the same employee and day that
 * follows the previous one by less than that many minutes is a repeated tap on the terminal (07:57 and 07:58)
 * and is not staged; the first one is kept. 0 = keep every punch. $limit cuts the result (test import).
 */
function import_min_gap(array $rows, int $minutes, int $limit = 0): array
{
    if ($minutes > 0) {
        $order = array_keys($rows);
        usort($order, fn($a, $b) => strcmp((string)$rows[$a]['bio_id'], (string)$rows[$b]['bio_id']) ?: strcmp((string)$rows[$a]['log_time'], (string)$rows[$b]['log_time']) ?: ($a <=> $b));
        $last = [];
        $drop = [];
        foreach ($order as $i) {
            $r = $rows[$i];
            if (!empty($r['error']) || empty($r['log_time'])) {
                continue;
            }
            $k = $r['bio_id'] . '|' . substr((string)$r['log_time'], 0, 10);
            $t = strtotime((string)$r['log_time']);
            if (isset($last[$k]) && $t - $last[$k] < $minutes * 60) {
                $drop[$i] = true;
                continue;
            }
            $last[$k] = $t;
        }
        $rows = array_values(array_diff_key($rows, $drop));
    }
    return $limit ? array_slice($rows, 0, $limit) : $rows;
}

/**
 * v22 Read an attendance record report (layout "report"), such as the JJM Logs: an Excel sheet with one block
 * per employee:
 *
 *   Attendance date:2026-10-15~2026-10-28            <- period label: gives the year and month of the days
 *   User ID: | 4 | Name: | ATON, JOSEFINA Y. | ...  <- ID label, the ID in the next cell
 *   15 | 16 | 17 | 18 | 19 | ...                     <- day numbers (Day-number row = 1 row below the ID row)
 *   07:03\n17:09 | 07:11\n17:17 | ...               <- the punches of each day under its number (First time row = 2)
 *   ...   | 17:12 |                                  <- more punches of the day continue below, up to the next ID row
 *
 * Every time found in a day's cells is one punch (no In / Out on the report: the shift code places them).
 */
function parse_report_file(string $path, array $profile, ?array $range = null, int &$outside = 0): array
{
    $grid = grid_read_file($path, max(0, (int)($profile['rp_sheet'] ?? 1) - 1));
    $norm = fn($v) => mb_strtolower(preg_replace('/\s+/u', '', (string)$v));
    $idL = $norm(($profile['rp_id_label'] ?? '') ?: 'User ID:');
    $nameL = $norm($profile['rp_name_label'] ?? '');
    $perL = $norm($profile['rp_period_label'] ?? '');
    // rows below the ID row with the day numbers; 0 = one row of day numbers for the whole sheet, above the
    // employees (NBJ Logs), found by looking up from each ID row
    $dayOff = max(0, (int)($profile['rp_day_row'] ?? 1));
    $timeOff = max(1, (int)($profile['rp_time_row'] ?? 2));
    $dateFmt = trim((string)($profile['rp_date_format'] ?? '')) ?: 'Y-m-d';
    // the value after a label: in the same cell after the label, else the next filled cell to the right
    $after = function (array $row, int $col, string $label): string {
        $txt = trim((string)$row[$col]);
        // "User ID: 12" in one cell: the text after the label
        $re = '/^' . implode('\\s*', array_map(fn($ch) => preg_quote($ch, '/'), preg_split('//u', $label, -1, PREG_SPLIT_NO_EMPTY))) . '\\s*(.*)$/isu';
        if (preg_match($re, $txt, $m) && trim($m[1]) !== '') {
            return trim($m[1]);
        }
        foreach ($row as $c => $v) {
            if ($c > $col && trim((string)$v) !== '') {
                return trim((string)$v);
            }
        }
        return '';
    };
    // the period of the report
    $start = $end = null;
    if ($perL !== '') {
        foreach ($grid as $row) {
            foreach ($row as $pc => $v) {
                if (strpos($norm($v), $perL) !== 0) {
                    continue;
                }
                // the dates in the label cell (JJM: "Attendance date:2026-10-15~2026-10-28") or in the next cells of the
                // row (NBJ: "Att. Time" | "2024-02-09 ~ 2024-02-23")
                $txt = (string)$v;
                foreach ($row as $c2 => $v2) {
                    if ($c2 > $pc && count(preg_split('#\d{4}[-/.]\d{1,2}[-/.]\d{1,2}|\d{1,2}[-/.]\d{1,2}[-/.]\d{2,4}#', $txt)) < 3) {
                        $txt .= ' ' . $v2;
                    }
                }
                preg_match_all('#\d{4}[-/.]\d{1,2}[-/.]\d{1,2}|\d{1,2}[-/.]\d{1,2}[-/.]\d{2,4}#', $txt, $m);
                $ds = [];
                foreach ($m[0] as $tok) {
                    $dt = DateTime::createFromFormat('!' . $dateFmt, $tok) ?: (strtotime($tok) ? new DateTime($tok) : null);
                    if ($dt) {
                        $ds[] = $dt->format('Y-m-d');
                    }
                }
                if ($ds) {
                    $start = $ds[0];
                    $end = $ds[1] ?? $ds[0];
                    break 2;
                }
            }
        }
    }
    if (!$start) {
        // no period on the report: the dates given for the staging (else this month)
        $start = $range[0] ?? date('Y-m-01');
        $end = $range[1] ?? date('Y-m-t');
    }
    if ($end < $start || days_between($start, $end) > 370) {
        $end = add_days($start, 62);
    }
    $dates = date_range($start, $end);
    // the employee blocks
    $blocks = [];
    foreach ($grid as $r => $row) {
        foreach ($row as $c => $v) {
            if (strpos($norm($v), $idL) === 0) {
                $id = $after($row, $c, $idL);
                $name = '';
                if ($nameL !== '') {
                    foreach ($row as $c2 => $v2) {
                        if ($c2 !== $c && strpos($norm($v2), $nameL) === 0) {
                            $name = $after($row, $c2, $nameL);
                        }
                    }
                }
                $blocks[] = ['row' => (int)$r, 'id' => preg_replace('/\.0+$/', '', $id), 'name' => $name];
                break;
            }
        }
    }
    if (!$blocks) {
        throw new RuntimeException('No "' . (($profile['rp_id_label'] ?? '') ?: 'User ID:') . '" label was found in the file: check the Employee ID label of the import configuration, or choose another configuration.');
    }
    $isDay = fn($v) => (bool)preg_match('/^\s*(\d{1,2})(\.0+)?\s*$/', (string)$v, $mm) && (int)$mm[1] >= 1 && (int)$mm[1] <= 31;
    $rowsOut = [];
    $lastRow = $grid ? max(array_keys($grid)) : 0;
    foreach ($blocks as $bi => $b) {
        $next = $blocks[$bi + 1]['row'] ?? $lastRow + 1;
        // the day numbers: the configured row, else the nearest row below the ID with day numbers
        $count = fn($rn) => count(array_filter($grid[$rn] ?? [], $isDay));
        $dayRowNo = $b['row'] + $dayOff;
        if ($dayOff === 0) {
            $dayRowNo = -1;
            for ($rn = $b['row'] - 1; $rn >= 0; $rn--) {
                if ($count($rn) >= 2) {
                    $dayRowNo = $rn;
                    break;
                }
            }
        } elseif ($count($dayRowNo) < 1) {
            for ($rn = $b['row'] + 1; $rn < min($next, $b['row'] + 4); $rn++) {
                if ($count($rn) >= 2) {
                    $dayRowNo = $rn;
                    break;
                }
            }
        }
        $dayRow = $grid[$dayRowNo] ?? [];
        ksort($dayRow);
        // day number -> date, column by column through the period (a period over two months goes on to the 1st)
        $colDate = [];
        $ptr = 0;
        foreach ($dayRow as $c => $v) {
            if (!$isDay($v)) {
                continue;
            }
            $d = (int)$v;
            while ($ptr < count($dates) && (int)substr($dates[$ptr], 8, 2) !== $d) {
                $ptr++;
            }
            if ($ptr < count($dates)) {
                $colDate[$c] = $dates[$ptr++];
            }
        }
        $punches = [];
        for ($rn = max($dayRowNo + 1, $b['row'] + $timeOff); $rn < $next; $rn++) {
            foreach ($grid[$rn] ?? [] as $c => $v) {
                if (!isset($colDate[$c])) {
                    continue;
                }
                $v = (string)$v;
                if (is_numeric($v) && (float)$v > 0 && (float)$v < 1) {
                    // an Excel time (fraction of a day)
                    $sec = (int)round((float)$v * 86400);
                    $punches[$colDate[$c]][] = [sprintf('%02d:%02d:00', intdiv($sec, 3600) % 24, intdiv($sec % 3600, 60)), $rn + 1, (int)$c];
                    continue;
                }
                preg_match_all('/(\d{1,2}):(\d{2})(?::(\d{2}))?\s*([AaPp])?\.?[Mm]?\.?/', $v, $m, PREG_SET_ORDER);
                foreach ($m as $t) {
                    $h = (int)$t[1];
                    $ap = strtolower($t[4] ?? '');
                    if ($ap === 'p' && $h < 12) {
                        $h += 12;
                    } elseif ($ap === 'a' && $h === 12) {
                        $h = 0;
                    }
                    if ($h > 23 || (int)$t[2] > 59) {
                        continue;
                    }
                    $punches[$colDate[$c]][] = [sprintf('%02d:%02d:%02d', $h, (int)$t[2], (int)($t[3] ?? 0)), $rn + 1, (int)$c];
                }
            }
        }
        ksort($punches);
        foreach ($punches as $date => $list) {
            usort($list, fn($a, $b2) => strcmp($a[0], $b2[0]));
            $seen = [];
            foreach ($list as [$time, $line, $col]) {
                if (isset($seen[$time])) {
                    continue;
                }
                $seen[$time] = true;
                if ($range && ($date < $range[0] || $date > $range[1])) {
                    $outside++;
                    continue;
                }
                $rowsOut[] = ['line_no' => $line, 'bio_id' => ltrim($b['id'], '0') ?: $b['id'], 'log_time' => $date . ' ' . $time, 'punch_type' => 'unknown',
                    'device_id' => null, 'raw_text' => trim('ID ' . $b['id'] . ($b['name'] !== '' ? ' ' . $b['name'] : '') . ', ' . $date . ' ' . substr($time, 0, 5) . ' (cell ' . import_col_letter($col) . $line . ')'),
                    'error' => $b['id'] === '' ? 'Missing employee ID next to the ID label' : null];
            }
        }
    }
    return $rowsOut;
}
