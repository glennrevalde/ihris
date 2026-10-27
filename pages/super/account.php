<?php
/** My account (Super Admin): password and e-mail of the platform account. */
$sa = super_admin();
if (is_post()) {
    $cur = (string)post('current_password');
    $new = (string)post('new_password');
    $email = trim((string)post('email', ''));
    if (!password_verify($cur, $sa['password_hash'])) {
        flash('error', 'Your current password is incorrect.');
    } else {
        if ($new !== '' || $sa['must_change_password']) {
            if (strlen($new) < 10) {
                flash('error', 'The new password must be at least 10 characters.');
                redirect('sa_account');
            }
            if ($new !== (string)post('confirm_password')) {
                flash('error', 'The new passwords do not match.');
                redirect('sa_account');
            }
            if ($sa['must_change_password'] && password_verify($new, $sa['password_hash'])) {
                flash('error', 'Choose a new password that is different from the temporary one.');
                redirect('sa_account');
            }
            mupdate('super_admins', ['password_hash' => password_hash($new, PASSWORD_DEFAULT), 'must_change_password' => 0], 'id = ?', [$sa['id']]);
            master_audit('super_admin_password_change');
            flash('success', 'Password updated.');
        }
        if ($email !== (string)$sa['email']) {
            if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || (int)mval('SELECT COUNT(*) FROM super_admins WHERE email = ? AND id <> ?', [$email, $sa['id']]) || (int)mval('SELECT COUNT(*) FROM company_memberships WHERE email = ?', [$email]))) {
                flash('error', 'That e-mail address is not valid or is already used.');
            } else {
                mupdate('super_admins', ['email' => $email ?: null], 'id = ?', [$sa['id']]);
                flash('success', 'E-mail updated.');
            }
        }
    }
    redirect('sa_account');
}
page_header('My Account', ['subtitle' => 'Super Admin account ' . h($sa['username'])]);
?>
<div class="grid grid-2">
  <div class="card">
    <h2>Account</h2>
    <dl class="kv"><dt>Name</dt><dd><?= h($sa['full_name']) ?></dd><dt>Username</dt><dd><?= h($sa['username']) ?></dd><dt>Role</dt><dd>Super Administrator (platform)</dd><dt>Last sign-in</dt><dd><?= h(fmt_dt($sa['last_login'])) ?></dd></dl>
  </div>
  <div class="card">
    <h2><?= $sa['must_change_password'] ? 'Set your password' : 'Change password' ?></h2>
    <?php if ($sa['must_change_password']): ?><div class="callout warn">Choose your own password before using the platform.</div><?php endif; ?>
    <form method="post"><?= csrf_field() ?>
      <div class="field"><label>Current password</label><input type="password" name="current_password" required autocomplete="current-password"></div>
      <div class="form-grid cols-2 tight">
        <div class="field"><label>New password</label><input type="password" name="new_password" minlength="10" autocomplete="new-password"<?= $sa['must_change_password'] ? ' required' : '' ?>></div>
        <div class="field"><label>Confirm new password</label><input type="password" name="confirm_password" minlength="10" autocomplete="new-password"></div>
      </div>
      <div class="field"><label>E-mail</label><input type="email" name="email" maxlength="150" value="<?= h($sa['email']) ?>"></div>
      <button class="btn btn-primary">Save</button>
    </form>
  </div>
</div>
<?php page_footer();
