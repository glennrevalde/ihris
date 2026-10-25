<?php
/**
 * My Calendar (v17): the month of the employee at a glance: schedule (shift or rest day), approved leaves,
 * overtime (requests and the pre-arranged overtime of the shift), holidays and company events.
 * HR (Employee Masterfile view right) can open the calendar of an employee of their assignment (?emp=).
 */
$me = my_employee_id();
$empId = $me;
$other = (int)get('emp', 0);
if ($other && $other !== $me) {
    if (!can('employees.view') || !scope_allows($other)) {
        scope_deny();
    }
    $empId = $other;
}
$emp = $empId ? row('SELECT e.*, b.name AS branch_name, ou.name AS unit_name FROM employees e LEFT JOIN branches b ON b.id = e.default_branch_id
                     LEFT JOIN org_units ou ON ou.id = e.org_unit_id WHERE e.id = ?', [$empId]) : null;
$ym = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)get('ym')) ? (string)get('ym') : date('Y-m');
$first = $ym . '-01';
$last = date('Y-m-t', strtotime($first));
$gridFrom = date('Y-m-d', strtotime($first . ' -' . (int)date('w', strtotime($first)) . ' days'));
$gridTo = date('Y-m-d', strtotime($last . ' +' . (6 - (int)date('w', strtotime($last))) . ' days'));
$prev = date('Y-m', strtotime($first . ' -1 month'));
$next = date('Y-m', strtotime($first . ' +1 month'));
$q = fn(array $x) => array_filter($x + ['emp' => $other && $other !== $me ? $other : null], fn($v) => $v !== null && $v !== '');

page_header($emp && $empId !== $me ? 'Calendar of ' . full_name($emp, false) : 'My Calendar', [
    'subtitle' => 'Schedule, approved leaves, overtime, holidays and company events of the month.',
    'actions' => (can('company_events.view') ? '<a class="btn" href="' . url('company_events') . '">Company events</a>' : '')
        . ($emp && $empId === $me && can('my_leaves.view') ? '<a class="btn" href="' . url('my_leaves') . '">File leave</a>' : '')
        . ($emp && $empId === $me && can('my_overtime.view') ? '<a class="btn" href="' . url('my_overtime') . '">Request overtime</a>' : ''),
]);
if (!$emp): ?>
  <div class="card"><div class="empty-state"><h3>No employee record</h3><p class="muted">Your account is not linked to an employee record. Ask the administrator to link it in User Accounts.</p></div></div>
