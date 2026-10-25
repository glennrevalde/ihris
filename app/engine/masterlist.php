<?php
/**
 * Employee 201 File Masterlisting: a customizable list of employees. Every field of the employee
 * information (201 file) can be shown as a column; the default layout is Employee ID, Name,
 * Date Hired, Position and Basic Salary. Layouts (columns, order, sorting, grouping) can be saved
 * and shared (table report_layouts).
 */

const ML_REPORT_KEY = 'emp201_master';
const ML_DEFAULT_COLUMNS = ['employee_no', 'name', 'date_hired', 'position', 'basic_rate'];

/**
 * Field catalog: key => [label, group, SQL expression or null (computed in PHP), type].
 * Types: text, date, money, num, int, bool, enum (value shown in words), long (wraps).
 */
function ml_fields(): array
{
    static $f = null;
    if ($f !== null) {
        return $f;
    }
    $name = "CONCAT(e.last_name, ', ', e.first_name, IF(COALESCE(e.middle_name, '') <> '', CONCAT(' ', e.middle_name), ''), IF(COALESCE(e.suffix, '') <> '', CONCAT(' ', e.suffix), ''))";
    $f = [
        // identification
        'employee_no' => ['Employee ID / Code', 'Identification', 'e.employee_no', 'text'],
        'name' => ['Name', 'Identification', $name, 'text'],
        'last_name' => ['Last name', 'Identification', 'e.last_name', 'text'],
        'first_name' => ['First name', 'Identification', 'e.first_name', 'text'],
        'middle_name' => ['Middle name', 'Identification', 'e.middle_name', 'text'],
        'suffix' => ['Suffix', 'Identification', 'e.suffix', 'text'],
        'biometric_id' => ['Biometric ID', 'Identification', 'e.biometric_id', 'text'],
        // personal
        'gender' => ['Sex', 'Personal information', 'e.gender', 'enum'],
        'birth_date' => ['Date of birth', 'Personal information', 'e.birth_date', 'date'],
        'age' => ['Age', 'Personal information', 'TIMESTAMPDIFF(YEAR, e.birth_date, CURDATE())', 'int'],
        'civil_status' => ['Civil status', 'Personal information', 'e.civil_status', 'enum'],
        'religion' => ['Religion', 'Personal information', 'e.religion', 'text'],
        'eye_color' => ['Color of eyes', 'Personal information', 'e.eye_color', 'text'],
        'weight' => ['Weight', 'Personal information', 'e.weight', 'text'],
        'height' => ['Height', 'Personal information', 'e.height', 'text'],
        'address' => ['Address', 'Personal information', 'e.address', 'long'],
        'contact_no' => ['Contact no.', 'Personal information', 'e.contact_no', 'text'],
        'email' => ['E-mail', 'Personal information', 'e.email', 'text'],
        'emergency_contact' => ['Emergency contact', 'Personal information', 'e.emergency_contact', 'text'],
        'emergency_phone' => ['Emergency phone', 'Personal information', 'e.emergency_phone', 'text'],
        // family
        'spouse_name' => ['Spouse name', 'Family background', 'e.spouse_name', 'text'],
        'spouse_address' => ['Spouse address', 'Family background', 'e.spouse_address', 'long'],
        'spouse_contact' => ['Spouse contact no.', 'Family background', 'e.spouse_contact', 'text'],
        'father_name' => ["Father's name", 'Family background', 'e.father_name', 'text'],
        'mother_name' => ["Mother's name", 'Family background', 'e.mother_name', 'text'],
        'children' => ['Children and birthdays', 'Family background', "(SELECT GROUP_CONCAT(CONCAT(c.name, IF(c.birth_date IS NULL, '', CONCAT(' (', DATE_FORMAT(c.birth_date, '%b %d, %Y'), ')'))) ORDER BY c.sort_order, c.id SEPARATOR '; ') FROM employee_children c WHERE c.employee_id = e.id)", 'long'],
        'children_count' => ['No. of children', 'Family background', '(SELECT COUNT(*) FROM employee_children c WHERE c.employee_id = e.id)', 'int'],
        // education and previous employment
        'education' => ['Educational background', 'Education and work history', "(SELECT GROUP_CONCAT(CONCAT_WS(', ', NULLIF(d.school, ''), NULLIF(d.course, ''), NULLIF(d.year_graduated, '')) ORDER BY d.sort_order, d.id SEPARATOR '; ') FROM employee_education d WHERE d.employee_id = e.id)", 'long'],
        'school' => ['Name of school (first listed)', 'Education and work history', '(SELECT d.school FROM employee_education d WHERE d.employee_id = e.id ORDER BY d.sort_order, d.id LIMIT 1)', 'text'],
        'course' => ['Course (first listed)', 'Education and work history', '(SELECT d.course FROM employee_education d WHERE d.employee_id = e.id ORDER BY d.sort_order, d.id LIMIT 1)', 'text'],
        'year_graduated' => ['Year graduated (first listed)', 'Education and work history', '(SELECT d.year_graduated FROM employee_education d WHERE d.employee_id = e.id ORDER BY d.sort_order, d.id LIMIT 1)', 'text'],
        'prev_employment' => ['Employment history (previous employers)', 'Education and work history', "(SELECT GROUP_CONCAT(CONCAT(p.company, IF(COALESCE(p.position, '') <> '', CONCAT(', ', p.position), ''), IF(p.date_from IS NULL AND p.date_to IS NULL, '', CONCAT(' (', COALESCE(DATE_FORMAT(p.date_from, '%Y'), '?'), '-', COALESCE(DATE_FORMAT(p.date_to, '%Y'), '?'), ')'))) ORDER BY p.sort_order, p.id SEPARATOR '; ') FROM employee_prev_employment p WHERE p.employee_id = e.id)", 'long'],
        // government numbers
        'sss_no' => ['SSS no.', 'Government numbers', 'e.sss_no', 'text'],
        'philhealth_no' => ['PhilHealth no.', 'Government numbers', 'e.philhealth_no', 'text'],
        'pagibig_no' => ['Pag-IBIG no.', 'Government numbers', 'e.pagibig_no', 'text'],
        'tin' => ['TIN', 'Government numbers', 'e.tin', 'text'],
        'pagibig_voluntary' => ['Pag-IBIG voluntary', 'Government numbers', 'e.pagibig_voluntary', 'money'],
        // employment
        'date_hired' => ['Date Hired', 'Employment', 'e.date_hired', 'date'],
        'date_regularized' => ['Date regularized', 'Employment', 'e.date_regularized', 'date'],
        'years_service' => ['Years of service', 'Employment', 'ROUND(TIMESTAMPDIFF(MONTH, e.date_hired, COALESCE(e.date_separated, CURDATE())) / 12, 1)', 'num'],
        'employment_status' => ['Employment status', 'Employment', 'e.employment_status', 'enum'],
        'position' => ['Position', 'Employment', 'e.position', 'text'],
        'job' => ['Job', 'Employment', 'jb.name', 'text'],
        'rank_level' => ['Rank / level', 'Employment', 'e.rank_level', 'text'],
        'rank_category' => ['Rank category', 'Employment', 'rc.name', 'text'],
        'classification' => ['Classification', 'Employment', 'cl.name', 'text'],
        'company' => ['Company', 'Employment', 'COALESCE(b.company_name, b.name)', 'text'],
        'branch' => ['Company / branch code', 'Employment', 'COALESCE(b.branch_code, b.code)', 'text'],
        'branch_name' => ['Branch', 'Employment', 'b.name', 'text'],
        'location' => ['Location', 'Employment', 'lc.name', 'text'],
        'org_unit' => ['Organizational unit', 'Employment', 'ou.name', 'text'],
        'division' => ['Division', 'Employment', null, 'text'],
        'department' => ['Department', 'Employment', null, 'text'],
        'org_path' => ['Organization (full path)', 'Employment', null, 'long'],
        'employee_type' => ['Employee type', 'Employment', 'et.name', 'text'],
        'employee_group' => ['Employee group', 'Employment', 'eg.name', 'text'],
        'class_group' => ['Class group', 'Employment', 'cg.name', 'text'],
        'supervisor' => ['Immediate supervisor', 'Employment', "CONCAT(sv.last_name, ', ', sv.first_name)", 'text'],
        'status' => ['Status', 'Employment', 'e.status', 'enum'],
        'date_separated' => ['Date separated', 'Employment', 'e.date_separated', 'date'],
        'separation_type' => ['Separation type', 'Employment', 'e.separation_type', 'enum'],
        'separation_reason' => ['Separation reason', 'Employment', 'e.separation_reason', 'long'],
        // compensation
        'basic_rate' => ['Basic Salary', 'Compensation', 'e.basic_rate', 'money'],
        'rate_type' => ['Rate type', 'Compensation', 'e.rate_type', 'enum'],
        'monthly_rate' => ['Monthly rate (equivalent)', 'Compensation', null, 'money'],
        'daily_rate' => ['Daily rate', 'Compensation', null, 'money'],
        'hourly_rate' => ['Hourly rate', 'Compensation', null, 'money'],
        'salary_grade' => ['Salary grade', 'Compensation', 'sg.code', 'text'],
        'salary_step' => ['Salary step', 'Compensation', 'e.salary_step', 'int'],
        'days_per_year' => ['Days-per-year factor', 'Compensation', 'e.days_per_year', 'int'],
        'pay_group' => ['Pay code / group', 'Compensation', "CONCAT(pg.code, ' — ', pg.name)", 'text'],
        'exemption_code' => ['Tax exemption code', 'Compensation', 'ex.code', 'text'],
        'is_mwe' => ['Minimum wage earner', 'Compensation', 'e.is_mwe', 'bool'],
        'bank_name' => ['Bank', 'Compensation', 'e.bank_name', 'text'],
        'bank_account_no' => ['Bank account no.', 'Compensation', 'e.bank_account_no', 'text'],
        'bank_account_type' => ['Bank account type', 'Compensation', 'e.bank_account_type', 'enum'],
        // attendance and other
        'shift' => ['Shift', 'Attendance and other', "CONCAT(sh.code, ' — ', sh.name)", 'text'],
        'day_off' => ['Default day-off', 'Attendance and other', 'e.day_off', 'dayoff'],
        'attendance_method' => ['Time capture method', 'Attendance and other', 'e.attendance_method', 'enum'],
        'timekeeping_exempt' => ['Exempt from time-keeping', 'Attendance and other', 'e.timekeeping_exempt', 'bool'],
        'is_solo_parent' => ['Solo parent', 'Attendance and other', 'e.is_solo_parent', 'bool'],
        'solo_parent_id_no' => ['Solo parent ID no.', 'Attendance and other', 'e.solo_parent_id_no', 'text'],
        'created_at' => ['Record created', 'Attendance and other', 'e.created_at', 'date'],
        'updated_at' => ['Record last changed', 'Attendance and other', 'e.updated_at', 'date'],
    ];
    if (function_exists('salary_can') && current_user() && !salary_can('view')) {
        // no Basic Salary view right: the salary columns are not offered (nor printed, nor exported)
        unset($f['basic_rate'], $f['monthly_rate'], $f['daily_rate'], $f['hourly_rate']);
    }
    return $f;
}

