<?php
/**
 * Email-based leave approval landing page (opened from the Approve / Return / Disallow links in the email).
 * The single-use token identifies the approver's step, so approvers can act without logging in.
 */
$token = (string)get('t', '');
$action = (string)get('a', 'approve');
if (!in_array($action, ['approve', 'return', 'disallow'], true)) {
    $action = 'approve';
}
$step = $token !== '' ? row('SELECT * FROM leave_approval_steps WHERE token = ?', [$token]) : null;
$result = null;
$error = null;
if (!$step) {
    $error = 'This approval link is invalid or has already been used.';
} elseif ($step['status'] !== 'pending') {
    $error = 'This request has already been acted upon.';
} elseif ($step['token_expires'] && $step['token_expires'] < now()) {
    $error = 'This approval link has expired. Please sign in to ' . app_name() . ' to act on the request.';
}
if (!$error && is_post()) {
    verify_csrf();
    $act = (string)post('action');
    $res = leave_act((int)$step['id'], $act, post('remarks'), 'email');
    if ($res['ok']) {
        $result = $res['message'];
    } else {
        $error = $res['message'];
        $step = row('SELECT * FROM leave_approval_steps WHERE id = ?', [$step['id']]);
        if ($step['status'] === 'pending') {
            $error .= ''; // keep the form visible
        }
    }
}
$app = $step ? leave_app_full((int)$step['application_id']) : null;
$bal = $app && $app['accrual_method'] !== 'per_event' ? leave_balance((int)$app['employee_id'], (int)$app['leave_type_id'], (int)substr($app['date_from'], 0, 4)) : null;
page_header('Leave approval');
?>
<div class="public-card">
  <div class="brand-mark" style="margin-bottom:10px">HR</div>
  <h1>Leave approval</h1>
  <p class="muted"><?= h(setting('company_name')) ?>, <?= h(app_name()) ?></p>
  <?php if ($result): ?>
    <div class="alert alert-success"><?= h($result) ?></div>
    <p class="muted">A confirmation has been recorded with a time stamp in the leave record. You may now close this window.</p>
  <?php elseif ($error && (!$step || $step['status'] !== 'pending' || ($step['token_expires'] && $step['token_expires'] < now()))): ?>
    <div class="alert alert-error"><?= h($error) ?></div>
    <p><a class="btn" href="<?= url('login') ?>">Sign in to <?= h(app_name()) ?></a></p>
  <?php else: ?>
    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
    <dl class="kv" style="margin-bottom:14px">
      <dt>Application No.</dt><dd><?= h($app['app_no']) ?></dd>
      <dt>Employee</dt><dd><?= h(full_name($app, false)) ?> (<?= h($app['employee_no']) ?>)</dd>
      <dt>Position / Unit</dt><dd><?= h($app['position']) ?> — <?= h($app['org_unit_name']) ?></dd>
      <dt>Leave type</dt><dd><?= h($app['type_name']) ?></dd>
      <dt>Inclusive dates</dt><dd><?= h(fmt_date($app['date_from'])) ?> to <?= h(fmt_date($app['date_to'])) ?></dd>
      <dt>Days charged</dt><dd><?= h($app['days']) ?></dd>
      <dt>Remaining balance</dt><dd><?= $bal ? h($bal['available']) . ' day(s) available' : 'Per-occurrence leave' ?></dd>
      <dt>Reason</dt><dd><?= nl2br(h($app['reason'])) ?></dd>
      <dt>Your role</dt><dd><?= h(label($step['approver_role'])) ?> — step <?= (int)$step['step_no'] ?></dd>
    </dl>
    <form method="post" action="<?= h(url('leave_action', array_filter(['t' => $token, 'a' => $action, 'co' => current_company_id()]))) ?>">
      <?= csrf_field() ?>
      <div class="field"><label>Decision</label>
        <select name="action">
          <?= options(['approve' => 'Approve', 'return' => 'Return for revision', 'disallow' => 'Disallow'], $action) ?>
        </select>
      </div>
      <div class="field" style="margin-top:10px"><label>Remarks / reason <span class="muted">(required when returning or disallowing)</span></label>
        <textarea name="remarks" rows="3"><?= h(post('remarks', '')) ?></textarea>
      </div>
      <div class="form-actions"><button class="btn btn-primary">Submit decision</button></div>
    </form>
  <?php endif; ?>
</div>
<?php page_footer();
