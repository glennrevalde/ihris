<?php
/**
 * Employee benefits: de minimis and company-specific benefits with eligibility rules, paid through payroll.
 * Each enrollment carries its manner of posting: every pay, or only the 1st, 2nd, 3rd or 4th pay of the
 * month; left blank it follows the frequency of the benefit type.
 * v17: Enroll employees takes one, two, three or more benefits at once, each with its amount and manner of posting.
 */
$postTerms = TERM_TRANS_TERMS;
$dimp = dimp_def('benefits', null, []);   // CSV import facility on this form (app/engine/dataimport.php)
dimp_handle($dimp);
if (is_post()) {
    $act = post('_action');
    if ($act === 'assign') {
        // v17: one, two, three or more benefits ticked, each with its amount (blank = default) and manner of posting
        $picked = code_pick_posted(['amount', 'post_term']);
        if (!$picked && post('benefit_type_id')) {
            $picked = [(string)(int)post('benefit_type_id') => ['amount' => (string)post('amount'), 'post_term' => (string)post('post_term', '')]];
        }
        $emps = array_map('intval', (array)post('employee_ids', []));
        if (!$picked || !$emps) {
            flash('error', !$picked ? 'Tick at least one benefit.' : 'Select at least one employee.');
            redirect('emp_benefits');
        }
        $msgs = [];
        foreach ($picked as $bid => $v) {
            $type = row('SELECT * FROM benefit_types WHERE id = ?', [(int)$bid]);
            if (!$type) {
                continue;
            }
            $n = 0;
            $skipped = 0;
            $amt = round((float)str_replace(',', '', (string)$v['amount']), 2);
            $pt = (string)$v['post_term'];
            foreach ($emps as $e) {
                $emp = row('SELECT * FROM employees WHERE id = ?', [$e]);
                if (!$emp || ($type['eligibility_regular_only'] && $emp['employment_status'] !== 'regular')
                    || ((int)$type['eligibility_min_months'] > 0 && months_of_service($emp['date_hired']) < (int)$type['eligibility_min_months'])) {
                    $skipped++;
                    continue;
                }
                insert('employee_benefits', ['employee_id' => $e, 'benefit_type_id' => $type['id'], 'amount' => $amt > 0 ? $amt : $type['default_amount'],
                    'start_date' => post('start_date') ?: today(), 'end_date' => post_null('end_date'), 'remarks' => post('remarks'),
                    'post_term' => isset($postTerms[$pt]) ? $pt : null]);
                $n++;
            }
            audit('payroll', 'benefit_assign', 'employee_benefits', null, ['type' => $type['code'], 'assigned' => $n, 'skipped' => $skipped]);
            $msgs[] = "$n in {$type['name']}" . ($skipped ? " ($skipped not eligible)" : '');
        }
        flash('success', 'Enrolled: ' . implode('; ', $msgs) . '.');
    } elseif ($act === 'post_term') {
        $pt = (string)post('post_term', '');
        $old = row('SELECT * FROM employee_benefits WHERE id = ?', [(int)post('id')]);
        if ($old) {
            update('employee_benefits', ['post_term' => isset($postTerms[$pt]) ? $pt : null], 'id = ?', [(int)$old['id']]);
            audit('payroll', 'benefit_post_term', 'employee_benefits', $old['id'], ['from' => $old['post_term'], 'to' => $pt ?: null]);
            flash('success', 'Manner of posting changed to ' . ($postTerms[$pt] ?? 'the frequency of the benefit type') . '.');
        }
    } elseif ($act === 'stop') {
        update('employee_benefits', ['status' => 'stopped', 'end_date' => today()], 'id = ?', [(int)post('id')]);
        audit('payroll', 'benefit_stop', 'employee_benefits', post('id'));
        flash('success', 'Benefit stopped.');
    } elseif ($act === 'bonus') {
        $emps = array_map('intval', (array)post('employee_ids', []));
        foreach ($emps as $e) {
            insert('pending_earnings', ['employee_id' => $e, 'source' => 'bonus', 'description' => post('description'), 'amount' => post_num('amount'),
                'tax_treatment' => post('tax_treatment', 'other_benefit'), 'created_by' => user_id()]);
        }
        audit('payroll', 'bonus_queue', 'pending_earnings', null, ['employees' => $emps, 'amount' => post('amount'), 'desc' => post('description')]);
        flash('success', count($emps) . ' one-time earning(s) queued for the next payroll run of each employee.');
    } elseif ($act === 'cancel_pe') {
        update('pending_earnings', ['status' => 'cancelled'], "id = ? AND status = 'pending'", [(int)post('id')]);
        flash('success', 'Pending earning cancelled.');
    }
    redirect('emp_benefits');
}
$list = rows("SELECT eb.*, bt.name AS type_name, bt.code AS type_code, bt.category, bt.tax_treatment, bt.frequency, e.first_name, e.last_name, e.middle_name, e.suffix, e.employee_no
              FROM employee_benefits eb JOIN benefit_types bt ON bt.id = eb.benefit_type_id JOIN employees e ON e.id = eb.employee_id
              WHERE eb.status = 'active'" . user_scope_sql('e') . ' ORDER BY bt.id, e.last_name');
$pe = rows("SELECT pe.*, e.first_name, e.last_name, e.middle_name, e.suffix FROM pending_earnings pe JOIN employees e ON e.id = pe.employee_id WHERE pe.status = 'pending'" . user_scope_sql('e') . ' ORDER BY pe.created_at DESC');
page_header('Employee Benefits', ['subtitle' => '13th month pay is computed automatically (13th-month runs and final pay). De minimis benefits are non-taxable up to BIR ceilings (RR 29-2025); company benefits follow their eligibility rules.',
    'actions' => '<button class="btn btn-primary" data-modal-open="benModal">+ Enroll employees</button> <button class="btn" data-modal-open="bonusModal">+ One-time earning / bonus</button>']);
echo dimp_panel($dimp);
$freqLabel = ['every_payroll' => 'Every pay', 'first_cutoff' => '1st pay of the month', 'second_cutoff' => '2nd pay of the month', 'monthly_last' => 'Last pay of the month'];
?>
<div class="card"><h2>Pending one-time earnings <span class="muted small">(included automatically in the next payroll run)</span></h2>
  <table class="table compact"><thead><tr><th>Employee</th><th>Source</th><th>Description</th><th>Tax treatment</th><th class="num">Amount</th><th></th></tr></thead><tbody>
  <?php if (!$pe) echo empty_row(6, 'None.'); foreach ($pe as $p): ?><tr><td><?= h(full_name($p)) ?></td><td><?= h(label($p['source'])) ?></td><td><?= h($p['description']) ?></td><td><?= h(label($p['tax_treatment'])) ?></td><td class="num"><?= money($p['amount']) ?></td>
    <td class="actions"><form method="post" class="inline" data-confirm="Cancel this pending earning?"><?= csrf_field() ?><input type="hidden" name="_action" value="cancel_pe"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn btn-sm btn-danger-outline">Cancel</button></form></td></tr><?php endforeach; ?>
  </tbody></table></div>
<div class="card"><div class="card-head"><h2>Active benefit enrollments</h2><input type="search" class="input table-search" data-target="benTbl" placeholder="Search..." style="max-width:220px"></div>
  <div class="table-wrap"><table class="table compact" id="benTbl"><thead><tr><th>Benefit</th><th>Employee</th><th>Tax treatment</th><th>Manner of posting</th><th class="num">Amount</th><th>Since</th><th></th></tr></thead><tbody>
  <?php if (!$list) echo empty_row(7); foreach ($list as $b): ?><tr><td><?= h($b['type_name']) ?></td><td><?= h(full_name($b)) ?><br><small class="muted"><?= h($b['employee_no']) ?></small></td><td><?= h(label($b['tax_treatment'])) ?></td>
    <td class="nowrap"><form method="post" class="retype"><?= csrf_field() ?><input type="hidden" name="_action" value="post_term"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
      <select name="post_term" class="input-sm" data-autosubmit aria-label="Manner of posting"><?= options($postTerms, $b['post_term'], 'Type default: ' . ($freqLabel[$b['frequency']] ?? label($b['frequency']))) ?></select></form></td>
    <td class="num"><?= money($b['amount']) ?></td><td class="small"><?= h(fmt_date($b['start_date'])) ?></td>
    <td class="actions"><form method="post" class="inline" data-confirm="Stop this benefit?"><?= csrf_field() ?><input type="hidden" name="_action" value="stop"><input type="hidden" name="id" value="<?= $b['id'] ?>"><button class="btn btn-sm">Stop</button></form></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
<div class="modal" id="benModal"><div class="modal-box lg"><h2>Enroll employees in a benefit</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="assign">
    <div class="form-grid">
      <?php $bgroups = [];
      foreach (rows("SELECT id, code, name, category, tax_treatment, default_amount FROM benefit_types WHERE is_active = 1 ORDER BY category, name") as $bt) {
          $bgroups[label($bt['category'])][] = ['key' => (int)$bt['id'], 'label' => $bt['name'], 'hint' => $bt['code'] . ', ' . label($bt['tax_treatment']) . ((float)$bt['default_amount'] > 0 ? ', default ' . money($bt['default_amount']) : ''),
              'values' => ['amount' => '', 'post_term' => '']];
      } ?>
      <div class="field wide"><label>Benefits <span class="muted">(tick one, two, three or more)</span></label>
        <?= code_pick_html('benCodes', $bgroups, ['amount' => ['label' => 'Amount per payment', 'type' => 'number', 'placeholder' => 'default'],
            'post_term' => ['label' => 'Manner of posting', 'type' => 'select', 'options' => $postTerms, 'placeholder' => 'As set on the type']], ['title' => 'Benefit', 'search' => true]) ?>
        <small class="help">Amount blank = the default amount of the benefit. Manner of posting: 1st, 2nd, 3rd or 4th pay of the month, or every pay.</small></div>
      <div class="field wide"><label>Employees</label><select name="employee_ids[]" multiple size="7" required><?= options(employee_options()) ?></select><small class="help">Ineligible employees (e.g. not regular, insufficient tenure) are skipped automatically.</small></div>
      <div class="field"><label>Start</label><input type="date" name="start_date" value="<?= h(today()) ?>"></div>
      <div class="field"><label>End (optional)</label><input type="date" name="end_date"></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary">Enroll</button><button type="button" class="btn" data-modal-close>Cancel</button></div></form></div></div>
<div class="modal" id="bonusModal"><div class="modal-box"><h2>One-time earning / bonus</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="bonus">
    <div class="form-grid">
      <div class="field wide"><label>Employees</label><select name="employee_ids[]" multiple size="7" required><?= options(employee_options()) ?></select></div>
      <div class="field wide"><label>Description</label><input name="description" required placeholder="e.g. Performance bonus Q3"></div>
      <div class="field"><label>Amount</label><input type="number" step="0.01" name="amount" required></div>
      <div class="field"><label>Tax treatment</label><select name="tax_treatment"><?= options(['other_benefit' => 'Other benefit (₱90K exemption)', 'taxable' => 'Taxable compensation', 'non_taxable' => 'Non-taxable']) ?></select></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary">Queue</button><button type="button" class="btn" data-modal-close>Cancel</button></div></form></div></div>
<?php page_footer();
