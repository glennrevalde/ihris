<?php
/**
 * User Account Management (Super Admin only). Basic information, role, account status and permissions
 * (role defaults, or customized with Allow / Deny overrides) of every account of the active company.
 * Multi-company: usernames are unique across every company because they are the sign-in identity; every
 * change is mapped into the master database. The Company Owner's account can only be changed by the
 * owner or a platform Super Admin. Nobody changes their own role, status or permissions, and the last
 * active Super Admin cannot be removed (perm_check_user_change()).
 */
require_super_user();
$co = tenant_company();
$ownerId = $co && $co['owner_user_id'] ? (int)$co['owner_user_id'] : 0;
$canTouchOwner = is_company_owner() || in_company_support();
if (is_post()) {
    $act = post('_action');
    $id = (int)post('id', 0);
    if ($act === 'save') {
        $d = ['username' => strtolower(trim((string)post('username'))), 'full_name' => trim((string)post('full_name')), 'email' => post_null('email'),
            'employee_id' => post_null('employee_id'), 'is_active' => post('is_active') === '0' ? 0 : 1];
        $back = $id ? ['edit' => $id] : ['new' => 1];
        $roleId = (int)post('role_id');
        if (!$d['username'] || !$d['full_name'] || !perm_role($roleId)) {
            flash('error', 'Username, full name and a valid role are required.');
            redirect('users', $back);
        }
        $old = $id ? row('SELECT * FROM users WHERE id = ? AND is_support = 0', [$id]) : null;
        if ($id && !$old) {
            flash('error', (int)val('SELECT is_support FROM users WHERE id = ?', [$id]) ? 'This support account is managed by the platform.' : 'User not found.');
            redirect('users');
        }
        if ($id && $id === $ownerId && !$canTouchOwner) {
            flash('error', 'Only the Company Owner can change the Company Owner\'s account.');
            redirect('users', ['edit' => $id]);
        }
        try {
            perm_check_user_change($old ?: ['id' => 0, 'role' => 'employee', 'role_id' => null, 'is_active' => 1], $roleId, (bool)$d['is_active']);
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('users', $back);
        }
        $linked = $old && !empty($old['linked_from']);
        if ($linked) {
            // an account reached by switching company: its username stays and it has no password of its own
            $d['username'] = $old['username'];
            $_POST['password'] = '';
        }
        $oldName = $old['username'] ?? null;
        if ($oldName !== $d['username'] && !preg_match('/^[a-z0-9][a-z0-9._@-]{1,59}$/', (string)$d['username'])) {
            flash('error', 'The username may use letters, digits, dot, dash, underscore and @ (2 to 60 characters).');
            redirect('users', $back);
        }
        if (!$linked && mc_username_taken((string)$d['username'], current_company_id(), $id ?: null)) {
            flash('error', 'The username "' . $d['username'] . '" is already used by another account (usernames are unique across all companies).');
            redirect('users', $back);
        }
        if ($d['email'] !== null && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid e-mail address or leave it blank.');
            redirect('users', $back);
        }
        if ((string)post('password') !== '') {
            if (strlen((string)post('password')) < 8) {
                flash('error', 'Password must be at least 8 characters.');
                redirect('users', $back);
            }
            $d['password_hash'] = password_hash((string)post('password'), PASSWORD_DEFAULT);
            $d['must_change_password'] = post('must_change') ? 1 : 0;
        } elseif (!$id) {
            flash('error', 'Set an initial password for the new account.');
            redirect('users', ['new' => 1]);
        }
        try {
            if ($id) {
                update('users', $d, 'id = ?', [$id]);
                $logOld = $old;
                unset($d['password_hash'], $logOld['password_hash']);
                $diff = diff_fields($logOld, $d);
                if ($diff || isset($d['must_change_password'])) {
                    audit('admin', 'user_update', 'users', $id, $diff + (isset($d['must_change_password']) ? ['password' => 'reset'] : []));
                }
            } else {
                $r = perm_role($roleId);
                $id = insert('users', $d + ['role_id' => $roleId, 'role' => $r['base_role'] ?: 'employee']);
                audit('admin', 'user_create', 'users', $id, ['username' => $d['username'], 'role' => $r['name']]);
                $old = row('SELECT * FROM users WHERE id = ?', [$id]);
            }
            $summary = perm_apply_user_access(row('SELECT * FROM users WHERE id = ?', [$id]), $_POST);
            mc_sync_user($id);
            flash('success', 'User account saved' . ($summary !== '' ? ': ' . $summary . '.' : '.'));
            $saved = row('SELECT * FROM users WHERE id = ?', [$id]);
            if ($saved && !is_super_user($saved) && scope_def($saved)['none'] && $saved['role'] !== 'employee') {
                flash('info', 'No company / branch or department is assigned to ' . $saved['full_name'] . ' yet, so the account sees no employee. Assign them in User Access & Permissions.');
            }
        } catch (PDOException $e) {
            flash('error', 'Username already exists.');
            redirect('users', $back);
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('users', $back);
        }
        redirect('users', ['edit' => $id]);
    }
    if ($act === 'reset_perms') {
        $u = row('SELECT * FROM users WHERE id = ? AND is_support = 0', [$id]);
        if ($u && $id !== user_id()) {
            $n = perm_reset_user($id);
            flash('success', $n ? "Reset to role defaults: $n override(s) removed." : 'This account already uses its role defaults.');
        } elseif ($u) {
            flash('error', 'You cannot change your own permissions.');
        }
        redirect('users', ['edit' => $id]);
    }
    if ($act === 'delete' || $act === 'activate') {
        $row = row('SELECT * FROM users WHERE id = ? AND is_support = 0', [$id]);
        if (!$row) {
            flash('error', 'User not found.');
            redirect('users');
        }
        if ($act === 'activate' && ($row['reg_status'] ?? '') === 'pending') {
            flash('error', 'Pending join requests are approved on the Join Requests page.');
            redirect('join_requests', ['id' => $id]);
        }
        try {
            $role = perm_user_role($row);
            perm_check_user_change($row, (int)($role['id'] ?? 0), $act === 'activate');
            if ($act === 'activate' && $role && $role['status'] !== 'active') {
                throw new RuntimeException('The role of this account ("' . $role['name'] . '") is inactive. Give the account an active role first.');
            }
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('users');
        }
        update('users', ['is_active' => $act === 'activate' ? 1 : 0], 'id = ?', [$id]);
        audit('admin', $act === 'activate' ? 'user_activate' : 'user_deactivate', 'users', $id);
        mc_sync_user($id);
        flash('success', $act === 'activate' ? 'User activated.' : 'User deactivated.');
        redirect('users');
    }
}
// keep the sign-in map of this company in step with its accounts
$clash = mc_sync_current_company();
$fRole = (int)get('role', 0);
$fStatus = (string)get('status', '');
$where = ['u.is_support = 0'];
$params = [];
if ($fRole && perm_role($fRole)) {
    $where[] = 'u.role_id = ?';
    $params[] = $fRole;
}
if ($fStatus === 'active') {
    $where[] = 'u.is_active = 1';
} elseif ($fStatus === 'inactive') {
    $where[] = "u.is_active = 0 AND (u.reg_status IS NULL OR u.reg_status = 'approved')";
} elseif (in_array($fStatus, ['pending', 'rejected'], true)) {
    $where[] = 'u.reg_status = ?';
    $params[] = $fStatus;
} elseif ($fStatus !== 'all') {
    $where[] = "(u.reg_status IS NULL OR u.reg_status = 'approved')";
}
$pendingCount = (int)val("SELECT COUNT(*) FROM users WHERE reg_status = 'pending'");
$users = rows('SELECT u.*, e.employee_no, (SELECT COUNT(*) FROM user_permission_overrides o WHERE o.user_id = u.id) AS overrides
               FROM users u LEFT JOIN employees e ON e.id = u.employee_id WHERE ' . implode(' AND ', $where) . '
               ORDER BY u.is_active DESC, u.full_name', $params);
$editId = (int)get('edit', 0);
$editing = $editId ? row('SELECT * FROM users WHERE id = ? AND is_support = 0', [$editId]) : (get('new') ? ['id' => 0, 'role' => 'employee', 'role_id' => (int)(perm_role_by_code('employee')['id'] ?? 0), 'is_active' => 1] : null);
if ($editId && !$editing) {
    flash('error', 'User not found.');
    redirect('users');
}
page_header($editing !== null ? ($editId ? 'User account: ' . $editing['full_name'] : 'New user account') : 'User Accounts', [
    'subtitle' => $editing !== null ? 'Basic information, role, account status and permissions of the account.' : 'Accounts of ' . h(current_company_name()) . '. Each account has a role (Role Management) and can be given its own Allow / Deny permissions.',
    'actions' => ($co ? '<a class="btn" href="' . url('join_requests') . '">Join requests' . ($pendingCount ? ' (' . $pendingCount . ')' : '') . '</a>' : '')
        . '<a class="btn" href="' . url('roles') . '">Roles</a><a class="btn" href="' . url('user_access', $editId ? ['user' => $editId] : []) . '">User access</a>'
        . ($editing === null ? '<a class="btn btn-primary" href="' . url('users', ['new' => 1]) . '">+ New user</a>' : '<a class="btn" href="' . url('users') . '">All accounts</a>')]);
if ($clash): ?>
  <div class="callout warn">These usernames are also used by an account of another company, so they cannot sign in until renamed here: <?= h(implode(', ', $clash)) ?>.</div>
<?php endif;
if ($pendingCount && $co && owner_or_support()): ?>
  <div class="callout"><strong><?= $pendingCount ?></strong> join request(s) made with the Company Code are waiting. <a href="<?= url('join_requests') ?>">Review join requests</a></div>
<?php endif;
if ($editing !== null):
    $self = $editId && $editId === user_id();
    $isOwnerAcct = $editId && $editId === $ownerId;
    $curRole = perm_user_role($editing);
    $ovr = $editId ? perm_user_overrides($editId) : [];
    $roleMap = perm_roles_keys_map();
    $roleSuper = array_map(fn($r) => (int)$r['is_super'], perm_roles());
    $linkedAcct = !empty($editing['linked_from']);
    $linksOut = $editId && !$linkedAcct ? mc_user_links_out($editId) : []; ?>
<form method="post" class="user-form" id="userForm">
  <?= csrf_field() ?><input type="hidden" name="_action" value="save"><input type="hidden" name="id" value="<?= $editId ?>">
  <div class="grid grid-2">
    <div class="card">
      <h2>Basic information</h2>
      <div class="form-grid">
        <div class="field"><label>Username</label><input name="username" value="<?= h($editing['username'] ?? '') ?>" required maxlength="60" autocomplete="off"<?= $linkedAcct ? ' readonly' : '' ?>></div>
        <div class="field"><label>Full name</label><input name="full_name" value="<?= h($editing['full_name'] ?? '') ?>" required maxlength="150"></div>
        <div class="field"><label>Email</label><input type="email" name="email" value="<?= h($editing['email'] ?? '') ?>" maxlength="150"></div>
        <div class="field"><label>Linked employee (self-service &amp; approvals)</label><select name="employee_id"><?= options(employee_options(false), $editing['employee_id'] ?? null, '— none —') ?></select></div>
        <?php if (!$linkedAcct): ?>
        <div class="field"><label><?= $editId ? 'New password (leave blank to keep)' : 'Password' ?></label><input type="password" name="password" autocomplete="new-password" minlength="8"></div>
        <div class="field"><label class="check"><input type="checkbox" name="must_change" value="1" checked> Require a new password at next sign-in</label></div>
        <?php endif; ?>
      </div>
      <?php if ($linkedAcct): ?><div class="callout">Linked account of <strong><?= h(mc_linked_label($editing['linked_from'])) ?></strong>. That user signs in with their own account and switches to <?= h(current_company_name()) ?> in the top bar, so this account has no password. Its role, status and permissions here are yours to manage.</div><?php endif; ?>
      <?php if ($linksOut): ?><div class="callout">Also has access to: <?= h(implode(', ', array_map(fn($l) => $l['company_name'] . ($l['status'] === 'active' ? '' : ' (inactive)'), $linksOut))) ?>. Access to other companies is given by the platform Super Admin.</div><?php endif; ?>
      <?php if ($editId): ?><p class="muted small">Last sign-in <?= h(fmt_dt($editing['last_login']) ?: 'never') ?>.<?= $isOwnerAcct ? ' This is the Company Owner.' : '' ?></p><?php endif; ?>
    </div>
    <div class="card">
      <h2>Role and account status</h2>
      <div class="form-grid">
        <div class="field"><label>Role</label>
          <select name="role_id" id="roleSel" data-perm-role="userGrid"<?= $self || $isOwnerAcct ? ' disabled' : '' ?>><?= options(array_map(fn($r) => $r['name'] . ($r['status'] === 'active' ? '' : ' (inactive)'),
              array_filter(perm_roles(), fn($r) => $r['status'] === 'active' || (int)$r['id'] === (int)($curRole['id'] ?? 0))), (int)($curRole['id'] ?? 0)) ?></select>
          <?php if ($self || $isOwnerAcct): ?><input type="hidden" name="role_id" value="<?= (int)($curRole['id'] ?? 0) ?>"><?php endif; ?>
          <span class="help"><?= $self ? 'You cannot change your own role.' : ($isOwnerAcct ? 'The Company Owner keeps the System Administrator role.' : '<a href="' . url('roles') . '">Manage roles</a>') ?></span></div>
        <div class="field"><label>Account status</label>
          <select name="is_active"<?= $self || $isOwnerAcct ? ' disabled' : '' ?>><?= options(['1' => 'Active', '0' => 'Inactive (cannot sign in)'], (string)(int)($editing['is_active'] ?? 1)) ?></select>
          <?php if ($self || $isOwnerAcct): ?><input type="hidden" name="is_active" value="1"><?php endif; ?></div>
      </div>
      <div class="perm-mode">
        <p class="label-line">Permissions</p>
        <label class="access-mode"><input type="radio" name="perm_mode" value="defaults" <?= checked(!$ovr) ?> data-perm-mode<?= $self ? ' disabled' : '' ?>> <span><strong>Use role defaults</strong><small>Exactly what the role gives.</small></span></label>
        <label class="access-mode"><input type="radio" name="perm_mode" value="custom" <?= checked((bool)$ovr) ?> data-perm-mode<?= $self ? ' disabled' : '' ?>> <span><strong>Customize access</strong><small>Inherit, Allow or Deny each permission for this user.</small></span></label>
        <?php if ($self): ?><p class="muted small">You cannot change your own permissions.</p><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card flush perm-panel" data-perm-panel="custom">
    <div class="card-head pad"><div><h2>Custom access</h2><div class="sub">Click a cell to cycle Inherit (the role decides), Allow and Deny. Faded ticks show what the role gives.</div></div>
      <?php if ($ovr && !$self): ?><button class="btn btn-sm" name="_action" value="reset_perms" formnovalidate data-confirm="Remove every override of this user and use the role defaults again?">Reset to role defaults</button><?php endif; ?></div>
    <div class="pad-x"><?= perm_grid('user', $curRole ? perm_role_keys((int)$curRole['id']) : [], $ovr, $self, $roleMap, 'userGrid') ?></div>
    <script type="application/json" id="roleSuperMap"><?= json_encode($roleSuper) ?></script>
  </div>

  <div class="toolbar sticky-actions">
    <button class="btn btn-primary">Save account</button>
    <a class="btn" href="<?= url('users') ?>">Cancel</a>
    <?php if ($editId): ?><a class="btn" href="#effective">Effective access</a><?php endif; ?>
  </div>
</form>
<?php if ($editId): ?>
<div class="card flush" id="effective">
  <div class="card-head pad"><div><h2>Effective access</h2><div class="sub">What <?= h($editing['full_name']) ?> can do right now: <?= h(user_role_name($editing)) ?><?= $ovr ? ' with ' . count($ovr) . ' custom override(s)' : ', role defaults' ?>. Saved settings only; save the account to see a change here.</div></div></div>
  <div class="pad-x"><?= perm_effective_html($editing, 'userEff') ?></div>
</div>
<?php endif; ?>
<?php
    page_footer();
    return;
endif; ?>
<form method="get" class="card filters">
  <input type="hidden" name="p" value="users">
  <label>Role<select name="role" data-autosubmit><?= options(role_options(true), $fRole ?: null, 'All roles') ?></select></label>
  <label>Status<select name="status" data-autosubmit><?= options(['' => 'Active and inactive', 'active' => 'Active', 'inactive' => 'Inactive', 'pending' => 'Pending join requests', 'rejected' => 'Rejected join requests', 'all' => 'All accounts'], $fStatus) ?></select></label>
  <label class="grow">Search<input type="search" class="table-search" data-target="uTbl" placeholder="Username, name or e-mail"></label>
</form>
<div class="card flush"><div class="table-wrap">
<table class="table" id="uTbl"><thead><tr><th>Username</th><th>Name</th><th>Role</th><th>Permissions</th><th>Employee</th><th>Last sign-in</th><th>Status</th><th class="actions"></th></tr></thead><tbody>
<?php foreach ($users as $u): $isOwner = (int)$u['id'] === $ownerId; $reg = (string)($u['reg_status'] ?? ''); ?><tr class="<?= $u['is_active'] ? '' : 'row-muted' ?>">
  <td><strong><?= h($u['username']) ?></strong></td>
  <td><?= h($u['full_name']) ?><?php if ($isOwner): ?><br><small class="owner-tag">Company Owner</small><?php endif; ?><?php if (!empty($u['linked_from'])): ?><br><small class="muted">Linked from <?= h(mc_linked_label($u['linked_from'])) ?></small><?php endif; ?></td>
  <td><?= h(user_role_name($u)) ?></td>
  <td class="small"><?= is_super_user($u) ? 'Every permission' : ((int)$u['overrides'] ? '<span class="state-custom">Customized</span>, ' . (int)$u['overrides'] . ' override(s)' : 'Role defaults') ?></td>
  <td><?= h($u['employee_no']) ?><?= $u['email'] ? '<br><small class="muted">' . h($u['email']) . '</small>' : '' ?></td>
  <td class="small"><?= h(fmt_dt($u['last_login'])) ?></td>
  <td><?= $reg === 'pending' ? badge('pending', 'Pending approval') : ($reg === 'rejected' ? badge('stopped', 'Rejected') : ($u['is_active'] ? badge('active') : badge('stopped', 'Inactive'))) ?></td>
  <td class="actions">
    <?php if ($reg === 'pending' || $reg === 'rejected'): ?>
      <a class="btn btn-sm" href="<?= url('join_requests', ['id' => $u['id']]) ?>">Review</a>
    <?php else: ?>
      <?php if (!$isOwner || $canTouchOwner): ?><a class="btn btn-sm" href="<?= url('users', ['edit' => $u['id']]) ?>">Edit</a><?php endif; ?>
      <a class="btn btn-sm" href="<?= url('user_access', ['user' => $u['id']]) ?>">Access</a>
      <?php if ($u['id'] != user_id() && $u['is_active'] && !$isOwner): ?><form method="post" class="inline" data-confirm="Deactivate this user?"><?= csrf_field() ?><input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="btn btn-sm btn-danger-outline">Deactivate</button></form><?php endif; ?>
      <?php if (!$u['is_active']): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="_action" value="activate"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="btn btn-sm">Activate</button></form><?php endif; ?>
    <?php endif; ?>
  </td></tr>
<?php endforeach; ?>
<?= $users ? '' : empty_row(8, 'No account matches these filters.') ?>
</tbody></table></div></div>
<?php page_footer();
