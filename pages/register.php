<?php
/**
 * Join a company with its Company Code (multi-company).
 * The code only tells which company the new account belongs to; it is not a password. The request is
 * created in that company's own database as a pending account and waits for the Company Owner
 * (Company > Join Requests). The account can sign in once approved, without the code.
 */
if (current_user()) {
    redirect('dashboard');
}
if (super_admin()) {
    redirect('sa_dashboard');
}
$multi = master_db() !== null;
$error = null;
$done = null;
$f = ['full_name' => '', 'email' => '', 'username' => '', 'company_code' => ''];

if ($multi && is_post()) {
    foreach ($f as $k => $v) {
        $f[$k] = trim((string)post($k, ''));
    }
    $f['username'] = strtolower($f['username']);
    $f['company_code'] = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $f['company_code']));
    $pw = (string)post('password', '');
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $since = date('Y-m-d H:i:s', time() - 900);
    $fails = (int)mval('SELECT COUNT(*) FROM join_attempts WHERE ip_address = ? AND success = 0 AND created_at >= ?', [$ip, $since]);
    $okHour = (int)mval('SELECT COUNT(*) FROM join_attempts WHERE ip_address = ? AND success = 1 AND created_at >= ?', [$ip, date('Y-m-d H:i:s', time() - 3600)]);
    try {
        if ($fails >= 10) {
            throw new RuntimeException('Too many attempts from this connection. Please wait 15 minutes and try again.');
        }
        if ($okHour >= 5) {
            throw new RuntimeException('Too many registrations from this connection. Please try again later.');
        }
        if ($f['full_name'] === '' || $f['email'] === '' || $f['username'] === '' || $pw === '' || $f['company_code'] === '') {
            throw new RuntimeException('Please fill in every field.');
        }
        if (mb_strlen($f['full_name']) > 150) {
            throw new RuntimeException('The full name is too long.');
        }
        if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL) || strlen($f['email']) > 150) {
            throw new RuntimeException('Please enter a valid e-mail address.');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,59}$/', $f['username'])) {
            throw new RuntimeException('The username must be 3 to 60 characters: letters, digits, dot, dash or underscore.');
        }
        if (strlen($pw) < 8) {
            throw new RuntimeException('The password must be at least 8 characters.');
        }
        if ($pw !== (string)post('password_confirm', '')) {
            throw new RuntimeException('The passwords do not match.');
        }
        $co = company_by_code($f['company_code']);
        if (!$co) {
            minsert('join_attempts', ['ip_address' => $ip, 'success' => 0, 'created_at' => now()]);
            throw new RuntimeException('Invalid or inactive Company Code. Please verify the code with your Company Owner.');
        }
        if (mc_username_taken($f['username'])) {
            throw new RuntimeException('That username is already taken. Please choose another one.');
        }
        if (mc_email_taken($f['email'])) {
            throw new RuntimeException('An account with this e-mail address already exists. Sign in instead, or ask your Company Owner for help.');
        }
        $uid = with_company_db($co['db_name'], function () use ($f, $pw, $co) {
            db_upgrade();
            if ((int)val('SELECT COUNT(*) FROM users WHERE username = ?', [$f['username']])) {
                throw new RuntimeException('That username is already taken. Please choose another one.');
            }
            $id = insert('users', ['username' => $f['username'], 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'full_name' => $f['full_name'],
                'email' => $f['email'], 'role' => 'employee', 'is_active' => 0, 'must_change_password' => 0,
                'reg_status' => 'pending', 'registered_at' => now()]);
            audit('auth', 'join_request', 'users', $id, ['username' => $f['username'], 'email' => $f['email']]);
            // let the Company Owner know (Email Outbox of that company, or sent, per its mail settings)
            $owner = $co['owner_user_id'] ? row('SELECT full_name, email FROM users WHERE id = ?', [(int)$co['owner_user_id']]) : null;
            if ($owner && $owner['email']) {
                send_mail($owner['email'], $owner['full_name'], 'Join request: ' . $f['full_name'], email_template('New join request',
                    '<p>' . h($f['full_name']) . ' (' . h($f['email']) . ') asked to join ' . h($co['name']) . ' with the Company Code.</p>'
                    . '<p>' . email_button(abs_url('join_requests'), 'Review join requests') . '</p>'), 'join_request', $id);
            }
            return $id;
        });
        $u = with_company_db($co['db_name'], fn() => row('SELECT * FROM users WHERE id = ?', [$uid]));
        if (!mc_sync_user_row(master_db(), (int)$co['id'], $u)) {
            with_company_db($co['db_name'], fn() => q('DELETE FROM users WHERE id = ?', [$uid]));
            throw new RuntimeException('That username is already taken. Please choose another one.');
        }
        minsert('join_attempts', ['ip_address' => $ip, 'success' => 1, 'created_at' => now()]);
        master_audit('join_request', (int)$co['id'], ['username' => $f['username']]);
        $done = ['company' => $co['name'], 'username' => $f['username']];
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

