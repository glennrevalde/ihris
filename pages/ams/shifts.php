<?php
/**
 * Shift Table: time in / out, up to two breaks, required duty hours, night-premium hours, flexi time and rest days.
 * v16: Overtime In / Overtime Out = pre-arranged overtime after the shift (e.g. 7:00 PM to 9:30 PM = 2.5 hours),
 * credited automatically without an overtime request: minutes late on Overtime In and undertime on Overtime Out
 * are taken out, and nothing beyond Overtime Out is counted. Works on 2-log, 4-log and 6-log shifts.
 * v25: Shift type. A flexible shift has no set time in or time out: the total required duty hours are the basis of
 * the computation, a day below them is undertime and no late is computed (4 logs with the break as logged, 2 logs
 * with the break minutes allocated, or 2 logs without a break). The fields of a fixed schedule are switched off.
 */
$days = ['0' => 'Sun', '1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat'];
$hm = fn($v) => $v ? substr((string)$v, 0, 5) : '';
crud_page([
    'page' => 'shifts', 'import' => 'shifts', 'table' => 'shifts', 'title' => 'Shift Table', 'singular' => 'shift', 'module' => 'ams', 'set_default' => true,
    'subtitle' => 'Fixed shifts with a start, an end and up to two breaks (2-log, 4-log or 6-log days), or flexible shifts that only require a number of duty hours. Lates, undertime, overtime and night premium are computed against the assigned shift.',
    'actions' => ' <a class="btn" href="' . url('schedules') . '">Employee shift entry</a> <a class="btn" href="' . url('batch_shift') . '">Batch shift define</a>',
    'order' => 'code',
    'fields' => [
        'code' => ['label' => 'Code', 'required' => true],
        'name' => ['label' => 'Description', 'required' => true],
        'flex_type' => ['label' => 'Shift type', 'type' => 'select', 'options' => SHIFT_FLEX_TYPES, 'placeholder' => null, 'wide' => true, 'attrs' => 'data-shift-type',
            'help' => 'Flexible: no specific time required. The total required duty hours are the basis of the computation; a day below them is undertime and no late is computed.'],
        'time_in' => ['label' => 'Time in', 'type' => 'time', 'attrs' => 'data-flex-label="Usual time in (optional)" data-flex-help="Optional on a flexible shift: the usual time in, used only to keep the logs of a night worker on the day they started (no late is computed)."'],
        'break_out1' => ['label' => 'Break out 1', 'type' => 'time', 'attrs' => 'data-fixed-only'],
        'break_in1' => ['label' => 'Break in 1', 'type' => 'time', 'attrs' => 'data-fixed-only'],
        'break_out2' => ['label' => 'Break out 2', 'type' => 'time', 'help' => '6-log days only', 'attrs' => 'data-fixed-only'],
        'break_in2' => ['label' => 'Break in 2', 'type' => 'time', 'attrs' => 'data-fixed-only'],
        'time_out' => ['label' => 'Time out', 'type' => 'time', 'help' => 'Earlier than time-in = overnight shift. Required on a fixed shift.', 'attrs' => 'data-fixed-only'],
        'ot_in' => ['label' => 'Overtime In', 'type' => 'time', 'help' => 'Pre-arranged overtime after the shift, e.g. 7:00 PM. Blank = none.', 'attrs' => 'data-fixed-only'],
        'ot_out' => ['label' => 'Overtime Out', 'type' => 'time', 'help' => 'End of the pre-arranged overtime, e.g. 9:30 PM (2.5 hours). Approved automatically; late on Overtime In or out before Overtime Out is deducted, time after Overtime Out is not counted.', 'attrs' => 'data-fixed-only'],
        'logs_per_day' => ['label' => 'Logs per day (shift code)', 'type' => 'select', 'options' => ['0' => 'Auto — from the break times', '2' => '2 logs: Time in, Time out', '4' => '4 logs: Time in, Break out 1, Break in 1, Time out', '6' => '6 logs: Time in, Break out 1, Break in 1, Break out 2, Break in 2, Time out'], 'placeholder' => null,
            'help' => 'Decides which log columns are filled for employees on this shift in Attendance Entry and the import review. A flexible shift has 4 or 2 logs by its type.', 'attrs' => 'data-fixed-only'],
        'required_hours' => ['label' => 'Total required duty hours', 'type' => 'number', 'step' => '0.01', 'help' => 'Blank = computed from the shift. Required on a flexible shift (e.g. 8).'],
        'np_hours' => ['label' => 'Total night-premium hours', 'type' => 'number', 'step' => '0.01', 'help' => 'Blank = computed from the night window', 'attrs' => 'data-fixed-only'],
        'break_minutes' => ['label' => 'Break allowance (minutes)', 'type' => 'number', 'default' => 60, 'help' => 'Fixed shift: used when no break times are set. Flexible, 2 logs with break: the minutes allocated. Flexible, 4 logs: used when the break logs are missing.',
            'attrs' => 'data-flex3-off'],
        'flex_minutes' => ['label' => 'Flexi time +/- (minutes)', 'type' => 'number', 'default' => 0, 'help' => 'Time-in within this window is not late; the day shifts accordingly.', 'attrs' => 'data-fixed-only'],
        'grace_minutes' => ['label' => 'Grace period override (minutes)', 'type' => 'number', 'help' => 'Blank = global setting', 'attrs' => 'data-fixed-only'],
        'rest_days' => ['label' => 'Rest days', 'type' => 'multicheck', 'options' => $days, 'help' => 'None ticked = a 7-day shift: each employee rests on the default day-off of the Employee Masterfile.'],
        'strict_time' => ['label' => '(!) Strict time — no grace period on this shift', 'type' => 'checkbox', 'attrs' => 'data-fixed-only'],
        'whole_week' => ['label' => '(www) Whole-week duty (7 days) — rest days come from the employee\'s default day-off and the Schedule Overrides', 'type' => 'checkbox'],
        'is_active' => ['label' => 'Active', 'type' => 'checkbox'],
    ],
    'defaults' => ['break_minutes' => 60, 'flex_minutes' => 0, 'rest_days' => '0', 'is_active' => 1, 'logs_per_day' => 0, 'flex_type' => 0],
    'format' => [
        'code' => fn($v, $r) => shift_code_html($r),   // v18: the complete schedule when pointed at
        'rest_days' => fn($v, $r) => shift_is_7day($r) ? '<span class="muted">Employee day-off</span>' : h(implode(', ', array_map(fn($d) => $days[$d] ?? $d, array_filter(explode(',', (string)$v), fn($x) => $x !== '')))),
        'name' => fn($v, $r) => h($v) . ' <span class="badge badge-gray">' . (int)val("SELECT COUNT(*) FROM employees WHERE shift_id = ? AND status = 'active'", [$r['id']]) . ' emp.</span>',
        'time_in' => fn($v, $r) => shift_flex_type($r) ? '<span class="muted">Flexible</span>' . ($v ? '<br><small class="muted">usually ' . h($hm($v)) . '</small>' : '') : h($hm($v)),
        'time_out' => fn($v, $r) => shift_flex_type($r) ? '<span class="muted">Flexible</span>' : h($hm($v)),
        'flex_type' => fn($v, $r) => shift_flex_type($r) ? h(SHIFT_FLEX_SHORT[shift_flex_type($r)]) : 'Fixed',
        'break_out1' => fn($v) => h($hm($v)), 'break_in1' => fn($v) => h($hm($v)), 'break_out2' => fn($v) => h($hm($v)), 'break_in2' => fn($v) => h($hm($v)),
        'strict_time' => fn($v) => $v ? '<span class="badge badge-amber">!</span>' : '', 'whole_week' => fn($v) => $v ? '<span class="badge badge-blue">www</span>' : '',
        'flex_minutes' => fn($v) => (int)$v ? '±' . (int)$v : '',
        'logs_per_day' => fn($v, $r) => (int)$v ?: (!empty($r['break_out2']) ? 6 : 4),
        'ot_in' => fn($v, $r) => $v && $r['ot_out'] ? h($hm($v)) . '–' . h($hm($r['ot_out'])) . '<br><small class="muted">' . number_format(shift_ot_hours($r), 2) . ' hrs OT</small>' : '',
    ],
    'list' => ['code', 'name', 'flex_type', 'time_in', 'break_out1', 'break_in1', 'break_out2', 'break_in2', 'time_out', 'ot_in', 'logs_per_day', 'required_hours', 'np_hours', 'flex_minutes', 'strict_time', 'whole_week', 'rest_days', 'is_active'],
    'validate' => function ($d) {
        $e = [];
        $fx = (int)($d['flex_type'] ?? 0);
        if ($fx) {
            // flexible shift: the required duty hours are the basis; the times of a fixed schedule do not apply
            if ((float)($d['required_hours'] ?? 0) <= 0) {
                $e[] = 'A flexible shift needs the Total required duty hours (e.g. 8).';
            } elseif ((float)$d['required_hours'] > 24) {
                $e[] = 'The Total required duty hours cannot be more than 24.';
            }
            if ($fx === 2 && (int)($d['break_minutes'] ?? 0) <= 0) {
                $e[] = 'Enter the break minutes allocated (Break allowance, e.g. 60) of a flexible 2-log shift with break.';
            }
            return $e;
        }
        if (empty($d['time_in']) || empty($d['time_out'])) {
            $e[] = 'A fixed shift needs its Time in and Time out (or choose a flexible shift type).';
            return $e;
        }
        foreach ([['break_out1', 'break_in1'], ['break_out2', 'break_in2']] as $p) {
            if (($d[$p[0]] === null) !== ($d[$p[1]] === null)) {
                $e[] = 'Enter both the break-out and the break-in time of a break.';
            }
        }
        if ($d['break_out2'] !== null && $d['break_out1'] === null) {
            $e[] = 'Define break 1 before break 2.';
        }
        if ((int)($d['logs_per_day'] ?? 0) === 6 && $d['break_out2'] === null) {
            $e[] = 'A 6-log shift needs the second break (Break out 2 / Break in 2).';
        }
        // pre-arranged overtime: both times, after the end of the shift
        if (($d['ot_in'] === null) !== ($d['ot_out'] === null)) {
            $e[] = 'Enter both Overtime In and Overtime Out (or leave both blank).';
        } elseif ($d['ot_in'] !== null && !empty($d['time_in']) && !empty($d['time_out'])) {
            $ref = '2026-01-05';
            $tin = strtotime("$ref {$d['time_in']}");
            $tout = strtotime("$ref {$d['time_out']}");
            if ($tout <= $tin) {
                $tout += 86400;
            }
            $w = shift_ot_window(['time_in' => $d['time_in'], 'ot_in' => $d['ot_in'], 'ot_out' => $d['ot_out']], $ref);
            if ($w && $w[0] < $tout) {
                $e[] = 'Overtime In must be at or after the Time out of the shift (the pre-arranged overtime follows the shift).';
            } elseif ($w && $w[1] - $w[0] > 12 * 3600) {
                $e[] = 'The pre-arranged overtime cannot be longer than 12 hours.';
            }
        }
        return $e;
    },
    'after_save' => function ($id) {
        $s = row('SELECT * FROM shifts WHERE id = ?', [$id]);
        $fx = shift_flex_type($s);
        if ($fx) {
            // a flexible shift keeps only its type, required hours, break minutes, usual time in and rest days
            update('shifts', ['time_out' => null, 'break_out1' => null, 'break_in1' => null, 'break_out2' => null, 'break_in2' => null, 'ot_in' => null, 'ot_out' => null,
                'logs_per_day' => $fx === 1 ? 4 : 2, 'np_hours' => null, 'flex_minutes' => 0, 'grace_minutes' => null, 'strict_time' => 0,
                'break_minutes' => $fx === 3 ? 0 : (int)$s['break_minutes']], 'id = ?', [$id]);
            return;
        }
        $calc = shift_computed_hours($s);
        $u = ['break_minutes' => $calc['break_minutes']];
        if (!(int)$s['logs_per_day']) {
            $u['logs_per_day'] = $s['break_out2'] !== null ? 6 : 4;
        }
        if ($s['required_hours'] === null) {
            $u['required_hours'] = $calc['required_hours'];
        }
        if ($s['np_hours'] === null) {
            $u['np_hours'] = $calc['np_hours'];
        }
        update('shifts', $u, 'id = ?', [$id]);
    },
]);
