<?php
/**
 * Payroll Parameters (M.A.D.A.S. Payroll Parameters Configuration):
 *   Comp. Rates, Processing Config, 13th Month (v17), Annual / Acct. Config, Payslip Param.
 * v17: the 13th Month tab shows every parameter of the 13th month computation, with its basis: Calendar Year
 * (January to December) or Fiscal Year (From / To dates of exactly one year).
 */
$rateRows = rows('SELECT * FROM pay_rates ORDER BY sort_order, id');
$groupsCfg = [
    'rates'   => ['Comp. Rates', ['payroll'], ['hours_per_day', 'default_days_per_year', 'default_conversion_factor', 'nd_rate_pct', 'holiday_pay_daily_rated']],
    'process' => ['Processing Config', ['payroll_params'], null],
    'annual'  => ['Annual / Acct. Config', ['payroll_annual'], null],
    'payslip' => ['Payslip Param.', ['payslip'], null],
];
$settingRows = rows("SELECT * FROM settings WHERE grp IN ('payroll','payroll_params','payroll_annual','payslip') AND label IS NOT NULL ORDER BY grp, sort_order");
$extraPayroll = ['nontax_benefit_ceiling', 'min_net_pay_pct', 'accountability_max_pct', 'pagibig_voluntary_allowed', 'de_minimis_leave_days', 'final_pay_days', 'payslip_note'];
$thKeys = ['thirteenth_basis', 'thirteenth_fy_from', 'thirteenth_fy_to', 'thirteenth_include_leave', 'thirteenth_deadline'];

