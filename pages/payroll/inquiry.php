<?php
/**
 * Payroll Inquiry (Pay Inq.) — everything paid to one employee: pay history, the breakdown of
 * one payslip, year-to-date figures, loans and the transactions waiting for the next payroll.
 */
$empId = (int)get('emp', 0);
$year = (int)get('year', (int)date('Y'));
if (!$empId && my_employee_id() && !can('pay_inquiry.view')) {
    $empId = (int)my_employee_id();
}
$emps = employee_options(false);
if (!$empId) {
    $empId = (int)(array_key_first($emps) ?: 0);
}
$emp = $empId ? row('SELECT e.*, ou.name AS unit_name, b.name AS branch_name, pg.name AS pay_group_name, pg.code AS pay_group_code
                     FROM employees e LEFT JOIN org_units ou ON ou.id = e.org_unit_id LEFT JOIN branches b ON b.id = e.default_branch_id
                     LEFT JOIN pay_groups pg ON pg.id = e.pay_group_id WHERE e.id = ?', [$empId]) : null;
if ($emp && !can('pay_inquiry.view') && (int)$emp['id'] !== my_employee_id()) {
    perm_deny();
}
$items = $emp ? rows("SELECT pi.*, pr.run_no, pr.run_type, pr.period_start, pr.period_end, pr.pay_date, pr.status AS run_status
                      FROM payroll_items pi JOIN payroll_runs pr ON pr.id = pi.run_id
                      WHERE pi.employee_id = ? AND YEAR(pr.period_end) = ? AND pr.status <> 'cancelled'
                      ORDER BY pr.period_end DESC, pr.id DESC", [$empId, $year]) : [];
$ytd = $emp ? payroll_ytd($empId, $year) : [];
$sel = (int)get('item', 0) ?: (int)($items[0]['id'] ?? 0);
$item = $sel ? payslip_item($sel) : null;
$rates = $emp ? employee_rates($emp) : null;
$years = [];
foreach (rows('SELECT DISTINCT YEAR(pr.period_end) y FROM payroll_items pi JOIN payroll_runs pr ON pr.id = pi.run_id WHERE pi.employee_id = ? ORDER BY y DESC', [$empId]) as $y) {
    $years[(int)$y['y']] = (int)$y['y'];
}
$years[(int)date('Y')] = (int)date('Y');
krsort($years);

page_header('Payroll Inquiry', [
    'subtitle' => 'Pay history, payslip details, year-to-date totals, loans and pending transactions of one employee.',
    'actions' => can('pay_register.view') ? '<a class="btn" href="' . url('pay_register') . '">Payroll register</a>' : '',
]);
?>
<form method="get" class="card filters">
  <input type="hidden" name="p" value="pay_inquiry">
  <label>Employee<select name="emp" data-autosubmit><?= options($emps, $empId) ?></select></label>
  <label>Year<select name="year" data-autosubmit><?= options($years, $year) ?></select></label>
</form>

<?php if (!$emp): ?>
  <div class="card"><div class="empty-state"><h3>No employee</h3></div></div>
<?php else: ?>
<div class="card">
  <div class="card-head">
    <div class="person">
      <span class="avatar"><?= h(mb_strtoupper(mb_substr($emp['first_name'], 0, 1) . mb_substr($emp['last_name'], 0, 1))) ?></span>
      <div><div class="person-name"><?= h(full_name($emp)) ?></div>
        <div class="person-meta"><?= h($emp['employee_no']) ?>, <?= h($emp['position'] ?: '—') ?>, <?= h($emp['unit_name'] ?: '—') ?>, <?= h($emp['pay_group_name'] ?: '—') ?>, <?= h($emp['branch_name'] ?: '—') ?></div></div>
    </div>
    <div class="chips">
      <span class="chip">Monthly ₱<?= h(number_format($rates['monthly'], 2)) ?></span>
      <span class="chip">Daily ₱<?= h(number_format($rates['daily'], 2)) ?></span>
      <span class="chip">Hourly ₱<?= h(number_format($rates['hourly'], 2)) ?></span>
      <span class="chip">TIN <?= h($emp['tin'] ?: '—') ?></span>
    </div>
  </div>
</div>

<div class="stats">
  <?= stat_card('YTD gross', money($ytd['gross'] ?? 0), $year . ', finalized runs', 'info') ?>
  <?= stat_card('YTD taxable', money($ytd['taxable'] ?? 0), 'Tax withheld ' . money($ytd['tax'] ?? 0)) ?>
  <?= stat_card('YTD contributions', money($ytd['contrib'] ?? 0), 'SSS + PhilHealth + Pag-IBIG') ?>
  <?= stat_card('YTD net pay', money($ytd['net'] ?? 0), count($items) . ' pay(s) this year', 'good') ?>
</div>

<div class="grid grid-2-1">
  <div class="card flush">
    <div class="card-head"><h2>Pay history <?= h($year) ?></h2></div>
    <div class="table-wrap table-scroll">
      <table class="table table-compact">
        <thead><tr><th>Period</th><th>Run</th><th class="num">Gross</th><th class="num">Deductions</th><th class="num">Net pay</th><th>Status</th><th class="actions"></th></tr></thead>
        <tbody>
        <?php if (!$items) {
            echo empty_row(7, 'No payroll for this year.');
        }
        foreach ($items as $i): ?>
          <tr class="clickable <?= (int)$i['id'] === $sel ? 'row-sel' : '' ?>" onclick="location.href='<?= h(url('pay_inquiry', ['emp' => $empId, 'year' => $year, 'item' => $i['id']])) ?>'">
            <td class="nowrap"><strong><?= h(fmt_date($i['period_start'], 'M d')) ?> – <?= h(fmt_date($i['period_end'], 'M d')) ?></strong><br><small class="muted">paid <?= h(fmt_date($i['pay_date'])) ?></small></td>
            <td><?= h($i['run_no']) ?><br><small class="muted"><?= h(RUN_TYPES[$i['run_type']] ?? $i['run_type']) ?></small></td>
            <td class="num"><?= money($i['gross_pay']) ?></td>
            <td class="num"><?= money($i['total_deductions']) ?></td>
            <td class="num"><strong><?= money($i['net_pay']) ?></strong></td>
            <td><?= badge($i['run_status']) ?></td>
            <td class="actions"><a class="btn btn-xs" href="<?= h(url('payslip', ['id' => $i['id']])) ?>">Payslip</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="2">Total <?= h($year) ?></td>
          <td class="num"><?= money(array_sum(array_map(fn($i) => (float)$i['gross_pay'], $items))) ?></td>
          <td class="num"><?= money(array_sum(array_map(fn($i) => (float)$i['total_deductions'], $items))) ?></td>
          <td class="num"><?= money(array_sum(array_map(fn($i) => (float)$i['net_pay'], $items))) ?></td>
          <td colspan="2"></td></tr></tfoot>
      </table>
    </div>
  </div>

  <div>
    <div class="card">
      <h2>Loans &amp; accountabilities</h2>
      <?php $leds = rows("SELECT l.*, pg.frequency FROM ledgers l JOIN employees e ON e.id = l.employee_id LEFT JOIN pay_groups pg ON pg.id = e.pay_group_id
                          WHERE l.employee_id = ? AND l.status = 'active' AND l.balance > 0 ORDER BY l.ledger_type, l.id", [$empId]);
      $terms = rows("SELECT tt.*, COALESCE(dt.code, ea.code) AS code, COALESCE(dt.name, ea.name) AS code_name FROM employee_term_trans tt
                     LEFT JOIN deduction_types dt ON dt.id = tt.deduction_type_id LEFT JOIN earning_types ea ON ea.id = tt.earning_type_id
                     WHERE tt.employee_id = ? AND tt.status = 'active' AND tt.trans_type = 'deduction' AND tt.balance > 0 ORDER BY tt.id", [$empId]); ?>
      <div class="sub-list">
        <?php if (!$leds && !$terms): ?><p class="muted">No outstanding balance.</p><?php endif; ?>
        <?php foreach ($leds as $l): ?>
          <div class="sub-item">
            <div class="sub-main"><strong><?= h($l['sub_type'] ?: label($l['ledger_type'])) ?></strong><small class="muted"><?= h($l['ledger_no']) ?><?= $l['reference_no'] ? ', ' . h($l['reference_no']) : '' ?></small></div>
            <div class="sub-amt"><strong><?= money($l['balance']) ?></strong><small class="muted">amort. <?= money($l['amortization']) ?></small></div>
            <button type="button" class="btn btn-xs" data-modal-open="subLed<?= (int)$l['id'] ?>">View subsidiary</button>
          </div>
        <?php endforeach; ?>
        <?php foreach ($terms as $t): ?>
          <div class="sub-item">
            <div class="sub-main"><strong><?= h($t['code_name']) ?></strong><small class="muted"><?= h($t['code']) ?><?= $t['doc_no'] ? ', ' . h($t['doc_no']) : '' ?>, transaction with terms</small></div>
            <div class="sub-amt"><strong><?= money($t['balance']) ?></strong><small class="muted">per pay <?= money((float)$t['per_pay_principal'] + (float)$t['per_pay_interest']) ?></small></div>
            <button type="button" class="btn btn-xs" data-modal-open="subTerm<?= (int)$t['id'] ?>">View subsidiary</button>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php // subsidiary views: the ledger of each loan without leaving Payroll Inquiry
    foreach ($leds as $l):
        $ents = rows('SELECT le.*, pr.run_no FROM ledger_entries le LEFT JOIN payroll_runs pr ON pr.id = le.payroll_run_id WHERE le.ledger_id = ? ORDER BY le.entry_date, le.id', [$l['id']]);
        $sched = ledger_schedule($l, (string)($l['frequency'] ?: 'semi_monthly'));
        $paidAmt = round(array_sum(array_map(fn($en) => (float)$en['decrease'], $ents)), 2);
        $accrual = in_array($l['ledger_type'], ['accrued_benefit', 'savings', 'fund'], true) || (float)$l['total_payable'] <= 0;
        $charged = round(array_sum(array_map(fn($en) => (float)$en['increase'], $ents)), 2); ?>
      <div class="modal" id="subLed<?= (int)$l['id'] ?>" role="dialog" aria-modal="true" aria-label="Subsidiary ledger">
        <div class="modal-box xl sub-modal">
          <div class="sub-head">
            <div><h2><?= h($l['sub_type'] ?: label($l['ledger_type'])) ?></h2>
              <p class="muted small"><?= h($l['ledger_no']) ?>, <?= h(LEDGER_TYPES[$l['ledger_type']] ?? label($l['ledger_type'])) ?><?= $l['reference_no'] ? ', ref. ' . h($l['reference_no']) : '' ?>, <?= h(full_name($emp)) ?></p></div>
            <button type="button" class="icon-btn" data-modal-close aria-label="Close">&times;</button>
          </div>
          <dl class="sub-facts">
            <div><dt>Transaction date</dt><dd><?= h(fmt_date($l['start_date'])) ?></dd></div>
            <div><dt><?= $accrual ? 'Total accrued' : 'Total payable' ?></dt><dd><?= money($accrual ? $charged : $l['total_payable']) ?></dd></div>
            <div><dt><?= $accrual ? 'Released / applied' : 'Payments applied' ?></dt><dd><?= money($paidAmt) ?></dd></div>
            <div><dt>Balance</dt><dd><strong><?= money($l['balance']) ?></strong></dd></div>
            <div><dt>Amortization</dt><dd><?= money($l['amortization']) ?></dd></div>
            <div><dt>Full payment</dt><dd><?= $sched ? h(fmt_date(end($sched)['date'])) : '—' ?></dd></div>
          </dl>
          <div class="sub-grid">
            <div><h3>Ledger entries</h3>
              <div class="table-wrap sched-wrap"><table class="table compact"><thead><tr><th>Date</th><th>Description</th><th class="num">Charge</th><th class="num">Payment</th><th class="num">Balance</th></tr></thead><tbody>
                <?php foreach ($ents as $en): ?><tr><td class="nowrap"><?= h(fmt_date($en['entry_date'])) ?></td><td><?= h($en['description']) ?></td><td class="num"><?= (float)$en['increase'] ? money($en['increase']) : '' ?></td>
                  <td class="num"><?= (float)$en['decrease'] ? money($en['decrease']) : '' ?></td><td class="num"><strong><?= money($en['balance']) ?></strong></td></tr><?php endforeach; ?>
                <?= $ents ? '' : empty_row(5, 'No entry yet.') ?></tbody></table></div></div>
            <div><h3>Projected amortization schedule</h3><?= $sched ? ledger_schedule_table($sched) : '<p class="muted">No amortization set.</p>' ?></div>
          </div>
          <div class="form-actions"><button type="button" class="btn" data-modal-close>Close</button>
            <a class="btn" href="<?= h(url_back('ledger_view', ['id' => $l['id']])) ?>">Open the ledger</a>
            <a class="btn" target="_blank" href="<?= h(url('ledger_print', ['id' => $l['id']])) ?>">Print</a></div>
        </div>
      </div>
    <?php endforeach;
    foreach ($terms as $t):
        $ts = term_schedule((int)$t['id']); ?>
      <div class="modal" id="subTerm<?= (int)$t['id'] ?>" role="dialog" aria-modal="true" aria-label="Transaction with terms">
        <div class="modal-box lg sub-modal">
          <div class="sub-head">
            <div><h2><?= h($t['code_name']) ?></h2><p class="muted small"><?= h($t['code']) ?><?= $t['doc_no'] ? ', doc. ' . h($t['doc_no']) : '' ?>, <?= h(full_name($emp)) ?></p></div>
            <button type="button" class="icon-btn" data-modal-close aria-label="Close">&times;</button>
          </div>
          <dl class="sub-facts">
            <div><dt>Transaction date</dt><dd><?= h(fmt_date($t['trans_date'])) ?></dd></div>
            <div><dt>Principal</dt><dd><?= money($t['principal']) ?></dd></div>
            <div><dt>Interest</dt><dd><?= money($t['interest_amount']) ?></dd></div>
            <div><dt>Terms</dt><dd><?= (int)$t['paid_terms'] ?> of <?= (int)$t['terms'] ?> paid</dd></div>
            <div><dt>Total paid</dt><dd><?= money($t['total_paid'] ?? 0) ?></dd></div>
            <div><dt>Balance</dt><dd><strong><?= money($t['balance']) ?></strong></dd></div>
          </dl>
          <div class="table-wrap sched-wrap"><table class="table compact"><thead><tr><th>#</th><th>Payday</th><th class="num">Principal</th><th class="num">Interest</th><th class="num">Amount</th><th>Status</th><th>Payroll</th></tr></thead><tbody>
            <?php foreach ($ts as $r): ?><tr class="<?= in_array($r['status'], ['posted', 'paid'], true) ? 'sched-paid' : '' ?>"><td><?= (int)$r['seq_no'] ?></td><td class="nowrap"><?= h(fmt_date($r['due_date'])) ?></td>
              <td class="num"><?= money($r['principal']) ?></td><td class="num"><?= money($r['interest']) ?></td><td class="num"><?= money($r['amount']) ?></td><td class="small"><?= h(label($r['status'])) ?></td><td class="small"><?= h($r['run_no'] ?? '') ?></td></tr><?php endforeach; ?>
          </tbody></table></div>
          <div class="form-actions"><button type="button" class="btn" data-modal-close>Close</button>
            <a class="btn" href="<?= h(url_back('emp_term_view', ['id' => $t['id']])) ?>">Open the transaction</a></div>
        </div>
      </div>
    <?php endforeach; ?>
    <div class="card">
      <h2>Pending transactions</h2>
      <table class="table table-compact">
        <tbody>
        <?php $pend = rows("SELECT et.*, COALESCE(ea.name, dt.name) AS code_name FROM employee_transactions et
                            LEFT JOIN earning_types ea ON ea.id = et.earning_type_id LEFT JOIN deduction_types dt ON dt.id = et.deduction_type_id
                            WHERE et.employee_id = ? AND et.status = 'pending' ORDER BY et.period_end", [$empId]);
        if (!$pend) {
            echo '<tr><td class="muted">None waiting for the next payroll.</td></tr>';
        }
        foreach ($pend as $t): ?>
          <tr><td><?= h($t['code_name']) ?><br><small class="muted"><?= h(fmt_date($t['period_start'], 'M d')) ?>–<?= h(fmt_date($t['period_end'], 'M d')) ?></small></td>
            <td class="num"><?= $t['trans_type'] === 'earning' ? '+' : '−' ?><?= money($t['amount']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (can('emp_transactions.create')): ?><a class="btn btn-sm" href="<?= h(url('emp_transactions', ['q' => $emp['employee_no']])) ?>">Encode a transaction</a><?php endif; ?>
    </div>
  </div>
</div>

<?php if ($item): ?>
<div class="card">
  <div class="card-head">
    <div><h2>Payslip breakdown — <?= h($item['run_no']) ?></h2>
      <div class="sub"><?= h(fmt_date($item['period_start'])) ?> – <?= h(fmt_date($item['period_end'])) ?>, paid <?= h(fmt_date($item['pay_date'])) ?></div></div>
    <div>
      <a class="btn btn-sm" href="<?= h(url('payslip', ['id' => $item['id']])) ?>">Open payslip</a>
      <a class="btn btn-sm" href="<?= h(url('payslip', ['id' => $item['id'], 'pdf' => 1])) ?>">PDF</a>
    </div>
  </div>
  <div class="grid grid-2">
    <div>
      <h3>Earnings</h3>
      <table class="table table-compact">
        <tbody>
        <?php foreach (array_filter($item['lines'], fn($l) => $l['line_type'] === 'earning') as $l): ?>
          <tr><td><?= h($l['description']) ?></td><td class="num"><?= money($l['amount']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td>Gross pay</td><td class="num"><?= money($item['gross_pay']) ?></td></tr></tfoot>
      </table>
    </div>
    <div>
      <h3>Deductions</h3>
      <table class="table table-compact">
        <tbody>
        <?php foreach (array_filter($item['lines'], fn($l) => $l['line_type'] === 'deduction') as $l): ?>
          <tr><td><?= h($l['description']) ?></td><td class="num"><?= money($l['amount']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td>Total deductions</td><td class="num"><?= money($item['total_deductions']) ?></td></tr>
          <tr><td><strong>Net pay</strong></td><td class="num"><strong><?= money($item['net_pay']) ?></strong></td></tr></tfoot>
      </table>
      <h3 style="margin-top:18px">Employer share</h3>
      <p class="small muted">SSS <?= money($item['sss_er']) ?>, EC <?= money($item['sss_ec']) ?>, PhilHealth <?= money($item['phic_er']) ?>, Pag-IBIG <?= money($item['hdmf_er']) ?></p>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endif;
page_footer();
