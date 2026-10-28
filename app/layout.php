<?php
/**
 * Page layout: sidebar navigation, top bar, command palette, footer and print layout.
 */

function icon(string $name): string
{
    $p = [
        'home'     => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>',
        'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
        'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5"/><circle cx="17" cy="9" r="2.5"/><path d="M17 14c2.5 0 5 1.3 5 4"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'money'    => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/>',
        'chart'    => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'shield'   => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
        'cog'      => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.8-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.5 1.7 1.7 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.8 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.5-1.1 1.7 1.7 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.8.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.8V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z"/>',
        'file'     => '<path d="M14 3H6a2 2 0 00-2 2v14a2 2 0 002 2h12a2 2 0 002-2V9z"/><path d="M14 3v6h6"/>',
        'check'    => '<path d="M5 12l5 5 9-10"/>',
        'menu'     => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/>',
        'print'    => '<path d="M6 9V3h12v6M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="7"/>',
        'inbox'    => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5h13L22 12v6a2 2 0 01-2 2H4a2 2 0 01-2-2v-6z"/>',
        'building' => '<rect x="4" y="3" width="16" height="18" rx="1"/><path d="M9 7h2M13 7h2M9 11h2M13 11h2M9 15h2M13 15h2"/>',
        'grid'     => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'upload'   => '<path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2"/>',
        'download' => '<path d="M12 4v12M7 11l5 5 5-5"/><path d="M4 19h16"/>',
        'device'   => '<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 6h4M9 18h6"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
        'list'     => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'refresh'  => '<path d="M20 11a8 8 0 10-2.3 6.1L20 15"/><path d="M20 4v7h-7"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'edit'     => '<path d="M4 20h4L19 9l-4-4L4 16v4z"/><path d="M13.5 6.5l4 4"/>',
        'x'        => '<path d="M6 6l12 12M18 6L6 18"/>',
        'sliders'  => '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
        'bolt'     => '<path d="M13 2L4 14h7l-1 8 9-12h-7z"/>',
        'book'     => '<path d="M4 4.5A2.5 2.5 0 016.5 2H20v18H6.5A2.5 2.5 0 004 22z"/><path d="M4 17.5h16"/>',
        'key'      => '<circle cx="8" cy="15" r="4"/><path d="M10.8 12.2L20 3M16 7l3 3M14 9l2 2"/>',
    ][$name] ?? '<circle cx="12" cy="12" r="3"/>';
    return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

/**
 * Sidebar menu.
 *  ['label' => group caption] renders a caption row,
 *  ['page' => route, ...] a single link, ['section' => ..., 'items' => [[label, route, roles], ...]] a group.
 */
function app_menu(): array
{
    if (is_super_mode()) {
        return super_menu();
    }
    $platform = in_company_support() ? [
        ['label' => 'Platform'],
        ['page' => 'sa_dashboard', 'label' => 'Super Admin', 'icon' => 'shield', 'roles' => 'super'],
        ['page' => 'sa_companies', 'label' => 'Companies', 'icon' => 'building', 'roles' => 'super'],
        ['page' => 'sa_passwords', 'label' => 'Password Resets', 'icon' => 'key', 'roles' => 'super'],
    ] : [];
    return array_merge($platform, company_menu());
}

/**
 * The menu of a company, the same for everybody: what each user sees is decided by their permissions
 * (app/permissions.php), not by this list. Items are [label, route, flag]: no flag = shown when the
 * user's effective permissions allow the page; '*' = every signed-in user; 'owner' = the Company Owner;
 * 'admin' = the Super Admin role only (roles and user permissions). The permission catalog is built from
 * this list, so a page added here gets its permissions automatically.
 */
function company_menu(): array
{
    return [
        ['label' => 'Main'],
        ['page' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'home', 'roles' => '*'],
        ['page' => 'manual', 'label' => 'User Manual', 'icon' => 'book', 'roles' => '*'],
        ['section' => 'My Workspace', 'icon' => 'user', 'ess' => true, 'module' => 'ess', 'items' => [
            ['Time Clock (GPS / Selfie)', 'my_clock'],
            ['My Calendar', 'my_calendar'],
            ['My Daily Time Record', 'my_dtr'],
            ['My Leaves', 'my_leaves'],
            ['My Overtime Requests', 'my_overtime'],
            ['My Payslips', 'my_payslips'],
            ['My Evaluations', 'my_evaluations'],
            ['My Loans & Ledgers', 'my_ledgers'],
            ['Loan / Deduction Request', 'my_loan_requests'],
            ['My 201 Profile', 'my_profile'],
            ['Memos & Announcements', 'my_memos'],
        ]],
        ['section' => 'Approvals', 'icon' => 'check', 'module' => 'approvals', 'items' => [
            ['Pending Approvals', 'approvals'],
            ['Loan / Deduction Approvals', 'loan_approvals'],
            ['Team Evaluations', 'evaluations'],
        ]],

        ['label' => 'Modules'],
        ['section' => 'Personnel (PIS)', 'icon' => 'users', 'module' => 'pis', 'items' => [
            ['Employee Masterfile', 'employees'],
            ['Masterfile Batch Edit', 'employee_batch'],
            ['Import Employees (CSV)', 'employee_import'],
            ['Organization Structure', 'org_units'],
            ['Leave Applications', 'leave_applications'],
            ['Leave Balances & Accrual', 'leave_balances'],
            ['Leave Category Table', 'leave_types'],
            ['Evaluation Cycles', 'eval_cycles'],
            ['Competencies & Merit Matrix', 'competencies'],
            ['Salary Grade Table', 'salary_grades'],
            ['Salary Scaling Simulator', 'salary_scaling'],
            ['Disciplinary Cases', 'cases'],
            ['Code of Conduct & Penalties', 'conduct'],
            ['Memoranda / Announcements', 'memos'],
            ['Memo Templates & Design', 'memo_templates'],
            ['Company Events', 'company_events'],
        ]],
        // v18: the order of the Attendance (AMS) menu follows the user's numbering (setup, schedules, capture, processing, transactions, closing)
        ['section' => 'Attendance (AMS)', 'icon' => 'clock', 'module' => 'ams', 'items' => [
            ['AMS Policies & Configuration', 'ams_policies'],
            ['Biometric Device Setup', 'bio_devices'],
            ['Import Log Configuration', 'import_config'],
            ['Periods & Cutoffs', 'cutoffs'],
            ['Employee Leave Entry', 'att_leave_entry'],
            ['Employee Shift Entry', 'schedules'],
            ['Batch Shift Define', 'batch_shift'],
            ['Web Check-in Verification', 'web_verify'],
            ['Overtime Authorizations', 'ot_requests'],
            ['Import Attendance Logs', 'import_logs'],
            ['Import Review Queue', 'import_review'],
            ['Raw Punch Logs', 'att_logs'],
            ['Attendance Exceptions', 'att_exceptions'],
            ['Process Attendance', 'att_process'],
            ['Attendance Entry (logs)', 'att_entry'],
            ['Excel / CSV Import', 'import_csv'],
            ['Auto-Generate Transactions', 'att_generate'],
            ['Attendance Summary Entry', 'att_summary'],
            ['Attendance Summary Entry II', 'att_summary2'],
            ['Attendance Batch Transaction Entry', 'att_batch'],
            ['Daily Time Records', 'dtr'],
            ['Attendance Transaction Closing', 'att_close'],
        ]],
        ['section' => 'Payroll System (PAY)', 'icon' => 'money', 'module' => 'pay', 'items' => [
            ['Period Parameters', 'periods'],
            ["Employee's Transactions", 'emp_transactions'],
            ['Fixed Transaction Setup', 'fixed_trans'],
            // v16: "Subsidiary Ledgers" and "Employee Deductions & Loans" in one menu (two tabs of the same screen)
            ['Employee Term Loan/Payables', 'ledgers', null, ['emp_deductions']],
            ['Employee Benefits', 'emp_benefits'],
            ['Transaction Payment', 'trans_payments'],
            ['Payroll Transaction', 'pay_trans'],
            ['Payroll Transaction II', 'pay_trans2'],
            ['Payroll Batch Transaction', 'pay_batch'],
            ['Retroactive Adjustments', 'retro'],
            ['YTD Info (Previous Employer)', 'prev_ytd'],
            ['Final Pay', 'final_pay'],
        ]],
        ['section' => 'Process & Registers', 'icon' => 'bolt', 'module' => 'process', 'items' => [
            // v18 order: process, registers, payslips, bank file, inquiry, then the closing
            ['Payroll Runs (Process)', 'payroll_runs'],
            ['Payroll Batch Register', 'pay_register'],
            ['Payroll Batch Register II', 'pay_register2'],
            ['Print Payslips', 'payslips_print'],
            ['Bank File / Payroll ATM', 'bank_file'],
            ['Payroll Inquiry', 'pay_inquiry'],
            ['Period Closing / Payroll Closing', 'period_closing'],
        ]],
        ['section' => 'Reports', 'icon' => 'chart', 'module' => 'reports', 'items' => [
            ['Employee 201 File Masterlisting', 'rpt_201_master'],
            ['Periodical Reports', 'rpt_periodical'],
            ['Monthly Reports', 'rpt_monthly_contrib'],
            ['YTD Reports', 'rpt_ytd_reports'],
            ['Bank Deposit Listing', 'rpt_bank_listing'],
            ['Cash Payment Listing', 'rpt_cash_listing'],
            ['Payroll Register (summary)', 'rpt_register'],
            ['Attendance Summary Report', 'rpt_attendance'],
            ['Earnings & Deductions', 'rpt_earn_ded'],
            ['Year-to-Date Report', 'rpt_ytd'],
            ['Payroll Journal (GL)', 'rpt_journal'],
            ['Loan & Ledger Balances', 'rpt_loans'],
            ['13th Month Report', 'rpt_13th'],
            ['Government Remittances', 'rpt_remittance'],
            ['BIR 2316 / Alphalist', 'rpt_2316'],
            ['Monthly Payroll Summary', 'rpt_monthly'],
            ['Branch Cost Allocation', 'rpt_branch'],
            ['Department Reports', 'rpt_departments'],
            ['Audit Trail', 'audit'],
        ]],

        ['label' => 'Setup & system'],
        ['section' => 'Company Parameters Setup', 'icon' => 'grid', 'module' => 'setup', 'items' => [
            ['All parameter tables', 'payroll_setup'],
            ['Company Table', 'branches'],
            ['Department Table', 'departments'],
            ['Location Table', 'locations'],
            ['Job Table', 'jobs'],
            ['Employee Type Table', 'emp_types'],
            ['Employee Group Table', 'emp_groups'],
            ['Class Group Table', 'class_groups'],
            ['Rank Category Table', 'rank_categories'],
            ['Classification Table', 'classifications'],
            ['Exemption Code Table', 'exemption_codes'],
            ['Pay Code Table', 'pay_groups'],
            ['Shift Table', 'shifts'],
            ['Annual Holidays', 'holidays'],
            ['Benefits Table', 'benefit_types'],
            ['Transaction Table', 'earning_types'],
            ['Fixed Transaction Table', 'fixed_trans_codes'],
            ['Payroll Code Table (GL)', 'gl_accounts'],
            ["Employee's Bank Setup", 'bank_setup'],
            ['SSS Table', 'sss_table'],
            ['Tax Table', 'tax_table'],
            ['Health Table (PhilHealth)', 'philhealth'],
            ['Pag-IBIG Table', 'pagibig'],
            ['Lates / Undertime Table', 'late_brackets'],
        ]],
        ['section' => 'Company', 'icon' => 'building', 'items' => [
            ['Company Profile & Code', 'company_profile', 'owner'],
            ['Join Requests', 'join_requests', 'owner'],
        ]],
        ['section' => 'System', 'icon' => 'cog', 'module' => 'system', 'items' => [
            ['User Accounts', 'users', 'admin'],
            ['Role Management', 'roles', 'admin'],
            ['User Access & Permissions', 'user_access', 'admin'],
            ['Payroll Parameters', 'payroll_params'],
            ['Signatory', 'signatories'],
            ['System Settings', 'settings'],
            ['Department Budgets', 'budgets'],
            ['Email Outbox', 'outbox'],
            ['Audit Trail', 'audit'],
        ]],
    ];
}

/** Sidebar of the platform (Super Admin, no company entered). */
function super_menu(): array
{
    return [
        ['label' => 'Platform'],
        ['page' => 'sa_dashboard', 'label' => 'Dashboard', 'icon' => 'home', 'roles' => 'super'],
        ['page' => 'sa_companies', 'label' => 'Companies', 'icon' => 'building', 'roles' => 'super'],
        ['page' => 'sa_user_links', 'label' => 'Multi-company Users', 'icon' => 'users', 'roles' => 'super'],
        ['page' => 'sa_passwords', 'label' => 'Password Resets', 'icon' => 'key', 'roles' => 'super'],
        ['page' => 'sa_company_new', 'label' => 'Create Company', 'icon' => 'plus', 'roles' => 'super'],
        ['page' => 'sa_logs', 'label' => 'Audit & Provisioning Logs', 'icon' => 'list', 'roles' => 'super'],
        ['label' => 'Administration'],
        ['page' => 'sa_admins', 'label' => 'Super Admin Accounts', 'icon' => 'shield', 'roles' => 'super'],
        ['page' => 'sa_account', 'label' => 'My Account', 'icon' => 'user', 'roles' => 'super'],
    ];
}

/** Is a menu entry shown to the signed-in user? $flag: null (permission), '*', 'super', 'owner' or 'admin'. */
function menu_visible($flag, ?string $route = null): bool
{
    if ($flag === '*') {
        return current_user() !== null;
    }
    if ($flag === 'super') {
        return super_admin() !== null;
    }
    if ($flag === 'owner') {
        return owner_or_support();
    }
    if ($flag === 'admin') {
        return is_super_user();
    }
    return $route !== null && perm_route_allowed($route);
}

/** Section caption and item label of a route (breadcrumbs). */
function menu_location(string $route): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (app_menu() as $m) {
            if (isset($m['page'])) {
                $map[$m['page']] = ['', $m['label']];
                continue;
            }
            foreach ($m['items'] ?? [] as $i) {
                foreach (array_merge([$i[1]], $i[3] ?? []) as $r) {   // [3]: other routes of the same menu item
                    if (!isset($map[$r])) {
                        $map[$r] = [$m['section'], $i[0]];
                    }
                }
            }
        }
    }
    return $map[$route] ?? ['', ''];
}

