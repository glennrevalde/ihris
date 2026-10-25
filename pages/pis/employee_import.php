<?php
/**
 * Import Employees (CSV) — load many 201 records into the Employee Masterfile at once.
 *
 *  1. Download the template, fill one row per employee (codes for the reference tables).
 *  2. Upload it: every row is validated and shown in a preview (create / update / error).
 *  3. Import: valid rows are created through employee_create() exactly like the form does
 *     (history, initial leave credits, optional portal account). Existing employee numbers are
 *     updated only when "Update existing employees" is ticked.
 */

/** Column => [description, example, required] */
const EMP_IMPORT_COLUMNS = [
    'employee_no'        => ['Employee No. — unique; blank = next EMP-0000 number', 'EMP-0101', false],
    'last_name'          => ['Last name', 'Dela Cruz', true],
    'first_name'         => ['First name', 'Juan', true],
    'middle_name'        => ['Middle name', 'Santos', false],
    'suffix'             => ['Suffix', 'Jr.', false],
    'gender'             => ['Sex: male / female', 'male', true],
    'birth_date'         => ['Birth date YYYY-MM-DD', '1990-05-21', false],
    'civil_status'       => ['single / married / widowed / separated / annulled', 'single', false],
    'contact_no'         => ['Mobile / contact no.', '09171234567', false],
    'email'              => ['Email address', 'juan@example.com', false],
    'address'            => ['Address', '12 Rizal St., Makati City', false],
    'emergency_contact'  => ['Emergency contact person', 'Maria Dela Cruz', false],
    'emergency_phone'    => ['Emergency contact no.', '09181234567', false],
    'sss_no'             => ['SSS No. (digits and dashes)', '34-1234567-8', false],
    'philhealth_no'      => ['PhilHealth No.', '12-345678901-2', false],
    'pagibig_no'         => ['Pag-IBIG MID No.', '1234-5678-9012', false],
    'tin'                => ['TIN', '123-456-789-000', false],
    'biometric_id'       => ['Biometric device user ID', '101', false],
    'date_hired'         => ['Date hired YYYY-MM-DD', '2026-01-15', true],
    'date_regularized'   => ['Date regularized YYYY-MM-DD', '2026-07-15', false],
    'employment_status'  => ['probationary / regular / project_based / contractual / resigned / terminated / retired', 'probationary', false],
    'position'           => ['Position title (blank = name of the Job code)', 'Sales Associate', true],
    'rank_level'         => ['Rank / level', 'Rank-and-file', false],
    'org_unit'           => ['Organizational unit CODE (or exact name)', 'SALES', false],
    'supervisor_no'      => ['Employee No. of the immediate supervisor', 'EMP-0005', false],
    'branch'             => ['Company / branch short CODE (employer of record)', 'HO', true],
    'job'                => ['Job Table code', '1400', false],
    'employee_group'     => ['Employee Group code', '01', false],
    'class_group'        => ['Class Group code', 'SAL', false],
    'rank_category'      => ['Rank Category code', 'RF', false],
    'classification'     => ['Classification code', 'DL', false],
    'location'           => ['Location code', 'MKT', false],
    'salary_grade'       => ['Salary grade code', 'SG-2', false],
    'salary_step'        => ['Salary step number', '1', false],
    'rate_type'          => ['monthly / daily', 'monthly', false],
    'basic_rate'         => ['Basic rate per month or per day', '18000', true],
    'employee_type'      => ['Employee Type code (conversion factor)', 'AA', false],
    'days_per_year'      => ['Days-per-year factor when no employee type (261 / 313 / 365)', '261', false],
    'pay_group'          => ['Pay code (pay group) CODE', 'SM-RF', true],
    'pagibig_voluntary'  => ['Voluntary Pag-IBIG per month', '0', false],
    'bank_name'          => ['Payroll bank', 'BDO', false],
    'bank_account_no'    => ['Bank account no.', '001234567890', false],
    'bank_account_type'  => ['savings / current / payroll / cash (cash = paid in cash: Cash Payment Listing, no bank file)', 'savings', false],
    'exemption_code'     => ['Exemption code (Z, MWE, EXEMP…)', 'Z', false],
    'is_mwe'             => ['Minimum wage earner: Y / N', 'N', false],
    'shift'              => ['Shift code', 'DAY-MF', false],
    'attendance_method'  => ['biometric / web / both / import', 'biometric', false],
    'timekeeping_exempt' => ['Exempt from time-keeping: Y / N', 'N', false],
    'day_off'            => ['Default day-off on 7-day shifts: day names or 0-6 (0 = Sunday), e.g. "Sun" or "Sat, Sun"; blank = none', '', false],
    'is_solo_parent'     => ['Qualified solo parent: Y / N', 'N', false],
    'solo_parent_id_no'  => ['Solo Parent ID No.', '', false],
    'create_account'     => ['Create the self-service account: Y / N (blank = the option below)', 'Y', false],
];

