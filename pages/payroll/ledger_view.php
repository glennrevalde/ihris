<?php
$id = (int)get('id', 0);
$l = row('SELECT l.*, e.first_name, e.last_name, e.middle_name, e.suffix, e.employee_no, e.id AS emp_id, pg.frequency FROM ledgers l JOIN employees e ON e.id = l.employee_id
          JOIN pay_groups pg ON pg.id = e.pay_group_id WHERE l.id = ?', [$id]);
if (!$l) {
    throw new RuntimeException('Ledger not found.');
}
$staff = can('ledgers.view');   // index.php allows the employee's own ledger read-only
if (!$staff && (int)$l['employee_id'] !== my_employee_id()) {
    perm_deny();
}
if (is_post() && $staff) {
    $act = post('_action');
    if ($act === 'entry') {
        $amt = post_num('amount');
        if ($amt <= 0 || !post('description')) {
            flash('error', 'Amount and description are required.');
        } else {
            $inc = post('direction') === 'increase' ? $amt : 0;
            $dec = post('direction') === 'decrease' ? $amt : 0;
            ledger_post($id, post('entry_date') ?: today(), (string)post('description'), $inc, $dec);
            audit('payroll', 'ledger_entry', 'ledgers', $id, $_POST);
            flash('success', 'Ledger entry posted.');
        }
    } elseif ($act === 'edit_entry' || $act === 'delete_entry') {
        try {
            $eid = (int)post('entry_id');
            if ((int)val('SELECT ledger_id FROM ledger_entries WHERE id = ?', [$eid]) !== $id) {
                throw new RuntimeException('Ledger entry not found.');
            }
            if ($act === 'edit_entry') {
                $amt = post_num('amount');
                $isInc = post('direction') === 'increase';
                ledger_entry_update($eid, (string)post('entry_date'), (string)post('description'), $isInc ? $amt : 0, $isInc ? 0 : $amt, (string)post('reason'));
                flash('success', 'Ledger entry updated; the running balance was recomputed.');
            } else {
                ledger_entry_delete($eid, (string)post('reason'));
                flash('success', 'Ledger entry deleted; the running balance was recomputed.');
            }
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('ledger_view', ['id' => $id, 'edit_entry' => (int)post('entry_id')]);
        }
    } elseif ($act === 'reconcile') {
        $agency = post_num('agency_balance');
        $var = round($agency - (float)$l['balance'], 2);
        insert('ledger_reconciliations', ['ledger_id' => $id, 'statement_date' => post('statement_date') ?: today(), 'agency_balance' => $agency, 'system_balance' => $l['balance'],
            'variance' => $var, 'remarks' => post('remarks'), 'created_by' => user_id()]);
        update('ledgers', ['agency_balance' => $agency, 'reconciled_at' => post('statement_date') ?: today()], 'id = ?', [$id]);
        audit('payroll', 'ledger_reconcile', 'ledgers', $id, ['agency' => $agency, 'variance' => $var]);
        flash($var == 0 ? 'success' : 'warning', $var == 0 ? 'Reconciled: the agency statement matches the subsidiary ledger.' : 'Variance of ' . peso($var) . ' against the agency statement — post an adjusting entry after verification.');
    } elseif ($act === 'amort') {
        update('ledgers', ['amortization' => post_num('amortization')], 'id = ?', [$id]);
        q("UPDATE employee_deductions SET amount = ? WHERE ledger_id = ? AND status IN ('active','pending')", [post_num('amortization'), $id]);
        audit('payroll', 'ledger_amortization', 'ledgers', $id, ['amortization' => post('amortization')]);
        flash('success', 'Amortization updated.');
    } elseif ($act === 'cancel') {
        update('ledgers', ['status' => 'cancelled'], 'id = ?', [$id]);
        q("UPDATE employee_deductions SET status = 'stopped' WHERE ledger_id = ? AND status IN ('active','pending')", [$id]);
        audit('payroll', 'ledger_cancel', 'ledgers', $id);
        flash('success', 'Ledger cancelled and its payroll deduction stopped.');
    }
    redirect('ledger_view', ['id' => $id]);
}
$l = row('SELECT l.*, e.first_name, e.last_name, e.middle_name, e.suffix, e.employee_no, e.id AS emp_id, pg.frequency FROM ledgers l JOIN employees e ON e.id = l.employee_id
          JOIN pay_groups pg ON pg.id = e.pay_group_id WHERE l.id = ?', [$id]);
$entries = rows('SELECT le.*, r.run_no, u.full_name AS edited_name FROM ledger_entries le LEFT JOIN payroll_runs r ON r.id = le.payroll_run_id
                  LEFT JOIN users u ON u.id = le.edited_by WHERE le.ledger_id = ? ORDER BY le.entry_date, le.id', [$id]);
$canEditEntry = $staff && can('ledgers.edit') && $l['status'] !== 'cancelled';
$canDelEntry = $staff && can('ledgers.delete') && $l['status'] !== 'cancelled';
$editEntry = $canEditEntry && (int)get('edit_entry') ? row('SELECT * FROM ledger_entries WHERE id = ? AND ledger_id = ?', [(int)get('edit_entry'), $id]) : null;
$recs = rows('SELECT * FROM ledger_reconciliations WHERE ledger_id = ? ORDER BY statement_date DESC', [$id]);
$ded = row('SELECT ed.*, dt.name FROM employee_deductions ed JOIN deduction_types dt ON dt.id = ed.deduction_type_id WHERE ed.ledger_id = ? ORDER BY ed.id DESC LIMIT 1', [$id]);
$sched = $l['status'] === 'active' ? ledger_schedule($l, $l['frequency']) : [];
$isAccrual = $l['ledger_type'] === 'accrued_benefit';
page_header('Ledger ' . $l['ledger_no'], ['back' => $staff ? url('ledgers') : url('my_ledgers'), 'subtitle' => h(full_name($l, false)) . ', ' . h(LEDGER_TYPES[$l['ledger_type']]) . ', ' . h($l['sub_type']) . ' ' . badge($l['status']),
    'actions' => '<a class="btn" href="' . h(url('ledger_print', ['id' => $id])) . '" target="_blank">Print ledger</a>'
        . ($staff ? '<a class="btn" href="' . h(url('ledger_print', ['employee_id' => $l['employee_id']])) . '" target="_blank">Print all ledgers of the employee</a>' : '')]);
?>
<div class="stats">
  <?= stat_card($isAccrual ? 'Total accrued' : 'Total payable', peso($isAccrual ? array_sum(array_column($entries, 'increase')) : $l['total_payable']), $isAccrual ? '' : 'Principal ' . peso($l['principal']) . ($l['interest_rate_pct'] > 0 ? ' + ' . (float)$l['interest_rate_pct'] . '% interest' : '')) ?>
  <?= stat_card($isAccrual ? 'Disbursed' : 'Payments applied', peso(array_sum(array_column($entries, 'decrease')))) ?>
  <?= stat_card($isAccrual ? 'Accrued liability' : 'Remaining balance', peso($l['balance']), '', $l['balance'] > 0 ? 'warn' : 'good') ?>
  <?php if (!$isAccrual): ?><?= stat_card('Amortization', peso($l['amortization']), $ded ? h($ded['name']) . ', ' . h($ded['status']) : 'no payroll deduction') ?>
  <?= stat_card('Full payment', $l['full_payment_date'] ? h(fmt_date($l['full_payment_date'])) : ($sched ? h(fmt_date(end($sched)['date'])) : '—'), $l['full_payment_date'] ? 'fully paid' : 'projected') ?><?php endif; ?>
</div>
<div class="grid grid-2-1">
  <div>
  <?php if ($editEntry):
      $isInc = (float)$editEntry['increase'] > 0; ?>
  <div class="card form-card" id="entryEdit">
    <h2>Edit ledger entry</h2>
    <?php if ($editEntry['payroll_run_id']): ?><div class="callout warn">This entry was posted by a payroll run. Changing it corrects the ledger only; the payslip of that run stays as it was.</div><?php endif; ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="edit_entry"><input type="hidden" name="entry_id" value="<?= (int)$editEntry['id'] ?>">
      <div class="form-grid">
        <div class="field"><label>Date</label><input type="date" name="entry_date" value="<?= h($editEntry['entry_date']) ?>" required></div>
        <div class="field"><label>Type</label><select name="direction"><?= options($isAccrual ? ['increase' => 'Accrual', 'decrease' => 'Disbursement'] : ['increase' => 'Charge', 'decrease' => 'Payment'], $isInc ? 'increase' : 'decrease') ?></select></div>
        <div class="field"><label>Amount</label><input type="number" step="0.01" min="0.01" name="amount" value="<?= h(number_format($isInc ? (float)$editEntry['increase'] : (float)$editEntry['decrease'], 2, '.', '')) ?>" required></div>
        <div class="field wide"><label>Description</label><input name="description" value="<?= h($editEntry['description']) ?>" maxlength="255" required></div>
        <div class="field wide"><label>Reason for the change</label><input name="reason" maxlength="255" required placeholder="e.g. wrong amount encoded, per agency statement"></div>
      </div>
      <div class="form-actions"><button class="btn btn-primary">Save entry</button> <a class="btn" href="<?= h(url('ledger_view', ['id' => $id])) ?>">Cancel</a></div>
    </form>
    <?php if ($canDelEntry): ?>
    <form method="post" class="inline-form" style="margin-top:12px" data-confirm="Delete this ledger entry? The running balance is recomputed."><?= csrf_field() ?>
      <input type="hidden" name="_action" value="delete_entry"><input type="hidden" name="entry_id" value="<?= (int)$editEntry['id'] ?>">
      <input name="reason" required maxlength="255" placeholder="Reason for deleting the entry" style="max-width:320px">
      <button class="btn btn-danger-outline">Delete entry</button></form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <div class="card"><div class="card-head"><div><h2>Ledger entries</h2><div class="sub"><?= count($entries) ?> entr<?= count($entries) === 1 ? 'y' : 'ies' ?><?= $canEditEntry ? '. Edit an entry to correct it; the running balance is recomputed and the change is kept in the audit trail.' : '' ?></div></div></div>
    <div class="table-wrap"><table class="table compact"><thead><tr><th>Date</th><th>Description</th><th>Payroll</th><th class="num"><?= $isAccrual ? 'Accrual' : 'Charge' ?></th><th class="num"><?= $isAccrual ? 'Disbursement' : 'Payment' ?></th><th class="num">Balance</th><?= $canEditEntry ? '<th class="actions"></th>' : '' ?></tr></thead><tbody>
    <?php if (!$entries) echo empty_row($canEditEntry ? 7 : 6); foreach ($entries as $e): ?><tr<?= $editEntry && (int)$editEntry['id'] === (int)$e['id'] ? ' class="row-sel"' : '' ?>><td class="nowrap"><?= h(fmt_date($e['entry_date'])) ?></td>
      <td><?= h($e['description']) ?><?php if ($e['edited_at']): ?><br><small class="muted">Edited <?= h(fmt_dt($e['edited_at'])) ?><?= $e['edited_name'] ? ' by ' . h($e['edited_name']) : '' ?>: <?= h($e['edit_note']) ?></small><?php endif; ?></td><td class="small"><?= h($e['run_no']) ?></td>
      <td class="num"><?= $e['increase'] > 0 ? money($e['increase']) : '' ?></td><td class="num"><?= $e['decrease'] > 0 ? money($e['decrease']) : '' ?></td><td class="num"><strong><?= money($e['balance']) ?></strong></td>
      <?php if ($canEditEntry): ?><td class="actions"><a class="btn btn-xs" href="<?= h(url('ledger_view', ['id' => $id, 'edit_entry' => $e['id']])) ?>#entryEdit">Edit</a></td><?php endif; ?></tr><?php endforeach; ?>
    </tbody></table></div>
  </div>
  </div>
  <div>
    <?php if ($sched): ?><div class="card"><h3>Projected amortization schedule</h3>
      <p class="muted small">From the transaction date <?= h(fmt_date($l['start_date'])) ?>: one payment per payroll, the first on the pay period ending on or after it.</p>
      <?= ledger_schedule_table($sched) ?></div><?php endif; ?>
    <?php if ($staff && $l['status'] !== 'cancelled'): ?>
    <div class="card"><h3>Post manual entry</h3>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="entry">
        <div class="form-grid"><div class="field"><label>Date</label><input type="date" name="entry_date" value="<?= h(today()) ?>"></div>
          <div class="field"><label>Direction</label><select name="direction"><?= options($isAccrual ? ['increase' => 'Accrual', 'decrease' => 'Disbursement'] : ['decrease' => 'Payment (e.g. over-the-counter)', 'increase' => 'Additional charge / adjustment']) ?></select></div>
          <div class="field"><label>Amount</label><input type="number" step="0.01" name="amount" required></div>
          <div class="field wide"><label>Description</label><input name="description" required></div></div>
        <div class="form-actions"><button class="btn">Post entry</button></div></form>
    </div>
    <?php if (!$isAccrual): ?>
    <div class="card"><h3>Reconcile against agency / statement</h3>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="reconcile">
        <div class="form-grid"><div class="field"><label>Statement date</label><input type="date" name="statement_date" value="<?= h(today()) ?>"></div><div class="field"><label>Balance per statement</label><input type="number" step="0.01" name="agency_balance" required></div>
          <div class="field wide"><label>Remarks</label><input name="remarks" placeholder="e.g. SSS loan statement, My.SSS"></div></div>
        <div class="form-actions"><button class="btn">Reconcile</button></div></form>
      <?php if ($recs): ?><table class="table compact" style="margin-top:10px"><thead><tr><th>Date</th><th class="num">Agency</th><th class="num">System</th><th class="num">Variance</th></tr></thead><tbody>
        <?php foreach ($recs as $r): ?><tr><td><?= h(fmt_date($r['statement_date'])) ?></td><td class="num"><?= money($r['agency_balance']) ?></td><td class="num"><?= money($r['system_balance']) ?></td><td class="num"><?= (float)$r['variance'] == 0 ? '<span class="badge badge-green">0.00</span>' : '<span class="badge badge-red">' . money($r['variance']) . '</span>' ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
    </div>
    <div class="card"><h3>Amortization</h3>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="_action" value="amort"><input type="number" step="0.01" name="amortization" value="<?= h($l['amortization']) ?>" style="width:140px;display:inline-block"> <button class="btn btn-sm">Update</button></form>
      <form method="post" class="inline" data-confirm="Cancel this ledger and stop its deduction?"><?= csrf_field() ?><input type="hidden" name="_action" value="cancel"><button class="btn btn-sm btn-danger-outline">Cancel ledger</button></form>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php page_footer();
