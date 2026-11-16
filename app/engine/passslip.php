<?php
/**
 * v35: Pass Slip Form (Forgot to Swipe / Log).
 *
 * The employee files the time in / time out he forgot to log from My Workspace; the immediate superior (or HR)
 * approves it like a leave or an overtime request, and the approved times are posted to the raw logs as manual
 * punches ("PASS SLIP <no>") and the daily time record is recomputed.
 *
 * The printed form is fully customizable (Attendance > Pass Slip Form Design): paper, orientation, margins, font,
 * left / right logos, header lines, form number, title, employee fields, the date rows, the time columns (group,
 * label, log type), the reason, the signatories and the footer. The layout is kept as JSON in form_layouts.
 */

const PASS_SLIP_PAPERS = [
    'letter' => ['Letter (8.5 × 11 in)', 215.9, 279.4],
    'a4' => ['A4 (210 × 297 mm)', 210, 297],
    'legal' => ['Legal (8.5 × 14 in)', 215.9, 355.6],
    'half' => ['Half letter (5.5 × 8.5 in)', 139.7, 215.9],
];
const PASS_SLIP_FONTS = ['Arial' => 'Arial', 'Helvetica' => 'Helvetica', 'Calibri' => 'Calibri', 'Tahoma' => 'Tahoma', 'Verdana' => 'Verdana',
    'Times New Roman' => 'Times New Roman', 'Georgia' => 'Georgia', 'Cambria' => 'Cambria', 'Courier New' => 'Courier New'];
const PASS_SLIP_PUNCH = ['in' => 'Time in', 'break_out' => 'Break out', 'break_in' => 'Break in', 'out' => 'Time out'];
const PASS_SLIP_INFO_SOURCES = [
    'employee_name' => 'Employee name (First Last)', 'employee_name_lf' => 'Employee name (Last, First)', 'position' => 'Position',
    'department' => 'Department', 'employee_no' => 'Employee / I.D. No.', 'biometric_id' => 'Biometric ID', 'company' => 'Company',
    'branch' => 'Branch', 'employee_group' => 'Employee group', 'date_filed' => 'Date filed', 'slip_no' => 'Pass slip no.',
    'status' => 'Status', 'blank' => 'Blank line (filled by hand)',
];
const PASS_SLIP_SIGN_SOURCES = ['blank' => 'Blank (signed by hand)', 'employee' => 'Employee', 'approver' => 'Approver of the slip',
    'supervisor' => 'Immediate superior', 'text' => 'Fixed name (type below)'];

/* ============================================================================ layout */

/** The layout of the sample form (JJM Pass Slip Form): every part can be changed in the designer. */
function pass_slip_default_layout(): array
{
    return [
        'paper' => 'letter', 'orientation' => 'portrait',
        'margin_top' => 12, 'margin_right' => 15, 'margin_bottom' => 12, 'margin_left' => 15,
        'font' => 'Arial', 'font_size' => 10,
        'logo_left' => ['source' => 'company', 'file' => '', 'height' => 18],
        'logo_right' => ['source' => 'none', 'file' => '', 'height' => 18],
        'header_align' => 'center', 'header_rule' => 1,
        'header_lines' => [
            ['text' => '{company_name}', 'size' => 13, 'bold' => 1, 'italic' => 0],
            ['text' => '{company_address}', 'size' => 8, 'bold' => 0, 'italic' => 0],
            ['text' => 'Telephone No.: (000) 000-0000 | Mobile No.: 0900 000 0000', 'size' => 8, 'bold' => 0, 'italic' => 0],
            ['text' => 'Email Address: hr@company.com', 'size' => 8, 'bold' => 0, 'italic' => 0],
        ],
        'form_no' => 'HRPSF-{no}', 'form_no_align' => 'right', 'form_no_size' => 8, 'seq_digits' => 3,
        'title' => 'PASS SLIP FORM (Forgot to Swipe/Log)', 'title_size' => 13, 'title_align' => 'center',
        'info_fields' => [
            ['label' => 'Employee:', 'source' => 'employee_name', 'side' => 'left'],
            ['label' => 'Position:', 'source' => 'position', 'side' => 'left'],
            ['label' => 'Department:', 'source' => 'department', 'side' => 'left'],
            ['label' => 'Date Filed:', 'source' => 'date_filed', 'side' => 'right'],
            ['label' => 'I.D No.:', 'source' => 'employee_no', 'side' => 'right'],
        ],
        'date_label' => 'DATE:', 'date_format' => 'M d, Y (D)', 'max_dates' => 3, 'blank_rows' => 1, 'row_height' => 8,
        'value_layout' => 'beside',
        'columns' => [
            ['key' => 'c1', 'group' => 'MORNING', 'label' => 'TIME IN', 'punch' => 'in'],
            ['key' => 'c2', 'group' => 'AFTERNOON', 'label' => 'TIME OUT', 'punch' => 'out'],
        ],
        'time_format' => 'h:i A',
        'reason_label' => 'Reason:', 'reason_lines' => 1,
        'signatories' => [
            ['label' => 'Requested by:', 'caption' => "Employee's Signature", 'name' => 'employee', 'text' => '', 'row' => 1, 'col' => 1, 'note' => 0],
            ['label' => 'Approved by:', 'caption' => 'Supervisor', 'name' => 'approver', 'text' => '', 'row' => 1, 'col' => 2, 'note' => 1],
            ['label' => '', 'caption' => 'Manager', 'name' => 'blank', 'text' => '', 'row' => 2, 'col' => 2, 'note' => 1],
            ['label' => 'For Executives:', 'caption' => 'CEO', 'name' => 'blank', 'text' => '', 'row' => 3, 'col' => 1, 'note' => 0],
            ['label' => '', 'caption' => 'CFO', 'name' => 'blank', 'text' => '', 'row' => 3, 'col' => 2, 'note' => 0],
            ['label' => 'Received by:', 'caption' => 'HR Dept.', 'name' => 'blank', 'text' => '', 'row' => 3, 'col' => 3, 'note' => 0],
        ],
        'sign_cols' => 3, 'note_label' => 'Note:',
        'footer' => '',
    ];
}

