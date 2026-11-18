<?php
/**
 * v36 Reports > Approved Undertime Report and Unauthorized Undertime Report (one page, two menu items).
 *
 * The undertime of the Daily Time Record (computed as always) for a date range, tagged by the Undertime Forms:
 * a date with an approved Undertime Form is Approved undertime, every other undertime is Unauthorized undertime.
 * One row per employee and date, a subtotal per employee and a grand total. Screen, Print / PDF and CSV.
 */
$kind = get('p') === 'rpt_ut_approved' ? 'approved' : 'unauthorized';
$route = $kind === 'approved' ? 'rpt_ut_approved' : 'rpt_ut_unauthorized';
$title = $kind === 'approved' ? 'Approved Undertime Report' : 'Unauthorized Undertime Report';

$branchOpts = pairs("SELECT id, CONCAT(COALESCE(NULLIF(branch_code, ''), code), ' — ', name) FROM branches WHERE is_active = 1 ORDER BY company_code, branch_code, code");
$unitOpts = pairs('SELECT id, name FROM org_units WHERE is_active = 1 ORDER BY name');
$groupOpts = emp_group_options();
$typeOpts = pairs('SELECT id, name FROM emp_types ORDER BY name');

$isDate = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false;
$period = pt_default_period(null);
$from = $isDate(get('from')) ? (string)get('from') : ($period['period_start'] ?? date('Y-m-01'));
$to = $isDate(get('to')) ? (string)get('to') : ($period['period_end'] ?? date('Y-m-15'));
if ($to < $from) {
    [$from, $to] = [$to, $from];
}
$notice = '';
if (count(date_range($from, $to)) > 366) {
    $to = add_days($from, 365);
    $notice = 'The report covers at most one year: it ends on ' . fmt_date($to) . '.';
}
$f = [
    'branch_id' => id_list(get('branch_id')),
    'org_unit_id' => id_list(get('org_unit_id')),
    'employee_group_id' => id_list(get('employee_group_id')),
    'employee_type_id' => (int)get('employee_type_id', 0) ?: null,
    'q' => trim((string)get('q', '')),
];
$sort = get('sort') === 'no' ? 'no' : 'name';

// --- the undertime days of the selection
$where = ['d.work_date BETWEEN ? AND ?', 'd.undertime_minutes > 0'];
$params = [$from, $to];
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
$days = rows('SELECT d.employee_id, d.work_date, d.shift_id, d.time_in, d.time_out, d.undertime_minutes, e.employee_no, e.last_name, e.first_name, e.middle_name, e.suffix,
                     ou.name AS unit_name
              FROM dtr d JOIN employees e ON e.id = d.employee_id LEFT JOIN org_units ou ON ou.id = e.org_unit_id
              WHERE ' . implode(' AND ', $where) . user_scope_sql('e')
    . ($sort === 'no' ? ' ORDER BY e.employee_no, d.work_date' : ' ORDER BY e.last_name, e.first_name, e.employee_no, d.work_date'), $params);
$ids = array_values(array_unique(array_map(fn($r) => (int)$r['employee_id'], $days)));
$approved = ut_approved_map($ids, $from, $to, 'approved');
$pending = $kind === 'unauthorized' ? ut_approved_map($ids, $from, $to, 'pending') : [];
$lines = [];
foreach ($days as $r) {
    $tag = $approved[(int)$r['employee_id']][$r['work_date']] ?? null;
    if (($kind === 'approved') !== ($tag !== null)) {
        continue;
    }
    $r['form'] = $tag;
    $r['pending'] = $pending[(int)$r['employee_id']][$r['work_date']] ?? null;
    $lines[] = $r;
}
// subtotals per employee
$byEmp = [];
foreach ($lines as $r) {
    $k = (int)$r['employee_id'];
    $byEmp[$k] = $byEmp[$k] ?? ['days' => 0, 'min' => 0];
    $byEmp[$k]['days']++;
    $byEmp[$k]['min'] += (int)$r['undertime_minutes'];
}
$totalMin = array_sum(array_column($byEmp, 'min'));
$hm = fn(int $m) => sprintf('%d:%02d', intdiv($m, 60), $m % 60);
$shiftText = function (?int $id): string {
    static $c = [];
    if (!$id) {
        return '';
    }
    return $c[$id] = $c[$id] ?? (($s = shift_row($id)) ? shift_coverage($s) : '');
};
$remark = function (array $r) use ($kind): string {
    if ($kind === 'approved') {
        return 'Form ' . $r['form']['no'] . ($r['form']['approver'] ? ', approved by ' . $r['form']['approver'] : '');
    }
    return $r['pending'] ? 'Form ' . $r['pending']['no'] . ' pending approval' : 'No approved undertime form';
};

