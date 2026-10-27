<?php
/**
 * Password Resets (Super Admin). Every account of every company, and the other Super Admin accounts,
 * can be given a temporary password when its user forgot the password. The user must choose a new
 * password right after signing in. The temporary password is shown once here (and optionally e-mailed);
 * the platform and company audit trails record who reset which account and why, never the password.
 * See app/engine/pwreset.php.
 */
$me = super_admin();
$q = trim((string)get('q', ''));
$company = (int)get('company', 0);
$status = in_array(get('status'), ['active', 'inactive', 'pending'], true) ? (string)get('status') : '';
$sel = (string)get('sel', '');
$keep = array_filter(['q' => $q, 'company' => $company ?: null, 'status' => $status ?: null], fn($v) => $v !== null && $v !== '');

if (is_post()) {
    $act = (string)post('_action');
    try {
        if ($act === 'refresh') {
            $n = pwreset_refresh();
            flash('success', 'The account list was refreshed from ' . $n . ' company database(s).');
        } elseif ($act === 'reset') {
            $kind = post('kind') === 'super' ? 'super' : 'user';
            $r = pwreset_apply($kind, (int)post('company_id'), (int)post('id'), [
                'mode' => post('mode') === 'typed' ? 'typed' : 'generate', 'password' => (string)post('password'),
                'email' => (bool)post('send_email'), 'reason' => (string)post('reason'),
            ]);
            // shown once on the next page, then forgotten
            $_SESSION['pwreset_result'] = ['name' => $r['account']['full_name'], 'username' => $r['account']['username'], 'company' => $r['account']['company_name'],
                'email' => $r['account']['email'], 'password' => $r['password'], 'emailed' => $r['emailed'], 'status' => $r['account']['status']];
            $sel = ($kind === 'super' ? 's' : 'u' . (int)post('company_id') . '-') . (int)post('id');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('sa_passwords', $keep + ($sel !== '' ? ['sel' => $sel] : []));
}

$result = $_SESSION['pwreset_result'] ?? null;
unset($_SESSION['pwreset_result']);

$companies = pwreset_companies();
$coNames = [];
foreach ($companies as $c) {
    $coNames[(int)$c['id']] = $c['name'];
}
$list = pwreset_details(pwreset_search($q, $company, $status));

// the selected account: u<company>-<user> or s<super admin>
$acct = null;
$selErr = null;
if (preg_match('/^u(\d+)-(\d+)$/', $sel, $m)) {
    try {
        $acct = pwreset_account('user', (int)$m[1], (int)$m[2]);
    } catch (Throwable $e) {
        $selErr = 'The database of this company cannot be opened right now.';
    }
} elseif (preg_match('/^s(\d+)$/', $sel, $m)) {
    $acct = pwreset_account('super', 0, (int)$m[1]);
}
if ($sel !== '' && !$acct && !$selErr) {
    $selErr = 'This account cannot be reset here. Support accounts and accounts reached by switching company have no password of their own: reset the home account instead.';
}
$isSelf = $acct && $acct['kind'] === 'super' && (int)$acct['id'] === (int)$me['id'];
$recent = pwreset_recent();
$minLen = $acct && $acct['kind'] === 'super' ? PWRESET_MIN_SUPER : PWRESET_MIN_USER;
$statusText = ['active' => 'Active', 'inactive' => 'Inactive', 'pending' => 'Waiting for approval', 'rejected' => 'Rejected'];

page_header('Password Resets', [
    'subtitle' => 'Give an account a temporary password when its user forgot the password. The user must choose a new one right after signing in.',
    'actions' => '<form method="post" class="inline">' . csrf_field() . '<button class="btn" name="_action" value="refresh">Refresh account list</button></form>'
        . '<a class="btn" href="' . h(url('sa_logs', ['action' => 'user_password_reset'])) . '">Reset history</a>',
]);
?>
<?php if ($result): ?>
  <div class="card pwr-result">
    <div class="pwr-result-head">
      <div>
        <h2>Temporary password for <?= h($result['name']) ?></h2>
        <p class="muted small">Username <strong><?= h($result['username']) ?></strong>, <?= h($result['company']) ?></p>
      </div>
    </div>
    <div class="code-box">
      <span class="code-value pwr-value" id="pwrValue"><?= h($result['password']) ?></span>
      <button type="button" class="btn btn-sm" data-copy="pwrValue">Copy</button>
    </div>
    <ul class="pwr-notes">
      <li>Give this password to <?= h($result['name']) ?> privately. It is shown only now and is not kept anywhere.</li>
      <li>At the next sign-in the user goes straight to My Account and must choose a new password, different from this one.</li>
      <?php if ($result['emailed'] === true): ?>
        <li>It was also e-mailed to <?= h($result['email']) ?>.</li>
      <?php elseif ($result['emailed'] === false): ?>
        <li class="state-off">The e-mail to <?= h($result['email']) ?> could not be sent. Give the password to the user yourself.</li>
      <?php endif; ?>
      <?php if ($result['status'] !== 'active'): ?>
        <li class="state-off">The account is <?= h(strtolower($statusText[$result['status']] ?? $result['status'])) ?>: the user cannot sign in until it is activated in User Accounts of the company.</li>
      <?php endif; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="get" class="card filters pwr-filters">
  <input type="hidden" name="p" value="sa_passwords">
  <label class="pwr-q">Find an account<input type="search" name="q" value="<?= h($q) ?>" placeholder="Name, username or e-mail" autocomplete="off"></label>
  <label>Company<select name="company" data-autosubmit>
    <option value="0">All companies and Super Admins</option>
    <?php foreach ($coNames as $cid => $n): ?><option value="<?= (int)$cid ?>"<?= $company === $cid ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?>
    <option value="-1"<?= $company === -1 ? ' selected' : '' ?>>Super Admin accounts only</option>
  </select></label>
  <label>Status<select name="status" data-autosubmit><?= options(['active' => 'Active', 'inactive' => 'Inactive', 'pending' => 'Waiting for approval'], $status ?: null, 'All') ?></select></label>
  <button class="btn btn-primary">Find</button>
</form>

<div class="split">
  <div class="card split-list">
    <div class="list-head"><strong>Accounts</strong><input type="search" data-filter-list="pwrList" placeholder="Filter this list…"></div>
    <div class="list-nav" id="pwrList">
      <?php foreach ($list as $r): ?>
        <a class="<?= $r['key'] === $sel ? 'active' : '' ?>" href="<?= url('sa_passwords', $keep + ['sel' => $r['key']]) ?>">
          <span class="ln-dot <?= $r['status'] === 'active' ? 'on' : '' ?>"></span>
          <span class="ln-main"><strong><?= h($r['full_name']) ?></strong>
            <small><?= h($r['username']) ?>, <?= h($r['company_name']) ?><?= !empty($r['role_name']) ? ', ' . h($r['role_name']) : '' ?></small>
            <?php if (!empty($r['must_change_password'])): ?><small class="pwr-flag">Temporary password not changed yet</small><?php endif; ?>
            <?php if ($r['status'] !== 'active'): ?><small class="state-off"><?= h($statusText[$r['status']] ?? $r['status']) ?></small><?php endif; ?>
          </span>
        </a>
      <?php endforeach; ?>
      <?php if (!$list): ?><div class="empty-state small">No account matches.<?= $q !== '' ? ' Try part of the name, or the e-mail address.' : '' ?></div><?php endif; ?>
    </div>
    <div class="list-count"><?= count($list) ?> account(s)<?= count($list) >= 200 ? ', narrow the search to see more' : '' ?></div>
  </div>

  <div class="pwr-main">
    <?php if ($selErr): ?>
      <div class="card"><div class="callout warn"><?= h($selErr) ?></div></div>
    <?php elseif (!$acct): ?>
      <div class="card"><div class="empty-state">
        <h3>Choose an account</h3>
        <p>Find the user by name, username or e-mail, pick the account on the left, then reset its password here.</p>
      </div></div>
    <?php else: ?>
      <div class="card pwr-account">
        <div class="card-head">
          <div class="person">
            <span class="avatar"><?= h(mb_strtoupper(mb_substr((string)$acct['full_name'], 0, 1))) ?></span>
            <div><div class="person-name"><?= h($acct['full_name']) ?></div>
              <div class="person-meta"><?= h($acct['username']) ?>, <?= h($acct['company_name']) ?><?= !empty($acct['is_owner']) ? ', Company Owner' : '' ?></div></div>
          </div>
          <?php if ($acct['kind'] === 'user'): ?><a class="btn btn-sm" href="<?= h(url('sa_company', ['id' => $acct['company_id']])) ?>">Open the company</a><?php endif; ?>
        </div>
        <dl class="kv pwr-kv">
          <dt>Role</dt><dd><?= h($acct['role_name'] ?: '—') ?></dd>
          <?php if ($acct['employee_no']): ?><dt>Employee no.</dt><dd><?= h($acct['employee_no']) ?></dd><?php endif; ?>
          <dt>E-mail</dt><dd><?= $acct['email'] ? h($acct['email']) : '<span class="muted">none</span>' ?></dd>
          <dt>Last sign-in</dt><dd><?= $acct['last_login'] ? h(fmt_dt($acct['last_login'])) : '<span class="muted">never</span>' ?></dd>
          <dt>Password</dt><dd><?= $acct['must_change_password'] ? '<span class="pwr-flag">Temporary, not changed yet</span>' : 'Set by the user' ?></dd>
          <dt>Account</dt><dd class="<?= $acct['status'] === 'active' ? '' : 'state-off' ?>"><?= h($statusText[$acct['status']] ?? $acct['status']) ?></dd>
        </dl>
      </div>

      <?php if ($isSelf): ?>
        <div class="card"><div class="callout">This is your own account. Change your password on <a href="<?= url('sa_account') ?>">My Account</a>.</div></div>
      <?php else: ?>
        <form method="post" class="card pwr-form" data-confirm="Reset the password of <?= h($acct['full_name']) ?>? The current password stops working right away.">
          <?= csrf_field() ?>
          <input type="hidden" name="_action" value="reset">
          <input type="hidden" name="kind" value="<?= h($acct['kind']) ?>">
          <input type="hidden" name="company_id" value="<?= (int)$acct['company_id'] ?>">
          <input type="hidden" name="id" value="<?= (int)$acct['id'] ?>">
          <h2>Reset the password</h2>
          <p class="muted small">The current password stops working at once. The account gets the temporary password below and must replace it at the next sign-in.</p>

          <div class="pwr-modes">
            <label class="access-mode"><input type="radio" name="mode" value="generate" checked data-pwr-mode>
              <span><strong>Generate a temporary password</strong><small>Twelve random letters and digits, shown once after the reset.</small></span></label>
            <label class="access-mode"><input type="radio" name="mode" value="typed" data-pwr-mode>
              <span><strong>Type the temporary password</strong><small>At least <?= $minLen ?> characters, for example one you agreed with the user by phone.</small></span></label>
          </div>
          <div class="field pwr-typed" data-pwr-typed hidden>
            <label for="pwrTyped">Temporary password</label>
            <input id="pwrTyped" name="password" class="mono" minlength="<?= $minLen ?>" maxlength="100" autocomplete="off" spellcheck="false">
          </div>

          <div class="field">
            <label for="pwrReason">Reason <span class="muted">(optional, kept in the audit trail)</span></label>
            <input id="pwrReason" name="reason" maxlength="200" placeholder="Forgot the password">
          </div>

          <?php if ($acct['kind'] === 'user'): ?>
            <?php if ($acct['email'] && $acct['mail_ready']): ?>
              <label class="check"><input type="checkbox" name="send_email" value="1"> Also e-mail the temporary password to <?= h($acct['email']) ?></label>
            <?php elseif ($acct['email']): ?>
              <p class="muted small">E-mail is not set up for <?= h($acct['company_name']) ?> (System Settings, Mail), so give the password to the user yourself.</p>
            <?php else: ?>
              <p class="muted small">The account has no e-mail address, so give the password to the user yourself.</p>
            <?php endif; ?>
          <?php endif; ?>

          <?php if ($acct['status'] !== 'active'): ?>
            <div class="callout warn">This account is <?= h(strtolower($statusText[$acct['status']] ?? $acct['status'])) ?>. The reset works, but the user cannot sign in until the account is activated in User Accounts of the company.</div>
          <?php endif; ?>

          <div class="form-actions"><button class="btn btn-primary">Reset password</button>
            <a class="btn" href="<?= url('sa_passwords', $keep) ?>">Cancel</a></div>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div class="card flush" style="margin-top:16px">
  <div class="card-head pad"><div><h2>Recent resets</h2><div class="sub">From the platform audit. Passwords are never recorded.</div></div></div>
  <div class="table-wrap"><table class="table table-compact pwr-recent">
    <thead><tr><th>When</th><th>Account</th><th>Company</th><th>Reset by</th><th>Reason</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $a): $d = json_decode((string)$a['details'], true) ?: []; ?>
      <tr><td class="small nowrap" data-label="When"><?= h(fmt_dt($a['created_at'])) ?></td><td data-label="Account"><strong><?= h($d['username'] ?? '') ?></strong><?= !empty($d['emailed']) ? '<br><small class="muted">e-mailed</small>' : '' ?></td>
        <td class="small" data-label="Company"><?= $a['action'] === 'super_admin_password_reset' ? 'Platform (Super Admin)' : h($coNames[(int)$a['company_id']] ?? ('Company ' . (int)$a['company_id'])) ?></td>
        <td class="small" data-label="Reset by"><?= h($a['actor_name']) ?></td><td class="small" data-label="Reason"><?= h((string)($d['reason'] ?? '')) ?: '<span class="muted">—</span>' ?></td></tr>
    <?php endforeach; ?>
    <?= $recent ? '' : empty_row(5, 'No password was reset yet.') ?>
    </tbody>
  </table></div>
</div>
<?php page_footer();
