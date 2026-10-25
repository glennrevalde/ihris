<?php
/**
 * Payslip rendering (M.A.D.A.S. layout): earnings by category, deductions, YTD information,
 * outstanding balances, disclaimer and signature. HTML (printable, 1–2 per page) and PDF.
 */

/** Earnings shown in the left column of the payslip: [code => label]. */
const PS_EARN_LEFT = [
    'BASIC'     => ['Basic', true],
    'ABSENT'    => ['Absent', true],
    'LATE'      => ['Late', true],
    'UT'        => ['Undertime', true],
    'OT_REG'    => ['Overtime', true, '(Reg)'],
    'RD'        => ['', false, '(OFF)'],
    'RD_OT'     => ['', false, '(OFF) XT'],
    'LH'        => ['', false, '(LEG) REG'],
    'LH_OT'     => ['', false, '(LEG) XT'],
    'LH_RD'     => ['', false, '(LEG) OFF'],
    'LH_RD_OT'  => ['', false, '(LEG) OFF XT'],
    'SH'        => ['', false, '(SPE) REG'],
    'SH_OT'     => ['', false, '(SPE) XT'],
    'SH_RD'     => ['', false, '(SPE) OFF'],
    'SH_RD_OT'  => ['', false, '(SPE) OFF XT'],
    'VL'        => ['Vacation Leave', true],
];
/** Earnings shown in the right column. */
const PS_EARN_RIGHT = [
    'NP_REG'       => ['N. Prem.', true, '(Reg)'],
    'NP_REG_OT'    => ['', false, '(OT) XT'],
    'NP_RD'        => ['', false, '(OFF)'],
    'NP_RD_OT'     => ['', false, '(OFF) XT'],
    'NP_LH'        => ['', false, '(LEG) REG'],
    'NP_LH_OT'     => ['', false, '(LEG) XT'],
    'NP_LH_RD'     => ['', false, '(LEG) OFF'],
    'NP_LH_RD_OT'  => ['', false, '(LEG) OFF XT'],
    'NP_SH'        => ['', false, '(SPE) REG'],
    'NP_SH_OT'     => ['', false, '(SPE) XT'],
    'NP_SH_RD'     => ['', false, '(SPE) OFF'],
    'NP_SH_RD_OT'  => ['', false, '(SPE) OFF XT'],
    'HOL'          => ['Holiday Pay', true],
    '13TH'         => ['13th Month Pay', true],
    'SL'           => ['Sick Leave', true],
    'EL'           => ['Emergency Leave', true],
    'OL'           => ['Offset Leave', true],
    'PM'           => ['Mat./Pat. Leave', true],
];
/** Deduction buckets of the payslip, by register column. */
const PS_DED_ROWS = [
    'sss'          => 'SSS',
    'sss_loan'     => 'SSS Loan',
    'sss_cal'      => 'SSS Calamity Loan',
    'hdmf'         => 'PAGIBIG',
    'hdmf_loan'    => 'PAGIBIG Loan',
    'phic'         => 'PhilHealth',
    'wtax'         => 'Tax',
    'company_loan' => 'Advances',
];

/**
 * Is a deduction line an SSS Calamity Loan? Its code (SSS-CL, or TTD_SSS-CL for a transaction with
 * terms), its description, or the sub-type of the government-loan ledger it comes from says so.
 * The payslip shows it on its own row instead of inside SSS Loan.
 */
function ps_is_sss_calamity(array $l): bool
{
    $code = strtoupper((string)$l['code']);
    $desc = strtolower((string)($l['description'] ?? ''));
    if (preg_match('/(^|_)SSS[-_]?CL$/', $code) || (strpos($desc, 'calamity') !== false && strpos($desc, 'sss') !== false)) {
        return true;
    }
    if (($l['ref_type'] ?? '') === 'ledger' && !empty($l['ref_id'])) {
        static $sub = [];
        $id = (int)$l['ref_id'];
        if (!array_key_exists($id, $sub)) {
            $sub[$id] = strtolower((string)val('SELECT sub_type FROM ledgers WHERE id = ?', [$id]));
        }
        return strpos($sub[$id], 'calamity') !== false && strpos($sub[$id], 'pag') === false && strpos($sub[$id], 'hdmf') === false;
    }
    return false;
}