/** The saved layout of the Pass Slip Form, completed with the defaults of anything missing. */
function pass_slip_layout(bool $reload = false): array
{
    static $l = null;
    if ($l !== null && !$reload) {
        return $l;
    }
    $saved = null;
    try {
        $saved = val("SELECT layout FROM form_layouts WHERE code = 'pass_slip'");
    } catch (Throwable $e) {
        $saved = null;
    }
    $d = pass_slip_default_layout();
    $s = $saved ? json_decode((string)$saved, true) : null;
    return $l = pass_slip_clean_layout(is_array($s) ? array_merge($d, $s) : $d);
}

/** Save the layout (JSON in form_layouts). */
function pass_slip_layout_save(array $layout): void
{
    $json = json_encode(pass_slip_clean_layout($layout), JSON_UNESCAPED_UNICODE);
    $u = current_user();
    if (val("SELECT COUNT(*) FROM form_layouts WHERE code = 'pass_slip'")) {
        update('form_layouts', ['layout' => $json, 'updated_by' => $u['id'] ?? null, 'updated_at' => now()], 'code = ?', ['pass_slip']);
    } else {
        insert('form_layouts', ['code' => 'pass_slip', 'name' => 'Pass Slip Form', 'layout' => $json, 'updated_by' => $u['id'] ?? null, 'updated_at' => now()]);
    }
    pass_slip_layout(true);
}

