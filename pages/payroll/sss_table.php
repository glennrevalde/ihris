<?php
/**
 * SSS contribution schedule (RA 11199): Monthly Salary Credit brackets with EE / ER shares, EC and MPF.
 * Includes a generator to rebuild the schedule from rate parameters when SSS issues a new circular.
 */
if (is_post() && post('_action') === 'generate') {
    $eff = post('effective_from');
    $min = (float)post('min_msc');
    $max = (float)post('max_msc');
    $step = (float)post('step');
    $mpf = (float)post('mpf_threshold');
    $ee = (float)post('ee_rate') / 100;
    $er = (float)post('er_rate') / 100;
    $ecLow = (float)post('ec_low');
    $ecHigh = (float)post('ec_high');
    $ecTh = (float)post('ec_threshold');
    if (!$eff || $min <= 0 || $max < $min || $step <= 0) {
        flash('error', 'Please provide valid schedule parameters.');
        redirect('sss_table');
    }
    tx(function () use ($eff, $min, $max, $step, $mpf, $ee, $er, $ecLow, $ecHigh, $ecTh) {
        q('DELETE FROM sss_table WHERE effective_from = ?', [$eff]);
        for ($msc = $min; $msc <= $max + 0.001; $msc += $step) {
            $from = $msc == $min ? 0 : $msc - $step / 2;
            $to = $msc >= $max ? null : $msc + $step / 2 - 0.01;
            $reg = $mpf > 0 ? min($msc, $mpf) : $msc;
            $mp = $mpf > 0 ? max(0, $msc - $mpf) : 0;
            insert('sss_table', ['range_from' => $from, 'range_to' => $to, 'msc_regular' => $reg, 'msc_mpf' => $mp, 'ee_regular' => round($reg * $ee, 2), 'ee_mpf' => round($mp * $ee, 2),
                'er_regular' => round($reg * $er, 2), 'er_mpf' => round($mp * $er, 2), 'ec' => $msc < $ecTh ? $ecLow : $ecHigh, 'effective_from' => $eff]);
        }
    });
    audit('payroll', 'sss_generate', 'sss_table', null, $_POST);
    flash('success', 'SSS schedule generated effective ' . fmt_date($eff) . '. Payroll periods on/after that date use the new schedule.');
    redirect('sss_table', ['effective_from' => $eff]);
}
$effs = pairs("SELECT DISTINCT effective_from, DATE_FORMAT(effective_from, '%M %d, %Y') FROM sss_table ORDER BY effective_from DESC");
$effSel = get('effective_from', array_key_first($effs) ?? '');
crud_page([
    'page' => 'sss_table', 'import' => 'sss_table', 'table' => 'sss_table', 'title' => 'SSS Contribution Table', 'singular' => 'bracket', 'module' => 'payroll',
    'subtitle' => 'Social Security Act of 2018 (RA 11199) schedule: 15% of the Monthly Salary Credit (employee 5%, employer 10%) plus Employees\' Compensation; MSC above ₱20,000 goes to the Mandatory Provident Fund (MPF).',
    'order' => 'range_from',
    'filters' => ['effective_from' => ['label' => 'Schedule effective', 'options' => $effs, 'default' => $effSel, 'all' => 'All schedules']],
    'fields' => [
        'range_from' => ['label' => 'Compensation from', 'type' => 'money', 'required' => true],
        'range_to' => ['label' => 'to', 'type' => 'money'],
        'msc_regular' => ['label' => 'MSC (regular SS)', 'type' => 'money', 'required' => true],
        'msc_mpf' => ['label' => 'MSC (MPF)', 'type' => 'money', 'default' => 0],
        'ee_regular' => ['label' => 'EE share', 'type' => 'money', 'required' => true],
        'ee_mpf' => ['label' => 'EE MPF', 'type' => 'money', 'default' => 0],
        'er_regular' => ['label' => 'ER share', 'type' => 'money', 'required' => true],
        'er_mpf' => ['label' => 'ER MPF', 'type' => 'money', 'default' => 0],
        'ec' => ['label' => 'EC (ER)', 'type' => 'money', 'default' => 0],
        'effective_from' => ['label' => 'Effective from', 'type' => 'date', 'required' => true],
    ],
    'list' => ['range_from', 'range_to', 'msc_regular', 'msc_mpf', 'ee_regular', 'ee_mpf', 'er_regular', 'er_mpf', 'ec'],
    'format' => [
        'range_to' => fn($v) => $v === null ? '<span class="muted">and over</span>' : money($v),
        'ec' => fn($v, $r) => money($v) . '<br><span class="muted small">Total ' . money($r['ee_regular'] + $r['ee_mpf'] + $r['er_regular'] + $r['er_mpf'] + $r['ec']) . '</span>',
    ],
    'top_html' => '<details class="card"><summary><strong>Regenerate schedule from parameters</strong> <span class="muted small">(use when SSS issues a new contribution circular)</span></summary>
        <form method="post" style="margin-top:12px">' . csrf_field() . '<input type="hidden" name="_action" value="generate"><div class="form-grid">
        <div class="field"><label>Effective from</label><input type="date" name="effective_from" value="' . h(date('Y') + 1 . '-01-01') . '" required></div>
        <div class="field"><label>Minimum MSC</label><input type="number" name="min_msc" value="5000"></div><div class="field"><label>Maximum MSC</label><input type="number" name="max_msc" value="35000"></div>
        <div class="field"><label>MSC step</label><input type="number" name="step" value="500"></div><div class="field"><label>MPF threshold (regular SS cap)</label><input type="number" name="mpf_threshold" value="20000"></div>
        <div class="field"><label>Employee rate (%)</label><input type="number" step="0.01" name="ee_rate" value="5"></div><div class="field"><label>Employer rate (%)</label><input type="number" step="0.01" name="er_rate" value="10"></div>
        <div class="field"><label>EC below threshold</label><input type="number" name="ec_low" value="10"></div><div class="field"><label>EC at/above threshold</label><input type="number" name="ec_high" value="30"></div>
        <div class="field"><label>EC threshold (MSC)</label><input type="number" name="ec_threshold" value="15000"></div></div>
        <div class="form-actions"><button class="btn btn-primary" data-confirm="Generate (replace) the schedule for this effectivity date?">Generate schedule</button></div></form></details>',
    'bottom_html' => function () {
        $amt = (float)get('test', 0);
        $out = '<div class="card"><h3>Test</h3><form class="filters" method="get" style="padding:0"><input type="hidden" name="p" value="sss_table"><label>Monthly compensation<input type="number" step="0.01" name="test" value="' . h($amt ?: '') . '"></label><button class="btn">Compute</button></form>';
        if ($amt > 0) {
            $c = sss_contribution($amt);
            $out .= '<p style="margin-top:10px">MSC ' . peso($c['msc']) . ' → employee ' . peso($c['ee']) . ' (MPF ' . peso($c['ee_mpf']) . '), employer ' . peso($c['er']) . ' + EC ' . peso($c['ec']) . ' = total ' . peso($c['total']) . ' per month.</p>';
        }
        return $out . '</div>';
    },
]);
