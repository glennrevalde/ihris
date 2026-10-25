<?php
/**
 * Multi-location (multi-trans) transaction entry — the hours of one employee for one period split
 * over the companies / branches / divisions / segments where they were rendered (M.A.D.A.S.
 * "Multi-Trans" window of the Payroll and Attendance Batch Transaction Entry).
 *
 * Each location is paid separately (its own branch payslip and cost allocation). The earnings not
 * tied to worked hours and every deduction go to one location, the deduction source: the
 * employee's default employer unless another row is selected and "Set Deduction Source" is pressed.
 * That row turns pink and its payslip carries the deductions and the net pay.
 */
require __DIR__ . '/_trans_form.php';

$period = pt_default_period((int)get('cutoff_id') ?: null);
$empId = (int)get('emp', 0);
$emp = $empId ? row('SELECT e.*, ou.name AS unit_name, b.name AS branch_name FROM employees e LEFT JOIN org_units ou ON ou.id = e.org_unit_id
                     LEFT JOIN branches b ON b.id = e.default_branch_id WHERE e.id = ?', [$empId]) : null;
$legacyBack = (string)get('p2', '');
$backUrl = back_url() ?: url($legacyBack ?: 'pay_trans', array_filter(['cutoff_id' => $period['id'] ?? null, 'emp' => $empId]));
$backRoute = back_route() ?: ($legacyBack ?: 'pay_trans');
$backLabel = 'Back to ' . (menu_location($backRoute)[1] ?: 'the transaction');
// side: opened from an attendance screen (open transactions) or a payroll screen (closed transactions)
$side = get('side') === 'pay' || (get('side') === null && pt_side($backRoute) === 'pay') ? 'pay' : 'att';
$keep = array_merge(array_filter(['cutoff_id' => $period['id'] ?? null, 'emp' => $empId, 'p2' => $legacyBack ?: null, 'side' => $side]), back_params());
$rowNow = $period && $empId ? pt_get((int)$period['id'], $empId) : null;
$editable = pt_row_editable($period, $rowNow, $side);

if (is_post()) {
    verify_csrf();
    $pid = (int)post('cutoff_id');
    $eid = (int)post('employee_id');
    $p = pt_period($pid);
    $act = (string)post('_action', 'save');
    try {
        $rowP = $p ? pt_get($pid, $eid) : null;
        if (!pt_row_editable($p, $rowP, $side)) {
            throw new RuntimeException(pt_locked_reason($p, $rowP, $side));
        }
        if ($act === 'set_source' || $act === 'reset_source') {
            $summary = pt_get($pid, $eid);
            if (!$summary) {
                throw new RuntimeException('Save the hours of this employee first; the deduction source belongs to the transaction.');
            }
            $e = row('SELECT default_branch_id FROM employees WHERE id = ?', [$eid]);
            $b = $act === 'set_source' ? (int)post('branch_id') : 0;
            if ($act === 'set_source' && !val('SELECT COUNT(*) FROM branches WHERE id = ?', [$b])) {
                throw new RuntimeException('Select a company / branch row first.');
            }
            $new = $b && $b !== (int)$e['default_branch_id'] ? $b : null;
            update('attendance_summaries', ['deduction_branch_id' => $new, 'updated_at' => now()], 'id = ?', [$summary['id']]);
            audit('payroll', 'set_deduction_source', 'attendance_summaries', $summary['id'],
                ['employee_id' => $eid, 'cutoff_id' => $pid, 'from' => $summary['deduction_branch_id'], 'to' => $new]);
            $name = $new ? (string)val('SELECT name FROM branches WHERE id = ?', [$new]) : 'the default employer';
            flash('success', 'Deduction source set to ' . $name . '. The earnings not tied to hours and every deduction of this pay will be charged there. Re-process the payroll run to apply it.');
        } else {
            $payload = json_decode((string)post('payload', '{}'), true);
            if (!is_array($payload)) {
                throw new RuntimeException('The grid could not be read.');
            }
            $byBranch = [];
            foreach ($payload as $b => $vals) {
                $clean = [];
                foreach ((array)$vals as $k => $v) {
                    if (in_array($k, pt_location_fields(), true)) {
                        $v = trim(str_replace(',', '', (string)$v));
                        if ($v !== '' && (!is_numeric($v) || (float)$v < 0)) {
                            throw new RuntimeException('Hours must be positive numbers (' . PT_FIELDS[$k][0] . ').');
                        }
                        $clean[$k] = $v === '' ? 0 : round((float)$v, 2);
                    }
                }
                if (array_filter($clean, fn($v) => $v != 0.0)) {
                    $byBranch[(int)$b] = $clean;
                }
            }
            $summary = pt_get($pid, $eid);
            $id = $summary ? (int)$summary['id'] : pt_save($pid, $eid, pt_blank(), null, 'manual');
            pt_save_branches($id, $byBranch);
            audit('payroll', 'multi_location_save', 'attendance_summaries', $id, ['employee_id' => $eid, 'locations' => array_keys($byBranch)]);
            flash('success', $byBranch ? count($byBranch) . ' work location(s) saved; the transaction totals were updated.' : 'All work locations were cleared.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('trans_multiloc', $keep);
}

page_header('Multi-Location Entry', [
    'subtitle' => 'Hours rendered per company / branch / division for one employee and period. The transaction totals are the sum of these rows. Select a row and press Set Deduction Source to charge the deductions to that location.',
    'back' => $backUrl,
    'back_label' => $backLabel,
    'wide' => true,
]);
if (!$period || !$emp) {
    echo '<div class="card"><div class="empty-state"><h3>Select a period and an employee first</h3><a class="btn btn-primary" href="' . url('pay_trans') . '">Payroll transactions</a></div></div>';
    page_footer();
    return;
}
$summary = pt_get((int)$period['id'], $empId);
$rows = $summary ? pt_branches((int)$summary['id']) : [];
$prefilled = false;
if (!$rows && $summary) {
    // no split yet: the hours of the transaction start on the default employer's row, so adding a
    // location moves hours instead of wiping them
    $rows[(int)$emp['default_branch_id']] = array_intersect_key($summary, array_flip(pt_location_fields()));
    $prefilled = (bool)array_filter($rows[(int)$emp['default_branch_id']], fn($v) => (float)$v != 0.0);
}
$srcId = $summary && !empty($summary['deduction_branch_id']) ? (int)$summary['deduction_branch_id'] : (int)$emp['default_branch_id'];
$allBranches = rows("SELECT id, code, branch_code, company_code, name, is_default FROM branches WHERE is_active = 1 OR id IN (" . implode(',', array_map('intval', array_merge([(int)$emp['default_branch_id'], $srcId], array_keys($rows)))) . ") ORDER BY company_code, branch_code, code");
$shown = [];
foreach ($allBranches as $b) {
    $id = (int)$b['id'];
    if (isset($rows[$id]) || $id === (int)$emp['default_branch_id'] || $id === $srcId) {
        $shown[] = $b;
    }
}
$fields = pt_location_fields();
$groups = [];
foreach ($fields as $f) {
    $groups[PT_FIELDS[$f][2]][] = $f;
}
$branchLabel = fn(array $b) => trim((trim(($b['company_code'] ?? '') . ' ' . ($b['branch_code'] ?? '')) ?: $b['code']) . ' ' . $b['name']);
$cellHtml = function (int $branchId, string $f, float $v) use ($editable) {
    return $editable
        ? sprintf('<input class="cell%s" type="number" step="0.01" min="0" value="%s" data-cell="1" data-row="%d" data-field="%s">',
            $v ? '' : ' zero', $v ? h(number_format($v, 2, '.', '')) : '', $branchId, h($f))
        : '<span class="cell-ro">' . ($v ? h(number_format($v, 2)) : '') . '</span>';
};
?>
<div class="card">
  <div class="card-head">
    <div class="person">
      <span class="avatar"><?= h(mb_strtoupper(mb_substr($emp['first_name'], 0, 1) . mb_substr($emp['last_name'], 0, 1))) ?></span>
      <div><div class="person-name"><?= h(full_name($emp)) ?> <span class="muted">(Multi-Trans)</span></div>
        <div class="person-meta"><?= h($emp['employee_no']) ?>, default employer <?= h($emp['branch_name'] ?: '—') ?>, <?= h(pt_period_label($period)) ?></div></div>
    </div>
    <?= pt_period_badge($period) ?>
  </div>
  <?php if ($prefilled): ?><div class="callout" style="margin-bottom:10px">The hours of the transaction are shown on the employer's row. Move part of them to another location (add it below) and save.</div><?php endif; ?>
  <p class="help" style="margin:0">Every location is paid on its own branch payslip. Statutory contributions and the withholding tax are computed once for the whole pay; they, the loans and every other deduction are taken from the <strong>deduction source</strong> (the pink row), together with the earnings that are not hours (benefits, allowances, fixed and one-time transactions). Remittances stay under the employer of record.</p>
</div>

<form method="post" data-json-grid="1" id="locForm">
  <?= csrf_field() ?>
  <input type="hidden" name="cutoff_id" value="<?= (int)$period['id'] ?>">
  <input type="hidden" name="employee_id" value="<?= (int)$emp['id'] ?>">
  <input type="hidden" name="branch_id" id="locSel" value="">
  <div class="grid-wrap">
    <table class="grid-entry multi-trans" id="locGrid">
      <thead>
        <tr class="grp">
          <th class="sticky" style="min-width:250px">&nbsp;</th>
          <?php foreach ($groups as $g => $fs): ?><th class="g-<?= h($g) ?>" colspan="<?= count($fs) ?>"><?= h(PT_GROUPS[$g]) ?></th><?php endforeach; ?>
        </tr>
        <tr>
          <th class="sticky">Company / branch / division</th>
          <?php foreach ($groups as $g => $fs) {
              foreach ($fs as $f) {
                  echo '<th title="' . h(PT_FIELDS[$f][0]) . '">' . h(PT_FIELDS[$f][1]) . '</th>';
              }
          } ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($shown as $b):
            $id = (int)$b['id'];
            $r = $rows[$id] ?? null; ?>
          <tr data-branch="<?= $id ?>" class="<?= $id === $srcId ? 'row-source' : '' ?>" title="Click to select this location">
            <td class="txt sticky">
              <strong><?= h($branchLabel($b)) ?></strong>
              <span class="loc-tags"><?= $id === (int)$emp['default_branch_id'] ? '<span class="tag">employer of record</span>' : '' ?><?= $id === $srcId ? '<span class="tag tag-source">deduction source</span>' : '' ?></span>
            </td>
            <?php foreach ($groups as $g => $fs) {
                foreach ($fs as $f) {
                    echo '<td>' . $cellHtml($id, $f, $r ? (float)$r[$f] : 0.0) . '</td>';
                }
            } ?>
          </tr>
        <?php endforeach; ?>
        <?php if ($editable): ?>
          <tr class="add-row">
            <td class="txt sticky" colspan="1">
              <select id="locAdd" class="input-sm" aria-label="Add a company / branch / division">
                <option value="">+ Add a company / branch / division…</option>
                <?php foreach ($allBranches as $b): if (in_array($b, $shown, true)) continue; ?>
                  <option value="<?= (int)$b['id'] ?>"><?= h($branchLabel($b)) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td colspan="<?= count($fields) ?>"></td>
          </tr>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr><td class="sticky">Total (transaction)</td>
          <?php foreach ($groups as $g => $fs) {
              foreach ($fs as $f) {
                  echo '<td data-total="' . h($f) . '">' . h(pt_fmt($summary[$f] ?? 0, $f)) . '</td>';
              }
          } ?>
        </tr>
      </tfoot>
    </table>
  </div>
  <template id="locRowTpl">
    <tr data-branch="__ID__" title="Click to select this location">
      <td class="txt sticky"><strong>__LABEL__</strong> <span class="loc-tags"><span class="tag">new</span></span></td>
      <?php foreach ($groups as $g => $fs) {
          foreach ($fs as $f) {
              echo '<td><input class="cell zero" type="number" step="0.01" min="0" value="" data-cell="1" data-row="__ID__" data-field="' . h($f) . '"></td>';
          }
      } ?>
    </tr>
  </template>
  <div class="toolbar sticky-bottom">
    <?php if ($editable): ?>
      <button class="btn btn-primary" name="_action" value="save">Save work locations</button>
      <button class="btn btn-source" name="_action" value="set_source" id="btnSource" disabled title="Select a row first">Set Deduction Source</button>
      <?php if ($srcId !== (int)$emp['default_branch_id']): ?>
        <button class="btn" name="_action" value="reset_source" data-confirm="Charge the deductions back to the default employer?">Reset to employer</button>
      <?php endif; ?>
      <span class="muted small" id="locHint">Click a row to select it.</span>
    <?php else: ?>
      <span class="muted small"><?= h(pt_locked_reason($period, $rowNow, $side)) ?> Read only.</span>
    <?php endif; ?>
    <span class="spacer"></span>
    <a class="btn" href="<?= h($backUrl) ?>"><?= h($backLabel) ?></a>
  </div>
</form>
<div class="grid-note">
  <span><span class="legend-dot" style="background:var(--pink-bg)"></span>Deduction source: carries the deductions and the net pay</span>
  <span>Saving replaces the location rows and sets the transaction totals to their sum. Clear every cell to go back to a single-location transaction.</span>
</div>
<script>
(function () {
  var grid = document.getElementById('locGrid'), sel = document.getElementById('locSel'), btn = document.getElementById('btnSource'), hint = document.getElementById('locHint');
  if (!grid) return;
  function pick(tr) {
    Array.prototype.forEach.call(grid.querySelectorAll('tr.row-sel'), function (r) { r.classList.remove('row-sel'); });
    tr.classList.add('row-sel');
    sel.value = tr.getAttribute('data-branch');
    if (btn) { btn.disabled = false; btn.title = ''; }
    if (hint) hint.textContent = 'Selected: ' + tr.querySelector('strong').textContent + '. Press Set Deduction Source to charge the deductions there.';
  }
  grid.addEventListener('click', function (e) {
    var tr = e.target.closest('tbody tr[data-branch]');
    if (tr) pick(tr);
  });
  var add = document.getElementById('locAdd'), tpl = document.getElementById('locRowTpl');
  if (add && tpl) {
    add.addEventListener('change', function () {
      if (!add.value) return;
      var opt = add.options[add.selectedIndex];
      var html = tpl.innerHTML.replace(/__ID__/g, add.value).replace('__LABEL__', opt.textContent.replace(/[<>&]/g, ''));
      var body = document.createElement('tbody');
      body.innerHTML = html.trim();
      var tr = body.firstElementChild;
      add.closest('tr').parentNode.insertBefore(tr, add.closest('tr'));
      opt.remove();
      add.value = '';
      pick(tr);
      var first = tr.querySelector('input');
      if (first) first.focus();
    });
  }
})();
</script>
<?php page_footer();
