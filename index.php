<?php
/**
 * G-SMaRT HRIS front controller.
 * Every page is served through index.php?p=<route>. Routes name the permission page that governs them
 * (app/routes.php); the request is authorized here, on the server, from the user's effective
 * permissions (role + user overrides, app/permissions.php) before the page runs.
 *
 * Setup: import database/ihris_mysql55.sql into MySQL, check the credentials in
 * config/config.php, then open this file in the browser. There is no installer.
 */
require __DIR__ . '/app/bootstrap.php';

// Multi-company: the session decides the company (and so the database) of this request; a company
// that was deactivated signs its users out here. See app/tenancy.php.
tenant_guard();

// Make sure the database is reachable and has been imported; otherwise explain what to do.
try {
    db()->query('SELECT 1 FROM users LIMIT 1');
} catch (PDOException $e) {
    $co = tenant_company();
    if ($co && empty($co['is_primary']) && (!empty($_SESSION['uid']) || !empty($_SESSION['sa_cid']))) {
        // a company database that cannot be reached: never fall back to another company's data
        if (!empty($_SESSION['sa'])) {
            unset($_SESSION['sa_cid'], $_SESSION['uid']);
            tenant_reset();
            flash('error', 'The database of ' . $co['name'] . ' cannot be reached.');
            redirect('sa_company', ['id' => $co['id']]);
        }
        tenant_signout('Your company\'s database cannot be reached right now. Please try again later or contact your Company Owner.');
    }
    require APP_ROOT . '/app/setup_help.php';
    exit;
}

// Bring the database up to the version this code expects (idempotent, MySQL 5.5 compatible).
try {
    db_upgrade();
    perm_sync_if_needed();
} catch (Throwable $e) {
    if (!empty($GLOBALS['config']['debug'])) {
        throw $e;
    }
}

$routes = require APP_ROOT . '/app/routes.php';
$page = preg_replace('/[^a-z0-9_]/', '', (string)($_GET['p'] ?? ''));
if ($page === '') {
    $page = current_user() ? 'dashboard' : (is_super_mode() ? 'sa_dashboard' : 'login');
}
if (!isset($routes[$page])) {
    http_response_code(404);
    require_login();
    page_header('Page not found');
    echo '<div class="card"><p>The page you requested does not exist.</p></div>';
    page_footer();
    exit;
}
[$file, $access] = $routes[$page];

if ($access === 'super') {
    // platform pages: Super Admin only (also while inside a company)
    if (!super_admin()) {
        require_login();
        http_response_code(403);
        page_header('Access denied');
        echo '<div class="card"><h2>Access denied</h2><p>This page is for the platform Super Admin.</p></div>';
        page_footer();
        exit;
    }
    if (super_admin()['must_change_password'] && !in_array($page, ['sa_account', 'logout'], true)) {
        flash('warning', 'Please change your temporary password before continuing.');
        redirect('sa_account');
    }
} elseif ($access !== 'public' && !($page === 'logout' && super_admin())) {
    // (signing out works for a Super Admin on the platform pages too, who has no company account)
    if (is_super_mode()) {
        // the Super Admin enters a company before opening its screens
        redirect('sa_dashboard');
    }
    require_login();
    $u = current_user();
    if ($u['must_change_password'] && !in_array($page, ['account', 'logout'], true)) {
        flash('warning', 'Please change your temporary password before continuing.');
        redirect('account');
    }
    if ($access === 'owner' && !owner_or_support()) {
        perm_deny();
    }
    if ($access === 'admin') {
        require_super_user();
    }
}
if (is_post() && $page !== 'login' && $page !== 'bio_push') {   // bio_push: the office PC agent signs with its device token
    verify_csrf();
}

// the permission of what this request does (view, create, edit, delete, approve, export, print ...),
// for pages, form submissions, exports, prints and AJAX requests alike
if ($access !== 'public' && $access !== 'super' && current_user()) {
    perm_authorize($page);
    // an employee named by the request must be inside the user's company / branch and department assignment
    scope_authorize($page);
    // links, buttons and forms the user may not use are left out of the page (convenience; the gate above is the security)
    ob_start('perm_ui_filter');
}

try {
    require APP_ROOT . '/pages/' . $file;
} catch (RuntimeException $e) {
    flash('error', $e->getMessage());
    redirect_back($page === 'dashboard' ? 'login' : 'dashboard');
}
