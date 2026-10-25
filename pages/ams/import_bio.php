<?php
/**
 * Biometric log file import (.txt / .dat) with configurable device mappings.
 */
$profiles = rows('SELECT * FROM import_profiles ORDER BY ' . (import_profiles_sorted() ? 'sort_order, ' : '') . 'id');
$preview = null;
if (is_post()) {
    $act = post('_action');
    $profile = row('SELECT * FROM import_profiles WHERE id = ?', [(int)post('profile_id')]);
    if (!$profile) {
        flash('error', 'Select an import mapping.');
        redirect('import_bio');
    }
    if ($act === 'upload') {
        try {
            $up = save_upload('logfile', 'imports', ['txt', 'dat', 'csv', 'log']);
            if (!$up) {
                throw new RuntimeException('Choose a log file to upload.');
            }
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('import_bio');
        }
        $_SESSION['bio_import'] = ['path' => $up['path'], 'name' => $up['name'], 'profile_id' => $profile['id'], 'branch_id' => post_null('branch_id')];
        redirect('import_bio', ['preview' => 1]);
    }
    if ($act === 'confirm' && !empty($_SESSION['bio_import'])) {
        $imp = $_SESSION['bio_import'];
        $rows = parse_biometric_file(upload_dir() . '/' . $imp['path'], $profile);
        $st = import_biometric_rows($rows, $profile, $imp['branch_id'] ? (int)$imp['branch_id'] : null, $imp['name']);
        unset($_SESSION['bio_import']);
        flash('success', sprintf('Imported %d punch(es) from %s: %d duplicate(s) skipped, %d unreadable line(s), %d punch(es) with unmatched biometric IDs (see Raw Punch Logs). DTRs were re-processed.',
            $st['imported'], $imp['name'], $st['duplicates'], $st['errors'], $st['unmatched']));
        redirect('import_bio');
    }
}
if (get('preview') && !empty($_SESSION['bio_import'])) {
    $imp = $_SESSION['bio_import'];
    $profile = row('SELECT * FROM import_profiles WHERE id = ?', [$imp['profile_id']]);
    $all = parse_biometric_file(upload_dir() . '/' . $imp['path'], $profile);
    $bioMap = pairs("SELECT biometric_id, CONCAT(last_name, ', ', first_name) FROM employees WHERE biometric_id IS NOT NULL");
    $preview = ['rows' => array_slice($all, 0, 50), 'total' => count($all), 'errors' => count(array_filter($all, fn($r) => $r['error'])),
        'unmatched' => count(array_filter($all, fn($r) => !$r['error'] && !isset($bioMap[$r['bio_id']]))), 'profile' => $profile, 'bioMap' => $bioMap];
}
$batches = rows("SELECT b.*, p.name AS profile_name, u.username FROM import_batches b LEFT JOIN import_profiles p ON p.id = b.profile_id LEFT JOIN users u ON u.id = b.uploaded_by ORDER BY b.id DESC LIMIT 20");
page_header('Biometric Log Import', ['subtitle' => 'Read raw device log files (delimited .txt / .dat with user ID, timestamp and punch type). Mappings let one system read logs from different device brands.',
    'actions' => '<a class="btn" href="' . url('import_profiles') . '">Device mappings</a>']);
?>
<?php if ($preview): ?>
<div class="card"><div class="card-head"><h2>Preview — <?= h($_SESSION['bio_import']['name']) ?></h2><span class="muted">Mapping: <?= h($preview['profile']['name']) ?></span></div>
  <div class="stats"><?= stat_card('Lines read', (string)$preview['total']) ?><?= stat_card('Unreadable', (string)$preview['errors'], '', $preview['errors'] ? 'bad' : '') ?><?= stat_card('Unmatched IDs', (string)$preview['unmatched'], 'kept for mapping', $preview['unmatched'] ? 'warn' : '') ?></div>
  <div class="table-wrap"><table class="table compact"><thead><tr><th>Line</th><th>Raw</th><th>User ID</th><th>Employee</th><th>Date-time</th><th>Punch</th><th>Issue</th></tr></thead><tbody>
  <?php foreach ($preview['rows'] as $r): ?><tr class="<?= $r['error'] ? 'row-bad' : (!isset($preview['bioMap'][$r['bio_id']]) ? 'row-warn' : '') ?>"><td><?= $r['line'] ?></td><td class="mono small"><?= h(mb_strimwidth($r['raw'], 0, 60, '…')) ?></td>
    <td class="mono"><?= h($r['bio_id']) ?></td><td><?= h($preview['bioMap'][$r['bio_id']] ?? '—') ?></td><td><?= h($r['time']) ?></td><td><?= h(label($r['type'])) ?></td><td class="small"><?= h($r['error']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if ($preview['total'] > 50): ?><p class="small muted">Showing the first 50 of <?= $preview['total'] ?> lines.</p><?php endif; ?>
  <form method="post" class="form-actions"><?= csrf_field() ?><input type="hidden" name="_action" value="confirm"><input type="hidden" name="profile_id" value="<?= $preview['profile']['id'] ?>">
    <button class="btn btn-primary">Import <?= $preview['total'] - $preview['errors'] ?> punch(es)</button> <a class="btn" href="<?= url('import_bio') ?>">Cancel</a></form>
</div>
<?php else: ?>
<div class="card"><h2>Upload device log</h2>
  <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="_action" value="upload">
    <div class="form-grid">
      <div class="field"><label>Device mapping</label><select name="profile_id" required><?= options(array_column($profiles, 'name', 'id')) ?></select></div>
      <div class="field"><label>Branch / site of the device</label><select name="branch_id"><?= options(branch_options(), null, '(use mapping default)') ?></select></div>
      <div class="field"><label>Log file (.txt, .dat, .csv, .log)</label><input type="file" name="logfile" accept=".txt,.dat,.csv,.log" required></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary">Upload &amp; preview</button></div>
  </form>
  <details style="margin-top:12px"><summary class="small">Example ZKTeco <code>attlog.dat</code> format</summary>
    <pre class="mono small" style="background:#f8fafc;padding:10px;border-radius:8px">    1001	2026-09-01 07:56:12	1	0	1	0
    1001	2026-09-01 17:05:41	1	1	1	0
    1008	2026-09-01 07:49:03	1	0	1	0</pre>
    <p class="small muted">Columns: user ID, date-time, device no., punch state (0 = check-in, 1 = check-out, 2 = break-out, 3 = break-in), verify mode, work code.</p></details>
</div>
<?php endif; ?>
<div class="card"><h2>Recent imports</h2>
  <table class="table compact"><thead><tr><th>Date</th><th>Type</th><th>File</th><th>Mapping</th><th class="num">Rows</th><th class="num">Imported</th><th class="num">Duplicates</th><th class="num">Flagged</th><th>By</th></tr></thead><tbody>
  <?php if (!$batches) echo empty_row(9); foreach ($batches as $b): ?><tr><td class="small"><?= h(fmt_dt($b['created_at'])) ?></td><td><?= h(label($b['batch_type'])) ?></td><td><?= h($b['file_name']) ?></td><td class="small"><?= h($b['profile_name']) ?></td>
    <td class="num"><?= (int)$b['total_rows'] ?></td><td class="num"><?= (int)$b['imported'] ?></td><td class="num"><?= (int)$b['duplicates'] ?></td><td class="num"><?= (int)$b['flagged'] ?></td><td class="small"><?= h($b['username']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
<?php page_footer();
