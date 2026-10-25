<?php
/**
 * Fixed Transaction Setup — recurring fixed amounts per employee (allowances, fixed contributions,
 * fixed withholding tax). A fixed transaction only needs a start date, an amount and its terms (every
 * pay, or the 1st, 2nd, 3rd or 4th pay of the month). Once set up it keeps adding (earnings) or
 * deducting (deductions) on every payroll it applies to while the employee works — it has no end,
 * unlike the Employee's Transactions, which run for a number of months. Stop it to end it.
 * v14: Hold pauses one (it is left out of the payroll computation) until Resume; the code list says
 * Earning or Deduction; Find and Department also narrow the employees of the Assign form.
 * Codes marked with a statutory role replace the computed SSS / Pag-IBIG / PhilHealth / tax.
 * v16: Co. / Branch, Employee group and Employee type filters; the employees of a code are ticked in a
 * searchable pick list (one, two or more); the list narrows while typing in Find; the filters stay
 * after saving so the next employees of the same filter can be assigned at once.
 * v17: the Assign form takes one, two, three or more fixed transaction codes at once, each with its own
 * amount and terms (multi-selection of the codes); editing a record keeps one code.
 */
$filters = pt_filters();
$codeF = (int)get('code_id', 0);
$statusF = (string)get('status', 'active');
$empPre = (int)get('emp', 0);   // employee picked on the Payroll Register: the list and the form open on that person
$keep = array_filter(array_merge(['p' => 'fixed_trans', 'code_id' => $codeF ?: null, 'status' => $statusF, 'emp' => $empPre ?: null], $filters), fn($v) => $v !== null && $v !== '');
$keep = array_merge($keep, back_params());

