<?php
/**
 * Attendance Monitoring System engine.
 *  - Resolves each employee's schedule (shift with up to two breaks, flexi time, rest days, holidays)
 *  - Consolidates raw punches (biometric, imported files, web GPS/selfie) into one Daily Time Record
 *    with 4 logs (In, Break Out, Break In, Out) or 6 logs (two breaks)
 *  - Applies the AMS Policies & Configurations (duty hours, late/undertime, incomplete logs, absences...)
 *  - Computes regular hours, lates, undertime, overtime, night premium, absences, leave and holiday hours
 *  - Summarizes a period into the hours-based attendance transaction used by payroll
 */

function att_cfg(bool $reset = false): array
{
    static $c = null;
    if ($reset) {
        $c = null;
    }
    if ($c === null) {
        $b = fn(string $k, string $d = '0') => (string)setting($k, $d) === '1';
        $c = [
            'grace'      => (int)setting('grace_minutes', 0),
            'ot_min'     => (int)setting('ot_min_minutes', 30),
            'ot_req'     => setting('ot_requires_approval', '1') === '1',
            'nd_start'   => (string)setting('nd_start', '22:00'),
            'nd_end'     => (string)setting('nd_end', '06:00'),
            'auto_break' => setting('auto_break_deduct', '1') === '1',
            'hpd'        => (int)setting_num('hours_per_day', 8),
            // AMS Policies & Configurations
            'duty_mode'         => (string)setting('ams_duty_mode', 'first_last'),
            'late_mode'         => (string)setting('ams_late_mode', 'in_out'),
            'break_excess_late' => $b('ams_break_excess_late'),
            'nolate_holiday'    => $b('ams_nolate_holiday'),
            'nolate_restday'    => $b('ams_nolate_restday', '1'),
            'nolate_leave'      => $b('ams_nolate_leave', '1'),
            'short_absent'      => $b('ams_short_absent'),
            'short_ut'          => $b('ams_short_ut'),
            'nobreak_absent'    => $b('ams_nobreak_absent'),
            'nobreak_ut'        => $b('ams_nobreak_ut'),
            'hol_absent_prior'  => $b('ams_holiday_absent_prior'),
            'inc_absent'        => $b('ams_incomplete_absent'),
            'inc_ut'            => $b('ams_incomplete_ut'),
            'inc_penalty'       => $b('ams_incomplete_penalty_on') ? (int)setting('ams_incomplete_penalty', 0) : 0,
            'early_bi_penalty'  => $b('ams_early_break_penalty_on') ? (int)setting('ams_early_break_penalty', 0) : 0,
            'np_from_logs'      => $b('ams_np_from_logs'),
            'invalid_hours'     => (float)setting('ams_invalid_hours', 17) ?: 17.0,
            'dup_minutes'       => max(1, (int)setting('ams_dup_minutes', 1)),
            'six_logs'          => $b('ams_six_logs'),
            'late_lookup'       => (string)setting('ams_late_lookup', 'E'),
            'tardy_week'        => $b('ams_tardy_week_total'),
            'tardy_period'      => $b('ams_tardy_period_total'),
        ];
    }
    return $c;
}

function shift_row(?int $id): ?array
{
    static $cache = [];
    if (!$id) {
        return null;
    }
    if (!array_key_exists($id, $cache)) {
        $cache[$id] = row('SELECT * FROM shifts WHERE id = ?', [$id]);
    }
    return $cache[$id];
}

function att_employee(int $id): ?array
{
    static $cache = [];
    if (!array_key_exists($id, $cache)) {
        $cache[$id] = row('SELECT * FROM employees WHERE id = ?', [$id]);
    }
    return $cache[$id];
}

/** Break windows, span, required and night-premium hours of a shift (computed on a reference date). */
/**
 * Logs per day of a shift: 2 (time in / time out), 4 (one break) or 6 (two breaks).
 * Taken from the shift when set, otherwise derived from its break times; the global
 * six-log policy still forces 6 for every shift.
 */
function shift_logs_per_day(?array $shift): int
{
    if (att_cfg()['six_logs']) {
        return 6;
    }
    $n = (int)($shift['logs_per_day'] ?? 0);
    if (in_array($n, [2, 4, 6], true)) {
        return $n;
    }
    if (!empty($shift['break_out2'])) {
        return 6;
    }
    return 4;
}

/**
 * The complete schedule of a shift, log by log (v18): 2 logs = Time in, Time out; 4 logs = with Break out /
 * Break in; 6 logs = with the second break; the pre-arranged overtime (Overtime In / Overtime Out) adds two
 * more logs (4, 6 or 8 logs a day). Returns [[label, time or null], ...].
 */
function shift_schedule_lines(array $shift): array
{
    $n = shift_logs_per_day($shift);
    $plan = $n === 2 ? ['time_in' => 'Time in', 'time_out' => 'Time out']
        : ($n === 4 ? ['time_in' => 'Time in', 'break_out1' => 'Break out', 'break_in1' => 'Break in', 'time_out' => 'Time out']
            : ['time_in' => 'Time in', 'break_out1' => 'Break out 1', 'break_in1' => 'Break in 1', 'break_out2' => 'Break out 2', 'break_in2' => 'Break in 2', 'time_out' => 'Time out']);
    if (!empty($shift['ot_in']) && !empty($shift['ot_out'])) {
        $plan += ['ot_in' => 'Overtime in', 'ot_out' => 'Overtime out'];
    }
    $out = [];
    $open = (int)($shift['break_minutes'] ?? 0) > 0 ? 'open, ' . (int)$shift['break_minutes'] . ' min.' : null;   // breaks without fixed times
    foreach ($plan as $col => $label) {
        $out[] = [$label, !empty($shift[$col]) ? fmt_time($shift[$col]) : (strpos($col, 'break_') === 0 ? $open : null)];
    }
    return $out;
}

/**
 * The time coverage of a shift (v19), e.g. "6:00 AM-12:00 PM, 1:00 PM-4:00 PM, OT 5:00 PM-7:30 PM":
 * 2 logs = one span; 4 logs = before / after the break; 6 logs = three spans; the pre-arranged overtime
 * adds its span (8 logs). A break without fixed times is shown as its length.
 */
function shift_coverage(array $shift): string
{
    $t = fn($v) => $v ? date('g:i A', strtotime('2000-01-01 ' . $v)) : '?';
    $n = shift_logs_per_day($shift);
    $marks = [$shift['time_in']];
    if ($n >= 4 && !empty($shift['break_out1']) && !empty($shift['break_in1'])) {
        $marks[] = $shift['break_out1'];
        $marks[] = $shift['break_in1'];
    }
    if ($n >= 6 && !empty($shift['break_out2']) && !empty($shift['break_in2'])) {
        $marks[] = $shift['break_out2'];
        $marks[] = $shift['break_in2'];
    }
    $marks[] = $shift['time_out'];
    $spans = [];
    for ($i = 0; $i + 1 < count($marks); $i += 2) {
        $spans[] = $t($marks[$i]) . '-' . $t($marks[$i + 1]);
    }
    $txt = implode(', ', $spans);
    if ($n >= 4 && count($marks) === 2 && (int)($shift['break_minutes'] ?? 0) > 0) {
        $txt .= ', ' . (int)$shift['break_minutes'] . '-min. break';   // a break without fixed times
    }
    if (!empty($shift['ot_in']) && !empty($shift['ot_out'])) {
        $txt .= ', OT ' . $t($shift['ot_in']) . '-' . $t($shift['ot_out']);
    }
    return $txt;
}

/** Label of a shift in a select (v19): code, time coverage and the day-off of the shift. */
function shift_option_label(array $shift): string
{
    $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $rest = shift_is_7day($shift) ? '7 days' : 'rest ' . implode(', ', array_map(fn($d) => $days[(int)$d] ?? $d, array_filter(explode(',', (string)$shift['rest_days']), 'strlen')));
    return $shift['code'] . ' — ' . shift_coverage($shift) . ' (' . $rest . ')';
}

/** HTML of the shift schedule popup (escaped; used by shift_code_html()). */
function shift_tip_html(array $shift): string
{
    $lines = shift_schedule_lines($shift);
    $h = '<div class="sp-head"><strong>' . h($shift['code']) . '</strong> ' . h($shift['name']) . '</div>'
        . '<div class="sp-sub">' . count($lines) . ' logs a day</div><table class="sp-table"><tbody>';
    foreach ($lines as $i => [$label, $time]) {
        $h .= '<tr><td class="sp-n">' . ($i + 1) . '</td><th>' . h($label) . '</th><td>' . ($time !== null ? h($time) : '<span class="muted">not set</span>') . '</td></tr>';
    }
    $h .= '</tbody></table>';
    $req = $shift['required_hours'] !== null && (float)$shift['required_hours'] > 0 ? (float)$shift['required_hours'] : shift_computed_hours($shift)['required_hours'];
    $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $rest = shift_is_7day($shift) ? 'Worked 7 days: the default day-off of the employee'
        : 'Rest day: ' . implode(', ', array_map(fn($d) => $days[(int)$d] ?? $d, array_filter(explode(',', (string)$shift['rest_days']), 'strlen')));
    return $h . '<div class="sp-foot">' . h(rtrim(rtrim(number_format((float)$req, 2), '0'), '.')) . ' working hours' . ((int)($shift['flex_minutes'] ?? 0) ? ', ' . (int)$shift['flex_minutes'] . ' min. flexible' : '') . '<br>' . h($rest) . '</div>';
}

/** A shift code that shows the complete schedule of the shift when pointed at (hover, focus or tap). */
function shift_code_html(?array $shift, string $fallback = '—', ?string $text = null): string
{
    if (!$shift) {
        return h($fallback);
    }
    return '<span class="shift-code' . ($text !== null ? ' is-long' : '') . '" tabindex="0" data-shift-tip="' . h(shift_tip_html($shift)) . '" aria-label="' . h('Shift ' . $shift['code'] . ': ' . implode(', ', array_map(fn($l) => $l[0] . ' ' . ($l[1] ?? 'not set'), shift_schedule_lines($shift)))) . '">' . h($text ?? $shift['code']) . '</span>';
}

function shift_computed_hours(array $s): array
{
    $d = '2026-01-05';
    $in = strtotime("$d {$s['time_in']}");
    $out = strtotime("$d {$s['time_out']}");
    if ($out <= $in) {
        $out += 86400;
    }
    $breaks = [];
    foreach ([['break_out1', 'break_in1'], ['break_out2', 'break_in2']] as $p) {
        if (!empty($s[$p[0]]) && !empty($s[$p[1]])) {
            $bo = strtotime("$d {$s[$p[0]]}");
            $bi = strtotime("$d {$s[$p[1]]}");
            if ($bo < $in) {
                $bo += 86400;
            }
            if ($bi < $bo) {
                $bi += 86400;
            }
            $breaks[] = [$bo, $bi];
        }
    }
    $breakMin = 0;
    foreach ($breaks as $b) {
        $breakMin += overlap_min($in, $out, $b[0], $b[1]);
    }
    if (!$breaks) {
        $breakMin = ($out - $in) > 5 * 3600 ? (int)($s['break_minutes'] ?? 60) : 0;
    }
    $span = (int)(($out - $in) / 60);
    $np = nd_minutes_in($in, $out, $breaks);
    if (!$breaks && $breakMin && $np >= $span) {
        $np = $span - $breakMin; // whole shift inside the night window: the unpaid break is too
    }
    return ['span_minutes' => $span, 'break_minutes' => $breakMin, 'required_hours' => round(max(0, $span - $breakMin) / 60, 2), 'np_hours' => round($np / 60, 2)];
}

