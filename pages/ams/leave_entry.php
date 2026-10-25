<?php
/**
 * Employee Leave Entry — the timekeeper encodes an approved leave directly (no approval
 * routing): it is charged to the balance, shown on the DTR and carried to the attendance
 * transaction in the leave column of its category (VL / SL / EL / OL / P-M).
 */
require __DIR__ . '/../payroll/_trans_form.php';

$filters = pt_filters();
$period = pt_default_period((int)get('cutoff_id') ?: null);
$empId = (int)get('emp', 0);
$employees = $period ? pt_period_employees($period, $filters) : rows('SELECT e.*, ou.name AS unit_name FROM employees e LEFT JOIN org_units ou ON ou.id = e.org_unit_id WHERE e.status = "active"' . user_scope_sql('e') . ' ORDER BY e.last_name LIMIT 300');
if (!$empId && $employees) {
    $empId = (int)$employees[0]['id'];
}
$emp = $empId ? row('SELECT e.*, ou.name AS unit_name FROM employees e LEFT JOIN org_units ou ON ou.id = e.org_unit_id WHERE e.id = ?', [$empId]) : null;
$keep = array_filter(array_merge(['p' => 'att_leave_entry', 'cutoff_id' => $period['id'] ?? null, 'emp' => $empId], $filters), fn($v) => $v !== null && $v !== '');
$year = (int)date('Y');

