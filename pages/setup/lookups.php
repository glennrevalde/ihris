<?php
/**
 * Company Parameters Setup reference tables (formerly "Emp. Setup"; M.A.D.A.S. payroll parameters forms).
 * One page file serves several routes; the route name selects the table definition.
 */
$route = (string)($_GET['p'] ?? '');
if ($route === 'gl_accounts') {
    quickref_handle('gl_accounts');   // New / Edit GL account posted from another form (JSON)
}
$glOld = '';

$inUse = function (string $col, string $what) {
    return function (int $id) use ($col, $what) {
        $n = (int)val("SELECT COUNT(*) FROM employees WHERE `$col` = ?", [$id]);
        if ($n) {
            throw new RuntimeException("This $what is assigned to $n employee(s). Reassign them or deactivate the $what instead.");
        }
    };
};
$empCount = fn(string $col) => fn($v, $r) => h($v) . ' <span class="badge badge-gray">' . (int)val("SELECT COUNT(*) FROM employees WHERE `$col` = ? AND status = 'active'", [$r['id']]) . ' emp.</span>';
$glOptions = pairs("SELECT code, CONCAT(code, ' — ', name) FROM gl_accounts WHERE is_active = 1 ORDER BY code");
$simple = function (string $page, string $table, string $title, string $singular, string $subtitle, ?string $empCol = null) use ($inUse, $empCount): array {
    return [
        'page' => $page, 'table' => $table, 'title' => $title, 'singular' => $singular, 'module' => 'setup', 'order' => 'code',
        'subtitle' => $subtitle, 'edit_roles' => ['payroll', 'hr'],
        'fields' => [
            'code' => ['label' => 'Code', 'required' => true],
            'name' => ['label' => 'Description', 'required' => true, 'wide' => true],
            'is_active' => ['label' => 'Active', 'type' => 'checkbox'],
        ],
        'defaults' => ['is_active' => 1],
        'before_delete' => $empCol ? $inUse($empCol, $singular) : null,
        'format' => $empCol ? ['name' => $empCount($empCol)] : [],
    ];
};