/** Group by options: key => [label, field key]. */
const ML_GROUPS = ['' => ['No grouping', null], 'company' => ['Company', 'company'], 'branch' => ['Company / branch', 'branch_name'], 'department' => ['Department', 'department'],
    'org_unit' => ['Organizational unit', 'org_unit'], 'employment_status' => ['Employment status', 'employment_status'], 'employee_type' => ['Employee type', 'employee_type'],
    'employee_group' => ['Employee group', 'employee_group'], 'position' => ['Position', 'position']];

/** Clean a requested column list: known keys only, no duplicates, the default when empty. */
function ml_columns($cols): array
{
    if (is_string($cols)) {
        $cols = explode(',', $cols);
    }
    $out = [];
    foreach ((array)$cols as $c) {
        $c = trim((string)$c);
        if (isset(ml_fields()[$c]) && !in_array($c, $out, true)) {
            $out[] = $c;
        }
    }
    return $out ?: array_values(array_filter(ML_DEFAULT_COLUMNS, fn($c) => isset(ml_fields()[$c])));
}

/** Filters from the request. */
function ml_filters(array $src): array
{
    $d = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($src[$k] ?? '')) ? (string)$src[$k] : null;
    return [
        'branch_id' => (int)($src['branch_id'] ?? 0) ?: null,
        'org_unit_id' => (int)($src['org_unit_id'] ?? 0) ?: null,
        'employee_type_id' => (int)($src['employee_type_id'] ?? 0) ?: null,
        'employee_group_id' => (int)($src['employee_group_id'] ?? 0) ?: null,
        'class_group_id' => (int)($src['class_group_id'] ?? 0) ?: null,
        'employment_status' => isset(EMPLOYMENT_STATUSES[$src['employment_status'] ?? '']) ? $src['employment_status'] : null,
        'status' => in_array($src['status'] ?? 'active', ['active', 'separated', 'all'], true) ? (string)($src['status'] ?? 'active') : 'active',
        'hired_from' => $d('hired_from'),
        'hired_to' => $d('hired_to'),
        'q' => mb_substr(trim((string)($src['q'] ?? '')), 0, 60),
    ];
}

