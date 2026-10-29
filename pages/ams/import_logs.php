<?php
/**
 * Customized Import Attendance Log — the staging area where raw logs are collected
 * (from a .dat / .txt / .csv file, from the HRIS attendance log table or straight from a
 * biometric device), checked, corrected and finally posted to the attendance logs.
 */

/**
 * Arrange staged punches into the Attendance Entry layout: one row per employee and day,
 * with the log columns of the employee's shift code filled (2, 4 or 6 logs per day).
 */
function staging_day_grid(array $rows): array
{
    $days = [];
    foreach ($rows as $r) {
        if (!$r['log_time']) {
            continue;
        }
        $key = ($r['employee_id'] ? 'e' . $r['employee_id'] : 'b' . $r['bio_id']) . '|' . substr($r['log_time'], 0, 10);
        $days[$key]['emp_id'] = $r['employee_id'] ? (int)$r['employee_id'] : null;
        $days[$key]['bio_id'] = $r['bio_id'];
        $days[$key]['name'] = $r['employee_id'] ? full_name($r) : null;
        $days[$key]['employee_no'] = $r['employee_no'] ?? null;
        $days[$key]['date'] = substr($r['log_time'], 0, 10);
        $days[$key]['logs'][] = $r;
    }
    $emps = [];
    $out = [];
    foreach ($days as $d) {
        $shift = null;
        $lpd = 4;
        $breaks = [];
        if ($d['emp_id']) {
            $emps[$d['emp_id']] = $emps[$d['emp_id']] ?? att_employee($d['emp_id']);
            if ($emps[$d['emp_id']]) {
                $sched = employee_schedule($emps[$d['emp_id']], $d['date']);
                $shift = $sched['shift'];
                $lpd = shift_logs_per_day($shift);
                $breaks = $sched['breaks'];
            }
        }
        usort($d['logs'], fn($a, $b) => strcmp($a['log_time'], $b['log_time']));
        $times = [];
        $seen = [];
        $dups = 0;
        foreach ($d['logs'] as $l) {
            $k = $l['punch_type'] . '|' . substr($l['log_time'], 0, 16);
            if (isset($seen[$k])) {
                $dups++;
                continue;
            }
            if ($l['status'] === 'duplicate') {
                $dups++; // already posted earlier; still shown in its slot so the day reads correctly
            }
            $seen[$k] = true;
            $times[] = $l;
        }
        $slots = ['in' => null, 'bo1' => null, 'bi1' => null, 'bo2' => null, 'bi2' => null, 'out' => null];
        $typed = array_filter($times, fn($l) => in_array($l['punch_type'], ['break_out', 'break_in'], true));
        $n = count($times);
        if ($n && (!$typed || $lpd === 2)) {
            // the same placement as the attendance engine: by position, a missing break log decided by the shift's break times
            $slots = punch_slots_by_position(array_column($times, 'log_time'), $lpd, $breaks);
        } elseif ($n) {
            foreach ($times as $l) {
                $t = $l['log_time'];
                switch ($l['punch_type']) {
                    case 'in':
                        $slots['in'] = $slots['in'] ?? $t;
                        break;
                    case 'out':
                        $slots['out'] = $t;
                        break;
                    case 'break_out':
                        if (!$slots['bo1']) {
                            $slots['bo1'] = $t;
                        } elseif ($lpd >= 6 && !$slots['bo2'] && $slots['bi1']) {
                            $slots['bo2'] = $t;
                        }
                        break;
                    case 'break_in':
                        if ($slots['bo1'] && !$slots['bi1']) {
                            $slots['bi1'] = $t;
                        } elseif ($lpd >= 6 && $slots['bo2'] && !$slots['bi2']) {
                            $slots['bi2'] = $t;
                        }
                        break;
                    default:
                        $slots['in'] = $slots['in'] ?? $t;
                        if ($slots['in'] !== $t) {
                            $slots['out'] = $t;
                        }
                }
            }
        }
        $used = count(array_filter($slots));
        $extra = max(0, $n - $used);
        if (!$d['emp_id']) {
            $status = 'Unknown biometric ID';
            $tone = 'bad';
        } elseif (!$slots['in'] && !$slots['out']) {
            $status = 'No time in / out';
            $tone = 'bad';
        } elseif (!$slots['in'] || !$slots['out']) {
            $status = !$slots['in'] ? 'Missing time in' : 'Missing time out';
            $tone = 'warn';
        } elseif ($lpd >= 4 && (!$slots['bo1'] || !$slots['bi1'])) {
            $status = 'No break log';
            $tone = 'warn';
        } else {
            $status = 'Complete';
            $tone = 'good';
        }
        if ($extra) {
            $status .= ", $extra extra log" . ($extra > 1 ? 's' : '');
        }
        if ($dups) {
            $status .= ", $dups duplicate" . ($dups > 1 ? 's' : '');
        }
        $d['shift'] = $shift['code'] ?? ($d['emp_id'] ? 'default' : '—');
        $d['lpd'] = $lpd;
        $d['slots'] = $slots;
        $d['status'] = $status;
        $d['tone'] = $tone;
        $d['count'] = $n;
        $out[] = $d;
    }
    usort($out, fn($a, $b) => strcmp($a['name'] ?? ('~' . $a['bio_id']), $b['name'] ?? ('~' . $b['bio_id'])) ?: strcmp($a['date'], $b['date']));
    return $out;
}

$profiles = rows('SELECT * FROM import_profiles ORDER BY ' . (import_profiles_sorted() ? 'sort_order, ' : '') . 'id');   // v19: the order set in Import Log Configuration
$devices = rows('SELECT d.*, b.name AS branch_name FROM bio_devices d LEFT JOIN branches b ON b.id = d.branch_id WHERE d.is_active = 1 ORDER BY d.dev_no');
$batchId = (int)get('batch_id', 0);
$batchIds = array_values(array_filter(array_map('intval', explode(',', (string)get('batches', '')))));   // v19: the files of one multi-file upload
$qF = trim((string)get('q', ''));
$perOpts = [100 => '100 a page', 500 => '500 a page', 1000 => '1,000 a page', 2500 => '2,500 a page', 0 => 'All'];
$per = array_key_exists((int)get('per', 500), $perOpts) ? (int)get('per', 500) : 500;
$pg = max(1, (int)get('pg', 1));
$statusF = (string)get('status', '');
// v24: the staged logs of some dates only (Log dates from / to); "Select logs" opens them all with every log ticked
$okD = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d);
$dFromF = $okD(get('dfrom')) ? (string)get('dfrom') : '';
$dToF = $okD(get('dto')) ? (string)get('dto') : '';
$selectRange = get('select') === '1' && $dFromF !== '' && $dToF !== '';
$view = get('view') === 'days' ? 'days' : 'list';   // list = one row per punch, days = Attendance Entry format

