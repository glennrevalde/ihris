<?php
/**
 * Department payroll budgets used for budget-variance analysis in the Monthly Payroll Summary.
 */
$year = (int)get('year', date('Y'));
$units = rows("SELECT * FROM org_units WHERE is_active = 1 AND unit_type IN ('Department','Division') ORDER BY sort_order, name");
if (is_post()) {
    $vals = (array)post('b', []);
    $n = 0;
    foreach ($vals as $ou => $months) {
        foreach ((array)$months as $mm => $v) {
            $v = str_replace(',', '', (string)$v);
            if ($v === '') {
                q('DELETE FROM department_budgets WHERE org_unit_id = ? AND year = ? AND month = ?', [(int)$ou, $year, (int)$mm]);
                continue;
            }
            q('INSERT INTO department_budgets (org_unit_id, year, month, budget_amount) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE budget_amount = VALUES(budget_amount)', [(int)$ou, $year, (int)$mm, (float)$v]);
            $n++;
        }
    }
    audit('admin', 'budgets_update', 'department_budgets', null, ['year' => $year, 'cells' => $n]);
    flash('success', 'Budgets saved.');
    redirect('budgets', ['year' => $year]);
}
$b = [];
foreach (rows('SELECT * FROM department_budgets WHERE year = ?', [$year]) as $r) {
    $b[$r['org_unit_id']][$r['month']] = $r['budget_amount'];
}
page_header('Department Payroll Budgets', ['subtitle' => 'Monthly payroll budget (gross + employer contributions) per department / division. Used in the Monthly Payroll Summary variance column.', 'back' => url('rpt_monthly')]);
?>
<form class="card filters" method="get"><input type="hidden" name="p" value="budgets"><label>Year<input type="number" name="year" value="<?= $year ?>" style="width:100px" data-autosubmit></label></form>
<form method="post" class="card"><?= csrf_field() ?>
  <div class="table-wrap"><table class="table compact"><thead><tr><th>Unit</th><?php for ($m = 1; $m <= 12; $m++): ?><th class="num"><?= date('M', mktime(0, 0, 0, $m, 1)) ?></th><?php endfor; ?></tr></thead><tbody>
  <?php foreach ($units as $u): ?><tr><td class="nowrap"><?= h($u['name']) ?> <span class="muted small"><?= h($u['unit_type']) ?></span></td>
    <?php for ($m = 1; $m <= 12; $m++): ?><td><input type="number" step="1" name="b[<?= $u['id'] ?>][<?= $m ?>]" value="<?= h(isset($b[$u['id']][$m]) ? (float)$b[$u['id']][$m] : '') ?>" style="min-width:95px"></td><?php endfor; ?></tr><?php endforeach; ?>
  </tbody></table></div>
  <div class="form-actions"><button class="btn btn-primary">Save budgets</button></div>
</form>
<?php page_footer();
