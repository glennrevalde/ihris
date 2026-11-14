<?php
/**
 * Benefits Table: allowances given every payroll at a fixed amount or per day / hour / week of duty.
 * v34: Given at "Per Count" pays the amount x a count encoded in the Payroll Transaction (Meal compensation,
 * Meal compensation OT): amount 100 and a count of 11 = 1,100. Such a benefit is paid to every employee with
 * that count (the amount of an Employee Benefits enrollment, when there is one, replaces the default amount).
 */
$countOn = mig_column_exists('benefit_types', 'count_field');
$gl = pairs("SELECT code, CONCAT(code, ' — ', name) FROM gl_accounts WHERE is_active = 1 ORDER BY code");
crud_page([
    'page' => 'benefit_types', 'import' => 'benefit_types', 'table' => 'benefit_types', 'title' => 'Benefits Table', 'singular' => 'benefit', 'module' => 'payroll',
    'subtitle' => 'Allowances and benefits. "Given at" pays a fixed amount per pay, an amount per day / hour / week of duty (-Hol = paid holidays count as duty) or an amount per count of the Payroll Transaction (Per Count). De minimis benefits are non-taxable up to BIR ceilings; the excess counts toward the ₱90,000 13th-month & other-benefits exemption.',
    'order' => 'code', 'actions' => ' <a class="btn" href="' . url('emp_benefits') . '">Employee benefits</a> <a class="btn" href="' . url('payroll_setup') . '">All setup tables</a>',
    'fields' => [
        'code' => ['label' => 'Code', 'required' => true],
        'name' => ['label' => 'Benefit description', 'required' => true],
        'gl_code' => ['label' => 'GL account', 'type' => 'select', 'options' => $gl, 'quick' => 'gl_accounts'],
        'default_amount' => ['label' => 'Amount', 'type' => 'money', 'default' => 0, 'help' => 'Default amount per pay (Fixed), per day / hour / week of duty, or per count (Per Count)'],
        'frequency' => ['label' => 'Process mode', 'type' => 'select', 'required' => true, 'options' => ['every_payroll' => 'Every Pay', 'first_cutoff' => '1st Pay', 'second_cutoff' => '2nd Pay', 'monthly_last' => 'Monthly (last pay)']],
        'given_at' => ['label' => 'Given at', 'type' => 'select', 'required' => true, 'options' => BENEFIT_GIVEN_AT, 'attrs' => 'data-count-toggle'],
    ] + ($countOn ? [
        'count_field' => ['label' => 'Count from (Payroll Transaction)', 'type' => 'select', 'options' => BENEFIT_COUNT_FIELDS, 'attrs' => 'data-count-field',
            'help' => 'Per Count only: the count encoded in Payroll Transaction Setup / Batch Transaction. Amount x count = the benefit (100 x 11 = 1,100).'],
    ] : []) + [
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
    // v34: Per Count needs the count it is paid on; a single active benefit per count
    'validate' => function (array $d, int $id) use ($countOn): array {
        if (!$countOn || ($d['given_at'] ?? '') !== 'count') {
            return [];
        }
        if (empty($d['count_field']) || !isset(BENEFIT_COUNT_FIELDS[$d['count_field']])) {
            return ['Given at Per Count: choose the count of the Payroll Transaction it is paid on (Count from).'];
        }
        $other = !empty($d['is_active']) ? row("SELECT code FROM benefit_types WHERE given_at = 'count' AND count_field = ? AND is_active = 1 AND id <> ?", [$d['count_field'], $id]) : null;
        return $other ? ['The active benefit ' . $other['code'] . ' is already paid per ' . BENEFIT_COUNT_FIELDS[$d['count_field']] . '. Deactivate it first, or choose another count.'] : [];
    },
    'after_save' => function (int $id, array $d) use ($countOn): void {
        if ($countOn) {
            q("UPDATE benefit_types SET count_field = NULL WHERE id = ? AND given_at <> 'count'", [$id]);
        }
    },
    'format' => [
        'tax_treatment' => fn($v) => ['taxable' => '<span class="badge badge-amber">Tax</span>', 'de_minimis' => '<span class="badge badge-green">De Mi</span>',
            'other_benefit' => '<span class="badge badge-blue">90K</span>', 'non_taxable' => '<span class="badge badge-gray">Non-tax</span>'][$v] ?? h($v),
        'no_deduction' => fn($v) => $v ? '✓' : '', 'ref_ext' => fn($v) => $v ? '✓' : '',
        'given_at' => fn($v, $r) => h(BENEFIT_GIVEN_AT[$v] ?? $v) . ($v === 'count' && !empty($r['count_field']) ? '<br><small class="muted">x ' . h(BENEFIT_COUNT_FIELDS[$r['count_field']] ?? $r['count_field']) . '</small>' : ''),
    ],
]);
