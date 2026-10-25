<?php
/**
 * Audit trail: chronological log of every actionable item of every user — sign-ins, creates,
 * updates, approvals, postings, imports, exports, prints, adjustments and manual overrides —
 * with the identity of the user, the time, the record and the details of the change.
 */
$f = ['from' => get('from', date('Y-m-d', strtotime('-30 days'))), 'to' => get('to', today()), 'user' => get('user', ''), 'module' => get('module', ''),
    'action' => get('action', ''), 'q' => get('q', '')];
$where = ['a.created_at BETWEEN ? AND ?'];
$params = [$f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59'];
if ($f['user'] !== '') {
    $where[] = 'a.username = ?';
    $params[] = $f['user'];
}
if ($f['module'] !== '') {
    $where[] = 'a.module = ?';
    $params[] = $f['module'];
}
if ($f['action'] !== '') {
    $where[] = 'a.action = ?';
    $params[] = $f['action'];
}
if ($f['q'] !== '') {
    $where[] = '(a.action LIKE ? OR a.entity LIKE ? OR a.details LIKE ? OR a.entity_id = ?)';
    array_push($params, '%' . $f['q'] . '%', '%' . $f['q'] . '%', '%' . $f['q'] . '%', $f['q']);
}
$whereSql = implode(' AND ', $where);
$list = rows('SELECT a.* FROM audit_log a WHERE ' . $whereSql . ' ORDER BY a.id DESC LIMIT 1000', $params);
if (get('csv')) {
    audit('admin', 'audit_export', 'audit_log', null, ['from' => $f['from'], 'to' => $f['to'], 'rows' => count($list)]);
    csv_download('audit_trail_' . $f['from'] . '_' . $f['to'] . '.csv', ['Timestamp', 'User', 'Module', 'Action', 'Entity', 'Entity ID', 'Details', 'IP'],
        array_map(fn($a) => [$a['created_at'], $a['username'], $a['module'], $a['action'], $a['entity'], $a['entity_id'], salary_mask_details($a['details']), $a['ip_address']], $list));
}
$total = (int)val('SELECT COUNT(*) FROM audit_log a WHERE ' . $whereSql, $params);
$byUser = rows('SELECT a.username, COUNT(*) AS n, MAX(a.created_at) AS last_at FROM audit_log a WHERE ' . $whereSql . ' GROUP BY a.username ORDER BY n DESC LIMIT 8', $params);
$byModule = pairs('SELECT a.module, COUNT(*) FROM audit_log a WHERE ' . $whereSql . ' GROUP BY a.module', $params);
$modules = ['pis' => 'Personnel (PIS)', 'ams' => 'Attendance (AMS)', 'payroll' => 'Payroll', 'setup' => 'Setup tables', 'admin' => 'Administration', 'auth' => 'Sign-in', 'system' => 'System'];
$actions = pairs('SELECT DISTINCT action, action FROM audit_log' . ($f['module'] !== '' ? ' WHERE module = ' . db()->quote($f['module']) : '') . ' ORDER BY action');
foreach ($actions as $k => $v) {
    $actions[$k] = label($v);
}
$link = fn(array $a): ?string => match (true) {
    $a['entity'] === 'employees' && $a['entity_id'] => url('employee_view', ['id' => $a['entity_id']]),
    $a['entity'] === 'payroll_runs' && $a['entity_id'] => url('payroll_run', ['id' => $a['entity_id']]),
    $a['entity'] === 'attendance_cutoffs' && $a['entity_id'] => url('cutoff_view', ['id' => $a['entity_id']]),
    $a['entity'] === 'leave_applications' && $a['entity_id'] => url('leave_view', ['id' => $a['entity_id']]),
    $a['entity'] === 'disciplinary_cases' && $a['entity_id'] => url('case_view', ['id' => $a['entity_id']]),
    $a['entity'] === 'ledgers' && $a['entity_id'] => url('ledger_view', ['id' => $a['entity_id']]),
    $a['entity'] === 'employee_term_trans' && $a['entity_id'] => url('emp_term_view', ['id' => $a['entity_id']]),
    $a['entity'] === 'dtr' && $a['entity_id'] => url('dtr_edit', ['id' => $a['entity_id']]),
    default => null,
};

page_header('Audit Trail', [
    'subtitle' => 'Every actionable item of every user, in order: sign-ins, records created and changed, approvals, postings, imports, exports, prints, adjustments and manual overrides, with the user, the time, the record and the details. For internal audit and DOLE inspection.',
    'actions' => '<a class="btn" href="' . h(url('audit', array_merge($f, ['csv' => 1]))) . '">Export CSV</a>',
]);
?>
<form class="card filters" method="get"><input type="hidden" name="p" value="audit">
  <label>From<input type="date" name="from" value="<?= h($f['from']) ?>"></label>
  <label>To<input type="date" name="to" value="<?= h($f['to']) ?>"></label>
  <label>User<select name="user"><?= options(pairs('SELECT DISTINCT username, username FROM audit_log WHERE username IS NOT NULL ORDER BY username'), $f['user'], 'All users') ?></select></label>
  <label>Module<select name="module" data-autosubmit><?= options($modules, $f['module'], 'All modules') ?></select></label>
  <label>Action<select name="action"><?= options($actions, $f['action'], 'All actions') ?></select></label>
  <label class="grow">Search<input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="action, record, detail, record id"></label>
  <button class="btn btn-primary">Filter</button>
</form>

<div class="stats">
  <?= stat_card('Entries', number_format($total), fmt_date($f['from']) . ' to ' . fmt_date($f['to']), 'info') ?>
  <?php foreach (['pis' => 'Personnel', 'ams' => 'Attendance', 'payroll' => 'Payroll'] as $m => $lbl): ?>
    <?= stat_card($lbl, number_format((int)($byModule[$m] ?? 0)), 'entries') ?>
  <?php endforeach; ?>
  <?= stat_card('Sign-ins', number_format((int)($byModule['auth'] ?? 0)), 'successful and failed') ?>
</div>

<div class="grid grid-2-1 audit-layout">
  <div class="card flush">
    <div class="card-head"><div><h2>Entries</h2><div class="sub"><?= count($list) === 1000 ? 'Latest 1,000 of ' . number_format($total) . ' entries; narrow the filters or export the CSV.' : number_format(count($list)) . ' entr' . (count($list) === 1 ? 'y' : 'ies') ?></div></div></div>
    <div class="table-wrap table-scroll">
      <table class="table compact">
        <thead><tr><th>Timestamp</th><th>User</th><th>Module</th><th>Action</th><th>Record</th><th>Details</th><th>IP</th></tr></thead>
        <tbody>
        <?php if (!$list) echo empty_row(7, 'No activity matches these filters.');
        foreach ($list as $a): $lk = $link($a); ?>
          <tr>
            <td class="nowrap small"><?= h(fmt_dt($a['created_at'], 'Y-m-d H:i:s')) ?></td>
            <td><strong><?= h($a['username']) ?></strong></td>
            <td class="small"><?= h($modules[$a['module']] ?? strtoupper((string)$a['module'])) ?></td>
            <td><?= h(label($a['action'])) ?></td>
            <td class="small"><?php if ($lk): ?><a href="<?= h($lk) ?>"><?= h($a['entity']) ?> <?= h($a['entity_id']) ?></a><?php else: ?><?= h($a['entity']) ?> <?= h($a['entity_id']) ?><?php endif; ?></td>
            <td class="small mono audit-details"><?= h(mb_strimwidth(salary_mask_details($a['details']), 0, 400, '…')) ?></td>
            <td class="small"><?= h($a['ip_address']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <h2>Most active users</h2>
    <p class="muted small">In the selected range.</p>
    <div class="table-wrap"><table class="table table-compact">
      <thead><tr><th>User</th><th class="num">Actions</th><th>Last</th></tr></thead>
      <tbody>
      <?php if (!$byUser) echo empty_row(3, 'No activity.');
      foreach ($byUser as $u): ?>
        <tr><td><a href="<?= h(url('audit', array_merge($f, ['user' => $u['username']]))) ?>"><?= h($u['username']) ?></a></td><td class="num"><?= number_format((int)$u['n']) ?></td><td class="small nowrap"><?= h(fmt_dt($u['last_at'], 'M d, H:i')) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="help" style="margin-top:12px">What is recorded: every sign-in and failed sign-in, every record created, changed or deleted, every approval and decision, every payroll compute, finalize and adjustment, every attendance import, correction and override, every export, print and setting change. Entries cannot be edited or deleted from the screens.</p>
  </div>
</div>
<?php page_footer();
