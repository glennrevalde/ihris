<?php
/**
 * Printed employee ledger: the employee (no., name, department, position), each subsidiary ledger
 * (ledger no., ledger type, sub-type, reference, terms, principal, interest, amortization, status) and
 * its entries with the running balance and the totals.
 *   ?id=…           one ledger
 *   ?employee_id=…  every ledger of the employee (&status=active|paid|cancelled|all, default all but cancelled)
 * Authorized by index.php: ledgers.print, or the employee's own ledgers.
 */
$id = (int)get('id', 0);
$empId = (int)get('employee_id', 0);
$status = (string)get('status', '');
if ($id) {
    $ledgers = rows('SELECT * FROM ledgers WHERE id = ?', [$id]);
    $empId = $ledgers ? (int)$ledgers[0]['employee_id'] : 0;
} else {
    $where = ['employee_id = ?'];
    $params = [$empId];
    if (in_array($status, ['active', 'paid', 'cancelled'], true)) {
        $where[] = 'status = ?';
        $params[] = $status;
    } elseif ($status !== 'all') {
        $where[] = "status <> 'cancelled'";
    }
    $ledgers = rows('SELECT * FROM ledgers WHERE ' . implode(' AND ', $where) . ' ORDER BY FIELD(ledger_type, \'gov_loan\', \'company_loan\', \'cash_advance\', \'payable\', \'accrued_benefit\'), start_date, id', $params);
}
$e = $empId ? employee_row($empId) : null;
if (!$e) {
    throw new RuntimeException('Employee not found.');
}
if (!can('ledgers.print') && $empId !== my_employee_id()) {
    perm_deny();
}
audit('payroll', 'ledger_print', 'employees', $empId, ['ledgers' => array_column($ledgers, 'ledger_no')]);
print_header('Employee Ledger — ' . full_name($e, false));
echo company_letterhead(row('SELECT * FROM branches WHERE id = ?', [$e['default_branch_id']]));
?>
<h1>Employee Ledger</h1>
<table class="doc-table led-head"><tbody>
  <tr><th>Employee no.</th><td><strong><?= h($e['employee_no']) ?></strong></td><th>Employee name</th><td><strong><?= h(full_name($e, false)) ?></strong></td></tr>
  <tr><th>Department</th><td><?= h($e['org_unit_name'] ?? '') ?></td><th>Position</th><td><?= h($e['position'] ?? '') ?></td></tr>
  <tr><th>Printed</th><td><?= h(fmt_dt(now())) ?></td><th>Ledgers</th><td><?= count($ledgers) ?></td></tr>
</tbody></table>
<?php if (!$ledgers): ?>
  <p class="muted">No ledger for this employee.</p>
<?php endif; ?>
<?php foreach ($ledgers as $l):
    $isAccrual = $l['ledger_type'] === 'accrued_benefit';
    $entries = rows('SELECT le.*, r.run_no FROM ledger_entries le LEFT JOIN payroll_runs r ON r.id = le.payroll_run_id WHERE le.ledger_id = ? ORDER BY le.entry_date, le.id', [$l['id']]);
    $inc = array_sum(array_column($entries, 'increase'));
    $dec = array_sum(array_column($entries, 'decrease')); ?>
  <section class="led-block">
    <div class="led-title">
      <div><span class="led-no"><?= h($l['ledger_no']) ?></span> <strong><?= h($l['sub_type']) ?></strong></div>
      <div class="led-type"><?= h(LEDGER_TYPES[$l['ledger_type']] ?? $l['ledger_type']) ?> &nbsp;|&nbsp; <?= h(label($l['status'])) ?></div>
    </div>
    <table class="doc-table"><tbody>
      <tr><th>Ledger type</th><td><?= h(LEDGER_TYPES[$l['ledger_type']] ?? $l['ledger_type']) ?></td><th>Reference</th><td><?= h($l['reference_no'] ?: '—') ?></td><th>Start date</th><td><?= h(fmt_date($l['start_date'])) ?></td></tr>
      <?php if (!$isAccrual): ?>
      <tr><th>Principal</th><td class="num"><?= money($l['principal']) ?></td><th>Interest</th><td class="num"><?= (float)$l['interest_rate_pct'] ? (float)$l['interest_rate_pct'] . '% , ' . money((float)$l['total_payable'] - (float)$l['principal']) : '—' ?></td><th>Total payable</th><td class="num"><?= money($l['total_payable']) ?></td></tr>
      <tr><th>Amortization</th><td class="num"><?= money($l['amortization']) ?></td><th>Term</th><td><?= $l['term_months'] ? (int)$l['term_months'] . ' month(s)' : '—' ?></td><th>Full payment</th><td><?= h($l['full_payment_date'] ? fmt_date($l['full_payment_date']) : '—') ?></td></tr>
      <?php endif; ?>
    </tbody></table>
    <table class="doc-table led-entries">
      <thead><tr><th>Date</th><th>Description</th><th>Payroll</th><th class="num"><?= $isAccrual ? 'Accrual' : 'Charge' ?></th><th class="num"><?= $isAccrual ? 'Disbursement' : 'Payment' ?></th><th class="num">Balance</th></tr></thead>
      <tbody>
      <?php if (!$entries): ?><tr><td colspan="6" class="muted">No entry.</td></tr><?php endif; ?>
      <?php foreach ($entries as $x): ?>
        <tr><td class="nowrap"><?= h(fmt_date($x['entry_date'])) ?></td><td><?= h($x['description']) ?></td><td class="nowrap"><?= h($x['run_no'] ?? '') ?></td>
          <td class="num"><?= (float)$x['increase'] ? money($x['increase']) : '' ?></td><td class="num"><?= (float)$x['decrease'] ? money($x['decrease']) : '' ?></td><td class="num"><?= money($x['balance']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="3">Totals</td><td class="num"><?= money($inc) ?></td><td class="num"><?= money($dec) ?></td><td class="num"><?= money($l['balance']) ?></td></tr></tfoot>
    </table>
  </section>
<?php endforeach; ?>
<?php if (count($ledgers) > 1):
    $open = array_filter($ledgers, fn($l) => $l['ledger_type'] !== 'accrued_benefit' && $l['status'] === 'active'); ?>
  <table class="doc-table"><tbody>
    <tr><th>Outstanding loans and payables</th><td class="num"><strong><?= money(array_sum(array_column($open, 'balance'))) ?></strong></td>
      <th>Accrued benefits</th><td class="num"><strong><?= money(array_sum(array_column(array_filter($ledgers, fn($l) => $l['ledger_type'] === 'accrued_benefit'), 'balance'))) ?></strong></td></tr>
  </tbody></table>
<?php endif; ?>
<?= signatory_block() ?>
<?php print_footer();