/** Keep every value of a layout inside its allowed range (a hand-edited or partly posted layout never breaks the form). */
function pass_slip_clean_layout(array $l): array
{
    $d = pass_slip_default_layout();
    $num = function ($v, float $min, float $max, float $def): float {
        return is_numeric($v) ? max($min, min($max, (float)$v)) : $def;
    };
    $txt = fn($v, int $len = 300) => mb_substr(trim((string)$v), 0, $len);
    $pick = fn($v, array $allowed, $def) => in_array((string)$v, $allowed, true) ? (string)$v : $def;
    $o = [];
    $o['paper'] = $pick($l['paper'] ?? '', array_keys(PASS_SLIP_PAPERS), 'letter');
    $o['orientation'] = $pick($l['orientation'] ?? '', ['portrait', 'landscape'], 'portrait');
    foreach (['margin_top', 'margin_right', 'margin_bottom', 'margin_left'] as $k) {
        $o[$k] = $num($l[$k] ?? null, 0, 60, $d[$k]);
    }
    $o['font'] = isset(PASS_SLIP_FONTS[$l['font'] ?? '']) ? $l['font'] : 'Arial';
    $o['font_size'] = $num($l['font_size'] ?? null, 6, 16, 10);
    foreach (['logo_left', 'logo_right'] as $k) {
        $g = is_array($l[$k] ?? null) ? $l[$k] : $d[$k];
        $o[$k] = ['source' => $pick($g['source'] ?? '', ['company', 'upload', 'none'], 'none'),
            'file' => preg_match('#^forms/[A-Za-z0-9_.-]+$#', (string)($g['file'] ?? '')) ? (string)$g['file'] : '',
            'height' => $num($g['height'] ?? null, 5, 60, 18)];
    }
    $o['header_align'] = $pick($l['header_align'] ?? '', ['left', 'center', 'right'], 'center');
    $o['header_rule'] = !empty($l['header_rule']) ? 1 : 0;
    $o['header_lines'] = [];
    foreach ((array)($l['header_lines'] ?? []) as $h) {
        if (!is_array($h) || trim((string)($h['text'] ?? '')) === '') {
            continue;
        }
        $o['header_lines'][] = ['text' => $txt($h['text']), 'size' => $num($h['size'] ?? null, 6, 28, 9), 'bold' => !empty($h['bold']) ? 1 : 0, 'italic' => !empty($h['italic']) ? 1 : 0];
    }
    $o['form_no'] = $txt($l['form_no'] ?? '', 80);
    $o['form_no_align'] = $pick($l['form_no_align'] ?? '', ['left', 'center', 'right'], 'right');
    $o['form_no_size'] = $num($l['form_no_size'] ?? null, 6, 20, 8);
    $o['seq_digits'] = (int)$num($l['seq_digits'] ?? null, 1, 8, 3);
    $o['title'] = $txt($l['title'] ?? '', 150);
    $o['title_size'] = $num($l['title_size'] ?? null, 8, 28, 13);
    $o['title_align'] = $pick($l['title_align'] ?? '', ['left', 'center', 'right'], 'center');
    $o['info_fields'] = [];
    foreach ((array)($l['info_fields'] ?? []) as $f) {
        if (!is_array($f) || (trim((string)($f['label'] ?? '')) === '' && ($f['source'] ?? '') === 'blank')) {
            continue;
        }
        $o['info_fields'][] = ['label' => $txt($f['label'] ?? '', 60), 'source' => $pick($f['source'] ?? '', array_keys(PASS_SLIP_INFO_SOURCES), 'blank'),
            'side' => $pick($f['side'] ?? '', ['left', 'right', 'full'], 'left')];
    }
    $o['date_label'] = $txt($l['date_label'] ?? '', 40);
    $o['date_format'] = in_array($l['date_format'] ?? '', ['M d, Y (D)', 'M d, Y', 'm/d/Y', 'Y-m-d', 'd M Y', 'l, F d, Y'], true) ? $l['date_format'] : 'M d, Y (D)';
    $o['max_dates'] = (int)$num($l['max_dates'] ?? null, 1, 31, 3);
    $o['blank_rows'] = (int)$num($l['blank_rows'] ?? null, 1, 31, 1);
    $o['row_height'] = $num($l['row_height'] ?? null, 5, 25, 8);
    $o['value_layout'] = $pick($l['value_layout'] ?? '', ['beside', 'below'], 'beside');
    $o['columns'] = [];
    $keys = [];
    foreach ((array)($l['columns'] ?? []) as $c) {
        if (!is_array($c) || trim((string)($c['label'] ?? '')) === '') {
            continue;
        }
        $k = preg_match('/^c\d{1,4}$/', (string)($c['key'] ?? '')) && !isset($keys[$c['key']]) ? (string)$c['key'] : '';
        if ($k === '') {
            $n = 1;
            while (isset($keys['c' . $n]) || in_array('c' . $n, array_column((array)($l['columns'] ?? []), 'key'), true)) {
                $n++;
            }
            $k = 'c' . $n;
        }
        $keys[$k] = true;
        $o['columns'][] = ['key' => $k, 'group' => $txt($c['group'] ?? '', 40), 'label' => $txt($c['label'], 40),
            'punch' => $pick($c['punch'] ?? '', array_keys(PASS_SLIP_PUNCH), 'in')];
    }
    if (!$o['columns']) {
        $o['columns'] = $d['columns'];
    }
    $o['time_format'] = in_array($l['time_format'] ?? '', ['h:i A', 'H:i', 'g:i a'], true) ? $l['time_format'] : 'h:i A';
    $o['reason_label'] = $txt($l['reason_label'] ?? '', 40);
    $o['reason_lines'] = (int)$num($l['reason_lines'] ?? null, 1, 6, 1);
    $o['signatories'] = [];
    foreach ((array)($l['signatories'] ?? []) as $s) {
        if (!is_array($s) || (trim((string)($s['label'] ?? '')) === '' && trim((string)($s['caption'] ?? '')) === '')) {
            continue;
        }
        $o['signatories'][] = ['label' => $txt($s['label'] ?? '', 40), 'caption' => $txt($s['caption'] ?? '', 60),
            'name' => $pick($s['name'] ?? '', array_keys(PASS_SLIP_SIGN_SOURCES), 'blank'), 'text' => $txt($s['text'] ?? '', 80),
            'row' => (int)$num($s['row'] ?? null, 1, 9, 1), 'col' => (int)$num($s['col'] ?? null, 1, 4, 1), 'note' => !empty($s['note']) ? 1 : 0];
    }
    $o['sign_cols'] = (int)$num($l['sign_cols'] ?? null, 1, 4, 3);
    $o['note_label'] = $txt($l['note_label'] ?? 'Note:', 30);
    $o['footer'] = $txt($l['footer'] ?? '', 500);
    return $o;
}

/** Paper width and height (mm) of a layout, after the orientation. */
function pass_slip_paper_mm(array $l): array
{
    [, $w, $h] = PASS_SLIP_PAPERS[$l['paper']] ?? PASS_SLIP_PAPERS['letter'];
    return $l['orientation'] === 'landscape' ? [$h, $w] : [$w, $h];
}

