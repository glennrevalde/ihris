<?php
/**
 * Leave management engine:
 *  - statutory & company leave types with configurable accrual (annual, monthly, length-of-service, per event)
 *  - balances, carry-over and cash conversion
 *  - multi-step approval chain (supervisor -> department head -> HR) with EMAIL-BASED approval links
 *  - approved leave posts automatically to the Daily Time Record
 */

function leave_type_row(int $id): ?array
{
    return row('SELECT * FROM leave_types WHERE id = ?', [$id]);
}

/** Can the employee avail of this leave type? Returns null when eligible, otherwise the reason. */
function leave_ineligibility(array $emp, array $type, ?string $asOf = null): ?string
{
    $asOf = $asOf ?: today();
    if ($type['gender'] !== 'any' && $type['gender'] !== $emp['gender']) {
        return $type['name'] . ' is only available to ' . $type['gender'] . ' employees.';
    }
    if ($type['civil_status_req'] && $emp['civil_status'] !== $type['civil_status_req']) {
        return $type['name'] . ' requires civil status: ' . $type['civil_status_req'] . '.';
    }
    if ($type['requires_solo_parent'] && !$emp['is_solo_parent']) {
        return $type['name'] . ' requires a valid Solo Parent ID on file.';
    }
    if ((int)$type['min_service_months'] > 0 && months_of_service($emp['date_hired'], $asOf) < (int)$type['min_service_months']) {
        return $type['name'] . ' requires at least ' . $type['min_service_months'] . ' month(s) of service.';
    }
    return null;
}

/** Annual entitlement for accrual-based types. */
function leave_entitlement(array $emp, array $type, int $year): float
{
    $base = (float)$type['days_per_year'];
    if ($type['accrual_method'] === 'service_based' && $type['service_step_years']) {
        $yrs = years_of_service($emp['date_hired'], $year . '-01-01');
        $base += floor($yrs / (int)$type['service_step_years']) * (float)$type['service_step_days'];
        if ($type['max_days'] !== null) {
            $base = min($base, (float)$type['max_days']);
        }
    }
    return $base;
}

function leave_balance_row(int $empId, int $typeId, int $year): array
{
    $r = row('SELECT * FROM leave_balances WHERE employee_id = ? AND leave_type_id = ? AND year = ?', [$empId, $typeId, $year]);
    if (!$r) {
        insert('leave_balances', ['employee_id' => $empId, 'leave_type_id' => $typeId, 'year' => $year]);
        $r = row('SELECT * FROM leave_balances WHERE employee_id = ? AND leave_type_id = ? AND year = ?', [$empId, $typeId, $year]);
    }
    return $r;
}

/** Balance summary including days tied up in pending applications. */
function leave_balance(int $empId, int $typeId, int $year): array
{
    $r = row('SELECT * FROM leave_balances WHERE employee_id = ? AND leave_type_id = ? AND year = ?', [$empId, $typeId, $year])
        ?: ['carried_over' => 0, 'earned' => 0, 'used' => 0, 'converted' => 0, 'forfeited' => 0, 'adjustment' => 0];
    $pending = (float)val("SELECT COALESCE(SUM(days),0) FROM leave_applications WHERE employee_id = ? AND leave_type_id = ? AND status = 'pending' AND YEAR(date_from) = ?",
        [$empId, $typeId, $year]);
    $total = (float)$r['carried_over'] + (float)$r['earned'] + (float)$r['adjustment'];
    $avail = $total - (float)$r['used'] - (float)$r['converted'] - (float)$r['forfeited'];
    return ['carried_over' => (float)$r['carried_over'], 'earned' => (float)$r['earned'], 'adjustment' => (float)$r['adjustment'],
        'used' => (float)$r['used'], 'converted' => (float)$r['converted'], 'forfeited' => (float)$r['forfeited'],
        'total' => $total, 'available' => round($avail, 2), 'pending' => $pending, 'remaining' => round($avail - $pending, 2)];
}

/** Count chargeable days between two dates according to the leave type's basis and the employee's schedule. */
function leave_count_days(array $emp, array $type, string $from, string $to): float
{
    if ($to < $from) {
        return 0;
    }
    if ($type['count_basis'] === 'calendar') {
        return days_between($from, $to) + 1;
    }
    $n = 0;
    foreach (date_range($from, $to) as $d) {
        $s = employee_schedule($emp, $d);
        if (!$s['is_rest'] && !holiday_on($d, $s['branch_id'])) {
            $n++;
        }
    }
    return $n;
}

