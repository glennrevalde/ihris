<?php
$year = (int)(get('year') ?: date('Y'));
$dimp = dimp_def('leave_adj', null, ['year' => $year]);   // CSV import facility on this form (app/engine/dataimport.php)
dimp_handle($dimp);
if (is_post()) {
    $act = post('_action');
    $y = (int)post('year', $year);
    if ($act === 'accrue_annual') {
        $n = leave_accrue_annual($y);
        flash('success', "Annual / length-of-service entitlements credited for $y ($n new credit(s); already-credited employees were skipped).");
    } elseif ($act === 'accrue_monthly') {
        $m = (int)post('month', date('n'));
        $n = leave_accrue_monthly($y, $m);
        flash('success', sprintf('Monthly accrual for %s %d posted (%d credit(s)).', month_name($m), $y, $n));
    } elseif ($act === 'year_end') {
        $r = leave_year_end($y);
        flash('success', sprintf('Year-end %d closed: %d conversion(s) totalling %s queued for payroll; %d balance(s) carried over to %d.', $y, $r['converted'], peso($r['amount']), $r['carried'], $y + 1));
    } elseif ($act === 'adjust') {
        $emp = (int)post('employee_id');
        $type = (int)post('leave_type_id');
        $days = (float)post('days');
        if (!$emp || !$type || !$days || !post('reason')) {
            flash('error', 'Employee, leave type, days and reason are required.');
        } else {
            $b = leave_balance_row($emp, $type, $y);
            q('UPDATE leave_balances SET adjustment = adjustment + ? WHERE id = ?', [$days, $b['id']]);
            audit('pis', 'leave_adjust', 'leave_balances', $b['id'], ['employee_id' => $emp, 'type' => $type, 'days' => $days, 'reason' => post('reason')]);
            flash('success', 'Leave balance adjusted.');
        }
    }
    redirect('leave_balances', ['year' => $y]);
}
$types = rows("SELECT * FROM leave_types WHERE is_active = 1 AND accrual_method <> 'per_event' ORDER BY is_statutory DESC, id");
$emps = rows("SELECT * FROM employees WHERE status = 'active'" . user_scope_sql('employees') . ' ORDER BY last_name, first_name');
page_header('Leave Balances & Accrual', ['subtitle' => 'Credits, usage, carry-over and cash conversion per leave type. Run accrual processes and year-end closing here.',
    'actions' => '<button class="btn" data-modal-open="adjModal">Manual adjustment</button>']);
echo dimp_panel($dimp);
?>
<div class="grid grid-3">
  <form class="card" method="post" data-confirm="Credit annual entitlements for the selected year?"><?= csrf_field() ?><input type="hidden" name="_action" value="accrue_annual">
    <h3>Annual &amp; service-based credits</h3><p class="small muted">Credits SIL, VL (length of service), Solo Parent, VAWC and other annual types. Pro-rated for new hires; safe to re-run.</p>
    <div class="field"><label>Year</label><input type="number" name="year" value="<?= $year ?>"></div>
    <div class="form-actions"><button class="btn btn-primary">Credit entitlements</button></div></form>
  <form class="card" method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="accrue_monthly">
    <h3>Monthly accrual</h3><p class="small muted">Posts 1/12 of the annual credit for monthly-accruing types (e.g. Sick Leave). Idempotent per month.</p>
    <div class="form-grid"><div class="field"><label>Year</label><input type="number" name="year" value="<?= $year ?>"></div>
      <div class="field"><label>Month</label><select name="month"><?php for ($m = 1; $m <= 12; $m++) echo '<option value="' . $m . '"' . ($m == date('n') ? ' selected' : '') . '>' . month_name($m) . '</option>'; ?></select></div></div>
    <div class="form-actions"><button class="btn btn-primary">Post accrual</button></div></form>
  <form class="card" method="post" data-confirm="Close the leave year? Unused convertible credits are converted to cash (queued for payroll), allowed balances are carried over and the rest forfeited."><?= csrf_field() ?><input type="hidden" name="_action" value="year_end">
    <h3>Year-end closing</h3><p class="small muted">Carry-over up to the allowed maximum, convert unused convertible credits to cash (posted to the accrued-benefit ledger and next payroll), forfeit the rest.</p>
    <div class="field"><label>Leave year to close</label><input type="number" name="year" value="<?= $year ?>"></div>
    <div class="form-actions"><button class="btn btn-warning">Run year-end</button></div></form>
</div>
<div class="card">
  <div class="card-head"><h2>Available balances — <?= $year ?></h2>
    <form method="get" class="inline"><input type="hidden" name="p" value="leave_balances"><select name="year" data-autosubmit><?php for ($y = date('Y') + 1; $y >= date('Y') - 3; $y--) echo '<option' . ($y == $year ? ' selected' : '') . '>' . $y . '</option>'; ?></select></form></div>
  <div class="table-tools"><input type="search" class="input table-search" data-target="balTable" placeholder="Search employee..."></div>
  <div class="table-wrap"><table class="table compact" id="balTable">
    <thead><tr><th>Employee</th><?php foreach ($types as $t): ?><th class="num" title="<?= h($t['name']) ?>"><?= h($t['code']) ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach ($emps as $e): ?>
      <tr><td><a href="<?= url('employee_view', ['id' => $e['id']]) ?>#tab-leaves"><?= h(full_name($e)) ?></a></td>
      <?php foreach ($types as $t):
          if (leave_ineligibility($e, $t, $year . '-12-31')) { echo '<td class="num muted">—</td>'; continue; }
          $b = leave_balance((int)$e['id'], (int)$t['id'], $year); ?>
        <td class="num" title="Carried <?= $b['carried_over'] ?> + earned <?= $b['earned'] ?> + adj <?= $b['adjustment'] ?> − used <?= $b['used'] ?> − converted <?= $b['converted'] ?>"><?= $b['available'] ?><?= $b['pending'] > 0 ? ' <span class="badge badge-amber">' . $b['pending'] . '</span>' : '' ?></td>
      <?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <p class="small muted">Amber badge = days in pending applications. Hover a cell for the computation.</p>
</div>
<div class="modal" id="adjModal"><div class="modal-box"><h2>Manual balance adjustment</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="adjust"><input type="hidden" name="year" value="<?= $year ?>">
    <div class="form-grid">
      <div class="field wide"><label>Employee</label><select name="employee_id" required><?= options(employee_options(), null, '— select —') ?></select></div>
      <div class="field"><label>Leave type</label><select name="leave_type_id" required><?= options(array_column($types, 'name', 'id'), null, '— select —') ?></select></div>
      <div class="field"><label>Days (+ credit / − debit)</label><input type="number" step="0.5" name="days" required></div>
      <div class="field wide"><label>Reason</label><input name="reason" required></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary">Apply</button><button type="button" class="btn" data-modal-close>Cancel</button></div>
  </form></div></div>
<?php page_footer();
