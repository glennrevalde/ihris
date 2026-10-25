<?php
/**
 * CSV import screen shared by six imports (see app/engine/csvimport.php):
 *   import_pay_trans    Payroll Transaction            import_att_batch   Attendance Batch Transaction
 *   import_emp_trans    Employee's Transactions        import_fixed_trans Employee's Fixed Transaction Setup
 *   import_deductions   Employee Deductions            import_benefits    Employee Benefits
 *
 * 1. Download the format.  2. Load the file.  3. Check data: every wrong cell is reported.
 * 4. Correct the cells here (or in the file and load it again), check again.  5. Save to database,
 *    which is possible only when every row checks clean. Period transactions can keep or replace
 *    the entries already encoded for the period.
 */
$route = (string)($_GET['p'] ?? '');
$kind = csvi_kind_of_route($route);
if (!$kind) {
    throw new RuntimeException('Unknown import.');
}
$def = csvi_kinds()[$kind];
$cols = csvi_columns($kind);
$S = &$_SESSION['csvi'][$kind];
$perPage = 150;
$pg = max(1, (int)get('pg', 1));
$show = in_array(get('show'), ['all', 'errors', 'notes'], true) ? get('show') : null;
$backLink = back_url() ?: url($def['return']);

// the format (blank, or with the employees of the period and their current figures)
if (get('template')) {
    $period = $def['period'] ? pt_default_period((int)get('cutoff_id') ?: null) : null;
    $withEmp = get('template') === 'period' && $period;
    [$header, $data] = csvi_template($kind, $period, (bool)$withEmp);
    audit($kind === 'att_batch' ? 'ams' : 'payroll', 'csv_format_download', null, null, ['import' => $kind, 'cutoff_id' => $period['id'] ?? null, 'with_employees' => (bool)$withEmp]);
    csv_download($kind . '_import_format' . ($withEmp ? '_' . $period['period_start'] . '_' . $period['period_end'] : '') . '.csv', $header, $data);
}
if (get('errors_csv') && !empty($S['rows'])) {
    [$header, $data] = csvi_error_report($kind, $S['rows']);
    csv_download($kind . '_import_errors.csv', $header, $data);
}

$readOpt = function () use ($def): array {
    $mode = (string)post('mode', 'keep');
    return [
        'cutoff_id' => $def['period'] ? (int)post('cutoff_id') : null,
        'mode' => $def['replace'] ? (in_array($mode, ['keep', 'replace', 'replace_all'], true) ? $mode : 'keep') : null,
        'update' => $def['update'] ? (bool)post('update') : false,
    ];
};

