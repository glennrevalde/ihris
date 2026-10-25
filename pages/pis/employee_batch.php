<?php
/**
 * Employee Masterfile Batch Edit (v17; v18: every field of the 201 file, grouped column chooser with search): the employees of a Company, Branch, Department and Employee Group
 * on one grid, one employee per row and the information of the 201 file as columns. Only the cells that were
 * changed are sent and saved; every change goes through the same checks and the same employment history as
 * the employee form (promotions, transfers, salary changes, status changes are recorded).
 */

/**
 * Columns of the grid (every field of the 201 file that can be typed in a cell; the photo, the separation and the
 * account are kept on the employee form): field => [label, type, options (select), width class, group].
 * Types: text, date, number (2 decimals), int, select, yesno.
 */
function emp_batch_columns(): array
{
    static $c = null;
    if ($c !== null) {
        return $c;
    }
    $yes = ['0' => 'No', '1' => 'Yes'];
    $c = [
        // personal information
        'last_name' => ['Last name', 'text', null, 'w-md', 'Personal'],
        'first_name' => ['First name', 'text', null, 'w-md', 'Personal'],
        'middle_name' => ['Middle name', 'text', null, 'w-md', 'Personal'],
        'suffix' => ['Suffix', 'text', null, 'w-xs', 'Personal'],
        'gender' => ['Sex', 'select', ['male' => 'Male', 'female' => 'Female'], 'w-sm', 'Personal'],
        'birth_date' => ['Birth date', 'date', null, 'w-md', 'Personal'],
        'civil_status' => ['Civil status', 'select', ['single' => 'Single', 'married' => 'Married', 'widowed' => 'Widowed', 'separated' => 'Separated', 'annulled' => 'Annulled'], 'w-sm', 'Personal'],
        'eye_color' => ['Color of eyes', 'text', null, 'w-sm', 'Personal'],
        'religion' => ['Religion', 'text', null, 'w-md', 'Personal'],
        'weight' => ['Weight', 'text', null, 'w-xs', 'Personal'],
        'height' => ['Height', 'text', null, 'w-xs', 'Personal'],
        // contact
        'contact_no' => ['Contact no.', 'text', null, 'w-md', 'Contact & family'],
        'email' => ['Email', 'text', null, 'w-lg', 'Contact & family'],
        'address' => ['Address', 'text', null, 'w-xl', 'Contact & family'],
        'emergency_contact' => ['Emergency contact person', 'text', null, 'w-lg', 'Contact & family'],
        'emergency_phone' => ['Emergency contact no.', 'text', null, 'w-md', 'Contact & family'],
        'spouse_name' => ['Name of spouse', 'text', null, 'w-lg', 'Contact & family'],
        'spouse_contact' => ['Spouse contact no.', 'text', null, 'w-md', 'Contact & family'],
        'spouse_address' => ['Spouse address', 'text', null, 'w-xl', 'Contact & family'],
        'father_name' => ["Father's name", 'text', null, 'w-lg', 'Contact & family'],
        'mother_name' => ["Mother's maiden name", 'text', null, 'w-lg', 'Contact & family'],
        // government numbers
        'sss_no' => ['SSS No.', 'text', null, 'w-md', 'Government numbers'],
        'philhealth_no' => ['PhilHealth No.', 'text', null, 'w-md', 'Government numbers'],
        'pagibig_no' => ['Pag-IBIG No.', 'text', null, 'w-md', 'Government numbers'],
        'tin' => ['TIN', 'text', null, 'w-md', 'Government numbers'],
        // employment
        'employee_no' => ['Employee No.', 'text', null, 'w-md', 'Employment'],
        'biometric_id' => ['Biometric ID', 'text', null, 'w-sm', 'Employment'],
        'date_hired' => ['Date hired', 'date', null, 'w-md', 'Employment'],
        'date_regularized' => ['Date regularized', 'date', null, 'w-md', 'Employment'],
        'employment_status' => ['Employment status', 'select', EMPLOYMENT_STATUSES, 'w-md', 'Employment'],
        'position' => ['Position', 'text', null, 'w-lg', 'Employment'],
        'rank_level' => ['Rank / level', 'text', null, 'w-md', 'Employment'],
        'job_id' => ['Job', 'select', lookup_options('jobs'), 'w-md', 'Employment'],
        'supervisor_id' => ['Immediate supervisor', 'select', employee_options(true), 'w-lg', 'Employment'],
        // organization
        'default_branch_id' => ['Company / branch', 'select', branch_options(), 'w-lg', 'Organization'],
        'org_unit_id' => ['Department', 'select', org_unit_options(), 'w-lg', 'Organization'],
        'employee_group_id' => ['Employee group', 'select', lookup_options('emp_groups'), 'w-md', 'Organization'],
        'employee_type_id' => ['Employee type', 'select', lookup_options('emp_types'), 'w-md', 'Organization'],
        'class_group_id' => ['Class group', 'select', lookup_options('class_groups'), 'w-md', 'Organization'],
        'rank_category_id' => ['Rank category', 'select', lookup_options('rank_categories'), 'w-md', 'Organization'],
        'classification_id' => ['Classification', 'select', lookup_options('classifications'), 'w-md', 'Organization'],
        'location_id' => ['Location', 'select', lookup_options('locations'), 'w-md', 'Organization'],
        // pay and tax
        'pay_group_id' => ['Pay group', 'select', pay_group_options(), 'w-md', 'Pay & tax'],
        'rate_type' => ['Rate type', 'select', ['monthly' => 'Monthly-rated', 'daily' => 'Daily-rated'], 'w-sm', 'Pay & tax'],
        'basic_rate' => ['Basic rate', 'number', null, 'w-sm', 'Pay & tax'],
        'salary_grade_id' => ['Salary grade', 'select', pairs("SELECT id, CONCAT(code, ' — ', position_band) FROM salary_grades ORDER BY sort_order"), 'w-md', 'Pay & tax'],
        'salary_step' => ['Salary step', 'int', null, 'w-xs', 'Pay & tax'],
        'days_per_year' => ['Days-per-year factor', 'int', null, 'w-xs', 'Pay & tax'],
        'pagibig_voluntary' => ['Voluntary Pag-IBIG', 'number', null, 'w-sm', 'Pay & tax'],
        'exemption_code_id' => ['Exemption code', 'select', lookup_options('exemption_codes'), 'w-sm', 'Pay & tax'],
        'is_mwe' => ['Minimum wage earner', 'yesno', $yes, 'w-xs', 'Pay & tax'],
        'is_solo_parent' => ['Solo parent', 'yesno', $yes, 'w-xs', 'Pay & tax'],
        'solo_parent_id_no' => ['Solo Parent ID No.', 'text', null, 'w-md', 'Pay & tax'],
        'bank_name' => ['Payroll bank', 'text', null, 'w-md', 'Pay & tax'],
        'bank_account_no' => ['Bank account no.', 'text', null, 'w-md', 'Pay & tax'],
        'bank_account_type' => ['Account type', 'select', EMP_ACCOUNT_TYPES, 'w-sm', 'Pay & tax'],
        // attendance
        'shift_id' => ['Shift', 'select', shift_options(), 'w-xl', 'Attendance'],
        'day_off' => ['Default day-off', 'text', null, 'w-sm', 'Attendance'],
        'attendance_method' => ['Time capture', 'select', ['biometric' => 'Biometric', 'web' => 'Web', 'both' => 'Both', 'import' => 'Import'], 'w-sm', 'Attendance'],
        'timekeeping_exempt' => ['Exempt from time-keeping', 'yesno', $yes, 'w-xs', 'Attendance'],
    ];
    $have = array_flip(array_column(rows('SHOW COLUMNS FROM employees'), 'Field'));
    foreach (array_keys($c) as $k) {
        if (!isset($have[$k])) {
            unset($c[$k]);   // a column of an older database version
        }
    }
    if (!salary_can('view')) {
        unset($c['basic_rate'], $c['salary_grade_id'], $c['salary_step']);   // no Basic Salary view right: not offered
    }
    return $c;
}

