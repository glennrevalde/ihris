<?php
/**
 * HR review queue for imported attendance rows that were unmatched or conflicting.
 */
if (is_post()) {
    $act = post('_action');
    $ids = array_map('intval', (array)post('ids', []));
    $n = 0;
    foreach ($ids as $id) {
        $x = row("SELECT * FROM import_exceptions WHERE id = ? AND status = 'open'", [$id]);
        if (!$x) {
            continue;
        }
        if ($act === 'post') {
            $empId = (int)($x['employee_id'] ?: post('map_' . $id));
            if (!$empId || !$x['work_date'] || (!$x['time_in'] && !$x['time_out'])) {
                continue;
            }
            $emp = att_employee($empId);
            if (dtr_locked($emp, $x['work_date'])) {
                continue;
            }
            post_import_punches($empId, $x['work_date'], $x['time_in'], $x['time_out'], null, null, $x['branch_id'] ? (int)$x['branch_id'] : null, (int)$x['batch_id'], $x['remarks']);
            update('import_exceptions', ['status' => 'posted', 'employee_id' => $empId, 'resolved_by' => user_id(), 'resolved_at' => now()], 'id = ?', [$id]);
            $n++;
        } elseif ($act === 'reject') {
            update('import_exceptions', ['status' => 'rejected', 'resolved_by' => user_id(), 'resolved_at' => now()], 'id = ?', [$id]);
            $n++;
        }
    }
    audit('ams', 'import_review_' . $act, 'import_exceptions', null, ['ids' => $ids, 'processed' => $n]);
    flash('success', "$n row(s) " . ($act === 'post' ? 'posted to attendance after review.' : 'rejected.'));
    redirect('import_review', array_filter(['batch_id' => get('batch_id')]));
}
$batch = get('batch_id', '');
$list = rows("SELECT x.*, b.file_name, e.first_name, e.last_name, e.middle_name, e.suffix FROM import_exceptions x JOIN import_batches b ON b.id = x.batch_id LEFT JOIN employees e ON e.id = x.employee_id
              WHERE x.status = 'open'" . ($batch !== '' ? ' AND x.batch_id = ' . (int)$batch : '') . scope_sql_or_null('e', 'x.employee_id') . ' ORDER BY x.batch_id DESC, x.row_no');
$empOpts = employee_options();
page_header('Import Review Queue', ['subtitle' => 'Imported attendance rows flagged during validation. Post them after verification, map them to an employee, or reject them.', 'back' => url('import_csv')]);
?>
<form method="post" class="card"><?= csrf_field() ?>
  <div class="card-head"><h2><?= count($list) ?> row(s) awaiting review</h2>
    <div><button class="btn btn-success" name="_action" value="post" data-confirm="Post the selected rows to attendance?">Post selected</button> <button class="btn btn-danger-outline" name="_action" value="reject" data-confirm="Reject the selected rows?">Reject selected</button></div></div>
  <div class="table-wrap"><table class="table compact"><thead><tr><th><input type="checkbox" data-check-all="ids[]"></th><th>File / row</th><th>Employee</th><th>Date</th><th>In</th><th>Out</th><th>Remarks</th><th>Issue</th></tr></thead><tbody>
  <?php if (!$list) echo empty_row(8, 'Nothing to review.'); foreach ($list as $x): ?>
    <tr><td><input type="checkbox" name="ids[]" value="<?= $x['id'] ?>"></td><td class="small"><?= h($x['file_name']) ?> #<?= (int)$x['row_no'] ?></td>
      <td><?php if ($x['employee_id']): ?><?= h(full_name($x)) ?><?php else: ?><span class="muted small">"<?= h($x['employee_no']) ?>"</span><br><select name="map_<?= $x['id'] ?>" style="width:auto"><?= options($empOpts, null, 'Map to…') ?></select><?php endif; ?></td>
      <td class="nowrap"><?= h(fmt_date($x['work_date'])) ?></td><td><?= h(fmt_time($x['time_in'])) ?></td><td><?= h(fmt_time($x['time_out'])) ?></td><td class="small"><?= h($x['remarks']) ?></td>
      <td class="small"><span class="badge badge-amber">flag</span> <?= h($x['issue']) ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
</form>
<?php page_footer();