/** Build the approval chain for an employee and leave type. */
function leave_build_chain(array $emp, array $type): array
{
    $roles = array_filter(array_map('trim', explode(',', (string)$type['approval_chain'])));
    $steps = [];
    $used = [];
    foreach ($roles as $role) {
        $approverEmp = null;
        if ($role === 'supervisor') {
            $approverEmp = $emp['supervisor_id'] ? (int)$emp['supervisor_id'] : null;
        } elseif ($role === 'dept_head') {
            $approverEmp = $emp['org_unit_id'] ? dept_head_of_unit((int)$emp['org_unit_id'], (int)$emp['id']) : null;
        }
        if ($role === 'hr') {
            // the HR approver: an account allowed to approve leave applications (Role Management), not the employee
            $hr = perm_users_with('leave_applications.approve', "email IS NOT NULL AND email <> '' AND (employee_id IS NULL OR employee_id <> ?)", [$emp['id']])[0] ?? null;
            if ($hr && $hr['employee_id'] && in_array((int)$hr['employee_id'], $used, true)) {
                // the HR officer already approved as supervisor / department head: route to any other HR officer
                $hr = perm_users_with('leave_applications.approve', "email IS NOT NULL AND email <> '' AND (employee_id IS NULL OR employee_id NOT IN (" . implode(',', array_merge([(int)$emp['id']], array_map('intval', $used))) . '))')[0] ?? null;
                if (!$hr) {
                    continue;
                }
            }
            $steps[] = ['role' => 'hr', 'user_id' => $hr['id'] ?? null, 'name' => $hr['full_name'] ?? 'HR Department',
                'email' => $hr['email'] ?? setting('hr_notify_email')];
            continue;
        }
        if (!$approverEmp || in_array($approverEmp, $used, true) || $approverEmp === (int)$emp['id']) {
            continue; // skip unresolvable or duplicate approvers (e.g. supervisor is also the department head)
        }
        $used[] = $approverEmp;
        $ae = row('SELECT * FROM employees WHERE id = ?', [$approverEmp]);
        $u = employee_user($approverEmp);
        $steps[] = ['role' => $role, 'user_id' => $u['id'] ?? null, 'name' => full_name($ae, false), 'email' => employee_email($approverEmp)];
    }
    if (!$steps) {
        $steps[] = ['role' => 'hr', 'user_id' => null, 'name' => 'HR Department', 'email' => setting('hr_notify_email')];
    }
    return $steps;
}

/**
 * File a leave application. $data: employee_id, leave_type_id, date_from, date_to, reason.
 * Returns ['ok' => bool, 'message' => string, 'id' => int|null].
 */