$periodText = date('F j, Y', strtotime($from)) . ' to ' . date('F j, Y', strtotime($to));
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
$filterLine = $filterText ? implode('; ', $filterText) : 'All departments, groups and types';
$qs = array_filter(['from' => $from, 'to' => $to, 'branch_id' => $f['branch_id'] ?: null, 'org_unit_id' => $f['org_unit_id'] ?: null,
    'employee_group_id' => $f['employee_group_id'] ?: null, 'employee_type_id' => $f['employee_type_id'], 'q' => $f['q'] !== '' ? $f['q'] : null,
    'sort' => $sort === 'no' ? 'no' : null], fn($v) => $v !== null);
$remarkHead = $kind === 'approved' ? 'Undertime form' : 'Remarks';

// --- CSV
if (get('csv')) {
    audit('reports', 'export_' . $route, null, null, ['from' => $from, 'to' => $to, 'rows' => count($lines)]);
    $out = [];
    foreach ($lines as $r) {
        $out[] = [$r['work_date'], $r['employee_no'], full_name($r), $r['unit_name'], $shiftText($r['shift_id'] ? (int)$r['shift_id'] : null),
            $r['time_in'] ? date('h:i A', strtotime($r['time_in'])) : '', $r['time_out'] ? date('h:i A', strtotime($r['time_out'])) : '',
            (int)$r['undertime_minutes'], $hm((int)$r['undertime_minutes']), $kind === 'approved' ? $r['form']['no'] : $remark($r), $kind === 'approved' ? $r['form']['reason'] : ''];
    }
    csv_download(($kind === 'approved' ? 'approved' : 'unauthorized') . '_undertime_' . $from . '_' . $to . '.csv',
        ['Date', 'Employee No.', 'Employee Name', 'Department', 'Shift', 'Time In', 'Time Out', 'Undertime (min)', 'Undertime (h:mm)', $remarkHead, 'Reason / purpose'], $out);
}

/** The rows of the report with a subtotal after each employee (screen and print). */
$body = function (bool $print) use ($lines, $byEmp, $hm, $shiftText, $remark, $kind, $totalMin): string {
    $cols = $kind === 'approved' ? 10 : 9;
    if (!$lines) {
        return '<tr><td colspan="' . $cols . '" class="center muted">No ' . ($kind === 'approved' ? 'approved' : 'unauthorized') . ' undertime in this selection.</td></tr>';
    }
    $h = '';
    $n = count($lines);
    foreach ($lines as $i => $r) {
        $k = (int)$r['employee_id'];
        $h .= '<tr><td class="nowrap">' . h(fmt_date($r['work_date'], 'D M d, Y')) . '</td><td class="nowrap">' . h($r['employee_no']) . '</td><td>' . h($print ? mb_strtoupper(full_name($r)) : full_name($r)) . '</td>'
            . '<td>' . h((string)$r['unit_name']) . '</td><td class="small nowrap">' . h($shiftText($r['shift_id'] ? (int)$r['shift_id'] : null)) . '</td>'
            . '<td class="nowrap">' . h($r['time_in'] ? date('h:i A', strtotime($r['time_in'])) : '') . '</td><td class="nowrap">' . h($r['time_out'] ? date('h:i A', strtotime($r['time_out'])) : '') . '</td>'
            . '<td class="num">' . h($hm((int)$r['undertime_minutes'])) . '</td><td class="small">' . h($remark($r)) . '</td>'
            . ($kind === 'approved' ? '<td class="small">' . h($r['form']['reason']) . '</td>' : '') . '</tr>';
        if ($i === $n - 1 || (int)$lines[$i + 1]['employee_id'] !== $k) {
            $h .= '<tr class="ut-subtotal"><td colspan="7">' . h(full_name($r)) . ': ' . $byEmp[$k]['days'] . ' day(s)</td><td class="num">' . h($hm($byEmp[$k]['min'])) . '</td><td colspan="' . ($cols - 8) . '"></td></tr>';
        }
    }
    return $h . '<tr class="ut-total"><td colspan="7">Total: ' . count($byEmp) . ' employee(s), ' . $n . ' day(s)</td><td class="num">' . h($hm($totalMin)) . '</td><td colspan="' . ($cols - 8) . '"></td></tr>';
};
$head = '<tr><th>Date</th><th>Employee No.</th><th>Employee Name</th><th>Department</th><th>Shift</th><th>Time In</th><th>Time Out</th><th class="num">Undertime (h:mm)</th><th>' . h($remarkHead) . '</th>'
    . ($kind === 'approved' ? '<th>Reason / purpose</th>' : '') . '</tr>';

