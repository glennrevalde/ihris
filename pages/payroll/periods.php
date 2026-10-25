<?php
/**
 * Period Parameters — the pay periods (cutoffs) of every pay code: generate a whole year,
 * add or edit one period, approve ("process attendance"), re-open and close (Pay Close).
 */
$pgSel = (int)get('pay_group_id', 0) ?: (int)val('SELECT id FROM pay_groups WHERE is_active = 1 ORDER BY id LIMIT 1');
$year = (int)get('year', (int)date('Y'));

if (is_post()) {
    verify_csrf();
    $action = post('_action');
    $id = (int)post('id');
    try {
        switch ($action) {
            case 'generate':
                $r = pt_generate_periods((int)post('pay_group_id'), (int)post('year'), [
                    'start1' => post('start1'), 'start2' => post('start2'), 'first_start' => post('first_start'),
                    'last_start' => post('last_start'), 'pay_offset' => post('pay_offset'), 'description' => post('description'),
                ]);
                flash($r['created'] ? 'success' : 'warning', sprintf('%d period(s) created%s.', $r['created'], $r['skipped'] ? ', ' . $r['skipped'] . ' skipped (already defined)' : ''));
                break;
            case 'create':
                pt_period_create((int)post('pay_group_id'), (string)post('period_start'), (string)post('period_end'), post('pay_date') ?: null, post('description') ?: null);
                flash('success', 'Period created.');
                break;
            case 'update':
                update('attendance_cutoffs', ['pay_date' => post('pay_date') ?: null, 'description' => post('description') ?: null, 'notes' => post('notes') ?: null], 'id = ?', [$id]);
                audit('payroll', 'period_update', 'attendance_cutoffs', $id);
                flash('success', 'Period updated.');
                break;
            case 'summarize':
                $st = summarize_cutoff($id, (bool)post('overwrite'));
                flash('success', sprintf('%d transaction(s) generated from the DTR, %d manual transaction(s) kept, %d open exception(s).', $st['employees'], $st['manual_kept'], $st['open_exceptions']));
                break;
            case 'approve':
            case 'approve_force':
                approve_cutoff($id, $action === 'approve_force');
                flash('success', 'Period approved — payroll can now be processed for it.');
                break;
            case 'reopen':
                pt_period_reopen($id);
                flash('success', 'Period re-opened for encoding.');
                break;
            case 'delete':
                if ((int)val('SELECT COUNT(*) FROM attendance_summaries WHERE cutoff_id = ?', [$id])) {
                    throw new RuntimeException('This period already has transactions. Delete them first, or keep the period.');
                }
                if (val("SELECT run_no FROM payroll_runs WHERE cutoff_id = ? AND status <> 'cancelled'", [$id])) {
                    throw new RuntimeException('A payroll run is linked to this period.');
                }
                q('DELETE FROM attendance_cutoffs WHERE id = ?', [$id]);
                audit('payroll', 'period_delete', 'attendance_cutoffs', $id);
                flash('success', 'Period deleted.');
                break;
            case 'run':
                $c = row('SELECT * FROM attendance_cutoffs WHERE id = ?', [$id]);
                $runId = payroll_create_run((int)$c['pay_group_id'], 'regular', $c['period_start'], $c['period_end'], $c['pay_date'] ?: add_days($c['period_end'], 5));
                flash('success', 'Payroll run created.');
                redirect('payroll_run', ['id' => $runId]);
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    } catch (PDOException $e) {
        flash('error', 'Database error: ' . $e->getMessage());
    }
    redirect('periods', ['pay_group_id' => (int)(post('pay_group_id') ?: $pgSel), 'year' => (int)(post('year') ?: $year)]);
}

$pg = $pgSel ? row('SELECT * FROM pay_groups WHERE id = ?', [$pgSel]) : null;
$list = $pg ? rows('SELECT c.*, (SELECT COUNT(*) FROM attendance_summaries s WHERE s.cutoff_id = c.id) AS n,
        (SELECT COALESCE(SUM(open_exceptions),0) FROM attendance_summaries s WHERE s.cutoff_id = c.id) AS ex,
        (SELECT r.run_no FROM payroll_runs r WHERE r.cutoff_id = c.id AND r.status <> "cancelled" ORDER BY r.id DESC LIMIT 1) AS run_no,
        (SELECT r.id FROM payroll_runs r WHERE r.cutoff_id = c.id AND r.status <> "cancelled" ORDER BY r.id DESC LIMIT 1) AS run_id,
        (SELECT r.status FROM payroll_runs r WHERE r.cutoff_id = c.id AND r.status <> "cancelled" ORDER BY r.id DESC LIMIT 1) AS run_status
    FROM attendance_cutoffs c WHERE c.pay_group_id = ? AND YEAR(c.period_end) = ? ORDER BY c.period_start', [$pgSel, $year]) : [];
$years = rows('SELECT DISTINCT YEAR(period_end) AS y FROM attendance_cutoffs ORDER BY y DESC');
$yearOpts = [];
foreach ($years as $y) {
    $yearOpts[(int)$y['y']] = (int)$y['y'];
}
foreach ([(int)date('Y') - 1, (int)date('Y'), (int)date('Y') + 1] as $y) {
    $yearOpts[$y] = $y;
}
krsort($yearOpts);
$freq = $pg['frequency'] ?? 'semi_monthly';
$edit = (int)get('edit', 0) ? row('SELECT * FROM attendance_cutoffs WHERE id = ?', [(int)get('edit')]) : null;

page_header('Period Parameters', [
    'subtitle' => 'Pay periods per pay code: the attendance and payroll transactions, the payroll run and the payslips all belong to one of these periods.',
    'actions' => '<a class="btn" href="' . url('pay_groups') . '">Pay Code Table</a><a class="btn" href="' . url('payroll_runs') . '">Payroll runs</a>',
]);
?>
<form method="get" class="card filters">
  <input type="hidden" name="p" value="periods">
  <label>Pay code<select name="pay_group_id" data-autosubmit><?= options(pairs("SELECT id, CONCAT(code, ' — ', name) FROM pay_groups ORDER BY code"), $pgSel) ?></select></label>
  <label>Year<select name="year" data-autosubmit><?= options($yearOpts, $year) ?></select></label>
  <span class="spacer"></span>
  <span class="muted small"><?= $pg ? h(label($pg['frequency'])) . ', ' . h($pg['name']) : '' ?></span>
</form>


  <div class="card flush">
    <div class="card-head"><div><h2><?= h($year) ?> periods</h2><div class="sub"><?= count($list) ?> period(s) defined for <?= h($pg['code'] ?? '') ?></div></div></div>
    <div class="table-wrap">
      <table class="table table-compact">
        <thead><tr><th>#</th><th>Period</th><th>Pay date</th><th>Description</th><th class="num">Trans.</th><th class="num">Exc.</th><th>Status</th><th>Payroll</th><th class="actions"></th></tr></thead>
        <tbody>
        <?php if (!$list) {
            echo empty_row(9, 'No period defined for this pay code and year — use the generator.');
        }
        foreach ($list as $c): ?>
          <tr<?= $edit && (int)$edit['id'] === (int)$c['id'] ? ' class="row-sel"' : '' ?>>
            <td><?= (int)$c['period_no'] ?></td>
            <td class="nowrap"><strong><?= h(fmt_date($c['period_start'], 'M d')) ?> – <?= h(fmt_date($c['period_end'], 'M d, Y')) ?></strong></td>
            <td class="nowrap"><?= h($c['pay_date'] ? fmt_date($c['pay_date']) : '—') ?></td>
            <td><?= h($c['description'] ?: '') ?></td>
            <td class="num"><?= (int)$c['n'] ?></td>
            <td class="num"><?= (int)$c['ex'] ? '<span class="badge badge-amber">' . (int)$c['ex'] . '</span>' : '' ?></td>
            <td><?= badge($c['status']) ?></td>
            <td class="nowrap"><?= $c['run_no'] ? '<a href="' . h(url('payroll_run', ['id' => $c['run_id']])) . '">' . h($c['run_no']) . '</a><br><small class="muted">' . h($c['run_status']) . '</small>' : '' ?></td>
            <td class="actions">
              <a class="btn btn-xs" href="<?= h(url('pay_trans', ['cutoff_id' => $c['id']])) ?>">Transactions</a>
              <a class="btn btn-xs" href="<?= h(url('periods', ['pay_group_id' => $pgSel, 'year' => $year, 'edit' => $c['id']])) ?>">Edit</a>
              <?php if (in_array($c['status'], ['open', 'summarized'], true)): ?>
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="pay_group_id" value="<?= $pgSel ?>"><input type="hidden" name="year" value="<?= $year ?>">
                  <button class="btn btn-xs" name="_action" value="summarize" data-confirm="Generate the transactions of this period from the daily time records? Manually encoded transactions are kept.">Process</button>
                  <button class="btn btn-xs btn-success" name="_action" value="approve" data-confirm="Approve this period? Its transactions become the basis of payroll.">Approve</button>
                  <?php if ((int)$c['ex']): ?>
                    <button class="btn btn-xs btn-warning" name="_action" value="approve_force" data-confirm="Approve even though there are open attendance exceptions? The encoded figures are accepted as they are." title="Approve with the exceptions reviewed">Approve anyway</button>
                  <?php endif; ?>
                  <?php if (!(int)$c['n']): ?><button class="btn btn-xs btn-danger-outline" name="_action" value="delete" data-confirm="Delete this period?">Delete</button><?php endif; ?>
                </form>
              <?php else: ?>
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="pay_group_id" value="<?= $pgSel ?>"><input type="hidden" name="year" value="<?= $year ?>">
                  <?php if (!$c['run_no']): ?><button class="btn btn-xs btn-primary" name="_action" value="run">Create payroll run</button><?php endif; ?>
                  <button class="btn btn-xs" name="_action" value="reopen" data-confirm="Re-open this period for encoding?">Re-open</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<div class="grid grid-3">
    <?php if ($edit): ?>
    <div class="card">
      <h2>Edit period</h2>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="_action" value="update"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
        <input type="hidden" name="pay_group_id" value="<?= $pgSel ?>"><input type="hidden" name="year" value="<?= $year ?>">
        <div class="form-grid">
          <div class="field"><label>Period</label><input value="<?= h(fmt_date($edit['period_start']) . ' – ' . fmt_date($edit['period_end'])) ?>" disabled></div>
          <div class="field"><label>Pay date</label><input type="date" name="pay_date" value="<?= h($edit['pay_date']) ?>"></div>
          <div class="field wide"><label>Description</label><input name="description" value="<?= h($edit['description']) ?>" placeholder="e.g. 1st pay of September"></div>
          <div class="field wide"><label>Notes</label><textarea name="notes" rows="2"><?= h($edit['notes']) ?></textarea></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary">Save</button><a class="btn" href="<?= h(url('periods', ['pay_group_id' => $pgSel, 'year' => $year])) ?>">Cancel</a></div>
      </form>
    </div>
    <?php endif; ?>

    <div class="card">
      <h2>Generate periods</h2>
      <p class="muted small">Creates every period of the year for this pay code. Periods that already exist are skipped.</p>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="_action" value="generate">
        <input type="hidden" name="pay_group_id" value="<?= $pgSel ?>">
        <div class="form-grid">
          <div class="field"><label>Year</label><input type="number" name="year" value="<?= $year ?>" min="2000" max="2100" required></div>
          <?php if ($freq === 'semi_monthly' || $freq === 'monthly'): ?>
            <div class="field"><label><?= $freq === 'monthly' ? 'Period starts on day' : '1st period starts on day' ?></label><input type="number" name="start1" value="<?= $freq === 'monthly' ? 1 : 1 ?>" min="1" max="28" required></div>
          <?php endif; ?>
          <?php if ($freq === 'semi_monthly'): ?>
            <div class="field"><label>2nd period starts on day</label><input type="number" name="start2" value="16" min="1" max="28" required><span class="help">e.g. 11 and 26 for a 26–10 / 11–25 cutoff</span></div>
          <?php endif; ?>
          <?php if ($freq === 'weekly' || $freq === 'daily'): ?>
            <div class="field"><label>First period starts</label><input type="date" name="first_start" value="<?= h($year . '-01-01') ?>" required></div>
          <?php endif; ?>
          <?php if ($freq === 'daily'): ?>
            <div class="field"><label>Last day</label><input type="date" name="last_start" value="<?= h($year . '-12-31') ?>" required></div>
          <?php endif; ?>
          <div class="field"><label>Pay date = period end +</label><input type="number" name="pay_offset" value="<?= (int)setting('pay_date_offset', 5) ?>" min="0" max="31"><span class="help">days</span></div>
          <div class="field wide"><label>Description (optional)</label><input name="description" placeholder="Shown beside the period"></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary" data-confirm="Generate the periods of this year for this pay code?">Generate <?= h($year) ?></button></div>
      </form>
    </div>

    <div class="card">
      <h2>Add one period</h2>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="_action" value="create">
        <input type="hidden" name="pay_group_id" value="<?= $pgSel ?>"><input type="hidden" name="year" value="<?= $year ?>">
        <div class="form-grid">
          <div class="field"><label>Period start</label><input type="date" name="period_start" required></div>
          <div class="field"><label>Period end</label><input type="date" name="period_end" required></div>
          <div class="field"><label>Pay date</label><input type="date" name="pay_date"></div>
          <div class="field"><label>Description</label><input name="description"></div>
        </div>
        <div class="form-actions"><button class="btn">Add period</button></div>
      </form>
    </div>
  </div>

<?php page_footer();
