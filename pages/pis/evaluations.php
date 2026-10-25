<?php
$cycles = pairs('SELECT id, name FROM evaluation_cycles ORDER BY period_start DESC');
$cycleId = (int)(get('cycle_id') ?: (val("SELECT id FROM evaluation_cycles WHERE status = 'open' ORDER BY period_start DESC LIMIT 1") ?: array_key_first($cycles)));
$where = 'ev.cycle_id = ?';
$params = [$cycleId];
if (!can('evaluations.approve')) {   // company-wide view for those who finalize evaluations; others see their team
    $team = my_team_ids();
    $me = my_employee_id();
    $where .= ' AND (ev.evaluator_employee_id = ? OR ev.employee_id IN (' . ($team ? implode(',', array_map('intval', $team)) : '0') . '))';
    $params[] = $me ?: 0;
} else {
    $where .= user_scope_sql('e');   // company / branch and department assignment
}
$list = rows("SELECT ev.*, e.first_name, e.last_name, e.middle_name, e.suffix, e.employee_no, e.position, ou.name AS ou_name,
                     s.first_name AS s_first, s.last_name AS s_last
              FROM evaluations ev JOIN employees e ON e.id = ev.employee_id LEFT JOIN org_units ou ON ou.id = e.org_unit_id
              LEFT JOIN employees s ON s.id = ev.evaluator_employee_id WHERE $where ORDER BY e.last_name", $params);
$dist = [];
foreach ($list as $l) {
    if ($l['rating_label']) {
        $dist[$l['rating_label']] = ($dist[$l['rating_label']] ?? 0) + 1;
    }
}
page_header('Performance Evaluations', ['subtitle' => 'Quantitative (weighted KPIs) and qualitative (competency ratings) evaluation per cycle.',
    'actions' => can('eval_cycles.view') ? '<a class="btn" href="' . url('eval_cycles') . '">Manage cycles</a>' : '']);
?>
<form class="card filters" method="get"><input type="hidden" name="p" value="evaluations">
  <label>Cycle<select name="cycle_id" data-autosubmit><?= options($cycles, $cycleId) ?></select></label>
</form>
<?php if ($dist): ?>
<div class="stats"><?php foreach (rows('SELECT rating_label FROM merit_matrix ORDER BY min_score DESC') as $m): ?><?= stat_card($m['rating_label'], (string)($dist[$m['rating_label']] ?? 0)) ?><?php endforeach; ?></div>
<?php endif; ?>
<div class="card"><div class="table-wrap"><table class="table">
  <thead><tr><th>Employee</th><th>Evaluator</th><th class="num">KPI achievement</th><th class="num">Quant. (1-5)</th><th class="num">Qual. (1-5)</th><th class="num">Overall</th><th>Rating</th><th>Status</th><th class="actions"></th></tr></thead><tbody>
  <?php if (!$list) echo empty_row(9, 'No evaluation forms yet. Generate them from Evaluation Cycles.'); foreach ($list as $v): ?>
    <tr class="<?= $v['disciplinary_referral'] ? 'row-bad' : '' ?>"><td><strong><?= h(full_name($v)) ?></strong><br><span class="muted small"><?= h($v['position']) ?>, <?= h($v['ou_name']) ?></span></td>
      <td class="small"><?= h(trim($v['s_first'] . ' ' . $v['s_last'])) ?></td>
      <td class="num"><?= $v['quant_pct'] !== null ? h($v['quant_pct']) . '%' : '—' ?></td><td class="num"><?= h($v['quant_score'] ?? '—') ?></td><td class="num"><?= h($v['qual_score'] ?? '—') ?></td>
      <td class="num"><strong><?= h($v['overall_score'] ?? '—') ?></strong></td><td><?= h($v['rating_label']) ?><?= $v['disciplinary_referral'] ? '<br><span class="badge badge-red">Refer to discipline</span>' : '' ?></td>
      <td><?= badge($v['status']) ?><?= $v['applied_at'] ? '<br><span class="badge badge-green">Applied</span>' : '' ?></td>
      <td class="actions"><a class="btn btn-sm btn-primary" href="<?= url('evaluation_edit', ['id' => $v['id']]) ?>">Open</a></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php page_footer();