if (is_post()) {
    verify_csrf();
    $action = post('_action');
    try {
        switch ($action) {
            case 'file':
                // v19: one, two or more log files at once (a punch can be logged on the device of another company:
                // Time in at Company A, Break out / in at Company B, Time out at Company C); each file has its own
                // import configuration and company / branch, and only the logs of the dates given are staged.
                $files = [];
                $fn = $_FILES['logfile']['name'] ?? null;
                foreach ((array)$fn as $k => $name) {
                    $tmp = is_array($fn) ? ($_FILES['logfile']['tmp_name'][$k] ?? '') : ($_FILES['logfile']['tmp_name'] ?? '');
                    $err = is_array($fn) ? ($_FILES['logfile']['error'][$k] ?? 4) : ($_FILES['logfile']['error'] ?? 4);
                    if ((string)$name !== '' && $err === UPLOAD_ERR_OK && is_uploaded_file($tmp)) {
                        $files[] = ['name' => (string)$name, 'tmp' => $tmp, 'i' => (int)$k];
                    } elseif ((string)$name !== '' && $err !== UPLOAD_ERR_NO_FILE) {
                        throw new RuntimeException('The file ' . $name . ' could not be uploaded (' . ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE ? 'too large for the server: give a narrower date range or split the file' : 'upload error ' . $err) . ').');
                    }
                }
                if (!$files) {
                    throw new RuntimeException('Choose one or more log files first (.dat, .txt, .log, .csv, .tsv, or an Excel attendance report .xls / .xlsx).');
                }
                $from = (string)post('date_from');
                $to = (string)post('date_to');
                $okDate = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
                if (!$okDate($from) || !$okDate($to) || $to < $from) {
                    throw new RuntimeException('Give the Date from and Date to of the logs to stage (the cut-off), the To date on or after the From date.');
                }
                if (days_between($from, $to) > 92) {
                    throw new RuntimeException('Stage at most 3 months of logs at a time (Date from to Date to).');
                }
                $profilesById = [];
                foreach ($profiles as $pr) {
                    $profilesById[(int)$pr['id']] = $pr;
                }
                $defProfile = (int)post('profile_id');
                $defBranch = (int)post('branch_id') ?: null;
                $perProfile = (array)post('file_profile', []);
                $perBranch = (array)post('file_branch', []);
                $ids = [];
                $msgs = [];
                $tot = ['read' => 0, 'staged' => 0, 'duplicates' => 0, 'errors' => 0, 'outside' => 0];
                $untypedIds = [];
                foreach ($files as &$f) {
                    $ext = strtolower((string)pathinfo($f['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, ['dat', 'txt', 'log', 'csv', 'tsv', 'xls', 'xlsx', 'htm', 'html'], true)) {
                        throw new RuntimeException($f['name'] . ': only .dat, .txt, .log, .csv, .tsv and the Excel attendance reports (.xls, .xlsx) can be read here. Use Excel / CSV Import for the summarized template.');
                    }
                    $f['profile'] = $profilesById[(int)($perProfile[$f['i']] ?? 0)] ?? ($profilesById[$defProfile] ?? null);
                    if (!$f['profile']) {
                        throw new RuntimeException('Select the import configuration of ' . $f['name'] . '.');
                    }
                    // v22: an Excel report (JJM Logs) is read only with a report configuration, and a report configuration only reads reports
                    $isReport = ($f['profile']['layout'] ?? '') === 'report';
                    if (in_array($ext, ['xls', 'xlsx'], true) && !$isReport) {
                        throw new RuntimeException($f['name'] . ' is an Excel file: choose a configuration of the layout "Attendance record report", such as JJM Logs or NBJ Logs.');
                    }
                    // v24: an Excel report is read with the report configuration whose labels it carries (JJM Logs: "User ID:",
                    // NBJ Logs: "ID:" and one row of day numbers at the top), whichever of them was chosen for the file
                    if ($isReport) {
                        $fit = import_report_profile_for($f['tmp'], $profiles, $f['profile']);
                        if ($fit && (int)$fit['id'] !== (int)$f['profile']['id']) {
                            flash('info', $f['name'] . ' is in the ' . $fit['name'] . ' format: read with that configuration instead of ' . $f['profile']['name'] . '.');
                            $f['profile'] = $fit;
                        }
                    }
                    $f['typed'] = ($f['profile']['layout'] === 'fixed' ? (int)$f['profile']['fw_type_width'] > 0 : (bool)$f['profile']['col_punch']) && trim((string)$f['profile']['punch_map']) !== '';
                }
                unset($f);
                // the files that say In / Out / Break first: the punches of a file without log type are then placed
                // among the punches of the same day read from the other files (Time in at A, breaks at B, Time out at C)
                usort($files, fn($a, $b) => ($b['typed'] <=> $a['typed']) ?: ($a['i'] <=> $b['i']));
                foreach ($files as $f) {
                    $profile = $f['profile'];
                    $branch = isset($perBranch[$f['i']]) && $perBranch[$f['i']] !== '' ? ((int)$perBranch[$f['i']] ?: null) : $defBranch;
                    $branch = $branch ?: ($profile['default_branch_id'] ? (int)$profile['default_branch_id'] : null);
                    $outside = 0;
                    $rows = parse_log_file($f['tmp'], $profile, 0, [$from, $to], $outside);
                    $batch = insert('import_batches', ['batch_type' => 'biometric', 'profile_id' => (int)$profile['id'], 'file_name' => mb_substr($f['name'], 0, 255),
                        'branch_id' => $branch, 'total_rows' => count($rows), 'uploaded_by' => user_id()]);
                    foreach ($rows as &$r) {
                        $r['branch_id'] = $branch;
                    }
                    unset($r);
                    // v24: a punch already staged from another file of this upload (same employee, within the minimum
                    // minutes between logs) is the same punch; the untyped ones are placed again once every file is in
                    $st = staging_insert($rows, 'file', $batch, ['group' => $ids, 'min_gap' => (int)($profile['min_gap'] ?? 0)]);
                    $untypedIds = array_merge($untypedIds, $st['untyped_ids']);
                    unset($st['untyped_ids']);
                    update('import_batches', ['imported' => $st['staged'], 'duplicates' => $st['duplicates'], 'flagged' => $st['errors']], 'id = ?', [$batch]);
                    audit('ams', 'import_file_stage', 'import_batches', $batch, $st + ['from' => $from, 'to' => $to, 'outside_dates' => $outside]);
                    $ids[] = $batch;
                    $tot['read'] += count($rows);
                    $tot['outside'] += $outside;
                    foreach (['staged', 'duplicates', 'errors'] as $k) {
                        $tot[$k] += $st[$k];
                    }
                    $msgs[] = sprintf('%s (%s): %d staged, %d duplicate(s), %d error(s)%s.', $f['name'], $profile['name'], $st['staged'], $st['duplicates'], $st['errors'],
                        $outside ? ', ' . number_format($outside) . ' log(s) outside the dates skipped' : '');
                }
                if (count($files) > 1 && $untypedIds) {
                    // the punches of one employee-day from different files (JJM Logs + NBJ Logs, Company A + B) placed together
                    $moved = staging_reclassify($untypedIds);
                    if ($moved) {
                        flash('info', $moved . ' log(s) placed again with the punches of the same day from the other files (Time in, Break out, Break in, Time out).');
                    }
                }
                flash($tot['staged'] ? 'success' : 'warning', sprintf('%d file(s), %s to %s: %d log(s) read, %d staged, %d duplicate(s), %d error(s)%s.', count($files), fmt_date($from), fmt_date($to),
                    $tot['read'], $tot['staged'], $tot['duplicates'], $tot['errors'], $tot['outside'] ? ', ' . number_format($tot['outside']) . ' outside the dates not staged' : ''));
                if (count($files) > 1) {
                    foreach ($msgs as $m) {
                        flash('info', $m);
                    }
                }
                redirect('import_logs', count($ids) === 1 ? ['batch_id' => $ids[0]] : ['batches' => implode(',', $ids)]);
                // no break
            case 'log_table':
                $st = staging_from_log_table((string)post('from'), (string)post('to'), (int)post('branch_id') ?: null, (int)post('employee_id') ?: null);
                flash('success', sprintf('%d log(s) read from the attendance table: %d staged, %d already posted.', $st['read'], $st['staged'], $st['duplicates']));
                redirect('import_logs', ['batch_id' => $st['batch']]);
                // no break
            case 'device':
                $dev = row('SELECT * FROM bio_devices WHERE id = ?', [(int)post('device_id')]);
                if (!$dev) {
                    throw new RuntimeException('Select a biometric device.');
                }
                // v22: Date From / Date To of the logs to download
                $dFrom = (string)post('date_from');
                $dTo = (string)post('date_to');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dTo) || $dTo < $dFrom) {
                    throw new RuntimeException('Give the Date from and Date to of the logs to download, the To date on or after the From date.');
                }
                $st = bio_device_download($dev, $dFrom, (bool)post('clear_after'), $dTo);
                flash('success', sprintf('%s: %d log(s) on the device, %d of %s to %s: %d staged, %d duplicate(s).', $dev['name'], $st['read_device'], $st['read'],
                    fmt_date($dFrom), fmt_date($dTo), $st['staged'], $st['duplicates']));
                if (post('clear_after') && !$st['cleared'] && $st['outside']) {
                    flash('warning', 'The device log was not cleared: ' . number_format($st['outside']) . ' log(s) on the device are outside these dates and would have been lost.');
                }
                redirect('import_logs', ['batch_id' => $st['batch']]);
                // no break
            case 'post':
                // v22: only the logs of Post from / Post to are posted (of the batch chosen, or of every batch)
                $pFrom = (string)post('post_from');
                $pTo = (string)post('post_to');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $pTo) || $pTo < $pFrom) {
                    throw new RuntimeException('Give the dates of the logs to post (Post from and to), the To date on or after the From date.');
                }
                $st = staging_post($batchId ?: null, array_map('intval', (array)post('ids', [])), [$pFrom, $pTo], $batchId ? [] : $batchIds);
                flash($st['posted'] || $st['duplicates'] || $st['days'] ? 'success' : 'warning', sprintf('%s to %s: %d log(s) posted to the attendance records, %d already posted, %d error(s); %d employee-day(s) recomputed.',
                    fmt_date($pFrom), fmt_date($pTo), $st['posted'], $st['duplicates'], $st['errors'], $st['days']));
                if ($st['locked']) {
                    flash('warning', $st['locked'] . ' employee-day(s) belong to a closed attendance period and were not recomputed: re-open the period (Attendance Transaction Closing) and post again.');
                }
                break;
            case 'delete':
                $ids = array_map('intval', (array)post('ids', []));
                if ($ids) {
                    q('DELETE FROM log_staging WHERE id IN (' . implode(',', $ids) . ')');
                    flash('success', count($ids) . ' staged log(s) deleted.');
                }
                break;
            case 'delete_all':
                $w = $batchId ? ' WHERE batch_id = ' . $batchId : ($batchIds ? ' WHERE batch_id IN (' . implode(',', $batchIds) . ')' : '');
                $n = (int)val('SELECT COUNT(*) FROM log_staging' . $w);
                q('DELETE FROM log_staging' . $w);
                audit('ams', 'staging_clear', 'log_staging', $batchId ?: null, ['deleted' => $n]);
                flash('success', $n . ' staged log(s) deleted.');
                break;
            case 'assign':
                $id = (int)post('id');
                $emp = (int)post('employee_id');
                $r = row('SELECT * FROM log_staging WHERE id = ?', [$id]);
                if ($r && $emp) {
                    update('log_staging', ['employee_id' => $emp, 'status' => 'pending', 'remarks' => 'Employee assigned manually'], 'id = ?', [$id]);
                    if (post('remember') && $r['bio_id']) {
                        // v24: an employee who already has another Biometric ID keeps it; this one is added to the
                        // Other biometric IDs (the user ID of the same person on another terminal or log format)
                        $cur = (string)val('SELECT biometric_id FROM employees WHERE id = ?', [$emp]);
                        if ($cur !== '' && $cur !== (string)$r['bio_id'] && bio_alt_supported()) {
                            if (!(int)val('SELECT COUNT(*) FROM employee_bio_ids WHERE bio_id = ?', [$r['bio_id']]) && !(int)val('SELECT COUNT(*) FROM employees WHERE biometric_id = ?', [$r['bio_id']])) {
                                insert('employee_bio_ids', ['employee_id' => $emp, 'bio_id' => (string)$r['bio_id'], 'note' => 'Import Attendance Logs', 'created_at' => now()]);
                                audit('pis', 'employee_bio_ids', 'employees', $emp, ['added' => (string)$r['bio_id']]);
                            }
                        } else {
                            q('UPDATE employees SET biometric_id = ? WHERE id = ?', [$r['bio_id'], $emp]);
                        }
                        q("UPDATE log_staging SET employee_id = ?, status = 'pending' WHERE bio_id = ? AND status = 'error'", [$emp, $r['bio_id']]);
                    }
                    flash('success', 'Employee assigned to the log.');
                }
                break;
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    } catch (ZkError $e) {
        flash('error', $e->getMessage());
    }
    redirect('import_logs', array_filter(['batch_id' => $batchId ?: null, 'batches' => $batchIds ? implode(',', $batchIds) : null, 'status' => $statusF ?: null, 'q' => $qF !== '' ? $qF : null,
        'view' => $view === 'days' ? 'days' : null, 'per' => $per !== 500 ? $per : null, 'dfrom' => $dFromF ?: null, 'dto' => $dToF ?: null]));
}