/** Salary columns: changed only with the Basic Salary edit right. */
const EMP_BATCH_SALARY = ['basic_rate', 'salary_grade_id', 'salary_step'];
const EMP_BATCH_DEFAULT = ['position', 'employment_status', 'org_unit_id', 'default_branch_id', 'employee_group_id', 'employee_type_id', 'pay_group_id', 'shift_id', 'date_regularized'];
const EMP_BATCH_REQUIRED = ['employee_no', 'last_name', 'first_name', 'gender', 'civil_status', 'position', 'date_hired', 'pay_group_id', 'default_branch_id', 'employment_status', 'rate_type',
    'is_mwe', 'is_solo_parent', 'timekeeping_exempt', 'days_per_year'];

$cols = emp_batch_columns();
$f = [
    'company' => trim((string)get('company', '')), 'branch_id' => (int)get('branch_id', 0) ?: null, 'org_unit_id' => (int)get('org_unit_id', 0) ?: null,
    'employee_group_id' => (int)get('employee_group_id', 0) ?: null, 'status' => get('status', 'active') === 'all' ? 'all' : (get('status', 'active') === 'separated' ? 'separated' : 'active'),
    'q' => trim((string)get('q', '')),
];
$pick = array_values(array_intersect((array)get('cols', []), array_keys($cols))) ?: array_values(array_intersect(EMP_BATCH_DEFAULT, array_keys($cols)));
$keep = array_filter(['p' => 'employee_batch'] + $f + ['cols' => $pick], fn($v) => $v !== null && $v !== '' && $v !== []);

