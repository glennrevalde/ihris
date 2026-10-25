<?php
/**
 * Bank payroll credit file generation.
 * Formats provided: generic CSV, and a fixed-width text layout (header / detail / trailer with hash total)
 * of the kind required by Philippine payroll-servicing banks. Adjust field widths to your bank's specification.
 * v16: opened from the menu (no payroll run given) it opens on the latest computed / finalized run, with a
 * payroll run picker; Employee group filter added next to the company / branch.
 */
$runs = pairs("SELECT r.id, CONCAT(r.run_no, ' — ', pg.code, ' ', DATE_FORMAT(r.period_start, '%m/%d/%y'), '-', DATE_FORMAT(r.period_end, '%m/%d/%y'), ' (', r.status, ')')
               FROM payroll_runs r JOIN pay_groups pg ON pg.id = r.pay_group_id WHERE r.status IN ('computed','finalized') ORDER BY r.period_end DESC, r.id DESC LIMIT 60");
$runId = (int)get('run_id', 0) ?: (int)(array_key_first($runs) ?: 0);
$run = $runId ? payroll_run($runId) : null;
$branchId = get('branch_id', '');
$groupId = (int)get('employee_group_id', 0);
$items = $run ? rows('SELECT pi.*, e.employee_no, e.first_name, e.last_name, e.middle_name, e.suffix, e.bank_name, e.bank_account_no, e.bank_account_type FROM payroll_items pi JOIN employees e ON e.id = pi.employee_id
               WHERE pi.run_id = ? AND pi.net_pay > 0' . ($branchId !== '' ? ' AND pi.default_branch_id = ' . (int)$branchId : '') . ($groupId ? ' AND e.employee_group_id = ' . $groupId : '')
               . user_scope_sql('e') . ' ORDER BY e.last_name', [$run['id']]) : [];
// account type Cash = paid in cash (Cash Payment Listing): never in the bank file, even with an account number
$withAcct = array_values(array_filter($items, fn($i) => trim((string)$i['bank_account_no']) !== '' && strtolower((string)$i['bank_account_type']) !== 'cash'));
$noAcct = array_values(array_filter($items, fn($i) => trim((string)$i['bank_account_no']) === '' || strtolower((string)$i['bank_account_type']) === 'cash'));
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
</div>
<div class="card"><div class="table-wrap"><table class="table compact"><thead><tr><th>Employee</th><th>Bank</th><th>Account</th><th>Paid by</th><th class="num">Net pay</th></tr></thead><tbody>
<?php if (!$items) echo empty_row(5, 'No employee with a net pay for these filters.'); foreach ($items as $i): $cash = trim((string)$i['bank_account_no']) === '' || strtolower((string)$i['bank_account_type']) === 'cash'; ?><tr class="<?= $cash ? 'row-warn' : '' ?>"><td><?= h(full_name($i)) ?><br><small class="muted"><?= h($i['employee_no']) ?></small></td><td><?= h($i['bank_name']) ?></td><td class="mono"><?= h($i['bank_account_no'] ?: 'none') ?></td><td class="small"><?= $cash ? 'Cash (not in the file)' : 'Bank credit' ?></td><td class="num"><?= money($i['net_pay']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php page_footer();
