<?php
/**
 * Department-specific reports from the organizational tree: headcount, payroll cost, attendance rate, disciplinary cases.
 */
$root = (int)(get('ou') ?: (val('SELECT id FROM org_units WHERE parent_id IS NULL ORDER BY sort_order, id LIMIT 1') ?: 0));
$from = get('from', date('Y-m-01'));
$to = get('to', today());
$month = get('month', date('Y-m', strtotime('first day of last month')));
[$y, $m] = array_map('intval', explode('-', $month));
$all = org_units_all();

function dept_stats(int $unit, string $from, string $to, int $y, int $m): array
{
    $ids = org_descendant_ids($unit);
    $in = in_list($ids);
    $hc = (int)val("SELECT COUNT(*) FROM employees WHERE status = 'active' AND org_unit_id IN ($in)", $ids);
    $pay = row("SELECT COALESCE(SUM(pi.gross_pay),0) g, COALESCE(SUM(pi.sss_er + pi.sss_ec + pi.phic_er + pi.hdmf_er),0) er, COALESCE(SUM(pi.ot_pay),0) ot
                FROM payroll_items pi JOIN payroll_runs r ON r.id = pi.run_id WHERE r.status = 'finalized' AND YEAR(r.period_end) = ? AND MONTH(r.period_end) = ? AND pi.org_unit_id IN ($in)", array_merge([$y, $m], $ids));
    $att = row("SELECT SUM(d.is_workday = 1 AND d.status IN ('present','absent','on_leave','incomplete','suspended')) sched, SUM(d.status = 'present' AND d.is_workday = 1) present,
                       SUM(d.status = 'absent') absent, SUM(d.status = 'on_leave') leave_days, SUM(d.late_minutes > 0 AND d.status = 'present' AND d.day_type = 'ordinary') lates
                FROM dtr d JOIN employees e ON e.id = d.employee_id WHERE d.work_date BETWEEN ? AND ? AND e.org_unit_id IN ($in)", array_merge([$from, $to], $ids));
    $cases = row("SELECT COUNT(*) total, SUM(c.status NOT IN ('decided','closed')) open FROM disciplinary_cases c JOIN employees e ON e.id = c.employee_id
                  WHERE c.incident_date >= ? AND e.org_unit_id IN ($in)", array_merge([date('Y-m-d', strtotime('-12 months'))], $ids));
    $sched = (int)$att['sched'];
    return ['hc' => $hc, 'gross' => (float)$pay['g'], 'er' => (float)$pay['er'], 'ot' => (float)$pay['ot'], 'rate' => $sched ? round(((int)$att['present'] + (int)$att['leave_days']) / $sched * 100, 1) : null,
        'present' => (int)$att['present'], 'absent' => (int)$att['absent'], 'leave' => (int)$att['leave_days'], 'lates' => (int)$att['lates'], 'cases' => (int)$cases['total'], 'open_cases' => (int)$cases['open']];
}
$units = array_merge([$root], array_map(fn($u) => (int)$u['id'], array_filter($all, fn($u) => (int)$u['parent_id'] === $root)));
page_header('Department Reports', ['subtitle' => 'Headcount, payroll cost, attendance rate and disciplinary cases for any node of the organizational tree (figures include sub-units).',
    'actions' => '<button class="btn" onclick="window.print()">Print</button>']);
?>
<form class="card filters" method="get"><input type="hidden" name="p" value="rpt_departments">
  <label>Unit<select name="ou"><?= options(org_unit_options(), $root) ?></select></label>
  <label>Attendance from<input type="date" name="from" value="<?= h($from) ?>"></label><label>to<input type="date" name="to" value="<?= h($to) ?>"></label>
  <label>Payroll month<input type="month" name="month" value="<?= h($month) ?>"></label>
  <button class="btn btn-primary">Show</button></form>
<div class="card"><h2><?= h(org_path($root)) ?></h2>
<div class="table-wrap"><table class="table"><thead><tr><th>Unit</th><th class="num">Headcount</th><th class="num">Payroll cost <?= h(date('M Y', mktime(0, 0, 0, $m, 1, $y))) ?></th><th class="num">Overtime</th><th class="num">Attendance rate</th><th class="num">Absences</th><th class="num">Leave days</th><th class="num">Late instances</th><th class="num">Disciplinary cases (12 mo.)</th></tr></thead><tbody>
<?php foreach ($units as $i => $u): $s = dept_stats($u, $from, $to, $y, $m); ?>
  <tr<?= $i === 0 ? ' style="font-weight:700;background:#f8fafc"' : '' ?>><td><?= $i === 0 ? 'All of ' . h($all[$u]['name'] ?? '') : '<a href="' . url('rpt_departments', ['ou' => $u, 'from' => $from, 'to' => $to, 'month' => $month]) . '">' . h($all[$u]['name']) . '</a> <span class="muted small">' . h($all[$u]['unit_type']) . '</span>' ?></td>
    <td class="num"><?= $s['hc'] ?></td><td class="num"><?= money($s['gross'] + $s['er']) ?></td><td class="num"><?= money($s['ot']) ?></td>
    <td class="num"><?= $s['rate'] === null ? '—' : '<span class="badge badge-' . ($s['rate'] >= 95 ? 'green' : ($s['rate'] >= 90 ? 'amber' : 'red')) . '">' . $s['rate'] . '%</span>' ?></td>
    <td class="num"><?= $s['absent'] ?></td><td class="num"><?= $s['leave'] ?></td><td class="num"><?= $s['lates'] ?></td><td class="num"><?= $s['cases'] ?><?= $s['open_cases'] ? ' <span class="badge badge-amber">' . $s['open_cases'] . ' open</span>' : '' ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<p class="small muted">Attendance rate = (days present + approved leave days) ÷ scheduled working days in the period.</p></div>
<?php page_footer();
