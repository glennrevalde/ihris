<?php
/**
 * v36: Undertime Form.
 *
 * The employee applies for undertime from My Workspace (date, the time in / time out of the day following the logs
 * of his shift: 2, 4 or 6 logs, the total hours and the reason / purpose); the immediate superior (or HR) approves it
 * like a leave or an overtime request. The undertime is computed by the Daily Time Record exactly as before: an
 * approved application only TAGS the undertime of its dates as "Approved undertime"; undertime without an approved
 * application is "Unauthorized undertime". Reports > Approved Undertime and Unauthorized Undertime list both.
 *
 * The printed form is fully customizable (Attendance > Undertime Form Design): paper, margins, font, logos,
 * header (company / branch name, address, contact no.), series no., title, employee fields, table headings and
 * widths, rows, signatories (label, caption, name, position by row / column, format) and footer.
 * The helpers of the Pass Slip Form (logos, {tokens}, employee fields, signature names) are shared.
 */

/* ============================================================================ layout */

/** The layout of the sample form (JJM Undertime Form). */
function ut_default_layout(): array
{
    return [
        'paper' => 'half', 'orientation' => 'landscape',
        'margin_top' => 8, 'margin_right' => 10, 'margin_bottom' => 8, 'margin_left' => 10,
        'font' => 'Arial', 'font_size' => 9,
        'logo_left' => ['source' => 'company', 'file' => '', 'height' => 16],
        'logo_right' => ['source' => 'none', 'file' => '', 'height' => 16],
        'header_align' => 'left', 'header_rule' => 0,
        'header_lines' => [
            ['text' => '{company_name}', 'size' => 10, 'bold' => 1, 'italic' => 0],
            ['text' => '{company_address}', 'size' => 8, 'bold' => 0, 'italic' => 0],
            ['text' => 'Contact No.: (000) 000-0000 | 0900 000 0000', 'size' => 8, 'bold' => 0, 'italic' => 0],
        ],
        'title' => 'UNDERTIME FORM', 'title_size' => 13, 'title_align' => 'left',
        'form_no' => 'HRUF-{no}', 'form_no_size' => 8, 'form_no_position' => 'title_row', 'seq_digits' => 4,
        'info_fields' => [
            ['label' => 'Employee:', 'source' => 'employee_name', 'side' => 'left'],
            ['label' => 'Position:', 'source' => 'position', 'side' => 'left'],
            ['label' => 'Department:', 'source' => 'department', 'side' => 'left'],
            ['label' => 'Date Filed:', 'source' => 'date_filed', 'side' => 'right'],
            ['label' => 'I.D No.:', 'source' => 'employee_no', 'side' => 'right'],
        ],
        'col_date' => 'DATE', 'col_time' => 'TIME', 'col_in' => 'IN', 'col_out' => 'OUT', 'col_total' => 'TOTAL HOURS', 'col_reason' => 'REASON/PURPOSE',
        'w_date' => 19, 'w_time' => 25, 'w_total' => 25, 'w_reason' => 31,
        'show_total' => 1, 'show_reason' => 1,
        'log_pairs' => 'auto', 'max_dates' => 4, 'blank_rows' => 4, 'row_height' => 7.5,
        'date_format' => 'm/d/Y', 'time_format' => 'h:i A', 'total_format' => 'hm',
        'signatories' => [
            ['label' => 'Requested by:', 'caption' => "Employee's Signature", 'name' => 'employee', 'text' => '', 'row' => 1, 'col' => 1, 'note' => 0, 'bold' => 0],
            ['label' => 'Approved by:', 'caption' => 'Supervisor', 'name' => 'approver', 'text' => '', 'row' => 1, 'col' => 2, 'note' => 1, 'bold' => 0],
            ['label' => '', 'caption' => 'Manager', 'name' => 'blank', 'text' => '', 'row' => 2, 'col' => 2, 'note' => 1, 'bold' => 0],
            ['label' => 'For Managers:', 'caption' => 'CEO', 'name' => 'blank', 'text' => '', 'row' => 3, 'col' => 1, 'note' => 0, 'bold' => 1],
            ['label' => '', 'caption' => 'CFO', 'name' => 'blank', 'text' => '', 'row' => 3, 'col' => 2, 'note' => 0, 'bold' => 1],
            ['label' => 'Received by:', 'caption' => 'HR Dept.', 'name' => 'blank', 'text' => '', 'row' => 3, 'col' => 3, 'note' => 0, 'bold' => 1],
        ],
        'sign_cols' => 3, 'sign_size' => 7.5, 'sign_label_bold' => 1, 'sign_gap' => 5, 'note_label' => 'Note:',
        'footer_note' => 'Note:', 'footer' => '',
    ];
}

