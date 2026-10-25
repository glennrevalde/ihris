<?php
$rows = rows('SELECT * FROM settings WHERE label IS NOT NULL ORDER BY grp, sort_order');
if (is_post()) {
    if (post('_action') === 'report_logo' || post('_action') === 'report_logo_remove') {
        // Company logo on reports (app/engine/branding.php)
        try {
            if (post('_action') === 'report_logo_remove') {
                $old = report_logo_file();
                if ($old) {
                    @unlink($old);
                }
                set_setting('report_logo_path', '');
                set_setting('report_logo_enabled', '0');
                audit('admin', 'report_logo_remove', 'settings', 'report_logo_path');
                flash('success', 'Company logo removed: the reports print the text letterhead again.');
            } else {
                $path = report_logo_store('report_logo');
                if ($path) {
                    set_setting('report_logo_path', $path);
                }
                $sel = array_values(array_intersect(array_map('strval', (array)post('logo_reports', [])), array_keys(report_logo_reports())));
                $all = post('logo_all') ? 'all' : implode(',', $sel);
                set_setting('report_logo_enabled', post('report_logo_enabled') ? '1' : '0');
                $place = (string)post('report_logo_placement', 'logo_center');
                set_setting('report_logo_placement', isset(LOGO_PLACEMENTS[$place]) ? $place : 'logo_center');
                set_setting('report_logo_reports', $all);
                audit('admin', 'report_logo_update', 'settings', 'report_logo', ['enabled' => post('report_logo_enabled') ? 1 : 0, 'reports' => $all === 'all' ? 'all' : count($sel), 'new_logo' => $path ? 1 : 0, 'placement' => report_logo_placement()]);
                if (post('report_logo_enabled') && !report_logo_file()) {
                    flash('warning', 'Upload the company logo: no logo is printed until one is uploaded.');
                } elseif (post('report_logo_enabled') && $all === '') {
                    flash('warning', 'No report is ticked: tick the reports that print the logo, or All reports.');
                }
                flash('success', 'Company logo on reports saved' . ($path ? ' (new logo uploaded)' : '') . '.');
            }
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        header('Location: ' . url('settings') . '#report-logo');
        exit;
    }
    if (post('_action') === 'test_mail') {
        $to = post('test_to') ?: current_user()['email'];
        $ok = send_mail((string)$to, null, app_name() . ' test email', email_template('Test email', '<p>Email delivery from ' . h(app_name()) . ' is working. Mode: <strong>' . h(setting('mail_mode')) . '</strong>.</p>'), 'test', null);
        $last = row('SELECT * FROM email_log ORDER BY id DESC LIMIT 1');
        flash($ok ? 'success' : 'error', $ok ? 'Test email ' . ($last['status'] === 'logged' ? 'recorded in the Email Outbox (log-only mode).' : 'sent to ' . $to . '.') : 'Sending failed: ' . ($last['error_message'] ?? 'invalid recipient'));
        redirect('settings');
    }
    $changes = [];
    $shown = ['general', 'mail', 'attendance', 'payroll', 'discipline'];
    foreach ($rows as $s) {
        $k = $s['skey'];
        if (!in_array($s['grp'], $shown, true)) {
            continue; // settings of other screens (AMS policies, payroll parameters, payslip, signatories) are not on this form
        }
        if ($s['input_type'] === 'bool') {
            $v = post($k) ? '1' : '0';
        } elseif ($s['input_type'] === 'password') {
            $v = post($k);
            if ($v === null || $v === '') {
                continue; // keep existing secret
            }
        } else {
            if (!array_key_exists($k, $_POST)) {
                continue; // not posted: never blank a value that was not on the form
            }
            $v = (string)post($k, '');
        }
        if ((string)$s['svalue'] !== (string)$v) {
            set_setting($k, $v);
            $changes[$k] = $s['input_type'] === 'password' ? '***' : ['from' => $s['svalue'], 'to' => $v];
        }
    }
    if ($changes) {
        audit('admin', 'settings_update', 'settings', null, $changes);
    }
    flash('success', count($changes) . ' setting(s) updated.');
    redirect('settings');
}
$groups = ['general' => 'Company', 'mail' => 'Email & leave approval links', 'attendance' => 'Attendance rules', 'payroll' => 'Payroll & compliance', 'discipline' => 'Discipline & performance'];
page_header('System Settings', ['subtitle' => 'Policy parameters used across the three modules. Statutory tables themselves are maintained under Payroll.']);
?>
<form method="post"><?= csrf_field() ?>
<?php foreach ($groups as $g => $gl): ?>
  <div class="card" id="<?= h($g) ?>"><h2><?= h($gl) ?></h2><div class="form-grid">
  <?php foreach (array_filter($rows, fn($r) => $r['grp'] === $g) as $s):
      $t = $s['input_type'];
      $name = h($s['skey']); ?>
    <div class="field">
      <?php if ($t === 'bool'): ?>
        <label class="check"><input type="checkbox" name="<?= $name ?>" value="1"<?= checked($s['svalue'] === '1') ?>> <?= h($s['label']) ?></label>
      <?php elseif (strpos($t, 'select:') === 0):
          $opts = [];
          foreach (explode(',', substr($t, 7)) as $o) {
              [$k, $l] = array_pad(explode('=', $o, 2), 2, $o);
              $opts[$k] = $l;
          } ?>
        <label><?= h($s['label']) ?></label><select name="<?= $name ?>"><?= options($opts, $s['svalue']) ?></select>
      <?php elseif ($t === 'password'): ?>
        <label><?= h($s['label']) ?></label><input type="password" name="<?= $name ?>" placeholder="<?= $s['svalue'] ? '•••••••• (unchanged)' : '' ?>" autocomplete="new-password">
      <?php else: ?>
        <label><?= h($s['label']) ?></label><input type="<?= $t === 'number' ? 'number" step="any' : ($t === 'time' ? 'time' : 'text') ?>" name="<?= $name ?>" value="<?= h($s['svalue']) ?>">
      <?php endif; ?>
      <?php if ($s['help']): ?><small class="help"><?= h($s['help']) ?></small><?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div></div>
<?php endforeach; ?>
<div class="card"><button class="btn btn-primary btn-lg">Save settings</button></div>
</form>
<?php
$logoUri = report_logo_uri();
$logoOn = setting('report_logo_enabled', '0') === '1';
$logoSel = (string)setting('report_logo_reports', '');
$logoAll = $logoSel === 'all';
$logoList = array_filter(explode(',', $logoSel));
$logoGroups = [];
foreach (report_logo_reports() as $r => [$l, $g]) {
    $logoGroups[$g][$r] = $l;
}
?>
<form method="post" enctype="multipart/form-data" class="card logo-card" id="report-logo"><?= csrf_field() ?><input type="hidden" name="_action" value="report_logo">
  <div class="card-head"><div><h2>Company logo on reports</h2><div class="sub">Optional. Upload the logo once, then choose the reports that print it in their letterhead.</div></div></div>
  <div class="logo-setup">
    <div class="logo-preview<?= $logoUri ? '' : ' is-empty' ?>">
      <?php if ($logoUri): ?><img src="<?= h($logoUri) ?>" alt="Company logo"><?php else: ?><span>No logo uploaded</span><?php endif; ?>
    </div>
    <div class="logo-fields">
      <label class="check logo-switch"><input type="checkbox" name="report_logo_enabled" value="1"<?= checked($logoOn) ?>> <strong>Add Company Logo to Reports</strong></label>
      <div class="field"><label>Company logo <?= $logoUri ? '(upload to replace)' : '' ?></label><input type="file" name="report_logo" accept="image/png,image/jpeg,image/gif,image/webp">
        <small class="help">PNG with a transparent background looks best. Resized to at most 600 x 240 pixels.</small></div>
    </div>
  </div>
  <div class="field logo-place-field"><label>Placement of the logo <small class="muted">(the same choices as the letterhead of Memo Templates &amp; Design)</small></label>
    <div class="logo-place" role="radiogroup" aria-label="Placement of the logo">
      <?php foreach (LOGO_PLACEMENTS as $pk => $pl): ?>
        <label><input type="radio" name="report_logo_placement" value="<?= h($pk) ?>"<?= checked(report_logo_placement() === $pk) ?>> <span><?= h($pl) ?></span><span class="lp-demo d-<?= h($pk) ?>" aria-hidden="true"><b></b><i></i></span></label>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="logo-reports" data-logo-reports>
    <label class="check logo-all"><input type="checkbox" name="logo_all" value="1"<?= checked($logoAll) ?> data-logo-all> <strong>All reports</strong> <small class="muted">(including the reports added to this list later)</small></label>
    <div class="logo-groups">
    <?php foreach ($logoGroups as $g => $items): ?>
      <fieldset class="logo-group"><legend><?= h($g) ?></legend>
        <?php foreach ($items as $r => $l): ?>
          <label class="check"><input type="checkbox" name="logo_reports[]" value="<?= h($r) ?>"<?= checked($logoAll || in_array($r, $logoList, true)) ?>> <?= h($l) ?></label>
        <?php endforeach; ?>
      </fieldset>
    <?php endforeach; ?>
    </div>
  </div>
  <div class="form-actions"><button class="btn btn-primary">Save logo settings</button>
    <?php if ($logoUri): ?><button class="btn btn-danger-outline" name="_action" value="report_logo_remove" formnovalidate data-confirm="Remove the company logo from every report?">Remove logo</button><?php endif; ?></div>
</form>
<div class="card"><h2>Test email delivery</h2>
  <form method="post" class="filters" style="padding:0"><?= csrf_field() ?><input type="hidden" name="_action" value="test_mail">
    <label>Send to<input type="email" name="test_to" value="<?= h(current_user()['email']) ?>"></label><button class="btn">Send test</button></form>
  <p class="small muted">For Gmail use host smtp.gmail.com, port 587, STARTTLS and an App Password. Links in approval emails use the Application URL in <code>config/config.local.php</code>: <strong><?= h(cfg('app_url')) ?></strong>.</p>
</div>
<?php page_footer();
