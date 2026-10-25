<?php
$empId = (int)get('employee_id', 0);
// authorized by index.php: dtr.print, or the employee's own record, or a manager of the employee
$e = employee_row($empId);
if (!$e) {
    throw new RuntimeException('Employee not found.');
}
$from = get('from', date('Y-m-01'));
$to = get('to', date('Y-m-t'));
$rows = rows('SELECT d.*, b.code AS branch_code FROM dtr d LEFT JOIN branches b ON b.id = d.branch_id WHERE d.employee_id = ? AND d.work_date BETWEEN ? AND ? ORDER BY d.work_date', [$empId, $from, $to]);
$byDate = [];
foreach ($rows as $r) {
    $byDate[$r['work_date']] = $r;
}
$t = ['reg' => 0, 'late' => 0, 'ut' => 0, 'ot' => 0, 'nd' => 0];
print_header('Daily Time Record — ' . full_name($e));
echo company_letterhead(row('SELECT * FROM branches WHERE id = ?', [$e['default_branch_id']]));
?>
<h1>Daily Time Record</h1>
<table class="doc-table"><tbody>
  <tr><th>Employee</th><td><?= h(full_name($e, false)) ?> (<?= h($e['employee_no']) ?>)</td><th>Period</th><td><?= h(fmt_date($from)) ?> – <?= h(fmt_date($to)) ?></td></tr>
  <tr><th>Position / unit</th><td><?= h($e['position']) ?>, <?= h($e['org_unit_name']) ?></td><th>Shift</th><td><?= h($e['shift_name']) ?></td></tr>
</tbody></table>
<table class="doc-table">
  <thead><tr><th>Date</th><th>Day type</th><th>Branch</th><th>In</th><th>Break</th><th>Out</th><th class="num">Reg hrs</th><th class="num">Late</th><th class="num">UT</th><th class="num">OT hrs</th><th class="num">ND hrs</th><th>Remarks</th></tr></thead>
  <tbody>
  <?php foreach (date_range($from, $to) as $day): $r = $byDate[$day] ?? null;
      if ($r) {
          $t['reg'] += $r['regular_minutes'];
          $t['late'] += $r['late_minutes'];
          $t['ut'] += $r['undertime_minutes'];
          $t['ot'] += $r['approved_ot_minutes'];
          $t['nd'] += $r['nd_minutes'] + $r['nd_ot_minutes'];
      } ?>
    <tr><td class="nowrap"><?= h(fmt_date($day, 'D m/d')) ?></td><td><?= $r ? h(label($r['day_type'])) : '' ?></td><td><?= h($r['branch_code'] ?? '') ?></td>
      <td><?= $r ? h(fmt_time($r['time_in'])) : '' ?></td><td class="nowrap"><?= $r && $r['break_out'] ? h(date('H:i', strtotime($r['break_out']))) . '–' . h(date('H:i', strtotime($r['break_in']))) : '' ?></td><td><?= $r ? h(fmt_time($r['time_out'])) : '' ?></td>
      <td class="num"><?= $r && $r['regular_minutes'] ? hours($r['regular_minutes']) : '' ?></td><td class="num"><?= $r['late_minutes'] ?? '' ?: '' ?></td><td class="num"><?= $r['undertime_minutes'] ?? '' ?: '' ?></td>
      <td class="num"><?= $r && $r['approved_ot_minutes'] ? hours($r['approved_ot_minutes']) : '' ?></td><td class="num"><?= $r && ($r['nd_minutes'] + $r['nd_ot_minutes']) ? hours($r['nd_minutes'] + $r['nd_ot_minutes']) : '' ?></td>
      <td class="small"><?= $r && !in_array($r['status'], ['present'], true) ? h(label($r['status'])) : '' ?> <?= h($r['remarks'] ?? '') ?></td></tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot><tr><td colspan="6">Totals</td><td class="num"><?= number_format($t['reg'] / 60, 2) ?></td><td class="num"><?= $t['late'] ?></td><td class="num"><?= $t['ut'] ?></td><td class="num"><?= number_format($t['ot'] / 60, 2) ?></td><td class="num"><?= number_format($t['nd'] / 60, 2) ?></td><td></td></tr></tfoot>
</table>
<p class="small">I certify on my honor that the above is a true and correct report of the hours of work performed, record of which was made daily at the time of arrival and departure.</p>
<div class="sig-row"><div class="sig"><div class="line"><?= h(full_name($e, false)) ?></div><small>Employee</small></div><div class="sig"><div class="line">&nbsp;</div><small>Verified by immediate supervisor</small></div></div>
<?php print_footer();