/* --------------------------------------------------------------------- pre-arranged overtime (v16) */

/**
 * Scheduled overtime of a shift on a date: [start, end] timestamps of Overtime In / Overtime Out, or
 * null when the shift has none. The overtime follows the shift (8:00 AM - 5:00 PM with Overtime
 * In 7:00 PM and Overtime Out 9:30 PM = 2.5 hours); a time earlier than the time-in is on the next day.
 */
function shift_ot_window(array $shift, string $date): ?array
{
    if (empty($shift['ot_in']) || empty($shift['ot_out']) || empty($shift['time_in'])) {
        return null;
    }
    $tin = strtotime("$date {$shift['time_in']}");
    $oi = strtotime("$date {$shift['ot_in']}");
    if ($oi < $tin) {
        $oi += 86400;
    }
    $oo = strtotime("$date {$shift['ot_out']}");
    while ($oo <= $oi) {
        $oo += 86400;
    }
    return [$oi, $oo];
}

/** Scheduled overtime hours of a shift (Overtime Out - Overtime In), 0 when none. */
function shift_ot_hours(array $shift): float
{
    $w = shift_ot_window($shift, '2026-01-05');
    return $w ? round(($w[1] - $w[0]) / 3600, 2) : 0.0;
}

/**
 * Credited pre-arranged overtime of a day. The overtime is approved automatically (no overtime
 * request needed) and is never more than the scheduled hours:
 *  - Overtime In later than scheduled: the minutes late are taken out (2.5 hrs - minutes late);
 *  - Overtime Out earlier than scheduled: the undertime minutes are taken out;
 *  - a log after Overtime Out adds nothing (the excess is not counted).
 * $oi / $oo: the Overtime In / Overtime Out logs; when the employee did not log out at the end of
 * the shift and stayed on, the time-in and time-out of the day are used ($in / $out).
 * Returns ['minutes', 'late', 'ut', 'nd', 'missing' => true when only one of the two overtime logs exists].
 */
function dtr_pre_ot(array $win, ?string $oi, ?string $oo, ?int $in = null, ?int $out = null): array
{
    [$ws, $we] = $win;
    $r = ['minutes' => 0, 'late' => 0, 'ut' => 0, 'nd' => 0, 'missing' => false];
    if ($oi && $oo) {
        $a = strtotime($oi);
        $b = strtotime($oo);
    } elseif ($oi || $oo) {
        $r['missing'] = true;
        return $r;
    } elseif ($in !== null && $out !== null && $out > $ws && $in < $we) {
        $a = $in;   // worked on without logging out at the end of the shift
        $b = $out;
    } else {
        return $r;
    }
    if ($b <= $a) {
        return $r;
    }
    $r['late'] = max(0, (int)floor(($a - $ws) / 60));
    $r['ut'] = max(0, (int)floor(($we - $b) / 60));
    $sched = (int)floor(($we - $ws) / 60);
    $r['late'] = min($r['late'], $sched);
    $r['ut'] = min($r['ut'], $sched - $r['late']);
    $r['minutes'] = max(0, $sched - $r['late'] - $r['ut']);
    if ($r['minutes'] > 0) {
        $r['nd'] = nd_minutes_in(max($a, $ws), min($b, $we));
    }
    return $r;
}

/* --------------------------------------------------------------------- default day-off (v12) */

const WEEKDAY_NAMES = ['0' => 'Sunday', '1' => 'Monday', '2' => 'Tuesday', '3' => 'Wednesday', '4' => 'Thursday', '5' => 'Friday', '6' => 'Saturday'];

/**
 * Default day-off of an employee from a form, a file or the database: weekday numbers (0 = Sunday ...
 * 6 = Saturday, 7 = Sunday too), day names (Sunday, Sun, Su) or a list of them separated by commas,
 * semicolons, slashes or spaces. Returns [stored value "0,6" or null for no day-off, unknown tokens].
 */
function day_off_parse($v): array
{
    $tokens = is_array($v) ? $v : preg_split('/[\s,;\/|+&]+/', (string)$v);
    $days = [];
    $bad = [];
    foreach ($tokens as $t) {
        $t = strtolower(trim((string)$t));
        if ($t === '' || in_array($t, ['none', 'no', 'n', '-', '—', 'and'], true)) {
            continue;
        }
        $d = null;
        if (preg_match('/^[0-7]$/', $t)) {
            $d = (string)((int)$t % 7);
        } else {
            foreach (WEEKDAY_NAMES as $k => $name) {
                $n = strtolower($name);
                if ($t === $n || (strlen($t) >= 2 && strpos($n, $t) === 0)) {
                    $d = (string)$k;
                    break;
                }
            }
            if ($t === 'th' || $t === 'thur' || $t === 'thurs') {
                $d = '4';
            }
            if ($t === 't' || $t === 's') {
                $d = null;   // ambiguous
            }
        }
        if ($d === null) {
            $bad[] = $t;
        } else {
            $days[$d] = true;
        }
    }
    $keys = array_keys($days);
    sort($keys);
    return [$keys ? implode(',', $keys) : null, $bad];
}

/** "Sunday, Saturday" (or "None") for a stored default day-off. */
function day_off_label(?string $v, bool $short = false): string
{
    $out = [];
    foreach (array_filter(explode(',', (string)$v), fn($x) => $x !== '') as $d) {
        if (isset(WEEKDAY_NAMES[$d])) {
            $out[] = $short ? substr(WEEKDAY_NAMES[$d], 0, 3) : WEEKDAY_NAMES[$d];
        }
    }
    return $out ? implode(', ', $out) : 'None';
}

/** A shift worked all seven days: whole-week duty, or no rest day in the Shift Table. */
function shift_is_7day(?array $shift): bool
{
    return $shift && (!empty($shift['whole_week']) || trim((string)($shift['rest_days'] ?? '')) === '');
}

/** Weekday numbers ('0' ... '6') of the employee's default day-off. */
function employee_day_off(array $emp): array
{
    if (!array_key_exists('day_off', $emp)) {
        static $cache = [];
        $id = (int)($emp['id'] ?? 0);
        if (!array_key_exists($id, $cache)) {
            $cache[$id] = $id ? val('SELECT day_off FROM employees WHERE id = ?', [$id]) : null;
        }
        $emp['day_off'] = $cache[$id];
    }
    return array_values(array_filter(explode(',', (string)$emp['day_off']), fn($x) => $x !== ''));
}

/**
 * Re-compute the open daily time records of an employee after the default day-off or the shift
 * changed (the last 45 days that already have a record; closed periods are left alone).
 */
function dtr_refresh_recent(int $empId, int $days = 45): int
{
    $n = 0;
    foreach (q('SELECT work_date FROM dtr WHERE employee_id = ? AND work_date BETWEEN ? AND ? ORDER BY work_date', [$empId, add_days(today(), -$days), today()])->fetchAll(PDO::FETCH_COLUMN) as $d) {
        process_dtr($empId, (string)$d);
        $n++;
    }
    return $n;
}

/**
 * Is the date a day-off by the shift of the day? (v18) The day-off embedded in the shift (rest days of
 * the Shift Table) decides; a 7-day shift (whole-week duty or no rest day) takes the employee's default
 * day-off of the Employee Masterfile.
 */
function shift_day_off(array $emp, ?array $shift, string $date): bool
{
    if (!$shift) {
        return false;
    }
    $dow = date('w', strtotime($date));
    if (shift_is_7day($shift)) {
        return in_array($dow, employee_day_off($emp), true);
    }
    return in_array($dow, array_map('trim', explode(',', (string)$shift['rest_days'])), true);
}

/**
 * Scheduled shift, rest day, breaks, required minutes and branch of an employee on a date.
 * Rest day: a Schedule Override of the date that says rest day / working day decides; an override that
 * only changes the shift (is_rest_day NULL, v18) and the days without override follow the day-off of the
 * shift: its embedded rest days, or, for a 7-day shift, the employee's default day-off.
 */
function employee_schedule(array $emp, string $date): array
{
    $o = row('SELECT * FROM employee_schedules WHERE employee_id = ? AND work_date = ?', [$emp['id'], $date]);
    $branch = (int)$emp['default_branch_id'];
    if ($o) {
        $shift = $o['shift_id'] ? shift_row((int)$o['shift_id']) : shift_row($emp['shift_id'] ? (int)$emp['shift_id'] : null);
        $isRest = $o['is_rest_day'] === null ? shift_day_off($emp, $shift, $date) : (bool)$o['is_rest_day'];
        if ($o['branch_id']) {
            $branch = (int)$o['branch_id'];
        }
        $noSched = !$shift && !$isRest;
    } else {
        $shift = shift_row($emp['shift_id'] ? (int)$emp['shift_id'] : null);
        $isRest = shift_day_off($emp, $shift, $date);
        $noSched = !$shift;
    }
    $in = $out = null;
    $breaks = [];
    $hpd = (int)att_cfg()['hpd'];
    $required = $hpd * 60;
    if ($shift) {
        $in = $date . ' ' . $shift['time_in'];
        $out = ($shift['time_out'] <= $shift['time_in'] ? add_days($date, 1) : $date) . ' ' . $shift['time_out'];
        $tin = strtotime($in);
        foreach ([['break_out1', 'break_in1'], ['break_out2', 'break_in2']] as $p) {
            if (!empty($shift[$p[0]]) && !empty($shift[$p[1]])) {
                $bo = strtotime("$date {$shift[$p[0]]}");
                $bi = strtotime("$date {$shift[$p[1]]}");
                if ($bo < $tin) {
                    $bo += 86400;
                }
                if ($bi < $bo) {
                    $bi += 86400;
                }
                $breaks[] = [$bo, $bi];
            }
        }
        if ($shift['required_hours'] !== null && (float)$shift['required_hours'] > 0) {
            $required = (int)round((float)$shift['required_hours'] * 60);
        } else {
            $required = min($hpd * 60, (int)(shift_computed_hours($shift)['required_hours'] * 60)) ?: $hpd * 60;
        }
    }
    // v16: pre-arranged overtime after the shift (Overtime In / Overtime Out), on working days only
    $ot = ($shift && !$isRest) ? shift_ot_window($shift, $date) : null;
    return ['shift' => $shift, 'is_rest' => $isRest, 'no_schedule' => $noSched, 'branch_id' => $branch, 'sched_in' => $in, 'sched_out' => $out,
        'breaks' => $breaks, 'required_min' => $required, 'flex' => (int)($shift['flex_minutes'] ?? 0), 'ot' => $ot];
}

/** 'regular', 'special', 'double' or null. */
function holiday_on(string $date, ?int $branchId = null): ?string
{
    static $cache = [];
    $k = $date . '|' . (int)$branchId;
    if (!array_key_exists($k, $cache)) {
        $h = rows('SELECT holiday_type FROM holidays WHERE holiday_date = ? AND (branch_id IS NULL OR branch_id = ?)', [$date, (int)$branchId]);
        $reg = count(array_filter($h, fn($x) => $x['holiday_type'] === 'regular'));
        $cache[$k] = $reg >= 2 ? 'double' : ($reg === 1 ? 'regular' : ($h ? 'special' : null));
    }
    return $cache[$k];
}

