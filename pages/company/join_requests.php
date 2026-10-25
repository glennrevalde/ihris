<?php
/**
 * Join Requests (Company Owner): accounts registered with the Company Code wait here as Pending.
 * Approve (role, optional link to an existing employee record) or Reject. Approval never creates an
 * employee record, so HR's masterfile is not duplicated: HR keeps creating employees and the owner
 * links the approved account to one (also possible later in User Accounts).
 */
$co = tenant_company();
if (!$co) {
    throw new RuntimeException('Multi-company is not set up on this installation.');
}
$status = (string)get('status', 'pending');
if (!in_array($status, ['pending', 'rejected', 'approved', 'all'], true)) {
    $status = 'pending';
}

if (is_post()) {
    $id = (int)post('id');
    try {
        $u = row("SELECT * FROM users WHERE id = ? AND is_support = 0 AND reg_status IN ('pending','rejected')", [$id]);
        if (!$u) {
            throw new RuntimeException('This join request was already decided or does not exist.');
        }
        if (post('_action') === 'approve') {
            $roleRow = perm_role((int)post('role_id'));
            if (!$roleRow || $roleRow['status'] !== 'active') {
                throw new RuntimeException('Choose an active role.');
            }
            $role = (string)$roleRow['name'];
            $emp = (int)post('employee_id', 0) ?: null;
            if ($emp) {
                if (!val('SELECT COUNT(*) FROM employees WHERE id = ?', [$emp])) {
                    throw new RuntimeException('Employee record not found.');
                }
                $other = row('SELECT username FROM users WHERE employee_id = ? AND is_active = 1 AND id <> ?', [$emp, $id]);
                if ($other) {
                    throw new RuntimeException('That employee record is already linked to the account "' . $other['username'] . '".');
                }
            }
            update('users', ['is_active' => 1, 'reg_status' => 'approved', 'role_id' => (int)$roleRow['id'], 'role' => $roleRow['base_role'] ?: 'employee', 'employee_id' => $emp, 'reviewed_by' => user_id(), 'reviewed_at' => now(), 'reg_note' => null], 'id = ?', [$id]);
            audit('admin', 'join_request_approved', 'users', $id, ['username' => $u['username'], 'role' => $role, 'employee_id' => $emp]);
            mc_sync_user($id);
            master_audit('join_request_approved', current_company_id(), ['username' => $u['username'], 'role' => $role]);
            if ($u['email']) {
                send_mail($u['email'], $u['full_name'], 'Your account is approved', email_template('Welcome to ' . current_company_name(),
                    '<p>Your request to join ' . h(current_company_name()) . ' was approved. Sign in with your username <strong>' . h($u['username']) . '</strong> and your password.</p>'
                    . '<p>' . email_button(abs_url('login'), 'Sign in') . '</p>'), 'join_request', $id);
            }
            flash('success', $u['full_name'] . ' can now sign in as ' . $u['username'] . '.');
        } elseif (post('_action') === 'reject') {
            $note = trim((string)post('reason', ''));
            update('users', ['is_active' => 0, 'reg_status' => 'rejected', 'reviewed_by' => user_id(), 'reviewed_at' => now(), 'reg_note' => mb_substr($note, 0, 255) ?: null], 'id = ?', [$id]);
            audit('admin', 'join_request_rejected', 'users', $id, ['username' => $u['username'], 'reason' => $note]);
            mc_sync_user($id);
            master_audit('join_request_rejected', current_company_id(), ['username' => $u['username']]);
            if ($u['email']) {
                send_mail($u['email'], $u['full_name'], 'Your join request', email_template('Join request not approved',
                    '<p>Your request to join ' . h(current_company_name()) . ' was not approved.' . ($note !== '' ? ' Reason: ' . h($note) : '') . '</p><p>Please contact your Company Owner.</p>'), 'join_request', $id);
            }
            flash('success', 'Join request of ' . $u['full_name'] . ' rejected.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
        redirect('join_requests', ['id' => $id]);
    }
    redirect('join_requests', ['status' => $status]);
}

$where = $status === 'all' ? "reg_status IS NOT NULL" : 'reg_status = ' . db()->quote($status);
$list = rows("SELECT u.*, e.employee_no, r.full_name AS reviewer FROM users u LEFT JOIN employees e ON e.id = u.employee_id LEFT JOIN users r ON r.id = u.reviewed_by
              WHERE u.is_support = 0 AND u.$where ORDER BY u.registered_at DESC, u.id DESC");
$counts = pairs("SELECT reg_status, COUNT(*) FROM users WHERE reg_status IS NOT NULL GROUP BY reg_status");
$selId = (int)get('id', 0);
$sel = $selId ? row("SELECT * FROM users WHERE id = ? AND is_support = 0 AND reg_status IS NOT NULL", [$selId]) : null;
$matches = [];
if ($sel) {
    // employee records that may be this person (same e-mail, or same last name) and are not linked yet
    $parts = preg_split('/\s+/', trim((string)$sel['full_name']));
    $last = end($parts) ?: '';
    $matches = rows("SELECT e.id, e.employee_no, e.first_name, e.last_name, e.middle_name, e.suffix, e.email, e.position FROM employees e
                     WHERE e.status = 'active' AND (e.email = ? OR e.last_name = ?) AND NOT EXISTS (SELECT 1 FROM users x WHERE x.employee_id = e.id AND x.is_active = 1)
                     ORDER BY (e.email = ?) DESC, e.last_name LIMIT 8", [(string)$sel['email'], $last, (string)$sel['email']]);
}

page_header('Join Requests', [
    'subtitle' => 'Accounts registered with the Company Code of ' . h(current_company_name()) . '. They can sign in once approved.',
    'actions' => '<a class="btn" href="' . url('company_profile') . '">Company Code</a><a class="btn" href="' . url('users') . '">User accounts</a>',
]);
?>
<div class="tabs-nav">
  <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $lbl): ?>
    <a class="tab-link<?= $status === $k ? ' active' : '' ?>" href="<?= url('join_requests', ['status' => $k]) ?>"><?= h($lbl) ?><?php if ($k !== 'all'): ?> <span class="count"><?= (int)($counts[$k] ?? 0) ?></span><?php endif; ?></a>
  <?php endforeach; ?>
</div>

<?php if ($sel): ?>
<div class="card join-review">
  <div class="card-head">
    <div class="person">
      <span class="avatar"><?= h(mb_strtoupper(mb_substr($sel['full_name'], 0, 1))) ?></span>
      <div><div class="person-name"><?= h($sel['full_name']) ?></div>
        <div class="person-meta"><?= h($sel['username']) ?>, <?= h($sel['email']) ?>, registered <?= h(fmt_dt($sel['registered_at'])) ?></div></div>
    </div>
    <a class="btn btn-sm" href="<?= url('join_requests', ['status' => $status]) ?>">Close</a>
  </div>
  <?php if (in_array($sel['reg_status'], ['pending', 'rejected'], true)): ?>
    <?php if ($sel['reg_status'] === 'rejected'): ?><div class="callout warn">Rejected<?= $sel['reg_note'] ? ': ' . h($sel['reg_note']) : '' ?>. You can still approve it.</div><?php endif; ?>
    <div class="grid grid-2">
      <form method="post" class="join-approve">
        <?= csrf_field() ?><input type="hidden" name="_action" value="approve"><input type="hidden" name="id" value="<?= (int)$sel['id'] ?>">
        <h3>Approve</h3>
        <div class="field"><label>Role</label><select name="role_id"><?= options(role_options(), (int)(perm_role_by_code('employee')['id'] ?? 0)) ?></select></div>
        <div class="field"><label>Link to employee record (self-service, payslips, approvals)</label>
          <select name="employee_id"><?= options(employee_options(true, 'id NOT IN (SELECT employee_id FROM users WHERE employee_id IS NOT NULL AND is_active = 1)'), $matches && $matches[0]['email'] === $sel['email'] ? $matches[0]['id'] : null, '— link later —') ?></select></div>
        <?php if ($matches): ?>
          <p class="muted small">Possible matches: <?= h(implode('; ', array_map(fn($m) => full_name($m) . ' (' . $m['employee_no'] . ($m['email'] ? ', ' . $m['email'] : '') . ')', $matches))) ?></p>
        <?php else: ?>
          <p class="muted small">No unlinked employee record matches this person. HR can create the employee record first, then you link it here or in User Accounts.</p>
        <?php endif; ?>
        <button class="btn btn-primary">Approve account</button>
      </form>
      <?php if ($sel['reg_status'] === 'pending'): ?>
      <form method="post" class="join-reject" data-confirm="Reject this join request?">
        <?= csrf_field() ?><input type="hidden" name="_action" value="reject"><input type="hidden" name="id" value="<?= (int)$sel['id'] ?>">
        <h3>Reject</h3>
        <div class="field"><label>Reason (sent to the person)</label><textarea name="reason" rows="3" maxlength="255"></textarea></div>
        <button class="btn btn-danger-outline">Reject request</button>
      </form>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <p class="muted">Approved <?= h(fmt_dt($sel['reviewed_at'])) ?>. Manage the account in <a href="<?= url('users', ['edit' => $sel['id']]) ?>">User Accounts</a>.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card flush">
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Name</th><th>Username</th><th>E-mail</th><th>Registered</th><th>Status</th><th>Decided</th><th class="actions"></th></tr></thead>
      <tbody>
      <?php foreach ($list as $r): ?>
        <tr<?= (int)$r['id'] === $selId ? ' class="row-selected"' : '' ?>>
          <td><strong><?= h($r['full_name']) ?></strong><?= $r['employee_no'] ? '<br><small class="muted">' . h($r['employee_no']) . '</small>' : '' ?></td>
          <td><?= h($r['username']) ?></td>
          <td class="small"><?= h($r['email']) ?></td>
          <td class="small"><?= h(fmt_dt($r['registered_at'])) ?></td>
          <td><?= badge($r['reg_status'], ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'][$r['reg_status']] ?? $r['reg_status']) ?></td>
          <td class="small"><?= $r['reviewed_at'] ? h(fmt_dt($r['reviewed_at'])) . ($r['reviewer'] ? '<br><span class="muted">' . h($r['reviewer']) . '</span>' : '') : '' ?></td>
          <td class="actions"><a class="btn btn-sm<?= $r['reg_status'] === 'pending' ? ' btn-primary' : '' ?>" href="<?= url('join_requests', ['status' => $status, 'id' => $r['id']]) ?>"><?= $r['reg_status'] === 'pending' ? 'Review' : 'View' ?></a></td>
        </tr>
      <?php endforeach; ?>
      <?= $list ? '' : empty_row(7, $status === 'pending' ? 'No join request is waiting.' : 'No join request.') ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_footer();
