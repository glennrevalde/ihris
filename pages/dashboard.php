<?php
$u = current_user();
$me = my_employee_id();
// every card, statistic and shortcut below is shown only when the user's permissions allow its module
$c = [
    'emp' => can('employees.view'), 'att' => can_any(['att_entry.view', 'dtr.view', 'cutoffs.view']), 'leave' => can('leave_applications.view'),
    'exc' => can('att_exceptions.view'), 'cases' => can('cases.view'), 'web' => can('web_verify.view'), 'runs' => can('payroll_runs.view'),
    'cost' => can_any(['rpt_monthly.view', 'payroll_runs.view']), 'audit' => can('audit.view'), 'imp' => can('import_review.view'),
    'cut' => can('cutoffs.view'), 'appr' => can('approvals.view'),
];
$isStaff = $c['emp'] || $c['att'] || $c['leave'] || $c['runs'] || $c['cases'] || $c['exc'];
$isMgr = $c['appr'];
$today = today();
$year = (int)date('Y');

page_header('Dashboard', ['subtitle' => 'Welcome back, ' . h($u['first_name'] ?: $u['full_name']) . ', ' . date('l, F j, Y')]);

// Company Owner (or a Super Admin inside the company): the company, its code and its join requests
if (owner_or_support() && ($co = tenant_company())) {
    $pendingJoin = (int)val("SELECT COUNT(*) FROM users WHERE reg_status = 'pending'");
    $code = company_code((int)$co['id']);
    echo '<div class="card company-strip"><div class="company-strip-main"><span class="context-label">' . (is_company_owner() ? 'Your company' : 'Company') . '</span><strong>' . h($co['name']) . '</strong></div>'
        . '<div class="company-strip-item"><span class="context-label">Company Code</span><span class="code-value sm">' . h($code ?: '—') . '</span></div>'
        . '<div class="company-strip-item"><span class="context-label">Join requests</span><strong>' . $pendingJoin . ' pending</strong></div>'
        . '<span class="spacer"></span><a class="btn btn-sm" href="' . url('company_profile') . '">Company profile</a>'
        . '<a class="btn btn-sm' . ($pendingJoin ? ' btn-primary' : '') . '" href="' . url('join_requests') . '">Review join requests</a>'
        . '<a class="btn btn-sm" href="' . url('users') . '">Users</a></div>';
}
if (is_super_user() && !master_db() && mc_enabled() && master_error()) {
    echo '<div class="callout warn">Multi-company is not active: the master database <strong>' . h(master_db_name()) . '</strong> could not be created or opened. '
        . 'The system keeps working as one company. Give the MySQL account of config/config.php the rights listed in README section 12, or import database/ihris_master_mysql55.sql.</div>';
}

// v16: System To-do's, Notice to Explain: employees flagged by the habitual tardiness policy, for HR and the immediate superior
$nte = nte_todos_for_user();
if ($nte['hr'] || $nte['team']):
    $nteRow = function (array $t, bool $hr): string {
        $h = '<div class="todo-item"><div class="todo-main"><div class="todo-who"><strong>' . h(full_name($t)) . '</strong> <small class="muted">' . h($t['employee_no']) . ($t['unit_name'] ? ', ' . h($t['unit_name']) : '') . '</small></div>'
            . '<div class="todo-text"><span class="todo-reason">' . h(nte_reason_text($t)) . '</span> ' . h(nte_todo_text($t)) . '</div>'
            . ($t['supervisor_name'] ? '<small class="muted">Immediate superior: ' . h($t['supervisor_name']) . '</small>' : '') . '</div><div class="todo-actions">';
        if ($hr) {
            $h .= '<a class="btn btn-sm btn-primary" href="' . h(nte_case_url($t)) . '">Issue NTE</a>'
                . '<form method="post" action="' . url('cases') . '" class="todo-dismiss" data-confirm="Dismiss this to-do without an NTE?">' . csrf_field()
                . '<input type="hidden" name="_action" value="nte_dismiss"><input type="hidden" name="id" value="' . (int)$t['id'] . '">'
                . '<input name="note" placeholder="Note (optional)" maxlength="255" aria-label="Note"><button class="btn btn-sm">Dismiss</button></form>';
        } else {
            $h .= '<span class="small muted">For your follow-up: HR is notified for the Notice to Explain.</span>';
        }
        if (can('att_entry.view')) {
            $h .= '<a class="btn btn-sm" href="' . h(url('att_entry', ['emp' => $t['employee_id'], 'from' => $t['period_month'], 'to' => date('Y-m-t', strtotime($t['period_month']))])) . '">Late days</a>';
        }
        return $h . '</div></div>';
    }; ?>
