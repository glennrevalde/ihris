<?php
/**
 * Auto-Generate Attendance Transaction — processes the daily time records of a period and
 * writes (or refreshes) the attendance / payroll transaction of every employee of the pay code.
 * Manually encoded transactions are kept unless you ask for them to be overwritten.
 */
require __DIR__ . '/../payroll/_trans_form.php';

$filters = pt_filters();
$period = pt_default_period((int)get('cutoff_id') ?: null);
$keep = array_filter(array_merge(['p' => 'att_generate', 'cutoff_id' => $period['id'] ?? null], $filters), fn($v) => $v !== null && $v !== '');

if (is_post()) {
    verify_csrf();
    $pid = (int)post('cutoff_id');
    try {
        $p = pt_period($pid);
        if (!$p) {
            throw new RuntimeException('Select a period.');
        }
        $action = post('_action');
        if ($action === 'generate') {
            $only = array_map('intval', (array)post('employee_ids', []));
            $st = summarize_cutoff($pid, (bool)post('overwrite'), $only ?: null);
            flash('success', sprintf('%d transaction(s) generated, %d manually encoded transaction(s) kept%s.',
                $st['employees'], $st['manual_kept'], $st['open_exceptions'] ? ', ' . $st['open_exceptions'] . ' open attendance exception(s)' : ''));
        } elseif ($action === 'reprocess') {
            $emps = pt_period_employees($p, []);
            $ids = array_map(fn($e) => (int)$e['id'], $emps);
            process_dtr_range($ids, $p['period_start'], $p['period_end']);
            flash('success', sprintf('Daily time records re-processed for %d employee(s) from %s to %s.', count($ids), fmt_date($p['period_start']), fmt_date($p['period_end'])));
        } elseif ($action === 'approve') {
            approve_cutoff($pid, (bool)post('force'));
            flash('success', 'Period approved — payroll can be processed.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('att_generate', array_merge($keep, ['cutoff_id' => $pid]));
}

page_header('Auto-Generate Attendance Transaction', [
    'subtitle' => 'Turn the daily logs of a period into the hour transactions the payroll pays.',
    'actions' => '<a class="btn" href="' . url('att_batch', $keep) . '">Batch transaction</a><a class="btn" href="' . url('att_entry', $keep) . '">Attendance entry</a>',
]);
echo pt_filter_bar('att_generate', $period, $filters);

if (!$period) {
    echo '<div class="card"><div class="empty-state"><h3>No pay period</h3><a class="btn btn-primary" href="' . url('periods') . '">Period Parameters</a></div></div>';
    page_footer();
    return;
}
$employees = pt_period_employees($period, $filters);
$trans = pairs('SELECT employee_id, source FROM attendance_summaries WHERE cutoff_id = ?', [$period['id']]);
$openEx = (int)val('SELECT COUNT(*) FROM dtr d JOIN employees e ON e.id = d.employee_id WHERE d.exception_status = "open" AND d.work_date BETWEEN ? AND ? AND e.pay_group_id = ?',
    [$period['period_start'], $period['period_end'], $period['pay_group_id']]);
$noLogs = 0;
foreach ($employees as $e) {
    if (!(int)val('SELECT COUNT(*) FROM dtr WHERE employee_id = ? AND work_date BETWEEN ? AND ? AND status NOT IN ("pending","no_schedule")',
        [$e['id'], $period['period_start'], $period['period_end']])) {
        $noLogs++;
    }
}
?>
<div class="stats">
  <?= stat_card('Period', h(pt_period_label($period, false)), h($period['pay_group_name']) . ', ' . pt_period_badge($period), 'info') ?>
  <?= stat_card('Employees', number_format(count($employees)), 'in this pay code') ?>
  <?= stat_card('Transactions', number_format(count($trans)), count(array_filter($trans, fn($s) => $s === 'manual')) . ' encoded manually', count($trans) ? 'good' : 'warn') ?>
  <?= stat_card('Open DTR exceptions', number_format($openEx), $noLogs ? $noLogs . ' employee(s) without any log' : 'all days accounted for', $openEx ? 'bad' : 'good') ?>
</div>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="cutoff_id" value="<?= (int)$period['id'] ?>">
  <div class="grid grid-2-1">
    <div class="card flush">
      <div class="card-head"><div><h2>Employees of the period</h2><div class="sub">Tick employees to generate only theirs; leave everything unticked for the whole pay code.</div></div>
        <input type="search" class="table-search" data-target="genTable" placeholder="Find…" style="width:200px"></div>
      <div class="table-wrap table-scroll">
        <table class="table table-compact" id="genTable">
          <thead><tr><th style="width:34px"><input type="checkbox" data-check-all="employee_ids[]"></th><th>Employee</th><th>Department</th><th>Days with logs</th><th>Transaction</th></tr></thead>
          <tbody>
          <?php foreach ($employees as $e):
              $days = (int)val('SELECT COUNT(*) FROM dtr WHERE employee_id = ? AND work_date BETWEEN ? AND ? AND status NOT IN ("pending","no_schedule")',
                  [$e['id'], $period['period_start'], $period['period_end']]);
              $src = $trans[$e['id']] ?? null; ?>
            <tr class="<?= $days ? '' : 'row-warn' ?>">
              <td><input type="checkbox" name="employee_ids[]" value="<?= (int)$e['id'] ?>"></td>
              <td><strong><?= h(full_name($e)) ?></strong><br><small class="muted"><?= h($e['employee_no']) ?></small></td>
              <td><?= h($e['unit_name'] ?? '—') ?></td>
              <td class="num"><?= $days ?></td>
              <td><?= $src ? '<span class="badge badge-' . ($src === 'manual' ? 'blue">Manual' : 'green">Generated') . '</span>' : '<span class="badge badge-gray">None</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          <?= $employees ? '' : empty_row(5, 'No employee in this pay code.') ?>
          </tbody>
        </table>
      </div>
    </div>

    <div>
      <div class="card">
        <h2>Generate</h2>
        <label class="check"><input type="checkbox" name="overwrite" value="1"> Overwrite transactions that were encoded manually</label>
        <div class="form-actions">
          <button class="btn btn-primary" name="_action" value="generate" data-confirm="Generate the attendance transactions of this period?">Auto-generate transactions</button>
        </div>
        <p class="help">Reads every daily time record of the period and fills the hour columns (work, absent, late, undertime, leaves, overtime, night premium, holiday premiums) of each employee.</p>
      </div>
      <div class="card">
        <h2>Before generating</h2>
        <div class="form-actions" style="margin-top:0">
          <button class="btn" name="_action" value="reprocess" data-confirm="Re-process every daily time record of this period from the raw logs?">Re-process daily logs</button>
        </div>
        <p class="help">Use this after importing new logs, changing a shift or editing the AMS policies.</p>
        <hr>
        <div class="form-actions">
          <button class="btn btn-success" name="_action" value="approve" data-confirm="Close the attendance of this period? Its transactions become the basis of payroll and appear in the Payroll Transaction screens.">Close attendance (approve period)</button>
        </div>
        <p class="help"><?= $openEx ? h($openEx) . ' open exception(s) must be resolved first (or use "Approve anyway" in Period Parameters).' : 'No open exception — the period can be approved.' ?></p>
      </div>
      <div class="card">
        <h2>Next steps</h2>
        <div class="quick">
          <a href="<?= h(url('att_batch', $keep)) ?>"><?= icon('grid') ?>Review the batch grid</a>
          <a href="<?= h(url('rpt_attendance', ['cutoff_id' => $period['id']])) ?>"><?= icon('chart') ?>Attendance report</a>
          <a href="<?= h(url('periods', ['pay_group_id' => $period['pay_group_id']])) ?>"><?= icon('calendar') ?>Period parameters</a>
          <a href="<?= h(url('payroll_runs')) ?>"><?= icon('money') ?>Process payroll</a>
        </div>
      </div>
    </div>
  </div>
</form>
<?php page_footer();
