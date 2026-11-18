<?php
/**
 * v36: My Workspace > Undertime Application Form. The employee applies for undertime (date, the time in / time out
 * of the day following the logs of his shift: 2, 4 or 6 logs, the total hours and the reason / purpose); the
 * immediate superior approves it in Pending Approvals. The undertime itself is computed by the Daily Time Record as
 * before: an approved form tags it as Approved undertime, without one it stays Unauthorized undertime.
 */
$me = my_employee_id();
if (!$me) {
    throw new RuntimeException('Your account is not linked to an employee record.');
}
$layout = ut_layout();

// the schedule, the logs and the undertime of a day (filled in as the date is typed)
if (get('day')) {
    $d = (string)get('day');
    header('Content-Type: application/json');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !strtotime($d)) {
        echo json_encode(['ok' => false]);
        exit;
    }
    $info = ut_day_info($me, $d);
    $times = array_map(fn($v) => preg_match('/^\d{1,2}:\d{2}$/', (string)$v) ? (string)$v : '', (array)get('t', []));
    $info['minutes'] = $times ? ut_minutes_for($me, $d, array_values($times)) : ($info['ut'] ?? 0);
    $info['total'] = ut_fmt_total((int)$info['minutes'], 'words') ?: 'none';
    $info['ok'] = true;
    echo json_encode($info);
    exit;
}

if (is_post()) {
    if (post('_action') === 'cancel') {
        $a = row("SELECT * FROM undertime_apps WHERE id = ? AND employee_id = ? AND status = 'pending'", [(int)post('id'), $me]);
        if ($a) {
            update('undertime_apps', ['status' => 'cancelled', 'remarks' => 'Cancelled by the employee'], 'id = ?', [(int)$a['id']]);
            audit('ams', 'undertime_cancel', 'undertime_apps', (int)$a['id']);
            flash('success', 'Undertime form ' . $a['ut_no'] . ' cancelled.');
        }
        redirect('my_undertime');
    }
    [$entries, $errors] = ut_entries_from_post($layout, $me);
    foreach ($entries as $en) {
        $dup = val("SELECT ut_no FROM undertime_apps WHERE employee_id = ? AND status IN ('pending','approved') AND entries LIKE ?", [$me, '%"' . $en['d'] . '"%']);
        if ($dup) {
            $errors[] = fmt_date($en['d']) . ' is already on undertime form ' . $dup . '.';
        }
    }
    if ($errors) {
        flash('error', implode(' ', array_unique($errors)));
        $_SESSION['ut_old'] = ['ut_date' => post('ut_date', []), 'ut_time' => post('ut_time', []), 'ut_reason' => post('ut_reason', [])];
        redirect('my_undertime');
    }
    $no = ut_next_no();
    $id = insert('undertime_apps', ['ut_no' => $no, 'employee_id' => $me, 'date_filed' => today(), 'first_date' => $entries[0]['d'], 'last_date' => end($entries)['d'],
        'entries' => json_encode($entries), 'total_minutes' => array_sum(array_column($entries, 'm')), 'reason' => mb_substr(implode('; ', array_unique(array_column($entries, 'r'))), 0, 500),
        'status' => 'pending', 'created_by' => user_id(), 'created_at' => now()]);
    audit('ams', 'undertime_file', 'undertime_apps', $id, ['ut_no' => $no, 'entries' => $entries]);
    $mailNote = ut_notify_filed(ut_row($id));
    flash('success', 'Undertime form ' . $no . ' submitted for approval. ' . $mailNote);
    redirect('my_undertime');
}

$old = $_SESSION['ut_old'] ?? null;
unset($_SESSION['ut_old']);
$list = rows('SELECT ua.*, u.full_name AS approver_user FROM undertime_apps ua LEFT JOIN users u ON u.id = ua.approved_by WHERE ua.employee_id = ? ORDER BY ua.id DESC LIMIT 100', [$me]);
$sup = row('SELECT s.first_name, s.last_name, s.middle_name, s.suffix FROM employees e JOIN employees s ON s.id = e.supervisor_id WHERE e.id = ?', [$me]);
$canPrint = can('my_undertime.print');
$rowsOld = $old ? array_keys((array)$old['ut_date']) : [0];
$pairsToday = ut_pairs_on($me, today());

