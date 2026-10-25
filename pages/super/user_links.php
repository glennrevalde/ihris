<?php
/**
 * Multi-company Users (Super Admin). Lets a user of one company switch to another company without
 * becoming a Super Admin. The user keeps signing in with the home account; the link creates an account
 * in the other company with a role of that company (never one with every permission), no password of
 * its own and no sign-in of its own. The other company manages that account's role and permissions on
 * its User Accounts page. A link can be deactivated, activated again or removed here.
 */
$homeCid = (int)get('home', 0);
$homeUid = (int)get('user', 0);
$targetCid = (int)get('target', 0);

/** Roles of a company that a linked account may get (active, not the all-permission roles). */
function ul_roles(array $co): array
{
    return with_company_db($co['db_name'], fn() => pairs("SELECT id, name FROM roles WHERE status = 'active' AND is_super = 0 ORDER BY is_system DESC, name"));
}

/** Username for the linked account in the target company (the home username when it is free there). */
function ul_username(array $co, string $base, int $homeCid): string
{
    return with_company_db($co['db_name'], function () use ($base, $homeCid) {
        foreach ([$base, $base . '.' . $homeCid, $base . '.' . $homeCid . 'b', $base . '.' . $homeCid . 'c'] as $u) {
            $u = substr($u, 0, 60);
            if (!(int)val('SELECT COUNT(*) FROM users WHERE username = ?', [$u])) {
                return $u;
            }
        }
        return substr($base, 0, 48) . '.' . bin2hex(random_bytes(3));
    });
}

function ul_company_ok(?array $co): bool
{
    return $co && $co['db_status'] === 'ready' && mc_valid_db_name($co['db_name']) && !in_array($co['status'], ['provisioning', 'failed'], true);
}

