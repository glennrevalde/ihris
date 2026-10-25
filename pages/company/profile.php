<?php
/**
 * Company Profile & Code (Company Owner): the company's registry details, its Company Code for new
 * employees (copy / regenerate) and a short overview of its accounts. Only the signed-in company.
 */
$co = tenant_company();
if (!$co) {
    throw new RuntimeException('Multi-company is not set up on this installation.');
}
$cid = (int)$co['id'];
$canRegen = in_company_support() || ($co['owner_can_regen'] && is_company_owner());

if (is_post()) {
    try {
        if (post('_action') === 'regen') {
            if (!$canRegen) {
                throw new RuntimeException('Regenerating the Company Code is reserved to the Super Admin for this company.');
            }
            $code = company_code_regenerate($cid);
            audit('admin', 'company_code_regenerated', 'company', $cid);
            flash('success', 'New Company Code: ' . $code . '. The previous code no longer accepts registrations; existing accounts are not affected.');
        } elseif (post('_action') === 'info') {
            $email = trim((string)post('email', ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Enter a valid e-mail address.');
            }
            $d = ['address' => trim((string)post('address', '')) ?: null, 'contact_no' => trim((string)post('contact_no', '')) ?: null, 'email' => $email ?: null];
            mupdate('companies', $d + ['updated_at' => now()], 'id = ?', [$cid]);
            if (post('sync_address')) {
                set_setting('company_address', (string)$d['address']);
            }
            audit('admin', 'company_info_update', 'company', $cid, $d);
            master_audit('company_updated', $cid, $d + ['by' => 'owner']);
            flash('success', 'Company information saved.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('company_profile');
}

$co = company_by_id($cid);
$code = company_code($cid);
$owner = $co['owner_user_id'] ? row('SELECT id, username, full_name, email FROM users WHERE id = ?', [(int)$co['owner_user_id']]) : null;
$n = [
    'users' => (int)val("SELECT COUNT(*) FROM users WHERE is_active = 1 AND is_support = 0"),
    'inactive' => (int)val("SELECT COUNT(*) FROM users WHERE is_active = 0 AND is_support = 0 AND (reg_status IS NULL OR reg_status = 'approved')"),
    'pending' => (int)val("SELECT COUNT(*) FROM users WHERE reg_status = 'pending'"),
    'employees' => (int)val("SELECT COUNT(*) FROM employees WHERE status = 'active'"),
];
$byRole = [];
foreach (rows('SELECT * FROM users WHERE is_active = 1 AND is_support = 0') as $x) {
    $rn = user_role_name($x);
    $byRole[$rn] = ($byRole[$rn] ?? 0) + 1;
}

page_header('Company Profile & Code', [
    'subtitle' => h($co['name']) . '. The Company Code lets new employees join this company; it is not a password.',
    'actions' => '<a class="btn" href="' . url('join_requests') . '">Join requests' . ($n['pending'] ? ' (' . $n['pending'] . ')' : '') . '</a><a class="btn" href="' . url('users') . '">User accounts</a>',
]);
?>
<div class="grid grid-4">
  <?= stat_card('Active users', (string)$n['users'], $n['inactive'] . ' inactive', 'info') ?>
  <?= stat_card('Pending join requests', (string)$n['pending'], '<a href="' . url('join_requests') . '">Review</a>', $n['pending'] ? 'warn' : '') ?>
  <?= stat_card('Active employees', (string)$n['employees'], '<a href="' . url('employees') . '">Employee masterfile</a>', 'good') ?>
  <?= stat_card('Company status', h(COMPANY_STATUSES[$co['status']] ?? $co['status']), 'since ' . h(fmt_date(substr((string)($co['activated_at'] ?: $co['created_at']), 0, 10)))) ?>
</div>

<div class="grid grid-2" style="margin-top:16px">
  <div class="card code-card">
    <div class="card-head"><div><h2>Company Code</h2><div class="sub">Give this code to employees who register for an account.</div></div></div>
    <div class="code-box">
      <span class="code-value" id="companyCode"><?= h($code ?: 'none') ?></span>
      <?php if ($code): ?><button type="button" class="btn btn-sm" data-copy="companyCode">Copy</button><?php endif; ?>
    </div>
    <p class="muted small">Registrations made with the code wait on <a href="<?= url('join_requests') ?>">Join Requests</a> until you approve them. Approved employees sign in with their own username and password; they never need the code again.</p>
    <?php if ($canRegen): ?>
      <form method="post" data-confirm="Generate a new Company Code? The current code stops working for new registrations at once. Existing accounts are not affected.">
        <?= csrf_field() ?><input type="hidden" name="_action" value="regen">
        <button class="btn btn-warning btn-sm">Regenerate code</button>
        <span class="muted small">Use it when the code was shared with the wrong people.</span>
      </form>
    <?php else: ?>
      <p class="muted small">Only the Super Admin can regenerate the code of this company.</p>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-head"><div><h2>Company information</h2><div class="sub">The company name is changed by the Super Admin.</div></div></div>
    <dl class="kv" style="margin-bottom:14px">
      <dt>Company</dt><dd><?= h($co['name']) ?></dd>
      <dt>Company Owner</dt><dd><?= $owner ? h($owner['full_name']) . ' (' . h($owner['username']) . ')' : '<span class="muted">not assigned</span>' ?></dd>
      <dt>Created</dt><dd><?= h(fmt_dt($co['created_at'])) ?></dd>
      <dt>Users by role</dt><dd><?= $byRole ? h(implode(', ', array_map(fn($r, $c) => $r . ' ' . $c, array_keys($byRole), $byRole))) : '—' ?></dd>
    </dl>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="_action" value="info">
      <div class="field"><label>Address</label><input name="address" maxlength="255" value="<?= h($co['address']) ?>"></div>
      <div class="form-grid cols-2 tight">
        <div class="field"><label>Contact number</label><input name="contact_no" maxlength="60" value="<?= h($co['contact_no']) ?>"></div>
        <div class="field"><label>E-mail</label><input type="email" name="email" maxlength="150" value="<?= h($co['email']) ?>"></div>
      </div>
      <label class="check"><input type="checkbox" name="sync_address" value="1" checked> Also use this address on documents (System Settings)</label>
      <div class="form-actions"><button class="btn btn-primary">Save</button></div>
    </form>
  </div>
</div>
<?php page_footer();