function resolve_day_type(bool $isRest, ?string $holiday): string
{
    if ($holiday === 'double') {
        return 'double_holiday';
    }
    if ($holiday === 'regular') {
        return $isRest ? 'regular_rest' : 'regular_holiday';
    }
    if ($holiday === 'special') {
        return $isRest ? 'special_rest' : 'special_holiday';
    }
    return $isRest ? 'rest_day' : 'ordinary';
}

function overlap_min(int $a1, int $a2, int $b1, int $b2): int
{
    return (int)max(0, floor((min($a2, $b2) - max($a1, $b1)) / 60));
}

/** Minutes of [start,end) falling inside night-premium windows, excluding given intervals. */
function nd_minutes_in(int $start, int $end, array $exclude = []): int
{
    if ($end <= $start) {
        return 0;
    }
    $c = att_cfg();
    $total = 0;
    $d = date('Y-m-d', $start - 86400);
    $last = date('Y-m-d', $end);
    while ($d <= $last) {
        $ws = strtotime($d . ' ' . $c['nd_start']);
        $we = strtotime(($c['nd_end'] <= $c['nd_start'] ? add_days($d, 1) : $d) . ' ' . $c['nd_end']);
        $total += overlap_min($start, $end, $ws, $we);
        foreach ($exclude as $ex) {
            if ($ex) {
                $s = max($start, $ex[0]);
                $e = min($end, $ex[1]);
                if ($e > $s) {
                    $total -= overlap_min($s, $e, $ws, $we);
                }
            }
        }
        $d = add_days($d, 1);
    }
    return max(0, $total);
}

