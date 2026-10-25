<?php
/**
 * Payroll Transaction Setup / Attendance Summary Manual Entry (single employee).
 * Routes: pay_trans (payroll) and att_summary (attendance).
 * The hours encoded here are what the payroll engine pays — they can be generated from the
 * DTR (Auto-Generate / ReCalc) or typed by the timekeeper / payroll master.
 */
require __DIR__ . '/_trans_form.php';

$route = (string)($_GET['p'] ?? 'pay_trans');
$isAms = $route === 'att_summary';
$side = pt_side($route);   // att: open transactions are encoded and closed; pay: closed transactions are processed
$filters = pt_filters();
$period = pt_default_period((int)get('cutoff_id') ?: null);
$employees = $period ? pt_period_employees($period, $filters) : [];
$closedMap = $period ? pairs('SELECT employee_id, is_closed FROM attendance_summaries WHERE cutoff_id = ?', [$period['id']]) : [];
if ($side === 'pay') {
    // only closed attendance transactions appear in the Payroll Transaction
    $employees = array_values(array_filter($employees, fn($e) => !empty($closedMap[$e['id']])));
}
$empId = (int)get('emp', 0);
if ($empId && !array_filter($employees, fn($e) => (int)$e['id'] === $empId)) {
    $emp = row('SELECT e.*, ou.name AS unit_name FROM employees e LEFT JOIN org_units ou ON ou.id = e.org_unit_id WHERE e.id = ?', [$empId]);
    if ($emp && $period && (int)$emp['pay_group_id'] === (int)$period['pay_group_id'] && ($side === 'att' || !empty($closedMap[$emp['id']]))) {
        $employees[] = $emp;
    } else {
        $empId = 0;
    }
}
if (!$empId && $employees) {
    $empId = (int)$employees[0]['id'];
}
$emp = $empId ? row('SELECT e.*, ou.name AS unit_name, b.name AS branch_name FROM employees e LEFT JOIN org_units ou ON ou.id = e.org_unit_id
                     LEFT JOIN branches b ON b.id = e.default_branch_id WHERE e.id = ?', [$empId]) : null;
$keep = array_filter(array_merge(['p' => $route, 'cutoff_id' => $period['id'] ?? null, 'emp' => $empId], $filters), fn($v) => $v !== null && $v !== '');
$keep = array_merge($keep, back_params());

$dimp = dimp_def($isAms ? 'att_batch' : 'pay_trans', null, ['cutoff_id' => $period['id'] ?? null]);   // CSV import facility on this form (app/engine/dataimport.php)
dimp_handle($dimp);
if (is_post()) {
    verify_csrf();
    $action = post('_action');
    $pid = (int)post('cutoff_id');
    $eid = (int)post('employee_id');
    $p = pt_period($pid);
    try {
        $rowNow = $p ? pt_get($pid, $eid) : null;
        if ($action === 'close' || $action === 'reopen') {
            if ($side !== 'att' || !$p) {
                throw new RuntimeException('Attendance is closed and re-opened on the attendance side.');
            }
            if ($action === 'close') {
                att_close($pid, [], true, [$eid]);
                flash('success', 'Attendance entry closed: it now appears in the Payroll Transaction for payroll processing.');
            } else {
                att_reopen($pid, [], [$eid]);
                flash('success', 'Attendance entry re-opened.');
            }
            redirect($route, array_merge($keep, ['emp' => $eid, 'cutoff_id' => $pid]));
        }
        if (!pt_row_editable($p, $rowNow, $side)) {
            throw new RuntimeException(pt_locked_reason($p, $rowNow, $side));
        }
        if ($side === 'pay' && in_array($action, ['delete', 'recalc'], true)) {
            throw new RuntimeException('A closed transaction is not deleted or regenerated from the payroll side. Re-open it in Attendance Transaction Closing first.');
        }
        if ($action === 'save') {
            [$values, $errors] = pt_clean($_POST, $p);
            if ($errors) {
                throw new RuntimeException(implode(' ', $errors));
            }
            $eRow = row('SELECT * FROM employees WHERE id = ?', [$eid]);
            [$values, $adj] = pt_apply_leave_rule($values, $eRow ?: [], $p);
            pt_save($pid, $eid, $values, trim((string)post('remarks')) ?: null, 'manual');
            flash('success', 'Transaction saved.' . ($adj ? ' Hours work was adjusted to ' . number_format($values['hours_work'], 2) . ' (perfect attendance less leaves).' : ''));
        } elseif ($action === 'delete') {
            pt_delete($pid, $eid);
            flash('success', 'Transaction deleted.');
        } elseif ($action === 'recalc') {
            $st = summarize_cutoff($pid, true, [$eid]);
            flash('success', 'Transaction re-generated from the daily time records (' . $st['employees'] . ' employee).');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect($route, array_merge($keep, ['emp' => $eid, 'cutoff_id' => $pid]));
}

$tRow = $period && $emp ? pt_get((int)$period['id'], $empId) : null;
$t = $tRow ?: pt_blank();
$exists = $tRow !== null;
$editable = $emp ? pt_row_editable($period, $tRow, $side) : pt_period_editable($period, $side);
$openCount = $period ? (int)val('SELECT COUNT(*) FROM attendance_summaries WHERE cutoff_id = ? AND is_closed = 0', [$period['id']]) : 0;
$locRows = $exists ? pt_branches((int)$t['id']) : [];
$hpd = $emp ? employee_hours_per_day($emp) : 8;
$perfect = $emp && $period ? pt_perfect_hours($period, $emp) : null;
$totalHours = 0.0;
foreach (pt_hour_fields() as $f) {
    $totalHours += (float)($t[$f] ?? 0);
}

$actions = '';
if ($period) {
    $actions .= '<a class="btn" href="' . url($isAms ? 'att_summary2' : 'pay_trans2', $keep) . '">Transaction II</a>'
        . '<a class="btn" href="' . url($isAms ? 'att_batch' : 'pay_batch', $keep) . '">Batch entry</a>'
        . '<a class="btn" href="' . url('periods', ['pay_group_id' => $period['pay_group_id']]) . '">Period parameters</a>'
        . ($isAms ? '<a class="btn" href="' . h(url_back('att_close', array_diff_key($keep, ['p' => 1, 'emp' => 1]))) . '">Attendance closing</a>' : '');
}
page_header($isAms ? 'Attendance Summary Entry' : 'Payroll Transaction Setup', [
    'subtitle' => 'Individual summary attendance record for one pay period. It can be imported from the Attendance Monitoring System or encoded directly — the payroll engine pays exactly these hours.',
    'actions' => $actions,
]);
echo dimp_panel($dimp);
echo pt_filter_bar($route, $period, $filters, [], '#empList,#empCount');
if ($period && $side === 'pay' && $openCount) {
    echo '<div class="callout warn">Only closed attendance transactions are listed here. ' . $openCount . ' transaction(s) of this period are still open on the attendance side: '
        . '<a href="' . h(url('att_close', ['cutoff_id' => $period['id']])) . '">close them in Attendance Transaction Closing</a>.</div>';
}

if (!$period) {
    echo '<div class="card"><div class="empty-state"><h3>' . (pt_open_only_route() ? 'No open pay period' : 'No pay period yet') . '</h3><p class="muted">' . (pt_open_only_route() ? 'Every period is closed and paid. Define the next period in Period Parameters.' : '') . '</p><p class="muted">Create the payroll periods first.</p><a class="btn btn-primary" href="' . url('periods') . '">Period Parameters</a></div></div>';
    page_footer();
    return;
}
?>
<div class="split">
  <div class="card split-list">
    <div class="list-head">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
        <strong><?= h($period['pay_group_code']) ?>: <?= h(pt_period_label($period, false)) ?></strong>
        <?= pt_period_badge($period) ?>
      </div>
      <input type="search" data-filter-list="empList" placeholder="Type to find an employee" aria-label="Find an employee in the list">
    </div>
    <div class="list-nav" id="empList">
      <?php
      $withTrans = pairs('SELECT employee_id, source FROM attendance_summaries WHERE cutoff_id = ?', [$period['id']]);
      foreach ($employees as $e):
          $has = $withTrans[$e['id']] ?? null;
          $isClosed = !empty($closedMap[$e['id']]); ?>
        <a class="<?= (int)$e['id'] === $empId ? 'active' : '' ?>" href="<?= url($route, array_merge($keep, ['emp' => $e['id']])) ?>" data-search="<?= h((string)($e['biometric_id'] ?? '')) ?>">
          <span class="ln-dot <?= $isClosed ? 'closed' : ($has === 'manual' ? 'manual' : ($has ? 'on' : '')) ?>" title="<?= $isClosed ? 'Closed' : ($has ? h('Transaction encoded (' . $has . ')') : 'No transaction yet') ?>"></span>
          <span class="ln-main"><strong><?= h(full_name($e)) ?></strong><small><?= h($e['employee_no']) ?>, <?= h($e['unit_name'] ?? '—') ?><?= $isClosed && $side === 'att' ? ', closed' : '' ?></small></span>
        </a>
      <?php endforeach; ?>
      <?php if (!$employees): ?><p class="muted" style="padding:12px"><?= $side === 'pay' ? 'No closed attendance transaction matches these filters.' : 'No employee matches these filters.' ?></p><?php endif; ?>
    </div>
    <div class="list-count" id="empCount"><?= count($employees) ?> employee(s), <?= count($withTrans) ?> with transactions</div>
  </div>

  <div>
    <?php if (!$emp): ?>
      <div class="card"><div class="empty-state"><h3>Select an employee</h3><p class="muted">Pick an employee on the left to encode the period transaction.</p></div></div>
    <?php else: ?>
    <form method="post" data-live-total="totalHours">
      <?= csrf_field() ?>
      <input type="hidden" name="_action" value="save">
      <input type="hidden" name="cutoff_id" value="<?= (int)$period['id'] ?>">
      <input type="hidden" name="employee_id" value="<?= (int)$emp['id'] ?>">
      <div class="card">
        <div class="card-head">
          <div class="person">
            <span class="avatar"><?= h(mb_strtoupper(mb_substr($emp['first_name'], 0, 1) . mb_substr($emp['last_name'], 0, 1))) ?></span>
            <div>
              <div class="person-name"><?= h(full_name($emp)) ?></div>
              <div class="person-meta"><?= h($emp['employee_no']) ?>, <?= h($emp['position'] ?: '—') ?>, <?= h($emp['unit_name'] ?: '—') ?>, <?= h($emp['branch_name'] ?: '—') ?></div>
            </div>
          </div>
          <div style="text-align:right">
            <div class="muted small">Total hours encoded</div>
            <div style="font-size:1.4rem;font-weight:750" id="totalHours">0.00</div>
          </div>
        </div>
        <?= pt_rate_chips($emp) ?>
        <?php if (!$editable): ?>
          <div class="callout warn" style="margin-top:12px"><?= h(pt_locked_reason($period, $tRow, $side)) ?>
            <?php if (!pt_period_editable($period, $side)): ?> <a href="<?= h(url('periods', ['pay_group_id' => $period['pay_group_id']])) ?>">Period Parameters</a><?php endif; ?></div>
        <?php elseif ($side === 'pay'): ?>
          <div class="callout good" style="margin-top:12px">Closed attendance transaction<?= $tRow['closed_at'] ? ' (closed ' . h(fmt_dt($tRow['closed_at'])) . ')' : '' ?>: this is what the payroll run pays.</div>
        <?php elseif ($exists && $t['source'] === 'ams'): ?>
          <div class="callout" style="margin-top:12px">Generated from the daily time records on <?= h(fmt_dt($t['updated_at'])) ?>. Editing it here marks the transaction as manually encoded, and Auto-Generate will keep your figures.</div>
        <?php endif; ?>
      </div>

      <div class="card">
        <?= pt_form_body($t, !$editable, $emp, $perfect) ?>
        <fieldset class="box">
          <legend>Remarks</legend>
          <textarea name="remarks" rows="2" placeholder="Reason for the manual figures, reference of the approval, etc."<?= $editable ? '' : ' readonly' ?>><?= h((string)($t['remarks'] ?? '')) ?></textarea>
        </fieldset>
        <?php if ($locRows): ?>
          <div class="callout good">Multi-location: the hours above are the total of <?= count($locRows) ?> work location(s). <a href="<?= h(url_back('trans_multiloc', ['cutoff_id' => $period['id'], 'emp' => $emp['id'], 'side' => $side])) ?>">Open the multi-location entry</a>.</div>
        <?php endif; ?>
      </div>

      <div class="toolbar">
        <?php if ($editable): ?>
          <button class="btn btn-primary" name="_action" value="save">Save transaction</button>
          <?php if ($side === 'att'): ?>
            <button class="btn btn-danger-outline" name="_action" value="delete" data-confirm="Delete the transaction of this employee for this period?"<?= $exists ? '' : ' disabled' ?>>Delete</button>
            <button class="btn" name="_action" value="recalc" data-confirm="Replace the encoded hours with the figures computed from the daily time records?">ReCalc from DTR</button>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($side === 'att' && $exists && $period['status'] !== 'locked'): ?>
          <?php if (empty($tRow['is_closed'])): ?>
            <button class="btn btn-success" name="_action" value="close" formnovalidate data-confirm="Close this attendance entry for payroll processing? Save your changes first.<?= (int)$tRow['open_exceptions'] ? ' It still has ' . (int)$tRow['open_exceptions'] . ' open DTR exception(s); it is closed as encoded.' : '' ?>">Close attendance entry</button>
          <?php else: ?>
            <button class="btn" name="_action" value="reopen" formnovalidate data-confirm="Re-open this attendance entry? It leaves the Payroll Transaction until closed again.">Re-open entry</button>
          <?php endif; ?>
        <?php endif; ?>
        <span class="sep"></span>
        <a class="btn" href="<?= h(url_back('dtr', ['employee_id' => $emp['id'], 'from' => $period['period_start'], 'to' => $period['period_end']])) ?>">Log details (DTR)</a>
        <a class="btn" href="<?= h(url('att_entry', array_merge($keep, ['emp' => $emp['id']]))) ?>">Attendance entry</a>
        <a class="btn" href="<?= h(url_back('trans_multiloc', ['cutoff_id' => $period['id'], 'emp' => $emp['id'], 'side' => $side])) ?>">Multi location</a>
        <a class="btn" href="<?= h(url('att_leave_entry', ['emp' => $emp['id'], 'cutoff_id' => $period['id']])) ?>">Leave entry</a>
        <a class="btn" href="<?= h(url('emp_transactions', ['emp' => $emp['id'], 'cutoff_id' => $period['id']])) ?>">Employee's transactions</a>
        <span class="spacer"></span>
        <span class="muted small">Hourly rate × hours: the engine applies the Comp. Rates percentages of each category.</span>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php page_footer();
