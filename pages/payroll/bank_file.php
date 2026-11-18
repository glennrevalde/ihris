<?php
/**
 * Bank payroll credit file generation.
 * Formats provided: generic CSV, and a fixed-width text layout (header / detail / trailer with hash total)
 * of the kind required by Philippine payroll-servicing banks. Adjust field widths to your bank's specification.
 * v16: opened from the menu (no payroll run given) it opens on the latest computed / finalized run, with a
 * payroll run picker; Employee group filter added next to the company / branch.
 * v36: BDO File (BDO Regular ATM Payroll File Format, Business Online Banking): a tab-delimited .txt named
 * Company code + upload date (MMDDYY) + batch number (NN), one line per credit: the 12-digit account number, a
 * single tab and the amount (12345.00). The BDO company code is saved per company / branch and the 2-digit batch
 * number is incremented by itself.
 */
$runs = pairs("SELECT r.id, CONCAT(r.run_no, ' — ', pg.code, ' ', DATE_FORMAT(r.period_start, '%m/%d/%y'), '-', DATE_FORMAT(r.period_end, '%m/%d/%y'), ' (', r.status, ')')
               FROM payroll_runs r JOIN pay_groups pg ON pg.id = r.pay_group_id WHERE r.status IN ('computed','finalized') ORDER BY r.period_end DESC, r.id DESC LIMIT 60");
$runId = (int)get('run_id', 0) ?: (int)(array_key_first($runs) ?: 0);
$run = $runId ? payroll_run($runId) : null;
$branchId = get('branch_id', '');
$groupId = (int)get('employee_group_id', 0);
$items = $run ? rows('SELECT pi.*, e.employee_no, e.first_name, e.last_name, e.middle_name, e.suffix, e.bank_name, e.bank_account_no, e.bank_account_type FROM payroll_items pi JOIN employees e ON e.id = pi.employee_id
               WHERE pi.run_id = ? AND pi.net_pay > 0' . ($branchId !== '' ? ' AND pi.default_branch_id = ' . (int)$branchId : '') . ($groupId ? ' AND e.employee_group_id = ' . $groupId : '')
               . salary_scope_sql('e') . ' ORDER BY e.last_name', [$run['id']]) : [];
// v32: the employees on the user's salary restriction list are left out (their net pay would show)
$salHidden = $run ? salary_hidden_count('FROM payroll_items pi JOIN employees e ON e.id = pi.employee_id WHERE pi.run_id = ? AND pi.net_pay > 0'
    . ($branchId !== '' ? ' AND pi.default_branch_id = ' . (int)$branchId : '') . ($groupId ? ' AND e.employee_group_id = ' . $groupId : '') . user_scope_sql('e'), [$run['id']]) : 0;
// account type Cash = paid in cash (Cash Payment Listing): never in the bank file, even with an account number
$withAcct = array_values(array_filter($items, fn($i) => trim((string)$i['bank_account_no']) !== '' && strtolower((string)$i['bank_account_type']) !== 'cash'));
$noAcct = array_values(array_filter($items, fn($i) => trim((string)$i['bank_account_no']) === '' || strtolower((string)$i['bank_account_type']) === 'cash'));
// --- v36: BDO File
$bdoKey = $branchId !== '' ? 'b' . (int)$branchId : 'default';
$bdoCode = (string)(setting('bdo_code_' . $bdoKey) ?? '') ?: ($bdoKey !== 'default' ? (string)setting('bdo_code_default', '') : '');
$bdoDaily = setting('bdo_batch_daily', '1') !== '0';
/** The next batch number for a company code and upload date: after the last one; 01 on a new upload date when "every day". */
$bdoNext = function (string $code, string $mmddyy) use ($bdoDaily): int {
    $last = (string)setting('bdo_batch_' . strtoupper($code), '');
    [$lastDate, $lastNo] = array_pad(explode('|', $last), 2, '0');
    if ($last === '' || ($bdoDaily && $lastDate !== $mmddyy)) {
        return 1;
    }
    $n = (int)$lastNo + 1;
    return $n > 99 ? 1 : $n;
};
/** A credit of the BDO file: the account as 12 digits (10 to 12 digits accepted, padded with zeros), null when not valid. */
$bdoAcct = function (string $acct): ?string {
    $d = preg_replace('/\D/', '', $acct);
    return strlen($d) >= 10 && strlen($d) <= 12 ? str_pad($d, 12, '0', STR_PAD_LEFT) : null;
};
$isBdo = fn($i) => trim((string)$i['bank_name']) === '' || preg_match('/\bBDO\b|BANCO\s+DE\s+ORO/i', (string)$i['bank_name']);
$bdoRows = array_values(array_filter($withAcct, fn($i) => $isBdo($i) && $bdoAcct((string)$i['bank_account_no']) !== null));
$bdoOut = array_values(array_filter($withAcct, fn($i) => !$isBdo($i) || $bdoAcct((string)$i['bank_account_no']) === null));
$bdoLines = array_map(fn($i) => $bdoAcct((string)$i['bank_account_no']) . "\t" . number_format((float)$i['net_pay'], 2, '.', ''), $bdoRows);
if (is_post() && $run && in_array(post('_action'), ['bdo', 'bdo_save'], true)) {
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)post('bdo_code')));
    set_setting('bdo_code_' . $bdoKey, $code);
    set_setting('bdo_batch_daily', post('bdo_daily') ? '1' : '0');
    if (post('_action') === 'bdo_save') {
        flash('success', 'BDO company code saved' . ($branchId !== '' ? ' for this company / branch' : '') . '.');
        redirect('bank_file', array_filter(['run_id' => $run['id'], 'branch_id' => $branchId, 'employee_group_id' => $groupId ?: null], fn($v) => $v !== null && $v !== ''));
    }
    $up = (string)post('bdo_date');
    $upDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $up) && strtotime($up) ? $up : today();
    $mmddyy = date('mdy', strtotime($upDate));
    $batch = (int)post('bdo_batch');
    // the batch number shown was the next one of today's date: another upload date takes its own next number,
    // unless the user typed a number himself
    if ($batch === (int)post('bdo_batch_shown') && $mmddyy !== (string)post('bdo_date_shown')) {
        $batch = $bdoNext($code, $mmddyy);
    }
    if ($code === '' || $batch < 1 || $batch > 99 || !$bdoLines) {
        flash('error', $code === '' ? 'Enter the company code provided by BDO.' : ($bdoLines ? 'The batch number is 01 to 99.' : 'No BDO credit to put in the file.'));
        redirect('bank_file', array_filter(['run_id' => $run['id'], 'branch_id' => $branchId, 'employee_group_id' => $groupId ?: null], fn($v) => $v !== null && $v !== ''));
    }
    $nn = str_pad((string)$batch, 2, '0', STR_PAD_LEFT);
    set_setting('bdo_batch_' . $code, $mmddyy . '|' . $nn);
    $name = $code . $mmddyy . $nn . '.txt';
    audit('payroll', 'bank_file', 'payroll_runs', $run['id'], ['format' => 'bdo', 'file' => $name, 'count' => count($bdoLines), 'total' => array_sum(array_map(fn($i) => (float)$i['net_pay'], $bdoRows))]);
    header('Content-Type: text/plain; charset=us-ascii');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: no-store');
    echo implode("\r\n", $bdoLines);
    exit;
}
$bdoToday = date('mdy');
$bdoBatch = $bdoCode !== '' ? $bdoNext($bdoCode, $bdoToday) : 1;
$fmt = get('format');
if ($fmt && $run) {
    if ($run['status'] !== 'finalized') {
        flash('warning', 'Note: this run is not yet finalized.');
    }
    $br = $branchId !== '' ? row('SELECT * FROM branches WHERE id = ?', [$branchId]) : row('SELECT * FROM branches ORDER BY id LIMIT 1');
    $total = array_sum(array_map(fn($i) => (float)$i['net_pay'], $withAcct));
    audit('payroll', 'bank_file', 'payroll_runs', $run['id'], ['format' => $fmt, 'count' => count($withAcct), 'total' => $total]);
    if ($fmt === 'csv') {
        csv_download('bankfile_' . $run['run_no'] . '.csv', ['Account Number', 'Account Name', 'Amount', 'Employee No', 'Remarks'],
            array_map(fn($i) => [preg_replace('/\D/', '', $i['bank_account_no']), strtoupper(full_name($i)), number_format((float)$i['net_pay'], 2, '.', ''), $i['employee_no'], 'PAYROLL ' . $run['period_end']], $withAcct));
    }
    // fixed-width text
    $pad = fn($s, $n) => str_pad(substr((string)$s, 0, $n), $n);
    $num = fn($v, $n) => str_pad((string)(int)round($v * 100), $n, '0', STR_PAD_LEFT);
    $hash = 0;
    $lines = [];
    $lines[] = 'H' . $pad($br['bank_company_code'] ?? '', 10) . $pad(preg_replace('/\D/', '', (string)($br['bank_account_no'] ?? '')), 16) . date('Ymd', strtotime($run['pay_date'])) . $num($total, 15) . str_pad((string)count($withAcct), 6, '0', STR_PAD_LEFT) . $pad(strtoupper($br['company_name'] ?? ''), 40);
    foreach ($withAcct as $i) {
        $acct = preg_replace('/\D/', '', $i['bank_account_no']);
        $hash += (int)substr($acct, -10);
        $lines[] = 'D' . $pad($acct, 16) . $num($i['net_pay'], 15) . $pad(strtoupper(full_name($i)), 40) . $pad($i['employee_no'], 12);
    }
    $lines[] = 'T' . str_pad((string)count($withAcct), 6, '0', STR_PAD_LEFT) . $num($total, 15) . str_pad((string)$hash, 18, '0', STR_PAD_LEFT);
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="bankfile_' . $run['run_no'] . '.txt"');
    echo implode("\r\n", $lines) . "\r\n";
    exit;
}
$q = ['run_id' => $run['id'] ?? null, 'branch_id' => $branchId, 'employee_group_id' => $groupId ?: null];
page_header('Bank File / Payroll ATM', ['subtitle' => 'Payroll credit file for the bank: the net pay of every employee with a bank account. Employees paid in cash or without an account are in the Cash Payment Listing.',
    'actions' => $run ? '<a class="btn" href="' . url('payroll_run', ['id' => $run['id']]) . '">Payroll run ' . h($run['run_no']) . '</a>' : '']);