if (is_post()) {
    verify_csrf();
    try {
        if (post('_action') === 'thirteenth') {
            // v17: 13th month parameters
            $basis = post('thirteenth_basis') === 'fiscal' ? 'fiscal' : 'calendar';
            $from = (string)post('thirteenth_fy_from', '');
            $to = (string)post('thirteenth_fy_to', '');
            if ($basis === 'fiscal' && ($err = thirteenth_fy_valid($from, $to)) !== null) {
                throw new RuntimeException($err);
            }
            $dl = trim((string)post('thirteenth_deadline', '12-24'));
            if (!preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $dl)) {
                throw new RuntimeException('Enter the deadline as MM-DD, e.g. 12-24.');
            }
            $new = ['thirteenth_basis' => $basis, 'thirteenth_include_leave' => post('thirteenth_include_leave') ? '1' : '0', 'thirteenth_deadline' => $dl];
            if ($from !== '' || $to !== '') {
                $new['thirteenth_fy_from'] = $from;
                $new['thirteenth_fy_to'] = $to;
            }
            $changes = [];
            foreach ($new as $k => $v) {
                if ((string)setting($k, '') !== (string)$v) {
                    $changes[$k] = ['from' => setting($k, ''), 'to' => $v];
                    set_setting($k, $v);
                }
            }
            if ($changes) {
                audit('payroll', 'thirteenth_params', 'settings', null, $changes);
            }
            flash('success', count($changes) . ' 13th month parameter(s) saved. ' . thirteenth_period((int)date('Y'))[3] . ' is used for ' . date('Y') . '.');
            header('Location: ' . url('payroll_params') . '#tab-13th');
            exit;
        } elseif (post('_action') === 'rates') {
            $n = 0;
            foreach ($rateRows as $r) {
                $day = post('day_' . $r['id']);
                $night = post('night_' . $r['id']);
                if ($day === null) {
                    continue;
                }
                $d = round((float)$day, 3);
                $ni = round((float)$night, 3);
                if (abs($d - (float)$r['day_pct']) > 0.0001 || abs($ni - (float)$r['night_pct']) > 0.0001) {
                    update('pay_rates', ['day_pct' => $d, 'night_pct' => $ni], 'id = ?', [$r['id']]);
                    $n++;
                }
            }
            if ($n) {
                audit('payroll', 'comp_rates_update', 'pay_rates', null, ['changed' => $n]);
                pay_rates(true);
            }
            flash('success', $n . ' comp. rate(s) updated. New payroll runs use the new percentages.');
        } else {
            $n = settings_save($settingRows, 'payroll');
            flash('success', $n . ' parameter(s) saved.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('payroll_params');
}

$byGrp = function (array $grps, ?array $only = null) use ($settingRows) {
    return array_values(array_filter($settingRows, fn($s) => in_array($s['grp'], $grps, true) && ($only === null || in_array($s['skey'], $only, true))));
};
page_header('Payroll Parameters', [
    'subtitle' => 'Percentages, processing rules, accounting codes and payslip options used when payroll is computed.',
    'actions' => '<a class="btn" href="' . url('payroll_setup') . '">All setup tables</a><a class="btn" href="' . url('settings') . '">System settings</a>',
]);
?>
<div class="tabs">
  <button class="tab" data-tab="tab-rates">Comp. Rates</button>
  <button class="tab" data-tab="tab-process">Processing Config</button>
  <button class="tab" data-tab="tab-13th">13th Month</button>
  <button class="tab" data-tab="tab-annual">Annual / Acct. Config</button>
  <button class="tab" data-tab="tab-payslip">Payslip Param.</button>
</div>

<div class="tab-panel" id="tab-rates">
  <div class="card">
    <div class="card-head"><div><h2>Compensation rates</h2>
      <div class="sub">Percent of the hourly rate paid for each kind of hour. Night premium is the additional percent for hours inside the night-shift window.</div></div></div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="_action" value="rates">
      <div class="table-wrap">
        <table class="table table-compact">
          <thead><tr><th>Category</th><th>Code</th><th class="num" style="width:140px">Day prem. %</th><th class="num" style="width:140px">Night prem. %</th><th>Applies to</th></tr></thead>
          <tbody>
          <?php $lastGroup = null;
          foreach ($rateRows as $r):
              if ($r['group_name'] !== $lastGroup) {
                  $lastGroup = $r['group_name'];
                  echo '<tr class="row-sub"><td colspan="5">' . h($lastGroup) . '</td></tr>';
              }
              $fields = array_keys(array_filter(PT_RATE_OF, fn($c) => $c === $r['code']));
              ?>
            <tr>
              <td><strong><?= h($r['label']) ?></strong></td>
              <td><code><?= h($r['code']) ?></code></td>
              <td class="num"><input type="number" step="0.001" min="0" name="day_<?= (int)$r['id'] ?>" value="<?= h(rtrim(rtrim(number_format((float)$r['day_pct'], 3, '.', ''), '0'), '.')) ?>" style="text-align:right"></td>
              <td class="num"><input type="number" step="0.001" min="0" name="night_<?= (int)$r['id'] ?>" value="<?= h(rtrim(rtrim(number_format((float)$r['night_pct'], 3, '.', ''), '0'), '.')) ?>" style="text-align:right"></td>
              <td class="small muted"><?= h(implode(', ', array_map(fn($f) => PT_FIELDS[$f][0], $fields))) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="form-actions"><button class="btn btn-primary">Save comp. rates</button>
        <span class="muted small">Philippine Labor Code minimums: OT 125%, rest day 130%, legal holiday 200%, special day 130%, night differential +10%.</span></div>
    </form>
  </div>
  <div class="card">
    <h2>Conversion factor &amp; basic rates</h2>
    <form method="post"><?= csrf_field() ?>
      <?= settings_fields($byGrp(['payroll'], $groupsCfg['rates'][2])) ?>
      <div class="form-actions"><button class="btn btn-primary">Save</button>
        <a class="btn" href="<?= url('emp_types') ?>">Employee Type Table (per-type factor)</a></div>
    </form>
    <p class="help">Daily rate = monthly rate ÷ conversion factor of the employee type (or the default above). Hourly rate = daily rate ÷ hours per day.</p>
  </div>
</div>

<div class="tab-panel" id="tab-process">
  <div class="card">
    <h2>Processing configuration</h2>
    <form method="post"><?= csrf_field() ?>
      <?= settings_fields($byGrp(['payroll_params'])) ?>
      <div class="form-actions"><button class="btn btn-primary">Save processing config</button></div>
    </form>
  </div>
  <div class="card">
    <h2>Wage-protection limits</h2>
    <form method="post"><?= csrf_field() ?>
      <?= settings_fields($byGrp(['payroll'], $extraPayroll)) ?>
      <div class="form-actions"><button class="btn btn-primary">Save limits</button></div>
    </form>
  </div>
</div>

<?php
$thY = (int)date('Y');
[$thFrom, $thTo, $thBasis, $thLabel] = thirteenth_period($thY);
$fyFrom = (string)setting('thirteenth_fy_from', '') ?: ($thY - 1) . '-12-01';
$fyTo = (string)setting('thirteenth_fy_to', '') ?: $thY . '-11-30';
$thBasisNow = (string)setting('thirteenth_basis', 'calendar');
$withLeave = setting('thirteenth_include_leave', '1') === '1';
$ceiling = setting_num('nontax_benefit_ceiling', 90000);
?>
<div class="tab-panel" id="tab-13th">
  <div class="grid grid-2-1">
  <div class="card">
    <div class="card-head"><div><h2>13th month pay parameters</h2><div class="sub">Presidential Decree 851: one twelfth of the basic salary earned in the year.</div></div></div>
    <form method="post" id="thForm"><?= csrf_field() ?><input type="hidden" name="_action" value="thirteenth">
      <fieldset class="th-basis"><legend>Basis of the annual computation</legend>
        <?php foreach (THIRTEENTH_BASES as $k => $l): ?>
          <label class="th-basis-opt"><input type="radio" name="thirteenth_basis" value="<?= h($k) ?>"<?= checked($thBasisNow === $k) ?>>
            <span><strong><?= $k === 'calendar' ? 'a.) Calendar Year' : 'b.) Fiscal Year' ?></strong><small><?= $k === 'calendar' ? 'January to December of the current year.' : 'Flexible dates from the previous year to the current year, exactly one year (365 days, 366 with February 29). Below one year is not accepted.' ?></small></span></label>
        <?php endforeach; ?>
      </fieldset>
      <div class="form-grid th-fy">
        <div class="field"><label>Fiscal year: From date</label><input type="date" name="thirteenth_fy_from" id="thFrom" value="<?= h($fyFrom) ?>"></div>
        <div class="field"><label>To date</label><input type="date" name="thirteenth_fy_to" id="thTo" value="<?= h($fyTo) ?>">
          <small class="help" id="thCheck" aria-live="polite"></small></div>
      </div>
      <p class="small muted th-fy-note">Fiscal Year: the system totals the basic salary earned by the employee from the From date to the To date and divides it by 12 for the 13th month payable. Each year the same dates move one year.</p>
      <div class="form-grid">
        <div class="field"><label class="check"><input type="checkbox" name="thirteenth_include_leave" value="1"<?= checked($withLeave) ?>> Basic salary includes the paid leaves</label>
          <small class="help">Unticked: the pay of the paid leaves is taken out of the basic salary earned.</small></div>
        <div class="field"><label>Deadline of payment (MM-DD)</label><input name="thirteenth_deadline" value="<?= h((string)setting('thirteenth_deadline', '12-24')) ?>" maxlength="5" pattern="\d{2}-\d{2}">
          <small class="help">PD 851: on or before December 24. A 13th month run paid later is flagged.</small></div>
      </div>
      <div class="form-actions"><button class="btn btn-primary">Save 13th month parameters</button><a class="btn" href="<?= url('rpt_13th') ?>">13th Month Report</a></div>
    </form>
  </div>
  <div class="card">
    <h2>Computation in use</h2>
    <dl class="th-params">
      <div><dt>Coverage <?= $thY ?></dt><dd><?= h($thLabel) ?><br><span class="muted small"><?= h(fmt_date($thFrom)) ?> to <?= h(fmt_date($thTo)) ?>, <?= days_between($thFrom, $thTo) + 1 ?> days</span></dd></div>
      <div><dt>Formula</dt><dd>Basic salary earned in the coverage ÷ 12, less the 13th month already paid for it</dd></div>
      <div><dt>Basic salary earned</dt><dd>Basic pay less absences, lates and undertime of every finalized payroll whose period ends in the coverage<?= $withLeave ? ', paid leaves included' : ', paid leaves excluded' ?></dd></div>
      <div><dt>Not part of the basis</dt><dd>Overtime, holiday and rest day premiums, night differential, allowances, benefits, commissions and other earnings</dd></div>
      <div><dt>Already paid</dt><dd>The 13TH lines of the finalized runs of the year (January to December, or to the end of the coverage)</dd></div>
      <div><dt>Where it is paid</dt><dd>13th Month Pay runs, the 13th Month Process option of a regular run, and the final pay (pro-rated to the separation)</dd></div>
      <div><dt>Accrual</dt><dd>Each finalized regular payroll accrues basic earned ÷ 12 in the employee's 13th Month Pay ledger</dd></div>
      <div><dt>Tax</dt><dd>Non-taxable up to <?= money($ceiling) ?> a year together with the other benefits (Processing Config, Wage-protection limits); the excess is taxable</dd></div>
      <div><dt>Deadline</dt><dd><?= h(fmt_date($thY . '-' . setting('thirteenth_deadline', '12-24'))) ?></dd></div>
    </dl>
  </div>
  </div>
</div>
<script>
(function () {
  var f = document.getElementById('thFrom'), t = document.getElementById('thTo'), out = document.getElementById('thCheck'), form = document.getElementById('thForm');
  if (!f || !t || !form) return;
  var basis = function () { var r = form.querySelector('[name=thirteenth_basis]:checked'); return r ? r.value : 'calendar'; };
  var expect = function () {
    if (!f.value) return '';
    var d = new Date(f.value + 'T00:00:00'); d.setFullYear(d.getFullYear() + 1); d.setDate(d.getDate() - 1);
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  };
  var check = function () {
    var fy = basis() === 'fiscal';
    form.classList.toggle('is-fiscal', fy);
    f.required = t.required = fy;
    if (!fy || !f.value || !t.value) { out.textContent = ''; out.className = 'help'; t.setCustomValidity(''); return; }
    var e = expect(), days = Math.round((new Date(t.value) - new Date(f.value)) / 86400000) + 1;
    var ok = t.value === e;
    out.textContent = ok ? 'Exactly one year: ' + days + ' days.' : (t.value < e ? 'Below one year (' + days + ' days) is not accepted.' : 'More than one year (' + days + ' days).') + ' From this date the To date is ' + e + '.';
    out.className = 'help ' + (ok ? 'text-green' : 'text-red');
    t.setCustomValidity(ok ? '' : out.textContent);
  };
  f.addEventListener('change', function () { if (f.value) { t.value = expect(); } check(); });
  t.addEventListener('input', check);
  form.addEventListener('change', check);
  check();
})();
</script>

<div class="tab-panel" id="tab-annual">
  <div class="card">
    <h2>Annual &amp; accounting configuration</h2>
    <form method="post"><?= csrf_field() ?>
      <?= settings_fields(array_values(array_filter($byGrp(['payroll_annual']), fn($s) => !in_array($s['skey'], $thKeys, true)))) ?>
      <div class="form-actions"><button class="btn btn-primary">Save</button>
        <a class="btn" href="<?= url('gl_accounts') ?>">Payroll Code Table (GL accounts)</a>
        <a class="btn" href="<?= url('rpt_journal') ?>">Payroll journal</a></div>
    </form>
  </div>
</div>

<div class="tab-panel" id="tab-payslip">
  <div class="card">
    <h2>Payslip parameters</h2>
    <form method="post"><?= csrf_field() ?>
      <?= settings_fields($byGrp(['payslip'])) ?>
      <div class="form-actions"><button class="btn btn-primary">Save payslip parameters</button>
        <a class="btn" href="<?= url('payslips_print') ?>">Print payslips</a>
        <a class="btn" href="<?= url('signatories') ?>">Signatory</a></div>
    </form>
  </div>
</div>
<?php page_footer();
