<?php
/**
 * Attendance Summary Report — the hours of every employee for a pay period
 * (the transactions that payroll pays), with totals and export.
 */
$period = pt_default_period((int)get('cutoff_id') ?: null);
$f = ['org_unit_id' => get('org_unit_id') ?: null, 'branch_id' => get('branch_id') ?: null, 'employee_group_id' => get('employee_group_id') ?: null,
    'employee_type_id' => get('employee_type_id') ?: null, 'q' => trim((string)get('q', ''))];
$cols = get('cols') === 'all' ? pt_all_fields() : ['hours_work', 'absent_hrs', 'late_hrs', 'undertime_hrs', 'vl_hrs', 'sl_hrs', 'el_hrs', 'ol_hrs', 'pm_hrs', 'holiday_hrs',
    'ot_reg', 'rd_hrs', 'rd_ot', 'lh_hrs', 'lh_ot', 'sh_hrs', 'sh_ot', 'np_reg'];

$rows = [];
$tot = array_fill_keys($cols, 0.0);
if ($period) {
    foreach (pt_period_employees($period, $f) as $e) {
        $t = pt_get((int)$period['id'], (int)$e['id']);
        if (!$t) {
            continue;
        }
        $rows[] = ['emp' => $e, 't' => $t];
        foreach ($cols as $c) {
            $tot[$c] += (float)$t[$c];
        }
    }
}
if (get('csv') && $period) {
    csv_download('attendance_summary_' . $period['period_start'] . '.csv',
        array_merge(['Employee no.', 'Employee name', 'Department', 'Source'], array_map(fn($c) => PT_FIELDS[$c][0], $cols)),
        array_map(fn($r) => array_merge([$r['emp']['employee_no'], full_name($r['emp']), $r['emp']['unit_name'], $r['t']['source']],
            array_map(fn($c) => number_format((float)$r['t'][$c], 2, '.', ''), $cols)), $rows));
}
if (get('print') && $period) {
    print_header('Attendance Summary — ' . pt_period_label($period), true);
    echo company_letterhead();
    echo '<h1>Attendance Summary Report</h1>';
    echo '<div class="doc-meta"><div><strong>' . h($period['pay_group_name']) . '</strong>, ' . h(pt_period_label($period, false)) . '</div><div>Printed ' . h(fmt_dt(now())) . '</div></div>';
    echo report_selection_html($f, [], true);
    echo '<table class="doc-table"><thead><tr><th>Emp. no.</th><th>Employee</th><th>Department</th>';
    foreach ($cols as $c) {
        echo '<th class="num">' . h(PT_FIELDS[$c][1]) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td>' . h($r['emp']['employee_no']) . '</td><td>' . h(full_name($r['emp'])) . '</td><td>' . h($r['emp']['unit_name'] ?? '') . '</td>';
        foreach ($cols as $c) {
            echo '<td class="num">' . h(pt_fmt($r['t'][$c], $c)) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody><tfoot><tr><td colspan="3">TOTAL (' . count($rows) . ')</td>';
    foreach ($cols as $c) {
        echo '<td class="num">' . h(number_format($tot[$c], 2)) . '</td>';
    }
    echo '</tr></tfoot></table>';
    echo signatory_block();
    print_footer();
    exit;
}

page_header('Attendance Summary Report', [
    'subtitle' => 'Hours per employee for a pay period — exactly what the payroll engine pays.',
    'wide' => true,
    'actions' => $period ? '<a class="btn" target="_blank" href="' . url('rpt_attendance', array_filter(array_merge(['cutoff_id' => $period['id'], 'print' => 1, 'cols' => get('cols')], $f))) . '">Print</a>'
        . '<a class="btn" href="' . url('rpt_attendance', array_filter(array_merge(['cutoff_id' => $period['id'], 'csv' => 1, 'cols' => get('cols')], $f))) . '">Export CSV</a>' : '',
]);
?>
<form method="get" class="card filters">
  <input type="hidden" name="p" value="rpt_attendance">
  <label>Period<select name="cutoff_id" data-autosubmit><?= options(pt_period_options(), $period['id'] ?? null, 'No period') ?></select></label>
  <label>Department<select name="org_unit_id"><?= options(pairs('SELECT id, name FROM org_units WHERE is_active = 1 ORDER BY name'), $f['org_unit_id'], 'All departments') ?></select></label>
  <label>Co. / Branch<select name="branch_id"><?= options(pairs("SELECT id, CONCAT(COALESCE(branch_code, code), ' — ', name) FROM branches WHERE is_active = 1 ORDER BY company_code, branch_code"), $f['branch_id'], 'All') ?></select></label>
  <label>Employee group<select name="employee_group_id"><?= options(emp_group_options(), $f['employee_group_id'], 'All groups') ?></select></label>
  <label>Columns<select name="cols" data-autosubmit><?= options(['main' => 'Main hour columns', 'all' => 'All columns'], get('cols', 'main')) ?></select></label>
  <label class="grow">Find<input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="Employee"></label>
  <button class="btn btn-primary">Apply</button>
</form>

<?php if (!$period): ?>
  <div class="card"><div class="empty-state"><h3>No pay period</h3><a class="btn btn-primary" href="<?= url('periods') ?>">Period Parameters</a></div></div>
<?php else: ?>
<div class="card flush">
  <div class="card-head">
    <div><h2><?= h(pt_period_label($period)) ?></h2><div class="sub"><?= count($rows) ?> transaction(s), <?= h($period['pay_group_name']) ?>, status <?= h($period['status']) ?></div><?= report_selection_html($f) ?></div>
  </div>
  <div class="table-wrap table-scroll">
    <table class="table table-compact">
      <thead><tr><th>Emp. no.</th><th>Employee</th><th>Department</th><th>Source</th><?php foreach ($cols as $c): ?><th class="num" title="<?= h(PT_FIELDS[$c][0]) ?>"><?= h(PT_FIELDS[$c][1]) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php if (!$rows) {
          echo empty_row(4 + count($cols), 'No transaction encoded for this period yet.');
      }
      foreach ($rows as $r): ?>
        <tr>
          <td><?= h($r['emp']['employee_no']) ?></td>
          <td><strong><?= h(full_name($r['emp'])) ?></strong></td>
          <td><?= h($r['emp']['unit_name'] ?? '—') ?></td>
          <td><span class="badge badge-<?= $r['t']['source'] === 'manual' ? 'blue' : 'green' ?>"><?= h(strtoupper(substr((string)$r['t']['source'], 0, 3))) ?></span></td>
          <?php foreach ($cols as $c): ?><td class="num"><?= h(pt_fmt($r['t'][$c], $c)) ?></td><?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="4">TOTAL</td><?php foreach ($cols as $c): ?><td class="num"><?= h(number_format($tot[$c], 2)) ?></td><?php endforeach; ?></tr></tfoot>
    </table>
  </div>
</div>
<?php endif;
page_footer();
