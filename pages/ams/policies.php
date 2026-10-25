<?php
/**
 * AMS Policies & Configurations — the rules used to interpret the daily logs
 * (Calculation tab) and the limits and lookups used while processing them (Configuration tab).
 */
$policy = rows("SELECT * FROM settings WHERE grp = 'ams_policy' AND label IS NOT NULL ORDER BY sort_order");
$config = rows("SELECT * FROM settings WHERE grp = 'ams_config' AND label IS NOT NULL ORDER BY sort_order");
$att = rows("SELECT * FROM settings WHERE grp = 'attendance' AND label IS NOT NULL ORDER BY sort_order");
$attTime = array_values(array_filter($att, fn($s) => in_array($s['skey'], ['grace_minutes', 'late_grace_mode', 'late_deduction_mode', 'ot_min_minutes', 'ot_requires_approval', 'auto_break_deduct'], true)));
$attNight = array_values(array_filter($att, fn($s) => in_array($s['skey'], ['nd_start', 'nd_end'], true)));
$used = array_merge(array_column($attTime, 'skey'), array_column($attNight, 'skey'));
$attOther = array_values(array_filter($att, fn($s) => !in_array($s['skey'], $used, true)));

if (is_post()) {
    verify_csrf();
    $n = settings_save(array_merge($policy, $config, $att), 'ams');
    att_cfg(true);
    flash('success', $n . ' policy setting(s) saved. Re-process the attendance of open periods so the new rules apply.');
    redirect('ams_policies');
}
page_header('AMS Policies & Configurations', [
    'subtitle' => 'How the raw logs become duty hours, lates, undertime, absences and premiums. Changing a rule affects the next processing of open periods.',
    'actions' => '<a class="btn" href="' . url('shifts') . '">Shift Table</a><a class="btn" href="' . url('late_brackets') . '">Lates / Undertime Table</a>'
        . '<a class="btn" href="' . url('att_generate') . '">Re-process attendance</a>',
]);
?>
<div class="tabs">
  <button class="tab" data-tab="tab-calc">Calculation</button>
  <button class="tab" data-tab="tab-conf">Configuration</button>
  <button class="tab" data-tab="tab-web">Web check-in &amp; discipline</button>
</div>
<form method="post">
  <?= csrf_field() ?>
  <div class="tab-panel" id="tab-calc">
    <div class="card">
      <h2>Log interpretation</h2>
      <p class="muted small">Duty hours, lates and undertime are read from the daily logs following these rules.</p>
      <?= settings_fields(array_values(array_filter($policy, fn($s) => in_array($s['skey'], ['ams_duty_mode', 'ams_late_mode', 'ams_break_excess_late'], true))), 'form-grid cols-2') ?>
    </div>
    <div class="card">
      <h2>ABSENT policy</h2>
      <?= settings_fields(array_values(array_filter($policy, fn($s) => in_array($s['skey'], ['ams_short_absent', 'ams_nobreak_absent'], true))), 'form-grid cols-2') ?>
      <?= settings_fields(array_values(array_filter($config, fn($s) => in_array($s['skey'], ['ams_abs_week_total', 'ams_abs_period_total', 'ams_holiday_absent_prior'], true))), 'form-grid cols-2') ?>
    </div>
    <div class="card">
      <h2>TARDINESS &amp; undertime policy</h2>
      <?= settings_fields(array_values(array_filter($policy, fn($s) => in_array($s['skey'], ['ams_nolate_holiday', 'ams_nolate_restday', 'ams_nolate_leave', 'ams_short_ut', 'ams_nobreak_ut'], true))), 'form-grid cols-2') ?>
      <?= settings_fields(array_values(array_filter($config, fn($s) => in_array($s['skey'], ['ams_tardy_week_total', 'ams_tardy_period_total', 'ams_late_lookup'], true))), 'form-grid cols-2') ?>
    </div>
    <div class="card">
      <h2>Time computation</h2>
      <?= settings_fields($attTime, 'form-grid cols-3') ?>
    </div>
  </div>

  <div class="tab-panel" id="tab-conf">
    <div class="card">
      <h2>LOGS policy</h2>
      <?= settings_fields(array_values(array_filter($config, fn($s) => in_array($s['skey'], ['ams_incomplete_absent', 'ams_incomplete_ut', 'ams_incomplete_penalty_on', 'ams_incomplete_penalty',
          'ams_early_break_penalty_on', 'ams_early_break_penalty', 'ams_six_logs'], true))), 'form-grid cols-2') ?>
    </div>
    <div class="card">
      <h2>Validation &amp; night premium</h2>
      <?= settings_fields($attNight, 'form-grid cols-2') ?>
      <?= settings_fields(array_values(array_filter($config, fn($s) => in_array($s['skey'], ['ams_np_from_logs', 'ams_invalid_hours', 'ams_dup_minutes'], true))), 'form-grid cols-2') ?>
      <p class="help">A day with more than the configured number of hours between the first and the last log is flagged as invalid. A log that follows another log of the same type inside the configured number of minutes is invalid and not counted (a double tap on the terminal); logs of different types, such as a break in after a 15-minute break, always count. The placement of the logs (Time in, Break out, Break in, Time out) follows the Logs per day of the employee's shift code, or six logs for everyone when the six-log policy above is on.</p>
    </div>
  </div>

  <div class="tab-panel" id="tab-web">
    <div class="card">
      <h2>Web / mobile check-in and discipline</h2>
      <?= settings_fields($attOther, 'form-grid cols-3') ?>
    </div>
  </div>

  <div class="toolbar sticky-bottom">
    <button class="btn btn-primary">Save policies</button>
    <span class="muted small">Saved rules apply the next time the daily logs are processed (Attendance Entry › ReCalc logs, or Auto-Generate).</span>
  </div>
</form>
<?php page_footer();