/** Register column of a deduction line that has none (payslips of older runs). */
function ps_col_of_code(string $code): string
{
    $c = strtoupper($code);
    if ($c === 'SSS') {
        return 'sss';
    }
    if ($c === 'PHIC') {
        return 'phic';
    }
    if ($c === 'HDMF' || $c === 'HDMF_VOL') {
        return 'hdmf';
    }
    if (strpos($c, 'WTAX') === 0) {
        return 'wtax';
    }
    if (strpos($c, 'SSS') !== false) {
        return 'sss_loan';
    }
    if (strpos($c, 'HDMF') !== false || strpos($c, 'PAGIBIG') !== false) {
        return 'hdmf_loan';
    }
    return 'others';
}

/** Everything the payslip prints for one payroll item. */
function payslip_blocks(array $it, ?int $branchId = null): array
{
    $branchRow = null;
    foreach ($it['branches'] as $b) {
        if ($branchId && (int)$b['branch_id'] === (int)$branchId) {
            $branchRow = $b;
        }
    }
    $lines = $it['lines'];
    // the branch that carries the deductions (Set Deduction Source); runs before version 4: the default employer
    $hasSource = (bool)array_filter($it['branches'], fn($b) => !empty($b['is_deduction_source']));
    $isHome = $branchRow && ($hasSource ? !empty($branchRow['is_deduction_source']) : !empty($branchRow['is_default']));
    $inBranch = function (array $l) use ($branchRow, $isHome) {
        if (!$branchRow) {
            return true;
        }
        return (int)$l['branch_id'] === (int)$branchRow['branch_id'] || ($l['branch_id'] === null && $isHome);
    };
    $byCode = [];
    $hours = [];
    $other = [];
    foreach ($lines as $l) {
        if ($l['line_type'] !== 'earning' || !$inBranch($l)) {
            continue;
        }
        $code = $l['code'];
        if (isset(PS_EARN_LEFT[$code]) || isset(PS_EARN_RIGHT[$code])) {
            $byCode[$code] = ($byCode[$code] ?? 0) + (float)$l['amount'];
            if ($l['quantity'] !== null) {
                $hours[$code] = ($hours[$code] ?? 0) + (float)$l['quantity'];
            }
        } else {
            $other[] = $l;
        }
    }
    // absences, lates and undertime are stored as negative earnings; the payslip prints them positive
    foreach (['ABSENT', 'LATE', 'UT'] as $c) {
        if (isset($byCode[$c])) {
            $byCode[$c] = abs($byCode[$c]);
        }
    }
    $otherTotal = 0.0;
    foreach ($other as $l) {
        $otherTotal += (float)$l['amount'];
    }
    $left = [];
    foreach (PS_EARN_LEFT as $code => $meta) {
        $left[] = ['code' => $code, 'label' => $meta[0], 'bold' => $meta[1], 'sub' => $meta[2] ?? '', 'amount' => (float)($byCode[$code] ?? 0), 'hours' => $hours[$code] ?? null];
    }
    $right = [];
    foreach (PS_EARN_RIGHT as $code => $meta) {
        $right[] = ['code' => $code, 'label' => $meta[0], 'bold' => $meta[1], 'sub' => $meta[2] ?? '', 'amount' => (float)($byCode[$code] ?? 0), 'hours' => $hours[$code] ?? null];
    }
    $gross = $branchRow ? (float)$branchRow['gross'] : (float)$it['gross_pay'];

    // deductions (only on the consolidated payslip)
    $ded = [];
    $dedLines = [];
    foreach (PS_DED_ROWS as $col => $label) {
        $ded[$col] = ['label' => $label, 'amount' => 0.0];
    }
    $ticketAmt = 0.0;
    if (!$branchRow || $isHome) {
        foreach ($lines as $l) {
            if ($l['line_type'] !== 'deduction') {
                continue;
            }
            $col = reg_col_of_line($l);   // payslips created before the register columns existed are mapped from the code
            if ($col === 'sss_loan' && ps_is_sss_calamity($l)) {
                $col = 'sss_cal';   // shown apart, not inside SSS Loan
            }
            if ($l['code'] === 'TICKETS') {
                $ticketAmt += (float)$l['amount'];
            } elseif (isset($ded[$col])) {
                $ded[$col]['amount'] += (float)$l['amount'];
            } else {
                $dedLines[] = ['label' => $l['description'], 'amount' => (float)$l['amount']];
            }
        }
    }
    $totalDed = !$branchRow || $isHome ? (float)$it['total_deductions'] : 0.0;

    // YTD information (finalized runs of the year, including this one when it is finalized)
    $year = (int)substr($it['period_end'], 0, 4);
    $prior = payroll_ytd((int)$it['employee_id'], $year, (int)$it['run_id']);
    $ytd = [
        'earn_prior' => $prior['gross'], 'earn_cur' => (float)$it['gross_pay'],
        'taxable_prior' => $prior['taxable'], 'taxable_cur' => (float)$it['taxable_income'],
        'tax_prior' => $prior['tax'], 'tax_cur' => (float)$it['withholding_tax'],
    ];
    $balances = [];
    foreach (rows("SELECT ledger_no, sub_type, ledger_type, balance FROM ledgers WHERE employee_id = ? AND status = 'active' AND balance > 0 ORDER BY ledger_type, id", [$it['employee_id']]) as $l) {
        $balances[] = ['label' => $l['sub_type'] ?: label($l['ledger_type']), 'ref' => $l['ledger_no'], 'amount' => (float)$l['balance']];
    }
    return ['branch' => $branchRow, 'left' => $left, 'right' => $right, 'other' => $other, 'other_total' => $otherTotal, 'gross' => $gross,
        'ded' => $ded, 'ded_lines' => $dedLines, 'tickets' => $ticketAmt, 'total_ded' => $totalDed,
        'net' => $branchRow ? round($gross - $totalDed, 2) : (float)$it['net_pay'], 'is_home' => $isHome, 'ytd' => $ytd, 'balances' => $balances];
}

