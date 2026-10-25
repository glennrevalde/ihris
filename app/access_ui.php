<?php
/**
 * Screens of the access-control system: the permission grid (roles: tick; users: Inherit / Allow / Deny),
 * the Effective Access preview, and the saving rules shared by Role Management, User Accounts and
 * User Access & Permissions (validation, Super Admin protection, audit).
 */

/** Actions used by at least one page, in catalog order (the columns of a grid). */
function perm_grid_columns(): array
{
    $used = [];
    foreach (perm_pages() as $p) {
        foreach ($p['actions'] as $a) {
            $used[$a] = true;
        }
    }
    return array_values(array_filter(array_keys(PERM_ACTIONS), fn($a) => isset($used[$a])));
}

/** Pages grouped by module: module => [label, pages]. */
function perm_pages_by_module(): array
{
    $out = [];
    foreach (perm_pages() as $route => $p) {
        $out[$p['module']]['label'] = $p['module_label'];
        $out[$p['module']]['pages'][$route] = $p;
    }
    return $out;
}

/**
 * Permission grid.
 *   mode 'role': a checkbox per permission (name perm[]), $checked = granted keys
 *   mode 'user': a three-state switch per permission (name ovr[key] = '' inherit | allow | deny),
 *                $roleKeys = what the role gives, $overrides = the user's overrides
 * $roles (user mode): role id => granted keys, so the grid follows a role chosen on the same form.
 */
function perm_grid(string $mode, array $checked = [], array $overrides = [], bool $readonly = false, array $roles = [], string $id = 'permGrid'): string
{
    $cols = perm_grid_columns();
    $set = array_flip($checked);
    $h = '<div class="perm-tools"><input type="search" class="perm-find" data-perm-find="' . h($id) . '" placeholder="Find a page…" aria-label="Find a page">'
        . '<label class="check small"><input type="checkbox" data-perm-onlyon="' . h($id) . '"> Only pages with access</label>';
    if ($mode === 'user') {
        $h .= '<span class="perm-legend"><span class="tri st-inherit on" aria-hidden="true">✓</span> Inherited from role'
            . '<span class="tri st-allow" aria-hidden="true">✓</span> Custom allow<span class="tri st-deny" aria-hidden="true">✕</span> Custom deny</span>';
    }
    $h .= '</div><div class="table-wrap table-scroll perm-wrap"><table class="table table-compact perm-grid' . ($readonly ? ' is-readonly' : '') . '" id="' . h($id) . '" data-mode="' . $mode . '"'
        . ($roles ? ' data-roles="' . h(json_encode($roles)) . '"' : '') . '>';
    $h .= '<thead><tr><th class="pg-page">Page</th>';
    foreach ($cols as $a) {
        $h .= '<th class="chk">' . h(PERM_ACTIONS[$a]) . '</th>';
    }
    $h .= '</tr></thead><tbody>';
    foreach (perm_pages_by_module() as $mod => $m) {
        $h .= '<tr class="pg-mod" data-mod="' . h($mod) . '"><td><strong>' . h($m['label']) . '</strong></td>';
        foreach ($cols as $a) {
            $has = (bool)array_filter($m['pages'], fn($p) => in_array($a, $p['actions'], true));
            if (!$has) {
                $h .= '<td></td>';
            } elseif ($mode === 'role') {
                $h .= '<td class="chk"><input type="checkbox" data-mod-all="' . h($mod) . '" data-a="' . $a . '"' . ($readonly ? ' disabled' : '')
                    . ' aria-label="' . h(PERM_ACTIONS[$a] . ', every page of ' . $m['label']) . '"></td>';
            } else {
                $h .= '<td class="chk"><button type="button" class="tri tri-mod" data-mod-all="' . h($mod) . '" data-a="' . $a . '"' . ($readonly ? ' disabled' : '')
                    . ' title="' . h(PERM_ACTIONS[$a] . ' for every page of ' . $m['label'] . ': click to cycle Inherit, Allow, Deny') . '">•</button></td>';
            }
        }
        $h .= '</tr>';
        foreach ($m['pages'] as $route => $p) {
            $h .= '<tr class="pg-row" data-in="' . h($mod) . '" data-page="' . h($route) . '"><td class="pg-page">' . h($p['label']) . ' <small class="muted">' . h($route) . '</small></td>';
            foreach ($cols as $a) {
                if (!in_array($a, $p['actions'], true)) {
                    $h .= '<td class="na"></td>';
                    continue;
                }
                $k = "$route.$a";
                $lbl = h(PERM_ACTIONS[$a] . ' — ' . $p['label']);
                if ($mode === 'role') {
                    $h .= '<td class="chk"><input type="checkbox" name="perm[]" value="' . h($k) . '" data-a="' . $a . '"' . (isset($set[$k]) ? ' checked' : '')
                        . ($readonly ? ' disabled' : '') . ' aria-label="' . $lbl . '" title="' . h($k) . '"></td>';
                } else {
                    $st = $overrides[$k] ?? 'inherit';
                    $h .= '<td class="chk"><button type="button" class="tri st-' . $st . (isset($set[$k]) ? ' on' : '') . '" data-k="' . h($k) . '" data-a="' . $a . '"'
                        . ($readonly ? ' disabled' : '') . ' aria-label="' . $lbl . '" title="' . h($k) . '"></button>'
                        . '<input type="hidden" name="ovr[' . h($k) . ']" value="' . ($st === 'inherit' ? '' : $st) . '"></td>';
                }
            }
            $h .= '</tr>';
        }
    }
    return $h . '</tbody></table></div>';
}