function leave_file(array $data, ?int $existingId = null): array
{
    $emp = row('SELECT * FROM employees WHERE id = ?', [(int)$data['employee_id']]);
    $type = leave_type_row((int)$data['leave_type_id']);
    if (!$emp || !$type || !$type['is_active']) {
        return ['ok' => false, 'message' => 'Invalid employee or leave type.'];
    }
    $from = $data['date_from'];
    $to = $data['date_to'];
    if (!$from || !$to || $to < $from) {
        return ['ok' => false, 'message' => 'Please enter a valid date range.'];
    }
    if ($reason = leave_ineligibility($emp, $type, $from)) {
        return ['ok' => false, 'message' => $reason];
    }
    $days = leave_count_days($emp, $type, $from, $to);
    if ($days <= 0) {
        return ['ok' => false, 'message' => 'The selected dates fall on rest days or holidays only; no leave days to charge.'];
    }
    $overlap = val("SELECT COUNT(*) FROM leave_applications WHERE employee_id = ? AND status IN ('pending','approved') AND date_from <= ? AND date_to >= ?"
        . ($existingId ? ' AND id <> ' . (int)$existingId : ''), [$emp['id'], $to, $from]);
    if ($overlap) {
        return ['ok' => false, 'message' => 'The dates overlap an existing pending or approved leave application.'];
    }
    $year = (int)substr($from, 0, 4);
    if ($type['accrual_method'] === 'per_event') {
        $max = (float)$type['days_per_year'] + ($emp['is_solo_parent'] ? (float)$type['solo_parent_extra_days'] : 0);
        if ($days > $max) {
            return ['ok' => false, 'message' => sprintf('%s allows up to %s day(s) per occurrence.', $type['name'], rtrim(rtrim(number_format($max, 2), '0'), '.'))];
        }
        if ($type['max_events']) {
            $events = (int)val("SELECT COUNT(*) FROM leave_applications WHERE employee_id = ? AND leave_type_id = ? AND status = 'approved'", [$emp['id'], $type['id']]);
            if ($events >= (int)$type['max_events']) {
                return ['ok' => false, 'message' => sprintf('%s is limited to %d occurrence(s).', $type['name'], $type['max_events'])];
            }
        }
    } else {
        $bal = leave_balance((int)$emp['id'], (int)$type['id'], $year);
        $remaining = $bal['remaining'];
        if ($existingId) {
            $remaining += (float)val('SELECT days FROM leave_applications WHERE id = ? AND status = \'pending\'', [$existingId]);
        }
        if ($days > $remaining + 0.001) {
            return ['ok' => false, 'message' => sprintf('Insufficient %s balance: %s day(s) remaining, %s requested.', $type['code'], $remaining, $days)];
        }
    }
    $chain = leave_build_chain($emp, $type);
    $id = tx(function () use ($data, $emp, $type, $days, $chain, $existingId) {
        if ($existingId) {
            update('leave_applications', [
                'leave_type_id' => $type['id'], 'date_from' => $data['date_from'], 'date_to' => $data['date_to'], 'days' => $days,
                'reason' => $data['reason'] ?? null, 'status' => 'pending', 'current_step' => 1, 'decision_reason' => null,
                'decided_at' => null, 'filed_at' => now(),
            ], 'id = ?', [$existingId]);
            q("UPDATE leave_approval_steps SET status = 'cancelled', token = NULL WHERE application_id = ? AND status IN ('waiting','pending')", [$existingId]);
            $appId = $existingId;
            $offset = (int)val('SELECT COALESCE(MAX(step_no),0) FROM leave_approval_steps WHERE application_id = ?', [$appId]);
        } else {
            $appId = insert('leave_applications', [
                'app_no' => next_number('LA', 'leave_applications', 'app_no'), 'employee_id' => $emp['id'], 'leave_type_id' => $type['id'],
                'date_from' => $data['date_from'], 'date_to' => $data['date_to'], 'days' => $days, 'reason' => $data['reason'] ?? null,
                'attachment' => $data['attachment'] ?? null, 'filed_by' => user_id(),
            ]);
            $offset = 0;
        }
        foreach ($chain as $i => $s) {
            insert('leave_approval_steps', [
                'application_id' => $appId, 'step_no' => $offset + $i + 1, 'approver_role' => $s['role'], 'approver_user_id' => $s['user_id'],
                'approver_name' => $s['name'], 'approver_email' => $s['email'], 'status' => 'waiting',
            ]);
        }
        update('leave_applications', ['current_step' => $offset + 1], 'id = ?', [$appId]);
        return $appId;
    });
    $first = row("SELECT * FROM leave_approval_steps WHERE application_id = ? AND status = 'waiting' ORDER BY step_no LIMIT 1", [$id]);
    leave_activate_step((int)$first['id']);
    audit('pis', $existingId ? 'leave_resubmit' : 'leave_file', 'leave_applications', $id, ['days' => $days, 'type' => $type['code']]);
    return ['ok' => true, 'message' => sprintf('Leave application filed (%s day(s)). It was routed to %s for approval.', $days, $chain[0]['name']), 'id' => $id];
}

/** Put a step in "pending" state, generate its token and email the approver. */
function leave_activate_step(int $stepId): void
{
    $token = bin2hex(random_bytes(24));
    $expires = date('Y-m-d H:i:s', time() + 86400 * max(1, (int)setting('leave_token_days', 7)));
    update('leave_approval_steps', ['status' => 'pending', 'token' => $token, 'token_expires' => $expires], 'id = ?', [$stepId]);
    $step = row('SELECT * FROM leave_approval_steps WHERE id = ?', [$stepId]);
    update('leave_applications', ['current_step' => $step['step_no']], 'id = ?', [$step['application_id']]);
    leave_send_step_email($stepId);
}

