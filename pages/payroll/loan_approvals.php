<?php
/**
 * Approvals > Loan / Deduction Approvals (v20): the counterpart of the employee's Loan / Deduction Request.
 * The immediate superior sees the requests of the team; HR (Employee's Transactions encoders, within their
 * company / branch and department assignment) and the administrator see every request. Approving sets the
 * amount approved, the term, the mode of payment, the interest rate and the start of payment, and enters the
 * transaction with terms of the employee by itself (app/engine/loanreq.php).
 */
$ready = loan_req_supported();
if (is_post() && $ready) {
    verify_csrf();
    $id = (int)post('id');
    try {
        if (post('_action') === 'approve') {
            $r = loan_req_approve($id, $_POST);
            flash('success', sprintf('%s approved: %s in %d paydays, %s a payday from %s. It is now a transaction with terms of %s.', $r['request_no'], money($r['amount_approved']),
                (int)$r['terms_approved'], money((float)$r['pp_principal'] + (float)$r['pp_interest']), fmt_date($r['start_date']), full_name($r, false)));
        } elseif (post('_action') === 'reject') {
            loan_req_reject($id, (string)post('decision_remarks'));
            flash('success', 'Request rejected. The employee sees the reason in the portal.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('loan_approvals', array_filter(['id' => $id ?: null, 'status' => get('status') ?: null]));
}

page_header('Loan / Deduction Approvals', [
    'subtitle' => 'Requests of the employees for a loan or a deduction. Approve the amount, the term and the start of payment: the approved request is entered in the employee\'s transactions by itself.',
    'actions' => (can('emp_transactions.view') ? '<a class="btn" href="' . url('emp_transactions', ['tstatus' => 'active']) . '">Employee\'s Transactions</a>' : '')
        . (can('approvals.view') ? '<a class="btn" href="' . url('approvals') . '">Pending Approvals</a>' : ''),
]);
if (!$ready) {
    echo '<div class="card"><div class="empty-state"><h3>Not available yet</h3><p class="muted">Open the site once to upgrade the database (version 18).</p></div></div>';
    page_footer();
    return;
}
$statusF = array_key_exists((string)get('status', 'submitted'), LOAN_REQ_STATUS + ['all' => 1]) ? (string)get('status', 'submitted') : 'submitted';
$q = trim((string)get('q', ''));
$params = [];
$where = ['1=1'];
if ($statusF !== 'all') {
    $where[] = 'r.status = ?';
    $params[] = $statusF;
} else {
    $where[] = "r.status <> 'draft'";   // a draft is the employee's own until submitted
}
if ($q !== '') {
    $w = search_words_sql(['e.employee_no', 'e.last_name', 'e.first_name', 'r.request_no', 'dt.name'], $q, $params);
    if ($w) {
        $where[] = $w;
    }
}
$list = rows('SELECT r.*, e.employee_no, e.first_name, e.last_name, e.middle_name, e.suffix, ou.name AS unit_name, dt.code AS type_code, dt.name AS type_name
              FROM loan_requests r JOIN employees e ON e.id = r.employee_id JOIN deduction_types dt ON dt.id = r.deduction_type_id LEFT JOIN org_units ou ON ou.id = e.org_unit_id
              WHERE ' . implode(' AND ', $where) . loan_req_visible_sql('e') . ' ORDER BY r.status = \'submitted\' DESC, r.submitted_at, r.id DESC LIMIT 300', $params);
$counts = pairs("SELECT r.status, COUNT(*) FROM loan_requests r JOIN employees e ON e.id = r.employee_id WHERE r.status <> 'draft'" . loan_req_visible_sql('e') . ' GROUP BY r.status');
$id = (int)get('id', 0) ?: (int)($list[0]['id'] ?? 0);
$cur = $id ? loan_req_get($id) : null;
if ($cur && ($cur['status'] === 'draft' || !in_array((int)$cur['id'], array_map('intval', array_column(rows('SELECT r.id FROM loan_requests r JOIN employees e ON e.id = r.employee_id WHERE r.id = ?' . loan_req_visible_sql('e'), [$id]), 'id')), true))) {
    $cur = null;   // not one of the requests this user may see
}
$st = fn(string $s) => '<span class="lr-status lr-' . h($s) . '">' . h(LOAN_REQ_STATUS[$s] ?? $s) . '</span>';
$keep = array_filter(['status' => $statusF !== 'submitted' ? $statusF : null, 'q' => $q !== '' ? $q : null]);
?>
<form method="get" class="card filters">
  <input type="hidden" name="p" value="loan_approvals">
  <label>Status<select name="status" data-autosubmit>
    <?php foreach (['submitted' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled by the employee', 'all' => 'All'] as $k => $l): ?>
      <option value="<?= h($k) ?>"<?= $statusF === $k ? ' selected' : '' ?>><?= h($l) ?><?= $k !== 'all' && !empty($counts[$k]) ? ' (' . (int)$counts[$k] . ')' : '' ?></option>
    <?php endforeach; ?>
  </select></label>
  <label class="grow">Find<input type="search" name="q" value="<?= h($q) ?>" placeholder="Employee, request no. or type" autocomplete="off"></label>
  <button class="btn btn-primary">Show</button>
</form>

<div class="lr-approve">
  <div class="card flush lr-list">
    <div class="card-head"><div><h2>Requests</h2><div class="sub"><?= count($list) ?> request(s)<?= $statusF === 'submitted' ? ' waiting' : '' ?></div></div></div>
    <div class="list-nav">
      <?php foreach ($list as $r): ?>
        <a class="<?= $cur && (int)$cur['id'] === (int)$r['id'] ? 'active' : '' ?>" href="<?= h(url('loan_approvals', $keep + ['id' => $r['id']])) ?>">
          <span class="ln-main"><strong><?= h(full_name($r)) ?></strong>
            <small><?= h($r['request_no']) ?>, <?= h($r['type_name']) ?>, <?= money($r['amount_applied']) ?> / <?= (int)$r['terms'] ?> paydays</small></span>
          <span class="ln-side"><?= $st($r['status']) ?></span>
        </a>
      <?php endforeach; ?>
      <?php if (!$list): ?><p class="muted" style="padding:14px"><?= $statusF === 'submitted' ? 'Nothing is waiting for your approval.' : 'No request for this selection.' ?></p><?php endif; ?>
    </div>
  </div>

  <div>
  <?php if (!$cur): ?>
    <div class="card"><div class="empty-state"><h3>Choose a request</h3><p class="muted">Pick a request on the left to review and decide it.</p></div></div>
  <?php else:
      $freq = $cur['frequency'] ?: 'semi_monthly';
      $ppm = periods_per_month($freq);
      $canDecide = $cur['status'] === 'submitted' && loan_req_can_decide($cur);
      // what the employee already pays: active transactions with terms and the last net pay
      $active = rows("SELECT tt.*, dt.name AS type_name FROM employee_term_trans tt LEFT JOIN deduction_types dt ON dt.id = tt.deduction_type_id
                      WHERE tt.employee_id = ? AND tt.status = 'active' AND tt.trans_type = 'deduction' ORDER BY tt.start_date", [$cur['employee_id']]);
      $lastPay = row("SELECT pi.net_pay, pi.gross_pay, pr.period_end FROM payroll_items pi JOIN payroll_runs pr ON pr.id = pi.run_id
                      WHERE pi.employee_id = ? AND pr.status IN ('computed', 'finalized') ORDER BY pr.period_end DESC LIMIT 1", [$cur['employee_id']]);
      $types = loan_req_types();
      $modes = array_filter(LOAN_REQ_MODES, fn($k) => $freq === 'weekly' || !in_array($k, ['third_pay', 'fourth_pay'], true), ARRAY_FILTER_USE_KEY); ?>
    <div class="card lr-view">
      <div class="lr-head">
        <div><h2><?= h(full_name($cur, false)) ?></h2>
          <p class="muted small"><?= h($cur['employee_no']) ?><?= $cur['position'] ? ', ' . h($cur['position']) : '' ?><?= $cur['unit_name'] ? ', ' . h($cur['unit_name']) : '' ?>. Paid <?= h(strtolower(label($freq))) ?>.</p></div>
        <div class="lr-stamp"><span class="muted small"><?= h($cur['request_no']) ?></span><?= $st($cur['status']) ?></div>
      </div>
      <div class="lr-cols">
        <section><h3>Application</h3>
          <dl class="lr-dl">
            <dt>Type</dt><dd><?= h($cur['type_code'] . ' — ' . $cur['type_name']) ?></dd>
            <dt>Date applied</dt><dd><?= h(fmt_date($cur['date_applied'])) ?><?= $cur['submitted_at'] ? ', submitted ' . h(fmt_dt($cur['submitted_at'])) : '' ?></dd>
            <dt>Amount applied</dt><dd><strong><?= money($cur['amount_applied']) ?></strong></dd>
            <dt>Term</dt><dd><?= (int)$cur['terms'] ?> paydays, <?= h(LOAN_REQ_MODES[$cur['trans_term']] ?? $cur['trans_term']) ?></dd>
            <dt>Interest</dt><dd><?= money($cur['interest_amount']) ?> (<?= h(rtrim(rtrim(number_format((float)$cur['int_rate'], 3), '0'), '.')) ?>% a month)</dd>
            <dt>Principal / int. amort.</dt><dd><?= money($cur['pp_principal']) ?> + <?= money($cur['pp_interest']) ?></dd>
            <?php if ($cur['purpose']): ?><dt>Purpose</dt><dd><?= h($cur['purpose']) ?></dd><?php endif; ?>
          </dl></section>
        <section><h3>Ability to pay</h3>
          <dl class="lr-dl">
            <dt>Last net pay</dt><dd><?= $lastPay ? money($lastPay['net_pay']) . ' <span class="muted small">(period to ' . h(fmt_date($lastPay['period_end'])) . ')</span>' : '<span class="muted">No payroll yet</span>' ?></dd>
            <dt>Active deductions with terms</dt><dd><?= $active ? count($active) . ', ' . money(array_sum(array_map(fn($t) => (float)$t['per_pay_principal'] + (float)$t['per_pay_interest'], $active))) . ' a payday, balance ' . money(array_sum(array_column($active, 'balance'))) : 'None' ?></dd>
          </dl>
          <?php if ($active): ?><ul class="lr-mini"><?php foreach (array_slice($active, 0, 5) as $t): ?><li><?= h($t['type_name'] ?: 'Deduction') ?>: <?= money((float)$t['per_pay_principal'] + (float)$t['per_pay_interest']) ?> a payday, balance <?= money($t['balance']) ?></li><?php endforeach; ?></ul><?php endif; ?>
        </section>
      </div>

      <?php if ($cur['status'] === 'approved'): ?>
        <div class="lr-decided">
          <h3>Approved</h3>
          <p><?= money($cur['amount_approved']) ?> in <?= (int)$cur['terms_approved'] ?> paydays, <?= h(strtolower(LOAN_REQ_MODES[$cur['trans_term_approved']] ?? '')) ?>, <?= money((float)$cur['pp_principal'] + (float)$cur['pp_interest']) ?> a payday from <?= h(fmt_date($cur['start_date'])) ?>.
            By <?= h($cur['decided_name'] ?: '—') ?>, <?= h(fmt_dt($cur['decided_at'])) ?>.<?= $cur['decision_remarks'] ? ' ' . h($cur['decision_remarks']) : '' ?></p>
          <?php if ($cur['term_trans_id'] && can('emp_transactions.view')): ?><a class="btn btn-sm" href="<?= url('emp_term_view', ['id' => $cur['term_trans_id']]) ?>">Open the transaction with terms</a><?php endif; ?>
        </div>
      <?php elseif ($cur['status'] === 'rejected'): ?>
        <div class="lr-decided is-bad"><h3>Rejected</h3><p>By <?= h($cur['decided_name'] ?: '—') ?>, <?= h(fmt_dt($cur['decided_at'])) ?>. <?= h($cur['decision_remarks'] ?: '') ?></p></div>
      <?php elseif ($cur['status'] === 'cancelled'): ?>
        <div class="lr-decided"><h3>Cancelled by the employee</h3></div>
      <?php elseif (!$canDecide): ?>
        <div class="lr-decided"><p class="muted">Waiting for approval. You cannot decide this request<?= (int)$cur['employee_id'] === (int)my_employee_id() ? ' (it is your own)' : '' ?>.</p></div>
      <?php else: ?>
        <form method="post" class="lr-decide" id="lrDecide" data-ppm="<?= h((string)$ppm) ?>">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
          <h3>Decision</h3>
          <div class="lr-grid">
            <div class="form-grid">
              <div class="field"><label for="laAmount">Amount approved</label><input type="number" step="0.01" min="0.01" name="amount_approved" id="laAmount" value="<?= h(number_format((float)$cur['amount_applied'], 2, '.', '')) ?>" required></div>
              <div class="field"><label for="laTerms">Term (paydays)</label><input type="number" step="1" min="1" max="520" name="terms_approved" id="laTerms" value="<?= (int)$cur['terms'] ?>" required></div>
              <div class="field"><label for="laMode">Mode of payment</label><select name="trans_term_approved" id="laMode"><?= options($modes, $cur['trans_term']) ?></select></div>
              <div class="field"><label for="laRate">Interest rate (% a month)</label><input type="number" step="0.001" min="0" max="100" name="int_rate_approved" id="laRate" value="<?= h(rtrim(rtrim(number_format((float)$cur['int_rate'], 3, '.', ''), '0'), '.')) ?>"><small class="help">Default of the code: <?= h(rtrim(rtrim(number_format((float)($types[(int)$cur['deduction_type_id']]['int_rate'] ?? $cur['int_rate']), 3), '0'), '.')) ?>%.</small></div>
              <div class="field"><label for="laStart">Start of payment</label><input type="date" name="start_date" id="laStart" value="<?= h(loan_req_default_start($cur)) ?>" required><small class="help">The first payday the amortization is taken (the payroll period on or after this date).</small></div>
              <div class="field wide"><label for="laRemarks">Remarks <span class="muted">(required to reject)</span></label><input name="decision_remarks" id="laRemarks" maxlength="255" placeholder="e.g. Approved per policy, reduced to two months of salary"></div>
            </div>
            <aside class="lr-calc" aria-live="polite">
              <h3>Amortization</h3>
              <dl>
                <div><dt>Amount approved</dt><dd id="lacAmount">0.00</dd></div>
                <div><dt>Computed interest</dt><dd id="lacInterest">0.00</dd></div>
                <div class="lr-sep"><dt>Principal amort.</dt><dd id="lacPp">0.00</dd></div>
                <div><dt>Int. amort.</dt><dd id="lacPi">0.00</dd></div>
                <div class="lr-total"><dt>Total amort.</dt><dd id="lacTot">0.00</dd></div>
              </dl>
              <p class="small muted">Total to pay <strong id="lacGrand">0.00</strong>.</p>
            </aside>
          </div>
          <div class="form-actions">
            <button class="btn btn-success" name="_action" value="approve" data-confirm="Approve this request? It is entered in the employee's transactions with terms and the payroll deducts it from the start of payment.">Approve</button>
            <button class="btn btn-danger-outline" name="_action" value="reject" formnovalidate data-confirm="Reject this request?">Reject</button>
          </div>
        </form>
        <script>
        (function () {
          var f = document.getElementById('lrDecide'), ppm = parseFloat(f.getAttribute('data-ppm')) || 2;
          var amt = document.getElementById('laAmount'), terms = document.getElementById('laTerms'), mode = document.getElementById('laMode'), rate = document.getElementById('laRate');
          var money = function (n) { return (Math.round(n * 100) / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
          var set = function (id, v) { document.getElementById(id).textContent = v; };
          var run = function () {
            var a = Math.max(0, parseFloat(amt.value) || 0), t = Math.max(1, parseInt(terms.value, 10) || 1), r = Math.max(0, parseFloat(rate.value) || 0);
            var months = t / (mode.value === 'every_pay' ? Math.max(1, ppm) : 1);
            var interest = Math.round(a * r / 100 * months * 100) / 100, pp = Math.round(a / t * 100) / 100, pi = Math.round(interest / t * 100) / 100;
            set('lacAmount', money(a)); set('lacInterest', money(interest)); set('lacPp', money(pp)); set('lacPi', money(pi)); set('lacTot', money(pp + pi)); set('lacGrand', money(a + interest));
          };
          [amt, terms, mode, rate].forEach(function (el) { el.addEventListener('input', run); el.addEventListener('change', run); });
          run();
        })();
        </script>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  </div>
</div>
<?php page_footer();