page_header('Join your company');
?>
<div class="auth">
  <div class="auth-brand">
    <div class="brand" style="padding:0">
      <div class="brand-mark"><?= h(app_mark()) ?></div>
      <div><div class="brand-name"><?= h(app_name()) ?></div><div class="brand-sub">Integrated HR, Payroll &amp; Attendance</div></div>
    </div>
    <h2>Join your company.</h2>
    <p>Your employer gives you a Company Code. Enter it once, with the account you want to use. The Company Owner approves the request, then you sign in with your username and password only.</p>
    <ul class="auth-feat">
      <li><?= icon('shield') ?><span><strong>The code is not a password</strong> — it only tells which company you belong to.</span></li>
      <li><?= icon('check') ?><span><strong>Approval</strong> — your account opens once the Company Owner approves it.</span></li>
      <li><?= icon('user') ?><span><strong>Your records</strong> — you only ever see your own company's information.</span></li>
    </ul>
  </div>
  <div class="auth-form">
    <?php if (!$multi): ?>
      <h1>Registration</h1>
      <p class="muted">Joining with a Company Code is not available on this installation. Ask your administrator for an account.</p>
      <p><a class="btn" href="<?= url('login') ?>">Back to sign in</a></p>
    <?php elseif ($done): ?>
      <h1>Request sent</h1>
      <div class="alert alert-success"><span>Your request to join <strong><?= h($done['company']) ?></strong> was sent.</span></div>
      <p class="muted">You can sign in as <strong><?= h($done['username']) ?></strong> once the Company Owner approves it. You will not need the Company Code again.</p>
      <p><a class="btn btn-primary btn-block" href="<?= url('login') ?>">Back to sign in</a></p>
    <?php else: ?>
      <h1>Join your company</h1>
      <p class="muted">All fields are required.</p>
      <?php if ($error): ?><div class="alert alert-error"><span><?= h($error) ?></span></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div class="field"><label for="cc">Company Code</label><input id="cc" name="company_code" required maxlength="16" value="<?= h($f['company_code']) ?>" class="code-input" autocapitalize="characters" spellcheck="false"></div>
        <div class="field"><label for="fn">Full name</label><input id="fn" name="full_name" required maxlength="150" value="<?= h($f['full_name']) ?>" autocomplete="name"></div>
        <div class="field"><label for="em">E-mail</label><input id="em" type="email" name="email" required maxlength="150" value="<?= h($f['email']) ?>" autocomplete="email"></div>
        <div class="field"><label for="un">Username</label><input id="un" name="username" required maxlength="60" pattern="[A-Za-z0-9][A-Za-z0-9._\-]{2,59}" value="<?= h($f['username']) ?>" autocomplete="username"></div>
        <div class="form-grid cols-2 tight">
          <div class="field"><label for="pw">Password</label><input id="pw" type="password" name="password" required minlength="8" autocomplete="new-password"></div>
          <div class="field"><label for="pw2">Confirm password</label><input id="pw2" type="password" name="password_confirm" required minlength="8" autocomplete="new-password"></div>
        </div>
        <button class="btn btn-primary btn-block btn-lg">Send join request</button>
      </form>
      <p class="auth-alt">Already have an account? <a href="<?= url('login') ?>">Sign in</a></p>
    <?php endif; ?>
  </div>
</div>
<?php page_footer();
