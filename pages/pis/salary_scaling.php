<?php
/**
 * Salary scaling simulator: model an across-the-board increase, an RTWPB minimum wage order, or a restructuring
 * of the grade table BEFORE committing; compares total payroll cost and flags employees who fall below the new
 * grade minimum / minimum wage.
 */
// the simulator shows and changes basic salaries: Basic Salary view right to open it, edit right to apply it
if (!salary_can('view') || (is_post() && !salary_can('edit'))) {
    perm_deny();
}
$in = array_merge([
    'scenario' => 'atb', 'pct' => '5', 'fixed' => '0', 'scope_pg' => '', 'scope_ou' => '', 'scope_branch' => '', 'include_daily' => '1',
    'new_min_wage' => '', 'grade_pct' => '5', 'raise_to_min' => '1', 'effective_date' => date('Y-m-01', strtotime('first day of next month')),
], $_GET, $_POST);
$gradePct = (array)($in['g'] ?? []);

$where = ["e.status = 'active'"];
$params = [];
if ($in['scope_pg'] !== '') {
    $where[] = 'e.pay_group_id = ?';
    $params[] = $in['scope_pg'];
}
if ($in['scope_branch'] !== '') {
    $where[] = 'e.default_branch_id = ?';
    $params[] = $in['scope_branch'];
}
if ($in['scope_ou'] !== '') {
    $ids = org_descendant_ids((int)$in['scope_ou']);
    $where[] = 'e.org_unit_id IN (' . in_list($ids) . ')';
    $params = array_merge($params, $ids);
}
$emps = rows('SELECT e.*, sg.code AS grade_code, sg.minimum AS gmin, sg.maximum AS gmax, sg.id AS gid FROM employees e LEFT JOIN salary_grades sg ON sg.id = e.salary_grade_id
              WHERE ' . implode(' AND ', $where) . user_scope_sql('e') . ' ORDER BY e.last_name', $params);
$grades = rows('SELECT * FROM salary_grades ORDER BY sort_order');

$simulated = get('run') || is_post();
$result = [];
$tot = ['cur' => 0, 'new' => 0, 'cur_er' => 0, 'new_er' => 0, 'flag' => 0, 'changed' => 0];
$newGrades = [];
if ($simulated) {
    foreach ($grades as $g) {
        $p = isset($gradePct[$g['id']]) && $gradePct[$g['id']] !== '' ? (float)$gradePct[$g['id']] : (float)$in['grade_pct'];
        $newGrades[$g['id']] = ['pct' => $p, 'min' => round($g['minimum'] * (1 + $p / 100), 2), 'mid' => round($g['midpoint'] * (1 + $p / 100), 2), 'max' => round($g['maximum'] * (1 + $p / 100), 2)];
    }
    foreach ($emps as $e) {
        $r = employee_rates($e);
        $curRate = (float)$e['basic_rate'];
        $newRate = $curRate;
        $flags = [];
        $dpy = (int)$e['days_per_year'];
        switch ($in['scenario']) {
            case 'atb':
                if ($e['rate_type'] === 'daily' && !$in['include_daily']) {
                    break;
                }
                $fixed = (float)$in['fixed'];
                if ($e['rate_type'] === 'daily') {
                    $fixed = $fixed * 12 / $dpy; // monthly fixed amount converted to daily
                }
                $newRate = round($curRate * (1 + (float)$in['pct'] / 100) + $fixed, 2);
                break;
            case 'wage_order':
                $min = (float)$in['new_min_wage'];
                if ($min > 0 && $r['daily'] < $min) {
                    $flags[] = 'Below new minimum wage (' . money($r['daily']) . '/day)';
                    $newRate = $e['rate_type'] === 'daily' ? $min : round($min * $dpy / 12, 2);
                }
                break;
            case 'grade':
                if ($e['gid'] && isset($newGrades[$e['gid']])) {
                    $ng = $newGrades[$e['gid']];
                    if ($r['monthly'] < $ng['min']) {
                        $flags[] = 'Below revised ' . $e['grade_code'] . ' minimum (' . money($ng['min']) . ')';
                        if ($in['raise_to_min']) {
                            $newRate = $e['rate_type'] === 'daily' ? round($ng['min'] * 12 / $dpy, 2) : $ng['min'];
                        }
                    }
                }
                break;
        }
        $newMonthly = $e['rate_type'] === 'daily' ? $newRate * $dpy / 12 : $newRate;
        $er = fn($m) => sss_contribution($m)['er'] + sss_contribution($m)['ec'] + philhealth_contribution($m)['er'] + pagibig_contribution($m)['er'];
        $row = ['e' => $e, 'cur' => $curRate, 'new' => $newRate, 'cur_m' => $r['monthly'], 'new_m' => round($newMonthly, 2), 'flags' => $flags];
        $tot['cur'] += $r['monthly'];
        $tot['new'] += $newMonthly;
        $tot['cur_er'] += $er($r['monthly']);
        $tot['new_er'] += $er($newMonthly);
        if ($flags) {
            $tot['flag']++;
        }
        if (abs($newRate - $curRate) > 0.004) {
            $tot['changed']++;
        }
        // flag employees below the (current) grade minimum even in other scenarios
        if ($in['scenario'] !== 'grade' && $e['gmin'] && $newMonthly < (float)$e['gmin']) {
            $row['flags'][] = 'Below ' . $e['grade_code'] . ' minimum';
        }
        $result[] = $row;
    }
}

if (is_post() && post('_action') === 'commit') {
    $eff = $in['effective_date'] ?: today();
    $n = 0;
    $retro = 0.0;
    $label = ['atb' => 'Across-the-board increase', 'wage_order' => 'Minimum wage order adjustment', 'grade' => 'Salary grade restructuring'][$in['scenario']] ?? 'Salary scaling';
    tx(function () use ($result, $eff, $label, $newGrades, $in, &$n, &$retro) {
        if ($in['scenario'] === 'grade') {
            foreach ($newGrades as $gid => $ng) {
                update('salary_grades', ['minimum' => $ng['min'], 'midpoint' => $ng['mid'], 'maximum' => $ng['max']], 'id = ?', [$gid]);
                q('UPDATE salary_grade_steps SET amount = ROUND(amount * ?, 2) WHERE grade_id = ?', [1 + $ng['pct'] / 100, $gid]);
            }
        }
        foreach ($result as $r) {
            if (abs($r['new'] - $r['cur']) > 0.004) {
                $res = employee_apply_changes((int)$r['e']['id'], ['basic_rate' => $r['new']], $eff, $label, 'salary_change');
                $n++;
                $retro += $res['retro']['amount'] ?? 0;
            }
        }
    });
    audit('pis', 'salary_scaling_commit', 'employees', null, ['scenario' => $in['scenario'], 'employees' => $n, 'effective' => $eff]);
    flash('success', "Committed: $n employee rate(s) updated effective " . fmt_date($eff) . '.' . ($retro > 0 ? ' Retroactive differentials of ' . peso($retro) . ' were queued for the next payroll.' : ''));
    redirect('salary_scaling');
}

page_header('Salary Scaling Simulator', ['subtitle' => 'Model the financial impact of an increase, a wage order or a grade restructuring before committing.', 'back' => url('salary_grades')]);
?>
<form class="card" method="get">
  <input type="hidden" name="p" value="salary_scaling"><input type="hidden" name="run" value="1">
  <div class="form-grid">
    <div class="field"><label>Scenario</label><select name="scenario" id="scn"><?= options(['atb' => 'Across-the-board increase', 'wage_order' => 'RTWPB minimum wage order', 'grade' => 'Restructure salary grade table'], $in['scenario']) ?></select></div>
    <div class="field"><label>Scope: pay group</label><select name="scope_pg"><?= options(pay_group_options(), $in['scope_pg'], 'All') ?></select></div>
    <div class="field"><label>Scope: branch / region</label><select name="scope_branch"><?= options(branch_options(), $in['scope_branch'], 'All') ?></select></div>
    <div class="field"><label>Scope: unit</label><select name="scope_ou"><?= options(org_unit_options(), $in['scope_ou'], 'All') ?></select></div>
  </div>
  <div class="form-grid scn scn-atb" style="margin-top:12px">
    <div class="field"><label>Increase (%)</label><input type="number" step="0.01" name="pct" value="<?= h($in['pct']) ?>"></div>
    <div class="field"><label>Plus fixed amount (₱/month)</label><input type="number" step="0.01" name="fixed" value="<?= h($in['fixed']) ?>"></div>
    <div class="field"><label class="check"><input type="checkbox" name="include_daily" value="1"<?= checked($in['include_daily']) ?>> Include daily-rated employees</label></div>
  </div>
  <div class="form-grid scn scn-wage_order" style="margin-top:12px">
    <div class="field"><label>New daily minimum wage (₱)</label><input type="number" step="0.01" name="new_min_wage" value="<?= h($in['new_min_wage']) ?>" placeholder="e.g. 745.00"><small class="help">Employees whose daily-equivalent rate falls below are raised to the new minimum.</small></div>
  </div>
  <div class="scn scn-grade" style="margin-top:12px">
    <div class="form-grid">
      <div class="field"><label>Adjust all grades by (%)</label><input type="number" step="0.01" name="grade_pct" value="<?= h($in['grade_pct']) ?>"></div>
      <div class="field"><label class="check"><input type="checkbox" name="raise_to_min" value="1"<?= checked($in['raise_to_min']) ?>> Raise employees below the new minimum to the minimum</label></div>
    </div>
    <table class="table compact" style="margin-top:8px"><thead><tr><th>Grade</th><th class="num">Current min</th><th class="num">Current max</th><th>Override %</th></tr></thead><tbody>
    <?php foreach ($grades as $g): ?><tr><td><?= h($g['code']) ?> — <?= h($g['position_band']) ?></td><td class="num"><?= money($g['minimum']) ?></td><td class="num"><?= money($g['maximum']) ?></td>
      <td><input type="number" step="0.01" name="g[<?= $g['id'] ?>]" value="<?= h($gradePct[$g['id']] ?? '') ?>" placeholder="use default" style="max-width:130px"></td></tr><?php endforeach; ?>
    </tbody></table>
  </div>
  <div class="form-actions"><button class="btn btn-primary">Run simulation</button></div>
</form>
<script>
(function(){var s=document.getElementById('scn');function f(){document.querySelectorAll('.scn').forEach(function(el){el.style.display=el.classList.contains('scn-'+s.value)?'':'none';});}s.addEventListener('change',f);f();})();
</script>

<?php if ($simulated): $diff = $tot['new'] - $tot['cur']; $diffEr = $tot['new_er'] - $tot['cur_er']; ?>
<div class="stats">
  <?= stat_card('Current monthly basic payroll', peso($tot['cur']), count($result) . ' employees in scope', 'info') ?>
  <?= stat_card('Proposed monthly basic payroll', peso($tot['new']), $tot['changed'] . ' employee(s) changed', 'info') ?>
  <?= stat_card('Monthly increase', peso($diff), ($tot['cur'] > 0 ? number_format($diff / $tot['cur'] * 100, 2) : 0) . '%, annual ' . peso($diff * 12), $diff > 0 ? 'warn' : '') ?>
  <?= stat_card('Employer statutory share Δ', peso($diffEr), 'SSS/EC + PhilHealth + Pag-IBIG per month', '') ?>
  <?= stat_card('Flagged employees', (string)$tot['flag'], 'below revised minimum', $tot['flag'] ? 'bad' : 'good') ?>
</div>
<?php if ($in['scenario'] === 'grade'): ?>
<div class="card"><h3>Revised grade table</h3><table class="table compact"><thead><tr><th>Grade</th><th class="num">%</th><th class="num">New minimum</th><th class="num">New midpoint</th><th class="num">New maximum</th></tr></thead><tbody>
<?php foreach ($grades as $g): $ng = $newGrades[$g['id']]; ?><tr><td><?= h($g['code']) ?></td><td class="num"><?= h($ng['pct']) ?></td><td class="num"><?= money($ng['min']) ?></td><td class="num"><?= money($ng['mid']) ?></td><td class="num"><?= money($ng['max']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<div class="card">
  <div class="card-head"><h3>Employee impact</h3>
    <form method="post" data-confirm="Commit these rate changes to the masterfile? This updates salaries (and the grade table for restructuring) and records employment history.">
      <?= csrf_field() ?><input type="hidden" name="_action" value="commit">
      <?php foreach ($in as $k => $v): if (is_array($v) || in_array($k, ['p', 'run', '_csrf', '_action'], true)) continue; ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>"><?php endforeach; ?>
      <?php foreach ($gradePct as $k => $v): ?><input type="hidden" name="g[<?= (int)$k ?>]" value="<?= h($v) ?>"><?php endforeach; ?>
      <label style="display:inline-block;margin-right:6px">Effective date <input type="date" name="effective_date" value="<?= h($in['effective_date']) ?>" style="width:auto"></label>
      <button class="btn btn-primary"<?= $tot['changed'] ? '' : ' disabled' ?>>Commit changes</button>
    </form>
  </div>
  <div class="table-wrap"><table class="table compact">
    <thead><tr><th>Employee</th><th>Grade</th><th>Type</th><th class="num">Current rate</th><th class="num">Proposed rate</th><th class="num">Monthly Δ</th><th>Flags</th></tr></thead><tbody>
    <?php foreach ($result as $r): $d = $r['new_m'] - $r['cur_m']; ?>
      <tr class="<?= $r['flags'] ? 'row-warn' : '' ?>"><td><?= h(full_name($r['e'])) ?> <span class="muted small"><?= h($r['e']['employee_no']) ?></span></td><td><?= h($r['e']['grade_code']) ?></td><td><?= h($r['e']['rate_type']) ?></td>
        <td class="num"><?= money($r['cur']) ?></td><td class="num"><?= money($r['new']) ?></td><td class="num"><?= $d ? money($d) : '' ?></td><td class="small"><?= h(implode('; ', $r['flags'])) ?></td></tr>
    <?php endforeach; ?></tbody>
    <tfoot><tr><td colspan="3">Total (monthly equivalent)</td><td class="num"><?= money($tot['cur']) ?></td><td class="num"><?= money($tot['new']) ?></td><td class="num"><?= money($diff) ?></td><td></td></tr></tfoot>
  </table></div>
</div>
<?php endif;
page_footer();
