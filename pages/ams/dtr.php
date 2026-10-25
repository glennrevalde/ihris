<?php
/**
 * Daily Time Records: the unified DTR per employee per day consolidated from every source
 * (biometric device, imported files, web / GPS / selfie check-ins).
 */
$emp = get('employee_id', '');
$from = get('from', date('Y-m-01'));
$to = get('to', min(date('Y-m-t'), today()));
$ou = get('ou', '');
$status = get('status', '');
if (is_post() && post('_action') === 'reprocess') {
    $ids = post('employee_id') ? [(int)post('employee_id')] : null;
    $n = process_dtr_range($ids, post('from'), post('to'));
    audit('ams', 'dtr_reprocess', 'dtr', null, ['from' => post('from'), 'to' => post('to'), 'employee' => post('employee_id')]);
    flash('success', "$n daily record(s) re-processed from the raw punches (approved cutoffs are locked and were skipped).");
    redirect('dtr', array_merge(['employee_id' => post('employee_id'), 'from' => post('from'), 'to' => post('to')], back_params()));
}
$where = ['d.work_date BETWEEN ? AND ?'];
$params = [$from, $to];
if ($emp !== '') {
    $where[] = 'd.employee_id = ?';
    $params[] = $emp;
    dtr_refresh_stale((int)$emp, $from, $to);   // records computed by an older engine are rebuilt before they are shown
}
if ($ou !== '') {
    $ids = org_descendant_ids((int)$ou);
    $where[] = 'e.org_unit_id IN (' . in_list($ids) . ')';
    $params = array_merge($params, $ids);
}
if ($status === 'exceptions') {
    $where[] = "d.exception_status = 'open'";
} elseif ($status !== '') {
    $where[] = 'd.status = ?';
    $params[] = $status;
}
$list = rows('SELECT d.*, e.first_name, e.last_name, e.middle_name, e.suffix, e.employee_no, b.code AS branch_code, s.code AS shift_code
              FROM dtr d JOIN employees e ON e.id = d.employee_id LEFT JOIN branches b ON b.id = d.branch_id LEFT JOIN shifts s ON s.id = d.shift_id
              WHERE ' . implode(' AND ', $where) . user_scope_sql('e') . ' ORDER BY ' . ($emp !== '' ? 'd.work_date' : 'd.work_date DESC, e.last_name') . ' LIMIT 3000', $params);
$tot = ['reg' => 0, 'ot' => 0, 'late' => 0, 'ut' => 0, 'nd' => 0, 'present' => 0, 'absent' => 0];
foreach ($list as $d) {
    $tot['reg'] += $d['regular_minutes'];
    $tot['ot'] += $d['approved_ot_minutes'];
    $tot['late'] += $d['late_minutes'];
    $tot['ut'] += $d['undertime_minutes'];
    $tot['nd'] += $d['nd_minutes'] + $d['nd_ot_minutes'];
    $tot['present'] += $d['status'] === 'present' ? 1 : 0;
    $tot['absent'] += $d['status'] === 'absent' ? 1 : 0;
}
$actions = $emp !== '' ? '<a class="btn" target="_blank" href="' . url('dtr_print', ['employee_id' => $emp, 'from' => $from, 'to' => $to]) . '">Print DTR</a>' : '';
page_header('Daily Time Records', ['subtitle' => 'Biometric attendance summary: time-in, time-out, break, hours rendered, lates and undertime against each employee\'s shift.', 'actions' => $actions]);
?>
<form class="card filters" method="get"><input type="hidden" name="p" value="dtr"><?php if (back_url()): ?><input type="hidden" name="back" value="<?= h(back_url()) ?>"><?php endif; ?>
  <label>Employee<select name="employee_id"><?= options(employee_options(false), $emp, 'All employees') ?></select></label>
  <label>Unit<select name="ou"><?= options(org_unit_options(), $ou, 'All') ?></select></label>
  <label>From<input type="date" name="from" value="<?= h($from) ?>"></label>
  <label>To<input type="date" name="to" value="<?= h($to) ?>"></label>
  <label>Status<select name="status"><?= options(['exceptions' => 'Open exceptions', 'present' => 'Present', 'absent' => 'Absent', 'on_leave' => 'On leave', 'incomplete' => 'Incomplete', 'rest_day' => 'Rest day', 'holiday' => 'Holiday', 'suspended' => 'Suspended'], $status, 'All') ?></select></label>
  <button class="btn btn-primary">Show</button>
</form>
<div class="stats">
  <?= stat_card('Days present', (string)$tot['present']) ?><?= stat_card('Absences', (string)$tot['absent'], '', $tot['absent'] ? 'warn' : '') ?>
  <?= stat_card('Regular hours', number_format($tot['reg'] / 60, 2)) ?><?= stat_card('Approved OT hours', number_format($tot['ot'] / 60, 2)) ?>
  <?= stat_card('Night diff. hours', number_format($tot['nd'] / 60, 2)) ?><?= stat_card('Late / undertime', fmt_minutes($tot['late']) . ' / ' . fmt_minutes($tot['ut'])) ?>
</div>
<div class="card">
  <div class="card-head"><span class="muted"><?= count($list) ?> record(s)</span>
    <form method="post" class="inline" data-confirm="Re-process the DTR for this range from the raw punch logs?"><?= csrf_field() ?><input type="hidden" name="_action" value="reprocess">
      <input type="hidden" name="employee_id" value="<?= h($emp) ?>"><input type="hidden" name="from" value="<?= h($from) ?>"><input type="hidden" name="to" value="<?= h($to) ?>"><button class="btn btn-sm">Re-process from punch logs</button></form></div>
  <div class="table-wrap"><table class="table compact">
    <thead><tr><th>Date</th><?php if ($emp === ''): ?><th>Employee</th><?php endif; ?><th>Shift</th><th>Day type</th><th>Branch</th><th>In</th><th>Break</th><th>Out</th><th class="num">Reg</th><th class="num">Late</th><th class="num">UT</th><th class="num">OT</th><th class="num">ND</th><th>Status</th><th>Exceptions</th><th class="actions"></th></tr></thead>
    <tbody>
    <?php if (!$list) echo empty_row(16); foreach ($list as $d): ?>
      <tr class="<?= $d['exception_status'] === 'open' ? 'row-warn' : ($d['status'] === 'absent' ? 'row-bad' : '') ?>">
        <td class="nowrap"><?= h(fmt_date($d['work_date'], 'D M d')) ?></td>
        <?php if ($emp === ''): ?><td class="nowrap"><a href="<?= url('dtr', ['employee_id' => $d['employee_id'], 'from' => $from, 'to' => $to]) ?>"><?= h(full_name($d)) ?></a></td><?php endif; ?>
        <td class="small"><?= shift_code_html($d['shift_id'] ? shift_row((int)$d['shift_id']) : null, '') ?></td><td class="small"><?= h(label($d['day_type'])) ?></td><td class="small"><?= h($d['branch_code']) ?></td>
        <td class="nowrap"><?= h(fmt_time($d['time_in'])) ?></td><td class="small nowrap"><?= $d['break_out'] ? h(date('H:i', strtotime($d['break_out']))) . '–' . h(date('H:i', strtotime($d['break_in']))) : ($d['break_minutes'] ? (int)$d['break_minutes'] . 'm' : '') ?></td>
        <td class="nowrap"><?= h(fmt_time($d['time_out'])) ?></td><td class="num"><?= $d['regular_minutes'] ? hours($d['regular_minutes']) : '' ?></td>
        <td class="num"><?= $d['late_minutes'] ?: '' ?></td><td class="num"><?= $d['undertime_minutes'] ?: '' ?></td>
        <td class="num"><?= $d['ot_minutes'] ? hours($d['approved_ot_minutes']) . ($d['approved_ot_minutes'] < $d['ot_minutes'] ? '<span class="muted">/' . hours($d['ot_minutes']) . '</span>' : '') : '' ?></td>
        <td class="num"><?= ($d['nd_minutes'] + $d['nd_ot_minutes']) ? hours($d['nd_minutes'] + $d['nd_ot_minutes']) : '' ?></td>
        <td><?= badge($d['status']) ?><?= $d['manual_override'] ? ' <span class="badge badge-purple" title="Manually edited">M</span>' : '' ?></td>
        <td class="small"><?php foreach (array_filter(explode(',', (string)$d['exceptions'])) as $x): ?><span class="badge badge-<?= $d['exception_status'] === 'resolved' ? 'gray' : 'amber' ?>"><?= h(exception_label($x)) ?></span> <?php endforeach; ?></td>
        <td class="actions"><?php if (can('dtr.edit')): ?><a class="btn btn-sm" href="<?= h(url_back('dtr_edit', ['id' => $d['id']])) ?>">Edit</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<?php page_footer();