if (is_post()) {
    verify_csrf();
    $posted = (array)($_POST['e'] ?? []);
    $saved = 0;
    $failed = [];
    $canSalary = salary_can('edit');
    $refresh = [];
    foreach ($posted as $eid => $vals) {
        $eid = (int)$eid;
        if ($eid <= 0 || !is_array($vals) || !scope_allows($eid)) {
            continue;
        }
        $emp = row('SELECT * FROM employees WHERE id = ?', [$eid]);
        if (!$emp) {
            continue;
        }
        $chg = [];
        foreach ($vals as $k => $v) {
            if (!isset($cols[$k]) || !is_scalar($v)) {
                continue;
            }
            if (in_array($k, EMP_BATCH_SALARY, true) && !$canSalary) {
                continue;
            }
            $v = trim((string)$v);
            if ($k === 'day_off') {
                [$v, $bad] = day_off_parse($v);
                if ($bad) {
                    $failed[] = full_name($emp) . ': unknown day-off "' . implode(', ', $bad) . '".';
                    continue 2;
                }
            } elseif ($cols[$k][1] === 'yesno') {
                $v = $v === '1' ? 1 : 0;
            } elseif ($cols[$k][1] === 'number') {
                $v = $v === '' && $k !== 'basic_rate' ? null : round((float)str_replace(',', '', $v), 2);
            } elseif ($cols[$k][1] === 'int') {
                $v = $v === '' && $k !== 'days_per_year' ? null : (int)$v;
            } elseif ($v === '' && !in_array($k, EMP_BATCH_REQUIRED, true)) {
                $v = null;
            } elseif (in_array($k, ['employee_no', 'sss_no', 'philhealth_no', 'pagibig_no', 'tin'], true)) {
                $v = strtoupper($v);
            }
            if ((string)$emp[$k] !== (string)$v) {
                $chg[$k] = $v;
            }
        }
        if (!$chg) {
            continue;
        }
        if (array_key_exists('employee_type_id', $chg) && $chg['employee_type_id'] && !array_key_exists('days_per_year', $chg)) {
            $et = emp_type_row((int)$chg['employee_type_id']);   // same as the employee form: the factor follows the employee type
            if ($et && (int)round((float)$et['conversion_factor'] * 12) !== (int)$emp['days_per_year']) {
                $chg['days_per_year'] = (int)round((float)$et['conversion_factor'] * 12);
            }
        }
        $merged = array_merge($emp, $chg);
        $errors = employee_validate($merged, $eid);
        if (!scope_fits($merged)) {
            $errors[] = 'The company / branch or the department is outside your assignment.';
        }
        if (array_key_exists('days_per_year', $chg) && ((int)$chg['days_per_year'] < 1 || (int)$chg['days_per_year'] > 366)) {
            $errors[] = 'Days-per-year factor must be 1 to 366 (e.g. 261, 313 or 365).';
        }
        if (array_key_exists('salary_step', $chg) && $chg['salary_step'] !== null && ((int)$chg['salary_step'] < 1 || (int)$chg['salary_step'] > 30)) {
            $errors[] = 'Salary step must be 1 to 30.';
        }
        foreach (['employment_status', 'rate_type'] as $k) {   // the others are checked by employee_validate()
            if (array_key_exists($k, $chg) && ($chg[$k] === null || $chg[$k] === '')) {
                $errors[] = label(str_replace('_id', '', $k)) . ' cannot be blank.';
            }
        }
        if ($errors) {
            $failed[] = full_name($emp) . ' (' . $emp['employee_no'] . '): ' . implode(' ', array_unique($errors));
            continue;
        }
        tx(fn() => employee_apply_changes($eid, $chg, today(), 'Batch edit of the masterfile'));
        if (array_key_exists('shift_id', $chg) || array_key_exists('day_off', $chg)) {
            $refresh[] = $eid;
        }
        audit('pis', 'employee_batch_edit', 'employees', $eid, diff_fields($emp, $chg));
        $saved++;
    }
    foreach ($refresh as $eid) {
        dtr_refresh_recent($eid);
    }
    if ($saved) {
        flash('success', $saved . ' employee(s) updated. The changes are in their employment history and the next payroll run.');
    }
    foreach (array_slice($failed, 0, 20) as $m) {
        flash('error', 'Not saved: ' . $m);
    }
    if (!$saved && !$failed) {
        flash('warning', 'Nothing was changed.');
    }
    $q = $keep;
    unset($q['p']);
    redirect('employee_batch', $q);
}

