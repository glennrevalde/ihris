<?php
/**
 * Employee Loan / Deduction Request (v20, database version 18).
 *
 * The employee applies in My Workspace > Loan / Deduction Request: the type (a code of the Employee
 * Transactions Table marked "Portal request"), the amount, the term in paydays and the mode of payment
 * (every 1st, 2nd, 3rd, 4th pay or every pay). The interest rate follows the code (0 = no interest) and the
 * amortization is computed the same way as the transactions with terms (Employee's Transactions):
 *   interest = amount x rate (% a month) x months (paydays / paydays a month of the mode)
 *   principal amort. = amount / term;  interest amort. = interest / term;  total amort. = their sum.
 *
 * Status: Draft -> Submitted for approval -> Approved or Rejected (the employee may cancel while submitted).
 * The immediate superior, HR (the users who edit the 201 files or encode Employee's Transactions) or an administrator approves:
 * the amount approved, the term, the mode, the rate and the start of payment. On approval the request becomes
 * a transaction with terms (employee_term_trans) taken by the payroll from the start of payment, so HR no
 * longer encodes it.
 */

const LOAN_REQ_STATUS = ['draft' => 'Draft', 'submitted' => 'Submitted for approval', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];
/** Mode of payment, in the order of the request form (keys are the transaction terms of the payroll). */
const LOAN_REQ_MODES = ['first_pay' => 'Every 1st pay', 'second_pay' => 'Every 2nd pay', 'third_pay' => 'Every 3rd pay', 'fourth_pay' => 'Every 4th pay', 'every_pay' => 'Every pay'];

/** Is the database ready for the requests (version 18)? */
function loan_req_supported(): bool
{
    static $ok = null;
    return $ok ??= (bool)val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'loan_requests'");
}

/** The codes an employee may apply for: id => row (code, name, int_rate, category). */
function loan_req_types(): array
{
    $out = [];
    foreach (rows("SELECT id, code, name, int_rate, category FROM deduction_types WHERE is_active = 1 AND ess_request = 1 AND category <> 'statutory' ORDER BY category = 'loan' DESC, name") as $r) {
        $out[(int)$r['id']] = $r;
    }
    return $out;
}

/** Pay frequency of an employee (semi_monthly when unknown). */
function loan_req_frequency(int $empId): string
{
    return (string)(val('SELECT pg.frequency FROM employees e LEFT JOIN pay_groups pg ON pg.id = e.pay_group_id WHERE e.id = ?', [$empId]) ?: 'semi_monthly');
}

/** Amortization of an amount: interest, principal / interest / total per payday (the transactions with terms rule). */
function loan_req_compute(float $amount, int $terms, float $rate, string $mode, string $frequency): array
{
    $c = term_compute(['principal' => $amount, 'monthly_rate' => $rate, 'terms' => max(1, $terms), 'trans_term' => isset(LOAN_REQ_MODES[$mode]) ? $mode : 'every_pay'], $frequency);
    return ['interest' => $c['interest_amount'], 'pp_principal' => $c['per_pay_principal'], 'pp_interest' => $c['per_pay_interest'], 'per_pay' => $c['per_pay'],
        'total' => $c['total_amount'], 'months' => $c['months']];
}

