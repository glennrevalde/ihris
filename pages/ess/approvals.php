<?php
/**
 * Pending approvals for supervisors, department heads and HR: leave steps and overtime requests.
 */
$u = current_user();
$isHr = can_any(['leave_applications.approve', 'leave_applications.reject']);   // the HR step of every leave chain
$otAll = can_any(['dtr.approve', 'att_exceptions.approve']);                   // overtime of every employee, not only the team
if (is_post()) {
    $act = post('_action');
    if ($act === 'leave') {
        $step = row('SELECT * FROM leave_approval_steps WHERE id = ?', [(int)post('step_id')]);
        if ($step && leave_can_act($step)) {
            $res = leave_act((int)$step['id'], (string)post('decision'), post('remarks'), 'system');
            flash($res['ok'] ? 'success' : 'error', $res['message']);
        }
    } elseif ($act === 'ot') {
        $team = $otAll ? null : my_team_ids();
        foreach (array_map('intval', (array)post('ids', [])) as $id) {
            $r = row("SELECT * FROM overtime_requests WHERE id = ? AND status = 'pending'", [$id]);
            if (!$r || ($team !== null && !in_array((int)$r['employee_id'], $team, true))) {
                continue;
            }
            update('overtime_requests', ['status' => post('decision') === 'approve' ? 'approved' : 'rejected', 'approved_by' => $u['id'], 'approved_at' => now()], 'id = ?', [$id]);
            if ($r['ot_date'] <= today()) {
                process_dtr((int)$r['employee_id'], $r['ot_date']);
            }
            audit('ams', 'ot_' . post('decision'), 'overtime_requests', $id);
        }
        flash('success', 'Overtime request(s) updated.');
    }
    redirect('approvals');
}
// HR steps (not addressed to the user personally) follow the HR user's company / branch and department assignment
$hrScope = scope_sql('e');
$where = $isHr ? "(s.approver_user_id = ? OR (s.approver_role = 'hr'" . $hrScope . '))' : 's.approver_user_id = ?';
if (is_super_user()) {
    $where = '(1=1 OR s.approver_user_id = ?)';
}
$steps = rows("SELECT s.*, la.app_no, la.date_from, la.date_to, la.days, la.reason, la.employee_id, lt.name AS type_name, e.first_name, e.last_name, e.middle_name, e.suffix, e.position
               FROM leave_approval_steps s JOIN leave_applications la ON la.id = s.application_id JOIN leave_types lt ON lt.id = la.leave_type_id JOIN employees e ON e.id = la.employee_id
               WHERE s.status = 'pending' AND $where ORDER BY la.date_from", [$u['id']]);
$team = $otAll ? null : my_team_ids();
$ots = rows("SELECT o.*, e.first_name, e.last_name, e.middle_name, e.suffix FROM overtime_requests o JOIN employees e ON e.id = o.employee_id WHERE o.status = 'pending'"
    . ($team !== null ? ' AND o.employee_id IN (' . ($team ? implode(',', $team) : '0') . ')' : $hrScope) . ' ORDER BY o.ot_date');
page_header('Pending Approvals', ['subtitle' => 'Leave requests routed to you (you can also act from the approval email), your team\'s overtime requests and the loan / deduction requests.']);
$loanWaiting = loan_req_pending_count();   // v20
?>
<?php if ($loanWaiting || can('loan_approvals.view')): ?>
<div class="card lr-pending"><div class="card-head" style="margin:0"><div><h2>Loan / deduction requests (<?= $loanWaiting ?>)</h2>
  <div class="sub"><?= $loanWaiting ? 'Requests of employees waiting for your approval.' : 'Nothing waiting for you.' ?></div></div>
  <?php if (can('loan_approvals.view')): ?><a class="btn<?= $loanWaiting ? ' btn-primary' : '' ?>" href="<?= url('loan_approvals') ?>">Open Loan / Deduction Approvals</a><?php endif; ?></div></div>
<?php endif; ?>
<div class="card"><h2>Leave requests (<?= count($steps) ?>)</h2>
<?php if (!$steps): ?><p class="muted">Nothing waiting for you.</p><?php endif; ?>
<?php foreach ($steps as $s):
    $bal = leave_balance((int)$s['employee_id'], (int)val('SELECT leave_type_id FROM leave_applications WHERE id = ?', [$s['application_id']]), (int)substr($s['date_from'], 0, 4)); ?>
  <div class="form-box" style="border-color:var(--border);border-radius:8px">
    <div class="card-head" style="margin-bottom:6px"><div><strong><?= h(full_name($s, false)) ?></strong> <span class="muted small"><?= h($s['position']) ?></span><br>
      <?= h($s['type_name']) ?>, <?= h(fmt_date($s['date_from'], 'D M d')) ?> – <?= h(fmt_date($s['date_to'], 'D M d, Y')) ?>, <strong><?= h($s['days']) ?> day(s)</strong>, balance <?= h($bal['available']) ?></div>
      <a class="small" href="<?= url('leave_view', ['id' => $s['application_id']]) ?>"><?= h($s['app_no']) ?> — step <?= (int)$s['step_no'] ?> (<?= h(label($s['approver_role'])) ?>)</a></div>
    <p class="small">“<?= h($s['reason']) ?>”</p>
    <?php if (leave_can_act($s)): ?>
    <form method="post" class="filters" style="padding:0"><?= csrf_field() ?><input type="hidden" name="_action" value="leave"><input type="hidden" name="step_id" value="<?= $s['id'] ?>">
      <label>Decision<select name="decision"><?= options(['approve' => 'Approve', 'return' => 'Return for revision', 'disallow' => 'Disallow']) ?></select></label>
      <label style="flex:1">Remarks<input name="remarks" placeholder="Required when returning or disallowing"></label><button class="btn btn-primary">Submit</button></form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="_action" value="ot">
  <div class="card-head"><h2>Overtime requests (<?= count($ots) ?>)</h2><div><button class="btn btn-success" name="decision" value="approve">Approve selected</button> <button class="btn btn-danger-outline" name="decision" value="reject">Reject selected</button></div></div>
  <table class="table compact"><thead><tr><th><input type="checkbox" data-check-all="ids[]"></th><th>Employee</th><th>Date</th><th class="num">Hours</th><th>Reason</th></tr></thead><tbody>
  <?php if (!$ots) echo empty_row(5, 'None pending.'); foreach ($ots as $o): ?><tr><td><input type="checkbox" name="ids[]" value="<?= $o['id'] ?>"></td><td><?= h(full_name($o)) ?></td><td><?= h(fmt_date($o['ot_date'], 'D M d, Y')) ?></td><td class="num"><?= h($o['hours']) ?></td><td class="small"><?= h($o['reason']) ?></td></tr><?php endforeach; ?>
  </tbody></table>
</form>
<?php page_footer();
