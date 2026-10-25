<?php
$u = current_user();
if (is_post()) {
    $cur = (string)post('current_password');
    $new = (string)post('new_password');
    $conf = (string)post('confirm_password');
    $email = post('email');
    $row = row('SELECT * FROM users WHERE id = ?', [$u['id']]);
    if (!empty($u['linked_from'])) {
        $new = '';   // a linked account has no password: the user signs in with the home account
    }
    if ($new !== '' || ($u['must_change_password'] && empty($u['linked_from']))) {
        if (!password_verify($cur, $row['password_hash'])) {
            flash('error', 'Your current password is incorrect.');
        } elseif (strlen($new) < 8) {
            flash('error', 'The new password must be at least 8 characters.');
        } elseif ($new !== $conf) {
            flash('error', 'The new passwords do not match.');
        } else {
            update('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT), 'must_change_password' => 0], 'id = ?', [$u['id']]);
            audit('auth', 'password_change', 'users', $u['id']);
            flash('success', 'Password updated.');
        }
    }
    if ($email !== null && $email !== (string)$row['email']) {
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please enter a valid email address.');
        } else {
            update('users', ['email' => $email ?: null], 'id = ?', [$u['id']]);
            mc_sync_user((int)$u['id']);
            flash('success', 'Email address updated. Leave approvals and notices will be sent here.');
        }
    }
    redirect('account');
}
page_header('My Account');
?>
<div class="grid grid-2">
  <div class="card">
    <h2>Account</h2>
    <dl class="kv">
      <dt>Name</dt><dd><?= h($u['full_name']) ?></dd>
      <dt>Username</dt><dd><?= h($u['username']) ?></dd>
      <dt>Role</dt><dd><?= h(user_role_name($u)) ?></dd>
      <dt>Employee No.</dt><dd><?= h($u['employee_no'] ?: '—') ?></dd>
      <dt>Last sign-in</dt><dd><?= h(fmt_dt($u['last_login'])) ?></dd>
      <?php if ($sw = mc_switch_targets()): ?><dt>Companies</dt><dd><?= h(implode(', ', array_column($sw, 'name'))) ?></dd><?php endif; ?>
    </dl>
  </div>
  <div class="card">
    <h2>Update credentials</h2>
    <?php if ($u['must_change_password']): ?><div class="callout warn">You are using a temporary password. Please set a new one.</div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="field wide"><label>Email (for notifications)</label><input type="email" name="email" value="<?= h($u['email']) ?>"></div>
        <?php if (empty($u['linked_from'])): ?>
        <div class="field wide"><label>Current password</label><input type="password" name="current_password" autocomplete="current-password"></div>
        <div class="field"><label>New password</label><input type="password" name="new_password" autocomplete="new-password" minlength="8"></div>
        <div class="field"><label>Confirm new password</label><input type="password" name="confirm_password" autocomplete="new-password"></div>
        <?php else: ?>
        <p class="muted small field wide">You reached <?= h(current_company_name()) ?> by switching company, so your password is changed in your own company. Switch back with the company selector in the top bar.</p>
        <?php endif; ?>
      </div>
      <div class="form-actions"><button class="btn btn-primary">Save changes</button></div>
    </form>
  </div>
</div>
<?php page_footer();