<div class="card todo-card">
  <div class="card-head">
    <div><h2>To-do's: Notice to Explain (NTE)</h2>
      <div class="sub">Employees flagged for HR review / NTE by the attendance policy: late <?= (int)setting('habitual_tardy_count', 5) ?> times or more in a month, or <?= (int)setting('tardy_cap_minutes', 120) ?> minutes or more.</div></div>
    <?php if (can('att_exceptions.view')): ?><a class="btn btn-sm" href="<?= url('att_exceptions') ?>">Tardiness monitor</a><?php endif; ?>
  </div>
  <?php if ($nte['hr']): ?>
    <div class="todo-group"><?= $nte['team'] ? '<div class="todo-group-title">For HR (' . count($nte['hr']) . ')</div>' : '' ?>
      <?php foreach (array_slice($nte['hr'], 0, 25) as $t) echo $nteRow($t, true); ?>
      <?= count($nte['hr']) > 25 ? '<p class="small muted">' . (count($nte['hr']) - 25) . ' more.</p>' : '' ?>
    </div>
  <?php endif; ?>
  <?php if ($nte['team']): ?>
    <div class="todo-group"><div class="todo-group-title">Your team (<?= count($nte['team']) ?>)</div>
      <?php foreach (array_slice($nte['team'], 0, 25) as $t) echo $nteRow($t, false); ?>
    </div>
  <?php endif; ?>
</div>
<?php endif;

