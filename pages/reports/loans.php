<?php
/**
 * Loan & Ledger Balances — outstanding government loans, company loans, cash advances and
 * accountabilities per employee, with the amortization deducted every payroll.
 * v16: Co. / Branch and Employee group filters.
 */
$typeF = (string)get('ledger_type', '');
$statusF = (string)get('status', 'active');
$unitF = (int)get('org_unit_id', 0);
$branchF = (int)get('branch_id', 0);
$groupF = (int)get('employee_group_id', 0);
$where = ["l.ledger_type <> 'accrued_benefit'"];
$params = [];
if ($typeF) {
    $where[] = 'l.ledger_type = ?';
    $params[] = $typeF;
}
if ($statusF) {
    $where[] = 'l.status = ?';
    $params[] = $statusF;
}
if ($unitF) {
    $where[] = 'e.org_unit_id IN (' . implode(',', array_map('intval', org_descendant_ids($unitF))) . ')';
}
if ($branchF) {
    $where[] = 'e.default_branch_id = ?';
    $params[] = $branchF;
}
if ($groupF) {
    $where[] = 'e.employee_group_id = ?';
    $params[] = $groupF;
}
$selF = ['branch_id' => $branchF ?: null, 'org_unit_id' => $unitF ?: null, 'employee_group_id' => $groupF ?: null];
$qF = array_filter(['ledger_type' => $typeF, 'status' => $statusF] + $selF);
$list = rows('SELECT l.*, e.employee_no, e.first_name, e.last_name, e.middle_name, e.suffix, ou.name AS unit_name,
                     (SELECT COALESCE(SUM(amount),0) FROM transaction_payments tp WHERE tp.ledger_id = l.id) AS paid_outside
              FROM ledgers l JOIN employees e ON e.id = l.employee_id LEFT JOIN org_units ou ON ou.id = e.org_unit_id
              WHERE ' . implode(' AND ', $where) . user_scope_sql('e') . ' ORDER BY e.last_name, e.first_name, l.ledger_type', $params);
$tot = ['principal' => 0.0, 'balance' => 0.0, 'amort' => 0.0];
foreach ($list as $l) {
    $tot['principal'] += (float)$l['principal'];
    $tot['balance'] += (float)$l['balance'];
    $tot['amort'] += (float)$l['amortization'];
}
if (get('csv')) {
    csv_download('loan_balances.csv', ['Employee no.', 'Employee', 'Department', 'Ledger no.', 'Type', 'Sub-type', 'Principal', 'Amortization', 'Balance', 'Status', 'Paid outside payroll'],
        array_map(fn($l) => [$l['employee_no'], full_name($l), $l['unit_name'], $l['ledger_no'], label($l['ledger_type']), $l['sub_type'],
            number_format((float)$l['principal'], 2, '.', ''), number_format((float)$l['amortization'], 2, '.', ''), number_format((float)$l['balance'], 2, '.', ''),
            $l['status'], number_format((float)$l['paid_outside'], 2, '.', '')], $list));
}
if (get('print')) {
    print_header('Loan & Ledger Balances', true);
    echo company_letterhead();
    echo '<h1>Loan and Ledger Balances</h1>';
    echo '<div class="doc-meta"><div>' . h($statusF ? label($statusF) . ' ledgers' : 'All ledgers') . ($typeF ? ', ' . h(label($typeF)) : '') . '</div><div>As of ' . h(fmt_date(today())) . '</div></div>';
    echo report_selection_html($selF, ['Ledger type' => $typeF ? label($typeF) : null, 'Status' => $statusF ? label($statusF) : 'All'], true);
    echo '<table class="doc-table"><thead><tr><th>Emp. no.</th><th>Employee</th><th>Department</th><th>Ledger</th><th>Type</th><th class="num">Principal</th><th class="num">Amort.</th><th class="num">Balance</th></tr></thead><tbody>';
    foreach ($list as $l) {
        echo '<tr><td>' . h($l['employee_no']) . '</td><td>' . h(full_name($l)) . '</td><td>' . h($l['unit_name']) . '</td><td>' . h($l['sub_type'] ?: $l['ledger_no']) . '</td><td>' . h(label($l['ledger_type']))
            . '</td><td class="num">' . h(money($l['principal'])) . '</td><td class="num">' . h(money($l['amortization'])) . '</td><td class="num">' . h(money($l['balance'])) . '</td></tr>';
    }
    echo '</tbody><tfoot><tr><td colspan="5">TOTAL (' . count($list) . ')</td><td class="num">' . h(money($tot['principal'])) . '</td><td class="num">' . h(money($tot['amort'])) . '</td><td class="num">' . h(money($tot['balance'])) . '</td></tr></tfoot></table>';
    echo signatory_block();
    print_footer();
    exit;
}
page_header('Loan & Ledger Balances', [
    'subtitle' => 'Outstanding balances and the amount recovered through payroll every period.',
    'actions' => '<a class="btn" target="_blank" href="' . url('rpt_loans', $qF + ['print' => 1]) . '">Print</a>'
        . '<a class="btn" href="' . url('rpt_loans', $qF + ['csv' => 1]) . '">Export CSV</a>'
        . '<a class="btn" href="' . url('trans_payments') . '">Transaction payment</a>',
]);
?>
<form method="get" class="card filters">
  <input type="hidden" name="p" value="rpt_loans">
  <label>Type<select name="ledger_type" data-autosubmit><?= options(['gov_loan' => 'Government loan', 'company_loan' => 'Company loan', 'cash_advance' => 'Cash advance', 'accountability' => 'Accountability'], $typeF, 'All types') ?></select></label>
  <label>Status<select name="status" data-autosubmit><?= options(['active' => 'Active', 'paid' => 'Fully paid', 'written_off' => 'Written off'], $statusF, 'All') ?></select></label>
  <label>Co. / Branch<select name="branch_id" data-autosubmit><?= options(pairs("SELECT id, CONCAT(COALESCE(branch_code, code), ' — ', name) FROM branches WHERE is_active = 1 ORDER BY company_code, branch_code, code"), $branchF ?: null, 'All companies') ?></select></label>
  <label>Department<select name="org_unit_id" data-autosubmit><?= options(pairs('SELECT id, name FROM org_units WHERE is_active = 1 ORDER BY name'), $unitF ?: null, 'All departments') ?></select></label>
  <label>Employee group<select name="employee_group_id" data-autosubmit><?= options(emp_group_options(), $groupF ?: null, 'All groups') ?></select></label>
</form>

<div class="stats">
  <?= stat_card('Ledgers', number_format(count($list)), h(label($statusF ?: 'all')), 'info') ?>
  <?= stat_card('Principal', money($tot['principal']), 'granted amount') ?>
  <?= stat_card('Outstanding', money($tot['balance']), 'still to recover', 'warn') ?>
  <?= stat_card('Per payroll', money($tot['amort']), 'total amortization') ?>
</div>

<div class="card flush">
  <div class="card-head"><div><h2>Balances</h2><?= report_selection_html($selF, ['Ledger type' => $typeF ? label($typeF) : null, 'Status' => $statusF ? label($statusF) : 'All']) ?></div><input type="search" class="table-search" data-target="loanTable" placeholder="Find employee…" style="width:220px"></div>
  <div class="table-wrap table-scroll">
    <table class="table table-compact" id="loanTable">
      <thead><tr><th>Employee</th><th>Ledger</th><th>Type</th><th class="num">Principal</th><th class="num">Amortization</th><th class="num">Balance</th><th class="num">Paid outside</th><th>Status</th><th class="actions"></th></tr></thead>
      <tbody>
      <?php if (!$list) {
          echo empty_row(9, 'No ledger for these filters.');
      }
      foreach ($list as $l): ?>
        <tr>
          <td><strong><?= h(full_name($l)) ?></strong><br><small class="muted"><?= h($l['employee_no']) ?>, <?= h($l['unit_name'] ?: '—') ?></small></td>
          <td><?= h($l['sub_type'] ?: '—') ?><br><small class="muted"><?= h($l['ledger_no']) ?></small></td>
          <td><?= h(label($l['ledger_type'])) ?></td>
          <td class="num"><?= money($l['principal']) ?></td>
          <td class="num"><?= money($l['amortization']) ?></td>
          <td class="num"><strong><?= money($l['balance']) ?></strong></td>
          <td class="num"><?= (float)$l['paid_outside'] ? money($l['paid_outside']) : '' ?></td>
          <td><?= badge($l['status']) ?></td>
          <td class="actions"><a class="btn btn-xs" href="<?= h(url('ledger_view', ['id' => $l['id']])) ?>">Ledger</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="3">TOTAL</td><td class="num"><?= money($tot['principal']) ?></td><td class="num"><?= money($tot['amort']) ?></td><td class="num"><?= money($tot['balance']) ?></td><td colspan="3"></td></tr></tfoot>
    </table>
  </div>
</div>
<?php page_footer();