if (is_post()) {
    $act = (string)post('_action');
    try {
        if ($act === 'create') {
            $hc = company_by_id((int)post('home_company_id'));
            $tc = company_by_id((int)post('company_id'));
            $mem = $hc ? mrow('SELECT * FROM company_memberships WHERE company_id = ? AND user_id = ?', [(int)$hc['id'], (int)post('home_user_id')]) : null;
            if (!ul_company_ok($hc) || !$mem) {
                throw new RuntimeException('Choose the company and the account that will switch.');
            }
            if ($mem['status'] !== 'active') {
                throw new RuntimeException('The account ' . $mem['username'] . ' is not active in ' . $hc['name'] . '.');
            }
            if (!ul_company_ok($tc) || (int)$tc['id'] === (int)$hc['id']) {
                throw new RuntimeException('Choose another company the account may switch to.');
            }
            $roles = ul_roles($tc);
            $roleId = (int)post('role_id');
            if (!isset($roles[$roleId])) {
                throw new RuntimeException('Choose the role of the account in ' . $tc['name'] . '.');
            }
            $empId = (int)post('employee_id') ?: null;
            $old = mrow('SELECT * FROM company_user_links WHERE home_company_id = ? AND home_user_id = ? AND company_id = ?', [(int)$hc['id'], (int)$mem['user_id'], (int)$tc['id']]);
            $linkedFrom = (int)$hc['id'] . ':' . (int)$mem['user_id'];
            $res = with_company_db($tc['db_name'], function () use ($old, $mem, $roleId, $empId, $linkedFrom, $tc, $hc) {
                db_upgrade();   // the company database may still be on an older version (linked_from column)
                $role = row('SELECT * FROM roles WHERE id = ?', [$roleId]);
                if ($empId && !(int)val('SELECT COUNT(*) FROM employees WHERE id = ?', [$empId])) {
                    $empId = null;
                }
                $existing = $old ? row('SELECT * FROM users WHERE id = ? AND linked_from = ?', [(int)$old['user_id'], $linkedFrom]) : null;
                $data = ['full_name' => $mem['full_name'] ?: $mem['username'], 'email' => $mem['email'], 'role_id' => $roleId, 'role' => $role['base_role'] ?: 'employee',
                    'is_active' => 1, 'must_change_password' => 0, 'employee_id' => $empId];
                if ($existing) {
                    update('users', $data, 'id = ?', [(int)$existing['id']]);
                    $uid = (int)$existing['id'];
                    $uname = $existing['username'];
                } else {
                    $uname = ul_username($tc, (string)$mem['username'], (int)$hc['id']);
                    $uid = insert('users', $data + ['username' => $uname, 'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                        'linked_from' => $linkedFrom, 'is_support' => 0]
                        // a new linked account sees every employee of the company until its assignment is set there (User Access & Permissions)
                        + (mig_column_exists('users', 'scope_all_branches') ? ['scope_all_branches' => 1, 'scope_all_units' => 1] : []));
                }
                login_audit($tc, ['id' => $uid, 'username' => 'platform'], 'linked_account_' . ($existing ? 'update' : 'create'),
                    ['username' => $uname, 'home_company' => $hc['name'], 'home_username' => $mem['username'], 'role' => $role['name']]);
                return [$uid, $uname, $role['name']];
            });
            [$uid, $uname, $roleName] = $res;
            if ($old) {
                mupdate('company_user_links', ['user_id' => $uid, 'status' => 'active', 'username' => $mem['username'], 'updated_at' => now()], 'id = ?', [(int)$old['id']]);
            } else {
                minsert('company_user_links', ['home_company_id' => (int)$hc['id'], 'home_user_id' => (int)$mem['user_id'], 'username' => $mem['username'], 'company_id' => (int)$tc['id'],
                    'user_id' => $uid, 'status' => 'active', 'created_by' => super_admin()['username'], 'created_at' => now(), 'updated_at' => now()]);
            }
            master_audit('user_link_saved', (int)$tc['id'], ['home_company' => (int)$hc['id'], 'username' => $mem['username'], 'linked_account' => $uname, 'role' => $roleName]);
            flash('success', $mem['username'] . ' of ' . $hc['name'] . ' can now switch to ' . $tc['name'] . ' (account ' . $uname . ', role ' . $roleName . '). The company switcher appears in the top bar at the next page.');
            redirect('sa_user_links');
        }
        if (in_array($act, ['deactivate', 'activate', 'remove'], true)) {
            $l = mrow('SELECT * FROM company_user_links WHERE id = ?', [(int)post('id')]);
            if (!$l) {
                throw new RuntimeException('Link not found.');
            }
            $tc = company_by_id((int)$l['company_id']);
            $on = $act === 'activate';
            if (ul_company_ok($tc)) {
                with_company_db($tc['db_name'], function () use ($l, $on, $tc, $act) {
                    q('UPDATE users SET is_active = ? WHERE id = ? AND linked_from IS NOT NULL', [$on ? 1 : 0, (int)$l['user_id']]);
                    login_audit($tc, ['id' => (int)$l['user_id'], 'username' => 'platform'], 'linked_account_' . $act, ['home_username' => $l['username']]);
                });
            }
            if ($act === 'remove') {
                mq('DELETE FROM company_user_links WHERE id = ?', [(int)$l['id']]);
            } else {
                mupdate('company_user_links', ['status' => $on ? 'active' : 'inactive', 'updated_at' => now()], 'id = ?', [(int)$l['id']]);
            }
            master_audit('user_link_' . $act, (int)$l['company_id'], ['home_company' => (int)$l['home_company_id'], 'username' => $l['username']]);
            flash('success', $act === 'remove' ? 'Link removed. The account in ' . ($tc['name'] ?? 'the company') . ' was deactivated and kept for its history.' : ($on ? 'Link activated.' : 'Link deactivated: the user can no longer switch to ' . ($tc['name'] ?? 'that company') . '.'));
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
        redirect('sa_user_links', array_filter(['home' => (int)post('home_company_id'), 'user' => (int)post('home_user_id'), 'target' => (int)post('company_id')]));
    } catch (PDOException $e) {
        flash('error', 'The company database could not be updated: ' . $e->getMessage());
    }
    redirect('sa_user_links');
}

$companies = mpairs("SELECT id, name FROM companies WHERE db_status = 'ready' AND status NOT IN ('provisioning','failed') ORDER BY is_primary DESC, name");
$hc = $homeCid && isset($companies[$homeCid]) ? company_by_id($homeCid) : null;
$members = $hc ? mpairs("SELECT user_id, CONCAT(username, ' — ', COALESCE(full_name, '')) FROM company_memberships WHERE company_id = ? AND status = 'active' ORDER BY username", [$homeCid]) : [];
$mem = $hc && $homeUid && isset($members[$homeUid]) ? mrow('SELECT * FROM company_memberships WHERE company_id = ? AND user_id = ?', [$homeCid, $homeUid]) : null;
$tc = $targetCid && $targetCid !== $homeCid && isset($companies[$targetCid]) ? company_by_id($targetCid) : null;
$roles = [];
$emps = [];
$tErr = null;
if ($tc) {
    try {
        $roles = ul_roles($tc);
        $emps = with_company_db($tc['db_name'], fn() => pairs("SELECT id, CONCAT(last_name, ', ', first_name, ' — ', employee_no) FROM employees WHERE status = 'active' ORDER BY last_name, first_name"));
    } catch (Throwable $e) {
        $tErr = 'The database of ' . $tc['name'] . ' cannot be opened: ' . $e->getMessage();
    }
}
$links = mrows('SELECT l.*, hc.name AS home_name, tc.name AS company_name, tc.status AS company_status FROM company_user_links l
                LEFT JOIN companies hc ON hc.id = l.home_company_id LEFT JOIN companies tc ON tc.id = l.company_id
                ORDER BY l.username, tc.name');
// role and account name of each link, read from the company that owns the linked account
$byCo = [];
foreach ($links as $l) {
    $byCo[(int)$l['company_id']][] = (int)$l['user_id'];
}
$acct = [];
foreach ($byCo as $cid => $ids) {
    $c = company_by_id($cid);
    if (!ul_company_ok($c)) {
        continue;
    }
    try {
        foreach (with_company_db($c['db_name'], fn() => rows('SELECT u.id, u.username, u.is_active, u.last_login, r.name AS role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id IN (' . implode(',', array_map('intval', $ids)) . ')')) as $u) {
            $acct[$cid . ':' . $u['id']] = $u;
        }
    } catch (Throwable $e) {
    }
}
page_header('Multi-company Users', [
    'subtitle' => 'Users who switch between companies with one sign-in, without being a Super Admin. In each company they work with that company\'s role and permissions only.',
]);
?>
  <div class="card">
    <div class="card-head"><div><h2>Give a user access to another company</h2><div class="sub">The user keeps the same sign-in. Choose the account, the other company, then the role it gets there.</div></div></div>
    <form method="get" class="form-grid cols-3 link-steps">
      <input type="hidden" name="p" value="sa_user_links">
      <div class="field"><label>Home company</label><select name="home" data-autosubmit><?= options($companies, $hc ? $homeCid : null, 'Choose the company') ?></select></div>
      <?php if ($hc): ?>
      <div class="field"><label>Account that signs in</label><select name="user" data-autosubmit><?= options($members, $mem ? $homeUid : null, $members ? 'Choose the account' : 'No active account') ?></select></div>
      <?php endif; ?>
      <?php if ($mem): ?>
      <div class="field"><label>May switch to</label><select name="target" data-autosubmit><?= options(array_diff_key($companies, [$homeCid => 1]), $tc ? $targetCid : null, 'Choose the other company') ?></select></div>
      <?php endif; ?>
      <noscript><div class="field wide"><button class="btn">Next</button></div></noscript>
    </form>
    <?php if ($tErr): ?><div class="callout warn"><?= h($tErr) ?></div><?php endif; ?>
    <?php if ($mem && $tc && !$tErr): ?>
    <form method="post" class="link-form">
      <?= csrf_field() ?><input type="hidden" name="_action" value="create">
      <input type="hidden" name="home_company_id" value="<?= (int)$homeCid ?>"><input type="hidden" name="home_user_id" value="<?= (int)$homeUid ?>"><input type="hidden" name="company_id" value="<?= (int)$targetCid ?>">
      <div class="form-grid cols-2">
        <div class="field"><label>Role in <?= h($tc['name']) ?></label><select name="role_id" required><?= options($roles, null, 'Choose the role') ?></select>
          <span class="help">Roles with every permission are not offered. <?= h($tc['name']) ?> can change the role and permissions later on its User Accounts page.</span></div>
        <div class="field"><label>Employee record in <?= h($tc['name']) ?> (optional)</label><select name="employee_id"><?= options($emps, null, '— none —') ?></select>
          <span class="help">For self-service and approvals in that company.</span></div>
      </div>
      <p class="link-summary"><strong><?= h($mem['full_name'] ?: $mem['username']) ?></strong> keeps signing in as <strong><?= h($mem['username']) ?></strong> of <?= h($hc['name']) ?> and switches to <?= h($tc['name']) ?> in the top bar.</p>
      <div class="form-actions"><button class="btn btn-primary">Give access</button></div>
    </form>
    <?php endif; ?>
  </div>

  <div class="card flush">
    <div class="card-head pad"><div><h2>Linked accounts</h2><div class="sub"><?= count($links) ?> link(s). The company switcher appears in the top bar of these users.</div></div></div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Signs in as</th><th>Can switch to</th><th>Account there</th><th>Role there</th><th>Status</th><th class="actions"></th></tr></thead>
        <tbody>
        <?php foreach ($links as $l): $a = $acct[$l['company_id'] . ':' . $l['user_id']] ?? null; $act = $l['status'] === 'active' && $a && $a['is_active']; ?>
          <tr class="<?= $act ? '' : 'row-muted' ?>">
            <td><strong><?= h($l['username']) ?></strong><br><small class="muted"><?= h($l['home_name'] ?: 'Company #' . $l['home_company_id']) ?></small></td>
            <td><?= h($l['company_name'] ?: 'Company #' . $l['company_id']) ?><?= $l['company_status'] && $l['company_status'] !== 'active' ? '<br><small class="muted">company ' . h(strtolower(COMPANY_STATUSES[$l['company_status']] ?? $l['company_status'])) . '</small>' : '' ?></td>
            <td><?= h($a['username'] ?? '—') ?><?= $a && $a['last_login'] ? '<br><small class="muted">last used ' . h(fmt_dt($a['last_login'])) . '</small>' : '' ?></td>
            <td><?= h($a['role_name'] ?? '—') ?></td>
            <td><?= $l['status'] !== 'active' ? badge('stopped', 'Inactive') : ($a && !$a['is_active'] ? badge('stopped', 'Account inactive') : badge('active')) ?></td>
            <td class="actions">
              <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                <?php if ($l['status'] === 'active'): ?><button class="btn btn-sm" name="_action" value="deactivate">Deactivate</button>
                <?php else: ?><button class="btn btn-sm" name="_action" value="activate">Activate</button><?php endif; ?>
                <button class="btn btn-sm btn-danger-outline" name="_action" value="remove" data-confirm="Remove this link? The account in <?= h($l['company_name']) ?> is deactivated and kept for its history.">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?= $links ? '' : empty_row(6, 'No user switches between companies yet.') ?>
        </tbody>
      </table>
    </div>
  </div>
<?php page_footer();
