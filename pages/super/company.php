<?php
/**
 * Company details (Super Admin): information, status, Company Owner, Company Code, database and
 * provisioning, the company's accounts and figures. Reads the company database only here, on demand.
 */
$id = (int)get('id', (int)post('id', 0));
$co = company_by_id($id);
if (!$co) {
    throw new RuntimeException('Company not found.');
}
$ready = $co['db_status'] === 'ready' && in_array($co['status'], ['active', 'inactive'], true);

if (is_post()) {
    $act = (string)post('_action');
    try {
        if ($act === 'info') {
            $name = trim((string)post('name', ''));
            $email = trim((string)post('email', ''));
            if ($name === '') {
                throw new RuntimeException('Enter the company name.');
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Enter a valid e-mail address.');
            }
            if ((int)mval("SELECT COUNT(*) FROM companies WHERE name = ? AND id <> ? AND status <> 'failed'", [$name, $id])) {
                throw new RuntimeException('Another company already has this name.');
            }
            $d = ['name' => $name, 'address' => trim((string)post('address', '')) ?: null, 'contact_no' => trim((string)post('contact_no', '')) ?: null, 'email' => $email ?: null];
            mupdate('companies', $d + ['updated_at' => now()], 'id = ?', [$id]);
            if ($ready && post('sync_settings')) {
                with_company_db($co['db_name'], function () use ($d, $co) {
                    set_setting('company_name', $d['name']);
                    set_setting('company_address', (string)$d['address']);
                    q('UPDATE branches SET company_name = ? WHERE company_name = ?', [$d['name'], $co['name']]);
                });
            }
            master_audit('company_updated', $id, $d);
            flash('success', 'Company information saved.');
        } elseif ($act === 'activate' || $act === 'deactivate') {
            if ($act === 'deactivate' && $co['is_primary'] && !post('confirm_primary')) {
                throw new RuntimeException('Tick the confirmation to deactivate the original installation.');
            }
            company_set_status($id, $act === 'activate' ? 'active' : 'inactive');
            flash('success', $act === 'activate' ? 'Company activated: its users can sign in again and its Company Code works.'
                : 'Company deactivated: its data is untouched, its users are signed out and cannot sign in, its Company Code stops working.');
        } elseif ($act === 'regen') {
            $code = company_code_regenerate($id);
            flash('success', 'New Company Code ' . $code . '. The previous code no longer works for new registrations; existing accounts are not affected.');
        } elseif ($act === 'regen_rule') {
            mupdate('companies', ['owner_can_regen' => post('owner_can_regen') ? 1 : 0, 'updated_at' => now()], 'id = ?', [$id]);
            master_audit('company_code_rule', $id, ['owner_can_regenerate' => post('owner_can_regen') ? 1 : 0]);
            flash('success', 'Saved.');
        } elseif ($act === 'owner_existing') {
            company_assign_owner($id, ['user_id' => (int)post('user_id')]);
            flash('success', 'Company Owner assigned.');
        } elseif ($act === 'owner_new') {
            $r = company_assign_owner($id, ['owner_name' => post('owner_name'), 'owner_email' => post('owner_email'), 'owner_username' => post('owner_username'), 'owner_password' => post('owner_password')]);
            flash('success', 'Company Owner account ' . $r['created']['username'] . ' created with the temporary password ' . $r['created']['password'] . ' (to be changed at the first sign-in).');
        } elseif ($act === 'check') {
            $issues = company_db_check($co['db_name']);
            if (!$issues) {
                mupdate('companies', ['db_version' => with_company_db($co['db_name'], fn() => db_version())], 'id = ?', [$id]);
            }
            master_audit('company_database_checked', $id, ['issues' => $issues]);
            flash($issues ? 'warning' : 'success', $issues ? 'Database check: ' . implode(' ', $issues) : 'Database check passed: every table is present and the defaults are in place.');
        } elseif ($act === 'upgrade') {
            $log = with_company_db($co['db_name'], fn() => db_upgrade());
            mupdate('companies', ['db_version' => DB_VERSION], 'id = ?', [$id]);
            master_audit('company_database_upgraded', $id, ['steps' => count($log)]);
            flash('success', $log ? 'Database upgraded (' . count($log) . ' step(s)).' : 'The database is already up to date.');
        } elseif ($act === 'retry') {
            $o = company_retry($id);
            flash('success', 'Provisioning completed: the company is active. Company Owner ' . $o['username'] . ', temporary password ' . $o['password'] . '.');
        } elseif ($act === 'discard') {
            company_discard_failed($id);
            flash('success', 'The failed company record was removed.');
            redirect('sa_companies');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    } catch (PDOException $e) {
        flash('error', 'Database error: ' . $e->getMessage());
    }
    redirect('sa_company', ['id' => $id]);
}

$code = company_code($id);
$codes = mrows('SELECT * FROM company_codes WHERE company_id = ? ORDER BY id DESC LIMIT 6', [$id]);
$log = mrows('SELECT * FROM company_provisioning_logs WHERE company_id = ? ORDER BY id DESC LIMIT 40', [$id]);
$audit = mrows('SELECT * FROM master_audit_log WHERE company_id = ? ORDER BY id DESC LIMIT 15', [$id]);
$stats = $ready ? company_stats($co) : null;
$users = [];
$owner = null;
if ($ready) {
    try {
        $users = with_company_db($co['db_name'], function () {
            $list = rows("SELECT u.*, e.employee_no FROM users u LEFT JOIN employees e ON e.id = u.employee_id WHERE u.is_support = 0
                          ORDER BY u.is_active DESC, FIELD(u.role, 'admin', 'hr', 'payroll', 'supervisor', 'employee'), u.username LIMIT 500");
            foreach ($list as &$x) {
                unset($x['password_hash']);
                $x['role_name'] = user_role_name($x);   // the company's own roles (read inside its database)
            }
            return $list;
        });
    } catch (Throwable $e) {
        $users = [];
    }
    foreach ($users as $u) {
        if ((int)$u['id'] === (int)$co['owner_user_id']) {
            $owner = $u;
        }
    }
}
$assignable = array_filter($users, fn($u) => $u['is_active'] && ($u['reg_status'] === null || $u['reg_status'] === 'approved') && (int)$u['id'] !== (int)$co['owner_user_id']);

page_header($co['name'], [
    'subtitle' => 'Company ' . (int)$co['id'] . ($co['is_primary'] ? ', the original installation' : '') . ', database ' . h($co['db_name']),
    'back' => url('sa_companies'), 'back_label' => 'Companies',
    'actions' => $ready ? '<form method="post" action="' . h(url('sa_enter')) . '" class="inline">' . csrf_field() . '<input type="hidden" name="_action" value="enter"><input type="hidden" name="id" value="' . $id . '"><button class="btn btn-primary">Enter company</button></form>' : '',
]);
?>
<?php if ($co['status'] === 'failed'): ?>
  <div class="callout bad"><strong>Provisioning failed.</strong> <?= h((string)$co['provision_error']) ?>
    <div class="form-actions">
      <form method="post" class="inline" data-confirm="Run the provisioning again for this company?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-primary btn-sm" name="_action" value="retry">Retry provisioning</button></form>
      <?php if ($co['db_status'] !== 'failed'): ?><form method="post" class="inline" data-confirm="Remove this failed company record? Its database was already removed."><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-danger-outline btn-sm" name="_action" value="discard">Discard</button></form><?php endif; ?>
    </div>
  </div>
<?php elseif ($co['status'] === 'inactive'): ?>
  <div class="callout warn"><strong>Inactive.</strong> The data is kept; its users cannot sign in and the Company Code does not accept registrations. You can still enter the company.</div>
<?php endif; ?>

<?php if ($stats): ?>
<div class="grid grid-4">
  <?= stat_card('Active employees', (string)(int)$stats['employees'], 'in ' . h($co['db_name']), 'info') ?>
  <?= stat_card('Active users', (string)(int)$stats['users'], (int)$stats['pending'] . ' join request(s) pending', (int)$stats['pending'] ? 'warn' : '') ?>
  <?= stat_card('Latest payroll run', $stats['last_run'] ? h($stats['last_run']['run_no']) : '—', $stats['last_run'] ? h(fmt_date($stats['last_run']['period_end'])) . ', ' . h($stats['last_run']['status']) : 'none yet') ?>
  <?= stat_card('Database', (int)$stats['tables'] . ' tables', h(number_format((float)$stats['size_mb'], 2)) . ' MB, version ' . (int)$stats['db_version'], 'good') ?>
</div>
<?php endif; ?>

<div class="grid grid-2" style="margin-top:16px">
  <div class="card">
    <div class="card-head"><div><h2>Company information</h2><div class="sub">Status <?= h(COMPANY_STATUSES[$co['status']] ?? $co['status']) ?>, created <?= h(fmt_dt($co['created_at'])) ?><?= $co['updated_at'] ? ', updated ' . h(fmt_dt($co['updated_at'])) : '' ?></div></div></div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="_action" value="info">
      <div class="field"><label>Company name</label><input name="name" required maxlength="150" value="<?= h($co['name']) ?>"></div>
      <div class="field"><label>Address</label><input name="address" maxlength="255" value="<?= h($co['address']) ?>"></div>
      <div class="form-grid cols-2 tight">
        <div class="field"><label>Contact number</label><input name="contact_no" maxlength="60" value="<?= h($co['contact_no']) ?>"></div>
        <div class="field"><label>E-mail</label><input type="email" name="email" maxlength="150" value="<?= h($co['email']) ?>"></div>
      </div>
      <?php if ($ready): ?><label class="check"><input type="checkbox" name="sync_settings" value="1"> Also change the company name and address printed on its documents (its System Settings)</label><?php endif; ?>
      <div class="form-actions"><button class="btn btn-primary">Save</button></div>
    </form>
    <?php if (in_array($co['status'], ['active', 'inactive'], true)): ?>
      <hr class="sep">
      <form method="post" <?= $co['status'] === 'active' ? 'data-confirm="Deactivate this company? Its users are signed out and cannot sign in; nothing is deleted."' : '' ?>>
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
        <?php if ($co['status'] === 'active'): ?>
          <?php if ($co['is_primary']): ?><label class="check"><input type="checkbox" name="confirm_primary" value="1"> This is the original installation; deactivate it anyway</label><?php endif; ?>
          <button class="btn btn-danger-outline" name="_action" value="deactivate">Deactivate company</button>
          <span class="muted small">Keeps every record; blocks sign-in and the Company Code.</span>
        <?php else: ?>
          <button class="btn btn-success" name="_action" value="activate">Activate company</button>
          <span class="muted small">Restores sign-in and the Company Code.</span>
        <?php endif; ?>
      </form>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-head"><div><h2>Company Owner</h2><div class="sub">An administrator of this company only; manages its users and join requests.</div></div></div>
    <dl class="kv">
      <dt>Current owner</dt>
      <dd><?= $co['owner_user_id'] ? h($co['owner_name']) . ' (' . h($co['owner_username']) . ')' . ($co['owner_email'] ? '<br><span class="muted small">' . h($co['owner_email']) . '</span>' : '') : '<span class="muted">not assigned</span>' ?></dd>
    </dl>
    <?php if ($ready): ?>
      <form method="post" class="owner-form">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="_action" value="owner_existing">
        <div class="field"><label><?= $co['owner_user_id'] ? 'Change the owner to an existing account' : 'Assign an existing account' ?></label>
          <select name="user_id" required><?= options(array_column(array_map(fn($u) => ['id' => $u['id'], 'l' => $u['full_name'] . ' (' . $u['username'] . ', ' . ($u['role_name'] ?? $u['role']) . ')'], $assignable), 'l', 'id'), null, '— choose —') ?></select></div>
        <p class="muted small">An account that is not an administrator becomes one.</p>
        <button class="btn btn-sm">Assign owner</button>
      </form>
      <details class="owner-new"<?= $co['owner_user_id'] ? '' : ' open' ?>>
        <summary>Or create a new owner account</summary>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="_action" value="owner_new">
          <div class="form-grid cols-2 tight">
            <div class="field"><label>Full name</label><input name="owner_name" required maxlength="150"></div>
            <div class="field"><label>E-mail</label><input type="email" name="owner_email" required maxlength="150"></div>
            <div class="field"><label>Username</label><input name="owner_username" required maxlength="60" autocomplete="off"></div>
            <div class="field"><label>Temporary password (blank = generate)</label><input type="password" name="owner_password" minlength="8" autocomplete="new-password"></div>
          </div>
          <button class="btn btn-sm btn-primary">Create owner account</button>
        </form>
      </details>
    <?php endif; ?>
  </div>
</div>

<div class="grid grid-2" style="margin-top:16px">
  <div class="card code-card">
    <div class="card-head"><div><h2>Company Code</h2><div class="sub">Employees enter it once, when registering. It is not a password.</div></div></div>
    <div class="code-box">
      <span class="code-value" id="companyCode"><?= h($code ?: 'none') ?></span>
      <?php if ($code): ?><button type="button" class="btn btn-sm" data-copy="companyCode">Copy</button><?php endif; ?>
    </div>
    <?php if ($co['status'] !== 'active'): ?><p class="muted small">The code accepts registrations only while the company is active.</p><?php endif; ?>
    <?php if (in_array($co['status'], ['active', 'inactive'], true)): ?>
      <form method="post" class="inline" data-confirm="Generate a new Company Code? The current code stops working for new registrations.">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-warning btn-sm" name="_action" value="regen">Regenerate code</button>
      </form>
      <form method="post" class="inline-check">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="_action" value="regen_rule">
        <label class="check"><input type="checkbox" name="owner_can_regen" value="1" <?= checked($co['owner_can_regen']) ?> data-autosubmit> The Company Owner may regenerate the code</label>
      </form>
    <?php endif; ?>
    <?php if ($codes): ?>
      <table class="table table-compact" style="margin-top:12px">
        <thead><tr><th>Code</th><th>Status</th><th>Created</th><th>Replaced</th></tr></thead>
        <tbody><?php foreach ($codes as $k): ?><tr><td class="mono"><?= h($k['code']) ?></td><td><?= h(label($k['status'])) ?></td><td class="small"><?= h(fmt_dt($k['created_at'])) ?><?= $k['created_by'] ? ', ' . h($k['created_by']) : '' ?></td><td class="small"><?= h(fmt_dt($k['replaced_at'])) ?></td></tr><?php endforeach; ?></tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-head"><div><h2>Database</h2><div class="sub">Resolved from this record only; never from a URL or a form.</div></div></div>
    <dl class="kv">
      <dt>Database</dt><dd class="mono"><?= h($co['db_name']) ?></dd>
      <dt>Database status</dt><dd><?= h(label($co['db_status'])) ?></dd>
      <dt>Structure version</dt><dd><?= $stats ? (int)$stats['db_version'] : h((string)($co['db_version'] ?? '—')) ?> (application <?= DB_VERSION ?>)</dd>
      <dt>Statutory tables</dt><dd><?= $co['is_primary'] ? 'Existing data' : (ctype_digit((string)$co['statutory_source']) ? 'Copied from company ' . h($co['statutory_source']) : 'Clean installation defaults') ?></dd>
      <dt>Uploaded files</dt><dd class="small">uploads/<?= $co['is_primary'] ? '' : 'company_' . $id . '/' ?></dd>
    </dl>
    <?php if ($ready): ?>
      <form method="post" class="form-actions"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn btn-sm" name="_action" value="check">Check database</button>
        <?php if ($stats && (int)$stats['db_version'] < DB_VERSION): ?><button class="btn btn-sm btn-primary" name="_action" value="upgrade">Upgrade database</button><?php endif; ?>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($ready): ?>
<div class="card flush" style="margin-top:16px">
  <div class="card-head pad"><div><h2>Users of this company</h2><div class="sub">Read from <?= h($co['db_name']) ?>. The Company Owner manages them; you can too after entering the company.</div></div></div>
  <div class="table-wrap">
    <table class="table table-compact">
      <thead><tr><th>Username</th><th>Name</th><th>Role</th><th>Employee</th><th>E-mail</th><th>Last sign-in</th><th>Status</th><th class="actions"></th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr class="<?= $u['is_active'] ? '' : 'row-muted' ?>"><td><strong><?= h($u['username']) ?></strong></td><td><?= h($u['full_name']) ?><?= (int)$u['id'] === (int)$co['owner_user_id'] ? '<br><small class="owner-tag">Company Owner</small>' : '' ?></td>
          <td><?= h($u['role_name'] ?? $u['role']) ?></td><td><?= h($u['employee_no']) ?></td><td class="small"><?= h($u['email']) ?></td><td class="small"><?= h(fmt_dt($u['last_login'])) ?></td>
          <td><?= $u['reg_status'] === 'pending' ? badge('pending', 'Pending approval') : ($u['reg_status'] === 'rejected' ? badge('rejected', 'Rejected') : ($u['is_active'] ? badge('active') : badge('stopped', 'Inactive'))) ?></td>
          <td class="actions"><?php if (empty($u['linked_from'])): ?><a class="btn btn-xs" href="<?= h(url('sa_passwords', ['company' => $id, 'sel' => 'u' . $id . '-' . (int)$u['id']])) ?>">Reset password</a><?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?= $users ? '' : empty_row(8, 'No account.') ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="grid grid-2" style="margin-top:16px">
  <div class="card flush">
    <div class="card-head pad"><div><h2>Provisioning log</h2></div></div>
    <div class="table-wrap"><table class="table table-compact">
      <thead><tr><th>When</th><th>Step</th><th>Result</th><th>Details</th></tr></thead>
      <tbody><?php foreach ($log as $l): ?><tr><td class="small nowrap"><?= h(fmt_dt($l['created_at'])) ?></td><td><?= h(label($l['step'])) ?></td><td><?= badge($l['status'] === 'ok' ? 'completed' : ($l['status'] === 'failed' ? 'failed' : 'logged'), ['ok' => 'OK', 'failed' => 'Failed', 'info' => 'Info'][$l['status']]) ?></td><td class="small"><?= h((string)$l['message']) ?></td></tr><?php endforeach; ?>
      <?= $log ? '' : empty_row(4, 'No entry.') ?></tbody>
    </table></div>
  </div>
  <div class="card flush">
    <div class="card-head pad"><div><h2>Platform audit of this company</h2></div><a class="btn btn-sm" href="<?= url('sa_logs', ['company' => $id]) ?>">All entries</a></div>
    <div class="table-wrap"><table class="table table-compact">
      <thead><tr><th>When</th><th>Action</th><th>By</th></tr></thead>
      <tbody><?php foreach ($audit as $a): ?><tr><td class="small nowrap"><?= h(fmt_dt($a['created_at'])) ?></td><td><?= h(label($a['action'])) ?></td><td class="small"><?= h($a['actor_name']) ?> <span class="muted">(<?= h(label($a['actor_type'])) ?>)</span></td></tr><?php endforeach; ?>
      <?= $audit ? '' : empty_row(3, 'No entry.') ?></tbody>
    </table></div>
  </div>
</div>
<?php page_footer();
