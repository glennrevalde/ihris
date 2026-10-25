<?php
/**
 * Monthly payroll summary: department-level and company-level payroll cost, headcount and average pay,
 * with budget variance analysis.
 */
$month = get('month', date('Y-m', strtotime('first day of last month')));
[$y, $m] = array_map('intval', explode('-', $month));
$rows = rows("SELECT pi.org_unit_id, pi.employee_id, pi.gross_pay, pi.net_pay, pi.basic_pay, pi.ot_pay, pi.sss_er + pi.sss_ec + pi.phic_er + pi.hdmf_er AS er, pi.withholding_tax, pi.total_deductions
              FROM payroll_items pi JOIN payroll_runs r ON r.id = pi.run_id JOIN employees e ON e.id = pi.employee_id
              WHERE r.status = 'finalized' AND YEAR(r.period_end) = ? AND MONTH(r.period_end) = ?" . user_scope_sql('e'), [$y, $m]);
$all = org_units_all();
// roll each item up to its department-level unit (Department type) or keep the unit itself
$dept = [];
foreach ($rows as $r) {
    $u = $r['org_unit_id'] ? (int)$r['org_unit_id'] : 0;
    $k = $u;
    $guard = 0;
    while ($k && isset($all[$k]) && !in_array($all[$k]['unit_type'], ['Department', 'Division', 'Company'], true) && $all[$k]['parent_id'] && $guard++ < 10) {
        $k = (int)$all[$k]['parent_id'];
    }
    if (!isset($dept[$k])) {
        $dept[$k] = ['emp' => [], 'gross' => 0, 'net' => 0, 'er' => 0, 'ot' => 0, 'tax' => 0, 'basic' => 0];
    }
    $dept[$k]['emp'][$r['employee_id']] = true;
    foreach (['gross' => 'gross_pay', 'net' => 'net_pay', 'er' => 'er', 'ot' => 'ot_pay', 'tax' => 'withholding_tax', 'basic' => 'basic_pay'] as $a => $b) {
        $dept[$k][$a] += (float)$r[$b];
    }
}
$budgets = pairs('SELECT org_unit_id, budget_amount FROM department_budgets WHERE year = ? AND month = ?', [$y, $m]);
$budgetFor = function (int $unit) use ($budgets) {
    $sum = 0;
    foreach (org_descendant_ids($unit) as $d) {
        $sum += (float)($budgets[$d] ?? 0);
    }
    return $sum;
};
$co = ['emp' => 0, 'gross' => 0, 'net' => 0, 'er' => 0, 'ot' => 0, 'tax' => 0];
foreach ($dept as $d) {
    $co['emp'] += count($d['emp']);
    foreach (['gross', 'net', 'er', 'ot', 'tax'] as $k) {
        $co[$k] += $d[$k];
    }
}
if (get('csv')) {
    csv_download("monthly_payroll_summary_$month.csv", ['Unit', 'Headcount', 'Gross', 'Employer share', 'Total cost', 'Average gross', 'Budget', 'Variance'],
        array_map(fn($k, $d) => [$k ? $all[$k]['name'] : 'Unassigned', count($d['emp']), round($d['gross'], 2), round($d['er'], 2), round($d['gross'] + $d['er'], 2),
            round($d['gross'] / max(1, count($d['emp'])), 2), $k ? $budgetFor($k) : 0, $k && $budgetFor($k) ? round($budgetFor($k) - $d['gross'] - $d['er'], 2) : ''], array_keys($dept), $dept));
}
page_header('Monthly Payroll Summary', ['subtitle' => 'Finalized payroll runs whose period ends in the selected month.',
    'actions' => '<a class="btn" href="' . url('rpt_monthly', ['month' => $month, 'csv' => 1]) . '">Export CSV</a> <button class="btn" onclick="window.print()">Print</button> <a class="btn" href="' . url('budgets', ['year' => $y]) . '">Budgets</a>']);
?>
<form class="card filters" method="get"><input type="hidden" name="p" value="rpt_monthly"><label>Month<input type="month" name="month" value="<?= h($month) ?>" data-autosubmit></label></form>
<div class="stats">
  <?= stat_card('Headcount paid', (string)$co['emp']) ?><?= stat_card('Gross payroll', peso($co['gross'])) ?><?= stat_card('Employer contributions', peso($co['er'])) ?>
  <?= stat_card('Total payroll cost', peso($co['gross'] + $co['er']), '', 'info') ?><?= stat_card('Average gross pay', peso($co['emp'] ? $co['gross'] / $co['emp'] : 0)) ?><?= stat_card('Tax withheld', peso($co['tax'])) ?>
</div>
<div class="card"><h2><?= h(month_name($m) . ' ' . $y) ?> — by department</h2>
<div class="table-wrap"><table class="table"><thead><tr><th>Unit</th><th class="num">Headcount</th><th class="num">Gross pay</th><th class="num">Overtime</th><th class="num">Employer share</th><th class="num">Total cost</th><th class="num">Average gross</th><th class="num">Budget</th><th class="num">Variance</th></tr></thead><tbody>
<?php if (!$dept) echo empty_row(9, 'No finalized payroll for this month.'); foreach ($dept as $k => $d): $cost = $d['gross'] + $d['er']; $b = $k ? $budgetFor($k) : 0; ?>
  <tr><td><?= $k ? h(org_path($k)) : 'Unassigned' ?></td><td class="num"><?= count($d['emp']) ?></td><td class="num"><?= money($d['gross']) ?></td><td class="num"><?= money($d['ot']) ?></td><td class="num"><?= money($d['er']) ?></td>
    <td class="num"><strong><?= money($cost) ?></strong></td><td class="num"><?= money($d['gross'] / max(1, count($d['emp']))) ?></td><td class="num"><?= $b ? money($b) : '—' ?></td>
    <td class="num"><?= $b ? '<span class="badge badge-' . ($b - $cost >= 0 ? 'green' : 'red') . '">' . money($b - $cost) . '</span>' : '' ?></td></tr>
<?php endforeach; ?></tbody>
<tfoot><tr><td>Company total</td><td class="num"><?= $co['emp'] ?></td><td class="num"><?= money($co['gross']) ?></td><td class="num"><?= money($co['ot']) ?></td><td class="num"><?= money($co['er']) ?></td><td class="num"><?= money($co['gross'] + $co['er']) ?></td><td class="num"><?= money($co['emp'] ? $co['gross'] / $co['emp'] : 0) ?></td><td></td><td></td></tr></tfoot></table></div>
<p class="small muted">Variance = budget − actual cost (gross + employer contributions). Positive = under budget.</p></div>
<?php page_footer();
