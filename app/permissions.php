<?php
/**
 * Dynamic role-based and user-specific access control.
 *
 *   SUPER ADMIN (the protected "System Administrator" role, the Company Owner, a platform Super Admin
 *   inside the company) creates roles and chooses their permissions
 *     -> assigns a role to every user
 *     -> may customize one user (Allow / Deny override per permission, Inherit by default)
 *     -> effective permissions = role permissions + Allow overrides - Deny overrides
 *     -> the menu, the dashboard and every button follow them, and index.php enforces the same rule
 *        on the server for every page, form submission, export, print and AJAX request.
 *
 * A permission is a stable machine key "<page>.<action>" (employees.view, employees.create,
 * payroll_runs.approve, rpt_remittance.export ...). The catalog is generated from the real menu of the
 * system (company_menu() in app/layout.php) and the actions each page actually has (perm_defs() below),
 * and synchronized into the `permissions` table automatically (perm_sync()).
 *
 * Tables: roles, permissions, role_permissions, user_permission_overrides; users.role_id.
 * users.role is kept as the role's legacy profile for backward compatibility only.
 *
 * Performance: the role table, the role's permissions and the user's overrides are read once per request
 * (three small queries) and memoized; nothing is cached across requests, so a change made by the Super
 * Admin applies on the user's very next click.
 */

const PERM_ACTIONS = [
    'view' => 'View', 'create' => 'Create', 'edit' => 'Edit', 'delete' => 'Delete', 'approve' => 'Approve', 'reject' => 'Reject',
    'import' => 'Import', 'export' => 'Export', 'print' => 'Print', 'generate' => 'Generate', 'configure' => 'Configure',
];

/** Module keys of the catalog (menu sections) and the legacy module keys of users.module_access they replace. */
const PERM_MODULES = [
    'ess' => 'My Workspace', 'approvals' => 'Approvals', 'pis' => 'Personnel (PIS)', 'ams' => 'Attendance (AMS)',
    'pay' => 'Payroll System (PAY)', 'process' => 'Process & Registers', 'reports' => 'Reports',
    'setup' => 'Company Parameters Setup', 'system' => 'System',
];
const PERM_LEGACY_MODULE = ['ess' => 'common', 'approvals' => 'common', 'pis' => 'pis', 'ams' => 'ams', 'pay' => 'payroll', 'process' => 'payroll',
    'reports' => 'reports', 'setup' => 'setup', 'system' => 'system'];

/**
 * Pages that carry permissions and what can be done on them.
 *   a    actions of the page (only those the page really has)
 *   def  default grants of the built-in roles (migrated from the old hard-coded access): "hr payroll" =
 *        all actions for both, "hr payroll:view,export" = everything for HR, view and export for payroll,
 *        "*" = every role. The System Administrator role has everything and is not listed.
 *   ops  POST action (the form's _action) => permission action, when the generic reading is not right
 *   label / module  only for pages that are not in the menu
 */
