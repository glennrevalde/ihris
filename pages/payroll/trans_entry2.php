<?php
/**
 * Payroll / Attendance Transaction II — master list of the period with the detail form below.
 * Routes: pay_trans2, att_summary2.
 */
require __DIR__ . '/_trans_form.php';

$route = (string)($_GET['p'] ?? 'pay_trans2');
$isAms = $route === 'att_summary2';
$side = pt_side($route);
$filters = pt_filters();
$period = pt_default_period((int)get('cutoff_id') ?: null);
$editable = pt_period_editable($period, $side);
$employees = $period ? pt_period_employees($period, $filters) : [];
$closedMap = $period ? pairs('SELECT employee_id, is_closed FROM attendance_summaries WHERE cutoff_id = ?', [$period['id']]) : [];
if ($side === 'pay') {
    // only closed attendance transactions appear in the Payroll Transaction
    $employees = array_values(array_filter($employees, fn($e) => !empty($closedMap[$e['id']])));
}
$empId = (int)get('emp', 0);
$keep = array_filter(array_merge(['p' => $route, 'cutoff_id' => $period['id'] ?? null], $filters), fn($v) => $v !== null && $v !== '');
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
        if ($action === 'close_all' || $action === 'reopen_all') {
            // Close Attendance Entry: every transaction listed with the current filters (summarized or manual)
            if ($side !== 'att' || !$p) {
                throw new RuntimeException('Attendance is closed on the attendance side.');
            }
            if ($action === 'close_all') {
                $r = att_close($pid, $filters, (bool)post('reviewed'));
                flash('success', $r['closed'] . ' attendance transaction(s) closed; they now appear in the Payroll Transaction.' . ($r['missing'] ? ' ' . $r['missing'] . ' employee(s) have no transaction.' : ''));
            } else {
                $r = att_reopen($pid, $filters);
                flash('success', $r['reopened'] . ' attendance transaction(s) re-opened.');
            }
            redirect($route, array_merge($keep, ['cutoff_id' => $pid]));
        }
        if ($action === 'process') {
            if (!$p || !pt_period_editable($p) || $side !== 'att') {
                throw new RuntimeException('This period is closed for processing.');
            }
            $st = summarize_cutoff($pid, false);
            flash('success', sprintf('Attendance processed: %d transaction(s) generated, %d manually encoded transaction(s) kept.%s',
                $st['employees'], $st['manual_kept'], $st['open_exceptions'] ? ' ' . $st['open_exceptions'] . ' attendance exception(s) still open.' : ''));
            redirect($route, array_merge($keep, ['cutoff_id' => $pid]));
        }
        $rowNow = $p ? pt_get($pid, $eid) : null;
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
            summarize_cutoff($pid, true, [$eid]);
            flash('success', 'Transaction re-generated from the daily time records.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect($route, array_merge($keep, ['emp' => $eid, 'cutoff_id' => $pid]));
}

