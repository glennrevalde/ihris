<?php
/**
 * Masterfile change handling. Because the masterfile is the authoritative record, any change here
 * (promotion, transfer, salary grade/rate change, status change) is logged to the employment history
 * and automatically reflected in the next payroll run. Retroactive salary changes create a
 * retroactive pay adjustment automatically.
 */

/** Employment status of the 201 file (v17: Resigned, Terminated and Retired added). */
const EMPLOYMENT_STATUSES = ['probationary' => 'Probationary', 'regular' => 'Regular', 'project_based' => 'Project-based', 'contractual' => 'Contractual',
    'resigned' => 'Resigned', 'terminated' => 'Terminated', 'retired' => 'Retired'];
/** Employment statuses that end the employment (the employee is separated through Separate employee). */
const EMPLOYMENT_ENDED = ['resigned', 'terminated', 'retired'];
/** Employment status that follows a separation type of the 201 file. */
const SEPARATION_EMPLOYMENT_STATUS = ['resignation' => 'resigned', 'retirement' => 'retired', 'termination' => 'terminated', 'dismissal' => 'terminated'];

const HISTORY_FIELDS = ['position', 'org_unit_id', 'salary_grade_id', 'salary_step', 'basic_rate', 'rate_type', 'employment_status', 'default_branch_id', 'pay_group_id', 'status'];

/** Has the database the employment statuses of version 15 (Resigned, Terminated, Retired)? */
function employment_status_supports_ended(): bool
{
    static $ok = [];
    $db = function_exists('active_db_name') ? active_db_name() : '';
    if (!isset($ok[$db])) {
        try {
            $ok[$db] = strpos((string)val("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'employees' AND column_name = 'employment_status'"), 'retired') !== false;
        } catch (Throwable $e) {
            $ok[$db] = false;
        }
    }
    return $ok[$db];
}

/**
 * Update an employee and record history. Returns ['history_id' => ?int, 'retro' => ?array].
 */
