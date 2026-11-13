<?php
/**
 * v27 Reports > Attendance Logs per Employee Listing.
 *
 * One row per employee (Employee No., Employee Name, Default Shift Time: v28 every log time of the shift) and one column per date of a flexible
 * date range, headed by the day of the month and the abbreviation of the weekday ("1 (Th)"). Each cell lists the
 * registered valid logs of the employee on that date, one under the other: a rejected web check-in and a
 * double log (a repeat of the same log type within the AMS policy "# of minutes between LOGS w/ same TYPE as
 * INVALID") are not included. The header shows the company / branch of the selection (name and logo), the
 * period and the other report filters; the printed report ends with Prepared by / Checked by / Approved by.
 * Screen, Print (and Save as PDF) and CSV export.
 */
$branchOpts = pairs("SELECT id, CONCAT(COALESCE(NULLIF(branch_code, ''), code), ' — ', name) FROM branches WHERE is_active = 1 ORDER BY company_code, branch_code, code");
$unitOpts = pairs('SELECT id, name FROM org_units WHERE is_active = 1 ORDER BY name');
$groupOpts = emp_group_options();
$typeOpts = pairs('SELECT id, name FROM emp_types ORDER BY name');

// --- the date range: any from / to (at most 62 days); by default the current pay period, else the 1st to the 15th
$isDate = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false;
$period = pt_default_period(null);
$from = $isDate(get('from')) ? (string)get('from') : ($period['period_start'] ?? date('Y-m-01'));
$to = $isDate(get('to')) ? (string)get('to') : ($period['period_end'] ?? date('Y-m-15'));
if ($to < $from) {
    [$from, $to] = [$to, $from];
}
$maxDays = 62;
$notice = '';
if (count(date_range($from, $to)) > $maxDays) {
    $to = add_days($from, $maxDays - 1);
    $notice = 'The listing covers at most ' . $maxDays . ' days: it ends on ' . fmt_date($to) . '.';
}
$dates = date_range($from, $to);

$f = [
    'branch_id' => id_list(get('branch_id')),
    'org_unit_id' => id_list(get('org_unit_id')),
    'employee_group_id' => id_list(get('employee_group_id')),
    'employee_type_id' => (int)get('employee_type_id', 0) ?: null,
    'q' => trim((string)get('q', '')),
];
$show = get('show') === 'logs' ? 'logs' : 'all';
$sort = get('sort') === 'no' ? 'no' : 'name';

// --- the employees of the selection, employed during the range
$where = ['e.date_hired <= ?', '(e.date_separated IS NULL OR e.date_separated >= ?)'];
$params = [$to, $from];
if ($f['branch_id']) {
    $where[] = sql_in('e.default_branch_id', $f['branch_id']);
}
if ($f['org_unit_id'] && ($units = org_units_with_descendants($f['org_unit_id']))) {
    $where[] = sql_in('e.org_unit_id', $units);
}
if ($f['employee_group_id']) {
    $where[] = sql_in('e.employee_group_id', $f['employee_group_id']);
}
if ($f['employee_type_id']) {
    $where[] = 'e.employee_type_id = ?';
    $params[] = $f['employee_type_id'];
}
if ($f['q'] !== '') {
    $where[] = search_words_sql(['e.employee_no', 'e.last_name', 'e.first_name', 'e.middle_name', 'e.biometric_id'], $f['q'], $params);
}
$emps = rows('SELECT e.id, e.employee_no, e.last_name, e.first_name, e.middle_name, e.suffix, e.shift_id, e.default_branch_id, ou.name AS unit_name
              FROM employees e LEFT JOIN org_units ou ON ou.id = e.org_unit_id WHERE ' . implode(' AND ', $where) . user_scope_sql('e')
    . ($sort === 'no' ? ' ORDER BY e.employee_no' : ' ORDER BY e.last_name, e.first_name, e.employee_no'), $params);