page_header($isAms ? 'Attendance Summary Entry II' : 'Payroll Transaction II', [
    'subtitle' => 'Period master list with the full transaction form of the selected employee. Use it to review everyone at a glance while encoding.',
    'actions' => '<a class="btn" href="' . url($isAms ? 'att_summary' : 'pay_trans', $keep) . '">Single entry</a>'
        . '<a class="btn" href="' . url($isAms ? 'att_batch' : 'pay_batch', $keep) . '">Batch entry</a>'
        . ($period && $isAms ? '<a class="btn" href="' . h(url_back('att_close', array_diff_key($keep, ['p' => 1]))) . '">Attendance closing</a>' : ''),
]);
echo dimp_panel($dimp);
echo pt_filter_bar($route, $period, $filters, [], '#transTable');
$openCount = $period ? (int)val('SELECT COUNT(*) FROM attendance_summaries WHERE cutoff_id = ? AND is_closed = 0', [$period['id']]) : 0;
if ($period && $side === 'pay' && $openCount) {
    echo '<div class="callout warn">Only closed attendance transactions are listed here. ' . $openCount . ' transaction(s) of this period are still open on the attendance side: '
        . '<a href="' . h(url('att_close', ['cutoff_id' => $period['id']])) . '">close them in Attendance Transaction Closing</a>.</div>';
}
if (!$period) {
    echo '<div class="card"><div class="empty-state"><h3>' . (pt_open_only_route() ? 'No open pay period' : 'No pay period yet') . '</h3><p class="muted">' . (pt_open_only_route() ? 'Every period is closed and paid. Define the next period in Period Parameters.' : '') . '</p><a class="btn btn-primary" href="' . url('periods') . '">Period Parameters</a></div></div>';
    page_footer();
    return;
}
$trans = [];
foreach (rows('SELECT * FROM attendance_summaries WHERE cutoff_id = ?', [$period['id']]) as $r) {
    $trans[(int)$r['employee_id']] = $r;
}
if (!$empId && $employees) {
    $empId = (int)$employees[0]['id'];
}
$emp = $empId ? row('SELECT e.*, ou.name AS unit_name, b.name AS branch_name FROM employees e LEFT JOIN org_units ou ON ou.id = e.org_unit_id
                     LEFT JOIN branches b ON b.id = e.default_branch_id WHERE e.id = ?', [$empId]) : null;
$t = $emp ? ($trans[$empId] ?? pt_blank()) : pt_blank();
$rowEditable = $emp ? pt_row_editable($period, $trans[$empId] ?? null, $side) : false;
$scopeCnt = $side === 'att' ? att_close_counts(att_close_scope($period, $filters)) : null;
// totals of the listed employees (filters applied; on the payroll side only the closed transactions)
$tot = ['employees' => 0];
foreach ($employees as $e) {
    if (!isset($trans[(int)$e['id']])) {
        continue;
    }
    $tot['employees']++;
    foreach (pt_all_fields() as $f) {
        $tot[$f] = ($tot[$f] ?? 0) + (float)($trans[(int)$e['id']][$f] ?? 0);
    }
}
$cols = ['hours_work' => 'Work', 'absent_hrs' => 'Absent', 'late_hrs' => 'Late', 'undertime_hrs' => 'Under', 'vl_hrs' => 'VL', 'sl_hrs' => 'SL', 'el_hrs' => 'EL',
    'ol_hrs' => 'OL', 'holiday_hrs' => 'Hol', 'ot_reg' => 'OT', 'rd_hrs' => 'Off', 'lh_hrs' => 'Leg', 'sh_hrs' => 'Spe', 'np_reg' => 'NP'];
?>
<div class="stats">
  <?= stat_card('Period', h(pt_period_label($period, false)), h($period['pay_group_name']) . ', ' . pt_period_badge($period), 'info') ?>
  <?php if ($side === 'pay'): ?>
    <?= stat_card('Closed for payroll', number_format(count($employees)), 'of ' . number_format(count($closedMap)) . ' transaction(s) of the period', count($employees) ? 'good' : 'warn') ?>
    <?= stat_card('Still open', number_format(count($closedMap) - count(array_filter($closedMap))), 'on the attendance side', count($closedMap) - count(array_filter($closedMap)) ? 'warn' : 'good') ?>
  <?php else: ?>
    <?= stat_card('Employees', number_format(count($employees)), 'in this pay code') ?>
    <?= stat_card('Transactions encoded', number_format((int)$tot['employees']), count($employees) ? round($tot['employees'] / max(1, count($employees)) * 100) . '% of the pay code' : '', $tot['employees'] ? 'good' : 'warn') ?>
  <?php endif; ?>
  <?= stat_card('Total hours work', number_format((float)($tot['hours_work'] ?? 0), 2), 'Absent ' . number_format((float)($tot['absent_hrs'] ?? 0), 2) . ' hrs, OT ' . number_format((float)($tot['ot_reg'] ?? 0), 2) . ' hrs') ?>
</div>

<div class="card flush">
  <div class="card-head">
    <div><h2>Period transactions</h2><div class="sub">Click a row to load it in the form below.</div></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <input type="search" class="table-search" data-target="transTable" placeholder="Find employee…" style="width:220px">
      <?php if ($editable && $side === 'att'): ?>
      <form method="post" class="inline" data-confirm="Process the attendance of the whole period? Manually encoded and closed transactions are kept.">
        <?= csrf_field() ?><input type="hidden" name="cutoff_id" value="<?= (int)$period['id'] ?>">
        <button class="btn btn-soft" name="_action" value="process">Process attendance</button>
      </form>
      <?php endif; ?>
      <?php if ($side === 'att' && $period['status'] !== 'locked' && $scopeCnt): ?>
      <form method="post" class="inline close-inline" data-confirm="Close the <?= (int)$scopeCnt['open'] ?> open attendance transaction(s) listed with these filters (summarized or manually encoded)? They move to the Payroll Transaction.">
        <?= csrf_field() ?><input type="hidden" name="cutoff_id" value="<?= (int)$period['id'] ?>">
        <?php if ($scopeCnt['exceptions']): ?><label class="check small"><input type="checkbox" name="reviewed" value="1"> Exceptions reviewed</label><?php endif; ?>
        <button class="btn btn-success" name="_action" value="close_all"<?= $scopeCnt['open'] ? '' : ' disabled' ?>>Close attendance entry</button>
        <?php if ($scopeCnt['closed']): ?><button class="btn" name="_action" value="reopen_all" data-confirm="Re-open the closed transactions listed with these filters?">Re-open</button><?php endif; ?>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="table-wrap table-scroll">
    <table class="table table-compact" id="transTable">
      <thead><tr><th>Employee</th><th>Department</th><?php foreach ($cols as $lbl): ?><th class="num"><?= h($lbl) ?></th><?php endforeach; ?><th>Source</th></tr></thead>
      <tbody>
      <?php foreach ($employees as $e):
          $r = $trans[(int)$e['id']] ?? null; ?>
        <tr class="clickable <?= (int)$e['id'] === $empId ? 'row-sel' : '' ?>" onclick="location.href='<?= h(url($route, array_merge($keep, ['emp' => $e['id']]))) ?>'">
          <td><strong><?= h(full_name($e)) ?></strong><br><small class="muted"><?= h($e['employee_no']) ?></small></td>
          <td><?= h($e['unit_name'] ?? '—') ?></td>
          <?php foreach ($cols as $f => $lbl): ?><td class="num"><?= $r ? h(pt_fmt($r[$f] ?? 0, $f)) : '' ?></td><?php endforeach; ?>
          <td><?= $r ? '<span class="badge badge-' . ($r['source'] === 'manual' ? 'blue">Manual' : 'green">AMS') . '</span>' : '<span class="badge badge-gray">None</span>' ?><?= $r && $r['is_closed'] ? '<br><span class="state-closed small">Closed</span>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      <?= $employees ? '' : empty_row(count($cols) + 3, $side === 'pay' ? 'No closed attendance transaction matches these filters.' : 'No employee matches these filters.') ?>
      </tbody>
      <tfoot><tr><td colspan="2">Period total</td><?php foreach ($cols as $f => $lbl): ?><td class="num"><?= h(pt_fmt($tot[$f] ?? 0, $f)) ?></td><?php endforeach; ?><td></td></tr></tfoot>
    </table>
  </div>
</div>

<?php if ($emp): ?>
<form method="post" data-live-total="totalHours2">
  <?= csrf_field() ?>
  <input type="hidden" name="_action" value="save">
  <input type="hidden" name="cutoff_id" value="<?= (int)$period['id'] ?>">
  <input type="hidden" name="employee_id" value="<?= (int)$emp['id'] ?>">
  <div class="card">
    <div class="card-head">
      <div class="person">
        <span class="avatar"><?= h(mb_strtoupper(mb_substr($emp['first_name'], 0, 1) . mb_substr($emp['last_name'], 0, 1))) ?></span>
        <div><div class="person-name"><?= h(full_name($emp)) ?></div>
          <div class="person-meta"><?= h($emp['employee_no']) ?>, <?= h($emp['position'] ?: '—') ?>, <?= h($emp['unit_name'] ?: '—') ?></div></div>
      </div>
      <div style="text-align:right"><div class="muted small">Total hours encoded</div><div style="font-size:1.4rem;font-weight:750" id="totalHours2">0.00</div></div>
    </div>
    <?= pt_rate_chips($emp) ?>
    <?php if (!$rowEditable): ?><div class="callout warn" style="margin-top:12px"><?= h(pt_locked_reason($period, $trans[$empId] ?? null, $side)) ?></div><?php endif; ?>
    <div style="margin-top:14px"><?= pt_form_body($t, !$rowEditable, $emp, $period ? pt_perfect_hours($period, $emp) : null) ?></div>
    <fieldset class="box"><legend>Remarks</legend>
      <textarea name="remarks" rows="2"<?= $rowEditable ? '' : ' readonly' ?>><?= h((string)($t['remarks'] ?? '')) ?></textarea>
    </fieldset>
  </div>
  <div class="toolbar">
    <?php if ($rowEditable): ?>
      <button class="btn btn-primary" name="_action" value="save">Save</button>
      <?php if ($side === 'att'): ?>
        <button class="btn btn-danger-outline" name="_action" value="delete" data-confirm="Delete this transaction?"<?= isset($trans[$empId]) ? '' : ' disabled' ?>>Delete</button>
        <button class="btn" name="_action" value="recalc" data-confirm="Recalculate this employee from the daily time records?">ReCalc logs</button>
      <?php endif; ?>
    <?php endif; ?>
    <span class="sep"></span>
    <a class="btn" href="<?= h(url_back('dtr', ['employee_id' => $emp['id'], 'from' => $period['period_start'], 'to' => $period['period_end']])) ?>">Log details</a>
    <a class="btn" href="<?= h(url('ot_requests', ['employee_id' => $emp['id']])) ?>">OT entry</a>
    <a class="btn" href="<?= h(url('att_leave_entry', ['emp' => $emp['id'], 'cutoff_id' => $period['id']])) ?>">Leave entry</a>
    <a class="btn" href="<?= h(url('schedules', ['employee_id' => $emp['id']])) ?>">Shift entry</a>
    <a class="btn" href="<?= h(url_back('trans_multiloc', ['cutoff_id' => $period['id'], 'emp' => $emp['id'], 'side' => $side])) ?>">Multi location</a>
    <span class="spacer"></span>
    <span class="muted small">Total holiday hrs. in period: <strong><?= h(number_format((float)($t['holiday_hrs'] ?? 0), 2)) ?></strong></span>
  </div>
</form>
<?php endif; ?>
<?php page_footer();