/** Ancestor of a unit with a given type (Division, Department), else ''. */
function ml_org_of_type(?int $unitId, string $type): string
{
    if (!$unitId) {
        return '';
    }
    $all = org_units_all();
    $id = $unitId;
    for ($g = 0; $id && isset($all[$id]) && $g < 20; $g++) {
        if (strcasecmp((string)$all[$id]['unit_type'], $type) === 0) {
            return (string)$all[$id]['name'];
        }
        $id = $all[$id]['parent_id'] ? (int)$all[$id]['parent_id'] : null;
    }
    return '';
}

/** The employees with the chosen columns: [rows (key => raw value), count]. */
function ml_rows(array $cols, array $f, string $sort = 'name', string $dir = 'asc', string $group = ''): array
{
    $fields = ml_fields();
    $need = $cols;
    if (!in_array($sort, $need, true) && isset($fields[$sort])) {
        $need[] = $sort;
    }
    if ($group && ($gk = ML_GROUPS[$group][1] ?? null) && !in_array($gk, $need, true)) {
        $need[] = $gk;
    }
    $sel = ['e.id AS __id', 'e.org_unit_id AS __unit', 'e.rate_type AS __rate_type', 'e.basic_rate AS __basic', 'e.employee_type_id AS __type', 'e.days_per_year AS __dpy', 'e.last_name AS __ln', 'e.first_name AS __fn'];
    foreach ($need as $k) {
        if ($fields[$k][2] !== null) {
            $sel[] = $fields[$k][2] . ' AS `' . $k . '`';
        }
    }
    $w = ['1=1'];
    $p = [];
    if ($f['status'] !== 'all') {
        $w[] = 'e.status = ?';
        $p[] = $f['status'];
    }
    foreach (['branch_id' => 'e.default_branch_id', 'employee_type_id' => 'e.employee_type_id', 'employee_group_id' => 'e.employee_group_id', 'class_group_id' => 'e.class_group_id', 'employment_status' => 'e.employment_status'] as $k => $col) {
        if ($f[$k]) {
            $w[] = "$col = ?";
            $p[] = $f[$k];
        }
    }
    if ($f['org_unit_id']) {
        $w[] = 'e.org_unit_id IN (' . implode(',', array_map('intval', org_descendant_ids((int)$f['org_unit_id']))) . ')';
    }
    if ($f['hired_from']) {
        $w[] = 'e.date_hired >= ?';
        $p[] = $f['hired_from'];
    }
    if ($f['hired_to']) {
        $w[] = 'e.date_hired <= ?';
        $p[] = $f['hired_to'];
    }
    if ($f['q'] !== '') {
        $w[] = '(e.employee_no LIKE ? OR e.last_name LIKE ? OR e.first_name LIKE ? OR e.position LIKE ?)';
        $like = '%' . $f['q'] . '%';
        array_push($p, $like, $like, $like, $like);
    }
    try {
        q('SET SESSION group_concat_max_len = 8192');
    } catch (Throwable $e) {
    }
    $sql = 'SELECT ' . implode(', ', $sel) . ' FROM employees e
            LEFT JOIN branches b ON b.id = e.default_branch_id
            LEFT JOIN org_units ou ON ou.id = e.org_unit_id
            LEFT JOIN salary_grades sg ON sg.id = e.salary_grade_id
            LEFT JOIN pay_groups pg ON pg.id = e.pay_group_id
            LEFT JOIN shifts sh ON sh.id = e.shift_id
            LEFT JOIN employees sv ON sv.id = e.supervisor_id
            LEFT JOIN emp_types et ON et.id = e.employee_type_id
            LEFT JOIN emp_groups eg ON eg.id = e.employee_group_id
            LEFT JOIN class_groups cg ON cg.id = e.class_group_id
            LEFT JOIN rank_categories rc ON rc.id = e.rank_category_id
            LEFT JOIN classifications cl ON cl.id = e.classification_id
            LEFT JOIN jobs jb ON jb.id = e.job_id
            LEFT JOIN exemption_codes ex ON ex.id = e.exemption_code_id
            LEFT JOIN locations lc ON lc.id = e.location_id
            WHERE ' . implode(' AND ', $w) . user_scope_sql('e') . ' ORDER BY e.last_name, e.first_name, e.employee_no';
    $rows = rows($sql, $p);
    foreach ($rows as &$r) {
        if (array_intersect(['division', 'department', 'org_path'], $need)) {
            $r['division'] = ml_org_of_type($r['__unit'] ? (int)$r['__unit'] : null, 'Division');
            $r['department'] = ml_org_of_type($r['__unit'] ? (int)$r['__unit'] : null, 'Department');
            $r['org_path'] = org_path($r['__unit'] ? (int)$r['__unit'] : null);
        }
        if (array_intersect(['monthly_rate', 'daily_rate', 'hourly_rate'], $need)) {
            $rt = employee_rates(['rate_type' => $r['__rate_type'], 'basic_rate' => $r['__basic'], 'employee_type_id' => $r['__type'], 'days_per_year' => $r['__dpy']]);
            $r['monthly_rate'] = $rt['monthly'];
            $r['daily_rate'] = round($rt['daily'], 2);
            $r['hourly_rate'] = round($rt['hourly'], 2);
        }
    }
    unset($r);
    // sorting (group first, then the chosen column, then the name)
    $type = $fields[$sort][3] ?? 'text';
    $gk = $group ? (ML_GROUPS[$group][1] ?? null) : null;
    usort($rows, function ($a, $b) use ($sort, $dir, $type, $gk) {
        if ($gk && ($c = strnatcasecmp((string)($a[$gk] ?? ''), (string)($b[$gk] ?? ''))) !== 0) {
            return $c;
        }
        $x = $a[$sort] ?? null;
        $y = $b[$sort] ?? null;
        if (in_array($type, ['money', 'num', 'int', 'bool'], true)) {
            $c = ((float)$x <=> (float)$y);
        } else {
            $c = strnatcasecmp((string)$x, (string)$y);
        }
        if ($c === 0) {
            $c = strnatcasecmp($a['__ln'] . ' ' . $a['__fn'], $b['__ln'] . ' ' . $b['__fn']);
        }
        return $dir === 'desc' ? -$c : $c;
    });
    return $rows;
}

/** A value in words for the screen, print and CSV. */
function ml_value($v, string $type, bool $csv = false): string
{
    if ($v === null || $v === '') {
        return '';
    }
    switch ($type) {
        case 'date':
            return $csv ? substr((string)$v, 0, 10) : fmt_date(substr((string)$v, 0, 10));
        case 'money':
            return $csv ? number_format((float)$v, 2, '.', '') : number_format((float)$v, 2);
        case 'num':
            return rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.');
        case 'int':
            return (string)(int)$v;
        case 'bool':
            return (int)$v ? 'Yes' : 'No';
        case 'enum':
            return ucwords(str_replace('_', ' ', (string)$v));
        case 'dayoff':
            return day_off_label((string)$v);
        default:
            return (string)$v;
    }
}

/** Saved layouts of the report. */
function ml_layouts(): array
{
    try {
        return rows('SELECT * FROM report_layouts WHERE report_key = ? ORDER BY is_default DESC, name', [ML_REPORT_KEY]);
    } catch (Throwable $e) {
        return [];
    }
}