function perm_defs(): array
{
    static $d = null;
    if ($d !== null) {
        return $d;
    }
    $crud = 'view create edit delete';
    $crudi = 'view create edit delete import';   // reference tables with the CSV import facility on the form
    return $d = [
        // My Workspace (self-service of the employee linked to the account)
        'my_clock' => ['a' => 'view create', 'def' => '*', 'ops' => ['' => 'create']],
        'my_dtr' => ['a' => 'view print', 'def' => '*'],
        'my_calendar' => ['a' => 'view', 'def' => '*'],   // v17: schedule, leaves, overtime, holidays, company events
        'my_leaves' => ['a' => 'view create', 'def' => '*', 'ops' => ['file' => 'create', '' => 'create']],
        'my_overtime' => ['a' => 'view create', 'def' => '*', 'ops' => ['' => 'create']],
        'my_payslips' => ['a' => 'view print', 'def' => '*'],
        'my_evaluations' => ['a' => 'view edit', 'def' => '*', 'ops' => ['' => 'edit']],
        'my_ledgers' => ['a' => 'view', 'def' => '*'],
        // v20: Employee Loan / Deduction Request (the employee applies) and its approval (superior, HR, administrator)
        'my_loan_requests' => ['a' => 'view create', 'def' => '*', 'ops' => ['save' => 'create', 'submit' => 'create', 'cancel' => 'create', 'delete' => 'create', '' => 'create']],
        'my_profile' => ['a' => 'view edit', 'def' => '*', 'ops' => ['' => 'edit']],
        'my_memos' => ['a' => 'view', 'def' => '*'],
        // Approvals
        'approvals' => ['a' => 'view approve reject', 'def' => 'hr supervisor'],
        'loan_approvals' => ['a' => 'view approve reject', 'def' => 'hr supervisor payroll', 'ops' => ['approve' => 'approve', 'reject' => 'reject']],
        'evaluations' => ['a' => 'view edit approve', 'def' => 'hr supervisor:view,edit',
            'ops' => ['save' => 'edit', 'submit' => 'edit', 'peer' => 'edit', 'del_peer' => 'edit', 'finalize' => 'approve', 'refer' => 'approve', 'apply' => 'approve', 'reopen' => 'approve']],
        // Personnel (PIS)
        'employees' => ['a' => 'view create edit import export print', 'def' => 'hr payroll:view,export,print',
            'ops' => ['account' => 'edit', 'separate' => 'edit', 'reinstate' => 'edit', 'upload_doc' => 'edit', 'delete_doc' => 'edit', 'upload' => 'import', 'confirm' => 'import', 'discard' => 'import']],
        // v13: the basic salary on the employee screens (201 file, masterfile list and export, 201 masterlisting, CSV import);
        // roles that could see / change the employees keep seeing / changing the salary after the upgrade (inherit)
        // v17: Masterfile Batch Edit (the roles that may change the employees get it)
        'employee_batch' => ['a' => 'view edit', 'def' => 'hr', 'label' => 'Masterfile Batch Edit', 'module' => 'pis',
            'inherit' => ['view' => 'employees.edit', 'edit' => 'employees.edit']],
        'employee_salary' => ['a' => 'view edit', 'def' => 'hr payroll:view', 'label' => 'Basic Salary (employee basic rate)', 'module' => 'pis',
            'inherit' => ['view' => 'employees.view', 'edit' => 'employees.edit']],
        // v14: Memoranda / Announcements (approve = issue)
        'memos' => ['a' => 'view create edit delete approve print', 'def' => 'hr', 'ops' => ['save_issue' => 'create', 'issue' => 'approve', 'archive' => 'edit', 'unarchive' => 'edit']],
        'memo_templates' => ['a' => 'view create edit delete configure', 'def' => 'hr', 'ops' => ['design' => 'configure', 'restore' => 'create', 'toggle' => 'edit']],
        'org_units' => ['a' => $crud, 'def' => 'hr'],
        'leave_applications' => ['a' => 'view create approve reject', 'def' => 'hr', 'ops' => ['file' => 'create']],
        'leave_balances' => ['a' => 'view edit generate import', 'def' => 'hr', 'ops' => ['adjust' => 'edit', 'accrue_annual' => 'generate', 'accrue_monthly' => 'generate', 'year_end' => 'generate']],
        'leave_types' => ['a' => $crud, 'def' => 'hr'],
        'eval_cycles' => ['a' => 'view create edit delete generate', 'def' => 'hr', 'ops' => ['generate' => 'generate']],
        'competencies' => ['a' => $crudi, 'def' => 'hr'],
        'salary_grades' => ['a' => $crud, 'def' => 'hr'],
        'salary_scaling' => ['a' => 'view approve', 'def' => 'hr', 'ops' => ['commit' => 'approve']],
        'cases' => ['a' => 'view create edit approve print', 'def' => 'hr',
            'ops' => ['create' => 'create', 'nte_dismiss' => 'create', 'issue_nte' => 'approve', 'decide' => 'approve', 'close' => 'approve', 'to_decision' => 'approve', 'lapsed' => 'approve',
                'attach' => 'edit', 'edit_draft' => 'edit', 'explanation' => 'edit', 'hearing' => 'edit', 'minutes' => 'edit']],
        'conduct' => ['a' => $crudi, 'def' => 'hr'],
        // Attendance (AMS)
        'att_entry' => ['a' => 'view edit generate', 'def' => 'hr payroll', 'ops' => ['recalc' => 'generate']],
        'import_logs' => ['a' => 'view import edit delete', 'def' => 'hr',
            'ops' => ['file' => 'import', 'device' => 'import', 'log_table' => 'import', 'post' => 'import', 'upload' => 'import', 'confirm' => 'import', 'assign' => 'edit', 'delete_all' => 'delete']],
        'import_config' => ['a' => $crud, 'def' => 'hr', 'ops' => ['test' => 'edit', 'default' => 'edit']],
        'bio_devices' => ['a' => 'view create edit delete import', 'def' => 'hr', 'ops' => ['download' => 'import', 'test' => 'edit', 'token' => 'edit', 'revoke_token' => 'edit', 'agent_config' => 'edit']],
        'att_leave_entry' => ['a' => 'view create delete', 'def' => 'hr', 'ops' => ['post' => 'create', 'cancel' => 'delete']],
        'schedules' => ['a' => 'view create delete import', 'def' => 'hr', 'ops' => ['assign' => 'create']],
        'batch_shift' => ['a' => 'view create', 'def' => 'hr', 'ops' => ['' => 'create']],
        'att_generate' => ['a' => 'view generate approve', 'def' => 'hr payroll', 'ops' => ['reprocess' => 'generate']],
        'att_process' => ['a' => 'view generate', 'def' => 'hr payroll', 'ops' => ['process' => 'generate']],
        'att_summary' => ['a' => 'view edit delete generate approve import', 'def' => 'hr payroll',
            'ops' => ['save' => 'edit', 'recalc' => 'generate', 'close' => 'approve', 'reopen' => 'approve', 'reset_source' => 'edit', 'set_source' => 'edit']],
        'att_summary2' => ['a' => 'view edit delete generate approve import', 'def' => 'hr payroll',
            'ops' => ['save' => 'edit', 'recalc' => 'generate', 'process' => 'generate', 'close' => 'approve', 'reopen' => 'approve', 'close_all' => 'approve', 'reopen_all' => 'approve']],
        'att_batch' => ['a' => 'view edit import', 'def' => 'hr payroll', 'ops' => ['' => 'edit', 'reset_source' => 'edit', 'set_source' => 'edit']],
        'dtr' => ['a' => 'view edit generate approve print', 'def' => 'hr payroll:view,generate,print',
            'ops' => ['reprocess' => 'generate', 'save' => 'edit', 'retype' => 'edit', 'revert' => 'edit', 'authorize_ot' => 'approve', 'resolve' => 'approve']],
        'cutoffs' => ['a' => 'view create generate approve', 'def' => 'hr payroll',
            'ops' => ['create' => 'create', 'summarize' => 'generate', 'approve' => 'approve', 'reopen' => 'approve', 'resolve_all' => 'approve']],
        'att_close' => ['a' => 'view approve', 'def' => 'hr payroll', 'ops' => ['close' => 'approve', 'reopen' => 'approve']],
        'att_exceptions' => ['a' => 'view approve', 'def' => 'hr', 'ops' => ['resolve' => 'approve']],
        'att_logs' => ['a' => 'view create edit', 'def' => 'hr', 'ops' => ['manual' => 'create', 'map_bio' => 'edit', 'retype' => 'edit']],
        'import_csv' => ['a' => 'view import', 'def' => 'hr', 'ops' => ['' => 'import']],
        'import_review' => ['a' => 'view approve reject', 'def' => 'hr', 'ops' => ['post' => 'approve']],
        'web_verify' => ['a' => 'view approve reject', 'def' => 'hr', 'ops' => ['verify' => 'approve']],
        'ot_requests' => ['a' => 'view create approve reject', 'def' => 'hr supervisor'],
        'ams_policies' => ['a' => 'view configure', 'def' => 'hr', 'ops' => ['' => 'configure']],
        // Payroll System (PAY)
        'periods' => ['a' => 'view create edit delete generate approve', 'def' => 'hr payroll',
            'ops' => ['create' => 'create', 'generate' => 'create', 'update' => 'edit', 'summarize' => 'generate', 'run' => 'generate', 'approve' => 'approve', 'approve_force' => 'approve', 'reopen' => 'approve']],
        'pay_trans' => ['a' => 'view edit delete generate import', 'def' => 'hr payroll',
            'ops' => ['save' => 'edit', 'recalc' => 'generate', 'reset_source' => 'edit', 'set_source' => 'edit']],
        'pay_trans2' => ['a' => 'view edit delete generate import', 'def' => 'hr payroll', 'ops' => ['save' => 'edit', 'recalc' => 'generate', 'process' => 'generate']],
        'pay_batch' => ['a' => 'view edit import', 'def' => 'hr payroll', 'ops' => ['' => 'edit', 'reset_source' => 'edit', 'set_source' => 'edit']],
        'emp_transactions' => ['a' => 'view create edit delete import', 'def' => 'payroll',
            'ops' => ['cancel' => 'delete', 'term_cancel' => 'delete', 'term_save' => 'edit', 'amort' => 'edit', 'pay' => 'edit', 'reactivate' => 'edit', 'remarks' => 'edit']],
        'fixed_trans' => ['a' => 'view create edit delete import', 'def' => 'payroll', 'ops' => ['stop' => 'edit', 'start' => 'edit']],
        'trans_payments' => ['a' => 'view create delete import', 'def' => 'payroll', 'ops' => ['pay' => 'create', 'void' => 'delete']],
        // v16: a tab of Employee Term Loan/Payables (no menu item of its own)
        'emp_deductions' => ['a' => 'view create edit approve import', 'def' => 'payroll', 'ops' => ['amount' => 'edit'], 'module' => 'pay', 'label' => 'Employee Term Loan/Payables: Employee Deductions'],
        'emp_benefits' => ['a' => 'view create edit delete import', 'def' => 'payroll',
            'ops' => ['assign' => 'create', 'bonus' => 'create', 'post_term' => 'edit', 'stop' => 'edit', 'cancel_pe' => 'delete']],
        'ledgers' => ['a' => 'view create edit delete approve print', 'def' => 'payroll',
            'ops' => ['create' => 'create', 'entry' => 'create', 'amort' => 'edit', 'edit_entry' => 'edit', 'delete_entry' => 'delete', 'cancel' => 'delete', 'reconcile' => 'approve']],
        'retro' => ['a' => 'view generate delete import', 'def' => 'payroll', 'ops' => ['compute' => 'generate', 'cancel' => 'delete']],
        'final_pay' => ['a' => 'view generate approve print', 'def' => 'hr payroll', 'ops' => ['compute' => 'generate', 'recompute' => 'generate', 'approve' => 'approve', 'release' => 'approve',
            'line_edit' => 'generate', 'line_delete' => 'generate', 'line_restore' => 'generate', 'line_add' => 'generate', 'adj_delete' => 'generate',
            'cancel' => 'generate', 'uncancel' => 'generate']],
        'prev_ytd' => ['a' => $crudi, 'def' => 'payroll'],
        // Process & Registers
        'payroll_runs' => ['a' => 'view create edit delete generate approve', 'def' => 'payroll',
            'ops' => ['create' => 'create', 'compute' => 'generate', 'finalize' => 'approve', 'resolve' => 'approve', 'cancel' => 'delete', 'delete_run' => 'delete', 'add_adj' => 'edit', 'del_adj' => 'edit']],
        'period_closing' => ['a' => 'view approve export print', 'def' => 'payroll', 'ops' => ['close' => 'approve']],
        'pay_register' => ['a' => 'view generate print export', 'def' => 'payroll', 'ops' => ['reprocess' => 'generate']],
        'pay_register2' => ['a' => 'view generate print export', 'def' => 'payroll', 'ops' => ['reprocess' => 'generate']],
        'pay_inquiry' => ['a' => 'view', 'def' => 'hr payroll'],
        'payslips_print' => ['a' => 'view print', 'def' => 'payroll'],
        'bank_file' => ['a' => 'view export', 'def' => 'payroll'],
        // Reports
        'rpt_periodical' => ['a' => 'view print export', 'def' => 'payroll'],
        'rpt_monthly_contrib' => ['a' => 'view print export', 'def' => 'payroll'],
        'rpt_ytd_reports' => ['a' => 'view print export', 'def' => 'payroll'],
        'rpt_bank_listing' => ['a' => 'view print export', 'def' => 'payroll'],
        'rpt_cash_listing' => ['a' => 'view print export', 'def' => 'payroll'],
        'rpt_register' => ['a' => 'view print export', 'def' => 'payroll'],
        'rpt_attendance' => ['a' => 'view print export', 'def' => 'hr payroll'],
        'rpt_earn_ded' => ['a' => 'view print export', 'def' => 'payroll'],
        'rpt_ytd' => ['a' => 'view', 'def' => 'payroll'],
        'rpt_journal' => ['a' => 'view print export', 'def' => 'payroll'],
        'rpt_loans' => ['a' => 'view print export', 'def' => 'payroll'],
        'rpt_13th' => ['a' => 'view print export', 'def' => 'payroll'],
        'rpt_remittance' => ['a' => 'view print export', 'def' => 'payroll'],
        'rpt_2316' => ['a' => 'view print', 'def' => 'payroll'],
        'rpt_monthly' => ['a' => 'view export', 'def' => 'hr payroll'],
        'rpt_branch' => ['a' => 'view', 'def' => 'payroll'],
        'rpt_departments' => ['a' => 'view', 'def' => 'hr payroll'],
        'rpt_201_master' => ['a' => 'view print export configure', 'def' => 'hr payroll:view,print,export',
            'ops' => ['save_layout' => 'configure', 'delete_layout' => 'configure', 'default_layout' => 'configure']],
        'audit' => ['a' => 'view export', 'def' => 'hr payroll'],
        // Company Parameters Setup
        'payroll_setup' => ['a' => 'view', 'def' => 'hr payroll'],
        'branches' => ['a' => $crudi, 'def' => 'hr payroll', 'ops' => ['set_default' => 'edit']],
        'departments' => ['a' => 'view import', 'def' => 'hr payroll'],
        'locations' => ['a' => $crudi, 'def' => 'hr payroll', 'ops' => ['set_default' => 'edit']],
        'jobs' => ['a' => $crudi, 'def' => 'hr payroll', 'ops' => ['set_default' => 'edit']],
        'emp_types' => ['a' => $crud, 'def' => 'hr payroll', 'ops' => ['set_default' => 'edit']],
        'emp_groups' => ['a' => $crud, 'def' => 'hr payroll', 'ops' => ['set_default' => 'edit']],
        'class_groups' => ['a' => $crud, 'def' => 'hr payroll', 'ops' => ['set_default' => 'edit']],
        'rank_categories' => ['a' => $crud, 'def' => 'hr payroll', 'ops' => ['set_default' => 'edit']],
        'classifications' => ['a' => $crud, 'def' => 'hr payroll', 'ops' => ['set_default' => 'edit']],
        'exemption_codes' => ['a' => $crud, 'def' => 'hr payroll', 'ops' => ['set_default' => 'edit']],
        'pay_groups' => ['a' => $crud, 'def' => 'payroll', 'ops' => ['set_default' => 'edit']],
        'shifts' => ['a' => $crudi, 'def' => 'hr', 'ops' => ['set_default' => 'edit']],
        'holidays' => ['a' => $crud, 'def' => 'hr payroll'],
        'company_events' => ['a' => 'view create edit delete', 'def' => 'hr', 'label' => 'Company Events', 'module' => 'pis'],   // v17
        'benefit_types' => ['a' => $crudi, 'def' => 'payroll'],
        'earning_types' => ['a' => $crudi, 'def' => 'payroll'],
        'deduction_types' => ['a' => $crudi, 'def' => 'payroll', 'label' => 'Deduction Type Table', 'module' => 'setup'],
        'premium_rates' => ['a' => 'view create edit delete', 'def' => 'payroll', 'label' => 'Overtime / Premium Table', 'module' => 'setup'],
        'fixed_trans_codes' => ['a' => $crudi, 'def' => 'payroll'],
        'gl_accounts' => ['a' => $crud, 'def' => 'payroll'],
        'bank_setup' => ['a' => 'view edit', 'def' => 'payroll', 'ops' => ['' => 'edit']],
        'sss_table' => ['a' => 'view create edit delete generate import', 'def' => 'payroll', 'ops' => ['generate' => 'generate']],
        'tax_table' => ['a' => $crud, 'def' => 'payroll'],
        'philhealth' => ['a' => $crudi, 'def' => 'payroll'],
        'pagibig' => ['a' => $crud, 'def' => 'payroll'],
        'late_brackets' => ['a' => $crudi, 'def' => 'payroll'],
        // System
        'payroll_params' => ['a' => 'view edit', 'def' => 'payroll', 'ops' => ['' => 'edit', 'rates' => 'edit']],
        'signatories' => ['a' => 'view edit', 'def' => 'hr payroll', 'ops' => ['' => 'edit']],
        'settings' => ['a' => 'view edit', 'def' => '', 'ops' => ['' => 'edit', 'test_mail' => 'edit', 'report_logo' => 'edit', 'report_logo_remove' => 'edit']],
        'budgets' => ['a' => 'view edit', 'def' => 'payroll', 'ops' => ['' => 'edit']],
        'outbox' => ['a' => 'view edit', 'def' => 'hr', 'ops' => ['resend' => 'edit']],
    ];
}

