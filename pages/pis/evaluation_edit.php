<?php
$id = (int)get('id', 0);
$ev = row('SELECT ev.*, c.name AS cycle_name, c.period_start, c.period_end, c.quant_weight, c.qual_weight, c.self_weight, c.supervisor_weight, c.peer_weight, c.status AS cycle_status
           FROM evaluations ev JOIN evaluation_cycles c ON c.id = ev.cycle_id WHERE ev.id = ?', [$id]);
if (!$ev) {
    throw new RuntimeException('Evaluation not found.');
}
$emp = employee_row((int)$ev['employee_id']);
$isHr = can('evaluations.approve');
$isEvaluator = my_employee_id() && ((int)$ev['evaluator_employee_id'] === my_employee_id() || is_manager_of((int)$ev['employee_id']));
if (!$isHr && !$isEvaluator) {
    perm_deny();
}
$locked = $ev['status'] === 'finalized';
$comps = rows('SELECT * FROM competencies WHERE is_active = 1 ORDER BY sort_order, id');

if (is_post()) {
    $act = post('_action');
    if (!$locked && in_array($act, ['save', 'submit', 'finalize'], true)) {
        tx(function () use ($id, $comps) {
            q('DELETE FROM evaluation_kpis WHERE evaluation_id = ?', [$id]);
            $names = (array)post('kpi_name', []);
            foreach ($names as $i => $n) {
                if (trim((string)$n) === '') {
                    continue;
                }
                insert('evaluation_kpis', ['evaluation_id' => $id, 'kpi_name' => trim($n), 'weight' => (float)(post('kpi_weight')[$i] ?? 0),
                    'target' => (float)(post('kpi_target')[$i] ?? 0), 'actual' => (post('kpi_actual')[$i] ?? '') === '' ? null : (float)post('kpi_actual')[$i],
                    'lower_is_better' => (int)(post('kpi_lower_flag')[$i] ?? 0)]);
            }
            $ratings = (array)post('sup_rating', []);
            q("DELETE FROM evaluation_ratings WHERE evaluation_id = ? AND rater_type = 'supervisor'", [$id]);
            foreach ($comps as $c) {
                $r = (int)($ratings[$c['id']] ?? 0);
                if ($r >= 1 && $r <= 5) {
                    insert('evaluation_ratings', ['evaluation_id' => $id, 'competency_id' => $c['id'], 'rater_type' => 'supervisor',
                        'rater_name' => current_user()['full_name'], 'rating' => $r, 'comment' => post('sup_comment')[$c['id']] ?? null]);
                }
            }
            update('evaluations', ['remarks' => post('remarks'), 'evaluator_employee_id' => post_null('evaluator_employee_id') ?? val('SELECT evaluator_employee_id FROM evaluations WHERE id = ?', [$id])], 'id = ?', [$id]);
        });
        $res = eval_compute($id);
        if ($act === 'submit') {
            update('evaluations', ['status' => 'submitted'], 'id = ?', [$id]);
        }
        if ($act === 'finalize' && $isHr) {
            if ($res['overall_score'] === null) {
                flash('error', 'Cannot finalize: encode KPI actuals and/or competency ratings first.');
                redirect('evaluation_edit', ['id' => $id]);
            }
            update('evaluations', ['status' => 'finalized', 'finalized_at' => now()], 'id = ?', [$id]);
            $pdfNote = 'Evaluation finalized.';
            audit('pis', 'eval_finalize', 'evaluations', $id, $res);
        }
        audit('pis', 'eval_' . $act, 'evaluations', $id);
        flash('success', ['save' => 'Evaluation saved.', 'submit' => 'Evaluation submitted to HR.', 'finalize' => 'Evaluation finalized. The rating now feeds merit, promotion and step recommendations.'][$act]);
    } elseif ($act === 'peer' && !$locked) {
        $name = trim((string)post('peer_name'));
        foreach ($comps as $c) {
            $r = (int)(post('peer_rating')[$c['id']] ?? 0);
            if ($r >= 1 && $r <= 5 && $name !== '') {
                insert('evaluation_ratings', ['evaluation_id' => $id, 'competency_id' => $c['id'], 'rater_type' => 'peer', 'rater_name' => $name, 'rating' => $r]);
            }
        }
        eval_compute($id);
        flash('success', 'Peer (360°) feedback recorded.');
    } elseif ($act === 'del_peer' && !$locked) {
        q("DELETE FROM evaluation_ratings WHERE evaluation_id = ? AND rater_type = 'peer' AND rater_name = ?", [$id, post('peer_name')]);
        eval_compute($id);
    } elseif ($act === 'reopen' && $isHr) {
        update('evaluations', ['status' => 'draft', 'finalized_at' => null], 'id = ?', [$id]);
        audit('pis', 'eval_reopen', 'evaluations', $id);
        flash('success', 'Evaluation reopened.');
    } elseif ($act === 'apply' && $isHr && $locked && !$ev['applied_at'] && !salary_can('edit')) {
        flash('error', 'Applying the recommendation changes the basic salary: it needs the Basic Salary edit right.');
    } elseif ($act === 'apply' && $isHr && $locked && !$ev['applied_at']) {
        $eff = post('effective_date') ?: add_days($ev['period_end'], 1);
        $mode = post('apply_mode');
        $e = row('SELECT * FROM employees WHERE id = ?', [$ev['employee_id']]);
        $changes = [];
        if ($mode === 'step' && (int)$ev['step_increase'] > 0 && $e['salary_grade_id']) {
            $maxStep = (int)val('SELECT num_steps FROM salary_grades WHERE id = ?', [$e['salary_grade_id']]);
            $newStep = min($maxStep, (int)$e['salary_step'] + (int)$ev['step_increase']);
            $amt = grade_step_amount((int)$e['salary_grade_id'], $newStep);
            if ($amt && $e['rate_type'] === 'monthly') {
                $changes = ['salary_step' => $newStep, 'basic_rate' => $amt];
            }
        } elseif ($mode === 'merit' && (float)$ev['merit_increase_pct'] > 0) {
            $changes = ['basic_rate' => round((float)$e['basic_rate'] * (1 + (float)$ev['merit_increase_pct'] / 100), 2)];
        }
        if (!$changes) {
            flash('error', 'Nothing to apply for the selected option (check grade/step or merit percentage).');
        } else {
            $res = employee_apply_changes((int)$ev['employee_id'], $changes, $eff, 'Per ' . $ev['cycle_name'] . ' evaluation (' . $ev['rating_label'] . ', ' . $ev['overall_score'] . ')');
            update('evaluations', ['applied_at' => now()], 'id = ?', [$id]);
            flash('success', 'Recommendation applied to the masterfile effective ' . fmt_date($eff) . '.' . (!empty($res['retro']) ? ' Retro pay of ' . peso($res['retro']['amount']) . ' queued.' : ''));
        }
    } elseif ($act === 'refer' && $isHr && $ev['disciplinary_referral']) {
        $off = (int)val("SELECT id FROM conduct_offenses WHERE code = 'PERF'") ?: (int)val('SELECT id FROM conduct_offenses ORDER BY id LIMIT 1');
        $cid = create_case((int)$ev['employee_id'], $off, $ev['period_end'], sprintf('Overall performance rating of %s (%s) in %s, below the %s standard for two consecutive evaluation cycles.',
            $ev['overall_score'], $ev['rating_label'], $ev['cycle_name'], setting('underperform_threshold')), 'Performance Management Policy', 'evaluation');
        flash('success', 'Disciplinary case drafted from the evaluation. Review and issue the Notice to Explain.');
        redirect('case_view', ['id' => $cid]);
    }
    redirect('evaluation_edit', ['id' => $id]);
}
$ev = row('SELECT ev.*, c.name AS cycle_name, c.period_start, c.period_end, c.quant_weight, c.qual_weight, c.self_weight, c.supervisor_weight, c.peer_weight
           FROM evaluations ev JOIN evaluation_cycles c ON c.id = ev.cycle_id WHERE ev.id = ?', [$id]);
$kpis = rows('SELECT * FROM evaluation_kpis WHERE evaluation_id = ? ORDER BY id', [$id]);
$rat = [];
foreach (rows('SELECT * FROM evaluation_ratings WHERE evaluation_id = ?', [$id]) as $r) {
    $rat[$r['rater_type']][$r['competency_id']][] = $r;
}
$peers = array_unique(array_map(fn($r) => $r['rater_name'], rows("SELECT rater_name FROM evaluation_ratings WHERE evaluation_id = ? AND rater_type = 'peer'", [$id])));
$ro = $locked ? ' disabled' : '';
$likert = [1 => '1 – Needs Improvement', 2 => '2 – Unsatisfactory', 3 => '3 – Satisfactory', 4 => '4 – Very Satisfactory', 5 => '5 – Outstanding'];
page_header('Evaluation — ' . full_name($emp, false), ['back' => url('evaluations', ['cycle_id' => $ev['cycle_id']])]);
?>
<div class="card"><div class="card-head"><div><h2><?= h($ev['cycle_name']) ?></h2><span class="muted"><?= h(fmt_date($ev['period_start'])) ?> – <?= h(fmt_date($ev['period_end'])) ?>, <?= h($emp['position']) ?>, <?= h($emp['org_unit_name']) ?></span></div><?= badge($ev['status']) ?></div>
  <div class="stats" style="margin:0">
    <?= stat_card('KPI achievement', $ev['quant_pct'] !== null ? h($ev['quant_pct']) . '%' : '—', 'weighted, capped at 150%') ?>
    <?= stat_card('Quantitative score', h($ev['quant_score'] ?? '—'), 'weight ' . (float)$ev['quant_weight'] . '%') ?>
    <?= stat_card('Qualitative score', h($ev['qual_score'] ?? '—'), 'weight ' . (float)$ev['qual_weight'] . '%') ?>
    <?= stat_card('Overall rating', '<strong>' . h($ev['overall_score'] ?? '—') . '</strong>', h($ev['rating_label'] ?? ''), $ev['disciplinary_referral'] ? 'bad' : 'good') ?>
  </div>
  <?php if ($ev['recommendation']): ?><div class="callout<?= $ev['disciplinary_referral'] ? ' bad' : '' ?>" style="margin-top:14px"><strong>Recommendation:</strong> <?= h($ev['recommendation']) ?></div><?php endif; ?>
</div>

<form method="post"><?= csrf_field() ?>
<div class="card"><div class="card-head"><h3>Quantitative — Key Performance Indicators</h3><?php if (!$locked): ?><button class="btn btn-sm" data-add-row="kpiRows">+ Add KPI</button><?php endif; ?></div>
  <p class="small muted">Achievement = actual ÷ target (or target ÷ actual when lower is better), capped at 150%. Weighted achievement converts to the 5-point scale: 100% of target = 4.0, 125% = 5.0.</p>
  <div class="table-wrap"><table class="table compact"><thead><tr><th>KPI</th><th class="num">Weight</th><th class="num">Target</th><th class="num">Actual</th><th>Direction</th><th class="num">Achievement</th><th></th></tr></thead>
  <tbody id="kpiRows">
  <?php foreach ($kpis as $k): ?>
    <tr><td><input name="kpi_name[]" value="<?= h($k['kpi_name']) ?>"<?= $ro ?>></td><td><input type="number" step="0.01" name="kpi_weight[]" value="<?= h($k['weight']) ?>" style="width:80px"<?= $ro ?>></td>
      <td><input type="number" step="any" name="kpi_target[]" value="<?= h($k['target']) ?>" style="width:120px"<?= $ro ?>></td><td><input type="number" step="any" name="kpi_actual[]" value="<?= h($k['actual']) ?>" style="width:120px"<?= $ro ?>></td>
      <td><select name="kpi_lower_flag[]"<?= $ro ?>><?= options([0 => 'Higher is better', 1 => 'Lower is better'], (int)$k['lower_is_better']) ?></select></td><td class="num ach"><?= $k['achievement_pct'] !== null ? h($k['achievement_pct']) . '%' : '—' ?></td>
      <td><?php if (!$locked): ?><button class="btn btn-sm btn-danger-outline remove-row" type="button">×</button><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <template id="kpiRows-tpl"><tr><td><input name="kpi_name[]" placeholder="e.g. Sales quota attained"></td><td><input type="number" step="0.01" name="kpi_weight[]" value="10" style="width:80px"></td><td><input type="number" step="any" name="kpi_target[]" style="width:120px"></td><td><input type="number" step="any" name="kpi_actual[]" style="width:120px"></td><td><select name="kpi_lower_flag[]"><option value="0">Higher is better</option><option value="1">Lower is better</option></select></td><td class="num ach">—</td><td><button class="btn btn-sm btn-danger-outline remove-row" type="button">×</button></td></tr></template>
</div>

<div class="card"><h3>Qualitative — competency ratings (Likert 1–5)</h3>
  <p class="small muted">Sources weighted: supervisor <?= (float)$ev['supervisor_weight'] ?>%, self <?= (float)$ev['self_weight'] ?>%, peers <?= (float)$ev['peer_weight'] ?>% (re-normalized when a source is missing). Self-assessments are entered by the employee under My Evaluations.</p>
  <div class="table-wrap"><table class="table compact"><thead><tr><th>Competency</th><th>Supervisor rating</th><th>Supervisor comment</th><th class="num">Self</th><th class="num">Peer avg.</th></tr></thead><tbody>
  <?php foreach ($comps as $c):
      $sup = $rat['supervisor'][$c['id']][0] ?? null;
      $self = $rat['self'][$c['id']][0]['rating'] ?? null;
      $pr = $rat['peer'][$c['id']] ?? [];
      $pavg = $pr ? round(array_sum(array_column($pr, 'rating')) / count($pr), 2) : null; ?>
    <tr><td><strong><?= h($c['name']) ?></strong><br><span class="small muted"><?= h($c['description']) ?></span></td>
      <td><select name="sup_rating[<?= $c['id'] ?>]"<?= $ro ?>><?= options($likert, $sup['rating'] ?? null, '—') ?></select></td>
      <td><input name="sup_comment[<?= $c['id'] ?>]" value="<?= h($sup['comment'] ?? '') ?>"<?= $ro ?>></td>
      <td class="num"><?= $self ?? '—' ?></td><td class="num"><?= $pavg ?? '—' ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <div class="form-grid" style="margin-top:12px">
    <div class="field"><label>Evaluator</label><select name="evaluator_employee_id"<?= $ro ?>><?= options(employee_options(), $ev['evaluator_employee_id'], '—') ?></select></div>
    <div class="field wide"><label>Overall remarks / development plan</label><textarea name="remarks" rows="3"<?= $ro ?>><?= h($ev['remarks']) ?></textarea></div>
  </div>
  <?php if (!$locked): ?>
  <div class="form-actions">
    <button class="btn" name="_action" value="save">Save draft &amp; compute</button>
    <?php if ($isEvaluator || $isHr): ?><button class="btn btn-primary" name="_action" value="submit">Submit to HR</button><?php endif; ?>
    <?php if ($isHr): ?><button class="btn btn-success" name="_action" value="finalize" data-confirm="Finalize this evaluation? It becomes read-only.">Finalize</button><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
</form>

<div class="grid grid-2">
  <div class="card"><h3>Peer / 360° feedback</h3>
    <?php if ($peers): ?><ul><?php foreach ($peers as $p): ?><li><?= h($p) ?><?php if (!$locked): ?> <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="_action" value="del_peer"><input type="hidden" name="peer_name" value="<?= h($p) ?>"><button class="btn btn-sm btn-danger-outline">Remove</button></form><?php endif; ?></li><?php endforeach; ?></ul><?php else: ?><p class="muted">No peer feedback recorded.</p><?php endif; ?>
    <?php if (!$locked): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="peer">
      <div class="field"><label>Peer / rater name</label><input name="peer_name" required></div>
      <div class="form-grid" style="margin-top:8px"><?php foreach ($comps as $c): ?><div class="field"><label><?= h($c['name']) ?></label><select name="peer_rating[<?= $c['id'] ?>]"><?= options($likert, null, '—') ?></select></div><?php endforeach; ?></div>
      <div class="form-actions"><button class="btn">Add peer feedback</button></div>
    </form>
    <?php endif; ?>
  </div>
  <div class="card"><h3>Actions</h3>
    <?php if ($locked && $isHr): ?>
      <?php if (!$ev['applied_at'] && !salary_can('edit')): ?>
      <p class="small muted">Applying the salary step or merit increase to the masterfile needs the Basic Salary edit right.</p>
      <?php elseif (!$ev['applied_at']): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="apply">
        <div class="form-grid">
          <div class="field"><label>Apply</label><select name="apply_mode"><?= options(['step' => 'Salary step progression (+' . (int)$ev['step_increase'] . ')', 'merit' => 'Merit increase (' . (float)$ev['merit_increase_pct'] . '%)'], 'step') ?></select></div>
          <div class="field"><label>Effective date</label><input type="date" name="effective_date" value="<?= h(add_days($ev['period_end'], 1)) ?>"></div>
        </div>
        <p class="small muted">Updates the masterfile and employment history; a past effective date creates a retroactive pay adjustment.</p>
        <div class="form-actions"><button class="btn btn-primary" data-confirm="Apply this compensation change to the masterfile?">Apply to masterfile</button></div>
      </form>
      <?php else: ?><p><span class="badge badge-green">Applied <?= h(fmt_dt($ev['applied_at'])) ?></span></p><?php endif; ?>
      <?php if ($ev['disciplinary_referral']): ?>
        <form method="post" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="_action" value="refer"><button class="btn btn-danger">Refer to disciplinary workflow (draft NTE)</button></form>
      <?php endif; ?>
      <form method="post" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="_action" value="reopen"><button class="btn btn-sm" data-confirm="Reopen this finalized evaluation?">Reopen evaluation</button></form>
    <?php elseif ($locked): ?><p class="muted">This evaluation is final.</p>
    <?php else: ?><p class="muted">Finalize the evaluation to apply merit, step progression or disciplinary referral.</p><?php endif; ?>
    <p class="small muted" style="margin-top:12px">Promotion eligible: <strong><?= $ev['promotion_eligible'] ? 'Yes' : 'No' ?></strong></p>
  </div>
</div>
<?php page_footer();
