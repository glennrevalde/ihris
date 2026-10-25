<?php
/**
 * Employee Term Loan/Payables (v16, formerly Subsidiary Ledgers): running balances per employee of the
 * government loans, company loans, cash advances, other payables and accrued benefits. The tab
 * Employee deductions (emp_deductions) holds the recurring deductions of the same menu.
 */
if (is_post() && post('_action') === 'create') {
    $d = ['employee_id' => (int)post('employee_id'), 'ledger_type' => post('ledger_type'), 'sub_type' => post('sub_type'), 'reference_no' => post('reference_no'),
        'principal' => post_num('principal'), 'interest_rate_pct' => post_num('interest_rate_pct'), 'term_months' => (int)post('term_months') ?: null,
        'amortization' => post_num('amortization'), 'start_date' => post('start_date') ?: today(), 'deduction_type_id' => post_null('deduction_type_id'),
        'written_consent' => post('written_consent') ? 1 : 0, 'remarks' => post('remarks')];
    if (!$d['employee_id'] || !$d['sub_type'] || $d['principal'] <= 0) {
        flash('error', 'Employee, description and a positive principal are required to create a loan / payable.');
        redirect('ledgers', ['new' => 1]);
    }
    if ($d['amortization'] <= 0 && $d['term_months']) {
        $emp = row('SELECT e.*, pg.frequency FROM employees e JOIN pay_groups pg ON pg.id = e.pay_group_id WHERE e.id = ?', [$d['employee_id']]);
        $total = $d['principal'] * (1 + $d['interest_rate_pct'] / 100);
        $d['amortization'] = round($total / ($d['term_months'] * periods_per_month($emp['frequency'])), 2);
    }
    $id = ledger_create($d);
    flash('success', 'Loan / payable created' . ($d['deduction_type_id'] ? ' and its payroll amortization scheduled.' : '.'));
    redirect('ledger_view', ['id' => $id]);
}
$f = ['type' => get('type', ''), 'status' => get('status', 'active'), 'emp' => get('employee_id', '')];
$where = ['1=1'];
$params = [];
foreach (['type' => 'l.ledger_type', 'status' => 'l.status', 'emp' => 'l.employee_id'] as $k => $col) {
    if ($f[$k] !== '' && !($k === 'status' && $f[$k] === 'all')) {
        $where[] = "$col = ?";
        $params[] = $f[$k];
    }
}
$list = rows('SELECT l.*, e.first_name, e.last_name, e.middle_name, e.suffix, e.employee_no,
              (SELECT COALESCE(SUM(decrease),0) FROM ledger_entries le WHERE le.ledger_id = l.id) AS paid
              FROM ledgers l JOIN employees e ON e.id = l.employee_id WHERE ' . implode(' AND ', $where) . user_scope_sql('e') . ' ORDER BY l.ledger_type, e.last_name', $params);
$tot = [];
foreach ($list as $l) {
    $tot[$l['ledger_type']] = ($tot[$l['ledger_type']] ?? 0) + (float)$l['balance'];
}
$new = get('new');
page_header('Employee Term Loan/Payables', ['subtitle' => 'Running balances per employee: government loans, company loans and cash advances, other payables, and accrued benefits (13th month, leave conversion).',
    'actions' => ($f['emp'] ? '<a class="btn" target="_blank" href="' . h(url('ledger_print', ['employee_id' => $f['emp'], 'status' => $f['status'] === 'active' ? '' : $f['status']])) . '">Print employee ledger</a>' : '')
        . (can('ledgers.create') ? '<a class="btn btn-primary" href="' . url('ledgers', ['new' => 1]) . '">+ New Loan/Payable</a>' : '')]);
echo term_loan_tabs('ledgers');
if ($new): ?>
<div class="card form-card"><h2>New Loan/Payable</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="create">
    <div class="form-grid">
      <div class="field"><label>Employee</label><select name="employee_id" required><?= options(employee_options(), get('employee_id'), '— select —') ?></select></div>
      <div class="field"><label>Ledger type</label><select name="ledger_type"><?= options(array_diff_key(LEDGER_TYPES, ['accrued_benefit' => 1]), 'gov_loan') ?></select></div>
      <div class="field"><label>Description</label><input name="sub_type" list="subTypes" required placeholder="e.g. SSS Salary Loan"><datalist id="subTypes"><option>SSS Salary Loan</option><option>SSS Calamity Loan</option><option>Pag-IBIG Multi-Purpose Loan</option><option>Pag-IBIG Calamity Loan</option><option>Company Loan</option><option>Cash Advance</option><option>Company property accountability</option><option>Negotiated settlement</option></datalist></div>
      <div class="field"><label>Reference no. (agency / voucher)</label><input name="reference_no"></div>
      <div class="field"><label>Principal</label><input type="number" step="0.01" name="principal" required></div>
      <div class="field"><label>Add-on interest for the term (%)</label><input type="number" step="0.01" name="interest_rate_pct" value="0"></div>
      <div class="field"><label>Term (months)</label><input type="number" name="term_months"></div>
      <div class="field"><label>Amortization per payroll</label><input type="number" step="0.01" name="amortization"><small class="help">Blank = total ÷ (term × payrolls per month)</small></div>
      <div class="field"><label>First deduction</label><input type="date" name="start_date" value="<?= h(today()) ?>"></div>
      <div class="field"><label>Payroll deduction</label><select name="deduction_type_id"><?= options(pairs("SELECT id, name FROM deduction_types WHERE computation = 'ledger' AND is_active = 1 ORDER BY priority"), get('deduction_type_id'), '(no automatic deduction)') ?></select></div>
      <div class="field"><label class="check"><input type="checkbox" name="written_consent" value="1" checked> Written authorization to deduct on file</label></div>
      <div class="field wide"><label>Remarks</label><input name="remarks"></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary">Create Loan/Payable</button> <a class="btn" href="<?= url('ledgers') ?>">Cancel</a></div>
  </form></div>
<?php endif; ?>
<div class="stats"><?php foreach (LEDGER_TYPES as $k => $l): ?><?= stat_card($l, peso($tot[$k] ?? 0), 'outstanding balance') ?><?php endforeach; ?></div>
<form class="card filters" method="get"><input type="hidden" name="p" value="ledgers">
  <label>Type<select name="type"><?= options(LEDGER_TYPES, $f['type'], 'All') ?></select></label>
  <label>Status<select name="status"><?= options(['active' => 'Active', 'paid' => 'Fully paid', 'cancelled' => 'Cancelled', 'all' => 'All'], $f['status']) ?></select></label>
  <label>Employee<select name="employee_id"><?= options(employee_options(false), $f['emp'], 'All') ?></select></label>
  <button class="btn btn-primary">Filter</button></form>
<div class="card"><div class="table-wrap"><table class="table compact">
  <thead><tr><th>Ledger</th><th>Employee</th><th>Type</th><th>Description</th><th class="num">Total payable</th><th class="num">Paid</th><th class="num">Amortization</th><th class="num">Balance</th><th>Status</th><th>Full payment</th><th class="actions"></th></tr></thead><tbody>
  <?php if (!$list) echo empty_row(11); foreach ($list as $l): ?>
    <tr><td><a href="<?= url('ledger_view', ['id' => $l['id']]) ?>"><?= h($l['ledger_no']) ?></a></td><td><?= h(full_name($l)) ?></td><td class="small"><?= h(LEDGER_TYPES[$l['ledger_type']]) ?></td><td><?= h($l['sub_type']) ?><br><span class="muted small"><?= h($l['reference_no']) ?></span></td>
      <td class="num"><?= money($l['total_payable']) ?></td><td class="num"><?= money($l['paid']) ?></td><td class="num"><?= $l['amortization'] > 0 ? money($l['amortization']) : '' ?></td><td class="num"><strong><?= money($l['balance']) ?></strong></td>
      <td><?= badge($l['status']) ?></td><td class="small"><?= h(fmt_date($l['full_payment_date'])) ?></td>
      <td class="actions nowrap"><a class="btn btn-xs" href="<?= url('ledger_view', ['id' => $l['id']]) ?>">Open</a><a class="btn btn-xs" target="_blank" href="<?= h(url('ledger_print', ['id' => $l['id']])) ?>">Print</a></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php page_footer();