<?php page_footer(); return; endif;
$items = calendar_items($emp, $gridFrom, $gridTo);
$today = today();
$counts = array_fill_keys(array_keys(CAL_KINDS), 0);
foreach ($items as $d => $list) {
    if ($d < $first || $d > $last) {
        continue;
    }
    foreach ($list as $it) {
        $counts[$it['kind']]++;
    }
}
?>
<div class="card cal-card">
  <div class="cal-head">
    <div class="cal-title"><h2><?= h(date('F Y', strtotime($first))) ?></h2>
      <span class="muted small"><?= h(full_name($emp)) ?>, <?= h($emp['employee_no']) ?><?= $emp['unit_name'] ? ', ' . h($emp['unit_name']) : '' ?></span></div>
    <div class="cal-nav">
      <a class="btn btn-sm" href="<?= h(url('my_calendar', $q(['ym' => $prev]))) ?>" aria-label="Previous month">&larr; <?= h(date('M', strtotime($prev . '-01'))) ?></a>
      <a class="btn btn-sm" href="<?= h(url('my_calendar', $q([]))) ?>">Today</a>
      <a class="btn btn-sm" href="<?= h(url('my_calendar', $q(['ym' => $next]))) ?>" aria-label="Next month"><?= h(date('M', strtotime($next . '-01'))) ?> &rarr;</a>
      <form method="get" class="cal-jump"><input type="hidden" name="p" value="my_calendar"><?php if ($other && $other !== $me): ?><input type="hidden" name="emp" value="<?= (int)$other ?>"><?php endif; ?>
        <input type="month" name="ym" value="<?= h($ym) ?>" aria-label="Month" data-autosubmit></form>
    </div>
  </div>
  <div class="cal-legend">
    <?php foreach (['shift', 'rest', 'leave', 'ot', 'preot', 'holiday', 'event'] as $k): ?>
      <span class="cal-key k-<?= h($k) ?>"><i></i><?= h(CAL_KINDS[$k]) ?><?= $counts[$k] ? ' <b>' . $counts[$k] . '</b>' : '' ?></span>
    <?php endforeach; ?>
  </div>
  <div class="cal-grid" role="grid" aria-label="<?= h(date('F Y', strtotime($first))) ?>">
    <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dn): ?><div class="cal-dow" role="columnheader"><?= $dn ?></div><?php endforeach; ?>
    <?php foreach ($items as $d => $list):
        $out = $d < $first || $d > $last;
        $isRest = (bool)array_filter($list, fn($x) => $x['kind'] === 'rest');
        $isHol = (bool)array_filter($list, fn($x) => $x['kind'] === 'holiday'); ?>
      <a class="cal-day<?= $out ? ' is-out' : '' ?><?= $d === $today ? ' is-today' : '' ?><?= $isRest ? ' is-rest' : '' ?><?= $isHol ? ' is-hol' : '' ?>" href="#d-<?= h($d) ?>" role="gridcell" aria-label="<?= h(date('l, F j', strtotime($d))) ?>">
        <span class="cal-num"><?= (int)date('j', strtotime($d)) ?></span>
        <span class="cal-items">
          <?php foreach (array_slice($list, 0, 4) as $it): ?>
            <span class="cal-item k-<?= h($it['kind']) ?>" title="<?= h($it['title'] . ($it['time'] ? ', ' . $it['time'] : '') . ($it['note'] ? '. ' . $it['note'] : '')) ?>"><?= h($it['kind'] === 'shift' ? ($it['time'] ?: $it['title']) : $it['title']) ?></span>
          <?php endforeach; ?>
          <?php if (count($list) > 4): ?><span class="cal-more">+<?= count($list) - 4 ?> more</span><?php endif; ?>
        </span>
        <span class="cal-dots" aria-hidden="true"><?php foreach (array_unique(array_column($list, 'kind')) as $k): ?><i class="k-<?= h($k) ?>"></i><?php endforeach; ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <h2>Agenda of <?= h(date('F', strtotime($first))) ?></h2>
  <div class="cal-agenda">
    <?php $any = false;
    foreach ($items as $d => $list):
        if ($d < $first || $d > $last) {
            continue;
        }
        $notable = array_values(array_filter($list, fn($x) => !in_array($x['kind'], ['shift', 'rest'], true)));
        $sched = array_values(array_filter($list, fn($x) => in_array($x['kind'], ['shift', 'rest'], true)));
        if (!$notable && $d !== $today) {
            continue;
        }
        $any = true; ?>
      <div class="cal-ag-day<?= $d === $today ? ' is-today' : '' ?>" id="d-<?= h($d) ?>">
        <div class="cal-ag-date"><strong><?= h(date('D, M j', strtotime($d))) ?></strong><?php if ($sched): ?><small><?= h($sched[0]['kind'] === 'rest' ? 'Rest day' : $sched[0]['time']) ?></small><?php endif; ?></div>
        <div class="cal-ag-list">
          <?php if (!$notable): ?><div class="muted small">Nothing else today.</div><?php endif; ?>
          <?php foreach ($notable as $it): ?>
            <div class="cal-ag-item k-<?= h($it['kind']) ?>"><span class="cal-ag-kind"><?= h(CAL_KINDS[$it['kind']] ?? $it['kind']) ?></span>
              <span><strong><?= h($it['title']) ?></strong><?= $it['time'] ? ' <span class="muted">' . h($it['time']) . '</span>' : '' ?><?= $it['note'] ? '<br><small class="muted">' . h($it['note']) . '</small>' : '' ?></span></div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$any): ?><p class="muted">No leave, overtime, holiday or company event this month.</p><?php endif; ?>
  </div>
</div>
<?php page_footer();
