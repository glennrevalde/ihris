<?php
crud_page([
    'page' => 'tax_table', 'table' => 'tax_brackets', 'title' => 'BIR Withholding Tax Table', 'singular' => 'tax bracket', 'module' => 'payroll',
    'subtitle' => 'Revised withholding tax tables under the TRAIN Law (RA 10963), effective January 1, 2023 onwards. Tax = fixed tax + rate × (compensation − bracket floor). Add brackets with a new effectivity date when the BIR issues a new revenue regulation — no code change needed.',
    'order' => "FIELD(frequency,'daily','weekly','semi_monthly','monthly','annual'), effective_from DESC, bracket_no",
    'filters' => ['frequency' => ['label' => 'Table', 'options' => ['daily' => 'Daily', 'weekly' => 'Weekly', 'semi_monthly' => 'Semi-monthly', 'monthly' => 'Monthly', 'annual' => 'Annual (year-end / BIR 2316)'], 'default' => 'semi_monthly']],
    'fields' => [
        'frequency' => ['label' => 'Frequency', 'type' => 'select', 'required' => true, 'options' => ['daily' => 'Daily', 'weekly' => 'Weekly', 'semi_monthly' => 'Semi-monthly', 'monthly' => 'Monthly', 'annual' => 'Annual']],
        'bracket_no' => ['label' => 'Bracket', 'type' => 'number', 'required' => true],
        'comp_from' => ['label' => 'Compensation from (floor)', 'type' => 'money', 'required' => true],
        'comp_to' => ['label' => 'Compensation to', 'type' => 'money', 'help' => 'Blank = and above'],
        'fixed_tax' => ['label' => 'Fixed tax', 'type' => 'money', 'required' => true],
        'rate_pct' => ['label' => 'Rate on excess (%)', 'type' => 'number', 'required' => true],
        'effective_from' => ['label' => 'Effective from', 'type' => 'date', 'required' => true],
    ],
    'format' => ['comp_to' => fn($v) => $v === null ? '<span class="muted">and above</span>' : money($v), 'rate_pct' => fn($v) => h((float)$v) . '%'],
    'bottom_html' => function () {
        $f = get('frequency', 'semi_monthly');
        $amt = (float)get('test', 0);
        $out = '<div class="card"><h3>Test the table</h3><form class="filters" method="get" style="padding:0"><input type="hidden" name="p" value="tax_table"><input type="hidden" name="frequency" value="' . h($f) . '">'
            . '<label>Taxable compensation (' . h(label($f)) . ')<input type="number" step="0.01" name="test" value="' . h($amt ?: '') . '"></label><button class="btn">Compute</button></form>';
        if ($amt > 0) {
            $out .= '<p style="margin-top:10px">Withholding tax: <strong>' . peso(compute_withholding_tax($amt, $f)) . '</strong></p>';
        }
        return $out . '</div>';
    },
]);