// ---------------------------------------------------------------- the employees of the filters
$where = ['1=1'];
$params = [];
if ($f['status'] !== 'all') {
    $where[] = 'e.status = ?';
    $params[] = $f['status'];
}
if ($f['company'] !== '') {
    $where[] = "e.default_branch_id IN (SELECT id FROM branches WHERE COALESCE(NULLIF(company_code, ''), company_name, name) = ?)";
    $params[] = $f['company'];
}
if ($f['branch_id']) {
    $where[] = 'e.default_branch_id = ?';
    $params[] = $f['branch_id'];
}
if ($f['org_unit_id']) {
    $where[] = 'e.org_unit_id IN (' . implode(',', array_map('intval', org_descendant_ids($f['org_unit_id']))) . ')';
}
if ($f['employee_group_id']) {
    $where[] = 'e.employee_group_id = ?';
    $params[] = $f['employee_group_id'];
}
if ($f['q'] !== '') {
    $where[] = search_words_sql(['e.employee_no', 'e.last_name', 'e.first_name', 'e.position', 'e.biometric_id'], $f['q'], $params);
}
$list = rows('SELECT e.* FROM employees e WHERE ' . implode(' AND ', $where) . user_scope_sql('e') . ' ORDER BY e.last_name, e.first_name LIMIT 500', $params);
$companies = pairs("SELECT DISTINCT COALESCE(NULLIF(company_code, ''), company_name, name) AS k, COALESCE(NULLIF(company_name, ''), name) FROM branches WHERE is_active = 1 ORDER BY 2");
$canEdit = can('employee_batch.edit');
$canSalary = salary_can('edit');

