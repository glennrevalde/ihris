<?php
/**
 * Payroll Batch Register (M.A.D.A.S.): one line per employee with every earning and deduction
 * column of the period, department sub-totals and grand totals. Print, export and the
 * "open …" shortcuts of the original form.
 *
 * Payroll Batch Register II (route pay_register2): the same register with the Others of the earnings and
 * the Others of the deductions always shown in detail, one column per item consolidated in them (for
 * example Position Allowance, Meal Subsidy, Cooperative Contribution), on screen, in print and in the CSV.
 *
 * v22 Report "Totals / Summary only" (both registers): one line per Co. / Branch, Department, Employee group
 * and / or Employee type of the payroll period (any combination), with the usual columns and the grand total.
 */
$route = (string)get('p') === 'pay_register2' ? 'pay_register2' : 'pay_register';
$regFixed = $route === 'pay_register2';
if ($regFixed) {
    $_GET['expand'] = 'ed';
}
$regTitle = $regFixed ? 'Payroll Batch Register II' : 'Payroll Batch Register';
$runs = pairs("SELECT r.id, CONCAT(r.run_no, ' — ', pg.code, ' ', DATE_FORMAT(r.period_start, '%m/%d/%y'), '-', DATE_FORMAT(r.period_end, '%m/%d/%y'), ' (', r.status, ')')
               FROM payroll_runs r JOIN pay_groups pg ON pg.id = r.pay_group_id WHERE r.status <> 'cancelled' ORDER BY r.period_end DESC, r.id DESC LIMIT 80");
$runId = (int)get('run_id', 0);
if (!$runId && get('cutoff_id')) {
    $runId = (int)val("SELECT id FROM payroll_runs WHERE cutoff_id = ? AND status <> 'cancelled' ORDER BY id DESC LIMIT 1", [(int)get('cutoff_id')]);
}
if (!$runId) {
    $runId = (int)(array_key_first($runs) ?: 0);
}
// v20: Department, Co. / Branch and Employee group take one, two or more values (lists; empty = all)
$f = ['org_unit_id' => id_list(get('org_unit_id')), 'branch_id' => id_list(get('branch_id')), 'employee_group_id' => id_list(get('employee_group_id')),
    'employee_type_id' => get('employee_type_id') ?: null, 'q' => trim((string)get('q', ''))];
$regBranches = pairs("SELECT id, CONCAT(COALESCE(branch_code, code), ' — ', name) FROM branches WHERE is_active = 1 ORDER BY company_code, branch_code");
$oneBranch = count($f['branch_id']) === 1 ? (int)$f['branch_id'][0] : 0;
$group = get('group') === '1';
// v22: Totals / Summary only, by Co. / Branch, Department, Employee group and / or Employee type
$sumView = get('view') === 'summary';
$sumBy = register_summary_by(get('by', [])) ?: ['unit'];
$viewKeep = $sumView ? ['view' => 'summary', 'by' => $sumBy] : [];
$sumDims = register_summary_dims();

if (is_post()) {
    verify_csrf();
    try {
        if (post('_action') === 'reprocess') {
            $st = payroll_compute($runId);
            flash('success', sprintf('Payroll re-processed: %d employee(s), gross %s, net %s.', $st['employees'], money($st['gross']), money($st['net'])));
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect($route, array_filter(array_merge(['run_id' => $runId], $f)));
}

if (!$runId) {
    page_header($regTitle);
    echo '<div class="card"><div class="empty-state"><h3>No payroll run yet</h3><p class="muted">Create and process a payroll run first.</p><a class="btn btn-primary" href="' . url('payroll_runs') . '">Payroll runs</a></div></div>';
    page_footer();
    return;
}
$data = register_data($runId, $f);
$run = $data['run'];
$rows = $data['rows'];
$tot = $data['totals'];
$summary = $sumView ? register_summary($data, $sumBy) : null;
$sumText = $sumView ? implode(', ', array_map(fn($d) => $sumDims[$d][0], $sumBy)) : '';

if (get('export') === 'csv') {
    [$head, $lines] = $summary ? register_summary_csv($data, $summary, (string)get('expand', '')) : register_csv($data, (string)get('expand', ''));
    csv_download(($regFixed ? 'payroll_register_ii_' : 'payroll_register_') . ($summary ? 'summary_' : '') . $run['run_no'] . '.csv', $head, $lines);
    exit;
}
if (get('print')) {
    audit('payroll', 'register_print', 'payroll_runs', $runId);
    print_header(($regFixed ? 'Payroll Register II' : 'Payroll Register') . ($summary ? ' Summary' : '') . ' — ' . $run['run_no'], true, 'legal');
    $lh = $oneBranch ? row('SELECT * FROM branches WHERE id = ?', [$oneBranch]) : null;
    echo company_letterhead($lh ?: row('SELECT * FROM branches WHERE is_default = 1 OR id = 1 ORDER BY is_default DESC LIMIT 1'));
    echo '<h1>Payroll Register' . ($regFixed ? ' II' : '') . ($summary ? ' Summary' : '') . ($lh ? ' — ' . h($lh['name']) : '') . '</h1>';
    echo '<div class="doc-meta"><div><strong>' . h($run['run_no']) . '</strong>, ' . h(RUN_TYPES[$run['run_type']] ?? $run['run_type']) . ', ' . h($run['pay_group_name']) . '</div>'
        . '<div>Period: ' . h(fmt_date($run['period_start'])) . ' – ' . h(fmt_date($run['period_end'])) . ', Pay date: ' . h(fmt_date($run['pay_date'])) . '</div>'
        . '<div>Printed: ' . h(fmt_dt(now())) . '</div></div>';
    echo report_selection_html($f, [], true);   // department, company / branch, group, type, find
    if ($summary) {
        echo '<div class="doc-meta"><div><strong>Totals by:</strong> ' . h($sumText) . '</div></div>';
    }
    include __DIR__ . '/_register_table.php';
    echo signatory_block();
    print_footer();
    exit;
}

$selEmp = null;
if ((int)get('emp', 0)) {
    foreach ($rows as $r0) {
        if ((int)$r0['item']['employee_id'] === (int)get('emp')) {
            $selEmp = ['id' => (int)get('emp'), 'name' => full_name($r0['item'])];
        }
    }
}
page_header($regTitle, [
    'subtitle' => h($run['run_no']) . ', ' . h($run['pay_group_name']) . ', ' . h(fmt_date($run['period_start'])) . ' – ' . h(fmt_date($run['period_end']))
        . ', pay date ' . h(fmt_date($run['pay_date'])),
    'wide' => true,
    'actions' => ($regFixed ? '<a class="btn" href="' . url('pay_register', array_filter(array_merge(['run_id' => $runId], $f, $viewKeep))) . '">Payroll Batch Register</a>'
        : '<a class="btn" href="' . url('pay_register2', array_filter(array_merge(['run_id' => $runId], $f, $viewKeep))) . '">Register II (Others in detail)</a>')
        . '<a class="btn" target="_blank" data-expand-link href="' . url($route, array_filter(array_merge(['run_id' => $runId, 'print' => 1, 'group' => $group && !$sumView ? 1 : null, 'expand' => get('expand') ?: null], $f, $viewKeep))) . '">Print ' . ($sumView ? 'summary' : 'register') . '</a>'
        . '<a class="btn" data-expand-link href="' . url($route, array_filter(array_merge(['run_id' => $runId, 'export' => 'csv', 'expand' => get('expand') ?: null], $f, $viewKeep))) . '">Export CSV</a>',
]);
?>
<form method="get" class="card filters">
  <input type="hidden" name="p" value="<?= $route ?>">
  <label>Payroll run<select name="run_id" data-autosubmit><?= options($runs, $runId) ?></select></label>
  <?= multi_pick('org_unit_id', 'Department', pairs('SELECT id, name FROM org_units WHERE is_active = 1 ORDER BY name'), $f['org_unit_id'], 'All departments') ?>
  <?= multi_pick('branch_id', 'Co. / Branch', $regBranches, $f['branch_id'], 'All companies') ?>
  <?= multi_pick('employee_group_id', 'Employee group', emp_group_options(), $f['employee_group_id'], 'All groups') ?>
  <label>Employee type<select name="employee_type_id"><?= options(pairs("SELECT id, CONCAT(code, ' — ', name) FROM emp_types ORDER BY code"), $f['employee_type_id'], 'All types') ?></select></label>
  <label class="grow">Find<input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="Employee name or number"></label>
  <?php if (!$sumView): ?><label class="check" style="margin-top:18px"><input type="checkbox" name="group" value="1" <?= checked($group) ?>> Sub-total by department</label><?php endif; ?>
  <fieldset class="seg-field reg-view">
    <legend>Report</legend>
    <span class="seg">
      <label><input type="radio" name="view" value="" <?= checked(!$sumView) ?> data-autosubmit><span>Per employee</span></label>
      <label><input type="radio" name="view" value="summary" <?= checked($sumView) ?> data-autosubmit><span>Totals / Summary only</span></label>
    </span>
  </fieldset>
  <?php if ($sumView): ?>
  <fieldset class="seg-field reg-by">
    <legend>Totals by</legend>
    <span class="reg-by-list">
      <?php foreach ($sumDims as $k => $d): ?><label class="check"><input type="checkbox" name="by[]" value="<?= h($k) ?>" <?= checked(in_array($k, $sumBy, true)) ?> data-autosubmit> <?= h($d[0]) ?></label><?php endforeach; ?>
    </span>
  </fieldset>
  <?php endif; ?>
  <button class="btn btn-primary">Apply</button>
</form>
<div class="rpt-selection-bar"><?= report_selection_html($f) ?></div>

<?php
$partRows = count(array_filter($rows, fn($r) => !empty($r['part'])));
if ($f['branch_id']):
    $bName = multi_pick_text(pairs('SELECT id, name FROM branches'), $f['branch_id'], ''); ?>
  <div class="callout branch-view">Showing the payroll of <strong><?= h($bName) ?></strong>: its employees and everyone who rendered hours there on a multi-location entry<?= $partRows ? ' (' . $partRows . ' shown as a branch part)' : '' ?>.
    A branch part carries only the pay earned in <?= h($bName) ?>, its share of the employer contributions, and the deductions when <?= h($bName) ?> is the deduction source, so each branch records its own payroll expense. Choose All to see every employee's pay lumped in one line.</div>
<?php
    // hours recorded in this branch on a multi-location entry that the run does not carry yet (entry saved after processing)
    $stale = $run['cutoff_id'] && $oneBranch ? rows('SELECT DISTINCT e.employee_no, e.first_name, e.last_name, e.middle_name, e.suffix
                 FROM attendance_summaries s JOIN attendance_summary_branches sb ON sb.summary_id = s.id JOIN employees e ON e.id = s.employee_id
                 JOIN payroll_items pi ON pi.run_id = ? AND pi.employee_id = s.employee_id
                 WHERE s.cutoff_id = ? AND sb.branch_id = ? AND e.default_branch_id <> sb.branch_id
                   AND NOT EXISTS (SELECT 1 FROM payroll_branch_items pb WHERE pb.item_id = pi.id AND pb.branch_id = sb.branch_id)' . user_scope_sql('e'),
        [$runId, (int)$run['cutoff_id'], $oneBranch]) : [];
    if ($stale): ?>
  <div class="callout warn">Hours in <?= h($bName) ?> were recorded on the multi-location entry of <?= h(implode(', ', array_map(fn($x) => full_name($x) . ' (' . $x['employee_no'] . ')', $stale))) ?> after this run was processed, so their pay is not split yet.
    <?= in_array($run['status'], ['draft', 'computed'], true) ? 'Press Reprocess payroll to include them.' : 'The run is finalized; the split applies to the next run processed.' ?></div>
<?php endif;
endif; ?>
<div class="toolbar">
  <span class="badge badge-<?= $run['status'] === 'finalized' ? 'green' : ($run['status'] === 'computed' ? 'amber' : 'gray') ?>"><?= h(label($run['status'])) ?></span>
  <?php $empN = count(array_unique(array_map(fn($r) => (int)$r['item']['employee_id'], $rows))); ?>
  <span class="muted small"><?= $empN ?> employee(s)<?= count($rows) > $empN ? ', ' . count($rows) . ' lines (one per company / branch worked)' : '' ?></span>
  <?php if ($sumView): ?>
  <span class="sep"></span>
  <span class="muted small">Totals by <?= h($sumText) ?>: <?= count(array_filter($summary['lines'], fn($l) => !$l['sub'])) ?> line(s).</span>
  <?php else: ?>
  <span class="sep"></span>
  <span class="reg-sel" id="regSel" data-empty="Click an employee row, then open a screen for that person"><?= $selEmp ? 'Selected: ' . h($selEmp['name']) : 'Click an employee row, then open a screen for that person' ?></span>
  <span class="sep"></span>
  <a class="btn btn-sm" href="<?= h(url_back('payslips_print', array_filter(['run_id' => $runId, 'branch_id' => $f['branch_id']]))) ?>" data-emp-link="emp">RePrint payslip</a>
  <a class="btn btn-sm" href="<?= h(url_back('pay_trans', ['cutoff_id' => $run['cutoff_id']])) ?>" data-emp-link="emp">Open payroll transaction</a>
  <a class="btn btn-sm" href="<?= h(url_back('emp_transactions', ['cutoff_id' => $run['cutoff_id']])) ?>" data-emp-link="emp">Open emp. transaction</a>
  <a class="btn btn-sm" href="<?= h(url_back('fixed_trans')) ?>" data-emp-link="emp">Open fixed transaction</a>
  <a class="btn btn-sm" href="<?= h(url_back('trans_payments')) ?>" data-emp-link="emp">Open trans. payment</a>
  <a class="btn btn-sm" href="<?= h(url_back('payroll_run', ['id' => $runId])) ?>" data-emp-link="emp">Payroll run</a>
  <a class="btn btn-sm" href="<?= h(url_back('pay_inquiry')) ?>" data-emp-link="emp" data-needs-emp>Payroll inquiry</a>
  <?php endif; ?>
  <span class="spacer"></span>
  <?php if (in_array($run['status'], ['draft', 'computed'], true)):
      // v15: the Payroll Computation form opens with the filters of this register pre-selected (department,
      // company / branch, employee group and type; the employees found by Find; the employee clicked in the list)
      $pre = ['p' => 'payroll_run', 'id' => $runId, 'compute' => 1, 'pre' => $route,
          'branch_ids' => $f['branch_id'] ?: null, 'unit_ids' => $f['org_unit_id'] ?: null,
          'employee_group_id' => count($f['employee_group_id']) === 1 ? $f['employee_group_id'][0] : null, 'employee_type_id' => $f['employee_type_id'] ?: null,
          // Find, or two or more employee groups: exactly the employees listed in the register
          'emp_list' => $f['q'] !== '' || count($f['employee_group_id']) > 1 ? implode(',', array_unique(array_map(fn($r) => (int)$r['item']['employee_id'], $rows))) : null,
          'ret_branch_id' => $oneBranch ?: null, 'ret_org_unit_id' => count($f['org_unit_id']) === 1 ? $f['org_unit_id'][0] : null, 'ret_q' => $f['q'] !== '' ? $f['q'] : null];
      $preUrl = 'index.php?' . http_build_query(array_filter($pre, fn($v) => $v !== null && $v !== '')); ?>
    <a class="btn btn-warning btn-sm" href="<?= h($preUrl) ?>" data-emp-link="emp_ids[]" title="Opens the Payroll Computation form with the filters of this register pre-selected">Reprocess payroll</a>
  <?php endif; ?>
</div>

<?php if ($regFixed): ?>
  <p class="reg-hint muted small">Every earning and deduction consolidated in <strong>Others</strong> is shown in its own column<?= !empty($data['others_e']) || !empty($data['others_d']) ? ': ' . h(implode(', ', array_merge($data['others_e'] ?? [], $data['others_d'] ?? []))) : '; this run has none' ?>.</p>
<?php elseif (!empty($data['others_e']) || !empty($data['others_d'])): ?>
  <p class="reg-hint muted small">Double-click the <strong>Others</strong> header of the earnings or the deductions to show each item in its own column; double-click again to fold them back. Print and Export follow what is shown.</p>
<?php endif; ?>
<div class="card flush reg-screen">
  <div class="table-wrap table-scroll">
    <?php include __DIR__ . '/_register_table.php'; ?>
  </div>
</div>
<div class="grid grid-4" style="margin-top:16px">
  <?= stat_card('Gross pay', money($tot['gross']), $empN . ' employee(s)', 'info') ?>
  <?= stat_card('Total deductions', money($tot['total_ded']), 'Tax ' . money($tot['ded']['wtax']), 'warn') ?>
  <?= stat_card('Net pay', money($tot['net']), 'Bank file / cash payout', 'good') ?>
  <?php $er = array_merge(['sss_er' => 0.0, 'sss_ec' => 0.0, 'phic_er' => 0.0, 'hdmf_er' => 0.0], $data['employer'] ?? []); ?>
  <?= stat_card('Employer share', money(array_sum($er)), 'SSS ' . money($er['sss_er'] + $er['sss_ec']) . ', PHIC ' . money($er['phic_er']) . ', HDMF ' . money($er['hdmf_er'])) ?>
</div>
<?php page_footer();
