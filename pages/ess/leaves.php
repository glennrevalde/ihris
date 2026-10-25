<?php
$me = my_employee_id();
if (!$me) {
    throw new RuntimeException('Your account is not linked to an employee record.');
}
$emp = row('SELECT * FROM employees WHERE id = ?', [$me]);
$year = (int)date('Y');
if (is_post()) {
    $act = post('_action');
    if ($act === 'file') {
        try {
            $att = save_upload('attachment', 'documents/leave', ['pdf', 'jpg', 'jpeg', 'png']);
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('my_leaves');
        }
        $existing = (int)post('edit_id', 0);
        if ($existing && !val("SELECT id FROM leave_applications WHERE id = ? AND employee_id = ? AND status = 'returned'", [$existing, $me])) {
            $existing = 0;
        }
        $data = ['employee_id' => $me, 'leave_type_id' => (int)post('leave_type_id'), 'date_from' => post('date_from'), 'date_to' => post('date_to'), 'reason' => post('reason'), 'attachment' => $att['path'] ?? null];
        $res = leave_file($data, $existing ?: null);
        flash($res['ok'] ? 'success' : 'error', $res['message']);
        if ($res['ok']) {
            redirect('leave_view', ['id' => $res['id']]);
        }
        redirect('my_leaves');
    }
}
$types = rows('SELECT * FROM leave_types WHERE is_active = 1 ORDER BY is_statutory DESC, name');
$eligible = array_filter($types, fn($t) => !leave_ineligibility($emp, $t));
$apps = rows('SELECT la.*, lt.name AS type_name,
              (SELECT CONCAT(s.approver_name) FROM leave_approval_steps s WHERE s.application_id = la.id AND s.status = \'pending\' LIMIT 1) AS waiting_on
              FROM leave_applications la JOIN leave_types lt ON lt.id = la.leave_type_id WHERE la.employee_id = ? ORDER BY la.filed_at DESC', [$me]);
$edit = get('edit') ? row("SELECT * FROM leave_applications WHERE id = ? AND employee_id = ? AND status = 'returned'", [(int)get('edit'), $me]) : null;
page_header('My Leaves', ['subtitle' => 'File leave requests. Approvers are notified by email and can act directly from their inbox; you are emailed when your request is decided.']);
?>
<div class="grid grid-2">
  <div class="card"><h2><?= $edit ? 'Revise returned application ' . h($edit['app_no']) : 'File a leave' ?></h2>
    <?php if ($edit): ?><div class="callout warn">Returned with remarks: <?= h($edit['decision_reason']) ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="_action" value="file"><input type="hidden" name="edit_id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="form-grid">
        <div class="field wide"><label>Leave type</label><select name="leave_type_id" required><?= options(array_column($eligible, 'name', 'id'), $edit['leave_type_id'] ?? null, '— select —') ?></select></div>
        <div class="field"><label>From</label><input type="date" name="date_from" value="<?= h($edit['date_from'] ?? '') ?>" required></div>
        <div class="field"><label>To</label><input type="date" name="date_to" value="<?= h($edit['date_to'] ?? '') ?>" required></div>
        <div class="field wide"><label>Reason</label><textarea name="reason" rows="3"><?= h($edit['reason'] ?? '') ?></textarea></div>
        <div class="field wide"><label>Supporting document (e.g. medical certificate)</label><input type="file" name="attachment"></div>
      </div>
      <p class="small muted">Working-day leaves skip your rest days and holidays automatically.</p>
      <div class="form-actions"><button class="btn btn-primary"><?= $edit ? 'Resubmit' : 'Submit request' ?></button></div>
    </form>
  </div>
  <div class="card"><h2>Balances <?= $year ?></h2>
    <table class="table compact"><thead><tr><th>Leave</th><th class="num">Available</th><th class="num">Pending</th><th class="num">Used</th></tr></thead><tbody>
    <?php foreach ($eligible as $t):
        if ($t['accrual_method'] === 'per_event') { echo '<tr><td>' . h($t['name']) . '</td><td colspan="3" class="small muted">Up to ' . (float)$t['days_per_year'] . ' day(s) per occurrence</td></tr>'; continue; }
        $b = leave_balance($me, (int)$t['id'], $year); ?>
      <tr><td><?= h($t['name']) ?></td><td class="num"><strong><?= $b['available'] ?></strong></td><td class="num"><?= $b['pending'] ?: '' ?></td><td class="num"><?= $b['used'] ?: '' ?></td></tr>
    <?php endforeach; ?></tbody></table>
  </div>
</div>
<div class="card"><h2>My applications</h2>
  <table class="table"><thead><tr><th>No.</th><th>Type</th><th>Dates</th><th class="num">Days</th><th>Status</th><th>Waiting on</th><th></th></tr></thead><tbody>
  <?php if (!$apps) echo empty_row(7); foreach ($apps as $a): ?>
    <tr><td><?= h($a['app_no']) ?></td><td><?= h($a['type_name']) ?></td><td class="nowrap"><?= h(fmt_date($a['date_from'], 'M d')) ?> – <?= h(fmt_date($a['date_to'], 'M d, Y')) ?></td><td class="num"><?= h($a['days']) ?></td>
      <td><?= badge($a['status']) ?></td><td class="small"><?= h($a['waiting_on']) ?></td>
      <td class="actions"><a class="btn btn-sm" href="<?= url('leave_view', ['id' => $a['id']]) ?>">Details</a><?php if ($a['status'] === 'returned'): ?><a class="btn btn-sm btn-primary" href="<?= url('my_leaves', ['edit' => $a['id']]) ?>">Revise</a><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table>
</div>
<?php page_footer();
