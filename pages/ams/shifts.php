<?php
/**
 * Shift Table: time in / out, up to two breaks, required duty hours, night-premium hours, flexi time and rest days.
 * v16: Overtime In / Overtime Out = pre-arranged overtime after the shift (e.g. 7:00 PM to 9:30 PM = 2.5 hours),
 * credited automatically without an overtime request: minutes late on Overtime In and undertime on Overtime Out
 * are taken out, and nothing beyond Overtime Out is counted. Works on 2-log, 4-log and 6-log shifts.
 */
$days = ['0' => 'Sun', '1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat'];
$hm = fn($v) => $v ? substr((string)$v, 0, 5) : '';
crud_page([
    'page' => 'shifts', 'import' => 'shifts', 'table' => 'shifts', 'title' => 'Shift Table', 'singular' => 'shift', 'module' => 'ams', 'set_default' => true,
    'subtitle' => 'Shift start / end with up to two breaks (2-log, 4-log or 6-log days), total required duty hours, night-premium hours, flexi time and weekly rest days. Lates, undertime, overtime and night premium are computed against the assigned shift.',
    'actions' => ' <a class="btn" href="' . url('schedules') . '">Employee shift entry</a> <a class="btn" href="' . url('batch_shift') . '">Batch shift define</a>',
    'order' => 'code',
    'fields' => [
        'code' => ['label' => 'Code', 'required' => true],
        'name' => ['label' => 'Description', 'required' => true],
        'time_in' => ['label' => 'Time in', 'type' => 'time', 'required' => true],
        'break_out1' => ['label' => 'Break out 1', 'type' => 'time'],
        'break_in1' => ['label' => 'Break in 1', 'type' => 'time'],
        'break_out2' => ['label' => 'Break out 2', 'type' => 'time', 'help' => '6-log days only'],
        'break_in2' => ['label' => 'Break in 2', 'type' => 'time'],
        'time_out' => ['label' => 'Time out', 'type' => 'time', 'required' => true, 'help' => 'Earlier than time-in = overnight shift'],
        'ot_in' => ['label' => 'Overtime In', 'type' => 'time', 'help' => 'Pre-arranged overtime after the shift, e.g. 7:00 PM. Blank = none.'],
        'ot_out' => ['label' => 'Overtime Out', 'type' => 'time', 'help' => 'End of the pre-arranged overtime, e.g. 9:30 PM (2.5 hours). Approved automatically; late on Overtime In or out before Overtime Out is deducted, time after Overtime Out is not counted.'],
        'logs_per_day' => ['label' => 'Logs per day (shift code)', 'type' => 'select', 'options' => ['0' => 'Auto — from the break times', '2' => '2 logs: Time in, Time out', '4' => '4 logs: Time in, Break out 1, Break in 1, Time out', '6' => '6 logs: Time in, Break out 1, Break in 1, Break out 2, Break in 2, Time out'], 'placeholder' => null,
            'help' => 'Decides which log columns are filled for employees on this shift in Attendance Entry and the import review.'],
        'required_hours' => ['label' => 'Total required duty hours', 'type' => 'number', 'step' => '0.01', 'help' => 'Blank = computed from the shift'],
        'np_hours' => ['label' => 'Total night-premium hours', 'type' => 'number', 'step' => '0.01', 'help' => 'Blank = computed from the night window'],
        'break_minutes' => ['label' => 'Break allowance (minutes)', 'type' => 'number', 'default' => 60, 'help' => 'Used when no break times are set'],
        'flex_minutes' => ['label' => 'Flexi time +/- (minutes)', 'type' => 'number', 'default' => 0, 'help' => 'Time-in within this window is not late; the day shifts accordingly.'],
        'grace_minutes' => ['label' => 'Grace period override (minutes)', 'type' => 'number', 'help' => 'Blank = global setting'],
        'rest_days' => ['label' => 'Rest days', 'type' => 'multicheck', 'options' => $days, 'help' => 'None ticked = a 7-day shift: each employee rests on the default day-off of the Employee Masterfile.'],
        'strict_time' => ['label' => '(!) Strict time — no grace period on this shift', 'type' => 'checkbox'],
        'whole_week' => ['label' => '(www) Whole-week duty (7 days) — rest days come from the employee\'s default day-off and the Schedule Overrides', 'type' => 'checkbox'],
        'is_active' => ['label' => 'Active', 'type' => 'checkbox'],
    ],
    'defaults' => ['break_minutes' => 60, 'flex_minutes' => 0, 'rest_days' => '0', 'is_active' => 1, 'logs_per_day' => 0],
    'format' => [
        'code' => fn($v, $r) => shift_code_html($r),   // v18: the complete schedule when pointed at
        'rest_days' => fn($v, $r) => shift_is_7day($r) ? '<span class="muted">Employee day-off</span>' : h(implode(', ', array_map(fn($d) => $days[$d] ?? $d, array_filter(explode(',', (string)$v), fn($x) => $x !== '')))),
        'name' => fn($v, $r) => h($v) . ' <span class="badge badge-gray">' . (int)val("SELECT COUNT(*) FROM employees WHERE shift_id = ? AND status = 'active'", [$r['id']]) . ' emp.</span>',
        'time_in' => fn($v) => h($hm($v)), 'time_out' => fn($v) => h($hm($v)),
        'break_out1' => fn($v) => h($hm($v)), 'break_in1' => fn($v) => h($hm($v)), 'break_out2' => fn($v) => h($hm($v)), 'break_in2' => fn($v) => h($hm($v)),
        'strict_time' => fn($v) => $v ? '<span class="badge badge-amber">!</span>' : '', 'whole_week' => fn($v) => $v ? '<span class="badge badge-blue">www</span>' : '',
        'flex_minutes' => fn($v) => (int)$v ? '±' . (int)$v : '',
        'logs_per_day' => fn($v, $r) => (int)$v ?: (!empty($r['break_out2']) ? 6 : 4),
        'ot_in' => fn($v, $r) => $v && $r['ot_out'] ? h($hm($v)) . '–' . h($hm($r['ot_out'])) . '<br><small class="muted">' . number_format(shift_ot_hours($r), 2) . ' hrs OT</small>' : '',
    ],
    'list' => ['code', 'name', 'time_in', 'break_out1', 'break_in1', 'break_out2', 'break_in2', 'time_out', 'ot_in', 'logs_per_day', 'required_hours', 'np_hours', 'flex_minutes', 'strict_time', 'whole_week', 'rest_days', 'is_active'],
    'validate' => function ($d) {
        $e = [];
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