function page_header(string $title, array $opt = []): void
{
    $u = layout_user();
    $superMode = $u && $u['role'] === 'super';
    $current = $_GET['p'] ?? 'dashboard';
    $appName = app_name();
    [$section] = empty($GLOBALS['__perm_denied']) ? menu_location($current) : [''];
    // A screen opened from another screen (back=...) gets a Back button to it, unless the page set its own.
    if (empty($opt['back']) && back_url()) {
        $br = back_route();
        $lbl = $br ? (menu_location($br)[1] ?: ['payroll_run' => 'Payroll run', 'employee_view' => '201 file', 'cutoff_view' => 'Cutoff'][$br] ?? label($br)) : '';
        $opt['actions'] = '<a class="btn btn-back" href="' . h(back_url()) . '">&larr; ' . h($lbl ? 'Back to ' . $lbl : 'Back') . '</a>' . ($opt['actions'] ?? '');
    }
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">
<meta name="theme-color" content="#0c1533">
<title><?= h($title) ?> - <?= h($appName) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='7' fill='%233f5ee8'/%3E%3Ctext x='16' y='22' font-family='Segoe UI,Arial' font-size='15' font-weight='700' fill='white' text-anchor='middle'%3E<?= rawurlencode(app_mark()) ?>%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="assets/css/app.css?v=28">
</head>
<body class="<?= $u ? 'has-sidebar' : 'guest' ?>">
<?php if ($u): ?>
<aside class="sidebar" id="sidebar">
  <div class="brand">
    <div class="brand-mark"><?= h(app_mark()) ?></div>
    <div><div class="brand-name"><?= h($appName) ?></div><div class="brand-sub">Personnel, Payroll, Attendance</div></div>
  </div>
  <div class="nav-find"><input type="search" id="navFind" placeholder="Filter menu…" autocomplete="off" aria-label="Filter menu"></div>
  <nav class="nav" id="navMenu">
  <?php foreach (app_menu() as $m):
        if (isset($m['label']) && !isset($m['page'])) { ?>
    <div class="nav-label"><?= h($m['label']) ?></div>
  <?php continue; }
        if (isset($m['page'])) {
            if (!menu_visible($m['roles'], $m['page'])) continue; ?>
    <a class="nav-link<?= $current === $m['page'] ? ' active' : '' ?>" href="<?= url($m['page']) ?>"><?= icon($m['icon']) ?><span><?= h($m['label']) ?></span></a>
  <?php continue; }
        if (!empty($m['ess']) && !my_employee_id()) continue;
        $items = [];
        foreach ($m['items'] as $i) {
            // an item with other routes ([3]) opens the first one the user may open
            foreach (array_merge([$i[1]], $i[3] ?? []) as $r) {
                if (menu_visible($i[2] ?? null, $r)) {
                    $i['on'] = in_array($current, array_merge([$i[1]], $i[3] ?? []), true);
                    $i[1] = $r;
                    $items[] = $i;
                    break;
                }
            }
        }
        if (!$items) continue;
        $open = (bool)array_filter($items, fn($i) => $i['on']); ?>
    <details class="nav-group"<?= $open ? ' open' : '' ?>>
      <summary><?= icon($m['icon']) ?><span><?= h($m['section']) ?></span></summary>
      <div class="nav-items">
      <?php foreach ($items as $i): ?>
        <a class="nav-sub<?= $i['on'] ? ' active' : '' ?>" href="<?= url($i[1]) ?>"><?= h($i[0]) ?></a>
      <?php endforeach; ?>
      </div>
    </details>
  <?php endforeach; ?>
    <div class="nav-empty" id="navEmpty">No menu item matches.</div>
  </nav>
  <div class="sidebar-foot"><span class="dot"></span> <?= h($superMode ? 'Platform administration' : current_company_name()) ?></div>
</aside>
<div class="nav-backdrop" id="navBackdrop" aria-hidden="true"></div>
<div class="main">
  <header class="topbar">
    <button class="icon-btn" id="navToggle" type="button" aria-label="Toggle menu"><?= icon('menu') ?></button>
    <div class="crumbs">
      <?php if ($section): ?><span><?= h($section) ?></span><span class="sep">/</span><?php endif; ?>
      <strong><?= h($title) ?></strong>
    </div>
    <button class="cmdk-btn" type="button" id="cmdkOpen"><?= icon('search') ?><span>Search pages…</span><kbd>Ctrl K</kbd></button>
    <?php if (!$superMode): ?><a class="help-btn" href="<?= h(manual_help_url($current)) ?>" title="User manual for this page"><?= icon('book') ?><span>Help</span></a><?php endif; ?>
    <?php if (!$superMode && !in_company_support() && ($switch = mc_switch_targets())): ?>
    <form method="post" action="<?= h(url('switch_company')) ?>" class="co-switch" title="Switch company">
      <?= csrf_field() ?>
      <label class="sr-only" for="coSwitch">Company</label>
      <select name="company_id" id="coSwitch" data-autosubmit>
        <?php foreach ($switch as $t): ?><option value="<?= (int)$t['company_id'] ?>"<?= $t['company_id'] === (int)current_company_id() ? ' selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?>
      </select>
      <noscript><button class="btn btn-sm">Switch</button></noscript>
    </form>
    <?php endif; ?>
    <div class="topbar-user">
      <a href="<?= url($superMode ? 'sa_account' : 'account') ?>" class="user-chip" title="My account">
        <span class="avatar"><?= h(mb_strtoupper(mb_substr($u['full_name'], 0, 1))) ?></span>
        <span class="user-meta"><strong><?= h($u['full_name']) ?></strong><small><?= h($superMode ? 'Super Administrator' : (is_company_owner() ? 'Company Owner' : user_role_name($u))) ?></small></span>
      </a>
      <a class="icon-btn" href="<?= url('logout') ?>" title="Sign out"><?= icon('logout') ?></a>
    </div>
  </header>
  <main class="content<?= !empty($opt['wide']) ? ' wide' : '' ?>">
<?php if (in_company_support() && ($co = tenant_company())): ?>
    <div class="context-bar">
      <span class="context-label">Super Admin inside</span>
      <strong><?= h($co['name']) ?></strong>
      <span class="context-meta">database <?= h($co['db_name']) ?><?= $co['status'] !== 'active' ? ', company ' . h(strtolower(COMPANY_STATUSES[$co['status']] ?? $co['status'])) : '' ?></span>
      <span class="spacer"></span>
      <form method="post" action="<?= h(url('sa_enter')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="_action" value="leave"><button class="btn btn-sm">Leave company</button></form>
    </div>
<?php endif; ?>
<?php else: ?>
<div class="guest-wrap">
<?php endif; ?>
<?php if ($u || !empty($opt['subtitle']) || !empty($opt['actions'])): ?>
    <div class="page-head">
      <div>
        <?php if (!empty($opt['back'])): ?><a class="back-link" href="<?= h($opt['back']) ?>">&larr; <?= h($opt['back_label'] ?? 'Back') ?></a><?php endif; ?>
        <h1><?= h($title) ?></h1>
        <?php if (!empty($opt['subtitle'])): ?><p class="muted"><?= $opt['subtitle'] ?></p><?php endif; ?>
      </div>
      <?php if (!empty($opt['actions'])): ?><div class="page-actions"><?= $opt['actions'] ?></div><?php endif; ?>
    </div>
<?php endif; ?>
<?php if (!empty($opt['tabs'])): ?><?= $opt['tabs'] ?><?php endif; ?>
<?php foreach (flashes() as $f): ?>
    <div class="alert alert-<?= h($f['type']) ?>"><span><?= h($f['msg']) ?></span></div>
<?php endforeach;
}

