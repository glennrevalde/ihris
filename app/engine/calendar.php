<?php
/**
 * Employee calendar (v17): what each day of a month holds for an employee, for My Workspace > My Calendar.
 *   Schedule         the shift of the day (Schedule Overrides, the employee's shift and day-off), or the rest day
 *   Approved leaves  the leave applications approved (and the ones still waiting, marked pending)
 *   Overtime         the overtime requests of the day (approved or pending) and the pre-arranged overtime of the shift
 *   Holidays         the Annual Holidays (nationwide or of the employee's branch)
 *   Company events   Company Events (all employees, a company / branch or a department)
 */

const CAL_KINDS = ['shift' => 'Schedule', 'rest' => 'Rest day', 'leave' => 'Approved leave', 'leave_pending' => 'Leave for approval',
    'ot' => 'Overtime', 'ot_pending' => 'Overtime for approval', 'preot' => 'Scheduled overtime', 'holiday' => 'Holiday', 'event' => 'Company event'];
const EVENT_KINDS = ['event' => 'Company event', 'meeting' => 'Meeting', 'training' => 'Training / seminar', 'activity' => 'Activity / outing',
    'celebration' => 'Celebration', 'reminder' => 'Reminder / deadline'];

function company_events_supported(): bool
{
    static $ok = [];
    $db = function_exists('active_db_name') ? active_db_name() : '';
    if (!isset($ok[$db])) {
        try {
            $ok[$db] = (bool)val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'company_events'");
        } catch (Throwable $e) {
            $ok[$db] = false;
        }
    }
    return $ok[$db];
}

/** Company events of a date range for an employee (null = every event): [date => list of events]. */
function company_events_between(string $from, string $to, ?array $emp = null): array
{
    if (!company_events_supported()) {
        return [];
    }
    $w = 'is_active = 1 AND event_date <= ? AND COALESCE(end_date, event_date) >= ?';
    $p = [$to, $from];
    if ($emp) {
        $units = [];
        for ($u = (int)($emp['org_unit_id'] ?? 0), $guard = 0; $u && $guard < 20; $guard++) {
            $units[] = $u;   // the department of the employee and the units above it
            $u = (int)val('SELECT parent_id FROM org_units WHERE id = ?', [$u]);
        }
        $w .= ' AND (branch_id IS NULL OR branch_id = ?) AND (org_unit_id IS NULL' . ($units ? ' OR org_unit_id IN (' . implode(',', $units) . ')' : '') . ')';
        $p[] = (int)($emp['default_branch_id'] ?? 0);
    }
    $out = [];
    foreach (rows("SELECT * FROM company_events WHERE $w ORDER BY event_date, start_time, title", $p) as $ev) {
        $end = $ev['end_date'] ?: $ev['event_date'];
        foreach (date_range(max($ev['event_date'], $from), min($end, $to)) as $d) {
            $out[$d][] = $ev;
        }
    }
    return $out;
}

/** Items of each day of the range for an employee: [date => list of ['kind', 'title', 'time', 'note']]. */
function calendar_items(array $emp, string $from, string $to): array
{
    $days = [];
    foreach (date_range($from, $to) as $d) {
        $days[$d] = [];
    }
    $hm = fn($t) => $t ? date('g:i A', strtotime($t)) : '';
    foreach (array_keys($days) as $d) {
        $s = employee_schedule($emp, $d);
        if ($s['is_rest']) {
            $days[$d][] = ['kind' => 'rest', 'title' => 'Rest day', 'time' => '', 'note' => ''];
        } elseif ($s['shift']) {
            $days[$d][] = ['kind' => 'shift', 'title' => $s['shift']['code'] . ' ' . $s['shift']['name'], 'time' => $hm($s['sched_in']) . ' – ' . $hm($s['sched_out']), 'note' => ''];
            if (!empty($s['ot'])) {
                $days[$d][] = ['kind' => 'preot', 'title' => 'Scheduled overtime', 'time' => date('g:i A', $s['ot'][0]) . ' – ' . date('g:i A', $s['ot'][1]), 'note' => 'Pre-arranged overtime of the shift'];
            }
        }
    }
    foreach (rows("SELECT la.*, lt.code, lt.name AS type_name FROM leave_applications la JOIN leave_types lt ON lt.id = la.leave_type_id
                   WHERE la.employee_id = ? AND la.status IN ('approved', 'pending') AND la.date_from <= ? AND la.date_to >= ? ORDER BY la.date_from", [$emp['id'], $to, $from]) as $l) {
        foreach (date_range(max($l['date_from'], $from), min($l['date_to'], $to)) as $d) {
            $days[$d][] = ['kind' => $l['status'] === 'approved' ? 'leave' : 'leave_pending', 'title' => $l['type_name'] . ($l['status'] === 'approved' ? '' : ' (for approval)'),
                'time' => '', 'note' => $l['app_no'] . ($l['reason'] ? ': ' . $l['reason'] : '')];
        }
    }
    foreach (rows("SELECT * FROM overtime_requests WHERE employee_id = ? AND status IN ('approved', 'pending') AND ot_date BETWEEN ? AND ? ORDER BY ot_date", [$emp['id'], $from, $to]) as $o) {
        $days[$o['ot_date']][] = ['kind' => $o['status'] === 'approved' ? 'ot' : 'ot_pending', 'title' => 'Overtime ' . rtrim(rtrim(number_format((float)$o['hours'], 2), '0'), '.') . ' hr(s)' . ($o['status'] === 'approved' ? '' : ' (for approval)'),
            'time' => '', 'note' => (string)$o['reason']];
    }
    foreach (rows('SELECT * FROM holidays WHERE holiday_date BETWEEN ? AND ? AND (branch_id IS NULL OR branch_id = ?) ORDER BY holiday_date', [$from, $to, (int)$emp['default_branch_id']]) as $hd) {
        $days[$hd['holiday_date']][] = ['kind' => 'holiday', 'title' => $hd['name'], 'time' => '', 'note' => $hd['holiday_type'] === 'regular' ? 'Regular holiday' : 'Special non-working day'];
    }
    foreach (company_events_between($from, $to, $emp) as $d => $evs) {
        foreach ($evs as $ev) {
            $days[$d][] = ['kind' => 'event', 'title' => $ev['title'], 'time' => $ev['start_time'] ? $hm($ev['start_time']) . ($ev['end_time'] ? ' – ' . $hm($ev['end_time']) : '') : '',
                'note' => trim((EVENT_KINDS[$ev['kind']] ?? 'Event') . ($ev['location'] ? ', ' . $ev['location'] : '') . ($ev['description'] ? '. ' . $ev['description'] : ''))];
        }
    }
    // holidays and events first, then leaves, overtime and the schedule
    $rank = ['holiday' => 0, 'event' => 1, 'leave' => 2, 'leave_pending' => 3, 'ot' => 4, 'ot_pending' => 5, 'shift' => 6, 'preot' => 7, 'rest' => 8];
    foreach ($days as &$list) {
        usort($list, fn($a, $b) => ($rank[$a['kind']] ?? 9) <=> ($rank[$b['kind']] ?? 9));
    }
    unset($list);
    return $days;
}
