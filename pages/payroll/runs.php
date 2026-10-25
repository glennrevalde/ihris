<?php
if (is_post() && post('_action') === 'delete_run') {
    try {
        $no = payroll_delete_run((int)post('id'));
        flash('success', 'Cancelled payroll run ' . $no . ' deleted.');
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('payroll_runs', array_filter(['status' => get('status'), 'year' => get('year'), 'pg' => get('pg')]));
}
if (is_post() && post('_action') === 'create') {
    $pg = (int)post('pay_group_id');
    $type = post('run_type', 'regular');
    try {
        if ($type === 'thirteenth') {
            $y = (int)post('year', date('Y'));
            $id = payroll_create_run($pg, 'thirteenth', "$y-01-01", "$y-12-31", post('pay_date') ?: "$y-12-15", post('notes'));
        } else {
            $id = payroll_create_run($pg, $type, (string)post('period_start'), (string)post('period_end'), (string)post('pay_date'), post('notes'));
        }
        if (post('process') === 'all') {
            $st = payroll_compute($id);
            flash('success', sprintf('Payroll run created and computed for %d employee(s).', $st['employees']));
            redirect('payroll_run', ['id' => $id]);
        }
        flash('success', 'Payroll run created. On the Payroll Computation form choose who to process (Employee / ID, Company / Branch, Department, group, type) and the options, then press Process.');
        redirect('payroll_run', ['id' => $id, 'compute' => 1]);
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
        redirect('payroll_runs', ['new' => 1, 'pay_group_id' => $pg]);
    }
}
$f = ['pg' => get('pg', ''), 'status' => get('status', ''), 'year' => get('year', date('Y'))];
$where = ['YEAR(r.period_end) = ?'];
$params = [$f['year']];
if ($f['pg'] !== '') {
    $where[] = 'r.pay_group_id = ?';
    $params[] = $f['pg'];
}
if ($f['status'] !== '') {
    $where[] = 'r.status = ?';
    $params[] = $f['status'];
}
$runs = rows('SELECT r.*, pg.name AS pg_name, pg.frequency, (SELECT COUNT(*) FROM payroll_items WHERE run_id = r.id) AS n,
              (SELECT SUM(gross_pay) FROM payroll_items WHERE run_id = r.id) AS gross, (SELECT SUM(net_pay) FROM payroll_items WHERE run_id = r.id) AS net,
              (SELECT COUNT(*) FROM payroll_exceptions x WHERE x.run_id = r.id AND x.status = \'open\' AND x.blocking = 1) AS blocking
              FROM payroll_runs r JOIN pay_groups pg ON pg.id = r.pay_group_id WHERE ' . implode(' AND ', $where) . ' ORDER BY r.period_end DESC, r.id DESC', $params);
$new = get('new');
$pgSel = (int)(get('pay_group_id') ?: 1);
$sug = payroll_suggest_period($pgSel);
if (get('start')) {
    $sug = ['start' => get('start'), 'end' => get('end'), 'pay_date' => add_days(get('end'), 5)];
}
page_header('Payroll Runs', ['subtitle' => 'Each run pulls the HR-approved attendance summary, the salary structure from the masterfile and the statutory tables.',
    'actions' => '<a class="btn btn-primary" href="' . url('payroll_runs', ['new' => 1]) . '">+ New payroll run</a>']);
if ($new): ?>
<div class="card form-card"><h2>New payroll run</h2>
  <form method="get" class="filters" style="padding:0 0 10px"><input type="hidden" name="p" value="payroll_runs"><input type="hidden" name="new" value="1">
    <label>Pay group<select name="pay_group_id" data-autosubmit><?= options(pay_group_options(), $pgSel) ?></select></label></form>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="create"><input type="hidden" name="pay_group_id" value="<?= $pgSel ?>">
    <div class="form-grid">
      <div class="field"><label>Run type</label><select name="run_type" id="rt"><?= options(['regular' => 'Regular payroll', 'thirteenth' => '13th month pay (PD 851)', 'special' => 'Special / bonus run (pending earnings & adjustments)']) ?></select></div>
      <div class="field rt-reg"><label>Period start</label><input type="date" name="period_start" value="<?= h($sug['start']) ?>"></div>
      <div class="field rt-reg"><label>Period end</label><input type="date" name="period_end" value="<?= h($sug['end']) ?>"></div>
      <div class="field rt-13" style="display:none"><label>Year</label><input type="number" name="year" value="<?= date('Y') ?>"></div>
      <div class="field"><label>Pay date</label><input type="date" name="pay_date" value="<?= h($sug['pay_date']) ?>" required></div>
      <div class="field wide"><label>Notes</label><input name="notes"></div>
      <div class="field wide"><label>After creating the run</label>
        <div class="checks run-proc">
          <label class="check"><input type="radio" name="process" value="form" checked> Open the Payroll Computation form: choose the Employee / ID, Company / Branch or Department to process (one, two or more) and the options</label>
          <label class="check"><input type="radio" name="process" value="all"> Process every employee of the pay code now</label>
        </div></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary">Create run</button> <a class="btn" href="<?= url('payroll_runs') ?>">Cancel</a></div>
  </form>
  <script>(function(){var s=document.getElementById('rt');function f(){var t=s.value==='thirteenth';document.querySelectorAll('.rt-reg').forEach(function(e){e.style.display=t?'none':'';});document.querySelectorAll('.rt-13').forEach(function(e){e.style.display=t?'':'none';});}s.addEventListener('change',f);f();})();</script>
</div>
<?php endif; ?>
<form class="card filters" method="get"><input type="hidden" name="p" value="payroll_runs">
  <label>Year<input type="number" name="year" value="<?= h($f['year']) ?>" style="width:100px"></label>
  <label>Pay group<select name="pg"><?= options(pay_group_options(), $f['pg'], 'All') ?></select></label>
  <label>Status<select name="status"><?= options(['draft' => 'Draft', 'computed' => 'Computed', 'finalized' => 'Finalized', 'cancelled' => 'Cancelled'], $f['status'], 'All') ?></select></label>
  <button class="btn btn-primary">Filter</button></form>
<div class="card"><div class="table-wrap"><table class="table">
  <thead><tr><th>Run</th><th>Type</th><th>Pay group</th><th>Period</th><th>Pay date</th><th class="num">Employees</th><th class="num">Gross</th><th class="num">Net</th><th>Status</th><th class="actions"></th></tr></thead><tbody>
  <?php if (!$runs) echo empty_row(10); foreach ($runs as $r): ?>
    <tr><td><a href="<?= url('payroll_run', ['id' => $r['id']]) ?>"><strong><?= h($r['run_no']) ?></strong></a></td><td><?= h(RUN_TYPES[$r['run_type']] ?? $r['run_type']) ?></td><td><?= h($r['pg_name']) ?></td>
      <td class="nowrap"><?= h(fmt_date($r['period_start'], 'M d')) ?> – <?= h(fmt_date($r['period_end'], 'M d, Y')) ?></td><td class="nowrap"><?= h(fmt_date($r['pay_date'])) ?></td>
      <td class="num"><?= (int)$r['n'] ?></td><td class="num"><?= money($r['gross']) ?></td><td class="num"><?= money($r['net']) ?></td>
      <td><?= badge($r['status']) ?><?= $r['blocking'] ? ' <span class="badge badge-red">' . (int)$r['blocking'] . ' blocking</span>' : '' ?><?= $r['status'] === 'finalized' ? '<br><small class="' . ($r['posted_at'] ? 'muted">posted' : 'state-open">to close') . '</small>' : '' ?></td>
      <td class="actions run-actions"><a class="btn btn-sm btn-primary" href="<?= url('payroll_run', ['id' => $r['id']]) ?>">Open</a>
        <?php if (in_array($r['status'], ['draft', 'computed'], true)): ?><a class="btn btn-sm" href="<?= url('payroll_run', ['id' => $r['id'], 'compute' => 1]) ?>" title="Payroll Computation form: choose who to process"><?= $r['status'] === 'draft' ? 'Process' : 'Reprocess' ?></a><?php endif; ?>
        <?php if ($r['status'] === 'cancelled'): ?><form method="post" class="inline" data-confirm="Delete cancelled payroll run <?= h($r['run_no']) ?> permanently?"><?= csrf_field() ?><input type="hidden" name="_action" value="delete_run"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-danger-outline">Delete</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php page_footer();
