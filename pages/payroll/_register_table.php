<?php
/**
 * Payroll register table (shared by the screen view and the printed register).
 * Expects: $rows, $tot (totals), $group (bool, sub-total by department), $data.
 *
 * Others: double-clicking the "Others" header of the earnings or of the deductions replaces that
 * column by one column per item lumped in it (e.g. Position Allowance, Meal Subsidy); double-click
 * again to fold them back. ?expand=e / d / ed opens the register (and its print / CSV) expanded.
 *
 * v23: the column Adv./AR/Sal Loans unfolds the same way into one column per loan / advance / AR (Cash Advance,
 * Salary Loan ...): always in Register II, by a double-click of its header in the Batch Register (?expand=c).
 *
 * v22 Totals / Summary only: when $summary (register_summary()) is given, the lines are the totals of each
 * Co. / Branch, Department, Employee group and / or Employee type chosen instead of the employees.
 */
$summary = $summary ?? null;
$sumDims = $summary ? register_summary_dims() : [];
$lead = $summary ? count($summary['by']) + 1 : 2;   // columns before the earnings
$print = !empty($_GET['print']);
$regFixed = !empty($regFixed);   // Payroll Batch Register II: the details are always shown, nothing to fold
$expand = (string)get('expand', '');
$othE = $data['others_e'] ?? [];
$othD = $data['others_d'] ?? [];
$othC = $data['others_c'] ?? [];
$expE = strpos($expand, 'e') !== false && $othE;
$expD = strpos($expand, 'd') !== false && $othD;
$expC = strpos($expand, 'c') !== false && $othC;
$colsE = REG_EARN_COLS;
$colsD = REG_DED_COLS;
$cell = function (float $v) {
    return $v ? number_format($v, 2) : '';
};
$money = fn($v) => number_format((float)$v, 2);
/** Earnings (or deductions) cells of a row: the register columns, with the Others details right after Others. */
$cells = function (array $r, string $g, bool $isTotal) use ($colsE, $colsD, $othE, $othD, $othC, $cell, $money) {
    $cols = $g === 'e' ? $colsE : $colsD;
    $src = $g === 'e' ? 'earn' : 'ded';
    $det = $g === 'e' ? $othE : $othD;
    $dk = $g === 'e' ? 'oe' : 'od';
    $html = '';
    foreach (array_keys($cols) as $c) {
        $v = (float)($r[$src][$c] ?? 0);
        $fmt = $isTotal ? $money($v) : h($cell($v));
        if ($c === 'others' && $det) {
            $html .= '<td class="num o' . $g . '">' . $fmt . '</td>';
            foreach ($det as $o) {
                $dv = (float)($r[$dk][$o] ?? 0);
                $html .= '<td class="num oth-d o' . $g . '-d">' . ($isTotal ? $money($dv) : h($cell($dv))) . '</td>';
            }
        } elseif ($g === 'd' && $c === 'company_loan' && $othC) {
            // v23: Adv./AR/Sal Loans and its items (Cash Advance, Salary Loan ...)
            $html .= '<td class="num oc">' . $fmt . '</td>';
            foreach ($othC as $o) {
                $dv = (float)($r['oc'][$o] ?? 0);
                $html .= '<td class="num oth-d oc-d">' . ($isTotal ? $money($dv) : h($cell($dv))) . '</td>';
            }
        } else {
            $html .= '<td class="num">' . $fmt . '</td>';
        }
    }
    return $html;
};
$renderRow = function (array $r) use ($cells, $print) {
    $it = $r['item'];
    $part = $r['part'] ?? null;
    $cls = trim(($print ? '' : 'clickable') . ($part ? ' is-part' : '')); ?>
  <tr<?= $cls ? ' class="' . $cls . '"' : '' ?><?= $print ? '' : ' data-emp="' . (int)$it['employee_id'] . '" data-item="' . (int)$it['id'] . '" data-name="' . h(full_name($it)) . '" title="Click to select this employee for the buttons above"' ?>>
    <td class="nowrap"><?= h($it['employee_no']) ?></td>
    <td class="nowrap"><?= h(full_name($it)) ?>
      <?php if ($part): ?><small class="part-tag" title="Only the pay earned in this branch (<?= (int)$part['locations'] ?> locations worked)">Branch part, <?= $part['is_home'] ? 'deductions here' : 'deductions at ' . h($part['source_code']) ?></small><?php endif; ?>
    </td>
    <?= $cells($r, 'e', false) ?>
    <td class="num"><strong><?= h(number_format($r['gross'], 2)) ?></strong></td>
    <?= $cells($r, 'd', false) ?>
    <td class="num"><?= h($r['total_ded'] ? number_format($r['total_ded'], 2) : '') ?></td>
    <td class="num"><strong><?= h(number_format($r['net'], 2)) ?></strong></td>
    <?php if (!$print): ?>
      <td class="nowrap"><a class="btn btn-xs" href="<?= h(url_back('payslip', array_filter(['id' => $it['id'], 'branch' => $part ? (int)$part['branch_id'] : null]))) ?>"><?= $part ? 'Branch payslip' : 'Payslip' ?></a></td>
    <?php endif; ?>
  </tr>
<?php };
$renderTotal = function (array $t, string $label) use ($cells, $print) { ?>
  <tr class="sub">
    <td colspan="2"><?= h($label) ?></td>
    <?= $cells($t, 'e', true) ?>
    <td class="num"><?= h(number_format($t['gross'], 2)) ?></td>
    <?= $cells($t, 'd', true) ?>
    <td class="num"><?= h(number_format($t['total_ded'], 2)) ?></td>
    <td class="num"><?= h(number_format($t['net'], 2)) ?></td>
    <?php if (!$print): ?><td></td><?php endif; ?>
  </tr>
<?php };
// header spans: folded (Others) and expanded (its details instead)
$spanE = count($colsE) + 1;
$spanD = count($colsD) + 1;
$allCols = $spanE + $spanD + 2 + $lead;
?>
<table class="reg<?= $expE ? ' exp-e' : '' ?><?= $expD ? ' exp-d' : '' ?><?= $expC ? ' exp-c' : '' ?><?= $regFixed ? ' reg-fixed' : '' ?><?= $summary ? ' reg-summary' : '' ?>"<?= $print ? '' : ($summary ? '' : ' data-select-emp') . ($regFixed ? '' : ' data-reg-others') ?>>
  <thead>
    <tr>
      <?php if ($summary): ?>
        <?php foreach ($summary['by'] as $d): ?><th rowspan="2"><?= h($sumDims[$d][0]) ?></th><?php endforeach; ?><th rowspan="2" class="num">Employees</th>
      <?php else: ?>
      <th rowspan="2">Emp. no.</th><th rowspan="2">Employee name</th>
      <?php endif; ?>
      <th class="grp-e" colspan="<?= $expE ? $spanE - 1 + count($othE) : $spanE ?>" data-span="<?= $spanE ?>" data-span-exp="<?= $spanE - 1 + count($othE) ?>" data-grp="e">Earnings</th>
      <th class="grp-d" colspan="<?= $spanD + ($expD ? count($othD) - 1 : 0) + ($expC ? count($othC) - 1 : 0) ?>" data-span="<?= $spanD ?>" data-span-exp="<?= $spanD - 1 + count($othD) ?>" data-grp="d">Deductions</th>
      <th rowspan="2">Net pay</th>
      <?php if (!$print): ?><th rowspan="2"></th><?php endif; ?>
    </tr>
    <tr>
      <?php foreach ($colsE as $c => $lbl): ?>
        <?php if ($c === 'others' && $othE): ?>
          <th class="grp-e oe<?= $regFixed ? '' : ' oth-toggle' ?>" data-oth="e" title="<?= $regFixed ? '' : 'Double-click to show the details of Others (' . h(implode(', ', $othE)) . ')' ?>"><?= h($lbl) ?><?= $regFixed ? '' : '<span class="oth-hint">+</span>' ?></th>
          <?php foreach ($othE as $o): ?><th class="grp-e oth-d oe-d<?= $regFixed ? '' : ' oth-toggle' ?>" data-oth="e"<?= $regFixed ? '' : ' title="Double-click to fold back into Others"' ?>><?= h($o) ?></th><?php endforeach; ?>
        <?php else: ?>
          <th class="grp-e"><?= h($lbl) ?></th>
        <?php endif; ?>
      <?php endforeach; ?>
      <th class="grp-e">Gross</th>
      <?php foreach ($colsD as $c => $lbl): ?>
        <?php if ($c === 'others' && $othD): ?>
          <th class="grp-d od<?= $regFixed ? '' : ' oth-toggle' ?>" data-oth="d" title="<?= $regFixed ? '' : 'Double-click to show the details of Others (' . h(implode(', ', $othD)) . ')' ?>"><?= h($lbl) ?><?= $regFixed ? '' : '<span class="oth-hint">+</span>' ?></th>
          <?php foreach ($othD as $o): ?><th class="grp-d oth-d od-d<?= $regFixed ? '' : ' oth-toggle' ?>" data-oth="d"<?= $regFixed ? '' : ' title="Double-click to fold back into Others"' ?>><?= h($o) ?></th><?php endforeach; ?>
        <?php elseif ($c === 'company_loan' && $othC): ?>
          <th class="grp-d oc<?= $regFixed ? '' : ' oth-toggle' ?>" data-oth="c" title="<?= $regFixed ? '' : 'Double-click to show each loan / advance (' . h(implode(', ', $othC)) . ')' ?>"><?= h($lbl) ?><?= $regFixed ? '' : '<span class="oth-hint">+</span>' ?></th>
          <?php foreach ($othC as $o): ?><th class="grp-d oth-d oc-d<?= $regFixed ? '' : ' oth-toggle' ?>" data-oth="c"<?= $regFixed ? '' : ' title="Double-click to fold back into Adv./AR/Sal Loans"' ?>><?= h($o) ?></th><?php endforeach; ?>
        <?php else: ?>
          <th class="grp-d"><?= h($lbl) ?></th>
        <?php endif; ?>
      <?php endforeach; ?>
      <th class="grp-d">Total</th>
    </tr>
  </thead>
  <tbody>
  <?php
  if ($summary && $rows) {
      // v22: the totals of each combination (and the sub-totals of the first dimension)
      foreach ($summary['lines'] as $line) { ?>
    <tr class="<?= $line['sub'] ? 'sub' : 'sum-line' ?>">
      <?php foreach ($summary['by'] as $i => $d): ?>
        <td class="nowrap"><?= $line['sub'] ? ($i === 0 ? 'Sub-total — ' . h($line['keys'][$d]) : '') : h($line['keys'][$d]) ?></td>
      <?php endforeach; ?>
      <td class="num"><?= (int)$line['employees'] ?></td>
      <?= $cells($line['totals'], 'e', $line['sub']) ?>
      <td class="num"><strong><?= h(number_format($line['totals']['gross'], 2)) ?></strong></td>
      <?= $cells($line['totals'], 'd', $line['sub']) ?>
      <td class="num"><?= h(number_format($line['totals']['total_ded'], 2)) ?></td>
      <td class="num"><strong><?= h(number_format($line['totals']['net'], 2)) ?></strong></td>
      <?php if (!$print): ?><td></td><?php endif; ?>
    </tr>
  <?php }
  } elseif (!$rows) {
      echo '<tr><td colspan="' . ($allCols + count($othE) + count($othD) + count($othC)) . '" style="text-align:center;padding:24px;color:#666">No payroll item matches these filters.</td></tr>';
  } elseif (!empty($group)) {
      foreach (register_groups($data) as $unit => $g) {
          echo '<tr class="sub"><td colspan="' . ($allCols + count($othE) + count($othD) + count($othC)) . '">' . h($unit ?: 'Unassigned') . '</td></tr>';
          foreach ($g['rows'] as $r) {
              $renderRow($r);
          }
          $renderTotal($g['totals'], 'Sub-total — ' . ($unit ?: 'Unassigned'));
      }
  } else {
      foreach ($rows as $r) {
          $renderRow($r);
      }
  }
  ?>
  </tbody>
  <tfoot>
    <tr>
      <?php if ($summary): ?>
        <td colspan="<?= count($summary['by']) ?>">GRAND TOTAL</td><td class="num"><?= count(array_unique(array_map(fn($r) => (int)$r['item']['employee_id'], $rows))) ?></td>
      <?php else: ?>
      <td colspan="2">GRAND TOTAL (<?= count($rows) ?>)</td>
      <?php endif; ?>
      <?= $cells($tot, 'e', true) ?>
      <td class="num"><?= h(number_format($tot['gross'], 2)) ?></td>
      <?= $cells($tot, 'd', true) ?>
      <td class="num"><?= h(number_format($tot['total_ded'], 2)) ?></td>
      <td class="num"><?= h(number_format($tot['net'], 2)) ?></td>
      <?php if (!$print): ?><td></td><?php endif; ?>
    </tr>
  </tfoot>
</table>
