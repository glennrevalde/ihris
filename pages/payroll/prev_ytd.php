<?php
/**
 * YTD Information for Previous Employer (M.A.D.A.S. YTD Info Maintenance): what a newly hired or newly
 * transferred employee earned and had deducted at the previous employer during the same year. The
 * figures are added to the year-end (annualized) tax computation, to BIR Form 2316 (taxable
 * compensation and taxes withheld by the previous employer) and to the alphalist.
 */
// PREV_YTD_FIELDS: app/engine/dataimport.php
$dimp = dimp_def('prev_ytd', null, []);   // CSV import facility on this form (app/engine/dataimport.php)
dimp_handle($dimp);

$year = (int)get('year', (int)date('Y')) ?: (int)date('Y');
$branchF = (int)get('branch_id', 0) ?: null;
$groupF = (int)get('employee_group_id', 0) ?: null;
$empId = (int)get('employee_id', 0);

if (is_post()) {
    $act = (string)post('_action');
    $eid = (int)post('employee_id');
    $yr = (int)post('year');
    try {
        $emp = $eid ? employee_row($eid) : null;
        if (!$emp) {
            throw new RuntimeException('Choose the employee.');
        }
        if ($yr < 2000 || $yr > (int)date('Y') + 1) {
            throw new RuntimeException('Enter a valid year.');
        }
        $old = row('SELECT * FROM employee_prev_ytd WHERE employee_id = ? AND year = ?', [$eid, $yr]);
        if ($act === 'delete') {
            if ($old) {
                q('DELETE FROM employee_prev_ytd WHERE id = ?', [$old['id']]);
                audit('payroll', 'prev_ytd_delete', 'employees', $eid, ['year' => $yr] + array_intersect_key($old, PREV_YTD_FIELDS));
                flash('success', 'Previous employer YTD of ' . full_name($emp) . ' for ' . $yr . ' deleted.');
            }
            redirect('prev_ytd', array_filter(['year' => $yr, 'branch_id' => $branchF, 'employee_group_id' => $groupF]));
        }
        $d = ['employer_name' => trim((string)post('employer_name')) ?: null, 'employer_tin' => trim((string)post('employer_tin')) ?: null,
            'date_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)post('date_from')) ? post('date_from') : null,
            'date_to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)post('date_to')) ? post('date_to') : null,
            'remarks' => trim((string)post('remarks')) ?: null];
        foreach (array_keys(PREV_YTD_FIELDS) as $k) {
            $v = post_num($k);
            if ($v < 0) {
                throw new RuntimeException(PREV_YTD_FIELDS[$k][0] . ' cannot be negative.');
            }
            $d[$k] = round($v, 2);
        }
        if ($d['taxable'] > $d['gross'] && $d['gross'] > 0) {
            throw new RuntimeException('YTD Taxable cannot be more than YTD Gross.');
        }
        if ($d['date_from'] && $d['date_to'] && $d['date_to'] < $d['date_from']) {
            throw new RuntimeException('The period at the previous employer ends before it starts.');
        }
        if ($old) {
            update('employee_prev_ytd', $d + ['updated_at' => now()], 'id = ?', [$old['id']]);
            audit('payroll', 'prev_ytd_update', 'employees', $eid, ['year' => $yr] + diff_fields($old, $d));
        } else {
            insert('employee_prev_ytd', $d + ['employee_id' => $eid, 'year' => $yr, 'created_by' => user_id(), 'created_at' => now(), 'updated_at' => now()]);
            audit('payroll', 'prev_ytd_create', 'employees', $eid, ['year' => $yr, 'taxable' => $d['taxable'], 'tax' => $d['tax']]);
        }
        flash('success', 'Previous employer YTD of ' . full_name($emp) . ' for ' . $yr . ' saved. It is counted in the annualized tax, BIR 2316 and the alphalist.');
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('prev_ytd', array_filter(['year' => $yr ?: $year, 'employee_id' => $eid ?: null, 'branch_id' => $branchF, 'employee_group_id' => $groupF]));
}

$w = ['1=1'];
$p = [];
if ($branchF) {
    $w[] = 'e.default_branch_id = ?';
    $p[] = $branchF;
}
if ($groupF) {
    $w[] = 'e.employee_group_id = ?';
    $p[] = $groupF;
}
$empOpts = [];
foreach (rows('SELECT e.id, e.employee_no, e.last_name, e.first_name, e.middle_name, e.suffix, e.date_hired, e.status FROM employees e WHERE ' . implode(' AND ', $w) . user_scope_sql('e') . ' ORDER BY e.last_name, e.first_name', $p) as $e) {
    $empOpts[(int)$e['id']] = full_name($e) . ' — ' . $e['employee_no'] . ($e['status'] !== 'active' ? ' (separated)' : '');
}
$emp = $empId && isset($empOpts[$empId]) ? employee_row($empId) : null;
$rec = $emp ? row('SELECT * FROM employee_prev_ytd WHERE employee_id = ? AND year = ?', [$empId, $year]) : null;
$list = rows('SELECT y.*, e.employee_no, e.last_name, e.first_name, e.middle_name, e.suffix, e.date_hired FROM employee_prev_ytd y JOIN employees e ON e.id = y.employee_id
              WHERE y.year = ? AND ' . implode(' AND ', $w) . user_scope_sql('e') . ' ORDER BY e.last_name, e.first_name', array_merge([$year], $p));
$keep = array_filter(['year' => $year, 'branch_id' => $branchF, 'employee_group_id' => $groupF]);

page_header('YTD Information for Previous Employer', [
    'subtitle' => 'Earnings and deductions of a new employee at the previous employer during the year, counted in the annualized tax, BIR 2316 and the alphalist.',
    'actions' => '<a class="btn" href="' . h(url('rpt_ytd', ['year' => $year])) . '">Year-to-Date &amp; BIR 2316</a>',
]);
echo dimp_panel($dimp);
?>
<form method="get" class="card filters">
  <input type="hidden" name="p" value="prev_ytd">
  <label>Company<select name="branch_id" data-autosubmit><?= options(pairs("SELECT id, CONCAT(COALESCE(branch_code, code), ' — ', name) FROM branches ORDER BY company_code, branch_code, code"), $branchF, 'All companies') ?></select></label>
  <label>Emp. Group<select name="employee_group_id" data-autosubmit><?= options(emp_group_options(), $groupF, 'All groups') ?></select></label>
  <label class="grow">Employee ID<select name="employee_id" data-autosubmit><?= options($empOpts, $emp ? $empId : null, 'Choose the employee') ?></select></label>
  <label>Year<input type="number" name="year" value="<?= $year ?>" min="2000" max="<?= (int)date('Y') + 1 ?>" style="width:110px"></label>
  <button class="btn btn-primary">Show</button>
</form>

<div class="grid grid-2-1">
  <div class="card">
    <?php if (!$emp): ?>
      <div class="empty-state"><h3>Choose an employee</h3><p class="muted">Pick the employee and the year above to encode what the previous employer paid and withheld.</p></div>
    <?php else: ?>
      <div class="card-head"><div><h2><?= h(full_name($emp)) ?></h2><div class="sub"><?= h($emp['employee_no']) ?>, hired <?= h(fmt_date($emp['date_hired'])) ?>, year <?= $year ?><?= $rec ? ', last saved ' . h(fmt_dt($rec['updated_at'])) : ', not encoded yet' ?></div></div></div>
      <form method="post" class="prev-ytd-form">
        <?= csrf_field() ?><input type="hidden" name="employee_id" value="<?= (int)$empId ?>"><input type="hidden" name="year" value="<?= $year ?>">
        <div class="form-grid">
          <div class="field wide"><label>Previous employer</label><input name="employer_name" maxlength="150" value="<?= h($rec['employer_name'] ?? '') ?>"></div>
          <div class="field"><label>TIN of previous employer</label><input name="employer_tin" maxlength="20" value="<?= h($rec['employer_tin'] ?? '') ?>"></div>
          <div class="field"><label>Employed from</label><input type="date" name="date_from" value="<?= h($rec['date_from'] ?? '') ?>"></div>
          <div class="field"><label>To</label><input type="date" name="date_to" value="<?= h($rec['date_to'] ?? '') ?>"></div>
        </div>
        <div class="form-section">YTD figures at the previous employer</div>
        <div class="form-grid prev-ytd-grid">
          <?php foreach (PREV_YTD_FIELDS as $k => [$lbl, $key]): ?>
            <div class="field"><label<?= $key ? ' class="req-key"' : '' ?>><?= h($lbl) ?></label><input type="number" step="0.01" min="0" name="<?= $k ?>" value="<?= h(number_format((float)($rec[$k] ?? 0), 2, '.', '')) ?>" inputmode="decimal"></div>
          <?php endforeach; ?>
          <div class="field wide"><label>Remarks</label><input name="remarks" maxlength="255" value="<?= h($rec['remarks'] ?? '') ?>"></div>
        </div>
        <p class="help">Figures marked in color are the ones used by the tax computation: YTD Taxable and YTD Tax enter the annualization and BIR 2316 (lines 22 and 25B); Gross, SSS, PhilHealth, HDMF and the 13th month complete the year's totals.</p>
        <div class="form-actions">
          <button class="btn btn-primary" name="_action" value="save">Save</button>
          <a class="btn" href="<?= h(url('prev_ytd', $keep)) ?>">Cancel</a>
          <?php if ($rec): ?><button class="btn btn-danger-outline" name="_action" value="delete" formnovalidate data-confirm="Delete the previous employer YTD of <?= h(full_name($emp)) ?> for <?= $year ?>?">Delete</button><?php endif; ?>
        </div>
      </form>
    <?php endif; ?>
  </div>
  <div class="card flush">
    <div class="card-head pad"><div><h2>Encoded for <?= $year ?></h2><div class="sub"><?= count($list) ?> employee(s)</div></div></div>
    <div class="table-wrap">
      <table class="table table-compact">
        <thead><tr><th>Employee</th><th class="num">Taxable</th><th class="num">Tax</th></tr></thead>
        <tbody>
        <?php foreach ($list as $r): ?>
          <tr class="clickable-row<?= (int)$r['employee_id'] === $empId ? ' row-sel' : '' ?>"><td><a href="<?= h(url('prev_ytd', $keep + ['employee_id' => $r['employee_id']])) ?>"><strong><?= h(full_name($r)) ?></strong></a><br><small class="muted"><?= h($r['employee_no']) ?><?= $r['employer_name'] ? ', ' . h($r['employer_name']) : '' ?></small></td>
            <td class="num"><?= money($r['taxable']) ?></td><td class="num"><?= money($r['tax']) ?></td></tr>
        <?php endforeach; ?>
        <?= $list ? '' : empty_row(3, 'None for this year.') ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php page_footer();
