<?php
/**
 * Create Company (Super Admin): company details + Company Owner. On save the platform reserves the
 * company, generates its Company Code and database name, creates the database, installs the current
 * MySQL 5.5 structure and the clean defaults, creates the owner, validates, then activates it.
 */
$f = ['name' => '', 'address' => '', 'contact_no' => '', 'email' => '', 'owner_name' => '', 'owner_email' => '', 'owner_username' => '', 'statutory_source' => 'defaults'];
$error = null;
if (is_post()) {
    foreach ($f as $k => $v) {
        $f[$k] = trim((string)post($k, ''));
    }
    try {
        $res = company_create($f + ['owner_password' => (string)post('owner_password', '')]);
        flash('success', 'Company created and active. Company Code ' . $res['code'] . '. Company Owner sign-in: ' . $res['owner']['username']
            . ($res['owner']['generated'] ? ' with the temporary password ' . $res['owner']['password'] : ' with the password you entered')
            . ' (to be changed at the first sign-in).');
        redirect('sa_company', ['id' => $res['id']]);
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
        // a failed provisioning keeps its record (status Failed) so it can be retried or discarded
        $failed = mrow("SELECT id FROM companies WHERE name = ? AND status = 'failed' ORDER BY id DESC LIMIT 1", [$f['name']]);
        if ($failed) {
            flash('error', $error);
            redirect('sa_company', ['id' => $failed['id']]);
        }
    }
}
$sources = mrows("SELECT id, name FROM companies WHERE status IN ('active', 'inactive') AND db_status = 'ready' ORDER BY is_primary DESC, name");
$grants = mc_server_grants();

page_header('Create Company', [
    'subtitle' => 'The company receives its own database, its Company Code and its Company Owner. Nothing of another company is copied.',
    'back' => url('sa_companies'), 'back_label' => 'Companies',
]);
if ($error): ?><div class="alert alert-error"><span><?= h($error) ?></span></div><?php endif;
if ($grants['can_create'] === false): ?>
  <div class="callout warn">The MySQL account may not be allowed to create company databases. See <a href="<?= url('sa_dashboard') ?>">Database server</a> on the dashboard for the statements to run.</div>
<?php endif; ?>
<form method="post" class="grid grid-2">
  <?= csrf_field() ?>
  <div class="card">
    <h2>Company</h2>
    <div class="field"><label>Company name</label><input name="name" required maxlength="150" value="<?= h($f['name']) ?>"></div>
    <div class="field"><label>Address</label><input name="address" maxlength="255" value="<?= h($f['address']) ?>"></div>
    <div class="form-grid cols-2 tight">
      <div class="field"><label>Contact number</label><input name="contact_no" maxlength="60" value="<?= h($f['contact_no']) ?>"></div>
      <div class="field"><label>E-mail</label><input type="email" name="email" maxlength="150" value="<?= h($f['email']) ?>"></div>
    </div>
    <div class="field"><label>Statutory tables (tax, SSS, PhilHealth, Pag-IBIG, premium and pay rates, lates, holidays)</label>
      <select name="statutory_source"><option value="defaults">Clean installation defaults (<?= date('Y') ?> rates)</option>
        <?php foreach ($sources as $s): ?><option value="<?= (int)$s['id'] ?>"<?= $f['statutory_source'] === (string)$s['id'] ? ' selected' : '' ?>>Copy the current tables of <?= h($s['name']) ?></option><?php endforeach; ?>
      </select></div>
    <p class="muted small">Generated automatically: the Company Code and the database <?= h(company_db_prefix()) ?>###. The new database starts clean: settings, statutory and lookup tables, leave types, shifts and pay codes, one Head Office branch and one top organization unit named after the company. No employee, user, attendance, payroll or history of another company is copied.</p>
  </div>
  <div class="card">
    <h2>Company Owner</h2>
    <p class="muted small">The primary account of this company: an administrator of this company only. It manages the company's users and approves the employees who join with the Company Code.</p>
    <div class="field"><label>Full name</label><input name="owner_name" required maxlength="150" value="<?= h($f['owner_name']) ?>"></div>
    <div class="field"><label>E-mail</label><input type="email" name="owner_email" required maxlength="150" value="<?= h($f['owner_email']) ?>"></div>
    <div class="field"><label>Username</label><input name="owner_username" required maxlength="60" pattern="[A-Za-z0-9][A-Za-z0-9._\-]{2,59}" value="<?= h($f['owner_username']) ?>" autocomplete="off"></div>
    <div class="field"><label>Temporary password (blank = generate one)</label><input type="password" name="owner_password" minlength="8" autocomplete="new-password"></div>
    <p class="muted small">Usernames and e-mail addresses are unique across all companies because they are what people sign in with. The owner changes the password at the first sign-in.</p>
    <div class="form-actions"><button class="btn btn-primary" data-busy="Creating the company database…">Create company</button> <a class="btn" href="<?= url('sa_companies') ?>">Cancel</a></div>
  </div>
</form>
<?php page_footer();
