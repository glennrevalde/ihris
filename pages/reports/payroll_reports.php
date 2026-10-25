<?php
/**
 * Payroll report menus (M.A.D.A.S. System Reports), one screen per menu:
 *   rpt_periodical       Periodical Reports: the open payroll period, employee shares only
 *   rpt_monthly_contrib  Monthly Reports: closed periods of a month, employee and employer shares
 *   rpt_ytd_reports      YTD Reports: per payroll period and per employee over a date coverage
 *   rpt_bank_listing     Bank Deposit Listing: net pay of the employees paid through the bank
 *   rpt_cash_listing     Cash Payment Listing: net pay of the employees paid in cash
 * The figures come from app/engine/payreports.php.
 */
$route = (string)get('p');
$menu = ['rpt_periodical' => 'periodical', 'rpt_monthly_contrib' => 'monthly', 'rpt_ytd_reports' => 'ytd', 'rpt_bank_listing' => 'bank', 'rpt_cash_listing' => 'cash'][$route] ?? 'periodical';
$titles = ['periodical' => 'Periodical Reports', 'monthly' => 'Monthly Reports', 'ytd' => 'YTD Reports', 'bank' => 'Bank Deposit Listing', 'cash' => 'Cash Payment Listing'];
$subs = [
    'periodical' => 'Reports of an open payroll period: employee shares only, deductions and earnings per employee, bank and cash listings. A closed period moves to the Monthly Reports.',
    'monthly' => 'Contribution, loan and tax schedules of a month from its closed payroll periods. The employer share is computed when every period of the month is closed.',
    'ytd' => 'History of each report per payroll period and per employee over a date coverage, from the finalized payroll runs.',
    'bank' => 'Net pay of each employee whose salary is deposited to the bank, for the bank or the payroll ATM.',
    'cash' => 'Net pay of each employee paid in cash, for the cash payout.',
];
$f = prpt_filters();
$types = in_array($menu, ['bank', 'cash'], true) ? [$menu] : PRPT_MENU[$menu];
$type = (string)get('type', $types[0]);
if (!in_array($type, $types, true)) {
    $type = $types[0];
}
$mode = $menu === 'ytd' ? (get('mode') === 'summary' ? 'summary' : 'detail') : 'summary';

