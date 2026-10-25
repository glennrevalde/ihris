<?php
/**
 * Attendance Entry (Detail Attendance Log Entry) — the day-by-day logs of one employee for a
 * period. The log columns follow the shift code of the employee: 2 logs (Time in, Time out),
 * 4 logs (with one break) or 6 logs (with two breaks), then the computed hours of the day,
 * the shift and the log status. Everything can be corrected by hand.
 * v16: a shift with pre-arranged overtime (Overtime In / Overtime Out) adds the OT In and OT Out log
 * columns and the Sched OT hours credited automatically.
 */
require __DIR__ . '/../payroll/_trans_form.php';

$filters = pt_filters();
$period = pt_default_period((int)get('cutoff_id') ?: null);
$from = (string)get('from', $period['period_start'] ?? date('Y-m-01'));
$to = (string)get('to', $period['period_end'] ?? date('Y-m-t'));
if ($to < $from) {
    $to = $from;
}
$employees = $period ? pt_period_employees($period, $filters) : rows('SELECT e.*, ou.name AS unit_name FROM employees e LEFT JOIN org_units ou ON ou.id = e.org_unit_id
                                WHERE e.status = "active"' . user_scope_sql('e') . ' ORDER BY e.last_name LIMIT 300');
$empId = (int)get('emp', 0);
if (!$empId && $employees) {
    $empId = (int)$employees[0]['id'];
}
$emp = $empId ? att_employee($empId) : null;
// Log columns follow the shift code of the employee (2, 4 or 6 logs per day); ?logs= overrides it for the session
$empShift = $emp ? shift_row($emp['shift_id'] ? (int)$emp['shift_id'] : null) : null;
$logsParam = in_array((int)get('logs'), [2, 4, 6], true) ? (int)get('logs') : 0;
$logCount = $logsParam ?: shift_logs_per_day($empShift);
$sixLogs = $logCount === 6;
$logFields = $logCount === 6 ? ['time_in', 'break_out', 'break_in', 'break_out2', 'break_in2', 'time_out']
    : ($logCount === 2 ? ['time_in', 'time_out'] : ['time_in', 'break_out', 'break_in', 'time_out']);
$logLabels = ['time_in' => 'Time In', 'break_out' => 'Brk Out 1', 'break_in' => 'Brk In 1', 'break_out2' => 'Brk Out 2', 'break_in2' => 'Brk In 2', 'time_out' => 'Time Out',
    'ot_in' => 'OT In', 'ot_out' => 'OT Out'];
$allLogFields = ['time_in', 'break_out', 'break_in', 'break_out2', 'break_in2', 'time_out', 'ot_in', 'ot_out'];
$keep = array_filter(array_merge(['p' => 'att_entry', 'cutoff_id' => $period['id'] ?? null, 'emp' => $empId, 'from' => $from, 'to' => $to, 'logs' => $logsParam ?: null], $filters),
    fn($v) => $v !== null && $v !== '');
$keep = array_merge($keep, back_params());

if (is_post()) {
    verify_csrf();
    $action = post('_action');
    $eid = (int)post('employee_id');
    $e = $eid ? att_employee($eid) : null;
    try {
        if (!$e) {
            throw new RuntimeException('Select an employee.');
        }
        $dates = (array)post('dates', []);
        $checked = (array)post('pick', []);
        if ($action === 'save') {
            $n = 0;
            foreach ($dates as $d) {
                if (dtr_locked($e, $d)) {
                    continue;
                }
                $old = row('SELECT * FROM dtr WHERE employee_id = ? AND work_date = ?', [$eid, $d]);
                $oldTimes = [];
                foreach ($allLogFields as $f) {
                    $oldTimes[$f] = $old && $old[$f] ? date('H:i', strtotime($old[$f])) : null;
                }
                $vals = [];
                $posted = false;
                foreach ($oldTimes as $f => $oldVal) {
                    if (!array_key_exists($f . '_' . $d, $_POST)) {
                        $vals[$f] = $oldVal;       // field not on the form (4-log mode) or row untouched
                        continue;
                    }
                    $posted = true;
                    $t = trim((string)post($f . '_' . $d, ''));
                    $vals[$f] = $t === '' ? null : $t;
                }
                if (!$posted) {
                    continue;   // the row was not touched (only changed rows are sent)
                }
                if ($oldTimes == $vals) {
                    continue;   // unchanged
                }
                // build the date-times; a time earlier than the time-in belongs to the next day (night shift)
                $base = strtotime($d . ' 00:00:00');
                $anchor = null;
                $dt = [];
                foreach ($allLogFields as $f) {
                    if ($vals[$f] === null) {
                        $dt[$f] = null;
                        continue;
                    }
                    [$hh, $mm] = array_pad(explode(':', $vals[$f]), 2, '0');
                    $ts = $base + ((int)$hh) * 3600 + ((int)$mm) * 60;
                    if ($anchor !== null && $ts < $anchor) {
                        $ts += 86400;
                    }
                    $anchor = $ts;
                    $dt[$f] = date('Y-m-d H:i:s', $ts);
                }
                $data = array_merge($dt, ['manual_override' => 1, 'updated_by' => user_id(), 'updated_at' => now(), 'remarks' => trim((string)post('remarks')) ?: ($old['remarks'] ?? null)]);
                if ($old) {
                    update('dtr', $data, 'id = ?', [$old['id']]);
                } else {
                    insert('dtr', array_merge($data, ['employee_id' => $eid, 'work_date' => $d, 'source' => 'manual']));
                }
                process_dtr($eid, $d);
                $n++;
            }
            audit('ams', 'attendance_entry', 'dtr', null, ['employee_id' => $eid, 'days' => $n, 'from' => $from, 'to' => $to]);
            flash($n ? 'success' : 'warning', $n ? $n . ' day(s) saved and recomputed.' : 'Nothing changed.');
        } elseif ($action === 'dayoff' || $action === 'workday') {
            foreach ($checked as $d) {
                $ex = row('SELECT * FROM employee_schedules WHERE employee_id = ? AND work_date = ?', [$eid, $d]);
                $data = ['is_rest_day' => $action === 'dayoff' ? 1 : 0];
                if ($ex) {
                    update('employee_schedules', $data, 'id = ?', [$ex['id']]);
                } else {
                    insert('employee_schedules', array_merge($data, ['employee_id' => $eid, 'work_date' => $d]));
                }
                process_dtr($eid, $d);
            }
            audit('ams', 'set_' . $action, 'employee_schedules', null, ['employee_id' => $eid, 'days' => count($checked)]);
            flash('success', count($checked) . ' day(s) set as ' . ($action === 'dayoff' ? 'day-off' : 'working day') . '.');
        } elseif ($action === 'shift') {
            $shiftId = (int)post('shift_id') ?: null;
            foreach ($checked as $d) {
                $ex = row('SELECT * FROM employee_schedules WHERE employee_id = ? AND work_date = ?', [$eid, $d]);
                if ($ex) {
                    update('employee_schedules', ['shift_id' => $shiftId], 'id = ?', [$ex['id']]);
                } else {
                    insert('employee_schedules', ['employee_id' => $eid, 'work_date' => $d, 'shift_id' => $shiftId, 'is_rest_day' => null]);   // v18: the day-off follows the shift / the masterfile
                }
                process_dtr($eid, $d);
            }
            audit('ams', 'change_shift', 'employee_schedules', null, ['employee_id' => $eid, 'days' => count($checked), 'shift' => $shiftId]);
            flash('success', 'Shift changed on ' . count($checked) . ' day(s).');
        } elseif ($action === 'recalc') {
            $days = $checked ?: $dates;
            foreach ($days as $d) {
                q('UPDATE dtr SET manual_override = 0 WHERE employee_id = ? AND work_date = ?', [$eid, $d]);
                process_dtr($eid, $d, false);
            }
            audit('ams', 'recalc_logs', 'dtr', null, ['employee_id' => $eid, 'days' => count($days)]);
            flash('success', count($days) . ' day(s) rebuilt from the raw punch logs.');
        }
    } catch (RuntimeException $e2) {
        flash('error', $e2->getMessage());
    }
    redirect('att_entry', $keep);
}

page_header('Attendance Entry', [
    'subtitle' => 'Daily logs and computed hours of one employee. Correct a punch, set a day-off, change the shift or rebuild the day from the raw biometric logs.',
    'wide' => true,
    'actions' => '<a class="btn" href="' . url('att_summary', $keep) . '">Attendance transaction</a>'
        . '<a class="btn" href="' . url('import_logs') . '">Import logs</a>',
]);
echo pt_filter_bar('att_entry', $period, $filters, ['from' => $from, 'to' => $to] + ($logsParam ? ['logs' => $logsParam] : []), '#empList2,#empCount2');

$shiftOpts = shift_options();   // v19: code and the actual time coverage of every log
$monthly = $emp && $emp['rate_type'] === 'monthly';
?>
<div class="split">
  <div class="card split-list">
    <div class="list-head">
      <div style="display:flex;justify-content:space-between;gap:8px;align-items:center"><strong>Employees</strong><?= pt_period_badge($period) ?></div>
      <input type="search" data-filter-list="empList2" placeholder="Type to find an employee" aria-label="Find an employee in the list">
    </div>
    <div class="list-nav" id="empList2">
      <?php foreach ($employees as $e): ?>
        <a class="<?= (int)$e['id'] === $empId ? 'active' : '' ?>" href="<?= url('att_entry', array_merge($keep, ['emp' => $e['id']])) ?>" data-search="<?= h((string)($e['biometric_id'] ?? '')) ?>">
          <span class="ln-main"><strong><?= h(full_name($e)) ?></strong><small><?= h($e['employee_no']) ?>, <?= h($e['unit_name'] ?? '—') ?></small></span>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="list-count" id="empCount2"><?= count($employees) ?> employee(s)</div>
  </div>

  <div>
  <?php if (!$emp): ?>
    <div class="card"><div class="empty-state"><h3>Select an employee</h3></div></div>
  <?php else:
      process_dtr_range([$empId], $from, $to);
      $recs = [];
      foreach (rows('SELECT * FROM dtr WHERE employee_id = ? AND work_date BETWEEN ? AND ? ORDER BY work_date', [$empId, $from, $to]) as $r) {
          $recs[$r['work_date']] = $r;
      }
      // pre-arranged overtime of the shift: OT In / OT Out columns and the credited Sched OT
      $hasOt = ($empShift && !empty($empShift['ot_in']) && !empty($empShift['ot_out']))
          || (bool)array_filter($recs, fn($r) => !empty($r['ot_in']) || !empty($r['ot_out']) || (int)($r['pre_ot_minutes'] ?? 0) > 0);
      if ($hasOt) {
          $logFields = array_merge($logFields, ['ot_in', 'ot_out']);
      }
      $dates = date_range($from, $to);
      $totals = ['late' => 0, 'ut' => 0, 'ot' => 0, 'aot' => 0, 'pot' => 0, 'np' => 0, 'abs' => 0, 'work' => 0];
      ?>
    <form method="post" data-changed-only="1">
      <?= csrf_field() ?>
      <input type="hidden" name="employee_id" value="<?= (int)$emp['id'] ?>">
      <div class="card">
        <div class="card-head">
          <div class="person">
            <span class="avatar"><?= h(mb_strtoupper(mb_substr($emp['first_name'], 0, 1) . mb_substr($emp['last_name'], 0, 1))) ?></span>
            <div><div class="person-name"><?= h(full_name($emp)) ?></div>
              <div class="person-meta"><?= h($emp['employee_no']) ?>, <?= h($emp['position'] ?: '—') ?>, shift <?= shift_code_html($empShift, 'default') ?> (<?= $logCount ?> logs per day)</div></div>
          </div>
          <div class="range-pick">
            <label class="small">From<input type="date" form="rangeForm" name="from" value="<?= h($from) ?>"></label>
            <label class="small">To<input type="date" form="rangeForm" name="to" value="<?= h($to) ?>"></label>
            <button class="btn btn-sm" form="rangeForm">Date coverage</button>
          </div>
        </div>
      </div>

      <div class="grid-wrap">
        <table class="grid-entry" id="dtrGrid">
          <thead>
            <tr class="grp">
              <th class="sticky" style="min-width:130px">&nbsp;</th>
              <th class="g-logs" colspan="<?= count($logFields) ?>">Daily logs (<?= $logCount ?>-log shift)</th>
              <th class="g-regular" colspan="4">Hours</th>
              <th class="g-ot_reg" colspan="<?= $hasOt ? 3 : 2 ?>">Overtime</th>
              <th class="g-np" colspan="1">NP</th>
              <th class="g-off_hol" colspan="2">Holiday</th>
              <th class="g-others" colspan="5">Leaves</th>
              <th colspan="2">Status</th>
            </tr>
            <tr>
              <th class="sticky">Date</th>
              <?php foreach ($logFields as $f): ?><th><?= h($logLabels[$f]) ?></th><?php endforeach; ?>
              <th>Work</th><th>Late</th><th>UT</th><th>ABS</th>
              <th>OT</th><th>Apprv OT</th><?php if ($hasOt): ?><th title="Pre-arranged overtime of the shift, approved automatically">Sched OT</th><?php endif; ?>
              <th>NP</th>
              <th>Leg</th><th>Spe</th>
              <th>VL</th><th>SL</th><th>EL</th><th>OL</th><th>P/M</th>
              <th>Log stat</th><th>Shf</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($dates as $d):
              $r = $recs[$d] ?? null;
              $locked = dtr_locked($emp, $d);
              $b = $r ? dtr_hour_buckets($r, $monthly) : [];
              $isRest = $r && in_array($r['day_type'], ['rest_day', 'special_rest', 'regular_rest'], true);
              $isHol = $r && in_array($r['day_type'], ['regular_holiday', 'double_holiday', 'special_holiday', 'special_rest', 'regular_rest'], true);
              $tv = fn($f) => $r && $r[$f] ? date('H:i', strtotime($r[$f])) : '';
              $hh = fn($m) => $m ? number_format($m / 60, 2) : '';
              $totals['late'] += (int)($r['late_minutes'] ?? 0);
              $totals['ut'] += (int)($r['undertime_minutes'] ?? 0);
              $totals['ot'] += (int)($r['ot_minutes'] ?? 0);
              $totals['aot'] += (int)($r['approved_ot_minutes'] ?? 0);
              $totals['pot'] += (int)($r['pre_ot_minutes'] ?? 0);
              $totals['np'] += (int)($r['nd_minutes'] ?? 0) + (int)($r['nd_ot_minutes'] ?? 0);
              $totals['abs'] += (int)($r['absent_minutes'] ?? 0);
              $totals['work'] += (int)($r['regular_minutes'] ?? 0);
              ?>
            <tr class="<?= $isRest ? 'is-rest' : '' ?> <?= $isHol ? 'is-hol' : '' ?>">
              <td class="txt sticky">
                <label class="check" style="gap:6px;margin:0">
                  <input type="checkbox" name="pick[]" value="<?= h($d) ?>"<?= $locked ? ' disabled' : '' ?>>
                  <span><strong><?= h(date('D d', strtotime($d))) ?></strong> <small class="muted"><?= h(date('M', strtotime($d))) ?></small></span>
                </label>
                <input type="hidden" name="dates[]" value="<?= h($d) ?>">
              </td>
              <?php foreach ($logFields as $f):
                  // a log after midnight belongs to this day (night or late shift): marked +1 with its full date
                  $nd = $r && $r[$f] && substr((string)$r[$f], 0, 10) > $d; ?>
                <td class="<?= $nd ? 'is-nextday' : '' ?>"<?= $nd ? ' title="' . h('Log of ' . date('D M j, Y g:i A', strtotime($r[$f])) . ' (next day), counted on ' . date('M j', strtotime($d))) . '"' : '' ?>><input class="cell w-time" type="time" name="<?= h($f . '_' . $d) ?>" value="<?= h($tv($f)) ?>"<?= $locked ? ' readonly' : '' ?>><?= $nd ? '<span class="nextday-mark" aria-label="next day">+1</span>' : '' ?></td>
              <?php endforeach; ?>
              <td class="num"><?= h($hh((int)($r['regular_minutes'] ?? 0))) ?></td>
              <td class="num"><?= h($hh((int)($r['late_minutes'] ?? 0))) ?></td>
              <td class="num"><?= h($hh((int)($r['undertime_minutes'] ?? 0))) ?></td>
              <td class="num"><?= h($hh((int)($r['absent_minutes'] ?? 0))) ?></td>
              <td class="num"><?= h($hh((int)($r['ot_minutes'] ?? 0))) ?></td>
              <td class="num"><?= h($hh((int)($r['approved_ot_minutes'] ?? 0))) ?></td>
              <?php if ($hasOt): ?><td class="num" title="<?= $r && ((int)$r['ot_late_minutes'] || (int)$r['ot_ut_minutes']) ? h('Late on OT In ' . (int)$r['ot_late_minutes'] . ' min, out before OT Out ' . (int)$r['ot_ut_minutes'] . ' min') : '' ?>"><?= h($hh((int)($r['pre_ot_minutes'] ?? 0))) ?></td><?php endif; ?>
              <td class="num"><?= h($hh((int)($r['nd_minutes'] ?? 0) + (int)($r['nd_ot_minutes'] ?? 0))) ?></td>
              <td class="num"><?= h(pt_fmt(($b['lh_hrs'] ?? 0) + ($b['lh_ot'] ?? 0) + ($b['lh_rd'] ?? 0))) ?></td>
              <td class="num"><?= h(pt_fmt(($b['sh_hrs'] ?? 0) + ($b['sh_ot'] ?? 0) + ($b['sh_rd'] ?? 0))) ?></td>
              <td class="num"><?= h(pt_fmt($b['vl_hrs'] ?? 0)) ?></td>
              <td class="num"><?= h(pt_fmt($b['sl_hrs'] ?? 0)) ?></td>
              <td class="num"><?= h(pt_fmt($b['el_hrs'] ?? 0)) ?></td>
              <td class="num"><?= h(pt_fmt($b['ol_hrs'] ?? 0)) ?></td>
              <td class="num"><?= h(pt_fmt($b['pm_hrs'] ?? 0)) ?></td>
              <td class="txt small">
                <?= $r ? h($r['log_status'] ?: label($r['status'])) : '<span class="muted">—</span>' ?>
                <?= $r && $r['exception_status'] === 'open' ? '<span class="badge badge-amber">exc</span>' : '' ?>
                <?= $r && $r['manual_override'] ? '<span class="badge badge-blue">M</span>' : '' ?>
              </td>
              <td class="txt small"><?= shift_code_html(!empty($r['shift_id']) ? shift_row((int)$r['shift_id']) : null, $isRest ? 'OFF' : '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td class="sticky">Total (hrs)</td>
              <td colspan="<?= count($logFields) ?>"></td>
              <td><?= h(number_format($totals['work'] / 60, 2)) ?></td>
              <td><?= h(number_format($totals['late'] / 60, 2)) ?></td>
              <td><?= h(number_format($totals['ut'] / 60, 2)) ?></td>
              <td><?= h(number_format($totals['abs'] / 60, 2)) ?></td>
              <td><?= h(number_format($totals['ot'] / 60, 2)) ?></td>
              <td><?= h(number_format($totals['aot'] / 60, 2)) ?></td>
              <?php if ($hasOt): ?><td><?= h(number_format($totals['pot'] / 60, 2)) ?></td><?php endif; ?>
              <td><?= h(number_format($totals['np'] / 60, 2)) ?></td>
              <td colspan="9"></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <div class="toolbar sticky-bottom">
        <button class="btn btn-primary" name="_action" value="save">Save logs</button>
        <button class="btn" name="_action" value="dayoff" data-confirm="Set the ticked days as day-off?">Set DayOff</button>
        <button class="btn" name="_action" value="workday" data-confirm="Set the ticked days as working days?">Set working day</button>
        <span class="sep"></span>
        <select name="shift_id" style="width:230px"><?= options($shiftOpts, $emp['shift_id'], 'No shift (use default)') ?></select>
        <button class="btn" name="_action" value="shift" data-confirm="Change the shift of the ticked days?">Change shift</button>
        <span class="sep"></span>
        <button class="btn btn-warning" name="_action" value="recalc" data-confirm="Rebuild the ticked days (or the whole period) from the raw biometric logs? Manual corrections on those days are discarded.">ReCalc logs</button>
        <a class="btn" href="<?= h(url_back('att_logs', ['employee_id' => $empId, 'from' => $from, 'to' => $to])) ?>">View raw log</a>
        <a class="btn" target="_blank" href="<?= h(url('dtr_print', ['employee_id' => $empId, 'from' => $from, 'to' => $to])) ?>">Print</a>
        <a class="btn" href="<?= h(url('att_entry', $keep)) ?>">Refresh</a>
        <span class="spacer"></span>
        <label class="small">Log columns
          <select class="input-sm" onchange="location.href=this.value">
            <?php foreach ([0 => 'From the shift code', 2 => '2 logs', 4 => '4 logs', 6 => '6 logs'] as $n => $l): ?>
              <option value="<?= h(url('att_entry', array_merge($keep, ['logs' => $n ?: null]))) ?>"<?= $n === $logsParam ? ' selected' : '' ?>><?= h($l) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
    </form>
    <form id="rangeForm" method="get">
      <input type="hidden" name="p" value="att_entry"><input type="hidden" name="emp" value="<?= (int)$empId ?>">
      <input type="hidden" name="cutoff_id" value="<?= (int)($period['id'] ?? 0) ?>"><?= $logsParam ? '<input type="hidden" name="logs" value="' . $logsParam . '">' : '' ?>
    </form>
  <?php endif; ?>
  </div>
</div>
<?php page_footer();
