<?php
/**
 * Salary Grade Table: grades with minimum / midpoint / maximum and incremental steps.
 */
if (is_post()) {
    $act = post('_action');
    $id = (int)post('id', 0);
    if ($act === 'delete') {
        if (val('SELECT COUNT(*) FROM employees WHERE salary_grade_id = ?', [$id])) {
            flash('error', 'Cannot delete a grade that is assigned to employees.');
        } else {
            q('DELETE FROM salary_grade_steps WHERE grade_id = ?', [$id]);
            q('DELETE FROM salary_grades WHERE id = ?', [$id]);
            audit('pis', 'delete', 'salary_grades', $id);
            flash('success', 'Salary grade deleted.');
        }
        redirect('salary_grades');
    }
    if ($act === 'save') {
        $d = ['code' => post('code'), 'position_band' => post('position_band'), 'minimum' => post_num('minimum'), 'midpoint' => post_num('midpoint'),
            'maximum' => post_num('maximum'), 'num_steps' => max(1, min(30, (int)post('num_steps', 5))), 'sort_order' => (int)post('sort_order', 0)];
        if (!$d['code'] || $d['minimum'] <= 0 || $d['maximum'] < $d['minimum'] || $d['midpoint'] < $d['minimum'] || $d['midpoint'] > $d['maximum']) {
            flash('error', 'Please provide a code and a valid range (minimum ≤ midpoint ≤ maximum).');
            redirect('salary_grades', $id ? ['edit' => $id] : ['new' => 1]);
        }
        tx(function () use (&$id, $d) {
            if ($id) {
                $old = row('SELECT * FROM salary_grades WHERE id = ?', [$id]);
                update('salary_grades', $d, 'id = ?', [$id]);
                audit('pis', 'update', 'salary_grades', $id, diff_fields($old, $d));
            } else {
                $id = insert('salary_grades', $d);
                audit('pis', 'create', 'salary_grades', $id, $d);
            }
            q('DELETE FROM salary_grade_steps WHERE grade_id = ?', [$id]);
            $amounts = (array)post('step_amount', []);
            for ($s = 1; $s <= $d['num_steps']; $s++) {
                $auto = $d['num_steps'] > 1 ? $d['minimum'] + ($d['maximum'] - $d['minimum']) * ($s - 1) / ($d['num_steps'] - 1) : $d['minimum'];
                $amt = (post('auto_steps') || !isset($amounts[$s]) || $amounts[$s] === '') ? round($auto, 2) : (float)$amounts[$s];
                insert('salary_grade_steps', ['grade_id' => $id, 'step_no' => $s, 'amount' => $amt]);
            }
        });
        flash('success', 'Salary grade saved.');
        redirect('salary_grades');
    }
}
$grades = rows('SELECT * FROM salary_grades ORDER BY sort_order, code');
$steps = [];
foreach (rows('SELECT * FROM salary_grade_steps ORDER BY step_no') as $s) {
    $steps[$s['grade_id']][$s['step_no']] = $s['amount'];
}
$stats = [];
foreach (rows("SELECT e.salary_grade_id, e.rate_type, e.basic_rate, e.days_per_year FROM employees e WHERE e.status = 'active' AND e.salary_grade_id IS NOT NULL") as $e) {
    $g = $e['salary_grade_id'];
    $m = employee_rates($e)['monthly'];
    $stats[$g]['n'] = ($stats[$g]['n'] ?? 0) + 1;
    $gr = array_values(array_filter($grades, fn($x) => $x['id'] == $g))[0] ?? null;
    if ($gr && $m < (float)$gr['minimum']) {
        $stats[$g]['below'] = ($stats[$g]['below'] ?? 0) + 1;
    }
    if ($gr && $m > (float)$gr['maximum']) {
        $stats[$g]['above'] = ($stats[$g]['above'] ?? 0) + 1;
    }
}
$editId = (int)get('edit', 0);
$editing = $editId ? row('SELECT * FROM salary_grades WHERE id = ?', [$editId]) : (get('new') ? ['num_steps' => 5, 'sort_order' => count($grades) + 1] : null);
$maxSteps = max(1, ...array_map(fn($g) => (int)$g['num_steps'], $grades ?: [['num_steps' => 1]]));