page_header('Masterfile Batch Edit', [
    'subtitle' => 'Change the 201 file of many employees at once: one employee per row, the information as columns. Only the changed cells are saved, with the same checks and employment history as the employee form.',
    'actions' => '<a class="btn" href="' . url('employees') . '">Employee Masterfile</a>',
]);
?>
<form method="get" class="card filters" id="ebFilters">
  <input type="hidden" name="p" value="employee_batch">
  <label>Company<select name="company"><?= options($companies, $f['company'] !== '' ? $f['company'] : null, 'All companies') ?></select></label>
  <label>Branch<select name="branch_id"><?= options(pairs("SELECT id, CONCAT(COALESCE(branch_code, code), ' — ', name) FROM branches WHERE is_active = 1 ORDER BY company_code, branch_code, code"), $f['branch_id'], 'All branches') ?></select></label>
  <label>Department<select name="org_unit_id"><?= options(org_unit_options(), $f['org_unit_id'], 'All departments') ?></select></label>
  <label>Employee group<select name="employee_group_id"><?= options(emp_group_options(), $f['employee_group_id'], 'All groups') ?></select></label>
  <label>Status<select name="status"><?= options(['active' => 'Active', 'separated' => 'Separated', 'all' => 'All'], $f['status']) ?></select></label>
  <label class="grow">Find<input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="Name, employee no., position" autocomplete="off"></label>
  <details class="eb-cols" id="ebCols">
    <summary>Columns <span class="eb-cols-n"><?= count($pick) ?></span> of <?= count($cols) ?></summary>
    <div class="eb-cols-box" role="group" aria-label="Columns to show">
      <div class="eb-cols-head">
        <input type="search" class="eb-cols-find" placeholder="Find a field" aria-label="Find a field" autocomplete="off">
        <div class="eb-cols-acts"><button type="button" class="pl-btn" data-ebc="all">Select all</button><button type="button" class="pl-btn" data-ebc="default">Default</button><button type="button" class="pl-btn" data-ebc="none">Clear</button></div>
      </div>
      <div class="eb-cols-list">
        <?php $grp = null; foreach ($cols as $k => $c):
            if ($c[4] !== $grp): $grp = $c[4]; ?>
          <div class="eb-cols-grp"><?= h($grp) ?></div>
        <?php endif; ?>
          <span class="eb-opt" data-search="<?= h(mb_strtolower($c[0] . ' ' . $grp)) ?>"><input type="checkbox" id="ebc-<?= h($k) ?>" name="cols[]" value="<?= h($k) ?>"<?= checked(in_array($k, $pick, true)) ?><?= in_array($k, EMP_BATCH_DEFAULT, true) ? ' data-default="1"' : '' ?>><label for="ebc-<?= h($k) ?>"><?= h($c[0]) ?></label></span>
        <?php endforeach; ?>
      </div>
      <div class="eb-cols-foot"><span class="muted small">The ticked fields become the columns of the grid, in this order.</span><button class="btn btn-primary btn-sm">Show</button></div>
    </div>
  </details>
  <button class="btn btn-primary">Show</button>
</form>

<form method="post" class="eb-form" id="ebForm" data-eb>
  <?= csrf_field() ?>
  <div class="card flush">
    <div class="card-head">
      <div><h2><?= count($list) ?> employee(s)</h2><div class="sub"><?= count($list) >= 500 ? 'The first 500 are shown: narrow the filters to see the others. ' : '' ?>Changed cells are highlighted; Save sends only those.</div></div>
      <?php if ($canEdit && $list): ?><div class="eb-tools"><span class="eb-count muted small" aria-live="polite">No change yet</span><button type="button" class="btn btn-sm" data-eb-undo>Undo changes</button><button class="btn btn-primary btn-sm">Save changes</button></div><?php endif; ?>
    </div>
    <div class="table-wrap table-scroll eb-wrap">
      <table class="table table-compact eb-grid">
        <thead><tr><th class="eb-sticky">Employee</th><?php foreach ($pick as $k): ?><th><?= h($cols[$k][0]) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php if (!$list) echo empty_row(count($pick) + 1, scope_def()['none'] ? 'No company / branch or department is assigned to your account.' : 'No employee for these filters.'); ?>
        <?php foreach ($list as $e): ?>
          <tr>
            <td class="eb-sticky"><a href="<?= url('employee_view', ['id' => $e['id']]) ?>"><strong><?= h(full_name($e)) ?></strong></a><br><small class="muted"><?= h($e['employee_no']) ?></small></td>
            <?php foreach ($pick as $k):
                [$lbl, $type, $opts, $w] = $cols[$k];
                $name = 'e[' . (int)$e['id'] . '][' . $k . ']';
                $val = (string)($e[$k] ?? '');
                $ro = !$canEdit || (in_array($k, EMP_BATCH_SALARY, true) && !$canSalary);
                if ($type === 'yesno') {
                    $val = $val === '1' ? '1' : '0';
                }
                if ($k === 'day_off') {
                    $val = day_off_label($e['day_off'], true);
                } ?>
              <td>
                <?php if ($type === 'select' || $type === 'yesno'): ?>
                  <select class="eb-cell <?= h($w) ?>" name="<?= h($name) ?>" data-orig="<?= h($val) ?>" aria-label="<?= h($lbl) ?>"<?= $ro ? ' disabled data-ro="1"' : '' ?>><?= options($opts + ($val !== '' && !isset($opts[$val]) ? [$val => '#' . $val] : []), $val, in_array($k, EMP_BATCH_REQUIRED, true) ? null : '—') ?></select>
                <?php else: ?>
                  <input class="eb-cell <?= h($w) ?>" type="<?= $type === 'date' ? 'date' : ($type === 'number' || $type === 'int' ? 'number' : 'text') ?>"<?= $type === 'number' ? ' step="0.01" min="0"' : ($type === 'int' ? ' step="1" min="0"' : '') ?> name="<?= h($name) ?>" value="<?= h($val) ?>" data-orig="<?= h($val) ?>" aria-label="<?= h($lbl) ?>"<?= $ro ? ' readonly data-ro="1"' : '' ?>>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php if ($canEdit && $list): ?>
  <div class="toolbar sticky-bottom eb-bar"><span class="eb-count muted small">No change yet</span><span class="spacer"></span><button type="button" class="btn" data-eb-undo>Undo changes</button><button class="btn btn-primary">Save changes</button></div>
  <?php endif; ?>