/** Store an uploaded logo of the form (resized like the report logos); returns the path under uploads/ or null. */
function pass_slip_logo_store(string $field): ?string
{
    $f = $_FILES[$field] ?? null;
    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Logo upload failed (error code ' . $f['error'] . ').');
    }
    if ($f['size'] > 4 * 1048576) {
        throw new RuntimeException('The logo is too large: up to 4 MB.');
    }
    $info = @getimagesize($f['tmp_name']);
    $types = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($types[$info[2]])) {
        throw new RuntimeException('Upload the logo as a PNG, JPG or GIF image.');
    }
    $dir = upload_dir() . '/forms';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = 'passslip_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . ($info[2] === IMAGETYPE_JPEG ? 'jpg' : 'png');
    $dest = $dir . '/' . $name;
    $saved = false;
    if (function_exists('imagecreatefromstring')) {
        $src = @imagecreatefromstring((string)file_get_contents($f['tmp_name']));
        if ($src) {
            [$w, $h] = [imagesx($src), imagesy($src)];
            $k = min(1, 700 / max(1, $w), 300 / max(1, $h));
            $nw = max(1, (int)round($w * $k));
            $nh = max(1, (int)round($h * $k));
            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $saved = $info[2] === IMAGETYPE_JPEG ? imagejpeg($dst, $dest, 88) : imagepng($dst, $dest, 6);
            imagedestroy($src);
            imagedestroy($dst);
        }
    }
    if (!$saved && !move_uploaded_file($f['tmp_name'], $dest) && !copy($f['tmp_name'], $dest)) {
        throw new RuntimeException('Could not store the logo.');
    }
    return 'forms/' . $name;
}

/* ============================================================================ slips */

/** Next pass slip number: two-digit year + running number of the year (26089 = the 89th slip of 2026). */
function pass_slip_next_no(?string $date = null): string
{
    $digits = pass_slip_layout()['seq_digits'];
    $yy = substr($date ?: today(), 2, 2);
    $n = (int)val('SELECT COUNT(*) FROM pass_slips WHERE slip_no LIKE ?', [$yy . '%']) + 1;
    do {
        $no = $yy . str_pad((string)$n++, $digits, '0', STR_PAD_LEFT);
    } while ((int)val('SELECT COUNT(*) FROM pass_slips WHERE slip_no = ?', [$no]));
    return $no;
}

/** One slip with the employee's name, position, IDs, department, company / branch and group. */
function pass_slip_row(int $id): ?array
{
    return row('SELECT ps.*, e.first_name, e.last_name, e.middle_name, e.suffix, e.position, e.employee_no, e.biometric_id, e.supervisor_id,
                       e.default_branch_id, e.pay_group_id, ou.name AS department, b.name AS branch_name, b.company_name, b.address AS company_address,
                       eg.name AS employee_group, u.full_name AS approver_user, u.employee_id AS approver_employee_id
                FROM pass_slips ps JOIN employees e ON e.id = ps.employee_id
                LEFT JOIN org_units ou ON ou.id = e.org_unit_id LEFT JOIN branches b ON b.id = e.default_branch_id
                LEFT JOIN emp_groups eg ON eg.id = e.employee_group_id LEFT JOIN users u ON u.id = ps.approved_by
                WHERE ps.id = ?', [$id]);
}

/** The employee fields printed on a blank form or a slip being filed. */
function pass_slip_employee(int $empId): ?array
{
    return row('SELECT e.id AS employee_id, e.first_name, e.last_name, e.middle_name, e.suffix, e.position, e.employee_no, e.biometric_id, e.supervisor_id,
                       e.default_branch_id, e.pay_group_id, ou.name AS department, b.name AS branch_name, b.company_name, b.address AS company_address, eg.name AS employee_group
                FROM employees e LEFT JOIN org_units ou ON ou.id = e.org_unit_id LEFT JOIN branches b ON b.id = e.default_branch_id
                LEFT JOIN emp_groups eg ON eg.id = e.employee_group_id WHERE e.id = ?', [$empId]);
}

/** The dates and times of a slip: [['d' => date, 't' => [column key => 'HH:MM']], ...]. */
function pass_slip_entries(?array $slip): array
{
    $e = $slip ? json_decode((string)($slip['entries'] ?? ''), true) : null;
    return is_array($e) ? array_values(array_filter($e, fn($x) => is_array($x) && !empty($x['d']))) : [];
}

/**
 * Read the dates / times posted by the filing form (ps_date[i], ps_time[i][column key]) against the layout.
 * Returns [entries, errors].
 */
function pass_slip_entries_from_post(array $layout, int $empId): array
{
    $dates = (array)post('ps_date', []);
    $times = (array)post('ps_time', []);
    $cols = $layout['columns'];
    $emp = att_employee($empId);
    $out = [];
    $err = [];
    $seen = [];
    foreach ($dates as $i => $d) {
        $d = trim((string)$d);
        $t = [];
        foreach ($cols as $c) {
            $v = trim((string)($times[$i][$c['key']] ?? ''));
            if ($v !== '') {
                if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $v)) {
                    $err[] = 'Enter a valid time for ' . $c['label'] . '.';
                    continue;
                }
                $t[$c['key']] = sprintf('%02d:%s', (int)substr($v, 0, strpos($v, ':')), substr($v, -2));
            }
        }
        if ($d === '' && !$t) {
            continue;   // empty row
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !strtotime($d)) {
            $err[] = 'Enter the date of every row.';
            continue;
        }
        if ($d > today()) {
            $err[] = fmt_date($d) . ' is a future date: a pass slip is filed for a log you forgot.';
            continue;
        }
        if (isset($seen[$d])) {
            $err[] = fmt_date($d) . ' is entered twice.';
            continue;
        }
        if (!$t) {
            $err[] = 'Enter at least one time for ' . fmt_date($d) . '.';
            continue;
        }
        if ($emp && dtr_locked($emp, $d)) {
            $err[] = 'The attendance of ' . fmt_date($d) . ' is already closed.';
            continue;
        }
        $seen[$d] = true;
        $out[] = ['d' => $d, 't' => $t];
    }
    if (!$out && !$err) {
        $err[] = 'Enter the date and the time you forgot to log.';
    }
    if (count($out) > $layout['max_dates']) {
        $err[] = 'Up to ' . $layout['max_dates'] . ' date(s) per pass slip.';
    }
    usort($out, fn($a, $b) => strcmp($a['d'], $b['d']));
    return [$out, $err];
}

