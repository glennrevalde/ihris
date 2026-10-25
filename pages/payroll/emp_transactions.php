<?php
/**
 * Employee's Transactions — earnings and deductions per employee (Transaction Table codes).
 *   Terms = 1  one-time transaction for a pay period: picked up by the next payroll run of that
 *              period and marked "included" when the run is finalized.
 *   Terms > 1  transaction WITH TERMS (M.A.D.A.S. Employee's Transaction Setup): the amount, the
 *              monthly interest and the number of paydays give the per-payday installment; the
 *              transaction term decides on which pay of the month it is taken (every pay, 1st,
 *              2nd, 3rd or 4th pay). Each installment is posted by the payroll run that takes it.
 * v17: New transaction takes one, two, three or more transaction codes at once (earnings and deductions),
 * each with its own amount, for every employee ticked; the terms apply to each code.
 */
require __DIR__ . '/_trans_form.php';

$period = pt_default_period((int)get('cutoff_id') ?: null);
$filters = pt_filters();
$typeF = (string)get('trans_type', '');
$statusF = (string)get('status', 'pending');
$tstatusF = (string)get('tstatus', 'active');
$empPre = (int)get('emp', 0);   // employee picked on the Payroll Register (or another screen): lists and form open on that person
$keep = array_filter(array_merge(['p' => 'emp_transactions', 'cutoff_id' => $period['id'] ?? null, 'trans_type' => $typeF, 'status' => $statusF, 'tstatus' => $tstatusF, 'emp' => $empPre ?: null], $filters),
    fn($v) => $v !== null && $v !== '');
$keep = array_merge($keep, back_params());