/**
 * How a GET request of a screen reads as a permission action when the screen itself says it:
 * the form of a new record is "create", the form of an existing one "edit", import screens "import",
 * printable documents "print". Other GET requests are "view", or "print" / "export" from their flags.
 */
function perm_route_get_action(string $route, array $get): ?string
{
    switch ($route) {
        case 'employee_edit':
            return !empty($get['id']) ? 'edit' : 'create';
        case 'employee_import': case 'import_bio': case 'import_pay_trans': case 'import_att_batch': case 'import_emp_trans':
        case 'import_fixed_trans': case 'import_deductions': case 'import_benefits':
            return 'import';
        case 'dtr_edit':
            return 'edit';
        case 'case_print': case 'dtr_print': case 'ledger_print': case 'closing_print':
            return 'print';
        case 'closing_backup':
            return 'export';
    }
    return null;
}

/** Read access a screen allows without the page permission (the record is the user's own, or their team's). */
function perm_route_allow_if(string $route, string $action, array $get): bool
{
    if (!in_array($action, ['view', 'print'], true) || !current_user()) {
        return false;
    }
    switch ($route) {
        case 'employee_view':
            $id = (int)($get['id'] ?? 0);
            return $id && is_manager_of($id);
        case 'dtr_print':
            $id = (int)($get['employee_id'] ?? 0);
            return $id && ($id === my_employee_id() || is_manager_of($id));
        case 'ledger_view': case 'ledger_print':
            if (!my_employee_id()) {
                return false;
            }
            if (!empty($get['employee_id'])) {
                return (int)$get['employee_id'] === my_employee_id();
            }
            $id = (int)($get['id'] ?? 0);
            return $id && (int)val('SELECT employee_id FROM ledgers WHERE id = ?', [$id]) === my_employee_id();
    }
    return false;
}

