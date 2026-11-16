<?php
/**
 * v35: My Pass Slips — PASS SLIP FORM (Forgot to Swipe / Log). The employee files the time in / time out he forgot
 * to log; the immediate superior approves it like a leave or an overtime request (Pending Approvals), and the
 * approved times are posted to the daily time record. Every slip can be printed on the customizable form.
 */
$me = my_employee_id();
if (!$me) {
    throw new RuntimeException('Your account is not linked to an employee record.');
}
$layout = pass_slip_layout();
$cols = $layout['columns'];
if (is_post()) {
    $act = post('_action');
    if ($act === 'cancel') {
        $s = row("SELECT * FROM pass_slips WHERE id = ? AND employee_id = ? AND status = 'pending'", [(int)post('id'), $me]);
        if ($s) {
            update('pass_slips', ['status' => 'cancelled', 'remarks' => 'Cancelled by the employee'], 'id = ?', [(int)$s['id']]);
            audit('ams', 'pass_slip_cancel', 'pass_slips', (int)$s['id']);
            flash('success', 'Pass slip ' . $s['slip_no'] . ' cancelled.');
        }
        redirect('my_pass_slips');
    }
    // file
    [$entries, $errors] = pass_slip_entries_from_post($layout, $me);
    $reason = trim((string)post('reason'));
    if ($reason === '') {
        $errors[] = 'State the reason why the time was not logged.';
    }
    // the same date already on a pending / approved slip
    foreach ($entries as $en) {
        $dup = val("SELECT slip_no FROM pass_slips WHERE employee_id = ? AND status IN ('pending','approved') AND entries LIKE ?", [$me, '%"' . $en['d'] . '"%']);
        if ($dup) {
            $errors[] = fmt_date($en['d']) . ' is already on pass slip ' . $dup . '.';
        }
    }
    if ($errors) {
        flash('error', implode(' ', array_unique($errors)));
        $_SESSION['ps_old'] = ['ps_date' => post('ps_date', []), 'ps_time' => post('ps_time', []), 'reason' => $reason];
        redirect('my_pass_slips');
    }
    $no = pass_slip_next_no();
    $id = insert('pass_slips', ['slip_no' => $no, 'employee_id' => $me, 'date_filed' => today(), 'first_date' => $entries[0]['d'],
        'entries' => json_encode($entries), 'reason' => mb_substr($reason, 0, 500), 'status' => 'pending', 'created_by' => user_id(), 'created_at' => now()]);
    audit('ams', 'pass_slip_file', 'pass_slips', $id, ['slip_no' => $no, 'entries' => $entries]);
    pass_slip_notify_filed(pass_slip_row($id));
    flash('success', 'Pass slip ' . $no . ' submitted to your immediate superior for approval.');
    redirect('my_pass_slips');
}
$old = $_SESSION['ps_old'] ?? null;
unset($_SESSION['ps_old']);
$list = rows('SELECT ps.*, u.full_name AS approver_user FROM pass_slips ps LEFT JOIN users u ON u.id = ps.approved_by WHERE ps.employee_id = ? ORDER BY ps.id DESC LIMIT 100', [$me]);
$sup = row('SELECT s.first_name, s.last_name, s.middle_name, s.suffix FROM employees e JOIN employees s ON s.id = e.supervisor_id WHERE e.id = ?', [$me]);
$canPrint = can('my_pass_slips.print');
$rowsOld = $old ? array_keys((array)$old['ps_date']) : [0];

