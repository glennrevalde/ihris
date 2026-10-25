<?php
/**
 * Code of Conduct catalog with progressive penalties (1st, 2nd, 3rd ... offense), fully editable by HR.
 * v17: the Code has its own column before the Offense Category, and each offense has an optional
 * No. of Days to reset Penalty (an offense later than that after the last one counts as a first offense).
 */
$hasReset = (bool)val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'conduct_offenses' AND column_name = 'reset_days'");
$dimp = dimp_def('conduct', null, []);   // CSV import facility on this form (app/engine/dataimport.php)
dimp_handle($dimp);
if (is_post()) {
    $act = post('_action');
    $id = (int)post('id', 0);
    if ($act === 'delete') {
        if (val('SELECT COUNT(*) FROM disciplinary_cases WHERE offense_id = ?', [$id])) {
            flash('error', 'This offense is referenced by disciplinary cases; deactivate it instead.');
        } else {
            q('DELETE FROM conduct_offenses WHERE id = ?', [$id]);
            audit('pis', 'delete', 'conduct_offenses', $id);
            flash('success', 'Offense deleted.');
        }
        redirect('conduct');
    }
    if ($act === 'save') {
        $d = ['code' => strtoupper((string)post('code')), 'category' => post('category'), 'description' => post('description'), 'severity' => post('severity', 'light'), 'is_active' => post('is_active') ? 1 : 0];
        if ($hasReset) {
            $rd = trim((string)post('reset_days', ''));
            $d['reset_days'] = $rd === '' || (int)$rd <= 0 ? null : min(3650, (int)$rd);
        }
        if (!$d['code'] || !$d['category']) {
            flash('error', 'Code and offense category are required.');
            redirect('conduct', $id ? ['edit' => $id] : ['new' => 1]);
        }
        try {
            tx(function () use (&$id, $d) {
                if ($id) {
                    update('conduct_offenses', $d, 'id = ?', [$id]);
                } else {
                    $id = insert('conduct_offenses', $d);
                }
                q('DELETE FROM conduct_penalties WHERE offense_id = ?', [$id]);
                $types = (array)post('pen_type', []);
                $n = 0;
                foreach ($types as $i => $t) {
                    if (!$t) {
                        continue;
                    }
                    $n++;
                    insert('conduct_penalties', ['offense_id' => $id, 'offense_no' => $n, 'penalty_type' => $t,
                        'min_days' => ($v = post('pen_min')[$i] ?? '') === '' ? null : (int)$v, 'max_days' => ($v = post('pen_max')[$i] ?? '') === '' ? null : (int)$v,
                        'notes' => post('pen_notes')[$i] ?? null]);
                }
            });
            audit('pis', 'save', 'conduct_offenses', $id, $d);
            flash('success', 'Offense and penalty matrix saved.');
        } catch (PDOException $e) {
            flash('error', 'Save failed: offense code must be unique.');
        }
        redirect('conduct');
    }
}
$offenses = rows('SELECT * FROM conduct_offenses ORDER BY is_active DESC, id');
$pens = [];
foreach (rows('SELECT * FROM conduct_penalties ORDER BY offense_no') as $p) {
    $pens[$p['offense_id']][(int)$p['offense_no']] = $p;
}
$maxN = max(3, ...array_map(fn($x) => count($x), $pens ?: [[]]));
$editId = (int)get('edit', 0);
$editing = $editId ? row('SELECT * FROM conduct_offenses WHERE id = ?', [$editId]) : (get('new') ? ['is_active' => 1, 'severity' => 'light'] : null);
$penTypes = ['verbal_warning' => 'Verbal Warning', 'written_warning' => 'Written Warning', 'suspension' => 'Suspension', 'dismissal' => 'Dismissal'];
page_header('Code of Conduct & Penalties', ['subtitle' => 'Progressive penalty matrix. Prior offenses of the same category within the rolling period (' . (int)setting('discipline_lookback_months', 12) . ' months) are counted automatically when an NTE is drafted; the No. of Days to reset Penalty of an offense starts the count again.',
    'actions' => '<a class="btn btn-primary" href="' . url('conduct', ['new' => 1]) . '">+ Add offense</a>']);
