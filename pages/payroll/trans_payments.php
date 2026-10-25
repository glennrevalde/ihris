<?php
/**
 * Transaction Payment — payments received outside the payroll (cash, deposit, offsetting)
 * against an employee's loan / accountability ledger. Each payment reduces the balance, so the
 * next payroll deducts only what is left.
 */
$dimp = dimp_def('trans_payments', null, []);   // CSV import facility on this form (app/engine/dataimport.php)
dimp_handle($dimp);
$empF = (int)get('emp', 0);
$typeF = (string)get('ledger_type', '');
$keep = array_filter(['p' => 'trans_payments', 'emp' => $empF ?: null, 'ledger_type' => $typeF ?: null], fn($v) => $v !== null);
$keep = array_merge($keep, back_params());

if (is_post()) {
    verify_csrf();
    try {
        if (post('_action') === 'pay') {
            $ledgerId = (int)post('ledger_id');
            $l = $ledgerId ? row('SELECT l.*, e.first_name, e.last_name FROM ledgers l JOIN employees e ON e.id = l.employee_id WHERE l.id = ?', [$ledgerId]) : null;
            if (!$l) {
                throw new RuntimeException('Select a ledger.');
            }
            $amount = round((float)str_replace(',', '', (string)post('amount')), 2);
            if ($amount <= 0) {
                throw new RuntimeException('The payment must be greater than zero.');
            }
            if ($amount > (float)$l['balance'] + 0.004) {
                throw new RuntimeException('The payment (' . money($amount) . ') is more than the outstanding balance (' . money($l['balance']) . ').');
            }
            $date = post('pay_date') ?: today();
            $ref = trim((string)post('reference_no')) ?: null;
            $remarks = trim((string)post('remarks')) ?: null;
            tx(function () use ($ledgerId, $date, $ref, $amount, $remarks) {
                ledger_post($ledgerId, $date, 'Payment' . ($ref ? ' ' . $ref : '') . ($remarks ? ' — ' . $remarks : ''), 0, $amount);
                insert('transaction_payments', ['ledger_id' => $ledgerId, 'pay_date' => $date, 'reference_no' => $ref, 'amount' => $amount,
                    'remarks' => $remarks, 'created_by' => user_id()]);
            });
            audit('payroll', 'transaction_payment', 'ledgers', $ledgerId, ['amount' => $amount, 'date' => $date, 'ref' => $ref]);
            flash('success', 'Payment of ' . money($amount) . ' posted.');
        } elseif (post('_action') === 'void') {
            $p = row('SELECT * FROM transaction_payments WHERE id = ?', [(int)post('id')]);
            if (!$p) {
                throw new RuntimeException('Payment not found.');
            }
            tx(function () use ($p) {
                ledger_post((int)$p['ledger_id'], today(), 'Voided payment ' . ($p['reference_no'] ?: '') . ' of ' . fmt_date($p['pay_date']), (float)$p['amount'], 0);
                q('DELETE FROM transaction_payments WHERE id = ?', [$p['id']]);
            });
            audit('payroll', 'transaction_payment_void', 'ledgers', (int)$p['ledger_id'], $p);
            flash('success', 'Payment voided and the balance restored.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('trans_payments', $keep);
}

$where = ["l.status = 'active'", "l.ledger_type <> 'accrued_benefit'", 'l.balance > 0'];
$params = [];
if ($empF) {
    $where = ['l.employee_id = ?', "l.ledger_type <> 'accrued_benefit'"];
    $params = [$empF];
}
if ($typeF) {
    $where[] = 'l.ledger_type = ?';
    $params[] = $typeF;
}
$ledgers = rows('SELECT l.*, e.employee_no, e.first_name, e.last_name, e.middle_name, e.suffix, ou.name AS unit_name
                 FROM ledgers l JOIN employees e ON e.id = l.employee_id LEFT JOIN org_units ou ON ou.id = e.org_unit_id
                 WHERE ' . implode(' AND ', $where) . user_scope_sql('e') . ' ORDER BY e.last_name, e.first_name, l.id', $params);
$payments = rows('SELECT tp.*, l.ledger_no, l.sub_type, l.ledger_type, e.employee_no, e.first_name, e.last_name, e.middle_name, e.suffix
                  FROM transaction_payments tp JOIN ledgers l ON l.id = tp.ledger_id JOIN employees e ON e.id = l.employee_id
                  WHERE 1=1' . ($empF ? ' AND l.employee_id = ' . $empF : '') . user_scope_sql('e') . '
                  ORDER BY tp.pay_date DESC, tp.id DESC LIMIT 100');
$totalBal = 0.0;
foreach ($ledgers as $l) {
    $totalBal += (float)$l['balance'];
}

page_header('Transaction Payment', [
    'subtitle' => 'Record payments made outside the payroll against loans, cash advances and accountabilities. The payroll then deducts only the remaining balance.',
    'actions' => '<a class="btn" href="' . url('ledgers') . '">Employee Term Loan/Payables</a><a class="btn" href="' . url('emp_deductions') . '">Employee deductions</a>',
]);
echo dimp_panel($dimp);
?>
<form method="get" class="card filters">
  <input type="hidden" name="p" value="trans_payments">
  <label>Employee<select name="emp" data-autosubmit><?= options(employee_options(false), $empF ?: null, 'All employees with a balance') ?></select></label>
  <label>Ledger type<select name="ledger_type" data-autosubmit><?= options(['gov_loan' => 'Government loan', 'company_loan' => 'Company loan', 'cash_advance' => 'Cash advance', 'accountability' => 'Accountability'], $typeF, 'All types') ?></select></label>
  <span class="spacer"></span>
  <span class="muted small">Outstanding: <strong><?= money($totalBal) ?></strong></span>
</form>

<div class="grid grid-2-1">
  <div class="card flush">
    <div class="card-head"><div><h2>Outstanding balances</h2><div class="sub"><?= count($ledgers) ?> ledger(s)</div></div>
      <input type="search" class="table-search" data-target="ledTable" placeholder="Find…" style="width:200px"></div>
    <div class="table-wrap table-scroll">
      <table class="table table-compact" id="ledTable">
        <thead><tr><th>Employee</th><th>Ledger</th><th>Type</th><th class="num">Principal</th><th class="num">Amortization</th><th class="num">Balance</th><th class="actions"></th></tr></thead>
        <tbody>
        <?php if (!$ledgers) {
            echo empty_row(7, 'No outstanding loan or accountability.');
        }
        foreach ($ledgers as $l): ?>
          <tr>
            <td><strong><?= h(full_name($l)) ?></strong><br><small class="muted"><?= h($l['employee_no']) ?></small></td>
            <td><?= h($l['sub_type'] ?: label($l['ledger_type'])) ?><br><small class="muted"><?= h($l['ledger_no']) ?></small></td>
            <td><?= h(label($l['ledger_type'])) ?></td>
            <td class="num"><?= money($l['principal']) ?></td>
            <td class="num"><?= money($l['amortization']) ?></td>
            <td class="num"><strong><?= money($l['balance']) ?></strong></td>
            <td class="actions">
              <button type="button" class="btn btn-xs btn-primary" data-modal-open="payModal"
                data-field-ledger_id="<?= (int)$l['id'] ?>" data-field-amount="<?= h(number_format(min((float)$l['amortization'] ?: (float)$l['balance'], (float)$l['balance']), 2, '.', '')) ?>"
                data-field-employee="<?= h(full_name($l) . ' — ' . ($l['sub_type'] ?: $l['ledger_no'])) ?>">Pay</button>
              <a class="btn btn-xs" href="<?= h(url('ledger_view', ['id' => $l['id']])) ?>">Ledger</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card flush">
    <div class="card-head"><h2>Recent payments</h2></div>
    <div class="table-wrap table-scroll">
      <table class="table table-compact">
        <thead><tr><th>Date</th><th>Employee</th><th>Ledger</th><th class="num">Amount</th><th class="actions"></th></tr></thead>
        <tbody>
        <?php if (!$payments) {
            echo empty_row(5, 'No payment recorded yet.');
        }
        foreach ($payments as $p): ?>
          <tr>
            <td class="nowrap"><?= h(fmt_date($p['pay_date'])) ?><?= $p['reference_no'] ? '<br><small class="muted">' . h($p['reference_no']) . '</small>' : '' ?></td>
            <td><?= h(full_name($p)) ?></td>
            <td class="small"><?= h($p['sub_type'] ?: label($p['ledger_type'])) ?><br><small class="muted"><?= h($p['ledger_no']) ?></small></td>
            <td class="num"><?= money($p['amount']) ?></td>
            <td class="actions">
              <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button class="btn btn-xs btn-danger-outline" name="_action" value="void" data-confirm="Void this payment and add the amount back to the balance?">Void</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal" id="payModal">
  <div class="modal-box">
    <h2>Record a payment</h2>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="_action" value="pay"><input type="hidden" name="ledger_id">
      <p class="muted" data-text="employee"></p>
      <div class="form-grid">
        <div class="field"><label>Payment date</label><input type="date" name="pay_date" value="<?= h(today()) ?>" required></div>
        <div class="field"><label>Amount</label><input type="number" step="0.01" min="0.01" name="amount" required></div>
        <div class="field"><label>Reference / OR no.</label><input name="reference_no" maxlength="40"></div>
        <div class="field wide"><label>Remarks</label><input name="remarks" maxlength="255"></div>
      </div>
      <div class="form-actions"><button class="btn btn-primary">Post payment</button><button type="button" class="btn" data-modal-close>Cancel</button></div>
    </form>
  </div>
</div>
<?php page_footer();