function ps_amt(float $v): string
{
    if (abs($v) < 0.005) {
        return '.00';
    }
    return number_format($v, 2);
}

/** One payslip as HTML (used for screen, printing and batch printing). */
function payslip_html(array $it, array $o = []): string
{
    $branchId = $o['branch_id'] ?? null;
    $copy = !empty($o['copy']);
    $b = payslip_blocks($it, $branchId);
    $branchRow = $b['branch'];
    $showHours = setting('payslip_show_hours', '1') === '1';
    $showYtd = setting('payslip_show_ytd', '1') === '1' && !$branchRow;
    $showBal = setting('payslip_show_balances', '1') === '1' && !$branchRow && $b['balances'];
    $company = $branchRow ? ($branchRow['company_name'] ?: $branchRow['branch_name']) : ($it['default_branch']['company_name'] ?? setting('company_name'));
    $rate = $it['rate_type'] === 'daily' ? (float)$it['daily_rate'] : (float)$it['monthly_rate'];
    $line = function (array $r) use ($showHours) {
        $lbl = $r['label'] !== '' ? '<b>' . h($r['label']) . '</b>' : '';
        if ($r['sub'] !== '') {
            $lbl .= ($lbl ? ' ' : '') . '<i>' . h($r['sub']) . '</i>';
        }
        $hrs = $showHours && !empty($r['hours']) ? '<span class="h">' . h(rtrim(rtrim(number_format((float)$r['hours'], 2), '0'), '.')) . 'h</span>' : '';
        return '<div class="ps-line"><span class="l">' . $lbl . $hrs . '</span><span class="a">' . ps_amt((float)$r['amount']) . '</span></div>';
    };
    ob_start(); ?>
<div class="ps<?= !empty($o['cut']) ? ' cut' : '' ?>">
  <div class="ps-co<?= function_exists('report_logo_on') && report_logo_on(isset($_GET['p']) && $_GET['p'] === 'payslips_print' ? 'payslips_print' : 'payslip') ? ' has-logo lp-' . report_logo_placement() : '' ?>"><?= function_exists('report_logo_img') ? report_logo_img('ps-logo', isset($_GET['p']) && $_GET['p'] === 'payslips_print' ? 'payslips_print' : 'payslip') : '' ?><?= h($company) ?></div>
  <div class="ps-sub"><?= h(trim((string)setting('payslip_title', '')) ?: 'PAYSLIP') ?><?= $branchRow ? ' — ' . h($branchRow['branch_name']) : (count($it['branches']) > 1 ? ' — consolidated' : '') ?>, <?= h($it['run_no']) ?></div>
  <?php if ($copy): ?><div class="ps-copy">( COPY )</div><?php endif; ?>
  <div class="ps-head">
    <div>
      <div class="r"><span>Employee ID</span><span>: <?= h($it['employee_no']) ?></span></div>
      <div class="r"><span>Employee's Name</span><span>: <?= h(full_name($it)) ?></span></div>
      <div class="r"><span>Department</span><span>: <?= h($it['org_unit_name'] ?: '—') ?></span></div>
      <div class="r"><span>Basic Rate</span><span>: <?= h(number_format($rate, 2)) ?><?= $it['rate_type'] === 'daily' ? ' /day' : ' /month' ?></span></div>
    </div>
    <div>
      <div class="r"><span>Period</span><span>: <?= h(date('m/d/Y', strtotime($it['period_start']))) ?> - <?= h(date('m/d/Y', strtotime($it['period_end']))) ?></span></div>
      <div class="r"><span>Pay Date</span><span>: <?= h(date('m/d/Y', strtotime($it['pay_date']))) ?></span></div>
      <div class="r"><span>Company Name</span><span>: <?= h($company) ?></span></div>
      <div class="r"><span>Exemption Code</span><span>: <?= h($it['exemption_code'] ?: ($it['is_mwe'] ? 'MWE' : 'Z')) ?></span></div>
    </div>
  </div>
  <div class="ps-body">
    <div class="ps-col">
      <h4>Earnings</h4>
      <div class="ps-earn">
        <div><?php foreach ($b['left'] as $r) {
            echo $line($r);
        } ?></div>
        <div><?php foreach ($b['right'] as $r) {
            echo $line($r);
        } ?></div>
      </div>
      <div class="ps-fill"></div>
      <?php foreach ($b['other'] as $l): ?>
        <div class="ps-line"><span class="l"><?= h(mb_strimwidth($l['description'], 0, 52, '…')) ?></span><span class="a"><?= ps_amt((float)$l['amount']) ?></span></div>
      <?php endforeach; ?>
      <div class="ps-line gross"><span class="l"><b>GROSS PAY</b></span><span class="a"><?= ps_amt($b['gross']) ?></span></div>
    </div>
    <div class="ps-col">
      <h4>Deductions</h4>
      <?php if ($branchRow): ?>
        <div class="ps-branch">Days worked at this branch: <?= h(number_format((float)$branchRow['days_worked'], 2)) ?>
          Regular hours: <?= h(number_format((float)$branchRow['reg_hours'], 2)) ?>, OT hours: <?= h(number_format((float)$branchRow['ot_hours'], 2)) ?></div>
      <?php endif; ?>
      <?php if ($branchRow && empty($b['is_home'])): ?>
        <div class="ps-branch">No deduction is taken from this branch. The deductions of the pay are carried by the deduction source branch<?= !empty($it['deduction_branch_id']) ? '' : ' (the employer of record)' ?>, so this payslip is paid in full.</div>
      <?php else: ?>
        <?php foreach ($b['ded'] as $d): ?>
          <div class="ps-line"><span class="l"><b><?= h($d['label']) ?></b></span><span class="a"><?= ps_amt((float)$d['amount']) ?></span></div>
        <?php endforeach; ?>
        <div class="ps-line"><span class="l"><b>Tickets</b></span><span class="a"><?= ps_amt($b['tickets']) ?></span></div>
        <div class="ps-fill"></div>
        <?php foreach ($b['ded_lines'] as $d): ?>
          <div class="ps-line"><span class="l"><?= h(mb_strimwidth($d['label'], 0, 34, '…')) ?></span><span class="a"><?= ps_amt((float)$d['amount']) ?></span></div>
        <?php endforeach; ?>
        <div class="ps-line tot"><span class="l">Total Deductions</span><span class="a"><?= ps_amt($b['total_ded']) ?></span></div>
      <?php endif; ?>
    </div>
    <div class="ps-col ps-ytd">
      <h4><?= $showYtd ? 'YTD Information' : 'Summary' ?></h4>
      <?php if ($showYtd): ?>
        <div class="ps-line"><span class="l">YTD Earnings</span><span class="a"><?= ps_amt($b['ytd']['earn_prior']) ?></span></div>
        <div class="ps-line"><span class="l">Current Earnings</span><span class="a"><?= ps_amt($b['ytd']['earn_cur']) ?></span></div>
        <div class="ps-line sum"><span class="l">Totals</span><span class="a"><?= ps_amt($b['ytd']['earn_prior'] + $b['ytd']['earn_cur']) ?></span></div>
        <div class="ps-line"><span class="l">YTD Taxable Income</span><span class="a"><?= ps_amt($b['ytd']['taxable_prior']) ?></span></div>
        <?php if (!empty($o['drill'])): ?>
        <a class="ps-line ps-drill" href="#taxDrill" title="Show how this figure was computed"><span class="l">Cur. Taxable Income</span><span class="a"><?= ps_amt($b['ytd']['taxable_cur']) ?></span></a>
        <?php else: ?>
        <div class="ps-line"><span class="l">Cur. Taxable Income</span><span class="a"><?= ps_amt($b['ytd']['taxable_cur']) ?></span></div>
        <?php endif; ?>
        <div class="ps-line sum"><span class="l">Totals</span><span class="a"><?= ps_amt($b['ytd']['taxable_prior'] + $b['ytd']['taxable_cur']) ?></span></div>
        <div class="ps-line"><span class="l">YTD Tax</span><span class="a"><?= ps_amt($b['ytd']['tax_prior']) ?></span></div>
        <div class="ps-line"><span class="l">Current Tax</span><span class="a"><?= ps_amt($b['ytd']['tax_cur']) ?></span></div>
        <div class="ps-line sum"><span class="l">Totals</span><span class="a"><?= ps_amt($b['ytd']['tax_prior'] + $b['ytd']['tax_cur']) ?></span></div>
      <?php else: ?>
        <div class="ps-line"><span class="l">Gross</span><span class="a"><?= ps_amt($b['gross']) ?></span></div>
      <?php endif; ?>
      <div class="ps-disc"><?= h(setting('payslip_disclaimer', '')) ?></div>
      <div class="ps-fill"></div>
      <div class="ps-net"><span>Net Pay<?= $branchRow ? ' (this branch)' : '' ?></span><span><?= ps_amt($b['net']) ?></span></div>
      <div class="ps-sign"><div class="line"><?= setting('payslip_sig_name', '') ? h(setting('payslip_sig_name')) : '&nbsp;' ?></div><?= h(setting('payslip_sig_label', 'HR Manager')) ?></div>
    </div>
  </div>
  <?php if ($showBal): ?>
    <div class="ps-bal"><b>Outstanding Balances:</b>
      <table><?php foreach ($b['balances'] as $bal): ?>
        <tr><td><?= h($bal['label']) ?> <span style="color:#666"><?= h($bal['ref']) ?></span></td><td class="num"><?= ps_amt($bal['amount']) ?></td></tr>
      <?php endforeach; ?></table>
    </div>
  <?php endif; ?>
  <?php if (!$branchRow && count($it['branches']) > 1): ?>
    <div class="ps-branch"><b>Earnings by branch:</b>
      <?php foreach ($it['branches'] as $br): ?>
        <?= h($br['branch_name']) ?> <?= h(ps_amt((float)$br['gross'])) ?><?= $br['is_default'] ? ' (employer of record)' : '' ?><?= !empty($br['is_deduction_source']) && !$br['is_default'] ? ' (deduction source)' : '' ?>;
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php
    return (string)ob_get_clean();
}

