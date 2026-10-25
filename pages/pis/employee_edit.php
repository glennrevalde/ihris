<?php
/**
 * New / Edit Employee (the 201 form). Creation goes through employee_create() in
 * app/engine/employee.php, which the CSV import shares. "Save & New" keeps the user on a
 * blank form to encode the next employee.
 */
$id = (int)get('id', 0);
$emp = $id ? row('SELECT * FROM employees WHERE id = ?', [$id]) : null;
if ($id && !$emp) {
    throw new RuntimeException('Employee not found.');
}
$isNew = !$emp;
// Basic Salary rights (app/engine/scope.php): without View the rate is hidden, without Edit it is not changed
$seeSalary = salary_can('view');
$editSalary = salary_can('edit');

$text = EMPLOYEE_TEXT_FIELDS;
$bools = EMPLOYEE_BOOL_FIELDS;

if (is_post()) {
    $d = [];
    foreach ($text as $k) {
        $d[$k] = post_null($k);
    }
    foreach ($bools as $k) {
        $d[$k] = post($k) ? 1 : 0;
    }
    $d['basic_rate'] = $editSalary ? post_num('basic_rate') : ($emp ? (float)$emp['basic_rate'] : 0.0);
    $d['days_per_year'] = (int)post_num('days_per_year', 261) ?: 261;
    if ($d['employee_type_id']) {
        $et = emp_type_row((int)$d['employee_type_id']);
        if ($et) {
            $d['days_per_year'] = (int)round((float)$et['conversion_factor'] * 12);
        }
    }
    if ($d['job_id'] && !$d['position']) {
        $d['position'] = val('SELECT name FROM jobs WHERE id = ?', [$d['job_id']]);
    }
    $d['pagibig_voluntary'] = post_num('pagibig_voluntary');
    $d['day_off'] = day_off_parse((array)post('day_off', []))[0];   // optional default weekly day-off (none, one or more)
    $errors = employee_validate($d, $id);
    if (!scope_fits($d)) {   // the company / branch and department must stay inside the user's assignment
        $errors[] = 'The company / branch or the department is outside your assignment (System, User Access & Permissions).';
    }
    if ($editSalary && $d['salary_grade_id'] && $d['rate_type'] === 'monthly') {
        $g = row('SELECT * FROM salary_grades WHERE id = ?', [$d['salary_grade_id']]);
        if ($g && ($d['basic_rate'] < (float)$g['minimum'] || $d['basic_rate'] > (float)$g['maximum'])) {
            flash('warning', sprintf('Note: the monthly rate is outside the %s range (%s – %s).', $g['code'], money($g['minimum']), money($g['maximum'])));
        }
    }
    try {
        $photo = save_upload('photo', 'photos', ['jpg', 'jpeg', 'png', 'webp']);
        if ($photo) {
            $d['photo_path'] = $photo['path'];
        }
    } catch (RuntimeException $e) {
        $errors[] = $e->getMessage();
    }
    if ($errors) {
        foreach ($errors as $e) {
            flash('error', $e);
        }
        $_SESSION['emp_form'] = $_POST;
        redirect('employee_edit', $id ? ['id' => $id] : []);
    }
    $saveAndNew = post('_next') === 'new';
    if ($isNew) {
        $res = employee_create($d, ['create_account' => (bool)post('create_account'), 'temp_password' => post('temp_password'), 'via' => 'form']);
        employee_save_background((int)$res['id'], $_POST);
        if ($res['account']) {
            flash('info', sprintf('Portal account created: username "%s", temporary password "%s" (must be changed at first sign-in).', $res['account']['username'], $res['account']['password']));
        }
        flash('success', 'Employee record created: ' . $d['last_name'] . ', ' . $d['first_name'] . ' (' . $d['employee_no'] . ').');
        if ($saveAndNew) {
            // keep the defaults that usually repeat from one employee to the next
            $_SESSION['emp_form'] = array_intersect_key($_POST, array_flip(['default_branch_id', 'pay_group_id', 'org_unit_id', 'shift_id', 'day_off', 'employee_type_id',
                'employee_group_id', 'rate_type', 'employment_status', 'attendance_method', 'date_hired', 'exemption_code_id', 'location_id', 'class_group_id', 'rank_category_id', 'classification_id', 'create_account', 'temp_password']));
            redirect('employee_edit');
        }
        redirect('employee_view', ['id' => $res['id']]);
    }
    $eff = post('effective_date') ?: today();
    $res = tx(fn() => employee_apply_changes($id, $d, $eff, post_null('change_remarks')));
    if ((string)$emp['day_off'] !== (string)$d['day_off'] || (string)$emp['shift_id'] !== (string)$d['shift_id']) {
        // the rest days of the open daily time records follow the new day-off / shift
        $nRe = dtr_refresh_recent($id);
        if ($nRe) {
            flash('info', sprintf('%d daily time record(s) of open periods were re-computed with the new %s.', $nRe, implode(' and ', array_keys(array_filter(['default day-off' => (string)$emp['day_off'] !== (string)$d['day_off'], 'shift' => (string)$emp['shift_id'] !== (string)$d['shift_id']])))));
        }
    }
    $bgOld = employee_background($id);
    employee_save_background($id, $_POST);
    $strip = fn(array $b) => array_map(fn($l) => array_map(fn($r) => array_diff_key($r, ['id' => 1, 'sort_order' => 1]), $l), $b);
    if ($strip($bgOld) !== $strip(employee_background($id))) {
        audit('pis', 'employee_background_update', 'employees', $id, ['children' => count($_POST['children']['name'] ?? []), 'education' => count($_POST['educ']['school'] ?? []),
            'previous_employment' => count($_POST['prevemp']['company'] ?? [])]);
    }
    flash('success', 'Employee record updated.' . ($res['history_id'] ? ' The change was recorded in the employment history and will be reflected in the next payroll run.' : ''));
    if ($res['retro']) {
        flash('info', sprintf('Retroactive pay differential of %s computed for %d paid period(s); it will be included in the next payroll run.', peso($res['retro']['amount']), $res['retro']['periods']));
    }
    if ($saveAndNew) {
        redirect('employee_edit');
    }
    redirect('employee_view', ['id' => $id]);
}