page_header('Salary Grade Table', ['subtitle' => 'Standardized, defensible pay structure. Each step is an incremental movement tied to tenure, performance or promotion.',
    'actions' => '<a class="btn" href="' . url('salary_scaling') . '">Salary scaling simulator</a> <a class="btn btn-primary" href="' . url('salary_grades', ['new' => 1]) . '">+ Add grade</a>']);
if ($editing !== null): ?>
<div class="card form-card"><h2><?= $editId ? 'Edit' : 'New' ?> salary grade</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="save"><input type="hidden" name="id" value="<?= $editId ?>">
    <div class="form-grid">
      <div class="field"><label>Code</label><input name="code" value="<?= h($editing['code'] ?? '') ?>" required></div>
      <div class="field"><label>Position band</label><input name="position_band" value="<?= h($editing['position_band'] ?? '') ?>" required></div>
      <div class="field"><label>Minimum (₱/month)</label><input type="number" step="0.01" name="minimum" value="<?= h($editing['minimum'] ?? '') ?>" required></div>
      <div class="field"><label>Midpoint</label><input type="number" step="0.01" name="midpoint" value="<?= h($editing['midpoint'] ?? '') ?>" required></div>
      <div class="field"><label>Maximum</label><input type="number" step="0.01" name="maximum" value="<?= h($editing['maximum'] ?? '') ?>" required></div>
      <div class="field"><label>Number of steps</label><input type="number" min="1" max="30" name="num_steps" value="<?= h($editing['num_steps'] ?? 5) ?>"></div>
      <div class="field"><label>Sort order</label><input type="number" name="sort_order" value="<?= h($editing['sort_order'] ?? 0) ?>"></div>
    </div>
    <h3 style="margin-top:14px">Step amounts</h3>
    <div class="form-grid">
      <?php for ($s = 1; $s <= (int)($editing['num_steps'] ?? 5); $s++): ?>
        <div class="field"><label>Step <?= $s ?></label><input type="number" step="0.01" name="step_amount[<?= $s ?>]" value="<?= h($steps[$editId][$s] ?? '') ?>"></div>
      <?php endfor; ?>
    </div>
    <label class="check" style="margin-top:8px"><input type="checkbox" name="auto_steps" value="1"<?= $editId ? '' : ' checked' ?>> Auto-generate steps evenly between minimum and maximum</label>
    <div class="form-actions"><button class="btn btn-primary">Save grade</button> <a class="btn" href="<?= url('salary_grades') ?>">Cancel</a></div>
  </form>
</div>
<?php endif; ?>
<div class="card"><div class="table-wrap"><table class="table">
  <thead><tr><th>Grade</th><th>Position band</th><th class="num">Minimum</th><th class="num">Midpoint</th><th class="num">Maximum</th>
    <?php for ($s = 1; $s <= $maxSteps; $s++): ?><th class="num">Step <?= $s ?></th><?php endfor; ?><th class="num">Employees</th><th class="actions"></th></tr></thead>
  <tbody>
  <?php foreach ($grades as $g): $st = $stats[$g['id']] ?? []; ?>
    <tr><td><strong><?= h($g['code']) ?></strong></td><td><?= h($g['position_band']) ?></td>
      <td class="num"><?= money($g['minimum']) ?></td><td class="num"><?= money($g['midpoint']) ?></td><td class="num"><?= money($g['maximum']) ?></td>
      <?php for ($s = 1; $s <= $maxSteps; $s++): ?><td class="num small"><?= isset($steps[$g['id']][$s]) ? money($steps[$g['id']][$s]) : '' ?></td><?php endfor; ?>
      <td class="num"><?= (int)($st['n'] ?? 0) ?><?= !empty($st['below']) ? '<br><span class="badge badge-red">' . $st['below'] . ' below min</span>' : '' ?><?= !empty($st['above']) ? '<br><span class="badge badge-amber">' . $st['above'] . ' above max</span>' : '' ?></td>
      <td class="actions"><a class="btn btn-sm" href="<?= url('salary_grades', ['edit' => $g['id']]) ?>">Edit</a>
        <form method="post" class="inline" data-confirm="Delete this grade?"><?= csrf_field() ?><input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="<?= $g['id'] ?>"><button class="btn btn-sm btn-danger-outline">Delete</button></form></td></tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
<?php page_footer();
