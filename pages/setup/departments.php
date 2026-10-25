<?php
/** Department Setup — flat view of the organization tree: Code, Section, Department, Division, Group. */
$dimp = dimp_def('departments', null, []);   // CSV import facility on this form (app/engine/dataimport.php)
dimp_handle($dimp);
$units = rows('SELECT * FROM org_units ORDER BY code');
$byId = [];
foreach ($units as $u) {
    $byId[(int)$u['id']] = $u;
}
$ancestor = function (array $u, array $types) use ($byId): string {
    $cur = $u;
    $guard = 0;
    while ($cur && $guard++ < 20) {
        if (in_array(strtolower((string)$cur['unit_type']), $types, true)) {
            return (string)$cur['name'];
        }
        $cur = $cur['parent_id'] ? ($byId[(int)$cur['parent_id']] ?? null) : null;
    }
    return '';
};
$empCounts = pairs("SELECT org_unit_id, COUNT(*) FROM employees WHERE status = 'active' AND org_unit_id IS NOT NULL GROUP BY org_unit_id");
page_header('Department Table', [
    'subtitle' => 'Departments and sections with their division and group, derived from the organization structure. Add, rename or move units in the organization tree.',
    'actions' => '<a class="btn btn-primary" href="' . url('org_units') . '">Edit organization tree</a> <a class="btn" href="' . url('payroll_setup') . '">All setup tables</a>',
]);
echo dimp_panel($dimp);
?>
<div class="card">
  <div class="table-tools"><input type="search" class="input table-search" placeholder="Search..." data-target="dept-table"><span class="muted"><?= count($units) ?> unit(s)</span></div>
  <div class="table-wrap"><table class="table" id="dept-table">
    <thead><tr><th>Code</th><th>Section name</th><th>Department name</th><th>Division</th><th>Group</th><th>Type</th><th class="num">Employees</th><th>Status</th></tr></thead>
    <tbody>
    <?php if (!$units) echo empty_row(8); ?>
    <?php foreach ($units as $u):
        $dept = $ancestor($u, ['department']) ?: $u['name'];
        $div = $ancestor($u, ['division']);
        $grp = $ancestor($u, ['group', 'company']); ?>
      <tr>
        <td><strong><?= h($u['code']) ?></strong></td>
        <td><?= h($u['name']) ?></td>
        <td><?= h($dept) ?></td>
        <td><?= h($div) ?></td>
        <td><?= h($grp) ?></td>
        <td><span class="badge badge-gray"><?= h($u['unit_type']) ?></span></td>
        <td class="num"><?= (int)($empCounts[$u['id']] ?? 0) ?></td>
        <td><?= $u['is_active'] ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-gray">Inactive</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php page_footer();