$v = $emp ?: ['days_per_year' => setting('default_days_per_year', 261), 'rate_type' => 'monthly', 'employment_status' => 'probationary', 'attendance_method' => 'biometric',
    'gender' => '', 'civil_status' => 'single', 'date_hired' => today(), 'pay_group_id' => 1, 'default_branch_id' => 1, 'shift_id' => 1,
    'employee_no' => next_employee_no(), 'employee_type_id' => lookup_default('emp_types'), 'employee_group_id' => lookup_default('emp_groups'),
    'exemption_code_id' => val("SELECT id FROM exemption_codes WHERE code = 'Z'")];
$bg = $emp ? employee_background($id) : ['children' => [], 'education' => [], 'employment' => []];
if (!empty($_SESSION['emp_form'])) {
    $v = array_merge($v, $_SESSION['emp_form']);
    // lists typed before a validation error come back as they were
    $sf = $_SESSION['emp_form'];
    if (isset($sf['children']) || isset($sf['educ']) || isset($sf['prevemp'])) {
        $bg = ['children' => [], 'education' => [], 'employment' => []];
        foreach ((array)($sf['children']['name'] ?? []) as $i => $nm) {
            $bg['children'][] = ['name' => $nm, 'birth_date' => $sf['children']['birth_date'][$i] ?? ''];
        }
        foreach ((array)($sf['educ']['school'] ?? []) as $i => $sc) {
            $bg['education'][] = ['level' => $sf['educ']['level'][$i] ?? '', 'school' => $sc, 'course' => $sf['educ']['course'][$i] ?? '', 'year_graduated' => $sf['educ']['year'][$i] ?? ''];
        }
        foreach ((array)($sf['prevemp']['company'] ?? []) as $i => $co) {
            $bg['employment'][] = ['company' => $co, 'position' => $sf['prevemp']['position'][$i] ?? '', 'date_from' => $sf['prevemp']['date_from'][$i] ?? '',
                'date_to' => $sf['prevemp']['date_to'][$i] ?? '', 'reason' => $sf['prevemp']['reason'][$i] ?? ''];
        }
    }
    unset($_SESSION['emp_form']);
}
$kidRow = fn(array $r) => '<tr data-repeat-row><td><input name="children[name][]" value="' . h($r['name'] ?? '') . '" maxlength="150" placeholder="Full name" aria-label="Name of child"></td>'
    . '<td><input type="date" name="children[birth_date][]" value="' . h($r['birth_date'] ?? '') . '" aria-label="Birthday"></td>'
    . '<td class="rep-x"><button type="button" class="btn btn-xs btn-ghost" data-repeat-remove aria-label="Remove">&times;</button></td></tr>';