$dimp = dimp_def('fixed_trans', null, []);   // CSV import facility on this form (app/engine/dataimport.php)
dimp_handle($dimp);
if (is_post()) {
    verify_csrf();
    $action = post('_action');
    try {
        if ($action === 'save' && !(int)post('id')) {
            // v17: several codes at once, each with its amount and terms, for every employee ticked
            $picked = code_pick_posted(['amount', 'terms']);
            $emps = array_map('intval', (array)post('employee_ids', []));
            if (!$picked) {
                throw new RuntimeException('Tick at least one fixed transaction code.');
            }
            if (!$emps) {
                throw new RuntimeException('Select at least one employee.');
            }
            $start = (string)post('start_date');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
                throw new RuntimeException('Enter the start date: the first payroll the fixed transaction applies to.');
            }
            $plan = [];
            foreach ($picked as $cid => $v) {
                $code = row('SELECT * FROM fixed_trans_codes WHERE id = ? AND is_active = 1', [(int)$cid]);
                if (!$code) {
                    throw new RuntimeException('A ticked code no longer exists.');
                }
                $amount = round((float)str_replace(',', '', (string)$v['amount']), 2);
                if ($amount < 0) {
                    throw new RuntimeException('The amount of ' . $code['code'] . ' cannot be negative.');
                }
                $terms = (string)$v['terms'];
                if (!isset(FIXED_TERMS[$terms]) && !isset(FIXED_TERMS_LEGACY[$terms])) {
                    $terms = isset(FIXED_TERMS[$code['default_terms']]) ? $code['default_terms'] : 'every_pay';
                }
                $plan[] = [$code, $amount, $terms];
            }
            $n = 0;
            foreach ($plan as [$code, $amount, $terms]) {
                $data = ['code_id' => (int)$code['id'], 'amount' => $amount, 'terms' => $terms, 'start_date' => $start, 'end_date' => null,
                    'status' => 'active', 'remarks' => trim((string)post('remarks')) ?: null];
                foreach ($emps as $eid) {
                    $dup = val('SELECT id FROM employee_fixed_trans WHERE employee_id = ? AND code_id = ? AND status IN ("active", "hold")', [$eid, $code['id']]);
                    if ($dup) {
                        update('employee_fixed_trans', $data, 'id = ?', [$dup]);
                    } else {
                        insert('employee_fixed_trans', array_merge($data, ['employee_id' => $eid, 'created_by' => user_id()]));
                    }
                    $n++;
                }
                audit('payroll', 'fixed_trans_assign', 'fixed_trans_codes', (int)$code['id'], ['employees' => count($emps), 'amount' => $amount, 'terms' => $terms]);
            }
            flash('success', count($plan) . ' code(s) assigned to ' . count($emps) . ' employee(s): ' . $n . ' fixed transaction(s) saved.');
        } elseif ($action === 'save') {
            $codeId = (int)post('code_id');
            $code = $codeId ? row('SELECT * FROM fixed_trans_codes WHERE id = ?', [$codeId]) : null;
            if (!$code) {
                throw new RuntimeException('Select a fixed transaction code.');
            }
            $amount = round((float)str_replace(',', '', (string)post('amount')), 2);
            if ($amount < 0) {
                throw new RuntimeException('The amount cannot be negative.');
            }
            $terms = (string)post('terms');
            if (!isset(FIXED_TERMS[$terms]) && !isset(FIXED_TERMS_LEGACY[$terms])) {
                $terms = isset(FIXED_TERMS[$code['default_terms']]) ? $code['default_terms'] : 'every_pay';
            }
            $start = (string)post('start_date');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
                throw new RuntimeException('Enter the start date: the first payroll the fixed transaction applies to.');
            }
            $data = [
                'code_id' => $codeId, 'amount' => $amount, 'terms' => $terms,
                'start_date' => $start, 'end_date' => null,   // no end: it runs while the employee works (Stop ends it)
                'status' => in_array(post('status'), ['stopped', 'hold'], true) ? post('status') : 'active', 'remarks' => trim((string)post('remarks')) ?: null,
            ];
            $id = (int)post('id');
            if ($id) {
                $old = row('SELECT * FROM employee_fixed_trans WHERE id = ?', [$id]);
                update('employee_fixed_trans', $data, 'id = ?', [$id]);
                audit('payroll', 'fixed_trans_update', 'employee_fixed_trans', $id, diff_fields($old ?: [], $data));
                flash('success', 'Fixed transaction updated.');
            } else {
                $emps = array_map('intval', (array)post('employee_ids', []));
                if (!$emps) {
                    throw new RuntimeException('Select at least one employee.');
                }
                $n = 0;
                foreach ($emps as $eid) {
                    $dup = val('SELECT id FROM employee_fixed_trans WHERE employee_id = ? AND code_id = ? AND status IN ("active", "hold")', [$eid, $codeId]);
                    if ($dup) {
                        update('employee_fixed_trans', $data, 'id = ?', [$dup]);
                    } else {
                        insert('employee_fixed_trans', array_merge($data, ['employee_id' => $eid, 'created_by' => user_id()]));
                    }
                    $n++;
                }
                audit('payroll', 'fixed_trans_assign', 'fixed_trans_codes', $codeId, ['employees' => $n, 'amount' => $amount]);
                flash('success', $n . ' employee(s) updated.');
            }
        } elseif (in_array($action, ['stop', 'start', 'delete', 'hold', 'resume'], true)) {
            $id = (int)post('id');
            $ft = row('SELECT * FROM employee_fixed_trans WHERE id = ?', [$id]);
            if (!$ft) {
                throw new RuntimeException('Fixed transaction not found.');
            }
            if ($action === 'delete') {
                q('DELETE FROM employee_fixed_trans WHERE id = ?', [$id]);
            } elseif ($action === 'hold') {
                // paused: left out of the earning / deduction computation until Resume
                update('employee_fixed_trans', ['status' => 'hold', 'held_at' => now(), 'held_by' => user_id()], 'id = ?', [$id]);
            } elseif ($action === 'resume') {
                update('employee_fixed_trans', ['status' => 'active', 'held_at' => null, 'held_by' => null], 'id = ?', [$id]);
            } else {
                update('employee_fixed_trans', ['status' => $action === 'stop' ? 'stopped' : 'active', 'held_at' => null, 'held_by' => null], 'id = ?', [$id]);
            }
            audit('payroll', 'fixed_trans_' . $action, 'employee_fixed_trans', $id, ['employee_id' => $ft['employee_id'], 'code_id' => $ft['code_id']]);
            flash('success', ['delete' => 'Fixed transaction deleted.', 'hold' => 'Fixed transaction on hold: it is left out of the payroll until you press Resume.',
                'resume' => 'Fixed transaction resumed: it applies again from the next payroll computed.', 'stop' => 'Fixed transaction stopped.', 'start' => 'Fixed transaction re-activated.'][$action]);
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('fixed_trans', $keep);
}