/** Increment the reprint counter; returns true when the payslip has been printed before. */
function payslip_mark_printed(int $itemId): bool
{
    $n = (int)val('SELECT print_count FROM payroll_items WHERE id = ?', [$itemId]);
    q('UPDATE payroll_items SET print_count = print_count + 1, last_printed_at = ? WHERE id = ?', [now(), $itemId]);
    return $n > 0;
}

/** Is this payslip a reprint (already printed once)? */
function payslip_is_copy(array $it): bool
{
    return setting('payslip_copy_label', '1') === '1' && (int)($it['print_count'] ?? 0) > 0;
}

/* ===================================================================== PDF */

/** One payslip as a PDF document (same layout as the printed form). */
function payslip_pdf(array $it, ?int $branchId = null, bool $copy = false): string
{
    $pdf = new SimplePDF();
    $pdf->addPage();
    payslip_pdf_block($pdf, $it, 50, $branchId, $copy);
    return $pdf->output();
}

/** Draw one payslip on a PDF page starting at $top; returns the Y coordinate after it. */
function payslip_pdf_block(SimplePDF $pdf, array $it, float $top, ?int $branchId = null, bool $copy = false): float
{
    $b = payslip_blocks($it, $branchId);
    $branchRow = $b['branch'];
    $W = $pdf->width();
    $L = 36;
    $R = $W - 36;
    $y = $top;
    $company = $branchRow ? ($branchRow['company_name'] ?: $branchRow['branch_name']) : ($it['default_branch']['company_name'] ?? setting('company_name'));
    $pdf->rect($L - 6, $y - 16, $R - $L + 12, 350, null, true, 0.75);
    $pdf->text(($L + $R) / 2, $y, $company, 13, true, 'C');
    $y += 12;
    $pdf->text(($L + $R) / 2, $y, (trim((string)setting('payslip_title', '')) ?: 'PAYSLIP') . ($branchRow ? ' - ' . $branchRow['branch_name'] : '') . '  ' . $it['run_no'], 8, false, 'C', 0.35);
    $y += 14;
    if ($copy) {
        $pdf->text(($L + $R) / 2 - 55, $y + 9, '( COPY )', 13, true, 'C');
        $y += 16;
    }
    $rate = $it['rate_type'] === 'daily' ? (float)$it['daily_rate'] : (float)$it['monthly_rate'];
    $rows = [
        ['Employee ID', $it['employee_no'], 'Period', date('m/d/Y', strtotime($it['period_start'])) . ' - ' . date('m/d/Y', strtotime($it['period_end']))],
        ["Employee's Name", full_name($it), 'Pay Date', date('m/d/Y', strtotime($it['pay_date']))],
        ['Department', (string)$it['org_unit_name'], 'Company Name', $company],
        ['Basic Rate', number_format($rate, 2), 'Exemption Code', (string)($it['exemption_code'] ?: ($it['is_mwe'] ? 'MWE' : 'Z'))],
    ];
    foreach ($rows as $r) {
        $pdf->text($L, $y, $r[0], 8, false, 'L', 0.2);
        $pdf->text($L + 95, $y, ': ' . $r[1], 8.5, true);
        $pdf->text($L + 285, $y, $r[2], 8, false, 'L', 0.2);
        $pdf->text($L + 365, $y, ': ' . mb_strimwidth((string)$r[3], 0, 34, '…'), 8.5, true);
        $y += 12;
    }
    $y += 4;
    $c1 = $L;
    $c2 = $L + 268;
    $c3 = $L + 390;
    $pdf->line($L, $y, $R, $y, 1);
    $y += 12;
    $pdf->text($c1 + 60, $y, 'Earnings', 9.5, true, 'C');
    $pdf->text($c2 + 50, $y, 'Deductions', 9.5, true, 'C');
    $pdf->text($c3 + 55, $y, $branchRow ? 'Summary' : 'YTD Information', 9.5, true, 'C');
    $y += 4;
    $pdf->line($c1, $y, $c2 - 8, $y, 0.6);
    $pdf->line($c2, $y, $c3 - 8, $y, 0.6);
    $pdf->line($c3, $y, $R, $y, 0.6);
    $pdf->line($c2 - 12, $y - 16, $c2 - 12, $y + 210, 0.6);
    $pdf->line($c3 - 12, $y - 16, $c3 - 12, $y + 210, 0.6);
    $startY = $y + 11;

    // earnings: two sub-columns
    $ey = $startY;
    foreach ($b['left'] as $r) {
        $lbl = ($r['label'] !== '' ? $r['label'] : '') . ($r['sub'] !== '' ? ($r['label'] !== '' ? ' ' : '     ') . $r['sub'] : '');
        $pdf->text($c1, $ey, $lbl, 7.5, $r['bold']);
        $pdf->text($c1 + 125, $ey, ps_amt((float)$r['amount']), 7.5, false, 'R');
        $ey += 9.2;
    }
    $ry = $startY;
    foreach ($b['right'] as $r) {
        $lbl = ($r['label'] !== '' ? $r['label'] : '') . ($r['sub'] !== '' ? ($r['label'] !== '' ? ' ' : '     ') . $r['sub'] : '');
        $pdf->text($c1 + 135, $ry, $lbl, 7.5, $r['bold']);
        $pdf->text($c2 - 16, $ry, ps_amt((float)$r['amount']), 7.5, false, 'R');
        $ry += 9.2;
    }
    $ey = max($ey, $ry) + 4;
    foreach (array_slice($b['other'], 0, 6) as $l) {
        $pdf->text($c1, $ey, mb_strimwidth($l['description'], 0, 40, '…'), 7.5);
        $pdf->text($c2 - 16, $ey, ps_amt((float)$l['amount']), 7.5, false, 'R');
        $ey += 9.2;
    }
    $ey += 3;
    $pdf->text($c1, $ey, 'GROSS PAY', 9, true);
    $pdf->text($c2 - 16, $ey, ps_amt($b['gross']), 9, true, 'R');

    // deductions
    $dy = $startY;
    if ($branchRow) {
        $pdf->text($c2, $dy, sprintf('Days worked: %s', number_format((float)$branchRow['days_worked'], 2)), 7.5);
        $dy += 10;
        $pdf->text($c2, $dy, sprintf('Regular hours: %s', number_format((float)$branchRow['reg_hours'], 2)), 7.5);
        $dy += 10;
        $pdf->text($c2, $dy, sprintf('OT hours: %s', number_format((float)$branchRow['ot_hours'], 2)), 7.5);
        $dy += 14;
    }
    if ($branchRow && empty($b['is_home'])) {
        $pdf->text($c2, $dy, 'No deduction is taken from this', 7, false, 'L', 0.35);
        $dy += 9;
        $pdf->text($c2, $dy, 'branch; it is paid in full.', 7, false, 'L', 0.35);
    } else {
        foreach ($b['ded'] as $d) {
            $pdf->text($c2, $dy, $d['label'], 8, true);
            $pdf->text($c3 - 16, $dy, ps_amt((float)$d['amount']), 8, false, 'R');
            $dy += 10;
        }
        $pdf->text($c2, $dy, 'Tickets', 8, true);
        $pdf->text($c3 - 16, $dy, ps_amt($b['tickets']), 8, false, 'R');
        $dy += 12;
        foreach (array_slice($b['ded_lines'], 0, 8) as $d) {
            $pdf->text($c2, $dy, mb_strimwidth($d['label'], 0, 26, '…'), 7.5);
            $pdf->text($c3 - 16, $dy, ps_amt((float)$d['amount']), 7.5, false, 'R');
            $dy += 9.2;
        }
        $dy += 4;
        $pdf->text($c2, $dy, 'Total Deductions', 8.5, true);
        $pdf->text($c3 - 16, $dy, ps_amt($b['total_ded']), 8.5, true, 'R');
    }

    // YTD
    $yy = $startY;
    if (!$branchRow && setting('payslip_show_ytd', '1') === '1') {
        $ytd = $b['ytd'];
        $pairs = [
            ['YTD Earnings', $ytd['earn_prior']], ['Current Earnings', $ytd['earn_cur']], ['Totals', $ytd['earn_prior'] + $ytd['earn_cur']],
            ['YTD Taxable Income', $ytd['taxable_prior']], ['Cur. Taxable Income', $ytd['taxable_cur']], ['Totals', $ytd['taxable_prior'] + $ytd['taxable_cur']],
            ['YTD Tax', $ytd['tax_prior']], ['Current Tax', $ytd['tax_cur']], ['Totals', $ytd['tax_prior'] + $ytd['tax_cur']],
        ];
        foreach ($pairs as $i => $p) {
            $bold = $p[0] === 'Totals';
            $pdf->text($c3, $yy, $p[0], 8, $bold);
            $pdf->text($R, $yy, ps_amt((float)$p[1]), 8, $bold, 'R');
            $yy += 10;
        }
    }
    $yy += 4;
    $disc = wordwrap((string)setting('payslip_disclaimer', ''), 42, "\n", true);
    foreach (array_slice(explode("\n", $disc), 0, 7) as $dline) {
        $pdf->text($c3, $yy, $dline, 6.5, false, 'L', 0.25);
        $yy += 7.6;
    }
    $bottom = max($ey, $dy, $yy) + 16;
    $pdf->rect($c3 - 4, $bottom - 11, $R - $c3 + 8, 17, 0.12, false);
    $pdf->text($c3, $bottom, $branchRow ? 'Net Pay (branch)' : 'Net Pay', 10, true, 'L', 1);
    $pdf->text($R - 4, $bottom, ps_amt($b['net']), 10, true, 'R', 1);
    $bottom += 30;
    $pdf->line($c3, $bottom - 8, $R, $bottom - 8, 0.6);
    $pdf->text(($c3 + $R) / 2, $bottom, setting('payslip_sig_label', 'HR Manager'), 8, false, 'C');
    if (setting('payslip_sig_name', '')) {
        $pdf->text(($c3 + $R) / 2, $bottom - 11, (string)setting('payslip_sig_name', ''), 8.5, true, 'C');
    }
    $bottom += 12;
    if (!$branchRow && $b['balances'] && setting('payslip_show_balances', '1') === '1') {
        $pdf->line($L, $bottom - 6, $R, $bottom - 6, 0.5);
        $pdf->text($L, $bottom + 4, 'Outstanding Balances:', 8, true);
        $bottom += 14;
        foreach (array_slice($b['balances'], 0, 6) as $bal) {
            $pdf->text($L + 8, $bottom, $bal['label'] . ' ' . $bal['ref'], 7.5);
            $pdf->text($L + 230, $bottom, ps_amt($bal['amount']), 7.5, false, 'R');
            $bottom += 9.5;
        }
    }
    return $bottom + 10;
}