$where = [];
$params = [];
if ($batchId) {
    $where[] = 's.batch_id = ?';
    $params[] = $batchId;
} elseif ($batchIds) {
    $where[] = 's.batch_id IN (' . implode(',', $batchIds) . ')';
}
if ($qF !== '') {
    // v19: find the logs of one employee (name, employee no. or biometric ID) among all the staged logs
    $params[] = $qF;
    $w = search_words_sql(['e.employee_no', 'e.last_name', 'e.first_name', 'e.biometric_id'], $qF, $params);
    $where[] = '(s.bio_id = ?' . ($w ? ' OR ' . $w : '') . ')';
}
if ($dFromF !== '') {
    $where[] = 's.log_time >= ?';
    $params[] = $dFromF . ' 00:00:00';
}
if ($dToF !== '') {
    $where[] = 's.log_time <= ?';
    $params[] = $dToF . ' 23:59:59';
}
if ($statusF) {
    $where[] = 's.status = ?';
    $params[] = $statusF;
}
// v19: every staged log can be viewed: page by page (100 to 2,500 a page) or all at once
$fromSql = ' FROM log_staging s LEFT JOIN employees e ON e.id = s.employee_id LEFT JOIN branches b ON b.id = s.branch_id'
    . ' WHERE ' . ($where ? implode(' AND ', $where) : '1=1') . scope_sql_or_null('e', 's.employee_id');