/** Routes that may be opened with the permission of any of several pages (the same screen reached from two menus). */
function perm_route_any_of(string $route): array
{
    return [
        'trans_multiloc' => ['pay_trans', 'pay_trans2', 'pay_batch', 'att_summary', 'att_summary2', 'att_batch'],
        'import_pay_trans' => ['pay_trans', 'pay_trans2', 'att_summary', 'att_summary2'],
        'import_att_batch' => ['att_batch', 'pay_batch'],
    ][$route] ?? [];
}

/* ======================================================================= catalog */

/**
 * The permission pages: route => [module, module_label, label, actions, ops, def], in menu order.
 * Built from the menu and perm_defs(); pages of the menu without a definition (should not happen) get "view".
 */
function perm_pages(): array
{
    static $pages = null;
    if ($pages !== null) {
        return $pages;
    }
    $defs = perm_defs();
    $pages = [];
    foreach (company_menu() as $m) {
        if (!isset($m['section']) || empty($m['module'])) {
            continue;
        }
        foreach ($m['items'] as $i) {
            if (!empty($i[2]) || isset($pages[$i[1]])) {
                continue;   // owner / Super Admin role pages, and a page listed twice (Audit Trail)
            }
            $route = $i[1];
            if (!isset($defs[$route])) {
                // a menu entry that opens a screen of another page (Import Employees -> employees.import)
                if (perm_route_page($route) !== $route) {
                    continue;
                }
                $defs[$route] = ['a' => 'view', 'def' => ''];
            }
            $pages[$route] = perm_page_entry($route, $defs[$route], $m['module'], $i[0]);
        }
    }
    foreach ($defs as $route => $d) {
        if (!isset($pages[$route]) && !empty($d['module'])) {
            $pages[$route] = perm_page_entry($route, $d, $d['module'], $d['label'] ?? label($route));
        }
    }
    return $pages;
}

function perm_page_entry(string $route, array $d, string $module, string $label): array
{
    $acts = array_values(array_intersect(array_keys(PERM_ACTIONS), preg_split('/\s+/', trim($d['a']))));
    return ['route' => $route, 'module' => $module, 'module_label' => PERM_MODULES[$module] ?? $module, 'label' => $label,
        'actions' => $acts, 'ops' => $d['ops'] ?? [], 'def' => $d['def'] ?? '', 'inherit' => $d['inherit'] ?? []];
}

/** Every permission: key => [page, action, module, label]. */
function perm_catalog(): array
{
    static $cat = null;
    if ($cat === null) {
        $cat = [];
        foreach (perm_pages() as $route => $p) {
            foreach ($p['actions'] as $a) {
                $cat["$route.$a"] = ['page' => $route, 'action' => $a, 'module' => $p['module'], 'label' => PERM_ACTIONS[$a] . ' — ' . $p['label']];
            }
        }
    }
    return $cat;
}

/** Permission page a route is governed by ('' = no page permission: public, self-service checks, owner, Super Admin). */
function perm_route_page(string $route): string
{
    static $routes = null;
    if ($routes === null) {
        $routes = require APP_ROOT . '/app/routes.php';
    }
    $access = $routes[$route][1] ?? '';
    return is_string($access) && !in_array($access, ['public', 'auth', 'owner', 'super', 'admin', ''], true) ? $access : '';
}

/** Default permission keys of a built-in role (from the access it had before roles became configurable). */
function perm_legacy_defaults(string $role): array
{
    $keys = [];
    foreach (perm_pages() as $route => $p) {
        foreach (preg_split('/\s+/', trim($p['def'])) as $tok) {
            if ($tok === '') {
                continue;
            }
            [$r, $acts] = array_pad(explode(':', $tok, 2), 2, null);
            if ($r !== '*' && $r !== $role) {
                continue;
            }
            foreach ($acts === null ? $p['actions'] : explode(',', $acts) as $a) {
                if (in_array($a, $p['actions'], true)) {
                    $keys["$route.$a"] = true;
                }
            }
        }
    }
    return array_keys($keys);
}

