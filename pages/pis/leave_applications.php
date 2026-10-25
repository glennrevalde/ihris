<?php
if (is_post() && post('_action') === 'file') {
    $att = null;
    try {
        $att = save_upload('attachment', 'documents/leave', ['pdf', 'jpg', 'jpeg', 'png']);
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
        redirect('leave_applications', ['file_for' => post('employee_id')]);
    }
    $res = leave_file(['employee_id' => (int)post('employee_id'), 'leave_type_id' => (int)post('leave_type_id'), 'date_from' => post('date_from'),
        'date_to' => post('date_to'), 'reason' => post('reason'), 'attachment' => $att['path'] ?? null]);
    flash($res['ok'] ? 'success' : 'error', $res['message']);
    redirect($res['ok'] ? 'leave_view' : 'leave_applications', $res['ok'] ? ['id' => $res['id']] : ['file_for' => post('employee_id')]);
}
$f = ['status' => get('status', ''), 'type' => get('type', ''), 'emp' => get('emp', ''), 'from' => get('from', date('Y-01-01')), 'to' => get('to', date('Y-12-31'))];
$where = ['la.date_to >= ?', 'la.date_from <= ?'];
$params = [$f['from'], $f['to']];
foreach (['status' => 'la.status', 'type' => 'la.leave_type_id', 'emp' => 'la.employee_id'] as $k => $col) {
    if ($f[$k] !== '') {
        $where[] = "$col = ?";
        $params[] = $f[$k];
    }
}
$list = rows('SELECT la.*, lt.name AS type_name, lt.code AS type_code, e.first_name, e.last_name, e.middle_name, e.suffix, e.employee_no,
                     (SELECT CONCAT(s.approver_name, \' (\', s.approver_role, \')\') FROM leave_approval_steps s WHERE s.application_id = la.id AND s.status = \'pending\' LIMIT 1) AS waiting_on
              FROM leave_applications la JOIN leave_types lt ON lt.id = la.leave_type_id JOIN employees e ON e.id = la.employee_id
              WHERE ' . implode(' AND ', $where) . user_scope_sql('e') . ' ORDER BY la.filed_at DESC', $params);
$fileFor = get('file_for');
page_header('Leave Applications', ['subtitle' => 'All leave requests with their approval routing (supervisor → department head → HR) and email trail.',
    'actions' => '<button class="btn btn-primary" data-modal-open="fileModal">+ File leave for an employee</button> <a class="btn" href="' . url('leave_balances') . '">Balances &amp; accrual</a>']);
?>
<form class="card filters" method="get"><input type="hidden" name="p" value="leave_applications">
  <label>Status<select name="status"><?= options(['pending' => 'Pending', 'approved' => 'Approved', 'returned' => 'Returned', 'disallowed' => 'Disallowed', 'cancelled' => 'Cancelled'], $f['status'], 'All') ?></select></label>
  <label>Type<select name="type"><?= options(pairs('SELECT id, name FROM leave_types ORDER BY name'), $f['type'], 'All') ?></select></label>
  <label>Employee<select name="emp"><?= options(employee_options(false), $f['emp'], 'All') ?></select></label>
  <label>From<input type="date" name="from" value="<?= h($f['from']) ?>"></label>
  <label>To<input type="date" name="to" value="<?= h($f['to']) ?>"></label>
  <button class="btn btn-primary">Filter</button>
</form>
<div class="card"><div class="table-wrap"><table class="table">
  <thead><tr><th>No.</th><th>Employee</th><th>Type</th><th>Dates</th><th class="num">Days</th><th>Status</th><th>Waiting on</th><th>Filed</th><th class="actions"></th></tr></thead><tbody>
  <?php if (!$list) echo empty_row(9); foreach ($list as $l): ?>
    <tr><td><a href="<?= url('leave_view', ['id' => $l['id']]) ?>"><?= h($l['app_no']) ?></a></td><td><?= h(full_name($l)) ?><br><span class="muted small"><?= h($l['employee_no']) ?></span></td>
      <td><?= h($l['type_name']) ?></td><td class="nowrap"><?= h(fmt_date($l['date_from'], 'M d')) ?> – <?= h(fmt_date($l['date_to'], 'M d, Y')) ?></td><td class="num"><?= h($l['days']) ?></td>
      <td><?= badge($l['status']) ?></td><td class="small"><?= h($l['waiting_on'] ? str_replace(['(dept_head)', '(hr)', '(supervisor)'], ['(Dept. Head)', '(HR)', '(Supervisor)'], $l['waiting_on']) : '') ?></td>
      <td class="small"><?= h(fmt_dt($l['filed_at'], 'M d, h:i A')) ?></td><td class="actions"><a class="btn btn-sm" href="<?= url('leave_view', ['id' => $l['id']]) ?>">Open</a></td></tr>
  <?php endforeach; ?></tbody></table></div></div>

<div class="modal<?= $fileFor ? ' open' : '' ?>" id="fileModal"><div class="modal-box">
  <h2>File leave on behalf of an employee</h2>
  <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="_action" value="file">
    <div class="form-grid">
      <div class="field wide"><label>Employee</label><select name="employee_id" required><?= options(employee_options(), $fileFor, '— select —') ?></select></div>
      <div class="field wide"><label>Leave type</label><select name="leave_type_id" required><?= options(pairs('SELECT id, name FROM leave_types WHERE is_active = 1 ORDER BY is_statutory DESC, name'), null, '— select —') ?></select></div>
      <div class="field"><label>From</label><input type="date" name="date_from" required></div>
      <div class="field"><label>To</label><input type="date" name="date_to" required></div>
      <div class="field wide"><label>Reason</label><textarea name="reason" rows="2"></textarea></div>
      <div class="field wide"><label>Supporting document (medical certificate, etc.)</label><input type="file" name="attachment"></div>
    </div>
    <p class="small muted">The request is routed through the configured approval chain and each approver receives an email with approve / return / disallow links.</p>
    <div class="form-actions"><button class="btn btn-primary">File leave</button><button type="button" class="btn" data-modal-close>Cancel</button></div>
  </form>
</div></div>
<?php page_footer();