$edit = (int)get('edit', 0) ? row('SELECT * FROM employee_fixed_trans WHERE id = ?', [(int)get('edit')]) : null;
$where = ['1=1'];
$params = [];
if ($codeF) {
    $where[] = 'ft.code_id = ?';
    $params[] = $codeF;
}
if ($statusF) {
    $where[] = 'ft.status = ?';
    $params[] = $statusF;
}
// employee filters shared by the list and the Assign form (Co. / Branch, Department, Employee group / type, Find)
$empFilter = function (array &$w, array &$p) use ($filters): void {
    if ($filters['org_unit_id']) {   // v20: one, two or more departments, companies / branches, groups
        $w[] = sql_in('e.org_unit_id', org_units_with_descendants($filters['org_unit_id']));
    }
    if ($filters['branch_id']) {
        $w[] = sql_in('e.default_branch_id', $filters['branch_id']);
    }
    if ($filters['employee_group_id']) {
        $w[] = sql_in('e.employee_group_id', $filters['employee_group_id']);
    }
    if (!empty($filters['employee_type_id'])) {
        $w[] = 'e.employee_type_id = ?';
        $p[] = (int)$filters['employee_type_id'];
    }
    if (!empty($filters['q'])) {
        $w[] = search_words_sql(['e.employee_no', 'e.last_name', 'e.first_name', 'e.middle_name', 'e.biometric_id'], (string)$filters['q'], $p);
    }
};
$empFilter($where, $params);
if ($empPre) {
    $where[] = 'e.id = ?';
    $params[] = $empPre;
}
$list = rows('SELECT ft.*, e.employee_no, e.first_name, e.last_name, e.middle_name, e.suffix, ou.name AS unit_name, c.code, c.name AS code_name, c.trans_type, c.role, c.default_terms
              FROM employee_fixed_trans ft JOIN employees e ON e.id = ft.employee_id JOIN fixed_trans_codes c ON c.id = ft.code_id
              LEFT JOIN org_units ou ON ou.id = e.org_unit_id
              WHERE ' . implode(' AND ', $where) . user_scope_sql('e') . ' ORDER BY c.code, e.last_name, e.first_name', $params);
// the code lists say whether a code is an Earning or a Deduction
$codeRows = rows('SELECT id, code, name, trans_type, role, default_terms FROM fixed_trans_codes WHERE is_active = 1 ORDER BY trans_type, code');
$codes = [];
foreach ($codeRows as $c) {
    $codes[(int)$c['id']] = $c['code'] . ' — ' . $c['name'] . ($c['trans_type'] === 'earning' ? '  (Earning)' : '  (Deduction' . ($c['role'] !== 'none' ? ', replaces ' . strtoupper($c['role']) : '') . ')');
}
$codeOptions = function ($sel, ?string $placeholder = null) use ($codeRows, $codes): string {
    $out = $placeholder !== null ? '<option value="">' . h($placeholder) . '</option>' : '';
    foreach (['earning' => 'Earnings', 'deduction' => 'Deductions'] as $type => $label) {
        $grp = array_filter($codeRows, fn($c) => $c['trans_type'] === $type);
        if (!$grp) {
            continue;
        }
        $out .= '<optgroup label="' . h($label) . '">';
        foreach ($grp as $c) {
            $out .= '<option value="' . (int)$c['id'] . '" data-type="' . h($type) . '"' . ((string)$sel === (string)$c['id'] ? ' selected' : '') . '>' . h($codes[(int)$c['id']]) . '</option>';
        }
        $out .= '</optgroup>';
    }
    return $out;
};
$total = 0.0;
$onHold = 0;
foreach ($list as $t) {
    if ($t['status'] === 'hold') {
        $onHold++;
    }
    if ($t['status'] !== 'active') {
        continue;   // held and stopped ones are not paid / deducted
    }
    $total += $t['trans_type'] === 'earning' ? (float)$t['amount'] : -(float)$t['amount'];
}
// employees of the Assign form: narrowed by the same filters as the list
$empWhere = ['e.status = "active"'];
$empParams = [];
$empFilter($empWhere, $empParams);
$filtered = !empty($filters['q']) || !empty($filters['org_unit_id']) || !empty($filters['branch_id']) || !empty($filters['employee_group_id']) || !empty($filters['employee_type_id']);
$assignEmps = rows('SELECT e.* FROM employees e WHERE ' . implode(' AND ', $empWhere) . user_scope_sql('e') . ' ORDER BY e.last_name, e.first_name', $empParams);
if ($empPre && !array_filter($assignEmps, fn($e) => (int)$e['id'] === $empPre)) {
    $assignEmps = array_merge(rows('SELECT e.* FROM employees e WHERE e.id = ?' . user_scope_sql('e'), [$empPre]), $assignEmps);
}

page_header('Fixed Transaction Setup', [
    'subtitle' => 'Recurring fixed amounts per employee: a start date, an amount and the terms (every pay, or the 1st, 2nd, 3rd or 4th pay of the month). They keep applying while the employee works; Stop ends one.',
    'actions' => '<a class="btn" href="' . url('fixed_trans_codes') . '">Fixed Transaction Table</a><a class="btn" href="' . url('emp_transactions') . '">One-time transactions</a>',
]);
echo dimp_panel($dimp);
?>
<form method="get" class="card filters" data-live="#ftListCard,#ftEmps,#ftNote">
  <input type="hidden" name="p" value="fixed_trans">
  <label>Code<select name="code_id" data-autosubmit><?= $codeOptions($codeF ?: null, 'All codes') ?></select></label>
  <label>Status<select name="status" data-autosubmit><?= options(['active' => 'Active', 'hold' => 'On hold', 'stopped' => 'Stopped'], $statusF, 'All') ?></select></label>
  <?= multi_pick('branch_id', 'Co. / Branch', pairs("SELECT id, CONCAT(COALESCE(branch_code, code), ' — ', name) FROM branches WHERE is_active = 1 ORDER BY company_code, branch_code, code"), $filters['branch_id'], 'All companies') ?>
  <?= multi_pick('org_unit_id', 'Department', pairs('SELECT id, name FROM org_units WHERE is_active = 1 ORDER BY name'), $filters['org_unit_id'], 'All departments') ?>
  <?= multi_pick('employee_group_id', 'Employee group', emp_group_options(), $filters['employee_group_id'], 'All groups') ?>
  <label>Employee type<select name="employee_type_id"><?= options(lookup_options('emp_types'), $filters['employee_type_id'], 'All types') ?></select></label>
  <label class="grow">Find employee<input type="search" name="q" value="<?= h((string)$filters['q']) ?>" placeholder="Type a name or employee no." autocomplete="off"></label>
  <button class="btn btn-primary">Apply</button>
</form>

<div class="grid grid-2-1">
  <div class="card flush" id="ftListCard">
    <div class="card-head"><div><h2>Employee fixed transactions</h2><div class="sub"><?= count($list) ?> record(s), net effect per month <?= money($total) ?><?= $onHold ? ', <span class="text-amber">' . $onHold . ' on hold</span>' : '' ?></div></div></div>
    <div class="table-wrap table-scroll">
      <table class="table table-compact">
        <thead><tr><th>Employee</th><th>Code</th><th class="num">Amount</th><th>Terms</th><th>Start date</th><th>Status</th><th class="actions"></th></tr></thead>
        <tbody>
        <?php if (!$list) {
            echo empty_row(7, 'No fixed transaction yet.');
        }
        foreach ($list as $t): ?>
          <tr class="<?= $edit && (int)$edit['id'] === (int)$t['id'] ? 'row-sel' : '' ?><?= $t['status'] === 'hold' ? ' row-hold' : '' ?>">
            <td><strong><?= h(full_name($t)) ?></strong><br><small class="muted"><?= h($t['employee_no']) ?>, <?= h($t['unit_name'] ?: '—') ?></small></td>
            <td><?= h($t['code']) ?> <?= h($t['code_name']) ?><br><small class="<?= $t['trans_type'] === 'earning' ? 'text-green' : 'text-amber' ?>"><?= h(label($t['trans_type'])) ?></small><?= $t['role'] !== 'none' ? '<small class="muted">, replaces ' . h(strtoupper($t['role'])) . '</small>' : '' ?></td>
            <td class="num"><strong><?= money($t['amount']) ?></strong></td>
            <td class="small"><?= h(fixed_term_label($t['terms'] ?: $t['default_terms'] ?? 'every_pay')) ?></td>
            <td class="small nowrap"><?= h($t['start_date'] ? fmt_date($t['start_date']) : 'from the start') ?><br><span class="muted"><?= $t['end_date'] ? 'until ' . h(fmt_date($t['end_date'])) . ' (old setting)' : 'no end' ?></span></td>
            <td><?= $t['status'] === 'hold' ? '<span class="badge badge-amber">On hold</span><br><small class="muted">since ' . h(fmt_date(substr((string)$t['held_at'], 0, 10))) . '</small>' : badge($t['status']) ?></td>
            <td class="actions ft-actions">
              <a class="btn btn-xs" href="<?= h(url('fixed_trans', array_merge($keep, ['edit' => $t['id']]))) ?>">Edit</a>
              <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <?php if ($t['status'] === 'active'): ?>
                  <button class="btn btn-xs btn-hold" name="_action" value="hold" title="Pause: left out of the earning / deduction computation until Resume">Hold</button>
                  <button class="btn btn-xs" name="_action" value="stop" data-confirm="Stop this fixed transaction?">Stop</button>
                <?php elseif ($t['status'] === 'hold'): ?>
                  <button class="btn btn-xs btn-resume" name="_action" value="resume" title="Apply it again from the next payroll">Resume</button>
                  <button class="btn btn-xs" name="_action" value="stop" data-confirm="Stop this fixed transaction?">Stop</button>
                <?php else: ?>
                  <button class="btn btn-xs" name="_action" value="start">Re-activate</button>
                <?php endif; ?>
                <button class="btn btn-xs btn-danger-outline" name="_action" value="delete" data-confirm="Delete this fixed transaction?">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <h2><?= $edit ? 'Edit fixed transaction' : 'Assign a fixed transaction' ?></h2>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="_action" value="save">
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
      <div class="form-grid">
        <?php if ($edit): ?>
        <div class="field wide"><label>Code</label><select name="code_id" required data-ed-indicator><?= $codeOptions($edit['code_id'] ?? null) ?></select>
          <span class="help ed-indicator" aria-live="polite"></span></div>
        <div class="field"><label>Amount</label><input type="number" step="0.01" min="0" name="amount" value="<?= h(number_format((float)($edit['amount'] ?? 0), 2, '.', '')) ?>" required></div>
        <div class="field"><label>Terms</label><select name="terms" required><?= options(fixed_term_options($edit['terms'] ?? null), $edit['terms'] ?? 'every_pay') ?></select>
          <span class="help">Every pay, or one pay of the month (the 3rd and 4th pays exist on weekly payrolls).</span></div>
        <?php else:
            // v17: tick one, two, three or more codes, each with its amount and terms
            $pickGroups = ['Earnings' => [], 'Deductions' => []];
            foreach ($codeRows as $c) {
                $pickGroups[$c['trans_type'] === 'earning' ? 'Earnings' : 'Deductions'][] = ['key' => (int)$c['id'], 'label' => $c['code'] . ' — ' . $c['name'],
                    'hint' => $c['trans_type'] === 'earning' ? 'Earning: added to the pay' : 'Deduction' . ($c['role'] !== 'none' ? ', replaces ' . strtoupper($c['role']) : ''),
                    'values' => ['amount' => '', 'terms' => isset(FIXED_TERMS[$c['default_terms'] ?? '']) ? $c['default_terms'] : 'every_pay']];
            } ?>
        <div class="field wide"><label>Fixed transaction codes <span class="muted">(tick one, two, three or more)</span></label>
          <?= code_pick_html('ftCodes', $pickGroups, ['amount' => ['label' => 'Amount', 'type' => 'number', 'placeholder' => '0.00'], 'terms' => ['label' => 'Terms', 'type' => 'select', 'options' => FIXED_TERMS]], ['title' => 'Code', 'search' => true]) ?>
          <span class="help">Each ticked code is assigned to every employee ticked below with its own amount and terms.</span></div>
        <?php endif; ?>
        <div class="field"><label>Start date</label><input type="date" name="start_date" value="<?= h($edit['start_date'] ?? today()) ?>" required>
          <span class="help">First payroll it applies to. There is no end date: it continues while the employee works.<?= !empty($edit['end_date']) ? ' Saving removes the old end date ' . h(fmt_date($edit['end_date'])) . '.' : '' ?></span></div>
        <?php if ($edit): ?>
          <div class="field"><label>Status</label><select name="status"><?= options(['active' => 'Active', 'hold' => 'On hold (paused)', 'stopped' => 'Stopped'], $edit['status']) ?></select></div>
          <div class="field wide"><label>Employee</label><input value="<?= h(full_name(row('SELECT * FROM employees WHERE id = ?', [$edit['employee_id']]))) ?>" disabled></div>
        <?php else: ?>
          <div class="field wide"><label>Employees <span class="muted">(tick one, two or more for the ticked codes)</span></label>
            <div id="ftNote"><?php if ($filtered): ?>
              <div class="callout small emp-filter-note"><?= count($assignEmps) ?> employee(s) matching the filters above<?= !empty($filters['q']) ? ' "' . h((string)$filters['q']) . '"' : '' ?>. <a href="<?= h(url('fixed_trans', array_diff_key($keep, ['q' => 1, 'org_unit_id' => 1, 'branch_id' => 1, 'employee_group_id' => 1, 'employee_type_id' => 1]))) ?>">Show all employees</a></div>
            <?php endif; ?></div>
            <select name="employee_ids[]" id="ftEmps" multiple size="10" required>
              <?php foreach ($assignEmps as $e): ?>
                <option value="<?= (int)$e['id'] ?>"<?= (int)$e['id'] === $empPre ? ' selected' : '' ?>><?= h(full_name($e)) ?> — <?= h($e['employee_no']) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="help">An employee who already has one of the codes gets the new amount and terms. The filters stay after saving.</span>
          </div>
        <?php endif; ?>
        <div class="field wide"><label>Remarks</label><input name="remarks" value="<?= h($edit['remarks'] ?? '') ?>" maxlength="255"></div>
      </div>
      <div class="form-actions">
        <button class="btn btn-primary"><?= $edit ? 'Save changes' : 'Assign' ?></button>
        <?php if ($edit): ?><a class="btn" href="<?= h(url('fixed_trans', $keep)) ?>">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>
</div>
<?php page_footer();