// --- the registered valid logs of each employee and date
$logs = [];
if ($emps) {
    $ids = array_map(fn($e) => (int)$e['id'], $emps);
    // the day before and after too: a night worker's time out after midnight belongs to the day of the shift
    $raw = rows("SELECT id, employee_id, log_time, punch_type, verify_status FROM attendance_logs
                 WHERE " . sql_in('employee_id', $ids) . " AND log_time BETWEEN ? AND ? AND verify_status <> 'rejected' ORDER BY employee_id, log_time, id",
        [add_days($from, -1) . ' 00:00:00', add_days($to, 1) . ' 23:59:59']);
    $invalid = punch_invalid_ids($raw);   // v30: logs of any type inside the AMS policy window of the previous counted log
    // the work day of each log the Daily Time Record placed (Time in, breaks, Time out, overtime logs)
    $dayOf = [];
    foreach (rows('SELECT employee_id, work_date, time_in, break_out, break_in, break_out2, break_in2, time_out, ot_in, ot_out FROM dtr
                   WHERE ' . sql_in('employee_id', $ids) . ' AND work_date BETWEEN ? AND ?', [add_days($from, -1), add_days($to, 1)]) as $r) {
        foreach (['time_in', 'break_out', 'break_in', 'break_out2', 'break_in2', 'time_out', 'ot_in', 'ot_out'] as $c) {
            if (!empty($r[$c])) {
                $dayOf[$r['employee_id'] . '|' . substr((string)$r[$c], 0, 16)] = (string)$r['work_date'];
            }
        }
    }
    $seen = [];
    foreach ($raw as $l) {
        if (isset($invalid[(int)$l['id']])) {
            continue;
        }
        $t = substr((string)$l['log_time'], 0, 16);
        $k = $l['employee_id'] . '|' . $t;
        if (isset($seen[$k])) {
            continue;   // the same minute twice (e.g. an In and an untyped log of one punch) shows once
        }
        $seen[$k] = true;
        $day = $dayOf[$k] ?? substr($t, 0, 10);   // a log the DTR did not place stays on its calendar date
        if ($day < $from || $day > $to) {
            continue;
        }
        $logs[(int)$l['employee_id']][$day][] = substr($t, 11, 5) . ($day !== substr($t, 0, 10) ? '+' : '');
    }
}
if ($show === 'logs') {
    $emps = array_values(array_filter($emps, fn($e) => !empty($logs[(int)$e['id']])));
}

// --- the default shift time of each employee
// v28: the complete time of the default shift, every log (4 logs: both spans; 6 logs: the three spans)
$shiftTime = fn(?int $shiftId): string => implode(', ', report_shift_times($shiftId ? shift_row($shiftId) : null)) ?: '—';
$shiftHtml = fn(?int $shiftId): string => implode('<br>', array_map('h', report_shift_times($shiftId ? shift_row($shiftId) : null))) ?: '—';
$dow = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
$dayHead = fn($d) => (int)substr($d, 8, 2) . ' (' . $dow[(int)date('w', strtotime($d))] . ')';
$months = [];
foreach ($dates as $d) {
    $months[substr($d, 0, 7)] = ($months[substr($d, 0, 7)] ?? 0) + 1;
}
$periodText = count($months) === 1 && substr($from, 0, 4) === substr($to, 0, 4)
    ? date('F j', strtotime($from)) . ' to ' . date(substr($from, 0, 7) === substr($to, 0, 7) ? 'j, Y' : 'F j, Y', strtotime($to))
    : date('F j, Y', strtotime($from)) . ' to ' . date('F j, Y', strtotime($to));

// --- the company / branch of the selection (header) and the other filters
$branchNames = array_map(fn($id) => (string)($branchOpts[$id] ?? ''), $f['branch_id']);
$companyLine = $f['branch_id'] ? implode(', ', array_filter($branchNames)) : 'All companies / branches';
$filterText = [];
if ($f['org_unit_id']) {
    $filterText[] = 'Department: ' . multi_pick_text($unitOpts, $f['org_unit_id'], 'All');
}
if ($f['employee_group_id']) {
    $filterText[] = 'Employee group: ' . multi_pick_text($groupOpts, $f['employee_group_id'], 'All');
}
if ($f['employee_type_id']) {
    $filterText[] = 'Employee type: ' . ($typeOpts[$f['employee_type_id']] ?? '');
}
if ($f['q'] !== '') {
    $filterText[] = 'Find: "' . $f['q'] . '"';
}
if ($show === 'logs') {
    $filterText[] = 'Only employees with logs';
}
$filterLine = $filterText ? implode('; ', $filterText) : 'All departments, groups and types';
$qs = array_filter(['from' => $from, 'to' => $to, 'branch_id' => $f['branch_id'] ?: null, 'org_unit_id' => $f['org_unit_id'] ?: null,
    'employee_group_id' => $f['employee_group_id'] ?: null, 'employee_type_id' => $f['employee_type_id'], 'q' => $f['q'] !== '' ? $f['q'] : null,
    'show' => $show === 'logs' ? 'logs' : null, 'sort' => $sort === 'no' ? 'no' : null], fn($v) => $v !== null);

// --- CSV
if (get('csv')) {
    audit('reports', 'export_att_logs', null, null, ['from' => $from, 'to' => $to, 'employees' => count($emps)]);
    $head = array_merge(['Employee No.', 'Employee Name', 'Default Shift Time'], array_map(fn($d) => $d . ' (' . $dow[(int)date('w', strtotime($d))] . ')', $dates));
    $lines = [];
    foreach ($emps as $e) {
        $line = [$e['employee_no'], full_name($e), $shiftTime($e['shift_id'] ? (int)$e['shift_id'] : null)];
        foreach ($dates as $d) {
            $line[] = implode(' ', $logs[(int)$e['id']][$d] ?? []);
        }
        $lines[] = $line;
    }
    csv_download('attendance_logs_' . $from . '_' . $to . '.csv', $head, $lines);
}

$cell = function (array $times): string {
    return $times ? implode('<br>', array_map('h', $times)) : '';
};

// --- printed report
if (get('print')) {
    audit('reports', 'print_att_logs', null, null, ['from' => $from, 'to' => $to, 'employees' => count($emps)]);
    print_header('Attendance Logs per Employee Listing — ' . $periodText, true, count($dates) > 16 ? 'legal' : '');
    if (count($f['branch_id']) === 1) {
        echo company_letterhead(row('SELECT * FROM branches WHERE id = ?', [$f['branch_id'][0]]));
    } elseif ($f['branch_id']) {
        // several companies / branches: their names, and their logos
        $cos = array_values(array_unique(array_filter(array_map(fn($id) => (string)val("SELECT COALESCE(NULLIF(company_name, ''), name) FROM branches WHERE id = ?", [$id]), $f['branch_id']))));
        echo company_letterhead(['company_name' => implode(', ', $cos), 'address' => ''], $f['branch_id']);
    } else {
        echo company_letterhead();
    }
    echo '<h1>Attendance Logs per Employee Listing</h1>';
    echo '<div class="doc-meta alog-meta"><div><strong>Period :</strong> ' . h($periodText) . '</div><div><strong>Company / Branch:</strong> ' . h($companyLine) . '</div><div>Printed ' . h(fmt_dt(now())) . '</div></div>';
    echo '<div class="doc-selection"><strong>Report filters:</strong> ' . h($filterLine) . '</div>';
    echo '<table class="doc-table alog-table"><thead>';
    if (count($months) > 1) {
        echo '<tr><th colspan="3"></th>';
        foreach ($months as $ym => $n) {
            echo '<th colspan="' . (int)$n . '" class="center">' . h(date('F Y', strtotime($ym . '-01'))) . '</th>';
        }
        echo '</tr>';
    }
    echo '<tr><th>Employee No.</th><th>Employee Name</th><th>Default Shift Time</th>';
    foreach ($dates as $d) {
        echo '<th class="center alog-day' . ((int)date('w', strtotime($d)) === 0 ? ' is-sun' : '') . '">' . h($dayHead($d)) . '</th>';
    }
    echo '</tr></thead><tbody>';
    if (!$emps) {
        echo '<tr><td colspan="' . (3 + count($dates)) . '" class="center">No employee in this selection.</td></tr>';
    }
    foreach ($emps as $e) {
        echo '<tr><td class="nowrap">' . h($e['employee_no']) . '</td><td>' . h(mb_strtoupper(full_name($e))) . '</td><td class="nowrap">' . $shiftHtml($e['shift_id'] ? (int)$e['shift_id'] : null) . '</td>';
        foreach ($dates as $d) {
            echo '<td class="center alog-cell">' . $cell($logs[(int)$e['id']][$d] ?? []) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<p class="alog-note">' . count($emps) . ' employee(s). Each date lists the registered valid logs of the employee; double logs and invalid logs are not included. + = a log after midnight counted on the day of the shift.</p>';
    echo signatory_block();
    print_footer();
    exit;
}

// --- screen
page_header('Attendance Logs per Employee Listing', [
    'subtitle' => 'The valid logs of every employee, day by day, for any date range. Double logs and invalid logs are left out.',
    'wide' => true,
    'actions' => '<a class="btn" target="_blank" href="' . h(url('rpt_att_logs', $qs + ['print' => 1])) . '">Print</a> <a class="btn" href="' . h(url('rpt_att_logs', $qs + ['csv' => 1])) . '">Export CSV</a>',
]);
?>
<form method="get" class="card filters alog-filters">
  <input type="hidden" name="p" value="rpt_att_logs">
  <label>Date from<input type="date" name="from" value="<?= h($from) ?>" required></label>
  <label>Date to<input type="date" name="to" value="<?= h($to) ?>" required></label>
  <?= multi_pick('branch_id', 'Company / branch', $branchOpts, $f['branch_id'], 'All companies') ?>
  <?= multi_pick('org_unit_id', 'Department', $unitOpts, $f['org_unit_id'], 'All departments') ?>
  <?= multi_pick('employee_group_id', 'Employee group', $groupOpts, $f['employee_group_id'], 'All groups') ?>
  <label>Employee type<select name="employee_type_id"><?= options($typeOpts, $f['employee_type_id'], 'All types') ?></select></label>
  <label>Show<select name="show"><?= options(['all' => 'All employees', 'logs' => 'Only employees with logs'], $show) ?></select></label>
  <label>Sort by<select name="sort"><?= options(['name' => 'Employee name', 'no' => 'Employee no.'], $sort) ?></select></label>
  <label class="grow">Find<input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="Name, employee no. or biometric ID"></label>
  <button class="btn btn-primary">Apply</button>
</form>
<?php if ($notice): ?><div class="alert alert-warning"><span><?= h($notice) ?></span></div><?php endif; ?>
<div class="card flush">
  <div class="card-head">
    <div><h2><?= h($companyLine) ?></h2>
      <div class="sub">Period: <?= h($periodText) ?> (<?= count($dates) ?> day<?= count($dates) === 1 ? '' : 's' ?>), <?= count($emps) ?> employee(s)</div>
      <div class="rpt-selection"><span>Report filters</span><?= h($filterLine) ?></div></div>
  </div>
  <div class="table-wrap table-scroll alog-wrap">
    <table class="table table-compact alog-screen">
      <thead>
        <?php if (count($months) > 1): ?>
        <tr class="alog-months"><th class="alog-fix alog-fix-1"></th><th class="alog-fix alog-fix-2"></th><th></th><?php foreach ($months as $ym => $n): ?><th colspan="<?= (int)$n ?>" class="center"><?= h(date('F Y', strtotime($ym . '-01'))) ?></th><?php endforeach; ?></tr>
        <?php endif; ?>
        <tr><th class="alog-fix alog-fix-1">Employee No.</th><th class="alog-fix alog-fix-2">Employee Name</th><th>Default Shift Time</th>
          <?php foreach ($dates as $d): ?><th class="center alog-day<?= (int)date('w', strtotime($d)) === 0 ? ' is-sun' : '' ?>" title="<?= h(fmt_date($d, 'l, M j, Y')) ?>"><?= h($dayHead($d)) ?></th><?php endforeach; ?></tr>
      </thead>
      <tbody>
      <?php if (!$emps) {
          echo empty_row(3 + count($dates), 'No employee in this selection.');
      }
      foreach ($emps as $e): ?>
        <tr>
          <td class="alog-fix alog-fix-1 nowrap"><?= h($e['employee_no']) ?></td>
          <td class="alog-fix alog-fix-2"><strong><?= h(full_name($e)) ?></strong><?php if ($e['unit_name']): ?><br><small class="muted"><?= h($e['unit_name']) ?></small><?php endif; ?></td>
          <td class="nowrap small"><?= $shiftHtml($e['shift_id'] ? (int)$e['shift_id'] : null) ?></td>
          <?php foreach ($dates as $d): ?><td class="center alog-cell"><?= $cell($logs[(int)$e['id']][$d] ?? []) ?></td><?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<p class="small muted">Each date lists the logs registered for the employee that day, earliest first. A rejected web check-in and a double log (the same log type again within the minutes of the AMS policy) are not included. A night worker's log after midnight shows on the day of the shift, as in the Daily Time Record, marked + (next day).</p>
<?php page_footer();