function employee_apply_changes(int $empId, array $new, string $effectiveDate, ?string $remarks = null, ?string $forceType = null): array
{
    $old = row('SELECT * FROM employees WHERE id = ?', [$empId]);
    if (!$old) {
        throw new RuntimeException('Employee not found.');
    }
    // v17: a separation sets the employment status (Resigned, Retired, Terminated); a reinstatement ends it
    if (($new['status'] ?? null) === 'separated' && !array_key_exists('employment_status', $new) && employment_status_supports_ended()) {
        $st = SEPARATION_EMPLOYMENT_STATUS[$new['separation_type'] ?? $old['separation_type'] ?? ''] ?? null;
        if ($st) {
            $new['employment_status'] = $st;
        }
    } elseif (($new['status'] ?? null) === 'active' && $old['status'] === 'separated' && !array_key_exists('employment_status', $new)
        && in_array($old['employment_status'], EMPLOYMENT_ENDED, true)) {
        $new['employment_status'] = 'regular';
    }
    $new['updated_at'] = now();
    update('employees', $new, 'id = ?', [$empId]);
    $cur = row('SELECT * FROM employees WHERE id = ?', [$empId]);

    $changed = [];
    foreach (HISTORY_FIELDS as $f) {
        if (array_key_exists($f, $new) && (string)$old[$f] !== (string)$cur[$f]) {
            $changed[] = $f;
        }
    }
    $result = ['history_id' => null, 'retro' => null];
    $all = diff_fields($old, array_intersect_key($cur, $new));
    unset($all['updated_at']);
    if ($all) {
        audit('pis', 'employee_update', 'employees', $empId, $all);
    }
    if (!$changed && !$forceType) {
        return $result;
    }
    $grades = pairs('SELECT id, code FROM salary_grades');
    $gradeLabel = fn($r) => $r['salary_grade_id'] ? ($grades[$r['salary_grade_id']] ?? '') . ($r['salary_step'] ? ' step ' . $r['salary_step'] : '') : null;
    $oldRank = $old['salary_grade_id'] ? (int)val('SELECT sort_order FROM salary_grades WHERE id = ?', [$old['salary_grade_id']]) : 0;
    $newRank = $cur['salary_grade_id'] ? (int)val('SELECT sort_order FROM salary_grades WHERE id = ?', [$cur['salary_grade_id']]) : 0;

    if ($forceType) {
        $type = $forceType;
    } elseif (in_array('status', $changed, true) && $cur['status'] === 'separated') {
        $type = 'separation';
    } elseif (in_array('position', $changed, true) && ($newRank > $oldRank || (float)$cur['basic_rate'] > (float)$old['basic_rate'])) {
        $type = 'promotion';
    } elseif (in_array('org_unit_id', $changed, true)) {
        $type = 'transfer';
    } elseif (in_array('salary_grade_id', $changed, true) || in_array('salary_step', $changed, true)) {
        $type = 'grade_change';
    } elseif (in_array('basic_rate', $changed, true) || in_array('rate_type', $changed, true)) {
        $type = 'salary_change';
    } elseif (in_array('employment_status', $changed, true)) {
        $type = 'status_change';
    } elseif (in_array('default_branch_id', $changed, true)) {
        $type = 'branch_change';
    } else {
        $type = 'other';
    }
    $notes = [];
    if (in_array('employment_status', $changed, true)) {
        $notes[] = 'Status: ' . label($old['employment_status']) . ' → ' . label($cur['employment_status']);
    }
    if (in_array('default_branch_id', $changed, true)) {
        $b = pairs('SELECT id, name FROM branches');
        $notes[] = 'Default branch: ' . ($b[$old['default_branch_id']] ?? '') . ' → ' . ($b[$cur['default_branch_id']] ?? '');
    }
    if (in_array('pay_group_id', $changed, true)) {
        $pg = pairs('SELECT id, name FROM pay_groups');
        $notes[] = 'Pay group: ' . ($pg[$old['pay_group_id']] ?? '') . ' → ' . ($pg[$cur['pay_group_id']] ?? '');
    }
    if ($remarks) {
        $notes[] = $remarks;
    }
    $result['history_id'] = insert('employee_history', [
        'employee_id' => $empId, 'change_type' => $type, 'effective_date' => $effectiveDate,
        'old_position' => $old['position'], 'new_position' => $cur['position'],
        'old_org_unit' => org_path($old['org_unit_id'] ? (int)$old['org_unit_id'] : null), 'new_org_unit' => org_path($cur['org_unit_id'] ? (int)$cur['org_unit_id'] : null),
        'old_rate' => $old['basic_rate'], 'new_rate' => $cur['basic_rate'], 'rate_type' => $cur['rate_type'],
        'old_grade' => $gradeLabel($old), 'new_grade' => $gradeLabel($cur),
        'remarks' => $notes ? implode('; ', $notes) : null, 'created_by' => user_id(),
    ]);
    if ((float)$old['basic_rate'] !== (float)$cur['basic_rate'] && $old['rate_type'] === $cur['rate_type'] && $effectiveDate < today()) {
        $result['retro'] = retro_compute($empId, $effectiveDate, (float)$old['basic_rate'], (float)$cur['basic_rate'], $cur['rate_type'], $result['history_id']);
    }
    return $result;
}

/** Salary for a grade/step from the Salary Grade Table. */
function grade_step_amount(?int $gradeId, ?int $step): ?float
{
    if (!$gradeId || !$step) {
        return null;
    }
    $v = val('SELECT amount FROM salary_grade_steps WHERE grade_id = ? AND step_no = ?', [$gradeId, $step]);
    return $v === null ? null : (float)$v;
}

/* ======================================================================= creating employees */

/** Columns of the employee record a form or an import may set. */
const EMPLOYEE_TEXT_FIELDS = ['employee_no', 'biometric_id', 'last_name', 'first_name', 'middle_name', 'suffix', 'gender', 'birth_date', 'civil_status', 'address', 'contact_no', 'email',
    'emergency_contact', 'emergency_phone', 'sss_no', 'philhealth_no', 'pagibig_no', 'tin', 'date_hired', 'date_regularized', 'employment_status', 'position',
    'rank_level', 'org_unit_id', 'salary_grade_id', 'salary_step', 'rate_type', 'basic_rate', 'days_per_year', 'pay_group_id', 'default_branch_id', 'shift_id',
    'supervisor_id', 'attendance_method', 'solo_parent_id_no', 'pagibig_voluntary', 'bank_name', 'bank_account_no',
    'employee_type_id', 'employee_group_id', 'class_group_id', 'rank_category_id', 'classification_id', 'job_id', 'exemption_code_id', 'location_id', 'bank_account_type',
    'eye_color', 'religion', 'weight', 'height', 'spouse_name', 'spouse_address', 'spouse_contact', 'father_name', 'mother_name'];
