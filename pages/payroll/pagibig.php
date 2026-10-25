<?php
crud_page([
    'page' => 'pagibig', 'table' => 'pagibig_table', 'title' => 'Pag-IBIG (HDMF) Contribution Table', 'singular' => 'bracket', 'module' => 'payroll',
    'subtitle' => 'Home Development Mutual Fund Law of 2009 (RA 9679). Contributions are computed on monthly compensation up to the maximum fund salary. Voluntary higher employee contributions are set per employee.',
    'order' => 'effective_from DESC, range_from',
    'fields' => [
        'range_from' => ['label' => 'Monthly compensation from', 'type' => 'money', 'required' => true],
        'range_to' => ['label' => 'to', 'type' => 'money', 'help' => 'Blank = and above'],
        'ee_rate_pct' => ['label' => 'Employee rate (%)', 'type' => 'number', 'required' => true],
        'er_rate_pct' => ['label' => 'Employer rate (%)', 'type' => 'number', 'required' => true],
        'max_comp' => ['label' => 'Maximum fund salary', 'type' => 'money', 'required' => true],
        'effective_from' => ['label' => 'Effective from', 'type' => 'date', 'required' => true],
    ],
    'format' => ['range_to' => fn($v) => $v === null ? '<span class="muted">and above</span>' : money($v)],
    'bottom_html' => function () {
        $amt = (float)get('test', 0);
        $out = '<div class="card"><h3>Test</h3><form class="filters" method="get" style="padding:0"><input type="hidden" name="p" value="pagibig"><label>Monthly compensation<input type="number" step="0.01" name="test" value="' . h($amt ?: '') . '"></label><button class="btn">Compute</button></form>';
        if ($amt > 0) {
            $c = pagibig_contribution($amt);
            $out .= '<p style="margin-top:10px">Employee ' . peso($c['ee']) . ', Employer ' . peso($c['er']) . ' per month (fund salary ' . peso($c['base']) . ').</p>';
        }
        return $out . '</div>';
    },
]);