$eduRow = fn(array $r) => '<tr data-repeat-row><td><select name="educ[level][]" aria-label="Level">' . options(EDUCATION_LEVELS, $r['level'] ?? null, '—') . '</select></td>'
    . '<td><input name="educ[school][]" value="' . h($r['school'] ?? '') . '" maxlength="150" placeholder="Name of school" aria-label="Name of school"></td>'
    . '<td><input name="educ[course][]" value="' . h($r['course'] ?? '') . '" maxlength="150" placeholder="Course / degree" aria-label="Course"></td>'
    . '<td><input name="educ[year][]" value="' . h($r['year_graduated'] ?? '') . '" maxlength="10" placeholder="e.g. 2015" inputmode="numeric" aria-label="Year graduated"></td>'
    . '<td class="rep-x"><button type="button" class="btn btn-xs btn-ghost" data-repeat-remove aria-label="Remove">&times;</button></td></tr>';
$empRow = fn(array $r) => '<tr data-repeat-row><td><input name="prevemp[company][]" value="' . h($r['company'] ?? '') . '" maxlength="150" placeholder="Company" aria-label="Company"></td>'
    . '<td><input name="prevemp[position][]" value="' . h($r['position'] ?? '') . '" maxlength="100" placeholder="Position" aria-label="Position"></td>'
    . '<td><input type="date" name="prevemp[date_from][]" value="' . h($r['date_from'] ?? '') . '" aria-label="From"></td>'
    . '<td><input type="date" name="prevemp[date_to][]" value="' . h($r['date_to'] ?? '') . '" aria-label="To"></td>'
    . '<td><input name="prevemp[reason][]" value="' . h($r['reason'] ?? '') . '" maxlength="255" placeholder="Reason for leaving" aria-label="Reason for leaving"></td>'
    . '<td class="rep-x"><button type="button" class="btn btn-xs btn-ghost" data-repeat-remove aria-label="Remove">&times;</button></td></tr>';
$steps = [];
foreach (rows('SELECT * FROM salary_grades ORDER BY sort_order') as $g) {
    $steps[$g['id']] = ['min' => (float)$g['minimum'], 'max' => (float)$g['maximum'], 'steps' => pairs('SELECT step_no, amount FROM salary_grade_steps WHERE grade_id = ? ORDER BY step_no', [$g['id']])];
    if (!$seeSalary) {   // the step amounts would show the salary: step numbers only
        $steps[$g['id']] = ['min' => 0, 'max' => 0, 'hidden' => 1, 'steps' => array_fill_keys(array_keys($steps[$g['id']]['steps']), '')];
    }
}
$ouPg = pairs('SELECT id, default_pay_group_id FROM org_units WHERE default_pay_group_id IS NOT NULL');
$F = fn(string $name, array $f) => form_field($name, $f, $v[$name] ?? null);