if (is_post()) {
    verify_csrf();
    $act = (string)post('_action');
    try {
        if ($act === 'load') {
            if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
                throw new RuntimeException('Choose the CSV file first.');
            }
            $ext = strtolower((string)pathinfo((string)$_FILES['csv']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['csv', 'txt'], true)) {
                throw new RuntimeException('Save the spreadsheet as CSV (comma separated) and load that file.');
            }
            $read = csvi_read((string)$_FILES['csv']['tmp_name'], $kind);
            if (!$read['rows']) {
                throw new RuntimeException('The file has the headers but no data row.');
            }
            $S = ['file' => (string)$_FILES['csv']['name'], 'rows' => $read['rows'], 'opt' => $readOpt(), 'checked' => false, 'counts' => null, 'unknown' => $read['unknown']];
            flash('success', count($read['rows']) . ' row(s) loaded from ' . $S['file'] . '. Press Check data to validate them.'
                . ($read['unknown'] ? ' Columns not in the format were ignored: ' . implode(', ', array_slice($read['unknown'], 0, 8)) . '.' : ''));
        } elseif ($act === 'check' || $act === 'save') {
            if (empty($S['rows'])) {
                throw new RuntimeException('Load a file first.');
            }
            // corrections typed on the grid, and rows removed
            $edits = json_decode((string)post('edits', '{}'), true) ?: [];
            foreach ($edits as $i => $cells) {
                if (!isset($S['rows'][(int)$i]) || !is_array($cells)) {
                    continue;
                }
                foreach ($cells as $k => $v) {
                    if (array_key_exists($k, $cols)) {
                        $S['rows'][(int)$i]['data'][$k] = trim(mb_substr((string)$v, 0, 255));
                    }
                }
            }
            $remove = array_map('intval', json_decode((string)post('remove', '[]'), true) ?: []);
            if ($remove) {
                foreach ($remove as $i) {
                    unset($S['rows'][$i]);
                }
                $S['rows'] = array_values($S['rows']);
            }
            $S['opt'] = $readOpt();
            $S['counts'] = csvi_check($kind, $S['rows'], $S['opt']);
            $S['checked'] = true;
            if ($act === 'check' || $S['counts']['error']) {
                if ($act === 'save' && $S['counts']['error']) {
                    throw new RuntimeException('Not saved: ' . $S['counts']['error'] . ' row(s) have errors. Correct them and check again.');
                }
                flash($S['counts']['error'] ? 'warning' : 'success', $S['counts']['error']
                    ? $S['counts']['error'] . ' of ' . $S['counts']['total'] . ' row(s) have errors, marked in red. Correct the cells (or remove the rows) and press Check data again.'
                    : 'All ' . $S['counts']['total'] . ' row(s) are valid. Press Save to database to write them.');
                $show = $S['counts']['error'] ? 'errors' : null;
            } else {
                $res = csvi_commit($kind, $S['rows'], $S['opt'], (string)$S['file']);
                $cut = $S['opt']['cutoff_id'] ?? null;
                $S = null;
                unset($_SESSION['csvi'][$kind]);
                $msg = [];
                foreach (['created' => 'created', 'updated' => 'updated', 'replaced' => 'replaced', 'kept' => 'kept (already encoded)', 'removed' => 'removed from the period', 'locked' => 'skipped (closed transactions on the attendance side, open ones on the payroll side)'] as $k => $l) {
                    if (!empty($res[$k])) {
                        $msg[] = $res[$k] . ' ' . $l;
                    }
                }
                flash('success', 'Import saved: ' . implode(', ', $msg ?: ['nothing to change']) . '.');
                $ret = in_array($kind, ['pay_trans', 'att_batch', 'emp_trans'], true) ? ['cutoff_id' => $cut] : [];
                header('Location: ' . (back_url() ?: url($def['return'], $ret)));
                exit;
            }
        } elseif ($act === 'discard') {
            $S = null;
            unset($_SESSION['csvi'][$kind]);
            flash('success', 'The loaded file was discarded.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect($route, array_merge(array_filter(['show' => $show]), back_params()));
}

$loaded = !empty($S['rows']);
$opt = $S['opt'] ?? ['cutoff_id' => null, 'mode' => 'keep', 'update' => $def['update']];
$period = $def['period'] ? pt_default_period((int)($opt['cutoff_id'] ?: get('cutoff_id')) ?: null) : null;
$counts = $S['counts'] ?? null;
$checked = !empty($S['checked']);
if ($loaded && $show === null) {
    $show = $checked && $counts && $counts['error'] ? 'errors' : 'all';
}

$actions = '<a class="btn" href="' . h(url($route, array_merge(['template' => 'blank'], back_params()))) . '">Download format</a>';
if ($def['period'] && $period && in_array($kind, ['pay_trans', 'att_batch', 'emp_trans'], true)) {
    $actions .= '<a class="btn" href="' . h(url($route, array_merge(['template' => 'period', 'cutoff_id' => $period['id']], back_params()))) . '" title="The format filled with every employee of the period' . (in_array($kind, ['pay_trans', 'att_batch'], true) ? ' and the figures already encoded' : '') . '">Format with the employees of the period</a>';
}
page_header($def['title'], [
    'subtitle' => 'Loads ' . h($def['what']),
    'back' => $backLink,
    'back_label' => 'Back to ' . (back_route() ? (menu_location((string)back_route())[1] ?: $def['return_label']) : $def['return_label']),
    'actions' => $actions,
    'wide' => $loaded,
]);
$modeLabels = [
    'keep' => ['Keep the existing entries', 'Employees who already have a transaction for the period are skipped.'],
    'replace' => ['Replace the entries of the employees in the file', 'Their transaction for the period is overwritten by the imported figures; the others stay.'],
    'replace_all' => ['Replace every entry of the period', 'The file becomes the whole period: transactions of employees not in the file are deleted.'],
];
$optionFields = function (bool $inGrid) use ($def, $opt, $period, $modeLabels, $kind) {
    ob_start();
    if ($def['period']): ?>
      <div class="field"><label>Pay period of the file</label><select name="cutoff_id" required><?= options(pt_period_options(), $period['id'] ?? null) ?></select>
        <span class="help"><?= $kind === 'emp_trans' ? 'One-time items are taken by the payroll run of this period; items with terms start on or after it.' : 'Only open or summarized periods can be changed.' ?></span></div>
    <?php endif;
    if ($def['replace'] && $inGrid): ?>
      <div class="field"><label>Existing entries of the period</label>
        <select name="mode"><?php foreach ($modeLabels as $m => [$lbl, $help]): ?><option value="<?= h($m) ?>"<?= ($opt['mode'] ?? 'keep') === $m ? ' selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?></select>
        <span class="help"><?= h($modeLabels[$opt['mode'] ?? 'keep'][1]) ?></span></div>
    <?php elseif ($def['replace']): ?>
      <div class="field wide"><label>Existing entries of the period</label>
        <div class="choice-list">
          <?php foreach ($modeLabels as $m => [$lbl, $help]): ?>
            <label class="choice"><input type="radio" name="mode" value="<?= h($m) ?>"<?= ($opt['mode'] ?? 'keep') === $m ? ' checked' : '' ?>><span><strong><?= h($lbl) ?></strong><small><?= h($help) ?></small></span></label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif;
    if ($def['update']): ?>
      <div class="field wide"><label class="check"><input type="checkbox" name="update" value="1"<?= !empty($opt['update']) ? ' checked' : '' ?>> Update existing: a row for an employee who already has an active entry of the same code overwrites it</label></div>
    <?php endif;
    return ob_get_clean();
};
?>

<?php if (!$loaded): ?>
<div class="grid grid-2-1 import-layout">
  <div class="card">
    <h2>Load the file</h2>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="_action" value="load">
      <div class="form-grid cols-2">
        <div class="field wide"><label>CSV file</label><input type="file" name="csv" accept=".csv,.txt,text/csv" required>
          <span class="help">Comma, semicolon or tab separated, first line = the column headers of the format. Up to <?= number_format(CSVI_MAX_ROWS) ?> rows.</span></div>
        <?= $optionFields(false) ?>
      </div>
      <div class="form-actions"><button class="btn btn-primary">Load file</button></div>
    </form>
  </div>
  <div class="card">
    <h2>How it works</h2>
    <ol class="man-steps">
      <li>Download the format<?= $def['period'] ? ', or the format already filled with the employees of the period' : '' ?>, and fill one row per <?= in_array($kind, ['pay_trans', 'att_batch'], true) ? 'employee (one row per location for a multi-location employee)' : 'item' ?>.</li>
      <li>Save it as CSV and load it here<?= $def['period'] ? ' with its pay period' : '' ?>.</li>
      <li>Press <strong>Check data</strong>. Every wrong value is marked in red with the reason.</li>
      <li>Correct the cells on screen (or fix the file and load it again) and check again.</li>
      <li>When every row is valid, press <strong>Save to database</strong>. Nothing is written before that.</li>
    </ol>
  </div>
</div>
<?php else:
    $rowsAll = $S['rows'];
    $idx = array_keys($rowsAll);
    if ($show === 'errors') {
        $idx = array_values(array_filter($idx, fn($i) => $rowsAll[$i]['status'] === 'error'));
    } elseif ($show === 'notes') {
        $idx = array_values(array_filter($idx, fn($i) => !empty($rowsAll[$i]['notes'])));
    }
    $pages = max(1, (int)ceil(count($idx) / $perPage));
    $pg = min($pg, $pages);
    $pageIdx = array_slice($idx, ($pg - 1) * $perPage, $perPage);
    $canSave = $checked && $counts && !$counts['error'] && $counts['total'] > 0;
    $statusLabel = ['unchecked' => ['Not checked', 'gray'], 'error' => ['Error', 'red'], 'new' => ['New', 'green'], 'update' => ['Update', 'blue'],
        'replace' => ['Replace', 'amber'], 'keep' => ['Skip (kept)', 'gray']];
?>
<div class="stats">
  <?= stat_card('File', h(mb_strimwidth((string)$S['file'], 0, 28, '…')), number_format(count($rowsAll)) . ' row(s) loaded', 'info') ?>
  <?php if ($checked && $counts): ?>
    <?= stat_card('Valid', number_format($counts['ok']), $counts['new'] . ' new' . ($counts['update'] ? ', ' . $counts['update'] . ' update' : '') . ($counts['replace'] ? ', ' . $counts['replace'] . ' replace' : '') . ($counts['keep'] ? ', ' . $counts['keep'] . ' kept' : ''), 'good') ?>
    <?= stat_card('With errors', number_format($counts['error']), $counts['error'] ? 'correct before saving' : 'none', $counts['error'] ? 'bad' : 'good') ?>
    <?php if (!empty($counts['removed_existing'])): ?><?= stat_card('Removed from the period', number_format($counts['removed_existing']), 'not in the file', 'warn') ?><?php endif; ?>
  <?php else: ?>
    <?= stat_card('Status', 'Not checked', 'press Check data', 'warn') ?>
  <?php endif; ?>
</div>

<form method="post" id="csviForm" class="csvi-form">
  <?= csrf_field() ?>
  <input type="hidden" name="edits" value="">
  <input type="hidden" name="remove" value="">
  <div class="card">
    <div class="card-head"><div><h2>Options</h2><div class="sub">Changing an option takes effect at the next Check data.</div></div></div>
    <div class="form-grid cols-2"><?= $optionFields(true) ?></div>
  </div>

  <div class="toolbar sticky-bottom csvi-bar">
    <button class="btn btn-primary" name="_action" value="check">Check data</button>
    <button class="btn btn-success" name="_action" value="save" <?= $canSave ? '' : 'disabled title="Available when every row is checked and valid"' ?> data-confirm="Save the <?= (int)($counts['ok'] ?? 0) ?> valid row(s) to the database?">Save to database</button>
    <?php if ($checked && $counts && $counts['error']): ?><a class="btn" href="<?= h(url($route, array_merge(['errors_csv' => 1], back_params()))) ?>">Download error report</a><?php endif; ?>
    <button class="btn btn-danger-outline" name="_action" value="discard" formnovalidate data-confirm="Discard the loaded file?">Load another file</button>
    <span class="spacer"></span>
    <div class="seg" role="tablist" aria-label="Rows shown">
      <a class="<?= $show === 'all' ? 'active' : '' ?>" href="<?= h(url($route, array_merge(['show' => 'all'], back_params()))) ?>">All rows</a>
      <a class="<?= $show === 'errors' ? 'active' : '' ?>" href="<?= h(url($route, array_merge(['show' => 'errors'], back_params()))) ?>">With errors<?= $counts ? ' (' . (int)$counts['error'] . ')' : '' ?></a>
      <a class="<?= $show === 'notes' ? 'active' : '' ?>" href="<?= h(url($route, array_merge(['show' => 'notes'], back_params()))) ?>">With notes</a>
    </div>
  </div>

  <div class="grid-wrap csvi-wrap">
    <table class="grid-entry csvi-grid" id="csviGrid">
      <thead>
        <tr>
          <th class="sticky" style="min-width:64px">Line</th>
          <th class="sticky-2 csvi-status">Status</th>
          <?php foreach ($cols as $k => $c): ?><th title="<?= h($c[2]) ?>"><?= h($c[0]) ?><?= $c[1] ? ' *' : '' ?></th><?php endforeach; ?>
          <th>Remove</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$pageIdx): ?>
        <tr><td class="txt" colspan="<?= count($cols) + 3 ?>"><div class="empty-state"><h3><?= $show === 'errors' ? 'No row with an error' : 'No row to show' ?></h3></div></td></tr>
      <?php endif; ?>
      <?php foreach ($pageIdx as $i):
          $r = $rowsAll[$i];
          [$sl, $tone] = $statusLabel[$r['status']] ?? [$r['status'], 'gray'];
          $msgs = $r['row_errors'];
          foreach ($r['errors'] as $k => $m) {
              $msgs[] = ($cols[$k][0] ?? $k) . ': ' . $m;
          } ?>
        <tr class="<?= $r['status'] === 'error' ? 'is-error' : '' ?>">
          <td class="txt sticky"><?= (int)$r['line'] ?></td>
          <td class="txt sticky-2 csvi-status"><span class="badge badge-<?= h($tone) ?>"><?= h($sl) ?></span>
            <?php if (!empty($r['norm']['employee_name'])): ?><small class="d-block muted"><?= h($r['norm']['employee_name']) ?></small><?php endif; ?></td>
          <?php foreach ($cols as $k => $c):
              $bad = isset($r['errors'][$k]); ?>
            <td class="<?= $bad ? 'cell-bad' : '' ?>"<?= $bad ? ' title="' . h($r['errors'][$k]) . '"' : '' ?>><input class="csv-cell" type="text" value="<?= h($r['data'][$k] ?? '') ?>" data-r="<?= (int)$i ?>" data-k="<?= h($k) ?>" aria-label="<?= h($c[0]) ?>, line <?= (int)$r['line'] ?>" size="<?= max(6, min(22, mb_strlen((string)($r['data'][$k] ?? '')) + 2, mb_strlen($c[0]))) ?>"></td>
          <?php endforeach; ?>
          <td class="center"><input type="checkbox" data-remove="<?= (int)$i ?>" aria-label="Remove line <?= (int)$r['line'] ?>"></td>
        </tr>
        <?php if ($msgs || $r['notes']): ?>
          <tr class="csvi-msg <?= $msgs ? 'is-error' : '' ?>"><td class="sticky"></td><td class="txt" colspan="<?= count($cols) + 2 ?>">
            <?php foreach ($msgs as $m): ?><div class="text-bad"><?= h($m) ?></div><?php endforeach; ?>
            <?php foreach ($r['notes'] as $m): ?><div class="muted"><?= h($m) ?></div><?php endforeach; ?>
          </td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <div class="pager">
      <?php for ($p = 1; $p <= $pages; $p++): ?>
        <a class="btn btn-sm<?= $p === $pg ? ' btn-soft' : '' ?>" href="<?= h(url($route, array_merge(['show' => $show, 'pg' => $p], back_params()))) ?>"><?= $p ?></a>
      <?php endfor; ?>
      <span class="muted small">Corrections on this page are kept when you press Check data before moving to another page.</span>
    </div>
  <?php endif; ?>
</form>
<script>
(function () {
  var form = document.getElementById('csviForm');
  if (!form) return;
  form.addEventListener('submit', function () {
    var edits = {}, remove = [];
    Array.prototype.forEach.call(form.querySelectorAll('input[data-k]'), function (inp) {
      if (inp.value !== inp.defaultValue) {
        var r = inp.getAttribute('data-r');
        (edits[r] = edits[r] || {})[inp.getAttribute('data-k')] = inp.value;
      }
      inp.disabled = true;
    });
    Array.prototype.forEach.call(form.querySelectorAll('input[data-remove]'), function (cb) {
      if (cb.checked) remove.push(parseInt(cb.getAttribute('data-remove'), 10));
      cb.disabled = true;
    });
    form.querySelector('input[name=edits]').value = JSON.stringify(edits);
    form.querySelector('input[name=remove]').value = JSON.stringify(remove);
    setTimeout(function () { Array.prototype.forEach.call(form.querySelectorAll('input[data-k], input[data-remove]'), function (i) { i.disabled = false; }); }, 4000);
  });
  // a corrected cell loses its red mark at once
  form.addEventListener('input', function (e) {
    var td = e.target.closest('td.cell-bad');
    if (td && e.target.value !== e.target.defaultValue) td.classList.add('cell-fixed');
  });
})();
</script>
<?php endif; ?>

<div class="grid grid-2-1 import-layout">
  <div class="card flush">
    <div class="card-head"><div><h2>Columns of the format</h2><div class="sub">* = required. The header may use the column key or its name.</div></div></div>
    <div class="table-wrap"><table class="table compact">
      <thead><tr><th>Column</th><th>Name</th><th>Description</th><th>Example</th></tr></thead>
      <tbody>
      <?php foreach ($cols as $k => $c): ?>
        <tr><td class="mono nowrap"><?= h($k) ?><?= $c[1] ? ' *' : '' ?></td><td><?= h($c[0]) ?></td><td class="small"><?= h($c[2]) ?></td><td class="mono small"><?= h($c[3]) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="card">
    <h2>Recent imports</h2>
    <?php $recent = rows('SELECT b.*, u.username FROM csv_import_batches b LEFT JOIN users u ON u.id = b.uploaded_by WHERE b.kind = ? ORDER BY b.id DESC LIMIT 8', [$kind]); ?>
    <div class="table-wrap"><table class="table compact">
      <thead><tr><th>When</th><th>File</th><th class="num">Rows</th><th class="num">Saved</th></tr></thead>
      <tbody>
      <?php if (!$recent) echo empty_row(4, 'No import yet.'); foreach ($recent as $b): ?>
        <tr><td class="small nowrap"><?= h(fmt_dt($b['created_at'], 'M d, H:i')) ?><br><span class="muted"><?= h($b['username']) ?></span></td><td class="small"><?= h(mb_strimwidth($b['file_name'], 0, 30, '…')) ?></td>
          <td class="num"><?= (int)$b['total_rows'] ?></td><td class="num"><?= (int)$b['created_rows'] + (int)$b['updated_rows'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>
<?php page_footer();
