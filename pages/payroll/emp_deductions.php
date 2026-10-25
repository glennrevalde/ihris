<?php
/**
 * Employee deductions: loan amortizations, company recurring deductions, ad hoc deductions (approval before posting)
 * and loss/damage accountabilities — all bound by the Labor Code wage-protection limits at payroll time.
 */
$dimp = dimp_def('deductions', null, []);   // CSV import facility on this form (app/engine/dataimport.php)
dimp_handle($dimp);
if (is_post()) {
    $act = post('_action');
    if ($act === 'create') {
        $type = row('SELECT * FROM deduction_types WHERE id = ?', [(int)post('deduction_type_id')]);
        if (!$type || $type['category'] === 'statutory') {
            flash('error', 'Select a valid (non-statutory) deduction type.');
            redirect('emp_deductions');
        }
        if ($type['computation'] === 'ledger') {
            flash('info', $type['name'] . ' is amortized from a loan / payable: create it in Employee Term Loan/Payables first; its payroll deduction is set up automatically.');
            redirect('ledgers', ['new' => 1, 'employee_id' => post('employee_id'), 'deduction_type_id' => $type['id']]);
        }
        $emps = array_map('intval', (array)post('employee_ids', []));
        foreach ($emps as $e) {
            $id = insert('employee_deductions', ['employee_id' => $e, 'deduction_type_id' => $type['id'], 'amount' => post_num('amount') ?: $type['default_amount'],
                'start_date' => post('start_date') ?: today(), 'end_date' => post_null('end_date'), 'written_consent' => post('written_consent') ? 1 : 0,
                'status' => $type['requires_approval'] ? 'pending' : 'active', 'remarks' => post('remarks'), 'created_by' => user_id()]);
            audit('payroll', 'deduction_create', 'employee_deductions', $id, $_POST);
        }
        flash('success', count($emps) . ' deduction(s) created' . ($type['requires_approval'] ? ' — pending approval before posting.' : '.'));
        if (!$type['authorized_by_law'] && !post('written_consent')) {
            flash('warning', 'No written consent was ticked: under Art. 113 the deduction will NOT be applied until the employee\'s written authorization is on file.');
        }
    } elseif (in_array($act, ['approve', 'reject', 'stop', 'resume', 'consent'], true)) {
        $d = row('SELECT * FROM employee_deductions WHERE id = ?', [(int)post('id')]);
        if ($d) {
            $upd = match ($act) {
                'approve' => ['status' => 'active', 'approved_by' => user_id(), 'approved_at' => now()],
                'reject' => ['status' => 'rejected', 'approved_by' => user_id(), 'approved_at' => now()],
                'stop' => ['status' => 'stopped', 'end_date' => today()],
                'resume' => ['status' => 'active', 'end_date' => null],
                'consent' => ['written_consent' => $d['written_consent'] ? 0 : 1],
            };
            update('employee_deductions', $upd, 'id = ?', [$d['id']]);
            audit('payroll', 'deduction_' . $act, 'employee_deductions', $d['id'], $upd);
            flash('success', 'Deduction updated.');
        }
    } elseif ($act === 'amount') {
        update('employee_deductions', ['amount' => post_num('amount'), 'end_date' => post_null('end_date')], 'id = ?', [(int)post('id')]);
        audit('payroll', 'deduction_update', 'employee_deductions', post('id'), ['amount' => post('amount'), 'end_date' => post('end_date')]);
        flash('success', 'Deduction updated.');
    }
    redirect('emp_deductions', array_filter(['status' => get('status'), 'type' => get('type')]));
}
$f = ['status' => get('status', 'active'), 'type' => get('type', ''), 'emp' => get('emp', '')];
$where = ["dt.category <> 'statutory'"];
$params = [];
if ($f['status'] !== 'all') {
    $where[] = 'ed.status = ?';
    $params[] = $f['status'];
}
foreach (['type' => 'ed.deduction_type_id', 'emp' => 'ed.employee_id'] as $k => $col) {
    if ($f[$k] !== '') {
        $where[] = "$col = ?";
        $params[] = $f[$k];
    }
}
$list = rows('SELECT ed.*, dt.name AS type_name, dt.code, dt.category, dt.computation, dt.frequency, dt.authorized_by_law, dt.requires_approval,
                     e.first_name, e.last_name, e.middle_name, e.suffix, e.employee_no, l.ledger_no, l.balance
              FROM employee_deductions ed JOIN deduction_types dt ON dt.id = ed.deduction_type_id JOIN employees e ON e.id = ed.employee_id LEFT JOIN ledgers l ON l.id = ed.ledger_id
              WHERE ' . implode(' AND ', $where) . user_scope_sql('e') . ' ORDER BY ed.status, e.last_name', $params);
$pending = (int)val("SELECT COUNT(*) FROM employee_deductions WHERE status = 'pending'");
page_header('Employee Term Loan/Payables', ['subtitle' => 'Employee deductions: ' . 'deductions other than statutory contributions and tax. Net pay is protected by the minimum take-home rule (' . h(setting('min_net_pay_pct', 0)) . '% of gross) and the ' . h(setting('accountability_max_pct', 20)) . '% cap on loss/damage deductions.',
    'actions' => '<button class="btn btn-primary" data-modal-open="dedModal">+ Add deduction</button> <a class="btn" href="' . url('ledgers', ['new' => 1]) . '">+ New Loan/Payable</a>']);
echo term_loan_tabs('emp_deductions');
echo dimp_panel($dimp);
if ($pending): ?><div class="alert alert-warning"><?= $pending ?> deduction(s) awaiting approval before they can be posted. <a href="<?= url('emp_deductions', ['status' => 'pending']) ?>">Review</a></div><?php endif; ?>
<form class="card filters" method="get"><input type="hidden" name="p" value="emp_deductions">
  <label>Status<select name="status"><?= options(['active' => 'Active', 'pending' => 'Pending approval', 'completed' => 'Completed', 'stopped' => 'Stopped', 'rejected' => 'Rejected', 'all' => 'All'], $f['status']) ?></select></label>
  <label>Type<select name="type"><?= options(pairs("SELECT id, name FROM deduction_types WHERE category <> 'statutory' ORDER BY priority"), $f['type'], 'All') ?></select></label>
  <label>Employee<select name="emp"><?= options(employee_options(false), $f['emp'], 'All') ?></select></label>
  <button class="btn btn-primary">Filter</button></form>
<div class="card"><div class="table-wrap"><table class="table compact">
  <thead><tr><th>Employee</th><th>Deduction</th><th>Frequency</th><th class="num">Amount / payroll</th><th>Period</th><th>Written consent</th><th>Ledger balance</th><th>Status</th><th class="actions"></th></tr></thead><tbody>
  <?php if (!$list) echo empty_row(9); foreach ($list as $d): $needsConsent = !$d['authorized_by_law']; ?>
    <tr class="<?= $needsConsent && !$d['written_consent'] && $d['status'] === 'active' ? 'row-warn' : '' ?>"><td><?= h(full_name($d)) ?><br><span class="muted small"><?= h($d['employee_no']) ?></span></td>
      <td><?= h($d['type_name']) ?><br><span class="small muted"><?= h(label($d['category'])) ?><?= $d['remarks'] ? ', ' . h($d['remarks']) : '' ?></span></td><td class="small"><?= h(label($d['frequency'])) ?></td>
      <td class="num"><?= $d['computation'] === 'percent_basic' ? h((float)$d['amount']) . '%' : money($d['amount']) ?></td>
      <td class="small nowrap"><?= h(fmt_date($d['start_date'])) ?> – <?= h($d['end_date'] ? fmt_date($d['end_date']) : 'open') ?></td>
      <td><?= $needsConsent ? ($d['written_consent'] ? '<span class="badge badge-green">On file</span>' : '<span class="badge badge-red">Missing — not deducted</span>') : '<span class="badge badge-gray">Authorized by law</span>' ?></td>
      <td><?= $d['ledger_no'] ? '<a href="' . url('ledger_view', ['id' => $d['ledger_id']]) . '">' . h($d['ledger_no']) . '</a> ' . money($d['balance']) : '' ?></td><td><?= badge($d['status']) ?></td>
      <td class="actions">
        <?php foreach (($d['status'] === 'pending' ? ['approve' => 'Approve', 'reject' => 'Reject'] : ($d['status'] === 'active' ? ['stop' => 'Stop'] : ($d['status'] === 'stopped' ? ['resume' => 'Resume'] : []))) + ($needsConsent && in_array($d['status'], ['active', 'pending'], true) ? ['consent' => $d['written_consent'] ? 'Clear consent' : 'Consent received'] : []) as $a => $l): ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="_action" value="<?= $a ?>"><input type="hidden" name="id" value="<?= $d['id'] ?>"><button class="btn btn-sm<?= $a === 'approve' ? ' btn-success' : '' ?>"><?= $l ?></button></form>
        <?php endforeach; ?>
        <?php if (in_array($d['status'], ['active', 'pending'], true) && !$d['ledger_id']): ?><button class="btn btn-sm" data-modal-open="amtModal" data-field-id="<?= $d['id'] ?>" data-field-amount="<?= h($d['amount']) ?>" data-field-end-date="<?= h($d['end_date']) ?>">Edit</button><?php endif; ?>
      </td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<div class="modal" id="dedModal"><div class="modal-box"><h2>Add deduction</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="create">
    <div class="form-grid">
      <div class="field wide"><label>Employee(s)</label><select name="employee_ids[]" multiple size="6" required><?= options(employee_options()) ?></select></div>
      <div class="field wide"><label>Deduction type</label><select name="deduction_type_id" required><?= options(pairs("SELECT id, CONCAT(name, ' — ', category, IF(authorized_by_law, '', ' (needs consent)')) FROM deduction_types WHERE category <> 'statutory' AND is_active = 1 ORDER BY priority"), null, '— select —') ?></select></div>
      <div class="field"><label>Amount per payroll (or % for % of basic)</label><input type="number" step="0.01" name="amount"></div>
      <div class="field"><label>Start</label><input type="date" name="start_date" value="<?= h(today()) ?>"></div>
      <div class="field"><label>End (optional)</label><input type="date" name="end_date"></div>
      <div class="field"><label class="check"><input type="checkbox" name="written_consent" value="1"> Employee's written authorization on file</label></div>
      <div class="field wide"><label>Remarks</label><input name="remarks"></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary">Save</button><button type="button" class="btn" data-modal-close>Cancel</button></div>
  </form></div></div>
<div class="modal" id="amtModal"><div class="modal-box"><h2>Edit deduction</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="amount"><input type="hidden" name="id">
    <div class="form-grid"><div class="field"><label>Amount</label><input type="number" step="0.01" name="amount"></div><div class="field"><label>End date</label><input type="date" name="end_date"></div></div>
    <div class="form-actions"><button class="btn btn-primary">Save</button><button type="button" class="btn" data-modal-close>Cancel</button></div></form></div></div>
<?php page_footer();