/* ======================================================================= roles and effective permissions */

/** Every role of the company: id => row (memoized per request and database). */
function perm_roles(bool $reload = false): array
{
    $db = active_db_name();
    if ($reload || !isset($GLOBALS['__perm_roles'][$db])) {
        $out = [];
        try {
            foreach (rows('SELECT * FROM roles ORDER BY is_super DESC, is_system DESC, name') as $r) {
                $out[(int)$r['id']] = $r;
            }
        } catch (PDOException $e) {
            $out = [];
        }
        $GLOBALS['__perm_roles'][$db] = $out;
    }
    return $GLOBALS['__perm_roles'][$db];
}

function perm_role(int $id): ?array
{
    return perm_roles()[$id] ?? null;
}

function perm_role_by_code(string $code): ?array
{
    foreach (perm_roles() as $r) {
        if ($r['code'] === $code) {
            return $r;
        }
    }
    return null;
}

/** The role of a user row (role_id, or the built-in role of the legacy users.role for older rows). */
function perm_user_role(?array $u): ?array
{
    if (!$u) {
        return null;
    }
    if (!empty($u['role_id']) && ($r = perm_role((int)$u['role_id']))) {
        return $r;
    }
    return perm_role_by_code((string)($u['role'] ?? 'employee'));
}

/** Display name of a user's role. */
function user_role_name(?array $u): string
{
    $r = perm_user_role($u);
    return $r ? (string)$r['name'] : (ROLE_LABELS[$u['role'] ?? ''] ?? (string)($u['role'] ?? ''));
}

/** Is the user (default: the signed-in user) a Super Admin: the protected full-access role, or a platform Super Admin inside the company? */
function is_super_user(?array $u = null): bool
{
    if ($u === null) {
        $u = current_user();
        if (!$u) {
            return false;
        }
        if (in_company_support()) {
            return true;
        }
    }
    if (!empty($u['is_support'])) {
        return true;
    }
    $r = perm_user_role($u);
    if ($r) {
        return (int)$r['is_super'] === 1 && $r['status'] === 'active';
    }
    return ($u['role'] ?? '') === 'admin';   // before the roles table exists (upgrade in progress)
}

/** Permission keys a role grants (all keys for the Super Admin role, none for an inactive role). */
function perm_role_keys(int $roleId): array
{
    $db = active_db_name();
    if (!isset($GLOBALS['__perm_rk'][$db][$roleId])) {
        $r = perm_role($roleId);
        if (!$r || $r['status'] !== 'active') {
            $keys = [];
        } elseif ((int)$r['is_super'] === 1) {
            $keys = array_keys(perm_catalog());
        } else {
            $keys = q('SELECT perm_key FROM role_permissions WHERE role_id = ?', [$roleId])->fetchAll(PDO::FETCH_COLUMN);
        }
        $GLOBALS['__perm_rk'][$db][$roleId] = array_values(array_intersect($keys, array_keys(perm_catalog())));
    }
    return $GLOBALS['__perm_rk'][$db][$roleId];
}

/** User-specific overrides: key => 'allow' | 'deny'. */
function perm_user_overrides(int $userId): array
{
    $db = active_db_name();
    if (!isset($GLOBALS['__perm_uo'][$db][$userId])) {
        try {
            $GLOBALS['__perm_uo'][$db][$userId] = pairs('SELECT perm_key, effect FROM user_permission_overrides WHERE user_id = ?', [$userId]);
        } catch (PDOException $e) {
            $GLOBALS['__perm_uo'][$db][$userId] = [];
        }
    }
    return $GLOBALS['__perm_uo'][$db][$userId];
}

/**
 * Effective permissions of a user: key => source ('role' or 'allow'). Denied keys are absent.
 * ROLE PERMISSIONS -> USER OVERRIDES (Allow adds, Deny removes, Inherit keeps the role) -> EFFECTIVE.
 */
function perm_effective(array $u): array
{
    $db = active_db_name();
    $uid = (int)$u['id'];
    if (!isset($GLOBALS['__perm_eff'][$db][$uid])) {
        $eff = [];
        if (is_super_user($u)) {
            foreach (array_keys(perm_catalog()) as $k) {
                $eff[$k] = 'role';
            }
        } else {
            $role = perm_user_role($u);
            foreach ($role ? perm_role_keys((int)$role['id']) : [] as $k) {
                $eff[$k] = 'role';
            }
            $cat = perm_catalog();
            foreach (perm_user_overrides($uid) as $k => $effect) {
                if (!isset($cat[$k])) {
                    continue;
                }
                if ($effect === 'deny') {
                    unset($eff[$k]);
                } elseif ($effect === 'allow') {
                    $eff[$k] = isset($eff[$k]) ? 'role' : 'allow';
                }
            }
        }
        $GLOBALS['__perm_eff'][$db][$uid] = $eff;
    }
    return $GLOBALS['__perm_eff'][$db][$uid];
}

/** Forget memoized permissions (after the Super Admin changes roles or overrides in this request). */
function perm_invalidate(): void
{
    unset($GLOBALS['__perm_roles'], $GLOBALS['__perm_rk'], $GLOBALS['__perm_uo'], $GLOBALS['__perm_eff']);
}

/** Does the signed-in user have this permission ("employees.create")? The one check used everywhere. */
function can(string $key): bool
{
    $u = current_user();
    if (!$u) {
        return false;
    }
    if (is_super_user()) {
        return true;
    }
    return isset(perm_effective($u)[$key]);
}

/** Any of several permissions. */
function can_any(array $keys): bool
{
    foreach ($keys as $k) {
        if (can($k)) {
            return true;
        }
    }
    return false;
}

/** May the user do $action on the page of $route (any page for screens reached from two menus)? */
function can_page(string $route, string $action = 'view'): bool
{
    $page = perm_route_page($route);
    if ($page === '') {
        return true;
    }
    $pages = perm_route_any_of($route) ?: [$page];
    foreach ($pages as $pg) {
        if (can($pg . '.' . perm_fit_action($action, perm_pages()[$pg]['actions'] ?? ['view']))) {
            return true;
        }
    }
    return false;
}

/* ======================================================================= reading a request */

/** Closest action a page has (a page without Reject is decided by Approve, without Import by Create, ...). */
function perm_fit_action(string $a, array $acts): string
{
    $chain = [
        'reject' => ['approve', 'edit'], 'import' => ['create', 'edit'], 'generate' => ['edit'], 'configure' => ['edit'],
        'create' => ['edit'], 'delete' => ['edit'], 'approve' => ['edit'], 'export' => ['print', 'view'], 'print' => ['export', 'view'],
    ];
    if (in_array($a, $acts, true)) {
        return $a;
    }
    foreach ($chain[$a] ?? [] as $alt) {
        if (in_array($alt, $acts, true)) {
            return $alt;
        }
    }
    return in_array('edit', $acts, true) && $a !== 'view' ? 'edit' : 'view';
}

