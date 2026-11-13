<?php
/**
 * v28 Reports > Attendance Summary per Employee Detailed Listing.
 *
 * A daily view of the attendance of each employee for a flexible date range: per date (headed "1 (Th)") the
 * Work (WRK), Late (LTE), Undertime (UND), Absent (ABS) and Net Work (NWRK) hours, and a TOTAL column.
 *   Net Work = Work - (Late + Undertime + Absent).
 *   Work of a working day = the required hours of the shift of the day (on a flexible shift that counts only the
 *   rendered hours, those hours). An approved paid leave (VL, SL, EL, ML ...) counts as Work but shows its leave
 *   code instead of WRK; the TOTAL adds it to the Work. Rendered and approved overtime is not included.
 *   A rest day worked shows RD (the regular hours rendered); a holiday not worked shows HOL; a rest day without
 *   work and a date not yet computed stay blank.
 * Display: Detailed (the five figures) or Net work only (a concise view of the net work hours).
 * The figures come from the Daily Time Records (rebuilt first when computed by an older engine).
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
$view = get('view') === 'net' ? 'net' : 'detail';
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

// --- the day figures of each employee and date (minutes), from the Daily Time Records
$hpd = (int)att_cfg()['hpd'];
$day = function (array $r) use ($hpd): ?array {
    if ($r['status'] === 'pending') {
        return null;   // a date not yet computed (or in the future)
    }
    $shift = shift_row($r['shift_id'] ? (int)$r['shift_id'] : null);
    $req = ($shift && $shift['required_hours'] !== null && (float)$shift['required_hours'] > 0) ? (int)round((float)$shift['required_hours'] * 60) : $hpd * 60;
    $reg = (int)$r['regular_minutes'];
    $d = ['label' => 'WRK', 'kind' => 'work', 'wrk' => 0, 'lte' => 0, 'und' => 0, 'abs' => 0, 'net' => 0];
    if (!(int)$r['is_workday']) {
        if ($reg <= 0) {
            return null;   // a rest day (or a day without schedule) without work
        }
        return ['label' => 'RD', 'kind' => 'rd', 'wrk' => $reg, 'net' => $reg] + $d;   // a rest day worked: the regular hours rendered
    }
    if ($r['status'] === 'on_leave') {
        $code = (string)($r['leave_code'] ?: 'LV');
        // a paid leave counts as work (records of a locked period computed before the leave hours were kept: the paid flag)
        $paid = ((int)$r['leave_minutes'] > 0 || (int)$r['leave_paid'] === 1) && ($r['leave_bucket'] ?? '') !== 'none' && (int)$r['absent_minutes'] === 0;
        if ($paid) {
            $lm = (int)$r['leave_minutes'] ?: $req;
            return ['label' => $code, 'kind' => 'leave', 'wrk' => $lm, 'net' => $lm] + $d;
        }
        $fxL = shift_flex_type($shift);
        if ($fxL && flex_mode($fxL) === 'rendered') {
            return ['label' => $code, 'kind' => 'unpaid'] + $d;   // v30 flexible types 4-6: no required hours, no absence
        }
        $abs = max((int)$r['absent_minutes'], $req);
        return ['label' => $code, 'kind' => 'unpaid', 'wrk' => $req, 'abs' => $abs, 'net' => 0] + $d;   // leave without pay
    }
    if ($r['status'] === 'holiday') {
        return ['label' => 'HOL', 'kind' => 'hol'] + $d;   // a holiday not worked
    }
    // present, absent, suspended, incomplete
    $fx = shift_flex_type($shift);
    $wrk = ($fx && flex_mode($fx) === 'rendered' && $r['status'] === 'present') ? $reg : $req;
    $abs = (int)$r['absent_minutes'];
    if ($r['status'] === 'incomplete' && !$abs) {
        $abs = max(0, $req - $reg);   // an unresolved incomplete day: what was not rendered is absent (as the summary counts it)
    }
    $lte = (int)$r['late_minutes'];
    $und = (int)$r['undertime_minutes'];
    return ['wrk' => $wrk, 'lte' => $lte, 'und' => $und, 'abs' => $abs, 'net' => max(0, $wrk - $lte - $und - $abs)] + $d;
};
$data = [];
$tot = [];
if ($emps) {
    $ids = array_map(fn($e) => (int)$e['id'], $emps);
    foreach ($ids as $id) {
        dtr_refresh_stale($id, $from, $to);   // days computed by an older engine are rebuilt first
    }
    $dtrs = rows('SELECT d.*, lt.code AS leave_code FROM dtr d LEFT JOIN leave_applications la ON la.id = d.leave_application_id LEFT JOIN leave_types lt ON lt.id = la.leave_type_id
                  WHERE ' . sql_in('d.employee_id', $ids) . ' AND d.work_date BETWEEN ? AND ?', [$from, $to]);
    foreach ($dtrs as $r) {
        $x = $day($r);
        if ($x === null) {
            continue;
        }
        $eid = (int)$r['employee_id'];
        $data[$eid][(string)$r['work_date']] = $x;
        $t = &$tot[$eid];
        $t = $t ?? ['wrk' => 0, 'lte' => 0, 'und' => 0, 'abs' => 0, 'net' => 0, 'leaves' => [], 'rd' => 0, 'hol' => 0];
        foreach (['wrk', 'lte', 'und', 'abs', 'net'] as $k) {
            $t[$k] += $x[$k];
        }
        if ($x['kind'] === 'leave') {
            $t['leaves'][$x['label']] = ($t['leaves'][$x['label']] ?? 0) + $x['wrk'];
        } elseif ($x['kind'] === 'rd') {
            $t['rd'] += $x['wrk'];
        } elseif ($x['kind'] === 'hol') {
            $t['hol']++;
        }
        unset($t);
    }
}
$grand = ['wrk' => 0, 'lte' => 0, 'und' => 0, 'abs' => 0, 'net' => 0];
foreach ($emps as $e) {
    foreach ($grand as $k => $v) {
        $grand[$k] += $tot[(int)$e['id']][$k] ?? 0;
    }
}

// --- formatting
$hr = function (int $min): string {
    $v = rtrim(rtrim(number_format($min / 60, 2, '.', ''), '0'), '.');
    return $v === '' || $v === '-0' ? '0' : $v;
};
$dow = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
$dayHead = fn($d) => (int)substr($d, 8, 2) . ' (' . $dow[(int)date('w', strtotime($d))] . ')';
$months = [];
foreach ($dates as $d) {
    $months[substr($d, 0, 7)] = ($months[substr($d, 0, 7)] ?? 0) + 1;
}
$periodText = count($months) === 1 && substr($from, 0, 4) === substr($to, 0, 4)
    ? date('F j', strtotime($from)) . ' to ' . date(substr($from, 0, 7) === substr($to, 0, 7) ? 'j, Y' : 'F j, Y', strtotime($to))
    : date('F j, Y', strtotime($from)) . ' to ' . date('F j, Y', strtotime($to));
$cellHtml = function (?array $x) use ($hr, $view): string {
    if (!$x) {
        return '';
    }
    if ($x['kind'] === 'hol') {
        return '<span class="ad-tag">HOL</span>';
    }
    if ($view === 'net') {
        return '<span class="ad-net">' . h($hr($x['net'])) . '</span>' . ($x['label'] !== 'WRK' ? ' <span class="ad-tag">' . h($x['label']) . '</span>' : '');
    }
    $row = fn($l, $v, $cls = '') => '<span class="ad-l' . $cls . '"><b>' . h($l) . '</b><i>' . h($v) . '</i></span>';
    return '<span class="ad-cell">' . $row($x['label'], $hr($x['wrk']), $x['label'] !== 'WRK' ? ' is-code' : '') . $row('LTE', $hr($x['lte']), $x['lte'] ? ' is-ded' : '')
        . $row('UND', $hr($x['und']), $x['und'] ? ' is-ded' : '') . $row('ABS', $hr($x['abs']), $x['abs'] ? ' is-ded' : '') . $row('NWRK', $hr($x['net']), ' is-net') . '</span>';
};
$totalHtml = function (?array $t) use ($hr, $view): string {
    $t = $t ?? ['wrk' => 0, 'lte' => 0, 'und' => 0, 'abs' => 0, 'net' => 0, 'leaves' => [], 'rd' => 0, 'hol' => 0];
    $notes = [];
    foreach ($t['leaves'] as $code => $m) {
        $notes[] = $code . ' ' . $hr($m);
    }
    if ($t['rd']) {
        $notes[] = 'RD ' . $hr($t['rd']);
    }
    if ($t['hol']) {
        $notes[] = 'HOL ' . $t['hol'] . ' day' . ($t['hol'] > 1 ? 's' : '');
    }
    $note = $notes ? '<span class="ad-incl">incl. ' . h(implode(', ', $notes)) . '</span>' : '';
    if ($view === 'net') {
        return '<span class="ad-net">' . h($hr($t['net'])) . '</span>' . $note;
    }
    $row = fn($l, $v, $cls = '') => '<span class="ad-l' . $cls . '"><b>' . h($l) . '</b><i>' . h($v) . '</i></span>';
    return '<span class="ad-cell">' . $row('WRK', $hr($t['wrk'])) . $row('LTE', $hr($t['lte'])) . $row('UND', $hr($t['und'])) . $row('ABS', $hr($t['abs']))
        . $row('NWRK', $hr($t['net']), ' is-net') . '</span>' . $note;
};

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
$filterText[] = 'Display: ' . ($view === 'net' ? 'Net work only' : 'Detailed');
$filterLine = implode('; ', $filterText);
$legend = $view === 'net'
    ? 'Hours. Net Work = Work - (Late + Undertime + Absent); an approved leave counts as work and shows its code; RD = rest day worked; HOL = holiday not worked. Overtime is not included.'
    : 'Hours. WRK = work (an approved leave counts as work and shows its code instead of WRK; RD = rest day worked), LTE = late, UND = undertime, ABS = absent, NWRK = net work = WRK - (LTE + UND + ABS); HOL = holiday not worked. Rendered and approved overtime is not included.';
$qs = array_filter(['from' => $from, 'to' => $to, 'branch_id' => $f['branch_id'] ?: null, 'org_unit_id' => $f['org_unit_id'] ?: null,
    'employee_group_id' => $f['employee_group_id'] ?: null, 'employee_type_id' => $f['employee_type_id'], 'q' => $f['q'] !== '' ? $f['q'] : null,
    'view' => $view === 'net' ? 'net' : null, 'sort' => $sort === 'no' ? 'no' : null], fn($v) => $v !== null);

// --- CSV: detailed = five lines per employee (WRK, LTE, UND, ABS, NWRK); net work only = one line per employee
if (get('csv')) {
    audit('reports', 'export_att_detail', null, null, ['from' => $from, 'to' => $to, 'employees' => count($emps), 'view' => $view]);
    $head = array_merge(['Employee No.', 'Employee Name'], $view === 'net' ? [] : ['Figure'], array_map(fn($d) => $d . ' (' . $dow[(int)date('w', strtotime($d))] . ')', $dates), ['TOTAL']);
    $num = fn(int $m) => number_format($m / 60, 2, '.', '');
    $lines = [];
    foreach ($emps as $e) {
        $eid = (int)$e['id'];
        $figs = $view === 'net' ? ['net' => 'NWRK'] : ['wrk' => 'WRK', 'lte' => 'LTE', 'und' => 'UND', 'abs' => 'ABS', 'net' => 'NWRK'];
        foreach ($figs as $k => $lbl) {
            $line = [$e['employee_no'], full_name($e)];
            if ($view !== 'net') {
                $line[] = $lbl;
            }
            foreach ($dates as $d) {
                $x = $data[$eid][$d] ?? null;
                $line[] = !$x ? '' : ($x['kind'] === 'hol' ? 'HOL' : (($k === 'wrk' || $view === 'net') && $x['label'] !== 'WRK' ? $x['label'] . ' ' : '') . $num($x[$k]));
            }
            $line[] = $num($tot[$eid][$k] ?? 0);
            $lines[] = $line;
        }
    }
    csv_download('attendance_summary_detailed_' . $from . '_' . $to . '.csv', $head, $lines);
}

// --- printed report
if (get('print')) {
    audit('reports', 'print_att_detail', null, null, ['from' => $from, 'to' => $to, 'employees' => count($emps), 'view' => $view]);
    print_header('Attendance Summary per Employee Detailed Listing' . ($view === 'net' ? ' (Net work)' : '') . ' — ' . $periodText, true, count($dates) > 16 ? 'legal' : '');
    if (count($f['branch_id']) === 1) {
        echo company_letterhead(row('SELECT * FROM branches WHERE id = ?', [$f['branch_id'][0]]));
    } elseif ($f['branch_id']) {
        $cos = array_values(array_unique(array_filter(array_map(fn($id) => (string)val("SELECT COALESCE(NULLIF(company_name, ''), name) FROM branches WHERE id = ?", [$id]), $f['branch_id']))));
        echo company_letterhead(['company_name' => implode(', ', $cos), 'address' => ''], $f['branch_id']);
    } else {
        echo company_letterhead();
    }
    echo '<h1>Attendance Summary per Employee Detailed Listing</h1>';
    echo '<div class="doc-meta alog-meta"><div><strong>Period :</strong> ' . h($periodText) . '</div><div><strong>Company / Branch:</strong> ' . h($companyLine) . '</div><div>Printed ' . h(fmt_dt(now())) . '</div></div>';
    echo '<div class="doc-selection"><strong>Report filters:</strong> ' . h($filterLine) . '</div>';
    echo '<table class="doc-table alog-table ad-table' . ($view === 'net' ? ' is-net-view' : '') . '"><thead>';
    if (count($months) > 1) {
        echo '<tr><th colspan="2"></th>';
        foreach ($months as $ym => $n) {
            echo '<th colspan="' . (int)$n . '" class="center">' . h(date('F Y', strtotime($ym . '-01'))) . '</th>';
        }
        echo '<th></th></tr>';
    }
    echo '<tr><th>Employee No.</th><th>Employee Name</th>';
    foreach ($dates as $d) {
        echo '<th class="center alog-day' . ((int)date('w', strtotime($d)) === 0 ? ' is-sun' : '') . '">' . h($dayHead($d)) . '</th>';
    }
    echo '<th class="center ad-total">TOTAL</th></tr></thead><tbody>';
    if (!$emps) {
        echo '<tr><td colspan="' . (3 + count($dates)) . '" class="center">No employee in this selection.</td></tr>';
    }
    foreach ($emps as $e) {
        $eid = (int)$e['id'];
        echo '<tr><td class="nowrap">' . h($e['employee_no']) . '</td><td>' . h(mb_strtoupper(full_name($e))) . '</td>';
        foreach ($dates as $d) {
            echo '<td class="ad-td">' . $cellHtml($data[$eid][$d] ?? null) . '</td>';
        }
        echo '<td class="ad-td ad-total">' . $totalHtml($tot[$eid] ?? null) . '</td></tr>';
    }
    echo '</tbody>';
    if ($emps) {
        echo '<tfoot><tr><td colspan="' . (2 + count($dates)) . '" class="right">GRAND TOTAL (' . count($emps) . ' employee' . (count($emps) > 1 ? 's' : '') . ')</td><td class="ad-td ad-total">'
            . $totalHtml($grand + ['leaves' => [], 'rd' => 0, 'hol' => 0]) . '</td></tr></tfoot>';
    }
    echo '</table>';
    echo '<p class="alog-note">' . h($legend) . '</p>';
    echo signatory_block();
    print_footer();
    exit;
}

// --- screen
page_header('Attendance Summary per Employee Detailed Listing', [
    'subtitle' => 'A daily view of the work, late, undertime, absent and net work hours of every employee, for any date range.',
    'wide' => true,
    'actions' => '<a class="btn" target="_blank" href="' . h(url('rpt_att_detail', $qs + ['print' => 1])) . '">Print</a> <a class="btn" href="' . h(url('rpt_att_detail', $qs + ['csv' => 1])) . '">Export CSV</a>',
]);
?>
<form method="get" class="card filters alog-filters">
  <input type="hidden" name="p" value="rpt_att_detail">
  <label>Date from<input type="date" name="from" value="<?= h($from) ?>" required></label>
  <label>Date to<input type="date" name="to" value="<?= h($to) ?>" required></label>
  <?= multi_pick('branch_id', 'Company / branch', $branchOpts, $f['branch_id'], 'All companies') ?>
  <?= multi_pick('org_unit_id', 'Department', $unitOpts, $f['org_unit_id'], 'All departments') ?>
  <?= multi_pick('employee_group_id', 'Employee group', $groupOpts, $f['employee_group_id'], 'All groups') ?>
  <label>Employee type<select name="employee_type_id"><?= options($typeOpts, $f['employee_type_id'], 'All types') ?></select></label>
  <label>Display<select name="view"><?= options(['detail' => 'Detailed: work, late, undertime, absent, net work', 'net' => 'Net work only'], $view) ?></select></label>
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
    <table class="table table-compact alog-screen ad-screen<?= $view === 'net' ? ' is-net-view' : '' ?>">
      <thead>
        <?php if (count($months) > 1): ?>
        <tr class="alog-months"><th class="alog-fix alog-fix-1"></th><th class="alog-fix alog-fix-2"></th><?php foreach ($months as $ym => $n): ?><th colspan="<?= (int)$n ?>" class="center"><?= h(date('F Y', strtotime($ym . '-01'))) ?></th><?php endforeach; ?><th></th></tr>
        <?php endif; ?>
        <tr><th class="alog-fix alog-fix-1">Employee No.</th><th class="alog-fix alog-fix-2">Employee Name</th>
          <?php foreach ($dates as $d): ?><th class="center alog-day<?= (int)date('w', strtotime($d)) === 0 ? ' is-sun' : '' ?>" title="<?= h(fmt_date($d, 'l, M j, Y')) ?>"><?= h($dayHead($d)) ?></th><?php endforeach; ?>
          <th class="center ad-total">TOTAL</th></tr>
      </thead>
      <tbody>
      <?php if (!$emps) {
          echo empty_row(3 + count($dates), 'No employee in this selection.');
      }
      foreach ($emps as $e):
          $eid = (int)$e['id']; ?>
        <tr>
          <td class="alog-fix alog-fix-1 nowrap"><?= h($e['employee_no']) ?></td>
          <td class="alog-fix alog-fix-2"><strong><?= h(full_name($e)) ?></strong><?php if ($e['unit_name']): ?><br><small class="muted"><?= h($e['unit_name']) ?></small><?php endif; ?></td>
          <?php foreach ($dates as $d): ?><td class="ad-td"><?= $cellHtml($data[$eid][$d] ?? null) ?></td><?php endforeach; ?>
          <td class="ad-td ad-total"><?= $totalHtml($tot[$eid] ?? null) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($emps): ?>
      <tfoot><tr><td class="alog-fix alog-fix-1"></td><td class="alog-fix alog-fix-2">Grand total</td><td colspan="<?= count($dates) ?>"></td><td class="ad-td ad-total"><?= $totalHtml($grand + ['leaves' => [], 'rd' => 0, 'hol' => 0]) ?></td></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>
<p class="small muted"><?= h($legend) ?> Figures from the Daily Time Records; a date not yet computed stays blank.</p>
<?php page_footer();