$total = (int)val('SELECT COUNT(*)' . $fromSql, $params);
$pages = $per && $view !== 'days' ? max(1, (int)ceil($total / $per)) : 1;
$pg = min($pg, $pages);
// the Attendance entry format needs every punch of a day together: all the logs of the selection (up to 20,000)
$limit = $view === 'days' ? ' LIMIT 20000' : ($per ? ' LIMIT ' . (($pg - 1) * $per) . ', ' . $per : '');
$list = rows('SELECT s.*, e.employee_no, e.first_name, e.last_name, e.middle_name, e.suffix, b.code AS branch' . $fromSql
    . ($view === 'days' ? ' ORDER BY s.employee_id, s.bio_id, s.log_time' : ' ORDER BY s.status, s.log_time DESC, s.id DESC') . $limit, $params);
$shownFrom = $total ? ($per && $view !== 'days' ? ($pg - 1) * $per + 1 : 1) : 0;
$shownTo = $per && $view !== 'days' ? min($total, $pg * $per) : min($total, count($list));
$listKeep = array_filter(['batch_id' => $batchId ?: null, 'batches' => $batchIds ? implode(',', $batchIds) : null, 'status' => $statusF ?: null, 'q' => $qF !== '' ? $qF : null,
    'view' => $view === 'days' ? 'days' : null, 'per' => $per !== 500 ? $per : null, 'dfrom' => $dFromF ?: null, 'dto' => $dToF ?: null], fn($v) => $v !== null);