/** What a GET request does: view, or print / export from its flags, or what the screen says (perm_route_get_action). */
function perm_get_action(string $route, array $get): string
{
    if (($a = perm_route_get_action($route, $get)) !== null) {
        return $a;
    }
    foreach (['export', 'csv', 'download', 'xlsx', 'backup'] as $k) {
        if (!empty($get[$k])) {
            return 'export';
        }
    }
    foreach (['print', 'pdf'] as $k) {
        if (!empty($get[$k])) {
            return 'print';
        }
    }
    $out = (string)($get['out'] ?? '');
    if (in_array($out, ['csv', 'xlsx'], true)) {
        return 'export';
    }
    if (in_array($out, ['print', 'pdf'], true)) {
        return 'print';
    }
    if (!empty($get['new'])) {
        return 'create';
    }
    foreach (['edit', 'edit_term', 'edit_entry'] as $k) {
        if (!empty($get[$k])) {
            return 'edit';
        }
    }
    return 'view';
}

/** What a POST request does, from the action the form sends (_action, decision) and the record id. */
function perm_post_action(string $route, string $page, array $get, array $post): string
{
    $a = strtolower(trim((string)($post['_action'] ?? $post['action'] ?? $post['act'] ?? '')));
    $ops = perm_pages()[$page]['ops'] ?? [];
    if ($route === 'employee_edit') {
        return !empty($get['id']) || !empty($post['id']) ? 'edit' : 'create';
    }
    if (in_array($route, ['employee_import', 'import_bio', 'import_pay_trans', 'import_att_batch', 'import_emp_trans', 'import_fixed_trans', 'import_deductions', 'import_benefits'], true)) {
        return 'import';
    }
    if (isset($ops[$a]) && $a !== '') {
        return $ops[$a];
    }
    if (isset($post['decision']) && in_array($a, ['', 'leave', 'ot', 'decide'], true)) {
        return in_array(strtolower((string)$post['decision']), ['reject', 'disallow', 'return', 'returned', 'rejected'], true) ? 'reject' : 'approve';
    }
    if ($a !== '') {
        if (preg_match('/(^|_)(reject|disallow|return)(_|$)/', $a)) {
            return 'reject';
        }
        if (preg_match('/(^|_)(delete|del|remove|purge|unlink|void|cancel)(_|$)/', $a)) {
            return 'delete';
        }
        if (preg_match('/(^|_)(approve|finalize|close|reopen|post|verify|release|resolve|decide|authorize|commit)(_|$)/', $a)) {
            return 'approve';
        }
        if (preg_match('/(^|_)(import|upload|load|confirm|check|discard)(_|$)/', $a)) {
            return 'import';
        }
        if (preg_match('/(^|_)(generate|compute|process|summarize|recalc|reprocess|accrue|run)(_|$)/', $a)) {
            return 'generate';
        }
        if (preg_match('/(^|_)(create|new|add|assign|enroll|file|bonus|copy|clone|duplicate)(_|$)/', $a)) {
            return 'create';
        }
        if (preg_match('/(^|_)(config|configure|policy|test)(_|$)/', $a)) {
            return 'configure';
        }
    }
    if (in_array($a, ['save', 'store', 'update', ''], true)) {
        if (array_key_exists('id', $post)) {
            return (int)$post['id'] > 0 ? 'edit' : 'create';
        }
        if ($a === '' && isset($ops[''])) {
            return $ops[''];
        }
    }
    return 'edit';
}

/**
 * Is a request (route + its GET parameters, and the POST body for a submission) allowed for the
 * signed-in user? Used by the server gate (index.php) and by the UI (menu, links, buttons), so both
 * always agree.
 */
function perm_route_allowed(string $route, array $get = [], ?array $post = null): bool
{
    if (!current_user()) {
        return false;
    }
    $page = perm_route_page($route);
    if ($page === '') {
        return true;
    }
    if (is_super_user()) {
        return true;
    }
    $a = $post === null ? perm_get_action($route, $get) : perm_post_action($route, $page, $get, $post);
    $pages = perm_route_any_of($route) ?: [$page];
    foreach ($pages as $pg) {
        $acts = perm_pages()[$pg]['actions'] ?? ['view'];
        $fit = perm_fit_action($a, $acts);
        // every action on a page also needs View of that page
        if (can("$pg.view") && can("$pg.$fit")) {
            return true;
        }
    }
    return perm_route_allow_if($route, $a, $get);
}

/** Server-side gate of index.php: refuses the request with 403 when the effective permissions do not allow it. */
function perm_authorize(string $route): void
{
    if (perm_route_allowed($route, $_GET, is_post() ? $_POST : null)) {
        return;
    }
    $page = perm_route_page($route);
    $a = is_post() ? perm_post_action($route, $page, $_GET, $_POST) : perm_get_action($route, $_GET);
    audit('security', 'access_denied', 'route', $route, ['action' => $a, 'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET']);
    perm_deny();
}

/** Is this an AJAX / fetch request expecting JSON? */
function wants_json(): bool
{
    return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || strpos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
        || !empty($_GET['suggest']) || !empty($_GET['ajax']);
}

/** 403 Unauthorized Access (JSON for AJAX). Says nothing about the restricted page. */
function perm_deny(): void
{
    if (wants_json()) {
        json_response(['error' => 'Unauthorized access', 'status' => 403], 403);
    }
    http_response_code(403);
    $GLOBALS['__perm_denied'] = true;   // the breadcrumb does not name the section of the refused page
    page_header('Access denied');
    echo '<div class="card denied-card"><div class="denied-code">403</div><div><h2>Unauthorized access</h2>'
        . '<p class="muted">You do not have permission to open this page or to perform this action. If you need it, ask your system administrator.</p>'
        . '<p><a class="btn" href="' . url('dashboard') . '">Go to the dashboard</a></p></div></div>';
    page_footer();
    exit;
}

/** Only a Super Admin (Role Management, User Accounts, User Access & Permissions). */
function require_super_user(): void
{
    require_login();
    if (!is_super_user()) {
        audit('security', 'access_denied', 'route', (string)($_GET['p'] ?? ''), ['reason' => 'super admin only']);
        perm_deny();
    }
}

/* ======================================================================= Super Admin protection */

/** Active accounts holding the Super Admin role (support accounts excluded), optionally ignoring one user. */
function perm_active_super_count(?int $exceptUserId = null): int
{
    $n = 0;
    foreach (rows("SELECT * FROM users WHERE is_active = 1 AND is_support = 0 AND (reg_status IS NULL OR reg_status = 'approved')") as $u) {
        if ((int)$u['id'] !== (int)$exceptUserId && is_super_user($u)) {
            $n++;
        }
    }
    return $n;
}

function perm_super_role(): ?array
{
    foreach (perm_roles() as $r) {
        if ((int)$r['is_super'] === 1) {
            return $r;
        }
    }
    return null;
}

