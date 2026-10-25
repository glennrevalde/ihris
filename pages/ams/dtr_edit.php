<?php
$id = (int)get('id', 0);
$d = row('SELECT * FROM dtr WHERE id = ?', [$id]);
if (!$d) {
    throw new RuntimeException('DTR record not found.');
}
$emp = att_employee((int)$d['employee_id']);
$locked = dtr_locked($emp, $d['work_date']);
$dt = fn($v) => $v ? str_replace('T', ' ', $v) . (strlen($v) === 16 ? ':00' : '') : null;

if (is_post() && !$locked) {
    $act = post('_action');
    if ($act === 'save') {
        $new = ['time_in' => $dt(post('time_in')), 'break_out' => $dt(post('break_out')), 'break_in' => $dt(post('break_in')), 'time_out' => $dt(post('time_out')),
            'branch_id' => post_null('branch_id'), 'remarks' => post('remarks'), 'manual_override' => 1, 'updated_by' => user_id()];
        if (array_key_exists('ot_in', $_POST)) {   // v16: pre-arranged overtime logs of the shift
            $new['ot_in'] = $dt(post('ot_in'));
            $new['ot_out'] = $dt(post('ot_out'));
        }
        if (!post('remarks')) {
            flash('error', 'Please state the reason for the manual correction (it is kept in the audit trail).');
            redirect('dtr_edit', array_merge(['id' => $id], back_params()));
        }
        update('dtr', $new, 'id = ?', [$id]);
        process_dtr((int)$d['employee_id'], $d['work_date']);
        audit('ams', 'dtr_manual_edit', 'dtr', $id, ['employee_id' => $d['employee_id'], 'date' => $d['work_date'], 'changes' => diff_fields($d, $new)]);
        flash('success', 'DTR corrected and recomputed.');
    } elseif ($act === 'revert') {
        update('dtr', ['manual_override' => 0, 'updated_by' => user_id()], 'id = ?', [$id]);
        process_dtr((int)$d['employee_id'], $d['work_date']);
        audit('ams', 'dtr_revert', 'dtr', $id);
        flash('success', 'Manual correction removed; the record was rebuilt from the punch logs.');
    } elseif ($act === 'retype') {
        try {
            $l = punch_retype((int)post('log_id'), (string)post('punch_type'), (string)post('reason', 'Corrected on the DTR screen'));
            flash('success', 'Log type changed to ' . label($l['punch_type']) . ' and the record recomputed.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
    } elseif ($act === 'resolve') {
        update('dtr', ['exception_status' => 'resolved', 'exception_note' => post('note') ?: 'Reviewed', 'updated_by' => user_id(), 'updated_at' => now()], 'id = ?', [$id]);
        audit('ams', 'dtr_resolve_exception', 'dtr', $id, ['exceptions' => $d['exceptions'], 'note' => post('note')]);
        flash('success', 'Exception marked as resolved.');
    } elseif ($act === 'authorize_ot') {
        $hrs = round((float)post('hours'), 2);
        if ($hrs > 0) {
            insert('overtime_requests', ['employee_id' => $d['employee_id'], 'ot_date' => $d['work_date'], 'hours' => $hrs, 'reason' => post('reason') ?: 'Authorized upon DTR review',
                'status' => 'approved', 'approved_by' => user_id(), 'approved_at' => now()]);
            process_dtr((int)$d['employee_id'], $d['work_date']);
            audit('ams', 'ot_authorize', 'dtr', $id, ['hours' => $hrs]);
            flash('success', "Overtime of $hrs hour(s) authorized; the DTR was recomputed.");
        }
    }
    redirect('dtr_edit', array_merge(['id' => $id], back_params()));
}
dtr_refresh_stale((int)$d['employee_id'], $d['work_date'], $d['work_date']);
$d = row('SELECT * FROM dtr WHERE id = ?', [$id]);
$sched = employee_schedule($emp, $d['work_date']);
$ws = $sched['sched_in'] ? date('Y-m-d H:i:s', strtotime($sched['sched_in']) - 4 * 3600) : $d['work_date'] . ' 00:00:00';
$we = $sched['sched_out'] ? date('Y-m-d H:i:s', strtotime($sched['sched_out']) + 8 * 3600) : add_days($d['work_date'], 1) . ' 04:00:00';
$logs = rows('SELECT l.*, b.code AS branch_code FROM attendance_logs l LEFT JOIN branches b ON b.id = l.branch_id WHERE l.employee_id = ? AND l.log_time BETWEEN ? AND ? ORDER BY l.log_time', [$d['employee_id'], $ws, $we]);
$ots = rows('SELECT * FROM overtime_requests WHERE employee_id = ? AND ot_date = ?', [$d['employee_id'], $d['work_date']]);
$invalid = punch_invalid_ids($logs);
$local = fn($v) => $v ? date('Y-m-d\TH:i', strtotime($v)) : '';
page_header('DTR — ' . full_name($emp, false) . ' — ' . fmt_date($d['work_date'], 'D, M d, Y'), ['back' => back_url() ?: url('dtr', ['employee_id' => $d['employee_id'], 'from' => date('Y-m-01', strtotime($d['work_date'])), 'to' => date('Y-m-t', strtotime($d['work_date']))]),
    'back_label' => back_url() ? 'Back to Daily Time Records' : 'Back']);
if ($locked): ?><div class="alert alert-warning">This date belongs to an approved (locked) attendance cutoff and can no longer be edited.</div><?php endif; ?>
<div class="grid grid-2">
  <div class="card"><h3>Computed record</h3>
    <dl class="kv">
      <dt>Schedule</dt><dd><?= $sched['shift'] ? h($sched['shift']['name']) . ' — ' . h(fmt_time($d['sched_in'])) . ' to ' . h(fmt_time($d['sched_out'])) : 'No schedule' ?><?= $sched['is_rest'] ? ' (rest day)' : '' ?></dd>
      <dt>Day type</dt><dd><?= h(label($d['day_type'])) ?></dd>
      <dt>Status</dt><dd><?= badge($d['status']) ?><?= $d['manual_override'] ? ' <span class="badge badge-purple">Manually corrected</span>' : '' ?></dd>
      <dt>Worked / regular</dt><dd><?= fmt_minutes($d['worked_minutes']) ?> / <?= fmt_minutes($d['regular_minutes']) ?> (break <?= (int)$d['break_minutes'] ?>m)</dd>
      <dt>Late / undertime</dt><dd><?= (int)$d['late_minutes'] ?> min / <?= (int)$d['undertime_minutes'] ?> min</dd>
      <dt>Overtime</dt><dd><?= fmt_minutes($d['ot_minutes']) ?> logged, <?= fmt_minutes($d['approved_ot_minutes']) ?> authorized</dd>
      <dt>Night differential</dt><dd><?= fmt_minutes($d['nd_minutes']) ?> regular, <?= fmt_minutes($d['nd_ot_minutes']) ?> OT</dd>
      <dt>Exceptions</dt><dd><?php foreach (array_filter(explode(',', (string)$d['exceptions'])) as $x): ?><span class="badge badge-amber"><?= h(exception_label($x)) ?></span> <?php endforeach; ?> <?= $d['exceptions'] ? badge($d['exception_status']) : 'None' ?><?= $d['exception_note'] ? '<br><span class="small">' . h($d['exception_note']) . '</span>' : '' ?></dd>
      <?php if ($d['remarks']): ?><dt>Remarks</dt><dd><?= h($d['remarks']) ?></dd><?php endif; ?>
    </dl>
    <?php if (!$locked && $d['exception_status'] === 'open'): ?>
      <form method="post" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="_action" value="resolve">
        <div class="field"><label>Resolve exception — note</label><input name="note" placeholder="e.g. OT not authorized — not paid; forgot to log out, confirmed by supervisor"></div>
        <div class="form-actions"><button class="btn btn-success">Mark resolved</button></div></form>
    <?php endif; ?>
    <?php if (!$locked && $d['ot_minutes'] > $d['approved_ot_minutes']): ?>
      <form method="post" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="_action" value="authorize_ot">
        <div class="form-grid"><div class="field"><label>Authorize OT (hours)</label><input type="number" step="0.25" name="hours" value="<?= round(($d['ot_minutes'] - $d['approved_ot_minutes']) / 60, 2) ?>"></div>
          <div class="field"><label>Reason</label><input name="reason"></div></div>
        <div class="form-actions"><button class="btn">Authorize overtime</button></div></form>
    <?php endif; ?>
  </div>
  <div class="card"><h3>Raw punches</h3><p class="help" style="margin:-8px 0 10px">Wrong classification (an evening log marked In, a morning log marked Out)? Change the type on the row and the record is rebuilt.</p>
    <table class="table compact"><thead><tr><th>Time</th><th>Type</th><th>Source</th><th>Branch</th><th>GPS / selfie</th></tr></thead><tbody>
    <?php if (!$logs) echo empty_row(5, 'No punches.'); foreach ($logs as $l): ?>
      <tr class="<?= $l['verify_status'] === 'rejected' || isset($invalid[(int)$l['id']]) ? 'row-muted' : '' ?>"><td class="nowrap"><?= h(fmt_dt($l['log_time'], 'M d h:i:s A')) ?><?= isset($invalid[(int)$l['id']]) ? '<small class="d-block text-warn">' . h($invalid[(int)$l['id']]) . '</small>' : '' ?></td>
        <td class="nowrap"><?php if (!$locked): ?><form method="post" class="retype" title="Change the log type"><?= csrf_field() ?><input type="hidden" name="_action" value="retype"><input type="hidden" name="log_id" value="<?= (int)$l['id'] ?>">
          <select name="punch_type" class="input-sm" data-autosubmit><?= options(['in' => 'In', 'break_out' => 'Break out', 'break_in' => 'Break in', 'out' => 'Out'], $l['punch_type'], $l['punch_type'] === 'unknown' ? 'Unknown' : null) ?></select></form>
          <?php else: ?><?= h(label($l['punch_type'])) ?><?php endif; ?></td><td><?= h(label($l['source'])) ?></td><td><?= h($l['branch_code']) ?></td>
        <td class="small"><?php if ($l['latitude']): ?><a target="_blank" rel="noopener" href="https://www.google.com/maps?q=<?= h($l['latitude'] . ',' . $l['longitude']) ?>"><?= $l['within_geofence'] ? 'inside' : 'outside' ?> (<?= h(number_format((float)$l['distance_m'])) ?> m)</a><?php endif; ?>
          <?php if ($l['photo_path']): ?> <a href="<?= h(upload_url($l['photo_path'])) ?>" target="_blank">selfie</a><?php endif; ?> <?= $l['source'] === 'web' ? badge($l['verify_status']) : '' ?></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php if ($ots): ?><h3 style="margin-top:12px">OT authorizations</h3><ul><?php foreach ($ots as $o): ?><li><?= h($o['hours']) ?> hr(s) — <?= badge($o['status']) ?> <?= h($o['reason']) ?></li><?php endforeach; ?></ul><?php endif; ?>
  </div>
</div>
<?php if (!$locked): ?>
<div class="card"><h3>Manual correction</h3>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="save">
    <div class="form-grid">
      <div class="field"><label>Time in</label><input type="datetime-local" name="time_in" value="<?= h($local($d['time_in'])) ?>"></div>
      <div class="field"><label>Break out</label><input type="datetime-local" name="break_out" value="<?= h($local($d['break_out'])) ?>"></div>
      <div class="field"><label>Break in</label><input type="datetime-local" name="break_in" value="<?= h($local($d['break_in'])) ?>"></div>
      <div class="field"><label>Time out</label><input type="datetime-local" name="time_out" value="<?= h($local($d['time_out'])) ?>"></div>
      <?php $dShift = shift_row($d['shift_id'] ? (int)$d['shift_id'] : null);
      if (($dShift && !empty($dShift['ot_in'])) || !empty($d['ot_in']) || !empty($d['ot_out'])): ?>
      <div class="field"><label>Overtime In</label><input type="datetime-local" name="ot_in" value="<?= h($local($d['ot_in'] ?? null)) ?>"><small class="help">Pre-arranged overtime of the shift<?= $dShift && !empty($dShift['ot_in']) ? ' (' . h(substr($dShift['ot_in'], 0, 5) . ' to ' . substr((string)$dShift['ot_out'], 0, 5)) . ')' : '' ?></small></div>
      <div class="field"><label>Overtime Out</label><input type="datetime-local" name="ot_out" value="<?= h($local($d['ot_out'] ?? null)) ?>"><small class="help">Credited <?= h(number_format((int)($d['pre_ot_minutes'] ?? 0) / 60, 2)) ?> hrs, approved automatically</small></div>
      <?php endif; ?>
      <div class="field"><label>Branch worked</label><select name="branch_id"><?= options(branch_options(), $d['branch_id']) ?></select></div>
      <div class="field wide"><label>Reason for correction (required)</label><input name="remarks" value="<?= h($d['remarks']) ?>" required></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary">Save correction</button></div>
  </form>
  <?php if ($d['manual_override']): ?><form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="_action" value="revert"><button class="btn btn-sm">Discard correction and rebuild from punches</button></form><?php endif; ?>
</div>
<?php endif;
page_footer();
