<?php
/**
 * Payroll Journal (GL) — the accounting entry of a payroll run: salaries and benefits debited,
 * statutory payables, loan recoveries and net pay credited. Account codes come from the
 * Payroll Code Table and from the benefit / transaction / deduction codes.
 */
$runs = pairs("SELECT r.id, CONCAT(r.run_no, ' — ', pg.code, ' ', DATE_FORMAT(r.period_start, '%m/%d/%y'), '-', DATE_FORMAT(r.period_end, '%m/%d/%y'), ' (', r.status, ')')
               FROM payroll_runs r JOIN pay_groups pg ON pg.id = r.pay_group_id WHERE r.status IN ('computed','finalized') ORDER BY r.period_end DESC, r.id DESC LIMIT 60");
$runId = (int)get('run_id', 0) ?: (int)(array_key_first($runs) ?: 0);
if (!$runId) {
    page_header('Payroll Journal');
    echo '<div class="card"><div class="empty-state"><h3>No processed payroll run</h3><a class="btn btn-primary" href="' . url('payroll_runs') . '">Payroll runs</a></div></div>';
    page_footer();
    return;
}
$j = payroll_journal($runId);
$run = $j['run'];
$balanced = abs($j['debit'] - $j['credit']) < 0.05;

if (get('csv')) {
    csv_download('payroll_journal_' . $run['run_no'] . '.csv', ['Account code', 'Account title', 'Debit', 'Credit'],
        array_map(fn($a) => [$a['code'], $a['name'], number_format($a['debit'], 2, '.', ''), number_format($a['credit'], 2, '.', '')], $j['accounts']));
}
if (get('print')) {
    print_header('Payroll Journal — ' . $run['run_no']);
    echo company_letterhead();
    echo '<h1>Payroll Journal Entry</h1>';
    echo '<div class="doc-meta"><div><strong>' . h($run['run_no']) . '</strong>, ' . h($run['pay_group_name']) . '</div>'
        . '<div>' . h(fmt_date($run['period_start'])) . ' – ' . h(fmt_date($run['period_end'])) . ', pay date ' . h(fmt_date($run['pay_date'])) . '</div></div>';
    echo '<table class="doc-table"><thead><tr><th>Account</th><th>Account title</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead><tbody>';
    foreach ($j['accounts'] as $a) {
        echo '<tr><td>' . h($a['code']) . '</td><td>' . h($a['name']) . '</td><td class="num">' . ($a['debit'] ? h(money($a['debit'])) : '') . '</td><td class="num">' . ($a['credit'] ? h(money($a['credit'])) : '') . '</td></tr>';
    }
    echo '</tbody><tfoot><tr><td colspan="2">TOTAL</td><td class="num">' . h(money($j['debit'])) . '</td><td class="num">' . h(money($j['credit'])) . '</td></tr></tfoot></table>';
    echo signatory_block();
    print_footer();
    exit;
}
page_header('Payroll Journal (GL)', [
    'subtitle' => h($run['run_no']) . ', ' . h($run['pay_group_name']) . ', ' . h(fmt_date($run['period_start'])) . ' – ' . h(fmt_date($run['period_end'])),
    'actions' => '<a class="btn" target="_blank" href="' . url('rpt_journal', ['run_id' => $runId, 'print' => 1]) . '">Print</a>'
        . '<a class="btn" href="' . url('rpt_journal', ['run_id' => $runId, 'csv' => 1]) . '">Export CSV</a>'
        . '<a class="btn" href="' . url('gl_accounts') . '">Payroll Code Table</a>',
]);
?>
<form method="get" class="card filters">
  <input type="hidden" name="p" value="rpt_journal">
  <label>Payroll run<select name="run_id" data-autosubmit><?= options($runs, $runId) ?></select></label>
  <span class="spacer"></span>
  <span class="badge badge-<?= $balanced ? 'green' : 'red' ?>"><?= $balanced ? 'Balanced' : 'Out of balance by ' . money(abs($j['debit'] - $j['credit'])) ?></span>
</form>

<div class="card flush">
  <div class="card-head"><div><h2>Journal entry</h2><div class="sub"><?= count($j['accounts']) ?> account(s)</div></div></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Account code</th><th>Account title</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
      <tbody>
      <?php foreach ($j['accounts'] as $a): ?>
        <tr>
          <td><code><?= h($a['code']) ?></code></td>
          <td><?= h($a['name']) ?></td>
          <td class="num"><?= $a['debit'] ? money($a['debit']) : '' ?></td>
          <td class="num"><?= $a['credit'] ? money($a['credit']) : '' ?></td>
        </tr>
      <?php endforeach; ?>
      <?= $j['accounts'] ? '' : empty_row(4, 'Nothing to post for this run.') ?>
      </tbody>
      <tfoot><tr><td colspan="2">TOTAL</td><td class="num"><?= money($j['debit']) ?></td><td class="num"><?= money($j['credit']) ?></td></tr></tfoot>
    </table>
  </div>
</div>
<p class="help">Accounts are taken from each benefit, transaction and deduction code; anything without a code falls back to the accounts configured in Payroll Parameters › Annual / Acct. Config.</p>
<?php page_footer();