$pager = function () use ($pg, $pages, $listKeep, $total, $shownFrom, $shownTo) {
    if ($pages <= 1) {
        return '';
    }
    $u = fn($n) => h(url('import_logs', $listKeep + ['pg' => $n]));
    $h = '<nav class="pager" aria-label="Pages of the staged logs"><a class="btn btn-sm' . ($pg <= 1 ? ' disabled' : '') . '" href="' . $u(max(1, $pg - 1)) . '">&larr; Previous</a>';
    $set = array_unique(array_filter([1, 2, $pg - 1, $pg, $pg + 1, $pages - 1, $pages], fn($n) => $n >= 1 && $n <= $pages));
    sort($set);
    $prev = 0;
    foreach ($set as $n) {
        if ($prev && $n > $prev + 1) {
            $h .= '<span class="pager-gap">…</span>';
        }
        $h .= '<a class="btn btn-sm' . ($n === $pg ? ' is-current' : '') . '" href="' . $u($n) . '"' . ($n === $pg ? ' aria-current="page"' : '') . '>' . $n . '</a>';
        $prev = $n;
    }
    return $h . '<a class="btn btn-sm' . ($pg >= $pages ? ' disabled' : '') . '" href="' . $u(min($pages, $pg + 1)) . '">Next &rarr;</a><span class="muted small">' . number_format($shownFrom) . '–' . number_format($shownTo) . ' of ' . number_format($total) . '</span></nav>';
};
$stats = staging_stats($batchId ?: null);
// v22: the dates of the pending logs of this selection fill Post from / to (only those dates are posted)
$pw = $batchId ? ' AND batch_id = ' . $batchId : ($batchIds ? ' AND batch_id IN (' . implode(',', $batchIds) . ')' : '');
$pr = row("SELECT MIN(DATE(log_time)) AS a, MAX(DATE(log_time)) AS b FROM log_staging WHERE status = 'pending'" . $pw);
$postRange = [$pr['a'] ?? date('Y-m-01'), $pr['b'] ?? today()];
if ($dFromF !== '' && $dToF !== '') {
    $postRange = [$dFromF, $dToF];   // v24: the dates of the list shown (Select logs, Log dates from / to)
}
$batches = rows('SELECT b.*, u.full_name AS user_name, (SELECT COUNT(*) FROM log_staging s WHERE s.batch_id = b.id) AS staged
                 FROM import_batches b LEFT JOIN users u ON u.id = b.uploaded_by
                 WHERE b.batch_type IN ("biometric","device","log_table") ORDER BY b.id DESC LIMIT 20');

$branchOpts = pairs("SELECT id, CONCAT(COALESCE(branch_code, code), ' — ', name) FROM branches WHERE is_active = 1 ORDER BY company_code, branch_code, code");
// v19: the open cut-offs give the dates of the logs to stage (the latest open one by default)
$cutoffs = rows("SELECT c.*, pg.code AS pay_group_code FROM attendance_cutoffs c JOIN pay_groups pg ON pg.id = c.pay_group_id WHERE c.status <> 'locked' ORDER BY c.period_end DESC, pg.code LIMIT 24");
$defCut = pt_default_period(null, true);
page_header('Customized Import Attendance Log', [
    'subtitle' => 'Collect the raw logs, review them, then post them to the attendance records. Posting recomputes the daily time records of the days concerned.',
    'wide' => true,
    'actions' => '<a class="btn" href="' . url('import_config') . '">Config</a><a class="btn" href="' . url('bio_devices') . '">Biometric devices</a>'
        . '<a class="btn" href="' . url('att_logs') . '">Attendance logs</a>',
]);
?>
<div class="stats">
  <?= stat_card('Pending', number_format($stats['pending']), 'ready to post', $stats['pending'] ? 'info' : '') ?>
  <?= stat_card('Errors', number_format($stats['error']), 'unknown biometric ID / bad line', $stats['error'] ? 'bad' : 'good') ?>
  <?= stat_card('Duplicates', number_format($stats['duplicate']), 'already in the logs') ?>
  <?= stat_card('Posted', number_format($stats['posted']), 'moved to the attendance logs', 'good') ?>
</div>

<div class="grid grid-3">
  <div class="card stage-card">
    <h2>From file</h2>
    <p class="muted small">TAB / CSV / fixed-width .dat, .txt or .log exported by the terminals, or an Excel attendance report (.xls, e.g. JJM Logs or NBJ Logs). Choose one, two, three or more files at once, e.g. the logs of the devices of Company A, B and C.</p>
    <form method="post" enctype="multipart/form-data" id="stageForm">
      <?= csrf_field() ?><input type="hidden" name="_action" value="file">
      <div class="field"><label>Import configuration</label><select name="profile_id" required>
        <?php foreach ($profiles as $p): ?><option value="<?= (int)$p['id'] ?>" data-layout="<?= h($p['layout']) ?>" <?= $p['is_default'] ? 'selected' : '' ?>><?= h($p['name']) ?> (<?= h(IMPORT_LAYOUTS[$p['layout']] ?? $p['layout']) ?>)</option><?php endforeach; ?>
      </select></div>
      <div class="field"><label>Company / branch of the device</label><select name="branch_id"><?= options($branchOpts, null, 'From the configuration') ?></select></div>
      <div class="field"><label for="stageCut">Cut-off</label><select id="stageCut">
        <?php foreach ($cutoffs as $c): ?><option value="<?= (int)$c['id'] ?>" data-from="<?= h($c['period_start']) ?>" data-to="<?= h($c['period_end']) ?>"<?= $defCut && (int)$c['id'] === (int)$defCut['id'] ? ' selected' : '' ?>><?= h(pt_period_label($c)) ?></option><?php endforeach; ?>
        <option value="" data-from="" data-to=""<?= $defCut ? '' : ' selected' ?>>Other dates</option>
      </select></div>
      <div class="form-grid stage-dates">
        <div class="field"><label for="stageFrom">Date from</label><input type="date" name="date_from" id="stageFrom" value="<?= h($defCut['period_start'] ?? date('Y-m-01')) ?>" required></div>
        <div class="field"><label for="stageTo">Date to</label><input type="date" name="date_to" id="stageTo" value="<?= h($defCut['period_end'] ?? date('Y-m-t')) ?>" required></div>
      </div>
      <small class="help">Only the logs of these dates are staged; the other lines of the file (an undated backup of the device) are skipped and counted.</small>
      <div class="field"><label for="stageFiles">Log files</label><input type="file" name="logfile[]" id="stageFiles" accept=".dat,.txt,.log,.csv,.tsv,.xls,.xlsx" multiple required></div>
      <div class="stage-files" id="stageList" hidden>
        <div class="stage-files-head"><span>File</span><span>Import configuration</span><span>Company / branch</span></div>
        <div class="stage-files-body"></div>
        <small class="help">Each file is read with its own configuration and company / branch; the punches of one employee in several files make one day.</small>
      </div>
      <div class="form-actions"><button class="btn btn-primary">Upload &amp; stage</button></div>
    </form>
  </div>

  <div class="card">
    <h2>From log table</h2>
    <p class="muted small">Re-stage logs that are already in the HRIS attendance log table (after fixing biometric IDs, for example).</p>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="_action" value="log_table">
      <div class="form-grid">
        <div class="field"><label>From</label><input type="date" name="from" value="<?= h(date('Y-m-01')) ?>" required></div>
        <div class="field"><label>To</label><input type="date" name="to" value="<?= h(date('Y-m-t')) ?>" required></div>
        <div class="field wide"><label>Employee (optional)</label><select name="employee_id"><?= options(employee_options(false), null, 'All employees') ?></select></div>
      </div>
      <div class="form-actions"><button class="btn">Re-upload from log table</button></div>
    </form>
  </div>

  <div class="card">
    <h2>From biometric device</h2>
    <p class="muted small">Downloads the attendance log directly from the terminal (ZKTeco protocol, UDP / TCP).</p>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="_action" value="device">
      <div class="field"><label>Device</label><select name="device_id" required>
        <?php foreach ($devices as $d): ?>
          <option value="<?= (int)$d['id'] ?>"><?= h($d['dev_no'] . ' — ' . $d['name']) ?> (<?= h($d['comm_address']) ?>:<?= h($d['comm_port']) ?>)</option>
        <?php endforeach; ?>
        <?php if (!$devices): ?><option value="">No device configured</option><?php endif; ?>
      </select></div>
      <div class="form-grid stage-dates">
        <div class="field"><label for="devFrom">Date from</label><input type="date" name="date_from" id="devFrom" value="<?= h($defCut['period_start'] ?? date('Y-m-01')) ?>" required></div>
        <div class="field"><label for="devTo">Date to</label><input type="date" name="date_to" id="devTo" value="<?= h(min(today(), $defCut['period_end'] ?? date('Y-m-t'))) ?>" required></div>
      </div>
      <small class="help">Only the logs of these dates are downloaded into the staging area.</small>
      <label class="check"><input type="checkbox" name="clear_after" value="1"> Clear the device log after a successful download</label>
      <small class="help">The device is cleared only when every log on it is inside the dates.</small>
      <div class="form-actions"><button class="btn"<?= $devices ? '' : ' disabled' ?>>Download logs</button>
        <a class="btn" href="<?= url('bio_devices') ?>">Device setup</a></div>
    </form>
  </div>
</div>

<form method="get" class="card filters">
  <input type="hidden" name="p" value="import_logs">
  <label>Import batch<select name="batch_id" data-autosubmit style="min-width:300px">
    <option value="">All batches</option>
    <?php foreach ($batches as $b): ?>
      <option value="<?= (int)$b['id'] ?>" <?= $batchId === (int)$b['id'] ? 'selected' : '' ?>><?= h(mb_strimwidth($b['file_name'], 0, 40, '…')) ?>, <?= h(fmt_dt($b['created_at'])) ?></option>
    <?php endforeach; ?>
  </select></label>
  <label>Status<select name="status" data-autosubmit><?= options(['pending' => 'Pending', 'error' => 'With error', 'duplicate' => 'Duplicate', 'posted' => 'Posted'], $statusF, 'All statuses') ?></select></label>
  <?php if ($batchIds): ?><input type="hidden" name="batches" value="<?= h(implode(',', $batchIds)) ?>"><?php endif; ?>
  <label class="grow">Find<input type="search" name="q" value="<?= h($qF) ?>" placeholder="Employee name, no. or biometric ID" autocomplete="off"></label>
  <label>Log dates from<input type="date" name="dfrom" value="<?= h($dFromF) ?>"></label>
  <label>to<input type="date" name="dto" value="<?= h($dToF) ?>"></label>
  <?php if ($view !== 'days'): ?><label>Show<select name="per" data-autosubmit><?= options($perOpts, $per) ?></select></label><?php endif; ?>
  <?php if ($view === 'days'): ?><input type="hidden" name="view" value="days"><?php endif; ?>
  <button class="btn btn-primary">Show</button>
  <label>View
    <span class="seg">
      <a class="<?= $view === 'list' ? 'active' : '' ?>" href="<?= h(url('import_logs', array_diff_key($listKeep, ['view' => 1]))) ?>">Punch list</a>
      <a class="<?= $view === 'days' ? 'active' : '' ?>" href="<?= h(url('import_logs', array_diff_key($listKeep, ['per' => 1]) + ['view' => 'days'])) ?>">Attendance entry format</a>
    </span>
  </label>
</form>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="batch_id" value="<?= $batchId ?>">
  <div class="toolbar">
    <span class="label">Staged logs</span>
    <span class="muted small"><?= $total ? number_format($shownFrom) . '–' . number_format($shownTo) . ' of ' . number_format($total) . ' log(s)' : 'No log' ?><?= $batchIds ? ', ' . count($batchIds) . ' file(s) of the last upload' : '' ?><?= $view === 'days' && $total > 20000 ? '. The first 20,000 are arranged: narrow the selection with Find or the batch.' : '' ?></span>
    <span class="spacer"></span>
    <span class="post-range">
      <label for="postFrom">Post from</label><input type="date" name="post_from" id="postFrom" value="<?= h($postRange[0]) ?>" required>
      <label for="postTo">to</label><input type="date" name="post_to" id="postTo" value="<?= h($postRange[1]) ?>" required>
    </span>
    <button type="button" class="btn" id="selectLogs" data-paged="<?= $pages > 1 || $view === 'days' ? '1' : '0' ?>"
      data-url="<?= h(url('import_logs', array_diff_key($listKeep, ['dfrom' => 1, 'dto' => 1, 'per' => 1, 'pg' => 1, 'view' => 1]) + ['per' => 0, 'select' => 1])) ?>"
      title="Tick every staged log of Post from / to">Select logs</button>
    <span class="muted small" id="selectedCount" aria-live="polite"></span>
    <button class="btn btn-primary" name="_action" value="post" data-confirm="Post the pending logs of these dates to the attendance records?">Post logs</button>
    <button class="btn" name="_action" value="delete" data-confirm="Delete the ticked staged logs?">Delete</button>
    <button class="btn btn-danger-outline" name="_action" value="delete_all" data-confirm="Delete every staged log of this selection? Posted attendance records are not affected.">Delete all logs</button>
    <a class="btn" href="<?= h(url('import_logs', ['status' => 'error'])) ?>">View log error</a>
  </div>
  <?= $view !== 'days' ? $pager() : '' ?>

  <?php if ($view === 'days'):
      $grid = staging_day_grid($list);
      $maxLpd = 2;
      foreach ($grid as $g) {
          $maxLpd = max($maxLpd, $g['lpd']);
      }
      $slotCols = $maxLpd === 6 ? ['in' => 'Time In', 'bo1' => 'Brk Out 1', 'bi1' => 'Brk In 1', 'bo2' => 'Brk Out 2', 'bi2' => 'Brk In 2', 'out' => 'Time Out']
          : ($maxLpd === 2 ? ['in' => 'Time In', 'out' => 'Time Out'] : ['in' => 'Time In', 'bo1' => 'Brk Out 1', 'bi1' => 'Brk In 1', 'out' => 'Time Out']);
      $slotOf = ['in' => 1, 'out' => 1, 'bo1' => 4, 'bi1' => 4, 'bo2' => 6, 'bi2' => 6];
      $hm = fn($t) => $t ? date('H:i', strtotime($t)) : '';
      ?>
  <div class="card flush">
    <div class="card-head"><div><h2>Staged logs by day</h2><div class="sub">One row per employee and day, in the Attendance Entry layout. The log columns filled depend on the shift code of the employee: 2, 4 or 6 logs per day. Ticking here is not available; post from the punch list.</div></div></div>
    <div class="table-wrap table-scroll">
      <table class="table table-compact grid-days">
        <thead><tr>
          <th>Emp. ID</th><th>Employee</th><th>Date</th>
          <?php foreach ($slotCols as $k => $l): ?><th class="center"><?= h($l) ?></th><?php endforeach; ?>
          <th class="num">Logs</th><th>Shf</th><th>Log status</th><th class="actions"></th>
        </tr></thead>
        <tbody>
        <?php if (!$grid) {
            echo empty_row(count($slotCols) + 7, 'No staged log to arrange for this selection.');
        }
        foreach ($grid as $g): ?>
          <tr class="<?= $g['tone'] === 'bad' ? 'row-bad' : ($g['tone'] === 'warn' ? 'row-warn' : '') ?>">
            <td><?= h($g['bio_id']) ?></td>
            <td><?= $g['emp_id'] ? '<strong>' . h($g['name']) . '</strong><br><small class="muted">' . h($g['employee_no']) . '</small>' : '<span class="text-bad">Unknown employee</span>' ?></td>
            <td class="nowrap"><?= h(date('D, M d', strtotime($g['date']))) ?></td>
            <?php foreach ($slotCols as $k => $l): ?>
              <td class="center mono<?= $slotOf[$k] > $g['lpd'] ? ' cell-na' : '' ?>"><?= $slotOf[$k] > $g['lpd'] ? '' : h($hm($g['slots'][$k])) ?></td>
            <?php endforeach; ?>
            <td class="num"><?= (int)$g['count'] ?></td>
            <td class="small"><?= h($g['shift']) ?> <span class="muted">(<?= (int)$g['lpd'] ?>)</span></td>
            <td class="small"><?= h($g['status']) ?></td>
            <td class="actions"><?php if ($g['emp_id']): ?><a class="btn btn-xs" href="<?= h(url('att_entry', ['emp' => $g['emp_id'], 'from' => $g['date'], 'to' => $g['date']])) ?>">Attendance entry</a><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php else: ?>
  <div class="card flush">
    <div class="table-wrap table-scroll">
      <table class="table table-compact">
        <thead><tr>
          <th style="width:34px"><input type="checkbox" data-check-all="ids[]"></th>
          <th>Date &amp; time</th><th>Emp. ID</th><th>Employee</th><th>Log details</th><th>Co / branch</th><th>Device</th><th>Source</th><th>Status</th><th>Remarks</th><th class="actions"></th>
        </tr></thead>
        <tbody>
        <?php if (!$list) {
            echo empty_row(11, 'The staging area is empty — upload a file, re-upload from the log table or download from a device.');
        }
        foreach ($list as $r): ?>
          <tr class="<?= $r['status'] === 'error' ? 'row-bad' : ($r['status'] === 'duplicate' ? 'row-warn' : '') ?>">
            <td><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" data-date="<?= h(substr((string)$r['log_time'], 0, 10)) ?>"<?= $selectRange && $r['log_time'] && substr((string)$r['log_time'], 0, 10) >= $dFromF && substr((string)$r['log_time'], 0, 10) <= $dToF ? ' checked' : '' ?>></td>
            <td class="nowrap"><?= h($r['log_time'] ? fmt_dt($r['log_time']) : '—') ?></td>
            <td><?= h($r['bio_id']) ?></td>
            <td><?= $r['employee_id'] ? h(full_name($r)) . '<br><small class="muted">' . h($r['employee_no']) . '</small>' : '<span class="badge badge-red">unknown</span>' ?></td>
            <td><span class="logchip <?= $r['punch_type'] === 'in' ? 'in' : ($r['punch_type'] === 'out' ? 'out' : 'brk') ?>"><?= h(label($r['punch_type'])) ?></span></td>
            <td><?= h($r['branch'] ?: ($r['branch_code'] ?: '—')) ?></td>
            <td><?= h($r['device_id'] ?: '—') ?></td>
            <td><?= h(label($r['source'])) ?></td>
            <td><?= badge($r['status']) ?></td>
            <td class="small muted"><?= h($r['remarks'] ?: '') ?></td>
            <td class="actions">
              <?php if (!$r['employee_id']): ?>
                <button type="button" class="btn btn-xs" data-modal-open="assignModal" data-field-id="<?= (int)$r['id'] ?>" data-field-bio="<?= h($r['bio_id']) ?>">Assign</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
  <?= $pager() ?>
</form>

<div class="modal" id="assignModal">
  <div class="modal-box">
    <h2>Assign an employee to biometric ID <span data-text="bio"></span></h2>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="_action" value="assign"><input type="hidden" name="id">
      <div class="field"><label>Employee</label><select name="employee_id" required><?= options(employee_options(false), null, 'Select…') ?></select></div>
      <label class="check"><input type="checkbox" name="remember" value="1" checked> Save this biometric ID on the employee record and fix the other logs of the same ID</label>
      <p class="muted small">An employee who already has a Biometric ID keeps it: this ID is added to the employee's Other biometric IDs (the same person on another terminal or log file).</p>
      <div class="form-actions"><button class="btn btn-primary">Assign</button><button type="button" class="btn" data-modal-close>Cancel</button></div>
    </form>
  </div>
</div>

<div class="card">
  <h2>Recent import batches</h2>
  <div class="table-wrap">
    <table class="table table-compact">
      <thead><tr><th>When</th><th>Source</th><th>File / device</th><th class="num">Rows</th><th class="num">Staged</th><th class="num">Duplicates</th><th class="num">Errors</th><th>By</th><th class="actions"></th></tr></thead>
      <tbody>
      <?php if (!$batches) {
          echo empty_row(9, 'No import yet.');
      }
      foreach ($batches as $b): ?>
        <tr>
          <td class="nowrap"><?= h(fmt_dt($b['created_at'])) ?></td>
          <td><?= h(label($b['batch_type'])) ?></td>
          <td><?= h(mb_strimwidth($b['file_name'], 0, 50, '…')) ?></td>
          <td class="num"><?= (int)$b['total_rows'] ?></td>
          <td class="num"><?= (int)$b['imported'] ?></td>
          <td class="num"><?= (int)$b['duplicates'] ?></td>
          <td class="num"><?= (int)$b['flagged'] ?></td>
          <td><?= h($b['user_name'] ?: '—') ?></td>
          <td class="actions"><a class="btn btn-xs" href="<?= h(url('import_logs', ['batch_id' => $b['id']])) ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<script>
(function () {
  // v24: Select logs ticks every staged log of Post from / to; when the list has more than one page (or is shown by day),
  // the logs of those dates are opened all on one page, already ticked
  var btn = document.getElementById('selectLogs'), cnt = document.getElementById('selectedCount');
  if (!btn) return;
  var boxes = function () { return Array.prototype.slice.call(document.querySelectorAll('input[name="ids[]"][data-date]')); };
  var show = function () {
    var n = boxes().filter(function (b) { return b.checked; }).length;
    cnt.textContent = n ? n.toLocaleString() + ' log(s) selected' : '';
  };
  btn.addEventListener('click', function () {
    var f = (document.getElementById('postFrom') || {}).value, t = (document.getElementById('postTo') || {}).value;
    if (!f || !t || t < f) { alert('Give Post from and to first (the To date on or after the From date).'); return; }
    if (btn.getAttribute('data-paged') === '1') {
      location.href = btn.getAttribute('data-url') + '&dfrom=' + encodeURIComponent(f) + '&dto=' + encodeURIComponent(t);
      return;
    }
    boxes().forEach(function (b) { var d = b.getAttribute('data-date'); b.checked = d >= f && d <= t; });
    show();
  });
  document.addEventListener('change', function (e) { if (e.target.matches && e.target.matches('input[name="ids[]"], [data-check-all]')) setTimeout(show, 0); });
  show();
})();
(function () {
  // v19: the cut-off fills Date from / Date to; each chosen file gets its own configuration and company / branch
  var cut = document.getElementById('stageCut'), from = document.getElementById('stageFrom'), to = document.getElementById('stageTo');
  if (cut) cut.addEventListener('change', function () {
    var o = cut.options[cut.selectedIndex];
    if (o && o.getAttribute('data-from')) { from.value = o.getAttribute('data-from'); to.value = o.getAttribute('data-to'); }
  });
  var files = document.getElementById('stageFiles'), box = document.getElementById('stageList');
  if (!files || !box) return;
  var form = files.form, body = box.querySelector('.stage-files-body');
  var tplProfile = form.querySelector('select[name=profile_id]'), tplBranch = form.querySelector('select[name=branch_id]');
  var size = function (n) { return n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; };
  var guess = function (name) {
    // a file named after the device layout picks that configuration (attlog.dat = ZKTeco, .csv = Generic CSV)
    var n = name.toLowerCase(), pick = null;
    Array.prototype.forEach.call(tplProfile.options, function (o) {
      var t = o.textContent.toLowerCase();
      if (!pick && /attlog|\.dat$/.test(n) && t.indexOf('zkteco') >= 0) pick = o.value;
      if (!pick && /\.csv$/.test(n) && t.indexOf('csv') >= 0) pick = o.value;
      if (!pick && /\.xlsx?$/.test(n) && o.getAttribute('data-layout') === 'report') pick = o.value;
    });
    return pick;
  };
  files.addEventListener('change', function () {
    body.innerHTML = '';
    var list = Array.prototype.slice.call(files.files || []);
    box.hidden = list.length < 2;
    if (list.length === 1 && guess(list[0].name)) tplProfile.value = guess(list[0].name);   // v22: an .xls report picks its configuration
    list.forEach(function (f, i) {
      var row = document.createElement('div');
      row.className = 'stage-file';
      var name = document.createElement('div');
      name.className = 'stage-file-name';
      name.innerHTML = '<strong></strong><small class="muted"></small>';
      name.querySelector('strong').textContent = f.name;
      name.querySelector('small').textContent = size(f.size);
      var p = tplProfile.cloneNode(true), b = tplBranch.cloneNode(true);
      p.name = 'file_profile[' + i + ']'; b.name = 'file_branch[' + i + ']';
      p.removeAttribute('required');
      p.value = guess(f.name) || tplProfile.value; b.value = tplBranch.value;
      p.setAttribute('aria-label', 'Import configuration of ' + f.name); b.setAttribute('aria-label', 'Company / branch of ' + f.name);
      row.appendChild(name); row.appendChild(p); row.appendChild(b);
      body.appendChild(row);
    });
  });
  // the defaults above follow into the rows not changed by hand
  [tplProfile, tplBranch].forEach(function (sel, k) {
    sel.addEventListener('change', function () {
      Array.prototype.forEach.call(body.querySelectorAll('.stage-file'), function (r) { r.querySelectorAll('select')[k].value = sel.value; });
    });
  });
})();
</script>
<?php page_footer();
