<?php
/**
 * Overtime authorizations. Logged overtime is paid only up to the authorized hours; the excess is flagged.
 */
// company-wide: users who may authorize overtime in the DTR or resolve attendance exceptions; others see their own team
$isHr = can_any(['dtr.approve', 'att_exceptions.approve']);
$team = $isHr ? null : my_team_ids();
if (is_post()) {
    $act = post('_action');
    if ($act === 'create') {
        $emps = array_map('intval', (array)post('employee_ids', []));
        if ($team !== null) {
            $emps = array_values(array_intersect($emps, $team));
        }
        $n = 0;
        foreach ($emps as $e) {
            insert('overtime_requests', ['employee_id' => $e, 'ot_date' => post('ot_date'), 'hours' => (float)post('hours'), 'reason' => post('reason'),
                'status' => 'approved', 'approved_by' => user_id(), 'approved_at' => now()]);
            if (post('ot_date') <= today()) {
                process_dtr($e, post('ot_date'));
            }
            $n++;
        }
        audit('ams', 'ot_preapprove', 'overtime_requests', null, ['employees' => $emps, 'date' => post('ot_date'), 'hours' => post('hours')]);
        flash('success', "$n overtime authorization(s) created.");
    } elseif (in_array($act, ['approve', 'reject'], true)) {
        foreach (array_map('intval', (array)post('ids', [])) as $id) {
            $r = row("SELECT * FROM overtime_requests WHERE id = ? AND status = 'pending'", [$id]);
            if (!$r || ($team !== null && !in_array((int)$r['employee_id'], $team, true))) {
                continue;
            }
            update('overtime_requests', ['status' => $act === 'approve' ? 'approved' : 'rejected', 'approved_by' => user_id(), 'approved_at' => now(), 'remarks' => post('remarks')], 'id = ?', [$id]);
            if ($r['ot_date'] <= today()) {
                process_dtr((int)$r['employee_id'], $r['ot_date']);
            }
            audit('ams', 'ot_' . $act, 'overtime_requests', $id);
        }
        flash('success', 'Overtime request(s) updated; affected DTRs recomputed.');
    }
    redirect('ot_requests');
}
$status = get('status', 'pending');
$where = $status !== 'all' ? 'o.status = ' . db()->quote($status) : '1=1';
if ($team !== null) {
    $where .= ' AND o.employee_id IN (' . ($team ? implode(',', $team) : '0') . ')';
}
$list = rows("SELECT o.*, e.first_name, e.last_name, e.middle_name, e.suffix, e.employee_no, u.username AS approver,
              (SELECT ot_minutes FROM dtr d WHERE d.employee_id = o.employee_id AND d.work_date = o.ot_date) AS logged
              FROM overtime_requests o JOIN employees e ON e.id = o.employee_id LEFT JOIN users u ON u.id = o.approved_by WHERE $where" . ($team === null ? user_scope_sql('e') : '') . " ORDER BY o.ot_date DESC LIMIT 500");
$empOpts = $team === null ? employee_options() : ($team ? employee_options(true, 'id IN (' . implode(',', $team) . ')', [], false) : []);
page_header('Overtime Authorizations', ['subtitle' => 'Pre-approve overtime, or act on employees\' OT requests. Unauthorized overtime is flagged in the DTR and payroll.', 'actions' => '<button class="btn btn-primary" data-modal-open="otModal">+ Authorize overtime</button>']);
?>
<form class="card filters" method="get"><input type="hidden" name="p" value="ot_requests"><label>Status<select name="status" data-autosubmit><?= options(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'], $status) ?></select></label></form>
<form method="post" class="card"><?= csrf_field() ?>
  <div class="card-head"><h2><?= count($list) ?> request(s)</h2><div><input name="remarks" placeholder="Remarks" style="width:200px;display:inline-block"> <button class="btn btn-success" name="_action" value="approve">Approve selected</button> <button class="btn btn-danger-outline" name="_action" value="reject">Reject selected</button></div></div>
  <table class="table compact"><thead><tr><th><input type="checkbox" data-check-all="ids[]"></th><th>Date</th><th>Employee</th><th class="num">Hours requested</th><th class="num">Logged OT</th><th>Reason</th><th>Status</th><th>By</th></tr></thead><tbody>
  <?php if (!$list) echo empty_row(8); foreach ($list as $o): ?>
    <tr><td><?php if ($o['status'] === 'pending'): ?><input type="checkbox" name="ids[]" value="<?= $o['id'] ?>"><?php endif; ?></td><td class="nowrap"><?= h(fmt_date($o['ot_date'], 'D M d, Y')) ?></td><td><?= h(full_name($o)) ?></td>
      <td class="num"><?= h($o['hours']) ?></td><td class="num"><?= $o['logged'] !== null ? hours($o['logged']) : '—' ?></td><td class="small"><?= h($o['reason']) ?></td><td><?= badge($o['status']) ?></td><td class="small"><?= h($o['approver']) ?></td></tr>
  <?php endforeach; ?></tbody></table>
</form>
<div class="modal" id="otModal"><div class="modal-box"><h2>Authorize overtime</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="create">
    <div class="form-grid">
      <div class="field wide"><label>Employees</label><select name="employee_ids[]" multiple size="6" required><?= options($empOpts) ?></select></div>
      <div class="field"><label>Date</label><input type="date" name="ot_date" value="<?= h(today()) ?>" required></div>
      <div class="field"><label>Authorized hours</label><input type="number" step="0.25" name="hours" value="2" required></div>
      <div class="field wide"><label>Reason / work to be done</label><input name="reason" required></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary">Authorize</button><button type="button" class="btn" data-modal-close>Cancel</button></div>
  </form></div></div>
<?php page_footer();