function page_footer(): void
{
    $u = layout_user();
    if ($u): ?>
  </main>
  <footer class="footer">
    <span class="footer-tags"><span><?= h(app_name()) ?></span><span>Personnel Information</span><span>Payroll</span><span>Attendance Monitoring</span></span>
    <span><?= h(date('D, d M Y')) ?></span>
  </footer>
</div>
<div class="cmdk" id="cmdk" aria-hidden="true">
  <div class="cmdk-box">
    <input type="search" id="cmdkInput" placeholder="Go to page…" autocomplete="off" aria-label="Search pages">
    <div class="cmdk-list" id="cmdkList"></div>
  </div>
</div>
<?= function_exists('quickref_modals') ? quickref_modals() : '' ?>
<?php else: ?>
</div>
<?php endif; ?>
<script src="assets/js/app.js?v=28"></script>
</body>
</html>
<?php
}

/** Minimal layout for printable documents (payslips, notices, reports, forms). */
function print_header(string $title, bool $landscape = false, string $size = ''): void
{
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
<link rel="stylesheet" href="assets/css/app.css?v=28">
<?php if ($landscape): ?><style>@page { size: <?= $size === 'legal' ? 'legal ' : '' ?>landscape; }</style><?php endif; ?>
</head>
<body class="print-body">
<div class="print-toolbar no-print">
  <button type="button" class="btn btn-primary" onclick="window.print()">Print / Save as PDF</button>
  <button type="button" class="btn" onclick="history.length > 1 ? history.back() : window.close()">Back</button>
  <span class="muted"><?= h($title) ?></span>
</div>
<div class="print-page<?= $landscape ? ' landscape' : '' ?><?= $size === 'legal' && $landscape ? ' legal-landscape' : '' ?>">
<?php
}

