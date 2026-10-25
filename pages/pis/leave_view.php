<?php
$id = (int)get('id', 0);
$app = leave_app_full($id);
if (!$app) {
    throw new RuntimeException('Leave application not found.');
}
$steps = rows('SELECT * FROM leave_approval_steps WHERE application_id = ? ORDER BY step_no', [$id]);
$u = current_user();
$isOwner = my_employee_id() === (int)$app['employee_id'];
$isApprover = (bool)array_filter($steps, fn($s) => $s['approver_user_id'] && (int)$s['approver_user_id'] === (int)$u['id']);
$hrLeave = can('leave_applications.view');        // leave administration
$hrAct = can('leave_applications.approve');       // resend / cancel on behalf
if (!$isOwner && !$isApprover && !$hrLeave && !is_manager_of((int)$app['employee_id'])) {
    perm_deny();
}
$pending = null;
foreach ($steps as $s) {
    if ($s['status'] === 'pending') {
        $pending = $s;
    }
}

if (is_post()) {
    $act = post('_action');
    if ($act === 'decide' && $pending && leave_can_act($pending)) {
        $res = leave_act((int)$pending['id'], (string)post('decision'), post('remarks'), 'system');
        flash($res['ok'] ? 'success' : 'error', $res['message']);
    } elseif ($act === 'resend' && $pending && $hrAct) {
        $ok = leave_send_step_email((int)$pending['id']);
        flash($ok ? 'success' : 'error', $ok ? 'Approval email re-sent to ' . $pending['approver_email'] . '.' : 'Could not send the email (no approver email or delivery failed — see Email Outbox).');
    } elseif ($act === 'cancel' && ($isOwner || $hrAct)) {
        $res = leave_cancel($id, (string)(post('remarks') ?: 'Cancelled by ' . $u['username']));
        flash($res['ok'] ? 'success' : 'error', $res['message']);
    }
    redirect('leave_view', ['id' => $id]);
}
$emails = rows("SELECT * FROM email_log WHERE related_type = 'leave_application' AND related_id = ? ORDER BY id", [$id]);
$bal = $app['accrual_method'] !== 'per_event' ? leave_balance((int)$app['employee_id'], (int)$app['leave_type_id'], (int)substr($app['date_from'], 0, 4)) : null;
page_header('Leave ' . $app['app_no'], ['back' => $hrLeave ? url('leave_applications') : ($isOwner ? url('my_leaves') : url('approvals'))]);
?>
<div class="grid grid-2-1">
  <div>
    <div class="card">
      <div class="card-head"><h2><?= h($app['type_name']) ?> — <?= h(full_name($app, false)) ?></h2><?= badge($app['status']) ?></div>
      <dl class="kv">
        <dt>Employee</dt><dd><?= h($app['employee_no']) ?>, <?= h($app['position']) ?>, <?= h($app['org_unit_name']) ?></dd>
        <dt>Inclusive dates</dt><dd><?= h(fmt_date($app['date_from'], 'l, M d, Y')) ?> to <?= h(fmt_date($app['date_to'], 'l, M d, Y')) ?></dd>
        <dt>Days charged</dt><dd><?= h($app['days']) ?> <?= $app['is_paid'] ? '(paid)' : '(unpaid)' ?></dd>
        <dt>Balance</dt><dd><?= $bal ? h($bal['available']) . ' available, ' . h($bal['pending']) . ' pending' : 'Per-occurrence leave' ?></dd>
        <dt>Reason</dt><dd><?= nl2br(h($app['reason'])) ?></dd>
        <?php if ($app['attachment']): ?><dt>Attachment</dt><dd><a href="<?= h(upload_url($app['attachment'])) ?>" target="_blank">View supporting document</a></dd><?php endif; ?>
        <dt>Filed</dt><dd><?= h(fmt_dt($app['filed_at'])) ?></dd>
        <?php if ($app['decided_at']): ?><dt>Decided</dt><dd><?= h(fmt_dt($app['decided_at'])) ?><?= $app['decision_reason'] ? ' — ' . h($app['decision_reason']) : '' ?></dd><?php endif; ?>
      </dl>
    </div>
    <?php if ($pending && leave_can_act($pending)): ?>
    <div class="card"><h3>Your decision (step <?= (int)$pending['step_no'] ?> — <?= h(label($pending['approver_role'])) ?>)</h3>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="decide">
        <div class="form-grid">
          <div class="field"><label>Decision</label><select name="decision"><?= options(['approve' => 'Approve', 'return' => 'Return for revision', 'disallow' => 'Disallow'], 'approve') ?></select></div>
          <div class="field wide"><label>Remarks / reason (required for return or disallow)</label><textarea name="remarks" rows="2"></textarea></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary">Submit decision</button></div>
      </form>
    </div>
    <?php endif; ?>
    <div class="card"><h3>Email trail</h3>
      <table class="table compact"><thead><tr><th>Time</th><th>To</th><th>Subject</th><th>Status</th></tr></thead><tbody>
      <?php if (!$emails) echo empty_row(4); foreach ($emails as $m): ?>
        <tr><td class="nowrap small"><?= h(fmt_dt($m['created_at'], 'M d, h:i:s A')) ?></td><td class="small"><?= h($m['to_email']) ?></td><td class="small"><?php if (can('outbox.view')): ?><a href="<?= url('outbox', ['view' => $m['id']]) ?>"><?= h($m['subject']) ?></a><?php else: ?><?= h($m['subject']) ?><?php endif; ?></td><td><?= badge($m['status']) ?></td></tr>
      <?php endforeach; ?></tbody></table>
    </div>
  </div>
  <div>
    <div class="card"><h3>Approval routing</h3>
      <ul class="timeline">
        <li><strong>Filed</strong><div class="when"><?= h(fmt_dt($app['filed_at'])) ?></div></li>
        <?php foreach ($steps as $s): ?>
          <li><strong>Step <?= (int)$s['step_no'] ?>: <?= h(label($s['approver_role'])) ?></strong> <?= badge($s['status']) ?>
            <div><?= h($s['approver_name']) ?> <span class="muted small"><?= h($s['approver_email']) ?></span></div>
            <?php if ($s['emailed_at']): ?><div class="when">Emailed <?= h(fmt_dt($s['emailed_at'])) ?></div><?php endif; ?>
            <?php if ($s['acted_at']): ?><div class="when">Acted <?= h(fmt_dt($s['acted_at'])) ?> via <?= h($s['acted_via']) ?></div><?php endif; ?>
            <?php if ($s['remarks']): ?><div class="small">“<?= h($s['remarks']) ?>”</div><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if ($pending && $hrAct): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="resend"><button class="btn btn-sm">Re-send approval email</button></form>
      <?php endif; ?>
    </div>
    <?php if (in_array($app['status'], ['pending', 'approved', 'returned'], true) && ($isOwner || $hrAct)): ?>
    <div class="card"><h3>Cancel application</h3>
      <form method="post" data-confirm="Cancel this leave application?"><?= csrf_field() ?><input type="hidden" name="_action" value="cancel">
        <div class="field"><label>Reason</label><input name="remarks"></div>
        <div class="form-actions"><button class="btn btn-danger-outline">Cancel leave</button></div>
      </form>
      <?php if ($app['status'] === 'approved'): ?><p class="small muted">Cancelling an approved leave restores the balance and re-processes the attendance record.</p><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($app['status'] === 'returned' && $isOwner): ?>
      <div class="callout warn">This request was returned for revision. <a href="<?= url('my_leaves', ['edit' => $id]) ?>">Revise and resubmit</a>.</div>
    <?php endif; ?>
  </div>
</div>
<?php page_footer();
