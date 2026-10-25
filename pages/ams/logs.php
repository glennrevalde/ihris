<?php
/**
 * Raw punch logs from every source; manual punch entry; mapping of unmatched biometric IDs.
 */
if (is_post()) {
    $act = post('_action');
    if ($act === 'manual') {
        $emp = (int)post('employee_id');
        $time = str_replace('T', ' ', (string)post('log_time'));
        if (!$emp || !$time || !post('reason')) {
            flash('error', 'Employee, date-time and reason are required.');
            redirect('att_logs');
        }
        $e = att_employee($emp);
        try {
            insert('attendance_logs', ['employee_id' => $emp, 'bio_id' => $e['biometric_id'], 'log_time' => strlen($time) === 16 ? $time . ':00' : $time,
                'punch_type' => post('punch_type', 'in'), 'source' => 'manual', 'branch_id' => post_null('branch_id') ?? $e['default_branch_id'], 'device_id' => 'HR: ' . post('reason')]);
        } catch (PDOException $ex) {
            flash('error', 'A punch of the same type already exists at that time.');
            redirect('att_logs');
        }
        $d = substr($time, 0, 10);
        process_dtr($emp, $d);
        process_dtr($emp, add_days($d, -1));
        audit('ams', 'manual_punch', 'attendance_logs', null, ['employee_id' => $emp, 'time' => $time, 'type' => post('punch_type'), 'reason' => post('reason')]);
        flash('success', 'Manual punch recorded and the DTR recomputed.');
        redirect('att_logs', array_merge(['employee_id' => $emp], back_params()));
    }
    if ($act === 'retype') {
        // correct the classification of one log (In / Break out / Break in / Out) and rebuild the day
        try {
            $l = punch_retype((int)post('log_id'), (string)post('punch_type'), (string)post('reason', ''));
            flash('success', 'Log type changed to ' . label($l['punch_type']) . '; the daily record was recomputed.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect('att_logs', array_merge(array_filter(['employee_id' => get('employee_id'), 'from' => get('from'), 'to' => get('to'), 'source' => get('source')], fn($v) => $v !== null && $v !== ''), back_params()));
    }
    if ($act === 'map_bio') {
        $bio = (string)post('bio_id');
        $emp = (int)post('employee_id');
        if ($bio !== '' && $emp) {
            if (val('SELECT COUNT(*) FROM employees WHERE biometric_id = ? AND id <> ?', [$bio, $emp])) {
                flash('error', 'That biometric ID is already assigned to another employee.');
            } else {
                update('employees', ['biometric_id' => $bio], 'id = ?', [$emp]);
                $dates = q('SELECT DISTINCT DATE(log_time) FROM attendance_logs WHERE bio_id = ? AND employee_id IS NULL', [$bio])->fetchAll(PDO::FETCH_COLUMN);
                q('UPDATE IGNORE attendance_logs SET employee_id = ? WHERE bio_id = ? AND employee_id IS NULL', [$emp, $bio]);
                foreach ($dates as $d) {
                    process_dtr($emp, $d);
                    process_dtr($emp, add_days($d, -1));
                }
                audit('ams', 'map_biometric_id', 'employees', $emp, ['bio_id' => $bio]);
                flash('success', 'Biometric ID mapped; ' . count($dates) . ' day(s) of punches linked and processed.');
            }
        }
        redirect('att_logs');
    }
}
$emp = get('employee_id', '');
$from = get('from', add_days(today(), -7));
$to = get('to', today());
$src = get('source', '');
$where = ['l.log_time BETWEEN ? AND ?'];
$params = [$from . ' 00:00:00', $to . ' 23:59:59'];
if ($emp !== '') {
    $where[] = 'l.employee_id = ?';
    $params[] = $emp;
}
if ($src !== '') {
    $where[] = 'l.source = ?';
    $params[] = $src;
}
$logs = rows('SELECT l.*, e.first_name, e.last_name, e.middle_name, e.suffix, b.code AS branch_code FROM attendance_logs l LEFT JOIN employees e ON e.id = l.employee_id
              LEFT JOIN branches b ON b.id = l.branch_id WHERE ' . implode(' AND ', $where) . scope_sql_or_null('e', 'l.employee_id') . ' ORDER BY l.log_time DESC LIMIT 2000', $params);
$invalid = punch_invalid_ids($logs);
$unmatched = rows('SELECT bio_id, COUNT(*) AS n, MIN(log_time) AS first_seen, MAX(log_time) AS last_seen FROM attendance_logs WHERE employee_id IS NULL GROUP BY bio_id ORDER BY n DESC');
$canEdit = can('att_logs.edit');
$typeOpts = ['in' => 'In', 'break_out' => 'Break out', 'break_in' => 'Break in', 'out' => 'Out'];
$actions = '<button class="btn btn-primary" data-modal-open="manModal">+ Manual punch</button>';
if ($emp !== '' && !back_url()) {
    // opened for one employee: offer the way back to that employee's Attendance Entry
    $actions = '<a class="btn" href="' . h(url('att_entry', ['emp' => (int)$emp, 'from' => $from, 'to' => $to])) . '">&larr; Attendance Entry of this employee</a>' . $actions;
}
page_header('Raw Punch Logs', ['subtitle' => 'Every punch from biometric devices, imported files, web/GPS check-ins and HR manual entries. The type of a wrongly classified log can be corrected on the row; the day is recomputed at once.', 'actions' => $actions]);
if ($unmatched): ?>
<div class="card"><h2>Unmatched biometric IDs</h2><p class="small muted">Punches whose device user ID is not assigned to any employee. Map them to link and process the attendance.</p>
  <table class="table compact"><thead><tr><th>Biometric ID</th><th class="num">Punches</th><th>First / last seen</th><th>Map to employee</th></tr></thead><tbody>
  <?php foreach ($unmatched as $u): ?><tr><td class="mono"><?= h($u['bio_id']) ?></td><td class="num"><?= (int)$u['n'] ?></td><td class="small"><?= h(fmt_dt($u['first_seen'])) ?> – <?= h(fmt_dt($u['last_seen'])) ?></td>
    <td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="_action" value="map_bio"><input type="hidden" name="bio_id" value="<?= h($u['bio_id']) ?>"><select name="employee_id" style="width:auto"><?= options(employee_options(true, 'biometric_id IS NULL'), null, '— select —') ?></select> <button class="btn btn-sm">Map</button></form></td></tr><?php endforeach; ?>
  </tbody></table></div>
<?php endif; ?>
<form class="card filters" method="get"><input type="hidden" name="p" value="att_logs"><?php if (back_url()): ?><input type="hidden" name="back" value="<?= h(back_url()) ?>"><?php endif; ?>
  <label>Employee<select name="employee_id"><?= options(employee_options(false), $emp, 'All') ?></select></label>
  <label>Source<select name="source"><?= options(['biometric' => 'Biometric device', 'import_txt' => 'Imported log file', 'import_csv' => 'Excel/CSV import', 'web' => 'Web / GPS', 'manual' => 'Manual (HR)'], $src, 'All') ?></select></label>
  <label>From<input type="date" name="from" value="<?= h($from) ?>"></label><label>To<input type="date" name="to" value="<?= h($to) ?>"></label>
  <button class="btn btn-primary">Filter</button></form>
<div class="card"><div class="table-wrap"><table class="table compact"><thead><tr><th>Time</th><th>Employee</th><th>Bio ID</th><th>Type</th><th>Source</th><th>Branch</th><th>Device / note</th><th>GPS</th><th>Selfie</th><th>Verification</th></tr></thead><tbody>
<?php if (!$logs) echo empty_row(10); foreach ($logs as $l): ?>
  <tr class="<?= isset($invalid[(int)$l['id']]) ? 'row-muted' : '' ?>"><td class="nowrap"><?= h(fmt_dt($l['log_time'], 'M d, Y h:i:s A')) ?><?= isset($invalid[(int)$l['id']]) ? '<small class="d-block text-warn">' . h($invalid[(int)$l['id']]) . '</small>' : '' ?></td><td><?= $l['employee_id'] ? h(full_name($l)) : '<span class="badge badge-red">Unmatched</span>' ?></td><td class="mono"><?= h($l['bio_id']) ?></td>
    <td class="nowrap"><?php if ($canEdit && $l['employee_id']): ?>
      <form method="post" class="retype" title="Change the log type"><?= csrf_field() ?><input type="hidden" name="_action" value="retype"><input type="hidden" name="log_id" value="<?= (int)$l['id'] ?>">
        <select name="punch_type" class="input-sm" data-autosubmit><?= options($typeOpts, $l['punch_type'], $l['punch_type'] === 'unknown' ? 'Unknown' : null) ?></select>
      </form><?php if (!empty($l['orig_punch_type']) && $l['orig_punch_type'] !== $l['punch_type']): ?><small class="muted d-block">corrected, read as <?= h(label($l['orig_punch_type'])) ?></small><?php endif; ?>
    <?php else: ?><?= h(label($l['punch_type'])) ?><?php endif; ?></td><td><?= h(label($l['source'])) ?></td><td><?= h($l['branch_code']) ?></td><td class="small"><?= h($l['device_id']) ?></td>
    <td class="small"><?php if ($l['latitude']): ?><a target="_blank" rel="noopener" href="https://www.google.com/maps?q=<?= h($l['latitude'] . ',' . $l['longitude']) ?>"><?= number_format((float)$l['distance_m']) ?> m <?= $l['within_geofence'] ? '✓' : '✗' ?></a><?php endif; ?></td>
    <td><?php if ($l['photo_path']): ?><a href="<?= h(upload_url($l['photo_path'])) ?>" target="_blank"><img class="selfie-thumb" src="<?= h(upload_url($l['photo_path'])) ?>" alt=""></a><?php endif; ?></td>
    <td><?= $l['source'] === 'web' ? badge($l['verify_status']) : '' ?></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<div class="modal" id="manModal"><div class="modal-box"><h2>Manual punch entry</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="manual">
    <div class="form-grid">
      <div class="field wide"><label>Employee</label><select name="employee_id" required><?= options(employee_options(), $emp, '— select —') ?></select></div>
      <div class="field"><label>Date &amp; time</label><input type="datetime-local" name="log_time" required></div>
      <div class="field"><label>Punch type</label><select name="punch_type"><?= options(['in' => 'Time in', 'break_out' => 'Break out', 'break_in' => 'Break in', 'out' => 'Time out']) ?></select></div>
      <div class="field"><label>Branch</label><select name="branch_id"><?= options(branch_options(), null, '(default branch)') ?></select></div>
      <div class="field wide"><label>Reason (e.g. device offline, approved by supervisor)</label><input name="reason" required></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary">Record punch</button><button type="button" class="btn" data-modal-close>Cancel</button></div>
  </form></div></div>
<?php page_footer();