echo salary_hidden_note($salHidden, 'this bank file', 'Their credits must be generated by a user who may see their pay.');
?>
<form class="card filters" method="get"><input type="hidden" name="p" value="bank_file">
  <label>Payroll run<select name="run_id" data-autosubmit><?= options($runs, $run['id'] ?? null, $runs ? null : 'No computed payroll run yet') ?></select></label>
  <label>Employer / branch<select name="branch_id" data-autosubmit><?= options(branch_options(false), $branchId, 'All') ?></select></label>
  <label>Employee group<select name="employee_group_id" data-autosubmit><?= options(emp_group_options(), $groupId ?: null, 'All groups') ?></select></label>
  <button class="btn btn-primary">Apply</button>
</form>
<?php if (!$run): ?>
  <div class="card"><div class="empty-state"><h3>No payroll run to pay</h3><p class="muted">The bank file is made from a computed or finalized payroll run.</p><a class="btn btn-primary" href="<?= url('payroll_runs') ?>">Payroll runs</a></div></div>
<?php page_footer(); return; endif; ?>
<div class="card">
  <h2><?= h($run['run_no']) ?>, <?= h($run['pay_group_name'] ?? '') ?> <span class="muted small"><?= h(fmt_date($run['period_start'])) ?> to <?= h(fmt_date($run['period_end'])) ?>, pay date <?= h(fmt_date($run['pay_date'])) ?><?= $run['status'] !== 'finalized' ? ', not yet finalized' : '' ?></span></h2>
  <div class="stats" style="margin-top:12px"><?= stat_card('Credits', (string)count($withAcct)) ?><?= stat_card('Total amount', peso(array_sum(array_column($withAcct, 'net_pay')))) ?><?= stat_card('Cash or no bank account', (string)count($noAcct), 'listed in the Cash Payment Listing', count($noAcct) ? 'warn' : '') ?></div>
  <div class="form-actions">
    <a class="btn btn-primary" href="<?= h(url('bank_file', $q + ['format' => 'txt'])) ?>">Download fixed-width .txt</a>
    <a class="btn" href="<?= h(url('bank_file', $q + ['format' => 'csv'])) ?>">Download CSV</a>
  </div>
  <p class="small muted">Fixed-width layout: header (H, company code, funding account, credit date, total in centavos, count, company name), detail lines (D, account, amount, name, employee no.) and trailer (T, count, total, account hash). Match field widths to your bank's payroll-crediting specification.</p>
  <form method="post" class="bdo-box" data-reload-after action="<?= h(url('bank_file', $q)) ?>"><?= csrf_field() ?>
    <h3>BDO File</h3>
    <div class="sub">BDO Regular ATM Payroll File Format (Business Online Banking): a tab-delimited text file named company code + upload date (MMDDYY) + batch number, one line per credit: the 12-digit account number, a tab and the amount.</div>
    <div class="bdo-grid">
      <label class="field"><span>Company code (provided by BDO)<?= $branchId !== '' ? ', this company / branch' : '' ?></span><input name="bdo_code" value="<?= h($bdoCode) ?>" maxlength="20" placeholder="e.g. XXX" autocomplete="off" data-bdo-code></label>
      <label class="field"><span>Upload date</span><input type="date" name="bdo_date" value="<?= h(today()) ?>" data-bdo-date></label>
      <input type="hidden" name="bdo_batch_shown" value="<?= (int)$bdoBatch ?>"><input type="hidden" name="bdo_date_shown" value="<?= h($bdoToday) ?>">
      <label class="field"><span>Batch number (01 to 99)</span><input type="number" name="bdo_batch" value="<?= h(str_pad((string)$bdoBatch, 2, '0', STR_PAD_LEFT)) ?>" min="1" max="99" step="1" data-bdo-batch></label>
      <label class="check"><input type="checkbox" name="bdo_daily" value="1"<?= $bdoDaily ? ' checked' : '' ?>> Start again at 01 on every new upload date</label>
    </div>
    <p class="small" style="margin:10px 0 0">File name: <span class="bdo-name" data-bdo-name><?= h(($bdoCode ?: 'CODE') . $bdoToday . str_pad((string)$bdoBatch, 2, '0', STR_PAD_LEFT)) ?>.txt</span>
      &nbsp; <?= count($bdoRows) ?> credit(s), <?= peso(array_sum(array_map(fn($i) => (float)$i['net_pay'], $bdoRows))) ?>. The batch number goes up by one after each download.</p>
    <?php if ($bdoOut): ?><p class="small text-warn" style="margin:6px 0 0"><?= count($bdoOut) ?> credit(s) left out of the BDO file: another bank, or an account number that is not 10 to 12 digits (marked below).</p><?php endif; ?>
    <?php if ($bdoLines): ?><pre class="bdo-preview" aria-label="First lines of the BDO file"><?= h(implode("\n", array_slice($bdoLines, 0, 6)) . (count($bdoLines) > 6 ? "\n…" : '')) ?></pre><?php endif; ?>
    <div class="form-actions"><button class="btn btn-primary" name="_action" value="bdo"<?= $bdoLines ? '' : ' disabled' ?>>Download BDO File</button> <button class="btn" name="_action" value="bdo_save" formnovalidate>Save company code</button></div>
  </form>
</div>
<div class="card"><div class="table-wrap"><table class="table compact"><thead><tr><th>Employee</th><th>Bank</th><th>Account</th><th>Paid by</th><th class="num">Net pay</th></tr></thead><tbody>
<?php if (!$items) echo empty_row(5, 'No employee with a net pay for these filters.'); foreach ($items as $i): $cash = trim((string)$i['bank_account_no']) === '' || strtolower((string)$i['bank_account_type']) === 'cash'; ?><tr class="<?= $cash ? 'row-warn' : '' ?>"><td><?= h(full_name($i)) ?><br><small class="muted"><?= h($i['employee_no']) ?></small></td><td><?= h($i['bank_name']) ?></td><td class="mono"><?= h($i['bank_account_no'] ?: 'none') ?></td><td class="small"><?= $cash ? 'Cash (not in the file)' : 'Bank credit' ?><?php if (!$cash && (!$isBdo($i) || $bdoAcct((string)$i['bank_account_no']) === null)): ?><small class="d-block text-warn"><?= !$isBdo($i) ? 'Not in the BDO file: another bank' : 'Not in the BDO file: account not 10 to 12 digits' ?></small><?php endif; ?></td><td class="num"><?= money($i['net_pay']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php page_footer();
