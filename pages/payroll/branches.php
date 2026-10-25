<?php
/**
 * Company Table (companies and their branches = employers of record).
 * A company row has no branch code; branches share the company code and carry a branch code (BCde).
 */
$companyOnly = (bool)get('company_only');
$defaults = ['is_active' => 1, 'geofence_radius_m' => 150];
if (get('new') && get('from')) {
    $src = row('SELECT * FROM branches WHERE id = ?', [(int)get('from')]);
    if ($src) {
        foreach (['company_code', 'company_name', 'tin', 'rdo_code', 'sss_er_no', 'philhealth_er_no', 'pagibig_er_no', 'bank_name', 'bank_account_no',
                     'bank_company_code', 'init_code', 'location_id', 'approver_name', 'checker_name', 'address'] as $k) {
            $defaults[$k] = $src[$k];
        }
        $defaults['company_code'] = $src['company_code'] ?: $src['code'];
        $next = (int)val("SELECT MAX(CAST(branch_code AS UNSIGNED)) FROM branches WHERE company_code = ?", [$defaults['company_code']]);
        $defaults['branch_code'] = (string)($next ? $next + 100 : 100);
        $defaults['code'] = $defaults['company_code'] . '-' . $defaults['branch_code'];
    }
}
$locations = pairs("SELECT id, name FROM locations ORDER BY name");
crud_page([
    'page' => 'branches', 'import' => 'branches', 'table' => 'branches', 'title' => 'Company Table', 'singular' => 'company / branch', 'module' => 'payroll',
    'subtitle' => 'Companies and branches (employers of record) with their SSS, Pag-IBIG and PhilHealth employer IDs, approver and checker, and the GPS geofence used for web check-ins.',
    'order' => "COALESCE(NULLIF(company_code, ''), code), COALESCE(branch_code, ''), code", 'edit_roles' => ['payroll', 'hr'], 'set_default' => true,
    'where' => $companyOnly ? "(branch_code IS NULL OR branch_code = '')" : null,
    'actions' => ' <a class="btn' . ($companyOnly ? ' btn-primary' : '') . '" href="' . url('branches', $companyOnly ? [] : ['company_only' => 1]) . '">' . ($companyOnly ? 'View all branches' : 'View company only') . '</a>'
        . ' <a class="btn" href="' . url('payroll_setup') . '">All setup tables</a>',
    'fields' => [
        'h1' => ['label' => 'Company / branch', 'type' => 'heading'],
        'company_code' => ['label' => 'Company code', 'help' => 'e.g. 1000; branches of one company share this code'],
        'branch_code' => ['label' => 'Branch code (BCde)', 'help' => 'Blank for the company (head office) row'],
        'code' => ['label' => 'Unique short code', 'required' => true, 'help' => 'Used in imports and reports, e.g. HO or 1300-100'],
        'name' => ['label' => 'Company / branch name', 'required' => true],
        'company_name' => ['label' => 'Employer (registered company name)', 'required' => true],
        'init_code' => ['label' => 'Init code'],
        'location_id' => ['label' => 'Location', 'type' => 'select', 'options' => $locations],
        'address' => ['label' => 'Address', 'wide' => true],
        'h2' => ['label' => 'Government registration', 'type' => 'heading'],
        'tin' => ['label' => 'Employer TIN'],
        'rdo_code' => ['label' => 'RDO code'],
        'sss_er_no' => ['label' => 'SSS employer ID'],
        'pagibig_er_no' => ['label' => 'Pag-IBIG employer ID'],
        'philhealth_er_no' => ['label' => 'PhilHealth employer ID'],
        'h3' => ['label' => 'Signatories & bank', 'type' => 'heading'],
        'approver_name' => ['label' => 'Approver name'],
        'checker_name' => ['label' => 'Checker name'],
        'bank_name' => ['label' => 'Payroll bank'],
        'bank_account_no' => ['label' => 'Funding account no.'],
        'bank_company_code' => ['label' => 'Bank company / client code'],
        'h4' => ['label' => 'Web check-in geofence', 'type' => 'heading'],
        'latitude' => ['label' => 'Latitude', 'type' => 'number', 'step' => 'any'],
        'longitude' => ['label' => 'Longitude', 'type' => 'number', 'step' => 'any'],
        'geofence_radius_m' => ['label' => 'Geofence radius (meters)', 'type' => 'number', 'default' => 150],
        'is_active' => ['label' => 'Active', 'type' => 'checkbox'],
    ],
    'defaults' => $defaults,
    'list' => ['company_code', 'branch_code', 'name', 'sss_er_no', 'pagibig_er_no', 'philhealth_er_no', 'init_code', 'location_id', 'approver_name', 'checker_name', 'is_active'],
    'format' => [
        'company_code' => fn($v, $r) => h($v ?: $r['code']),
        'location_id' => fn($v) => h($locations[$v] ?? ''),
        'name' => fn($v, $r) => h($v) . '<div class="small muted">' . h($r['company_name']) . '</div>',
    ],
    'row_actions' => fn($r) => '<a class="btn btn-sm btn-ghost" href="' . url('branches', ['new' => 1, 'from' => $r['id']]) . '" title="Add a branch of this company">New branch</a>',
    'validate' => function ($d, $id) {
        $e = [];
        if ($d['company_code'] !== null && $d['company_code'] !== '' && (int)val("SELECT COUNT(*) FROM branches WHERE company_code = ? AND COALESCE(branch_code,'') = ? AND id <> ?", [$d['company_code'], (string)$d['branch_code'], $id])) {
            $e[] = 'Another row already uses this company code and branch code.';
        }
        return $e;
    },
]);