const EMPLOYEE_BOOL_FIELDS = ['timekeeping_exempt', 'is_mwe', 'is_solo_parent'];
/** Account type of the payroll account. Cash: the net pay is paid in cash (Cash Payment Listing), never in the bank file. */
const EMP_ACCOUNT_TYPES = ['savings' => 'Savings', 'current' => 'Current / checking', 'payroll' => 'Payroll card', 'cash' => 'Cash (paid in cash, no bank)'];

/** Next free EMP-0001 style number. */
function next_employee_no(): string
{
    $last = val("SELECT employee_no FROM employees WHERE employee_no LIKE 'EMP-%' ORDER BY employee_no DESC LIMIT 1");
    $n = $last ? (int)substr($last, 4) + 1 : 1;
    while (val('SELECT COUNT(*) FROM employees WHERE employee_no = ?', [sprintf('EMP-%04d', $n)])) {
        $n++;
    }
    return sprintf('EMP-%04d', $n);
}

/**
 * Validate an employee record before it is created or changed. Returns the list of errors.
 * $id = the record being edited (0 for a new one).
 */
function employee_validate(array $d, int $id = 0): array
{
    $required = ['employee_no' => 'Employee No.', 'last_name' => 'Last name', 'first_name' => 'First name', 'gender' => 'Sex', 'date_hired' => 'Date hired',
        'position' => 'Position', 'basic_rate' => 'Basic rate', 'pay_group_id' => 'Pay group', 'default_branch_id' => 'Default branch'];
    $errors = [];
    foreach ($required as $k => $l) {
        if ($k === 'basic_rate' && function_exists('salary_can') && current_user() && !salary_can('edit')) {
            continue;   // set by a user with the Basic Salary edit right
        }
        if (!isset($d[$k]) || $d[$k] === null || $d[$k] === '' || ($k === 'basic_rate' && (float)$d[$k] <= 0)) {
            $errors[] = "$l is required.";
        }
    }
    if (!empty($d['gender']) && !in_array($d['gender'], ['male', 'female'], true)) {
        $errors[] = 'Sex must be male or female.';
    }
    if (!empty($d['civil_status']) && !in_array($d['civil_status'], ['single', 'married', 'widowed', 'separated', 'annulled'], true)) {
        $errors[] = 'Civil status must be single, married, widowed, separated or annulled.';
    }
    if (!empty($d['employment_status']) && !isset(EMPLOYMENT_STATUSES[$d['employment_status']])) {
        $errors[] = 'Employment status must be ' . implode(', ', array_keys(EMPLOYMENT_STATUSES)) . '.';
    }
    if (!empty($d['rate_type']) && !in_array($d['rate_type'], ['monthly', 'daily'], true)) {
        $errors[] = 'Rate type must be monthly or daily.';
    }
    if (!empty($d['attendance_method']) && !in_array($d['attendance_method'], ['biometric', 'web', 'both', 'import'], true)) {
        $errors[] = 'Time capture must be biometric, web, both or import.';
    }
    foreach (['date_hired', 'date_regularized', 'birth_date'] as $k) {
        if (!empty($d[$k]) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d[$k])) {
            $errors[] = label($k) . ' must be a date (YYYY-MM-DD).';
        }
    }
    if (!empty($d['email']) && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email address is invalid.';
    }
    foreach (['sss_no' => 'SSS', 'philhealth_no' => 'PhilHealth', 'pagibig_no' => 'Pag-IBIG', 'tin' => 'TIN'] as $k => $l) {
        if (!empty($d[$k]) && !preg_match('/^[0-9\- ]{6,20}$/', (string)$d[$k])) {
            $errors[] = "$l number may only contain digits and dashes (6 to 20 characters).";
        }
    }
    if (!empty($d['employee_no']) && val('SELECT COUNT(*) FROM employees WHERE employee_no = ? AND id <> ?', [$d['employee_no'], $id])) {
        $errors[] = 'Employee No. ' . $d['employee_no'] . ' is already used.';
    }
    if (!empty($d['biometric_id']) && val('SELECT COUNT(*) FROM employees WHERE biometric_id = ? AND id <> ?', [$d['biometric_id'], $id])) {
        $errors[] = 'Biometric ID ' . $d['biometric_id'] . ' is already assigned to another employee.';
    }
    if ($id && !empty($d['supervisor_id']) && (int)$d['supervisor_id'] === $id) {
        $errors[] = 'An employee cannot be his/her own supervisor.';
    }
    foreach (['pay_group_id' => 'pay_groups', 'default_branch_id' => 'branches', 'org_unit_id' => 'org_units', 'shift_id' => 'shifts', 'salary_grade_id' => 'salary_grades',
        'employee_type_id' => 'emp_types', 'employee_group_id' => 'emp_groups', 'job_id' => 'jobs', 'exemption_code_id' => 'exemption_codes', 'location_id' => 'locations',
        'class_group_id' => 'class_groups', 'rank_category_id' => 'rank_categories', 'classification_id' => 'classifications', 'supervisor_id' => 'employees'] as $k => $tbl) {
        if (!empty($d[$k]) && !val("SELECT COUNT(*) FROM `$tbl` WHERE id = ?", [(int)$d[$k]])) {
            $errors[] = label(str_replace('_id', '', $k)) . ' does not exist.';
        }
    }
    return $errors;
}

