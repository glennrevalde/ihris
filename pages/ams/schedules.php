<?php
/**
 * Schedule overrides: assign a different shift, a rest day or a different branch for specific dates
 * (e.g. an employee scheduled across sister branches during the same cutoff).
 * v16: filters Co. / Branch, Department, Employee group and Employee type narrow the employees of
 * the Assign form and the overrides listed; the Employee search shows the overrides of one employee
 * only. Without filters every employee is listed and one, two, three or more can be ticked.
 * v18: Day-off of an override: follow the shift (default), rest day or working day.
 */
const SCHED_REST_MODES = ['auto' => 'Follow the shift / default day-off', 'rest' => 'Rest day (day-off)', 'work' => 'Working day'];
$sf = [
    'branch_id' => (int)get('branch_id', 0) ?: null, 'org_unit_id' => (int)get('org_unit_id', 0) ?: null,
    'employee_group_id' => (int)get('employee_group_id', 0) ?: null, 'employee_type_id' => (int)get('employee_type_id', 0) ?: null,
    'emp' => (int)get('emp', 0) ?: null,
];
$from = get('from', date('Y-m-01'));
$to = get('to', date('Y-m-t'));
$keep = array_filter($sf + ['from' => $from, 'to' => $to], fn($v) => $v !== null && $v !== '');
$empCond = function (array &$p, bool $withEmp = true) use ($sf): string {
    $w = '';
    foreach (['branch_id' => 'e.default_branch_id', 'employee_group_id' => 'e.employee_group_id', 'employee_type_id' => 'e.employee_type_id', 'emp' => 'e.id'] as $k => $col) {
        if ($sf[$k] && ($withEmp || $k !== 'emp')) {
            $w .= " AND $col = ?";
            $p[] = $sf[$k];
        }
    }
    if ($sf['org_unit_id']) {
        $w .= ' AND e.org_unit_id IN (' . implode(',', array_map('intval', org_descendant_ids($sf['org_unit_id']))) . ')';
    }
    return $w;
};
$dimp = dimp_def('schedules', null, []);   // CSV import facility on this form (app/engine/dataimport.php)
dimp_handle($dimp);
if (is_post()) {
    $act = post('_action');
    if ($act === 'assign') {
        $emps = array_map('intval', (array)post('employee_ids', []));
        $from = post('date_from');
        $to = post('date_to');
        $dow = array_map('strval', (array)post('weekdays', []));
        if (!$emps || !$from || !$to || $to < $from) {
            flash('error', 'Select employees and a valid date range.');
            redirect('schedules', $keep);
        }
        // v18: Day-off = follow the shift (its embedded rest days, or the default day-off of the masterfile on a 7-day shift), rest day or working day
        $restMode = ['rest' => 1, 'work' => 0][(string)post('rest_mode')] ?? null;
        $n = 0;
        foreach ($emps as $eid) {
            foreach (date_range($from, $to) as $d) {
                if ($dow && !in_array(date('w', strtotime($d)), $dow, true)) {
                    continue;
                }
                q('INSERT INTO employee_schedules (employee_id, work_date, shift_id, is_rest_day, branch_id) VALUES (?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE shift_id = VALUES(shift_id), is_rest_day = VALUES(is_rest_day), branch_id = VALUES(branch_id)',
                    [$eid, $d, post_null('shift_id'), $restMode, post_null('branch_id')]);
                if ($d <= today()) {
                    process_dtr($eid, $d);
                }
                $n++;
            }
        }
        audit('ams', 'schedule_assign', 'employee_schedules', null, ['employees' => $emps, 'from' => $from, 'to' => $to, 'count' => $n]);
        flash('success', "$n schedule override(s) saved.");
        redirect('schedules', array_merge($keep, ['from' => $from, 'to' => $to]));   // the filters stay
    }
    if ($act === 'delete') {
        $s = row('SELECT * FROM employee_schedules WHERE id = ?', [(int)post('id')]);
        if ($s) {
            q('DELETE FROM employee_schedules WHERE id = ?', [$s['id']]);
            if ($s['work_date'] <= today()) {
                process_dtr((int)$s['employee_id'], $s['work_date']);
            }
            flash('success', 'Override removed; the regular shift applies again.');
        }
        redirect('schedules', $keep);
    }
}
$lp = [$from, $to];
$lw = $empCond($lp);
$list = rows('SELECT s.*, e.first_name, e.last_name, e.middle_name, e.suffix, e.employee_no, sh.name AS shift_name, b.name AS branch_name FROM employee_schedules s
              JOIN employees e ON e.id = s.employee_id LEFT JOIN shifts sh ON sh.id = s.shift_id LEFT JOIN branches b ON b.id = s.branch_id
              WHERE s.work_date BETWEEN ? AND ?' . $lw . user_scope_sql('e') . ' ORDER BY s.work_date, e.last_name', $lp);
// employees of the Assign form (the filters above; the Employee search keeps the others selectable)
$ap = [];
$aw = $empCond($ap, false);
$assignOpts = pairs("SELECT e.id, CONCAT(e.last_name, ', ', e.first_name, ' (', e.employee_no, ')') FROM employees e WHERE e.status = 'active'"
    . $aw . user_scope_sql('e') . ' ORDER BY e.last_name, e.first_name', $ap);
if ($sf['emp'] && !isset($assignOpts[$sf['emp']]) && scope_allows($sf['emp'])) {   // the searched employee is always offered
    $assignOpts = pairs("SELECT id, CONCAT(last_name, ', ', first_name, ' (', employee_no, ')') FROM employees WHERE id = ?", [$sf['emp']]) + $assignOpts;
}
$filtered = $sf['branch_id'] || $sf['org_unit_id'] || $sf['employee_group_id'] || $sf['employee_type_id'];
$branchF = pairs("SELECT id, CONCAT(COALESCE(branch_code, code), ' — ', name) FROM branches WHERE is_active = 1 ORDER BY company_code, branch_code, code");
page_header('Schedule Overrides', ['subtitle' => 'Change the shift, rest day or branch of specific dates without editing the employee\'s default shift.', 'back' => url('shifts')]);
echo dimp_panel($dimp);
?>
<form class="card filters" method="get" data-live="#schedList,#schedEmps,#schedNote">
  <input type="hidden" name="p" value="schedules">
  <label>Co. / Branch<select name="branch_id"><?= options($branchF, $sf['branch_id'], 'All companies') ?></select></label>
  <label>Department<select name="org_unit_id"><?= options(org_unit_options(), $sf['org_unit_id'], 'All departments') ?></select></label>
  <label>Employee group<select name="employee_group_id"><?= options(emp_group_options(), $sf['employee_group_id'], 'All groups') ?></select></label>
  <label>Employee type<select name="employee_type_id"><?= options(lookup_options('emp_types'), $sf['employee_type_id'], 'All types') ?></select></label>
  <label class="grow">Employee search<select name="emp" data-combo aria-label="Search one employee"><?= options(employee_options(), $sf['emp'], 'All employees') ?></select></label>
  <label>From<input type="date" name="from" value="<?= h($from) ?>"></label>
  <label>To<input type="date" name="to" value="<?= h($to) ?>"></label>
  <button class="btn btn-primary">Show</button>
</form>
<div class="card"><h2>Assign schedule</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="assign">
    <div class="form-grid">
      <div class="field wide"><label>Employees <span class="muted">(tick one, two, three or more)</span></label>
        <div id="schedNote"><?php if ($filtered): ?><div class="callout small emp-filter-note"><?= count($assignOpts) ?> employee(s) for the filters above. <a href="<?= h(url('schedules', array_intersect_key($keep, ['from' => 1, 'to' => 1, 'emp' => 1]))) ?>">Show all employees</a></div><?php endif; ?></div>
        <select name="employee_ids[]" id="schedEmps" multiple size="8" required><?php foreach ($assignOpts as $id => $n): ?><option value="<?= (int)$id ?>"<?= $sf['emp'] === (int)$id ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select>
        <small class="help">The list follows the filters above. The employee picked in Employee search is ticked; tick others to add them.</small></div>
      <div class="field"><label>From</label><input type="date" name="date_from" value="<?= h(today()) ?>" required></div>
      <div class="field"><label>To</label><input type="date" name="date_to" value="<?= h(add_days(today(), 6)) ?>" required></div>
      <div class="field"><label>Only on weekdays</label><div class="checks"><?php foreach (['1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat', '0' => 'Sun'] as $k => $l): ?><label class="check"><input type="checkbox" name="weekdays[]" value="<?= $k ?>"> <?= $l ?></label><?php endforeach; ?></div><small class="help">None ticked = every day in range</small></div>
      <div class="field"><label>Shift</label><select name="shift_id"><?= options(shift_options(), null, '(keep default shift)') ?></select></div>
      <div class="field"><label>Branch</label><select name="branch_id"><?= options(branch_options(), null, '(default branch)') ?></select></div>
      <div class="field"><label>Day-off</label><select name="rest_mode"><?= options(SCHED_REST_MODES, 'auto') ?></select>
        <small class="help">Follow the shift: the rest days set in the shift, or, on a 7-day shift, the default day-off of the Employee Masterfile. Working day removes the day-off on these dates.</small></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary">Save overrides</button></div>
  </form>
</div>
<div class="card flush" id="schedList">
  <div class="card-head"><div><h2>Overrides</h2><div class="sub"><?= count($list) ?> override(s), <?= h(fmt_date($from)) ?> to <?= h(fmt_date($to)) ?><?= $sf['emp'] ? ', ' . h((string)($assignOpts[$sf['emp']] ?? val("SELECT CONCAT(last_name, ', ', first_name) FROM employees WHERE id = ?", [$sf['emp']]))) : '' ?></div></div></div>
  <div class="table-wrap"><table class="table compact"><thead><tr><th>Date</th><th>Employee</th><th>Shift</th><th>Day-off</th><th>Branch</th><th class="actions"></th></tr></thead><tbody>
<?php if (!$list) echo empty_row(6, 'No overrides in this range for these filters.'); foreach ($list as $s): ?>
  <tr><td><?= h(fmt_date($s['work_date'], 'D M d, Y')) ?></td><td><?= h(full_name($s)) ?><br><small class="muted"><?= h($s['employee_no']) ?></small></td><td><?= $s['shift_id'] ? shift_code_html(shift_row((int)$s['shift_id']), '', shift_row((int)$s['shift_id'])['code'] . ' — ' . $s['shift_name']) : 'Default' ?></td><td><?= $s['is_rest_day'] === null ? '<span class="muted">Follows the shift</span>' : ($s['is_rest_day'] ? 'Rest day' : 'Working day') ?></td><td><?= h($s['branch_name'] ?: 'Default') ?></td>
    <td class="actions"><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button class="btn btn-sm btn-danger-outline">Remove</button></form></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php page_footer();