/**
 * Effective access of a user, page by page: action => [allowed, source]. Source: role, allow (custom allow),
 * deny (custom deny), none (not given), noview (given but the page itself cannot be opened), super.
 */
function perm_effective_matrix(array $u): array
{
    $eff = perm_effective($u);
    $ovr = is_super_user($u) ? [] : perm_user_overrides((int)$u['id']);
    $super = is_super_user($u);
    $out = [];
    foreach (perm_pages() as $route => $p) {
        $viewOk = isset($eff["$route.view"]);
        foreach ($p['actions'] as $a) {
            $k = "$route.$a";
            $ok = isset($eff[$k]) && ($a === 'view' || $viewOk);
            $src = $super ? 'super' : (($ovr[$k] ?? '') === 'deny' ? 'deny' : (($ovr[$k] ?? '') === 'allow' ? 'allow' : (isset($eff[$k]) ? 'role' : 'none')));
            if (isset($eff[$k]) && !$ok) {
                $src = 'noview';
            }
            $out[$route][$a] = [$ok, $src];
        }
    }
    return $out;
}

/** Effective Access section (read only): what the user can do right now, after role + overrides. */
function perm_effective_html(array $u, string $id = 'effGrid'): string
{
    $mx = perm_effective_matrix($u);
    $cols = perm_grid_columns();
    $srcLbl = ['role' => 'from role', 'allow' => 'custom allow', 'deny' => 'custom deny', 'none' => '', 'noview' => 'needs View', 'super' => 'Super Admin'];
    $allowed = 0;
    $total = 0;
    $h = '<div class="perm-tools"><input type="search" class="perm-find" data-perm-find="' . h($id) . '" placeholder="Find a page…" aria-label="Find a page">'
        . '<label class="check small"><input type="checkbox" data-perm-onlyon="' . h($id) . '" checked> Only pages with access</label></div>';
    $h .= '<div class="table-wrap table-scroll perm-wrap"><table class="table table-compact perm-grid eff-grid" id="' . h($id) . '"><thead><tr><th class="pg-page">Page</th>';
    foreach ($cols as $a) {
        $h .= '<th class="chk">' . h(PERM_ACTIONS[$a]) . '</th>';
    }
    $h .= '</tr></thead><tbody>';
    foreach (perm_pages_by_module() as $mod => $m) {
        $h .= '<tr class="pg-mod" data-mod="' . h($mod) . '"><td colspan="' . (count($cols) + 1) . '"><strong>' . h($m['label']) . '</strong></td></tr>';
        foreach ($m['pages'] as $route => $p) {
            $any = false;
            $cells = '';
            foreach ($cols as $a) {
                if (!isset($mx[$route][$a])) {
                    $cells .= '<td class="na"></td>';
                    continue;
                }
                [$ok, $src] = $mx[$route][$a];
                $total++;
                if ($ok) {
                    $allowed++;
                    $any = true;
                }
                $cells .= '<td class="chk eff ' . ($ok ? 'eff-yes' : 'eff-no') . ' src-' . $src . '" title="' . h("$route.$a") . '"><span>' . ($ok ? 'Allowed' : 'Denied') . '</span>'
                    . ($srcLbl[$src] !== '' ? '<small>' . h($srcLbl[$src]) . '</small>' : '') . '</td>';
            }
            $h .= '<tr class="pg-row' . ($any ? ' has-on' : '') . '" data-in="' . h($mod) . '" data-page="' . h($route) . '"><td class="pg-page">' . h($p['label']) . ' <small class="muted">' . h($route) . '</small></td>' . $cells . '</tr>';
        }
    }
    $h .= '</tbody></table></div>';
    return '<p class="muted small eff-sum">' . $allowed . ' of ' . $total . ' permissions allowed. An action is allowed only with View of its page.</p>' . $h;
}

