<?php
/**
 * Import Log Configuration — how a log file is read: delimited columns or fixed-width
 * start/width positions for the employee ID, date, time, log type and device, with a
 * "Test import" preview of the first lines of a real file.
 * v22: the layout "Attendance record report" reads an Excel report with one block per employee (e.g. JJM Logs):
 * the ID label, the period label, the day-number row and the first time row, and for every layout the
 * minimum minutes between two logs of the same day (a repeated tap on the terminal is not staged).
 */
$rpOk = mig_column_exists('import_profiles', 'rp_id_label');   // database version 19
$id = (int)get('id', 0);
$rec = $id ? row('SELECT * FROM import_profiles WHERE id = ?', [$id]) : null;
$preview = null;
$previewErr = null;

if (is_post()) {
    verify_csrf();
    $action = post('_action');
    $data = [
        'name' => trim((string)post('name')) ?: 'Log format',
        'layout' => in_array(post('layout'), ['fixed', 'report'], true) && ($rpOk || post('layout') === 'fixed') ? (string)post('layout') : 'delimited',
        'delimiter' => post('delimiter') ?: 'tab',
        'has_header' => post('has_header') ? 1 : 0,
        'col_bio_id' => (int)post('col_bio_id') ?: 1,
        'col_datetime' => post('col_datetime') !== '' ? (int)post('col_datetime') : null,
        'col_date' => post('col_date') !== '' ? (int)post('col_date') : null,
        'col_time' => post('col_time') !== '' ? (int)post('col_time') : null,
        'col_punch' => post('col_punch') !== '' ? (int)post('col_punch') : null,
        'col_device' => post('col_device') !== '' ? (int)post('col_device') : null,
        'datetime_format' => trim((string)post('datetime_format')) ?: 'Y-m-d H:i:s',
        'punch_map' => trim((string)post('punch_map')) ?: null,
        'default_branch_id' => post('default_branch_id') ?: null,
        'notes' => trim((string)post('notes')) ?: null,
    ];
    if (import_profiles_sorted()) {
        $data['sort_order'] = max(0, min(999, (int)post('sort_order')));   // v19: the order of the configurations in the selections
    }
    if ($rpOk) {
        // v22: attendance record report (Excel) and the minimum minutes between logs
        $data += ['rp_id_label' => mb_substr(trim((string)post('rp_id_label')), 0, 40) ?: 'User ID:', 'rp_name_label' => mb_substr(trim((string)post('rp_name_label')), 0, 40) ?: null,
            'rp_period_label' => mb_substr(trim((string)post('rp_period_label')), 0, 40) ?: null, 'rp_date_format' => mb_substr(trim((string)post('rp_date_format')), 0, 20) ?: 'Y-m-d',
            'rp_day_row' => post('rp_day_row') === '' ? 1 : max(0, min(9, (int)post('rp_day_row'))), 'rp_time_row' => max(1, min(9, (int)post('rp_time_row') ?: 2)),
            'rp_sheet' => max(1, min(50, (int)post('rp_sheet') ?: 1)), 'min_gap' => max(0, min(120, (int)post('min_gap')))];
    }
    foreach (['id', 'date', 'time', 'type', 'dev'] as $k) {
        $data['fw_' . $k . '_start'] = post('fw_' . $k . '_start') !== '' ? (int)post('fw_' . $k . '_start') : null;
        $data['fw_' . $k . '_width'] = post('fw_' . $k . '_width') !== '' ? (int)post('fw_' . $k . '_width') : null;
    }
    try {
        if ($action === 'delete' && $id) {
            q('DELETE FROM import_profiles WHERE id = ?', [$id]);
            audit('ams', 'import_profile_delete', 'import_profiles', $id);
            flash('success', 'Configuration deleted.');
            redirect('import_config');
        }
        if ($action === 'default' && $id) {
            q('UPDATE import_profiles SET is_default = IF(id = ?, 1, 0)', [$id]);
            flash('success', 'Default configuration set.');
            redirect('import_config', ['id' => $id]);
        }
        if ($action === 'save' || $action === 'test') {
            if ($id) {
                update('import_profiles', $data, 'id = ?', [$id]);
                audit('ams', 'import_profile_update', 'import_profiles', $id, $data);
            } else {
                $id = insert('import_profiles', $data);
                audit('ams', 'import_profile_create', 'import_profiles', $id, $data);
            }
            if ($action === 'save') {
                flash('success', 'Import configuration saved.');
                redirect('import_config', ['id' => $id]);
            }
            // test import: parse the uploaded sample without staging anything
            if (empty($_FILES['sample']['tmp_name'])) {
                throw new RuntimeException('Choose a sample log file to test the configuration.');
            }
            $profile = row('SELECT * FROM import_profiles WHERE id = ?', [$id]);
            $tmp = (string)$_FILES['sample']['tmp_name'];
            if ($profile['layout'] === 'report') {
                // the first rows of the sheet, cell by cell (an .xls is not text)
                $raw = [];
                foreach (array_slice(grid_read_file($tmp, max(0, (int)$profile['rp_sheet'] - 1)), 0, 12, true) as $rn => $cells) {
                    $raw[] = sprintf('%3d  ', $rn + 1) . implode('  |  ', array_map(fn($c, $v) => import_col_letter($c) . ': ' . str_replace(["\r", "\n"], ['', ' / '], trim((string)$v)), array_keys($cells), $cells));
                }
                $all = parse_log_file($tmp, $profile);
                $_SESSION['import_test'] = ['rows' => array_slice($all, 0, 40), 'raw' => $raw, 'name' => (string)$_FILES['sample']['name'], 'report' => true,
                    'count' => count($all), 'ids' => count(array_unique(array_column($all, 'bio_id')))];
            } else {
                $_SESSION['import_test'] = ['rows' => array_slice(parse_log_file($tmp, $profile, 25), 0, 25),
                    'raw' => array_slice(file($tmp, FILE_IGNORE_NEW_LINES), 0, 8), 'name' => (string)$_FILES['sample']['name']];
            }
            flash('success', 'Test import finished — the preview is below. Nothing was imported.');
            redirect('import_config', ['id' => $id, 'test' => 1]);
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
        redirect('import_config', ['id' => $id]);
    }
}
if (get('test') && !empty($_SESSION['import_test'])) {
    $preview = $_SESSION['import_test'];
    unset($_SESSION['import_test']);
}
$list = rows('SELECT p.*, (SELECT COUNT(*) FROM import_batches b WHERE b.profile_id = p.id) AS uses FROM import_profiles p ORDER BY ' . (import_profiles_sorted() ? 'p.sort_order, ' : '') . 'p.id');
$rec = $rec ?: ($id ? row('SELECT * FROM import_profiles WHERE id = ?', [$id]) : null);
$v = fn(string $k, $d = '') => h((string)($rec[$k] ?? $d));

page_header('Import Log Configuration', [
    'subtitle' => 'One configuration per log format. Fixed-width files are read by character positions (start and width), delimited files by column number.',
    'actions' => '<a class="btn" href="' . url('import_logs') . '">Import attendance logs</a>' . ($id ? '<a class="btn" href="' . url('import_config') . '">New configuration</a>' : ''),
]);
?>
<div class="grid grid-1-2">
  <div class="card flush">
    <div class="card-head"><h2>Configurations</h2></div>
    <div class="list-nav">
      <?php foreach ($list as $p): ?>
        <a class="<?= (int)$p['id'] === $id ? 'active' : '' ?>" href="<?= h(url('import_config', ['id' => $p['id']])) ?>">
          <span class="ln-main"><strong><?= h($p['name']) ?><?= $p['is_default'] ? ' ★' : '' ?></strong>
            <small><?= isset($p['sort_order']) ? (int)$p['sort_order'] . '. ' : '' ?><?= h(ucfirst(IMPORT_LAYOUTS[$p['layout']] ?? $p['layout'])) ?>, <?= (int)$p['uses'] ?> import(s)</small></span>
        </a>
      <?php endforeach; ?>
      <?php if (!$list): ?><p class="muted" style="padding:12px">No configuration yet.</p><?php endif; ?>
    </div>
  </div>

  <div class="card">
    <h2><?= $rec ? 'Edit configuration' : 'New configuration' ?></h2>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="field wide"><label>Name</label><input name="name" value="<?= $v('name') ?>" required placeholder="e.g. ZKTeco attlog.dat"></div>
        <?php if (import_profiles_sorted()): ?><div class="field"><label>Display order</label><input type="number" min="0" max="999" name="sort_order" value="<?= $v('sort_order', $rec ? 0 : (int)val('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM import_profiles')) ?>">
          <span class="help">Position in the Import configuration lists (1 = first).</span></div><?php endif; ?>
        <div class="field"><label>Layout</label><select name="layout" id="layoutSel"><?= options(['delimited' => 'Delimited columns (TAB / CSV)', 'fixed' => 'Fixed width (positions)']
            + ($rpOk ? ['report' => 'Attendance record report (Excel, one block per employee)'] : []), $rec['layout'] ?? 'delimited') ?></select></div>
        <?php if ($rpOk): ?><div class="field"><label>Minimum minutes between logs</label><input type="number" min="0" max="120" name="min_gap" value="<?= $v('min_gap', 0) ?>">
          <span class="help">A log of the same employee and day less than this many minutes after the previous one (07:57 then 07:58) is a repeated tap and is not staged. 0 = keep every log.</span></div><?php endif; ?>
        <div data-not-report class="field"><label>With header</label><label class="check"><input type="checkbox" name="has_header" value="1" <?= checked(!empty($rec['has_header'])) ?>> The first line is a header</label></div>
        <div data-not-report class="field"><label>Date &amp; time format</label><input name="datetime_format" value="<?= $v('datetime_format', 'Y-m-d H:i:s') ?>" required>
          <span class="help">PHP format of the date + time once joined, e.g. <code>Y-m-d H:i:s</code>, <code>m/d/Y h:i A</code>, <code>d/m/Y H:i</code></span></div>
        <div class="field"><label>Default company / branch</label><select name="default_branch_id"><?= options(pairs("SELECT id, CONCAT(COALESCE(branch_code, code), ' — ', name) FROM branches WHERE is_active = 1 ORDER BY code"), $rec['default_branch_id'] ?? null, 'None') ?></select></div>
        <div data-not-report class="field wide"><label>Log-type map</label><input name="punch_map" value="<?= $v('punch_map') ?>" placeholder="0=in,1=out,2=break_out,3=break_in">
          <span class="help">Leave blank to place the logs by the employee's shift code instead: 2 logs a day = In, Out; 4 = In, Break out, Break in, Out; 6 = In, Break out 1, Break in 1, Break out 2, Break in 2, Out. For ZK state codes type 0=in,1=out,2=break_out,3=break_in,4=break_out,5=break_in.</span></div>
      </div>

      <fieldset class="box" id="fxDelimited">
        <legend>Delimited columns</legend>
        <div class="form-grid cols-3">
          <div class="field"><label>Delimiter</label><select name="delimiter"><?= options(['tab' => 'Tab', 'comma' => 'Comma (,)', 'semicolon' => 'Semicolon (;)', 'pipe' => 'Pipe (|)', 'space' => 'Whitespace'], $rec['delimiter'] ?? 'tab') ?></select></div>
          <div class="field"><label>Column: employee / biometric ID</label><input type="number" min="1" name="col_bio_id" value="<?= $v('col_bio_id', 1) ?>"></div>
          <div class="field"><label>Column: date &amp; time (combined)</label><input type="number" min="0" name="col_datetime" value="<?= $v('col_datetime') ?>"></div>
          <div class="field"><label>Column: date</label><input type="number" min="0" name="col_date" value="<?= $v('col_date') ?>"></div>
          <div class="field"><label>Column: time</label><input type="number" min="0" name="col_time" value="<?= $v('col_time') ?>"></div>
          <div class="field"><label>Column: log type</label><input type="number" min="0" name="col_punch" value="<?= $v('col_punch') ?>"></div>
          <div class="field"><label>Column: device</label><input type="number" min="0" name="col_device" value="<?= $v('col_device') ?>"></div>
        </div>
      </fieldset>

      <?php if ($rpOk): ?>
      <fieldset class="box" id="fxReport">
        <legend>Attendance record report (Excel)</legend>
        <p class="muted small">One block per employee: the ID label with the ID in the next cell, the day numbers (under each ID row, or once at the top of the sheet), and the times of each day under its number (several times in one cell, one per line or run together as 08:3017:20; more rows below when the day has more logs). The year and month come from the period label. The report has no In / Out: the employee's shift code places the logs.</p>
        <div class="form-grid cols-3">
          <div class="field"><label>Employee ID label</label><input name="rp_id_label" value="<?= $v('rp_id_label', 'User ID:') ?>" maxlength="40">
            <span class="help">The ID is the next filled cell to the right (or the text after the label in the same cell). It must match the Biometric ID (or one of the Other biometric IDs) of the masterfile.</span></div>
          <div class="field"><label>Employee name label</label><input name="rp_name_label" value="<?= $v('rp_name_label', 'Name:') ?>" maxlength="40">
            <span class="help">For the preview and the log details only.</span></div>
          <div class="field"><label>Period label</label><input name="rp_period_label" value="<?= $v('rp_period_label', 'Attendance date:') ?>" maxlength="40">
            <span class="help">e.g. <code>Attendance date:2026-10-15~2026-10-28</code> (JJM) or <code>Att. Time</code> with the dates in the next cell (NBJ). Without it the Date from of the upload gives the month.</span></div>
          <div class="field"><label>Period date format</label><input name="rp_date_format" value="<?= $v('rp_date_format', 'Y-m-d') ?>" maxlength="20">
            <span class="help"><code>Y-m-d</code> = 2026-10-15, <code>m/d/Y</code> = 10/15/2026</span></div>
          <div class="field"><label>Day-number row</label><input type="number" min="0" max="9" name="rp_day_row" value="<?= $v('rp_day_row', 1) ?>">
            <span class="help">Rows below the ID row (1 = the next row, JJM Logs). 0 = one row of day numbers for the whole sheet, at the top (NBJ Logs).</span></div>
          <div class="field"><label>First time row</label><input type="number" min="1" max="9" name="rp_time_row" value="<?= $v('rp_time_row', 2) ?>">
            <span class="help">Rows below the ID row; the times continue down to the next ID row.</span></div>
          <div class="field"><label>Sheet</label><input type="number" min="1" max="50" name="rp_sheet" value="<?= $v('rp_sheet', 1) ?>">
            <span class="help">1 = the first worksheet of the file.</span></div>
        </div>
      </fieldset>
      <?php endif; ?>

      <fieldset class="box" id="fxFixed">
        <legend>Fixed width (start position and width, first character = 1)</legend>
        <table class="matrix">
          <thead><tr><th class="rowh"></th><th>Start</th><th>Width</th></tr></thead>
          <tbody>
          <?php foreach (['id' => 'Employee / biometric ID', 'date' => 'Date', 'time' => 'Time', 'type' => 'Log type', 'dev' => 'Bio ID / device'] as $k => $lbl): ?>
            <tr><th class="rowh"><?= h($lbl) ?></th>
              <td><input type="number" min="0" name="fw_<?= $k ?>_start" value="<?= $v('fw_' . $k . '_start') ?>"></td>
              <td><input type="number" min="0" name="fw_<?= $k ?>_width" value="<?= $v('fw_' . $k . '_width') ?>"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </fieldset>

      <fieldset class="box">
        <legend>Test import</legend>
        <div class="form-grid">
          <div class="field wide"><label>Sample log file</label><input type="file" name="sample" accept=".dat,.txt,.log,.csv,.tsv,.xls,.xlsx">
            <span class="help">The first lines are parsed and shown below (an Excel report: its first rows and the first 40 logs read). Nothing is imported.</span></div>
          <div class="field wide"><label>Notes</label><input name="notes" value="<?= $v('notes') ?>"></div>
        </div>
      </fieldset>

      <div class="form-actions">
        <button class="btn btn-primary" name="_action" value="save">Save configuration</button>
        <button class="btn" name="_action" value="test">Test import</button>
        <?php if ($id): ?>
          <button class="btn" name="_action" value="default">Set as default</button>
          <button class="btn btn-danger-outline" name="_action" value="delete" data-confirm="Delete this configuration?">Delete</button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<?php if ($preview): ?>
<div class="card">
  <h2>Preview — <?= h($preview['name']) ?></h2>
  <?php if (!empty($preview['report'])): ?><p class="muted small"><?= number_format((int)$preview['count']) ?> log(s) read for <?= (int)$preview['ids'] ?> employee ID(s); the first 40 are listed. Repeated taps inside the minimum minutes are already left out.</p><?php endif; ?>
  <h3><?= !empty($preview['report']) ? 'First rows of the sheet (column: value)' : 'Raw lines' ?></h3>
  <pre class="mono" style="background:var(--surface-2);padding:10px;border-radius:8px;overflow-x:auto"><?php
    $ruler = '';
  for ($i = 1; $i <= 80; $i++) {
      $ruler .= $i % 10 === 0 ? (string)(($i / 10) % 10) : ($i % 5 === 0 ? '+' : '.');
  }
  echo (!empty($preview['report']) ? '' : h($ruler) . "\n") . h(implode("\n", $preview['raw']));
  ?></pre>
  <h3>Parsed result</h3>
  <div class="table-wrap">
    <table class="table table-compact">
      <thead><tr><th>Line</th><th>Employee / bio ID</th><th>Matched employee</th><th>Date &amp; time</th><th>Log type</th><th><?= !empty($preview['report']) ? 'Read from' : 'Device' ?></th><th>Error</th></tr></thead>
      <tbody>
      <?php // v24: the masterfile Biometric ID and the Other biometric IDs of the employees
      $names = pairs('SELECT id, CONCAT(last_name, ", ", first_name) FROM employees');
      $bioMap = array_map(fn($e) => $names[$e] ?? '—', bio_employee_map());
      foreach ($preview['rows'] as $r):
          $key = ltrim((string)$r['bio_id'], '0') ?: (string)$r['bio_id']; ?>
        <tr class="<?= $r['error'] ? 'row-bad' : '' ?>">
          <td><?= (int)$r['line_no'] ?></td>
          <td><?= h((string)$r['bio_id']) ?></td>
          <td><?= h($bioMap[$r['bio_id']] ?? ($bioMap[$key] ?? '—')) ?></td>
          <td><?= h((string)($r['log_time'] ?? '')) ?></td>
          <td><?= h((string)$r['punch_type']) ?></td>
          <td<?= !empty($preview['report']) ? ' class="small"' : '' ?>><?= h((string)(!empty($preview['report']) ? ($r['raw_text'] ?? '') : ($r['device_id'] ?? ''))) ?></td>
          <td class="small"><?= h((string)($r['error'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<script>
(function () {
  var sel = document.getElementById('layoutSel');
  function sync() {
    var v = sel.value, rep = document.getElementById('fxReport');
    document.getElementById('fxFixed').style.display = v === 'fixed' ? '' : 'none';
    document.getElementById('fxDelimited').style.display = v === 'delimited' ? '' : 'none';
    if (rep) rep.style.display = v === 'report' ? '' : 'none';
    Array.prototype.forEach.call(document.querySelectorAll('[data-not-report]'), function (el) { el.style.display = v === 'report' ? 'none' : ''; });
  }
  if (sel) { sel.addEventListener('change', sync); sync(); }
})();
</script>
<?php page_footer();
