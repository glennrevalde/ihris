<?php
/**
 * Switch company (top bar). A user linked to another company by a Super Admin moves between the home
 * company and the linked companies without signing in again. In each company the user works with that
 * company's account, role and permissions; nothing of one company is visible in the other.
 */
if (!is_post()) {
    redirect('dashboard');
}
$cid = (int)post('company_id');
if ($cid === (int)current_company_id()) {
    redirect('dashboard');
}
try {
    $co = mc_switch_company($cid);
    flash('success', 'You are now working in ' . $co['name'] . '.');
} catch (RuntimeException $e) {
    flash('error', $e->getMessage());
}
redirect('dashboard');
