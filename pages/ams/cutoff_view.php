<?php
$id = (int)get('id', 0);
$cut = row('SELECT c.*, pg.name AS pg_name, pg.frequency FROM attendance_cutoffs c JOIN pay_groups pg ON pg.id = c.pay_group_id WHERE c.id = ?', [$id]);
if (!$cut) {
    throw new RuntimeException('Cutoff not found.');
}
if (is_post()) {
    $act = post('_action');
    if ($act === 'summarize') {
        $r = summarize_cutoff($id);
        flash('success', sprintf('DTR processed and summarized: %d employee(s), %d open exception(s).', $r['employees'], $r['open_exceptions']));
    } elseif ($act === 'approve') {
        approve_cutoff($id);
        flash('success', 'Attendance summary approved. It is now locked and ready for the payroll run.');
    } elseif ($act === 'reopen' && can('cutoffs.approve') && $cut['status'] === 'approved') {
        if (val("SELECT COUNT(*) FROM payroll_runs WHERE cutoff_id = ? AND status = 'finalized'", [$id])) {
            flash('error', 'A finalized payroll run uses this cutoff; it cannot be reopened.');
        } else {
            update('attendance_cutoffs', ['status' => 'summarized', 'approved_at' => null, 'approved_by' => null], 'id = ?', [$id]);
            q('UPDATE attendance_summaries SET is_closed = 0, closed_at = NULL, closed_by = NULL WHERE cutoff_id = ?', [$id]);
            audit('ams', 'cutoff_reopen', 'attendance_cutoffs', $id);
            flash('success', 'Cutoff reopened for corrections.');
        }
    } elseif ($act === 'resolve_all' && can('cutoffs.approve')) {
        $n = q("UPDATE dtr d JOIN employees e ON e.id = d.employee_id SET d.exception_status = 'resolved', d.exception_note = ?, d.updated_by = ?, d.updated_at = NOW()
                WHERE e.pay_group_id = ? AND d.work_date BETWEEN ? AND ? AND d.exception_status = 'open'", [post('note') ?: 'Bulk-reviewed by HR', user_id(), $cut['pay_group_id'], $cut['period_start'], $cut['period_end']])->rowCount();
        audit('ams', 'dtr_bulk_resolve', 'attendance_cutoffs', $id, ['count' => $n, 'note' => post('note')]);
        summarize_cutoff($id);
        flash('success', "$n exception(s) marked resolved and the cutoff was re-summarized.");
    }
    redirect('cutoff_view', ['id' => $id]);
}
$sums = rows('SELECT s.*, e.first_name, e.last_name, e.middle_name, e.suffix, e.employee_no, e.rate_type FROM attendance_summaries s JOIN employees e ON e.id = s.employee_id
              WHERE s.cutoff_id = ?' . user_scope_sql('e') . ' ORDER BY e.last_name', [$id]);
$lines = [];
foreach (rows('SELECT l.*, b.code FROM attendance_summary_lines l JOIN attendance_summaries s ON s.id = l.summary_id JOIN branches b ON b.id = l.branch_id WHERE s.cutoff_id = ?', [$id]) as $l) {
    $lines[$l['summary_id']][] = $l;
}
$openEx = array_sum(array_column($sums, 'open_exceptions'));
$run = row("SELECT * FROM payroll_runs WHERE cutoff_id = ? AND status <> 'cancelled' LIMIT 1", [$id]);
$actions = '';
if (in_array($cut['status'], ['open', 'summarized', 'approved'], true)) {   // v19: closed transactions are kept, the open ones summarized
    $actions .= '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="_action" value="summarize"><button class="btn">Process DTR &amp; summarize</button></form> ';
}
if ($cut['status'] === 'summarized') {
    $actions .= '<form method="post" class="inline" data-confirm="Approve and lock this attendance summary for payroll?">' . csrf_field() . '<input type="hidden" name="_action" value="approve"><button class="btn btn-success"' . ($openEx ? ' disabled title="Resolve exceptions first"' : '') . '>Close attendance (approve for payroll)</button></form> ';
}
if ($cut['status'] === 'approved') {
    $actions .= '<form method="post" class="inline" data-confirm="Reopen this cutoff?">' . csrf_field() . '<input type="hidden" name="_action" value="reopen"><button class="btn">Reopen</button></form> ';
    if (!$run && can('payroll_runs.create')) {
        $actions .= '<a class="btn btn-primary" href="' . url('payroll_runs', ['new' => 1, 'pay_group_id' => $cut['pay_group_id'], 'start' => $cut['period_start'], 'end' => $cut['period_end']]) . '">Create payroll run</a>';
    }
}
if ($cut['status'] !== 'locked') {
    $actions .= ' <a class="btn" href="' . url('att_close', ['cutoff_id' => $id]) . '">Attendance closing by company / group</a>';
}
if ($run) {
    $actions .= ' <a class="btn btn-primary" href="' . url('payroll_run', ['id' => $run['id']]) . '">Payroll ' . h($run['run_no']) . '</a>';
}
page_header('Cutoff: ' . $cut['pg_name'], ['subtitle' => h(fmt_date($cut['period_start'])) . ' – ' . h(fmt_date($cut['period_end'])) . ', ' . badge($cut['status']), 'back' => url('cutoffs'), 'actions' => $actions]);
?>
<div class="stats">
  <?= stat_card('Employees', (string)count($sums)) ?>
  <?= stat_card('Days worked', number_format(array_sum(array_column($sums, 'days_worked')), 1)) ?>
  <?= stat_card('Absences', number_format(array_sum(array_column($sums, 'absent_days')), 1), '', 'warn') ?>
  <?= stat_card('Paid leave days', number_format(array_sum(array_column($sums, 'leave_paid_days')), 1)) ?>
  <?= stat_card('Approved OT hours', number_format(array_sum(array_column($sums, 'ot_minutes')) / 60, 2)) ?>
  <?= stat_card('Open exceptions', (string)$openEx, $openEx ? '<a href="' . url('att_exceptions', ['cutoff_id' => $id]) . '">Resolve</a>' : 'Ready to approve', $openEx ? 'bad' : 'good') ?>
</div>
<?php if ($openEx && $cut['status'] === 'summarized'): ?>
<div class="card"><div class="callout warn">Exceptions (missing punches, unauthorized overtime, unverified web check-ins) must be resolved before approval so that nothing is silently paid or silently withheld.
  Resolve them one by one in the DTR (correct the time, authorize the OT or confirm non-payment), or bulk-confirm below.</div>
  <form method="post" data-confirm="Mark ALL open exceptions in this cutoff as reviewed?"><?= csrf_field() ?><input type="hidden" name="_action" value="resolve_all">
    <div class="form-grid"><div class="field wide"><label>Review note (applies to all)</label><input name="note" placeholder="e.g. Reviewed with supervisors; unauthorized OT not paid"></div></div>
    <div class="form-actions"><button class="btn btn-warning">Bulk-resolve all exceptions</button> <a class="btn" href="<?= url('att_exceptions', ['cutoff_id' => $id]) ?>">Review individually</a></div></form>
</div>
<?php endif; ?>
<div class="card"><div class="table-wrap"><table class="table compact">
  <thead><tr><th>Employee</th><th class="num">Sched.</th><th class="num">Worked</th><th class="num">Absent</th><th class="num">Leave (paid/unpaid)</th><th class="num">Hol. unworked</th><th class="num">Late (min)</th><th class="num">UT (min)</th><th class="num">Reg hrs</th><th class="num">OT hrs</th><th class="num">ND hrs</th><th>By branch / day type</th><th>Flags</th></tr></thead><tbody>
  <?php if (!$sums) echo empty_row(13, 'Not yet summarized.'); foreach ($sums as $s): ?>
    <tr class="<?= $s['open_exceptions'] ? 'row-warn' : '' ?>"><td class="nowrap"><a href="<?= url('dtr', ['employee_id' => $s['employee_id'], 'from' => $cut['period_start'], 'to' => $cut['period_end']]) ?>"><?= h(full_name($s)) ?></a></td>
      <td class="num"><?= (float)$s['scheduled_days'] ?></td><td class="num"><?= (float)$s['days_worked'] ?></td><td class="num"><?= (float)$s['absent_days'] + (float)$s['suspended_days'] ?: '' ?></td>
      <td class="num"><?= ((float)$s['leave_paid_days'] || (float)$s['leave_unpaid_days']) ? (float)$s['leave_paid_days'] . ' / ' . (float)$s['leave_unpaid_days'] : '' ?></td>
      <td class="num"><?= ((float)$s['reg_holiday_unworked'] + (float)$s['spl_holiday_unworked']) ?: '' ?></td><td class="num"><?= $s['late_minutes'] ?: '' ?></td><td class="num"><?= $s['undertime_minutes'] ?: '' ?></td>
      <td class="num"><?= hours($s['reg_minutes']) ?></td><td class="num"><?= $s['ot_minutes'] ? hours($s['ot_minutes']) : '' ?></td><td class="num"><?= $s['nd_minutes'] ? hours($s['nd_minutes']) : '' ?></td>
      <td class="small"><?php foreach ($lines[$s['id']] ?? [] as $l): ?><div><?= h($l['code']) ?>, <?= h(label($l['day_type'])) ?>: <?= (float)$l['days'] ?>d, <?= hours($l['reg_minutes']) ?>h<?= $l['ot_minutes'] ? ' + OT ' . hours($l['ot_minutes']) . 'h' : '' ?></div><?php endforeach; ?></td>
      <td class="small"><?php foreach (array_filter(explode(',', (string)$s['flags'])) as $fl): ?><span class="badge badge-<?= in_array($fl, ['habitual_tardiness', 'tardiness_cap'], true) ? 'red' : 'amber' ?>"><?= h(exception_label($fl)) ?></span> <?php endforeach; ?>
        <?= $s['open_exceptions'] ? '<span class="badge badge-amber">' . (int)$s['open_exceptions'] . ' open</span>' : '' ?>
        <?php if (strpos((string)$s['flags'], 'tardi') !== false && can('cases.create')): ?><a class="small" href="<?= url('cases', ['new' => 1, 'employee_id' => $s['employee_id'], 'offense_id' => 1, 'source' => 'attendance', 'date' => $cut['period_end'], 'details' => sprintf('Late %d time(s) for a total of %d minutes in %s (as of %s).', $s['late_count'], $s['late_minutes'], date('F Y', strtotime($cut['period_end'])), fmt_date($cut['period_end']))]) ?>">Draft NTE</a><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php page_footer();