// --- printed report
if (get('print')) {
    audit('reports', 'print_' . $route, null, null, ['from' => $from, 'to' => $to, 'rows' => count($lines)]);
    print_header($title . ' — ' . $periodText, true);
    if (count($f['branch_id']) === 1) {
        echo company_letterhead(row('SELECT * FROM branches WHERE id = ?', [$f['branch_id'][0]]));
    } elseif ($f['branch_id']) {
        $cos = array_values(array_unique(array_filter(array_map(fn($id) => (string)val("SELECT COALESCE(NULLIF(company_name, ''), name) FROM branches WHERE id = ?", [$id]), $f['branch_id']))));
        echo company_letterhead(['company_name' => implode(', ', $cos), 'address' => ''], $f['branch_id']);
    } else {
        echo company_letterhead();
    }
    echo '<h1>' . h($title) . '</h1>';
    echo '<div class="doc-meta"><div><strong>Period :</strong> ' . h($periodText) . '</div><div><strong>Company / Branch:</strong> ' . h($companyLine) . '</div><div>Printed ' . h(fmt_dt(now())) . '</div></div>';
    echo '<div class="doc-selection"><strong>Report filters:</strong> ' . h($filterLine) . '</div>';
    echo '<table class="doc-table ut-report"><thead>' . $head . '</thead><tbody>' . $body(true) . '</tbody></table>';
    echo '<p class="small">' . ($kind === 'approved' ? 'Undertime of the dates of an approved Undertime Form.' : 'Undertime without an approved Undertime Form.') . ' The undertime is the one computed by the Daily Time Record.</p>';
    echo signatory_block();
    print_footer();
    exit;
}

// --- screen
page_header($title, [
    'subtitle' => $kind === 'approved'
        ? 'The undertime of the dates covered by an approved Undertime Form. It is computed as every undertime; only the tag differs.'
        : 'The undertime of the dates without an approved Undertime Form. It is computed as every undertime; only the tag differs.',
    'actions' => '<a class="btn" target="_blank" href="' . h(url($route, $qs + ['print' => 1])) . '">Print</a> <a class="btn" href="' . h(url($route, $qs + ['csv' => 1])) . '">Export CSV</a> '
        . '<a class="btn" href="' . h(url($kind === 'approved' ? 'rpt_ut_unauthorized' : 'rpt_ut_approved', $qs)) . '">' . ($kind === 'approved' ? 'Unauthorized Undertime' : 'Approved Undertime') . '</a>',
]);
?>
<form method="get" class="card filters">
  <input type="hidden" name="p" value="<?= h($route) ?>">
  <label>Date from<input type="date" name="from" value="<?= h($from) ?>" required></label>
  <label>Date to<input type="date" name="to" value="<?= h($to) ?>" required></label>
  <?= multi_pick('branch_id', 'Company / branch', $branchOpts, $f['branch_id'], 'All companies') ?>
  <?= multi_pick('org_unit_id', 'Department', $unitOpts, $f['org_unit_id'], 'All departments') ?>
  <?= multi_pick('employee_group_id', 'Employee group', $groupOpts, $f['employee_group_id'], 'All groups') ?>
  <label>Employee type<select name="employee_type_id"><?= options($typeOpts, $f['employee_type_id'], 'All types') ?></select></label>
  <label>Sort by<select name="sort"><?= options(['name' => 'Employee name', 'no' => 'Employee no.'], $sort) ?></select></label>
  <label class="grow">Find<input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="Name, employee no. or biometric ID"></label>
  <button class="btn btn-primary">Apply</button>
</form>
<?php if ($notice): ?><div class="alert alert-warning"><span><?= h($notice) ?></span></div><?php endif; ?>
<div class="stats"><?= stat_card('Employees', (string)count($byEmp)) ?><?= stat_card('Days', (string)count($lines)) ?><?= stat_card($kind === 'approved' ? 'Approved undertime' : 'Unauthorized undertime', $hm($totalMin), number_format($totalMin / 60, 2) . ' hours', $kind === 'approved' ? '' : ($totalMin ? 'warn' : '')) ?></div>
<div class="card flush">
  <div class="card-head"><div><h2><?= h($companyLine) ?></h2><div class="sub">Period: <?= h($periodText) ?></div><div class="rpt-selection"><span>Report filters</span><?= h($filterLine) ?></div></div></div>
  <div class="table-wrap"><table class="table compact ut-report"><thead><?= $head ?></thead><tbody><?= $body(false) ?></tbody></table></div>
</div>
<?php page_footer();