/**
 * Create an employee record: the row, the hire line of the employment history, the initial
 * leave credits of the year and, when asked, the self-service account.
 * $opt: create_account (bool), temp_password, import_batch_id. Returns ['id' => …, 'account' => [username, password] | null].
 */
function employee_create(array $d, array $opt = []): array
{
    $d['days_per_year'] = (int)($d['days_per_year'] ?? 0) ?: (int)setting('default_days_per_year', 261);
    if (!empty($d['employee_type_id'])) {
        $et = emp_type_row((int)$d['employee_type_id']);
        if ($et) {
            $d['days_per_year'] = (int)round((float)$et['conversion_factor'] * 12);
        }
    }
    if (!empty($d['job_id']) && empty($d['position'])) {
        $d['position'] = val('SELECT name FROM jobs WHERE id = ?', [$d['job_id']]);
    }
    if (!empty($opt['import_batch_id'])) {
        $d['import_batch_id'] = (int)$opt['import_batch_id'];
    }
    $account = null;
    $newId = tx(function () use ($d, $opt, &$account) {
        $newId = insert('employees', $d);
        insert('employee_history', ['employee_id' => $newId, 'change_type' => 'hire', 'effective_date' => $d['date_hired'], 'new_position' => $d['position'],
            'new_org_unit' => org_path(!empty($d['org_unit_id']) ? (int)$d['org_unit_id'] : null), 'new_rate' => $d['basic_rate'], 'rate_type' => $d['rate_type'] ?? 'monthly',
            'remarks' => $opt['history_remarks'] ?? 'New hire', 'created_by' => user_id()]);
        if (!empty($opt['create_account'])) {
            // usernames are unique across all companies (multi-company sign-in identity)
            $uname = mc_unique_username(strtolower((string)$d['employee_no']));
            if (!val('SELECT COUNT(*) FROM users WHERE username = ?', [$uname])) {
                $temp = (string)($opt['temp_password'] ?? '') ?: 'Welcome' . date('Y');
                insert('users', ['username' => $uname, 'password_hash' => password_hash($temp, PASSWORD_DEFAULT), 'full_name' => $d['first_name'] . ' ' . $d['last_name'],
                    'email' => $d['email'] ?? null, 'role' => 'employee', 'employee_id' => $newId, 'must_change_password' => 1]);
                $account = ['username' => $uname, 'password' => $temp];
            }
        }
        return $newId;
    });
    if ($account) {
        mc_sync_user((int)val('SELECT id FROM users WHERE username = ?', [$account['username']]));
    }
    audit('pis', 'employee_create', 'employees', $newId, ['employee_no' => $d['employee_no'], 'via' => $opt['via'] ?? 'form']);
    // initial leave credits for the current year (pro-rated for a mid-year hire)
    $e = row('SELECT * FROM employees WHERE id = ?', [$newId]);
    foreach (rows("SELECT * FROM leave_types WHERE is_active = 1 AND accrual_method IN ('annual','service_based')") as $t) {
        if (!leave_ineligibility($e, $t)) {
            $days = leave_entitlement($e, $t, (int)date('Y'));
            if (substr($e['date_hired'], 0, 4) == date('Y')) {
                $days = round($days * (13 - (int)substr($e['date_hired'], 5, 2)) / 12 * 2) / 2;
            }
            $b = leave_balance_row($newId, (int)$t['id'], (int)date('Y'));
            q('UPDATE leave_balances SET earned = ? WHERE id = ?', [$days, $b['id']]);
        }
    }
    return ['id' => $newId, 'account' => $account];
}


