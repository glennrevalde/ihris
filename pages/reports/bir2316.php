<?php
/**
 * BIR Form 2316 — Certificate of Compensation Payment / Tax Withheld (system-generated summary layout).
 */
$empId = (int)get('employee_id');
$year = (int)get('year', date('Y'));
$e = employee_row($empId);
if (!$e) {
    throw new RuntimeException('Employee not found.');
}
$d = row("SELECT SUM(pi.gross_pay) gross, SUM(pi.basic_earned) basic, SUM(pi.holiday_pay) hol, SUM(pi.premium_pay) prem, SUM(pi.ot_pay) ot, SUM(pi.nd_pay) nd,
                 SUM(pi.benefits_other) other_nt, SUM(pi.benefits_nontax) deminimis, SUM(pi.benefits_taxable) ben_tax, SUM(pi.other_earnings) other_tax,
                 SUM(pi.sss_ee) sss, SUM(pi.phic_ee) phic, SUM(pi.hdmf_ee) hdmf, SUM(pi.taxable_income) taxable, SUM(pi.withholding_tax) tax,
                 SUM(CASE WHEN pi.is_mwe = 1 THEN pi.basic_earned ELSE 0 END) mwe_basic, SUM(CASE WHEN pi.is_mwe = 1 THEN pi.holiday_pay + pi.premium_pay + pi.ot_pay + pi.nd_pay ELSE 0 END) mwe_prem,
                 MIN(r.period_start) p_from, MAX(r.period_end) p_to
          FROM payroll_items pi JOIN payroll_runs r ON r.id = pi.run_id WHERE pi.employee_id = ? AND r.status = 'finalized' AND YEAR(r.period_end) = ?", [$empId, $year]);
$union = (float)val("SELECT COALESCE(SUM(l.amount),0) FROM payroll_item_lines l JOIN payroll_items pi ON pi.id = l.item_id JOIN payroll_runs r ON r.id = pi.run_id
                     WHERE pi.employee_id = ? AND r.status = 'finalized' AND YEAR(r.period_end) = ? AND l.code = 'DED_UNION'", [$empId, $year]);
$contrib = (float)$d['sss'] + (float)$d['phic'] + (float)$d['hdmf'] + $union;
$nontax = (float)$d['mwe_basic'] + (float)$d['mwe_prem'] + (float)$d['other_nt'] + (float)$d['deminimis'] + $contrib;
$prev = prev_employer_ytd($empId, $year);
$grossTaxable = round((float)$d['taxable'] + $prev['taxable'], 2);
$taxDue = compute_annual_tax($grossTaxable, $year . '-12-31');
$totalWithheld = round((float)$d['tax'] + $prev['tax'], 2);
$br = row('SELECT * FROM branches WHERE id = ?', [$e['default_branch_id']]);
audit('payroll', 'bir2316_generate', 'employees', $empId, ['year' => $year]);
print_header("BIR 2316 $year — " . full_name($e));
?>
<div class="letterhead"><div class="lh-name">BIR Form No. 2316</div><div class="lh-addr">Certificate of Compensation Payment / Tax Withheld — For Compensation Payment With or Without Tax Withheld</div></div>
<table class="doc-table"><tbody>
  <tr><th>For the year</th><td><?= $year ?></td><th>Period</th><td><?= h(fmt_date($d['p_from'], 'm/d')) ?> to <?= h(fmt_date($d['p_to'], 'm/d')) ?></td></tr>
</tbody></table>
<div class="grid grid-2">
  <div class="form-box"><h3>Part I — Employee information</h3>
    <table class="doc-table" style="border:0"><tbody><tr><td>TIN</td><td><strong><?= h($e['tin']) ?></strong></td></tr><tr><td>Employee name</td><td><strong><?= h(strtoupper(full_name($e))) ?></strong></td></tr>
      <tr><td>Registered address</td><td><?= h($e['address']) ?></td></tr><tr><td>Date of birth</td><td><?= h(fmt_date($e['birth_date'], 'm/d/Y')) ?></td></tr>
      <tr><td>Minimum wage earner</td><td><?= $e['is_mwe'] ? 'Yes' : 'No' ?></td></tr></tbody></table></div>
  <div class="form-box"><h3>Part II — Employer information (present)</h3>
    <table class="doc-table" style="border:0"><tbody><tr><td>TIN</td><td><strong><?= h($br['tin']) ?></strong></td></tr><tr><td>Employer name</td><td><strong><?= h($br['company_name']) ?></strong></td></tr>
      <tr><td>Registered address</td><td><?= h($br['address']) ?></td></tr><tr><td>Type</td><td>Main employer</td></tr></tbody></table></div>
</div>
<?php if ($prev['exists']): ?>
<div class="form-box"><h3>Part III — Employer information (previous)</h3>
  <table class="doc-table" style="border:0"><tbody><tr><td>TIN</td><td><strong><?= h($prev['employer_tin'] ?: '—') ?></strong></td><td>Employer name</td><td><strong><?= h($prev['employer_name'] ?: '—') ?></strong></td></tr>
    <tr><td>Gross compensation</td><td class="num"><?= money($prev['gross']) ?></td><td>Taxable compensation / tax withheld</td><td class="num"><?= money($prev['taxable']) ?> / <?= money($prev['tax']) ?></td></tr></tbody></table></div>
<?php endif; ?>
<div class="grid grid-2">
  <div class="form-box"><h3>Part IV-B — Details of compensation income</h3>
    <table class="doc-table"><tbody>
      <tr><th colspan="2">A. Non-taxable / exempt compensation income</th></tr>
      <tr><td>27. Basic salary (statutory minimum wage) — MWE</td><td class="num"><?= money($d['mwe_basic']) ?></td></tr>
      <tr><td>28–31. Holiday / OT / night differential / hazard pay — MWE</td><td class="num"><?= money($d['mwe_prem']) ?></td></tr>
      <tr><td>32. 13th month pay and other benefits (≤ ₱<?= number_format(setting_num('nontax_benefit_ceiling', 90000)) ?>)</td><td class="num"><?= money($d['other_nt']) ?></td></tr>
      <tr><td>33. De minimis benefits</td><td class="num"><?= money($d['deminimis']) ?></td></tr>
      <tr><td>34. SSS, GSIS, PHIC &amp; Pag-IBIG contributions and union dues (employee share)</td><td class="num"><?= money($contrib) ?></td></tr>
      <tr><td>35. Salaries and other forms of compensation</td><td class="num">0.00</td></tr>
      <tr><th>36. Total non-taxable / exempt compensation</th><td class="num"><strong><?= money($nontax) ?></strong></td></tr>
      <tr><th colspan="2">B. Taxable compensation income (regular)</th></tr>
      <tr><td>37. Basic salary</td><td class="num"><?= money((float)$d['basic'] - (float)$d['mwe_basic']) ?></td></tr>
      <tr><td>42–45. Overtime, premium, holiday pay, night differential</td><td class="num"><?= money((float)$d['hol'] + (float)$d['prem'] + (float)$d['ot'] + (float)$d['nd'] - (float)$d['mwe_prem']) ?></td></tr>
      <tr><td>51. Taxable 13th month &amp; other benefits (excess)</td><td class="num"><?= money($d['ben_tax']) ?></td></tr>
      <tr><td>47A. Other taxable compensation (allowances, retro pay, adjustments)</td><td class="num"><?= money($d['other_tax']) ?></td></tr>
    </tbody></table></div>
  <div class="form-box"><h3>Part IV-A — Summary</h3>
    <table class="doc-table"><tbody>
      <tr><td>19. Gross compensation income from present employer</td><td class="num"><?= money($d['gross']) ?></td></tr>
      <tr><td>20. Less: total non-taxable / exempt compensation</td><td class="num"><?= money($nontax) ?></td></tr>
      <tr><td>21. Taxable compensation income from present employer</td><td class="num"><?= money($d['taxable']) ?></td></tr>
      <tr><td>22. Add: taxable compensation from previous employer</td><td class="num"><?= money($prev['taxable']) ?></td></tr>
      <tr><td>23. Gross taxable compensation income</td><td class="num"><?= money($grossTaxable) ?></td></tr>
      <tr><th>24. Tax due</th><td class="num"><strong><?= money($taxDue) ?></strong></td></tr>
      <tr><td>25A. Amount of taxes withheld — present employer</td><td class="num"><?= money($d['tax']) ?></td></tr>
      <tr><td>25B. Amount of taxes withheld — previous employer</td><td class="num"><?= money($prev['tax']) ?></td></tr>
      <tr><td>26. Total amount of taxes withheld as adjusted</td><td class="num"><?= money($totalWithheld) ?></td></tr>
      <tr><th><?= $taxDue - $totalWithheld >= 0 ? 'Tax still due (to be withheld on year-end adjustment)' : 'Excess withholding to be refunded' ?></th><td class="num"><strong><?= money(abs($taxDue - $totalWithheld)) ?></strong></td></tr>
    </tbody></table>
    <p class="small muted">Figures are computed from finalized payroll runs<?= $prev['exists'] ? ' and the YTD Information for Previous Employer' : '' ?>. Line 21 is the sum of per-period taxable compensation (gross less non-taxable items and mandatory contributions).</p></div>
</div>
<div class="sig-row">
  <div class="sig"><div class="line">&nbsp;</div><small>Present employer / authorized agent — signature over printed name / date signed</small></div>
  <div class="sig"><div class="line"><?= h(full_name($e, false)) ?></div><small>Employee — signature over printed name / date signed</small></div>
</div>
<?php print_footer();