function leave_app_full(int $appId): ?array
{
    return row('SELECT la.*, lt.name AS type_name, lt.code AS type_code, lt.accrual_method, lt.is_paid, e.first_name, e.last_name, e.middle_name, e.suffix,
                       e.employee_no, e.position, e.email AS emp_email, ou.name AS org_unit_name
                FROM leave_applications la
                JOIN leave_types lt ON lt.id = la.leave_type_id
                JOIN employees e ON e.id = la.employee_id
                LEFT JOIN org_units ou ON ou.id = e.org_unit_id
                WHERE la.id = ?', [$appId]);
}

function leave_send_step_email(int $stepId): bool
{
    $step = row('SELECT * FROM leave_approval_steps WHERE id = ?', [$stepId]);
    $app = leave_app_full((int)$step['application_id']);
    if (!$step || !$app || !$step['approver_email']) {
        return false;
    }
    $bal = $app['accrual_method'] === 'per_event' ? null : leave_balance((int)$app['employee_id'], (int)$app['leave_type_id'], (int)substr($app['date_from'], 0, 4));
    // multi-company: the link names the company whose database holds the single-use token
    $link = abs_url('leave_action', array_filter(['t' => $step['token'], 'co' => current_company_id()]));
    $prev = rows("SELECT * FROM leave_approval_steps WHERE application_id = ? AND step_no < ? AND status = 'approved' ORDER BY step_no", [$app['id'], $step['step_no']]);
    $trail = '';
    foreach ($prev as $p) {
        $trail .= '<div style="font-size:13px;color:#047857">&#10003; Approved by ' . h($p['approver_name']) . ' (' . h(label($p['approver_role'])) . ') on ' . h(fmt_dt($p['acted_at'])) . '</div>';
    }
    $body = '<p>Hello ' . h($step['approver_name']) . ',</p><p>A leave application requires your action as <strong>' . h(label($step['approver_role'])) . '</strong>.</p>'
        . email_table([
            'Application No.' => h($app['app_no']),
            'Employee' => h(full_name($app, false)) . ' (' . h($app['employee_no']) . ')',
            'Position / Unit' => h($app['position']) . ' — ' . h($app['org_unit_name']),
            'Leave type' => h($app['type_name']),
            'Inclusive dates' => h(fmt_date($app['date_from'])) . ' to ' . h(fmt_date($app['date_to'])),
            'Days to be charged' => h(rtrim(rtrim(number_format((float)$app['days'], 2), '0'), '.')),
            'Remaining balance' => $bal ? h($bal['available']) . ' day(s) available (' . h($bal['remaining']) . ' after pending requests)' : 'Not applicable (per-occurrence leave)',
            'Reason' => nl2br(h($app['reason'])),
        ])
        . $trail
        . '<p style="margin-top:16px">Act on this request directly from your inbox:</p>'
        . email_button($link . '&a=approve', 'Approve', '#047857')
        . email_button($link . '&a=return', 'Return for revision', '#b45309')
        . email_button($link . '&a=disallow', 'Disallow', '#b91c1c')
        . '<p style="font-size:12px;color:#6b7280;margin-top:14px">This link is valid until ' . h(fmt_dt($step['token_expires'])) . '. You will be asked to confirm and may add remarks.</p>';
    $ok = send_mail($step['approver_email'], $step['approver_name'], 'Leave approval needed: ' . full_name($app, false) . ' — ' . $app['type_name'] . ' (' . $app['app_no'] . ')',
        email_template('Leave application for your approval', $body), 'leave_application', (int)$app['id']);
    if ($ok) {
        update('leave_approval_steps', ['emailed_at' => now()], 'id = ?', [$stepId]);
    }
    return $ok;
}

/** Can the current user act on this step inside the system? */
function leave_can_act(array $step): bool
{
    $u = current_user();
    if (!$u || $step['status'] !== 'pending') {
        return false;
    }
    if (is_super_user()) {
        return true;
    }
    // the HR step of a leave chain: anyone allowed to approve or reject leave applications
    if ($step['approver_role'] === 'hr' && can_any(['leave_applications.approve', 'leave_applications.reject'])) {
        return true;
    }
    return $step['approver_user_id'] && (int)$step['approver_user_id'] === (int)$u['id'];
}

/**
 * Apply an approver decision. $action: approve | return | disallow. $via: email | system.
 */
function leave_act(int $stepId, string $action, ?string $remarks, string $via): array
{
    $step = row('SELECT * FROM leave_approval_steps WHERE id = ?', [$stepId]);
    if (!$step || $step['status'] !== 'pending') {
        return ['ok' => false, 'message' => 'This approval step is no longer pending.'];
    }
    if (!in_array($action, ['approve', 'return', 'disallow'], true)) {
        return ['ok' => false, 'message' => 'Invalid action.'];
    }
    if ($action !== 'approve' && trim((string)$remarks) === '') {
        return ['ok' => false, 'message' => 'Please state the reason for returning or disallowing the request.'];
    }
    $app = leave_app_full((int)$step['application_id']);
    if ($app['status'] !== 'pending') {
        return ['ok' => false, 'message' => 'The application is no longer pending.'];
    }
    $status = ['approve' => 'approved', 'return' => 'returned', 'disallow' => 'disallowed'][$action];
    $final = false;
    tx(function () use ($step, $app, $status, $remarks, $via, $action, &$final) {
        update('leave_approval_steps', ['status' => $status, 'remarks' => $remarks, 'acted_at' => now(), 'acted_via' => $via, 'token' => null], 'id = ?', [$step['id']]);
        if ($action === 'approve') {
            $next = row("SELECT * FROM leave_approval_steps WHERE application_id = ? AND status = 'waiting' ORDER BY step_no LIMIT 1", [$app['id']]);
            if ($next) {
                leave_activate_step((int)$next['id']);
            } else {
                $final = true;
                update('leave_applications', ['status' => 'approved', 'decided_at' => now(), 'decision_reason' => $remarks], 'id = ?', [$app['id']]);
                if ($app['accrual_method'] !== 'per_event') {
                    $bal = leave_balance_row((int)$app['employee_id'], (int)$app['leave_type_id'], (int)substr($app['date_from'], 0, 4));
                    q('UPDATE leave_balances SET used = used + ? WHERE id = ?', [$app['days'], $bal['id']]);
                }
            }
        } else {
            $final = true;
            q("UPDATE leave_approval_steps SET status = 'cancelled', token = NULL WHERE application_id = ? AND status = 'waiting'", [$app['id']]);
            update('leave_applications', ['status' => $status, 'decided_at' => now(), 'decision_reason' => $remarks], 'id = ?', [$app['id']]);
        }
    });
    audit('pis', 'leave_' . $action, 'leave_applications', $app['id'], ['step' => $step['step_no'], 'role' => $step['approver_role'], 'via' => $via, 'remarks' => $remarks]);
    if ($final) {
        if ($status === 'approved') {
            leave_post_to_dtr((int)$app['id']);
        }
        leave_notify_employee((int)$app['id'], $status, $remarks, $step);
    }
    $msg = $action === 'approve' ? ($final ? 'Leave application fully approved and posted to attendance.' : 'Approved. The request was forwarded to the next approver.')
        : 'Leave application ' . $status . '. The employee has been notified.';
    return ['ok' => true, 'message' => $msg];
}

function leave_notify_employee(int $appId, string $status, ?string $remarks, array $step): void
{
    $app = leave_app_full($appId);
    $email = employee_email((int)$app['employee_id']);
    if (!$email) {
        return;
    }
    $color = ['approved' => '#047857', 'returned' => '#b45309', 'disallowed' => '#b91c1c', 'cancelled' => '#6b7280'][$status] ?? '#1e3a8a';
    $body = '<p>Hello ' . h($app['first_name']) . ',</p><p>Your leave application <strong>' . h($app['app_no']) . '</strong> has been '
        . '<strong style="color:' . $color . '">' . h(strtoupper(label($status))) . '</strong>.</p>'
        . email_table([
            'Leave type' => h($app['type_name']),
            'Inclusive dates' => h(fmt_date($app['date_from'])) . ' to ' . h(fmt_date($app['date_to'])),
            'Days' => h($app['days']),
            'Decision by' => h($step['approver_name']) . ' (' . h(label($step['approver_role'])) . ')',
            'Reason / remarks' => $remarks ? nl2br(h($remarks)) : '—',
        ])
        . ($status === 'returned' ? '<p>You may revise and resubmit the request from <em>My Leaves</em>.</p>' : '')
        . email_button(abs_url('my_leaves'), 'Open My Leaves');
    send_mail($email, full_name($app, false), 'Leave ' . label($status) . ': ' . $app['type_name'] . ' (' . $app['app_no'] . ')',
        email_template('Leave application ' . label($status), $body), 'leave_application', $appId);
}

/** Mark the leave dates in the DTR so an approved leave is never mistaken for an absence. */
function leave_post_to_dtr(int $appId): void
{
    $app = row('SELECT * FROM leave_applications WHERE id = ?', [$appId]);
    if (!$app) {
        return;
    }
    $to = min($app['date_to'], today());
    if ($to >= $app['date_from']) {
        foreach (date_range($app['date_from'], $to) as $d) {
            process_dtr((int)$app['employee_id'], $d);
        }
    }
}

function leave_cancel(int $appId, string $reason): array
{
    $app = row('SELECT * FROM leave_applications WHERE id = ?', [$appId]);
    if (!$app || !in_array($app['status'], ['pending', 'approved', 'returned'], true)) {
        return ['ok' => false, 'message' => 'This application cannot be cancelled.'];
    }
    $type = leave_type_row((int)$app['leave_type_id']);
    tx(function () use ($app, $type, $reason) {
        if ($app['status'] === 'approved' && $type['accrual_method'] !== 'per_event') {
            q('UPDATE leave_balances SET used = GREATEST(0, used - ?) WHERE employee_id = ? AND leave_type_id = ? AND year = ?',
                [$app['days'], $app['employee_id'], $app['leave_type_id'], (int)substr($app['date_from'], 0, 4)]);
        }
        q("UPDATE leave_approval_steps SET status = 'cancelled', token = NULL WHERE application_id = ? AND status IN ('waiting','pending')", [$app['id']]);
        update('leave_applications', ['status' => 'cancelled', 'decision_reason' => $reason, 'decided_at' => now()], 'id = ?', [$app['id']]);
    });
    if ($app['status'] === 'approved') {
        leave_post_to_dtr($appId);
    }
    audit('pis', 'leave_cancel', 'leave_applications', $appId, ['reason' => $reason]);
    return ['ok' => true, 'message' => 'Leave application cancelled.'];
}

/**
 * Credit annual / service-based entitlements for a year (idempotent).
 * Monthly types are credited by leave_accrue_monthly().
 */
function leave_accrue_annual(int $year): int
{
    $types = rows("SELECT * FROM leave_types WHERE is_active = 1 AND accrual_method IN ('annual','service_based')");
    $emps = rows("SELECT * FROM employees WHERE status = 'active'");
    $n = 0;
    foreach ($emps as $e) {
        foreach ($types as $t) {
            $asOf = max($year . '-01-01', min($year . '-12-31', today()));
            if (leave_ineligibility($e, $t, $asOf)) {
                continue;
            }
            if (val('SELECT id FROM leave_accrual_log WHERE employee_id = ? AND leave_type_id = ? AND year = ? AND month = 0', [$e['id'], $t['id'], $year])) {
                continue;
            }
            $days = leave_entitlement($e, $t, $year);
            // pro-rate for employees hired during the year
            if (substr($e['date_hired'], 0, 4) == $year) {
                $days = round($days * (13 - (int)substr($e['date_hired'], 5, 2)) / 12 * 2) / 2;
            }
            if ($days <= 0) {
                continue;
            }
            $b = leave_balance_row((int)$e['id'], (int)$t['id'], $year);
            q('UPDATE leave_balances SET earned = earned + ? WHERE id = ?', [$days, $b['id']]);
            insert('leave_accrual_log', ['employee_id' => $e['id'], 'leave_type_id' => $t['id'], 'year' => $year, 'month' => 0, 'days' => $days]);
            $n++;
        }
    }
    audit('pis', 'leave_accrue_annual', 'leave_balances', null, ['year' => $year, 'credits' => $n]);
    return $n;
}

function leave_accrue_monthly(int $year, int $month): int
{
    $types = rows("SELECT * FROM leave_types WHERE is_active = 1 AND accrual_method = 'monthly'");
    $monthEnd = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));
    $emps = rows("SELECT * FROM employees WHERE status = 'active' AND date_hired <= ?", [$monthEnd]);
    $n = 0;
    foreach ($emps as $e) {
        foreach ($types as $t) {
            if (leave_ineligibility($e, $t, $monthEnd)) {
                continue;
            }
            if (val('SELECT id FROM leave_accrual_log WHERE employee_id = ? AND leave_type_id = ? AND year = ? AND month = ?', [$e['id'], $t['id'], $year, $month])) {
                continue;
            }
            $ent = leave_entitlement($e, $t, $year);
            $days = round($ent * $month / 12, 2) - round($ent * ($month - 1) / 12, 2); // cumulative rounding: 12 credits add up exactly
            $b = leave_balance_row((int)$e['id'], (int)$t['id'], $year);
            q('UPDATE leave_balances SET earned = earned + ? WHERE id = ?', [$days, $b['id']]);
            insert('leave_accrual_log', ['employee_id' => $e['id'], 'leave_type_id' => $t['id'], 'year' => $year, 'month' => $month, 'days' => $days]);
            $n++;
        }
    }
    audit('pis', 'leave_accrue_monthly', 'leave_balances', null, ['year' => $year, 'month' => $month, 'credits' => $n]);
    return $n;
}