/**
 * Pass slips of every employee (like the overtime requests): users who may authorize overtime in the DTR or resolve
 * attendance exceptions (HR). Everyone else acts on his own team only (immediate superior / department head).
 */
function pass_slip_company_wide(): bool
{
    return is_super_user() || can_any(['dtr.approve', 'att_exceptions.approve']);
}

/** Can the signed-in user approve / reject this slip? Never his own slip (unless Super Admin). */
function pass_slip_can_act(array $slip): bool
{
    if ($slip['status'] !== 'pending') {
        return false;
    }
    if (is_super_user()) {
        return true;
    }
    if ((int)$slip['employee_id'] === (int)my_employee_id() || !can_any(['pass_slips.approve', 'approvals.approve'])) {
        return false;
    }
    return pass_slip_company_wide() || in_array((int)$slip['employee_id'], my_team_ids(), true);
}

/** Can the signed-in user open / print this slip? Its employee, the approvers and the users of Pass Slips. */
function pass_slip_can_view(array $slip): bool
{
    return (int)$slip['employee_id'] === (int)my_employee_id() || is_super_user() || can('pass_slips.view')
        || is_manager_of((int)$slip['employee_id']);
}

/**
 * Post the times of an approved slip as manual logs (source manual, device "PASS SLIP <no>") and recompute the
 * days. A time out earlier than the time in of the same date is read as the next day (night shift). Days already
 * closed and logs already there are skipped. Returns [posted, notes].
 */
function pass_slip_post_logs(array $slip): array
{
    $layout = pass_slip_layout();
    $byKey = array_column($layout['columns'], null, 'key');
    $emp = att_employee((int)$slip['employee_id']);
    if (!$emp) {
        return [0, ['The employee record is missing.']];
    }
    $n = 0;
    $notes = [];
    $days = [];
    foreach (pass_slip_entries($slip) as $en) {
        if (dtr_locked($emp, $en['d'])) {
            $notes[] = fmt_date($en['d']) . ' is closed: not posted.';
            continue;
        }
        $firstIn = null;
        foreach ($en['t'] as $k => $tm) {
            if (($byKey[$k]['punch'] ?? '') === 'in' && ($firstIn === null || $tm < $firstIn)) {
                $firstIn = $tm;
            }
        }
        foreach ($en['t'] as $k => $tm) {
            $punch = $byKey[$k]['punch'] ?? null;
            if (!$punch) {
                continue;   // a column removed from the layout after filing
            }
            $date = $en['d'];
            if ($punch !== 'in' && $firstIn !== null && $tm < $firstIn) {
                $date = add_days($date, 1);
            }
            $at = $date . ' ' . $tm . ':00';
            if (val('SELECT COUNT(*) FROM attendance_logs WHERE employee_id = ? AND log_time = ? AND punch_type = ?', [$emp['id'], $at, $punch])) {
                $notes[] = fmt_date($date) . ' ' . fmt_time($at) . ' (' . PASS_SLIP_PUNCH[$punch] . ') is already logged.';
                continue;
            }
            insert('attendance_logs', ['employee_id' => $emp['id'], 'bio_id' => $emp['biometric_id'], 'log_time' => $at, 'punch_type' => $punch,
                'source' => 'manual', 'branch_id' => $emp['default_branch_id'], 'device_id' => mb_substr('PASS SLIP ' . $slip['slip_no'], 0, 40)]);
            $n++;
            $days[$en['d']] = true;
            $days[$date] = true;
        }
    }
    foreach (array_keys($days) as $d) {
        if ($d <= today()) {
            process_dtr((int)$emp['id'], $d);
            process_dtr((int)$emp['id'], add_days($d, -1));
        }
    }
    return [$n, $notes];
}