$dimp = dimp_def('emp_trans', null, ['cutoff_id' => $period['id'] ?? null]);   // CSV import facility on this form (app/engine/dataimport.php)
dimp_handle($dimp);
if (is_post()) {
    verify_csrf();
    $action = post('_action');
    try {
        if ($action === 'save' && !(int)post('id') && post('codes') !== null) {
            // v17: several transaction codes at once (key "earning:12" / "deduction:7"), each with its amount
            $p = pt_period((int)post('cutoff_id'));
            if (!$p) {
                throw new RuntimeException('Select a pay period.');
            }
            $items = [];
            foreach (code_pick_posted(['amount']) as $key => $v) {
                [$type, $cid] = array_pad(explode(':', (string)$key, 2), 2, '0');
                $type = $type === 'deduction' ? 'deduction' : 'earning';
                $code = (int)$cid ? row('SELECT code, name FROM ' . ($type === 'earning' ? 'earning_types' : 'deduction_types') . ' WHERE id = ?', [(int)$cid]) : null;
                if (!$code) {
                    throw new RuntimeException('A ticked transaction code no longer exists.');
                }
                $amount = round((float)str_replace(',', '', (string)$v['amount']), 2);
                if ($amount <= 0) {
                    throw new RuntimeException('Enter the amount of ' . $code['code'] . ' (greater than zero).');
                }
                $items[] = [$type, (int)$cid, $amount, $code['code']];
            }
            if (!$items) {
                throw new RuntimeException('Tick at least one transaction code.');
            }
            $emps = array_map('intval', (array)post('employee_ids', []));
            if (!$emps) {
                throw new RuntimeException('Select at least one employee.');
            }
            $terms = max(1, (int)post('terms', 1));
            $one = count($items) === 1;   // the per-payday overrides apply when one code is ticked
            $n = 0;
            foreach ($items as [$type, $codeId, $amount]) {
                foreach ($emps as $eid) {
                    if ($terms > 1) {
                        term_create([
                            'employee_id' => $eid, 'trans_type' => $type, 'code_id' => $codeId, 'principal' => $amount,
                            'monthly_rate' => post_num('monthly_rate'), 'terms' => $terms, 'trans_term' => (string)post('trans_term', 'every_pay'),
                            'interest_amount' => $one && post('interest_amount') !== null && post('interest_amount') !== '' ? post_num('interest_amount') : null,
                            'per_pay_principal' => $one && post('per_pay_principal') !== null && post('per_pay_principal') !== '' ? post_num('per_pay_principal') : null,
                            'per_pay_interest' => $one && post('per_pay_interest') !== null && post('per_pay_interest') !== '' ? post_num('per_pay_interest') : null,
                            'trans_date' => post('trans_date') ?: today(), 'start_date' => post('start_date') ?: $p['period_start'],
                            'doc_no' => trim((string)post('doc_no')) ?: null, 'branch_id' => (int)post('branch_id') ?: null, 'remarks' => trim((string)post('remarks')) ?: null,
                        ]);
                    } else {
                        $data = [
                            'cutoff_id' => (int)$p['id'], 'pay_group_id' => (int)$p['pay_group_id'], 'period_start' => $p['period_start'], 'period_end' => $p['period_end'],
                            'trans_type' => $type, 'earning_type_id' => $type === 'earning' ? $codeId : null, 'deduction_type_id' => $type === 'deduction' ? $codeId : null,
                            'amount' => $amount, 'remarks' => trim((string)post('remarks')) ?: null,
                        ];
                        $newId = insert('employee_transactions', array_merge($data, ['employee_id' => $eid, 'created_by' => user_id()]));
                        audit('payroll', 'emp_transaction_create', 'employee_transactions', $newId, $data);
                    }
                    $n++;
                }
            }
            flash('success', count($items) . ' code(s) for ' . count($emps) . ' employee(s): ' . $n . ($terms > 1
                ? ' transaction(s) with terms created, ' . $terms . ' payday(s), ' . strtolower(TERM_TRANS_TERMS[post('trans_term')] ?? 'every pay') . '.'
                : ' transaction(s) encoded.'));
            redirect('emp_transactions', $keep);
        } elseif ($action === 'save') {
            $p = pt_period((int)post('cutoff_id'));
            if (!$p) {
                throw new RuntimeException('Select a pay period.');
            }
            $type = post('trans_type') === 'deduction' ? 'deduction' : 'earning';
            $codeId = (int)post('code_id');
            if (!$codeId) {
                throw new RuntimeException('Select a transaction code.');
            }
            $amount = round((float)str_replace(',', '', (string)post('amount')), 2);
            if ($amount <= 0) {
                throw new RuntimeException('The amount must be greater than zero.');
            }
            $emps = array_map('intval', (array)post('employee_ids', []));
            if (!$emps && post('employee_id')) {
                $emps = [(int)post('employee_id')];
            }
            if (!$emps) {
                throw new RuntimeException('Select at least one employee.');
            }
            $id = (int)post('id');
            $terms = max(1, (int)post('terms', 1));
            if ($terms > 1 && !$id) {
                // transaction with terms: one amortized plan per employee
                $n = 0;
                foreach ($emps as $eid) {
                    term_create([
                        'employee_id' => $eid, 'trans_type' => $type, 'code_id' => $codeId, 'principal' => $amount,
                        'monthly_rate' => post_num('monthly_rate'), 'terms' => $terms, 'trans_term' => (string)post('trans_term', 'every_pay'),
                        'interest_amount' => post('interest_amount') !== null && post('interest_amount') !== '' ? post_num('interest_amount') : null,
                        'per_pay_principal' => post('per_pay_principal') !== null && post('per_pay_principal') !== '' ? post_num('per_pay_principal') : null,
                        'per_pay_interest' => post('per_pay_interest') !== null && post('per_pay_interest') !== '' ? post_num('per_pay_interest') : null,
                        'trans_date' => post('trans_date') ?: today(), 'start_date' => post('start_date') ?: $p['period_start'],
                        'doc_no' => trim((string)post('doc_no')) ?: null, 'branch_id' => (int)post('branch_id') ?: null, 'remarks' => trim((string)post('remarks')) ?: null,
                    ]);
                    $n++;
                }
                flash('success', $n . ' transaction(s) with terms created: ' . $terms . ' payday(s), ' . strtolower(TERM_TRANS_TERMS[post('trans_term')] ?? 'every pay') . '. The first installment is taken by the next payroll run on or after ' . fmt_date(post('start_date') ?: $p['period_start']) . '.');
                redirect('emp_transactions', $keep);
            }
            $data = [
                'cutoff_id' => (int)$p['id'], 'pay_group_id' => (int)$p['pay_group_id'], 'period_start' => $p['period_start'], 'period_end' => $p['period_end'],
                'trans_type' => $type, 'earning_type_id' => $type === 'earning' ? $codeId : null, 'deduction_type_id' => $type === 'deduction' ? $codeId : null,
                'amount' => $amount, 'remarks' => trim((string)post('remarks')) ?: null,
            ];
            if ($id) {
                $old = row('SELECT * FROM employee_transactions WHERE id = ?', [$id]);
                if (!$old || $old['status'] !== 'pending') {
                    throw new RuntimeException('Only pending transactions can be changed.');
                }
                update('employee_transactions', $data, 'id = ?', [$id]);
                audit('payroll', 'emp_transaction_update', 'employee_transactions', $id, diff_fields($old, $data));
                flash('success', 'Transaction updated.');
            } else {
                $n = 0;
                foreach ($emps as $eid) {
                    $newId = insert('employee_transactions', array_merge($data, ['employee_id' => $eid, 'created_by' => user_id()]));
                    audit('payroll', 'emp_transaction_create', 'employee_transactions', $newId, $data);
                    $n++;
                }
                flash('success', $n . ' transaction(s) encoded.');
            }
        } elseif ($action === 'term_save') {
            $tid = (int)post('term_id');
            $reserved = term_update($tid, [
                'trans_type' => post('trans_type'), 'code_id' => (int)post('code_id'), 'principal' => post_num('amount'), 'monthly_rate' => post_num('monthly_rate'),
                'terms' => (int)post('terms', 1), 'remaining_terms' => (int)post('remaining_terms', 0), 'trans_term' => (string)post('trans_term', 'every_pay'),
                'interest_amount' => post('interest_amount') !== null && post('interest_amount') !== '' ? post_num('interest_amount') : null,
                'per_pay_principal' => post('per_pay_principal') !== null && post('per_pay_principal') !== '' ? post_num('per_pay_principal') : null,
                'per_pay_interest' => post('per_pay_interest') !== null && post('per_pay_interest') !== '' ? post_num('per_pay_interest') : null,
                'trans_date' => post('trans_date'), 'start_date' => post('start_date'), 'doc_no' => post('doc_no'), 'branch_id' => post('branch_id'), 'remarks' => post('remarks'),
            ]);
            flash('success', 'Transaction with terms updated and its schedule rebuilt.' . ($reserved ? ' Payroll run ' . implode(', ', $reserved) . ' had reserved an installment: process it again.' : ''));
            redirect('emp_transactions', $keep);
        } elseif ($action === 'term_cancel') {
            term_cancel((int)post('id'), trim((string)post('reason')) ?: null);
            flash('success', 'Transaction with terms cancelled. Installments already taken stay in the history.');
        } elseif ($action === 'delete' || $action === 'cancel') {
            $id = (int)post('id');
            $t = row('SELECT * FROM employee_transactions WHERE id = ?', [$id]);
            if (!$t || $t['status'] !== 'pending') {
                throw new RuntimeException('Only pending transactions can be removed.');
            }
            if ($action === 'delete') {
                q('DELETE FROM employee_transactions WHERE id = ?', [$id]);
            } else {
                update('employee_transactions', ['status' => 'cancelled'], 'id = ?', [$id]);
            }
            audit('payroll', 'emp_transaction_' . $action, 'employee_transactions', $id, $t);
            flash('success', 'Transaction ' . ($action === 'delete' ? 'deleted' : 'cancelled') . '.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('emp_transactions', $keep);
}

$edit = (int)get('edit', 0) ? row('SELECT * FROM employee_transactions WHERE id = ?', [(int)get('edit')]) : null;
$editTerm = (int)get('edit_term', 0) ? term_get((int)get('edit_term')) : null;
if ($editTerm && $editTerm['status'] === 'cancelled') {
    $editTerm = null;
}
$termTaken = $editTerm ? term_taken_count((int)$editTerm['id']) : 0;
$termPending = $editTerm ? (int)val("SELECT COUNT(*) FROM employee_term_schedule WHERE term_id = ? AND status = 'pending'", [(int)$editTerm['id']]) : 0;
$where = ['1=1'];
$params = [];
if ($period) {
    $where[] = '(et.cutoff_id = ? OR (et.cutoff_id IS NULL AND et.period_end >= ? AND et.period_start <= ?))';
    array_push($params, $period['id'], $period['period_start'], $period['period_end']);
}
if ($typeF) {
    $where[] = 'et.trans_type = ?';
    $params[] = $typeF;
}
if ($statusF) {
    $where[] = 'et.status = ?';
    $params[] = $statusF;
}
if ($filters['org_unit_id']) {   // v20: one, two or more departments, companies / branches, groups
    $where[] = sql_in('e.org_unit_id', org_units_with_descendants($filters['org_unit_id']));
}
if ($filters['branch_id']) {
    $where[] = sql_in('e.default_branch_id', $filters['branch_id']);
}
if ($filters['employee_group_id']) {
    $where[] = sql_in('e.employee_group_id', $filters['employee_group_id']);
}
if (!empty($filters['employee_type_id'])) {
    $where[] = 'e.employee_type_id = ?';
    $params[] = (int)$filters['employee_type_id'];
}
if (!empty($filters['q'])) {
    $where[] = search_words_sql(['e.employee_no', 'e.last_name', 'e.first_name', 'e.biometric_id'], (string)$filters['q'], $params);
}
if ($empPre) {
    $where[] = 'e.id = ?';
    $params[] = $empPre;
}
$list = rows('SELECT et.*, e.employee_no, e.first_name, e.last_name, e.middle_name, e.suffix, ou.name AS unit_name,
                     COALESCE(ea.code, dt.code) AS code, COALESCE(ea.name, dt.name) AS code_name, pr.run_no
              FROM employee_transactions et JOIN employees e ON e.id = et.employee_id
              LEFT JOIN org_units ou ON ou.id = e.org_unit_id
              LEFT JOIN earning_types ea ON ea.id = et.earning_type_id LEFT JOIN deduction_types dt ON dt.id = et.deduction_type_id
              LEFT JOIN payroll_runs pr ON pr.id = et.payroll_run_id
              WHERE ' . implode(' AND ', $where) . user_scope_sql('e') . ' ORDER BY et.status, e.last_name, e.first_name, et.id DESC', $params);
$totalE = 0.0;
$totalD = 0.0;
foreach ($list as $t) {
    if ($t['trans_type'] === 'earning') {
        $totalE += (float)$t['amount'];
    } else {
        $totalD += (float)$t['amount'];
    }
}
// transactions with terms (not tied to one period)
$twhere = ['1=1'];
$tparams = [];
if ($tstatusF !== '' && $tstatusF !== 'all') {
    $twhere[] = 'tt.status = ?';
    $tparams[] = $tstatusF;
}
if ($typeF) {
    $twhere[] = 'tt.trans_type = ?';
    $tparams[] = $typeF;
}
if ($filters['org_unit_id']) {   // v20: one, two or more departments, companies / branches, groups
    $twhere[] = sql_in('e.org_unit_id', org_units_with_descendants($filters['org_unit_id']));
}
if ($filters['branch_id']) {
    $twhere[] = sql_in('e.default_branch_id', $filters['branch_id']);
}
if ($filters['employee_group_id']) {
    $twhere[] = sql_in('e.employee_group_id', $filters['employee_group_id']);
}
if (!empty($filters['employee_type_id'])) {
    $twhere[] = 'e.employee_type_id = ?';
    $tparams[] = (int)$filters['employee_type_id'];
}
if (!empty($filters['q'])) {
    $twhere[] = search_words_sql(['e.employee_no', 'e.last_name', 'e.first_name', 'e.biometric_id'], (string)$filters['q'], $tparams);
}
if ($empPre) {
    $twhere[] = 'e.id = ?';
    $tparams[] = $empPre;
}
$termList = rows('SELECT tt.*, e.employee_no, e.first_name, e.last_name, e.middle_name, e.suffix, ou.name AS unit_name, b.code AS branch_code,
                         COALESCE(ea.code, dt.code) AS code, COALESCE(ea.name, dt.name) AS code_name
                  FROM employee_term_trans tt JOIN employees e ON e.id = tt.employee_id
                  LEFT JOIN org_units ou ON ou.id = e.org_unit_id LEFT JOIN branches b ON b.id = tt.branch_id
                  LEFT JOIN earning_types ea ON ea.id = tt.earning_type_id LEFT JOIN deduction_types dt ON dt.id = tt.deduction_type_id
                  WHERE ' . implode(' AND ', $twhere) . user_scope_sql('e') . ' ORDER BY tt.status, e.last_name, e.first_name, tt.id DESC LIMIT 400', $tparams);
$termTotals = ['principal' => 0, 'paid' => 0, 'balance' => 0];
foreach ($termList as $tt) {
    $termTotals['principal'] += (float)$tt['principal'];
    $termTotals['paid'] += (float)$tt['total_paid'];
    $termTotals['balance'] += (float)$tt['balance'];
}
$branchOpts = pairs("SELECT id, CONCAT(COALESCE(branch_code, code), ' — ', name) FROM branches WHERE is_active = 1 ORDER BY company_code, branch_code, code");
$earnCodes = pairs("SELECT id, CONCAT(code, ' — ', name) FROM earning_types WHERE is_active = 1 ORDER BY code");
$dedCodes = pairs("SELECT id, CONCAT(code, ' — ', name) FROM deduction_types WHERE is_active = 1 AND category <> 'statutory' ORDER BY code");
$emps = $period ? pt_period_employees($period, $filters) : [];

page_header("Employee's Transactions", [
    'subtitle' => 'Earnings and deductions per employee: one-time items for a pay period, or transactions with terms (loans, advances, long-term allowances) amortized over several paydays with interest, taken on the 1st, 2nd, 3rd or 4th pay of the month or every pay.',
    'actions' => '<a class="btn" href="' . url('earning_types') . '">Transaction Table</a><a class="btn" href="' . url('fixed_trans') . '">Fixed transactions</a>',
]);
echo dimp_panel($dimp);
?>
<form method="get" class="card filters" data-live="#etTermCard,#etOneCard,#etEmps">
  <input type="hidden" name="p" value="emp_transactions">
  <label>Period<select name="cutoff_id" data-autosubmit><?= options(pt_period_options(), $period['id'] ?? null, 'All periods') ?></select></label>
  <label>Type<select name="trans_type" data-autosubmit><?= options(['earning' => 'Earnings', 'deduction' => 'Deductions'], $typeF, 'All') ?></select></label>
  <label>Status (one-time)<select name="status" data-autosubmit><?= options(['pending' => 'Pending', 'included' => 'Included in payroll', 'cancelled' => 'Cancelled'], $statusF, 'All') ?></select></label>
  <label>Status (with terms)<select name="tstatus" data-autosubmit><?= options(['active' => 'Active', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'all' => 'All'], $tstatusF) ?></select></label>
  <?= multi_pick('branch_id', 'Co. / Branch', $branchOpts, $filters['branch_id'], 'All companies') ?>
  <?= multi_pick('org_unit_id', 'Department', pairs('SELECT id, name FROM org_units WHERE is_active = 1 ORDER BY name'), $filters['org_unit_id'], 'All departments') ?>
  <?= multi_pick('employee_group_id', 'Employee group', emp_group_options(), $filters['employee_group_id'], 'All groups') ?>
  <label>Employee type<select name="employee_type_id"><?= options(lookup_options('emp_types'), $filters['employee_type_id'], 'All types') ?></select></label>
  <label class="grow">Find employee<input type="search" name="q" value="<?= h((string)$filters['q']) ?>" placeholder="Type a name or employee no." autocomplete="off"></label>
  <button class="btn btn-primary">Apply</button>
</form>

<div class="grid grid-2-1 et-layout">
  <div>
  <div class="card flush" id="etTermCard">
    <div class="card-head">
      <div><h2>Transactions with terms</h2><div class="sub"><?= count($termList) ?> record(s), principal <?= money($termTotals['principal']) ?>, paid <?= money($termTotals['paid']) ?>, balance <?= money($termTotals['balance']) ?></div></div>
    </div>
    <div class="table-wrap table-scroll">
      <table class="table table-compact">
        <thead><tr><th>ST</th><th>Employee</th><th>Transaction</th><th>Trans. date</th><th>Document</th><th class="num">Principal</th><th class="num">Interest</th><th class="num">Term</th><th class="num">Per pay</th><th class="num">Total paid</th><th class="num">Balance</th><th>Co./Branch</th><th class="actions"></th></tr></thead>
        <tbody>
        <?php if (!$termList) {
            echo empty_row(13, 'No transaction with terms for these filters. Set Terms above 1 in the form to create one.');
        }
        foreach ($termList as $tt): ?>
          <tr class="<?= $tt['status'] === 'cancelled' ? 'row-muted' : '' ?>">
            <td class="small"><?= h(['active' => 'A', 'completed' => 'C', 'cancelled' => 'X'][$tt['status']] ?? '') ?></td>
            <td><strong><?= h(full_name($tt)) ?></strong><br><small class="muted"><?= h($tt['employee_no']) ?></small></td>
            <td><?= h($tt['code']) ?> <?= h($tt['code_name']) ?><br><small class="muted"><?= h(label($tt['trans_type'])) ?>, <?= h(strtolower(TERM_TRANS_TERMS[$tt['trans_term']] ?? $tt['trans_term'])) ?></small></td>
            <td class="nowrap small"><?= h(fmt_date($tt['trans_date'])) ?></td>
            <td class="small"><?= h($tt['doc_no'] ?: '—') ?></td>
            <td class="num"><?= money($tt['principal']) ?></td>
            <td class="num"><?= money($tt['interest_amount']) ?></td>
            <td class="num nowrap"><?= (int)$tt['paid_terms'] ?> / <?= (int)$tt['terms'] ?></td>
            <td class="num"><?= money((float)$tt['per_pay_principal'] + (float)$tt['per_pay_interest']) ?></td>
            <td class="num"><?= money($tt['total_paid']) ?></td>
            <td class="num"><strong><?= money($tt['balance']) ?></strong></td>
            <td class="small"><?= h($tt['branch_code'] ?: '—') ?></td>
            <td class="actions">
              <a class="btn btn-xs" href="<?= h(url('emp_term_view', ['id' => $tt['id']])) ?>">View</a>
              <?php if ($tt['status'] !== 'cancelled'): ?><a class="btn btn-xs" href="<?= h(url('emp_transactions', array_merge($keep, ['edit_term' => $tt['id']]))) ?>">Edit</a><?php endif; ?>
              <?php if ($tt['status'] === 'active'): ?>
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$tt['id'] ?>">
                  <button class="btn btn-xs btn-danger-outline" name="_action" value="term_cancel" data-confirm="Cancel this transaction with terms? Pending installments will no longer be taken.">Cancel</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card flush" id="etOneCard">
    <div class="card-head">
      <div><h2>One-time transactions</h2><div class="sub"><?= count($list) ?> record(s), earnings <?= money($totalE) ?>, deductions <?= money($totalD) ?></div></div>
    </div>
    <div class="table-wrap table-scroll">
      <table class="table table-compact">
        <thead><tr><th>Employee</th><th>Code</th><th>Type</th><th class="num">Amount</th><th>Period</th><th>Remarks</th><th>Status</th><th class="actions"></th></tr></thead>
        <tbody>
        <?php if (!$list) {
            echo empty_row(8, 'No transaction for these filters.');
        }
        foreach ($list as $t): ?>
          <tr<?= $edit && (int)$edit['id'] === (int)$t['id'] ? ' class="row-sel"' : '' ?>>
            <td><strong><?= h(full_name($t)) ?></strong><br><small class="muted"><?= h($t['employee_no']) ?>, <?= h($t['unit_name'] ?: '—') ?></small></td>
            <td><?= h($t['code']) ?><br><small class="muted"><?= h($t['code_name']) ?></small></td>
            <td><span class="badge badge-<?= $t['trans_type'] === 'earning' ? 'green' : 'amber' ?>"><?= h(label($t['trans_type'])) ?></span></td>
            <td class="num"><strong><?= money($t['amount']) ?></strong></td>
            <td class="nowrap small"><?= h(fmt_date($t['period_start'], 'M d')) ?>–<?= h(fmt_date($t['period_end'], 'M d, Y')) ?></td>
            <td class="small"><?= h($t['remarks'] ?: '') ?></td>
            <td><?= badge($t['status']) ?><?= $t['run_no'] ? '<br><small class="muted">' . h($t['run_no']) . '</small>' : '' ?></td>
            <td class="actions">
              <?php if ($t['status'] === 'pending'): ?>
                <a class="btn btn-xs" href="<?= h(url('emp_transactions', array_merge($keep, ['edit' => $t['id']]))) ?>">Edit</a>
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                  <button class="btn btn-xs btn-danger-outline" name="_action" value="delete" data-confirm="Delete this transaction?">Delete</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  </div>

  <?php if ($editTerm):
      $et = $editTerm;
      $lockFin = $termTaken > 0 || $et['status'] !== 'active'; ?>
  <div class="card et-form" id="termEdit">
    <h2>Edit transaction with terms</h2>
    <p class="muted small"><?= h(full_name($et)) ?>, <?= h($et['employee_no']) ?>. <?= $termTaken ? $termTaken . ' installment(s) already taken: the amount stays; the remaining balance of ' . h(money($et['balance'])) . ' is spread again over the remaining paydays.' : 'No installment taken yet: every field can be changed and the schedule is rebuilt.' ?></p>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="_action" value="term_save"><input type="hidden" name="term_id" value="<?= (int)$et['id'] ?>">
      <div class="form-grid">
        <div class="field"><label>Type</label>
          <select name="trans_type" id="etType"<?= $lockFin ? ' disabled' : '' ?>><?= options(['earning' => 'Earning', 'deduction' => 'Deduction'], $et['trans_type']) ?></select></div>
        <div class="field"><label>Amount (principal)</label><input type="number" step="0.01" min="0" name="amount" value="<?= h(number_format((float)$et['principal'], 2, '.', '')) ?>"<?= $lockFin ? ' readonly' : ' required' ?>></div>
        <div class="field wide"><label>Transaction code</label>
          <select name="code_id" id="etCode"<?= $lockFin ? ' disabled' : ' required' ?>>
            <optgroup label="Earnings" data-type="earning"><?= options($earnCodes, $et['trans_type'] === 'earning' ? $et['earning_type_id'] : null) ?></optgroup>
            <optgroup label="Deductions" data-type="deduction"><?= options($dedCodes, $et['trans_type'] === 'deduction' ? $et['deduction_type_id'] : null) ?></optgroup>
          </select></div>
        <?php if ($lockFin): ?>
          <div class="field"><label>Remaining paydays</label><input type="number" name="remaining_terms" min="1" max="520" value="<?= $termPending ?: 1 ?>"<?= $et['status'] !== 'active' ? ' disabled' : '' ?>><span class="help"><?= $termTaken ?> of <?= (int)$et['terms'] ?> taken.</span></div>
        <?php else: ?>
          <div class="field"><label>Terms (paydays)</label><input type="number" name="terms" id="etTerms" min="1" max="520" value="<?= (int)$et['terms'] ?>" required></div>
        <?php endif; ?>
        <div class="field"><label>Trans. term</label><select name="trans_term" id="etTransTerm"<?= $et['status'] !== 'active' ? ' disabled' : '' ?>><?= options(TERM_TRANS_TERMS, $et['trans_term']) ?></select></div>
        <?php if (!$lockFin): ?>
          <div class="field"><label>Monthly interest rate (%)</label><input type="number" name="monthly_rate" id="etRate" step="0.001" min="0" value="<?= h((string)(float)$et['monthly_rate']) ?>"></div>
          <div class="field"><label>Interest amount</label><input type="number" name="interest_amount" id="etInterest" step="0.01" min="0" value="<?= h(number_format((float)$et['interest_amount'], 2, '.', '')) ?>"><span class="help">Clear it to compute from the rate.</span></div>
          <div class="field"><label>Trans/PD (principal)</label><input type="number" name="per_pay_principal" id="etPPP" step="0.01" min="0" placeholder="computed"></div>
          <div class="field"><label>Trans/PD (interest)</label><input type="number" name="per_pay_interest" id="etPPI" step="0.01" min="0" placeholder="computed"></div>
        <?php endif; ?>
        <div class="field"><label>Trans. date</label><input type="date" name="trans_date" value="<?= h($et['trans_date']) ?>"></div>
        <div class="field"><label><?= $lockFin ? 'Next installment on or after' : 'Start (first payday on or after)' ?></label><input type="date" name="start_date" value="<?= h($lockFin ? ($et['next_date'] ?: today()) : $et['start_date']) ?>"<?= $et['status'] !== 'active' ? ' disabled' : '' ?>></div>
        <div class="field"><label>Document no.</label><input name="doc_no" maxlength="40" value="<?= h($et['doc_no'] ?? '') ?>"></div>
        <div class="field"><label>Co. / Branch</label><select name="branch_id"><?= options($branchOpts, $et['branch_id'], "Employee's branch") ?></select></div>
        <div class="field wide"><label>Remarks</label><input name="remarks" value="<?= h($et['remarks'] ?? '') ?>" maxlength="255"></div>
        <div class="field wide"><div class="et-preview" id="etPreview" hidden></div></div>
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" data-confirm="Save the changes and rebuild the installment schedule?">Save changes</button>
        <a class="btn" href="<?= h(url('emp_transactions', $keep)) ?>">Cancel</a>
        <a class="btn btn-ghost" href="<?= h(url('emp_term_view', ['id' => $et['id']])) ?>">Schedule</a>
      </div>
    </form>
  </div>
  <?php else: ?>
  <div class="card et-form">
    <h2><?= $edit ? 'Edit transaction' : 'New transaction' ?></h2>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="_action" value="save">
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><input type="hidden" name="employee_id" value="<?= (int)$edit['employee_id'] ?>"><?php endif; ?>
      <div class="form-grid">
        <div class="field wide"><label>Period</label>
          <select name="cutoff_id" required><?= options(pt_period_options(), $edit['cutoff_id'] ?? ($period['id'] ?? null)) ?></select></div>
        <?php if ($edit): ?>
        <div class="field"><label>Type</label>
          <select name="trans_type" id="etType"><?= options(['earning' => 'Earning', 'deduction' => 'Deduction'], $edit['trans_type'] ?? 'earning') ?></select></div>
        <div class="field"><label>Amount</label><input type="number" step="0.01" min="0" name="amount" value="<?= h(number_format((float)($edit['amount'] ?? 0), 2, '.', '')) ?>" required></div>
        <div class="field wide"><label>Transaction code</label>
          <select name="code_id" id="etCode" required>
            <optgroup label="Earnings" data-type="earning"><?= options($earnCodes, $edit && $edit['trans_type'] === 'earning' ? $edit['earning_type_id'] : null) ?></optgroup>
            <optgroup label="Deductions" data-type="deduction"><?= options($dedCodes, $edit && $edit['trans_type'] === 'deduction' ? $edit['deduction_type_id'] : null) ?></optgroup>
          </select>
        </div>
        <?php else:
            // v17: tick one, two, three or more transaction codes, each with its amount
            $grp = ['Earnings' => [], 'Deductions' => []];
            foreach ($earnCodes as $cid => $lbl) {
                $grp['Earnings'][] = ['key' => 'earning:' . $cid, 'label' => $lbl];
            }
            foreach ($dedCodes as $cid => $lbl) {
                $grp['Deductions'][] = ['key' => 'deduction:' . $cid, 'label' => $lbl];
            } ?>
        <div class="field wide"><label>Transaction codes <span class="muted">(tick one, two, three or more)</span></label>
          <?= code_pick_html('etCodes', $grp, ['amount' => ['label' => 'Amount', 'type' => 'number', 'placeholder' => '0.00']], ['title' => 'Code', 'search' => true]) ?>
          <span class="help">Every ticked code is encoded for each employee ticked below with its own amount. With terms above 1, each code becomes a transaction with terms.</span></div>
        <?php endif; ?>
        <?php if (!$edit): ?>
        <div class="field wide"><label>Employees <span class="muted">(tick one, two or more)</span></label>
          <select name="employee_ids[]" id="etEmps" multiple size="9" required>
            <?php foreach ($emps as $e): ?>
              <option value="<?= (int)$e['id'] ?>"<?= (int)$e['id'] === $empPre ? ' selected' : '' ?>><?= h(full_name($e)) ?> — <?= h($e['employee_no']) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="help"><?= $empPre ? 'Opened for the employee selected on the previous screen; pick others to add them too.' : 'The list follows the period and the filters above (Co. / Branch, Department, Employee group, Find). The filters stay after saving, for the next transaction.' ?></span>
        </div>
        <?php else: ?>
          <div class="field wide"><label>Employee</label><input value="<?= h(full_name(row('SELECT * FROM employees WHERE id = ?', [$edit['employee_id']]))) ?>" disabled></div>
        <?php endif; ?>
        <div class="field wide"><label>Remarks</label><input name="remarks" value="<?= h($edit['remarks'] ?? '') ?>" maxlength="255"></div>
        <?php if (!$edit): ?>
        <div class="field wide form-section-title">Terms (leave at 1 for a one-time transaction)</div>
        <div class="field"><label>Terms (paydays)</label><input type="number" name="terms" id="etTerms" min="1" max="520" step="1" value="1"><span class="help">2 or more spreads the amount over that many paydays.</span></div>
        <div class="field"><label>Trans. term</label><select name="trans_term" id="etTransTerm"><?= options(TERM_TRANS_TERMS, 'every_pay') ?></select><span class="help">Which pay of the month takes the installment.</span></div>
        <div class="field"><label>Monthly interest rate (%)</label><input type="number" name="monthly_rate" id="etRate" step="0.001" min="0" value="0"></div>
        <div class="field"><label>Interest amount</label><input type="number" name="interest_amount" id="etInterest" step="0.01" min="0" placeholder="computed"><span class="help">Blank = amount × rate × months.</span></div>
        <div class="field"><label>Trans/PD (principal)</label><input type="number" name="per_pay_principal" id="etPPP" step="0.01" min="0" placeholder="computed"></div>
        <div class="field"><label>Trans/PD (interest)</label><input type="number" name="per_pay_interest" id="etPPI" step="0.01" min="0" placeholder="computed"></div>
        <div class="field"><label>Trans. date</label><input type="date" name="trans_date" value="<?= h(today()) ?>"></div>
        <div class="field"><label>Start (first payday on or after)</label><input type="date" name="start_date" value="<?= h($period['period_start'] ?? today()) ?>"></div>
        <div class="field"><label>Document no.</label><input name="doc_no" maxlength="40" placeholder="voucher, agency reference"></div>
        <div class="field"><label>Co. / Branch</label><select name="branch_id"><?= options($branchOpts, null, "Employee's branch") ?></select></div>
        <div class="field wide"><div class="et-preview" id="etPreview" hidden></div></div>
        <?php endif; ?>
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" id="etSubmit"><?= $edit ? 'Save changes' : 'Add transaction(s)' ?></button>
        <?php if ($edit): ?><a class="btn" href="<?= h(url('emp_transactions', $keep)) ?>">Cancel</a><?php endif; ?>
      </div>
    </form>
    <p class="help">Earning codes carry their own tax treatment (taxable, de minimis, special, separate tax rate) from the Transaction Table; deduction codes carry the payroll-register column and the GL account.</p>
  </div>
  <?php endif; ?>
</div>
<script>
(function () {
  var t = document.getElementById('etType'), c = document.getElementById('etCode');
  function sync() {
    if (!t || !c) return;
    var want = t.value;
    Array.prototype.forEach.call(c.getElementsByTagName('optgroup'), function (g) {
      var on = g.getAttribute('data-type') === want;
      g.disabled = !on; g.hidden = !on;
      Array.prototype.forEach.call(g.children, function (o) { o.hidden = !on; o.disabled = !on; });
    });
    if (c.selectedOptions.length && c.selectedOptions[0].disabled) {
      var first = c.querySelector('optgroup[data-type="' + want + '"] option');
      if (first) c.value = first.value;
    }
  }
  if (t && c) { t.addEventListener('change', sync); sync(); }

  // transactions with terms: live per-payday preview (mirrors term_compute in app/engine/termtrans.php)
  var terms = document.getElementById('etTerms'), rate = document.getElementById('etRate'), amtOf = function () { return document.querySelector('[name="amount"]') || document.querySelector('#etCodes .cp-row.is-on input[type=number]'); },
      tt = document.getElementById('etTransTerm'), intr = document.getElementById('etInterest'), ppp = document.getElementById('etPPP'), ppi = document.getElementById('etPPI'),
      prev = document.getElementById('etPreview'), submit = document.getElementById('etSubmit');
  var ppmOf = <?= json_encode(['every_pay' => round(periods_per_month($period['frequency'] ?? 'semi_monthly'), 4), 'fixed' => 1]) ?>;
  function money(n) { return '₱' + (Math.round(n * 100) / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function preview() {
    if (!terms || !prev) return;
    var amt = amtOf(), nCodes = document.querySelectorAll('#etCodes .cp-row.is-on').length;
    var n = parseInt(terms.value || '1', 10) || 1, a = parseFloat((amt && amt.value) || '0') || 0, r = parseFloat(rate.value || '0') || 0;
    if (n <= 1 || a <= 0) { prev.hidden = true; if (submit) submit.textContent = 'Add transaction(s)'; return; }
    var ppm = tt.value === 'every_pay' ? ppmOf.every_pay : ppmOf.fixed;
    var months = n / ppm;
    var interest = intr.value !== '' ? parseFloat(intr.value) : Math.round(a * r / 100 * months * 100) / 100;
    var pp = ppp.value !== '' ? parseFloat(ppp.value) : Math.round(a / n * 100) / 100;
    var pi = ppi.value !== '' ? parseFloat(ppi.value) : Math.round(interest / n * 100) / 100;
    intr.placeholder = interest.toFixed(2); ppp.placeholder = pp.toFixed(2); ppi.placeholder = pi.toFixed(2);
    prev.hidden = false;
    prev.innerHTML = (nCodes > 1 ? 'First ticked code: ' : '') + '<strong>' + n + ' paydays</strong>, ' + tt.options[tt.selectedIndex].text.toLowerCase() + ' (' + months.toFixed(2).replace(/\.?0+$/, '') + ' months). '
      + 'Principal ' + money(a) + ' + interest ' + money(interest) + ' = <strong>' + money(a + interest) + '</strong>. '
      + 'Per payday: ' + money(pp) + ' principal + ' + money(pi) + ' interest = <strong>' + money(pp + pi) + '</strong>.';
    if (submit) submit.textContent = 'Create transaction(s) with terms';
  }
  [terms, rate, tt, intr, ppp, ppi, amtOf(), document.getElementById('etCodes')].forEach(function (el) { if (el) { el.addEventListener('input', preview); el.addEventListener('change', preview); } });
  preview();
})();
</script>
<?php page_footer();
