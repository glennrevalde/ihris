<?php
$me = my_employee_id();
if (!$me) {
    throw new RuntimeException('Your account is not linked to an employee record.');
}
$list = rows('SELECT * FROM ledgers WHERE employee_id = ? ORDER BY status, created_at DESC', [$me]);
page_header('My Loans & Ledgers', ['subtitle' => 'Running balances of your loans, cash advances, payables and accrued benefits.',
    'actions' => '<a class="btn" target="_blank" href="' . h(url('ledger_print', ['employee_id' => my_employee_id()])) . '">Print my ledgers</a>']);
?>
<div class="card"><table class="table"><thead><tr><th>Ledger</th><th>Type</th><th>Description</th><th class="num">Total</th><th class="num">Amortization</th><th class="num">Balance</th><th>Status</th><th></th></tr></thead><tbody>
<?php if (!$list) echo empty_row(8, 'No ledgers.'); foreach ($list as $l): ?>
  <tr><td><?= h($l['ledger_no']) ?></td><td><?= h(LEDGER_TYPES[$l['ledger_type']]) ?></td><td><?= h($l['sub_type']) ?></td><td class="num"><?= $l['ledger_type'] === 'accrued_benefit' ? '' : money($l['total_payable']) ?></td>
    <td class="num"><?= $l['amortization'] > 0 ? money($l['amortization']) : '' ?></td><td class="num"><strong><?= money($l['balance']) ?></strong></td><td><?= badge($l['status']) ?></td>
    <td class="actions"><a class="btn btn-sm" href="<?= url('ledger_view', ['id' => $l['id']]) ?>">Details</a></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php page_footer();
