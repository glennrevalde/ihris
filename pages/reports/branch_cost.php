<?php
/**
 * Inter-branch cost allocation: gross earnings, employer share and total payroll cost attributable to each branch,
 * even though statutory deductions are logged under each employee's default branch.
 */
$month = get('month', date('Y-m', strtotime('first day of last month')));
[$y, $m] = array_map('intval', explode('-', $month));
$rows = rows("SELECT pb.branch_id, b.code, b.name, pb.employee_id, e.first_name, e.last_name, e.middle_name, e.suffix, pb.is_default,
                     pb.days_worked, pb.reg_hours, pb.basic_alloc, pb.premium_pay, pb.ot_pay, pb.nd_pay, pb.other_earnings, pb.late_ut_deduction, pb.gross, pb.er_share_alloc
              FROM payroll_branch_items pb JOIN payroll_runs r ON r.id = pb.run_id JOIN branches b ON b.id = pb.branch_id JOIN employees e ON e.id = pb.employee_id
              WHERE r.status = 'finalized' AND YEAR(r.period_end) = ? AND MONTH(r.period_end) = ?" . user_scope_sql('e') . ' ORDER BY b.code, e.last_name', [$y, $m]);
$by = [];
foreach ($rows as $r) {
    $b = $r['branch_id'];
    $by[$b]['name'] = $r['code'] . ' — ' . $r['name'];
    $e = $r['employee_id'];
    if (!isset($by[$b]['emp'][$e])) {
        $by[$b]['emp'][$e] = ['name' => full_name($r), 'default' => $r['is_default'], 'days' => 0, 'hrs' => 0, 'gross' => 0, 'er' => 0, 'ot' => 0];
    }
    $x = &$by[$b]['emp'][$e];
    $x['days'] += (float)$r['days_worked'];
    $x['hrs'] += (float)$r['reg_hours'];
    $x['gross'] += (float)$r['gross'];
    $x['er'] += (float)$r['er_share_alloc'];
    $x['ot'] += (float)$r['ot_pay'];
    unset($x);
}
$multi = [];
foreach ($rows as $r) {
    $multi[$r['employee_id']][$r['branch_id']] = true;
}
$multi = array_filter($multi, fn($b) => count($b) > 1);
page_header('Branch Cost Allocation', ['subtitle' => 'Payroll cost per branch / cost center for ' . h(month_name($m) . ' ' . $y) . '. ' . count($multi) . ' employee(s) worked in more than one branch.',
    'actions' => '<button class="btn" onclick="window.print()">Print</button>']);
?>
<form class="card filters" method="get"><input type="hidden" name="p" value="rpt_branch"><label>Month<input type="month" name="month" value="<?= h($month) ?>" data-autosubmit></label></form>
<?php if (!$by): ?><div class="card"><p class="muted">No finalized payroll for this month.</p></div><?php endif; ?>
<?php foreach ($by as $b): $tg = array_sum(array_column($b['emp'], 'gross')); $te = array_sum(array_column($b['emp'], 'er')); ?>
<div class="card"><div class="card-head"><h2><?= h($b['name']) ?></h2><span><strong><?= peso($tg + $te) ?></strong> total cost</span></div>
  <table class="table compact"><thead><tr><th>Employee</th><th class="num">Days</th><th class="num">Reg. hours</th><th class="num">Overtime</th><th class="num">Gross earnings</th><th class="num">ER share alloc.</th><th class="num">Cost</th></tr></thead><tbody>
  <?php foreach ($b['emp'] as $eid => $e): ?><tr><td><?= h($e['name']) ?><?= $e['default'] ? '' : ' <span class="badge badge-blue">shared</span>' ?><?= isset($multi[$eid]) ? ' <span class="badge badge-purple">multi-branch</span>' : '' ?></td>
    <td class="num"><?= $e['days'] ?></td><td class="num"><?= money($e['hrs']) ?></td><td class="num"><?= money($e['ot']) ?></td><td class="num"><?= money($e['gross']) ?></td><td class="num"><?= money($e['er']) ?></td><td class="num"><?= money($e['gross'] + $e['er']) ?></td></tr><?php endforeach; ?>
  </tbody><tfoot><tr><td>Total</td><td></td><td></td><td class="num"><?= money(array_sum(array_column($b['emp'], 'ot'))) ?></td><td class="num"><?= money($tg) ?></td><td class="num"><?= money($te) ?></td><td class="num"><?= money($tg + $te) ?></td></tr></tfoot></table>
</div>
<?php endforeach;
page_footer();
