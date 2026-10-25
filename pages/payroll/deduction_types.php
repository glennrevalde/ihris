<?php
crud_page([
    'page' => 'deduction_types', 'import' => 'deduction_types', 'table' => 'deduction_types', 'title' => 'Employee Transactions Table Setup', 'singular' => 'deduction code', 'module' => 'payroll',
    'top_html' => tab_links(['earning_types' => 'Earnings', 'deduction_types' => 'Deductions'], 'deduction_types'),
    'actions' => ' <a class="btn" href="' . url('emp_deductions') . '">Employee deductions</a> <a class="btn" href="' . url('payroll_setup') . '">All setup tables</a>',
    'subtitle' => 'Unlimited configurable deductions with their own computation rule, frequency and posting behavior. Types not authorized by law require the employee\'s written consent (Labor Code Art. 113).',
    'order' => 'priority, id',
    'fields' => [
        'code' => ['label' => 'Code', 'required' => true],
        'name' => ['label' => 'Name', 'required' => true],
        'category' => ['label' => 'Category', 'type' => 'select', 'required' => true, 'options' => ['statutory' => 'Statutory (system-computed)', 'loan' => 'Loan amortization', 'recurring' => 'Company recurring', 'adhoc' => 'Ad hoc / one-time', 'accountability' => 'Loss / damage accountability']],
        'computation' => ['label' => 'Computation', 'type' => 'select', 'required' => true, 'options' => ['fixed' => 'Fixed amount per payroll', 'percent_basic' => '% of basic pay', 'ledger' => 'Amortize a subsidiary ledger']],
        'default_amount' => ['label' => 'Default amount', 'type' => 'money', 'default' => 0],
        'frequency' => ['label' => 'Frequency', 'type' => 'select', 'required' => true, 'options' => ['every_payroll' => 'Every payroll', 'first_cutoff' => 'First cutoff of the month', 'second_cutoff' => 'Second / last cutoff of the month', 'monthly_last' => 'Once a month (last cutoff)']],
        'authorized_by_law' => ['label' => 'Authorized by law / regulation (no consent needed)', 'type' => 'checkbox'],
        'requires_approval' => ['label' => 'Requires approval before posting', 'type' => 'checkbox'],
        'priority' => ['label' => 'Deduction priority (lower first)', 'type' => 'number', 'default' => 50],
        'int_rate' => ['label' => 'Int. rate (%)', 'type' => 'number', 'step' => '0.01', 'default' => 0, 'help' => 'Default interest of the loans of this code (% a month). Also the interest of the Loan / Deduction Requests of the employee portal; 0 = no interest.'],
        'gl_code' => ['label' => 'GL code', 'type' => 'select', 'options' => pairs("SELECT code, CONCAT(code, ' — ', name) FROM gl_accounts WHERE is_active = 1 ORDER BY code"), 'quick' => 'gl_accounts'],
        'register_group' => ['label' => 'Payroll register column', 'type' => 'select', 'required' => true, 'options' => ['sss_loan' => 'SSS Loans', 'hdmf_loan' => 'HDMF Loans', 'company_loan' => 'Adv./AR/Sal Loans', 'other' => 'Others']],
        'bir2316_box' => ['label' => 'BIR 2316 box / item'],
        'ref_ext' => ['label' => 'Ref Ext — reference only (shown, not deducted)', 'type' => 'checkbox'],
        'is_active' => ['label' => 'Active', 'type' => 'checkbox'],
    ] + (mig_column_exists('deduction_types', 'ess_request') ? [
        // v20: the codes an employee may apply for in My Workspace > Loan / Deduction Request
        'ess_request' => ['label' => 'Portal request', 'type' => 'checkbox', 'help' => 'Offered in My Workspace > Loan / Deduction Request: the employee applies, the superior or HR approves, and the approved amount becomes a transaction with terms.'],
    ] : []),
    'defaults' => ['is_active' => 1, 'category' => 'recurring', 'computation' => 'fixed', 'frequency' => 'every_payroll', 'priority' => 50, 'register_group' => 'other'],
    'list' => array_merge(['code', 'name', 'category', 'int_rate', 'gl_code', 'computation', 'frequency', 'register_group', 'authorized_by_law', 'priority'],
        mig_column_exists('deduction_types', 'ess_request') ? ['ess_request'] : [], ['is_active']),
    'format' => ['name' => fn($v, $r) => h($v) . ($r['is_system'] ? ' <span class="badge badge-purple">System</span>' : '')],
    'before_delete' => function ($id, $old) {
        if ($old && $old['is_system']) {
            throw new RuntimeException('System deduction types cannot be deleted.');
        }
    },
]);