/** The saved layout of the Undertime Form, completed with the defaults. */
function ut_layout(bool $reload = false): array
{
    static $l = null;
    if ($l !== null && !$reload) {
        return $l;
    }
    $saved = null;
    try {
        $saved = val("SELECT layout FROM form_layouts WHERE code = 'undertime'");
    } catch (Throwable $e) {
        $saved = null;
    }
    $d = ut_default_layout();
    $s = $saved ? json_decode((string)$saved, true) : null;
    return $l = ut_clean_layout(is_array($s) ? array_merge($d, $s) : $d);
}

function ut_layout_save(array $layout): void
{
    $json = json_encode(ut_clean_layout($layout), JSON_UNESCAPED_UNICODE);
    $u = current_user();
    if (val("SELECT COUNT(*) FROM form_layouts WHERE code = 'undertime'")) {
        update('form_layouts', ['layout' => $json, 'updated_by' => $u['id'] ?? null, 'updated_at' => now()], 'code = ?', ['undertime']);
    } else {
        insert('form_layouts', ['code' => 'undertime', 'name' => 'Undertime Form', 'layout' => $json, 'updated_by' => $u['id'] ?? null, 'updated_at' => now()]);
    }
    ut_layout(true);
}

/** Keep every value inside its allowed range. */
function ut_clean_layout(array $l): array
{
    $d = ut_default_layout();
    $num = fn($v, float $min, float $max, float $def): float => is_numeric($v) ? max($min, min($max, (float)$v)) : $def;
    $txt = fn($v, int $len = 300) => mb_substr(trim((string)$v), 0, $len);
    $pick = fn($v, array $allowed, $def) => in_array((string)$v, $allowed, true) ? (string)$v : $def;
    $o = [];
    $o['paper'] = $pick($l['paper'] ?? '', array_keys(PASS_SLIP_PAPERS), 'half');
    $o['orientation'] = $pick($l['orientation'] ?? '', ['portrait', 'landscape'], 'landscape');
    foreach (['margin_top', 'margin_right', 'margin_bottom', 'margin_left'] as $k) {
        $o[$k] = $num($l[$k] ?? null, 0, 60, $d[$k]);
    }
    $o['font'] = isset(PASS_SLIP_FONTS[$l['font'] ?? '']) ? $l['font'] : 'Arial';
    $o['font_size'] = $num($l['font_size'] ?? null, 6, 16, 9);
    foreach (['logo_left', 'logo_right'] as $k) {
        $g = is_array($l[$k] ?? null) ? $l[$k] : $d[$k];
        $o[$k] = ['source' => $pick($g['source'] ?? '', ['company', 'upload', 'none'], 'none'),
            'file' => preg_match('#^forms/[A-Za-z0-9_.-]+$#', (string)($g['file'] ?? '')) ? (string)$g['file'] : '',
            'height' => $num($g['height'] ?? null, 5, 60, 16)];
    }
    $o['header_align'] = $pick($l['header_align'] ?? '', ['left', 'center', 'right'], 'left');
    $o['header_rule'] = !empty($l['header_rule']) ? 1 : 0;
    $o['header_lines'] = [];
    foreach ((array)($l['header_lines'] ?? []) as $h) {
        if (is_array($h) && trim((string)($h['text'] ?? '')) !== '') {
            $o['header_lines'][] = ['text' => $txt($h['text']), 'size' => $num($h['size'] ?? null, 6, 28, 9), 'bold' => !empty($h['bold']) ? 1 : 0, 'italic' => !empty($h['italic']) ? 1 : 0];
        }
    }
    $o['title'] = $txt($l['title'] ?? '', 150);
    $o['title_size'] = $num($l['title_size'] ?? null, 8, 28, 13);
    $o['title_align'] = $pick($l['title_align'] ?? '', ['left', 'center', 'right'], 'left');
    $o['form_no'] = $txt($l['form_no'] ?? '', 80);
    $o['form_no_size'] = $num($l['form_no_size'] ?? null, 6, 20, 8);
    $o['form_no_position'] = $pick($l['form_no_position'] ?? '', ['title_row', 'above', 'below'], 'title_row');
    $o['seq_digits'] = (int)$num($l['seq_digits'] ?? null, 1, 8, 4);
    $o['info_fields'] = [];
    foreach ((array)($l['info_fields'] ?? []) as $f) {
        if (!is_array($f) || (trim((string)($f['label'] ?? '')) === '' && ($f['source'] ?? '') === 'blank')) {
            continue;
        }
        $o['info_fields'][] = ['label' => $txt($f['label'] ?? '', 60), 'source' => $pick($f['source'] ?? '', array_keys(PASS_SLIP_INFO_SOURCES), 'blank'),
            'side' => $pick($f['side'] ?? '', ['left', 'right', 'full'], 'left')];
    }
    foreach (['col_date', 'col_time', 'col_in', 'col_out', 'col_total', 'col_reason'] as $k) {
        $o[$k] = $txt($l[$k] ?? $d[$k], 40);
    }
    foreach (['w_date', 'w_time', 'w_total', 'w_reason'] as $k) {
        $o[$k] = $num($l[$k] ?? null, 5, 80, $d[$k]);
    }
    $o['show_total'] = !empty($l['show_total']) ? 1 : 0;
    $o['show_reason'] = !empty($l['show_reason']) ? 1 : 0;
    $o['log_pairs'] = $pick($l['log_pairs'] ?? '', ['auto', '1', '2', '3'], 'auto');
    $o['max_dates'] = (int)$num($l['max_dates'] ?? null, 1, 31, 4);
    $o['blank_rows'] = (int)$num($l['blank_rows'] ?? null, 0, 31, 4);
    $o['row_height'] = $num($l['row_height'] ?? null, 5, 25, 7.5);
    $o['date_format'] = in_array($l['date_format'] ?? '', ['m/d/Y', 'M d, Y', 'M d, Y (D)', 'Y-m-d', 'd M Y'], true) ? $l['date_format'] : 'm/d/Y';
    $o['time_format'] = in_array($l['time_format'] ?? '', ['h:i A', 'H:i', 'g:i a'], true) ? $l['time_format'] : 'h:i A';
    $o['total_format'] = $pick($l['total_format'] ?? '', ['hm', 'dec', 'words'], 'hm');
    $o['signatories'] = [];
    foreach ((array)($l['signatories'] ?? []) as $s) {
        if (!is_array($s) || (trim((string)($s['label'] ?? '')) === '' && trim((string)($s['caption'] ?? '')) === '')) {
            continue;
        }
        $o['signatories'][] = ['label' => $txt($s['label'] ?? '', 40), 'caption' => $txt($s['caption'] ?? '', 60),
            'name' => $pick($s['name'] ?? '', array_keys(PASS_SLIP_SIGN_SOURCES), 'blank'), 'text' => $txt($s['text'] ?? '', 80),
            'row' => (int)$num($s['row'] ?? null, 1, 9, 1), 'col' => (int)$num($s['col'] ?? null, 1, 4, 1),
            'note' => !empty($s['note']) ? 1 : 0, 'bold' => !empty($s['bold']) ? 1 : 0];
    }
    $o['sign_cols'] = (int)$num($l['sign_cols'] ?? null, 1, 4, 3);
    $o['sign_size'] = $num($l['sign_size'] ?? null, 6, 14, 7.5);
    $o['sign_label_bold'] = !empty($l['sign_label_bold']) ? 1 : 0;
    $o['sign_gap'] = $num($l['sign_gap'] ?? null, 0, 30, 5);
    $o['note_label'] = $txt($l['note_label'] ?? 'Note:', 30);
    $o['footer_note'] = $txt($l['footer_note'] ?? '', 40);
    $o['footer'] = $txt($l['footer'] ?? '', 500);
    return $o;
}