</form>
<script>
(function () {
  // column chooser: find, select all / default / clear, count; at least one column
  var box = document.getElementById('ebCols');
  if (box) {
    var opts = Array.prototype.slice.call(box.querySelectorAll('.eb-opt'));
    var boxes = opts.map(function (o) { return o.querySelector('input'); });
    var n = box.querySelector('.eb-cols-n');
    var count = function () { n.textContent = boxes.filter(function (b) { return b.checked; }).length; };
    box.addEventListener('change', count);
    box.querySelector('.eb-cols-find').addEventListener('input', function () {
      var q = this.value.toLowerCase().trim();
      opts.forEach(function (o) { o.hidden = q !== '' && o.getAttribute('data-search').indexOf(q) < 0; });
      Array.prototype.forEach.call(box.querySelectorAll('.eb-cols-grp'), function (g) {
        var x = g.nextElementSibling, any = false;
        while (x && !x.classList.contains('eb-cols-grp')) { if (!x.hidden) any = true; x = x.nextElementSibling; }
        g.hidden = !any;
      });
    });
    box.addEventListener('click', function (e) {
      var b = e.target.closest('[data-ebc]');
      if (!b) return;
      var m = b.getAttribute('data-ebc');
      boxes.forEach(function (c) {
        if (c.closest('.eb-opt').hidden && m !== 'none') return;   // Select all = the fields shown by the search
        c.checked = m === 'all' ? true : (m === 'default' ? c.hasAttribute('data-default') : false);
      });
      count();
    });
    document.getElementById('ebFilters').addEventListener('submit', function (e) {
      if (!boxes.some(function (b) { return b.checked; })) { boxes.forEach(function (c) { c.checked = c.hasAttribute('data-default'); }); }
    });
    document.addEventListener('click', function (e) { if (box.open && !box.contains(e.target)) box.open = false; });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && box.open) box.open = false; });
  }
  var form = document.getElementById('ebForm');
  if (!form) return;
  var cells = Array.prototype.slice.call(form.querySelectorAll('.eb-cell'));
  var counters = Array.prototype.slice.call(form.querySelectorAll('.eb-count'));
  var mark = function () {
    var n = 0, rows = {};
    cells.forEach(function (c) {
      var ch = c.value !== c.getAttribute('data-orig');
      c.classList.toggle('is-changed', ch);
      var tr = c.closest('tr');
      if (ch) { n++; rows[c.name.split(']')[0]] = 1; }
      tr.classList.toggle('is-dirty', !!tr.querySelector('.is-changed'));
    });
    var r = Object.keys(rows).length;
    counters.forEach(function (o) { o.textContent = n ? n + ' change(s) on ' + r + ' employee(s)' : 'No change yet'; });
    form.setAttribute('data-dirty', n ? '1' : '');
  };
  form.addEventListener('input', mark);
  form.addEventListener('change', mark);
  form.addEventListener('click', function (e) {
    if (!e.target.closest('[data-eb-undo]')) return;
    cells.forEach(function (c) { c.value = c.getAttribute('data-orig'); });
    mark();
  });
  form.addEventListener('submit', function (e) {
    var changed = cells.filter(function (c) { return c.classList.contains('is-changed'); });
    if (!changed.length) { e.preventDefault(); alert('Nothing was changed.'); return; }
    // only the changed cells are sent (a large grid stays under the limits of the server)
    cells.forEach(function (c) { if (!c.classList.contains('is-changed')) c.disabled = true; });
    setTimeout(function () { cells.forEach(function (c) { if (!c.hasAttribute('data-ro')) c.disabled = false; }); }, 4000);
  });
})();
</script>
<?php page_footer();
