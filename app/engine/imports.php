<?php
/**
 * Attendance imports:
 *  - raw biometric device logs (.txt / .dat) read through configurable column mappings
 *  - summarized attendance from Excel (.xlsx) / CSV using the standard template
 */

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
    $bioMap = pairs('SELECT biometric_id, id FROM employees WHERE biometric_id IS NOT NULL');
    // normalize leading zeros
    foreach ($bioMap as $k => $v) {
        $bioMap[ltrim((string)$k, '0') ?: (string)$k] = $v;
    }
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

/** Insert parsed rows into the staging area. Returns counters. */
function staging_insert(array $rows, string $source, ?int $batchId = null): array
{
    $bioMap = pairs('SELECT biometric_id, id FROM employees WHERE biometric_id IS NOT NULL AND biometric_id <> ""');
    foreach ($bioMap as $k => $v) {
        $bioMap[ltrim((string)$k, '0') ?: (string)$k] = $v;
    }
    $branchByCode = pairs('SELECT COALESCE(branch_code, code), id FROM branches');
    $st = ['staged' => 0, 'duplicates' => 0, 'errors' => 0, 'unmatched' => 0];
    // untyped punches are placed by the shift code (2, 4 or 6 logs a day) before they are staged
    $rows = import_classify_untyped($rows, fn($bio) => $bioMap[ltrim($bio, '0') ?: $bio] ?? ($bioMap[$bio] ?? null));
    $ins = db()->prepare('INSERT INTO log_staging (batch_id, line_no, log_time, bio_id, employee_id, punch_type, co_code, branch_code, branch_id, device_id, source, raw_text, remarks, status, created_by)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($rows as $r) {
        $bio = (string)($r['bio_id'] ?? '');
        $bioKey = ltrim($bio, '0') ?: $bio;
        $empId = $bioMap[$bioKey] ?? ($bioMap[$bio] ?? null);
        $branchId = $r['branch_id'] ?? ($r['branch_code'] ?? null ? ($branchByCode[$r['branch_code']] ?? null) : null);
        $status = 'pending';
        $remark = $r['error'] ?? null;
        if ($remark) {
            $status = 'error';
            $st['errors']++;
        } elseif (!$empId) {
            $status = 'error';
            $remark = 'No employee has biometric ID "' . $bio . '"';
            $st['unmatched']++;
            $st['errors']++;
        } elseif (val('SELECT COUNT(*) FROM attendance_logs WHERE employee_id = ? AND log_time = ?', [$empId, $r['log_time']])) {
            $status = 'duplicate';
            $remark = 'Already in the attendance logs';
            $st['duplicates']++;
        } elseif (val("SELECT COUNT(*) FROM log_staging WHERE bio_id = ? AND log_time = ? AND status = 'pending'", [$bio, $r['log_time']])) {
            $status = 'duplicate';
            $remark = 'Already staged';
            $st['duplicates']++;
        } else {
            $st['staged']++;
        }
        $ins->execute([$batchId, (int)($r['line_no'] ?? 0), $r['log_time'] ?? null, $bio, $empId, $r['punch_type'] ?? 'unknown',
            $r['co_code'] ?? null, $r['branch_code'] ?? null, $branchId, $r['device_id'] ?? null, $source,
            mb_substr((string)($r['raw_text'] ?? ''), 0, 250), $remark ? mb_substr($remark, 0, 250) : null, $status, user_id()]);
    }
    return $st;
}

/** Post staged rows to the attendance logs and re-process the affected days. */
function staging_post(?int $batchId = null, array $ids = []): array
{
    $where = ["status = 'pending'"];
    $params = [];
    if ($batchId) {
        $where[] = 'batch_id = ?';
        $params[] = $batchId;
    }
    if ($ids) {
        $where[] = 'id IN (' . implode(',', array_map('intval', $ids)) . ')';
    }
    $rows = rows('SELECT * FROM log_staging WHERE ' . implode(' AND ', $where) . ' ORDER BY log_time LIMIT 20000', $params);
    $st = ['posted' => 0, 'duplicates' => 0, 'errors' => 0];
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
            continue;
        }
        $ins->execute([$r['employee_id'], $r['bio_id'], $r['log_time'], $r['punch_type'] ?: 'unknown',
            $r['source'] === 'device' ? 'biometric' : 'import_txt', $r['branch_id'], $r['batch_id'], $r['device_id']]);
        update('log_staging', ['status' => 'posted'], 'id = ?', [$r['id']]);
        $st['posted']++;
        $touched[(int)$r['employee_id']][substr((string)$r['log_time'], 0, 10)] = true;
    }
    foreach ($touched as $emp => $dates) {
        foreach (array_keys($dates) as $d) {
            process_dtr((int)$emp, $d);
            process_dtr((int)$emp, add_days($d, -1));
        }
    }
    audit('ams', 'staging_post', 'log_staging', $batchId, $st);
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
    $fixed = ($profile['layout'] ?? 'delimited') === 'fixed';
    if (!$fixed) {
        $out = [];
        foreach (parse_biometric_file($path, $profile, $limit, $range, $outside) as $r) {
            $out[] = ['line_no' => $r['line'], 'bio_id' => $r['bio_id'], 'log_time' => $r['time'], 'punch_type' => $r['type'] === 'unknown' ? 'unknown' : $r['type'],
                'device_id' => $r['device'], 'raw_text' => $r['raw'], 'error' => $r['error']];
        }
        return $out;
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
        if ($limit && count($rows) >= $limit) {
            break;
        }
    }
    fclose($fh);
    return $rows;
}
