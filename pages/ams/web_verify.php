<?php
/**
 * Verification of web / mobile check-ins: live selfie compared with the 201 photo, GPS position vs. the branch geofence.
 */
if (is_post()) {
    $ids = array_map('intval', (array)post('ids', []));
    $status = post('_action') === 'verify' ? 'verified' : 'rejected';
    foreach ($ids as $id) {
        $l = row("SELECT * FROM attendance_logs WHERE id = ? AND source = 'web'", [$id]);
        if (!$l) {
            continue;
        }
        update('attendance_logs', ['verify_status' => $status, 'verified_by' => user_id()], 'id = ?', [$id]);
        $d = substr($l['log_time'], 0, 10);
        process_dtr((int)$l['employee_id'], $d);
        process_dtr((int)$l['employee_id'], add_days($d, -1));
    }
    audit('ams', 'web_checkin_' . $status, 'attendance_logs', null, ['ids' => $ids, 'reason' => post('reason')]);
    flash('success', count($ids) . ' check-in(s) ' . $status . '. Affected DTRs were recomputed' . ($status === 'rejected' ? ' (rejected punches are ignored).' : '.'));
    redirect('web_verify', ['status' => get('status', 'pending')]);
}
$status = get('status', 'pending');
$list = rows("SELECT l.*, e.first_name, e.last_name, e.middle_name, e.suffix, e.employee_no, e.photo_path AS emp_photo, b.name AS branch_name, b.geofence_radius_m
              FROM attendance_logs l JOIN employees e ON e.id = l.employee_id LEFT JOIN branches b ON b.id = l.branch_id
              WHERE l.source = 'web'" . ($status !== 'all' ? ' AND l.verify_status = ' . db()->quote($status) : '') . user_scope_sql('e') . ' ORDER BY l.log_time DESC LIMIT 300');
page_header('Web Check-in Verification', ['subtitle' => 'Web/mobile time capture with GPS geofencing and live facial capture. Check-ins outside the authorized radius, or without a detected face, wait here for HR verification.']);
?>
<form class="card filters" method="get"><input type="hidden" name="p" value="web_verify">
  <label>Show<select name="status" data-autosubmit><?= options(['pending' => 'Pending verification', 'auto' => 'Auto-accepted (inside geofence)', 'verified' => 'Verified by HR', 'rejected' => 'Rejected', 'all' => 'All'], $status) ?></select></label></form>
<form method="post" class="card"><?= csrf_field() ?>
  <div class="card-head"><h2><?= count($list) ?> check-in(s)</h2>
    <div><input name="reason" placeholder="Note / reason" style="width:220px;display:inline-block"> <button class="btn btn-success" name="_action" value="verify">Verify selected</button> <button class="btn btn-danger-outline" name="_action" value="reject" data-confirm="Reject the selected check-ins? Rejected punches are excluded from the DTR.">Reject selected</button></div></div>
  <div class="table-wrap"><table class="table"><thead><tr><th><input type="checkbox" data-check-all="ids[]"></th><th>Employee</th><th>Time</th><th>Type</th><th>Selfie</th><th>201 photo</th><th>Location</th><th>Face</th><th>Status</th></tr></thead><tbody>
  <?php if (!$list) echo empty_row(9, 'Nothing here.'); foreach ($list as $l): ?>
    <tr class="<?= $l['within_geofence'] === 0 || $l['within_geofence'] === '0' ? 'row-warn' : '' ?>"><td><input type="checkbox" name="ids[]" value="<?= $l['id'] ?>"></td>
      <td><strong><?= h(full_name($l)) ?></strong><br><span class="muted small"><?= h($l['employee_no']) ?></span></td>
      <td class="nowrap"><?= h(fmt_dt($l['log_time'], 'M d, Y h:i A')) ?></td><td><?= h(label($l['punch_type'])) ?></td>
      <td><?php if ($l['photo_path']): ?><a href="<?= h(upload_url($l['photo_path'])) ?>" target="_blank"><img class="selfie-lg" style="max-width:120px" src="<?= h(upload_url($l['photo_path'])) ?>" alt="selfie"></a><?php else: ?><span class="muted small">none</span><?php endif; ?></td>
      <td><?php if ($l['emp_photo']): ?><img class="selfie-lg" style="max-width:90px" src="<?= h(upload_url($l['emp_photo'])) ?>" alt="201"><?php else: ?><span class="muted small">no 201 photo</span><?php endif; ?></td>
      <td class="small"><?php if ($l['latitude']): ?><a target="_blank" rel="noopener" href="https://www.google.com/maps?q=<?= h($l['latitude'] . ',' . $l['longitude']) ?>"><?= h(number_format((float)$l['latitude'], 5) . ', ' . number_format((float)$l['longitude'], 5)) ?></a><br>
        <?= $l['within_geofence'] ? '<span class="badge badge-green">Inside</span>' : '<span class="badge badge-red">Outside</span>' ?> <?= h(number_format((float)$l['distance_m'])) ?> m from <?= h($l['branch_name']) ?> (±<?= h(number_format((float)$l['accuracy_m'])) ?> m)<?php endif; ?></td>
      <td><?= $l['face_detected'] === null ? '<span class="muted small">n/a</span>' : ($l['face_detected'] ? '<span class="badge badge-green">Detected</span>' : '<span class="badge badge-red">Not detected</span>') ?></td>
      <td><?= badge($l['verify_status']) ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
</form>
<?php page_footer();
