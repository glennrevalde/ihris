<?php
/** Company Parameters Setup hub (formerly Emp. Setup) — all payroll / attendance reference tables in one place. */
$cnt = function (string $table, string $where = '') {
    try {
        return (int)val("SELECT COUNT(*) FROM `$table`" . ($where ? " WHERE $where" : ''));
    } catch (PDOException $e) {
        return 0;
    }
};
$groups = [
    'Company & organization' => [
        ['branches', 'Company Table', 'building', $cnt('branches'), 'Companies, branches, employer IDs, approver & checker'],
        ['departments', 'Department Table', 'users', $cnt('org_units'), 'Sections, departments, divisions and groups'],
        ['locations', 'Location Table', 'building', $cnt('locations'), 'Work locations'],
        ['jobs', 'Job Table', 'file', $cnt('jobs'), 'Jobs with category, sub-category and OT/OL'],
    ],
    'Employee classification' => [
        ['emp_types', 'Employee Type Table', 'user', $cnt('emp_types'), 'Monthly-to-daily conversion factor'],
        ['emp_groups', 'Employee Group Table', 'users', $cnt('emp_groups'), 'Groups, log/grp and cost centers'],
        ['class_groups', 'Class Group Table', 'users', $cnt('class_groups'), 'Class groupings'],
        ['rank_categories', 'Rank Category Table', 'chart', $cnt('rank_categories'), 'Rank and file, supervisory, managerial'],
        ['classifications', 'Classification Table', 'file', $cnt('classifications'), 'Employee classifications'],
        ['exemption_codes', 'Exemption Code Table', 'shield', $cnt('exemption_codes'), 'Tax exemption codes (MWE, exempt)'],
    ],
    'Pay & time' => [
        ['pay_groups', 'Pay Code Table', 'money', $cnt('pay_groups'), 'Frequency, SSS / Pag-IBIG / PhilHealth / tax deduction mode'],
        ['shifts', 'Shift Table', 'clock', $cnt('shifts'), 'Time in/out, 2 breaks, required & NP hours, flexi'],
        ['holidays', 'Annual Holidays', 'calendar', $cnt('holidays', 'YEAR(holiday_date) = ' . (int)date('Y')), 'Regular and special holidays this year'],
        ['leave_types', 'Leave Category Table', 'calendar', $cnt('leave_types'), 'Leave types and their transaction column'],
        ['payroll_params', 'Payroll Parameters', 'cog', $cnt('pay_rates'), 'Comp. rates, processing, annual/acct., payslip'],
        ['periods', 'Period Parameters', 'calendar', $cnt('attendance_cutoffs'), 'Payroll periods per pay code'],
    ],
    'Earnings, deductions & accounts' => [
        ['benefit_types', 'Benefits Table', 'money', $cnt('benefit_types'), 'Allowances: fixed or per day / hour / week of duty'],
        ['earning_types', 'Transaction Table', 'file', $cnt('earning_types') + $cnt('deduction_types'), 'Earning and deduction transaction codes'],
        ['fixed_trans_codes', 'Fixed Transaction Table', 'file', $cnt('fixed_trans_codes'), 'Recurring fixed amounts, fixed contributions'],
        ['gl_accounts', 'Payroll Code Table', 'chart', $cnt('gl_accounts'), 'GL account codes for the payroll journal'],
        ['bank_setup', "Employee's Bank Setup", 'building', $cnt('employees', "status = 'active' AND bank_account_no IS NOT NULL AND bank_account_no <> ''"), 'Payroll bank accounts of employees'],
        ['signatories', 'Signatory', 'user', 3, 'Prepared / checked / approved by on reports'],
    ],
    'Government tables' => [
        ['sss_table', 'SSS Table', 'shield', $cnt('sss_table'), 'SSS contribution schedule (EE, ER, EC, MPF)'],
        ['tax_table', 'Tax Table', 'shield', $cnt('tax_brackets'), 'BIR withholding tax tables'],
        ['philhealth', 'Health Table', 'shield', $cnt('philhealth_params'), 'PhilHealth premium parameters'],
        ['pagibig', 'Pag-IBIG Table', 'shield', $cnt('pagibig_table'), 'Pag-IBIG contribution rates'],
        ['late_brackets', 'Lates / Undertime Table', 'clock', $cnt('late_brackets'), 'Late & undertime deduction brackets'],
    ],
];
page_header('Company Parameters Setup', ['subtitle' => 'Reference tables used by attendance and payroll processing: the company, its departments, pay codes, transaction codes and the government tables.']);
foreach ($groups as $title => $tiles): ?>
  <h2 class="section-title"><?= h($title) ?></h2>
  <div class="tile-grid">
    <?php foreach ($tiles as $t): ?>
      <a class="tile" href="<?= url($t[0]) ?>">
        <span class="tile-icon"><?= icon($t[2]) ?></span>
        <span class="tile-body"><span class="tile-title"><?= h($t[1]) ?></span><span class="tile-sub"><?= h($t[4]) ?></span></span>
        <span class="tile-count"><?= (int)$t[3] ?></span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endforeach;
page_footer();
