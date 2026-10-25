<?php
/**
 * Role Management (Super Admin only): create, edit, deactivate and delete roles, choose page by page and
 * action by action what a role may do, and move the users of a role to another role.
 *
 * Rules: the System Administrator role is the protected Super Admin role (every permission, never edited,
 * deactivated or deleted); built-in roles can be edited and deactivated but not deleted; a role that still
 * has users cannot be deleted or deactivated: reassign its users first. Every change is in the audit trail.
 */
require_super_user();

$editId = (int)get('edit', 0);
$isNew = (bool)get('new');

if (is_post()) {
    $act = (string)post('_action');
    $id = (int)post('id', 0);
    try {
        $role = $id ? perm_role($id) : null;
        if ($id && !$role) {
            throw new RuntimeException('Role not found.');
        }
        if ($act === 'save') {
            $name = trim(preg_replace('/\s+/', ' ', (string)post('name')));
            $desc = trim((string)post('description'));
            $status = post('status') === 'inactive' ? 'inactive' : 'active';
            if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
                throw new RuntimeException('The role name must have 2 to 80 characters.');
            }
            if (mb_strlen($desc) > 255) {
                throw new RuntimeException('The description is limited to 255 characters.');
            }
            foreach (perm_roles() as $r) {
                if ((int)$r['id'] !== $id && mb_strtolower($r['name']) === mb_strtolower($name)) {
                    throw new RuntimeException('A role named "' . $r['name'] . '" already exists.');
                }
            }
            if ($role && (int)$role['is_super'] === 1) {
                throw new RuntimeException('The System Administrator role is the protected Super Admin role. It always has every permission and cannot be changed.');
            }
            if ($role && $status === 'inactive' && $role['status'] === 'active') {
                $n = (int)val('SELECT COUNT(*) FROM users WHERE role_id = ? AND is_active = 1', [$id]);
                if ($n) {
                    throw new RuntimeException("$n active user(s) have this role. Reassign them to another role before deactivating it.");
                }
            }
            $keys = (array)post('perm', []);
            if (!$role) {
                $code = substr(preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), 0, 30) ?: 'role';
                $base = $code;
                $i = 1;
                while (perm_role_by_code($code)) {
                    $code = substr($base, 0, 26) . '_' . (++$i);
                }
                $id = insert('roles', ['code' => trim($code, '_') ?: 'role_' . time(), 'name' => $name, 'description' => $desc ?: null, 'status' => $status,
                    'is_system' => 0, 'is_super' => 0, 'base_role' => 'employee', 'created_at' => now(), 'updated_at' => now(), 'created_by' => user_id(), 'updated_by' => user_id()]);
                perm_invalidate();
                [$add] = perm_save_role_permissions($id, $keys);
                audit('admin', 'role_created', 'roles', $id, ['name' => $name, 'status' => $status, 'permissions' => count($add)]);
                flash('success', 'Role "' . $name . '" created with ' . count($add) . ' permission(s).');
            } else {
                $old = ['name' => $role['name'], 'description' => $role['description'], 'status' => $role['status']];
                $new = ['name' => $name, 'description' => $desc ?: null, 'status' => $status];
                update('roles', $new + ['updated_at' => now(), 'updated_by' => user_id()], 'id = ?', [$id]);
                $d = diff_fields($old, $new);
                if ($d) {
                    audit('admin', $status !== $role['status'] ? ($status === 'inactive' ? 'role_deactivated' : 'role_activated') : 'role_updated', 'roles', $id, $d);
                }
                [$add, $del] = perm_save_role_permissions($id, $keys);
                if ($add || $del) {
                    audit('admin', 'role_permissions_modified', 'roles', $id, ['role' => $name, 'added' => $add, 'removed' => $del]);
                    update('roles', ['updated_at' => now(), 'updated_by' => user_id()], 'id = ?', [$id]);
                }
                flash('success', 'Role "' . $name . '" saved' . ($add || $del ? ': ' . count($add) . ' permission(s) added, ' . count($del) . ' removed.' : '.'));
            }
            perm_invalidate();
            redirect('roles', ['edit' => $id]);
        }
        if (!$role) {
            throw new RuntimeException('Role not found.');
        }
        if ((int)$role['is_super'] === 1) {
            throw new RuntimeException('The System Administrator role is protected.');
        }
        if ($act === 'status') {
            $to = post('to') === 'inactive' ? 'inactive' : 'active';
            if ($to === 'inactive' && ($n = (int)val('SELECT COUNT(*) FROM users WHERE role_id = ? AND is_active = 1', [$id]))) {
                throw new RuntimeException("$n active user(s) have the role \"{$role['name']}\". Reassign them to another role before deactivating it.");
            }
            update('roles', ['status' => $to, 'updated_at' => now(), 'updated_by' => user_id()], 'id = ?', [$id]);
            audit('admin', $to === 'inactive' ? 'role_deactivated' : 'role_activated', 'roles', $id, ['role' => $role['name']]);
            flash('success', 'Role "' . $role['name'] . '" ' . ($to === 'inactive' ? 'deactivated.' : 'activated.'));
        } elseif ($act === 'delete') {
            if ((int)$role['is_system'] === 1) {
                throw new RuntimeException('Built-in roles cannot be deleted; deactivate it instead when no user has it.');
            }
            $n = (int)val('SELECT COUNT(*) FROM users WHERE role_id = ?', [$id]);
            if ($n) {
                throw new RuntimeException("$n user(s) are assigned to the role \"{$role['name']}\". Reassign them to another role first, or deactivate the role to keep it for the record.");
            }
            tx(function () use ($id) {
                q('DELETE FROM role_permissions WHERE role_id = ?', [$id]);
                q('DELETE FROM roles WHERE id = ?', [$id]);
            });
            audit('admin', 'role_deleted', 'roles', $id, ['role' => $role['name'], 'code' => $role['code']]);
            flash('success', 'Role "' . $role['name'] . '" deleted.');
            perm_invalidate();
            redirect('roles');
        } elseif ($act === 'reassign') {
            $to = perm_role((int)post('to_role'));
            if (!$to || $to['status'] !== 'active' || (int)$to['id'] === $id) {
                throw new RuntimeException('Choose another active role to move the users to.');
            }
            $users = rows('SELECT * FROM users WHERE role_id = ?', [$id]);
            $moved = 0;
            foreach ($users as $u) {
                if ((int)$u['id'] === (int)user_id()) {
                    continue;   // own role is changed by another Super Admin
                }
                perm_assign_role((int)$u['id'], (int)$to['id']);
                audit('admin', 'user_role_changed', 'users', $u['id'], ['role' => [$role['name'], $to['name']], 'reason' => 'Role reassignment']);
                $moved++;
            }
            audit('admin', 'role_users_reassigned', 'roles', $id, ['from' => $role['name'], 'to' => $to['name'], 'users' => $moved]);
            flash('success', "$moved user(s) moved from \"{$role['name']}\" to \"{$to['name']}\".");
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
        redirect('roles', $id ? ['edit' => $id] : ($isNew ? ['new' => 1] : []));
    } catch (PDOException $e) {
        flash('error', 'The role could not be saved: ' . ($e->getCode() == 23000 ? 'the name or code is already used.' : $e->getMessage()));
        redirect('roles', $id ? ['edit' => $id] : ['new' => 1]);
    }
    perm_invalidate();
    redirect('roles', $id ? ['edit' => $id] : []);
}

