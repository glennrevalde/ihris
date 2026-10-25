<?php
/** Employee Transactions Table Setup — Earnings tab (Deductions tab = deduction_types). */
$gl = pairs("SELECT code, CONCAT(code, ' — ', name) FROM gl_accounts WHERE is_active = 1 ORDER BY code");
crud_page([
    'page' => 'earning_types', 'import' => 'earning_types', 'table' => 'earning_types', 'title' => 'Employee Transactions Table Setup', 'singular' => 'earning code', 'module' => 'payroll',
    'subtitle' => 'Earning transaction codes for one-time or per-period employee transactions (Payroll System (PAY) › Employee\'s Transaction).',
    'order' => 'code', 'edit_roles' => ['payroll'],
    'top_html' => tab_links(['earning_types' => 'Earnings', 'deduction_types' => 'Deductions'], 'earning_types'),
    'actions' => ' <a class="btn" href="' . url('emp_transactions') . '">Employee\'s transactions</a> <a class="btn" href="' . url('payroll_setup') . '">All setup tables</a>',
    'fields' => [
        'code' => ['label' => 'Trans. code', 'required' => true],
        'name' => ['label' => 'Description', 'required' => true],
        'int_rate' => ['label' => 'Int. rate (%)', 'type' => 'number', 'step' => '0.01', 'default' => 0],
        'gl_code' => ['label' => 'GL code', 'type' => 'select', 'options' => $gl, 'quick' => 'gl_accounts'],
        'taxable' => ['label' => 'Tax — taxable compensation', 'type' => 'checkbox'],
        'tax_rate' => ['label' => 'Tax rate (%)', 'type' => 'number', 'step' => '0.01', 'default' => 0, 'help' => 'Separate withholding on this earning, e.g. 10% (0 = none)'],
        'special' => ['label' => 'Spc — special earning (non-taxable up to the special earnings ceiling)', 'type' => 'checkbox'],
        'de_minimis' => ['label' => 'De Mi — de minimis', 'type' => 'checkbox'],
        'bir2316_box' => ['label' => 'BIR 2316 box / item'],
        'ref_ext' => ['label' => 'Ref Ext — reference only (shown, not paid)', 'type' => 'checkbox'],
        'is_active' => ['label' => 'Act — active', 'type' => 'checkbox'],
    ],
    'defaults' => ['taxable' => 1, 'is_active' => 1, 'int_rate' => 0, 'tax_rate' => 0],
    'list' => ['code', 'name', 'int_rate', 'gl_code', 'taxable', 'tax_rate', 'special', 'de_minimis', 'bir2316_box', 'ref_ext', 'is_active'],
    'format' => ['taxable' => fn($v) => $v ? '✓' : '', 'special' => fn($v) => $v ? '✓' : '', 'de_minimis' => fn($v) => $v ? '✓' : '', 'ref_ext' => fn($v) => $v ? '✓' : '',
        'is_active' => fn($v) => $v ? '<span class="badge badge-green">Yes</span>' : '<span class="badge badge-gray">No</span>'],
    'before_delete' => function ($id) {
        if ((int)val('SELECT COUNT(*) FROM employee_transactions WHERE earning_type_id = ?', [$id])) {
            throw new RuntimeException('This code is used by employee transactions. Deactivate it instead.');
        }
    },
]);
