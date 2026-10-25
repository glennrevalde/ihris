<?php
/**
 * Earnings & Deductions Report — the earnings and deductions coming from the Fixed Transactions, the
 * Employee's Transactions (one-time and with terms), the Employee Deductions & Loans (subsidiary
 * ledgers) and the Employee Benefits, listed per employee (M.A.D.A.S. Deduction Listing / Earning
 * Listing) or summarized per code. Salary items (basic pay, overtime, absences, night premium, paid
 * leaves, holidays) and the government contributions and tax are not part of it.
 * Report: Earnings only, Deductions only, or both.
 */
$runId = (int)get('run_id', 0);
// without a range, the month of the latest computed or finalized payroll (not an empty current month)
$lastEnd = (string)(val("SELECT MAX(period_end) FROM payroll_runs WHERE status IN ('computed','finalized')") ?: date('Y-m-d'));
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)get('from')) ? (string)get('from') : date('Y-m-01', strtotime($lastEnd));
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)get('to')) ? (string)get('to') : date('Y-m-t', strtotime($lastEnd));
$show = in_array(get('show'), ['earnings', 'deductions'], true) ? (string)get('show') : 'both';
$layout = get('layout') === 'summary' ? 'summary' : 'listing';
$f = prpt_filters();
$runs = pairs("SELECT r.id, CONCAT(r.run_no, ' — ', pg.code, ' ', DATE_FORMAT(r.period_start, '%m/%d/%y'), '-', DATE_FORMAT(r.period_end, '%m/%d/%y'))
               FROM payroll_runs r JOIN pay_groups pg ON pg.id = r.pay_group_id WHERE r.status IN ('computed','finalized') ORDER BY r.period_end DESC LIMIT 60");
if ($runId && !isset($runs[$runId])) {
    $runId = 0;
}
$scope = $runId ? ['run' => $runId] : ['from' => $from, 'to' => $to, 'any' => true];
$scopeLabel = $runId ? 'Payroll run ' . $runs[$runId] : fmt_date($from) . ' – ' . fmt_date($to);
$items = prpt_items($scope, $f);
$kinds = $show === 'both' ? ['earnings', 'deductions'] : [$show];
$reps = [];
foreach ($kinds as $k) {
    $reps[$k] = prpt_build($k, $items, ['code' => $f['code'], 'mode' => 'detail']);
}
// summary per code from the same lines
$summary = [];
foreach ($reps as $k => $rep) {
    foreach ($rep['groups'] as $g) {
        foreach ($g['units'] as $u) {
            foreach ($u['emps'] as $e) {
                foreach ($e['lines'] as $l) {
                    $key = $k . '|' . $l['code'];
                    $summary[$key] = $summary[$key] ?? ['type' => $k === 'earnings' ? 'Earning' : 'Deduction', 'code' => $l['code'], 'desc' => $l['desc'], 'source' => $l['source'], 'emps' => [], 'n' => 0, 'amount' => 0.0];
                    $summary[$key]['n']++;
                    $summary[$key]['emps'][(int)$e['emp']['employee_id']] = true;
                    $summary[$key]['amount'] += (float)$l['amount'];
                }
            }
        }
    }
}
$keep = array_filter(array_merge(['run_id' => $runId ?: null, 'from' => $runId ? null : $from, 'to' => $runId ? null : $to, 'show' => $show, 'layout' => $layout], $f), fn($v) => $v !== null && $v !== '');
$names = ['earnings' => 'Earning Listing', 'deductions' => 'Deduction Listing'];

if (get('csv')) {
    if ($layout === 'summary') {
        csv_download('earnings_deductions_summary.csv', ['Type', 'Code', 'Description', 'Source', 'Employees', 'Count', 'Amount'],
            array_map(fn($s) => [$s['type'], $s['code'], $s['desc'], $s['source'], count($s['emps']), $s['n'], round($s['amount'], 2)], array_values($summary)));
    }
    $head = null;
    $out = [];
    foreach ($reps as $k => $rep) {
        [$h, $lines] = prpt_csv($rep);
        $head = array_merge(['Report'], $k === 'earnings' && $show === 'both' ? array_merge(array_slice($h, 0, 9), ['Principal', 'Interest'], array_slice($h, 9)) : $h);
        foreach ($lines as $l) {
            if ($k === 'earnings' && $show === 'both') {
                $l = array_merge(array_slice($l, 0, 9), ['', ''], array_slice($l, 9));
            }
            $out[] = array_merge([$names[$k]], $l);
        }
    }
    if ($show === 'both' && $head) {
        $head = array_map(fn($x) => in_array($x, ['Deduction', 'Earning'], true) ? 'Item' : $x, $head);
    }
    audit('reports', 'export_earn_ded', 'report', null, $keep);
    csv_download('earnings_deductions_listing.csv', $head ?: ['Report'], $out);
}
$heading = $show === 'both' ? 'Earnings & Deductions Report' : $names[$show];
if (get('print')) {
    audit('reports', 'print_earn_ded', 'report', null, $keep);
    print_header($heading, true);
    echo company_letterhead($f['branch_id'] ? row('SELECT * FROM branches WHERE id = ?', [$f['branch_id']]) : null);
    echo '<h1>' . h($heading) . '</h1>';
    echo '<div class="doc-meta"><div>' . h($scopeLabel) . ($layout === 'summary' ? ', summary per code' : ', per employee') . '</div><div>Printed ' . h(fmt_dt(now())) . '</div></div>';
    echo report_selection_html($f, [], true);   // organizational unit, company / branch, code chosen on the form
    if ($layout === 'summary') {
        echo '<table class="doc-table"><thead><tr><th>Type</th><th>Code</th><th>Description</th><th>Source</th><th class="num">Employees</th><th class="num">Amount</th></tr></thead><tbody>';
        foreach ($summary as $s) {
            echo '<tr><td>' . h($s['type']) . '</td><td>' . h($s['code']) . '</td><td>' . h($s['desc']) . '</td><td>' . h($s['source']) . '</td><td class="num">' . count($s['emps']) . '</td><td class="num">' . h(money($s['amount'])) . '</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        foreach ($reps as $k => $rep) {
            if ($show === 'both') {
                echo '<h2 class="prpt-h">' . h($names[$k]) . '</h2>';
            }
            echo prpt_html($rep);
        }
    }
    echo signatory_block();
    print_footer();
    exit;
}

page_header('Earnings & Deductions Report', [
    'subtitle' => 'Earnings and deductions from Fixed Transactions, Employee\'s Transactions, Employee Term Loan/Payables and Employee Benefits, per employee. Salary items are not included.',
    'actions' => '<a class="btn" target="_blank" href="' . h(url('rpt_earn_ded', $keep + ['print' => 1])) . '">Print</a><a class="btn" href="' . h(url('rpt_earn_ded', $keep + ['csv' => 1])) . '">Export CSV</a>',
]);
?>
<form method="get" class="card filters">
  <input type="hidden" name="p" value="rpt_earn_ded">
  <fieldset class="seg-field"><legend>Report</legend><div class="seg">
    <label class="check"><input type="radio" name="show" value="both" <?= checked($show === 'both') ?>> Earnings and deductions</label>
    <label class="check"><input type="radio" name="show" value="earnings" <?= checked($show === 'earnings') ?>> Earnings only</label>
    <label class="check"><input type="radio" name="show" value="deductions" <?= checked($show === 'deductions') ?>> Deductions only</label>
  </div></fieldset>
  <label class="grow">Payroll run<select name="run_id"><?= options($runs, $runId ?: null, 'Date range below') ?></select></label>
  <label>From<input type="date" name="from" value="<?= h($from) ?>"></label>
  <label>To<input type="date" name="to" value="<?= h($to) ?>"></label>
  <label>Company / Branch<select name="branch_id"><?= options(pairs("SELECT id, CONCAT(COALESCE(branch_code, code), ' — ', name) FROM branches WHERE is_active = 1 ORDER BY company_code, branch_code, code"), $f['branch_id'], 'All companies') ?></select></label>
  <label>Organizational unit<select name="org_unit_id"><?= options(org_unit_options(), $f['org_unit_id'], 'All units') ?></select></label>
  <label>Code<input name="code" value="<?= h((string)$f['code']) ?>" placeholder="All codes" style="width:130px"></label>
  <fieldset class="seg-field"><legend>Layout</legend><div class="seg">
    <label class="check"><input type="radio" name="layout" value="listing" <?= checked($layout === 'listing') ?>> Per employee</label>
    <label class="check"><input type="radio" name="layout" value="summary" <?= checked($layout === 'summary') ?>> Summary per code</label>
  </div></fieldset>
  <button class="btn btn-primary">Apply</button>
</form>

<?php if ($layout === 'summary'): ?>
  <div class="card flush">
    <div class="card-head pad"><div><h2>Summary per code</h2><div class="sub"><?= h($scopeLabel) ?></div><?= report_selection_html($f) ?></div></div>
    <div class="table-wrap">
      <table class="table table-compact">
        <thead><tr><th>Type</th><th>Code</th><th>Description</th><th>Source</th><th class="num">Employees</th><th class="num">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($summary as $s): ?>
          <tr><td class="<?= $s['type'] === 'Earning' ? 'text-green' : 'text-amber' ?>"><?= h($s['type']) ?></td>
            <td><a href="<?= h(url('rpt_earn_ded', array_merge($keep, ['code' => $s['code'], 'layout' => 'listing']))) ?>"><?= h($s['code']) ?></a></td>
            <td><?= h($s['desc']) ?></td><td class="small"><?= h($s['source']) ?></td><td class="num"><?= count($s['emps']) ?></td><td class="num"><strong><?= money($s['amount']) ?></strong></td></tr>
        <?php endforeach; ?>
        <?= $summary ? '' : empty_row(6, 'No earning or deduction from these sources for this selection.') ?>
        </tbody>
      </table>
    </div>
  </div>
<?php else: ?>
  <?php foreach ($reps as $k => $rep): ?>
    <div class="card flush prpt-card">
      <div class="card-head pad"><div><h2><?= h($names[$k]) ?></h2><div class="sub"><?= h($scopeLabel) ?>, <?= (int)$rep['employees'] ?> employee(s), total <?= money($rep['grand']['amount'] ?? 0) ?></div><?= report_selection_html($f) ?></div></div>
      <div class="pad-x prpt-body"><?= prpt_html($rep) ?></div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php page_footer();
