<?php
/**
 * Process Attendance (M.A.D.A.S. AMS > Process Attendance): build the attendance transactions of a
 * period from the logs of the biometric devices, the web time clock and the imported files, for one
 * employee, several, or all of the selection (Employee, Company Code, Department, Employee Group,
 * Employee Type, Pay Code, Period).
 *
 * Replace Manual Entry: when ticked, a transaction encoded by hand (Attendance Summary Entry, CSV
 * import) is replaced by the newly processed attendance; otherwise it is kept. A transaction already
 * closed for payroll is never touched (re-open it in Attendance Transaction Closing first). Only open
 * payroll periods are offered.
 */
$pgId = (int)get('pay_group_id', 0) ?: (int)val("SELECT pay_group_id FROM attendance_cutoffs WHERE status IN ('open','summarized','approved') ORDER BY period_end DESC LIMIT 1");
$periods = [];
foreach (rows("SELECT c.*, pg.code AS pay_group_code FROM attendance_cutoffs c JOIN pay_groups pg ON pg.id = c.pay_group_id
               WHERE c.status IN ('open','summarized','approved')" . ($pgId ? ' AND c.pay_group_id = ' . $pgId : '') . ' ORDER BY c.period_end DESC LIMIT 40') as $c) {
    $periods[(int)$c['id']] = fmt_date($c['period_start'], 'm/d/y') . ' - ' . fmt_date($c['period_end'], 'm/d/y') . ($c['description'] ? ' (' . $c['description'] . ')' : '') . ($c['status'] === 'summarized' ? ', processed before' : ($c['status'] === 'approved' ? ', closed (the open employees can still be processed)' : ''));
}
$cid = (int)get('cutoff_id', 0);
if (!isset($periods[$cid])) {
    $cid = (int)(array_key_first($periods) ?? 0);
}
$period = $cid ? pt_period($cid) : null;
$f = ['branch_id' => (int)get('branch_id', 0) ?: null, 'org_unit_id' => (int)get('org_unit_id', 0) ?: null, 'employee_group_id' => (int)get('employee_group_id', 0) ?: null,
    'employee_type_id' => (int)get('employee_type_id', 0) ?: null, 'q' => ''];
$keep = array_filter(['pay_group_id' => $pgId ?: null, 'cutoff_id' => $cid ?: null] + $f);

if (is_post()) {
    $pid = (int)post('cutoff_id');
    try {
        $p = pt_period($pid);
        if (!$p || !in_array($p['status'], ['open', 'summarized', 'approved'], true)) {
            throw new RuntimeException('Choose an open payroll period.');
        }
        $pf = [];
        foreach (['branch_id', 'org_unit_id', 'employee_group_id', 'employee_type_id'] as $k) {
            $pf[$k] = (int)post('f_' . $k) ?: null;
        }
        $all = array_map(fn($e) => (int)$e['id'], pt_period_employees($p, $pf));
        $scope = post('emp_scope') === 'select' ? 'select' : 'all';
        $ids = $scope === 'select' ? array_values(array_intersect($all, array_map('intval', (array)post('emp_ids', [])))) : $all;
        if (!$ids) {
            throw new RuntimeException($scope === 'select' ? 'Tick the employee(s) to process, or choose All employees of the selection.' : 'No employee matches the selection.');
        }
        $replace = post('replace_manual') === '1';
        $before = pairs('SELECT employee_id, CONCAT(source, IF(is_closed = 1, \':closed\', \'\')) FROM attendance_summaries WHERE cutoff_id = ? AND employee_id IN (' . in_list($ids) . ')', array_merge([$pid], $ids));
        $st = summarize_cutoff($pid, $replace, $ids);
        $manualReplaced = $replace ? count(array_filter($before, fn($s) => $s === 'manual')) : 0;
        $closed = count(array_filter($before, fn($s) => strpos($s, ':closed') !== false));
        audit('ams', 'process_attendance', 'attendance_cutoffs', $pid, ['employees' => count($ids), 'scope' => $scope, 'replace_manual' => $replace ? 1 : 0,
            'processed' => $st['employees'], 'manual_replaced' => $manualReplaced, 'kept' => $st['manual_kept']]);
        flash('success', sprintf('Attendance processed for %d employee(s)%s.%s%s', $st['employees'],
            $replace ? ', ' . $manualReplaced . ' manual entr' . ($manualReplaced === 1 ? 'y' : 'ies') . ' replaced by the processed attendance' : '',
            !$replace && $st['manual_kept'] - $closed > 0 ? ' ' . ($st['manual_kept'] - $closed) . ' manual entr' . ($st['manual_kept'] - $closed === 1 ? 'y was' : 'ies were') . ' kept (tick Replace Manual Entry to replace them).' : '',
            $closed ? ' ' . $closed . ' transaction(s) closed for payroll were not touched.' : ''));
        if ($st['open_exceptions']) {
            flash('warning', $st['open_exceptions'] . ' open attendance exception(s) in the period need a decision before closing.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('att_process', array_filter(['pay_group_id' => (int)post('pay_group_id') ?: null, 'cutoff_id' => $pid ?: null, 'branch_id' => (int)post('f_branch_id') ?: null,
        'org_unit_id' => (int)post('f_org_unit_id') ?: null, 'employee_group_id' => (int)post('f_employee_group_id') ?: null, 'employee_type_id' => (int)post('f_employee_type_id') ?: null]));
}

$emps = $period ? pt_period_employees($period, $f) : [];
$trans = $period ? pairs('SELECT employee_id, CONCAT(source, IF(is_closed = 1, \':closed\', \'\')) FROM attendance_summaries WHERE cutoff_id = ?', [$cid]) : [];
$logs = $period && $emps ? pairs('SELECT employee_id, COUNT(*) FROM attendance_logs WHERE log_time BETWEEN ? AND ? AND employee_id IN (' . in_list(array_column($emps, 'id')) . ') GROUP BY employee_id',
    array_merge([$period['period_start'] . ' 00:00:00', $period['period_end'] . ' 23:59:59'], array_map('intval', array_column($emps, 'id')))) : [];
$nManual = count(array_filter($emps, fn($e) => ($trans[$e['id']] ?? '') === 'manual'));

page_header('Process Attendance', [
    'subtitle' => 'Process the logs of the biometric devices, the web time clock and the imported files into the attendance transactions of a period, for one employee, several or all.',
    'actions' => '<a class="btn" href="' . h(url('att_generate', array_filter(['cutoff_id' => $cid ?: null]))) . '">Auto-Generate Transactions</a><a class="btn" href="' . h(url('att_close', array_filter(['cutoff_id' => $cid ?: null]))) . '">Attendance closing</a>',
]);
?>
<form method="get" class="card filters">
  <input type="hidden" name="p" value="att_process">
  <label>Pay Code<select name="pay_group_id" data-autosubmit><?= options(pairs("SELECT id, CONCAT(code, ' — ', name) FROM pay_groups ORDER BY code"), $pgId ?: null, 'All pay codes') ?></select></label>
  <label class="grow">Period<select name="cutoff_id" data-autosubmit><?= options($periods, $cid ?: null, $periods ? null : 'No open payroll period') ?></select></label>
  <label>Company Code<select name="branch_id"><?= options(pairs("SELECT id, CONCAT(COALESCE(branch_code, code), ' — ', name) FROM branches WHERE is_active = 1 ORDER BY company_code, branch_code, code"), $f['branch_id'], 'All companies') ?></select></label>
  <label>Department<select name="org_unit_id"><?= options(org_unit_options(), $f['org_unit_id'], 'All departments') ?></select></label>
  <label>Employee Group<select name="employee_group_id"><?= options(emp_group_options(), $f['employee_group_id'], 'All groups') ?></select></label>
  <label>Employee Type<select name="employee_type_id"><?= options(pairs("SELECT id, CONCAT(code, ' — ', name) FROM emp_types ORDER BY code"), $f['employee_type_id'], 'All types') ?></select></label>
  <button class="btn btn-primary">Apply</button>
</form>

<?php if (!$period): ?>
  <div class="card"><div class="empty-state"><h3>No open payroll period</h3><p class="muted">Only open periods can be processed. Paid periods are closed in Period Closing.</p><a class="btn" href="<?= url('periods') ?>">Period Parameters</a></div></div>
<?php page_footer(); return; endif; ?>

<form method="post" id="procForm">
  <?= csrf_field() ?>
  <input type="hidden" name="cutoff_id" value="<?= (int)$cid ?>"><input type="hidden" name="pay_group_id" value="<?= (int)$pgId ?>">
  <?php foreach (['branch_id', 'org_unit_id', 'employee_group_id', 'employee_type_id'] as $k): ?><input type="hidden" name="f_<?= $k ?>" value="<?= h((string)($f[$k] ?? '')) ?>"><?php endforeach; ?>
  <div class="grid grid-2-1">
    <div class="card flush">
      <div class="card-head pad">
        <div><h2>Employee ID</h2><div class="sub"><?= count($emps) ?> employee(s) in the selection, <?= h($period['pay_group_code']) ?> <?= h(pt_period_label($period, false)) ?></div></div>
        <input type="search" class="table-search" data-target="procTbl" placeholder="Find employee…" style="max-width:240px">
      </div>
      <div class="pad-x">
        <div class="pick-scope">
          <label class="check"><input type="radio" name="emp_scope" value="all" checked> All employees of the selection</label>
          <label class="check"><input type="radio" name="emp_scope" value="select"> Only the employees ticked (one or many)</label>
        </div>
        <div class="emp-pick" data-emp-pick>
          <table class="table table-compact" id="procTbl">
            <thead><tr><th class="chk"><input type="checkbox" data-check-all="emp_ids[]" aria-label="Tick all"></th><th>Employee</th><th>Department</th><th class="num">Logs</th><th>Transaction</th></tr></thead>
            <tbody>
            <?php foreach ($emps as $e):
                $t = $trans[$e['id']] ?? '';
                $closedT = strpos($t, ':closed') !== false; ?>
              <tr class="<?= $closedT ? 'row-muted' : '' ?>">
                <td class="chk"><input type="checkbox" name="emp_ids[]" value="<?= (int)$e['id'] ?>" aria-label="Tick <?= h(full_name($e)) ?>"<?= $closedT ? ' title="Closed for payroll: not processed"' : '' ?>></td>
                <td><strong><?= h(full_name($e)) ?></strong><br><small class="muted"><?= h($e['employee_no']) ?><?= $e['biometric_id'] ? ', bio ' . h($e['biometric_id']) : '' ?></small></td>
                <td class="small"><?= h($e['unit_name'] ?? '—') ?></td>
                <td class="num"><?= (int)($logs[$e['id']] ?? 0) ?: '<span class="muted">0</span>' ?></td>
                <td class="small"><?= $t === '' ? '<span class="muted">None yet</span>' : ($closedT ? '<span class="state-closed">Closed</span>' : ($t === 'manual' ? '<span class="text-amber">Manual entry</span>' : 'Processed')) ?></td>
              </tr>
            <?php endforeach; ?>
            <?= $emps ? '' : empty_row(5, 'No employee matches the selection.') ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div>
      <div class="card">
        <h2>Process</h2>
        <dl class="kv">
          <dt>Pay code</dt><dd><?= h($period['pay_group_code'] . ' — ' . $period['pay_group_name']) ?></dd>
          <dt>Period</dt><dd><?= h(fmt_date($period['period_start'])) ?> – <?= h(fmt_date($period['period_end'])) ?></dd>
          <dt>Manual entries</dt><dd><?= $nManual ?> in the selection</dd>
        </dl>
        <div class="replace-box">
          <label class="check"><input type="checkbox" name="replace_manual" value="1"> Replace Manual Entry</label>
          <p>Ticked, every manually encoded transaction of the employees processed is replaced by the attendance processed from the biometrics or the other devices. Unticked, manual entries are kept.</p>
        </div>
        <div class="process-bar" aria-hidden="true"><span id="procBar"></span></div>
        <div class="form-actions" style="margin-top:0">
          <a class="btn" href="<?= url('dashboard') ?>">Exit</a>
          <button class="btn btn-primary" name="_action" value="process" data-confirm="Process the attendance of the selected employees for this period?">Process</button>
        </div>
        <p class="help">Transactions already closed for payroll are never changed. Daily records are rebuilt from the logs first, then totalled per employee.</p>
      </div>
    </div>
  </div>
</form>
<script>
(function () {
  var f = document.getElementById('procForm'), bar = document.getElementById('procBar');
  if (!f || !bar) return;
  f.addEventListener('submit', function () {
    var w = 8;
    bar.style.width = w + '%';
    var t = setInterval(function () { w = Math.min(92, w + (95 - w) / 9); bar.style.width = w + '%'; }, 250);
    window.addEventListener('pagehide', function () { clearInterval(t); });
  });
})();
</script>
<?php page_footer();
