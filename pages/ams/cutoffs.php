<?php
/**
 * Attendance cutoffs: HR summarizes and approves the DTR per pay group and period before payroll.
 */
if (is_post()) {
    $act = post('_action');
    if ($act === 'create') {
        $pg = (int)post('pay_group_id');
        $from = post('period_start');
        $to = post('period_end');
        if (!$pg || !$from || !$to || $to < $from) {
            flash('error', 'Select a pay group and a valid period.');
            redirect('cutoffs');
        }
        try {
            $id = pt_period_create($pg, (string)$from, (string)$to);
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('cutoffs');
        }
        if (post('summarize')) {
            $r = summarize_cutoff($id);
            flash('success', sprintf('Cutoff created and summarized: %d employee(s), %d open exception(s).', $r['employees'], $r['open_exceptions']));
        } else {
            flash('success', 'Cutoff created.');
        }
        redirect('cutoff_view', ['id' => $id]);
    }
}
$pgSel = (int)get('pg', 1);
$sug = payroll_suggest_period($pgSel, val('SELECT MAX(period_end) FROM attendance_cutoffs WHERE pay_group_id = ?', [$pgSel]) ?: null);
$list = rows('SELECT c.*, pg.name AS pg_name, (SELECT COUNT(*) FROM attendance_summaries s WHERE s.cutoff_id = c.id) AS n,
              (SELECT COALESCE(SUM(open_exceptions),0) FROM attendance_summaries s WHERE s.cutoff_id = c.id) AS ex,
              (SELECT run_no FROM payroll_runs r WHERE r.cutoff_id = c.id AND r.status <> \'cancelled\' LIMIT 1) AS run_no
              FROM attendance_cutoffs c JOIN pay_groups pg ON pg.id = c.pay_group_id ORDER BY c.period_end DESC, pg.id LIMIT 200');
page_header('Attendance Cutoffs', ['subtitle' => 'Summarize the DTR for a pay period, resolve exceptions, then approve — the approved summary is what the Payroll System consumes.']);
?>
<div class="card"><h2>New cutoff</h2>
  <form method="get" class="filters" style="padding:0 0 10px"><input type="hidden" name="p" value="cutoffs"><label>Pay group<select name="pg" data-autosubmit><?= options(pay_group_options(), $pgSel) ?></select></label></form>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="create"><input type="hidden" name="pay_group_id" value="<?= $pgSel ?>">
    <div class="form-grid">
      <div class="field"><label>Period start</label><input type="date" name="period_start" value="<?= h($sug['start']) ?>" required></div>
      <div class="field"><label>Period end</label><input type="date" name="period_end" value="<?= h($sug['end']) ?>" required></div>
      <div class="field"><label class="check"><input type="checkbox" name="summarize" value="1" checked> Process DTR and summarize now</label></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary">Create cutoff</button></div>
  </form>
</div>
<div class="card"><div class="table-wrap"><table class="table">
  <thead><tr><th>Pay group</th><th>Period</th><th class="num">Employees</th><th class="num">Open exceptions</th><th>Status</th><th>Approved</th><th>Payroll run</th><th class="actions"></th></tr></thead><tbody>
  <?php if (!$list) echo empty_row(8); foreach ($list as $c): ?>
    <tr><td><?= h($c['pg_name']) ?></td><td class="nowrap"><?= h(fmt_date($c['period_start'], 'M d')) ?> – <?= h(fmt_date($c['period_end'], 'M d, Y')) ?></td><td class="num"><?= (int)$c['n'] ?></td>
      <td class="num"><?= $c['ex'] ? '<span class="badge badge-amber">' . (int)$c['ex'] . '</span>' : '0' ?></td><td><?= badge($c['status']) ?></td><td class="small"><?= h(fmt_dt($c['approved_at'])) ?></td><td><?= h($c['run_no']) ?></td>
      <td class="actions"><a class="btn btn-sm btn-primary" href="<?= url('cutoff_view', ['id' => $c['id']]) ?>">Open</a></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php page_footer();