/* ============================================================================ schedule, logs, undertime */

/** Number of IN / OUT pairs of the employee on a date: the logs of his shift (2, 4 or 6) / 2. */
function ut_pairs_on(int $empId, string $date): int
{
    $emp = att_employee($empId);
    if (!$emp) {
        return 1;
    }
    $sched = employee_schedule($emp, $date);
    return max(1, min(3, intdiv(shift_logs_per_day($sched['shift'] ?? null), 2)));
}

/** Pairs printed / entered: the layout's fixed number, or the most pairs of the dates (auto). */
function ut_pairs(array $layout, int $empId = 0, array $dates = []): int
{
    if ($layout['log_pairs'] !== 'auto') {
        return (int)$layout['log_pairs'];
    }
    if (!$empId) {
        return 1;
    }
    $n = 1;
    foreach ($dates ?: [today()] as $d) {
        $n = max($n, ut_pairs_on($empId, $d));
    }
    return $n;
}

/**
 * What the system knows of a day: the pairs of the shift, the scheduled times, the logs of the Daily Time Record
 * placed as IN / OUT pairs, and the undertime computed by the DTR.
 */
function ut_day_info(int $empId, string $date): array
{
    $emp = att_employee($empId);
    $pairs = ut_pairs_on($empId, $date);
    $out = ['pairs' => $pairs, 'shift' => '', 'sched' => [], 'logs' => array_fill(0, $pairs * 2, ''), 'ut' => null, 'rest' => false];
    if (!$emp) {
        return $out;
    }
    $sched = employee_schedule($emp, $date);
    $out['rest'] = (bool)$sched['is_rest'];
    if (!empty($sched['shift'])) {
        $out['shift'] = shift_coverage($sched['shift']);
    }
    // the scheduled IN / OUT of each pair
    if (!empty($sched['sched_in']) && !empty($sched['sched_out'])) {
        $marks = [strtotime($sched['sched_in'])];
        foreach (array_slice($sched['breaks'], 0, $pairs - 1) as $b) {
            $marks[] = $b[0];
            $marks[] = $b[1];
        }
        $marks[] = strtotime($sched['sched_out']);
        $out['sched'] = array_map(fn($t) => date('H:i', $t), $marks);
    }
    $d = row('SELECT * FROM dtr WHERE employee_id = ? AND work_date = ?', [$empId, $date]);
    if ($d) {
        $cols = $pairs === 1 ? ['time_in', 'time_out'] : ($pairs === 2 ? ['time_in', 'break_out', 'break_in', 'time_out']
            : ['time_in', 'break_out', 'break_in', 'break_out2', 'break_in2', 'time_out']);
        foreach ($cols as $i => $c) {
            $out['logs'][$i] = !empty($d[$c]) ? date('H:i', strtotime($d[$c])) : '';
        }
        if (!empty($d['time_out'])) {
            $out['ut'] = (int)$d['undertime_minutes'];
        }
    }
    return $out;
}