$roles = perm_roles(true);
$counts = [];
foreach (rows("SELECT role_id, SUM(is_active) AS active, COUNT(*) AS total FROM users WHERE is_support = 0 AND (reg_status IS NULL OR reg_status = 'approved') GROUP BY role_id") as $r) {
    $counts[(int)$r['role_id']] = $r;
}
$catCount = count(perm_catalog());
$editing = $editId ? ($roles[$editId] ?? null) : null;
if ($editId && !$editing) {
    flash('error', 'Role not found.');
    redirect('roles');
}

if ($editing || $isNew) {
    $copyFrom = (int)get('copy_from', 0);
    $checked = $editing ? perm_role_keys((int)$editing['id']) : ($copyFrom && isset($roles[$copyFrom]) ? perm_role_keys($copyFrom) : []);
    if ($editing && $copyFrom && isset($roles[$copyFrom])) {
        $checked = perm_role_keys($copyFrom);
    }
    $protected = $editing && (int)$editing['is_super'] === 1;
    $members = $editing ? rows('SELECT u.id, u.username, u.full_name, u.is_active, e.employee_no FROM users u LEFT JOIN employees e ON e.id = u.employee_id
                                 WHERE u.role_id = ? AND u.is_support = 0 ORDER BY u.is_active DESC, u.full_name', [(int)$editing['id']]) : [];
    page_header($editing ? 'Role: ' . $editing['name'] : 'New role', [
        'subtitle' => $protected ? 'The protected Super Admin role. It has every permission of the system.' : 'Choose what this role may do, page by page and action by action. Users of the role inherit it; a user can still be given custom Allow or Deny overrides.',
        'actions' => '<a class="btn" href="' . url('roles') . '">All roles</a><a class="btn" href="' . url('users') . '">User accounts</a>',
    ]);
    ?>
    <form method="post" class="role-form">
      <?= csrf_field() ?><input type="hidden" name="_action" value="save"><input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
      <div class="role-top">
        <div class="card">
          <h2>Role</h2>
          <div class="form-grid">
            <div class="field"><label>Role name</label><input name="name" value="<?= h($editing['name'] ?? '') ?>" maxlength="80" required<?= $protected ? ' readonly' : '' ?>></div>
            <div class="field"><label>Status</label><select name="status"<?= $protected ? ' disabled' : '' ?>><?= options(['active' => 'Active', 'inactive' => 'Inactive'], $editing['status'] ?? 'active') ?></select></div>
            <div class="field wide"><label>Description</label><input name="description" value="<?= h($editing['description'] ?? '') ?>" maxlength="255"<?= $protected ? ' readonly' : '' ?>></div>
          </div>
          <?php if ($editing): ?>
            <dl class="role-facts">
              <div><dt>Key</dt><dd class="mono"><?= h($editing['code']) ?></dd></div>
              <div><dt>Type</dt><dd><?= $protected ? 'Super Admin, protected' : ((int)$editing['is_system'] ? 'Built-in' : 'Custom') ?></dd></div>
              <div><dt>Date created</dt><dd><?= h(fmt_dt($editing['created_at'])) ?></dd></div>
              <div><dt>Date updated</dt><dd><?= h(fmt_dt($editing['updated_at'])) ?></dd></div>
            </dl>
          <?php endif; ?>
        </div>
        <div class="card">
          <h2>Users with this role</h2>
          <?php if (!$editing): ?>
            <p class="muted small">Assign the role to users in User Accounts once it is saved.</p>
          <?php elseif (!$members): ?>
            <p class="muted small">No user has this role yet.</p>
          <?php else: ?>
            <div class="role-members">
              <?php foreach ($members as $m): ?>
                <a href="<?= url('user_access', ['user' => $m['id']]) ?>" class="<?= $m['is_active'] ? '' : 'row-muted' ?>"><strong><?= h($m['full_name']) ?></strong><small><?= h($m['username']) ?><?= $m['employee_no'] ? ', ' . h($m['employee_no']) : '' ?><?= $m['is_active'] ? '' : ', inactive' ?></small></a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card flush">
        <div class="card-head pad">
          <div><h2>Permissions</h2><div class="sub"><?= $protected ? 'Every permission, always.' : 'Tick View to open a page; the other columns allow its actions. The module row ticks a whole column of the module.' ?></div></div>
          <?php if (!$protected): ?>
            <div class="role-copy">
              <select id="copyFrom" aria-label="Copy permissions from"><?= options(array_map(fn($r) => $r['name'], array_filter($roles, fn($r) => (int)$r['id'] !== (int)($editing['id'] ?? 0))), null, 'Start from the permissions of…') ?></select>
              <button type="button" class="btn btn-sm" data-copy-role="<?= h(url('roles', $editing ? ['edit' => $editing['id']] : ['new' => 1])) ?>">Load</button>
            </div>
          <?php endif; ?>
        </div>
        <div class="pad-x">
          <?= perm_grid('role', $protected ? array_keys(perm_catalog()) : $checked, [], $protected, [], 'roleGrid') ?>
        </div>
      </div>
      <?php if (!$protected): ?>
        <div class="toolbar sticky-actions">
          <button class="btn btn-primary">Save role</button>
          <a class="btn" href="<?= url('roles') ?>">Cancel</a>
          <span class="muted small" data-perm-count="roleGrid"></span>
        </div>
      <?php endif; ?>
    </form>

    <?php if ($editing && !$protected): ?>
      <div class="grid grid-2 role-danger">
        <div class="card">
          <h2>Reassign users</h2>
          <p class="muted small">Move every user of this role to another role, for example before deleting or deactivating it.</p>
          <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="_action" value="reassign"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
            <select name="to_role" required><?= options(array_map(fn($r) => $r['name'], array_filter($roles, fn($r) => $r['status'] === 'active' && (int)$r['id'] !== (int)$editing['id'])), null, 'Move to role…') ?></select>
            <button class="btn" data-confirm="Move the <?= count($members) ?> user(s) of this role to the selected role?"<?= $members ? '' : ' disabled' ?>>Move users</button>
          </form>
        </div>
        <div class="card">
          <h2><?= (int)$editing['is_system'] ? 'Deactivate' : 'Deactivate or delete' ?></h2>
          <p class="muted small">A role with users cannot be deactivated or deleted. Deactivating keeps it in the records (and in the audit trail) but it can no longer be assigned.<?= (int)$editing['is_system'] ? ' Built-in roles are never deleted.' : '' ?></p>
          <div class="inline-form">
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="_action" value="status"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
              <input type="hidden" name="to" value="<?= $editing['status'] === 'active' ? 'inactive' : 'active' ?>">
              <button class="btn"><?= $editing['status'] === 'active' ? 'Deactivate role' : 'Activate role' ?></button></form>
            <?php if (!(int)$editing['is_system']): ?>
              <form method="post" class="inline" data-confirm="Delete the role &quot;<?= h($editing['name']) ?>&quot; for good?"><?= csrf_field() ?><input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                <button class="btn btn-danger-outline">Delete role</button></form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>
    <?php
    page_footer();
    return;
}

page_header('Role Management', [
    'subtitle' => 'Roles give their users default permissions. Create any role the organization needs and choose exactly what it may see and do; customize single users in User Access & Permissions.',
    'actions' => '<a class="btn" href="' . url('user_access') . '">User access &amp; permissions</a><a class="btn btn-primary" href="' . url('roles', ['new' => 1]) . '">+ New role</a>',
]);
?>
<div class="card flush">
  <div class="card-head pad"><div><h2>Roles</h2><div class="sub"><?= count($roles) ?> role(s), <?= $catCount ?> permissions in the system</div></div>
    <input type="search" class="table-search" data-target="roleTbl" placeholder="Find a role…" style="max-width:240px"></div>
  <div class="table-wrap">
    <table class="table" id="roleTbl">
      <thead><tr><th>Role</th><th>Description</th><th>Status</th><th class="num">Users</th><th class="num">Permissions</th><th>Date created</th><th>Date updated</th><th class="actions"></th></tr></thead>
      <tbody>
      <?php foreach ($roles as $r):
          $cnt = $counts[(int)$r['id']] ?? ['active' => 0, 'total' => 0];
          $pc = (int)$r['is_super'] ? $catCount : count(perm_role_keys((int)$r['id'])); ?>
        <tr class="<?= $r['status'] === 'active' ? '' : 'row-muted' ?>">
          <td><a href="<?= url('roles', ['edit' => $r['id']]) ?>"><strong><?= h($r['name']) ?></strong></a><br><small class="muted"><?= (int)$r['is_super'] ? 'Super Admin role, protected' : ((int)$r['is_system'] ? 'Built-in' : 'Custom') ?></small></td>
          <td class="small"><?= h($r['description'] ?? '') ?></td>
          <td><?= $r['status'] === 'active' ? '<span class="state-on">Active</span>' : '<span class="state-off">Inactive</span>' ?></td>
          <td class="num"><?= (int)$cnt['active'] ?><?= (int)$cnt['total'] > (int)$cnt['active'] ? ' <small class="muted">+' . ((int)$cnt['total'] - (int)$cnt['active']) . ' inactive</small>' : '' ?></td>
          <td class="num"><?= (int)$r['is_super'] ? 'All' : $pc ?></td>
          <td class="small nowrap"><?= h(fmt_date($r['created_at'])) ?></td>
          <td class="small nowrap"><?= h(fmt_date($r['updated_at'])) ?></td>
          <td class="actions"><a class="btn btn-sm" href="<?= url('roles', ['edit' => $r['id']]) ?>"><?= (int)$r['is_super'] ? 'View' : 'Edit' ?></a>
            <a class="btn btn-sm" href="<?= url('users', ['role' => $r['id']]) ?>">Users</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_footer();