/** Assign a role to a user (role_id and the legacy users.role profile together). */
function perm_assign_role(int $userId, int $roleId): void
{
    $r = perm_role($roleId);
    if (!$r) {
        throw new RuntimeException('Invalid role.');
    }
    update('users', ['role_id' => $roleId, 'role' => $r['base_role'] ?: 'employee'], 'id = ?', [$userId]);
    perm_invalidate();
}

/* ======================================================================= synchronization */

/** Hash of the catalog: when the code adds or removes a page or an action, the database follows. */
function perm_catalog_hash(): string
{
    return md5(json_encode([perm_catalog(), array_map(fn($p) => $p['def'], perm_pages()), 'v8']));
}

/** Synchronize the permissions table with the catalog when the code changed (cheap check on every request). */
function perm_sync_if_needed(): void
{
    try {
        if (setting('perm_catalog_hash') === perm_catalog_hash() || !mig_table_exists('permissions')) {
            return;
        }
        perm_sync();
    } catch (Throwable $e) {
        if (!empty($GLOBALS['config']['debug'])) {
            throw $e;
        }
    }
}

/**
 * Write the catalog into `permissions`, grant newly added permissions to the built-in roles as they
 * had them before, make sure every user has a role, and convert the old module / page access of
 * version 7 into Deny overrides (once), so nobody gains or loses access through the upgrade.
 */
function perm_sync(): void
{
    $cat = perm_catalog();
    $known = array_flip(q('SELECT perm_key FROM permissions')->fetchAll(PDO::FETCH_COLUMN));
    $sort = 0;
    $new = [];
    foreach ($cat as $k => $p) {
        $sort++;
        if (!isset($known[$k])) {
            $new[] = $k;
        }
        q('INSERT INTO permissions (perm_key, module, page, action, label, sort_order) VALUES (?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE module = VALUES(module), page = VALUES(page), action = VALUES(action), label = VALUES(label), sort_order = VALUES(sort_order)',
            [$k, $p['module'], $p['page'], $p['action'], mb_substr($p['label'], 0, 120), $sort]);
    }
    $stale = array_diff(array_keys($known), array_keys($cat));
    if ($stale) {
        $stale = array_values($stale);
        q('DELETE FROM role_permissions WHERE perm_key IN (' . in_list($stale) . ')', $stale);
        q('DELETE FROM user_permission_overrides WHERE perm_key IN (' . in_list($stale) . ')', $stale);
        q('DELETE FROM permissions WHERE perm_key IN (' . in_list($stale) . ')', $stale);
    }
    // built-in roles get the permissions that are new to the database, as their old access allowed
    perm_invalidate();
    $fresh = !$known;
    foreach (perm_roles(true) as $r) {
        if ((int)$r['is_system'] !== 1 || (int)$r['is_super'] === 1) {
            continue;
        }
        $def = array_intersect(perm_legacy_defaults((string)$r['code']), $fresh ? array_keys($cat) : $new);
        foreach ($def as $k) {
            q('INSERT IGNORE INTO role_permissions (role_id, perm_key) VALUES (?, ?)', [(int)$r['id'], $k]);
        }
    }
    // a new permission that splits off an existing one (Basic Salary from the Employee Masterfile): every
    // custom role and every Allow override holding the old permission gets the new one, so no access is lost
    if ($known) {
        foreach (perm_pages() as $route => $p) {
            foreach ($p['inherit'] ?? [] as $act => $from) {
                $k = "$route.$act";
                if (!in_array($k, $new, true)) {
                    continue;
                }
                q('INSERT IGNORE INTO role_permissions (role_id, perm_key) SELECT rp.role_id, ? FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
                   WHERE rp.perm_key = ? AND r.is_super = 0 AND r.is_system = 0', [$k, $from]);
                q("INSERT IGNORE INTO user_permission_overrides (user_id, perm_key, effect) SELECT user_id, ?, effect FROM user_permission_overrides WHERE perm_key = ?", [$k, $from]);
            }
        }
    }
    // every account carries a role id
    q('UPDATE users u JOIN roles r ON r.code = u.role AND r.is_system = 1 SET u.role_id = r.id WHERE u.role_id IS NULL');
    if (!setting('perm_legacy_converted')) {
        perm_convert_legacy_access();
        q("INSERT INTO settings (skey, svalue, label, grp, input_type, sort_order) VALUES ('perm_legacy_converted', '1', 'Legacy access converted', 'system', 'hidden', 0)
           ON DUPLICATE KEY UPDATE svalue = '1'");
    }
    q("INSERT INTO settings (skey, svalue, label, grp, input_type, sort_order) VALUES ('perm_catalog_hash', ?, 'Permission catalog', 'system', 'hidden', 0)
       ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)", [perm_catalog_hash()]);
    setting('__reload__');
    perm_invalidate();
}

/**
 * Version 7 limited users by module (users.module_access) or page by page (user_page_access, users.page_access).
 * Each of those limits becomes a Deny override on the permissions the role would otherwise give.
 */
function perm_convert_legacy_access(): void
{
    $map = ['view' => 'view', 'create' => 'add', 'import' => 'add', 'edit' => 'edit', 'configure' => 'edit', 'generate' => 'edit',
        'delete' => 'delete', 'print' => 'print', 'export' => 'print', 'approve' => 'approve', 'reject' => 'approve'];
    $cat = perm_catalog();
    foreach (rows("SELECT * FROM users WHERE (page_access = 1 OR (module_access IS NOT NULL AND module_access <> '')) AND role <> 'admin'") as $u) {
        $role = perm_user_role($u);
        $keys = $role ? perm_role_keys((int)$role['id']) : [];
        $deny = [];
        if (!empty($u['page_access'])) {
            $grid = [];
            foreach (rows('SELECT * FROM user_page_access WHERE user_id = ?', [$u['id']]) as $r) {
                $grid[$r['route']] = $r;
            }
            foreach ($keys as $k) {
                $p = $cat[$k];
                if ($p['module'] === 'ess') {
                    continue;
                }
                $g = $grid[$p['page']] ?? null;
                if (!$g || empty($g['can_view']) || empty($g['can_' . $map[$p['action']]])) {
                    $deny[] = $k;
                }
            }
        } else {
            $mods = array_filter(array_map('trim', explode(',', (string)$u['module_access'])));
            foreach ($keys as $k) {
                $lm = PERM_LEGACY_MODULE[$cat[$k]['module']] ?? 'common';
                if ($lm !== 'common' && !in_array($lm, $mods, true)) {
                    $deny[] = $k;
                }
            }
        }
        foreach ($deny as $k) {
            q("INSERT IGNORE INTO user_permission_overrides (user_id, perm_key, effect, created_at) VALUES (?, ?, 'deny', NOW())", [$u['id'], $k]);
        }
        update('users', ['page_access' => 0, 'module_access' => null], 'id = ?', [$u['id']]);
        if ($deny) {
            audit('admin', 'user_permissions_migrated', 'users', $u['id'], ['deny_overrides' => count($deny), 'from' => !empty($u['page_access']) ? 'page access' : 'module access']);
        }
    }
}

/* ======================================================================= permission-aware UI */

/**
 * Last step of every HTML page of a user who is not a Super Admin: links, buttons and forms the user
 * may not use are taken out, decided by perm_route_allowed() exactly like the server gate. Links in
 * text are kept as plain text, action buttons disappear, a form left without any allowed button
 * disappears unless it holds a table (its data stays readable). The server still refuses the request
 * if somebody rebuilds the button by hand.
 */
function perm_ui_filter(string $html): string
{
    try {
        if (stripos($html, '<html') === false || !current_user() || is_super_user()) {
            return $html;
        }
        foreach (headers_list() as $hd) {
            if (stripos($hd, 'content-type:') === 0 && stripos($hd, 'text/html') === false) {
                return $html;
            }
        }
        $current = preg_replace('/[^a-z0-9_]/', '', (string)($_GET['p'] ?? 'dashboard'));
        // never touch scripts
        $parts = preg_split('/(<script\b.*?<\/script>)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                continue;
            }
            $part = preg_replace_callback('/<form\b[^>]*>.*?<\/form>/is', fn($m) => perm_ui_form($m[0], $current), $part);
            $part = preg_replace_callback('/<a\b([^>]*)>(.*?)<\/a>/is', fn($m) => perm_ui_link($m), $part);
            $parts[$i] = $part;
        }
        return implode('', $parts);
    } catch (Throwable $e) {
        return $html;
    }
}

/** Route and GET parameters of an application URL ('' when the URL is not a page of the application). */
function perm_ui_parse_url(string $href): array
{
    $href = html_entity_decode($href, ENT_QUOTES, 'UTF-8');
    if ($href === '' || $href[0] === '#' || preg_match('#^(https?:|mailto:|tel:|javascript:|data:)#i', $href)) {
        return ['', []];
    }
    $q = parse_url($href, PHP_URL_QUERY);
    $path = (string)parse_url($href, PHP_URL_PATH);
    if ($path !== '' && basename($path) !== 'index.php') {
        return ['', []];
    }
    parse_str((string)$q, $get);
    $route = preg_replace('/[^a-z0-9_]/', '', (string)($get['p'] ?? ''));
    return [$route, $get];
}

function perm_ui_attr(string $tag, string $name): ?string
{
    return preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*"([^"]*)"/i', $tag, $m) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')
        : (preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*\'([^\']*)\'/i', $tag, $m) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : null);
}