// ---- template download
if (get('template')) {
    $header = array_keys(EMP_IMPORT_COLUMNS);
    $example = array_map(fn($c) => $c[1], EMP_IMPORT_COLUMNS);
    if (!salary_can('view')) {
        $example['basic_rate'] = '';
    }
    audit('pis', 'employee_import_template', 'employees');
    csv_download('employee_import_template.csv', $header, [$example]);
}

/** Read a CSV upload into rows keyed by the header names (UTF-8 BOM, comma or semicolon). */
function emp_import_read(string $path): array
{
    $text = (string)file_get_contents($path);
    if (substr($text, 0, 3) === "\xEF\xBB\xBF") {
        $text = substr($text, 3);
    }
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
    $first = '';
    foreach ($lines as $l) {
        if (trim($l) !== '') {
            $first = $l;
            break;
        }
    }
    $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $text);
    rewind($fh);
    $header = null;
    $rows = [];
    $n = 0;
    while (($cells = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
        $n++;
        if ($cells === [null] || !array_filter($cells, fn($c) => trim((string)$c) !== '')) {
            continue;
        }
        if ($header === null) {
            $header = array_map(fn($c) => strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '_', (string)$c), '_')), $cells);
            continue;
        }
        $r = [];
        foreach ($header as $i => $k) {
            if ($k === '') {
                continue;
            }
            $r[$k] = trim((string)($cells[$i] ?? ''));
        }
        $r['_line'] = $n;
        $rows[] = $r;
    }
    fclose($fh);
    return ['header' => $header ?: [], 'rows' => $rows];
}

/** Resolve a code (or exact name) of a reference table to its id. */
function emp_import_lookup(string $table, string $value, array &$cache, string $codeCol = 'code', string $nameCol = 'name'): ?int
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $k = $table . '|' . mb_strtolower($value);
    if (!array_key_exists($k, $cache)) {
        $cache[$k] = val("SELECT id FROM `$table` WHERE LOWER(`$codeCol`) = LOWER(?) OR LOWER(`$nameCol`) = LOWER(?) ORDER BY (LOWER(`$codeCol`) = LOWER(?)) DESC LIMIT 1", [$value, $value, $value]);
        $cache[$k] = $cache[$k] ? (int)$cache[$k] : 0;
    }
    return $cache[$k] ?: null;
}

/**
 * Turn one CSV row into an employee record + the list of problems.
 * Returns ['data' => [...], 'errors' => [...], 'existing' => ?int, 'account' => bool].
 */