function print_footer(): void
{
    // On a phone the printable tables scroll inside their own frame instead of widening the page.
    echo "</div>\n<script>(function(){var t=document.querySelectorAll('table.doc-table,table.reg,table.table');for(var i=0;i<t.length;i++){if(t[i].closest('.table-wrap'))continue;var w=document.createElement('div');w.className='table-wrap table-wrap-auto';t[i].parentNode.insertBefore(w,t[i]);w.appendChild(t[i]);}})();</script>\n</body>\n</html>";
}

function company_letterhead(?array $branch = null): string
{
    $name = $branch['company_name'] ?? setting('company_name');
    $addr = $branch['address'] ?? setting('company_address');
    // company logo when this report is ticked in System Settings > Company logo on reports (app/engine/branding.php)
    $logo = function_exists('report_logo_img') ? report_logo_img() : '';
    $place = $logo ? ' lp-' . report_logo_placement() : '';   // centered, left / right beside the name, or at the left / right edge
    return '<div class="letterhead' . ($logo ? ' has-logo' : '') . $place . '">' . $logo . '<div class="lh-text"><div class="lh-name">' . h($name) . '</div><div class="lh-addr">' . h($addr) . '</div></div></div>';
}

/** Small helper for rendering a stat card. */
function stat_card(string $label, string $value, string $hint = '', string $tone = ''): string
{
    $long = mb_strlen(trim(html_entity_decode(strip_tags($value)))) > 9;
    return '<div class="stat ' . h($tone) . '"><div class="stat-label">' . h($label) . '</div><div class="stat-value' . ($long ? ' long' : '') . '">' . $value . '</div>'
        . ($hint !== '' ? '<div class="stat-hint">' . $hint . '</div>' : '') . '</div>';
}

