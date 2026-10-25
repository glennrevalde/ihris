<?php
/**
 * Payslip — consolidated payslip plus one payslip per branch worked (M.A.D.A.S. layout).
 * HTML (printable) and PDF download; reprints are marked ( COPY ).
 */
$staff = can_any(['payroll_runs.view', 'pay_register.view', 'payslips_print.view', 'pay_inquiry.view']);
$id = (int)get('id');
$it = $id ? payslip_item($id) : null;
if (!$it) {
    throw new RuntimeException('Payslip not found.');
}
if (!$staff && ((int)$it['employee_id'] !== my_employee_id() || $it['run_status'] !== 'finalized')) {
    http_response_code(403);
    exit('Access denied.');
}
$branchId = get('branch') ? (int)get('branch') : null;
$copy = payslip_is_copy($it);

if (get('pdf')) {
    payslip_mark_printed($id);
    $pdf = payslip_pdf($it, $branchId, $copy);
    audit('payroll', 'payslip_download', 'payroll_items', $id);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="payslip_' . $it['employee_no'] . '_' . $it['period_end'] . ($branchId ? '_branch' . $branchId : '') . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}
if (get('print')) {
    payslip_mark_printed($id);
    audit('payroll', 'payslip_print', 'payroll_items', $id);
    print_header('Payslip — ' . full_name($it, false));
    echo payslip_html($it, ['branch_id' => $branchId, 'copy' => $copy]);
    print_footer();
    exit;
}

page_header('Payslip — ' . full_name($it, false), [
    'subtitle' => h($it['run_no']) . ', ' . h(RUN_TYPES[$it['run_type']] ?? $it['run_type']) . ', ' . h(fmt_date($it['period_start'])) . ' – ' . h(fmt_date($it['period_end']))
        . ((int)$it['print_count'] > 0 ? ', printed ' . (int)$it['print_count'] . '× (last ' . h(fmt_dt($it['last_printed_at'])) . ')' : ''),
    'back' => back_url() ?: ($staff ? url('payroll_run', ['id' => $it['run_id']]) : url('my_payslips')),
    'back_label' => back_url() ? 'Back to ' . (menu_location((string)back_route())[1] ?: 'previous screen') : 'Back',
    'actions' => '<a class="btn btn-primary" href="' . url('payslip', ['id' => $it['id'], 'pdf' => 1]) . '">Download PDF</a>'
        . '<a class="btn" target="_blank" href="' . url('payslip', ['id' => $it['id'], 'print' => 1]) . '">Print</a>'
        . ($staff ? '<a class="btn" href="' . url('pay_inquiry', ['emp' => $it['employee_id']]) . '">Payroll inquiry</a>' : ''),
]);
if (count($it['branches']) > 1): ?>
  <div class="tabs"<?= $branchId ? ' data-initial="ps-b' . (int)$branchId . '"' : '' ?>>
    <button class="tab" data-tab="ps-all">Consolidated</button>
    <?php foreach ($it['branches'] as $b): ?><button class="tab" data-tab="ps-b<?= (int)$b['branch_id'] ?>"><?= h($b['branch_name']) ?></button><?php endforeach; ?>
  </div>
  <div class="tab-panel" id="ps-all"><div class="card"><?= payslip_html($it, ['copy' => $copy, 'drill' => true]) ?></div></div>
  <?php foreach ($it['branches'] as $b): ?>
    <div class="tab-panel" id="ps-b<?= (int)$b['branch_id'] ?>">
      <div class="card">
        <?= payslip_html($it, ['branch_id' => (int)$b['branch_id'], 'copy' => $copy]) ?>
        <div class="form-actions">
          <a class="btn btn-sm" href="<?= h(url('payslip', ['id' => $it['id'], 'pdf' => 1, 'branch' => $b['branch_id']])) ?>">Download branch payslip (PDF)</a>
          <a class="btn btn-sm" target="_blank" href="<?= h(url('payslip', ['id' => $it['id'], 'print' => 1, 'branch' => $b['branch_id']])) ?>">Print branch payslip</a>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
<?php else: ?>
  <div class="card"><?= payslip_html($it, ['copy' => $copy, 'drill' => true]) ?></div>
<?php endif;
$tb = payslip_tax_breakdown($it);
$baseLabel = ['gross_less_govt' => 'taxable earnings less the mandatory contributions', 'basic_less_govt' => 'basic pay earned less the mandatory contributions', 'gross' => 'taxable earnings (contributions not deducted)'][$tb['base']] ?? $tb['base'];
?>
<div class="card tax-drill" id="taxDrill">
  <div class="card-head"><div><h2>Current taxable income, how it was computed</h2><div class="sub">The drill-down of the Cur. Taxable Income and Current Tax figures on this payslip. Taxable base rule: <?= h($baseLabel) ?>.</div></div>
    <a class="btn btn-sm" href="#taxDrill">Cur. Taxable Income <?= h(number_format($tb['taxable'], 2)) ?></a></div>
  <div class="grid grid-2">
    <div>
      <h3>Taxable earnings</h3>
      <table class="table compact"><tbody>
      <?php if (!$tb['taxable_lines']) echo empty_row(2, 'No taxable earning on this payslip.'); foreach ($tb['taxable_lines'] as $l): ?>
        <tr><td><?= h($l['description'] ?: $l['code']) ?></td><td class="num"><?= h(number_format((float)$l['amount'], 2)) ?></td></tr>
      <?php endforeach; ?>
      <tr class="row-sub"><td>Total taxable earnings</td><td class="num"><?= h(number_format($tb['sum_taxable'], 2)) ?></td></tr>
      <?php if ($tb['base'] !== 'gross'): foreach ($tb['mandatory'] as [$lbl, $amt]): ?>
        <tr><td>Less: <?= h($lbl) ?></td><td class="num">−<?= h(number_format($amt, 2)) ?></td></tr>
      <?php endforeach; endif; ?>
      <tr class="row-sub"><td><strong>Cur. Taxable Income</strong><?= abs($tb['derived'] - $tb['taxable']) > 0.01 ? '<br><span class="small muted">The stored figure differs from the plain sum by ' . h(number_format($tb['taxable'] - $tb['derived'], 2)) . ' because of a special rule of this pay (overtime not taxable, exemption, or a fixed tax base).</span>' : '' ?></td><td class="num"><strong><?= h(number_format($tb['taxable'], 2)) ?></strong></td></tr>
      </tbody></table>
      <?php if ($tb['nontax_lines']): ?>
        <h3 style="margin-top:14px">Not taxable (excluded from the base)</h3>
        <table class="table compact"><tbody>
        <?php foreach ($tb['nontax_lines'] as $l): ?><tr><td><?= h($l['description'] ?: $l['code']) ?></td><td class="num"><?= h(number_format((float)$l['amount'], 2)) ?></td></tr><?php endforeach; ?>
        <tr class="row-sub"><td>Total non-taxable earnings</td><td class="num"><?= h(number_format($tb['sum_nontax'], 2)) ?></td></tr>
        </tbody></table>
      <?php endif; ?>
    </div>
    <div>
      <h3>Withholding tax on it</h3>
      <?php if ($tb['exempt']): ?>
        <p>This employee is exempt from withholding tax (statutory minimum wage earner or exemption code), so no tax is withheld whatever the taxable income.</p>
      <?php elseif ($tb['bracket']): $b = $tb['bracket']; ?>
        <dl class="kv">
          <dt>Tax table</dt><dd><?= h(label($tb['table'])) ?> (BIR withholding tax table effective <?= h(fmt_date($b['effective_from'])) ?>)</dd>
          <dt>Bracket</dt><dd>Over <?= h(number_format((float)$b['comp_from'], 2)) ?><?= $b['comp_to'] !== null ? ' up to ' . h(number_format((float)$b['comp_to'], 2)) : ' and above' ?></dd>
          <dt>Rule</dt><dd><?= h(number_format((float)$b['fixed_tax'], 2)) ?> + <?= h(rtrim(rtrim(number_format((float)$b['rate_pct'], 2), '0'), '.')) ?>% of the excess over <?= h(number_format((float)$b['comp_from'], 2)) ?></dd>
          <dt>Computation</dt><dd><?= h(number_format((float)$b['fixed_tax'], 2)) ?> + (<?= h(number_format($tb['taxable'], 2)) ?> − <?= h(number_format((float)$b['comp_from'], 2)) ?>) × <?= h(rtrim(rtrim(number_format((float)$b['rate_pct'], 2), '0'), '.')) ?>% = <strong><?= h(number_format($tb['table_tax'], 2)) ?></strong></dd>
          <dt>Tax withheld</dt><dd><strong><?= h(number_format($tb['tax'], 2)) ?></strong><?= abs($tb['table_tax'] - $tb['tax']) > 0.01 ? '<br><span class="small muted">Differs from the table figure because of the pay code tax mode (' . h(['every' => 'every pay', 'first' => '1st pay of the month', 'second' => '2nd pay of the month'][$tb['tax_mode']] ?? $tb['tax_mode']) . ', month-to-date true-up), a fixed tax, or a year-end annualization. ' . h($tb['tax_line']['description'] ?? '') . '</span>' : '' ?></dd>
        </dl>
      <?php else: ?>
        <p>The taxable income is below the first bracket of the <?= h(label($tb['table'])) ?> table, so no tax is due. Tax withheld: <strong><?= h(number_format($tb['tax'], 2)) ?></strong><?= $tb['tax_line'] ? ' (' . h($tb['tax_line']['description']) . ')' : '' ?>.</p>
      <?php endif; ?>
      <?php foreach ($tb['notes'] as $n): ?><p class="help"><?= h($n) ?></p><?php endforeach; ?>
      <?php if (!$tb['ot_taxable']): ?><p class="help">Company rule: overtime, premium and night differential pay are not part of the taxable base.</p><?php endif; ?>
    </div>
  </div>
</div>
<?php page_footer();
