<?php
/** Benefits Table: allowances given every payroll at a fixed amount or per day / hour / week of duty. */
$gl = pairs("SELECT code, CONCAT(code, ' — ', name) FROM gl_accounts WHERE is_active = 1 ORDER BY code");
crud_page([
    'page' => 'benefit_types', 'import' => 'benefit_types', 'table' => 'benefit_types', 'title' => 'Benefits Table', 'singular' => 'benefit', 'module' => 'payroll',
    'subtitle' => 'Allowances and benefits. "Given at" pays a fixed amount per pay or an amount per day / hour / week of duty (-Hol = paid holidays count as duty). De minimis benefits are non-taxable up to BIR ceilings; the excess counts toward the ₱90,000 13th-month & other-benefits exemption.',
    'order' => 'code', 'actions' => ' <a class="btn" href="' . url('emp_benefits') . '">Employee benefits</a> <a class="btn" href="' . url('payroll_setup') . '">All setup tables</a>',
    'fields' => [
        'code' => ['label' => 'Code', 'required' => true],
        'name' => ['label' => 'Benefit description', 'required' => true],
        'gl_code' => ['label' => 'GL account', 'type' => 'select', 'options' => $gl, 'quick' => 'gl_accounts'],
        'default_amount' => ['label' => 'Amount', 'type' => 'money', 'default' => 0, 'help' => 'Default amount per pay (Fixed) or per day / hour / week of duty'],
        'frequency' => ['label' => 'Process mode', 'type' => 'select', 'required' => true, 'options' => ['every_payroll' => 'Every Pay', 'first_cutoff' => '1st Pay', 'second_cutoff' => '2nd Pay', 'monthly_last' => 'Monthly (last pay)']],
        'given_at' => ['label' => 'Given at', 'type' => 'select', 'required' => true, 'options' => BENEFIT_GIVEN_AT],
        'category' => ['label' => 'Category', 'type' => 'select', 'required' => true, 'options' => ['de_minimis' => 'De minimis', 'company' => 'Company benefit', 'statutory' => 'Statutory']],
        'tax_treatment' => ['label' => 'Tax treatment', 'type' => 'select', 'required' => true, 'options' => ['taxable' => 'Tax — taxable compensation', 'de_minimis' => 'De Mi — de minimis (non-taxable up to ceiling)', 'other_benefit' => 'Other benefit (₱90K exemption)', 'non_taxable' => 'Non-taxable']],
        'ceiling_amount' => ['label' => 'Non-taxable ceiling', 'type' => 'money'],
        'ceiling_period' => ['label' => 'Ceiling period', 'type' => 'select', 'options' => ['monthly' => 'Per month', 'annual' => 'Per year', 'none' => 'None'], 'required' => true],
        'no_deduction' => ['label' => 'No Ded — not reduced by absences (fixed benefits)', 'type' => 'checkbox'],
        'ref_ext' => ['label' => 'Ref Ext — reference only (shown, not added to pay)', 'type' => 'checkbox'],
        'bir2316_box' => ['label' => 'BIR 2316 box / item'],
        'eligibility_regular_only' => ['label' => 'Eligibility: regular employees only', 'type' => 'checkbox'],
        'eligibility_min_months' => ['label' => 'Eligibility: minimum tenure (months)', 'type' => 'number', 'default' => 0],
        'legal_basis' => ['label' => 'Legal basis / policy', 'wide' => true],
        'is_active' => ['label' => 'Active', 'type' => 'checkbox'],
    ],
    'defaults' => ['is_active' => 1, 'category' => 'company', 'tax_treatment' => 'taxable', 'ceiling_period' => 'none', 'frequency' => 'every_payroll', 'given_at' => 'fixed', 'no_deduction' => 1],
    'list' => ['code', 'name', 'gl_code', 'default_amount', 'frequency', 'tax_treatment', 'no_deduction', 'ref_ext', 'given_at', 'bir2316_box', 'is_active'],
    'format' => [
        'tax_treatment' => fn($v) => ['taxable' => '<span class="badge badge-amber">Tax</span>', 'de_minimis' => '<span class="badge badge-green">De Mi</span>',
            'other_benefit' => '<span class="badge badge-blue">90K</span>', 'non_taxable' => '<span class="badge badge-gray">Non-tax</span>'][$v] ?? h($v),
        'no_deduction' => fn($v) => $v ? '✓' : '', 'ref_ext' => fn($v) => $v ? '✓' : '',
    ],
]);
