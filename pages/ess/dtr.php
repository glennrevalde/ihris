<?php
$me = my_employee_id();
if (!$me) {
    throw new RuntimeException('Your account is not linked to an employee record.');
}
$from = get('from', date('Y-m-01'));
$to = get('to', min(date('Y-m-t'), today()));
$list = rows('SELECT d.*, b.code AS branch_code FROM dtr d LEFT JOIN branches b ON b.id = d.branch_id WHERE d.employee_id = ? AND d.work_date BETWEEN ? AND ? ORDER BY d.work_date', [$me, $from, $to]);
page_header('My Daily Time Record', ['actions' => '<a class="btn" target="_blank" href="' . url('dtr_print', ['employee_id' => $me, 'from' => $from, 'to' => $to]) . '">Print DTR</a>']);
?>
<form class="card filters" method="get"><input type="hidden" name="p" value="my_dtr"><label>From<input type="date" name="from" value="<?= h($from) ?>"></label><label>To<input type="date" name="to" value="<?= h($to) ?>"></label><button class="btn btn-primary">Show</button></form>
<div class="card"><div class="table-wrap"><table class="table compact"><thead><tr><th>Date</th><th>Day type</th><th>Site</th><th>In</th><th>Out</th><th class="num">Regular hrs</th><th class="num">Late (min)</th><th class="num">Undertime</th><th class="num">OT hrs</th><th>Status</th><th>Notes</th></tr></thead><tbody>
<?php if (!$list) echo empty_row(11); foreach ($list as $d): ?>
  <tr><td class="nowrap"><?= h(fmt_date($d['work_date'], 'D M d')) ?></td><td class="small"><?= h(label($d['day_type'])) ?></td><td><?= h($d['branch_code']) ?></td><td><?= h(fmt_time($d['time_in'])) ?></td><td><?= h(fmt_time($d['time_out'])) ?></td>
    <td class="num"><?= $d['regular_minutes'] ? hours($d['regular_minutes']) : '' ?></td><td class="num"><?= $d['late_minutes'] ?: '' ?></td><td class="num"><?= $d['undertime_minutes'] ?: '' ?></td><td class="num"><?= $d['approved_ot_minutes'] ? hours($d['approved_ot_minutes']) : '' ?></td>
    <td><?= badge($d['status']) ?></td><td class="small"><?php foreach (array_filter(explode(',', (string)$d['exceptions'])) as $x): ?><span class="badge badge-<?= $d['exception_status'] === 'open' ? 'amber' : 'gray' ?>"><?= h(exception_label($x)) ?></span> <?php endforeach; ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<p class="small muted">Missing punches or overtime issues? Coordinate with your supervisor / HR — corrections are made by HR with an audit trail.</p></div>
<?php page_footer();