/** Approve or reject a pending slip; approval posts the logs. Returns [ok, message]. */
function pass_slip_decide(int $id, string $decision, ?string $remarks = null): array
{
    $s = pass_slip_row($id);
    if (!$s || $s['status'] !== 'pending') {
        return [false, 'The pass slip is no longer pending.'];
    }
    if (!pass_slip_can_act($s)) {
        return [false, 'You cannot act on pass slip ' . $s['slip_no'] . '.'];
    }
    $approve = $decision === 'approve';
    if (trim((string)$remarks) === '') {
        $remarks = null;
    }
    $u = current_user();
    update('pass_slips', ['status' => $approve ? 'approved' : 'rejected', 'approved_by' => $u['id'], 'approved_at' => now(),
        'remarks' => $remarks !== null ? mb_substr((string)$remarks, 0, 255) : null], 'id = ?', [$id]);
    $msg = 'Pass slip ' . $s['slip_no'] . ($approve ? ' approved' : ' rejected');
    if ($approve) {
        [$n, $notes] = pass_slip_post_logs(pass_slip_row($id));
        update('pass_slips', ['logs_posted' => $n], 'id = ?', [$id]);
        $msg .= ': ' . $n . ' log(s) posted and the time record recomputed' . ($notes ? ' (' . implode(' ', $notes) . ')' : '');
    }
    audit('ams', 'pass_slip_' . ($approve ? 'approve' : 'reject'), 'pass_slips', $id, ['remarks' => $remarks]);
    pass_slip_notify_decided(pass_slip_row($id));
    return [true, $msg . '.'];
}

/** Email the immediate superior that a slip waits for approval. */
function pass_slip_notify_filed(array $s): void
{
    if (empty($s['supervisor_id']) || !($mail = employee_email((int)$s['supervisor_id']))) {
        return;
    }
    $body = '<p>' . h(full_name($s, false)) . ' filed pass slip <strong>' . h($s['slip_no']) . '</strong> (forgot to swipe / log) for approval.</p>'
        . pass_slip_entries_html($s) . '<p>Reason: ' . h($s['reason']) . '</p>' . email_button(abs_url('approvals'), 'Review in ' . app_name());
    send_mail($mail, null, 'Pass slip ' . $s['slip_no'] . ': ' . full_name($s, false), email_template('Pass slip for approval', $body), 'pass_slip', (int)$s['id']);
}

/** Email the employee the decision on the slip. */
function pass_slip_notify_decided(?array $s): void
{
    if (!$s || !($mail = employee_email((int)$s['employee_id']))) {
        return;
    }
    $ok = $s['status'] === 'approved';
    $body = '<p>Your pass slip <strong>' . h($s['slip_no']) . '</strong> was <strong>' . ($ok ? 'approved' : 'rejected') . '</strong>'
        . ($s['approver_user'] ? ' by ' . h($s['approver_user']) : '') . '.</p>' . pass_slip_entries_html($s)
        . ($s['remarks'] ? '<p>Remarks: ' . h($s['remarks']) . '</p>' : '')
        . ($ok ? '<p>The times were posted to your daily time record.</p>' : '') . email_button(abs_url('my_pass_slips'), 'Open My Pass Slips');
    send_mail($mail, null, 'Pass slip ' . $s['slip_no'] . ' ' . ($ok ? 'approved' : 'rejected'), email_template('Pass slip ' . ($ok ? 'approved' : 'rejected'), $body), 'pass_slip', (int)$s['id']);
}

/** The dates and times of a slip in a short list (screens and emails). */
function pass_slip_entries_html(array $s, bool $compact = false): string
{
    $cols = array_column(pass_slip_layout()['columns'], null, 'key');
    $out = [];
    foreach (pass_slip_entries($s) as $en) {
        $parts = [];
        foreach ($en['t'] as $k => $tm) {
            $parts[] = h(($cols[$k]['label'] ?? 'Time') . ' ' . date('h:i A', strtotime('2000-01-01 ' . $tm)));
        }
        $out[] = '<strong>' . h(fmt_date($en['d'], 'D M d, Y')) . '</strong>' . ($parts ? ($compact ? ' · ' : ': ') . implode(', ', $parts) : '');
    }
    return $compact ? implode('<br>', $out) : '<p>' . implode('<br>', $out) . '</p>';
}

/* ============================================================================ printed form */

/** The value of an employee field of the form. */
function pass_slip_field_value(string $src, ?array $s): string
{
    if (!$s) {
        return '';
    }
    switch ($src) {
        case 'employee_name': return full_name($s, false);
        case 'employee_name_lf': return full_name($s, true);
        case 'position': return (string)($s['position'] ?? '');
        case 'department': return (string)($s['department'] ?? '');
        case 'employee_no': return (string)($s['employee_no'] ?? '');
        case 'biometric_id': return (string)($s['biometric_id'] ?? '');
        case 'company': return (string)($s['company_name'] ?? '') ?: (string)setting('company_name');
        case 'branch': return (string)($s['branch_name'] ?? '');
        case 'employee_group': return (string)($s['employee_group'] ?? '');
        case 'date_filed': return !empty($s['date_filed']) ? fmt_date($s['date_filed']) : '';
        case 'slip_no': return (string)($s['slip_no'] ?? '');
        case 'status': return !empty($s['status']) ? label($s['status']) : '';
    }
    return '';
}

