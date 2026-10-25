<?php
/** Pay Code Table: pay frequency plus the default deduction mode of each statutory deduction and the default tax table. */
$modes = ['every' => 'Every Pay', 'first' => '1st Pay', 'second' => '2nd Pay'];
crud_page([
    'page' => 'pay_groups', 'table' => 'pay_groups', 'title' => 'Pay Code Table', 'singular' => 'pay code', 'module' => 'payroll',
    'subtitle' => 'Pay codes (pay groups) with their pay frequency, the payroll-processing deduction mode of SSS, Pag-IBIG, PhilHealth and withholding tax, and the default tax table.',
    'actions' => ' <a class="btn" href="' . url('payroll_setup') . '">All setup tables</a>',
    'fields' => [
        'code' => ['label' => 'Code', 'required' => true],
        'name' => ['label' => 'Pay code description', 'required' => true],
        'frequency' => ['label' => 'Pay frequency', 'type' => 'select', 'required' => true, 'options' => ['daily' => 'Daily', 'weekly' => 'Weekly', 'semi_monthly' => 'Semi-monthly', 'monthly' => 'Monthly']],
        'h1' => ['label' => 'Payroll processing default deduction mode', 'type' => 'heading', 'help' => 'Every Pay = monthly amount split over the pays of the month; 1st / 2nd Pay = whole monthly amount on that pay.'],
        'sss_mode' => ['label' => 'SSS', 'type' => 'select', 'required' => true, 'options' => $modes],
        'hdmf_mode' => ['label' => 'Pag-IBIG', 'type' => 'select', 'required' => true, 'options' => $modes],
        'phic_mode' => ['label' => 'PhilHealth', 'type' => 'select', 'required' => true, 'options' => $modes],
        'tax_mode' => ['label' => 'Withholding tax', 'type' => 'select', 'required' => true, 'options' => $modes, 'help' => '1st / 2nd Pay: tax on the month-to-date taxable income using the default tax table.'],
        'tax_type' => ['label' => 'Default tax type (table)', 'type' => 'select', 'options' => ['monthly' => 'Monthly', 'semi_monthly' => 'Semi-Monthly', 'weekly' => 'Weekly', 'daily' => 'Daily'], 'placeholder' => 'Same as pay frequency'],
        'description' => ['label' => 'Description', 'wide' => true],
        'is_active' => ['label' => 'Active', 'type' => 'checkbox'],
    ],
    'defaults' => ['frequency' => 'semi_monthly', 'sss_mode' => 'every', 'hdmf_mode' => 'every', 'phic_mode' => 'every', 'tax_mode' => 'every', 'is_active' => 1],
    'list' => ['code', 'name', 'frequency', 'sss_mode', 'hdmf_mode', 'phic_mode', 'tax_mode', 'tax_type', 'is_active'],
    'format' => [
        'name' => fn($v, $r) => h($v) . ' <span class="badge badge-gray">' . (int)val("SELECT COUNT(*) FROM employees WHERE pay_group_id = ? AND status = 'active'", [$r['id']]) . ' emp.</span>',
        'tax_type' => fn($v, $r) => h(label($v ?: $r['frequency'])),
    ],
    'after_save' => function ($id, $d) {
        // keep the legacy single contribution mode in step (used by older reports)
        $m = $d['sss_mode'] === 'first' ? 'first' : ($d['sss_mode'] === 'second' ? 'last' : 'split');
        q('UPDATE pay_groups SET contrib_mode = ? WHERE id = ?', [$m, $id]);
    },
    'before_delete' => function ($id) {
        if ((int)val('SELECT COUNT(*) FROM employees WHERE pay_group_id = ?', [$id])) {
            throw new RuntimeException('This pay code is assigned to employees.');
        }
    },
]);
