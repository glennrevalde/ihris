<?php
$f = [
    'q' => get('q', ''), 'status' => get('status', 'active'), 'ou' => get('ou', ''), 'branch' => get('branch', ''),
    'pg' => get('pg', ''), 'es' => get('es', ''),
];
$where = ['1=1'];
$params = [];
if ($f['status'] !== 'all' && $f['status'] !== '') {
    $where[] = 'e.status = ?';
    $params[] = $f['status'];
}
if ($f['ou'] !== '') {
    $ids = org_descendant_ids((int)$f['ou']);
    $where[] = 'e.org_unit_id IN (' . in_list($ids) . ')';
    $params = array_merge($params, $ids);
}
foreach (['branch' => 'e.default_branch_id', 'pg' => 'e.pay_group_id', 'es' => 'e.employment_status'] as $k => $col) {
    if ($f[$k] !== '') {
        $where[] = "$col = ?";
        $params[] = $f[$k];
    }
}
if ($f['q'] !== '') {
    // every word typed must match (name, employee no., position or biometric ID)
    $where[] = search_words_sql(['e.employee_no', 'e.last_name', 'e.first_name', 'e.middle_name', 'e.position', 'e.biometric_id'], $f['q'], $params);
}
$list = rows('SELECT e.*, ou.name AS ou_name, b.code AS branch_code, pg.name AS pg_name, sg.code AS grade_code
              FROM employees e LEFT JOIN org_units ou ON ou.id = e.org_unit_id LEFT JOIN branches b ON b.id = e.default_branch_id
              LEFT JOIN pay_groups pg ON pg.id = e.pay_group_id LEFT JOIN salary_grades sg ON sg.id = e.salary_grade_id
              WHERE ' . implode(' AND ', $where) . user_scope_sql('e') . ' ORDER BY e.last_name, e.first_name', $params);
$seeSalary = salary_can('view');   // Basic Salary view right (app/engine/scope.php)

if (get('export')) {
    audit('pis', 'export', 'employees', null, ['count' => count($list)]);
    $head = ['Employee No', 'Last Name', 'First Name', 'Middle Name', 'Gender', 'Birth Date', 'Civil Status', 'Position', 'Unit', 'Branch', 'Pay Group',
        'Employment Status', 'Date Hired', 'Rate Type', 'Basic Rate', 'Grade', 'SSS', 'PhilHealth', 'Pag-IBIG', 'TIN', 'Email', 'Status'];
    $data = array_map(fn($e) => [$e['employee_no'], $e['last_name'], $e['first_name'], $e['middle_name'], $e['gender'], $e['birth_date'], $e['civil_status'],
        $e['position'], $e['ou_name'], $e['branch_code'], $e['pg_name'], $e['employment_status'], $e['date_hired'], $e['rate_type'], $e['basic_rate'],
        $e['grade_code'] . ($e['salary_step'] ? '-' . $e['salary_step'] : ''), $e['sss_no'], $e['philhealth_no'], $e['pagibig_no'], $e['tin'], $e['email'], $e['status']], $list);
    if (!$seeSalary) {   // no Basic Salary view right: the column is left out
        unset($head[14]);
        $data = array_map(function ($r) { unset($r[14]); return array_values($r); }, $data);
        $head = array_values($head);
    }
    csv_download('employee_masterfile_' . date('Ymd') . '.csv', $head, $data);
}

$actions = can('employees.export') ? '<a class="btn" href="' . h(url('employees', array_merge($f, ['export' => 1]))) . '">Export CSV</a>' : '';
if (can('rpt_201_master.view')) {
    $actions .= ' <a class="btn" href="' . url('rpt_201_master') . '">201 File Masterlisting</a>';
}
if (can('employee_batch.view')) {
    $actions .= ' <a class="btn" href="' . h(url('employee_batch', array_filter(['branch_id' => $f['branch'] ?: null, 'org_unit_id' => $f['ou'] ?: null, 'q' => $f['q'] ?: null]))) . '">Batch edit</a>';
}
if (can('employees.import')) {
    $actions .= ' <a class="btn" href="' . url('employee_import') . '">Import CSV</a>';
}
if (can('employees.create')) {
    $actions .= ' <a class="btn btn-primary" href="' . url('employee_edit') . '">+ New employee</a>';
}
page_header('Employee Masterfile', ['subtitle' => 'Digital 201 files — the single source of truth for every downstream process.', 'actions' => $actions]);
?>
<form class="card filters" method="get" data-live="#empResults">
  <input type="hidden" name="p" value="employees">
  <label class="grow">Search<input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="Type a name, employee no., position or biometric ID" autocomplete="off"></label>
  <label>Unit<select name="ou"><?= options(org_unit_options(), $f['ou'], 'All units') ?></select></label>
  <label>Branch<select name="branch"><?= options(branch_options(false), $f['branch'], 'All branches') ?></select></label>
  <label>Pay group<select name="pg"><?= options(pay_group_options(), $f['pg'], 'All') ?></select></label>
  <label>Employment<select name="es"><?= options(EMPLOYMENT_STATUSES, $f['es'], 'All') ?></select></label>
  <label>Status<select name="status"><?= options(['active' => 'Active', 'separated' => 'Separated', 'all' => 'All'], $f['status']) ?></select></label>
  <button class="btn btn-primary">Filter</button>
</form>
<div class="card" id="empResults">
  <div class="table-tools"><span class="muted"><?= count($list) ?> employee(s)<?= $f['q'] !== '' ? ' matching "' . h($f['q']) . '"' : '' ?></span><span class="live-hint">The list narrows as you type.</span></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Employee</th><th>Position</th><th>Unit</th><th>Branch</th><th>Employment</th><?php if ($seeSalary): ?><th class="num">Rate</th><?php endif; ?><th>Status</th><th class="actions"></th></tr></thead>
    <tbody>
    <?php if (!$list) echo empty_row($seeSalary ? 8 : 7, scope_def()['none'] ? 'No company / branch or department is assigned to your account, so no employee is shown. Ask your system administrator.' : 'No records found.'); ?>
    <?php foreach ($list as $e): ?>
      <tr>
        <td><a href="<?= url('employee_view', ['id' => $e['id']]) ?>"><strong><?= h(full_name($e)) ?></strong></a><br><span class="muted small"><?= h($e['employee_no']) ?><?= $e['biometric_id'] ? ', Bio ' . h($e['biometric_id']) : '' ?></span></td>
        <td><?= h($e['position']) ?><br><span class="muted small"><?= h($e['grade_code'] ? $e['grade_code'] . ($e['salary_step'] ? ' step ' . $e['salary_step'] : '') : '') ?></span></td>
        <td><?= h($e['ou_name']) ?></td>
        <td><?= h($e['branch_code']) ?></td>
        <td><?= h(label($e['employment_status'])) ?><br><span class="muted small">since <?= h(fmt_date($e['date_hired'])) ?></span></td>
        <?php if ($seeSalary): ?><td class="num"><?= money($e['basic_rate']) ?><br><span class="muted small">/<?= $e['rate_type'] === 'daily' ? 'day' : 'month' ?></span></td><?php endif; ?>
        <td><?= badge($e['status']) ?></td>
        <td class="actions"><a class="btn btn-sm" href="<?= url('employee_view', ['id' => $e['id']]) ?>">View</a><?php if (can('employees.edit')): ?><a class="btn btn-sm" href="<?= url('employee_edit', ['id' => $e['id']]) ?>">Edit</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php page_footer();