// ---------------------------------------------------------------- scope of each menu
$scope = null;
$scopeLabel = '';
$note = null;
$withEr = false;
$periods = [];
$runs = [];
if ($menu === 'periodical') {
    // only the payroll periods that are not closed (paid)
    foreach (rows("SELECT c.*, pg.code AS pay_group_code FROM attendance_cutoffs c JOIN pay_groups pg ON pg.id = c.pay_group_id
                   WHERE c.status <> 'locked' AND EXISTS (SELECT 1 FROM payroll_runs r WHERE r.cutoff_id = c.id AND r.status IN ('computed','finalized'))
                   ORDER BY c.period_end DESC LIMIT 60") as $c) {
        $periods[(int)$c['id']] = $c['pay_group_code'] . ': ' . fmt_date($c['period_start'], 'm/d/y') . ' - ' . fmt_date($c['period_end'], 'm/d/y') . ($c['description'] ? ' (' . $c['description'] . ')' : '');
    }
    $pid = (int)get('cutoff_id', 0);
    if (!isset($periods[$pid])) {
        $pid = (int)(array_key_first($periods) ?? 0);
    }
    if ($pid) {
        $scope = ['period' => $pid];
        $scopeLabel = 'Period ' . $periods[$pid];
    }
} elseif ($menu === 'monthly') {
    $ym = preg_match('/^\d{4}-\d{2}$/', (string)get('month')) ? (string)get('month') : date('Y-m', strtotime((string)(val("SELECT MAX(period_end) FROM attendance_cutoffs WHERE status = 'locked'") ?: today())));
    $scope = ['month' => $ym];
    $scopeLabel = 'For the month of ' . date('F Y', strtotime($ym . '-01'));
} elseif ($menu === 'ytd') {
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)get('from')) ? (string)get('from') : date('Y') . '-01-01';
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)get('to')) ? (string)get('to') : date('Y') . '-12-31';
    if ($to < $from) {
        [$from, $to] = [$to, $from];
    }
    $scope = ['from' => $from, 'to' => $to];
    $scopeLabel = fmt_date($from) . ' to ' . fmt_date($to);
} else {
    $runs = pairs("SELECT r.id, CONCAT(r.run_no, ' — ', pg.code, ' ', DATE_FORMAT(r.period_start, '%m/%d/%y'), '-', DATE_FORMAT(r.period_end, '%m/%d/%y'), ', pay date ', DATE_FORMAT(r.pay_date, '%m/%d/%y'), ' (', r.status, ')')
                   FROM payroll_runs r JOIN pay_groups pg ON pg.id = r.pay_group_id WHERE r.status IN ('computed','finalized') ORDER BY r.period_end DESC, r.id DESC LIMIT 80");
    $rid = (int)get('run_id', 0);
    if (!isset($runs[$rid])) {
        $rid = (int)(array_key_first($runs) ?? 0);
    }
    if ($rid) {
        $scope = ['run' => $rid];
        $scopeLabel = $runs[$rid];
    }
}

$items = $scope ? prpt_items($scope, $f) : [];
if ($menu === 'monthly' && $scope) {
    [$complete, $openP] = prpt_month_status($scope['month'], $items ?: prpt_items(['month' => $scope['month']], []));
    $withEr = $complete;
    if (!$complete && in_array($type, ['sss', 'hdmf', 'phic'], true)) {
        $note = $openP ? 'Employer share not computed: the month is not complete. Still open: '
            . implode(', ', array_map(fn($c) => $c['pay_group_code'] . ' ' . fmt_date($c['period_start'], 'M d') . ' – ' . fmt_date($c['period_end'], 'M d'), $openP))
            . '. Close them in Period Closing; only closed periods are counted here.'
            : 'Employer share not computed: no closed payroll period in this month yet.';
    }
}
$rep = prpt_build($type, $items, ['with_er' => $withEr, 'mode' => $mode, 'code' => $f['code']]);
$keep = array_filter(array_merge(['type' => $type, 'mode' => $menu === 'ytd' ? $mode : null, 'cutoff_id' => $scope['period'] ?? null, 'month' => $scope['month'] ?? null,
    'from' => $scope['from'] ?? null, 'to' => $scope['to'] ?? null, 'run_id' => $scope['run'] ?? null], $f), fn($v) => $v !== null && $v !== '');

$title = in_array($menu, ['bank', 'cash'], true) ? $titles[$menu] : $rep['title'];
if (get('export')) {
    [$head, $lines] = prpt_csv($rep);
    audit('reports', 'export_' . $menu . '_' . $type, 'report', null, $keep);
    csv_download(strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $titles[$menu] . ' ' . $title)) . '.csv', $head, $lines);
    exit;
}
$filterText = [];
if ($f['branch_id']) {
    $filterText[] = (string)val("SELECT CONCAT(COALESCE(branch_code, code), ' ', name) FROM branches WHERE id = ?", [$f['branch_id']]);
}
if ($f['org_unit_id']) {
    $filterText[] = (string)val('SELECT name FROM org_units WHERE id = ?', [$f['org_unit_id']]);
}
if ($f['employee_group_id']) {
    $filterText[] = 'Group ' . (string)val('SELECT name FROM emp_groups WHERE id = ?', [$f['employee_group_id']]);
}
if ($f['class_group_id']) {
    $filterText[] = 'Class ' . (string)val('SELECT name FROM class_groups WHERE id = ?', [$f['class_group_id']]);
}
if ($f['employee_type_id']) {
    $filterText[] = 'Type ' . (string)val('SELECT name FROM emp_types WHERE id = ?', [$f['employee_type_id']]);
}
if ($f['emp_from'] || $f['emp_to']) {
    $filterText[] = 'Employees ' . ($f['emp_from'] ?: 'first') . ' to ' . ($f['emp_to'] ?: 'last');
}
if ($f['code']) {
    $filterText[] = 'Code ' . $f['code'];
}