/**
 * Undertime minutes of an entered day: the Daily Time Record's undertime once the day has its time out (the
 * computation is never changed), else an estimate from the times entered: each OUT earlier than its scheduled OUT.
 */
function ut_minutes_for(int $empId, string $date, array $times): int
{
    $info = ut_day_info($empId, $date);
    if ($info['ut'] !== null) {
        return (int)$info['ut'];
    }
    if (!$info['sched']) {
        return 0;
    }
    $sIn = strtotime($date . ' ' . $info['sched'][0]);
    $abs = function (string $hm) use ($date, $sIn) {
        $t = strtotime($date . ' ' . $hm);
        return $t < $sIn - 6 * 3600 ? $t + 86400 : $t;   // a night shift's out after midnight
    };
    $m = 0;
    $n = count($info['sched']);
    if ($n === $info['pairs'] * 2) {
        // fixed breaks: each OUT against its scheduled OUT
        for ($i = 1; $i < $n; $i += 2) {
            $o = $times[$i] ?? '';
            if ($o !== '') {
                $m += max(0, (int)round(($abs($info['sched'][$i]) - $abs($o)) / 60));
            }
        }
        return $m;
    }
    // breaks without fixed times: the last OUT entered against the scheduled time out
    $last = '';
    for ($i = 1; $i < count($times); $i += 2) {
        if (($times[$i] ?? '') !== '') {
            $last = $times[$i];
        }
    }
    return $last !== '' ? max(0, (int)round(($abs($info['sched'][$n - 1]) - $abs($last)) / 60)) : 0;
}

/** Undertime minutes as printed: 1:30, 1.50 or 1 hr 30 min. */
function ut_fmt_total(int $m, string $fmt = 'hm'): string
{
    if ($m <= 0) {
        return '';
    }
    if ($fmt === 'dec') {
        return number_format($m / 60, 2);
    }
    if ($fmt === 'words') {
        $h = intdiv($m, 60);
        $r = $m % 60;
        return trim(($h ? $h . ' hr' . ($h > 1 ? 's' : '') : '') . ($r ? ' ' . $r . ' min' : ''));
    }
    return sprintf('%d:%02d', intdiv($m, 60), $m % 60);
}

/* ============================================================================ applications */

function ut_next_no(?string $date = null): string
{
    $digits = ut_layout()['seq_digits'];
    $yy = substr($date ?: today(), 2, 2);
    $n = (int)val('SELECT COUNT(*) FROM undertime_apps WHERE ut_no LIKE ?', [$yy . '%']) + 1;
    do {
        $no = $yy . str_pad((string)$n++, $digits, '0', STR_PAD_LEFT);
    } while ((int)val('SELECT COUNT(*) FROM undertime_apps WHERE ut_no = ?', [$no]));
    return $no;
}

