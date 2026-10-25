<?php
/**
 * Payroll / Attendance BATCH transaction entry (M.A.D.A.S. Payroll Batch Transaction Entry).
 * Routes: pay_batch, att_batch. One grid row per employee, one column per hour field,
 * grouped exactly like the batch form (Regular, Overtime Reg./Ext., Day-off holiday, Night premium, Others).
 *
 * Filtered on a company / branch, the grid lists the employees of that branch and everyone with
 * hours recorded there on a multi-location entry, and each row shows (and saves) only the part of
 * the transaction that belongs to that branch. With All, every transaction is shown whole.
 */
require __DIR__ . '/_trans_form.php';

$route = (string)($_GET['p'] ?? 'pay_batch');
$isAms = $route === 'att_batch';
$side = pt_side($route);   // att: open transactions; pay: closed transactions only
$filters = pt_filters();
$period = pt_default_period((int)get('cutoff_id') ?: null);
$mode = get('mode') === 'review' ? 'review' : 'transaction';
$editable = pt_period_editable($period, $side) && $mode === 'transaction';
$keep = array_filter(array_merge(['p' => $route, 'cutoff_id' => $period['id'] ?? null, 'mode' => $mode], $filters), fn($v) => $v !== null && $v !== '');
$keep = array_merge($keep, back_params());
$branchF = count($filters['branch_id']) === 1 ? (int)$filters['branch_id'][0] : 0;   // branch view (one Co. / Branch chosen): the rows are the branch parts of the transactions

$dimp = dimp_def($isAms ? 'att_batch' : 'pay_trans', null, ['cutoff_id' => $period['id'] ?? null]);   // CSV import facility on this form (app/engine/dataimport.php)
dimp_handle($dimp);