/**
 * Year-end processing: convert unused convertible leave to cash (posted as a pending payroll earning and
 * an accrued-benefit ledger), carry over up to the allowed maximum, forfeit the rest.
 */
function leave_year_end(int $year): array
{
    $res = ['converted' => 0, 'amount' => 0.0, 'carried' => 0];
    $types = rows("SELECT * FROM leave_types WHERE accrual_method <> 'per_event'");
    $deMinDays = setting_num('de_minimis_leave_days', 12);
    foreach (rows('SELECT lb.*, e.status FROM leave_balances lb JOIN employees e ON e.id = lb.employee_id WHERE lb.year = ? AND lb.closed = 0', [$year]) as $b) {
        $t = null;
        foreach ($types as $tt) {
            if ((int)$tt['id'] === (int)$b['leave_type_id']) {
                $t = $tt;
            }
        }
        if (!$t) {
            continue;
        }
        $bal = leave_balance((int)$b['employee_id'], (int)$b['leave_type_id'], $year);
        $avail = max(0, $bal['available']);
        $carry = min($avail, (float)$t['max_carry_over']);
        $rest = $avail - $carry;
        $convert = $t['convertible'] ? min($rest, $t['max_convertible_days'] !== null ? (float)$t['max_convertible_days'] : $rest) : 0;
        $forfeit = $rest - $convert;
        tx(function () use ($b, $t, $year, $carry, $convert, $forfeit, $deMinDays, &$res) {
            if ($convert > 0 && $b['status'] === 'active') {
                $emp = row('SELECT * FROM employees WHERE id = ?', [$b['employee_id']]);
                $rates = employee_rates($emp);
                $amount = round($convert * $rates['daily'], 2);
                $nontaxDays = in_array($t['code'], ['VL', 'SIL'], true) ? min($convert, $deMinDays) : 0;
                $nontax = round($nontaxDays * $rates['daily'], 2);
                $led = ledger_accrual((int)$emp['id'], 'Leave Conversion');
                ledger_post((int)$led['id'], $year . '-12-31', sprintf('Conversion of %s unused %s day(s), %d', $convert, $t['code'], $year), $amount, 0);
                insert('pending_earnings', [
                    'employee_id' => $emp['id'], 'source' => 'leave_conversion', 'source_id' => $led['id'],
                    'description' => sprintf('Leave conversion: %s day(s) %s (%d)', $convert, $t['code'], $year), 'amount' => $amount,
                    'tax_treatment' => $nontax > 0 ? 'de_minimis' : 'other_benefit', 'nontax_portion' => $nontax, 'created_by' => user_id(),
                ]);
                $res['converted']++;
                $res['amount'] += $amount;
            } elseif ($convert > 0) {
                $forfeit += 0; // separated employees: conversion handled in final pay
                $convert = 0;
            }
            update('leave_balances', ['converted' => $convert, 'forfeited' => $forfeit, 'closed' => 1], 'id = ?', [$b['id']]);
            if ($carry > 0) {
                $nb = leave_balance_row((int)$b['employee_id'], (int)$b['leave_type_id'], $year + 1);
                q('UPDATE leave_balances SET carried_over = ? WHERE id = ?', [$carry, $nb['id']]);
                $res['carried']++;
            }
        });
    }
    audit('pis', 'leave_year_end', 'leave_balances', null, array_merge(['year' => $year], $res));
    return $res;
}