/** One application with the employee's fields (ut_no is also given as slip_no for the shared form helpers). */
function ut_row(int $id): ?array
{
    return row('SELECT ua.*, ua.ut_no AS slip_no, e.first_name, e.last_name, e.middle_name, e.suffix, e.position, e.employee_no, e.biometric_id, e.supervisor_id,
                       e.default_branch_id, e.pay_group_id, e.org_unit_id, ou.name AS department, b.name AS branch_name, b.company_name, b.address AS company_address,
                       eg.name AS employee_group, u.full_name AS approver_user, u.employee_id AS approver_employee_id
                FROM undertime_apps ua JOIN employees e ON e.id = ua.employee_id
                LEFT JOIN org_units ou ON ou.id = e.org_unit_id LEFT JOIN branches b ON b.id = e.default_branch_id
                LEFT JOIN emp_groups eg ON eg.id = e.employee_group_id LEFT JOIN users u ON u.id = ua.approved_by
                WHERE ua.id = ?', [$id]);
}

function ut_entries(?array $a): array
{
    $e = $a ? json_decode((string)($a['entries'] ?? ''), true) : null;
    return is_array($e) ? array_values(array_filter($e, fn($x) => is_array($x) && !empty($x['d']))) : [];
}

/**
 * Read the rows posted by the application form (ut_date[i], ut_time[i][k], ut_reason[i]). Returns [entries, errors].
 */
function ut_entries_from_post(array $layout, int $empId): array
{
    $dates = (array)post('ut_date', []);
    $times = (array)post('ut_time', []);
    $reasons = (array)post('ut_reason', []);
    $emp = att_employee($empId);
    $out = [];
    $err = [];
    $seen = [];
    foreach ($dates as $i => $d) {
        $d = trim((string)$d);
        $t = [];
        $any = false;
        foreach ((array)($times[$i] ?? []) as $k => $v) {
            $v = trim((string)$v);
            if ($v !== '' && !preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $v)) {
                $err[] = 'Enter valid times (hh:mm).';
                $v = '';
            }
            if ($v !== '') {
                $v = sprintf('%02d:%s', (int)substr($v, 0, strpos($v, ':')), substr($v, -2));
                $any = true;
            }
            $t[(int)$k] = $v;
        }
        ksort($t);
        $r = mb_substr(trim((string)($reasons[$i] ?? '')), 0, 200);
        if ($d === '' && !$any && $r === '') {
            continue;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !strtotime($d)) {
            $err[] = 'Enter the date of every row.';
            continue;
        }
        if (isset($seen[$d])) {
            $err[] = fmt_date($d) . ' is entered twice.';
            continue;
        }
        if (!$any) {
            $err[] = 'Enter the time out (or the time in) of ' . fmt_date($d) . '.';
            continue;
        }
        if ($r === '') {
            $err[] = 'Enter the reason / purpose of ' . fmt_date($d) . '.';
            continue;
        }
        if ($emp && dtr_locked($emp, $d)) {
            $err[] = 'The attendance of ' . fmt_date($d) . ' is already closed.';
            continue;
        }
        $seen[$d] = true;
        $out[] = ['d' => $d, 't' => array_values($t), 'm' => ut_minutes_for($empId, $d, array_values($t)), 'r' => $r];
    }
    if (!$out && !$err) {
        $err[] = 'Enter the date, the time and the reason of the undertime.';
    }
    if (count($out) > $layout['max_dates']) {
        $err[] = 'Up to ' . $layout['max_dates'] . ' date(s) per undertime form.';
    }
    usort($out, fn($a, $b) => strcmp($a['d'], $b['d']));
    return [$out, $err];
}

/** Can the signed-in user approve / reject this application? (as the Pass Slips: HR for everyone, else the team) */
function ut_can_act(array $a): bool
{
    if ($a['status'] !== 'pending') {
        return false;
    }
    if (is_super_user()) {
        return true;
    }
    if ((int)$a['employee_id'] === (int)my_employee_id() || !can_any(['undertime_apps.approve', 'approvals.approve'])) {
        return false;
    }
    return pass_slip_company_wide() || in_array((int)$a['employee_id'], my_team_ids(), true);
}

function ut_can_view(array $a): bool
{
    return (int)$a['employee_id'] === (int)my_employee_id() || is_super_user() || can('undertime_apps.view') || is_manager_of((int)$a['employee_id']);
}

/** Approve or reject a pending application. The undertime of its dates is tagged; nothing is recomputed. */
function ut_decide(int $id, string $decision, ?string $remarks = null): array
{
    $a = ut_row($id);
    if (!$a || $a['status'] !== 'pending') {
        return [false, 'The undertime form is no longer pending.'];
    }
    if (!ut_can_act($a)) {
        return [false, 'You cannot act on undertime form ' . $a['ut_no'] . '.'];
    }
    $approve = $decision === 'approve';
    $remarks = trim((string)$remarks) === '' ? null : mb_substr((string)$remarks, 0, 255);
    // the latest undertime of the days (the DTR may have been processed since the form was filed)
    $entries = ut_entries($a);
    $total = 0;
    foreach ($entries as $k => $en) {
        $entries[$k]['m'] = ut_minutes_for((int)$a['employee_id'], $en['d'], $en['t']);
        $total += $entries[$k]['m'];
    }
    update('undertime_apps', ['status' => $approve ? 'approved' : 'rejected', 'approved_by' => user_id(), 'approved_at' => now(), 'remarks' => $remarks,
        'entries' => json_encode($entries), 'total_minutes' => $total], 'id = ?', [$id]);
    audit('ams', 'undertime_' . ($approve ? 'approve' : 'reject'), 'undertime_apps', $id, ['remarks' => $remarks]);
    ut_notify_decided(ut_row($id));
    return [true, 'Undertime form ' . $a['ut_no'] . ($approve ? ' approved: the undertime of its date(s) is tagged as Approved undertime' : ' rejected') . '.'];
}

