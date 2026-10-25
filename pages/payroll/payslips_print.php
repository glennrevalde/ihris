<?php
/**
 * Batch payslip printing: every payslip of a payroll run (or a filtered selection),
 * printed 1 or 2 per page, on screen (print) or as one PDF file.
 */
$runId = (int)get('run_id', 0);
$runs = pairs("SELECT r.id, CONCAT(r.run_no, ' — ', pg.code, ' ', DATE_FORMAT(r.period_start, '%m/%d/%y'), '-', DATE_FORMAT(r.period_end, '%m/%d/%y'), ' (', r.status, ')')
               FROM payroll_runs r JOIN pay_groups pg ON pg.id = r.pay_group_id WHERE r.status IN ('computed','finalized') ORDER BY r.period_end DESC, r.id DESC LIMIT 60");
if (!$runId) {
    $runId = (int)(array_key_first($runs) ?: 0);
}
$run = $runId ? payroll_run($runId) : null;
// v20: Department, Company / branch and Employee group take one, two or more values (lists; empty = all)
$unitIds = id_list(get('org_unit_id'));
$branchIds = id_list(get('branch_id'));
$empId = (int)get('emp', 0);
$groupIds = id_list(get('employee_group_id'));   // v16: Employee group filter
$branchId = count($branchIds) === 1 ? $branchIds[0] : 0;
$fq = ['org_unit_id' => $unitIds ?: null, 'branch_id' => $branchIds ?: null, 'employee_group_id' => $groupIds ?: null];
$split = (bool)get('split');   // a separate payslip for each company / branch / division the employee worked in

$where = ['pi.run_id = ?'];
$params = [$runId];
if ($unitIds) {
    $where[] = sql_in('pi.org_unit_id', org_units_with_descendants($unitIds));
}
if ($branchIds) {
    // companies / branches: their employees and everyone who worked there on a multi-location entry; those get the
    // payslip of each branch chosen (only the pay earned there). All companies: one consolidated payslip each (or split)
    $where[] = '(' . sql_in('pi.default_branch_id', $branchIds) . ' OR EXISTS (SELECT 1 FROM payroll_branch_items pb WHERE pb.item_id = pi.id AND ' . sql_in('pb.branch_id', $branchIds) . '))';
}
if ($empId) {
    $where[] = 'pi.employee_id = ?';
    $params[] = $empId;
}
if ($groupIds) {
    $where[] = sql_in('e.employee_group_id', $groupIds);
}
$ids = $run ? array_map('intval', q('SELECT pi.id FROM payroll_items pi JOIN employees e ON e.id = pi.employee_id WHERE ' . implode(' AND ', $where)
    . user_scope_sql('e') . ' ORDER BY e.last_name, e.first_name', $params)->fetchAll(PDO::FETCH_COLUMN)) : [];

$mode = (string)get('out', '');
if ($mode && $ids) {
    $items = [];
    foreach ($ids as $iid) {
        $x = payslip_item($iid);
        if (!$x) {
            continue;
        }
        $mine = $branchIds && count($x['branches']) > 1 ? array_values(array_filter($branchIds, fn($b) => in_array($b, array_map('intval', array_column($x['branches'], 'branch_id')), true))) : [];
        if ($mine) {
            foreach ($mine as $b) {   // the payslip of each company / branch chosen where the employee worked
                $items[] = $x + ['_branch' => $b];
            }
        } elseif ($split && count($x['branches']) > 1) {
            foreach ($x['branches'] as $br) {
                $items[] = $x + ['_branch' => (int)$br['branch_id']];
            }
        } else {
            $items[] = $x;
        }
    }
    $copy = (bool)get('copy');
    foreach ($ids as $iid) {
        payslip_mark_printed($iid);
    }
    audit('payroll', 'payslip_batch_' . ($mode === 'pdf' ? 'pdf' : 'print'), 'payroll_runs', $runId, ['payslips' => count($items)]);
    if ($mode === 'pdf') {
        $pdf = payslips_pdf($items, $copy);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="payslips_' . $run['run_no'] . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }
    print_header('Payslips — ' . $run['run_no']);
    $per = max(1, min(2, (int)setting('payslip_per_page', 2)));
    foreach ($items as $i => $x) {
        echo payslip_html($x, ['copy' => $copy || payslip_is_copy($x), 'cut' => $i % $per !== 0, 'branch_id' => $x['_branch'] ?? null]);
        if ($per > 1 && $i % $per === $per - 1 && $i !== count($items) - 1) {
            echo '<div class="page-break"></div>';
        }
    }
    print_footer();
    exit;
}

page_header('Print Payslips', [
    'subtitle' => 'Print or download the payslips of a whole payroll run, a department, a branch or one employee. Reprints are marked ( COPY ).',
    'actions' => '<a class="btn" href="' . url('pay_register', ['run_id' => $runId]) . '">Payroll register</a>',
]);
?>
<form method="get" class="card filters">
  <input type="hidden" name="p" value="payslips_print">
  <label>Payroll run<select name="run_id" data-autosubmit><?= options($runs, $runId, $runs ? null : 'No payroll run yet') ?></select></label>
  <?= multi_pick('org_unit_id', 'Department', pairs('SELECT id, name FROM org_units WHERE is_active = 1 ORDER BY name'), $unitIds, 'All departments') ?>
  <?= multi_pick('branch_id', 'Company / branch', pairs("SELECT id, CONCAT(COALESCE(branch_code, code), ' — ', name) FROM branches WHERE is_active = 1 ORDER BY company_code, branch_code"), $branchIds, 'All companies') ?>
  <?= multi_pick('employee_group_id', 'Employee group', emp_group_options(), $groupIds, 'All groups') ?>
  <label>Employee<select name="emp"><?= options(employee_options(false), $empId, 'All employees') ?></select></label>
  <label class="check" style="margin-top:18px" title="With All companies selected. A company / branch filter always prints the payslip of that branch for employees who worked in several locations."><input type="checkbox" name="split" value="1"<?= checked($split) ?>> Separate payslip per company / branch / division</label>
  <?php if (back_url()): ?><input type="hidden" name="back" value="<?= h(back_url()) ?>"><?php endif; ?>
  <button class="btn btn-primary">Apply</button>
</form>

<?php if (!$run): ?>
  <div class="card"><div class="empty-state"><h3>No payroll run</h3><p class="muted">Process a payroll run first.</p><a class="btn btn-primary" href="<?= url('payroll_runs') ?>">Payroll runs</a></div></div>
<?php else: ?>
<div class="toolbar">
  <span class="label"><?= h($run['run_no']) ?>, <?= h($run['pay_group_name']) ?></span>
  <span class="badge badge-<?= $run['status'] === 'finalized' ? 'green' : 'amber' ?>"><?= h(label($run['status'])) ?></span>
  <span class="muted small"><?= count($ids) ?> payslip(s) selected, <?= h(setting('payslip_per_page', 2)) ?> per page</span>
  <span class="spacer"></span>
  <a class="btn btn-primary" target="_blank" href="<?= h(url('payslips_print', array_filter(['run_id' => $runId, 'org_unit_id' => $unitIds, 'branch_id' => $branchIds, 'employee_group_id' => $groupIds, 'emp' => $empId, 'split' => $split ? 1 : null, 'out' => 'print']))) ?>">Print payslips</a>
  <a class="btn" href="<?= h(url('payslips_print', array_filter(['run_id' => $runId, 'org_unit_id' => $unitIds, 'branch_id' => $branchIds, 'employee_group_id' => $groupIds, 'emp' => $empId, 'split' => $split ? 1 : null, 'out' => 'pdf']))) ?>">Download PDF</a>
  <a class="btn" target="_blank" href="<?= h(url('payslips_print', array_filter(['run_id' => $runId, 'org_unit_id' => $unitIds, 'branch_id' => $branchIds, 'employee_group_id' => $groupIds, 'emp' => $empId, 'split' => $split ? 1 : null, 'out' => 'print', 'copy' => 1]))) ?>">Reprint as ( COPY )</a>
  <a class="btn" href="<?= url('payroll_params') ?>#payslip">Payslip parameters</a>
</div>
<div class="rpt-selection-bar"><?= report_selection_html($fq + ['emp' => $empId ?: null]) ?></div>
<?php
$parts = [];
if ($branchIds && $ids) {
    foreach (rows('SELECT pb.*, b.name AS part_branch_name, COALESCE(b.branch_code, b.code) AS part_branch_code FROM payroll_branch_items pb
                   JOIN branches b ON b.id = pb.branch_id WHERE pb.item_id IN (' . implode(',', $ids) . ')') as $pb) {
        $parts[(int)$pb['item_id']][] = $pb;
    }
    $branchName = multi_pick_text(pairs('SELECT id, name FROM branches'), $branchIds, '');
    echo '<div class="callout branch-view">Showing <strong>' . h($branchName) . '</strong>. Employees who worked in several locations get the <strong>' . h($branchName)
        . '</strong> payslip, with only the pay earned there; the deductions appear on the payslip of the deduction source. Choose All to print one consolidated payslip per employee.</div>';
}
?>
<div class="card flush">
  <div class="table-wrap table-scroll">
    <table class="table table-compact">
      <thead><tr><th>Employee</th><th>Department</th><th class="num">Gross</th><th class="num">Deductions</th><th class="num">Net pay</th><th class="num">Printed</th><th class="actions"></th></tr></thead>
      <tbody>
      <?php foreach (rows('SELECT pi.*, e.employee_no, e.first_name, e.last_name, e.middle_name, e.suffix, ou.name AS unit FROM payroll_items pi
                           JOIN employees e ON e.id = pi.employee_id LEFT JOIN org_units ou ON ou.id = pi.org_unit_id
                           WHERE pi.id IN (' . implode(',', array_map('intval', $ids ?: [0])) . ')' . user_scope_sql('e') . ' ORDER BY e.last_name, e.first_name') as $x):
          // one line per company / branch chosen where a multi-location employee worked (the payslip of that branch)
          $xParts = [];
          foreach ($branchIds as $bid) {
              if ($pt = register_branch_part($parts[(int)$x['id']] ?? [], $bid)) {
                  $xParts[] = $pt;
              }
          }
          foreach ($xParts ?: [null] as $part):
          $gross = $part ? (float)$part['gross'] : (float)$x['gross_pay'];
          $ded = $part ? ($part['is_home'] ? (float)$x['total_deductions'] : 0.0) : (float)$x['total_deductions'];
          $psArgs = array_filter(['id' => $x['id'], 'branch' => $part ? (int)$part['branch_id'] : null]); ?>
        <tr<?= $part ? ' class="is-part"' : '' ?>>
          <td><strong><?= h(full_name($x)) ?></strong><br><small class="muted"><?= h($x['employee_no']) ?></small>
            <?php if ($part): ?><small class="part-tag"><?= h($part['part_branch_code']) ?> payslip, part of <?= (int)$part['locations'] ?> locations<?= $part['is_home'] ? '' : ', deductions at ' . h($part['source_code']) ?></small><?php endif; ?></td>
          <td><?= h($x['unit'] ?: '—') ?></td>
          <td class="num"><?= money($gross) ?></td>
          <td class="num"><?= money($ded) ?></td>
          <td class="num"><strong><?= money(round($gross - $ded, 2)) ?></strong></td>
          <td class="num"><?= (int)$x['print_count'] ? (int)$x['print_count'] . '×' : '' ?></td>
          <td class="actions">
            <a class="btn btn-xs" href="<?= h(url_back('payslip', $psArgs)) ?>">View</a>
            <a class="btn btn-xs" href="<?= h(url('payslip', $psArgs + ['pdf' => 1])) ?>">PDF</a>
          </td>
        </tr>
      <?php endforeach; endforeach; ?>
      <?= $ids ? '' : empty_row(7, 'No payslip matches these filters.') ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif;
page_footer();
