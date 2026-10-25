<?php
/**
 * 13th Month Pay Report (PD 851) — basic salary earned per employee for the year, the 1/12
 * entitlement, what has already been paid and the balance still due before the deadline.
 * v17: the coverage follows the 13th month basis of the Payroll Parameters (Calendar or Fiscal Year).
 */
$year = (int)get('year', (int)date('Y'));
$unitF = (int)get('org_unit_id', 0);
[$covFrom, $covTo, $covBasis, $covLabel] = thirteenth_period($year);
$where = ["pr.status = 'finalized'", 'pr.period_end BETWEEN ? AND ?'];
$params = [$covFrom, $covTo];
if ($unitF) {
    $where[] = 'e.org_unit_id IN (' . implode(',', array_map('intval', org_descendant_ids($unitF))) . ')';
}
$rows = rows('SELECT e.id, e.employee_no, e.first_name, e.last_name, e.middle_name, e.suffix, e.date_hired, e.date_separated, e.status, ou.name AS unit_name,
                     COALESCE(SUM(pi.basic_earned),0) AS basic_earned, COUNT(*) AS pays,
                     COALESCE(SUM(CASE WHEN pr.run_type = "thirteenth" THEN pi.gross_pay ELSE 0 END),0) AS th_run
              FROM payroll_items pi JOIN payroll_runs pr ON pr.id = pi.run_id JOIN employees e ON e.id = pi.employee_id
              LEFT JOIN org_units ou ON ou.id = e.org_unit_id
              WHERE ' . implode(' AND ', $where) . user_scope_sql('e') . '
              GROUP BY e.id, e.employee_no, e.first_name, e.last_name, e.middle_name, e.suffix, e.date_hired, e.date_separated, e.status, ou.name
              ORDER BY ou.name, e.last_name, e.first_name', $params);
$paid = pairs("SELECT pi.employee_id, COALESCE(SUM(l.amount),0) FROM payroll_item_lines l JOIN payroll_items pi ON pi.id = l.item_id
               JOIN payroll_runs pr ON pr.id = pi.run_id WHERE l.code = '13TH' AND pr.status = 'finalized' AND pr.period_end BETWEEN ? AND ?
               GROUP BY pi.employee_id", [$year . '-01-01', max($covTo, $year . '-12-31')]);
$data = [];
$tot = ['basic' => 0.0, 'due' => 0.0, 'paid' => 0.0, 'bal' => 0.0];
foreach ($rows as $r) {
    $due = round((float)$r['basic_earned'] / 12, 2);
    $p = (float)($paid[$r['id']] ?? 0);
    $r['due'] = $due;
    $r['paid'] = $p;
    $r['balance'] = round($due - $p, 2);
    $tot['basic'] += (float)$r['basic_earned'];
    $tot['due'] += $due;
    $tot['paid'] += $p;
    $tot['bal'] += $r['balance'];
    $data[] = $r;
}
$deadline = $year . '-' . setting('thirteenth_deadline', '12-24');
if (get('csv')) {
    csv_download("13th_month_$year.csv", ['Employee no.', 'Employee', 'Department', 'Date hired', 'Pays', 'Basic salary earned', '1/12 entitlement', 'Paid', 'Balance'],
        array_map(fn($r) => [$r['employee_no'], full_name($r), $r['unit_name'], $r['date_hired'], $r['pays'], number_format((float)$r['basic_earned'], 2, '.', ''),
            number_format($r['due'], 2, '.', ''), number_format($r['paid'], 2, '.', ''), number_format($r['balance'], 2, '.', '')], $data));
}
if (get('print')) {
    print_header("13th Month Pay Report $year", true);
    echo company_letterhead();
    echo '<h1>13th Month Pay Report ' . h($year) . '</h1>';
    echo '<div class="doc-meta"><div>Presidential Decree 851, ' . h($covLabel) . ', deadline ' . h(fmt_date($deadline)) . '</div><div>Printed ' . h(fmt_dt(now())) . '</div></div>';
    echo report_selection_html(['org_unit_id' => $unitF ?: null], ['Year' => $year], true);
    echo '<table class="doc-table"><thead><tr><th>Emp. no.</th><th>Employee</th><th>Department</th><th>Date hired</th><th class="num">Basic earned</th><th class="num">1/12</th><th class="num">Paid</th><th class="num">Balance</th></tr></thead><tbody>';
    foreach ($data as $r) {
        echo '<tr><td>' . h($r['employee_no']) . '</td><td>' . h(full_name($r)) . '</td><td>' . h($r['unit_name']) . '</td><td>' . h(fmt_date($r['date_hired'])) . '</td>'
            . '<td class="num">' . h(money($r['basic_earned'])) . '</td><td class="num">' . h(money($r['due'])) . '</td><td class="num">' . h(money($r['paid'])) . '</td><td class="num">' . h(money($r['balance'])) . '</td></tr>';
    }
    echo '</tbody><tfoot><tr><td colspan="4">TOTAL (' . count($data) . ')</td><td class="num">' . h(money($tot['basic'])) . '</td><td class="num">' . h(money($tot['due']))
        . '</td><td class="num">' . h(money($tot['paid'])) . '</td><td class="num">' . h(money($tot['bal'])) . '</td></tr></tfoot></table>';
    echo signatory_block();
    print_footer();
    exit;
}
page_header('13th Month Pay Report', [
    'subtitle' => 'One twelfth of the basic salary actually earned in the coverage (PD 851): ' . $covLabel . '. Leave pay is ' . (setting('thirteenth_include_leave', '1') === '1' ? 'included in' : 'excluded from') . ' the basic salary.',
    'actions' => '<a class="btn" target="_blank" href="' . url('rpt_13th', ['year' => $year, 'org_unit_id' => $unitF ?: null, 'print' => 1]) . '">Print</a>'
        . '<a class="btn" href="' . url('rpt_13th', ['year' => $year, 'org_unit_id' => $unitF ?: null, 'csv' => 1]) . '">Export CSV</a>'
        . '<a class="btn" href="' . url('payroll_runs') . '">Create a 13th month run</a>',
]);
?>
<form method="get" class="card filters">
  <input type="hidden" name="p" value="rpt_13th">
  <label>Year<input type="number" name="year" value="<?= $year ?>" style="width:110px"></label>
  <label>Department<select name="org_unit_id" data-autosubmit><?= options(pairs('SELECT id, name FROM org_units WHERE is_active = 1 ORDER BY name'), $unitF ?: null, 'All departments') ?></select></label>
  <button class="btn btn-primary">Apply</button>
</form>

<div class="stats">
  <?= stat_card('Employees', number_format(count($data)), 'with payroll in ' . $year, 'info') ?>
  <?= stat_card('Basic salary earned', money($tot['basic']), 'finalized runs') ?>
  <?= stat_card('13th month due', money($tot['due']), 'total 1/12 entitlement') ?>
  <?= stat_card('Still to pay', money($tot['bal']), 'deadline ' . fmt_date($deadline), $tot['bal'] > 0 ? 'warn' : 'good') ?>
</div>

<div class="card flush">
  <div class="card-head"><div><h2>Per employee</h2><?= report_selection_html(['org_unit_id' => $unitF ?: null], ['Year' => $year]) ?></div><input type="search" class="table-search" data-target="thTable" placeholder="Find employee…" style="width:220px"></div>
  <div class="table-wrap table-scroll">
    <table class="table table-compact" id="thTable">
      <thead><tr><th>Employee</th><th>Department</th><th>Date hired</th><th class="num">Pays</th><th class="num">Basic earned</th><th class="num">1/12 entitlement</th><th class="num">Paid</th><th class="num">Balance</th></tr></thead>
      <tbody>
      <?php if (!$data) {
          echo empty_row(8, 'No finalized payroll for this year.');
      }
      foreach ($data as $r): ?>
        <tr<?= $r['balance'] > 0.005 ? ' class="row-warn"' : '' ?>>
          <td><strong><?= h(full_name($r)) ?></strong><br><small class="muted"><?= h($r['employee_no']) ?><?= $r['date_separated'] ? ', separated ' . h(fmt_date($r['date_separated'])) : '' ?></small></td>
          <td><?= h($r['unit_name'] ?: '—') ?></td>
          <td class="nowrap"><?= h(fmt_date($r['date_hired'])) ?></td>
          <td class="num"><?= (int)$r['pays'] ?></td>
          <td class="num"><?= money($r['basic_earned']) ?></td>
          <td class="num"><strong><?= money($r['due']) ?></strong></td>
          <td class="num"><?= money($r['paid']) ?></td>
          <td class="num"><?= money($r['balance']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="4">TOTAL</td><td class="num"><?= money($tot['basic']) ?></td><td class="num"><?= money($tot['due']) ?></td><td class="num"><?= money($tot['paid']) ?></td><td class="num"><?= money($tot['bal']) ?></td></tr></tfoot>
    </table>
  </div>
</div>
<?php page_footer();