/** Email the approver(s) of a new application; returns who was emailed (or why not), like the Pass Slip. */
function ut_notify_filed(array $a): string
{
    $to = pass_slip_recipients($a, 'undertime_apps.approve');
    if (!$to) {
        return 'No email was sent: neither your immediate superior, your department head nor HR has an email address in the system. Please inform your superior.';
    }
    $body = '<p>' . h(full_name($a, false)) . ' filed undertime form <strong>' . h($a['ut_no']) . '</strong> for approval.</p>'
        . ut_entries_html($a) . email_button(abs_url('approvals'), 'Review in ' . app_name());
    $sent = [];
    $failed = [];
    foreach ($to as [$mail, $name, $who]) {
        $ok = send_mail($mail, $name ?: null, 'Undertime form ' . $a['ut_no'] . ': ' . full_name($a, false), email_template('Undertime form for approval', $body), 'undertime', (int)$a['id']);
        $label = ($name !== '' ? $name : $mail) . ($who !== 'supervisor' ? ' (' . $who . ')' : '');
        if ($ok) {
            $sent[] = $label;
        } else {
            $failed[] = $label;
        }
    }
    $msg = $sent ? 'Emailed to ' . implode(', ', $sent) . '.' : '';
    if ($failed) {
        $msg .= ' The email to ' . implode(', ', $failed) . ' could not be sent (see Email Outbox).';
    }
    if (setting('mail_mode', 'log') === 'log') {
        $msg .= ' Note: email delivery is set to "log only" in System Settings, so the email was recorded but not sent.';
    }
    return trim($msg);
}

function ut_notify_decided(?array $a): void
{
    if (!$a || !($mail = employee_email((int)$a['employee_id']))) {
        return;
    }
    $ok = $a['status'] === 'approved';
    $body = '<p>Your undertime form <strong>' . h($a['ut_no']) . '</strong> was <strong>' . ($ok ? 'approved' : 'rejected') . '</strong>'
        . ($a['approver_user'] ? ' by ' . h($a['approver_user']) : '') . '.</p>' . ut_entries_html($a)
        . ($a['remarks'] ? '<p>Remarks: ' . h($a['remarks']) . '</p>' : '') . email_button(abs_url('my_undertime'), 'Open My Undertime');
    send_mail($mail, null, 'Undertime form ' . $a['ut_no'] . ' ' . ($ok ? 'approved' : 'rejected'), email_template('Undertime form ' . ($ok ? 'approved' : 'rejected'), $body), 'undertime', (int)$a['id']);
}

/** The dates, times, hours and reasons of an application in a short list (screens and emails). */
function ut_entries_html(array $a, bool $compact = false): string
{
    $out = [];
    foreach (ut_entries($a) as $en) {
        $times = [];
        foreach ($en['t'] as $i => $tm) {
            if ($tm !== '') {
                $times[] = ($i % 2 ? 'out ' : 'in ') . date('h:i A', strtotime('2000-01-01 ' . $tm));
            }
        }
        $out[] = '<strong>' . h(fmt_date($en['d'], 'D M d, Y')) . '</strong>: ' . h(implode(', ', $times))
            . ((int)($en['m'] ?? 0) > 0 ? ', ' . h(ut_fmt_total((int)$en['m'], 'words')) : '') . ($compact ? '' : '. ' . h($en['r'] ?? ''));
    }
    return $compact ? implode('<br>', $out) : '<p>' . implode('<br>', $out) . '</p>';
}

/**
 * Approved undertime applications of employees over a date range: [employee id][date] => [ut_no, id, approver].
 * Used to tag the undertime of the Daily Time Record (Approved / Unauthorized) and by the reports.
 */