/** Tab strip of links between related pages: [route => label]. */
/** v16: the two tabs of Employee Term Loan/Payables (Subsidiary Ledgers + Employee Deductions & Loans). */
function term_loan_tabs(string $active): string
{
    $tabs = array_filter(['ledgers' => 'Loans & payables (subsidiary ledgers)', 'emp_deductions' => 'Employee deductions'], fn($r) => perm_route_allowed($r), ARRAY_FILTER_USE_KEY);
    return count($tabs) > 1 ? tab_links($tabs, $active) : '';
}

function tab_links(array $tabs, string $active, array $params = []): string
{
    $h = '<nav class="tabs-nav">';
    foreach ($tabs as $route => $label) {
        $h .= '<a class="tab-link' . ($route === $active ? ' active' : '') . '" href="' . h(url($route, $params)) . '">' . h($label) . '</a>';
    }
    return $h . '</nav>';
}

/** Signatory block for printed reports (System › Signatory). */
function signatory_block(bool $withCaption = true): string
{
    $h = '<div class="sig-row">';
    foreach ([['prepared', 'Prepared by'], ['checked', 'Checked by'], ['approved', 'Approved by']] as $s) {
        $name = trim((string)setting('sig_' . $s[0] . '_name', ''));
        $h .= '<div class="sig">' . ($withCaption ? '<div class="cap">' . h($s[1]) . ':</div>' : '')
            . '<div class="line">' . ($name !== '' ? h($name) : '&nbsp;') . '</div><small>' . h(setting('sig_' . $s[0] . '_title', '')) . '</small></div>';
    }
    return $h . '</div>';
}