if (get('print')) {
    audit('reports', 'print_' . $menu . '_' . $type, 'report', null, $keep);
    print_header($titles[$menu] . ' — ' . $title, true, 'legal');
    echo company_letterhead($f['branch_id'] ? row('SELECT * FROM branches WHERE id = ?', [$f['branch_id']]) : null);
    echo '<h1>' . h($title) . '</h1>';
    echo '<div class="doc-meta"><div><strong>' . h($scopeLabel) . '</strong>' . ($menu === 'ytd' ? ', ' . ($mode === 'detail' ? 'detail per payroll period' : 'summary per employee') : '') . '</div>'
        . '<div>Printed ' . h(fmt_dt(now())) . '</div></div>' . report_selection_html($f, [], true);
    if ($note) {
        echo '<p class="small"><em>' . h($note) . '</em></p>';
    }
    echo prpt_html($rep);
    echo signatory_block();
    print_footer();
    exit;
}

page_header($titles[$menu], [
    'subtitle' => $subs[$menu],
    'actions' => $scope ? '<a class="btn" target="_blank" href="' . h(url($route, $keep + ['print' => 1])) . '">Print</a><a class="btn" href="' . h(url($route, $keep + ['export' => 1])) . '">Export CSV</a>' : '',
]);
$branchOpts = pairs("SELECT id, CONCAT(COALESCE(branch_code, code), ' — ', name) FROM branches WHERE is_active = 1 ORDER BY company_code, branch_code, code");
?>
<?php if (count($types) > 1): ?>
  <nav class="rpt-types" aria-label="Report">
    <?php foreach ($types as $t): ?>
      <a class="rpt-type<?= $t === $type ? ' is-on' : '' ?>" href="<?= h(url($route, array_merge($keep, ['type' => $t]))) ?>"><?= h(PRPT_TYPES[$t]) ?></a>
    <?php endforeach; ?>
    <?php if ($menu === 'ytd'): ?>
      <a class="rpt-type" href="<?= h(url('rpt_ytd', ['year' => substr($scope['to'], 0, 4)])) ?>">BIR Form 2316 / Alphalisting</a>
    <?php endif; ?>
  </nav>
<?php endif; ?>

