<?php
/**
 * Retroactive pay adjustments (salary increases, promotions or wage orders approved with a past effectivity date).
 */
$dimp = dimp_def('retro', null, []);   // CSV import facility on this form (app/engine/dataimport.php)
dimp_handle($dimp);
if (is_post()) {
    $act = post('_action');
    if ($act === 'compute') {
        $emp = row('SELECT * FROM employees WHERE id = ?', [(int)post('employee_id')]);
        $old = post_num('old_rate');
        $new = post_num('new_rate');
        if (!$emp || !post('effective_date') || $old <= 0 || $new <= 0) {
            flash('error', 'Employee, effectivity date and both rates are required.');
        } else {
            if (post('update_rate') && abs((float)$emp['basic_rate'] - $old) < 0.005) {
                // update the masterfile; the retroactive differential is computed as part of the change
                $res = employee_apply_changes((int)$emp['id'], ['basic_rate' => $new], (string)post('effective_date'), post('remarks') ?: 'Retroactive rate adjustment', 'salary_change');
                $r = $res['retro'];
            } else {
                $r = retro_compute((int)$emp['id'], (string)post('effective_date'), $old, $new, $emp['rate_type']);
            }
            if ($r) {
                flash('success', sprintf('Retroactive differential of %s computed over %d finalized period(s); queued for the next payroll.', peso($r['amount']), $r['periods']));
            } else {
                flash('warning', 'No finalized payroll periods paid at the old rate on or after the effectivity date — nothing to adjust.');
            }
        }
    } elseif ($act === 'cancel') {
        $r = row("SELECT * FROM retro_adjustments WHERE id = ? AND status = 'pending'", [(int)post('id')]);
        if ($r) {
            update('retro_adjustments', ['status' => 'cancelled'], 'id = ?', [$r['id']]);
            update('pending_earnings', ['status' => 'cancelled'], "id = ? AND status = 'pending'", [$r['pending_earning_id']]);
            audit('payroll', 'retro_cancel', 'retro_adjustments', $r['id']);
            flash('success', 'Retroactive adjustment cancelled.');
        }
    }
    redirect('retro');
}
$list = rows('SELECT r.*, e.first_name, e.last_name, e.middle_name, e.suffix, e.employee_no, pr.run_no FROM retro_adjustments r JOIN employees e ON e.id = r.employee_id
              LEFT JOIN pending_earnings pe ON pe.id = r.pending_earning_id LEFT JOIN payroll_runs pr ON pr.id = pe.payroll_run_id WHERE 1=1' . user_scope_sql('e') . ' ORDER BY r.created_at DESC');
page_header('Retroactive Pay Adjustments', ['subtitle' => 'Created automatically when a salary change is saved with a past effectivity date, or computed here (e.g. a wage order with retroactive effect). The differential is included in the next payroll run as taxable pay.']);
echo dimp_panel($dimp);
?>
<div class="card"><h2>Compute retroactive differential</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="compute">
    <div class="form-grid">
      <div class="field"><label>Employee</label><select name="employee_id" required><?= options(employee_options(), null, '— select —') ?></select></div>
      <div class="field"><label>Effectivity date</label><input type="date" name="effective_date" required></div>
      <div class="field"><label>Old rate (as paid)</label><input type="number" step="0.01" name="old_rate" required></div>
      <div class="field"><label>New rate</label><input type="number" step="0.01" name="new_rate" required></div>
      <div class="field"><label class="check"><input type="checkbox" name="update_rate" value="1" checked> Also update the masterfile rate</label></div>
      <div class="field wide"><label>Remarks / authority (e.g. Wage Order NCR-26)</label><input name="remarks"></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary">Compute</button></div>
  </form>
</div>
<div class="card"><table class="table"><thead><tr><th>Employee</th><th>Effective</th><th class="num">Old → new rate</th><th class="num">Differential</th><th>Computation</th><th>Status</th><th>Paid in</th><th></th></tr></thead><tbody>
<?php if (!$list) echo empty_row(8); foreach ($list as $r): ?>
  <tr><td><?= h(full_name($r)) ?></td><td><?= h(fmt_date($r['effective_date'])) ?></td><td class="num"><?= money($r['old_rate']) ?> → <?= money($r['new_rate']) ?><br><span class="muted small"><?= h($r['rate_type']) ?></span></td>
    <td class="num"><strong><?= money($r['amount']) ?></strong></td><td class="small mono"><?= nl2br(h($r['breakdown'])) ?></td><td><?= badge($r['status']) ?></td><td><?= h($r['run_no']) ?></td>
    <td class="actions"><?php if ($r['status'] === 'pending'): ?><form method="post" class="inline" data-confirm="Cancel this retro adjustment?"><?= csrf_field() ?><input type="hidden" name="_action" value="cancel"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-sm btn-danger-outline">Cancel</button></form><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php page_footer();
