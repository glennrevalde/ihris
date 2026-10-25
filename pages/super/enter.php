<?php
/**
 * Enter / leave a company (Super Admin support session). Entering signs the Super Admin into that
 * company under its hidden support account (an administrator of that company, recorded in its own
 * audit trail); the header shows which company is active until "Leave company".
 */
if (!is_post()) {
    redirect('sa_companies');
}
$sa = super_admin();
if (post('_action') === 'leave') {
    $cid = (int)($_SESSION['sa_cid'] ?? 0);
    if ($cid) {
        master_audit('company_left', $cid);
    }
    unset($_SESSION['sa_cid'], $_SESSION['uid'], $_SESSION['cid']);
    tenant_reset();
    redirect($cid ? 'sa_company' : 'sa_dashboard', $cid ? ['id' => $cid] : []);
}
$co = company_by_id((int)post('id'));
if (!$co || !in_array($co['status'], ['active', 'inactive'], true) || $co['db_status'] !== 'ready' || !mc_valid_db_name($co['db_name'])) {
    flash('error', 'This company cannot be entered: its database is not ready.');
    redirect('sa_companies');
}
try {
    $uid = company_support_user($co, $sa);
} catch (Throwable $e) {
    flash('error', 'The database of ' . $co['name'] . ' cannot be opened: ' . $e->getMessage());
    redirect('sa_company', ['id' => $co['id']]);
}
session_regenerate_id(true);
$_SESSION['sa_cid'] = (int)$co['id'];
$_SESSION['uid'] = $uid;
unset($_SESSION['cid']);
tenant_reset();
with_company_db($co['db_name'], fn() => login_audit($co, ['id' => $uid, 'username' => 'support.' . $sa['username']], 'support_session_start', ['super_admin' => $sa['username']]));
master_audit('company_entered', (int)$co['id']);
flash('info', 'You are working inside ' . $co['name'] . '. Everything you open and change now belongs to this company only.');
redirect('dashboard');
