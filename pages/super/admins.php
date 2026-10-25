<?php
/**
 * Super Admin accounts (master database). Only a Super Admin manages Super Admins; no company role,
 * not even a Company Owner or a company administrator, can become or create one.
 */
$me = super_admin();
if (is_post()) {
    $act = (string)post('_action');
    $id = (int)post('id');
    try {
        if ($act === 'create') {
            $u = strtolower(trim((string)post('username')));
            $name = trim((string)post('full_name'));
            $email = trim((string)post('email'));
            $pw = (string)post('password');
            if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,59}$/', $u) || $name === '') {
                throw new RuntimeException('Enter a username (3 to 60 letters, digits, dot, dash, underscore) and the full name.');
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Enter a valid e-mail address.');
            }
            if (mc_username_taken($u) || ($email !== '' && mc_email_taken($email))) {
                throw new RuntimeException('That username or e-mail address is already used by another account.');
            }
            if ($pw === '') {
                $pw = provision_temp_password();
            } elseif (strlen($pw) < 10) {
                throw new RuntimeException('A Super Admin password must have at least 10 characters.');
            }
            $nid = minsert('super_admins', ['username' => $u, 'email' => $email ?: null, 'full_name' => $name, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
                'is_active' => 1, 'must_change_password' => 1, 'created_at' => now()]);
            master_audit('super_admin_created', null, ['username' => $u]);
            flash('success', 'Super Admin ' . $u . ' created with the temporary password ' . $pw . ' (to be changed at the first sign-in).');
        } elseif ($act === 'toggle') {
            if ($id === (int)$me['id']) {
                throw new RuntimeException('You cannot deactivate your own account.');
            }
            $row = mrow('SELECT * FROM super_admins WHERE id = ?', [$id]);
            if ($row && $row['is_active'] && (int)mval('SELECT COUNT(*) FROM super_admins WHERE is_active = 1') < 2) {
                throw new RuntimeException('At least one Super Admin must stay active.');
            }
            if ($row) {
                mupdate('super_admins', ['is_active' => $row['is_active'] ? 0 : 1], 'id = ?', [$id]);
                master_audit($row['is_active'] ? 'super_admin_deactivated' : 'super_admin_activated', null, ['username' => $row['username']]);
                flash('success', 'Saved.');
            }
        } elseif ($act === 'reset') {
            $row = mrow('SELECT * FROM super_admins WHERE id = ?', [$id]);
            if ($row && $id !== (int)$me['id']) {
                $pw = provision_temp_password();
                mupdate('super_admins', ['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'must_change_password' => 1], 'id = ?', [$id]);
                master_audit('super_admin_password_reset', null, ['username' => $row['username']]);
                flash('success', 'Temporary password for ' . $row['username'] . ': ' . $pw);
            }
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('sa_admins');
}
$list = mrows('SELECT * FROM super_admins ORDER BY is_active DESC, username');
page_header('Super Admin Accounts', ['subtitle' => 'Platform administrators. They manage companies and Company Owners; they sign in on the same page as everyone else.']);
?>
<div class="grid grid-2">
  <div class="card flush">
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Username</th><th>Name</th><th>Last sign-in</th><th>Status</th><th class="actions"></th></tr></thead>
      <tbody>
      <?php foreach ($list as $a): ?>
        <tr class="<?= $a['is_active'] ? '' : 'row-muted' ?>"><td><strong><?= h($a['username']) ?></strong><?= $a['email'] ? '<br><small class="muted">' . h($a['email']) . '</small>' : '' ?></td><td><?= h($a['full_name']) ?></td>
          <td class="small"><?= h(fmt_dt($a['last_login'])) ?></td><td><?= $a['is_active'] ? badge('active') : badge('stopped', 'Inactive') ?></td>
          <td class="actions"><?php if ((int)$a['id'] !== (int)$me['id']): ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-sm" name="_action" value="toggle"><?= $a['is_active'] ? 'Deactivate' : 'Activate' ?></button></form>
            <form method="post" class="inline" data-confirm="Reset this password?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-sm" name="_action" value="reset">Reset password</button></form>
          <?php else: ?><a class="btn btn-sm" href="<?= url('sa_account') ?>">My account</a><?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="card">
    <h2>New Super Admin</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="create">
      <div class="form-grid cols-2 tight">
        <div class="field"><label>Username</label><input name="username" required maxlength="60" autocomplete="off"></div>
        <div class="field"><label>Full name</label><input name="full_name" required maxlength="150"></div>
        <div class="field"><label>E-mail</label><input type="email" name="email" maxlength="150"></div>
        <div class="field"><label>Temporary password (blank = generate)</label><input type="password" name="password" minlength="10" autocomplete="new-password"></div>
      </div>
      <button class="btn btn-primary">Create Super Admin</button>
    </form>
  </div>
</div>
<?php page_footer();
