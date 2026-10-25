<?php
/**
 * Customizable departments: unlimited multi-level organizational tree (Division → Department → Section → Unit ...).
 */
if (is_post()) {
    $act = post('_action');
    $id = (int)post('id', 0);
    if ($act === 'delete') {
        $kids = (int)val('SELECT COUNT(*) FROM org_units WHERE parent_id = ?', [$id]);
        $emps = (int)val('SELECT COUNT(*) FROM employees WHERE org_unit_id = ?', [$id]);
        if ($kids || $emps) {
            flash('error', "Cannot delete: the unit still has $kids sub-unit(s) and $emps employee(s). Move them first.");
        } else {
            $old = row('SELECT * FROM org_units WHERE id = ?', [$id]);
            q('DELETE FROM org_units WHERE id = ?', [$id]);
            audit('pis', 'delete', 'org_units', $id, $old);
            flash('success', 'Unit deleted.');
        }
        redirect('org_units');
    }
    if ($act === 'save') {
        $d = [
            'parent_id' => post_null('parent_id'), 'code' => strtoupper((string)post('code')), 'name' => post('name'), 'unit_type' => post('unit_type') ?: 'Department',
            'head_employee_id' => post_null('head_employee_id'), 'default_pay_group_id' => post_null('default_pay_group_id'),
            'sort_order' => (int)post('sort_order', 0), 'is_active' => post('is_active') ? 1 : 0,
        ];
        if (!$d['code'] || !$d['name']) {
            flash('error', 'Code and name are required.');
            redirect('org_units', $id ? ['edit' => $id] : ['new' => 1]);
        }
        if ($id && $d['parent_id'] && in_array((int)$d['parent_id'], org_descendant_ids($id), true)) {
            flash('error', 'A unit cannot be placed under itself or one of its sub-units.');
            redirect('org_units', ['edit' => $id]);
        }
        try {
            if ($id) {
                $old = row('SELECT * FROM org_units WHERE id = ?', [$id]);
                update('org_units', $d, 'id = ?', [$id]);
                audit('pis', 'update', 'org_units', $id, diff_fields($old, $d));
            } else {
                $id = insert('org_units', $d);
                audit('pis', 'create', 'org_units', $id, $d);
            }
            flash('success', 'Organizational unit saved. Approval routing and reports use the updated structure immediately.');
        } catch (PDOException $e) {
            flash('error', 'Save failed: the unit code must be unique.');
        }
        redirect('org_units');
    }
}
org_units_all(true);
$all = org_units_all();
$counts = pairs("SELECT org_unit_id, COUNT(*) FROM employees WHERE status = 'active' AND org_unit_id IS NOT NULL GROUP BY org_unit_id");
$heads = pairs("SELECT id, CONCAT(first_name, ' ', last_name) FROM employees");
$editId = (int)get('edit', 0);
$editing = $editId ? ($all[$editId] ?? null) : (get('new') ? ['parent_id' => get('parent'), 'unit_type' => 'Department', 'is_active' => 1, 'sort_order' => 0] : null);

function org_total(int $id, array $counts): int
{
    $n = 0;
    foreach (org_descendant_ids($id) as $d) {
        $n += (int)($counts[$d] ?? 0);
    }
    return $n;
}
function org_render(?int $parent, array $all, array $counts, array $heads): string
{
    $kids = array_filter($all, fn($u) => ($parent === null && $u['parent_id'] === null) || ($parent !== null && (int)$u['parent_id'] === $parent));
    if (!$kids) {
        return '';
    }
    $html = '<ul>';
    foreach ($kids as $u) {
        $html .= '<li><span class="tree-node' . ($u['is_active'] ? '' : ' muted') . '"><span class="type">' . h($u['unit_type']) . '</span> <strong>' . h($u['name']) . '</strong>'
            . ' <span class="muted small">' . h($u['code']) . '</span>'
            . ($u['head_employee_id'] ? ' <span class="small">Head: ' . h($heads[$u['head_employee_id']] ?? '') . '</span>' : '')
            . ' <span class="badge badge-blue">' . org_total((int)$u['id'], $counts) . '</span>'
            . ' <a class="small" href="' . url('org_units', ['edit' => $u['id']]) . '">Edit</a>'
            . ' <a class="small" href="' . url('org_units', ['new' => 1, 'parent' => $u['id']]) . '">+ Sub-unit</a>'
            . ' <a class="small" href="' . url('rpt_departments', ['ou' => $u['id']]) . '">Report</a>'
            . ' <a class="small" href="' . url('employees', ['ou' => $u['id']]) . '">Employees</a>'
            . '</span>' . org_render((int)$u['id'], $all, $counts, $heads) . '</li>';
    }
    return $html . '</ul>';
}

page_header('Organization Structure', ['subtitle' => 'Define unlimited divisions, departments, sections and units. Approvals (leave, overtime, discipline) route through the unit heads automatically.',
    'actions' => '<a class="btn btn-primary" href="' . url('org_units', ['new' => 1]) . '">+ Add unit</a>']);
if ($editing !== null): ?>
<div class="card form-card"><h2><?= $editId ? 'Edit unit' : 'New unit' ?></h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="save"><input type="hidden" name="id" value="<?= $editId ?>">
    <div class="form-grid">
      <div class="field"><label>Parent unit</label><select name="parent_id"><?= options(array_diff_key(org_unit_options(), $editId ? array_flip(org_descendant_ids($editId)) : []), $editing['parent_id'] ?? null, '— (top level)') ?></select></div>
      <div class="field"><label>Code</label><input name="code" value="<?= h($editing['code'] ?? '') ?>" required></div>
      <div class="field"><label>Name</label><input name="name" value="<?= h($editing['name'] ?? '') ?>" required></div>
      <div class="field"><label>Unit type</label><input name="unit_type" list="unitTypes" value="<?= h($editing['unit_type'] ?? '') ?>">
        <datalist id="unitTypes"><option>Company</option><option>Division</option><option>Department</option><option>Section</option><option>Unit</option><option>Team</option></datalist></div>
      <div class="field"><label>Unit head (department head)</label><select name="head_employee_id"><?= options(employee_options(), $editing['head_employee_id'] ?? null, '—') ?></select></div>
      <div class="field"><label>Default pay group</label><select name="default_pay_group_id"><?= options(pay_group_options(), $editing['default_pay_group_id'] ?? null, '—') ?></select></div>
      <div class="field"><label>Sort order</label><input type="number" name="sort_order" value="<?= h($editing['sort_order'] ?? 0) ?>"></div>
      <div class="field"><label class="check"><input type="checkbox" name="is_active" value="1"<?= checked($editing['is_active'] ?? 1) ?>> Active</label></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary">Save</button> <a class="btn" href="<?= url('org_units') ?>">Cancel</a></div>
  </form>
  <?php if ($editId): ?>
  <form method="post" data-confirm="Delete this unit?" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="<?= $editId ?>"><button class="btn btn-sm btn-danger-outline">Delete unit</button></form>
  <?php endif; ?>
</div>
<?php endif; ?>
<div class="card">
  <div class="card-head"><h2>Organizational tree</h2><span class="muted small">Badge = active headcount including sub-units</span></div>
  <div class="tree"><?= org_render(null, $all, $counts, $heads) ?></div>
</div>
<?php page_footer();