echo dimp_panel($dimp);
if ($editing !== null): $rowsP = $pens[$editId] ?? []; ?>
<div class="card form-card"><h2><?= $editId ? 'Edit' : 'New' ?> offense</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="save"><input type="hidden" name="id" value="<?= $editId ?>">
    <div class="form-grid">
      <div class="field"><label>Code</label><input name="code" value="<?= h($editing['code'] ?? '') ?>" required></div>
      <div class="field"><label>Offense category</label><input name="category" value="<?= h($editing['category'] ?? '') ?>" required></div>
      <div class="field"><label>Severity</label><select name="severity"><?= options(['light' => 'Light', 'less_grave' => 'Less grave', 'grave' => 'Grave'], $editing['severity'] ?? 'light') ?></select></div>
      <?php if ($hasReset): ?>
      <div class="field"><label>No. of Days to reset Penalty</label><input type="number" name="reset_days" min="1" max="3650" step="1" value="<?= h((string)($editing['reset_days'] ?? '')) ?>" placeholder="Optional">
        <small class="help">Optional. When the last offense is more than this many days before, the new one counts as a first offense again. Blank = the offenses do not reset.</small></div>
      <?php endif; ?>
      <div class="field"><label class="check"><input type="checkbox" name="is_active" value="1"<?= checked($editing['is_active'] ?? 1) ?>> Active</label></div>
      <div class="field wide"><label>Description / covered acts</label><textarea name="description" rows="2"><?= h($editing['description'] ?? '') ?></textarea></div>
    </div>
    <h3 style="margin-top:14px">Progressive penalties</h3>
    <table class="table compact"><thead><tr><th>Offense</th><th>Penalty</th><th>Min days</th><th>Max days</th><th>Notes</th><th></th></tr></thead>
      <tbody id="penRows">
      <?php foreach ($rowsP ?: [1 => []] as $n => $p): ?>
        <tr><td class="muted">#</td><td><select name="pen_type[]"><?= options($penTypes, $p['penalty_type'] ?? null, '—') ?></select></td>
          <td><input type="number" name="pen_min[]" value="<?= h($p['min_days'] ?? '') ?>" style="width:90px"></td><td><input type="number" name="pen_max[]" value="<?= h($p['max_days'] ?? '') ?>" style="width:90px"></td>
          <td><input name="pen_notes[]" value="<?= h($p['notes'] ?? '') ?>"></td><td><button type="button" class="btn btn-sm btn-danger-outline remove-row">×</button></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    <template id="penRows-tpl"><tr><td class="muted">#</td><td><select name="pen_type[]"><?= options($penTypes, null, '—') ?></select></td><td><input type="number" name="pen_min[]" style="width:90px"></td><td><input type="number" name="pen_max[]" style="width:90px"></td><td><input name="pen_notes[]"></td><td><button type="button" class="btn btn-sm btn-danger-outline remove-row">×</button></td></tr></template>
    <button class="btn btn-sm" data-add-row="penRows">+ Add next offense level</button>
    <div class="form-actions"><button class="btn btn-primary">Save</button> <a class="btn" href="<?= url('conduct') ?>">Cancel</a></div>
  </form>
</div>
<?php endif; ?>
<div class="card"><div class="table-wrap"><table class="table">
  <thead><tr><th>Code</th><th>Offense category</th><th>Severity</th><?php if ($hasReset): ?><th class="num" title="No. of Days to reset Penalty">Reset (days)</th><?php endif; ?><?php for ($i = 1; $i <= $maxN; $i++): ?><th><?= ordinal($i) ?> Offense</th><?php endfor; ?><th class="actions"></th></tr></thead><tbody>
  <?php foreach ($offenses as $o): ?>
    <tr class="<?= $o['is_active'] ? '' : 'row-muted' ?>"><td class="nowrap"><strong class="mono"><?= h($o['code']) ?></strong></td><td><strong><?= h($o['category']) ?></strong><br><span class="small muted"><?= h($o['description']) ?></span></td><td><?= h(label($o['severity'])) ?></td>
      <?php if ($hasReset): ?><td class="num"><?= $o['reset_days'] ? (int)$o['reset_days'] : '<span class="muted">none</span>' ?></td><?php endif; ?>
      <?php for ($i = 1; $i <= $maxN; $i++): ?><td class="small"><?= isset($pens[$o['id']][$i]) ? h(penalty_text($pens[$o['id']][$i])) : '—' ?></td><?php endfor; ?>
      <td class="actions"><a class="btn btn-sm" href="<?= url('conduct', ['edit' => $o['id']]) ?>">Edit</a><form method="post" class="inline" data-confirm="Delete this offense?"><?= csrf_field() ?><input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="<?= $o['id'] ?>"><button class="btn btn-sm btn-danger-outline">Delete</button></form></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php page_footer();