if ($isStaff) {
    $cards = '';
    if ($c['emp']) {
        $headcount = (int)val("SELECT COUNT(*) FROM employees e WHERE e.status = 'active'" . user_scope_sql('e'));
        $cards .= stat_card('Active employees', number_format($headcount), '<a href="' . url('employees') . '">Masterfile</a>', 'info');
    }
    if ($c['att']) {
        $presentEmp = (int)val("SELECT COUNT(DISTINCT employee_id) FROM attendance_logs WHERE DATE(log_time) = ?", [$today]);
        $cards .= stat_card('Clocked in today', number_format($presentEmp), $c['emp'] ? 'of ' . $headcount . ' employees' : 'employees', 'good');
    }
    if ($c['leave']) {
        $pendingLeaves = (int)val("SELECT COUNT(*) FROM leave_applications WHERE status = 'pending'");
        $cards .= stat_card('Leave requests pending', number_format($pendingLeaves), '<a href="' . url('leave_applications', ['status' => 'pending']) . '">Review</a>', $pendingLeaves ? 'warn' : '');
    }
    if ($c['exc']) {
        $openEx = (int)val("SELECT COUNT(*) FROM dtr WHERE exception_status = 'open' AND work_date <= ?", [$today]);
        $cards .= stat_card('Attendance exceptions', number_format($openEx), '<a href="' . url('att_exceptions') . '">Resolve</a>', $openEx ? 'bad' : 'good');
    }
    if ($c['cases']) {
        $openCases = (int)val("SELECT COUNT(*) FROM disciplinary_cases WHERE status NOT IN ('decided','closed')");
        $cards .= stat_card('Open disciplinary cases', number_format($openCases), '<a href="' . url('cases') . '">Cases</a>', $openCases ? 'warn' : '');
    }
    if ($c['web']) {
        $pendingWeb = (int)val("SELECT COUNT(*) FROM attendance_logs WHERE source = 'web' AND verify_status = 'pending'");
        $cards .= stat_card('Web check-ins to verify', number_format($pendingWeb), '<a href="' . url('web_verify') . '">Verify</a>', $pendingWeb ? 'warn' : '');
    }
    if ($cards !== '') {
        echo '<div class="stats">' . $cards . '</div>';
    }

    // M.A.D.A.S. style quick actions: the payroll cycle from attendance to payslip (only the ones the user may open)
    $openPeriod = $c['cut'] || can_any(['pay_trans.view', 'pay_batch.view']) ? row("SELECT c.*, pg.code FROM attendance_cutoffs c JOIN pay_groups pg ON pg.id = c.pay_group_id WHERE c.status IN ('open','summarized') ORDER BY c.period_end DESC LIMIT 1") : null;
    $quick = [
        ['Attendance entry', 'att_entry', 'clock', []],
        ['Import logs', 'import_logs', 'upload', []],
        ['Payroll transaction', 'pay_trans', 'money', $openPeriod ? ['cutoff_id' => $openPeriod['id']] : []],
        ['Batch transaction', 'pay_batch', 'grid', $openPeriod ? ['cutoff_id' => $openPeriod['id']] : []],
        ['Process payroll', 'payroll_runs', 'bolt', []],
        ['Period closing', 'period_closing', 'check', []],
        ['Payroll register', 'pay_register', 'list', []],
        ['Print payslips', 'payslips_print', 'print', []],
        ['Period parameters', 'periods', 'calendar', []],
    ];
    $quick = array_filter($quick, fn($qa) => perm_route_allowed($qa[1], $qa[3]));
    if ($quick) {
        echo '<h2 class="section-title">Payroll cycle</h2><div class="quick" style="margin-bottom:20px">';
        foreach ($quick as $qa) {
            echo '<a href="' . h(url($qa[1], $qa[3])) . '">' . icon($qa[2]) . h($qa[0]) . '</a>';
        }
        echo '</div>';
    }

    $byDept = $c['emp'] ? rows("SELECT COALESCE(ou.name, 'Unassigned') AS name, COUNT(*) AS n FROM employees e LEFT JOIN org_units ou ON ou.id = e.org_unit_id
                    WHERE e.status = 'active'" . user_scope_sql('e') . ' GROUP BY ou.name ORDER BY n DESC') : [];
    $maxDept = max(1, ...array_map(fn($r) => (int)$r['n'], $byDept ?: [['n' => 1]]));
    $months = [];
    for ($i = 5; $i >= 0; $i--) {
        $d = date('Y-m-01', strtotime(date('Y-m-01') . " -$i months"));
        $months[$d] = 0.0;
    }
    if ($c['cost']) {
        foreach (rows("SELECT DATE_FORMAT(r.period_end, '%Y-%m-01') AS m, SUM(pi.gross_pay + pi.sss_er + pi.sss_ec + pi.phic_er + pi.hdmf_er) AS cost
                       FROM payroll_items pi JOIN payroll_runs r ON r.id = pi.run_id WHERE r.status = 'finalized' AND r.period_end >= ?
                       GROUP BY DATE_FORMAT(r.period_end, '%Y-%m-01')", [array_key_first($months)]) as $r) {
            if (isset($months[$r['m']])) {
                $months[$r['m']] = (float)$r['cost'];
            }
        }
    }
    $maxM = max(1, max($months));
    ?>
    <?php if ($c['cost'] || $c['emp']): ?>
    <div class="grid grid-2">
      <?php if ($c['cost']): ?>
      <div class="card">
        <div class="card-head"><h2>Payroll cost — last 6 months</h2><a class="btn btn-sm" href="<?= url('rpt_monthly') ?>">Monthly summary</a></div>
        <div class="spark">
          <?php foreach ($months as $m => $v): ?>
            <div class="col" title="<?= h(date('F Y', strtotime($m))) ?>: <?= h(peso($v)) ?>">
              <span><?= $v > 0 ? h(number_format($v / 1000, 0)) . 'k' : '' ?></span>
              <div class="b" style="height:<?= round($v / $maxM * 90) ?>px"></div>
              <span><?= h(date('M', strtotime($m))) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <p class="small muted" style="margin-top:8px">Gross pay plus employer SSS/EC, PhilHealth and Pag-IBIG shares, finalized runs.</p>
      </div>
      <?php endif; ?>
      <?php if ($c['emp']): ?>
      <div class="card">
        <div class="card-head"><h2>Headcount by unit</h2><a class="btn btn-sm" href="<?= url('rpt_departments') ?>">Department reports</a></div>
        <div class="bars">
          <?php foreach ($byDept as $r): ?>
            <div class="bar-row"><span><?= h($r['name']) ?></span><div class="bar-track"><div class="bar-fill" style="width:<?= round($r['n'] / $maxDept * 100) ?>%"></div></div><span class="v"><?= (int)$r['n'] ?></span></div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($c['runs'] || $c['cut'] || $c['audit']): ?>
    <div class="grid grid-2">
      <?php if ($c['runs'] || $c['cut']): ?>
      <div class="card">
        <div class="card-head"><h2>Payroll</h2><a class="btn btn-sm btn-primary" href="<?= url('payroll_runs') ?>">Payroll runs</a></div>
        <?php if ($c['runs']):
            $lastRun = row("SELECT r.*, pg.name AS pg FROM payroll_runs r JOIN pay_groups pg ON pg.id = r.pay_group_id WHERE r.status = 'finalized' ORDER BY r.pay_date DESC, r.id DESC LIMIT 1");
            $draftRuns = (int)val("SELECT COUNT(*) FROM payroll_runs WHERE status IN ('draft','computed')");
            $toClose = (int)val("SELECT COUNT(*) FROM payroll_runs WHERE status = 'finalized' AND posted_at IS NULL"); ?>
          <?php if ($lastRun): ?>
            <p>Last finalized run: <a href="<?= url('payroll_run', ['id' => $lastRun['id']]) ?>"><?= h($lastRun['run_no']) ?></a> — <?= h($lastRun['pg']) ?>,
               <?= h(fmt_date($lastRun['period_start'])) ?> to <?= h(fmt_date($lastRun['period_end'])) ?> (paid <?= h(fmt_date($lastRun['pay_date'])) ?>).</p>
          <?php else: ?><p class="muted">No finalized payroll yet.</p><?php endif; ?>
          <p><?= $draftRuns ?> run(s) in draft/computed state<?= $toClose ? ', ' . $toClose . ' finalized run(s) waiting for <a href="' . url('period_closing') . '">Period Closing</a>' : '' ?><?= $c['imp'] ? ', ' . (int)val("SELECT COUNT(*) FROM import_exceptions WHERE status = 'open'") . ' imported attendance row(s) awaiting review' : '' ?>.</p>
        <?php endif; ?>
        <?php
        $cuts = $c['cut'] ? rows("SELECT c.*, pg.name AS pg FROM attendance_cutoffs c JOIN pay_groups pg ON pg.id = c.pay_group_id WHERE c.status IN ('open','summarized') ORDER BY c.period_end LIMIT 5") : [];
        if ($cuts): ?>
          <table class="table compact"><thead><tr><th>Open cutoff</th><th>Period</th><th>Status</th></tr></thead><tbody>
          <?php foreach ($cuts as $ct): ?>
            <tr><td><a href="<?= url('cutoff_view', ['id' => $ct['id']]) ?>"><?= h($ct['pg']) ?></a></td><td><?= h(fmt_date($ct['period_start'], 'M d')) ?> – <?= h(fmt_date($ct['period_end'], 'M d, Y')) ?></td><td><?= badge($ct['status']) ?></td></tr>
          <?php endforeach; ?></tbody></table>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ($c['audit']): ?>
      <div class="card">
        <div class="card-head"><h2>Recent activity</h2><a class="btn btn-sm" href="<?= url('audit') ?>">Audit trail</a></div>
        <table class="table compact"><tbody>
        <?php foreach (rows('SELECT * FROM audit_log ORDER BY id DESC LIMIT 8') as $a): ?>
          <tr><td class="nowrap small muted"><?= h(fmt_dt($a['created_at'], 'M d h:i A')) ?></td><td><strong><?= h($a['username']) ?></strong> <?= h(label($a['action'])) ?> <span class="muted"><?= h($a['entity']) ?> <?= h($a['entity_id']) ?></span></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
<?php
}

if ($isMgr && !$isStaff) {
    $team = my_team_ids();
    $pend = (int)val("SELECT COUNT(*) FROM leave_approval_steps WHERE status = 'pending' AND approver_user_id = ?", [$u['id']]);
    $otPend = $team ? (int)val('SELECT COUNT(*) FROM overtime_requests WHERE status = \'pending\' AND employee_id IN (' . in_list($team) . ')', $team) : 0;
    echo '<div class="stats">';
    echo stat_card('Team members', (string)count($team), '', 'info');
    echo stat_card('Leave approvals waiting', (string)$pend, '<a href="' . url('approvals') . '">Open approvals</a>', $pend ? 'warn' : 'good');
    echo stat_card('OT requests waiting', (string)$otPend, '<a href="' . url('approvals') . '">Open approvals</a>', $otPend ? 'warn' : 'good');
    echo '</div>';
}

$essAny = $me && can_any(['my_clock.view', 'my_leaves.view', 'my_payslips.view']);
if ($essAny) {
    $emp = employee_row($me);
    $punches = rows('SELECT * FROM attendance_logs WHERE employee_id = ? AND DATE(log_time) = ? ORDER BY log_time', [$me, $today]);
    $types = rows("SELECT * FROM leave_types WHERE is_active = 1 AND accrual_method <> 'per_event' ORDER BY id");
    $slip = row("SELECT pi.id, r.run_no, r.period_start, r.period_end, pi.net_pay FROM payroll_items pi JOIN payroll_runs r ON r.id = pi.run_id
                 WHERE pi.employee_id = ? AND r.status = 'finalized' ORDER BY r.pay_date DESC LIMIT 1", [$me]);
    ?>
    <h2 style="margin-top:6px">My workspace</h2>
    <div class="grid grid-3">
      <?php if (can('my_clock.view') || can('my_dtr.view')): ?>
      <div class="card">
        <div class="card-head"><h3>Today</h3><div class="btn-row"><?php if (can('my_calendar.view')): ?><a class="btn btn-sm" href="<?= url('my_calendar') ?>">My calendar</a><?php endif; ?><?php if (in_array($emp['attendance_method'], ['web', 'both'], true)): ?><a class="btn btn-sm btn-primary" href="<?= url('my_clock') ?>">Time clock</a><?php endif; ?></div></div>
        <p class="muted small">Shift: <?= !empty($emp['shift_id']) && ($es = shift_row((int)$emp['shift_id'])) ? shift_code_html($es, '', $es['code'] . ' — ' . $es['name']) : h($emp['shift_name'] ?: 'No fixed shift') ?></p>
        <?php if ($punches): ?>
          <ul class="timeline"><?php foreach ($punches as $p): ?><li><strong><?= h(label($p['punch_type'])) ?></strong> <span class="when"><?= h(fmt_time($p['log_time'])) ?>, <?= h(label($p['source'])) ?></span></li><?php endforeach; ?></ul>
        <?php else: ?><p class="muted">No punches recorded yet today.</p><?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if (can('my_leaves.view')): ?>
      <div class="card">
        <div class="card-head"><h3>Leave balances <?= $year ?></h3><a class="btn btn-sm" href="<?= url('my_leaves') ?>">File leave</a></div>
        <table class="table compact"><tbody>
        <?php foreach ($types as $t):
            if (leave_ineligibility($emp, $t)) continue;
            $b = leave_balance($me, (int)$t['id'], $year); ?>
          <tr><td><?= h($t['name']) ?></td><td class="num"><strong><?= h($b['available']) ?></strong> <span class="muted small">day(s)</span></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      </div>
      <?php endif; ?>
      <?php if (can('my_payslips.view')): ?>
      <div class="card">
        <div class="card-head"><h3>Latest payslip</h3><a class="btn btn-sm" href="<?= url('my_payslips') ?>">All payslips</a></div>
        <?php if ($slip): ?>
          <p><?= h(fmt_date($slip['period_start'])) ?> – <?= h(fmt_date($slip['period_end'])) ?></p>
          <div class="stat-value"><?= h(peso($slip['net_pay'])) ?></div>
          <p class="muted small">Net pay, <?= h($slip['run_no']) ?></p>
          <a class="btn btn-sm" href="<?= url('payslip', ['id' => $slip['id']]) ?>">View</a>
          <a class="btn btn-sm" href="<?= url('payslip', ['id' => $slip['id'], 'pdf' => 1]) ?>">Download PDF</a>
        <?php else: ?><p class="muted">No payslips yet.</p><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
<?php
}
if (!$essAny && !$isStaff && !$isMgr) {
    echo '<div class="card"><p>Your account is not linked to an employee record. Ask the administrator to link it in User Accounts.</p></div>';
}
page_footer();
