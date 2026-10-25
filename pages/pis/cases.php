<?php
if (get('suggest')) {
    $emp = (int)get('employee_id');
    $off = (int)get('offense_id');
    $date = get('date') ?: today();
    if (!$emp || !$off) {
        json_response(['text' => '']);
    }
    $s = suggest_penalty($emp, $off, $date);
    json_response(['prior' => $s['prior'], 'text' => sprintf('%s offense within %d months (%d prior) → %s', ordinal($s['offense_no']), (int)setting('discipline_lookback_months', 12), $s['prior'], $s['text'])]);
}
if (is_post() && post('_action') === 'nte_dismiss') {
    // v16: an NTE to-do reviewed by HR and not pursued (kept with the note)
    $t = nte_supported() ? row('SELECT * FROM nte_todos WHERE id = ?', [(int)post('id')]) : null;
    if ($t && scope_allows((int)$t['employee_id'])) {
        $note = trim((string)post('note'));
        nte_close((int)$t['id'], 'dismissed', null, $note !== '' ? mb_substr($note, 0, 255) : 'Reviewed by HR, no NTE');
        audit('pis', 'nte_todo_dismiss', 'nte_todos', $t['id'], ['employee_id' => $t['employee_id'], 'month' => $t['period_month'], 'note' => $note]);
        flash('success', 'To-do dismissed.');
    }
    redirect('dashboard');
}
if (is_post() && post('_action') === 'create') {
    if (!post('employee_id') || !post('offense_id') || !post('incident_date') || !post('incident_details')) {
        flash('error', 'Employee, offense, incident date and details are required.');
        redirect('cases', ['new' => 1]);
    }
    $id = create_case((int)post('employee_id'), (int)post('offense_id'), (string)post('incident_date'), (string)post('incident_details'), post('policy_provision'), post('source', 'manual'));
    if ((int)post('nte_todo') && nte_supported()) {   // v16: the NTE to-do of the dashboard is done
        $t = row('SELECT * FROM nte_todos WHERE id = ?', [(int)post('nte_todo')]);
        if ($t && (int)$t['employee_id'] === (int)post('employee_id')) {
            nte_close((int)$t['id'], 'nte_issued', $id);
        }
    }
    flash('success', 'Case drafted. Review the suggested penalty, then issue the Notice to Explain.');
    redirect('case_view', ['id' => $id]);
}
$f = ['status' => get('status', ''), 'offense' => get('offense', ''), 'emp' => get('emp', '')];
$where = ['1=1'];
$params = [];
if ($f['status'] === 'open') {
    $where[] = "c.status NOT IN ('decided','closed')";
} elseif ($f['status'] !== '') {
    $where[] = 'c.status = ?';
    $params[] = $f['status'];
}
foreach (['offense' => 'c.offense_id', 'emp' => 'c.employee_id'] as $k => $col) {
    if ($f[$k] !== '') {
        $where[] = "$col = ?";
        $params[] = $f[$k];
    }
}
$list = rows('SELECT c.*, o.category, e.first_name, e.last_name, e.middle_name, e.suffix, e.employee_no FROM disciplinary_cases c
              JOIN conduct_offenses o ON o.id = c.offense_id JOIN employees e ON e.id = c.employee_id WHERE ' . implode(' AND ', $where) . user_scope_sql('e') . ' ORDER BY c.created_at DESC', $params);
$new = get('new');
page_header('Disciplinary Cases', ['subtitle' => 'Twin-notice due process: Notice to Explain → opportunity to be heard → evaluation → Notice of Decision. Every stage is time-stamped and archived in the 201 file.',
    'actions' => '<a class="btn btn-primary" href="' . url('cases', ['new' => 1]) . '">+ New case / draft NTE</a>']);
if ($new): ?>
<div class="card form-card"><h2>New disciplinary case</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="create"><input type="hidden" name="source" value="<?= h(get('source', 'manual')) ?>"><?php if ((int)get('nte_todo')): ?><input type="hidden" name="nte_todo" value="<?= (int)get('nte_todo') ?>"><?php endif; ?>
    <div class="form-grid">
      <div class="field"><label>Employee</label><select name="employee_id" id="cEmp" required><?= options(employee_options(), get('employee_id'), '— select —') ?></select></div>
      <div class="field"><label>Offense (Code of Conduct)</label><select name="offense_id" id="cOff" required><?= options(pairs("SELECT id, CONCAT(category, ' (', code, ')') FROM conduct_offenses WHERE is_active = 1 ORDER BY id"), get('offense_id'), '— select —') ?></select></div>
      <div class="field"><label>Incident date</label><input type="date" name="incident_date" id="cDate" value="<?= h(get('date', today())) ?>" required></div>
      <div class="field wide"><label>Specific act or omission complained of</label><textarea name="incident_details" rows="3" required><?= h(get('details', '')) ?></textarea></div>
      <div class="field wide"><label>Company policy / Code of Conduct provision allegedly violated</label><input name="policy_provision" value="<?= h(get('provision', '')) ?>"></div>
    </div>
    <div class="callout" id="cSuggest" style="margin-top:12px">Select an employee and offense to see the suggested progressive penalty.</div>
    <div class="form-actions"><button class="btn btn-primary">Save draft</button> <a class="btn" href="<?= url('cases') ?>">Cancel</a></div>
  </form>
</div>
<script>
(function () {
  var e = document.getElementById('cEmp'), o = document.getElementById('cOff'), d = document.getElementById('cDate'), out = document.getElementById('cSuggest');
  function upd() {
    if (!e.value || !o.value) return;
    fetch('<?= url('cases') ?>&suggest=1&employee_id=' + e.value + '&offense_id=' + o.value + '&date=' + d.value, {credentials: 'same-origin'})
      .then(function (r) { return r.json(); }).then(function (j) { out.innerHTML = '<strong>Suggested penalty:</strong> ' + j.text.replace(/</g, '&lt;'); });
  }
  [e, o, d].forEach(function (x) { x.addEventListener('change', upd); }); upd();
})();
</script>
<?php endif; ?>
<form class="card filters" method="get"><input type="hidden" name="p" value="cases">
  <label>Status<select name="status"><?= options(array_merge(['open' => 'All open'], CASE_STAGES), $f['status'], 'All') ?></select></label>
  <label>Offense<select name="offense"><?= options(pairs('SELECT id, category FROM conduct_offenses ORDER BY id'), $f['offense'], 'All') ?></select></label>
  <label>Employee<select name="emp"><?= options(employee_options(false), $f['emp'], 'All') ?></select></label>
  <button class="btn btn-primary">Filter</button>
</form>
<div class="card"><div class="table-wrap"><table class="table">
  <thead><tr><th>Case No.</th><th>Employee</th><th>Offense</th><th>Incident</th><th>Offense no.</th><th>Suggested / decided</th><th>Explanation due</th><th>Status</th><th class="actions"></th></tr></thead><tbody>
  <?php if (!$list) echo empty_row(9); foreach ($list as $c): $overdue = $c['status'] === 'nte_issued' && $c['explanation_due'] && $c['explanation_due'] < today(); ?>
    <tr class="<?= $overdue ? 'row-warn' : '' ?>"><td><a href="<?= url('case_view', ['id' => $c['id']]) ?>"><?= h($c['case_no']) ?></a><br><span class="small muted"><?= h(label($c['source'])) ?></span></td>
      <td><?= h(full_name($c)) ?><br><span class="muted small"><?= h($c['employee_no']) ?></span></td><td><?= h($c['category']) ?></td><td class="nowrap"><?= h(fmt_date($c['incident_date'])) ?></td>
      <td><?= h(ordinal((int)$c['offense_count'])) ?></td><td class="small"><?= $c['decision'] ? '<strong>' . h(PENALTY_LABELS[$c['decision']]) . '</strong>' : h($c['suggested_penalty']) ?></td>
      <td class="nowrap"><?= h(fmt_date($c['explanation_due'])) ?><?= $overdue ? ' <span class="badge badge-amber">lapsed</span>' : '' ?></td><td><?= badge($c['status'], CASE_STAGES[$c['status']]) ?></td>
      <td class="actions"><a class="btn btn-sm" href="<?= url('case_view', ['id' => $c['id']]) ?>">Open</a></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php page_footer();
