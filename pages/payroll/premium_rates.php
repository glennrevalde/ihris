<?php
crud_page([
    'page' => 'premium_rates', 'table' => 'premium_rates', 'title' => 'Overtime & Premium Pay Table', 'singular' => 'premium rate', 'module' => 'payroll',
    'subtitle' => 'Labor Code / DOLE premium rates applied automatically by day type. Overtime = hourly rate × day multiplier × OT multiplier. Night differential (' . h(setting('nd_rate_pct', 10)) . '%, Settings) applies on top of the applicable rate, so overlapping conditions combine correctly.',
    'order' => 'id', 'can_add' => false, 'can_delete' => false,
    'fields' => [
        'day_type' => ['label' => 'Day type', 'readonly' => true],
        'label' => ['label' => 'Description', 'required' => true],
        'work_multiplier' => ['label' => 'Rate for first 8 hours (× hourly)', 'type' => 'number', 'required' => true, 'step' => '0.01'],
        'ot_multiplier' => ['label' => 'Overtime multiplier (on top of day rate)', 'type' => 'number', 'required' => true, 'step' => '0.01'],
        'legal_basis' => ['label' => 'Legal basis', 'wide' => true],
    ],
    'list' => ['label', 'work_multiplier', 'ot_multiplier', 'legal_basis'],
    'format' => [
        'work_multiplier' => fn($v) => h(rtrim(rtrim(number_format($v * 100, 2), '0'), '.')) . '%',
        'ot_multiplier' => fn($v, $r) => h(rtrim(rtrim(number_format($v * 100, 2), '0'), '.')) . '% <span class="muted small">(OT = ' . h(rtrim(rtrim(number_format($v * $r['work_multiplier'] * 100, 2), '0'), '.')) . '% of hourly)</span>',
    ],
    'bottom_html' => '<div class="card"><h3>How the rates combine</h3><table class="table compact"><tbody>'
        . '<tr><td>Ordinary overtime</td><td>Hourly rate × 125%</td></tr><tr><td>Rest day / special day work</td><td>Hourly rate × 130%</td></tr>'
        . '<tr><td>Overtime on a rest day / special day</td><td>Hourly rate × 130% × 130% (= 169%)</td></tr><tr><td>Regular holiday work</td><td>Daily rate × 200%</td></tr>'
        . '<tr><td>Overtime on a regular holiday</td><td>Hourly rate × 200% × 130% (= 260%)</td></tr><tr><td>Night shift differential (10:00 PM – 6:00 AM)</td><td>+10% of the applicable hourly rate (e.g. OT on a rest day at night = 169% × 10% extra)</td></tr>'
        . '</tbody></table><p class="small muted">Monthly-rated employees are already paid for regular and special days falling on their workdays, so only the premium portion is added; rest days are paid in full at the multiplier.</p></div>',
]);
