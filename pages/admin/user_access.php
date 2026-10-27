<?php
/**
 * User Access & Permissions (Super Admin only). For one user: the role, the permission mode (role
 * defaults or customized with Inherit / Allow / Deny per permission), the Effective Access preview
 * (role + overrides = what the user can do now), and the data assignment (companies / branches and
 * departments the user's employee lists are limited to).
 *
 * v13: the assignment decides which employees the user sees anywhere (app/engine/scope.php). Nothing
 * ticked = no employee; "All companies / branches" and "All departments" lift the limit of a side.
 */
require_super_user();

$userId = (int)get('user', 0);
// the Super Admin support account and join requests not yet approved are not listed
$users = rows("SELECT u.*, e.employee_no, (SELECT COUNT(*) FROM user_permission_overrides o WHERE o.user_id = u.id) AS overrides
               FROM users u LEFT JOIN employees e ON e.id = u.employee_id
               WHERE u.is_support = 0 AND (u.reg_status IS NULL OR u.reg_status = 'approved') ORDER BY u.is_active DESC, u.full_name");
if (!$userId && $users) {
    $userId = (int)$users[0]['id'];
}
$user = $userId ? row('SELECT u.*, e.employee_no FROM users u LEFT JOIN employees e ON e.id = u.employee_id WHERE u.id = ? AND u.is_support = 0', [$userId]) : null;
$co = tenant_company();
$ownerId = $co && $co['owner_user_id'] ? (int)$co['owner_user_id'] : 0;

if (is_post()) {
    $uid = (int)post('user_id');
    try {
        $u = row('SELECT * FROM users WHERE id = ? AND is_support = 0', [$uid]);
        if (!$u) {
            throw new RuntimeException('User not found.');
        }
        if ($uid === $ownerId && !(is_company_owner() || in_company_support())) {
            throw new RuntimeException('Only the Company Owner can change the Company Owner\'s access.');
        }
        $act = (string)post('_action');
        if ($act === 'save') {
            $roleId = (int)post('role_id') ?: (int)(perm_user_role($u)['id'] ?? 0);
            perm_check_user_change($u, $roleId, (bool)$u['is_active']);
            $summary = perm_apply_user_access($u, $_POST);
            // data assignment
            $branches = array_values(array_unique(array_filter(array_map('intval', (array)post('branches', [])))));
            $units = array_values(array_unique(array_filter(array_map('intval', (array)post('units', [])))));
            $okB = $branches ? q('SELECT id FROM branches WHERE id IN (' . in_list($branches) . ')', $branches)->fetchAll(PDO::FETCH_COLUMN) : [];
            $okU = $units ? q('SELECT id FROM org_units WHERE id IN (' . in_list($units) . ')', $units)->fetchAll(PDO::FETCH_COLUMN) : [];
            $oldSc = rows('SELECT scope_type, ref_id FROM user_scopes WHERE user_id = ? ORDER BY scope_type, ref_id', [$uid]);
            q('DELETE FROM user_scopes WHERE user_id = ?', [$uid]);
            foreach ($okB as $b) {
                insert('user_scopes', ['user_id' => $uid, 'scope_type' => 'branch', 'ref_id' => (int)$b]);
            }
            foreach ($okU as $o) {
                insert('user_scopes', ['user_id' => $uid, 'scope_type' => 'org_unit', 'ref_id' => (int)$o]);
            }
            $allB = post('scope_all_branches') ? 1 : 0;
            $allU = post('scope_all_units') ? 1 : 0;
            update('users', ['scope_all_branches' => $allB, 'scope_all_units' => $allU], 'id = ?', [$uid]);
            $newSc = rows('SELECT scope_type, ref_id FROM user_scopes WHERE user_id = ? ORDER BY scope_type, ref_id', [$uid]);
            if ($oldSc != $newSc || (int)$u['scope_all_branches'] !== $allB || (int)$u['scope_all_units'] !== $allU) {
                audit('admin', 'user_assignment_update', 'users', $uid, ['branches' => $allB ? 'all' : count($okB), 'departments' => $allU ? 'all' : count($okU)]);
            }
            unset($GLOBALS['__scope']);
            if (!is_super_user(row('SELECT * FROM users WHERE id = ?', [$uid])) && scope_def(row('SELECT * FROM users WHERE id = ?', [$uid]))['none']) {
                flash('warning', $u['full_name'] . ' has no company / branch and no department assigned, so the account sees no employee.');
            }
            mc_sync_user($uid);
            flash('success', 'Access saved for ' . $u['full_name'] . ($summary !== '' ? ': ' . $summary . '.' : '.'));
        } elseif ($act === 'reset_perms') {
            if ($uid === (int)user_id()) {
                throw new RuntimeException('You cannot change your own permissions.');
            }
            $n = perm_reset_user($uid);
            flash('success', $n ? "Reset to role defaults: $n override(s) removed." : 'This account already uses its role defaults.');
        } elseif ($act === 'copy') {
            $from = row('SELECT * FROM users WHERE id = ? AND is_support = 0', [(int)post('from_user')]);
            if (!$from) {
                throw new RuntimeException('Select the user to copy from.');
            }
            if ($uid === (int)user_id()) {
                throw new RuntimeException('You cannot change your own access.');
            }
            $fromRole = perm_user_role($from);
            perm_check_user_change($u, (int)($fromRole['id'] ?? 0), (bool)$u['is_active']);
            perm_apply_user_access($u, ['role_id' => (int)($fromRole['id'] ?? 0), 'perm_mode' => 'custom', 'ovr' => perm_user_overrides((int)$from['id'])]);
            q('DELETE FROM user_scopes WHERE user_id = ?', [$uid]);
            foreach (rows('SELECT * FROM user_scopes WHERE user_id = ?', [$from['id']]) as $s) {
                insert('user_scopes', ['user_id' => $uid, 'scope_type' => $s['scope_type'], 'ref_id' => $s['ref_id']]);
            }
            update('users', ['scope_all_branches' => (int)$from['scope_all_branches'], 'scope_all_units' => (int)$from['scope_all_units']], 'id = ?', [$uid]);
            audit('admin', 'user_access_copy', 'users', $uid, ['from' => $from['username']]);
            mc_sync_user($uid);
            flash('success', 'Role, permissions and assignment copied from ' . $from['full_name'] . '.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('user_access', ['user' => $uid]);
}

$scopes = ['branch' => [], 'org_unit' => []];
foreach ($user ? rows('SELECT * FROM user_scopes WHERE user_id = ?', [$userId]) : [] as $s) {
    $scopes[$s['scope_type']][] = (int)$s['ref_id'];
}
$branches = rows('SELECT * FROM branches ORDER BY company_code, branch_code, code');
$units = rows('SELECT * FROM org_units WHERE is_active = 1 ORDER BY name');

page_header('User Access & Permissions', [
    'subtitle' => 'Role permissions are the defaults of a user. Customize a user with Allow or Deny, check the Effective Access, and limit the employees the user works on to some companies or departments.',
    'actions' => '<a class="btn" href="' . url('roles') . '">Roles</a><a class="btn" href="' . url('users') . '">User accounts</a><a class="btn" href="' . url('audit', ['module' => 'admin']) . '">Audit trail</a>',
]);
?>
<div class="split">
  <div class="card split-list">
    <div class="list-head"><strong>Users</strong><input type="search" data-filter-list="userList" placeholder="Filter users…"></div>
    <div class="list-nav" id="userList">
      <?php foreach ($users as $u): ?>
        <a class="<?= (int)$u['id'] === $userId ? 'active' : '' ?>" href="<?= url('user_access', ['user' => $u['id']]) ?>">
          <span class="ln-dot <?= $u['is_active'] ? 'on' : '' ?>"></span>
          <span class="ln-main"><strong><?= h($u['full_name']) ?></strong><small><?= h($u['username']) ?>, <?= h(user_role_name($u)) ?><?= (int)$u['overrides'] ? ', customized' : '' ?><?= !is_super_user($u) && scope_def($u)['none'] ? ', <span class="state-off">no employee assigned</span>' : '' ?></small></span>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="list-count"><?= count($users) ?> user(s)</div>
  </div>

  <div class="ua-main">
  <?php if (!$user): ?>
    <div class="card"><div class="empty-state"><h3>Select a user</h3></div></div>
  <?php else:
      $self = (int)$user['id'] === (int)user_id();
      $isOwnerAcct = (int)$user['id'] === $ownerId;
      $role = perm_user_role($user);
      $super = is_super_user($user);
      $ovr = perm_user_overrides((int)$user['id']);
      $locked = $self || ($isOwnerAcct && !(is_company_owner() || in_company_support())); ?>
    <form method="post" id="accessForm">
      <?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>"><input type="hidden" name="_action" value="save">
      <div class="card">
        <div class="card-head">
          <div class="person">
            <span class="avatar"><?= h(mb_strtoupper(mb_substr($user['full_name'], 0, 1))) ?></span>
            <div><div class="person-name"><?= h($user['full_name']) ?></div>
              <div class="person-meta"><?= h($user['username']) ?><?= $user['employee_no'] ? ', ' . h($user['employee_no']) : '' ?><?= $isOwnerAcct ? ', Company Owner' : '' ?>
                <?= $user['is_active'] ? '' : ', <span class="state-off">inactive</span>' ?></div></div>
          </div>
          <div class="btn-row">
            <?php if (super_admin() && current_company_id() && empty($user['linked_from']) && empty($user['is_support'])): ?>
              <a class="btn btn-sm" href="<?= h(url('sa_passwords', ['company' => current_company_id(), 'sel' => 'u' . current_company_id() . '-' . (int)$user['id']])) ?>">Reset password</a>
            <?php endif; ?>
            <a class="btn btn-sm" href="<?= h(url('users', ['edit' => $user['id']])) ?>">Edit the account</a>
          </div>
        </div>
        <div class="form-grid ua-role">
          <div class="field"><label>Role</label>
            <select name="role_id" data-perm-role="userGrid"<?= $locked || $isOwnerAcct ? ' disabled' : '' ?>><?= options(array_map(fn($r) => $r['name'] . ($r['status'] === 'active' ? '' : ' (inactive)'),
                array_filter(perm_roles(), fn($r) => $r['status'] === 'active' || (int)$r['id'] === (int)($role['id'] ?? 0))), (int)($role['id'] ?? 0)) ?></select>
            <?php if ($locked || $isOwnerAcct): ?><input type="hidden" name="role_id" value="<?= (int)($role['id'] ?? 0) ?>"><?php endif; ?></div>
          <div class="perm-mode">
            <label class="access-mode"><input type="radio" name="perm_mode" value="defaults" <?= checked(!$ovr) ?> data-perm-mode<?= $locked || $super ? ' disabled' : '' ?>> <span><strong>Use role defaults</strong><small>Exactly what the role gives.</small></span></label>
            <label class="access-mode"><input type="radio" name="perm_mode" value="custom" <?= checked((bool)$ovr) ?> data-perm-mode<?= $locked || $super ? ' disabled' : '' ?>> <span><strong>Customize access</strong><small>Inherit, Allow or Deny each permission.</small></span></label>
          </div>
        </div>
        <?php if ($super): ?>
          <div class="callout">This account holds the Super Admin role<?= in_array((int)$user['id'], [$ownerId], true) ? ' as Company Owner' : '' ?>: it has every permission, so overrides do not apply.</div>
        <?php elseif ($self): ?>
          <div class="callout warn">You cannot change your own role or permissions. Another Super Admin can.</div>
        <?php endif; ?>
      </div>

      <div class="card flush perm-panel" data-perm-panel="custom">
        <div class="card-head pad"><div><h2>Custom access</h2><div class="sub">Click a cell to cycle Inherit (the role decides), Allow and Deny. The module row sets a whole column of the module.</div></div>
          <?php if ($ovr && !$locked): ?><button class="btn btn-sm" name="_action" value="reset_perms" data-confirm="Remove every override of <?= h($user['full_name']) ?> and use the role defaults again?">Reset to role defaults</button><?php endif; ?></div>
        <div class="pad-x"><?= perm_grid('user', $role ? perm_role_keys((int)$role['id']) : [], $ovr, $locked, perm_roles_keys_map(), 'userGrid') ?></div>
        <script type="application/json" id="roleSuperMap"><?= json_encode(array_map(fn($r) => (int)$r['is_super'], perm_roles())) ?></script>
      </div>

      <?php $sd = scope_def($user); ?>
      <div class="card scope-summary<?= !$super && $sd['none'] ? ' is-none' : '' ?>" id="scopeSummary">
        <div><h2>Employees this user sees</h2>
          <p class="muted small">Every employee list, report, entry form and 201 file follows this assignment. Nothing ticked in both panels = the user sees no employee. Ticking companies / branches only limits the user to their employees (all departments); ticking departments only limits the user to those departments (all companies); ticking both = the departments inside the companies.</p></div>
        <div class="scope-now"<?= $super ? ' data-fixed' : '' ?>><?= $super ? 'All employees: Super Admin role' : h(scope_summary($user)) ?></div>
      </div>
      <div class="grid grid-2" data-scope-panels>
        <div class="card scope-panel">
          <h2>Company / branch assignment</h2>
          <p class="muted small">The user only sees employees whose employer of record is one of the ticked companies / branches.</p>
          <label class="check scope-all"><input type="checkbox" name="scope_all_branches" value="1" data-scope-all="branches" <?= checked(!empty($user['scope_all_branches'])) ?><?= $locked ? ' disabled' : '' ?>> <strong>All companies / branches</strong> <small class="muted">(including those added later)</small></label>
          <div class="checks scroll-checks" data-scope-list="branches">
            <?php foreach ($branches as $b): ?>
              <label class="check"><input type="checkbox" name="branches[]" value="<?= (int)$b['id'] ?>" <?= checked(in_array((int)$b['id'], $scopes['branch'], true)) ?><?= $locked ? ' disabled' : '' ?>>
                <?= h(trim(($b['company_code'] ?? '') . ' ' . ($b['branch_code'] ?? '')) ?: $b['code']) ?> — <?= h($b['name']) ?></label>
            <?php endforeach; ?>
          </div>
          <?php if (!$locked): ?><div class="scope-tools"><button type="button" class="btn btn-xs" data-scope-tick="branches">Tick all</button><button type="button" class="btn btn-xs" data-scope-clear="branches">Clear</button></div><?php endif; ?>
        </div>
        <div class="card scope-panel">
          <h2>Department assignment</h2>
          <p class="muted small">Limits the user to these departments and everything under them.</p>
          <label class="check scope-all"><input type="checkbox" name="scope_all_units" value="1" data-scope-all="units" <?= checked(!empty($user['scope_all_units'])) ?><?= $locked ? ' disabled' : '' ?>> <strong>All departments</strong> <small class="muted">(including those added later)</small></label>
          <div class="checks scroll-checks" data-scope-list="units">
            <?php foreach ($units as $o): ?>
              <label class="check"><input type="checkbox" name="units[]" value="<?= (int)$o['id'] ?>" <?= checked(in_array((int)$o['id'], $scopes['org_unit'], true)) ?><?= $locked ? ' disabled' : '' ?>>
                <?= h($o['name']) ?> <small class="muted"><?= h(label($o['unit_type'] ?? '')) ?></small></label>
            <?php endforeach; ?>
          </div>
          <?php if (!$locked): ?><div class="scope-tools"><button type="button" class="btn btn-xs" data-scope-tick="units">Tick all</button><button type="button" class="btn btn-xs" data-scope-clear="units">Clear</button></div><?php endif; ?>
        </div>
      </div>

      <?php if (!$locked): ?>
      <div class="toolbar">
        <button class="btn btn-primary">Save access</button>
        <span class="sep"></span>
        <select name="from_user" style="width:240px" aria-label="Copy access from"><?= options(pairs("SELECT id, full_name FROM users WHERE id <> ? AND is_support = 0 AND (reg_status IS NULL OR reg_status = 'approved') ORDER BY full_name", [$userId]), null, 'Copy access from…') ?></select>
        <button class="btn" name="_action" value="copy" data-confirm="Replace this user's role, permissions and assignment with those of the selected user?">Copy access</button>
      </div>
      <?php endif; ?>
    </form>

    <div class="card flush" id="effective">
      <div class="card-head pad"><div><h2>Effective access</h2><div class="sub">What <?= h($user['full_name']) ?> can do right now: <?= h($role['name'] ?? 'no role') ?><?= $role && $role['status'] !== 'active' ? ' (inactive role: it gives nothing)' : '' ?><?= $ovr ? ' with ' . count($ovr) . ' custom override(s)' : ', role defaults' ?>. Saved settings only.</div></div></div>
      <div class="pad-x"><?= perm_effective_html($user) ?></div>
    </div>
  <?php endif; ?>
  </div>
</div>
<?php page_footer();
