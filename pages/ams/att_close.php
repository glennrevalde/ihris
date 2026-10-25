<?php
/**
 * Attendance Transaction Closing (M.A.D.A.S. "Att. Close"): close the attendance transactions of a period
 * for a Company / Branch, Employee Group, Employee Type or Department, whether they come from summarized
 * logs or were encoded by hand. Closed transactions appear in the Payroll Transaction screens and are the
 * only ones the payroll run pays. Re-open sends them back to the attendance side.
 * v19: closing a company / branch (or a group, type, department, employees) locks only those employees: the
 * others stay open for posting biologs, viewing and summarizing (dtr_locked() is per employee). A filter changed
 * on this screen applies at once and the confirmation names the selection.
 */
require dirname(__DIR__) . '/payroll/_trans_form.php';

$filters = pt_filters();
$period = pt_default_period((int)get('cutoff_id') ?: null);
$keep = array_filter(array_merge(['cutoff_id' => $period['id'] ?? null], $filters), fn($v) => $v !== null && $v !== '');
$keep = array_merge($keep, back_params());

if (is_post()) {
    $pid = (int)post('cutoff_id');
    $ids = array_values(array_filter(array_map('intval', (array)post('emp_ids', []))));
    $f = [];
    foreach (['branch_id', 'employee_group_id', 'employee_type_id', 'org_unit_id', 'q'] as $k) {
        $f[$k] = in_array($k, ['branch_id', 'employee_group_id', 'org_unit_id'], true) ? id_list(post('f_' . $k)) : (post('f_' . $k) ?: null);   // v20: lists
    }
    try {
        if (post('_action') === 'close') {
            $r = att_close($pid, $f, (bool)post('reviewed'), $ids ?: null);
            $partial = $f['branch_id'] || $f['employee_group_id'] || $f['employee_type_id'] || $f['org_unit_id'] || $f['q'] || $ids;
            flash('success', sprintf('%d attendance transaction(s) closed. They now appear in the Payroll Transaction screens for payroll processing.%s%s%s', $r['closed'],
                $r['already'] ? ' ' . $r['already'] . ' were already closed.' : '', $r['missing'] ? ' ' . $r['missing'] . ' employee(s) of the selection have no transaction.' : '',
                $partial ? ' The employees outside the selection stay open.' : ''));
        } elseif (post('_action') === 'reopen') {
            $r = att_reopen($pid, $f, $ids ?: null);
            flash('success', sprintf('%d attendance transaction(s) re-opened for the attendance side.%s', $r['reopened'],
                $r['run'] ? ' Payroll run ' . $r['run'] . ' was computed with them: recompute it after closing again.' : ''));
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('att_close', array_merge(array_filter(array_merge(['cutoff_id' => $pid], $f)), back_params()));
}

page_header('Attendance Transaction Closing', [
    'subtitle' => 'Close the attendance of a period so the pay master can process the payroll. Summarized and manually encoded transactions close the same way.',
    'actions' => $period ? '<a class="btn" href="' . h(url('att_summary2', $keep)) . '">Attendance Summary Entry II</a>'
        . '<a class="btn" href="' . h(url('pay_trans2', $keep)) . '">Payroll Transaction II</a>' : '',
]);
echo pt_filter_bar('att_close', $period, $filters);
// v19: a filter changed here applies at once, so Close attendance always works on the companies / branches shown
// (v20: a list of one, two or more values applies when it closes)
echo '<script>document.querySelectorAll("form.filters select").forEach(function (s) { s.addEventListener("change", function () { s.form.submit(); }); });'
    . 'document.querySelectorAll("form.filters").forEach(function (f) { f.setAttribute("data-mpick-submit", ""); });</script>';
if (!$period) {
    echo '<div class="card"><div class="empty-state"><h3>' . (pt_open_only_route() ? 'No open pay period' : 'No pay period yet') . '</h3><p class="muted">' . (pt_open_only_route() ? 'Every period is closed and paid. Define the next period in Period Parameters.' : '') . '</p><a class="btn btn-primary" href="' . url('periods') . '">Period Parameters</a></div></div>';
    page_footer();
    return;
}
$scope = att_close_scope($period, $filters);
$cnt = att_close_counts($scope);
$prog = att_close_progress((int)$period['id']);
$locked = $period['status'] === 'locked';
$branchName = multi_pick_text(pairs("SELECT id, CONCAT(COALESCE(branch_code, code), ' — ', name) FROM branches"), $filters['branch_id'], 'All companies');
// the selection in words, for the confirmations: what is closed and what stays open
$selWords = [$branchName];
if ($filters['org_unit_id']) {
    $selWords[] = multi_pick_text(pairs('SELECT id, name FROM org_units'), $filters['org_unit_id'], '');
}
if ($filters['employee_group_id']) {
    $selWords[] = (count($filters['employee_group_id']) > 1 ? 'groups ' : 'group ') . multi_pick_text(pairs("SELECT id, CONCAT(code, ' ', name) FROM emp_groups"), $filters['employee_group_id'], '');
}
if ($filters['employee_type_id']) {
    $selWords[] = 'type ' . (string)val("SELECT CONCAT(code, ' ', name) FROM emp_types WHERE id = ?", [(int)$filters['employee_type_id']]);
}
if ($filters['q'] !== '') {
    $selWords[] = '"' . $filters['q'] . '"';
}
$selText = implode(', ', $selWords);
$partial = $filters['branch_id'] || $filters['org_unit_id'] || $filters['employee_group_id'] || $filters['employee_type_id'] || $filters['q'] !== '';
$closers = pairs('SELECT id, full_name FROM users');
?>
<div class="stats">
  <?= stat_card('Employees in selection', number_format($cnt['employees']), h($branchName), 'info') ?>
  <?= stat_card('Open transactions', number_format($cnt['open']), $cnt['manual'] . ' manually encoded', $cnt['open'] ? 'warn' : 'good') ?>
  <?= stat_card('Closed transactions', number_format($cnt['closed']), 'Period: ' . $prog['closed'] . ' of ' . $prog['total'] . ' closed', $cnt['closed'] ? 'good' : '') ?>
  <?= stat_card('Without transaction', number_format($cnt['missing']), $cnt['exceptions'] ? $cnt['exceptions'] . ' open DTR exception(s)' : 'No open exception', $cnt['missing'] || $cnt['exceptions'] ? 'bad' : '') ?>
</div>

<form method="post" class="close-form">
  <?= csrf_field() ?>
  <input type="hidden" name="cutoff_id" value="<?= (int)$period['id'] ?>">
  <?php foreach (['branch_id', 'employee_group_id', 'employee_type_id', 'org_unit_id', 'q'] as $k): ?>
    <?php if (is_array($filters[$k] ?? null)): foreach ($filters[$k] as $fv): ?><input type="hidden" name="f_<?= $k ?>[]" value="<?= (int)$fv ?>"><?php endforeach; else: ?>
    <input type="hidden" name="f_<?= $k ?>" value="<?= h((string)($filters[$k] ?? '')) ?>"><?php endif; ?>
  <?php endforeach; ?>
  <div class="card close-card">
    <div class="close-head">
      <div>
        <h2><?= h($period['pay_group_code']) ?>: <?= h(pt_period_label($period, false)) ?></h2>
        <p class="muted small">Pay code <?= h($period['pay_group_name']) ?>, period status <?= pt_period_badge($period) ?>. Tick rows to close or re-open only those employees; with no row ticked the whole selection is used.</p>
        <p class="close-scope">Selection: <strong><?= h($selText) ?></strong><?= $partial ? '. Only these employees are closed; the other companies / branches stay open: their biologs can still be posted, their attendance viewed, corrected and summarized.' : '. Every company / branch of the pay code.' ?></p>
      </div>
      <div class="close-actions">
        <?php if ($locked): ?>
          <span class="muted">The payroll of this period is closed and paid.</span>
        <?php else: ?>
          <?php if ($cnt['exceptions']): ?>
            <label class="check"><input type="checkbox" name="reviewed" value="1"> Exceptions reviewed (close as encoded)</label>
          <?php endif; ?>
          <button class="btn btn-primary" name="_action" value="close" data-confirm="<?= h('Close the attendance of ' . $selText . ' (' . $cnt['open'] . ' open transaction(s), or only the ticked rows)? They move to the Payroll Transaction screens and can no longer be changed on the attendance side.' . ($partial ? ' The other companies / branches stay open.' : '')) ?>"<?= $cnt['open'] ? '' : ' disabled' ?>>Close attendance</button>
          <button class="btn btn-danger-outline" name="_action" value="reopen" data-confirm="<?= h('Re-open the closed attendance transactions of ' . $selText . ' (or only the ticked rows)? They leave the Payroll Transaction screens until closed again.') ?>"<?= $cnt['closed'] ? '' : ' disabled' ?>>Re-open</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="card flush">
    <div class="card-head">
      <div><h2>Transactions of the selection</h2><div class="sub"><?= $cnt['transactions'] ?> transaction(s) for <?= $cnt['employees'] ?> employee(s)</div></div>
      <input type="search" class="table-search" data-target="closeTable" placeholder="Find employee…" style="max-width:260px">
    </div>
    <div class="table-wrap table-scroll">
      <table class="table table-compact" id="closeTable">
        <thead><tr><th class="chk"><input type="checkbox" data-check-all="emp_ids[]" aria-label="Select all"></th><th>Employee</th><th>Department</th><th>Co. / Branch</th><th>Source</th>
          <th class="num">Hours work</th><th class="num">Absent</th><th class="num">Late</th><th class="num">OT</th><th class="num">Exceptions</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($scope as $s):
            $e = $s['emp'];
            $t = $s['trans']; ?>
          <tr class="<?= $t && $t['is_closed'] ? 'row-closed' : ($t ? '' : 'row-muted') ?>">
            <td class="chk"><?php if ($t): ?><input type="checkbox" name="emp_ids[]" value="<?= (int)$e['id'] ?>" aria-label="Select <?= h(full_name($e)) ?>"><?php endif; ?></td>
            <td><strong><?= h(full_name($e)) ?></strong><br><small class="muted"><?= h($e['employee_no']) ?></small></td>
            <td class="small"><?= h($e['unit_name'] ?? '—') ?></td>
            <td class="small"><?= h($e['branch_code'] ?? '—') ?></td>
            <td class="small"><?= $t ? ($t['source'] === 'manual' ? 'Manual' : 'Summarized logs') : '—' ?></td>
            <td class="num"><?= $t ? number_format((float)$t['hours_work'], 2) : '' ?></td>
            <td class="num"><?= $t && (float)$t['absent_hrs'] ? number_format((float)$t['absent_hrs'], 2) : '' ?></td>
            <td class="num"><?= $t && (float)$t['late_hrs'] ? number_format((float)$t['late_hrs'], 2) : '' ?></td>
            <td class="num"><?= $t && (float)$t['ot_reg'] ? number_format((float)$t['ot_reg'], 2) : '' ?></td>
            <td class="num"><?= $t && (int)$t['open_exceptions'] ? '<span class="text-red">' . (int)$t['open_exceptions'] . '</span>' : '' ?></td>
            <td class="small"><?php if (!$t): ?><span class="muted">No transaction</span>
              <?php elseif ($t['is_closed']): ?><span class="state-closed">Closed</span><br><span class="muted"><?= h(fmt_dt($t['closed_at'])) ?><?= $t['closed_by'] ? ', ' . h($closers[$t['closed_by']] ?? '') : '' ?></span>
              <?php else: ?><span class="state-open">Open</span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        <?= $scope ? '' : empty_row(11, 'No employee matches these filters.') ?>
        </tbody>
      </table>
    </div>
  </div>
</form>
<?php page_footer();