/** Replace the {tokens} of a header line / the form number. */
function pass_slip_tokens(string $text, ?array $s): string
{
    $map = [
        '{company_name}' => (string)($s['company_name'] ?? '') ?: (string)setting('company_name'),
        '{company_address}' => (string)($s['company_address'] ?? '') ?: (string)setting('company_address'),
        '{branch_name}' => (string)($s['branch_name'] ?? ''),
        '{no}' => (string)($s['slip_no'] ?? '') !== '' ? (string)$s['slip_no'] : '________',   // blank form: written by hand
        '{year}' => substr((string)($s['date_filed'] ?? today()), 0, 4),
    ];
    return strtr($text, $map);
}

/** A logo of the form as an <img>, '' without logo. */
function pass_slip_logo_html(array $g, ?array $s, string $side): string
{
    $uri = '';
    if (!empty($g['data'])) {
        $uri = (string)$g['data'];   // designer preview of an image chosen but not saved yet
    } elseif ($g['source'] === 'company') {
        $uri = function_exists('report_logo_uri') ? report_logo_uri(!empty($s['default_branch_id']) ? (int)$s['default_branch_id'] : null) : '';
    } elseif ($g['source'] === 'upload' && $g['file'] !== '') {
        $uri = report_logo_file_uri(report_logo_path_file($g['file']));
    }
    return $uri !== '' ? '<img class="ps-logo ps-logo-' . $side . '" src="' . h($uri) . '" alt="" style="height:' . (float)$g['height'] . 'mm">' : '';
}

/** Name printed on a signature line. */
function pass_slip_sign_name(array $sg, ?array $s): string
{
    if (!$s) {
        return $sg['name'] === 'text' ? $sg['text'] : '';
    }
    switch ($sg['name']) {
        case 'employee': return full_name($s, false);
        case 'approver':
            if (($s['status'] ?? '') !== 'approved') {
                return '';
            }
            if (!empty($s['approver_employee_id']) && ($e = row('SELECT first_name, last_name, middle_name, suffix FROM employees WHERE id = ?', [(int)$s['approver_employee_id']]))) {
                return full_name($e, false);
            }
            return (string)($s['approver_user'] ?? '');
        case 'supervisor':
            if (!empty($s['supervisor_id']) && ($e = row('SELECT first_name, last_name, middle_name, suffix FROM employees WHERE id = ?', [(int)$s['supervisor_id']]))) {
                return full_name($e, false);
            }
            return '';
        case 'text': return $sg['text'];
    }
    return '';
}

/**
 * The printed form as HTML (a sheet of the paper size with the margins inside).
 * $s = a slip (pass_slip_row) or an employee (pass_slip_employee) for a blank form of that employee, or null.
 */