<form method="get" class="card filters">
  <input type="hidden" name="p" value="<?= h($route) ?>"><input type="hidden" name="type" value="<?= h($type) ?>">
  <?php if ($menu === 'periodical'): ?>
    <label class="grow">Open payroll period<select name="cutoff_id"><?= options($periods, $scope['period'] ?? null, $periods ? null : 'No open period with a payroll') ?></select></label>
  <?php elseif ($menu === 'monthly'): ?>
    <label>Month<input type="month" name="month" value="<?= h($scope['month']) ?>"></label>
  <?php elseif ($menu === 'ytd'): ?>
    <label>Date coverage from<input type="date" name="from" value="<?= h($scope['from']) ?>"></label>
    <label>To<input type="date" name="to" value="<?= h($scope['to']) ?>"></label>
    <label>Employee no. from<input name="emp_from" value="<?= h((string)$f['emp_from']) ?>" placeholder="First" style="width:120px"></label>
    <label>To<input name="emp_to" value="<?= h((string)$f['emp_to']) ?>" placeholder="Last" style="width:120px"></label>
  <?php else: ?>
    <label class="grow">Payroll run<select name="run_id"><?= options($runs, $scope['run'] ?? null, $runs ? null : 'No processed payroll yet') ?></select></label>
  <?php endif; ?>
  <label>Company / Branch<select name="branch_id"><?= options($branchOpts, $f['branch_id'], 'All companies') ?></select></label>
  <label><?= in_array($menu, ['bank', 'cash'], true) ? 'Division / Department' : 'Organizational unit' ?><select name="org_unit_id"><?= options(org_unit_options(), $f['org_unit_id'], 'All units') ?></select></label>
  <label>Employee group<select name="employee_group_id"><?= options(emp_group_options(), $f['employee_group_id'], 'All groups') ?></select></label>
  <?php if (in_array($menu, ['bank', 'cash'], true)): ?>
    <label>Employee type<select name="employee_type_id"><?= options(pairs("SELECT id, CONCAT(code, ' — ', name) FROM emp_types ORDER BY code"), $f['employee_type_id'], 'All types') ?></select></label>
  <?php else: ?>
    <label>Class group<select name="class_group_id"><?= options(pairs("SELECT id, CONCAT(code, ' — ', name) FROM class_groups ORDER BY code"), $f['class_group_id'], 'All classes') ?></select></label>
  <?php endif; ?>
  <?php if (in_array($type, ['deductions', 'earnings', 'sss_loan', 'hdmf_loan'], true)): ?>
    <label>Trans. code<input name="code" value="<?= h((string)$f['code']) ?>" placeholder="All codes" style="width:130px"></label>
  <?php endif; ?>
  <?php if ($menu === 'ytd'): ?>
    <fieldset class="seg-field"><legend>Report type</legend><div class="seg">
      <label class="check"><input type="radio" name="mode" value="detail" <?= checked($mode === 'detail') ?>> Detail</label>
      <label class="check"><input type="radio" name="mode" value="summary" <?= checked($mode === 'summary') ?>> Summary</label>
    </div></fieldset>
  <?php endif; ?>
  <button class="btn btn-primary">Preview</button>
</form>

<?php if (!$scope): ?>
  <div class="card"><div class="empty-state">
    <h3><?= $menu === 'periodical' ? 'No open payroll period with a processed payroll' : 'No processed payroll yet' ?></h3>
    <p class="muted"><?= $menu === 'periodical' ? 'Periodical Reports show the open period being paid. Closed periods are in the Monthly Reports.' : 'Process a payroll run first.' ?></p>
    <?php if ($menu === 'periodical'): ?><a class="btn" href="<?= h(url('rpt_monthly_contrib')) ?>">Monthly Reports</a><?php endif; ?>
  </div></div>
<?php page_footer(); return; endif; ?>

<?php if ($note): ?><div class="callout warn"><?= h($note) ?></div><?php endif; ?>
<?php if ($menu === 'periodical'): ?><p class="muted small">Employee shares only. The employer share is reported in the Monthly Reports once the period is closed.</p><?php endif; ?>

<div class="card flush prpt-card">
  <div class="card-head pad">
    <div><h2><?= h($title) ?></h2><div class="sub"><?= h($scopeLabel) ?>, <?= (int)$rep['employees'] ?> employee(s)</div><?= report_selection_html($f) ?></div>
    <?php if (!$rep['detail'] && $rep['employees'] > 8): ?><input type="search" class="table-search" data-target="prptTbl" placeholder="Find employee…" style="max-width:240px"><?php endif; ?>
  </div>
  <div class="pad-x prpt-body"><?= prpt_html($rep, 'prptTbl') ?></div>
</div>

<?php if (in_array($menu, ['bank', 'cash'], true) && $rep['employees']): ?>
  <div class="grid grid-3" style="margin-top:16px">
    <?= stat_card('Employees', number_format($rep['employees']), $menu === 'bank' ? 'paid through the bank' : 'paid in cash', 'info') ?>
    <?= stat_card('Total net pay', money($rep['grand']['net']), h($scopeLabel), 'good') ?>
    <?php if ($menu === 'bank'): ?><?= stat_card('Bank file', 'Payroll ATM', '<a href="' . h(url('bank_file', ['run_id' => $scope['run']])) . '">Bank file / Payroll ATM</a>') ?><?php endif; ?>
  </div>
<?php endif; ?>
<?php page_footer();
