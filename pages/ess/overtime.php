<?php
$me = my_employee_id();
if (!$me) {
    throw new RuntimeException('Your account is not linked to an employee record.');
}
if (is_post()) {
    $hrs = post_num('hours');
    if (!post('ot_date') || $hrs <= 0 || $hrs > 16 || !post('reason')) {
        flash('error', 'Date, hours (up to 16) and the work to be done are required.');
    } else {
        $id = insert('overtime_requests', ['employee_id' => $me, 'ot_date' => post('ot_date'), 'hours' => $hrs, 'reason' => post('reason')]);
        audit('ams', 'ot_request', 'overtime_requests', $id);
        $e = row('SELECT * FROM employees WHERE id = ?', [$me]);
        if ($e['supervisor_id'] && ($mail = employee_email((int)$e['supervisor_id']))) {
            send_mail($mail, null, 'Overtime request: ' . full_name($e, false) . ' — ' . fmt_date(post('ot_date')),
                email_template('Overtime request for approval', '<p>' . h(full_name($e, false)) . ' requests authorization for <strong>' . h($hrs) . ' hour(s)</strong> of overtime on <strong>' . h(fmt_date(post('ot_date'))) . '</strong>.</p><p>Reason: ' . h(post('reason')) . '</p>'
                    . email_button(abs_url('approvals'), 'Review in ' . app_name())), 'overtime_request', $id);
        }
        flash('success', 'Overtime request submitted to your supervisor.');
    }
    redirect('my_overtime');
}
$list = rows('SELECT o.*, (SELECT ot_minutes FROM dtr d WHERE d.employee_id = o.employee_id AND d.work_date = o.ot_date) logged FROM overtime_requests o WHERE o.employee_id = ? ORDER BY o.ot_date DESC LIMIT 100', [$me]);
page_header('My Overtime Requests', ['subtitle' => 'Overtime is paid only up to the authorized hours (Labor Code premium rates apply automatically).']);
?>
<div class="grid grid-2-1">
  <div class="card"><h2>History</h2><table class="table compact"><thead><tr><th>Date</th><th class="num">Hours</th><th class="num">Logged</th><th>Reason</th><th>Status</th></tr></thead><tbody>
  <?php if (!$list) echo empty_row(5); foreach ($list as $o): ?><tr><td><?= h(fmt_date($o['ot_date'], 'D M d, Y')) ?></td><td class="num"><?= h($o['hours']) ?></td><td class="num"><?= $o['logged'] !== null ? hours($o['logged']) : '—' ?></td><td class="small"><?= h($o['reason']) ?></td><td><?= badge($o['status']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <div class="card"><h2>Request overtime</h2>
    <form method="post"><?= csrf_field() ?>
      <div class="field"><label>Date</label><input type="date" name="ot_date" value="<?= h(today()) ?>" required></div>
      <div class="field" style="margin-top:10px"><label>Hours</label><input type="number" step="0.25" name="hours" value="2" required></div>
      <div class="field" style="margin-top:10px"><label>Work to be done / reason</label><textarea name="reason" rows="3" required></textarea></div>
      <div class="form-actions"><button class="btn btn-primary">Submit</button></div>
    </form></div>
</div>
<?php page_footer();