function loan_req_get(int $id): ?array
{
    return row("SELECT r.*, e.employee_no, e.first_name, e.last_name, e.middle_name, e.suffix, e.position, e.supervisor_id, e.pay_group_id, e.default_branch_id,
                       e.rate_type, ou.name AS unit_name, dt.code AS type_code, dt.name AS type_name, u.full_name AS decided_name, pg.frequency
                FROM loan_requests r JOIN employees e ON e.id = r.employee_id JOIN deduction_types dt ON dt.id = r.deduction_type_id
                LEFT JOIN org_units ou ON ou.id = e.org_unit_id LEFT JOIN users u ON u.id = r.decided_by LEFT JOIN pay_groups pg ON pg.id = e.pay_group_id
                WHERE r.id = ?", [$id]);
}

/**
 * Who decides: the immediate superior of the employee (the team of the supervisor or unit head), HR (the users who
 * encode Employee's Transactions, within their company / branch and department assignment) or an administrator.
 * Nobody decides his / her own request.
 */
function loan_req_all_access(): bool
{
    // HR (edits the 201 files) and payroll (encodes Employee's Transactions) see every request of their assignment
    return is_super_user() || can('employees.edit') || can('emp_transactions.create');
}

function loan_req_can_decide(array $req): bool
{
    if (!can('loan_approvals.approve') && !can('loan_approvals.reject')) {
        return false;
    }
    if ((int)$req['employee_id'] === (int)my_employee_id()) {
        return false;
    }
    if (loan_req_all_access()) {
        return scope_allows((int)$req['employee_id']);
    }
    return in_array((int)$req['employee_id'], my_team_ids(), true);
}

/** SQL limit of the requests a user may see in the approval list. */
function loan_req_visible_sql(string $a = 'e'): string
{
    $me = (int)my_employee_id();
    if (loan_req_all_access()) {
        return scope_sql($a) . ($me ? " AND $a.id <> $me" : '');
    }
    $team = my_team_ids();
    return ' AND ' . $a . '.id IN (' . ($team ? implode(',', array_map('intval', $team)) : '0') . ')';
}

/** Check and normalize the values of a request (the employee side). Returns [values, errors]. */
function loan_req_clean(array $in, int $empId): array
{
    $types = loan_req_types();
    $err = [];
    $typeId = (int)($in['deduction_type_id'] ?? 0);
    if (!isset($types[$typeId])) {
        $err[] = 'Choose the type of loan / deduction.';
    }
    $amount = round((float)str_replace(',', '', (string)($in['amount_applied'] ?? '0')), 2);
    if ($amount <= 0) {
        $err[] = 'Enter the amount applied for.';
    } elseif ($amount > 9999999) {
        $err[] = 'The amount is too large.';
    }
    $terms = (int)($in['terms'] ?? 0);
    $max = max(1, (int)setting('loan_request_max_terms', 48));
    if ($terms < 1) {
        $err[] = 'Enter the term in paydays (1 or more).';
    } elseif ($terms > $max) {
        $err[] = 'The term can be at most ' . $max . ' paydays.';
    }
    $mode = (string)($in['trans_term'] ?? 'every_pay');
    if (!isset(LOAN_REQ_MODES[$mode])) {
        $mode = 'every_pay';
    }
    $freq = loan_req_frequency($empId);
    if (in_array($mode, ['third_pay', 'fourth_pay'], true) && $freq !== 'weekly') {
        $err[] = 'Every 3rd / 4th pay is for weekly pay only; choose every 1st pay, every 2nd pay or every pay.';
    }
    $rate = isset($types[$typeId]) ? (float)$types[$typeId]['int_rate'] : 0.0;   // the interest follows the setup of the code
    $c = loan_req_compute($amount, max(1, $terms), $rate, $mode, $freq);
    $purpose = trim((string)($in['purpose'] ?? ''));
    return [[
        'deduction_type_id' => $typeId, 'amount_applied' => $amount, 'terms' => max(1, $terms), 'int_rate' => $rate, 'interest_amount' => $c['interest'],
        'trans_term' => $mode, 'pp_principal' => $c['pp_principal'], 'pp_interest' => $c['pp_interest'], 'purpose' => $purpose !== '' ? mb_substr($purpose, 0, 255) : null,
    ], $err];
}

/** E-mail to the people who decide (the immediate superior, else nobody: HR sees it in the list). Best effort. */
function loan_req_notify_submitted(array $req): void
{
    try {
        if (!$req['supervisor_id']) {
            return;
        }
        $to = row('SELECT u.email, u.full_name FROM users u WHERE u.employee_id = ? AND u.is_active = 1 AND u.email IS NOT NULL ORDER BY u.id LIMIT 1', [(int)$req['supervisor_id']])
            ?: row('SELECT email, CONCAT(first_name, " ", last_name) AS full_name FROM employees WHERE id = ? AND email IS NOT NULL', [(int)$req['supervisor_id']]);
        if ($to && !empty($to['email'])) {
            send_mail((string)$to['email'], (string)$to['full_name'], 'Loan / deduction request ' . $req['request_no'] . ' of ' . full_name($req, false),
                '<p>' . h(full_name($req, false)) . ' applied for <strong>' . h($req['type_name']) . '</strong> of ' . h(money($req['amount_applied'])) . ' in ' . (int)$req['terms']
                . ' paydays.</p><p>Open <a href="' . h(abs_url('loan_approvals', ['id' => $req['id']])) . '">Loan / Deduction Approvals</a> to approve or reject it.</p>', 'loan_request', (int)$req['id']);
        }
    } catch (Throwable $e) {
        // the request is saved even when the mail cannot be sent
    }
}

/** E-mail to the employee when the request is decided. Best effort. */
function loan_req_notify_decided(array $req): void
{
    try {
        $to = row('SELECT u.email, u.full_name FROM users u WHERE u.employee_id = ? AND u.is_active = 1 AND u.email IS NOT NULL ORDER BY u.id LIMIT 1', [(int)$req['employee_id']]);
        if ($to && !empty($to['email'])) {
            $ok = $req['status'] === 'approved';
            send_mail((string)$to['email'], (string)$to['full_name'], 'Your loan / deduction request ' . $req['request_no'] . ' was ' . ($ok ? 'approved' : 'rejected'),
                '<p>' . h($req['type_name']) . ': ' . ($ok ? 'approved for ' . h(money($req['amount_approved'])) . ' in ' . (int)$req['terms_approved'] . ' paydays, payment from '
                . h(fmt_date($req['start_date'])) . '.' : 'rejected.') . ($req['decision_remarks'] ? ' ' . h($req['decision_remarks']) : '') . '</p>', 'loan_request', (int)$req['id']);
        }
    } catch (Throwable $e) {
    }
}

/**
 * Approve: the values approved (amount, term, mode, rate, start of payment) and the transaction with terms that
 * the payroll will take from the start of payment.
 */
function loan_req_approve(int $id, array $in): array
{
    $req = loan_req_get($id);
    if (!$req || $req['status'] !== 'submitted') {
        throw new RuntimeException('Only a request submitted for approval can be approved.');
    }
    if (!loan_req_can_decide($req)) {
        throw new RuntimeException('You cannot decide this request.');
    }
    $amount = round((float)str_replace(',', '', (string)($in['amount_approved'] ?? '0')), 2);
    $terms = (int)($in['terms_approved'] ?? 0);
    $mode = isset(LOAN_REQ_MODES[$in['trans_term_approved'] ?? '']) ? (string)$in['trans_term_approved'] : $req['trans_term'];
    $rate = isset($in['int_rate_approved']) && $in['int_rate_approved'] !== '' ? round((float)$in['int_rate_approved'], 3) : (float)$req['int_rate'];
    $start = (string)($in['start_date'] ?? '');
    if ($amount <= 0) {
        throw new RuntimeException('Enter the amount approved.');
    }
    if ($terms < 1 || $terms > 520) {
        throw new RuntimeException('Enter the term in paydays (1 to 520).');
    }
    if ($rate < 0 || $rate > 100) {
        throw new RuntimeException('The interest rate must be 0 to 100% a month.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
        throw new RuntimeException('Give the start of payment (the first payday the amortization is taken).');
    }
    $freq = $req['frequency'] ?: 'semi_monthly';
    if (in_array($mode, ['third_pay', 'fourth_pay'], true) && $freq !== 'weekly') {
        throw new RuntimeException('Every 3rd / 4th pay is for weekly pay only.');
    }
    $c = loan_req_compute($amount, $terms, $rate, $mode, $freq);
    $remarks = trim((string)($in['decision_remarks'] ?? ''));
    return tx(function () use ($req, $amount, $terms, $mode, $rate, $start, $c, $remarks) {
        $termId = term_create([
            'employee_id' => (int)$req['employee_id'], 'trans_type' => 'deduction', 'code_id' => (int)$req['deduction_type_id'], 'doc_no' => $req['request_no'],
            'trans_date' => today(), 'principal' => $amount, 'monthly_rate' => $rate, 'terms' => $terms, 'trans_term' => $mode, 'start_date' => $start,
            'branch_id' => null, 'remarks' => mb_substr('Loan / deduction request ' . $req['request_no'] . ' approved by ' . (current_user()['full_name'] ?? 'the approver') . ($remarks !== '' ? ': ' . $remarks : ''), 0, 255),
        ]);
        update('loan_requests', ['status' => 'approved', 'amount_approved' => $amount, 'terms_approved' => $terms, 'int_rate_approved' => $rate, 'interest_approved' => $c['interest'],
            'trans_term_approved' => $mode, 'start_date' => $start, 'decided_by' => user_id(), 'decided_at' => now(), 'decision_remarks' => $remarks !== '' ? mb_substr($remarks, 0, 255) : null,
            'term_trans_id' => $termId, 'pp_principal' => $c['pp_principal'], 'pp_interest' => $c['pp_interest'], 'updated_at' => now()], 'id = ?', [(int)$req['id']]);
        audit('payroll', 'loan_request_approve', 'loan_requests', (int)$req['id'], ['amount' => $amount, 'terms' => $terms, 'mode' => $mode, 'rate' => $rate, 'start' => $start, 'term_trans_id' => $termId]);
        $done = loan_req_get((int)$req['id']);
        loan_req_notify_decided($done);
        return $done;
    });
}

function loan_req_reject(int $id, string $remarks): void
{
    $req = loan_req_get($id);
    if (!$req || $req['status'] !== 'submitted') {
        throw new RuntimeException('Only a request submitted for approval can be rejected.');
    }
    if (!loan_req_can_decide($req)) {
        throw new RuntimeException('You cannot decide this request.');
    }
    $remarks = trim($remarks);
    if ($remarks === '') {
        throw new RuntimeException('Give the reason of the rejection.');
    }
    update('loan_requests', ['status' => 'rejected', 'decided_by' => user_id(), 'decided_at' => now(), 'decision_remarks' => mb_substr($remarks, 0, 255), 'updated_at' => now()], 'id = ?', [$id]);
    audit('payroll', 'loan_request_reject', 'loan_requests', $id, ['remarks' => $remarks]);
    loan_req_notify_decided(loan_req_get($id));
}

/** Default start of payment: the first open payroll period of the employee's pay code that ends from today. */
function loan_req_default_start(array $req): string
{
    return (string)(val("SELECT period_start FROM attendance_cutoffs WHERE pay_group_id = ? AND status <> 'locked' AND period_end >= ? ORDER BY period_start LIMIT 1", [(int)$req['pay_group_id'], today()])
        ?: today());
}

/** Requests waiting for the signed-in user (for the Pending Approvals page and the dashboard). */
function loan_req_pending_count(): int
{
    if (!loan_req_supported() || (!can('loan_approvals.approve') && !can('loan_approvals.reject'))) {
        return 0;
    }
    return (int)val("SELECT COUNT(*) FROM loan_requests r JOIN employees e ON e.id = r.employee_id WHERE r.status = 'submitted'" . loan_req_visible_sql('e'));
}
