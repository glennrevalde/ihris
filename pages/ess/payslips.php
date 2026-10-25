<?php
$me = my_employee_id();
if (!$me) {
    throw new RuntimeException('Your account is not linked to an employee record.');
}
$year = (int)get('year', date('Y'));
$list = rows("SELECT pi.*, r.run_no, r.run_type, r.period_start, r.period_end, r.pay_date FROM payroll_items pi JOIN payroll_runs r ON r.id = pi.run_id
              WHERE pi.employee_id = ? AND r.status = 'finalized' AND YEAR(r.period_end) = ? ORDER BY r.pay_date DESC", [$me, $year]);
$ytd = ['gross' => 0, 'tax' => 0, 'net' => 0, 'contrib' => 0];
foreach ($list as $p) {
    $ytd['gross'] += $p['gross_pay'];
    $ytd['tax'] += $p['withholding_tax'];
    $ytd['net'] += $p['net_pay'];
    $ytd['contrib'] += $p['sss_ee'] + $p['phic_ee'] + $p['hdmf_ee'];
}
page_header('My Payslips', ['subtitle' => 'View and download your payslips electronically.']);
?>
<form class="card filters" method="get"><input type="hidden" name="p" value="my_payslips"><label>Year<input type="number" name="year" value="<?= $year ?>" style="width:100px" data-autosubmit></label></form>
<div class="stats"><?= stat_card('Gross pay YTD', peso($ytd['gross'])) ?><?= stat_card('Government contributions YTD', peso($ytd['contrib'])) ?><?= stat_card('Tax withheld YTD', peso($ytd['tax'])) ?><?= stat_card('Net pay YTD', peso($ytd['net']), '', 'good') ?></div>
<div class="card"><table class="table"><thead><tr><th>Pay date</th><th>Period</th><th>Type</th><th class="num">Gross</th><th class="num">Deductions</th><th class="num">Net pay</th><th class="actions"></th></tr></thead><tbody>
<?php if (!$list) echo empty_row(7, 'No payslips for this year.'); foreach ($list as $p): ?>
  <tr><td><?= h(fmt_date($p['pay_date'])) ?></td><td><?= h(fmt_date($p['period_start'], 'M d')) ?> – <?= h(fmt_date($p['period_end'], 'M d, Y')) ?></td><td><?= h(RUN_TYPES[$p['run_type']]) ?></td>
    <td class="num"><?= money($p['gross_pay']) ?></td><td class="num"><?= money($p['total_deductions']) ?></td><td class="num"><strong><?= money($p['net_pay']) ?></strong></td>
    <td class="actions"><a class="btn btn-sm" href="<?= url('payslip', ['id' => $p['id']]) ?>">View</a><a class="btn btn-sm btn-primary" href="<?= url('payslip', ['id' => $p['id'], 'pdf' => 1]) ?>">PDF</a></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php page_footer();
