<?php
/**
 * v36: Undertime Applications of the employees. HR (users who authorize overtime / resolve attendance exceptions)
 * sees every employee of their assignment; a superior sees his team. Approving tags the undertime of the dates as
 * Approved undertime (the computation does not change); undertime without an approved form is Unauthorized.
 */
$wide = pass_slip_company_wide();
$team = $wide ? null : my_team_ids();
$keep = fn() => array_filter(['status' => get('status') ?: null, 'q' => trim((string)get('q', '')) ?: null, 'from' => get('from') ?: null, 'to' => get('to') ?: null]);
if (is_post()) {
    $act = post('_action');
    if (in_array($act, ['approve', 'reject'], true)) {
        $ok = 0;
        $msgs = [];
        foreach (array_map('intval', (array)post('ids', [])) as $id) {
            $s = ut_row($id);
            if (!$s || ($team !== null && !in_array((int)$s['employee_id'], $team, true))) {
                continue;
            }
            [$done, $msg] = ut_decide($id, $act, post('remarks'));
            $ok += $done ? 1 : 0;
            $msgs[] = $msg;
        }
        flash($ok ? 'success' : 'error', $msgs ? implode(' ', $msgs) : 'Tick the undertime forms to ' . $act . '.');
    }
    redirect('undertime_apps', $keep());
}
$status = get('status', 'pending');
$from = get('from') ?: '';
$to = get('to') ?: '';
$qText = trim((string)get('q', ''));
$params = [];
$where = $status !== 'all' ? 'ps.status = ' . db()->quote($status) : '1=1';
if ($qText !== '') {
    $where .= ' AND ' . search_words_sql(['e.last_name', 'e.first_name', 'e.employee_no', 'e.biometric_id', 'ps.ut_no'], $qText, $params);
}
if ($from !== '') {
    $where .= ' AND ps.last_date >= ?';
    $params[] = $from;
}
if ($to !== '') {
    $where .= ' AND ps.first_date <= ?';
    $params[] = $to;
}
if ($team !== null) {
    $where .= ' AND ps.employee_id IN (' . ($team ? implode(',', $team) : '0') . ')';
}
$list = rows("SELECT ps.*, e.first_name, e.last_name, e.middle_name, e.suffix, e.employee_no, e.position, u.full_name AS approver_user
              FROM undertime_apps ps JOIN employees e ON e.id = ps.employee_id LEFT JOIN users u ON u.id = ps.approved_by
              WHERE $where" . ($team === null ? user_scope_sql('e') : '') . ' ORDER BY ps.status = \'pending\' DESC, ps.first_date DESC, ps.id DESC LIMIT 500', $params);
$canAct = can_any(['undertime_apps.approve', 'undertime_apps.reject']);
$canPrint = can('undertime_apps.print');
$pending = count(array_filter($list, fn($s) => $s['status'] === 'pending'));
page_header('Undertime Applications', [
    'subtitle' => 'Undertime forms filed by the employees. Approving tags the undertime of the dates as approved; it is still computed as undertime.',
    'actions' => ($canPrint ? '<a class="btn" target="_blank" href="' . h(url('undertime_print', ['blank' => 1])) . '">Print blank form</a> ' : '')
        . (can('undertime_design.view') ? '<a class="btn" href="' . h(url('undertime_design')) . '">Form design</a>' : '') . ' '
        . (can('rpt_ut_approved.view') ? '<a class="btn" href="' . h(url('rpt_ut_approved')) . '">Approved Undertime Report</a>' : ''),
]);
?>
<form class="card filters" method="get"><input type="hidden" name="p" value="undertime_apps">
  <label>Status<select name="status" data-autosubmit><?= options(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'all' => 'All'], $status) ?></select></label>
  <label class="grow">Employee<input type="search" name="q" value="<?= h($qText) ?>" placeholder="Name, employee no. or form no." autocomplete="off"></label>
  <label>From<input type="date" name="from" value="<?= h($from) ?>"></label><label>To<input type="date" name="to" value="<?= h($to) ?>"></label>
  <button class="btn btn-primary">Apply</button></form>
<form method="post" class="card"><?= csrf_field() ?>
  <div class="card-head"><div><h2><?= count($list) ?> undertime form(s)</h2><?php if ($pending): ?><div class="sub"><?= $pending ?> waiting for approval</div><?php endif; ?></div>
    <?php if ($canAct && $pending): ?><div class="row-actions"><input name="remarks" placeholder="Remarks" maxlength="255" style="width:200px;display:inline-block"> <button class="btn btn-success" name="_action" value="approve">Approve selected</button> <button class="btn btn-danger-outline" name="_action" value="reject">Reject selected</button></div><?php endif; ?></div>
  <div class="table-wrap"><table class="table compact"><thead><tr><th><?php if ($canAct && $pending): ?><input type="checkbox" data-check-all="ids[]" aria-label="Select all"><?php endif; ?></th><th>Form no.</th><th>Employee</th><th>Date(s), time(s) and hours</th><th>Reason</th><th>Filed</th><th>Status</th><th class="actions"></th></tr></thead><tbody>
  <?php if (!$list) echo empty_row(8, 'No undertime form found.'); foreach ($list as $s): ?>
    <tr><td><?php if ($canAct && $s['status'] === 'pending' && (int)$s['employee_id'] !== (int)my_employee_id()): ?><input type="checkbox" name="ids[]" value="<?= (int)$s['id'] ?>"><?php endif; ?></td>
      <td class="mono nowrap"><?= h($s['ut_no']) ?></td><td><?= h(full_name($s)) ?><small class="d-block muted"><?= h(implode(', ', array_filter([$s['employee_no'], $s['position']]))) ?></small></td>
      <td class="small ps-hist-times"><?= ut_entries_html($s, true) ?></td><td class="small"><?= h($s['reason']) ?></td><td class="nowrap"><?= h(fmt_date($s['date_filed'])) ?></td>
      <td><?= badge($s['status']) ?><?php if ((int)$s['total_minutes'] > 0): ?><small class="d-block muted"><?= h(ut_fmt_total((int)$s['total_minutes'], 'words')) ?> undertime</small><?php endif; ?><?php if ($s['approver_user'] && $s['status'] !== 'cancelled'): ?><small class="d-block muted">by <?= h($s['approver_user']) ?></small><?php endif; ?><?php if ($s['remarks']): ?><small class="d-block muted"><?= h($s['remarks']) ?></small><?php endif; ?></td>
      <td class="actions"><?php if ($canPrint): ?><a class="btn btn-sm" target="_blank" href="<?= url('undertime_print', ['id' => $s['id']]) ?>">Print</a><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
</form>
<?php page_footer();
