<?php
/**
 * Payroll register: itemized gross pay, all deductions and net pay per employee for a run.
 */
$runId = (int)get('run_id', 0);
if (!$runId) {
    $runs = rows("SELECT r.id, r.run_no, r.period_start, r.period_end, pg.name FROM payroll_runs r JOIN pay_groups pg ON pg.id = r.pay_group_id WHERE r.status IN ('computed','finalized') ORDER BY r.period_end DESC, r.id DESC LIMIT 100");
    page_header('Payroll Register', ['subtitle' => 'Itemized gross pay, deductions and net pay per employee for the selected cutoff.']);
    echo '<div class="card"><table class="table"><thead><tr><th>Run</th><th>Pay group</th><th>Period</th><th></th></tr></thead><tbody>';
    foreach ($runs as $r) {
        echo '<tr><td>' . h($r['run_no']) . '</td><td>' . h($r['name']) . '</td><td>' . h(fmt_date($r['period_start'])) . ' – ' . h(fmt_date($r['period_end'])) . '</td><td class="actions"><a class="btn btn-sm btn-primary" target="_blank" href="' . url('rpt_register', ['run_id' => $r['id']]) . '">Open register</a> <a class="btn btn-sm" href="' . url('rpt_register', ['run_id' => $r['id'], 'csv' => 1]) . '">CSV</a></td></tr>';
    }
    echo '</tbody></table></div>';
    page_footer();
    exit;
}
$run = payroll_run($runId);
if (!$run) {
    throw new RuntimeException('Payroll run not found.');
}
$items = rows('SELECT pi.*, e.employee_no, e.first_name, e.last_name, e.middle_name, e.suffix, e.tin, ou.name AS ou_name, b.code AS branch_code
               FROM payroll_items pi JOIN employees e ON e.id = pi.employee_id LEFT JOIN org_units ou ON ou.id = pi.org_unit_id LEFT JOIN branches b ON b.id = pi.default_branch_id
               WHERE pi.run_id = ?' . user_scope_sql('e') . ' ORDER BY ou.name, e.last_name, e.first_name', [$runId]);
$cols = ['basic_pay' => 'Basic', 'absence_deduction' => 'Absences', 'late_deduction' => 'Late', 'undertime_deduction' => 'Undertime', 'holiday_pay' => 'Holiday', 'premium_pay' => 'RD/Hol prem.',
    'ot_pay' => 'Overtime', 'nd_pay' => 'Night diff.', 'benefits_nontax' => 'De minimis / NT', 'benefits_other' => '13th & other (NT)', 'benefits_taxable' => 'Taxable benefits', 'other_earnings' => 'Other taxable',
    'gross_pay' => 'GROSS', 'sss_ee' => 'SSS', 'phic_ee' => 'PhilHealth', 'hdmf_ee' => 'Pag-IBIG', 'hdmf_ee_voluntary' => 'HDMF vol.', 'withholding_tax' => 'W/Tax',
    'other_deductions' => 'Other ded.', 'total_deductions' => 'Total ded.', 'net_pay' => 'NET PAY'];
if (get('csv')) {
    audit('payroll', 'export_register', 'payroll_runs', $runId);
    csv_download('payroll_register_' . $run['run_no'] . '.csv', array_merge(['Employee No', 'Name', 'Unit', 'Branch', 'TIN', 'Days'], array_values($cols)),
        array_map(fn($i) => array_merge([$i['employee_no'], full_name($i), $i['ou_name'], $i['branch_code'], $i['tin'], $i['days_worked']], array_map(fn($k) => number_format((float)$i[$k], 2, '.', ''), array_keys($cols))), $items));
}
$tot = array_fill_keys(array_keys($cols), 0.0);
print_header('Payroll Register ' . $run['run_no'], true);
echo company_letterhead();
?>
<h1>Payroll Register</h1>
<p class="center"><?= h($run['run_no']) ?>, <?= h(RUN_TYPES[$run['run_type']]) ?>, <?= h($run['pay_group_name']) ?>, Period <?= h(fmt_date($run['period_start'])) ?> – <?= h(fmt_date($run['period_end'])) ?>, Pay date <?= h(fmt_date($run['pay_date'])) ?>, Status: <?= h(strtoupper($run['status'])) ?></p>
<table class="doc-table" style="font-size:10px">
  <thead><tr><th>#</th><th>Employee</th><th class="num">Days</th><?php foreach ($cols as $c): ?><th class="num"><?= h($c) ?></th><?php endforeach; ?><th>Signature</th></tr></thead>
  <tbody>
  <?php foreach ($items as $n => $i): foreach ($cols as $k => $c) $tot[$k] += (float)$i[$k]; ?>
    <tr><td><?= $n + 1 ?></td><td class="nowrap"><?= h(full_name($i)) ?><br><span class="muted"><?= h($i['employee_no']) ?>, <?= h($i['branch_code']) ?></span></td><td class="num"><?= (float)$i['days_worked'] ?></td>
      <?php foreach ($cols as $k => $c): ?><td class="num"<?= in_array($k, ['gross_pay', 'net_pay'], true) ? ' style="font-weight:700"' : '' ?>><?= (float)$i[$k] != 0 ? money($i[$k]) : '' ?></td><?php endforeach; ?><td style="min-width:70px"></td></tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot><tr><td colspan="3">TOTAL (<?= count($items) ?> employees)</td><?php foreach ($cols as $k => $c): ?><td class="num"><?= money($tot[$k]) ?></td><?php endforeach; ?><td></td></tr></tfoot>
</table>
<div class="sig-row"><div class="sig"><div class="line">&nbsp;</div><small>Prepared by</small></div><div class="sig"><div class="line">&nbsp;</div><small>Checked by</small></div><div class="sig"><div class="line">&nbsp;</div><small>Approved by</small></div></div>
<?php print_footer();