/**
 * v17: pick list of codes (Fixed Transaction, Employee's Transaction, Benefit): tick one, two, three or
 * more codes, each with its own values (amount, terms ...). Only the ticked rows are sent:
 *   codes[]            the keys of the ticked rows
 *   <col>[<key>]       the value of each column of a ticked row
 * $groups: group label ('' = none) => list of ['key', 'label', 'hint' (optional), 'values' => [col => default], 'attrs' => [data-*]]
 * $cols:   col => ['label', 'type' (number|text|select), 'options' (select), 'step', 'placeholder', 'width']
 * $opt:    'title', 'checked' => keys ticked at first, 'required' (default true), 'search' (default true when more than 8 rows)
 */
function code_pick_html(string $id, array $groups, array $cols, array $opt = []): string
{
    $checked = array_map('strval', $opt['checked'] ?? []);
    $rows = array_sum(array_map('count', $groups));
    $h = '<div class="codepick" id="' . h($id) . '"' . (($opt['required'] ?? true) ? ' data-required="1"' : '') . '>';
    $h .= '<div class="cp-head">' . (($opt['search'] ?? $rows > 8) ? '<input type="search" class="cp-find" placeholder="Type a code or name" aria-label="Find a code">' : '')
        . '<span class="cp-count" aria-live="polite"></span><button type="button" class="pl-btn" data-cp-clear>Clear</button></div>';
    $h .= '<div class="cp-box"><table class="cp-table"><thead><tr><th class="cp-tick"></th><th>' . h($opt['title'] ?? 'Code') . '</th>';
    foreach ($cols as $c) {
        $h .= '<th class="' . (($c['type'] ?? 'number') === 'number' ? 'num' : '') . '">' . h($c['label']) . '</th>';
    }
    $h .= '</tr></thead>';
    foreach ($groups as $glabel => $list) {
        if (!$list) {
            continue;
        }
        $h .= '<tbody>' . ($glabel !== '' ? '<tr class="cp-group"><th colspan="' . (2 + count($cols)) . '">' . h($glabel) . '</th></tr>' : '');
        foreach ($list as $r) {
            $key = (string)$r['key'];
            $on = in_array($key, $checked, true);
            $attrs = '';
            foreach ($r['attrs'] ?? [] as $k => $v) {
                $attrs .= ' ' . h($k) . '="' . h((string)$v) . '"';
            }
            $h .= '<tr class="cp-row' . ($on ? ' is-on' : '') . '" data-search="' . h(mb_strtolower($r['label'] . ' ' . ($r['hint'] ?? ''))) . '"' . $attrs . '>'
                . '<td class="cp-tick"><input type="checkbox" name="codes[]" value="' . h($key) . '"' . ($on ? ' checked' : '') . ' aria-label="' . h($r['label']) . '"></td>'
                . '<td class="cp-name"><label>' . h($r['label']) . '</label>' . (!empty($r['hint']) ? '<small>' . h($r['hint']) . '</small>' : '') . '</td>';
            foreach ($cols as $ck => $c) {
                $val = $r['values'][$ck] ?? ($c['default'] ?? '');
                $name = h($ck) . '[' . h($key) . ']';
                $dis = $on ? '' : ' disabled';
                $type = $c['type'] ?? 'number';
                if ($type === 'select') {
                    $h .= '<td data-label="' . h($c['label']) . '"><select name="' . $name . '"' . $dis . ' aria-label="' . h($c['label']) . '">' . options($c['options'], $val, $c['placeholder'] ?? null) . '</select></td>';
                } else {
                    $h .= '<td class="' . ($type === 'number' ? 'num' : '') . '" data-label="' . h($c['label']) . '"><input type="' . $type . '" name="' . $name . '" value="' . h((string)$val) . '" aria-label="' . h($c['label']) . '"'
                        . ($type === 'number' ? ' step="' . h($c['step'] ?? '0.01') . '" min="0"' : '') . (isset($c['placeholder']) ? ' placeholder="' . h($c['placeholder']) . '"' : '') . $dis . '></td>';
                }
            }
            $h .= '</tr>';
        }
        $h .= '</tbody>';
    }
    return $h . '</table></div><div class="pl-msg" hidden></div></div>';
}

/** The ticked codes of a code_pick_html() list: [key => [col => value]]. */
function code_pick_posted(array $cols): array
{
    $out = [];
    foreach ((array)post('codes', []) as $key) {
        $key = (string)$key;
        if ($key === '' || isset($out[$key])) {
            continue;
        }
        $out[$key] = [];
        foreach ($cols as $c) {
            $v = $_POST[$c][$key] ?? null;
            $out[$key][$c] = is_scalar($v) ? trim((string)$v) : null;
        }
    }
    return $out;
}

function empty_row(int $cols, string $msg = 'No records found.'): string
{
    return '<tr><td colspan="' . $cols . '" class="empty">' . h($msg) . '</td></tr>';
}