if (is_post()) {
    verify_csrf();
    $pid = (int)post('cutoff_id');
    $p = pt_period($pid);
    try {
        if (!$p || !pt_period_editable($p, $side)) {
            throw new RuntimeException('This period is ' . ($p ? $p['status'] : 'missing') . ' — transactions can no longer be changed.');
        }
        $payload = json_decode((string)post('payload', '{}'), true);
        if (!is_array($payload)) {
            throw new RuntimeException('The grid could not be read. Please try again.');
        }
        $saved = 0;
        $errors = [];
        foreach ($payload as $eid => $vals) {
            $eid = (int)$eid;
            if (!$eid || !is_array($vals)) {
                continue;
            }
            $part = (int)($vals['_branch'] ?? 0);
            unset($vals['_branch']);
            [$values, $errs] = pt_clean($vals, $p);
            if ($errs) {
                $name = val('SELECT CONCAT(last_name, ", ", first_name) FROM employees WHERE id = ?', [$eid]);
                $errors[] = $name . ': ' . implode(' ', $errs);
                continue;
            }
            $existing = pt_get($pid, $eid);
            if (!pt_row_editable($p, $existing, $side)) {
                $name = val('SELECT CONCAT(last_name, ", ", first_name) FROM employees WHERE id = ?', [$eid]);
                $errors[] = $name . ': ' . ($side === 'pay' ? 'not closed yet' : 'closed (re-open it to change it here)');
                continue;
            }
            if ($part && $existing) {
                // branch view of a multi-location transaction: only this branch's hours change; the totals
                // become the sum of the locations again, and the Others amounts change only on the deduction source
                $splits = pt_branches((int)$existing['id']);
                $eRow = row('SELECT * FROM employees WHERE id = ?', [$eid]);
                [$cur, , $isSrc] = pt_branch_part($existing, $splits, $part, (int)$eRow['default_branch_id']);
                $changed = false;
                foreach ($values as $k => $v) {
                    if (abs((float)$cur[$k] - (float)$v) > 0.001 && (in_array($k, pt_location_fields(), true) || $isSrc)) {
                        $changed = true;
                    }
                }
                if (!$changed) {
                    continue;
                }
                $splits[$part] = array_intersect_key($values, array_flip(pt_location_fields()));
                pt_save_branches((int)$existing['id'], $splits);
                if ($isSrc) {
                    $others = array_diff_key(array_intersect_key($values, array_flip(pt_all_fields())), array_flip(pt_location_fields()));
                    if ($others) {
                        update('attendance_summaries', $others + ['source' => 'manual', 'updated_at' => now(), 'entered_by' => user_id()], 'id = ?', [$existing['id']]);
                    }
                }
                audit('payroll', 'transaction_branch_part', 'attendance_summaries', $existing['id'], ['employee_id' => $eid, 'branch_id' => $part]);
                $saved++;
                continue;
            }
            $any = (bool)array_filter($values, fn($v) => (float)$v != 0.0);
            if (!$existing && !$any) {
                continue;
            }
            if ($existing) {
                $same = true;
                foreach ($values as $k => $v) {
                    if (abs((float)$existing[$k] - (float)$v) > 0.001) {
                        $same = false;
                        break;
                    }
                }
                if ($same) {
                    continue;
                }
            }
            // monthly-salaried: leaves are deducted from the perfect attendance hours work
            $eRow = row('SELECT * FROM employees WHERE id = ?', [$eid]);
            [$values] = pt_apply_leave_rule($values, $eRow ?: [], $p);
            pt_save($pid, $eid, $values, $existing['remarks'] ?? null, 'manual');
            $saved++;
        }
        if ($errors) {
            flash('error', implode(' | ', array_slice($errors, 0, 5)));
        }
        flash('success', $saved . ' transaction(s) saved.');
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect($route, $keep);
}

page_header($isAms ? 'Attendance Batch Transaction Entry' : 'Payroll Batch Transaction', [
    'subtitle' => 'Encode the period hours of a whole company, department, employee group, employee type or pay code in one grid. Blank cells are zero.',
    'actions' => '<a class="btn" href="' . url($isAms ? 'att_summary' : 'pay_trans', $keep) . '">Single entry</a>'
        . '<a class="btn" href="' . url($isAms ? 'att_summary2' : 'pay_trans2', $keep) . '">Transaction II</a>'
        . ($period && $isAms ? '<a class="btn" href="' . h(url_back('att_close', array_diff_key($keep, ['p' => 1, 'mode' => 1]))) . '">Attendance closing</a>' : ''),
    'wide' => true,
]);
echo dimp_panel($dimp);
echo pt_filter_bar($route, $period, $filters, ['mode' => $mode]);
if (!$period) {
    echo '<div class="card"><div class="empty-state"><h3>' . (pt_open_only_route() ? 'No open pay period' : 'No pay period yet') . '</h3><p class="muted">' . (pt_open_only_route() ? 'Every period is closed and paid. Define the next period in Period Parameters.' : '') . '</p><a class="btn btn-primary" href="' . url('periods') . '">Period Parameters</a></div></div>';
    page_footer();
    return;
}
$employees = pt_period_employees($period, $filters + ['with_split' => 1]);
$trans = [];
foreach (rows('SELECT * FROM attendance_summaries WHERE cutoff_id = ?', [$period['id']]) as $r) {
    $trans[(int)$r['employee_id']] = $r;
}
$openCount = count(array_filter($trans, fn($r) => !$r['is_closed']));
if ($side === 'pay') {
    // only closed attendance transactions appear in the Payroll Transaction
    $employees = array_values(array_filter($employees, fn($e) => !empty($trans[(int)$e['id']]['is_closed'])));
}
$splitsAll = pt_period_splits((int)$period['id']);
$branchName = $branchF ? (string)val('SELECT CONCAT(COALESCE(branch_code, code), " ", name) FROM branches WHERE id = ?', [$branchF]) : '';
$groups = [];
foreach (PT_FIELDS as $f => $meta) {
    $groups[$meta[2]][] = $f;
}
// the values each row shows: the whole transaction, or in the branch view the part of that branch
$shown = [];
$tot = array_fill_keys(pt_all_fields(), 0.0);
foreach ($employees as $e) {
    $r = $trans[(int)$e['id']] ?? null;
    if ($branchF) {
        [$vals, $isSplit, $isSrc] = pt_branch_part($r, $splitsAll[(int)$e['id']] ?? [], $branchF, (int)$e['default_branch_id']);
    } else {
        $vals = $r ? array_map('floatval', array_intersect_key($r, array_flip(pt_all_fields()))) : array_fill_keys(pt_all_fields(), 0.0);
        $isSplit = !empty($splitsAll[(int)$e['id']]);
        $isSrc = true;
    }
    $shown[(int)$e['id']] = ['vals' => $vals, 'split' => $isSplit, 'src' => $isSrc];
    foreach ($vals as $k => $v) {
        $tot[$k] += $v;
    }
}
?>
<div class="toolbar">
  <span class="label">Process mode</span>
  <div class="seg">
    <a class="<?= $mode === 'transaction' ? 'active' : '' ?>" href="<?= h(url($route, array_merge($keep, ['mode' => 'transaction']))) ?>">Transaction</a>
    <a class="<?= $mode === 'review' ? 'active' : '' ?>" href="<?= h(url($route, array_merge($keep, ['mode' => 'review']))) ?>">Review</a>
  </div>
  <span class="sep"></span>
  <span class="label">View column</span>
  <?php foreach (PT_GROUPS as $g => $label): ?>
    <label class="check small"><input type="checkbox" data-toggle-group="<?= h($g) ?>" data-grid="batchGrid" checked> <?= h($label) ?></label>
  <?php endforeach; ?>
  <span class="spacer"></span>
  <?= pt_period_badge($period) ?>
  <span class="muted small"><?= count($employees) ?> employee(s), <span id="dirtyCount">0</span> changed</span>
</div>

<?php if ($side === 'pay' && $openCount): ?>
  <div class="callout warn">Only closed attendance transactions are listed here. <?= $openCount ?> transaction(s) of this period are still open on the attendance side:
    <a href="<?= h(url('att_close', ['cutoff_id' => $period['id']])) ?>">close them in Attendance Transaction Closing</a>.</div>
<?php elseif ($side === 'att' && count($trans) - $openCount > 0): ?>
  <div class="callout"><?= count($trans) - $openCount ?> transaction(s) of this period are closed: they are shown read-only here and belong to the Payroll Transaction. <a href="<?= h(url('att_close', ['cutoff_id' => $period['id']])) ?>">Attendance Transaction Closing</a></div>
<?php endif; ?>
<?php if ($branchF): ?>
  <div class="callout branch-view">Showing <strong><?= h($branchName) ?></strong>: its employees and everyone with hours recorded there on a multi-location entry. Rows marked <strong>Part</strong> show only the hours rendered in <?= h($branchName) ?>; saving changes that location and the total becomes the sum of the locations again. The amounts under Others belong to the deduction source. Choose All companies to see every transaction whole.</div>
<?php endif; ?>
<form method="post" data-json-grid="1">
  <?= csrf_field() ?>
  <input type="hidden" name="cutoff_id" value="<?= (int)$period['id'] ?>">
  <div class="grid-wrap" id="batchGridWrap">
    <table class="grid-entry" id="batchGrid">
      <thead>
        <tr class="grp">
          <th class="sticky" style="width:88px">&nbsp;</th>
          <th class="sticky-2" style="min-width:210px">&nbsp;</th>
          <?php foreach ($groups as $g => $fields): ?>
            <th class="g-<?= h($g) ?>" colspan="<?= count($fields) ?>" data-col-group="<?= h($g) ?>"><?= h(PT_GROUPS[$g]) ?></th>
          <?php endforeach; ?>
          <th rowspan="2" style="min-width:90px">Multi loc.</th>
        </tr>
        <tr>
          <th class="sticky">Emp. no.</th>
          <th class="sticky-2">Employee name</th>
          <?php foreach ($groups as $g => $fields) {
              foreach ($fields as $f) {
                  echo '<th data-col-group="' . h($g) . '" title="' . h(PT_FIELDS[$f][0]) . '">' . h(PT_FIELDS[$f][1]) . '</th>';
              }
          } ?>
        </tr>
      </thead>
      <tbody>
      <?php
      $branchCodes = pairs('SELECT id, COALESCE(branch_code, code) FROM branches');
      $branchNames = pairs('SELECT id, name FROM branches');
      foreach ($employees as $e):
          $r = $trans[(int)$e['id']] ?? null;
          $nloc = count($splitsAll[(int)$e['id']] ?? []);
          $sv = $shown[(int)$e['id']];
          $partRow = $branchF && $sv['split'];
          $rowEdit = $editable && pt_row_editable($period, $r, $side);
          $isMonthly = $e['rate_type'] === 'monthly' && empty($e['timekeeping_exempt']);
          $perfect = $isMonthly && $editable ? pt_perfect_hours($period, $e) : 0; ?>
        <tr class="<?= $partRow ? 'is-part' : '' ?>"<?= $perfect > 0 && !$partRow ? ' data-perfect-hours="' . h(number_format($perfect, 2, '.', '')) . '" data-monthly="1"' : '' ?>>
          <td class="txt sticky"><?= h($e['employee_no']) ?><?php if ($partRow && $rowEdit): ?><input type="hidden" data-cell="1" data-row="<?= (int)$e['id'] ?>" data-field="_branch" value="<?= $branchF ?>"><?php endif; ?></td>
          <td class="txt sticky-2">
            <a href="<?= h(url_back($isAms ? 'att_summary' : 'pay_trans', ['cutoff_id' => $period['id'], 'emp' => $e['id']])) ?>"><?= h(full_name($e)) ?></a>
            <?= $r ? '<span class="badge badge-' . ($r['source'] === 'manual' ? 'blue">M' : 'green">A') . '</span>' : '' ?>
            <?php if ($r && $r['is_closed'] && $side === 'att'): ?><small class="part-tag">Closed</small><?php endif; ?>
            <?php if ($partRow): ?><small class="part-tag" title="Only the hours rendered in <?= h($branchName) ?> are shown">Part of a multi-location transaction<?= (int)$e['default_branch_id'] !== $branchF ? ', employer ' . h($e['branch_code']) : '' ?></small><?php endif; ?>
          </td>
          <?php foreach ($groups as $g => $fields) {
              foreach ($fields as $f) {
                  $v = (float)$sv['vals'][$f];
                  $val = $f === 'tickets' ? ($v ? (string)(int)$v : '') : ($v ? number_format($v, 2, '.', '') : '');
                  $locked = $branchF && $g === 'others' && !$sv['src'];   // Others amounts belong to the deduction source
                  printf('<td data-col-group="%s"%s>%s</td>', h($g), $locked ? ' class="cell-na" title="Charged to the deduction source"' : '',
                      $rowEdit && !$locked
                          ? sprintf('<input class="cell%s" type="number" step="%s" min="0" value="%s" data-cell="1" data-row="%d" data-field="%s" title="%s">',
                              $v ? '' : ' zero', $f === 'tickets' ? '1' : '0.01', h($val), (int)$e['id'], h($f), h(PT_FIELDS[$f][0] . ' — ' . full_name($e)))
                          : '<span style="display:block;padding:6px 8px;text-align:right">' . h($val) . '</span>');
              }
          } ?>
          <?php $srcB = $r && !empty($r['deduction_branch_id']) && (int)$r['deduction_branch_id'] !== (int)$e['default_branch_id'] ? (int)$r['deduction_branch_id'] : 0; ?>
          <td class="txt multi-cell<?= $srcB ? ' is-source' : '' ?>" title="<?= $srcB ? h('Deduction source: ' . ($branchNames[$srcB] ?? '')) : 'Split the hours over several companies / branches / divisions and set the deduction source' ?>">
            <a class="btn btn-xs" href="<?= h(url_back('trans_multiloc', ['cutoff_id' => $period['id'], 'emp' => $e['id'], 'side' => $side])) ?>"><?= $nloc ? $nloc . ' loc.' : 'Split' ?></a>
            <?php if ($srcB): ?><small class="src-tag">Ded. <?= h($branchCodes[$srcB] ?? '') ?></small><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$employees): ?>
        <tr><td class="txt" colspan="<?= 3 + count(PT_FIELDS) ?>"><div class="empty-state"><h3>No employees</h3><p class="muted"><?= $side === 'pay' ? 'No closed attendance transaction matches the filters.' : 'No employee of this pay code matches the filters.' ?></p></div></td></tr>
      <?php endif; ?>
      </tbody>
      <tfoot>
        <tr>
          <td class="sticky">Total</td><td class="sticky-2"><?= count($employees) ?> employee(s)</td>
          <?php foreach ($groups as $g => $fields) {
              foreach ($fields as $f) {
                  echo '<td data-col-group="' . h($g) . '" data-total="' . h($f) . '">' . h(pt_fmt($tot[$f] ?? 0, $f)) . '</td>';
              }
          } ?>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <div class="toolbar sticky-bottom">
    <?php if ($editable): ?>
      <button class="btn btn-primary">Save batch</button>
      <span class="muted small">Only rows you changed are written. Values are hours (tickets and the amounts under “Others” are counts / pesos).</span>
    <?php else: ?>
      <span class="muted small"><?= pt_period_editable($period, $side) ? 'Review mode — switch to Transaction to edit.' : 'This period is ' . h($period['status']) . ' and can no longer be edited.' ?></span>
    <?php endif; ?>
    <span class="spacer"></span>
    <a class="btn" href="<?= h(url('att_generate', ['cutoff_id' => $period['id']])) ?>">Auto-generate from DTR</a>
    <a class="btn" href="<?= h(url('pay_register', ['cutoff_id' => $period['id']])) ?>">Payroll register</a>
  </div>
</form>
<div class="grid-note">
  <span><span class="legend-dot" style="background:#eef2ff"></span>Regular</span>
  <span><span class="legend-dot" style="background:#fdecef"></span>Overtime</span>
  <span><span class="legend-dot" style="background:#fff1e6"></span>Day-off holiday</span>
  <span><span class="legend-dot" style="background:#f1ecfe"></span>Night premium</span>
  <span><span class="legend-dot" style="background:#e6f6ee"></span>Others</span>
  <span>M = manually encoded, A = generated from attendance</span>
  <span><span class="legend-dot" style="background:var(--pink-bg)"></span>Ded. = deduction source set on the multi-location entry</span>
</div>
<?php page_footer();