/** One date row of the filing form (index $i; values of a returned form). */
$entryRow = function ($i, ?array $old) use ($cols): string {
    $d = $old['ps_date'][$i] ?? ($i === 0 && !$old ? today() : '');
    $h = '<tr><td class="ps-e-date" data-label="Date"><input type="date" name="ps_date[' . h($i) . ']" value="' . h($d) . '" max="' . h(today()) . '" required></td>';
    foreach ($cols as $c) {
        $h .= '<td data-label="' . h(trim($c['group'] . ' ' . $c['label'])) . '"><input type="time" name="ps_time[' . h($i) . '][' . h($c['key']) . ']" value="' . h($old['ps_time'][$i][$c['key']] ?? '') . '"></td>';
    }
    return $h . '<td><button type="button" class="btn btn-sm btn-danger-outline remove-row" title="Remove this date" aria-label="Remove this date">×</button></td></tr>';
};
page_header('Pass Slip (Forgot to Swipe/Log)', [
    'subtitle' => 'File the time you forgot to log. Your immediate superior approves it' . ($sup ? ' (' . full_name($sup, false) . ')' : '') . ', then the time is posted to your daily time record.',
    'actions' => $canPrint ? '<a class="btn" target="_blank" href="' . h(url('pass_slip_print', ['blank' => 1])) . '">Print blank form</a>' : '',
]);
?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="_action" value="file">
  <div class="card-head"><div><h2>File a pass slip</h2><div class="sub">Enter only the time(s) that were not logged. Up to <?= (int)$layout['max_dates'] ?> date(s) per slip.</div></div></div>
  <div class="table-wrap" style="border:0;box-shadow:none;overflow:visible">
  <table class="ps-entry">
    <thead><tr><th style="min-width:150px">Date</th><?php foreach ($cols as $c): ?><th style="min-width:120px"><?php if ($c['group'] !== ''): ?><small><?= h($c['group']) ?></small><?php endif; ?><?= h($c['label']) ?></th><?php endforeach; ?><th></th></tr></thead>
    <tbody id="psRows" data-max="<?= (int)$layout['max_dates'] ?>"><?php foreach ($rowsOld as $i) echo $entryRow($i, $old); ?></tbody>
  </table>
  </div>
  <template id="psRows-tpl"><?= $entryRow('__i__', null) ?></template>
  <div class="ps-entry-foot"><button type="button" class="btn btn-sm" data-add-row="psRows"<?= count($rowsOld) >= $layout['max_dates'] ? ' disabled' : '' ?>>+ Add another date</button></div>
  <div class="field" style="margin-top:14px"><label for="psReason">Reason</label><textarea id="psReason" name="reason" rows="3" maxlength="500" required placeholder="Why the time was not logged (e.g. the biometric device was offline)"><?= h($old['reason'] ?? '') ?></textarea></div>
  <div class="form-actions"><button class="btn btn-primary">Submit for approval</button></div>
</form>
<div class="card"><div class="card-head"><h2>My pass slips</h2></div>
  <div class="table-wrap"><table class="table compact">
    <thead><tr><th>Slip no.</th><th>Date(s) and time(s)</th><th>Reason</th><th>Filed</th><th>Status</th><th class="actions"></th></tr></thead>
    <tbody>
    <?php if (!$list) echo empty_row(6, 'No pass slip filed yet.'); foreach ($list as $s): ?>
      <tr><td class="mono nowrap"><?= h($s['slip_no']) ?></td><td class="small ps-hist-times"><?= pass_slip_entries_html($s, true) ?></td><td class="small"><?= h($s['reason']) ?></td>
        <td class="nowrap"><?= h(fmt_date($s['date_filed'])) ?></td>
        <td><?= badge($s['status']) ?><?php if ($s['status'] !== 'pending' && $s['approver_user'] && $s['status'] !== 'cancelled'): ?><small class="d-block muted">by <?= h($s['approver_user']) ?></small><?php endif; ?><?php if ($s['remarks'] && $s['status'] !== 'cancelled'): ?><small class="d-block muted"><?= h($s['remarks']) ?></small><?php endif; ?></td>
        <td class="actions nowrap"><?php if ($canPrint): ?><a class="btn btn-sm" target="_blank" href="<?= url('pass_slip_print', ['id' => $s['id']]) ?>">Print</a><?php endif; ?>
          <?php if ($s['status'] === 'pending'): ?><form method="post" class="inline" data-confirm="Cancel pass slip <?= h($s['slip_no']) ?>?"><?= csrf_field() ?><input type="hidden" name="_action" value="cancel"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn btn-sm btn-danger-outline">Cancel</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<?php page_footer();