function ut_approved_map(array $empIds, string $from, string $to, string $status = 'approved'): array
{
    $empIds = array_values(array_filter(array_map('intval', $empIds)));
    if (!$empIds) {
        return [];
    }
    try {
        $apps = rows("SELECT ua.id, ua.ut_no, ua.employee_id, ua.entries, ua.status, u.full_name AS approver FROM undertime_apps ua LEFT JOIN users u ON u.id = ua.approved_by
                      WHERE ua.status = ? AND ua.first_date <= ? AND ua.last_date >= ? AND " . sql_in('ua.employee_id', $empIds), [$status, $to, $from]);
    } catch (Throwable $e) {
        return [];   // before the upgrade
    }
    $map = [];
    foreach ($apps as $a) {
        foreach (ut_entries($a) as $en) {
            if ($en['d'] >= $from && $en['d'] <= $to) {
                $map[(int)$a['employee_id']][$en['d']] = ['id' => (int)$a['id'], 'no' => $a['ut_no'], 'approver' => (string)$a['approver'], 'reason' => (string)($en['r'] ?? '')];
            }
        }
    }
    return $map;
}

/** The tag of a day's undertime, as plain text for the DTR screens: '' when no undertime. */
function ut_tag_html(int $minutes, ?array $approved): string
{
    if ($minutes <= 0) {
        return '';
    }
    return $approved
        ? '<small class="d-block ut-tag ut-ok" title="Undertime form ' . h($approved['no']) . '">approved</small>'
        : '<small class="d-block ut-tag ut-bad" title="No approved undertime form">unauthorized</small>';
}

/* ============================================================================ printed form */

/**
 * The printed Undertime Form. $a = an application (ut_row), an employee (pass_slip_employee) for a blank form,
 * or null.
 */
function ut_html(array $l, ?array $a = null): string
{
    [$pw, $ph] = pass_slip_paper_mm($l);
    $entries = ut_entries($a);
    $empId = (int)($a['employee_id'] ?? 0);
    $pairs = ut_pairs($l, $empId, array_column($entries, 'd'));
    foreach ($entries as $en) {
        $pairs = max($pairs, (int)ceil(count($en['t']) / 2));
    }
    $pairs = max(1, min(3, $pairs));
    $rows = max(count($entries), $l['blank_rows'], $entries ? 0 : 1);
    $font = "'" . str_replace("'", '', $l['font']) . "', Arial, sans-serif";
    $rh = (float)$l['row_height'];
    // column widths (percent of the table): date, time block, total, reason
    $wd = $l['w_date'];
    $wt = $l['w_time'] * $pairs;   // the width of one IN / OUT pair, times the pairs
    $wtot = $l['show_total'] ? $l['w_total'] : 0;
    $wr = $l['show_reason'] ? $l['w_reason'] : 0;
    $sum = max(1, $wd + $wt + $wtot + $wr);
    $pc = fn($w) => round($w * 100 / $sum, 3);
    $no = $l['form_no'] !== '' ? '<div class="ut-formno" style="font-size:' . (float)$l['form_no_size'] . 'pt">' . h(pass_slip_tokens($l['form_no'], $a)) . '</div>' : '';
    $title = $l['title'] !== '' ? '<div class="ut-title" style="text-align:' . h($l['title_align']) . ';font-size:' . (float)$l['title_size'] . 'pt">' . h($l['title']) . '</div>' : '';
    ob_start(); ?>
<div class="ps-sheet ut-sheet" style="width:<?= $pw ?>mm;--ps-h:<?= $ph ?>mm;padding:<?= (float)$l['margin_top'] ?>mm <?= (float)$l['margin_right'] ?>mm <?= (float)$l['margin_bottom'] ?>mm <?= (float)$l['margin_left'] ?>mm;font-family:<?= h($font) ?>;font-size:<?= (float)$l['font_size'] ?>pt">
  <?php $ll = pass_slip_logo_html($l['logo_left'], $a, 'left'); $lr = pass_slip_logo_html($l['logo_right'], $a, 'right'); ?>
  <?php if ($ll || $lr || $l['header_lines']): ?>
  <div class="ps-head ut-head<?= $l['header_rule'] ? ' ps-rule' : '' ?>">
    <div class="ps-logo-cell"><?= $ll ?></div>
    <div class="ps-head-text" style="text-align:<?= h($l['header_align']) ?>">
      <?php foreach ($l['header_lines'] as $hl): ?><div style="font-size:<?= (float)$hl['size'] ?>pt;font-weight:<?= $hl['bold'] ? 700 : 400 ?>;font-style:<?= $hl['italic'] ? 'italic' : 'normal' ?>"><?= h(pass_slip_tokens($hl['text'], $a)) ?></div><?php endforeach; ?>
      <?php if ($l['form_no_position'] === 'title_row'): ?><div class="ut-title-row"><?= $title ?><?= $no ?></div><?php endif; ?>
    </div>
    <div class="ps-logo-cell ps-right"><?= $lr ?></div>
  </div>
  <?php endif; ?>
  <?php if ($l['form_no_position'] === 'above'): ?><div class="ut-no-row"><?= $no ?></div><?= $title ?><?php elseif ($l['form_no_position'] === 'below'): ?><?= $title ?><div class="ut-no-row"><?= $no ?></div><?php elseif (!($ll || $lr || $l['header_lines'])): ?><div class="ut-title-row"><?= $title ?><?= $no ?></div><?php endif; ?>
  <?php
    $sides = ['left' => [], 'right' => [], 'full' => []];
    foreach ($l['info_fields'] as $f) {
        $sides[$f['side']][] = $f;
    }
    $field = fn($f) => '<div class="ps-field"><span class="ps-flabel">' . h($f['label']) . '</span><span class="ps-fval">' . h(pass_slip_field_value($f['source'], $a)) . '</span></div>';
  ?>
  <?php if ($sides['left'] || $sides['right']): ?><div class="ps-info ut-info"><div><?= implode('', array_map($field, $sides['left'])) ?></div><div><?= implode('', array_map($field, $sides['right'])) ?></div></div><?php endif; ?>
  <?php if ($sides['full']): ?><div class="ps-info ps-info-full ut-info"><div><?= implode('', array_map($field, $sides['full'])) ?></div></div><?php endif; ?>
  <table class="ps-grid ut-grid">
    <colgroup><col style="width:<?= $pc($wd) ?>%"><?php for ($i = 0; $i < $pairs * 2; $i++): ?><col style="width:<?= round($pc($wt) / ($pairs * 2), 3) ?>%"><?php endfor; ?><?php if ($wtot): ?><col style="width:<?= $pc($wtot) ?>%"><?php endif; ?><?php if ($wr): ?><col style="width:<?= $pc($wr) ?>%"><?php endif; ?></colgroup>
    <thead>
      <tr style="height:<?= $rh ?>mm"><th rowspan="2" class="ut-top"><?= h($l['col_date']) ?></th><th colspan="<?= $pairs * 2 ?>" class="ut-top"><?= h($l['col_time']) ?></th><?php if ($wtot): ?><th rowspan="2" class="ut-top"><?= h($l['col_total']) ?></th><?php endif; ?><?php if ($wr): ?><th rowspan="2" class="ut-top"><?= h($l['col_reason']) ?></th><?php endif; ?></tr>
      <tr style="height:<?= $rh ?>mm"><?php for ($i = 0; $i < $pairs; $i++): ?><th class="ut-sub"><?= h($l['col_in']) ?></th><th class="ut-sub"><?= h($l['col_out']) ?></th><?php endfor; ?></tr>
    </thead>
    <tbody>
    <?php for ($r = 0; $r < $rows; $r++): $en = $entries[$r] ?? null; ?>
      <tr style="height:<?= $rh ?>mm">
        <td class="center"><?= $en ? h(fmt_date($en['d'], $l['date_format'])) : '' ?></td>
        <?php for ($i = 0; $i < $pairs * 2; $i++): $tm = $en['t'][$i] ?? ''; ?><td class="center ut-time"><?= $tm !== '' ? h(date($l['time_format'], strtotime('2000-01-01 ' . $tm))) : '' ?></td><?php endfor; ?>
        <?php if ($wtot): ?><td class="center"><?= $en ? h(ut_fmt_total((int)($en['m'] ?? 0), $l['total_format'])) : '' ?></td><?php endif; ?>
        <?php if ($wr): ?><td class="ut-reason"><?= $en ? h($en['r'] ?? '') : '' ?></td><?php endif; ?>
      </tr>
    <?php endfor; ?>
    </tbody>
  </table>
  <?php
    $srows = [];
    foreach ($l['signatories'] as $sg) {
        $srows[$sg['row']][$sg['col']][] = $sg;
    }
    ksort($srows);
  ?>
  <div class="ps-signs ut-signs" style="font-size:<?= (float)$l['sign_size'] ?>pt">
  <?php foreach ($srows as $cells): ?>
    <div class="ps-sign-row" style="grid-template-columns:repeat(<?= $l['sign_cols'] ?>, 1fr);margin-bottom:<?= (float)$l['sign_gap'] ?>mm">
      <?php for ($c = 1; $c <= $l['sign_cols']; $c++): ?>
      <div class="ps-sign-cell">
        <?php foreach ($cells[$c] ?? [] as $sg): ?>
        <div class="ps-sign">
          <span class="ps-slabel" style="font-weight:<?= $l['sign_label_bold'] ? 700 : 400 ?>"><?= h($sg['label']) ?></span>
          <span class="ps-sline"><span class="ps-sname"><?= h(pass_slip_sign_name($sg, $a)) ?></span><span class="ps-scap" style="font-weight:<?= $sg['bold'] ? 700 : 400 ?>"><?= h($sg['caption']) ?></span></span>
          <?php if ($sg['note']): ?><span class="ps-snote"><?= h($l['note_label']) ?></span><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endfor; ?>
    </div>
  <?php endforeach; ?>
  </div>
  <?php if ($l['footer_note'] !== ''): ?><div class="ps-reason ut-footnote"><span class="ps-flabel"><?= h($l['footer_note']) ?></span><span class="ps-fval"></span></div><?php endif; ?>
  <?php if ($l['footer'] !== ''): ?><div class="ps-footer"><?= nl2br(h(pass_slip_tokens($l['footer'], $a))) ?></div><?php endif; ?>
  <?php if (in_array($a['status'] ?? '', ['rejected', 'cancelled'], true) && !empty($a['ut_no'])): ?><div class="ps-status"><?= h(strtoupper(label($a['status']))) ?></div><?php endif; ?>
</div>
<?php
    return (string)ob_get_clean();
}