function emp_import_map(array $r, array $opt, array &$cache, array &$seenNos, array &$seenBio): array
{
    $err = [];
    $d = [];
    $yn = fn($v) => in_array(mb_strtolower(trim((string)$v)), ['y', 'yes', '1', 'true', 'x'], true) ? 1 : 0;
    $date = function (string $v) use (&$err, $r) {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        $ts = strtotime($v);
        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $v)) {
            [$m, $dd, $y] = explode('/', $v);
            $ts = mktime(0, 0, 0, (int)$m, (int)$dd, (int)$y);
        } elseif (is_numeric($v) && (float)$v > 20000) {
            $ts = (int)(((float)$v - 25569) * 86400);   // Excel serial date
        }
        return $ts ? date('Y-m-d', $ts) : $v;
    };
    foreach (['last_name', 'first_name', 'middle_name', 'suffix', 'address', 'contact_no', 'email', 'emergency_contact', 'emergency_phone', 'sss_no', 'philhealth_no', 'pagibig_no', 'tin',
        'biometric_id', 'position', 'rank_level', 'bank_name', 'bank_account_no', 'solo_parent_id_no'] as $k) {
        $d[$k] = ($r[$k] ?? '') !== '' ? $r[$k] : null;
    }
    $d['employee_no'] = ($r['employee_no'] ?? '') !== '' ? strtoupper($r['employee_no']) : null;
    $d['gender'] = mb_strtolower((string)($r['gender'] ?? '')) ?: null;
    if (in_array($d['gender'], ['m', 'f'], true)) {
        $d['gender'] = $d['gender'] === 'm' ? 'male' : 'female';
    }
    $d['civil_status'] = mb_strtolower((string)($r['civil_status'] ?? '')) ?: 'single';
    $d['birth_date'] = $date((string)($r['birth_date'] ?? ''));
    $d['date_hired'] = $date((string)($r['date_hired'] ?? '')) ?: today();
    $d['date_regularized'] = $date((string)($r['date_regularized'] ?? ''));
    $d['employment_status'] = str_replace([' ', '-'], '_', mb_strtolower((string)($r['employment_status'] ?? ''))) ?: 'probationary';
    $d['rate_type'] = mb_strtolower((string)($r['rate_type'] ?? '')) ?: 'monthly';
    $d['basic_rate'] = round((float)str_replace([',', '₱', ' '], '', (string)($r['basic_rate'] ?? '0')), 2);
    if (!salary_can('edit')) {   // no Basic Salary edit right: the column is ignored (the rate on file is kept)
        unset($r['basic_rate']);
        $d['basic_rate'] = 0.0;
    }
    $d['salary_step'] = ($r['salary_step'] ?? '') !== '' ? (int)$r['salary_step'] : null;
    $d['days_per_year'] = (int)($r['days_per_year'] ?? 0) ?: (int)setting('default_days_per_year', 261);
    $d['pagibig_voluntary'] = round((float)str_replace(',', '', (string)($r['pagibig_voluntary'] ?? '0')), 2);
    $d['bank_account_type'] = mb_strtolower((string)($r['bank_account_type'] ?? '')) ?: null;
    $d['attendance_method'] = mb_strtolower((string)($r['attendance_method'] ?? '')) ?: 'biometric';
    $d['is_mwe'] = $yn($r['is_mwe'] ?? '');
    $d['timekeeping_exempt'] = $yn($r['timekeeping_exempt'] ?? '');
    $d['is_solo_parent'] = $yn($r['is_solo_parent'] ?? '');
    if (array_key_exists('day_off', $r)) {   // a file without the column keeps the day-off on file
        [$d['day_off'], $badDays] = day_off_parse((string)$r['day_off']);
        if ($badDays) {
            $err[] = 'Default day-off: "' . implode('", "', $badDays) . '" is not a day (use Sunday ... Saturday, Sun ... Sat or 0 to 6).';
        }
    }

    // reference tables by code (or exact name)
    $refs = [
        'org_unit' => ['org_units', 'org_unit_id', 'code', 'name'], 'branch' => ['branches', 'default_branch_id', 'code', 'name'], 'job' => ['jobs', 'job_id', 'code', 'name'],
        'employee_group' => ['emp_groups', 'employee_group_id', 'code', 'name'], 'class_group' => ['class_groups', 'class_group_id', 'code', 'name'],
        'rank_category' => ['rank_categories', 'rank_category_id', 'code', 'name'], 'classification' => ['classifications', 'classification_id', 'code', 'name'],
        'location' => ['locations', 'location_id', 'code', 'name'], 'salary_grade' => ['salary_grades', 'salary_grade_id', 'code', 'position_band'],
        'employee_type' => ['emp_types', 'employee_type_id', 'code', 'name'], 'pay_group' => ['pay_groups', 'pay_group_id', 'code', 'name'],
        'exemption_code' => ['exemption_codes', 'exemption_code_id', 'code', 'name'], 'shift' => ['shifts', 'shift_id', 'code', 'name'],
    ];
    foreach ($refs as $col => [$table, $field, $codeCol, $nameCol]) {
        $v = trim((string)($r[$col] ?? ''));
        if ($v === '') {
            $d[$field] = null;
            continue;
        }
        $id = emp_import_lookup($table, $v, $cache, $codeCol, $nameCol);
        if (!$id) {
            $err[] = ucfirst(str_replace('_', ' ', $col)) . " \"$v\" was not found.";
        }
        $d[$field] = $id;
    }
    // branch shorthand: the short code, the branch code or the company code
    if (($r['branch'] ?? '') !== '' && empty($d['default_branch_id'])) {
        $bid = val('SELECT id FROM branches WHERE branch_code = ? OR company_code = ? ORDER BY id LIMIT 1', [$r['branch'], $r['branch']]);
        if ($bid) {
            $d['default_branch_id'] = (int)$bid;
            array_pop($err);
        }
    }
    $d['supervisor_id'] = null;
    if (($r['supervisor_no'] ?? '') !== '') {
        $sid = val('SELECT id FROM employees WHERE employee_no = ?', [strtoupper($r['supervisor_no'])]);
        if (!$sid) {
            $err[] = 'Supervisor "' . $r['supervisor_no'] . '" was not found.';
        }
        $d['supervisor_id'] = $sid ? (int)$sid : null;
    }
    // defaults from the import options when the row leaves them blank
    foreach (['default_branch_id' => 'branch_id', 'pay_group_id' => 'pay_group_id', 'shift_id' => 'shift_id', 'org_unit_id' => 'org_unit_id'] as $field => $optKey) {
        if (empty($d[$field]) && !empty($opt[$optKey])) {
            $d[$field] = (int)$opt[$optKey];
        }
    }
    if (empty($d['employee_type_id']) && lookup_default('emp_types')) {
        $d['employee_type_id'] = lookup_default('emp_types');
    }
    if (empty($d['employee_group_id']) && lookup_default('emp_groups')) {
        $d['employee_group_id'] = lookup_default('emp_groups');
    }
    if (empty($d['exemption_code_id'])) {
        $d['exemption_code_id'] = val("SELECT id FROM exemption_codes WHERE code = 'Z'") ?: null;
    }
    if (!empty($d['job_id']) && empty($d['position'])) {
        $d['position'] = val('SELECT name FROM jobs WHERE id = ?', [$d['job_id']]);
    }
    if (!empty($d['employee_type_id'])) {
        $et = emp_type_row((int)$d['employee_type_id']);
        if ($et) {
            $d['days_per_year'] = (int)round((float)$et['conversion_factor'] * 12);
        }
    }

    $existing = $d['employee_no'] ? val('SELECT id FROM employees WHERE employee_no = ?', [$d['employee_no']]) : null;
    $existing = $existing ? (int)$existing : null;
    if ($existing && !scope_allows($existing)) {
        $err[] = 'Employee No. ' . $d['employee_no'] . ' is outside your company / branch and department assignment.';
    } elseif (!scope_fits($d)) {
        $err[] = 'The company / branch or department of this row is outside your assignment.';
    }
    if ($existing && empty($opt['update_existing'])) {
        $err[] = 'Employee No. ' . $d['employee_no'] . ' already exists (tick "Update existing employees" to change it).';
    }
    // duplicates inside the file
    if ($d['employee_no']) {
        if (isset($seenNos[$d['employee_no']])) {
            $err[] = 'Employee No. ' . $d['employee_no'] . ' appears more than once in the file (line ' . $seenNos[$d['employee_no']] . ').';
        }
        $seenNos[$d['employee_no']] = $r['_line'];
    }
    if ($d['biometric_id']) {
        if (isset($seenBio[$d['biometric_id']])) {
            $err[] = 'Biometric ID ' . $d['biometric_id'] . ' appears more than once in the file (line ' . $seenBio[$d['biometric_id']] . ').';
        }
        $seenBio[$d['biometric_id']] = $r['_line'];
    }
    if (!$d['employee_no'] && !$existing) {
        $d['employee_no'] = '(auto)';
    }
    if ($existing) {
        // an update touches only the columns the file actually fills
        foreach ($d as $k => $v) {
            if (($v === null || $v === '' || $v === 0 || $v === 0.0) && !in_array($k, ['employee_no'], true) && (!isset($r[$k]) || $r[$k] === '')) {
                unset($d[$k]);
            }
        }
        foreach (array_keys($refs) as $col) {
            if (($r[$col] ?? '') === '') {
                unset($d[$refs[$col][1]]);
            }
        }
        if (($r['supervisor_no'] ?? '') === '') {
            unset($d['supervisor_id']);
        }
        $full = array_merge(row('SELECT * FROM employees WHERE id = ?', [$existing]) ?: [], $d);
        $err = array_merge($err, employee_validate($full, $existing));
    } else {
        $check = $d;
        if ($check['employee_no'] === '(auto)') {
            $check['employee_no'] = 'EMP-AUTO';
        }
        $err = array_merge($err, employee_validate($check, 0));
    }
    $account = ($r['create_account'] ?? '') !== '' ? (bool)$yn($r['create_account']) : !empty($opt['create_accounts']);
    return ['data' => $d, 'errors' => array_values(array_unique($err)), 'existing' => $existing, 'account' => $account && !$existing, 'line' => $r['_line']];
}

