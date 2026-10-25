<?php
crud_page([
    'page' => 'late_brackets', 'import' => 'late_brackets', 'table' => 'late_brackets', 'title' => 'Lates / Undertime Table', 'singular' => 'bracket', 'module' => 'payroll',
    'subtitle' => 'Default deduction is per minute (hourly rate ÷ 60 × minutes late, "no work, no pay") after a ' . (int)setting('grace_minutes', 0) . '-minute grace period. Set Settings › Attendance › "deduction basis" to Bracket to round lates using this table instead. Current basis: ' . (setting('late_deduction_mode') === 'bracket' ? 'BRACKET TABLE' : 'PER MINUTE') . '.',
    'order' => 'from_minutes',
    'fields' => [
        'from_minutes' => ['label' => 'From (minutes)', 'type' => 'number', 'required' => true],
        'to_minutes' => ['label' => 'To (minutes)', 'type' => 'number', 'required' => true],
        'deduct_minutes' => ['label' => 'Minutes deducted', 'type' => 'number', 'required' => true],
        'remarks' => ['label' => 'Remarks'],
    ],
    'bottom_html' => '<div class="card"><h3>Related settings</h3><ul class="small"><li>Grace period: ' . (int)setting('grace_minutes', 0) . ' minute(s); beyond grace deduct: ' . h(setting('late_grace_mode') === 'excess' ? 'only minutes beyond grace' : 'all minutes late') . '</li>'
        . '<li>Habitual tardiness: ' . (int)setting('habitual_tardy_count', 5) . ' times per month → flagged for HR review</li><li>Disciplinary referral cap: ' . (int)setting('tardy_cap_minutes', 120) . ' minutes per month → Code of Conduct penalty matrix</li></ul>'
        . '<a class="btn btn-sm" href="' . url('settings') . '#attendance">Edit settings</a></div>',
]);