function pass_slip_html(array $l, ?array $s = null): string
{
    [$pw, $ph] = pass_slip_paper_mm($l);
    $entries = pass_slip_entries($s);
    $blank = !$entries;
    if ($blank) {
        $entries = array_fill(0, $l['blank_rows'], ['d' => '', 't' => []]);
    }
    $cols = $l['columns'];
    $per = $l['value_layout'] === 'beside' ? 2 : 1;
    $span = count($cols) * $per;
    $rh = (float)$l['row_height'];
    $font = "'" . str_replace("'", '', $l['font']) . "', Arial, sans-serif";
    ob_start(); ?>
<div class="ps-sheet" style="width:<?= $pw ?>mm;--ps-h:<?= $ph ?>mm;padding:<?= (float)$l['margin_top'] ?>mm <?= (float)$l['margin_right'] ?>mm <?= (float)$l['margin_bottom'] ?>mm <?= (float)$l['margin_left'] ?>mm;font-family:<?= h($font) ?>;font-size:<?= (float)$l['font_size'] ?>pt">
  <?php $ll = pass_slip_logo_html($l['logo_left'], $s, 'left'); $lr = pass_slip_logo_html($l['logo_right'], $s, 'right'); ?>
  <?php if ($ll || $lr || $l['header_lines']): ?>
  <div class="ps-head<?= $l['header_rule'] ? ' ps-rule' : '' ?>">
    <div class="ps-logo-cell"><?= $ll ?></div>
    <div class="ps-head-text" style="text-align:<?= h($l['header_align']) ?>">
      <?php foreach ($l['header_lines'] as $hl): ?><div style="font-size:<?= (float)$hl['size'] ?>pt;font-weight:<?= $hl['bold'] ? 700 : 400 ?>;font-style:<?= $hl['italic'] ? 'italic' : 'normal' ?>"><?= h(pass_slip_tokens($hl['text'], $s)) ?></div><?php endforeach; ?>
    </div>
    <div class="ps-logo-cell ps-right"><?= $lr ?></div>
  </div>
  <?php endif; ?>
  <?php if ($l['form_no'] !== ''): ?><div class="ps-formno" style="text-align:<?= h($l['form_no_align']) ?>;font-size:<?= (float)$l['form_no_size'] ?>pt"><?= h(pass_slip_tokens($l['form_no'], $s)) ?></div><?php endif; ?>
  <?php if ($l['title'] !== ''): ?><div class="ps-title" style="text-align:<?= h($l['title_align']) ?>;font-size:<?= (float)$l['title_size'] ?>pt"><?= h($l['title']) ?></div><?php endif; ?>
  <?php
    $sides = ['left' => [], 'right' => [], 'full' => []];
    foreach ($l['info_fields'] as $f) {
        $sides[$f['side']][] = $f;
    }
    $field = fn($f) => '<div class="ps-field"><span class="ps-flabel">' . h($f['label']) . '</span><span class="ps-fval">' . h(pass_slip_field_value($f['source'], $s)) . '</span></div>';
  ?>
  <?php if ($sides['left'] || $sides['right']): ?>
  <div class="ps-info"><div><?= implode('', array_map($field, $sides['left'])) ?></div><div><?= implode('', array_map($field, $sides['right'])) ?></div></div>
  <?php endif; ?>
  <?php if ($sides['full']): ?><div class="ps-info ps-info-full"><div><?= implode('', array_map($field, $sides['full'])) ?></div></div><?php endif; ?>
  <table class="ps-grid">
    <colgroup><?php for ($i = 0; $i < $span; $i++): ?><col style="width:<?= round(100 / $span, 3) ?>%"><?php endfor; ?></colgroup>
    <?php foreach ($entries as $en):
        $groups = [];
        foreach ($cols as $c) {
            $last = count($groups) - 1;
            if ($last >= 0 && $groups[$last]['g'] === $c['group']) {
                $groups[$last]['n']++;
            } else {
                $groups[] = ['g' => $c['group'], 'n' => 1];
            }
        }
        $hasGroups = (bool)array_filter(array_column($cols, 'group'), fn($g) => $g !== ''); ?>
    <tbody>
      <tr style="height:<?= $rh ?>mm"><td colspan="<?= $span ?>" class="ps-date"><strong><?= h($l['date_label']) ?></strong> <?= $en['d'] !== '' ? h(fmt_date($en['d'], $l['date_format'])) : '' ?></td></tr>
      <?php if ($hasGroups): ?><tr style="height:<?= $rh ?>mm"><?php foreach ($groups as $g): ?><th colspan="<?= $g['n'] * $per ?>"><?= h($g['g']) ?></th><?php endforeach; ?></tr><?php endif; ?>
      <?php if ($per === 2): ?>
      <tr style="height:<?= $rh ?>mm"><?php foreach ($cols as $c): $tm = $en['t'][$c['key']] ?? ''; ?><th class="ps-clabel"><?= h($c['label']) ?></th><td class="ps-time"><?= $tm !== '' ? h(date($l['time_format'], strtotime('2000-01-01 ' . $tm))) : '' ?></td><?php endforeach; ?></tr>
      <?php else: ?>
      <tr style="height:<?= $rh ?>mm"><?php foreach ($cols as $c): ?><th class="ps-clabel"><?= h($c['label']) ?></th><?php endforeach; ?></tr>
      <tr style="height:<?= $rh ?>mm"><?php foreach ($cols as $c): $tm = $en['t'][$c['key']] ?? ''; ?><td class="ps-time"><?= $tm !== '' ? h(date($l['time_format'], strtotime('2000-01-01 ' . $tm))) : '' ?></td><?php endforeach; ?></tr>
      <?php endif; ?>
    </tbody>
    <?php endforeach; ?>
  </table>
  <?php if ($l['reason_label'] !== ''): ?>
  <div class="ps-reason"><span class="ps-flabel"><?= h($l['reason_label']) ?></span><span class="ps-fval ps-reason-text"><?= h((string)($s['reason'] ?? '')) ?></span></div>
  <?php for ($i = 1; $i < $l['reason_lines']; $i++): ?><div class="ps-reason"><span class="ps-fval"></span></div><?php endfor; ?>
  <?php endif; ?>
  <?php
    $rows = [];
    foreach ($l['signatories'] as $sg) {
        $rows[$sg['row']][$sg['col']][] = $sg;
    }
    ksort($rows);
  ?>
  <div class="ps-signs">
  <?php foreach ($rows as $cells): ?>
    <div class="ps-sign-row" style="grid-template-columns:repeat(<?= $l['sign_cols'] ?>, 1fr)">
      <?php for ($c = 1; $c <= $l['sign_cols']; $c++): ?>
      <div class="ps-sign-cell">
        <?php foreach ($cells[$c] ?? [] as $sg): ?>
        <div class="ps-sign">
          <span class="ps-slabel"><?= h($sg['label']) ?></span>
          <span class="ps-sline"><span class="ps-sname"><?= h(pass_slip_sign_name($sg, $s)) ?></span><span class="ps-scap"><?= h($sg['caption']) ?></span></span>
          <?php if ($sg['note']): ?><span class="ps-snote"><?= h($l['note_label']) ?></span><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endfor; ?>
    </div>
  <?php endforeach; ?>
  </div>
  <?php if ($l['footer'] !== ''): ?><div class="ps-footer"><?= nl2br(h(pass_slip_tokens($l['footer'], $s))) ?></div><?php endif; ?>
  <?php if (in_array($s['status'] ?? '', ['rejected', 'cancelled'], true) && !empty($s['slip_no'])): ?><div class="ps-status"><?= h(strtoupper(label($s['status']))) ?></div><?php endif; ?>
</div>
<?php
    return (string)ob_get_clean();
}