if (is_post()) {
    verify_csrf();
    $action = post('_action');
    $eid = (int)post('employee_id');
    try {
        if ($action === 'post') {
            $e = row('SELECT * FROM employees WHERE id = ?', [$eid]);
            $type = leave_type_row((int)post('leave_type_id'));
            $from = (string)post('date_from');
            $to = (string)post('date_to') ?: $from;
            if (!$e || !$type) {
                throw new RuntimeException('Select an employee and a leave type.');
            }
            if (!$from || $to < $from) {
                throw new RuntimeException('Enter a valid date range.');
            }
            $days = leave_count_days($e, $type, $from, $to);
            if ($days <= 0) {
                throw new RuntimeException('The selected dates are rest days or holidays only — nothing to charge.');
            }
            $y = (int)substr($from, 0, 4);
            $bal = leave_balance($eid, (int)$type['id'], $y);
            if ($type['accrual_method'] !== 'per_event' && $days > $bal['remaining'] + 0.001 && !post('allow_negative')) {
                throw new RuntimeException(sprintf('Insufficient %s balance: %s day(s) remaining, %s requested. Tick "post anyway" to charge it beyond the balance.',
                    $type['code'], rtrim(rtrim(number_format($bal['remaining'], 2), '0'), '.'), rtrim(rtrim(number_format($days, 2), '0'), '.')));
            }
            $overlap = val("SELECT app_no FROM leave_applications WHERE employee_id = ? AND status IN ('pending','approved') AND date_from <= ? AND date_to >= ?", [$eid, $to, $from]);
            if ($overlap) {
                throw new RuntimeException("These dates overlap leave application $overlap.");
            }
            $appId = insert('leave_applications', [
                'app_no' => next_number('LA', 'leave_applications', 'app_no'), 'employee_id' => $eid, 'leave_type_id' => (int)$type['id'],
                'date_from' => $from, 'date_to' => $to, 'days' => $days, 'reason' => trim((string)post('reason')) ?: 'Encoded by the timekeeper',
                'status' => 'approved', 'current_step' => 0, 'filed_by' => user_id(), 'decided_at' => now(), 'decision_reason' => 'Encoded directly in Employee Leave Entry',
            ]);
            leave_post_to_dtr($appId);
            foreach (date_range($from, $to) as $d) {
                process_dtr($eid, $d);
            }
            audit('ams', 'leave_entry', 'leave_applications', $appId, ['employee_id' => $eid, 'type' => $type['code'], 'days' => $days, 'from' => $from, 'to' => $to]);
            flash('success', sprintf('%s leave of %s day(s) posted for %s.', $type['code'], rtrim(rtrim(number_format($days, 2), '0'), '.'), full_name($e, false)));
        } elseif ($action === 'cancel') {
            $appId = (int)post('id');
            $r = leave_cancel($appId, trim((string)post('reason')) ?: 'Cancelled by the timekeeper');
            flash($r['ok'] ? 'success' : 'error', $r['message']);
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('att_leave_entry', array_merge($keep, ['emp' => $eid]));
}

$types = rows('SELECT * FROM leave_types WHERE is_active = 1 ORDER BY code');
$apps = $emp ? rows('SELECT la.*, lt.code, lt.name AS type_name, lt.summary_bucket FROM leave_applications la JOIN leave_types lt ON lt.id = la.leave_type_id
                     WHERE la.employee_id = ? AND la.date_to >= ? ORDER BY la.date_from DESC LIMIT 40',
    [$empId, $period['period_start'] ?? date('Y-01-01')]) : [];

page_header('Employee Leave Entry', [
    'subtitle' => 'Encode an approved leave for a period. It is charged to the balance, appears on the daily time record and fills the leave column of the attendance transaction.',
    'actions' => '<a class="btn" href="' . url('leave_applications') . '">Leave applications (with approval)</a><a class="btn" href="' . url('leave_balances') . '">Leave balances</a>',
]);
echo pt_filter_bar('att_leave_entry', $period, $filters, [], '#empList3,#empCount3');
?>
<div class="split">
  <div class="card split-list">
    <div class="list-head"><strong>Employees</strong><input type="search" data-filter-list="empList3" placeholder="Type to find an employee" aria-label="Find an employee in the list"></div>
    <div class="list-nav" id="empList3">
      <?php foreach ($employees as $e): ?>
        <a class="<?= (int)$e['id'] === $empId ? 'active' : '' ?>" href="<?= url('att_leave_entry', array_merge($keep, ['emp' => $e['id']])) ?>" data-search="<?= h((string)($e['biometric_id'] ?? '')) ?>">
          <span class="ln-main"><strong><?= h(full_name($e)) ?></strong><small><?= h($e['employee_no']) ?>, <?= h($e['unit_name'] ?? '—') ?></small></span>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="list-count" id="empCount3"><?= count($employees) ?> employee(s)</div>
  </div>

  <div>
    <?php if (!$emp): ?>
      <div class="card"><div class="empty-state"><h3>Select an employee</h3></div></div>
    <?php else: ?>
    <div class="card">
      <div class="card-head">
        <div class="person">
          <span class="avatar"><?= h(mb_strtoupper(mb_substr($emp['first_name'], 0, 1) . mb_substr($emp['last_name'], 0, 1))) ?></span>
          <div><div class="person-name"><?= h(full_name($emp)) ?></div><div class="person-meta"><?= h($emp['employee_no']) ?>, <?= h($emp['unit_name'] ?: '—') ?></div></div>
        </div>
      </div>
      <div class="chips">
        <?php foreach ($types as $t):
            if (!in_array($t['code'], ['VL', 'SL', 'SIL', 'EL', 'OFL'], true)) {
                continue;
            }
            $b = leave_balance($empId, (int)$t['id'], $year); ?>
          <span class="chip"><?= h($t['code']) ?>: <strong><?= h(rtrim(rtrim(number_format($b['remaining'], 2), '0'), '.')) ?></strong> day(s) left</span>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <h2>Post a leave</h2>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="_action" value="post"><input type="hidden" name="employee_id" value="<?= (int)$emp['id'] ?>">
        <div class="form-grid">
          <div class="field wide"><label>Leave type</label><select name="leave_type_id" required>
            <?php foreach ($types as $t): ?>
              <option value="<?= (int)$t['id'] ?>"><?= h($t['code'] . ' — ' . $t['name']) ?> (column <?= h(strtoupper($t['summary_bucket'])) ?>)</option>
            <?php endforeach; ?>
          </select></div>
          <div class="field"><label>From</label><input type="date" name="date_from" value="<?= h($period['period_start'] ?? today()) ?>" required></div>
          <div class="field"><label>To</label><input type="date" name="date_to" value="<?= h($period['period_start'] ?? today()) ?>"></div>
          <div class="field wide"><label>Reason / reference</label><input name="reason" maxlength="255" placeholder="Approved leave slip no., reason…"></div>
          <div class="field wide"><label class="check"><input type="checkbox" name="allow_negative" value="1"> Post anyway even if the balance is not enough (leave without pay is charged by the payroll)</label></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary">Post leave</button>
          <a class="btn" href="<?= h(url('att_entry', array_merge($keep, ['emp' => $empId]))) ?>">Attendance entry</a></div>
      </form>
    </div>

    <div class="card flush">
      <div class="card-head"><h2>Leaves of this employee</h2></div>
      <div class="table-wrap">
        <table class="table table-compact">
          <thead><tr><th>App. no.</th><th>Type</th><th>Dates</th><th class="num">Days</th><th>Status</th><th>Reason</th><th class="actions"></th></tr></thead>
          <tbody>
          <?php if (!$apps) {
              echo empty_row(7, 'No leave for this period.');
          }
          foreach ($apps as $a): ?>
            <tr>
              <td><a href="<?= h(url('leave_view', ['id' => $a['id']])) ?>"><?= h($a['app_no']) ?></a></td>
              <td><?= h($a['code']) ?><br><small class="muted"><?= h($a['type_name']) ?></small></td>
              <td class="nowrap"><?= h(fmt_date($a['date_from'], 'M d')) ?> – <?= h(fmt_date($a['date_to'], 'M d, Y')) ?></td>
              <td class="num"><?= h(rtrim(rtrim(number_format((float)$a['days'], 2), '0'), '.')) ?></td>
              <td><?= badge($a['status']) ?></td>
              <td class="small"><?= h(mb_strimwidth((string)$a['reason'], 0, 40, '…')) ?></td>
              <td class="actions">
                <?php if (in_array($a['status'], ['approved', 'pending'], true)): ?>
                  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="_action" value="cancel"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <input type="hidden" name="employee_id" value="<?= (int)$empId ?>">
                    <button class="btn btn-xs btn-danger-outline" data-confirm="Cancel this leave and restore the balance?">Cancel</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php page_footer();