page_header($isNew ? 'New Employee' : 'Edit Employee — ' . full_name($emp, false), ['back' => $isNew ? url('employees') : url('employee_view', ['id' => $id])]);
?>
<form method="post" enctype="multipart/form-data" class="card" data-steps="<?= h(json_encode($steps)) ?>" data-ou-paygroup="<?= h(json_encode($ouPg)) ?>">
  <?= csrf_field() ?>
  <div class="form-section">Personal information</div>
  <div class="form-grid">
    <?= $F('last_name', ['label' => 'Last name', 'required' => true]) ?>
    <?= $F('first_name', ['label' => 'First name', 'required' => true]) ?>
    <?= $F('middle_name', ['label' => 'Middle name']) ?>
    <?= $F('suffix', ['label' => 'Suffix (Jr., III)']) ?>
    <?= $F('gender', ['label' => 'Sex', 'type' => 'select', 'options' => ['male' => 'Male', 'female' => 'Female'], 'required' => true, 'placeholder' => '—']) ?>
    <?= $F('birth_date', ['label' => 'Birth date', 'type' => 'date']) ?>
    <?= $F('civil_status', ['label' => 'Civil status', 'type' => 'select', 'options' => ['single' => 'Single', 'married' => 'Married', 'widowed' => 'Widowed', 'separated' => 'Separated', 'annulled' => 'Annulled'], 'required' => true]) ?>
    <?= $F('contact_no', ['label' => 'Mobile / contact no.']) ?>
    <?= $F('email', ['label' => 'Email', 'type' => 'email']) ?>
    <?= $F('address', ['label' => 'Address', 'wide' => true]) ?>
    <?= $F('emergency_contact', ['label' => 'Emergency contact person']) ?>
    <?= $F('emergency_phone', ['label' => 'Emergency contact no.']) ?>
    <div class="field"><label>Photo</label><input type="file" name="photo" accept="image/*"><?php if (!empty($v['photo_path'])): ?><small class="help">A photo is on file; upload to replace.</small><?php endif; ?></div>
  </div>

  <div class="form-section">Physical and other personal data</div>
  <div class="form-grid">
    <?= $F('eye_color', ['label' => 'Color of eyes']) ?>
    <?= $F('religion', ['label' => 'Religion']) ?>
    <?= $F('weight', ['label' => 'Weight', 'help' => 'e.g. 65 kg']) ?>
    <?= $F('height', ['label' => 'Height', 'help' => 'e.g. 170 cm or 5 ft 7 in']) ?>
  </div>

  <div class="form-section">Family background</div>
  <div class="form-grid">
    <?= $F('spouse_name', ['label' => 'Name of spouse']) ?>
    <?= $F('spouse_contact', ['label' => 'Spouse contact no.']) ?>
    <?= $F('spouse_address', ['label' => 'Spouse address', 'wide' => true]) ?>
    <?= $F('father_name', ['label' => "Father's name"]) ?>
    <?= $F('mother_name', ['label' => "Mother's name (maiden)"]) ?>
  </div>
  <div class="rep-block">
    <div class="rep-head"><strong>Children</strong><button type="button" class="btn btn-sm" data-repeat-add="kidRows">+ Add child</button></div>
    <div class="table-wrap"><table class="table table-compact rep-table"><thead><tr><th>Name of child</th><th style="width:190px">Birthday</th><th class="rep-x"></th></tr></thead>
      <tbody id="kidRows"><?php foreach ($bg['children'] as $r) echo $kidRow($r); ?></tbody></table></div>
    <template id="kidRows-tpl"><?= $kidRow([]) ?></template>
  </div>

  <div class="form-section">Educational background</div>
  <div class="rep-block">
    <div class="rep-head"><span class="muted small">School, course and year graduated, from the highest level.</span><button type="button" class="btn btn-sm" data-repeat-add="eduRows">+ Add school</button></div>
    <div class="table-wrap"><table class="table table-compact rep-table"><thead><tr><th style="width:190px">Level</th><th>Name of school</th><th>Course</th><th style="width:120px">Year graduated</th><th class="rep-x"></th></tr></thead>
      <tbody id="eduRows"><?php foreach ($bg['education'] as $r) echo $eduRow($r); ?></tbody></table></div>
    <template id="eduRows-tpl"><?= $eduRow([]) ?></template>
  </div>

  <div class="form-section">Employment history (previous employers)</div>
  <div class="rep-block">
    <div class="rep-head"><span class="muted small">Work before joining the company. Changes inside the company are kept automatically in the employment history of the 201 file.</span><button type="button" class="btn btn-sm" data-repeat-add="empRows">+ Add employer</button></div>
    <div class="table-wrap"><table class="table table-compact rep-table"><thead><tr><th>Company</th><th>Position</th><th style="width:160px">From</th><th style="width:160px">To</th><th>Reason for leaving</th><th class="rep-x"></th></tr></thead>
      <tbody id="empRows"><?php foreach ($bg['employment'] as $r) echo $empRow($r); ?></tbody></table></div>
    <template id="empRows-tpl"><?= $empRow([]) ?></template>
  </div>

  <div class="form-section">Government identification numbers</div>
  <div class="form-grid">
    <?= $F('sss_no', ['label' => 'SSS No.']) ?>
    <?= $F('philhealth_no', ['label' => 'PhilHealth No. (PIN)']) ?>
    <?= $F('pagibig_no', ['label' => 'Pag-IBIG MID No.']) ?>
    <?= $F('tin', ['label' => 'TIN']) ?>
  </div>

  <div class="form-section">Employment information</div>
  <div class="form-grid">
    <?= $F('employee_no', ['label' => 'Employee No.', 'required' => true]) ?>
    <?= $F('biometric_id', ['label' => 'Biometric ID', 'help' => 'User ID enrolled in the biometric device.']) ?>
    <?= $F('date_hired', ['label' => 'Date hired', 'type' => 'date', 'required' => true]) ?>
    <?= $F('date_regularized', ['label' => 'Date regularized', 'type' => 'date']) ?>
    <?= $F('employment_status', ['label' => 'Employment status', 'type' => 'select', 'options' => EMPLOYMENT_STATUSES, 'required' => true,
        'help' => 'Resigned, Terminated and Retired: end the employment with Separate employee on the 201 file (date, final pay); the status is then set by itself.']) ?>
    <?= $F('position', ['label' => 'Position', 'required' => true]) ?>
    <?= $F('rank_level', ['label' => 'Rank / level', 'help' => 'e.g. Rank-and-file, Supervisory, Managerial']) ?>
    <?= $F('org_unit_id', ['label' => 'Organizational unit', 'type' => 'select', 'options' => org_unit_options()]) ?>
    <?= $F('supervisor_id', ['label' => 'Immediate supervisor', 'type' => 'select', 'options' => employee_options(true, $id ? 'id <> ' . $id : null)]) ?>
    <?= $F('default_branch_id', ['label' => 'Company / branch (employer)', 'type' => 'select', 'options' => branch_options(), 'required' => true, 'help' => 'Statutory deductions & remittances are logged here.']) ?>
    <?= $F('job_id', ['label' => 'Job', 'type' => 'select', 'options' => lookup_options('jobs'), 'help' => 'Job table; fills the position when blank.']) ?>
    <?php // an inactive group stays on the employee who already has it (it is no longer offered to the others)
    $grpOpts = lookup_options('emp_groups');
    if (!empty($v['employee_group_id']) && !isset($grpOpts[$v['employee_group_id']])) {
        $grpOpts[$v['employee_group_id']] = (string)val("SELECT CONCAT(code, ' — ', name, ' (inactive)') FROM emp_groups WHERE id = ?", [$v['employee_group_id']]);
    } ?>
    <?= $F('employee_group_id', ['label' => 'Employee group', 'type' => 'select', 'options' => $grpOpts]) ?>
    <?= $F('class_group_id', ['label' => 'Class group', 'type' => 'select', 'options' => lookup_options('class_groups')]) ?>
    <?= $F('rank_category_id', ['label' => 'Rank category', 'type' => 'select', 'options' => lookup_options('rank_categories')]) ?>
    <?= $F('classification_id', ['label' => 'Classification', 'type' => 'select', 'options' => lookup_options('classifications')]) ?>
    <?= $F('location_id', ['label' => 'Location', 'type' => 'select', 'options' => lookup_options('locations')]) ?>
  </div>

  <div class="form-section">Compensation</div>
  <div class="form-grid">
    <?= $F('salary_grade_id', ['label' => 'Salary grade', 'type' => 'select', 'options' => pairs("SELECT id, CONCAT(code, ' — ', position_band) FROM salary_grades ORDER BY sort_order")]) ?>
    <div class="field"><label>Step</label><select name="salary_step" data-current="<?= h($v['salary_step'] ?? '') ?>"><option value="">—</option></select></div>
    <?= $F('rate_type', ['label' => 'Rate type', 'type' => 'select', 'options' => ['monthly' => 'Monthly-rated', 'daily' => 'Daily-rated'], 'required' => true]) ?>
    <?php if ($editSalary): ?>
      <?= $F('basic_rate', ['label' => 'Basic rate (₱ per month / day)', 'type' => 'money', 'required' => true]) ?>
    <?php elseif ($seeSalary): ?>
      <?= $F('basic_rate', ['label' => 'Basic rate (₱ per month / day)', 'type' => 'money', 'readonly' => true, 'help' => 'Read only: you have no Basic Salary edit right.']) ?>
    <?php else: ?>
      <div class="field"><label>Basic rate (₱ per month / day)</label><div class="input-static muted">Hidden</div><small class="help">You have no Basic Salary view right. <?= $isNew ? 'The rate is set by a user with the Basic Salary edit right.' : 'The rate on file is kept when you save.' ?></small></div>
    <?php endif; ?>
    <?= $F('employee_type_id', ['label' => 'Employee type', 'type' => 'select', 'options' => pairs("SELECT id, CONCAT(code, ' — ', name, ' (factor ', ROUND(conversion_factor, 2), ')') FROM emp_types WHERE is_active = 1 ORDER BY code"), 'help' => 'Conversion factor: daily rate = monthly rate ÷ factor.']) ?>
    <?= $F('days_per_year', ['label' => 'Days-per-year factor', 'type' => 'number', 'help' => 'Used only when no employee type is set (261, 313 or 365).']) ?>
    <?= $F('pay_group_id', ['label' => 'Pay group / frequency', 'type' => 'select', 'options' => pay_group_options(), 'required' => true, 'help' => 'Determines the BIR table (daily/weekly/semi-monthly/monthly).']) ?>
    <?= $F('pagibig_voluntary', ['label' => 'Voluntary Pag-IBIG (₱/month)', 'type' => 'money']) ?>
    <?= $F('bank_name', ['label' => 'Payroll bank']) ?>
    <?= $F('bank_account_no', ['label' => 'Bank account no.']) ?>
    <?= $F('bank_account_type', ['label' => 'Account type', 'type' => 'select', 'options' => EMP_ACCOUNT_TYPES, 'help' => 'Cash: the net pay is listed in the Cash Payment Listing and left out of the bank file and the Bank Deposit Listing.']) ?>
    <?= $F('exemption_code_id', ['label' => 'Exemption code', 'type' => 'select', 'options' => lookup_options('exemption_codes'), 'help' => 'Tax-exempt codes (e.g. MWE) are not withheld.']) ?>
    <?= $F('is_mwe', ['label' => 'Minimum wage earner (tax-exempt)', 'type' => 'checkbox']) ?>
    <div class="field wide"><small id="gradeHint" class="help"></small></div>
  </div>

  <div class="form-section">Attendance</div>
  <div class="form-grid">
    <?= $F('shift_id', ['label' => 'Shift schedule', 'type' => 'select', 'options' => shift_options()]) ?>
    <?= $F('attendance_method', ['label' => 'Time capture method', 'type' => 'select', 'options' => ['biometric' => 'Biometric device', 'web' => 'Web / mobile (GPS + selfie)', 'both' => 'Biometric and web', 'import' => 'Imported file (Excel/CSV)']]) ?>
    <?= $F('timekeeping_exempt', ['label' => 'Exempt from time-keeping (managerial)', 'type' => 'checkbox']) ?>
    <?= $F('day_off', ['label' => 'Default day-off (weekly)', 'type' => 'multicheck', 'options' => WEEKDAY_NAMES, 'wide' => true,
        'help' => 'Optional: none, one, two or more days. On a 7-day shift (whole-week duty or a shift without rest days) these days are marked as day-off; a Schedule Override of a date (Employee Shift Entry) replaces it. Shifts with their own rest days keep them.']) ?>
  </div>

  <div class="form-section">Solo parent</div>
  <div class="form-grid">
    <?= $F('is_solo_parent', ['label' => 'Qualified solo parent (RA 8972 / RA 11861)', 'type' => 'checkbox']) ?>
    <?= $F('solo_parent_id_no', ['label' => 'Solo Parent ID No.']) ?>
  </div>

  <?php if ($isNew): ?>
    <div class="form-section">Self-service portal</div>
    <div class="form-grid">
      <div class="field"><label class="check"><input type="checkbox" name="create_account" value="1" checked> Create employee portal account</label><small class="help">Username = employee no. (lower-case)</small></div>
      <div class="field"><label>Temporary password</label><input name="temp_password" value="Welcome<?= date('Y') ?>"></div>
    </div>
  <?php else: ?>
    <div class="form-section">Record of change</div>
    <div class="form-grid">
      <div class="field"><label>Effective date of change</label><input type="date" name="effective_date" value="<?= h(today()) ?>"><small class="help">A past date on a salary change triggers a retroactive pay adjustment.</small></div>
      <div class="field wide"><label>Remarks (e.g. Board resolution, promotion memo no.)</label><input name="change_remarks"></div>
    </div>
  <?php endif; ?>
  <div class="form-actions">
    <button class="btn btn-primary btn-lg" name="_next" value="view"><?= $isNew ? 'Create employee' : 'Save changes' ?></button>
    <button class="btn btn-lg" name="_next" value="new" title="Save this record and open a blank form for the next employee">Save &amp; New</button>
    <a class="btn btn-lg btn-ghost" href="<?= $isNew ? url('employees') : url('employee_view', ['id' => $id]) ?>">Cancel</a>
  </div>
</form>
<?php page_footer();