/** One row of the form: date, IN / OUT of each pair (up to 3; the shift of the date shows 1, 2 or 3), total, reason. */
$entryRow = function ($i, ?array $old) use ($pairsToday): string {
    $d = $old['ut_date'][$i] ?? ($i === 0 && !$old ? today() : '');
    $h = '<tr data-ut-row><td class="ut-e-date" data-label="Date"><input type="date" name="ut_date[' . h($i) . ']" value="' . h($d) . '" required data-ut-date>'
        . '<small class="muted d-block ut-e-shift" data-ut-shift></small></td><td class="ut-e-times" data-label="Time"><div class="ut-pairs">';
    for ($p = 0; $p < 3; $p++) {
        $hide = $p >= $pairsToday;
        $h .= '<div class="ut-pair"' . ($hide ? ' hidden' : '') . ' data-ut-pair="' . $p . '">';
        foreach (['In', 'Out'] as $k => $lbl) {
            $n = $p * 2 + $k;
            $h .= '<label><span>' . $lbl . ($p ? ' ' . ($p + 1) : '') . '</span><input type="time" name="ut_time[' . h($i) . '][' . $n . ']" value="' . h($old['ut_time'][$i][$n] ?? '') . '"' . ($hide ? ' disabled' : '') . ' data-ut-time></label>';
        }
        $h .= '</div>';
    }
    return $h . '</div></td><td class="ut-e-total" data-label="Total hours"><span data-ut-total class="ut-e-sum">—</span></td>'
        . '<td class="ut-e-reason" data-label="Reason / purpose"><input name="ut_reason[' . h($i) . ']" value="' . h($old['ut_reason'][$i] ?? '') . '" maxlength="200" placeholder="Reason / purpose" required></td>'
        . '<td><button type="button" class="btn btn-sm btn-danger-outline remove-row" title="Remove this date" aria-label="Remove this date">×</button></td></tr>';
};
page_header('Undertime Application Form', [
    'subtitle' => 'Apply for the time you leave early. ' . ($sup ? 'Your immediate superior approves it (' . full_name($sup, false) . ').' : 'Your record has no immediate superior, so your department head or HR approves it.')
        . ' Approved undertime is tagged as approved; it is still computed as undertime.',
    'actions' => $canPrint ? '<a class="btn" target="_blank" href="' . h(url('undertime_print', ['blank' => 1])) . '">Print blank form</a>' : '',
]);
?>
<form method="post" class="card" data-ut-form data-day-url="<?= h(url('my_undertime')) ?>"><?= csrf_field() ?><input type="hidden" name="_action" value="file">
  <div class="card-head"><div><h2>Apply for undertime</h2><div class="sub">The time in / time out follow the logs of your shift on the date (2, 4 or 6 logs) and are filled from your logs when the day is already recorded. Up to <?= (int)$layout['max_dates'] ?> date(s) per form.</div></div></div>
  <div class="table-wrap" style="border:0;box-shadow:none;overflow:visible">
  <table class="ut-entry">
    <thead><tr><th style="min-width:150px">Date</th><th>Time</th><th style="min-width:110px">Total hours</th><th style="min-width:200px">Reason / purpose</th><th></th></tr></thead>
    <tbody id="utRows" data-max="<?= (int)$layout['max_dates'] ?>"><?php foreach ($rowsOld as $i) echo $entryRow($i, $old); ?></tbody>
  </table>
  </div>
  <template id="utRows-tpl"><?= $entryRow('__i__', null) ?></template>
  <div class="ps-entry-foot"><button type="button" class="btn btn-sm" data-add-row="utRows"<?= count($rowsOld) >= $layout['max_dates'] ? ' disabled' : '' ?>>+ Add another date</button></div>
  <div class="form-actions"><button class="btn btn-primary">Submit for approval</button></div>
</form>
<div class="card"><div class="card-head"><h2>My undertime forms</h2></div>
  <div class="table-wrap"><table class="table compact">
    <thead><tr><th>Form no.</th><th>Date(s), time(s) and hours</th><th>Reason / purpose</th><th>Filed</th><th>Status</th><th class="actions"></th></tr></thead>
    <tbody>
    <?php if (!$list) echo empty_row(6, 'No undertime form filed yet.'); foreach ($list as $a): ?>
      <tr><td class="mono nowrap"><?= h($a['ut_no']) ?></td><td class="small ps-hist-times"><?= ut_entries_html($a, true) ?></td><td class="small"><?= h($a['reason']) ?></td>
        <td class="nowrap"><?= h(fmt_date($a['date_filed'])) ?></td>
        <td><?= badge($a['status']) ?><?php if (!in_array($a['status'], ['pending', 'cancelled'], true) && $a['approver_user']): ?><small class="d-block muted">by <?= h($a['approver_user']) ?></small><?php endif; ?><?php if ($a['remarks'] && $a['status'] !== 'cancelled'): ?><small class="d-block muted"><?= h($a['remarks']) ?></small><?php endif; ?></td>
        <td class="actions nowrap"><?php if ($canPrint): ?><a class="btn btn-sm" target="_blank" href="<?= url('undertime_print', ['id' => $a['id']]) ?>">Print</a><?php endif; ?>
          <?php if ($a['status'] === 'pending'): ?><form method="post" class="inline" data-confirm="Cancel undertime form <?= h($a['ut_no']) ?>?"><?= csrf_field() ?><input type="hidden" name="_action" value="cancel"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-sm btn-danger-outline">Cancel</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<?php page_footer();
