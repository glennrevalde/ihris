<?php
/**
 * Batch Shift Define — assign a shift (or a day-off) to many employees for a range of dates
 * in one step. It writes the per-day schedule used by the attendance engine.
 */
require __DIR__ . '/../payroll/_trans_form.php';

$filters = pt_filters();
$period = pt_default_period((int)get('cutoff_id') ?: null);
$from = (string)get('from', $period['period_start'] ?? date('Y-m-01'));
$to = (string)get('to', $period['period_end'] ?? date('Y-m-t'));
$employees = $period ? pt_period_employees($period, $filters) : rows('SELECT e.*, ou.name AS unit_name FROM employees e LEFT JOIN org_units ou ON ou.id = e.org_unit_id WHERE e.status = "active"' . user_scope_sql('e') . ' ORDER BY e.last_name LIMIT 500');
$keep = array_filter(array_merge(['p' => 'batch_shift', 'cutoff_id' => $period['id'] ?? null, 'from' => $from, 'to' => $to], $filters), fn($v) => $v !== null && $v !== '');
$shifts = rows('SELECT * FROM shifts WHERE is_active = 1 ORDER BY code');

if (is_post()) {
    verify_csrf();
    try {
        $ids = array_map('intval', (array)post('employee_ids', []));
        $f = (string)post('from');
        $t = (string)post('to') ?: $f;
        $days = array_map('intval', (array)post('weekdays', []));
        $mode = (string)post('mode', 'shift');
        $shiftId = (int)post('shift_id') ?: null;
        $keepOff = (bool)post('keep_day_off');
        $shiftRow = $shiftId ? shift_row($shiftId) : null;
        if (!$ids) {
            throw new RuntimeException('Select at least one employee.');
        }
        if (!$f || $t < $f) {
            throw new RuntimeException('Enter a valid date range.');
        }
        if ($mode === 'shift' && !$shiftId) {
            throw new RuntimeException('Select the shift to assign.');
        }
        $dates = date_range($f, $t);
        $n = 0;
        $locked = 0;
        $offDays = 0;
        tx(function () use ($ids, $dates, $days, $mode, $shiftId, $shiftRow, $keepOff, &$n, &$locked, &$offDays) {
            foreach ($ids as $eid) {
                $emp = att_employee($eid);
                if (!$emp) {
                    continue;
                }
                foreach ($dates as $d) {
                    if ($days && !in_array((int)date('w', strtotime($d)), $days, true)) {
                        continue;
                    }
                    if (dtr_locked($emp, $d)) {
                        $locked++;
                        continue;
                    }
                    $ex = row('SELECT * FROM employee_schedules WHERE employee_id = ? AND work_date = ?', [$eid, $d]);
                    // v18: a shift keeps its day-off: the rest days set in the shift, or, on a 7-day shift, the employee's
                    // default day-off of the masterfile (is_rest_day NULL = follow the shift); unticked = works every day
                    $data = ['shift_id' => $mode === 'dayoff' ? ($ex['shift_id'] ?? null) : $shiftId, 'is_rest_day' => $mode === 'dayoff' ? 1 : ($keepOff ? null : 0)];
                    if ($mode === 'shift' && $keepOff && shift_day_off($emp, $shiftRow, $d)) {
                        $offDays++;
                    }
                    if ($mode === 'clear') {
                        if ($ex) {
                            q('DELETE FROM employee_schedules WHERE id = ?', [$ex['id']]);
                        }
                    } elseif ($ex) {
                        update('employee_schedules', $data, 'id = ?', [$ex['id']]);
                    } else {
                        insert('employee_schedules', array_merge($data, ['employee_id' => $eid, 'work_date' => $d]));
                    }
                    process_dtr($eid, $d);
                    $n++;
                }
            }
        });
        audit('ams', 'batch_shift', 'employee_schedules', null, ['employees' => count($ids), 'days' => $n, 'mode' => $mode, 'shift' => $shiftId, 'default_day_off' => $offDays]);
        flash('success', sprintf('%d employee-day(s) updated%s%s.', $n, $offDays ? ", $offDays of them on the day-off of the shift / the employee" : '', $locked ? ", $locked skipped (attendance closed)" : ''));
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('batch_shift', $keep);
}

page_header('Batch Shift Define', [
    'subtitle' => 'Give a shift or a day-off to many employees at once for a range of dates — e.g. a night shift for one week, or Sundays off for a whole department.',
    'actions' => '<a class="btn" href="' . url('shifts') . '">Shift Table</a><a class="btn" href="' . url('schedules') . '">Employee Shift Entry</a>',
]);
echo pt_filter_bar('batch_shift', $period, $filters, ['from' => $from, 'to' => $to]);
?>
<form method="post">
  <?= csrf_field() ?>
  <div class="grid grid-2-1">
    <div class="card flush">
      <div class="card-head"><div><h2>Employees</h2><div class="sub"><?= count($employees) ?> employee(s) — tick the ones to update</div></div>
        <input type="search" class="table-search" data-target="bsTable" placeholder="Find…" style="width:200px"></div>
      <div class="table-wrap table-scroll">
        <table class="table table-compact" id="bsTable">
          <thead><tr><th style="width:34px"><input type="checkbox" data-check-all="employee_ids[]"></th><th>Employee</th><th>Department</th><th>Default shift</th><th>Default day-off</th></tr></thead>
          <tbody>
          <?php foreach ($employees as $e): ?>
            <tr>
              <td><input type="checkbox" name="employee_ids[]" value="<?= (int)$e['id'] ?>"></td>
              <td><strong><?= h(full_name($e)) ?></strong><br><small class="muted"><?= h($e['employee_no']) ?></small></td>
              <td><?= h($e['unit_name'] ?? '—') ?></td>
              <td><?php $es = $e['shift_id'] ? shift_row((int)$e['shift_id']) : null; ?><?= $es ? shift_code_html($es, '', $es['code'] . ' — ' . $es['name']) : 'default' ?></td>
              <td><?= ($e['day_off'] ?? null) ? h(day_off_label($e['day_off'], true)) : '<span class="muted">None</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          <?= $employees ? '' : empty_row(5, 'No employee matches the filters.') ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <h2>What to assign</h2>
      <div class="form-grid">
        <div class="field"><label>From</label><input type="date" name="from" value="<?= h($from) ?>" required></div>
        <div class="field"><label>To</label><input type="date" name="to" value="<?= h($to) ?>" required></div>
        <div class="field wide"><label>Action</label><select name="mode">
          <option value="shift">Assign a shift</option>
          <option value="dayoff">Set as day-off</option>
          <option value="clear">Clear the per-day schedule (use the employee's own shift)</option>
        </select></div>
        <div class="field wide"><label>Shift</label><select name="shift_id">
          <?php foreach ($shifts as $s): ?>
            <option value="<?= (int)$s['id'] ?>"<?= $s['is_default'] ? ' selected' : '' ?>><?= h(shift_option_label($s)) ?></option>
          <?php endforeach; ?>
        </select></div>
        <div class="field wide"><label class="check"><input type="checkbox" name="keep_day_off" value="1" checked> Keep the day-off</label>
          <small class="help">A shift with rest days keeps them (e.g. Sunday); a shift worked 7 days a week takes each employee's default day-off of the Employee Masterfile. Untick to make every date of the range a working day. Change one date afterwards in Employee Shift Entry (Schedule Override).</small></div>
        <div class="field wide"><label>Only on these days <span class="muted">(none = every day of the range)</span></label>
          <div class="checks">
            <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $i => $d): ?>
              <label class="check"><input type="checkbox" name="weekdays[]" value="<?= $i ?>"> <?= h($d) ?></label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" data-confirm="Apply this schedule to the ticked employees?">Apply</button>
      </div>
      <p class="help">Days that belong to an approved (closed) period are skipped. Each affected day is recomputed right away.</p>
    </div>
  </div>
</form>
<?php page_footer();