/** Several payslips in one PDF (2 per page). */
function payslips_pdf(array $items, bool $copy = false): string
{
    $pdf = new SimplePDF();
    $perPage = max(1, min(2, (int)setting('payslip_per_page', 2)));
    $i = 0;
    foreach ($items as $it) {
        if ($i % $perPage === 0) {
            $pdf->addPage();
        }
        payslip_pdf_block($pdf, $it, $i % $perPage === 0 ? 50 : 460, $it['_branch'] ?? null, $copy || payslip_is_copy($it));
        $i++;
    }
    if (!$i) {
        $pdf->addPage();
    }
    return $pdf->output();
}

/**
 * Drill-down of the "Cur. Taxable Income" figure of a payslip: how the gross pay became the
 * taxable income, and how the withholding tax was read from the table. Built from the stored
 * payroll lines, so it explains exactly what was paid, not a recomputation.
 */
function payslip_tax_breakdown(array $it): array
{
    $earn = array_filter($it['lines'], fn($l) => $l['line_type'] === 'earning');
    // several locations: name the location of each line
    if (count($it['branches']) > 1) {
        $bn = [];
        foreach ($it['branches'] as $br) {
            $bn[(int)$br['branch_id']] = $br['branch_name'];
        }
        foreach ($earn as &$l) {
            if ($l['branch_id'] && isset($bn[(int)$l['branch_id']])) {
                $l['description'] = ($l['description'] ?: $l['code']) . ' (' . $bn[(int)$l['branch_id']] . ')';
            }
        }
        unset($l);
    }
    $taxableLines = [];
    $nonTaxLines = [];
    foreach ($earn as $l) {
        if ((float)$l['amount'] == 0) {
            continue;
        }
        if ((int)$l['taxable'] === 1) {
            $taxableLines[] = $l;
        } else {
            $nonTaxLines[] = $l;
        }
    }
    $sumT = array_sum(array_map(fn($l) => (float)$l['amount'], $taxableLines));
    $sumN = array_sum(array_map(fn($l) => (float)$l['amount'], $nonTaxLines));
    $base = (string)setting('taxable_base', 'gross_less_govt');
    $mand = [
        ['SSS contribution (employee share)', (float)$it['sss_ee']],
        ['PhilHealth contribution (employee share)', (float)$it['phic_ee']],
        ['Pag-IBIG contribution (employee share)', (float)$it['hdmf_ee']],
    ];
    $mandTotal = $base === 'gross' ? 0 : array_sum(array_column($mand, 1));
    $exempt = false;
    $taxLine = null;
    foreach ($it['lines'] as $l) {
        if ($l['code'] === 'WTAX') {
            $taxLine = $l;
            $exempt = strpos((string)$l['description'], 'exempt') !== false;
        }
    }
    $pg = row('SELECT pg.* FROM pay_groups pg JOIN payroll_runs r ON r.pay_group_id = pg.id WHERE r.id = ?', [(int)$it['run_id']]) ?: [];
    $table = ($pg['tax_type'] ?? '') ?: (string)$it['frequency'];
    $taxable = (float)$it['taxable_income'];
    $bracket = null;
    foreach (tax_brackets_for($table, $it['period_end']) as $b) {
        if ($taxable >= (float)$b['comp_from']) {
            $bracket = $b;
        }
    }
    $tableTax = $bracket ? max(0, round((float)$bracket['fixed_tax'] + ($taxable - (float)$bracket['comp_from']) * (float)$bracket['rate_pct'] / 100, 2)) : 0.0;
    $notes = array_values(array_map(fn($l) => $l['description'], array_filter($it['lines'], fn($l) => $l['line_type'] === 'info' && in_array($l['code'], ['ANNUALIZED'], true))));
    return [
        'taxable_lines' => $taxableLines, 'nontax_lines' => $nonTaxLines, 'sum_taxable' => $sumT, 'sum_nontax' => $sumN,
        'gross' => (float)$it['gross_pay'], 'base' => $base, 'mandatory' => $mand, 'mandatory_total' => $mandTotal,
        'derived' => round(max(0, ($base === 'basic_less_govt' ? (float)$it['basic_earned'] : $sumT) - $mandTotal), 2),
        'taxable' => $taxable, 'exempt' => $exempt, 'table' => $table, 'tax_mode' => $pg['tax_mode'] ?? 'every', 'bracket' => $bracket,
        'table_tax' => $tableTax, 'tax' => (float)$it['withholding_tax'], 'tax_line' => $taxLine, 'notes' => $notes,
        'ot_taxable' => setting('ot_taxable', '1') === '1',
    ];
}
