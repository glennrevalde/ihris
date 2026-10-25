<?php
/**
 * Year-to-date report per employee: cumulative gross pay, deductions and tax withheld — the basis of
 * BIR Form 2316 and the annual alphalist.
 */
$year = (int)get('year', date('Y'));
$employer = get('employer', '');
$params = [$year];
$empWhere = '';
if ($employer !== '') {
    $cn = val('SELECT company_name FROM branches WHERE id = ?', [$employer]);
    $ids = array_map('intval', q('SELECT id FROM branches WHERE company_name = ?', [$cn])->fetchAll(PDO::FETCH_COLUMN));
    $empWhere = ' AND pi.default_branch_id IN (' . implode(',', $ids) . ')';
}
$data = rows("SELECT e.id, e.employee_no, e.last_name, e.first_name, e.middle_name, e.suffix, e.tin, e.status, e.date_separated, e.is_mwe,
                     SUM(pi.gross_pay) gross, SUM(pi.basic_earned) basic, SUM(pi.holiday_pay + pi.premium_pay + pi.ot_pay + pi.nd_pay) prem,
                     SUM(pi.benefits_other) other_nt, SUM(pi.benefits_nontax) deminimis, SUM(pi.benefits_taxable + pi.other_earnings) other_tax,
                     SUM(pi.sss_ee) sss, SUM(pi.phic_ee) phic, SUM(pi.hdmf_ee) hdmf, SUM(pi.taxable_income) taxable, SUM(pi.withholding_tax) tax, SUM(pi.net_pay) net,
                     SUM(CASE WHEN pi.is_mwe = 1 THEN pi.basic_earned + pi.holiday_pay + pi.premium_pay + pi.ot_pay + pi.nd_pay ELSE 0 END) mwe
              FROM payroll_items pi JOIN payroll_runs r ON r.id = pi.run_id JOIN employees e ON e.id = pi.employee_id
              WHERE r.status = 'finalized' AND YEAR(r.period_end) = ?$empWhere" . user_scope_sql('e') . "
              GROUP BY e.id, e.employee_no, e.last_name, e.first_name, e.middle_name, e.suffix, e.tin, e.status, e.date_separated, e.is_mwe ORDER BY e.last_name, e.first_name", $params);
foreach ($data as &$d) {
    // previous employer of the year (YTD Information for Previous Employer)
    $pv = prev_employer_ytd((int)$d['id'], $year);
    $d['prev_taxable'] = $pv['taxable'];
    $d['prev_tax'] = $pv['tax'];
    $d['tax_due'] = compute_annual_tax((float)$d['taxable'] + $pv['taxable'], $year . '-12-31');
    $d['diff'] = round($d['tax_due'] - (float)$d['tax'] - $pv['tax'], 2);
}
unset($d);
if (get('alphalist')) {
    audit('payroll', 'export_alphalist', 'report', null, ['year' => $year]);
    csv_download("alphalist_$year.csv", ['Seq', 'TIN', 'Last Name', 'First Name', 'Middle Name', 'Status', 'Gross Compensation', 'Non-Taxable 13th & Other Benefits', 'De Minimis',
        'SSS/PHIC/HDMF & Union Dues', 'MWE Compensation (exempt)', 'Taxable Compensation', 'Taxable (previous employer)', 'Tax Due', 'Tax Withheld', 'Tax Withheld (previous employer)', 'Over/(Under) Withholding'],
        array_map(fn($d, $i) => [$i + 1, $d['tin'], $d['last_name'], $d['first_name'], $d['middle_name'], $d['status'] === 'separated' ? 'Separated ' . $d['date_separated'] : 'Employed',
            $d['gross'], $d['other_nt'], $d['deminimis'], round($d['sss'] + $d['phic'] + $d['hdmf'], 2), $d['mwe'], $d['taxable'], $d['prev_taxable'], $d['tax_due'], $d['tax'], $d['prev_tax'], round($d['tax'] + $d['prev_tax'] - $d['tax_due'], 2)], $data, array_keys($data)));
}
page_header('Year-to-Date & BIR 2316', ['subtitle' => 'Cumulative figures from finalized payroll runs. Tax due is the annualized tax on total taxable compensation (TRAIN annual table).',
    'actions' => '<a class="btn" href="' . url('rpt_ytd', ['year' => $year, 'employer' => $employer, 'alphalist' => 1]) . '">Export alphalist (CSV)</a> <button class="btn" onclick="window.print()">Print</button>']);
?>
<form class="card filters" method="get"><input type="hidden" name="p" value="rpt_ytd">
  <label>Year<input type="number" name="year" value="<?= $year ?>" style="width:100px"></label>
  <label>Employer<select name="employer"><?= options(pairs('SELECT MIN(id), company_name FROM branches GROUP BY company_name'), $employer, 'All') ?></select></label>
  <button class="btn btn-primary">Show</button></form>
<div class="card"><div class="table-wrap"><table class="table compact">
  <thead><tr><th>Employee</th><th class="num">Gross</th><th class="num">Basic earned</th><th class="num">OT/Prem/Hol/ND</th><th class="num">13th &amp; other (NT)</th><th class="num">De minimis</th><th class="num">Contributions</th><th class="num">Taxable</th><th class="num">Withheld</th><th class="num">Prev. employer (taxable / tax)</th><th class="num">Annual tax due</th><th class="num">Due / (refund)</th><th></th></tr></thead><tbody>
  <?php if (!$data) echo empty_row(13, 'No finalized payroll for this year.'); foreach ($data as $d): ?>
    <tr><td class="nowrap"><?= h(full_name($d)) ?><br><span class="muted small"><?= h($d['tin']) ?><?= $d['is_mwe'] ? ', MWE' : '' ?></span></td><td class="num"><?= money($d['gross']) ?></td><td class="num"><?= money($d['basic']) ?></td><td class="num"><?= money($d['prem']) ?></td>
      <td class="num"><?= money($d['other_nt']) ?></td><td class="num"><?= money($d['deminimis']) ?></td><td class="num"><?= money($d['sss'] + $d['phic'] + $d['hdmf']) ?></td><td class="num"><?= money($d['taxable']) ?></td>
      <td class="num"><?= money($d['tax']) ?></td><td class="num small"><?= $d['prev_taxable'] || $d['prev_tax'] ? money($d['prev_taxable']) . ' / ' . money($d['prev_tax']) : '' ?></td><td class="num"><?= money($d['tax_due']) ?></td><td class="num"><?= $d['diff'] != 0 ? '<span class="badge badge-' . ($d['diff'] > 0 ? 'amber' : 'blue') . '">' . money($d['diff']) . '</span>' : '0.00' ?></td>
      <td class="actions"><a class="btn btn-sm" target="_blank" href="<?= url('rpt_2316', ['employee_id' => $d['id'], 'year' => $year]) ?>">BIR 2316</a></td></tr>
  <?php endforeach; ?></tbody>
  <?php if ($data): $s = fn($k) => array_sum(array_column($data, $k)); ?><tfoot><tr><td>Total</td><td class="num"><?= money($s('gross')) ?></td><td class="num"><?= money($s('basic')) ?></td><td class="num"><?= money($s('prem')) ?></td><td class="num"><?= money($s('other_nt')) ?></td><td class="num"><?= money($s('deminimis')) ?></td>
    <td class="num"><?= money($s('sss') + $s('phic') + $s('hdmf')) ?></td><td class="num"><?= money($s('taxable')) ?></td><td class="num"><?= money($s('tax')) ?></td><td class="num small"><?= money($s('prev_taxable')) ?> / <?= money($s('prev_tax')) ?></td><td class="num"><?= money($s('tax_due')) ?></td><td class="num"><?= money($s('diff')) ?></td><td></td></tr></tfoot><?php endif; ?>
</table></div>
<p class="small muted">A positive difference is additional tax to be withheld in the last payroll of the year (or refunded if negative) upon year-end adjustment.</p></div>
<?php page_footer();
