<?php
/**
 * My Workspace > Loan / Deduction Request (v20): the employee applies for a loan or a deduction of the Employee
 * Transactions Table (codes marked Portal request). Draft -> Submitted for approval -> Approved / Rejected.
 * The approved amount becomes a transaction with terms by itself (app/engine/loanreq.php), so HR does not
 * encode it during the payroll.
 */
$me = my_employee_id();
$emp = $me ? row('SELECT e.*, pg.frequency, pg.name AS pay_group_name, ou.name AS unit_name FROM employees e LEFT JOIN pay_groups pg ON pg.id = e.pay_group_id
                  LEFT JOIN org_units ou ON ou.id = e.org_unit_id WHERE e.id = ?', [$me]) : null;
$ready = loan_req_supported();

if (is_post() && $emp && $ready) {
    verify_csrf();
    $act = (string)post('_action');
    $id = (int)post('id');
    $old = $id ? row('SELECT * FROM loan_requests WHERE id = ? AND employee_id = ?', [$id, $me]) : null;
    try {
        if ($id && !$old) {
            throw new RuntimeException('Request not found.');
        }
        if ($act === 'save' || $act === 'submit') {
            if ($old && $old['status'] !== 'draft') {
                throw new RuntimeException('Only a draft can be changed. This request is ' . strtolower(LOAN_REQ_STATUS[$old['status']] ?? $old['status']) . '.');
            }
            [$d, $err] = loan_req_clean($_POST, $me);
            if ($act === 'save' && $err && $d['amount_applied'] <= 0 && !$d['deduction_type_id']) {
                throw new RuntimeException(implode(' ', $err));
            }
            if ($act === 'submit' && $err) {
                throw new RuntimeException(implode(' ', $err));
            }
            if ($act === 'save' && $err) {
                flash('warning', 'Saved as a draft. Before submitting: ' . implode(' ', $err));
            }
            $d['updated_at'] = now();
            if ($old) {
                update('loan_requests', $d, 'id = ?', [$old['id']]);
                $id = (int)$old['id'];
            } else {
                $id = insert('loan_requests', $d + ['request_no' => next_number('LR', 'loan_requests', 'request_no'), 'employee_id' => $me, 'date_applied' => today(),
                    'status' => 'draft', 'created_by' => user_id(), 'created_at' => now()]);
            }
            if ($act === 'submit') {
                update('loan_requests', ['status' => 'submitted', 'submitted_at' => now(), 'date_applied' => today()], 'id = ?', [$id]);
                audit('ess', 'loan_request_submit', 'loan_requests', $id, $d);
                loan_req_notify_submitted(loan_req_get($id));
                flash('success', 'Request submitted for approval. Your immediate superior, HR or the administrator will approve it; the approved amount is then deducted by the payroll from the start of payment.');
            } else {
                audit('ess', 'loan_request_save', 'loan_requests', $id, $d);
                if (!$err) {
                    flash('success', 'Draft saved. Submit it for approval when ready.');
                }
            }
            redirect('my_loan_requests', ['id' => $id]);
        }
        if ($act === 'cancel' && $old) {
            if ($old['status'] !== 'submitted') {
                throw new RuntimeException('Only a request waiting for approval can be cancelled.');
            }
            update('loan_requests', ['status' => 'cancelled', 'updated_at' => now()], 'id = ?', [$old['id']]);
            audit('ess', 'loan_request_cancel', 'loan_requests', $old['id']);
            flash('success', 'Request ' . $old['request_no'] . ' cancelled.');
            redirect('my_loan_requests', ['id' => $old['id']]);
        }
        if ($act === 'delete' && $old) {
            if ($old['status'] !== 'draft') {
                throw new RuntimeException('Only a draft can be deleted.');
            }
            q('DELETE FROM loan_requests WHERE id = ?', [$old['id']]);
            audit('ess', 'loan_request_delete', 'loan_requests', $old['id']);
            flash('success', 'Draft deleted.');
            redirect('my_loan_requests');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
        $_SESSION['loan_req_form'] = $_POST;
        redirect('my_loan_requests', array_filter(['id' => $id ?: null, 'new' => $id ? null : 1]));
    }
}

page_header('Loan / Deduction Request', [
    'subtitle' => 'Apply for a loan or a deduction. Your immediate superior, HR or the administrator approves it, and the approved amount is deducted by the payroll from the start of payment.',
    'actions' => ($emp && $ready ? '<a class="btn btn-primary" href="' . url('my_loan_requests', ['new' => 1]) . '">+ New request</a>' : '')
        . (can('my_ledgers.view') ? '<a class="btn" href="' . url('my_ledgers') . '">My Loans &amp; Ledgers</a>' : ''),
]);
if (!$emp) {
    echo '<div class="card"><div class="empty-state"><h3>No employee record</h3><p class="muted">Your account is not linked to an employee record. Ask the administrator to link it in User Accounts.</p></div></div>';
    page_footer();
    return;
}
if (!$ready) {
    echo '<div class="card"><div class="empty-state"><h3>Not available yet</h3><p class="muted">The administrator must open the site once to upgrade the database (version 18).</p></div></div>';
    page_footer();
    return;
}
$types = loan_req_types();
$freq = $emp['frequency'] ?: 'semi_monthly';
$ppm = periods_per_month($freq);
$mine = rows('SELECT r.*, dt.code AS type_code, dt.name AS type_name, u.full_name AS decided_name FROM loan_requests r JOIN deduction_types dt ON dt.id = r.deduction_type_id
              LEFT JOIN users u ON u.id = r.decided_by WHERE r.employee_id = ? ORDER BY r.id DESC', [$me]);
$id = (int)get('id', 0);
$cur = $id ? row('SELECT r.*, dt.code AS type_code, dt.name AS type_name, u.full_name AS decided_name FROM loan_requests r JOIN deduction_types dt ON dt.id = r.deduction_type_id
                  LEFT JOIN users u ON u.id = r.decided_by WHERE r.id = ? AND r.employee_id = ?', [$id, $me]) : null;
$editing = get('new') || ($cur && $cur['status'] === 'draft') || (!$cur && !$mine);
$form = $_SESSION['loan_req_form'] ?? null;
unset($_SESSION['loan_req_form']);
$v = $form ?: ($cur && $cur['status'] === 'draft' ? $cur : ['deduction_type_id' => array_key_first($types), 'amount_applied' => '', 'terms' => '', 'trans_term' => 'every_pay', 'purpose' => '']);
$modes = array_filter(LOAN_REQ_MODES, fn($k) => $freq === 'weekly' || !in_array($k, ['third_pay', 'fourth_pay'], true), ARRAY_FILTER_USE_KEY);
$st = fn(string $s) => '<span class="lr-status lr-' . h($s) . '">' . h(LOAN_REQ_STATUS[$s] ?? $s) . '</span>';
?>
<div class="lr-layout">
<?php if ($editing): ?>
  <?php if (!$types): ?>
  <div class="card"><div class="empty-state"><h3>No loan or deduction is open for requests</h3><p class="muted">HR chooses the codes employees may apply for (Employee Transactions Table, Portal request).</p></div></div>
  <?php else: ?>
  <form method="post" class="card lr-form" id="lrForm" data-ppm="<?= h((string)$ppm) ?>" data-every="<?= h((string)$ppm) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $cur && $cur['status'] === 'draft' ? (int)$cur['id'] : '' ?>">
    <div class="lr-head">
      <div><h2><?= $cur && $cur['status'] === 'draft' ? 'Draft ' . h($cur['request_no']) : 'New request' ?></h2>
        <p class="muted small"><?= h(full_name($emp, false)) ?>, <?= h($emp['employee_no']) ?><?= $emp['unit_name'] ? ', ' . h($emp['unit_name']) : '' ?>. Paid <?= h(strtolower(label($freq))) ?>.</p></div>
      <div class="lr-stamp"><span class="muted small">Status</span><?= $st('draft') ?></div>
    </div>
    <div class="lr-grid">
      <div class="lr-fields">
        <div class="form-grid">
          <div class="field wide"><label for="lrType">Type of loan / deduction</label>
            <select name="deduction_type_id" id="lrType" required>
              <?php foreach ($types as $t): ?><option value="<?= (int)$t['id'] ?>" data-rate="<?= h((string)(float)$t['int_rate']) ?>"<?= (int)$v['deduction_type_id'] === (int)$t['id'] ? ' selected' : '' ?>><?= h($t['code'] . ' — ' . $t['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="field"><label>Date applied</label><input value="<?= h(fmt_date($cur['date_applied'] ?? today())) ?>" readonly tabindex="-1"><small class="help">Set when the request is submitted.</small></div>
          <div class="field"><label for="lrAmount">Amount applied</label><input type="number" step="0.01" min="0.01" name="amount_applied" id="lrAmount" value="<?= h((string)$v['amount_applied']) ?>" required inputmode="decimal" placeholder="0.00"></div>
          <div class="field"><label for="lrTerms">Term (paydays)</label><input type="number" step="1" min="1" max="<?= (int)setting('loan_request_max_terms', 48) ?>" name="terms" id="lrTerms" value="<?= h((string)$v['terms']) ?>" required placeholder="e.g. 12"></div>
          <div class="field"><label for="lrMode">Mode of payment</label><select name="trans_term" id="lrMode"><?= options($modes, $v['trans_term'] ?? 'every_pay') ?></select></div>
          <div class="field"><label>Interest rate</label><input id="lrRate" value="" readonly tabindex="-1"><small class="help">From the setup of the code (% a month); 0 when the code has no interest.</small></div>
          <div class="field wide"><label for="lrPurpose">Purpose / remarks <span class="muted">(optional)</span></label><textarea name="purpose" id="lrPurpose" rows="2" maxlength="255" placeholder="e.g. Tuition of my child, house repair"><?= h((string)($v['purpose'] ?? '')) ?></textarea></div>
        </div>
      </div>
      <aside class="lr-calc" aria-live="polite">
        <h3>Amortization</h3>
        <dl>
          <div><dt>Amount applied</dt><dd id="lrcAmount">0.00</dd></div>
          <div><dt>Computed interest</dt><dd id="lrcInterest">0.00</dd></div>
          <div class="lr-sep"><dt>Principal amort.</dt><dd id="lrcPp">0.00</dd></div>
          <div><dt>Int. amort.</dt><dd id="lrcPi">0.00</dd></div>
          <div class="lr-total"><dt>Total amort.</dt><dd id="lrcTot">0.00</dd></div>
        </dl>
        <p class="small muted" id="lrcNote">Per payday, <span id="lrcMode"></span>.</p>
        <p class="small muted">Total to pay <strong id="lrcGrand">0.00</strong>. The approver may change the amount, the term and the mode, and sets the start of payment.</p>
      </aside>
    </div>
    <div class="form-actions lr-actions">
      <button class="btn btn-primary" name="_action" value="submit" data-confirm="Submit this request for approval? It can no longer be changed, only cancelled while it waits.">Submit for approval</button>
      <button class="btn" name="_action" value="save" formnovalidate>Save as draft</button>
      <?php if ($cur && $cur['status'] === 'draft'): ?>
        <button class="btn btn-danger-outline" name="_action" value="delete" formnovalidate data-confirm="Delete this draft?">Delete draft</button>
      <?php endif; ?>
      <a class="btn" href="<?= url('my_loan_requests') ?>">Close</a>
    </div>
  </form>
  <?php endif; ?>
<?php elseif ($cur): ?>
  <div class="card lr-view">
    <div class="lr-head">
      <div><h2><?= h($cur['request_no']) ?>, <?= h($cur['type_name']) ?></h2>
        <p class="muted small">Applied <?= h(fmt_date($cur['date_applied'])) ?><?= $cur['submitted_at'] ? ', submitted ' . h(fmt_dt($cur['submitted_at'])) : '' ?>.</p></div>
      <div class="lr-stamp"><span class="muted small">Status</span><?= $st($cur['status']) ?></div>
    </div>
    <div class="lr-cols">
      <section><h3>Application</h3>
        <dl class="lr-dl">
          <dt>Amount applied</dt><dd><?= money($cur['amount_applied']) ?></dd>
          <dt>Term</dt><dd><?= (int)$cur['terms'] ?> paydays, <?= h(LOAN_REQ_MODES[$cur['trans_term']] ?? $cur['trans_term']) ?></dd>
          <dt>Interest rate</dt><dd><?= h(rtrim(rtrim(number_format((float)$cur['int_rate'], 3), '0'), '.')) ?>% a month</dd>
          <dt>Computed interest</dt><dd><?= money($cur['interest_amount']) ?></dd>
          <?php if ($cur['status'] !== 'approved'): ?>
          <dt>Principal / int. amort.</dt><dd><?= money($cur['pp_principal']) ?> + <?= money($cur['pp_interest']) ?></dd>
          <dt>Total amort.</dt><dd><strong><?= money((float)$cur['pp_principal'] + (float)$cur['pp_interest']) ?></strong> per payday</dd>
          <?php endif; ?>
          <?php if ($cur['purpose']): ?><dt>Purpose</dt><dd><?= h($cur['purpose']) ?></dd><?php endif; ?>
        </dl></section>
      <section><h3>Approval</h3>
        <?php if ($cur['status'] === 'approved'): ?>
        <dl class="lr-dl">
          <dt>Amount approved</dt><dd><strong><?= money($cur['amount_approved']) ?></strong></dd>
          <dt>Term</dt><dd><?= (int)$cur['terms_approved'] ?> paydays, <?= h(LOAN_REQ_MODES[$cur['trans_term_approved']] ?? $cur['trans_term_approved']) ?></dd>
          <dt>Interest</dt><dd><?= money($cur['interest_approved']) ?> (<?= h(rtrim(rtrim(number_format((float)$cur['int_rate_approved'], 3), '0'), '.')) ?>% a month)</dd>
          <dt>Principal / int. amort.</dt><dd><?= money($cur['pp_principal']) ?> + <?= money($cur['pp_interest']) ?></dd>
          <dt>Total amort.</dt><dd><strong><?= money((float)$cur['pp_principal'] + (float)$cur['pp_interest']) ?></strong> per payday</dd>
          <dt>Start of payment</dt><dd><?= h(fmt_date($cur['start_date'])) ?></dd>
          <dt>Approved by</dt><dd><?= h($cur['decided_name'] ?: '—') ?>, <?= h(fmt_dt($cur['decided_at'])) ?></dd>
          <?php if ($cur['decision_remarks']): ?><dt>Remarks</dt><dd><?= h($cur['decision_remarks']) ?></dd><?php endif; ?>
        </dl>
        <p class="small muted">Entered in your transactions: the payroll deducts it from the start of payment. Follow the balance in My Loans &amp; Ledgers.</p>
        <?php elseif ($cur['status'] === 'rejected'): ?>
        <dl class="lr-dl"><dt>Rejected by</dt><dd><?= h($cur['decided_name'] ?: '—') ?>, <?= h(fmt_dt($cur['decided_at'])) ?></dd><dt>Reason</dt><dd><?= h($cur['decision_remarks'] ?: '—') ?></dd></dl>
        <?php elseif ($cur['status'] === 'cancelled'): ?>
        <p class="muted">You cancelled this request.</p>
        <?php else: ?>
        <p class="muted">Waiting for your immediate superior, HR or the administrator.</p>
        <form method="post" class="inline" data-confirm="Cancel this request?"><?= csrf_field() ?><input type="hidden" name="_action" value="cancel"><input type="hidden" name="id" value="<?= (int)$cur['id'] ?>"><button class="btn btn-danger-outline">Cancel request</button></form>
        <?php endif; ?>
      </section>
    </div>
  </div>
<?php endif; ?>

  <div class="card flush">
    <div class="card-head"><div><h2>My requests</h2><div class="sub"><?= count($mine) ?> request(s)</div></div></div>
    <div class="table-wrap">
      <table class="table table-compact lr-table">
        <thead><tr><th>No.</th><th>Type</th><th>Applied</th><th class="num">Amount</th><th class="num">Term</th><th>Mode</th><th class="num">Total amort.</th><th>Status</th><th class="actions"></th></tr></thead>
        <tbody>
        <?php if (!$mine) echo empty_row(9, 'No request yet. Press + New request.'); ?>
        <?php foreach ($mine as $r):
            $ok = $r['status'] === 'approved'; ?>
          <tr class="<?= $cur && (int)$cur['id'] === (int)$r['id'] ? 'is-current' : '' ?>">
            <td class="nowrap"><a href="<?= url('my_loan_requests', ['id' => $r['id']]) ?>"><strong><?= h($r['request_no']) ?></strong></a></td>
            <td><?= h($r['type_name']) ?></td>
            <td class="nowrap"><?= h(fmt_date($r['date_applied'])) ?></td>
            <td class="num"><?= money($ok ? $r['amount_approved'] : $r['amount_applied']) ?><?= $ok && (float)$r['amount_approved'] !== (float)$r['amount_applied'] ? '<br><small class="muted">applied ' . money($r['amount_applied']) . '</small>' : '' ?></td>
            <td class="num"><?= (int)($ok ? $r['terms_approved'] : $r['terms']) ?></td>
            <td class="small"><?= h(LOAN_REQ_MODES[$ok ? $r['trans_term_approved'] : $r['trans_term']] ?? '') ?></td>
            <td class="num"><?= money((float)$r['pp_principal'] + (float)$r['pp_interest']) ?></td>
            <td><?= $st($r['status']) ?><?= $ok && $r['start_date'] ? '<br><small class="muted">from ' . h(fmt_date($r['start_date'])) . '</small>' : '' ?></td>
            <td class="actions"><a class="btn btn-xs" href="<?= url('my_loan_requests', ['id' => $r['id']]) ?>"><?= $r['status'] === 'draft' ? 'Edit' : 'View' ?></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php if ($editing && $types): ?>
<script>
(function () {
  // the same computation as the transactions with terms: interest = amount x rate % a month x months
  var f = document.getElementById('lrForm');
  if (!f) return;
  var ppm = parseFloat(f.getAttribute('data-ppm')) || 2;
  var type = document.getElementById('lrType'), amt = document.getElementById('lrAmount'), terms = document.getElementById('lrTerms'), mode = document.getElementById('lrMode'), rate = document.getElementById('lrRate');
  var money = function (n) { return (Math.round(n * 100) / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var set = function (id, v) { document.getElementById(id).textContent = v; };
  var run = function () {
    var r = parseFloat(type.options[type.selectedIndex].getAttribute('data-rate')) || 0;
    rate.value = (Math.round(r * 1000) / 1000) + '% a month';
    var a = Math.max(0, parseFloat(amt.value) || 0), t = Math.max(1, parseInt(terms.value, 10) || 1);
    var months = t / (mode.value === 'every_pay' ? Math.max(1, ppm) : 1);
    var interest = Math.round(a * r / 100 * months * 100) / 100;
    var pp = Math.round(a / t * 100) / 100, pi = Math.round(interest / t * 100) / 100;
    set('lrcAmount', money(a)); set('lrcInterest', money(interest)); set('lrcPp', money(pp)); set('lrcPi', money(pi)); set('lrcTot', money(pp + pi));
    set('lrcGrand', money(a + interest));
    set('lrcMode', mode.options[mode.selectedIndex].textContent.toLowerCase() + ', ' + t + ' payday' + (t > 1 ? 's' : '') + (months !== t ? ' (about ' + (Math.round(months * 10) / 10) + ' months)' : ''));
  };
  [type, amt, terms, mode].forEach(function (el) { el.addEventListener('input', run); el.addEventListener('change', run); });
  run();
})();
</script>
<?php endif; ?>
<?php page_footer();
