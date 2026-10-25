<?php
/**
 * Period Closing / Payroll Closing (Process & Registers): the separate form that ends the payroll of a
 * period. Parameters: Pay Code, Period to Close, Company / Branch, Employee Group, Employee Type.
 * Closing posts the salaries, payments, leaves, government deductions, taxes and employee ledgers of
 * the finalized payroll (app/engine/closing.php). Routes: period_closing (the form), closing_print
 * (printed closing report), closing_backup (database backup download).
 */
$route = (string)get('p');
if ($route === 'closing_backup') {
    closing_stream_backup();
}
if ($route === 'closing_print') {
    $pc = row('SELECT pc.*, pg.code AS pay_group_code, pg.name AS pay_group_name, u.full_name AS closed_name, b.name AS branch_name, g.name AS group_name, t.name AS type_name
               FROM period_closings pc JOIN pay_groups pg ON pg.id = pc.pay_group_id LEFT JOIN users u ON u.id = pc.closed_by LEFT JOIN branches b ON b.id = pc.branch_id
               LEFT JOIN emp_groups g ON g.id = pc.employee_group_id LEFT JOIN emp_types t ON t.id = pc.employee_type_id WHERE pc.id = ?', [(int)get('id')]);
    if (!$pc) {
        throw new RuntimeException('Period closing not found.');
    }
    $runs = rows('SELECT r.run_no, r.run_type, r.period_start, r.period_end, r.pay_date, COUNT(pi.id) AS n, SUM(pi.gross_pay) AS gross, SUM(pi.net_pay) AS net
                  FROM payroll_items pi JOIN payroll_runs r ON r.id = pi.run_id WHERE pi.closing_id = ? GROUP BY r.id ORDER BY r.pay_date', [(int)$pc['id']]);
    print_header('Period Closing ' . $pc['closing_no']);
    echo company_letterhead(); ?>
    <h1>Period Closing Report</h1>
    <table class="doc-table"><tbody>
      <tr><th>Closing no.</th><td><?= h($pc['closing_no']) ?></td><th>Pay code</th><td><?= h($pc['pay_group_code'] . ' — ' . $pc['pay_group_name']) ?></td></tr>
      <tr><th>Period closed</th><td><?= h(fmt_date($pc['period_start'])) ?> – <?= h(fmt_date($pc['period_end'])) ?></td><th>Closed</th><td><?= h(fmt_dt($pc['closed_at'])) ?>, <?= h($pc['closed_name'] ?? '') ?></td></tr>
      <tr><th>Company / branch</th><td><?= h($pc['branch_name'] ?? 'All') ?></td><th>Group / type</th><td><?= h(($pc['group_name'] ?? 'All groups') . ', ' . ($pc['type_name'] ?? 'all types')) ?></td></tr>
    </tbody></table>
    <table class="doc-table">
      <thead><tr><th>Payroll run</th><th>Type</th><th>Period</th><th>Pay date</th><th class="num">Employees</th><th class="num">Gross</th><th class="num">Net</th></tr></thead>
      <tbody><?php foreach ($runs as $r): ?><tr><td><?= h($r['run_no']) ?></td><td><?= h(RUN_TYPES[$r['run_type']] ?? $r['run_type']) ?></td><td><?= h(fmt_date($r['period_start'])) ?> – <?= h(fmt_date($r['period_end'])) ?></td><td><?= h(fmt_date($r['pay_date'])) ?></td>
        <td class="num"><?= (int)$r['n'] ?></td><td class="num"><?= money($r['gross']) ?></td><td class="num"><?= money($r['net']) ?></td></tr><?php endforeach; ?></tbody>
    </table>
    <table class="doc-table"><thead><tr><th>Posted</th><th class="num">Employee share</th><th class="num">Employer share</th><th class="num">Total</th></tr></thead><tbody>
      <tr><td>SSS / EC</td><td class="num"><?= money($pc['sss_ee']) ?></td><td class="num"><?= money($pc['sss_er']) ?></td><td class="num"><?= money($pc['sss_ee'] + $pc['sss_er']) ?></td></tr>
      <tr><td>PhilHealth</td><td class="num"><?= money($pc['phic_ee']) ?></td><td class="num"><?= money($pc['phic_er']) ?></td><td class="num"><?= money($pc['phic_ee'] + $pc['phic_er']) ?></td></tr>
      <tr><td>Pag-IBIG</td><td class="num"><?= money($pc['hdmf_ee']) ?></td><td class="num"><?= money($pc['hdmf_er']) ?></td><td class="num"><?= money($pc['hdmf_ee'] + $pc['hdmf_er']) ?></td></tr>
      <tr><td>Withholding tax</td><td class="num"><?= money($pc['tax']) ?></td><td></td><td class="num"><?= money($pc['tax']) ?></td></tr>
    </tbody><tfoot><tr><td>Gross <?= money($pc['gross']) ?>, deductions <?= money($pc['deductions']) ?></td><td colspan="2">Employees <?= (int)$pc['employees'] ?></td><td class="num">Net <?= money($pc['net']) ?></td></tr></tfoot></table>
    <h3>Status</h3>
    <ol class="closing-log"><?php foreach (explode("\n", (string)$pc['log']) as $ln): if (trim($ln) === '') continue; ?><li><?= h($ln) ?></li><?php endforeach; ?></ol>
    <?= signatory_block() ?>
    <?php print_footer();
    return;
}

// ------------------------------------------------------------------ the form
$pgId = (int)get('pay_group_id', 0) ?: (int)val("SELECT pay_group_id FROM attendance_cutoffs WHERE status <> 'locked' ORDER BY period_end LIMIT 1") ?: (int)val('SELECT id FROM pay_groups ORDER BY id LIMIT 1');
$f = ['branch_id' => (int)get('branch_id', 0) ?: null, 'employee_group_id' => (int)get('employee_group_id', 0) ?: null, 'employee_type_id' => (int)get('employee_type_id', 0) ?: null];
$periods = [];
foreach (rows("SELECT * FROM attendance_cutoffs WHERE pay_group_id = ? AND status <> 'locked' ORDER BY period_end LIMIT 60", [$pgId]) as $c) {
    $periods[(int)$c['id']] = fmt_date($c['period_start'], 'm/d/y') . ' - ' . fmt_date($c['period_end'], 'm/d/y') . ($c['pay_date'] ? ', pay date ' . fmt_date($c['pay_date'], 'M d') : '');
}
// default: the oldest open period that has a finalized payroll waiting, else the oldest open one
$cutId = (int)get('cutoff_id', 0);
if (!$cutId || !isset($periods[$cutId])) {
    $cutId = (int)val("SELECT c.id FROM attendance_cutoffs c JOIN payroll_runs r ON r.cutoff_id = c.id AND r.status = 'finalized' AND r.posted_at IS NULL
                       WHERE c.pay_group_id = ? AND c.status <> 'locked' ORDER BY c.period_end LIMIT 1", [$pgId]) ?: (int)(array_key_first($periods) ?? 0);
}
$cut = $cutId ? pt_period($cutId) : null;
$keep = array_filter(['pay_group_id' => $pgId, 'cutoff_id' => $cutId] + $f);

if (is_post()) {
    try {
        $id = closing_execute((int)post('cutoff_id'), ['branch_id' => (int)post('branch_id') ?: null, 'employee_group_id' => (int)post('employee_group_id') ?: null,
            'employee_type_id' => (int)post('employee_type_id') ?: null], post('backup_done') === '1');
        $pc = row('SELECT * FROM period_closings WHERE id = ?', [$id]);
        flash('success', 'Period closed (' . $pc['closing_no'] . '): ' . (int)$pc['employees'] . ' employee(s) posted' . ($pc['period_locked'] ? ', the period is now paid and locked.' : '.'));
        redirect('period_closing', $keep + ['done' => $id]);
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
        redirect('period_closing', $keep);
    }
}

$pv = $cut ? closing_preview($cut, $f) : null;
$done = (int)get('done', 0) ? row('SELECT * FROM period_closings WHERE id = ?', [(int)get('done')]) : null;
$history = rows('SELECT pc.*, pg.code AS pay_group_code, u.full_name AS closed_name, b.code AS branch_code FROM period_closings pc JOIN pay_groups pg ON pg.id = pc.pay_group_id
                 LEFT JOIN users u ON u.id = pc.closed_by LEFT JOIN branches b ON b.id = pc.branch_id ORDER BY pc.id DESC LIMIT 20');
$canClose = $pv && !$pv['blocking'] && $pv['items'] && can('period_closing.approve');

page_header('Period Closing / Payroll Closing', [
    'subtitle' => 'The last step of the payroll: posting the salaries, payments, leaves, government deductions, taxes and employee ledgers of the finalized payroll of a period.',
    'actions' => '<a class="btn" href="' . url('payroll_runs') . '">Payroll runs</a>' . (can('period_closing.export') ? '<a class="btn" href="' . url('closing_backup', ['backup' => 1]) . '">Download database backup</a>' : ''),
]);
?>
<div class="closing-layout">
  <div class="card closing-card">
    <div class="closing-warn"><strong>Warning:</strong> BACKUP MUST BE DONE BEFORE THIS JOB. PLEASE CHECK WITH MIS.
      <span>Closing cannot be undone: it posts to the employee ledgers and locks the period.</span></div>
    <form method="get" class="closing-params">
      <input type="hidden" name="p" value="period_closing">
      <div class="form-grid">
        <div class="field"><label>Pay Code</label><select name="pay_group_id" data-autosubmit><?= options(pairs("SELECT id, CONCAT(code, ' — ', name) FROM pay_groups ORDER BY code"), $pgId) ?></select></div>
        <div class="field"><label>Period to Close</label><select name="cutoff_id" data-autosubmit><?= options($periods, $cutId, $periods ? null : 'No open period') ?></select></div>
        <div class="field"><label>Company / Branch</label><select name="branch_id" data-autosubmit><?= options(pairs("SELECT id, CONCAT(COALESCE(branch_code, code), ' — ', name) FROM branches WHERE is_active = 1 ORDER BY company_code, branch_code, code"), $f['branch_id'], 'All companies') ?></select></div>
        <div class="field"><label>Employee Group</label><select name="employee_group_id" data-autosubmit><?= options(emp_group_options(), $f['employee_group_id'], 'All groups') ?></select></div>
        <div class="field"><label>Employee Type</label><select name="employee_type_id" data-autosubmit><?= options(pairs("SELECT id, CONCAT(code, ' — ', name) FROM emp_types WHERE is_active = 1 ORDER BY code"), $f['employee_type_id'], 'All types') ?></select></div>
      </div>
      <noscript><button class="btn">Show</button></noscript>
    </form>

    <fieldset class="closing-status">
      <legend>Status</legend>
      <?php if ($done): ?>
        <ol class="closing-log"><?php foreach (explode("\n", (string)$done['log']) as $ln): if (trim($ln) === '') continue; ?><li><?= h($ln) ?></li><?php endforeach; ?></ol>
        <p class="small"><a href="<?= h(url('closing_print', ['id' => $done['id']])) ?>" target="_blank">Print the closing report <?= h($done['closing_no']) ?></a></p>
      <?php elseif (!$cut): ?>
        <p class="muted">No open period for this pay code.</p>
      <?php else: ?>
        <?php if ($pv['blocking']): ?>
          <div class="callout bad">Not ready: finalize or cancel <?php foreach ($pv['blocking'] as $i => $r): ?><?= $i ? ', ' : '' ?><a href="<?= url('payroll_run', ['id' => $r['id']]) ?>"><?= h($r['run_no']) ?></a> (<?= h($r['status']) ?>)<?php endforeach; ?> first.</div>
        <?php elseif (!$pv['items']): ?>
          <p class="muted"><?= $pv['ready'] ? 'Everything of this selection is already posted.' : 'No finalized payroll run for this period yet. Process and finalize the payroll first.' ?></p>
        <?php else: $t = $pv['totals']; ?>
          <p>Ready to post <strong><?= (int)$t['employees'] ?></strong> employee(s) of
            <?= implode(', ', array_map(fn($r) => '<a href="' . url('payroll_run', ['id' => $r['id']]) . '">' . h($r['run_no']) . '</a>', array_filter($pv['ready'], fn($r) => (int)$r['unposted'] > 0))) ?>.</p>
          <dl class="closing-totals">
            <div><dt>Gross</dt><dd><?= money($t['gross']) ?></dd></div>
            <div><dt>SSS / EC</dt><dd><?= money($t['sss_ee'] + $t['sss_er']) ?></dd></div>
            <div><dt>PhilHealth</dt><dd><?= money($t['phic_ee'] + $t['phic_er']) ?></dd></div>
            <div><dt>Pag-IBIG</dt><dd><?= money($t['hdmf_ee'] + $t['hdmf_er']) ?></dd></div>
            <div><dt>Withholding tax</dt><dd><?= money($t['tax']) ?></dd></div>
            <div><dt>Net pay</dt><dd><strong><?= money($t['net']) ?></strong></dd></div>
          </dl>
          <p class="muted small"><?= (int)$pv['loan_lines'] ?> loan / installment deduction(s) will be posted to the employee ledgers, with the 13th month accrual, the queued earnings and the leave credits.</p>
        <?php endif; ?>
      <?php endif; ?>
    </fieldset>

    <form method="post" class="closing-actions">
      <?= csrf_field() ?><input type="hidden" name="_action" value="close">
      <?php foreach (['cutoff_id' => $cutId] + $f as $k => $v): ?><input type="hidden" name="<?= $k ?>" value="<?= h((string)$v) ?>"><?php endforeach; ?>
      <label class="check"><input type="checkbox" name="backup_done" value="1" required<?= $canClose ? '' : ' disabled' ?>> A backup of the database was made</label>
      <span class="spacer"></span>
      <a class="btn" href="<?= url('dashboard') ?>">Exit</a>
      <button class="btn btn-primary" data-confirm="Close this period? Postings to the employee ledgers cannot be undone."<?= $canClose ? '' : ' disabled' ?>>Close</button>
    </form>
  </div>

  <div class="card flush">
    <div class="card-head pad"><div><h2>Closed periods</h2><div class="sub">The last 20 closings</div></div></div>
    <div class="table-wrap">
      <table class="table table-compact">
        <thead><tr><th>Closing</th><th>Pay code</th><th>Period</th><th>Selection</th><th class="num">Employees</th><th class="num">Net</th><th>Closed</th><th class="actions"></th></tr></thead>
        <tbody>
        <?php foreach ($history as $hc): ?>
          <tr><td class="nowrap"><strong><?= h($hc['closing_no']) ?></strong><br><small class="muted"><?= $hc['period_locked'] ? 'Period locked' : 'Partial' ?></small></td><td><?= h($hc['pay_group_code']) ?></td>
            <td class="nowrap small"><?= h(fmt_date($hc['period_start'], 'M d')) ?> – <?= h(fmt_date($hc['period_end'], 'M d, Y')) ?></td>
            <td class="small"><?= h($hc['branch_code'] ?: 'All') ?><?= $hc['employee_group_id'] ? ', group' : '' ?><?= $hc['employee_type_id'] ? ', type' : '' ?></td>
            <td class="num"><?= (int)$hc['employees'] ?></td><td class="num"><?= money($hc['net']) ?></td>
            <td class="small nowrap"><?= h(fmt_dt($hc['closed_at'])) ?><br><span class="muted"><?= h($hc['closed_name'] ?? '') ?></span></td>
            <td class="actions"><a class="btn btn-xs" target="_blank" href="<?= h(url('closing_print', ['id' => $hc['id']])) ?>">Print</a></td></tr>
        <?php endforeach; ?>
        <?= $history ? '' : empty_row(8, 'No period closed yet.') ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php page_footer();