function perm_ui_link(array $m): string
{
    [$route, $get] = perm_ui_parse_url((string)perm_ui_attr('<a' . $m[1] . '>', 'href'));
    if ($route === '' || perm_route_allowed($route, $get)) {
        return $m[0];
    }
    $cls = (string)perm_ui_attr('<a' . $m[1] . '>', 'class');
    if (preg_match('/\b(btn|nav-link|nav-sub|tile|quick|chip|tab-link|icon-btn)\b/', $cls) || trim(strip_tags($m[2])) === '') {
        return '';
    }
    return '<span class="perm-text">' . $m[2] . '</span>';
}

function perm_ui_form(string $form, string $current): string
{
    preg_match('/^<form\b[^>]*>/is', $form, $open);
    $tag = $open[0];
    $method = strtolower((string)(perm_ui_attr($tag, 'method') ?? 'get'));
    $action = (string)(perm_ui_attr($tag, 'action') ?? '');
    [$route, $get] = $action !== '' ? perm_ui_parse_url($action) : ['', []];
    // fields the form sends
    $fields = [];
    preg_match_all('/<input\b[^>]*>/is', $form, $inputs);
    foreach ($inputs[0] as $in) {
        $type = strtolower((string)(perm_ui_attr($in, 'type') ?? 'text'));
        $name = perm_ui_attr($in, 'name');
        if ($name !== null && in_array($type, ['hidden'], true)) {
            $fields[$name] = (string)perm_ui_attr($in, 'value');
        }
    }
    if ($method !== 'post') {
        $r = preg_replace('/[^a-z0-9_]/', '', (string)($fields['p'] ?? ($route ?: $current)));
        return $r === '' || perm_route_allowed($r, ['p' => $r]) ? $form : '';
    }
    if ($route === '') {
        $route = $current;
        $get = $_GET;
    }
    if (perm_route_page($route) === '') {
        return $form;
    }
    $allowedAny = false;
    $submits = 0;
    $form = preg_replace_callback('/<button\b([^>]*)>.*?<\/button>|<input\b([^>]*type\s*=\s*"submit"[^>]*)>/is',
        function ($b) use ($fields, $route, $get, &$allowedAny, &$submits) {
            $attrs = $b[1] !== '' ? $b[1] : ($b[2] ?? '');
            $type = strtolower((string)(perm_ui_attr('<x' . $attrs . '>', 'type') ?? 'submit'));
            if ($type !== 'submit') {
                return $b[0];
            }
            $submits++;
            $post = $fields;
            $name = perm_ui_attr('<x' . $attrs . '>', 'name');
            if ($name !== null) {
                $post[$name] = (string)perm_ui_attr('<x' . $attrs . '>', 'value');
            }
            if (perm_route_allowed($route, $get, $post)) {
                $allowedAny = true;
                return $b[0];
            }
            return '';
        }, $form);
    if ($submits === 0) {
        // submitted by script (auto-submit selects): judge the form itself
        return perm_route_allowed($route, $get, $fields) ? $form : (stripos($form, '<table') !== false ? $form : '');
    }
    if (!$allowedAny && stripos($form, '<table') === false) {
        return '';
    }
    return $form;
}

/**
 * Active accounts (not support accounts, not Super Admins) whose effective permissions include $key,
 * in account order. Used where the system picks a person by what they may do, for example the HR
 * approver of a leave chain (leave_applications.approve) instead of a hard-coded role.
 */
function perm_users_with(string $key, string $extraWhere = '1=1', array $params = []): array
{
    $out = [];
    foreach (rows("SELECT * FROM users WHERE is_active = 1 AND is_support = 0 AND (reg_status IS NULL OR reg_status = 'approved') AND $extraWhere ORDER BY id", $params) as $u) {
        if (!is_super_user($u) && isset(perm_effective($u)[$key]) && isset(perm_effective($u)[explode('.', $key)[0] . '.view'])) {
            $out[] = $u;
        }
    }
    return $out;
}

/** Active roles for a role dropdown: id => name. */
function role_options(bool $withInactive = false): array
{
    $out = [];
    foreach (perm_roles() as $r) {
        if ($withInactive || $r['status'] === 'active') {
            $out[(int)$r['id']] = $r['name'] . ($r['status'] === 'active' ? '' : ' (inactive)');
        }
    }
    return $out;
}