$defs = [
    'class_groups' => $simple('class_groups', 'class_groups', 'Class Group Table', 'class group', 'Employee class groupings used for filtering and reports.', 'class_group_id'),
    'rank_categories' => $simple('rank_categories', 'rank_categories', 'Rank Category Table', 'rank category', 'Rank categories (rank and file, supervisory, managerial...).', 'rank_category_id'),
    'classifications' => $simple('classifications', 'classifications', 'Classification Table', 'classification', 'Employee classifications (direct / indirect labor, confidential...).', 'classification_id'),
    'locations' => $simple('locations', 'locations', 'Location Table', 'location', 'Work locations used by companies / branches and employees.', 'location_id'),
    'exemption_codes' => array_replace_recursive($simple('exemption_codes', 'exemption_codes', 'Exemption Code Table', 'exemption code',
        'Tax exemption codes printed on the payslip. Codes marked tax-exempt (e.g. minimum wage earners) are not subject to withholding tax.', 'exemption_code_id'), [
        'fields' => ['tax_exempt' => ['label' => 'Tax exempt (no withholding)', 'type' => 'checkbox']],
    ]),
    'emp_types' => [
        'page' => 'emp_types', 'table' => 'emp_types', 'title' => 'Employee Type Table', 'singular' => 'employee type', 'module' => 'setup', 'order' => 'code',
        'subtitle' => 'Conversion factor from monthly to daily rate used in payroll computation: daily rate = monthly rate ÷ factor; hourly rate = daily rate ÷ hours per day.',
        'edit_roles' => ['payroll', 'hr'], 'set_default' => true,
        'fields' => [
            'code' => ['label' => 'Code', 'required' => true],
            'name' => ['label' => 'Employee type description', 'required' => true, 'wide' => true],
            'conversion_factor' => ['label' => 'Conversion factor (days per month)', 'type' => 'number', 'step' => '0.0001', 'required' => true, 'help' => 'e.g. 26 (6-day week), 21.75 (5-day week), 30 (paid every day)'],
            'hours_per_day' => ['label' => 'Hours per day', 'type' => 'number', 'step' => '0.01', 'required' => true],
            'is_active' => ['label' => 'Active', 'type' => 'checkbox'],
        ],
        'defaults' => ['conversion_factor' => 26, 'hours_per_day' => 8, 'is_active' => 1],
        'list' => ['code', 'name', 'conversion_factor', 'hours_per_day', 'is_active'],
        'before_delete' => $inUse('employee_type_id', 'employee type'),
        'format' => ['name' => $empCount('employee_type_id'), 'conversion_factor' => fn($v) => number_format((float)$v, 4)],
        'validate' => fn($d) => (float)$d['conversion_factor'] <= 0 ? ['The conversion factor must be greater than zero.'] : [],
    ],
    'emp_groups' => [
        'page' => 'emp_groups', 'table' => 'emp_groups', 'title' => 'Employee Group Table', 'singular' => 'employee group', 'module' => 'setup', 'order' => 'code',
        'subtitle' => 'Employee groups for batch attendance / payroll entry, registers and cost-center reporting.',
        'edit_roles' => ['payroll', 'hr'], 'set_default' => true,
        'fields' => [
            'code' => ['label' => 'Code', 'required' => true],
            'name' => ['label' => 'Employee group description', 'required' => true, 'wide' => true],
            'log_group' => ['label' => 'Log / Grp — attendance is logged by group', 'type' => 'checkbox'],
            'cost_center' => ['label' => 'Cost center'],
            // v16: Active Yes / No. "No" hides the group from the filters of the reports and the transaction forms
            'is_active' => ['label' => 'Active', 'type' => 'select', 'options' => ['1' => 'Yes', '0' => 'No'], 'required' => true,
                'help' => 'No = the employee group is no longer shown in the reports and transactions (filters and selections).'],
        ],
        'defaults' => ['is_active' => 1],
        'list' => ['code', 'name', 'log_group', 'cost_center', 'is_active'],
        'before_delete' => $inUse('employee_group_id', 'employee group'),
        'format' => ['name' => $empCount('employee_group_id'), 'log_group' => fn($v) => $v ? 'Y' : 'N',
            'is_active' => fn($v) => (int)$v ? '<span class="badge badge-green">Yes</span>' : '<span class="badge badge-gray">No</span> <small class="muted">hidden from reports and transactions</small>'],
    ],
    'jobs' => [
        'page' => 'jobs', 'table' => 'jobs', 'title' => 'Job Table', 'singular' => 'job', 'module' => 'setup', 'order' => 'code',
        'subtitle' => 'Job titles with category and sub-category. OT/OL marks jobs whose approved overtime is credited as offset leave instead of being paid.',
        'edit_roles' => ['payroll', 'hr'],
        'fields' => [
            'code' => ['label' => 'Code', 'required' => true],
            'name' => ['label' => 'Description', 'required' => true],
            'category' => ['label' => 'Category'],
            'sub_category' => ['label' => 'Sub-category'],
            'ot_offset' => ['label' => 'OT/OL — overtime earns offset leave (not paid)', 'type' => 'checkbox'],
            'is_active' => ['label' => 'Active', 'type' => 'checkbox'],
        ],
        'defaults' => ['is_active' => 1],
        'list' => ['code', 'name', 'category', 'sub_category', 'ot_offset', 'is_active'],
        'before_delete' => $inUse('job_id', 'job'),
        'format' => ['name' => $empCount('job_id')],
    ],
    'gl_accounts' => [
        'page' => 'gl_accounts', 'table' => 'gl_accounts', 'title' => 'Payroll Code Table (GL Accounts)', 'singular' => 'GL account', 'module' => 'setup', 'order' => 'code',
        'subtitle' => 'General-ledger account codes used by the transaction, benefit and fixed-transaction tables and by the payroll journal report.',
        'edit_roles' => ['payroll'],
        'fields' => [
            'code' => ['label' => 'Account code', 'required' => true],
            'name' => ['label' => 'Account title', 'required' => true, 'wide' => true],
            'account_type' => ['label' => 'Type', 'type' => 'select', 'required' => true, 'options' => GL_ACCOUNT_TYPES],
            'is_active' => ['label' => 'Active', 'type' => 'checkbox'],
        ],
        'defaults' => ['account_type' => 'expense', 'is_active' => 1],
        // same checks as the New / Edit GL account form of the other tables (app/engine/quickref.php)
        'validate' => function (array $d, int $id) use (&$glOld) {
            $glOld = $id ? (string)val('SELECT code FROM gl_accounts WHERE id = ?', [$id]) : '';
            return gl_account_validate($d, $id);
        },
        'after_save' => function (int $id, array $d) use (&$glOld) {
            if ($glOld !== '' && $glOld !== strtoupper(trim((string)$d['code']))) {
                gl_account_cascade($glOld, (string)val('SELECT code FROM gl_accounts WHERE id = ?', [$id]));
            }
        },
        'before_delete' => function (int $id) {
            $code = (string)val('SELECT code FROM gl_accounts WHERE id = ?', [$id]);
            $n = 0;
            foreach (gl_account_users() as $t) {
                $n += (int)val("SELECT COUNT(*) FROM `$t` WHERE gl_code = ?", [$code]);
            }
            if ($n) {
                throw new RuntimeException("GL account $code is used by $n transaction, deduction, benefit or fixed transaction code(s). Deactivate it instead.");
            }
        },
    ],
    'fixed_trans_codes' => [
        'page' => 'fixed_trans_codes', 'table' => 'fixed_trans_codes', 'title' => 'Fixed Transaction Code Setup', 'singular' => 'fixed transaction code', 'module' => 'setup', 'order' => 'code',
        'subtitle' => 'Codes for fixed, recurring amounts per employee (Payroll System (PAY) › Fixed Transaction Setup). A code with a statutory role replaces the computed SSS / Pag-IBIG / PhilHealth / tax with the fixed amount.',
        'edit_roles' => ['payroll'],
        'fields' => [
            'code' => ['label' => 'Code', 'required' => true],
            'name' => ['label' => 'Description', 'required' => true],
            'trans_type' => ['label' => 'Transaction type', 'type' => 'select', 'required' => true, 'options' => ['earning' => '01 - Earnings', 'deduction' => '02 - Deductions']],
            'role' => ['label' => 'Replaces statutory deduction', 'type' => 'select', 'required' => true, 'options' => ['none' => 'None (regular earning / deduction)', 'sss' => 'SSS contribution', 'hdmf' => 'Pag-IBIG contribution', 'phic' => 'PhilHealth contribution', 'tax' => 'Withholding tax']],
            'default_terms' => ['label' => 'Default terms', 'type' => 'select', 'required' => true, 'options' => FIXED_TERMS + FIXED_TERMS_LEGACY, 'help' => 'Every pay, or the 1st, 2nd, 3rd or 4th pay of the month. Monthly split is kept only for codes set up before.'],
            'gl_code' => ['label' => 'GL account', 'type' => 'select', 'options' => $glOptions, 'quick' => 'gl_accounts'],
            'taxable' => ['label' => 'Taxable (earnings)', 'type' => 'checkbox'],
            'de_minimis' => ['label' => 'De minimis (earnings)', 'type' => 'checkbox'],
            'bir2316_box' => ['label' => 'BIR 2316 box / item', 'help' => 'Optional reference for the 2316 report'],
            'is_active' => ['label' => 'Active', 'type' => 'checkbox'],
        ],
        'defaults' => ['trans_type' => 'earning', 'role' => 'none', 'default_terms' => 'every_pay', 'taxable' => 1, 'is_active' => 1],
        'list' => ['code', 'name', 'trans_type', 'role', 'default_terms', 'gl_code', 'de_minimis', 'bir2316_box', 'taxable', 'is_active'],
        'before_delete' => function (int $id) {
            if ((int)val('SELECT COUNT(*) FROM employee_fixed_trans WHERE code_id = ?', [$id])) {
                throw new RuntimeException('This code is used by employee fixed transactions. Deactivate it instead.');
            }
        },
        'validate' => fn($d) => ($d['role'] !== 'none' && $d['trans_type'] !== 'deduction') ? ['A code that replaces a statutory deduction must be a deduction.'] : [],
    ],
];

if (!isset($defs[$route])) {
    throw new RuntimeException('Unknown setup table.');
}
$cfg = $defs[$route];
if (in_array($route, ['locations', 'jobs', 'fixed_trans_codes'], true)) {
    $cfg['import'] = $route;   // CSV import facility on the form
}
if (empty($cfg['before_delete'])) {
    unset($cfg['before_delete']);
}
$cfg['actions'] = ($cfg['actions'] ?? '') . ' <a class="btn" href="' . url('payroll_setup') . '">All setup tables</a>';
crud_page($cfg);