/* ======================================================================= family and background (201) */

/** Educational levels offered on the 201 form. */
const EDUCATION_LEVELS = ['elementary' => 'Elementary', 'secondary' => 'Secondary / High School', 'senior_high' => 'Senior High School',
    'vocational' => 'Vocational / Technical', 'college' => 'College', 'graduate' => 'Post-graduate'];

/** Children, educational background and previous employment of an employee. */
function employee_background(int $empId): array
{
    return [
        'children' => rows('SELECT * FROM employee_children WHERE employee_id = ? ORDER BY sort_order, birth_date, id', [$empId]),
        'education' => rows('SELECT * FROM employee_education WHERE employee_id = ? ORDER BY sort_order, id', [$empId]),
        'employment' => rows('SELECT * FROM employee_prev_employment WHERE employee_id = ? ORDER BY sort_order, date_from, id', [$empId]),
    ];
}

/**
 * Save the lists of the 201 form (children[name|birth_date][], educ[level|school|course|year][],
 * prevemp[company|position|date_from|date_to|reason][]): the lists are replaced as they were submitted;
 * empty rows are ignored. Returns the number of rows kept.
 */
function employee_save_background(int $empId, array $post): int
{
    $col = fn(array $a, string $k, int $i) => trim((string)($a[$k][$i] ?? ''));
    $date = function (string $v): ?string {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null;
    };
    $kids = (array)($post['children'] ?? []);
    $educ = (array)($post['educ'] ?? []);
    $prev = (array)($post['prevemp'] ?? []);
    $n = 0;
    tx(function () use ($empId, $kids, $educ, $prev, $col, $date, &$n) {
        q('DELETE FROM employee_children WHERE employee_id = ?', [$empId]);
        q('DELETE FROM employee_education WHERE employee_id = ?', [$empId]);
        q('DELETE FROM employee_prev_employment WHERE employee_id = ?', [$empId]);
        foreach (array_keys((array)($kids['name'] ?? [])) as $i => $k) {
            $name = $col($kids, 'name', $k);
            if ($name === '') {
                continue;
            }
            insert('employee_children', ['employee_id' => $empId, 'name' => mb_substr($name, 0, 150), 'birth_date' => $date($col($kids, 'birth_date', $k)), 'sort_order' => $i]);
            $n++;
        }
        foreach (array_keys((array)($educ['school'] ?? [])) as $i => $k) {
            $school = $col($educ, 'school', $k);
            if ($school === '') {
                continue;
            }
            $lvl = $col($educ, 'level', $k);
            insert('employee_education', ['employee_id' => $empId, 'level' => isset(EDUCATION_LEVELS[$lvl]) ? $lvl : null, 'school' => mb_substr($school, 0, 150),
                'course' => mb_substr($col($educ, 'course', $k), 0, 150) ?: null, 'year_graduated' => mb_substr(preg_replace('/[^0-9A-Za-z \-]/', '', $col($educ, 'year', $k)), 0, 10) ?: null, 'sort_order' => $i]);
            $n++;
        }
        foreach (array_keys((array)($prev['company'] ?? [])) as $i => $k) {
            $co = $col($prev, 'company', $k);
            if ($co === '') {
                continue;
            }
            insert('employee_prev_employment', ['employee_id' => $empId, 'company' => mb_substr($co, 0, 150), 'position' => mb_substr($col($prev, 'position', $k), 0, 100) ?: null,
                'date_from' => $date($col($prev, 'date_from', $k)), 'date_to' => $date($col($prev, 'date_to', $k)), 'reason' => mb_substr($col($prev, 'reason', $k), 0, 255) ?: null, 'sort_order' => $i]);
            $n++;
        }
    });
    return $n;
}