/** Keys granted by each active role (for a role dropdown driving a user grid). */
function perm_roles_keys_map(): array
{
    $out = [];
    foreach (perm_roles() as $id => $r) {
        $out[$id] = perm_role_keys((int)$id);
    }
    return $out;
}

/**
 * Save a role's permissions (only catalog keys; the Super Admin role is never stored). Returns [added, removed].
 */
function perm_save_role_permissions(int $roleId, array $keys): array
{
    $cat = perm_catalog();
    $keys = array_values(array_unique(array_filter(array_map('strval', $keys), fn($k) => isset($cat[$k]))));
    $old = q('SELECT perm_key FROM role_permissions WHERE role_id = ?', [$roleId])->fetchAll(PDO::FETCH_COLUMN);
    $add = array_values(array_diff($keys, $old));
    $del = array_values(array_diff($old, $keys));
    tx(function () use ($roleId, $add, $del) {
        foreach ($add as $k) {
            q('INSERT IGNORE INTO role_permissions (role_id, perm_key) VALUES (?, ?)', [$roleId, $k]);
        }
        if ($del) {
            q('DELETE FROM role_permissions WHERE role_id = ? AND perm_key IN (' . in_list($del) . ')', array_merge([$roleId], $del));
        }
    });
    perm_invalidate();
    return [$add, $del];
}

/**
 * Save a user's overrides from a posted ovr[key] = '' | allow | deny map (anything else is ignored).
 * An override equal to what the role already gives is dropped (it would change nothing).
 * Returns [added, changed, removed] for the audit trail.
 */
function perm_save_user_overrides(int $userId, array $posted, ?int $roleId): array
{
    $cat = perm_catalog();
    $roleKeys = array_flip($roleId ? perm_role_keys($roleId) : []);
    $want = [];
    foreach ($posted as $k => $v) {
        $k = (string)$k;
        if (!isset($cat[$k]) || !in_array($v, ['allow', 'deny'], true)) {
            continue;
        }
        if (($v === 'allow' && isset($roleKeys[$k])) || ($v === 'deny' && !isset($roleKeys[$k]))) {
            continue;   // same as the role: inherit
        }
        $want[$k] = $v;
    }
    $old = pairs('SELECT perm_key, effect FROM user_permission_overrides WHERE user_id = ?', [$userId]);
    $added = $changed = $removed = [];
    foreach ($want as $k => $v) {
        if (!isset($old[$k])) {
            $added[$k] = $v;
        } elseif ($old[$k] !== $v) {
            $changed[$k] = [$old[$k], $v];
        }
    }
    foreach ($old as $k => $v) {
        if (!isset($want[$k])) {
            $removed[$k] = $v;
        }
    }
    tx(function () use ($userId, $want, $added, $changed, $removed) {
        foreach ($added as $k => $v) {
            insert('user_permission_overrides', ['user_id' => $userId, 'perm_key' => $k, 'effect' => $v, 'created_at' => now(), 'created_by' => user_id()]);
        }
        foreach ($changed as $k => $v) {
            update('user_permission_overrides', ['effect' => $v[1], 'created_at' => now(), 'created_by' => user_id()], 'user_id = ? AND perm_key = ?', [$userId, $k]);
        }
        if ($removed) {
            q('DELETE FROM user_permission_overrides WHERE user_id = ? AND perm_key IN (' . in_list(array_keys($removed)) . ')', array_merge([$userId], array_keys($removed)));
        }
    });
    if ($added) {
        audit('admin', 'user_override_added', 'users', $userId, $added);
    }
    if ($changed) {
        audit('admin', 'user_override_changed', 'users', $userId, $changed);
    }
    if ($removed) {
        audit('admin', 'user_override_removed', 'users', $userId, $removed);
    }
    perm_invalidate();
    return [$added, $changed, $removed];
}

