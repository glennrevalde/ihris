<?php
/**
 * Government remittance reports for one employer-of-record and applicable month:
 * SSS R-3 (contribution collection list) & R-5 (payment return), PhilHealth RF-1, Pag-IBIG MCRF, BIR 1601-C.
 */
$month = get('month', date('Y-m', strtotime('first day of last month')));
[$y, $m] = array_map('intval', explode('-', $month));
$employers = pairs('SELECT MIN(id), company_name FROM branches GROUP BY company_name ORDER BY MIN(id)');
$empSel = (int)get('employer', array_key_first($employers));
$type = get('type', 'sss_r3');
$er = row('SELECT * FROM branches WHERE id = ?', [$empSel]);
$branchIds = array_map('intval', q('SELECT id FROM branches WHERE company_name = ?', [$er['company_name']])->fetchAll(PDO::FETCH_COLUMN));
$data = rows('SELECT e.id, e.employee_no, e.last_name, e.first_name, e.middle_name, e.suffix, e.sss_no, e.philhealth_no, e.pagibig_no, e.tin, e.birth_date,
                     SUM(pi.gross_pay) gross, MAX(pi.contrib_basis) basis, SUM(pi.sss_ee) sss_ee, SUM(pi.sss_er) sss_er, SUM(pi.sss_ec) sss_ec,
                     SUM(pi.phic_ee) phic_ee, SUM(pi.phic_er) phic_er, SUM(pi.hdmf_ee) hdmf_ee, SUM(pi.hdmf_ee_voluntary) hdmf_vol, SUM(pi.hdmf_er) hdmf_er,
                     SUM(pi.taxable_income) taxable, SUM(pi.withholding_tax) tax, SUM(pi.benefits_other) other_nt, SUM(pi.benefits_nontax) deminimis,
                     SUM(CASE WHEN pi.is_mwe = 1 THEN pi.basic_earned ELSE 0 END) mwe_basic,
                     SUM(CASE WHEN pi.is_mwe = 1 THEN pi.holiday_pay + pi.premium_pay + pi.ot_pay + pi.nd_pay ELSE 0 END) mwe_premiums
              FROM payroll_items pi JOIN payroll_runs r ON r.id = pi.run_id JOIN employees e ON e.id = pi.employee_id
              WHERE r.status = \'finalized\' AND YEAR(r.period_end) = ? AND MONTH(r.period_end) = ? AND pi.default_branch_id IN (' . in_list($branchIds) . ')' . user_scope_sql('e') . '
              GROUP BY e.id, e.employee_no, e.last_name, e.first_name, e.middle_name, e.suffix, e.sss_no, e.philhealth_no, e.pagibig_no, e.tin, e.birth_date
              ORDER BY e.last_name, e.first_name', array_merge([$y, $m], $branchIds));
$union = pairs("SELECT pi.employee_id, SUM(l.amount) FROM payroll_item_lines l JOIN payroll_items pi ON pi.id = l.item_id JOIN payroll_runs r ON r.id = pi.run_id
                WHERE r.status = 'finalized' AND YEAR(r.period_end) = ? AND MONTH(r.period_end) = ? AND l.code = 'DED_UNION' GROUP BY pi.employee_id", [$y, $m]);
$period = month_name($m) . ' ' . $y;
$sssRows = [];
foreach ($data as $d) {
    $c = sss_contribution((float)$d['basis'], sprintf('%04d-%02d-01', $y, $m));
    $mpfEe = min((float)$d['sss_ee'], $c['ee_mpf']);
    $mpfEr = min((float)$d['sss_er'], $c['er_mpf']);
    $sssRows[] = ['d' => $d, 'msc' => $c['msc'], 'ss_ee' => $d['sss_ee'] - $mpfEe, 'ss_er' => $d['sss_er'] - $mpfEr, 'ec' => (float)$d['sss_ec'], 'mpf_ee' => $mpfEe, 'mpf_er' => $mpfEr];
}
$reports = ['sss_r3' => 'SSS R-3 — Contribution Collection List', 'sss_r5' => 'SSS R-5 — Contribution Payment Return', 'phic_rf1' => 'PhilHealth RF-1 — Employer Remittance Report',
    'hdmf_mcrf' => 'Pag-IBIG MCRF — Monthly Collection Remittance Form', 'bir_1601c' => 'BIR 1601-C — Monthly Remittance Return of Income Taxes Withheld on Compensation'];

if (get('csv')) {
    audit('payroll', 'export_remittance', 'report', null, ['type' => $type, 'month' => $month, 'employer' => $er['company_name']]);
    switch ($type) {
        case 'sss_r3':
        case 'sss_r5':
            csv_download("SSS_R3_{$month}.csv", ['SS Number', 'Last Name', 'First Name', 'MI', 'MSC', 'SS EE', 'SS ER', 'EC', 'MPF EE', 'MPF ER', 'Total'],
                array_map(fn($r) => [$r['d']['sss_no'], $r['d']['last_name'], $r['d']['first_name'], mb_substr((string)$r['d']['middle_name'], 0, 1), $r['msc'], $r['ss_ee'], $r['ss_er'], $r['ec'], $r['mpf_ee'], $r['mpf_er'],
                    round($r['ss_ee'] + $r['ss_er'] + $r['ec'] + $r['mpf_ee'] + $r['mpf_er'], 2)], $sssRows));
        case 'phic_rf1':
            csv_download("PhilHealth_RF1_{$month}.csv", ['PhilHealth No', 'Last Name', 'First Name', 'MI', 'Monthly Basic Salary', 'PS (EE)', 'ES (ER)', 'Total'],
                array_map(fn($d) => [$d['philhealth_no'], $d['last_name'], $d['first_name'], mb_substr((string)$d['middle_name'], 0, 1), $d['basis'], $d['phic_ee'], $d['phic_er'], $d['phic_ee'] + $d['phic_er']], $data));
        case 'hdmf_mcrf':
            csv_download("PagIBIG_MCRF_{$month}.csv", ['Pag-IBIG MID No', 'Last Name', 'First Name', 'MI', 'Birth Date', 'Monthly Compensation', 'EE Share', 'ER Share', 'Total'],
                array_map(fn($d) => [$d['pagibig_no'], $d['last_name'], $d['first_name'], mb_substr((string)$d['middle_name'], 0, 1), $d['birth_date'], $d['basis'], $d['hdmf_ee'] + $d['hdmf_vol'], $d['hdmf_er'], $d['hdmf_ee'] + $d['hdmf_vol'] + $d['hdmf_er']], $data));
        default:
            csv_download("BIR_1601C_{$month}.csv", ['TIN', 'Name', 'Gross Compensation', 'MWE Basic', 'MWE Hol/OT/ND', '13th & Other Benefits', 'De Minimis', 'Mandatory Contributions', 'Taxable', 'Tax Withheld'],
                array_map(fn($d) => [$d['tin'], full_name($d), $d['gross'], $d['mwe_basic'], $d['mwe_premiums'], $d['other_nt'], $d['deminimis'], $d['sss_ee'] + $d['phic_ee'] + $d['hdmf_ee'] + (float)($union[$d['id']] ?? 0), $d['taxable'], $d['tax']], $data));
    }
}
if (get('print')) {
    print_header($reports[$type] . ' ' . $period, $type !== 'sss_r5' && $type !== 'bir_1601c');
} else {
    page_header('Government Remittance Reports', ['subtitle' => 'Pre-formatted to the layout required by each agency. Statutory deductions are reported under each employee\'s default branch / employer only.']);
    ?>
    <form class="card filters" method="get"><input type="hidden" name="p" value="rpt_remittance">
      <label>Applicable month<input type="month" name="month" value="<?= h($month) ?>"></label>
      <label>Employer<select name="employer"><?= options($employers, $empSel) ?></select></label>
      <label>Report<select name="type"><?= options($reports, $type) ?></select></label>
      <button class="btn btn-primary">Show</button>
      <a class="btn" target="_blank" href="<?= url('rpt_remittance', ['month' => $month, 'employer' => $empSel, 'type' => $type, 'print' => 1]) ?>">Print</a>
      <a class="btn" href="<?= url('rpt_remittance', ['month' => $month, 'employer' => $empSel, 'type' => $type, 'csv' => 1]) ?>">Export CSV</a>
    </form>
    <div class="card">
    <?php
}
$hdr = function (string $title, array $kv) use ($er) {
    echo '<div class="form-box"><div class="card-head" style="margin:0"><h3 style="margin:0">' . h($title) . '</h3></div><table class="doc-table" style="border:0;margin:6px 0 0"><tbody><tr>';
    $i = 0;
    foreach ($kv as $k => $v) {
        echo '<th style="width:16%">' . h($k) . '</th><td>' . $v . '</td>';
        if (++$i % 2 === 0) {
            echo '</tr><tr>';
        }
    }
    echo '</tr></tbody></table></div>';
};
switch ($type) {
    case 'sss_r3':
        $hdr('SSS Form R-3 - Contribution Collection List', ['Employer name' => h($er['company_name']), 'SSS employer no.' => h($er['sss_er_no']), 'Applicable month' => h($period), 'Address' => h($er['address'])]);
        $t = ['msc' => 0, 'ss_ee' => 0, 'ss_er' => 0, 'ec' => 0, 'mpf_ee' => 0, 'mpf_er' => 0];
        echo '<table class="doc-table"><thead><tr><th>#</th><th>SS Number</th><th>Name of member</th><th class="num">MSC</th><th class="num">SS (EE)</th><th class="num">SS (ER)</th><th class="num">EC (ER)</th><th class="num">MPF (EE)</th><th class="num">MPF (ER)</th><th class="num">Total</th></tr></thead><tbody>';
        foreach ($sssRows as $i => $r) {
            foreach ($t as $k => $v) {
                $t[$k] += $r[$k];
            }
            echo '<tr><td>' . ($i + 1) . '</td><td class="mono">' . h($r['d']['sss_no']) . '</td><td>' . h(full_name($r['d'])) . '</td><td class="num">' . money($r['msc']) . '</td><td class="num">' . money($r['ss_ee']) . '</td><td class="num">' . money($r['ss_er'])
                . '</td><td class="num">' . money($r['ec']) . '</td><td class="num">' . money($r['mpf_ee']) . '</td><td class="num">' . money($r['mpf_er']) . '</td><td class="num">' . money($r['ss_ee'] + $r['ss_er'] + $r['ec'] + $r['mpf_ee'] + $r['mpf_er']) . '</td></tr>';
        }
        echo '</tbody><tfoot><tr><td colspan="3">TOTAL (' . count($sssRows) . ' members)</td><td class="num">' . money($t['msc']) . '</td><td class="num">' . money($t['ss_ee']) . '</td><td class="num">' . money($t['ss_er']) . '</td><td class="num">' . money($t['ec'])
            . '</td><td class="num">' . money($t['mpf_ee']) . '</td><td class="num">' . money($t['mpf_er']) . '</td><td class="num">' . money(array_sum($t) - $t['msc']) . '</td></tr></tfoot></table>';
        break;
    case 'sss_r5':
        $ss = array_sum(array_map(fn($r) => $r['ss_ee'] + $r['ss_er'], $sssRows));
        $ec = array_sum(array_column($sssRows, 'ec'));
        $mpf = array_sum(array_map(fn($r) => $r['mpf_ee'] + $r['mpf_er'], $sssRows));
        $hdr('SSS Form R-5 - Contribution Payment Return', ['Employer name' => h($er['company_name']), 'SSS employer no.' => h($er['sss_er_no']), 'Applicable month' => h($period), 'Number of employees' => count($sssRows)]);
        echo '<table class="doc-table"><tbody><tr><th>Social Security contributions (EE + ER)</th><td class="num">' . money($ss) . '</td></tr><tr><th>Employees\' Compensation (EC)</th><td class="num">' . money($ec) . '</td></tr>'
            . '<tr><th>Mandatory Provident Fund (WISP)</th><td class="num">' . money($mpf) . '</td></tr><tr><th>TOTAL AMOUNT DUE</th><td class="num"><strong>' . money($ss + $ec + $mpf) . '</strong></td></tr>'
            . '<tr><th>Payment reference (PRN) / date paid</th><td>&nbsp;</td></tr></tbody></table>';
        echo '<div class="sig-row"><div class="sig"><div class="line">&nbsp;</div><small>Certified correct — authorized signatory</small></div></div>';
        break;
    case 'phic_rf1':
        $hdr('PhilHealth RF-1 - Employer\'s Remittance Report', ['Employer name' => h($er['company_name']), 'PhilHealth employer no. (PEN)' => h($er['philhealth_er_no']), 'Applicable month' => h($period), 'Employer TIN' => h($er['tin'])]);
        $t = [0, 0, 0];
        echo '<table class="doc-table"><thead><tr><th>#</th><th>PhilHealth No. (PIN)</th><th>Name of member</th><th class="num">Monthly basic salary</th><th class="num">PS (Employee)</th><th class="num">ES (Employer)</th><th class="num">Total</th></tr></thead><tbody>';
        foreach ($data as $i => $d) {
            $t[0] += $d['basis'];
            $t[1] += $d['phic_ee'];
            $t[2] += $d['phic_er'];
            echo '<tr><td>' . ($i + 1) . '</td><td class="mono">' . h($d['philhealth_no']) . '</td><td>' . h(full_name($d)) . '</td><td class="num">' . money($d['basis']) . '</td><td class="num">' . money($d['phic_ee']) . '</td><td class="num">' . money($d['phic_er']) . '</td><td class="num">' . money($d['phic_ee'] + $d['phic_er']) . '</td></tr>';
        }
        echo '</tbody><tfoot><tr><td colspan="3">TOTAL</td><td class="num">' . money($t[0]) . '</td><td class="num">' . money($t[1]) . '</td><td class="num">' . money($t[2]) . '</td><td class="num">' . money($t[1] + $t[2]) . '</td></tr></tfoot></table>';
        break;
    case 'hdmf_mcrf':
        $hdr('Pag-IBIG MCRF - Monthly Collection Remittance Form', ['Employer name' => h($er['company_name']), 'Pag-IBIG employer ID' => h($er['pagibig_er_no']), 'Applicable month' => h($period), 'Address' => h($er['address'])]);
        $t = [0, 0, 0];
        echo '<table class="doc-table"><thead><tr><th>#</th><th>Pag-IBIG MID No.</th><th>Name of member</th><th>Birth date</th><th class="num">Monthly compensation</th><th class="num">EE share</th><th class="num">ER share</th><th class="num">Total</th></tr></thead><tbody>';
        foreach ($data as $i => $d) {
            $ee = $d['hdmf_ee'] + $d['hdmf_vol'];
            $t[0] += $d['basis'];
            $t[1] += $ee;
            $t[2] += $d['hdmf_er'];
            echo '<tr><td>' . ($i + 1) . '</td><td class="mono">' . h($d['pagibig_no']) . '</td><td>' . h(full_name($d)) . '</td><td>' . h(fmt_date($d['birth_date'], 'm/d/Y')) . '</td><td class="num">' . money($d['basis']) . '</td><td class="num">' . money($ee) . '</td><td class="num">' . money($d['hdmf_er']) . '</td><td class="num">' . money($ee + $d['hdmf_er']) . '</td></tr>';
        }
        echo '</tbody><tfoot><tr><td colspan="4">TOTAL</td><td class="num">' . money($t[0]) . '</td><td class="num">' . money($t[1]) . '</td><td class="num">' . money($t[2]) . '</td><td class="num">' . money($t[1] + $t[2]) . '</td></tr></tfoot></table>';
        break;
    default:
        $s = fn($k) => array_sum(array_map(fn($d) => (float)$d[$k], $data));
        $contrib = $s('sss_ee') + $s('phic_ee') + $s('hdmf_ee') + array_sum($union);
        $nontax = $s('mwe_basic') + $s('mwe_premiums') + $s('other_nt') + $s('deminimis') + $contrib;
        $hdr('BIR Form 1601-C - Monthly Remittance Return of Income Taxes Withheld on Compensation', ['Withholding agent' => h($er['company_name']), 'TIN' => h($er['tin']), 'RDO code' => h($er['rdo_code']), 'For the month of' => h($period)]);
        echo '<table class="doc-table"><tbody>'
            . '<tr><th style="width:70%">14. Total amount of compensation</th><td class="num">' . money($s('gross')) . '</td></tr>'
            . '<tr><th colspan="2">Less: Non-taxable / exempt compensation</th></tr>'
            . '<tr><td>15. Statutory minimum wage (MWEs)</td><td class="num">' . money($s('mwe_basic')) . '</td></tr>'
            . '<tr><td>16. Holiday pay, overtime pay, night shift differential and hazard pay (MWEs only)</td><td class="num">' . money($s('mwe_premiums')) . '</td></tr>'
            . '<tr><td>17. 13th month pay and other benefits</td><td class="num">' . money($s('other_nt')) . '</td></tr>'
            . '<tr><td>18. De minimis benefits</td><td class="num">' . money($s('deminimis')) . '</td></tr>'
            . '<tr><td>19. SSS, GSIS, PHIC, HDMF mandatory contributions & union dues (employee share only)</td><td class="num">' . money($contrib) . '</td></tr>'
            . '<tr><td>20. Other non-taxable compensation</td><td class="num">0.00</td></tr>'
            . '<tr><th>21. Total non-taxable compensation</th><td class="num">' . money($nontax) . '</td></tr>'
            . '<tr><th>22. Total taxable compensation</th><td class="num">' . money($s('taxable')) . '</td></tr>'
            . '<tr><th>25. Total taxes withheld</th><td class="num"><strong>' . money($s('tax')) . '</strong></td></tr>'
            . '</tbody></table><p class="small muted">Taxable compensation is the sum of each employee\'s per-period taxable income; minor differences from line 14 − line 21 arise from rounding.</p>';
        echo '<h3>Schedule — employees</h3><table class="doc-table"><thead><tr><th>TIN</th><th>Employee</th><th class="num">Gross</th><th class="num">Non-taxable</th><th class="num">Taxable</th><th class="num">Tax withheld</th></tr></thead><tbody>';
        foreach ($data as $d) {
            $nt = $d['mwe_basic'] + $d['mwe_premiums'] + $d['other_nt'] + $d['deminimis'] + $d['sss_ee'] + $d['phic_ee'] + $d['hdmf_ee'] + (float)($union[$d['id']] ?? 0);
            echo '<tr><td class="mono">' . h($d['tin']) . '</td><td>' . h(full_name($d)) . '</td><td class="num">' . money($d['gross']) . '</td><td class="num">' . money($nt) . '</td><td class="num">' . money($d['taxable']) . '</td><td class="num">' . money($d['tax']) . '</td></tr>';
        }
        echo '</tbody></table>';
}
if (get('print')) {
    echo '<div class="sig-row"><div class="sig"><div class="line">&nbsp;</div><small>Prepared by</small></div><div class="sig"><div class="line">&nbsp;</div><small>Certified correct</small></div></div>';
    print_footer();
} else {
    echo '</div>';
    page_footer();
}
