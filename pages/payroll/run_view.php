<?php
$id = (int)get('id', 0);
$run = payroll_run($id);
if (!$run) {
    throw new RuntimeException('Payroll run not found.');
}
$editable = in_array($run['status'], ['draft', 'computed'], true);
if (is_post()) {
    $act = post('_action');
    if ($act === 'compute' && $editable) {
        // Payroll Computation form
        $opt = [
            'branch_ids' => (array)post('branch_ids', []), 'unit_ids' => (array)post('unit_ids', []), 'emp_ids' => (array)post('emp_ids', []),
            'employee_group_id' => (int)post('employee_group_id') ?: null, 'employee_type_id' => (int)post('employee_type_id') ?: null,
            'tax_table' => (string)post('tax_table', ''), 'annualize' => post('annualize') === '1', 'regular' => post('regular', '1') === '1',
            'thirteenth' => post('thirteenth') === '1', 'selection' => post('processing') === 'selection',
            'emp_from' => (string)post('emp_from', ''), 'emp_to' => (string)post('emp_to', ''),
        ];
        $st = payroll_compute($id, $opt);
        flash('success', sprintf('%s %d employee(s): gross %s, net %s.', $st['partial'] ? 'Processed the selected' : 'Processed', $st['employees'], peso($st['gross']), peso($st['net'])));
        // opened from the Payroll Batch Register (I or II): back to it with its filters
        $ret = (string)post('return_url', '');
        if ($ret !== '' && preg_match('#^index\.php\?p=pay_register2?(&[A-Za-z0-9_%\[\]=.\-+&]*)?$#', $ret)) {
            header('Location: ' . $ret);
            exit;
        }
    } elseif ($act === 'finalize') {
        payroll_finalize($id);
        flash('success', $run['run_type'] === 'final_pay' ? 'Final pay finalized and posted.'
            : 'Payroll finalized and payslips released. Close the period in Period Closing to post the ledgers, deductions, accruals and leaves and lock the attendance.');
    } elseif ($act === 'cancel' && $editable) {
        update('payroll_runs', ['status' => 'cancelled'], 'id = ?', [$id]);
        q('DELETE FROM payroll_items WHERE run_id = ?', [$id]);
        audit('payroll', 'run_cancel', 'payroll_runs', $id);
        flash('success', 'Payroll run cancelled. You can now delete it if it is no longer needed.');
    } elseif ($act === 'delete_run') {
        $no = payroll_delete_run($id);
        flash('success', 'Cancelled payroll run ' . $no . ' deleted.');
        redirect('payroll_runs');
    } elseif ($act === 'resolve') {
        update('payroll_exceptions', ['status' => 'resolved', 'resolution' => post('resolution') ?: 'Reviewed', 'resolved_by' => user_id(), 'resolved_at' => now()], 'id = ? AND run_id = ?', [(int)post('ex_id'), $id]);
        audit('payroll', 'exception_resolve', 'payroll_exceptions', post('ex_id'), ['resolution' => post('resolution')]);
        flash('success', 'Exception resolved.');
    } elseif ($act === 'add_adj' && $editable) {
        if (!post('employee_id') || post_num('amount') <= 0 || !post('description') || !post('reason')) {
            flash('error', 'Employee, description, a positive amount and the reason are required for a manual adjustment.');
        } else {
            $aid = insert('payroll_adjustments', ['run_id' => $id, 'employee_id' => (int)post('employee_id'), 'adj_type' => post('adj_type'), 'description' => post('description'),
                'amount' => post_num('amount'), 'taxable' => post('taxable') ? 1 : 0, 'reason' => post('reason'), 'created_by' => user_id()]);
            audit('payroll', 'manual_adjustment', 'payroll_adjustments', $aid, $_POST);
            payroll_compute($id);
            flash('success', 'Manual adjustment recorded in the audit trail and the run was recomputed.');
        }
    } elseif ($act === 'del_adj' && $editable) {
        $old = row('SELECT * FROM payroll_adjustments WHERE id = ? AND run_id = ?', [(int)post('adj_id'), $id]);
        if ($old) {
            q('DELETE FROM payroll_adjustments WHERE id = ?', [$old['id']]);
            audit('payroll', 'manual_adjustment_delete', 'payroll_adjustments', $old['id'], $old);
            payroll_compute($id);
            flash('success', 'Adjustment removed and the run recomputed.');
        }
    }
    redirect('payroll_run', ['id' => $id]);
}
$run = payroll_run($id);
$items = rows('SELECT pi.*, e.employee_no, e.first_name, e.last_name, e.middle_name, e.suffix, b.code AS branch_code FROM payroll_items pi
               JOIN employees e ON e.id = pi.employee_id LEFT JOIN branches b ON b.id = pi.default_branch_id WHERE pi.run_id = ?' . user_scope_sql('e') . ' ORDER BY e.last_name, e.first_name', [$id]);
$ex = rows('SELECT x.*, e.first_name, e.last_name, e.middle_name, e.suffix FROM payroll_exceptions x LEFT JOIN employees e ON e.id = x.employee_id WHERE x.run_id = ?' . scope_sql_or_null('e', 'x.employee_id') . ' ORDER BY x.status DESC, x.blocking DESC, x.id', [$id]);
$adjs = rows('SELECT a.*, e.first_name, e.last_name, e.middle_name, e.suffix, u.username FROM payroll_adjustments a JOIN employees e ON e.id = a.employee_id LEFT JOIN users u ON u.id = a.created_by WHERE a.run_id = ?' . user_scope_sql('e'), [$id]);
$branches = rows('SELECT b.code, b.name, COUNT(DISTINCT pb.employee_id) AS n, SUM(pb.gross) AS gross, SUM(pb.er_share_alloc) AS er, SUM(pb.reg_hours) AS hrs
                  FROM payroll_branch_items pb JOIN branches b ON b.id = pb.branch_id WHERE pb.run_id = ? GROUP BY b.code, b.name ORDER BY b.code', [$id]);
$t = array_fill_keys(['gross_pay', 'basic_pay', 'ot_pay', 'premium_pay', 'nd_pay', 'holiday_pay', 'sss_ee', 'phic_ee', 'hdmf_ee', 'hdmf_ee_voluntary', 'withholding_tax', 'other_deductions', 'total_deductions', 'net_pay', 'sss_er', 'sss_ec', 'phic_er', 'hdmf_er', 'absence_deduction', 'late_deduction', 'undertime_deduction'], 0.0);
foreach ($items as $it) {
    foreach ($t as $k => $v) {
        $t[$k] += (float)$it[$k];
    }
}
$er = $t['sss_er'] + $t['sss_ec'] + $t['phic_er'] + $t['hdmf_er'];
$blocking = count(array_filter($ex, fn($x) => $x['status'] === 'open' && $x['blocking']));
$cut = $run['cutoff_id'] ? row('SELECT * FROM attendance_cutoffs WHERE id = ?', [$run['cutoff_id']]) : null;

$actions = '';
if ($editable) {
    $actions .= '<button type="button" class="btn btn-primary" data-modal-open="calcModal">' . ($run['status'] === 'draft' ? 'Process payroll' : 'Process again') . '</button> ';
}
if ($run['status'] === 'computed') {
    $actions .= '<form method="post" class="inline" data-confirm="Finalize this payroll? The figures are locked and the payslips released; the postings follow in Period Closing.">' . csrf_field()
        . '<input type="hidden" name="_action" value="finalize"><button class="btn btn-success"' . ($blocking ? ' disabled title="Resolve blocking exceptions first"' : '') . '>Finalize</button></form> ';
}
if ($run['status'] === 'finalized' && empty($run['posted_at'])) {
    $actions .= '<a class="btn btn-success" href="' . h(url('period_closing', array_filter(['pay_group_id' => $run['pay_group_id'], 'cutoff_id' => $run['cutoff_id']]))) . '">Period closing</a> ';
}
if ($items) {
    $actions .= '<a class="btn" href="' . url('pay_register', ['run_id' => $id]) . '">Payroll register</a> ';
    $actions .= '<a class="btn" href="' . url('payslips_print', ['run_id' => $id]) . '">Print payslips</a> ';
    $actions .= '<a class="btn" href="' . url('bank_file', ['run_id' => $id]) . '">Bank file</a> ';
}
if ($editable) {
    $actions .= '<form method="post" class="inline" data-confirm="Cancel this payroll run?">' . csrf_field() . '<input type="hidden" name="_action" value="cancel"><button class="btn btn-danger-outline">Cancel run</button></form>';
}
if ($run['status'] === 'cancelled') {
    $actions .= '<form method="post" class="inline" data-confirm="Delete this cancelled payroll run permanently? Its number, exceptions and adjustments are removed.">' . csrf_field()
        . '<input type="hidden" name="_action" value="delete_run"><button class="btn btn-danger">Delete run</button></form>';
}
page_header('Payroll ' . $run['run_no'], ['back' => back_url() ?: url('payroll_runs'), 'back_label' => back_url() ? 'Back to ' . (menu_location((string)back_route())[1] ?: 'previous screen') : 'Back', 'actions' => $actions,
    'subtitle' => h(RUN_TYPES[$run['run_type']]) . ', ' . h($run['pay_group_name']) . ', ' . h(fmt_date($run['period_start'])) . ' – ' . h(fmt_date($run['period_end']))
        . ', Pay date ' . h(fmt_date($run['pay_date'])) . ', ' . badge($run['status'])
        . ($run['status'] === 'finalized' ? (empty($run['posted_at']) ? ', <span class="state-open">waiting for Period Closing</span>' : ', <span class="state-closed">posted ' . h(fmt_date($run['posted_at'])) . '</span>') : '')
        . ($cut ? ', Attendance cutoff <a href="' . url('cutoff_view', ['id' => $cut['id']]) . '">' . badge($cut['status']) . '</a>' : '')]);
?>
<div class="stats">
  <?= stat_card('Employees', (string)count($items), '', 'info') ?>
  <?= stat_card('Gross pay', peso($t['gross_pay'])) ?>
  <?= stat_card('Deductions', peso($t['total_deductions']), 'Tax ' . peso($t['withholding_tax'])) ?>
  <?= stat_card('Net pay', peso($t['net_pay']), '', 'good') ?>
  <?= stat_card('Employer contributions', peso($er), 'SSS/EC, PhilHealth, Pag-IBIG') ?>
  <?= stat_card('Total payroll cost', peso($t['gross_pay'] + $er)) ?>
</div>

<?php
$calc = payroll_calc_options($run);
// v15: the Payroll Computation form opened from the Payroll Batch Register comes with its filters pre-selected
$preFrom = get('pre') ? (string)get('pre') : '';
$returnUrl = '';
if ($preFrom !== '') {
    $calc['branch_ids'] = payroll_id_list((array)get('branch_ids', []));
    $calc['unit_ids'] = payroll_id_list((array)get('unit_ids', []));
    $calc['emp_ids'] = payroll_id_list((array)get('emp_ids', [])) ?: payroll_id_list((string)get('emp_list', ''));
    $calc['employee_group_id'] = (int)get('employee_group_id') ?: null;
    $calc['employee_type_id'] = (int)get('employee_type_id') ?: null;
    $calc['branch_id'] = null;
    $calc['selection'] = false;
    if (in_array($preFrom, ['pay_register', 'pay_register2'], true)) {
        $rq = array_filter(['run_id' => $id, 'org_unit_id' => (int)get('ret_org_unit_id') ?: null, 'branch_id' => (int)get('ret_branch_id') ?: null,
            'employee_group_id' => (int)get('employee_group_id') ?: null, 'employee_type_id' => (int)get('employee_type_id') ?: null, 'q' => trim((string)get('ret_q', '')) ?: null]);
        $returnUrl = 'index.php?' . http_build_query(['p' => $preFrom] + $rq);
    }
}
$calcBranches = pairs("SELECT id, CONCAT(COALESCE(branch_code, code), ' — ', name) FROM branches WHERE is_active = 1 ORDER BY company_code, branch_code, code");
$calcGroups = emp_group_options();
$calcTypes = pairs("SELECT id, CONCAT(code, ' — ', name) FROM emp_types WHERE is_active = 1 ORDER BY code");
$calcEmps = pairs("SELECT employee_no, CONCAT(employee_no, ' — ', last_name, ', ', first_name) FROM employees WHERE pay_group_id = ?" . user_scope_sql('employees') . ' ORDER BY employee_no', [$run['pay_group_id']]);
// v14: Employee / ID, Company / Branch and Department filters (one, two or more of each)
$calcEmpIds = pairs("SELECT id, CONCAT(employee_no, ' — ', last_name, ', ', first_name) FROM employees WHERE pay_group_id = ?" . user_scope_sql('employees') . ' ORDER BY last_name, first_name', [$run['pay_group_id']]);
$calcUnits = pairs('SELECT id, name FROM org_units WHERE is_active = 1 ORDER BY name');
$multiPick = function (string $name, string $label, array $opts, array $sel, string $all, string $id) {
    $o = '';
    foreach ($opts as $k => $v) {
        $o .= '<option value="' . h((string)$k) . '"' . (in_array((int)$k, $sel, true) ? ' selected' : '') . '>' . h($v) . '</option>';
    }
    return '<div class="calc-pick"><label for="' . h($id) . '">' . h($label) . '</label>'
        . '<input type="search" class="calc-pick-find" data-filter-select="' . h($id) . '" placeholder="Find…" aria-label="Find in ' . h($label) . '">'
        . '<select name="' . h($name) . '[]" id="' . h($id) . '" multiple size="5" data-pick-count>' . $o . '</select>'
        . '<small class="help calc-pick-count" data-count-for="' . h($id) . '" data-all="' . h($all) . '">' . ($sel ? count($sel) . ' selected' : h($all)) . '</small>'
        . '<button type="button" class="btn btn-xs calc-pick-clear" data-clear-select="' . h($id) . '">Clear</button></div>';
};
$pgRow = row('SELECT * FROM pay_groups WHERE id = ?', [$run['pay_group_id']]);
$defaultTable = $pgRow['tax_type'] ?: $pgRow['frequency'];
$yn = function (string $name, bool $yes, bool $disabled = false) {
    $d = $disabled ? ' disabled' : '';
    return '<span class="yn"><label class="check"><input type="radio" name="' . $name . '" value="1"' . ($yes ? ' checked' : '') . $d . '> Yes</label>'
        . '<label class="check"><input type="radio" name="' . $name . '" value="0"' . (!$yes ? ' checked' : '') . $d . '> No</label></span>';
};
if ($run['computed_at'] && ($calc['branch_id'] || $calc['branch_ids'] || $calc['unit_ids'] || $calc['emp_ids'] || $calc['employee_group_id'] || $calc['employee_type_id'] || $calc['selection'] || $calc['tax_table'] || $calc['annualize'] || !$calc['regular'] || $calc['thirteenth'])): ?>
  <div class="callout calc-note">Last Payroll Computation:
    <?= h(implode(', ', array_filter([
        $calc['emp_ids'] ? count($calc['emp_ids']) . ' employee(s): ' . implode('; ', array_slice(array_map(fn($i) => $calcEmpIds[$i] ?? '#' . $i, $calc['emp_ids']), 0, 5)) . (count($calc['emp_ids']) > 5 ? ' …' : '') : null,
        $calc['branch_ids'] ? 'Co./Branch ' . implode(', ', array_map(fn($i) => $calcBranches[$i] ?? '#' . $i, $calc['branch_ids'])) : null,
        $calc['unit_ids'] ? 'department ' . implode(', ', array_map(fn($i) => $calcUnits[$i] ?? '#' . $i, $calc['unit_ids'])) : null,
        $calc['employee_group_id'] ? 'group ' . ($calcGroups[$calc['employee_group_id']] ?? '') : null,
        $calc['employee_type_id'] ? 'type ' . ($calcTypes[$calc['employee_type_id']] ?? '') : null,
        $calc['selection'] ? 'selection ' . $calc['emp_from'] . ($calc['emp_to'] !== '' && $calc['emp_to'] !== $calc['emp_from'] ? ' to ' . $calc['emp_to'] : '') : null,
        $calc['tax_table'] ? CALC_TAX_TABLES[$calc['tax_table']] . ' tax table' : null,
        $calc['annualize'] ? 'annualized tax' : null,
        !$calc['regular'] ? 'incomes only (regular processing off)' : null,
        $calc['thirteenth'] ? 'with 13th month pay' : null,
    ]))) ?>.</div>
<?php endif; ?>

<?php if ($editable): ?>
<div class="modal<?= get('compute') ? ' open' : '' ?>" id="calcModal">
  <div class="modal-box calc-box">
    <div class="calc-title"><h2>Payroll Computation</h2><button type="button" class="icon-btn" data-modal-close aria-label="Close">×</button></div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="_action" value="compute"><?php if ($returnUrl !== ''): ?><input type="hidden" name="return_url" value="<?= h($returnUrl) ?>"><?php endif; ?>
      <?php if ($preFrom !== ''): ?><div class="callout calc-pre">Filters pre-selected from the <?= $preFrom === 'pay_register2' ? 'Payroll Batch Register II' : ($preFrom === 'pay_register' ? 'Payroll Batch Register' : 'previous screen') ?>. Check them, then press Process<?= $returnUrl !== '' ? '; you return to the register with the same filters' : '' ?>.</div><?php endif; ?>
      <div class="calc-grid">
        <div class="calc-picks"><div class="calc-picks-title"><?= $run['status'] === 'draft' ? 'Process only' : 'Reprocess only' ?> <small>(optional: pick one, two or more; Ctrl or tap for several. Leave empty for everyone.)</small></div>
        <?= $multiPick('emp_ids', 'Employee / ID', $calcEmpIds, $calc['emp_ids'], 'All employees of the pay code', 'calcEmpIds') ?>
        <?= $multiPick('branch_ids', 'Company / Branch', $calcBranches, $calc['branch_ids'], 'All companies / branches', 'calcBranchIds') ?>
        <?= $multiPick('unit_ids', 'Department', $calcUnits, $calc['unit_ids'], 'All departments', 'calcUnitIds') ?>
        </div>
        <label>Employee Group<select name="employee_group_id"><?= options($calcGroups, $calc['employee_group_id'], 'All groups') ?></select></label>
        <label>Employee Type<select name="employee_type_id"><?= options($calcTypes, $calc['employee_type_id'], 'All types') ?></select></label>
        <label>Pay Code<select disabled><option><?= h($pgRow['code'] . ' — ' . $pgRow['name']) ?></option></select></label>
        <label class="span-2">Period<input value="<?= h(fmt_date($run['period_start']) . ' – ' . fmt_date($run['period_end']) . ', pay date ' . fmt_date($run['pay_date'])) ?>" disabled></label>
        <label class="span-2">Tax Table to use<select name="tax_table"><?= options(CALC_TAX_TABLES, $calc['tax_table'], 'Pay code setting (' . (CALC_TAX_TABLES[$defaultTable] ?? $defaultTable) . ')') ?></select></label>
      </div>
      <div class="calc-switches">
        <div class="calc-row"><span>Annualization Process</span><?= $yn('annualize', $calc['annualize']) ?></div>
        <div class="calc-row"><span>Regular Processing</span><?= $yn('regular', $calc['regular']) ?></div>
        <div class="calc-row"><span>13th Month Process</span><?= $yn('thirteenth', $calc['thirteenth'], $run['run_type'] !== 'regular') ?></div>
        <div class="calc-row is-off"><span>Periodical Calculation</span><?= $yn('periodical', false, true) ?></div>
        <div class="calc-row"><span>Processing Type</span><span class="yn">
          <label class="check"><input type="radio" name="processing" value="batch"<?= $calc['selection'] ? '' : ' checked' ?> data-calc-proc> Batch</label>
          <label class="check"><input type="radio" name="processing" value="selection"<?= $calc['selection'] ? ' checked' : '' ?> data-calc-proc> Selection</label></span></div>
        <div class="calc-range" id="calcRange">
          <label>Employee ID from<select name="emp_from"><?= options($calcEmps, $calc['emp_from'] ?: null, '—') ?></select></label>
          <label>to<select name="emp_to"><?= options($calcEmps, $calc['emp_to'] ?: null, '—') ?></select></label>
        </div>
        <div class="calc-row is-off"><span>Capture Process</span><?= $yn('capture', false, true) ?></div>
      </div>
      <fieldset class="box calc-help"><legend>Note</legend>
        <p><strong>Regular processing</strong> means all incomes and all deductions are implemented: tax, SSS, PhilHealth, Pag-IBIG, loans and company deductions. With No, only the incomes are computed.</p>
        <p><strong>Annualization</strong> computes the tax on the year's total taxable income less the tax already withheld (year-end adjustment). <strong>13th Month Process</strong> adds the 13th month pay earned so far to this run.</p>
        <p>An <strong>Employee / ID</strong>, <strong>Company / Branch</strong> or <strong>Department</strong> filter (one, two or more of each), a Group, a Type or a Selection reprocesses only those employees; the others of the run keep their figures. Leave them empty to process everyone. Only closed attendance transactions are paid.</p>
      </fieldset>
      <div class="form-actions">
        <button type="button" class="btn" data-modal-close>Exit</button>
        <button class="btn btn-primary">Process</button>
        <a class="btn" href="#exceptions" data-modal-close<?= $blocking || $ex ? '' : ' aria-disabled="true"' ?>>View errors<?= $ex ? ' (' . count($ex) . ')' : '' ?></a>
      </div>
    </form>
  </div>
</div>
<script>(function(){var r=document.getElementById('calcRange');function f(){var s=document.querySelector('[data-calc-proc][value=selection]');r.classList.toggle('on',!!(s&&s.checked));r.querySelectorAll('select').forEach(function(x){x.disabled=!(s&&s.checked);});}document.querySelectorAll('[data-calc-proc]').forEach(function(x){x.addEventListener('change',f);});f();})();</script>
<?php endif; ?>

<?php if ($ex): ?>
<div class="card" id="exceptions"><div class="card-head"><h2>Exceptions &amp; discrepancies</h2><?= $blocking ? '<span class="badge badge-red">' . $blocking . ' blocking</span>' : '<span class="badge badge-green">No blocking issues</span>' ?></div>
  <p class="small muted">Discrepancies are flagged for resolution before finalization rather than silently paid or silently withheld.</p>
  <table class="table compact"><thead><tr><th>Employee</th><th>Type</th><th>Message</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($ex as $x): ?>
    <tr class="<?= $x['status'] === 'open' && $x['blocking'] ? 'row-bad' : '' ?>"><td><?= $x['employee_id'] ? h(full_name($x)) : '<em>Run</em>' ?></td><td><?= h(label($x['exception_type'])) ?><?= $x['blocking'] ? ' <span class="badge badge-red">blocking</span>' : ' <span class="badge badge-gray">info</span>' ?></td>
      <td class="small"><?= h($x['message']) ?><?= $x['resolution'] ? '<br><em>Resolution: ' . h($x['resolution']) . '</em>' : '' ?></td><td><?= badge($x['status']) ?></td>
      <td class="actions"><?php if ($x['status'] === 'open' && $editable): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="_action" value="resolve"><input type="hidden" name="ex_id" value="<?= $x['id'] ?>"><input name="resolution" placeholder="Resolution note" style="width:180px;display:inline-block"> <button class="btn btn-sm">Resolve</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table>
</div>
<?php endif; ?>

<div class="card"><div class="card-head"><h2>Payroll items</h2><input type="search" class="input table-search" data-target="itemsTbl" placeholder="Search..." style="max-width:220px"></div>
  <div class="table-wrap"><table class="table compact" id="itemsTbl">
    <thead><tr><th>Employee</th><th class="num">Days</th><th class="num">Basic</th><th class="num">Abs/Late/UT</th><th class="num">OT</th><th class="num">Prem/Hol/ND</th><th class="num">Gross</th><th class="num">SSS</th><th class="num">PHIC</th><th class="num">HDMF</th><th class="num">Tax</th><th class="num">Other ded.</th><th class="num">Net pay</th><th class="actions"></th></tr></thead>
    <tbody>
    <?php if (!$items) echo empty_row(14, 'Not computed yet.'); foreach ($items as $it): ?>
      <tr id="emp-<?= (int)$it['employee_id'] ?>" class="<?= (int)get('emp', 0) === (int)$it['employee_id'] ? 'row-sel' : '' ?>"><td class="nowrap"><strong><?= h(full_name($it)) ?></strong><br><span class="muted small"><?= h($it['employee_no']) ?>, <?= h($it['branch_code']) ?><?= $it['is_mwe'] ? ', MWE' : '' ?></span></td>
        <td class="num"><?= (float)$it['days_worked'] ?></td><td class="num"><?= money($it['basic_pay']) ?></td>
        <td class="num"><?= ($d = $it['absence_deduction'] + $it['late_deduction'] + $it['undertime_deduction']) > 0 ? '−' . money($d) : '' ?></td>
        <td class="num"><?= $it['ot_pay'] > 0 ? money($it['ot_pay']) : '' ?></td><td class="num"><?= ($p = $it['premium_pay'] + $it['holiday_pay'] + $it['nd_pay']) > 0 ? money($p) : '' ?></td>
        <td class="num"><strong><?= money($it['gross_pay']) ?></strong></td><td class="num"><?= money($it['sss_ee']) ?></td><td class="num"><?= money($it['phic_ee']) ?></td><td class="num"><?= money($it['hdmf_ee'] + $it['hdmf_ee_voluntary']) ?></td>
        <td class="num"><?= money($it['withholding_tax']) ?></td><td class="num"><?= $it['other_deductions'] > 0 ? money($it['other_deductions']) : '' ?><?= $it['deferred_deductions'] > 0 ? '<br><span class="badge badge-amber">' . money($it['deferred_deductions']) . ' deferred</span>' : '' ?></td>
        <td class="num"><strong><?= money($it['net_pay']) ?></strong></td>
        <td class="actions"><a class="btn btn-sm" href="<?= url('payslip', ['id' => $it['id']]) ?>">Payslip</a></td></tr>
    <?php endforeach; ?>
    </tbody>
    <?php if ($items): ?><tfoot><tr><td>Totals</td><td></td><td class="num"><?= money($t['basic_pay']) ?></td><td class="num">−<?= money($t['absence_deduction'] + $t['late_deduction'] + $t['undertime_deduction']) ?></td><td class="num"><?= money($t['ot_pay']) ?></td>
      <td class="num"><?= money($t['premium_pay'] + $t['holiday_pay'] + $t['nd_pay']) ?></td><td class="num"><?= money($t['gross_pay']) ?></td><td class="num"><?= money($t['sss_ee']) ?></td><td class="num"><?= money($t['phic_ee']) ?></td><td class="num"><?= money($t['hdmf_ee'] + $t['hdmf_ee_voluntary']) ?></td>
      <td class="num"><?= money($t['withholding_tax']) ?></td><td class="num"><?= money($t['other_deductions']) ?></td><td class="num"><?= money($t['net_pay']) ?></td><td></td></tr></tfoot><?php endif; ?>
  </table></div>
</div>

<div class="grid grid-2">
  <div class="card"><h2>Multi-branch cost allocation</h2>
    <p class="small muted">Earnings are attributed to the branch where the work was rendered; statutory deductions are logged once under each employee's default branch. Employer shares are allocated in proportion to branch earnings.</p>
    <div class="table-wrap"><table class="table compact"><thead><tr><th>Branch</th><th class="num">Employees</th><th class="num">Hours</th><th class="num">Gross earnings</th><th class="num">ER share alloc.</th><th class="num">Total cost</th></tr></thead><tbody>
    <?php foreach ($branches as $b): ?><tr><td><?= h($b['code']) ?> — <?= h($b['name']) ?></td><td class="num"><?= (int)$b['n'] ?></td><td class="num"><?= money($b['hrs']) ?></td><td class="num"><?= money($b['gross']) ?></td><td class="num"><?= money($b['er']) ?></td><td class="num"><?= money($b['gross'] + $b['er']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </div>
  <div class="card"><h2>Manual adjustments &amp; overrides</h2>
    <p class="small muted">Every adjustment requires a reason and is recorded in the audit trail.</p>
    <table class="table compact"><thead><tr><th>Employee</th><th>Type</th><th>Description</th><th class="num">Amount</th><th>By / reason</th><th></th></tr></thead><tbody>
    <?php if (!$adjs) echo empty_row(6, 'No adjustments.'); foreach ($adjs as $a): ?>
      <tr><td><?= h(full_name($a)) ?></td><td><?= h(label($a['adj_type'])) ?><?= $a['adj_type'] === 'earning' ? ($a['taxable'] ? ' (taxable)' : ' (non-taxable)') : '' ?></td><td><?= h($a['description']) ?></td><td class="num"><?= money($a['amount']) ?></td><td class="small"><?= h($a['username']) ?>: <?= h($a['reason']) ?></td>
        <td class="actions"><?php if ($editable): ?><form method="post" class="inline" data-confirm="Remove this adjustment?"><?= csrf_field() ?><input type="hidden" name="_action" value="del_adj"><input type="hidden" name="adj_id" value="<?= $a['id'] ?>"><button class="btn btn-sm btn-danger-outline">×</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php if ($editable): ?>
    <form method="post" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="_action" value="add_adj">
      <div class="form-grid">
        <div class="field wide"><label>Employee</label><select name="employee_id" required><?= options(array_column(array_map(fn($i) => ['id' => $i['employee_id'], 'n' => full_name($i)], $items), 'n', 'id') ?: employee_options(true, 'pay_group_id = ' . (int)$run['pay_group_id']), null, '— select —') ?></select></div>
        <div class="field"><label>Type</label><select name="adj_type"><?= options(['earning' => 'Earning', 'deduction' => 'Deduction']) ?></select></div>
        <div class="field"><label>Amount</label><input type="number" step="0.01" name="amount" required></div>
        <div class="field wide"><label>Description (shown on payslip)</label><input name="description" required></div>
        <div class="field"><label class="check"><input type="checkbox" name="taxable" value="1" checked> Taxable (earnings)</label></div>
        <div class="field wide"><label>Reason / authority (audit trail)</label><input name="reason" required></div>
      </div>
      <div class="form-actions"><button class="btn">Add adjustment</button></div>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php page_footer();