/** Remove every override of a user (Reset to Role Defaults). */
function perm_reset_user(int $userId): int
{
    $old = pairs('SELECT perm_key, effect FROM user_permission_overrides WHERE user_id = ?', [$userId]);
    q('DELETE FROM user_permission_overrides WHERE user_id = ?', [$userId]);
    if ($old) {
        audit('admin', 'user_override_removed', 'users', $userId, $old + ['reason' => 'Reset to role defaults']);
    }
    perm_invalidate();
    return count($old);
}

/**
 * Change a user's role and status with the Super Admin protections. Throws RuntimeException with the reason.
 *   - nobody changes their own role, status or permissions
 *   - the Company Owner keeps the Super Admin role and stays active (a platform Super Admin assigns another owner)
 *   - the last active Super Admin cannot lose the role or be deactivated
 *   - only active roles can be assigned
 */
function perm_check_user_change(array $user, int $newRoleId, bool $active): void
{
    $uid = (int)$user['id'];
    $oldRole = perm_user_role($user);
    $newRole = perm_role($newRoleId);
    if (!$newRole) {
        throw new RuntimeException('Choose a valid role.');
    }
    if ($newRole['status'] !== 'active' && (!$oldRole || (int)$oldRole['id'] !== $newRoleId)) {
        throw new RuntimeException('The role "' . $newRole['name'] . '" is inactive and cannot be assigned.');
    }
    $changes = !$oldRole || (int)$oldRole['id'] !== $newRoleId || (bool)$user['is_active'] !== $active;
    if ($uid === (int)user_id() && $changes) {
        throw new RuntimeException('You cannot change your own role or deactivate your own account. Ask another Super Admin.');
    }
    $co = tenant_company();
    if ($co && (int)$co['owner_user_id'] === $uid && ((int)$newRole['is_super'] !== 1 || !$active)) {
        throw new RuntimeException('The Company Owner keeps the System Administrator (Super Admin) role and stays active. A platform Super Admin can assign another owner first.');
    }
    $wasSuper = is_super_user($user) && !empty($user['is_active']);
    if ($wasSuper && ((int)$newRole['is_super'] !== 1 || !$active) && perm_active_super_count($uid) < 1) {
        throw new RuntimeException('At least one active Super Admin must remain. Give another account the System Administrator role first.');
    }
}

/**
 * Apply a posted role / permission form to a user: role_id, permission mode (defaults | custom) and ovr[].
 * $allowSelf=false skips the permission part for one's own account (own permissions cannot be changed).
 * Returns a short summary for the flash message.
 */
function perm_apply_user_access(array $user, array $post): string
{
    $uid = (int)$user['id'];
    $roleId = (int)($post['role_id'] ?? 0) ?: (int)(perm_user_role($user)['id'] ?? 0);
    $oldRole = perm_user_role($user);
    $msg = [];
    if (!$oldRole || (int)$oldRole['id'] !== $roleId) {
        perm_assign_role($uid, $roleId);
        audit('admin', 'user_role_changed', 'users', $uid, ['role' => [$oldRole['name'] ?? '—', perm_role($roleId)['name'] ?? '—']]);
        $msg[] = 'role changed to ' . (perm_role($roleId)['name'] ?? '');
    }
    if ($uid === (int)user_id()) {
        return implode(', ', $msg);   // own permissions are never changed by oneself
    }
    $newRole = perm_role($roleId);
    if ($newRole && (int)$newRole['is_super'] === 1) {
        if (perm_reset_user($uid)) {
            $msg[] = 'overrides removed (the Super Admin role has every permission)';
        }
        return implode(', ', $msg);
    }
    $mode = (string)($post['perm_mode'] ?? 'defaults');
    if ($mode === 'custom') {
        [$a, $c, $r] = perm_save_user_overrides($uid, (array)($post['ovr'] ?? []), $roleId);
        if ($a || $c || $r) {
            $msg[] = count($a) + count($c) . ' override(s) set, ' . count($r) . ' removed';
        }
    } elseif ($n = perm_reset_user($uid)) {
        $msg[] = 'reset to role defaults (' . $n . ' override(s) removed)';
    }
    return implode(', ', $msg);
}
