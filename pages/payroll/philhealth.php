<?php
crud_page([
    'page' => 'philhealth', 'import' => 'philhealth', 'table' => 'philhealth_params', 'title' => 'PhilHealth Premium Table', 'singular' => 'premium schedule', 'module' => 'payroll',
    'subtitle' => 'Universal Health Care Act (RA 11223): premium = rate × monthly basic salary (bounded by the floor and ceiling), shared between employee and employer. Add a row with a new effectivity date for scheduled rate changes.',
    'order' => 'effective_from DESC',
    'fields' => [
        'effective_from' => ['label' => 'Effective from', 'type' => 'date', 'required' => true],
        'rate_pct' => ['label' => 'Premium rate (%)', 'type' => 'number', 'required' => true],
        'floor_salary' => ['label' => 'Salary floor', 'type' => 'money', 'required' => true],
        'ceiling_salary' => ['label' => 'Salary ceiling', 'type' => 'money', 'required' => true],
        'ee_share_pct' => ['label' => 'Employee share (% of premium)', 'type' => 'number', 'required' => true],
        'legal_basis' => ['label' => 'Legal basis', 'wide' => true],
    ],
    'bottom_html' => function () {
        $amt = (float)get('test', 0);
        $out = '<div class="card"><h3>Test</h3><form class="filters" method="get" style="padding:0"><input type="hidden" name="p" value="philhealth"><label>Monthly basic salary<input type="number" step="0.01" name="test" value="' . h($amt ?: '') . '"></label><button class="btn">Compute</button></form>';
        if ($amt > 0) {
            $c = philhealth_contribution($amt);
            $out .= '<p style="margin-top:10px">Premium base ' . peso($c['base']) . ' → total ' . peso($c['premium']) . ' (employee ' . peso($c['ee']) . ', employer ' . peso($c['er']) . ') per month.</p>';
        }
        return $out . '</div>';
    },
]);
