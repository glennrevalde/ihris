<?php
/**
 * Employee's transaction with terms — schedule (installments), ledger of postings,
 * payments outside the payroll, amortization change, cancel / re-activate.
 */
$id = (int)get('id', 0);
$t = $id ? term_get($id) : null;
if (!$t) {
    throw new RuntimeException('Transaction not found.');
}

if (is_post()) {
    verify_csrf();
    $action = post('_action');
    try {
        switch ($action) {
            case 'pay':
                $amt = term_pay_outside($id, post_num('amount'), post('pay_date') ?: today(), trim((string)post('reference')) ?: null);
                flash('success', 'Payment of ' . peso($amt) . ' recorded. The payroll will take only what is still outstanding.');
                break;
            case 'amort':
                term_update_amortization($id, post_num('per_pay'));
                flash('success', 'Amortization changed. The remaining balance was re-spread over the pending installments.');
                break;
            case 'cancel':
                term_cancel($id, trim((string)post('reason')) ?: null);
                flash('success', 'Transaction cancelled. Installments already taken stay in the history.');
                break;
            case 'reactivate':
                term_reactivate($id);
                flash('success', 'Transaction re-activated.');
                break;
            case 'remarks':
                update('employee_term_trans', ['doc_no' => trim((string)post('doc_no')) ?: null, 'remarks' => trim((string)post('remarks')) ?: null, 'updated_at' => now()], 'id = ?', [$id]);
                audit('payroll', 'term_trans_update', 'employee_term_trans', $id, ['doc_no' => post('doc_no'), 'remarks' => post('remarks')]);
                flash('success', 'Details saved.');
                break;
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('emp_term_view', ['id' => $id]);
}

$sched = term_schedule($id);
$posted = array_filter($sched, fn($s) => in_array($s['status'], ['posted', 'paid'], true));
$pending = array_filter($sched, fn($s) => $s['status'] === 'pending');
$perPay = (float)$t['per_pay_principal'] + (float)$t['per_pay_interest'];
$statusText = TERM_TRANS_STATUS[$t['status']] ?? $t['status'];

$actions = '<a class="btn" href="' . h(url('emp_transactions', ['tstatus' => 'all', 'q' => $t['employee_no']])) . '">All transactions of ' . h($t['last_name']) . '</a>'
    . '<a class="btn" href="' . h(url('employee_view', ['id' => $t['employee_id']])) . '">201 file</a>';
$actions .= $t['status'] !== 'cancelled' ? '<a class="btn btn-primary" href="' . h(url('emp_transactions', ['tstatus' => 'all', 'q' => $t['employee_no'], 'edit_term' => $t['id']])) . '#termEdit">Edit</a>' : '';
page_header('Transaction with terms — ' . $t['code'] . ' ' . $t['code_name'], [
    'back' => url('emp_transactions', ['tstatus' => 'all']),
    'back_label' => "Employee's Transactions",
    'subtitle' => h(full_name($t)) . ', ' . h($t['employee_no']) . '. ' . h(label($t['trans_type'])) . ' taken ' . h(strtolower(TERM_TRANS_TERMS[$t['trans_term']] ?? $t['trans_term'])) . '. Status: ' . h($statusText) . '.',
    'actions' => $actions,
]);
?>
<div class="stats">
  <?= stat_card('Principal', money($t['principal']), 'transaction amount') ?>
  <?= stat_card('Interest', money($t['interest_amount']), rtrim(rtrim(number_format((float)$t['monthly_rate'], 3), '0'), '.') . '% per month') ?>
  <?= stat_card('Total', money($t['total_amount']), 'principal + interest', 'info') ?>
  <?= stat_card('Per payday', money($perPay), money($t['per_pay_principal']) . ' + ' . money($t['per_pay_interest']) . ' interest') ?>
  <?= stat_card('Paid', money($t['total_paid']), (int)$t['paid_terms'] . ' of ' . (int)$t['terms'] . ' paydays', 'good') ?>
  <?= stat_card('Balance', money($t['balance']), $t['next_date'] ? 'next ' . fmt_date($t['next_date']) : $statusText, (float)$t['balance'] > 0 ? 'warn' : 'good') ?>
</div>

<div class="grid grid-2-1">
  <div>
    <div class="card flush">
      <div class="card-head"><div><h2>Schedule</h2><div class="sub">One row per payday. Pending installments are taken by the next payroll run that matches the transaction term; a payroll that could only take part of an installment (minimum take-home rule) adds the shortfall at the end.</div></div></div>
      <div class="table-wrap">
        <table class="table table-compact">
          <thead><tr><th>#</th><th>Payday</th><th class="num">Principal</th><th class="num">Interest</th><th class="num">Amount</th><th>Status</th><th>Posted</th><th>Reference</th></tr></thead>
          <tbody>
          <?php $run = 0.0;
          foreach ($sched as $s):
              if (in_array($s['status'], ['posted', 'paid'], true)) {
                  $run += (float)$s['amount'];
              } ?>
            <tr class="<?= $s['status'] === 'cancelled' ? 'row-muted' : ($s['status'] === 'pending' ? '' : 'row-good') ?>">
              <td><?= (int)$s['seq_no'] ?></td>
              <td class="nowrap"><?= h(fmt_date($s['due_date'])) ?></td>
              <td class="num"><?= money($s['principal']) ?></td>
              <td class="num"><?= money($s['interest']) ?></td>
              <td class="num"><strong><?= money($s['amount']) ?></strong></td>
              <td><?= h(['pending' => 'Pending', 'posted' => 'Taken by payroll', 'paid' => 'Paid outside payroll', 'skipped' => 'Skipped', 'cancelled' => 'Cancelled'][$s['status']] ?? $s['status']) ?></td>
              <td class="nowrap small"><?= h($s['posted_at'] ? fmt_date($s['posted_at']) : '') ?></td>
              <td class="small"><?= h($s['run_no'] ?: ($s['reference'] ?: '')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><td colspan="4">Taken / paid so far</td><td class="num"><?= money($run) ?></td><td colspan="3">Balance <?= money($t['balance']) ?></td></tr></tfoot>
        </table>
      </div>
    </div>

    <div class="card">
      <h2>Ledger</h2>
      <p class="muted small">Every posting against this transaction, in the order it happened.</p>
      <div class="table-wrap">
        <table class="table table-compact">
          <thead><tr><th>Date</th><th>Description</th><th class="num">Charge</th><th class="num">Payment</th><th class="num">Balance</th></tr></thead>
          <tbody>
            <tr><td class="nowrap"><?= h(fmt_date($t['trans_date'])) ?></td><td><?= h($t['code_name']) ?> — principal <?= (float)$t['interest_amount'] > 0 ? '+ interest (' . h(rtrim(rtrim(number_format((float)$t['monthly_rate'], 3), '0'), '.')) . '% monthly)' : '' ?><?= $t['doc_no'] ? ', doc. ' . h($t['doc_no']) : '' ?></td>
              <td class="num"><?= money($t['total_amount']) ?></td><td class="num"></td><td class="num"><?= money($t['total_amount']) ?></td></tr>
            <?php $bal = (float)$t['total_amount'];
            foreach ($posted as $s):
                $bal -= (float)$s['amount']; ?>
              <tr><td class="nowrap"><?= h(fmt_date($s['posted_at'])) ?></td>
                <td><?= $s['status'] === 'paid' ? 'Payment outside payroll' : 'Payroll deduction / payment' ?>, installment <?= (int)$s['seq_no'] ?><?= $s['run_no'] ? ' (' . h($s['run_no']) . ')' : ($s['reference'] ? ' (' . h($s['reference']) . ')' : '') ?></td>
                <td class="num"></td><td class="num"><?= money($s['amount']) ?></td><td class="num"><?= money(max(0, $bal)) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$posted): ?><tr><td colspan="5" class="empty">Nothing taken yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div>
    <div class="card">
      <h2>Details</h2>
      <dl class="kv">
        <dt>Employee</dt><dd><a href="<?= h(url('employee_view', ['id' => $t['employee_id']])) ?>"><?= h(full_name($t)) ?></a> (<?= h($t['employee_no']) ?>)</dd>
        <dt>Transaction</dt><dd><?= h($t['code']) ?> — <?= h($t['code_name']) ?> (<?= h(label($t['trans_type'])) ?>)</dd>
        <dt>Pay code</dt><dd><?= h($t['pay_group_code'] ?: '—') ?>, <?= h(label($t['frequency'] ?: '')) ?></dd>
        <dt>Trans. term</dt><dd><?= h(TERM_TRANS_TERMS[$t['trans_term']] ?? $t['trans_term']) ?></dd>
        <dt>Trans. date</dt><dd><?= h(fmt_date($t['trans_date'])) ?></dd>
        <dt>Start</dt><dd><?= h(fmt_date($t['start_date'])) ?></dd>
        <dt>Terms</dt><dd><?= (int)$t['terms'] ?> paydays</dd>
        <dt>Co. / Branch</dt><dd><?= h($t['branch_name'] ?: '—') ?></dd>
        <dt>Status</dt><dd><?= h($statusText) ?></dd>
      </dl>
      <form method="post" style="margin-top:14px">
        <?= csrf_field() ?><input type="hidden" name="_action" value="remarks">
        <div class="field"><label>Document no.</label><input name="doc_no" value="<?= h($t['doc_no'] ?? '') ?>" maxlength="40"></div>
        <div class="field"><label>Remarks</label><input name="remarks" value="<?= h($t['remarks'] ?? '') ?>" maxlength="255"></div>
        <div class="form-actions"><button class="btn">Save details</button></div>
      </form>
    </div>

    <?php if ($t['status'] === 'active'): ?>
    <div class="card">
      <h2>Payment outside the payroll</h2>
      <p class="muted small">A payment made at the cashier or to the agency settles the next installments, so the payroll takes only what is still outstanding.</p>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="_action" value="pay">
        <div class="form-grid cols-2">
          <div class="field"><label>Payment date</label><input type="date" name="pay_date" value="<?= h(today()) ?>" required></div>
          <div class="field"><label>Amount</label><input type="number" step="0.01" min="0.01" max="<?= h(number_format((float)$t['balance'], 2, '.', '')) ?>" name="amount" value="<?= h(number_format(min($perPay, (float)$t['balance']), 2, '.', '')) ?>" required></div>
          <div class="field wide"><label>Reference / OR no.</label><input name="reference" maxlength="80"></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary">Post payment</button></div>
      </form>
    </div>

    <div class="card">
      <h2>Amortization</h2>
      <p class="muted small">Change the amount taken per payday. The outstanding balance is re-spread over as many paydays as needed.</p>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="_action" value="amort">
        <div class="field"><label>Amount per payday</label><input type="number" step="0.01" min="0.01" name="per_pay" value="<?= h(number_format($perPay, 2, '.', '')) ?>" required></div>
        <div class="form-actions"><button class="btn">Update amortization</button></div>
      </form>
    </div>

    <div class="card">
      <h2>Cancel</h2>
      <form method="post" data-confirm="Cancel this transaction with terms? Pending installments will no longer be taken by the payroll.">
        <?= csrf_field() ?><input type="hidden" name="_action" value="cancel">
        <div class="field"><label>Reason</label><input name="reason" maxlength="120" placeholder="e.g. fully paid to the agency, restructured"></div>
        <div class="form-actions"><button class="btn btn-danger-outline">Cancel transaction</button></div>
      </form>
    </div>
    <?php elseif ($t['status'] === 'cancelled'): ?>
    <div class="card">
      <h2>Re-activate</h2>
      <p class="muted small">Puts the cancelled installments back to pending.</p>
      <form method="post" data-confirm="Re-activate this transaction?">
        <?= csrf_field() ?><input type="hidden" name="_action" value="reactivate">
        <div class="form-actions"><button class="btn">Re-activate</button></div>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php page_footer();
