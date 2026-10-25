<?php
/**
 * Company Events (v17): events, meetings, trainings, activities, celebrations and reminders shown in the
 * calendar of the employees (My Workspace > My Calendar): for everyone, one company / branch or one
 * department (and the units under it).
 */
$ym = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)get('ym')) ? (string)get('ym') : '';
$hm = fn($v) => $v ? date('g:i A', strtotime((string)$v)) : '';
crud_page([
    'page' => 'company_events', 'table' => 'company_events', 'title' => 'Company Events', 'singular' => 'event', 'module' => 'pis',
    'subtitle' => 'Events shown in the calendar of the employees, with the holidays, their schedule, approved leaves and overtime.',
    'actions' => '<a class="btn" href="' . url('my_calendar') . '">Calendar</a> <a class="btn" href="' . url('holidays') . '">Annual Holidays</a>',
    'order' => 'event_date DESC, start_time',
    'where' => $ym !== '' ? "event_date <= LAST_DAY(?) AND COALESCE(end_date, event_date) >= ?" : null, 'where_params' => $ym !== '' ? [$ym . '-01', $ym . '-01'] : [],
    'top_html' => '<form class="card filters" method="get"><input type="hidden" name="p" value="company_events"><label>Month<input type="month" name="ym" value="' . h($ym) . '" data-autosubmit></label>'
        . ($ym !== '' ? '<a class="btn" href="' . url('company_events') . '">All months</a>' : '') . '</form>',
    'fields' => [
        'title' => ['label' => 'Event', 'required' => true, 'wide' => true],
        'kind' => ['label' => 'Kind', 'type' => 'select', 'options' => EVENT_KINDS, 'required' => true],
        'event_date' => ['label' => 'Date', 'type' => 'date', 'required' => true],
        'end_date' => ['label' => 'Until (optional)', 'type' => 'date', 'help' => 'For an event of several days.'],
        'start_time' => ['label' => 'Starts at', 'type' => 'time'],
        'end_time' => ['label' => 'Ends at', 'type' => 'time'],
        'location' => ['label' => 'Venue / location'],
        'branch_id' => ['label' => 'For the company / branch', 'type' => 'select', 'options' => branch_options(), 'placeholder' => 'All companies / branches'],
        'org_unit_id' => ['label' => 'For the department', 'type' => 'select', 'options' => org_unit_options(), 'placeholder' => 'All departments'],
        'description' => ['label' => 'Details', 'type' => 'textarea', 'wide' => true],
        'is_active' => ['label' => 'Shown in the calendar', 'type' => 'checkbox'],
    ],
    'defaults' => ['kind' => 'event', 'event_date' => today(), 'is_active' => 1],
    'list' => ['event_date', 'title', 'kind', 'start_time', 'location', 'branch_id', 'org_unit_id', 'is_active'],
    'format' => [
        'event_date' => fn($v, $r) => h(fmt_date($v, 'D, M d, Y')) . ($r['end_date'] && $r['end_date'] !== $v ? '<br><small class="muted">until ' . h(fmt_date($r['end_date'], 'M d, Y')) . '</small>' : ''),
        'title' => fn($v, $r) => '<strong>' . h($v) . '</strong>' . ($r['description'] ? '<br><small class="muted">' . h(mb_strimwidth((string)$r['description'], 0, 90, '…')) . '</small>' : ''),
        'kind' => fn($v) => h(EVENT_KINDS[$v] ?? $v),
        'start_time' => fn($v, $r) => h($v ? $hm($v) . ($r['end_time'] ? ' – ' . $hm($r['end_time']) : '') : 'Whole day'),
        'branch_id' => fn($v) => $v ? h((string)val('SELECT name FROM branches WHERE id = ?', [$v])) : '<span class="muted">All</span>',
        'org_unit_id' => fn($v) => $v ? h((string)val('SELECT name FROM org_units WHERE id = ?', [$v])) : '<span class="muted">All</span>',
    ],
    'validate' => function ($d) {
        $e = [];
        if (!empty($d['end_date']) && $d['end_date'] < $d['event_date']) {
            $e[] = 'The end date is before the date of the event.';
        }
        if (!empty($d['end_time']) && !empty($d['start_time']) && empty($d['end_date']) && $d['end_time'] < $d['start_time']) {
            $e[] = 'The event ends before it starts.';
        }
        return $e;
    },
    'after_save' => function ($id) {
        q('UPDATE company_events SET created_by = COALESCE(created_by, ?), created_at = COALESCE(created_at, ?), updated_at = ? WHERE id = ?', [user_id(), now(), now(), $id]);
    },
]);