$preview = $_SESSION['emp_import'] ?? null;

if (is_post()) {
    verify_csrf();
    $action = post('_action');
    try {
        if ($action === 'upload') {
            if (empty($_FILES['csv']['tmp_name'])) {
                throw new RuntimeException('Choose the CSV file to import.');
            }
            $ext = strtolower((string)pathinfo((string)$_FILES['csv']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['csv', 'txt', 'tsv'], true)) {
                throw new RuntimeException('The employee import reads .csv files. Save the spreadsheet as CSV (UTF-8) first.');
            }
            $read = emp_import_read((string)$_FILES['csv']['tmp_name']);
            if (!$read['rows']) {
                throw new RuntimeException('No data rows found. The first row must be the column headers of the template.');
            }
            $known = array_keys(EMP_IMPORT_COLUMNS);
            $missing = array_diff(['last_name', 'first_name'], $read['header']);
            if ($missing) {
                throw new RuntimeException('Missing required columns: ' . implode(', ', $missing) . '. Download the template and keep its header row.');
            }
            $unknown = array_diff(array_filter($read['header']), $known);
            $opt = ['update_existing' => (bool)post('update_existing'), 'create_accounts' => (bool)post('create_accounts'),
                'branch_id' => (int)post('branch_id') ?: null, 'pay_group_id' => (int)post('pay_group_id') ?: null, 'shift_id' => (int)post('shift_id') ?: null, 'org_unit_id' => (int)post('org_unit_id') ?: null,
                'temp_password' => trim((string)post('temp_password')) ?: setting('emp_import_default_password', 'Welcome' . date('Y'))];
            $cache = [];
            $seenNos = [];
            $seenBio = [];
            $items = [];
            foreach (array_slice($read['rows'], 0, 2000) as $r) {
                $items[] = emp_import_map($r, $opt, $cache, $seenNos, $seenBio);
            }
            $_SESSION['emp_import'] = ['file' => (string)$_FILES['csv']['name'], 'items' => $items, 'opt' => $opt, 'unknown' => array_values($unknown), 'total' => count($read['rows'])];
            audit('pis', 'employee_import_preview', 'employees', null, ['file' => $_FILES['csv']['name'], 'rows' => count($items)]);
            redirect('employee_import');
        }
        if ($action === 'confirm') {
            if (!$preview) {
                throw new RuntimeException('Nothing to import. Upload the CSV file first.');
            }
            $opt = $preview['opt'];
            $created = 0;
            $updated = 0;
            $rejected = 0;
            $accounts = [];
            $refresh = [];
            $batch = insert('employee_import_batches', ['file_name' => $preview['file'], 'total_rows' => count($preview['items']), 'uploaded_by' => user_id()]);
            foreach ($preview['items'] as $it) {
                if ($it['errors']) {
                    $rejected++;
                    continue;
                }
                $d = $it['data'];
                if ($it['existing']) {
                    unset($d['employee_no']);
                    $was = row('SELECT shift_id, day_off FROM employees WHERE id = ?', [(int)$it['existing']]);
                    tx(fn() => employee_apply_changes((int)$it['existing'], $d, today(), 'Updated by CSV import (' . $preview['file'] . ')'));
                    // v18: a new default day-off or shift re-computes the open daily time records (same as the employee form)
                    if ((array_key_exists('day_off', $d) && (string)$d['day_off'] !== (string)$was['day_off']) || (array_key_exists('shift_id', $d) && (string)$d['shift_id'] !== (string)$was['shift_id'])) {
                        $refresh[] = (int)$it['existing'];
                    }
                    $updated++;
                    continue;
                }
                if ($d['employee_no'] === '(auto)') {
                    $d['employee_no'] = next_employee_no();
                }
                $res = employee_create($d, ['create_account' => $it['account'], 'temp_password' => $opt['temp_password'], 'import_batch_id' => $batch, 'via' => 'csv_import',
                    'history_remarks' => 'New hire (CSV import)']);
                if ($res['account']) {
                    $accounts[] = $res['account']['username'];
                }
                $created++;
            }
            foreach ($refresh as $rid) {
                dtr_refresh_recent($rid);
            }
            update('employee_import_batches', ['created_rows' => $created, 'updated_rows' => $updated, 'rejected_rows' => $rejected], 'id = ?', [$batch]);
            audit('pis', 'employee_import', 'employee_import_batches', $batch, ['file' => $preview['file'], 'created' => $created, 'updated' => $updated, 'rejected' => $rejected]);
            unset($_SESSION['emp_import']);
            flash('success', sprintf('Import finished: %d employee(s) created, %d updated, %d row(s) skipped because of errors.', $created, $updated, $rejected));
            if ($accounts) {
                flash('info', count($accounts) . ' portal account(s) created with the temporary password "' . $opt['temp_password'] . '" (must be changed at first sign-in): ' . implode(', ', array_slice($accounts, 0, 20)) . (count($accounts) > 20 ? '…' : ''));
            }
            redirect('employees', ['status' => 'active']);
        }
        if ($action === 'discard') {
            unset($_SESSION['emp_import']);
            flash('info', 'Preview discarded. Nothing was imported.');
            redirect('employee_import');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
        redirect('employee_import');
    }
}

$batches = rows('SELECT b.*, u.full_name FROM employee_import_batches b LEFT JOIN users u ON u.id = b.uploaded_by ORDER BY b.id DESC LIMIT 10');

page_header('Import Employees (CSV)', [
    'subtitle' => 'Load many employees into the Employee Masterfile from one CSV file. Every row is validated and previewed before anything is written; imported employees get their employment history, leave credits and, when asked, a self-service account, exactly like a record created on the form.',
    'back' => url('employees'),
    'back_label' => 'Employee Masterfile',
    'actions' => '<a class="btn btn-primary" href="' . h(url('employee_import', ['template' => 1])) . '">Download CSV template</a><a class="btn" href="' . url('employee_edit') . '">New employee (form)</a>',
]);

if ($preview):
    $items = $preview['items'];
    $ok = array_filter($items, fn($i) => !$i['errors'] && !$i['existing']);
    $upd = array_filter($items, fn($i) => !$i['errors'] && $i['existing']);
    $bad = array_filter($items, fn($i) => $i['errors']);
    $bview = pairs('SELECT id, code FROM branches');
    $pgview = pairs('SELECT id, code FROM pay_groups');
    ?>
  <div class="stats">
    <?= stat_card('Rows read', number_format(count($items)), h($preview['file'])) ?>
    <?= stat_card('To create', number_format(count($ok)), 'new employee records', count($ok) ? 'good' : '') ?>
    <?= stat_card('To update', number_format(count($upd)), 'existing employee numbers', count($upd) ? 'info' : '') ?>
    <?= stat_card('With errors', number_format(count($bad)), 'skipped unless corrected', count($bad) ? 'bad' : 'good') ?>
  </div>
  <?php if ($preview['unknown']): ?>
    <div class="callout warn">Columns not in the template are ignored: <?= h(implode(', ', $preview['unknown'])) ?>.</div>
  <?php endif; ?>
  <form method="post" class="toolbar">
    <?= csrf_field() ?>
    <span class="label">Preview</span>
    <span class="muted small">Check the rows below, then import. Rows with errors are skipped; fix them in the file and upload again.</span>
    <span class="spacer"></span>
    <button class="btn btn-primary" name="_action" value="confirm" data-confirm="Import <?= count($ok) ?> new and <?= count($upd) ?> updated employee(s) now?"<?= (count($ok) + count($upd)) ? '' : ' disabled' ?>>Import <?= count($ok) + count($upd) ?> row(s)</button>
    <button class="btn" name="_action" value="discard">Discard</button>
  </form>
  <div class="card flush">
    <div class="table-wrap table-scroll">
      <table class="table table-compact">
        <thead><tr><th>Line</th><th>Result</th><th>Employee No.</th><th>Name</th><th>Position</th><th>Branch</th><th>Pay code</th><th>Date hired</th><?php if (salary_can('view')): ?><th class="num">Rate</th><?php endif; ?><th>Account</th><th>Problems</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): $d = $it['data']; ?>
          <tr class="<?= $it['errors'] ? 'row-bad' : ($it['existing'] ? 'row-warn' : 'row-good') ?>">
            <td class="num"><?= (int)$it['line'] ?></td>
            <td><?= $it['errors'] ? 'Error' : ($it['existing'] ? 'Update' : 'Create') ?></td>
            <td class="nowrap"><?= h($d['employee_no'] ?? '') ?></td>
            <td><strong><?= h(trim(($d['last_name'] ?? '') . ', ' . ($d['first_name'] ?? '') . ' ' . ($d['middle_name'] ?? ''))) ?></strong></td>
            <td><?= h($d['position'] ?? '') ?></td>
            <td><?= h($bview[$d['default_branch_id'] ?? 0] ?? '') ?></td>
            <td><?= h($pgview[$d['pay_group_id'] ?? 0] ?? '') ?></td>
            <td class="nowrap"><?= h($d['date_hired'] ?? '') ?></td>
            <?php if (salary_can('view')): ?><td class="num"><?= isset($d['basic_rate']) ? money($d['basic_rate']) . '<br><small class="muted">/' . h(($d['rate_type'] ?? 'monthly') === 'daily' ? 'day' : 'month') . '</small>' : '' ?></td><?php endif; ?>
            <td><?= $it['account'] ? 'Yes' : ($it['existing'] ? '' : 'No') ?></td>
            <td class="small <?= $it['errors'] ? 'text-bad' : 'muted' ?>"><?= h(implode(' ', $it['errors'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php else: ?>
  <div class="grid grid-2-1">
    <div class="card">
      <h2>Upload the file</h2>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?><input type="hidden" name="_action" value="upload">
        <div class="form-grid">
          <div class="field wide"><label>CSV file</label><input type="file" name="csv" accept=".csv,.txt,.tsv" required>
            <span class="help">UTF-8, comma or semicolon separated, first row = the column headers of the template. Up to 2,000 employees per file.</span></div>
          <div class="field wide form-section-title">Defaults for rows that leave the column blank</div>
          <div class="field"><label>Company / branch</label><select name="branch_id"><?= options(branch_options(), null, 'From the file only') ?></select></div>
          <div class="field"><label>Pay code</label><select name="pay_group_id"><?= options(pay_group_options(), null, 'From the file only') ?></select></div>
          <div class="field"><label>Shift</label><select name="shift_id"><?= options(shift_options(), null, 'From the file only') ?></select></div>
          <div class="field"><label>Organizational unit</label><select name="org_unit_id"><?= options(org_unit_options(), null, 'From the file only') ?></select></div>
          <div class="field wide form-section-title">Options</div>
          <div class="field"><label class="check"><input type="checkbox" name="create_accounts" value="1" checked> Create self-service accounts</label><span class="help">Username = employee no. in lower case. A row can override this with its create_account column.</span></div>
          <div class="field"><label>Temporary password</label><input name="temp_password" value="<?= h(setting('emp_import_default_password', 'Welcome' . date('Y'))) ?>"><span class="help">Must be changed at the first sign-in.</span></div>
          <div class="field wide"><label class="check"><input type="checkbox" name="update_existing" value="1"> Update existing employees whose Employee No. is in the file</label><span class="help">Only the columns the row fills are changed, with an entry in the employment history. Left unticked, existing numbers are reported as errors.</span></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary btn-lg">Validate and preview</button></div>
      </form>
    </div>

    <div>
      <div class="card">
        <h2>How it works</h2>
        <ol class="man-steps">
          <li>Download the template. The first row holds the column names, the second row is an example you delete.</li>
          <li>Fill one row per employee. Reference tables are given by their code: branch, pay code, shift, unit, job, employee type and group, grade, exemption code.</li>
          <li>Upload the file. Every row is checked: required fields, duplicate employee numbers or biometric IDs, unknown codes, dates and government numbers.</li>
          <li>Read the preview and press Import. Rows with errors are skipped, everything else is created with its hire history and leave credits.</li>
        </ol>
      </div>
      <div class="card">
        <h2>Recent imports</h2>
        <div class="table-wrap"><table class="table table-compact">
          <thead><tr><th>When</th><th>File</th><th class="num">Rows</th><th class="num">Created</th><th class="num">Updated</th><th class="num">Skipped</th><th>By</th></tr></thead>
          <tbody>
          <?php if (!$batches) echo empty_row(7, 'No import yet.');
          foreach ($batches as $b): ?>
            <tr><td class="nowrap"><?= h(fmt_dt($b['created_at'])) ?></td><td><?= h(mb_strimwidth($b['file_name'], 0, 40, '…')) ?></td><td class="num"><?= (int)$b['total_rows'] ?></td>
              <td class="num"><?= (int)$b['created_rows'] ?></td><td class="num"><?= (int)$b['updated_rows'] ?></td><td class="num"><?= (int)$b['rejected_rows'] ?></td><td><?= h($b['full_name'] ?: '—') ?></td></tr>
          <?php endforeach; ?>
          </tbody></table></div>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Template columns</h2>
    <p class="muted small">Required columns are marked. Everything else may stay blank; blank reference columns take the defaults chosen above.</p>
    <div class="table-wrap"><table class="table table-compact">
      <thead><tr><th>Column</th><th>Meaning</th><th>Example</th><th>Required</th></tr></thead>
      <tbody>
      <?php foreach (EMP_IMPORT_COLUMNS as $col => $c): $noSal = $col === 'basic_rate' && !salary_can('edit'); ?>
        <tr><td class="mono"><?= h($col) ?></td><td><?= h($c[0]) ?><?= $noSal ? ' <small class="muted">(ignored: you have no Basic Salary edit right)</small>' : '' ?></td><td class="mono"><?= $col === 'basic_rate' && !salary_can('view') ? '' : h($c[1]) ?></td><td><?= $c[2] && !$noSal ? 'Yes' : '' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
<?php endif;
page_footer();
