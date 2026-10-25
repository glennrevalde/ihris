<?php
/** Employee's Bank Setup — edit payroll bank, account number and account type of many employees at once. */
if (is_post()) {
    $payload = json_decode((string)post('payload', '{}'), true) ?: [];
    $n = 0;
    tx(function () use ($payload, &$n) {
        foreach ($payload as $empId => $vals) {
            $empId = (int)$empId;
            $d = [];
            foreach (['bank_name', 'bank_account_no', 'bank_account_type'] as $f) {
                if (array_key_exists($f, (array)$vals)) {
                    $v = trim((string)$vals[$f]);
                    $d[$f] = $v === '' ? null : mb_substr($v, 0, $f === 'bank_account_type' ? 20 : 80);
                }
            }
            if ($d && $empId) {
                $old = row('SELECT bank_name, bank_account_no, bank_account_type FROM employees WHERE id = ?', [$empId]);
                $changes = $old ? diff_fields($old, $d) : [];
                if ($old && $changes) {
                    update('employees', $d, 'id = ?', [$empId]);
                    audit('payroll', 'bank_setup', 'employees', $empId, $changes);
                    $n++;
                }
            }
        }
    });
    flash('success', "Bank details saved for $n employee(s).");
    redirect('bank_setup', array_filter(['branch_id' => get('branch_id'), 'pay_group_id' => get('pay_group_id'), 'org_unit_id' => get('org_unit_id')]));
}
$f = ['branch_id' => get('branch_id'), 'pay_group_id' => get('pay_group_id'), 'org_unit_id' => get('org_unit_id')];
$where = ["e.status = 'active'"];
$params = [];
if ($f['branch_id']) { $where[] = 'e.default_branch_id = ?'; $params[] = (int)$f['branch_id']; }
if ($f['pay_group_id']) { $where[] = 'e.pay_group_id = ?'; $params[] = (int)$f['pay_group_id']; }
if ($f['org_unit_id']) { $where[] = 'e.org_unit_id IN (' . implode(',', array_map('intval', org_descendant_ids((int)$f['org_unit_id']))) . ')'; }
$emps = rows('SELECT e.*, ou.name AS unit_name FROM employees e LEFT JOIN org_units ou ON ou.id = e.org_unit_id WHERE ' . implode(' AND ', $where) . user_scope_sql('e') . ' ORDER BY e.last_name, e.first_name', $params);
$banks = array_filter(array_unique(array_merge(q("SELECT DISTINCT bank_name FROM employees WHERE bank_name IS NOT NULL AND bank_name <> ''")->fetchAll(PDO::FETCH_COLUMN), q("SELECT DISTINCT bank_name FROM branches WHERE bank_name IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN))));
page_header("Employee's Bank Setup", ['subtitle' => 'Payroll bank and account of each employee, used by the bank file / bank advice. Edit the cells and click Save.',
    'actions' => '<a class="btn" href="' . url('payroll_setup') . '">All setup tables</a>']);
?>
<form class="filters card" method="get"><input type="hidden" name="p" value="bank_setup">
  <label>Company / branch <select name="branch_id" data-autosubmit><?= options(branch_options(), $f['branch_id'], 'All') ?></select></label>
  <label>Pay code <select name="pay_group_id" data-autosubmit><?= options(pay_group_options(), $f['pay_group_id'], 'All') ?></select></label>
  <label>Department <select name="org_unit_id" data-autosubmit><?= options(org_unit_options(), $f['org_unit_id'], 'All') ?></select></label>
</form>
<form method="post" class="card" data-json-grid>
  <?= csrf_field() ?><input type="hidden" name="payload" value="{}">
  <datalist id="bankList"><?php foreach ($banks as $b): ?><option value="<?= h($b) ?>"><?php endforeach; ?></datalist>
  <div class="table-tools"><input type="search" class="input table-search" placeholder="Search employee..." data-target="bank-table"><span class="muted"><?= count($emps) ?> employee(s)</span>
    <span class="spacer"></span><span class="dirty-count muted"></span><button class="btn btn-primary">Save changes</button></div>
  <div class="table-wrap"><table class="table table-compact" id="bank-table">
    <thead><tr><th>Employee ID</th><th>Employee name</th><th>Department</th><th style="width:22%">Bank</th><th style="width:20%">Account no.</th><th style="width:14%">Account type</th></tr></thead>
    <tbody>
    <?php if (!$emps) echo empty_row(6); ?>
    <?php foreach ($emps as $e): $id = (int)$e['id']; ?>
      <tr>
        <td><?= h($e['employee_no']) ?></td><td><?= h(full_name($e)) ?></td><td class="small muted"><?= h($e['unit_name']) ?></td>
        <td><input class="input input-sm" list="bankList" data-cell data-row="<?= $id ?>" data-field="bank_name" value="<?= h($e['bank_name']) ?>"></td>
        <td><input class="input input-sm" data-cell data-row="<?= $id ?>" data-field="bank_account_no" value="<?= h($e['bank_account_no']) ?>"></td>
        <td><select class="input input-sm" data-cell data-row="<?= $id ?>" data-field="bank_account_type"><?= options(EMP_ACCOUNT_TYPES, $e['bank_account_type'], '—') ?></select></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</form>
<?php page_footer();
