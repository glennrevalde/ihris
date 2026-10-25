<?php
/**
 * Attendance exceptions: missing punches, unauthorized OT, unverified web check-ins, plus the tardiness monitor
 * that feeds habitual tardiness into the disciplinary workflow (Code of Conduct penalty matrix).
 */
if (is_post() && post('_action') === 'resolve') {
    $ids = array_map('intval', (array)post('ids', []));
    if ($ids) {
        q('UPDATE dtr SET exception_status = \'resolved\', exception_note = ?, updated_by = ?, updated_at = NOW() WHERE id IN (' . in_list($ids) . ") AND exception_status = 'open'",
            array_merge([post('note') ?: 'Reviewed by HR', user_id()], $ids));
        audit('ams', 'dtr_resolve_exceptions', 'dtr', null, ['ids' => $ids, 'note' => post('note')]);
        flash('success', count($ids) . ' exception(s) resolved.');
    }
    redirect_back('att_exceptions');
}
$cutId = get('cutoff_id', '');
$type = get('type', '');
$where = ["d.exception_status = 'open'"];
$params = [];
if ($cutId !== '') {
    $c = row('SELECT * FROM attendance_cutoffs WHERE id = ?', [$cutId]);
    if ($c) {
        $where[] = 'e.pay_group_id = ? AND d.work_date BETWEEN ? AND ?';
        array_push($params, $c['pay_group_id'], $c['period_start'], $c['period_end']);
    }
}
if ($type !== '') {
    $where[] = 'FIND_IN_SET(?, d.exceptions)';
    $params[] = $type;
}
$list = rows('SELECT d.*, e.first_name, e.last_name, e.middle_name, e.suffix, e.employee_no FROM dtr d JOIN employees e ON e.id = d.employee_id
              WHERE ' . implode(' AND ', $where) . user_scope_sql('e') . ' ORDER BY d.work_date DESC, e.last_name LIMIT 1000', $params);

// tardiness monitor
$month = get('month', date('Y-m'));
$mStart = $month . '-01';
$mEnd = date('Y-m-t', strtotime($mStart));
$grace = (int)setting('grace_minutes', 0);
$thCount = (int)setting('habitual_tardy_count', 5);
$thMin = (int)setting('tardy_cap_minutes', 120);
$tardy = rows("SELECT e.id, e.first_name, e.last_name, e.middle_name, e.suffix, e.employee_no, COUNT(*) AS cnt, SUM(d.late_minutes) AS mins
               FROM dtr d JOIN employees e ON e.id = d.employee_id
               WHERE d.work_date BETWEEN ? AND ? AND d.status = 'present' AND d.day_type = 'ordinary' AND d.late_minutes > ?" . user_scope_sql('e') . "
               GROUP BY e.id, e.first_name, e.last_name, e.middle_name, e.suffix, e.employee_no ORDER BY cnt DESC, mins DESC", [$mStart, $mEnd, $grace]);
page_header('Attendance Exceptions', ['subtitle' => 'Flagged for HR resolution before the cutoff is finalized — never silently paid or silently withheld.']);
?>
<form class="card filters" method="get"><input type="hidden" name="p" value="att_exceptions">
  <label>Cutoff<select name="cutoff_id"><?= options(pairs("SELECT c.id, CONCAT(pg.name, ' ', DATE_FORMAT(c.period_start, '%b %d'), '-', DATE_FORMAT(c.period_end, '%b %d, %Y')) FROM attendance_cutoffs c JOIN pay_groups pg ON pg.id = c.pay_group_id WHERE c.status <> 'locked' ORDER BY c.period_end DESC LIMIT 50"), $cutId, 'All dates') ?></select></label>
  <label>Exception<select name="type"><?= options(array_combine(['missing_in', 'missing_out', 'unauthorized_ot', 'outside_geofence', 'unverified_web', 'no_schedule', 'leave_conflict', 'invalid_punch_order'], array_map('exception_label', ['missing_in', 'missing_out', 'unauthorized_ot', 'outside_geofence', 'unverified_web', 'no_schedule', 'leave_conflict', 'invalid_punch_order'])), $type, 'All') ?></select></label>
  <button class="btn btn-primary">Filter</button>
</form>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="_action" value="resolve">
  <div class="card-head"><h2>Open exceptions (<?= count($list) ?>)</h2>
    <div><input name="note" placeholder="Resolution note for selected" style="width:280px;display:inline-block"> <button class="btn btn-success" data-confirm="Resolve the selected exceptions?">Resolve selected</button></div></div>
  <div class="table-wrap"><table class="table compact"><thead><tr><th><input type="checkbox" data-check-all="ids[]"></th><th>Date</th><th>Employee</th><th>In</th><th>Out</th><th>Exceptions</th><th>OT logged / authorized</th><th class="actions"></th></tr></thead><tbody>
  <?php if (!$list) echo empty_row(8, 'No open exceptions.'); foreach ($list as $d): ?>
    <tr><td><input type="checkbox" name="ids[]" value="<?= $d['id'] ?>"></td><td class="nowrap"><?= h(fmt_date($d['work_date'], 'D M d, Y')) ?></td><td><?= h(full_name($d)) ?></td>
      <td><?= h(fmt_time($d['time_in'])) ?></td><td><?= h(fmt_time($d['time_out'])) ?></td>
      <td><?php foreach (array_filter(explode(',', (string)$d['exceptions'])) as $x): ?><span class="badge badge-amber"><?= h(exception_label($x)) ?></span> <?php endforeach; ?></td>
      <td class="small"><?= $d['ot_minutes'] ? fmt_minutes($d['ot_minutes']) . ' / ' . fmt_minutes($d['approved_ot_minutes']) : '' ?></td>
      <td class="actions"><a class="btn btn-sm" href="<?= url('dtr_edit', ['id' => $d['id']]) ?>">Review</a><?php if (strpos((string)$d['exceptions'], 'web') !== false || strpos((string)$d['exceptions'], 'geofence') !== false): ?><a class="btn btn-sm" href="<?= url('web_verify') ?>">Verify</a><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
</form>
<div class="card">
  <div class="card-head"><h2>Tardiness monitor</h2>
    <form method="get" class="inline"><input type="hidden" name="p" value="att_exceptions"><input type="month" name="month" value="<?= h($month) ?>" data-autosubmit style="width:auto"></form></div>
  <p class="small muted">Habitual tardiness = late at least <?= $thCount ?> time(s) in the month; disciplinary referral cap = <?= $thMin ?> minutes (Settings › Attendance). Grace period <?= $grace ?> min.</p>
  <table class="table compact"><thead><tr><th>Employee</th><th class="num">Times late</th><th class="num">Total minutes</th><th>Flags</th><th>Suggested penalty</th><th class="actions"></th></tr></thead><tbody>
  <?php if (!$tardy) echo empty_row(6, 'No tardiness recorded.'); foreach ($tardy as $t):
      $flag = (int)$t['cnt'] >= $thCount || (int)$t['mins'] >= $thMin;
      $sug = suggest_penalty((int)$t['id'], 1, $mEnd); ?>
    <tr class="<?= $flag ? 'row-bad' : '' ?>"><td><?= h(full_name($t)) ?></td><td class="num"><?= (int)$t['cnt'] ?></td><td class="num"><?= (int)$t['mins'] ?></td>
      <td><?= (int)$t['cnt'] >= $thCount ? '<span class="badge badge-red">Habitual tardiness</span> ' : '' ?><?= (int)$t['mins'] >= $thMin ? '<span class="badge badge-red">Cap exceeded</span>' : '' ?></td>
      <td class="small"><?= $flag ? h(ordinal($sug['offense_no']) . ' offense: ' . $sug['text']) : '' ?></td>
      <td class="actions"><?php if ($flag): ?><a class="btn btn-sm btn-danger-outline" href="<?= url('cases', ['new' => 1, 'employee_id' => $t['id'], 'offense_id' => 1, 'source' => 'attendance', 'date' => min($mEnd, today()), 'details' => sprintf('Late %d time(s) for a total of %d minutes in %s.', $t['cnt'], $t['mins'], date('F Y', strtotime($mStart))), 'provision' => 'Code of Conduct – Tardiness / Undertime']) ?>">Draft NTE</a><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table>
</div>
<?php page_footer();