/** Approved leave covering a date (with its type and transaction column). */
function approved_leave_on(int $empId, string $date): ?array
{
    return row("SELECT la.id, la.leave_type_id, lt.is_paid, lt.code, lt.name, lt.summary_bucket
                FROM leave_applications la JOIN leave_types lt ON lt.id = la.leave_type_id
                WHERE la.employee_id = ? AND la.status = 'approved' AND ? BETWEEN la.date_from AND la.date_to
                ORDER BY la.id DESC LIMIT 1", [$empId, $date]);
}

/** Workdays of an employee in a range (used for suspension and leave counting). */
function employee_workdays(array $emp, string $from, int $count): array
{
    $out = [];
    $d = $from;
    $guard = 0;
    while (count($out) < $count && $guard++ < 120) {
        $s = employee_schedule($emp, $d);
        if (!$s['is_rest'] && !holiday_on($d, $s['branch_id'])) {
            $out[] = $d;
        }
        $d = add_days($d, 1);
    }
    return $out;
}

function is_suspended_on(array $emp, string $date): bool
{
    $cases = rows("SELECT suspension_start, suspension_days FROM disciplinary_cases
                   WHERE employee_id = ? AND decision = 'suspension' AND status IN ('decided','closed')
                     AND suspension_start IS NOT NULL AND suspension_start <= ? AND suspension_start >= ?",
        [$emp['id'], $date, add_days($date, -90)]);
    foreach ($cases as $c) {
        if (in_array($date, employee_workdays($emp, $c['suspension_start'], (int)$c['suspension_days']), true)) {
            return true;
        }
    }
    return false;
}

/**
 * Is the day of this employee locked? v19: per employee, not per period. The day is locked when the payroll of
 * its period is closed and paid (Period Closing), or when the attendance transaction of THIS employee is closed
 * (Attendance Transaction Closing). Closing one company / branch leaves the employees of the other companies
 * open: their biologs can still be posted, their days viewed, corrected and summarized.
 */
function dtr_locked(array $emp, string $date): bool
{
    static $cache = [];
    $k = (int)($emp['id'] ?? 0) . '|' . (int)$emp['pay_group_id'] . '|' . $date;
    if (array_key_exists($k, $cache) && $cache[$k] === true) {
        return true;
    }
    $locked = (bool)val("SELECT COUNT(*) FROM attendance_cutoffs c LEFT JOIN attendance_summaries s ON s.cutoff_id = c.id AND s.employee_id = ?
                         WHERE c.pay_group_id = ? AND ? BETWEEN c.period_start AND c.period_end AND (c.status = 'locked' OR s.is_closed = 1)",
        [(int)($emp['id'] ?? 0), $emp['pay_group_id'], $date]);
    if ($locked) {
        $cache[$k] = true;   // a lock never lifts inside one request; an open day is checked again (it can be closed meanwhile)
    }
    return $locked;
}

/** Was the employee absent (without approved leave) on the last scheduled working day before a holiday? */
function absent_prior_workday(array $emp, string $date): bool
{
    $d = add_days($date, -1);
    for ($i = 0; $i < 7; $i++, $d = add_days($d, -1)) {
        if ($d < $emp['date_hired']) {
            return false;
        }
        $s = employee_schedule($emp, $d);
        if ($s['is_rest'] || $s['no_schedule'] || holiday_on($d, $s['branch_id'])) {
            continue;
        }
        $st = val('SELECT status FROM dtr WHERE employee_id = ? AND work_date = ?', [$emp['id'], $d]);
        return $st === 'absent' || $st === 'suspended';
    }
    return false;
}

/**
 * Arrange a day's punches by position, the way the legacy Attendance Entry does, using the
 * number of logs the shift requires: 2 = In, Out; 4 = In, Break Out 1, Break In 1, Out;
 * 6 = In, Break Out 1, Break In 1, Break Out 2, Break In 2, Out. The first punch is always the
 * time in and the last the time out.
 *
 * When the day has fewer punches than the shift requires and the shift's break times are known
 * ($breaks = [[break out, break in], ...] as timestamps), each punch in between goes to the break
 * log it is nearest to, in order: on an 8:00-12:00 / 1:00-5:00 shift, 8:00 12:01 5:05 reads as
 * In, Break out, Out (break in missing) and 8:00 12:58 5:05 as In, Break in, Out (break out
 * missing). Without break times the punches in between fill the break logs from the first one.
 * Returns ['in','bo1','bi1','bo2','bi2','out'] => time or null.
 */
function punch_slots_by_position(array $times, int $lpd, array $breaks = []): array
{
    $p = ['in' => null, 'bo1' => null, 'bi1' => null, 'bo2' => null, 'bi2' => null, 'out' => null];
    $times = array_values($times);
    $n = count($times);
    if (!$n) {
        return $p;
    }
    $p['in'] = $times[0];
    if ($n === 1) {
        return $p;
    }
    $p['out'] = $times[$n - 1];
    $mid = array_slice($times, 1, $n - 2);
    $slotKeys = $lpd >= 6 ? ['bo1', 'bi1', 'bo2', 'bi2'] : ($lpd >= 4 ? ['bo1', 'bi1'] : []);
    if (!$mid || !$slotKeys) {
        return $p;
    }
    // scheduled time of each break log the shift defines
    $slotTime = [];
    foreach ($slotKeys as $i => $k) {
        $b = $breaks[intdiv($i, 2)] ?? null;
        if ($b) {
            $slotTime[$k] = $b[$i % 2];
        }
    }
    if (count($mid) < count($slotKeys) && count($slotTime) === count($slotKeys)) {
        // fewer punches than break logs: each punch to its nearest break log, keeping the order
        $best = null;
        $choose = function (int $pi, int $from, array $pick, float $cost) use (&$choose, &$best, $mid, $slotKeys, $slotTime) {
            if ($pi === count($mid)) {
                if ($best === null || $cost < $best[0]) {
                    $best = [$cost, $pick];
                }
                return;
            }
            for ($si = $from; $si <= count($slotKeys) - (count($mid) - $pi); $si++) {
                $k = $slotKeys[$si];
                $choose($pi + 1, $si + 1, $pick + [$k => $mid[$pi]], $cost + abs(strtotime($mid[$pi]) - $slotTime[$k]));
            }
        };
        $choose(0, 0, [], 0.0);
        foreach ($best[1] as $k => $t) {
            $p[$k] = $t;
        }
        return $p;
    }
    foreach ($slotKeys as $i => $k) {
        if (isset($mid[$i])) {
            $p[$k] = $mid[$i];
        }
    }
    return $p;
}

/**
 * The work segments of a shift with breaks and whether the logs that bound each of them are there.
 * An 8:00-12:00 / 1:00-5:00 shift has two segments: 8:00-12:00 (needs Time in and Break out 1) and
 * 1:00-5:00 (needs Break in 1 and Time out). Used by the policy "No BREAK log on SHIFT w/ break as
 * ABSENT (or UNDERTIME)": a segment whose opening or closing log is missing is not credited.
 * Returns a list of ['from', 'to', 'minutes', 'start', 'end', 'ok', 'missing' => [log labels]].
 */
function dtr_break_segments(array $p, int $si, int $so, array $breaks): array
{
    $label = ['in' => 'Time in', 'bo1' => 'Break out 1', 'bi1' => 'Break in 1', 'bo2' => 'Break out 2', 'bi2' => 'Break in 2', 'out' => 'Time out'];
    $nb = count($breaks);
    $segs = [];
    for ($i = 0; $i <= $nb; $i++) {
        $from = $i === 0 ? $si : $breaks[$i - 1][1];
        $to = $i === $nb ? $so : $breaks[$i][0];
        $start = $i === 0 ? 'in' : 'bi' . $i;
        $end = $i === $nb ? 'out' : 'bo' . ($i + 1);
        $missing = [];
        foreach ([$start, $end] as $k) {
            if (empty($p[$k])) {
                $missing[] = $label[$k];
            }
        }
        $segs[] = ['from' => $from, 'to' => $to, 'minutes' => max(0, (int)floor(($to - $from) / 60)), 'start' => $start, 'end' => $end,
            'ok' => !$missing, 'missing' => $missing];
    }
    return $segs;
}

/** Punch type of each slot, for classifying untyped logs: slot key => punch_type. */
const PUNCH_SLOT_TYPES = ['in' => 'in', 'bo1' => 'break_out', 'bi1' => 'break_in', 'bo2' => 'break_out', 'bi2' => 'break_in', 'out' => 'out'];

/**
 * Classify untyped (or in/out-only) punches of one employee-day by the shift code:
 * returns [log_time => punch_type] for every punch that lands in a slot; the rest stay 'unknown'.
 */
function classify_punches_by_shift(array $emp, string $date, array $times): array
{
    sort($times);
    $sched = employee_schedule($emp, $date);
    $lpd = shift_logs_per_day($sched['shift'] ?? null);
    $out = [];
    foreach (punch_slots_by_position(array_values($times), $lpd, $sched['breaks']) as $slot => $t) {
        if ($t !== null) {
            $out[$t] = PUNCH_SLOT_TYPES[$slot];
        }
    }
    return $out;
}

/**
 * Change the type of one raw punch (In / Break out / Break in / Out) and rebuild the days it
 * touches. Used to correct a log the device or the import classified wrongly.
 */
function punch_retype(int $logId, string $type, string $reason = ''): array
{
    if (!in_array($type, ['in', 'break_out', 'break_in', 'out'], true)) {
        throw new RuntimeException('Unknown log type.');
    }
    $l = row('SELECT * FROM attendance_logs WHERE id = ?', [$logId]);
    if (!$l) {
        throw new RuntimeException('Punch not found.');
    }
    if ($l['punch_type'] === $type) {
        return $l;
    }
    $emp = $l['employee_id'] ? att_employee((int)$l['employee_id']) : null;
    $date = substr((string)$l['log_time'], 0, 10);
    if ($emp && dtr_locked($emp, $date)) {
        throw new RuntimeException('That day belongs to an approved (locked) attendance period.');
    }
    // the unique key is employee + time + type: a punch of the new type at the same second already exists
    if ($l['employee_id'] && val('SELECT COUNT(*) FROM attendance_logs WHERE employee_id = ? AND log_time = ? AND punch_type = ? AND id <> ?', [$l['employee_id'], $l['log_time'], $type, $logId])) {
        throw new RuntimeException('A punch of that type already exists at the same time.');
    }
    $orig = $l['orig_punch_type'] ?? null;
    update('attendance_logs', ['punch_type' => $type, 'orig_punch_type' => $orig ?: $l['punch_type']], 'id = ?', [$logId]);
    if ($emp) {
        foreach ([add_days($date, -1), $date, add_days($date, 1)] as $d) {
            if (!dtr_locked($emp, $d)) {
                process_dtr((int)$emp['id'], $d);
            }
        }
    }
    audit('ams', 'punch_retype', 'attendance_logs', $logId, ['employee_id' => $l['employee_id'], 'time' => $l['log_time'], 'from' => $l['punch_type'], 'to' => $type, 'reason' => $reason]);
    return array_merge($l, ['punch_type' => $type]);
}

/**
 * The logs the AMS policy "# of minutes between LOGS w/ same TYPE as INVALID" leaves out, for
 * display on the raw log screens: id => reason. Same rule as dtr_collect_punches(), applied per
 * employee and calendar day.
 */
function punch_invalid_ids(array $logs): array
{
    $gap = att_cfg()['dup_minutes'] * 60;
    $groups = [];
    foreach ($logs as $l) {
        if ($l['employee_id'] && ($l['verify_status'] ?? '') !== 'rejected') {
            $groups[$l['employee_id'] . '|' . substr((string)$l['log_time'], 0, 10)][] = $l;
        }
    }
    $out = [];
    foreach ($groups as $g) {
        usort($g, fn($a, $b) => strcmp($a['log_time'], $b['log_time']) ?: ((int)$a['id'] <=> (int)$b['id']));
        $lastByType = [];
        foreach ($g as $l) {
            $t = strtotime($l['log_time']);
            $k = $l['punch_type'];
            if (isset($lastByType[$k]) && $t - $lastByType[$k] < $gap) {
                $out[(int)$l['id']] = sprintf('Not counted: within %d min of the previous %s log (AMS policy).', att_cfg()['dup_minutes'], $k === 'unknown' ? 'untyped' : label($k));
                continue;
            }
            $lastByType[$k] = $t;
        }
    }
    return $out;
}

/**
 * Read and assign the punches of a day: In, Break Out 1, Break In 1, Break Out 2, Break In 2, Out.
 *
 * Follows the AMS Policies & Configurations:
 *  - "# of minutes between logs as INVALID": a punch that follows another punch of the same type
 *    inside that window is not counted (a double tap on the terminal). Punches without a usable
 *    type are held to the same window against the previous punch of any type.
 *  - "Use 6 logs per day" and the shift code's Logs per day decide how many punches make a day.
 *  - When the device only says In / Out (or nothing), the punches are placed by position, the
 *    way the Attendance Entry grid shows them: first = time in, last = time out, the ones in
 *    between = break out / break in as the shift requires. Only explicit Break out / Break in
 *    types switch to placement by type.
 */
function dtr_collect_punches(int $empId, array $sched, string $date): array
{
    $c = att_cfg();
    if ($sched['sched_in']) {
        $ws = date('Y-m-d H:i:s', strtotime($sched['sched_in']) - 4 * 3600);
        $we = date('Y-m-d H:i:s', max(strtotime($sched['sched_out']) + 8 * 3600, !empty($sched['ot']) ? $sched['ot'][1] + 4 * 3600 : 0));
    } else {
        $ws = $date . ' 04:00:00';
        $we = add_days($date, 1) . ' 03:59:59';
    }
    $logs = rows("SELECT * FROM attendance_logs WHERE employee_id = ? AND log_time BETWEEN ? AND ? AND verify_status <> 'rejected' ORDER BY log_time, id",
        [$empId, $ws, $we]);
    $lpd = shift_logs_per_day($sched['shift'] ?? null);
    $rawTypes = array_unique(array_column($logs, 'punch_type'));
    $byType = (bool)array_intersect($rawTypes, ['break_out', 'break_in']);   // explicit break punches: place by type
    $gap = $c['dup_minutes'] * 60;
    $clean = [];
    $lastByType = [];
    $invalid = 0;
    foreach ($logs as $l) {
        // a log repeating the previous log of the same type (as the device or file recorded it, untyped
        // logs included) inside the policy window is a double tap and is not counted; a log of another
        // type, such as the break in after a 15-minute break, always counts
        $t = strtotime($l['log_time']);
        $k = $l['punch_type'];
        if (isset($lastByType[$k]) && $t - $lastByType[$k] < $gap) {
            $invalid++;
            continue;
        }
        $lastByType[$k] = $t;
        $clean[] = $l;
    }
    $logs = $clean;
    $p = ['in' => null, 'bo1' => null, 'bi1' => null, 'bo2' => null, 'bi2' => null, 'out' => null];
    $res = ['p' => $p + ['oti' => null, 'oto' => null], 'source' => null, 'web' => [], 'branch' => null, 'count' => count($logs), 'invalid' => $invalid, 'lpd' => $lpd];
    if (!$logs) {
        return $res;
    }
    // v16 pre-arranged overtime: the logs after the middle of the gap between the end of the shift and
    // Overtime In are the Overtime In / Overtime Out logs (first and last); the others make the day
    $ot = ['oti' => null, 'oto' => null];
    if (!empty($sched['ot']) && $sched['sched_out']) {
        $soT = strtotime($sched['sched_out']);
        $gapS = $sched['ot'][0] - $soT;
        if ($gapS >= 1800) {
            $boundary = $soT + intdiv($gapS, 2);
            $dayLogs = $otLogs = [];
            foreach ($logs as $l) {
                if (strtotime($l['log_time']) >= $boundary) {
                    $otLogs[] = $l;
                } else {
                    $dayLogs[] = $l;
                }
            }
            if (count($otLogs) === 1 && count($dayLogs) < 2) {
                $dayLogs[] = $otLogs[0];   // only a time-in before: the late log is the time out of the day
                $otLogs = [];
            }
            if (count($otLogs) >= 2) {
                $ot['oti'] = $otLogs[0]['log_time'];
                $ot['oto'] = end($otLogs)['log_time'];
            } elseif ($otLogs) {
                $t = strtotime($otLogs[0]['log_time']);
                $ot[abs($t - $sched['ot'][0]) <= abs($t - $sched['ot'][1]) ? 'oti' : 'oto'] = $otLogs[0]['log_time'];
            }
            $logs = $dayLogs;
            if (!$logs) {
                $res['p'] = $p + $ot;
                $res['source'] = $otLogs ? $otLogs[0]['source'] : null;
                return $res;
            }
        }
    }
    $times = array_column($logs, 'log_time');
    if (!$byType) {
        $n = count($times);
        if ($n === 1) {
            $t = strtotime($times[0]);
            $type = $logs[0]['punch_type'];
            $nearerOut = $sched['sched_in'] && abs($t - strtotime($sched['sched_out'])) < abs($t - strtotime($sched['sched_in']));
            // v22: a lone punch from a terminal or a file is placed by the schedule, whatever In / Out it carries: many
            // terminals record every punch as "Out", so an 8:10 AM "Out" is the time in. A web check-in keeps the
            // type the employee chose.
            if ($sched['sched_in'] && $logs[0]['source'] !== 'web') {
                $type = $nearerOut ? 'out' : 'in';
            }
            if ($type === 'out' || ($type === 'unknown' && $nearerOut)) {
                $p['out'] = $times[0];
            } else {
                $p['in'] = $times[0];
            }
        } else {
            $p = punch_slots_by_position($times, $lpd, $sched['breaks'] ?? []);
        }
    } else {
        $by = ['in' => [], 'out' => [], 'break_out' => [], 'break_in' => [], 'unknown' => []];
        foreach ($logs as $l) {
            $by[$l['punch_type']][] = $l['log_time'];
        }
        $p['in'] = $by['in'][0] ?? null;
        $p['out'] = $by['out'] ? end($by['out']) : null;
        $p['bo1'] = $by['break_out'][0] ?? null;
        foreach ($by['break_in'] as $t) {
            if (!$p['bo1'] || $t > $p['bo1']) {
                $p['bi1'] = $t;
                break;
            }
        }
        if ($p['bi1']) {
            foreach ($by['break_out'] as $t) {
                if ($t > $p['bi1']) {
                    $p['bo2'] = $t;
                    break;
                }
            }
            if ($p['bo2']) {
                foreach ($by['break_in'] as $t) {
                    if ($t > $p['bo2']) {
                        $p['bi2'] = $t;
                        break;
                    }
                }
            }
        }
        if ($lpd === 2) {
            $p['bo1'] = $p['bi1'] = $p['bo2'] = $p['bi2'] = null;
        } elseif ($lpd === 4) {
            $p['bo2'] = $p['bi2'] = null;
        }
        $un = $by['unknown'];
        if ($un && !$p['in']) {
            $p['in'] = $un[0];
        }
        if ($un && !$p['out'] && count($un) > 1) {
            $p['out'] = end($un);
        }
        // a reversed pair (the terminal said Out in the morning and In in the evening) is still one day
        if ($p['in'] && $p['out'] && $p['out'] < $p['in']) {
            [$p['in'], $p['out']] = [$p['out'], $p['in']];
        }
    }
    foreach ($logs as $l) {
        if ($l['branch_id'] && $l['log_time'] === $p['in']) {
            $res['branch'] = (int)$l['branch_id'];
        }
        if ($l['source'] === 'web' && $l['verify_status'] !== 'verified') {
            if ($l['within_geofence'] === 0 || $l['within_geofence'] === '0') {
                $res['web']['outside_geofence'] = true;
            }
            if ($l['verify_status'] === 'pending') {
                $res['web']['unverified_web'] = true;
            }
        }
    }
    $res['p'] = $p + $ot;
    $res['source'] = $logs[0]['source'];
    return $res;
}

/** v24: grace period of a shift: none on a strict-time shift, the shift's own grace, else AMS Policies & Configuration. */
function dtr_grace_minutes(?array $shift, array $c): int
{
    if ($shift && !empty($shift['strict_time'])) {
        return 0;
    }
    if ($shift && isset($shift['grace_minutes']) && $shift['grace_minutes'] !== null && $shift['grace_minutes'] !== '') {
        return max(0, (int)$shift['grace_minutes']);
    }
    return max(0, (int)$c['grace']);
}

/**
 * Build / refresh the DTR row of one employee for one date.
 * Manually-overridden rows keep their encoded times but minutes are recomputed.
 */
function process_dtr(int $empId, string $date, bool $force = false): ?array
{
    $emp = att_employee($empId);
    if (!$emp || $date < $emp['date_hired'] || ($emp['date_separated'] && $date > $emp['date_separated'])) {
        return null;
    }
    if (!$force && dtr_locked($emp, $date)) {
        return row('SELECT * FROM dtr WHERE employee_id = ? AND work_date = ?', [$empId, $date]);
    }
    $c = att_cfg();
    $existing = row('SELECT * FROM dtr WHERE employee_id = ? AND work_date = ?', [$empId, $date]);
    $sched = employee_schedule($emp, $date);
    $shift = $sched['shift'];
    $hol = holiday_on($date, $sched['branch_id']);
    $dayType = resolve_day_type($sched['is_rest'], $hol);
    $isWorkday = !$sched['is_rest'] && !$sched['no_schedule'];
    $branch = $sched['branch_id'];
    $req = (int)$sched['required_min'];

    $webFlags = [];
    $source = null;
    if ($existing && $existing['manual_override']) {
        $p = ['in' => $existing['time_in'], 'bo1' => $existing['break_out'], 'bi1' => $existing['break_in'],
            'bo2' => $existing['break_out2'] ?? null, 'bi2' => $existing['break_in2'] ?? null, 'out' => $existing['time_out'],
            'oti' => $existing['ot_in'] ?? null, 'oto' => $existing['ot_out'] ?? null];
        $branch = $existing['branch_id'] ? (int)$existing['branch_id'] : $branch;
        $source = 'manual';
    } else {
        $col = dtr_collect_punches($empId, $sched, $date);
        $p = $col['p'];
        $webFlags = $col['web'];
        $source = $col['source'];
        if ($col['branch']) {
            $branch = $col['branch'];
        }
    }
    $tin = $p['in'];
    $tout = $p['out'];

    $leave = approved_leave_on($empId, $date);
    $d = [
        'employee_id' => $empId, 'work_date' => $date, 'branch_id' => $branch, 'shift_id' => $shift['id'] ?? null,
        'sched_in' => $sched['sched_in'], 'sched_out' => $sched['sched_out'],
        'time_in' => $tin, 'break_out' => $p['bo1'], 'break_in' => $p['bi1'], 'break_out2' => $p['bo2'], 'break_in2' => $p['bi2'], 'time_out' => $tout,
        'ot_in' => $p['oti'] ?? null, 'ot_out' => $p['oto'] ?? null, 'pre_ot_minutes' => 0, 'ot_late_minutes' => 0, 'ot_ut_minutes' => 0,
        'break_minutes' => 0, 'worked_minutes' => 0, 'regular_minutes' => 0, 'late_minutes' => 0, 'undertime_minutes' => 0,
        'ot_minutes' => 0, 'approved_ot_minutes' => 0, 'nd_minutes' => 0, 'nd_ot_minutes' => 0,
        'absent_minutes' => 0, 'leave_minutes' => 0, 'leave_bucket' => null, 'holiday_minutes' => 0, 'penalty_minutes' => 0, 'log_status' => null,
        'day_type' => $dayType, 'is_workday' => $isWorkday ? 1 : 0, 'status' => 'pending',
        'leave_application_id' => null, 'leave_paid' => 0, 'source' => $source,
    ];
    $ex = [];
    $isHolidayWorkday = $hol && !$sched['is_rest'];
    $holMinutes = function () use ($hol, $req, $c, $emp, $date) {
        if ($hol !== 'regular' && $hol !== 'double') {
            return 0;
        }
        if ($c['hol_absent_prior'] && absent_prior_workday($emp, $date)) {
            return -1; // not paid: absent on the prior working day
        }
        return $hol === 'double' ? $req * 2 : $req;
    };

    if (!$tin && !$tout) {
        if ($leave && ($isWorkday || $isHolidayWorkday) && !$hol) {
            $d['status'] = 'on_leave';
            $d['leave_application_id'] = $leave['id'];
            $d['leave_paid'] = (int)$leave['is_paid'];
            if ($leave['is_paid'] && ($leave['summary_bucket'] ?? 'ol') !== 'none') {
                $d['leave_minutes'] = $req;
                $d['leave_bucket'] = $leave['summary_bucket'] ?: 'ol';
            } else {
                $d['absent_minutes'] = $req;
            }
            $d['log_status'] = 'On Leave (' . $leave['code'] . ')';
        } elseif ($isWorkday && is_suspended_on($emp, $date)) {
            $d['status'] = 'suspended';
            $d['absent_minutes'] = $req;
            $d['log_status'] = 'Suspended';
        } elseif ($sched['no_schedule']) {
            $d['status'] = 'no_schedule';
            $d['log_status'] = 'No Schedule';
        } elseif ($sched['is_rest']) {
            $d['status'] = 'rest_day';
            $d['log_status'] = $hol ? 'Day Off (' . ($hol === 'special' ? 'Special' : 'Legal') . ' Holiday)' : 'Day Off';
        } elseif ($hol) {
            $d['status'] = 'holiday';
            $hm = $holMinutes();
            if ($hm < 0) {
                $d['absent_minutes'] = $req;
                $d['log_status'] = ($hol === 'special' ? 'Special' : 'Legal') . ' Holiday — absent prior day';
            } else {
                $d['holiday_minutes'] = $hm;
                $d['log_status'] = $hol === 'special' ? 'Special Holiday' : ($hol === 'double' ? 'Double Holiday' : 'Legal Holiday');
            }
        } elseif ($date > today()) {
            $d['status'] = 'pending';
        } else {
            $d['status'] = 'absent';
            $d['absent_minutes'] = $req;
            $d['log_status'] = 'Absent';
        }
    } elseif (!$tin || !$tout) {
        $ex[] = !$tin ? 'missing_in' : 'missing_out';
        $d['log_status'] = 'Incomplete';
        if ($isWorkday && $c['inc_absent']) {
            $d['status'] = 'absent';
            $d['absent_minutes'] = $req;
        } elseif ($isWorkday && $c['inc_ut'] && $sched['sched_in']) {
            // count what was rendered between the first and the last log; the rest is undertime
            $all = array_values(array_filter([$p['in'], $p['bo1'], $p['bi1'], $p['bo2'], $p['bi2'], $p['out']]));
            sort($all);
            $first = strtotime($all[0]);
            $last = strtotime(end($all));
            $si = strtotime($sched['sched_in']);
            $so = strtotime($sched['sched_out']);
            $reg = overlap_min($first, $last, $si, $so);
            foreach ($sched['breaks'] as $bk) {
                $reg -= overlap_min(max($first, $si), min($last, $so), $bk[0], $bk[1]);
            }
            $reg = max(0, min($reg, $req));
            $late = $tin ? max(0, (int)floor(($first - $si) / 60)) : 0;
            $d['status'] = 'present';
            $d['regular_minutes'] = $reg;
            $d['worked_minutes'] = $reg;
            $d['undertime_minutes'] = max(0, $req - $reg - $late) + $c['inc_penalty'];
            if ($late > 0 && $late <= dtr_grace_minutes($shift, $c)) {
                // v24: within the grace period = not late, and the minutes count as rendered
                $d['regular_minutes'] = $d['worked_minutes'] = min($req, $reg + $late);
                $d['undertime_minutes'] = max(0, $req - $d['regular_minutes']) + $c['inc_penalty'];
                $late = 0;
            }
            $d['late_minutes'] = $late;
            $d['penalty_minutes'] = $c['inc_penalty'];
        } else {
            $d['status'] = 'incomplete';
        }
    } else {
        $d['status'] = 'present';
        $in = strtotime($tin);
        $out = strtotime($tout);
        if ($out <= $in) {
            $d['status'] = 'incomplete';
            $d['log_status'] = 'Invalid logs';
            $ex[] = 'invalid_punch_order';
        } elseif (($out - $in) / 3600 > $c['invalid_hours']) {
            $d['status'] = 'incomplete';
            $d['log_status'] = 'Invalid (over ' . rtrim(rtrim(number_format($c['invalid_hours'], 2), '0'), '.') . ' hrs)';
            $ex[] = 'invalid_hours';
        } else {
            // breaks actually logged
            $actual = [];
            foreach ([['bo1', 'bi1'], ['bo2', 'bi2']] as $bp) {
                if ($p[$bp[0]] && $p[$bp[1]] && strtotime($p[$bp[1]]) > strtotime($p[$bp[0]])) {
                    $actual[] = [strtotime($p[$bp[0]]), strtotime($p[$bp[1]])];
                }
            }
            $schedBreaks = $sched['breaks'];
            $si = $sched['sched_in'] ? strtotime($sched['sched_in']) : null;
            $so = $sched['sched_out'] ? strtotime($sched['sched_out']) : null;
            // flexi time: the schedule follows the actual time-in within the flexible window
            if ($si && $sched['flex'] > 0 && !$sched['is_rest']) {
                $delta = max(-$sched['flex'] * 60, min($sched['flex'] * 60, $in - $si));
                $si += $delta;
                $so += $delta;
                $schedBreaks = array_map(fn($b) => [$b[0] + $delta, $b[1] + $delta], $schedBreaks);
            }
            if ($actual) {
                $deduct = $actual;
            } elseif ($c['duty_mode'] === 'pairs') {
                $deduct = [];
            } elseif ($schedBreaks) {
                $deduct = $schedBreaks;
            } elseif ($c['auto_break'] && ($out - $in) > 5 * 3600) {
                $bm = $shift ? (int)$shift['break_minutes'] : 60;
                $mid = $si ? ($si + $so) / 2 : ($in + $out) / 2;
                $deduct = [[(int)($mid - $bm * 30), (int)($mid + $bm * 30)]];
            } else {
                $deduct = [];
            }
            $breakMin = 0;
            foreach ($deduct as $bk) {
                $breakMin += overlap_min($in, $out, $bk[0], $bk[1]);
            }
            $d['break_minutes'] = $breakMin;
            $maxReg = $req;
            $noLate = ($hol && $c['nolate_holiday']) || ($leave && $c['nolate_leave']);
            if ($si && (!$sched['is_rest'] || !$c['nolate_restday'])) {
                // --- late & undertime
                $lateIn = max(0, (int)floor(($in - $si) / 60));
                $utOut = max(0, (int)floor(($so - $out) / 60));
                // v17: logs entirely outside the scheduled shift (time-in at or after its end, or time-out at or before
                // its start): the shift was not rendered. That is an absence of the shift, not a late of 13.90 hours.
                $offShift = $in >= $so || $out <= $si;
                if ($offShift) {
                    $lateIn = $utOut = 0;
                }
                $lateBreak = 0;
                $utBreak = 0;
                if ($c['late_mode'] === 'every' && $actual && $schedBreaks) {
                    foreach ($actual as $i => $ab) {
                        if (isset($schedBreaks[$i])) {
                            $lateBreak += max(0, (int)floor(($ab[1] - $schedBreaks[$i][1]) / 60));
                            $utBreak += max(0, (int)floor(($schedBreaks[$i][0] - $ab[0]) / 60));
                        }
                    }
                }
                if ($c['break_excess_late'] && $actual) {
                    $allowed = $schedBreaks ? array_sum(array_map(fn($b) => ($b[1] - $b[0]) / 60, $schedBreaks)) : (int)($shift['break_minutes'] ?? 60);
                    $taken = array_sum(array_map(fn($b) => ($b[1] - $b[0]) / 60, $actual));
                    $lateBreak = max($lateBreak, (int)floor($taken - $allowed));
                }
                $penalty = 0;
                if ($c['early_bi_penalty'] && $schedBreaks && $in >= $schedBreaks[0][1] - 20 * 60 && $in <= $schedBreaks[0][1]) {
                    $penalty = $c['early_bi_penalty'];
                }
                // --- regular hours inside the schedule
                $rs = max($in, $si);
                $re = min($out, $so);
                $reg = $re > $rs ? (int)floor(($re - $rs) / 60) : 0;
                foreach ($deduct as $bk) {
                    $reg -= overlap_min($rs, $re, $bk[0], $bk[1]);
                }
                // --- policy: No BREAK log on SHIFT w/ break as ABSENT (or as UNDERTIME).
                // The employee has to log every break of the shift. A work segment whose opening or closing
                // log is missing is not credited: on an 8:00-12:00 / 1:00-5:00 shift, logs 8:00 12:01 5:05
                // (no break in) make 1:00-5:00 absent, 4 hours; logs 7:57 5:15 (no break out, no break in)
                // make the whole 8 hours absent. The late / undertime of an uncredited segment is not added again.
                $missMin = 0;
                $nb = min(count($schedBreaks), max(0, intdiv(shift_logs_per_day($shift) - 2, 2)));
                if ($nb > 0 && !$sched['is_rest'] && ($c['nobreak_absent'] || $c['nobreak_ut'])) {
                    // duty is counted inside the credited segments only (the scheduled breaks are outside every
                    // segment; time taken beyond the break is taken out and shows as late / undertime)
                    $missing = [];
                    $reg = 0;
                    foreach (dtr_break_segments($p, $si, $so, array_slice($schedBreaks, 0, $nb)) as $i => $seg) {
                        if ($seg['ok']) {
                            $reg += overlap_min($rs, $re, $seg['from'], $seg['to']);
                            foreach ($actual as $ab) {
                                $reg -= overlap_min(max($rs, $seg['from']), min($re, $seg['to']), $ab[0], $ab[1]);
                            }
                            continue;
                        }
                        $missMin += $seg['minutes'];
                        $missing = array_merge($missing, $seg['missing']);
                        if ($i === 0) {
                            $lateIn = 0;
                        }
                        if ($seg['end'] === 'out') {
                            $utOut = 0;
                        }
                    }
                    if ($missMin > 0) {
                        $d['log_status'] = 'No ' . strtolower(implode(', ', array_unique($missing))) . ' log';
                    }
                }
                $reg = max(0, min($reg, $maxReg));
                $late = $lateIn + $lateBreak + $penalty;
                $ut = $utOut + $utBreak;
                $absentMin = ($offShift && !$sched['is_rest']) ? $req : 0;
                if ($offShift) {
                    $late = $ut = $penalty = 0;
                    $missMin = 0;
                    if (!$d['log_status']) {
                        $d['log_status'] = 'Logs outside the shift';
                    }
                }
                if ($missMin > 0) {
                    if ($c['nobreak_absent']) {
                        $absentMin += $missMin;
                    } else {
                        $ut += $missMin;
                    }
                }
                // --- policies: rendered hours below the shift hours (the unaccounted shortfall is absent or undertime)
                if (!$sched['is_rest']) {
                    $shortfall = max(0, $req - $reg - $late - $ut - $absentMin);
                    if ($shortfall > 0 && $c['short_absent']) {
                        $absentMin += $shortfall;
                    } elseif ($shortfall > 0 && $c['short_ut']) {
                        $ut += $shortfall;
                    }
                }
                $d['absent_minutes'] = $noLate ? 0 : min($req, $absentMin);
                if ($noLate) {
                    $late = $ut = 0;
                    $penalty = 0;
                }
                // v17: late and undertime never exceed the hours of the shift (nor what is left of them after the absence)
                $late = min($late, max(0, $req - $d['absent_minutes']));
                $ut = min($ut, max(0, $req - $d['absent_minutes'] - $late));
                $penalty = min($penalty, $late);
                // v24: tardiness within the grace period (AMS Policies & Configuration, or the grace of the shift) is
                // not late at all: no late minutes on the daily time record, the Attendance Entry or the summary. Beyond
                // the grace, the late stays whole here and "When late beyond grace, deduct" decides what is deducted.
                if ($late - $penalty > 0 && $late - $penalty <= dtr_grace_minutes($shift, $c)) {
                    $reg = min($maxReg, $reg + ($late - $penalty));   // the minutes of the grace count as rendered
                    $late = $penalty;
                }
                $d['late_minutes'] = $late;
                $d['undertime_minutes'] = $ut;
                $d['penalty_minutes'] = $penalty;
                // --- overtime and night premium
                $ot = ($d['status'] === 'present' && $out > $so) ? (int)floor(($out - max($so, $in)) / 60) : 0;
                $otWin = $sched['ot'] ?? null;
                if ($otWin && $ot > 0) {
                    // a shift with pre-arranged overtime: only the time between the end of the shift and Overtime In
                    // is ordinary overtime (it needs an overtime request); the pre-arranged part is credited below and
                    // the time after Overtime Out is not counted
                    $ot = $out > $so ? max(0, (int)floor((min($out, $otWin[0]) - max($so, $in)) / 60)) : 0;
                }
                if ($ot < $c['ot_min']) {
                    $ot = 0;
                }
                $d['regular_minutes'] = $reg;
                $d['ot_minutes'] = $ot;
                if ($otWin) {
                    $ndOt = $ot > 0 ? nd_minutes_in(max($so, $in), min($out, $otWin[0])) : 0;
                    $ndTotal = nd_minutes_in(max($in, $si), min($out, $so), $deduct) + $ndOt;
                } elseif ($c['np_from_logs']) {
                    $ndOt = $ot > 0 ? nd_minutes_in($out - $ot * 60, $out) : 0;
                    $ndTotal = nd_minutes_in($in, $out, $deduct);
                } else {
                    $ndOt = $ot > 0 ? nd_minutes_in(max($so, $in), $out) : 0;
                    $ndTotal = nd_minutes_in(max($in, $si), min($out, $so), $deduct) + $ndOt;
                }
            } else {
                $worked = (int)floor(($out - $in) / 60);
                foreach ($deduct as $bk) {
                    $worked -= overlap_min($in, $out, $bk[0], $bk[1]);
                }
                $worked = max(0, $worked);
                $reg = min($worked, $maxReg);
                $ot = $worked - $reg;
                if ($ot < $c['ot_min']) {
                    $ot = 0;
                }
                $d['regular_minutes'] = $reg;
                $d['ot_minutes'] = $ot;
                $ndTotal = nd_minutes_in($in, $out, $deduct);
                $ndOt = $ot > 0 ? nd_minutes_in($out - $ot * 60, $out) : 0;
                if ($sched['no_schedule']) {
                    $ex[] = 'no_schedule';
                }
            }
            $d['worked_minutes'] = $d['regular_minutes'] + $d['ot_minutes'];
            $d['nd_minutes'] = max(0, min($ndTotal - $ndOt, $d['regular_minutes']));
            if ($d['ot_minutes'] > 0) {
                $authorized = (float)val("SELECT COALESCE(SUM(hours),0) FROM overtime_requests WHERE employee_id = ? AND ot_date = ? AND status = 'approved'", [$empId, $date]) * 60;
                $approved = $c['ot_req'] ? (int)min($d['ot_minutes'], $authorized) : $d['ot_minutes'];
                if ($d['ot_minutes'] - $approved < $c['ot_min']) {
                    // excess below the minimum OT unit is simply not counted (no exception)
                    $d['ot_minutes'] = $approved;
                    $d['worked_minutes'] = $d['regular_minutes'] + $approved;
                }
                $d['approved_ot_minutes'] = $approved;
                $d['nd_ot_minutes'] = $d['ot_minutes'] > 0 ? (int)round($ndOt * $approved / $d['ot_minutes']) : 0;
                if ($approved < $d['ot_minutes']) {
                    $ex[] = 'unauthorized_ot';
                }
            }
            if ($leave) {
                $ex[] = 'leave_conflict';
            }
            // --- v16 pre-arranged overtime of the shift (approved automatically)
            if (!empty($sched['ot']) && $d['status'] === 'present') {
                $sepOt = !empty($p['oti']) || !empty($p['oto']);
                $po = dtr_pre_ot($sched['ot'], $p['oti'] ?? null, $p['oto'] ?? null, $sepOt ? null : $in, $sepOt ? null : $out);
                if ($po['missing']) {
                    $ex[] = 'missing_ot_log';
                } elseif ($po['minutes'] > 0 || $po['late'] || $po['ut']) {
                    $d['pre_ot_minutes'] = $po['minutes'];
                    $d['ot_late_minutes'] = $po['late'];
                    $d['ot_ut_minutes'] = $po['ut'];
                    $d['ot_minutes'] += $po['minutes'];
                    $d['approved_ot_minutes'] += $po['minutes'];
                    $d['nd_ot_minutes'] += $po['nd'];
                    $d['worked_minutes'] += $po['minutes'];
                }
            }
            if ($d['status'] === 'present' && !$d['log_status']) {
                if ($sched['is_rest']) {
                    $d['log_status'] = $hol ? 'Day Off (' . ($hol === 'special' ? 'Special' : 'Legal') . ' Holiday)' : 'Day Off';
                } elseif ($hol) {
                    $d['log_status'] = $hol === 'special' ? 'Special Holiday' : ($hol === 'double' ? 'Double Holiday' : 'Legal Holiday');
                }
            }
        }
    }
    foreach (array_keys($webFlags) as $wf) {
        $ex[] = $wf;
    }
    $exStr = $ex ? implode(',', array_unique($ex)) : null;
    $d['exceptions'] = $exStr;
    if (!$exStr) {
        $d['exception_status'] = 'none';
        $d['exception_note'] = null;
    } elseif ($existing && $existing['exception_status'] === 'resolved' && $existing['exceptions'] === $exStr) {
        $d['exception_status'] = 'resolved';
    } else {
        $d['exception_status'] = 'open';
    }
    $d['updated_at'] = now();
    $d['calc_version'] = DTR_CALC_VERSION;

    if ($existing) {
        unset($d['employee_id'], $d['work_date']);
        if ($existing['manual_override']) {
            unset($d['source']);
        }
        update('dtr', $d, 'id = ?', [$existing['id']]);
        $d['id'] = $existing['id'];
        $d['employee_id'] = $empId;
        $d['work_date'] = $date;
    } else {
        $d['id'] = insert('dtr', $d);
    }
    return $d;
}

/**
 * Version of the daily computation. Raised when the way the logs are read changes (version 4:
 * positional placement of In / Break / Out by the shift's logs per day, and the same-type
 * interval policy), so records computed by an older version are rebuilt the first time they are
 * shown or summarized. Locked (approved) days are never touched.
 */
const DTR_CALC_VERSION = 10;   // 10 (v24): a late within the grace period is not late; 9 (v22): a lone terminal punch placed by the schedule; 6 (v16): pre-arranged overtime; 7 (v17): logs outside the shift, late capped at the shift hours; 8 (v18): day-off of the shift / masterfile on shift-only overrides

/** Rebuild the unlocked days of one employee computed by an older version of the engine. Returns the number rebuilt. */
function dtr_refresh_stale(int $empId, string $from, string $to): int
{
    $emp = att_employee($empId);
    if (!$emp) {
        return 0;
    }
    $n = 0;
    foreach (rows('SELECT work_date FROM dtr WHERE employee_id = ? AND work_date BETWEEN ? AND ? AND calc_version < ?', [$empId, $from, $to, DTR_CALC_VERSION]) as $r) {
        if (!dtr_locked($emp, $r['work_date']) && process_dtr($empId, $r['work_date'])) {
            $n++;
        }
    }
    return $n;
}

/** Re-process DTR for employees (null = all active) over a date range. Returns number of rows processed. */
function process_dtr_range(?array $empIds, string $from, string $to, bool $force = false): int
{
    if ($empIds === null) {
        $empIds = array_map('intval', q("SELECT id FROM employees WHERE status = 'active' OR date_separated >= ?", [$from])->fetchAll(PDO::FETCH_COLUMN));
    }
    $to = min($to, today() > $to ? $to : today());
    $n = 0;
    foreach ($empIds as $id) {
        foreach (date_range($from, $to) as $d) {
            if (process_dtr((int)$id, $d, $force)) {
                $n++;
            }
        }
    }
    return $n;
}

/** Employees belonging to a pay group and employed at some point in the period. */
function pay_group_employees(int $payGroupId, string $from, string $to, bool $includeExempt = true): array
{
    return rows("SELECT * FROM employees WHERE pay_group_id = ? AND date_hired <= ? AND (date_separated IS NULL OR date_separated >= ?)"
        . ($includeExempt ? '' : ' AND timekeeping_exempt = 0') . ' ORDER BY last_name, first_name', [$payGroupId, $to, $from]);
}

/** Hours of the attendance-transaction columns of one DTR row (day type -> columns). */
function dtr_hour_buckets(array $r, bool $monthlyRated): array
{
    $H = fn($m) => (int)$m / 60;
    $o = [];
    $add = function (string $k, float $v) use (&$o) {
        if ($v != 0.0) {
            $o[$k] = ($o[$k] ?? 0) + $v;
        }
    };
    $shift = shift_row($r['shift_id'] ? (int)$r['shift_id'] : null);
    $reqMin = ($shift && $shift['required_hours'] !== null && (float)$shift['required_hours'] > 0) ? (float)$shift['required_hours'] * 60 : att_cfg()['hpd'] * 60;
    $status = $r['status'];
    if ($status === 'pending') {
        return $o;
    }
    $reg = $H($r['regular_minutes']);
    $ot = $H($r['approved_ot_minutes']);
    $nd = $H($r['nd_minutes']);
    $ndot = $H($r['nd_ot_minutes']);
    $isHoliday = in_array($r['day_type'], ['regular_holiday', 'double_holiday', 'special_holiday'], true);
    if (!$isHoliday || $monthlyRated) {
        // daily-rated employees are simply not paid for an unpaid holiday; there is nothing to deduct
        $add('absent_hrs', $H($r['absent_minutes'] ?? 0));
    }
    if ((int)($r['leave_minutes'] ?? 0) > 0 && !empty($r['leave_bucket']) && $r['leave_bucket'] !== 'none') {
        $add($r['leave_bucket'] . '_hrs', $H($r['leave_minutes']));
    }
    switch ($r['day_type']) {
        case 'ordinary':
            if ($r['is_workday']) {
                if (!($status === 'on_leave' && (int)($r['leave_minutes'] ?? 0) > 0)) {
                    $add('hours_work', $reqMin / 60);
                }
                if ($status === 'incomplete') {
                    // unresolved incomplete / invalid day: whatever was not rendered is absent
                    $add('absent_hrs', max(0, $reqMin / 60 - $reg));
                }
            } elseif ($reg > 0) {
                $add('hours_work', $reg);
            }
            $add('ot_reg', $ot);
            $add('np_reg', $nd);
            $add('np_reg_ot', $ndot);
            break;
        case 'rest_day':
            $add('rd_hrs', $reg);
            $add('rd_ot', $ot);
            $add('np_rd', $nd);
            $add('np_rd_ot', $ndot);
            break;
        case 'regular_holiday':
        case 'double_holiday':
            $add('lh_hrs', $reg);
            $add('lh_ot', $ot);
            $add('np_lh', $nd);
            $add('np_lh_ot', $ndot);
            $add('holiday_hrs', $H($r['holiday_minutes'] ?? 0));
            break;
        case 'regular_rest':
            $add('lh_rd', $reg);
            $add('lh_rd_ot', $ot);
            $add('np_lh_rd', $nd);
            $add('np_lh_rd_ot', $ndot);
            break;
        case 'special_holiday':
            $add('sh_hrs', $reg);
            $add('sh_ot', $ot);
            $add('np_sh', $nd);
            $add('np_sh_ot', $ndot);
            break;
        case 'special_rest':
            $add('sh_rd', $reg);
            $add('sh_rd_ot', $ot);
            $add('np_sh_rd', $nd);
            $add('np_sh_rd_ot', $ndot);
            break;
    }
    return $o;
}

/**
 * Aggregate DTR rows of one employee over a period into the payroll-ready attendance transaction.
 * Returns ['header' => [...legacy day/minute totals + hour columns...], 'lines' => [...], 'branches' => [branch_id => hour columns]]
 */
function attendance_summary_data(array $emp, string $from, string $to): array
{
    $c = att_cfg();
    $grace = $c['grace'];
    $monthly = ($emp['rate_type'] ?? 'monthly') === 'monthly';
    $dtrs = rows('SELECT * FROM dtr WHERE employee_id = ? AND work_date BETWEEN ? AND ? ORDER BY work_date', [$emp['id'], $from, $to]);
    $h = [
        'scheduled_days' => 0, 'days_worked' => 0, 'absent_days' => 0, 'leave_paid_days' => 0, 'leave_unpaid_days' => 0,
        'suspended_days' => 0, 'reg_holiday_unworked' => 0, 'spl_holiday_unworked' => 0, 'late_minutes' => 0, 'late_count' => 0,
        'undertime_minutes' => 0, 'reg_minutes' => 0, 'ot_minutes' => 0, 'unauthorized_ot_minutes' => 0, 'nd_minutes' => 0,
        'open_exceptions' => 0,
    ];
    foreach (pt_all_fields() as $f) {
        $h[$f] = 0;
    }
    $lines = [];
    $branches = [];
    $lateRaw = [];   // [date => [late, ut, strict]] for the tardiness lookup policy
    foreach ($dtrs as $r) {
        if ($r['is_workday']) {
            $h['scheduled_days']++;
        }
        if ($r['exception_status'] === 'open') {
            $h['open_exceptions']++;
        }
        switch ($r['status']) {
            case 'absent': $h['absent_days']++; break;
            case 'on_leave': $r['leave_paid'] ? $h['leave_paid_days']++ : $h['leave_unpaid_days']++; break;
            case 'suspended': $h['suspended_days']++; break;
            case 'holiday':
                if (in_array($r['day_type'], ['regular_holiday', 'double_holiday'], true)) {
                    $h['reg_holiday_unworked'] += $r['day_type'] === 'double_holiday' ? 2 : 1;
                } else {
                    $h['spl_holiday_unworked']++;
                }
                break;
        }
        $branch = (int)($r['branch_id'] ?: $emp['default_branch_id']);
        foreach (dtr_hour_buckets($r, $monthly) as $k => $v) {
            $h[$k] += $v;
            $branches[$branch][$k] = ($branches[$branch][$k] ?? 0) + $v;
        }
        // lates & undertime: ordinary days, plus holidays on workdays for monthly-rated employees
        $countsLate = $r['day_type'] === 'ordinary' || ($monthly && in_array($r['day_type'], ['regular_holiday', 'special_holiday', 'double_holiday'], true) && $r['status'] === 'present');
        if ($countsLate && in_array($r['status'], ['present'], true)) {
            $shift = shift_row($r['shift_id'] ? (int)$r['shift_id'] : null);
            $lateRaw[$r['work_date']] = [(int)$r['late_minutes'], (int)$r['undertime_minutes'], $branch,
                $shift && !empty($shift['strict_time']) ? 0 : ($shift && $shift['grace_minutes'] !== null ? (int)$shift['grace_minutes'] : null)];
            if ((int)$r['late_minutes'] > ($shift && !empty($shift['strict_time']) ? 0 : $grace)) {
                $h['late_count']++;
            }
        }
        if (!in_array($r['status'], ['present'], true)) {
            continue;
        }
        $k = $branch . '|' . $r['day_type'];
        if (!isset($lines[$k])) {
            $lines[$k] = ['branch_id' => $branch, 'day_type' => $r['day_type'], 'days' => 0, 'reg_minutes' => 0, 'ot_minutes' => 0,
                'nd_minutes' => 0, 'nd_ot_minutes' => 0, 'late_minutes' => 0, 'undertime_minutes' => 0];
        }
        $L = &$lines[$k];
        $L['days']++;
        $L['reg_minutes'] += (int)$r['regular_minutes'];
        $L['ot_minutes'] += (int)$r['approved_ot_minutes'];
        $L['nd_minutes'] += (int)$r['nd_minutes'];
        $L['nd_ot_minutes'] += (int)$r['nd_ot_minutes'];
        if ($r['day_type'] === 'ordinary') {
            $h['days_worked']++;
        }
        unset($L);
        $h['reg_minutes'] += (int)$r['regular_minutes'];
        $h['ot_minutes'] += (int)$r['approved_ot_minutes'];
        $h['unauthorized_ot_minutes'] += max(0, (int)$r['ot_minutes'] - (int)$r['approved_ot_minutes']);
        $h['nd_minutes'] += (int)$r['nd_minutes'] + (int)$r['nd_ot_minutes'];
    }
    // deductible tardiness per the late-table lookup / tardiness policy (E/D = per day, W = per week, P = per period)
    $mode = $c['tardy_period'] || $c['late_lookup'] === 'P' ? 'P' : ($c['tardy_week'] ? 'W' : 'D');
    $groups = [];
    foreach ($lateRaw as $date => $lr) {
        $key = $mode === 'P' ? 'all' : ($mode === 'W' ? date('o-W', strtotime($date)) : $date);
        $groups[$key][] = $lr;
    }
    foreach ($groups as $items) {
        $late = array_sum(array_column($items, 0));
        $ut = array_sum(array_column($items, 1));
        $graceO = $items[0][3];
        $dl = deductible_late_minutes($late, false, $graceO);
        $du = deductible_late_minutes($ut, true);
        $h['late_minutes'] += $dl;
        $h['undertime_minutes'] += $du;
        // attribute to branches in proportion to raw minutes
        foreach ($items as $it) {
            $b = $it[2];
            $shareL = $late > 0 ? $it[0] / $late : 0;
            $shareU = $ut > 0 ? $it[1] / $ut : 0;
            $branches[$b]['late_hrs'] = ($branches[$b]['late_hrs'] ?? 0) + $dl * $shareL / 60;
            $branches[$b]['undertime_hrs'] = ($branches[$b]['undertime_hrs'] ?? 0) + $du * $shareU / 60;
            $bk = $b . '|ordinary';
            if (isset($lines[$bk])) {
                $lines[$bk]['late_minutes'] += (int)round($dl * $shareL);
                $lines[$bk]['undertime_minutes'] += (int)round($du * $shareU);
            }
        }
    }
    $h['late_hrs'] = $h['late_minutes'] / 60;
    $h['undertime_hrs'] = $h['undertime_minutes'] / 60;
    foreach (pt_all_fields() as $f) {
        $h[$f] = round((float)$h[$f], 2);
    }
    foreach ($branches as $b => $vals) {
        foreach ($vals as $k => $v) {
            $branches[$b][$k] = round((float)$v, 2);
        }
    }
    // tardiness monitoring (calendar month of period end)
    $flags = [];
    $mStart = date('Y-m-01', strtotime($to));
    $mon = row("SELECT COUNT(*) AS cnt, COALESCE(SUM(late_minutes),0) AS mins FROM dtr
                WHERE employee_id = ? AND work_date BETWEEN ? AND ? AND day_type = 'ordinary' AND status = 'present' AND late_minutes > ?",
        [$emp['id'], $mStart, $to, $grace]);
    if ((int)$mon['cnt'] >= (int)setting('habitual_tardy_count', 5)) {
        $flags[] = 'habitual_tardiness';
    }
    if ((int)$mon['mins'] >= (int)setting('tardy_cap_minutes', 120)) {
        $flags[] = 'tardiness_cap';
    }
    if ($h['unauthorized_ot_minutes'] > 0) {
        $flags[] = 'unauthorized_ot';
    }
    $h['flags'] = $flags ? implode(',', $flags) : null;
    return ['header' => $h, 'lines' => array_values($lines), 'branches' => $branches];
}

/**
 * Process DTRs and build the attendance transactions of a cutoff (Auto-Generate Attendance Transaction).
 * Manually encoded transactions are kept unless $overwriteManual is true.
 */
function summarize_cutoff(int $cutoffId, bool $overwriteManual = false, ?array $onlyEmpIds = null): array
{
    $cut = row('SELECT * FROM attendance_cutoffs WHERE id = ?', [$cutoffId]);
    if (!$cut) {
        throw new RuntimeException('Cutoff / period not found.');
    }
    if ($cut['status'] === 'locked') {
        throw new RuntimeException('The payroll of this period is closed and paid (Period Closing).');
    }
    // v19: the closed transactions (a company / branch already closed) are kept; the others are summarized
    $emps = pay_group_employees((int)$cut['pay_group_id'], $cut['period_start'], $cut['period_end'], false);
    if ($onlyEmpIds !== null) {
        $emps = array_values(array_filter($emps, fn($e) => in_array((int)$e['id'], $onlyEmpIds, true)));
    }
    $ids = array_map(fn($e) => (int)$e['id'], $emps);
    process_dtr_range($ids, $cut['period_start'], $cut['period_end']);
    $count = 0;
    $kept = 0;
    tx(function () use ($cut, $emps, $overwriteManual, &$count, &$kept) {
        foreach ($emps as $e) {
            $old = row('SELECT id, source, deduction_branch_id, is_closed FROM attendance_summaries WHERE cutoff_id = ? AND employee_id = ?', [$cut['id'], $e['id']]);
            if ($old && $old['is_closed']) {
                // closed transactions belong to payroll: processing the attendance never overwrites them
                $kept++;
                continue;
            }
            if ($old && $old['source'] === 'manual' && !$overwriteManual) {
                $kept++;
                continue;
            }
            $s = attendance_summary_data($e, $cut['period_start'], $cut['period_end']);
            if ($old) {
                q('DELETE FROM attendance_summaries WHERE id = ?', [$old['id']]);
            }
            $sid = insert('attendance_summaries', array_merge(['cutoff_id' => $cut['id'], 'employee_id' => $e['id'], 'source' => 'ams', 'updated_at' => now(),
                'deduction_branch_id' => $old['deduction_branch_id'] ?? null], $s['header']));   // the Set Deduction Source survives a re-summary
            // A restored or re-imported database can leave detail rows behind; clear them so the id is clean.
            q('DELETE FROM attendance_summary_lines WHERE summary_id = ?', [$sid]);
            q('DELETE FROM attendance_summary_branches WHERE summary_id = ?', [$sid]);
            foreach ($s['lines'] as $l) {
                insert('attendance_summary_lines', array_merge(['summary_id' => $sid], $l));
            }
            if (count($s['branches']) > 1 || ($s['branches'] && (int)array_key_first($s['branches']) !== (int)$e['default_branch_id'])) {
                foreach ($s['branches'] as $b => $vals) {
                    $vals = array_intersect_key($vals, array_flip(pt_location_fields()));
                    insert('attendance_summary_branches', array_merge(['summary_id' => $sid, 'branch_id' => $b], $vals));
                }
            }
            $count++;
        }
        update('attendance_cutoffs', ['status' => 'summarized', 'summarized_at' => now()], 'id = ?', [$cut['id']]);
        att_cutoff_sync_status((int)$cut['id']);   // every transaction closed: the period stays closed for payroll
    });
    audit('ams', 'summarize_cutoff', 'attendance_cutoffs', $cutoffId, ['employees' => $count, 'manual_kept' => $kept]);
    return ['employees' => $count, 'manual_kept' => $kept, 'open_exceptions' => (int)val('SELECT COALESCE(SUM(open_exceptions),0) FROM attendance_summaries WHERE cutoff_id = ?', [$cutoffId])];
}

function approve_cutoff(int $cutoffId, bool $force = false): void
{
    $cut = row('SELECT * FROM attendance_cutoffs WHERE id = ?', [$cutoffId]);
    if (!$cut || !in_array($cut['status'], ['summarized', 'open'], true)) {
        throw new RuntimeException('Only an open or summarized period can be approved.');
    }
    if (!(int)val('SELECT COUNT(*) FROM attendance_summaries WHERE cutoff_id = ?', [$cutoffId])) {
        throw new RuntimeException('There are no attendance transactions in this period yet. Generate them from the DTR or encode them first.');
    }
    $open = (int)val('SELECT COALESCE(SUM(open_exceptions),0) FROM attendance_summaries WHERE cutoff_id = ?', [$cutoffId]);
    if ($open > 0 && !$force) {
        throw new RuntimeException("There are $open unresolved attendance exception(s). Resolve them in the DTR, or approve with \"exceptions reviewed\" to accept the figures as encoded.");
    }
    // closing the whole period closes every attendance transaction in it
    q('UPDATE attendance_summaries SET is_closed = 1, closed_at = ?, closed_by = ? WHERE cutoff_id = ? AND is_closed = 0', [now(), user_id(), $cutoffId]);
    update('attendance_cutoffs', ['status' => 'approved', 'approved_by' => user_id(), 'approved_at' => now()], 'id = ?', [$cutoffId]);
    audit('ams', 'approve_cutoff', 'attendance_cutoffs', $cutoffId, $open > 0 ? ['exceptions_accepted' => $open] : null);
}

/** Choose the nearest branch geofence for a GPS point. */
function nearest_branch(float $lat, float $lng): ?array
{
    $best = null;
    foreach (rows('SELECT * FROM branches WHERE is_active = 1 AND latitude IS NOT NULL AND longitude IS NOT NULL') as $b) {
        $dist = haversine_m($lat, $lng, (float)$b['latitude'], (float)$b['longitude']);
        if (!$best || $dist < $best['distance']) {
            $best = ['branch' => $b, 'distance' => $dist];
        }
    }
    return $best;
}

/**
 * Record a web / mobile check-in with GPS and optional selfie.
 * Returns ['ok' => bool, 'message' => string].
 */
function web_punch(int $empId, string $type, ?float $lat, ?float $lng, ?float $acc, ?string $photoDataUrl, ?bool $faceDetected): array
{
    $emp = att_employee($empId);
    if (!$emp || $emp['status'] !== 'active') {
        return ['ok' => false, 'message' => 'Employee record is not active.'];
    }
    if (!in_array($emp['attendance_method'], ['web', 'both'], true)) {
        return ['ok' => false, 'message' => 'Web check-in is not enabled for your account. Please use the biometric device.'];
    }
    if (!in_array($type, ['in', 'out', 'break_out', 'break_in'], true)) {
        return ['ok' => false, 'message' => 'Invalid punch type.'];
    }
    if ($lat === null || $lng === null) {
        return ['ok' => false, 'message' => 'Location is required. Please allow GPS / location access.'];
    }
    $interval = (int)setting('punch_min_interval', 2);
    $recent = val("SELECT COUNT(*) FROM attendance_logs WHERE employee_id = ? AND punch_type = ? AND log_time >= ?",
        [$empId, $type, date('Y-m-d H:i:s', time() - $interval * 60)]);
    if ($recent) {
        return ['ok' => false, 'message' => "You already recorded this punch within the last $interval minute(s)."];
    }
    $photo = null;
    if ($photoDataUrl) {
        $photo = save_data_url_image($photoDataUrl, 'selfies');
    }
    if (setting('selfie_required', '1') === '1' && !$photo) {
        return ['ok' => false, 'message' => 'A live selfie capture is required for web check-in.'];
    }
    $near = nearest_branch($lat, $lng);
    $within = null;
    $dist = null;
    $branchId = (int)$emp['default_branch_id'];
    if ($near) {
        $dist = round($near['distance'], 1);
        $within = $near['distance'] <= (float)$near['branch']['geofence_radius_m'] ? 1 : 0;
        if ($within) {
            $branchId = (int)$near['branch']['id'];
        }
    }
    if ($within === 0 && setting('geofence_mode', 'flag') === 'reject') {
        return ['ok' => false, 'message' => sprintf('You are %s m away from the nearest authorized work site (%s). Check-in rejected.', number_format((float)$dist), $near['branch']['name'])];
    }
    $verify = ($within === 1 && ($faceDetected === true || !$photo)) ? 'auto' : 'pending';
    $now = now();
    insert('attendance_logs', [
        'employee_id' => $empId, 'bio_id' => $emp['biometric_id'], 'log_time' => $now, 'punch_type' => $type, 'source' => 'web',
        'branch_id' => $branchId, 'latitude' => $lat, 'longitude' => $lng, 'accuracy_m' => $acc, 'distance_m' => $dist,
        'within_geofence' => $within, 'photo_path' => $photo, 'face_detected' => $faceDetected === null ? null : ($faceDetected ? 1 : 0),
        'verify_status' => $verify, 'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
    process_dtr($empId, date('Y-m-d'));
    process_dtr($empId, add_days(date('Y-m-d'), -1));
    $msg = 'Recorded ' . label($type) . ' at ' . date('h:i A');
    if ($near) {
        $msg .= $within ? ' — ' . $near['branch']['name'] . ' (within geofence).' : sprintf(' — outside geofence (%s m from %s); flagged for HR review.', number_format((float)$dist), $near['branch']['name']);
    }
    return ['ok' => true, 'message' => $msg];
}

function exception_label(string $code): string
{
    return [
        'missing_in' => 'Missing time-in', 'missing_out' => 'Missing time-out', 'unauthorized_ot' => 'Unauthorized overtime', 'missing_ot_log' => 'Missing Overtime In / Overtime Out log',
        'no_schedule' => 'No shift schedule', 'leave_conflict' => 'Present while on approved leave', 'outside_geofence' => 'Web check-in outside geofence',
        'unverified_web' => 'Web check-in pending verification', 'invalid_punch_order' => 'Time-out earlier than time-in',
        'invalid_hours' => 'Hours worked exceed the valid maximum',
        'habitual_tardiness' => 'Habitual tardiness', 'tardiness_cap' => 'Monthly tardiness cap exceeded',
    ][$code] ?? label($code);
}
