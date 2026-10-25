<?php
/**
 * Employee's transactions WITH TERMS — earnings or deductions that run over several
 * paydays (salary loans, cash advances repaid in installments, long-term allowances).
 *
 * Follows the M.A.D.A.S. "Employee's Transaction Setup": transaction amount (principal),
 * monthly interest rate, number of terms (paydays), the per-payday principal and interest
 * (Trans/PD), the transaction term (every pay, 1st, 2nd, 3rd or 4th pay of the month), the
 * start date and the running total paid / balance. A schedule row is kept per installment;
 * the payroll engine takes the next pending installment of every run the term applies to
 * and the installment is marked posted when the run is finalized.
 *
 * Tables: employee_term_trans, employee_term_schedule (database version 3).
 */

const TERM_TRANS_TERMS = [
    'every_pay'  => 'Every pay',
    'first_pay'  => '1st pay of the month',
    'second_pay' => '2nd pay of the month',
    'third_pay'  => '3rd pay of the month (weekly)',
    'fourth_pay' => '4th pay of the month (weekly)',
];

const TERM_TRANS_STATUS = ['active' => 'Active', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

/** Period number of the month a transaction term corresponds to (null = every pay). */
function term_period_no(string $transTerm): ?int
{
    return ['first_pay' => 1, 'second_pay' => 2, 'third_pay' => 3, 'fourth_pay' => 4][$transTerm] ?? null;
}

/** Installments per month for a term: every pay follows the pay frequency, a fixed pay is once a month. */
function term_installments_per_month(string $transTerm, string $frequency): float
{
    return $transTerm === 'every_pay' ? max(1, periods_per_month($frequency)) : 1.0;
}

/**
 * Compute the amounts of a term transaction.
 * $in: principal, monthly_rate (%), terms, trans_term, and optional overrides interest_amount,
 * per_pay_principal, per_pay_interest (blank = computed).
 */
function term_compute(array $in, string $frequency): array
{
    $principal = round((float)($in['principal'] ?? 0), 2);
    $rate = round((float)($in['monthly_rate'] ?? 0), 3);
    $terms = max(1, (int)($in['terms'] ?? 1));
    $transTerm = isset(TERM_TRANS_TERMS[$in['trans_term'] ?? '']) ? $in['trans_term'] : 'every_pay';
    $months = $terms / term_installments_per_month($transTerm, $frequency);
    $interest = isset($in['interest_amount']) && $in['interest_amount'] !== '' && $in['interest_amount'] !== null
        ? round((float)$in['interest_amount'], 2)
        : round($principal * $rate / 100 * $months, 2);
    $ppPrincipal = isset($in['per_pay_principal']) && $in['per_pay_principal'] !== '' && $in['per_pay_principal'] !== null
        ? round((float)$in['per_pay_principal'], 2) : round($principal / $terms, 2);
    $ppInterest = isset($in['per_pay_interest']) && $in['per_pay_interest'] !== '' && $in['per_pay_interest'] !== null
        ? round((float)$in['per_pay_interest'], 2) : round($interest / $terms, 2);
    return [
        'principal' => $principal, 'monthly_rate' => $rate, 'terms' => $terms, 'trans_term' => $transTerm,
        'interest_amount' => $interest, 'total_amount' => round($principal + $interest, 2),
        'per_pay_principal' => $ppPrincipal, 'per_pay_interest' => $ppInterest, 'per_pay' => round($ppPrincipal + $ppInterest, 2),
        'months' => round($months, 2),
    ];
}

/**
 * Projected paydays of the installments: the defined periods of the pay code that match the
 * term (1st / 2nd / … pay or every pay) from the start date; when the periods run out the
 * dates continue at the frequency of the pay code.
 */
function term_schedule_dates(int $payGroupId, string $frequency, string $start, int $terms, string $transTerm): array
{
    $no = term_period_no($transTerm);
    $sql = 'SELECT period_end, pay_date, period_no, id FROM attendance_cutoffs WHERE pay_group_id = ? AND period_end >= ?' . ($no ? ' AND period_no = ?' : '') . ' ORDER BY period_end LIMIT ?';
    $params = $no ? [$payGroupId, $start, $no, $terms] : [$payGroupId, $start, $terms];
    $dates = [];
    foreach (rows($sql, $params) as $c) {
        $dates[] = ['date' => $c['pay_date'] ?: $c['period_end'], 'cutoff_id' => (int)$c['id']];
    }
    $last = $dates ? end($dates)['date'] : $start;
    $step = $no ? '+1 month' : ['monthly' => '+1 month', 'semi_monthly' => '+15 days', 'weekly' => '+7 days', 'daily' => '+1 day'][$frequency] ?? '+15 days';
    while (count($dates) < $terms) {
        $last = date('Y-m-d', strtotime($last . ' ' . $step));
        $dates[] = ['date' => $last, 'cutoff_id' => null];
    }
    return $dates;
}

/** Create a term transaction and its installment schedule. Returns the new id. */
function term_create(array $d): int
{
    $emp = row('SELECT e.*, pg.frequency FROM employees e LEFT JOIN pay_groups pg ON pg.id = e.pay_group_id WHERE e.id = ?', [(int)$d['employee_id']]);
    if (!$emp) {
        throw new RuntimeException('Employee not found.');
    }
    $freq = $emp['frequency'] ?: 'semi_monthly';
    $c = term_compute($d, $freq);
    if ($c['principal'] <= 0) {
        throw new RuntimeException('The transaction amount must be greater than zero.');
    }
    if ($c['terms'] < 1 || $c['terms'] > 520) {
        throw new RuntimeException('Terms must be between 1 and 520 paydays.');
    }
    if ($c['per_pay'] <= 0) {
        throw new RuntimeException('The amount per payday must be greater than zero.');
    }
    $start = $d['start_date'] ?: ($d['trans_date'] ?: today());
    $dates = term_schedule_dates((int)$emp['pay_group_id'], $freq, $start, $c['terms'], $c['trans_term']);
    return tx(function () use ($d, $emp, $c, $start, $dates) {
        $id = insert('employee_term_trans', [
            'employee_id' => (int)$emp['id'], 'trans_type' => $d['trans_type'] === 'earning' ? 'earning' : 'deduction',
            'earning_type_id' => $d['trans_type'] === 'earning' ? (int)$d['code_id'] : null,
            'deduction_type_id' => $d['trans_type'] === 'earning' ? null : (int)$d['code_id'],
            'doc_no' => $d['doc_no'] ?: null, 'trans_date' => $d['trans_date'] ?: today(),
            'principal' => $c['principal'], 'monthly_rate' => $c['monthly_rate'], 'interest_amount' => $c['interest_amount'], 'total_amount' => $c['total_amount'],
            'terms' => $c['terms'], 'per_pay_principal' => $c['per_pay_principal'], 'per_pay_interest' => $c['per_pay_interest'], 'trans_term' => $c['trans_term'],
            'start_date' => $start, 'next_date' => $dates[0]['date'] ?? $start, 'branch_id' => $d['branch_id'] ?: ($emp['default_branch_id'] ?: null),
            'remarks' => $d['remarks'] ?: null, 'paid_terms' => 0, 'total_paid' => 0, 'balance' => $c['total_amount'], 'status' => 'active',
            'created_by' => user_id(), 'updated_at' => now(),
        ]);
        // installments: the last one absorbs the rounding so the schedule sums exactly to the total
        $sumP = 0.0;
        $sumI = 0.0;
        for ($i = 1; $i <= $c['terms']; $i++) {
            $pr = $i < $c['terms'] ? $c['per_pay_principal'] : round($c['principal'] - $sumP, 2);
            $in = $i < $c['terms'] ? $c['per_pay_interest'] : round($c['interest_amount'] - $sumI, 2);
            $pr = max(0, $pr);
            $in = max(0, $in);
            $sumP += $pr;
            $sumI += $in;
            insert('employee_term_schedule', ['term_id' => $id, 'seq_no' => $i, 'due_date' => $dates[$i - 1]['date'] ?? null, 'cutoff_id' => $dates[$i - 1]['cutoff_id'] ?? null,
                'principal' => $pr, 'interest' => $in, 'amount' => round($pr + $in, 2), 'status' => 'pending']);
        }
        audit('payroll', 'term_trans_create', 'employee_term_trans', $id, ['employee_id' => $emp['id'], 'principal' => $c['principal'], 'terms' => $c['terms'], 'trans_term' => $c['trans_term']]);
        return $id;
    });
}

function term_get(int $id): ?array
{
    return row('SELECT tt.*, e.employee_no, e.first_name, e.last_name, e.middle_name, e.suffix, e.pay_group_id, pg.frequency, pg.code AS pay_group_code,
                       COALESCE(ea.code, dt.code) AS code, COALESCE(ea.name, dt.name) AS code_name, b.name AS branch_name, b.code AS branch_code
                FROM employee_term_trans tt JOIN employees e ON e.id = tt.employee_id
                LEFT JOIN pay_groups pg ON pg.id = e.pay_group_id
                LEFT JOIN earning_types ea ON ea.id = tt.earning_type_id LEFT JOIN deduction_types dt ON dt.id = tt.deduction_type_id
                LEFT JOIN branches b ON b.id = tt.branch_id WHERE tt.id = ?', [$id]);
}

function term_schedule(int $termId): array
{
    return rows('SELECT s.*, pr.run_no FROM employee_term_schedule s LEFT JOIN payroll_runs pr ON pr.id = s.payroll_run_id WHERE s.term_id = ? ORDER BY s.seq_no', [$termId]);
}

/** Recompute paid terms, total paid, balance, next date and status from the schedule. */
function term_recalc(int $termId): void
{
    $t = row('SELECT * FROM employee_term_trans WHERE id = ?', [$termId]);
    if (!$t) {
        return;
    }
    $paid = row("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amt FROM employee_term_schedule WHERE term_id = ? AND status IN ('posted','paid')", [$termId]);
    $pending = row("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amt, MIN(due_date) AS next_date FROM employee_term_schedule WHERE term_id = ? AND status = 'pending'", [$termId]);
    $balance = round(max(0, (float)$t['total_amount'] - (float)$paid['amt']), 2);
    $status = $t['status'] === 'cancelled' ? 'cancelled' : (((int)$pending['n'] === 0 || $balance <= 0.004) ? 'completed' : 'active');
    update('employee_term_trans', ['paid_terms' => (int)$paid['n'], 'total_paid' => round((float)$paid['amt'], 2), 'balance' => $balance,
        'next_date' => $status === 'active' ? $pending['next_date'] : null, 'status' => $status, 'updated_at' => now()], 'id = ?', [$termId]);
}

/**
 * Installments due to an employee in a payroll run: active terms whose start date is on or
 * before the period end and whose term matches the period (1st / 2nd / … pay of the month,
 * or every pay). Returns [['term' => row, 'sched' => next pending installment], …].
 */
function term_due_for_run(int $empId, array $run, string $frequency): array
{
    if (!in_array($run['run_type'], ['regular', 'final_pay'], true)) {
        return [];
    }
    $meta = pt_period_meta($run['period_start'], $run['period_end'], $frequency);
    $isFinal = $run['run_type'] === 'final_pay';
    $terms = rows("SELECT tt.*, ea.code AS e_code, ea.name AS e_name, ea.taxable, ea.special, ea.de_minimis, ea.ref_ext AS e_ref,
                          dt.code AS d_code, dt.name AS d_name, dt.register_group, dt.ref_ext AS d_ref
                   FROM employee_term_trans tt LEFT JOIN earning_types ea ON ea.id = tt.earning_type_id LEFT JOIN deduction_types dt ON dt.id = tt.deduction_type_id
                   WHERE tt.employee_id = ? AND tt.status = 'active' AND tt.start_date <= ? ORDER BY tt.id", [$empId, $run['period_end']]);
    $out = [];
    foreach ($terms as $t) {
        $no = term_period_no($t['trans_term']);
        if (!$isFinal && $no !== null && $no !== (int)$meta['period_no']) {
            continue;
        }
        if ($isFinal) {
            // final pay: the whole remaining balance is settled
            $pend = rows("SELECT * FROM employee_term_schedule WHERE term_id = ? AND status = 'pending' ORDER BY seq_no", [$t['id']]);
            if ($pend) {
                $sum = ['id' => (int)$pend[0]['id'], 'amount' => round(array_sum(array_column($pend, 'amount')), 2), 'seq_no' => (int)$pend[0]['seq_no'], 'all_ids' => array_map('intval', array_column($pend, 'id'))];
                $out[] = ['term' => $t, 'sched' => $sum];
            }
            continue;
        }
        $s = row("SELECT * FROM employee_term_schedule WHERE term_id = ? AND status = 'pending' ORDER BY seq_no LIMIT 1", [$t['id']]);
        if ($s) {
            $out[] = ['term' => $t, 'sched' => $s];
        }
    }
    return $out;
}

/**
 * Mark an installment posted by a finalized payroll run. When the run took less than the
 * installment (minimum take-home rule), the shortfall is appended as a new installment.
 */
function term_post_installment(int $scheduleId, int $runId, string $runNo, string $date, float $amountTaken): void
{
    $s = row('SELECT * FROM employee_term_schedule WHERE id = ?', [$scheduleId]);
    if (!$s || $s['status'] !== 'pending') {
        return;
    }
    $t = row('SELECT * FROM employee_term_trans WHERE id = ?', [$s['term_id']]);
    $run = row('SELECT * FROM payroll_runs WHERE id = ?', [$runId]);
    if ($run && $run['run_type'] === 'final_pay') {
        // every remaining installment is settled by the final pay
        q("UPDATE employee_term_schedule SET status = 'posted', payroll_run_id = ?, posted_at = ?, reference = ? WHERE term_id = ? AND status = 'pending'", [$runId, now(), $runNo, $s['term_id']]);
        term_recalc((int)$s['term_id']);
        return;
    }
    $due = round((float)$s['amount'], 2);
    $taken = round($amountTaken, 2);
    update('employee_term_schedule', ['status' => 'posted', 'payroll_run_id' => $runId, 'posted_at' => now(), 'reference' => $runNo, 'amount' => min($due, $taken)], 'id = ?', [$scheduleId]);
    if ($taken + 0.004 < $due) {
        $short = round($due - $taken, 2);
        $last = (int)val('SELECT MAX(seq_no) FROM employee_term_schedule WHERE term_id = ?', [$s['term_id']]);
        $lastDate = val('SELECT MAX(due_date) FROM employee_term_schedule WHERE term_id = ?', [$s['term_id']]);
        insert('employee_term_schedule', ['term_id' => (int)$s['term_id'], 'seq_no' => $last + 1, 'due_date' => $lastDate ? date('Y-m-d', strtotime($lastDate . ' +15 days')) : null,
            'principal' => $short, 'interest' => 0, 'amount' => $short, 'status' => 'pending', 'reference' => 'Shortfall of installment ' . $s['seq_no'] . ' (' . $runNo . ')']);
        if ($t) {
            q('UPDATE employee_term_trans SET terms = terms + 1 WHERE id = ?', [$t['id']]);
        }
    }
    term_recalc((int)$s['term_id']);
}

/** A payment made outside the payroll (cashier, agency) settles the next installments. */
function term_pay_outside(int $termId, float $amount, string $date, ?string $reference = null): float
{
    $t = row('SELECT * FROM employee_term_trans WHERE id = ?', [$termId]);
    if (!$t || $t['status'] !== 'active') {
        throw new RuntimeException('Only active transactions can receive a payment.');
    }
    $amount = round($amount, 2);
    if ($amount <= 0 || $amount > (float)$t['balance'] + 0.004) {
        throw new RuntimeException(sprintf('The payment must be between 0.01 and the outstanding balance (%s).', number_format((float)$t['balance'], 2)));
    }
    $left = $amount;
    tx(function () use ($termId, &$left, $date, $reference, $amount) {
        foreach (rows("SELECT * FROM employee_term_schedule WHERE term_id = ? AND status = 'pending' ORDER BY seq_no", [$termId]) as $s) {
            if ($left <= 0.004) {
                break;
            }
            $due = (float)$s['amount'];
            if ($left + 0.004 >= $due) {
                update('employee_term_schedule', ['status' => 'paid', 'posted_at' => $date . ' 00:00:00', 'reference' => $reference ?: 'Paid outside payroll'], 'id = ?', [$s['id']]);
                $left = round($left - $due, 2);
            } else {
                // partial: this installment is split into a paid part and a smaller pending part
                update('employee_term_schedule', ['amount' => round($left, 2), 'principal' => round($left, 2), 'interest' => 0, 'status' => 'paid',
                    'posted_at' => $date . ' 00:00:00', 'reference' => $reference ?: 'Partial payment outside payroll'], 'id = ?', [$s['id']]);
                insert('employee_term_schedule', ['term_id' => $termId, 'seq_no' => (int)$s['seq_no'], 'due_date' => $s['due_date'], 'cutoff_id' => $s['cutoff_id'],
                    'principal' => round($due - $left, 2), 'interest' => 0, 'amount' => round($due - $left, 2), 'status' => 'pending']);
                $left = 0;
            }
        }
        audit('payroll', 'term_trans_payment', 'employee_term_trans', $termId, ['amount' => $amount, 'date' => $date, 'reference' => $reference]);
    });
    term_recalc($termId);
    return $amount;
}

/** Cancel a term transaction: pending installments are cancelled, posted ones stay in history. */
function term_cancel(int $termId, ?string $reason = null): void
{
    $t = row('SELECT * FROM employee_term_trans WHERE id = ?', [$termId]);
    if (!$t || $t['status'] === 'cancelled') {
        throw new RuntimeException('This transaction is already cancelled or does not exist.');
    }
    q("UPDATE employee_term_schedule SET status = 'cancelled' WHERE term_id = ? AND status = 'pending'", [$termId]);
    update('employee_term_trans', ['status' => 'cancelled', 'next_date' => null, 'updated_at' => now(), 'remarks' => trim(($t['remarks'] ? $t['remarks'] . ' ' : '') . ($reason ? '[Cancelled: ' . $reason . ']' : '[Cancelled]'))], 'id = ?', [$termId]);
    audit('payroll', 'term_trans_cancel', 'employee_term_trans', $termId, ['reason' => $reason, 'balance' => $t['balance']]);
}

/** Reactivate a cancelled transaction (its cancelled installments become pending again). */
function term_reactivate(int $termId): void
{
    $t = row('SELECT * FROM employee_term_trans WHERE id = ?', [$termId]);
    if (!$t || $t['status'] !== 'cancelled') {
        throw new RuntimeException('Only cancelled transactions can be re-activated.');
    }
    q("UPDATE employee_term_schedule SET status = 'pending' WHERE term_id = ? AND status = 'cancelled'", [$termId]);
    update('employee_term_trans', ['status' => 'active', 'updated_at' => now()], 'id = ?', [$termId]);
    term_recalc($termId);
    audit('payroll', 'term_trans_reactivate', 'employee_term_trans', $termId);
}

/** Change the remaining per-payday amount and re-spread the balance over the pending installments. */
function term_update_amortization(int $termId, float $perPay): void
{
    $t = row('SELECT * FROM employee_term_trans WHERE id = ?', [$termId]);
    if (!$t || $t['status'] !== 'active') {
        throw new RuntimeException('Only active transactions can be changed.');
    }
    $perPay = round($perPay, 2);
    if ($perPay <= 0) {
        throw new RuntimeException('The amount per payday must be greater than zero.');
    }
    $pending = rows("SELECT * FROM employee_term_schedule WHERE term_id = ? AND status = 'pending' ORDER BY seq_no", [$termId]);
    if (!$pending) {
        throw new RuntimeException('There is no pending installment left.');
    }
    $balance = round(array_sum(array_column($pending, 'amount')), 2);
    $ratioI = $t['total_amount'] > 0 ? (float)$t['interest_amount'] / (float)$t['total_amount'] : 0;
    $n = max(1, (int)ceil($balance / $perPay));
    $first = $pending[0];
    $dates = term_schedule_dates((int)val('SELECT pay_group_id FROM employees WHERE id = ?', [$t['employee_id']]),
        (string)(val('SELECT pg.frequency FROM employees e JOIN pay_groups pg ON pg.id = e.pay_group_id WHERE e.id = ?', [$t['employee_id']]) ?: 'semi_monthly'),
        $first['due_date'] ?: today(), $n, $t['trans_term']);
    tx(function () use ($termId, $pending, $balance, $perPay, $n, $ratioI, $first, $dates) {
        q("DELETE FROM employee_term_schedule WHERE term_id = ? AND status = 'pending'", [$termId]);
        $seq = (int)$first['seq_no'];
        $left = $balance;
        for ($i = 0; $i < $n; $i++) {
            $amt = $i < $n - 1 ? min($perPay, $left) : round($left, 2);
            $in = round($amt * $ratioI, 2);
            insert('employee_term_schedule', ['term_id' => $termId, 'seq_no' => $seq + $i, 'due_date' => $dates[$i]['date'] ?? null, 'cutoff_id' => $dates[$i]['cutoff_id'] ?? null,
                'principal' => round($amt - $in, 2), 'interest' => $in, 'amount' => round($amt, 2), 'status' => 'pending']);
            $left = round($left - $amt, 2);
        }
        update('employee_term_trans', ['per_pay_principal' => round($perPay * (1 - $ratioI), 2), 'per_pay_interest' => round($perPay * $ratioI, 2),
            'terms' => $seq - 1 + $n, 'updated_at' => now()], 'id = ?', [$termId]);
        audit('payroll', 'term_trans_amortization', 'employee_term_trans', $termId, ['per_pay' => $perPay, 'installments' => $n]);
    });
    term_recalc($termId);
}

/** Installments already taken (posted by a payroll or paid outside it). */
function term_taken_count(int $termId): int
{
    return (int)val("SELECT COUNT(*) FROM employee_term_schedule WHERE term_id = ? AND status IN ('posted','paid')", [$termId]);
}

/**
 * Edit a transaction with terms (Employee's Transactions > Edit).
 *  - nothing taken yet: everything can change (type, code, amount, rate, interest, terms, trans. term,
 *    Trans/PD, dates); the schedule is rebuilt
 *  - installments already taken: the amount and the rate stay; the remaining balance is spread again over
 *    the number of remaining paydays, on the trans. term and from the start date given
 *  - document no., Co. / Branch, trans. date and remarks can always be changed
 * Returns the payroll runs (draft / computed) that had reserved a pending installment: they must be
 * computed again.
 */
function term_update(int $termId, array $d): array
{
    $t = row('SELECT tt.*, e.pay_group_id, pg.frequency FROM employee_term_trans tt JOIN employees e ON e.id = tt.employee_id
              LEFT JOIN pay_groups pg ON pg.id = e.pay_group_id WHERE tt.id = ?', [$termId]);
    if (!$t) {
        throw new RuntimeException('Transaction not found.');
    }
    if ($t['status'] === 'cancelled') {
        throw new RuntimeException('A cancelled transaction cannot be edited. Re-activate it first.');
    }
    $freq = $t['frequency'] ?: 'semi_monthly';
    $taken = term_taken_count($termId);
    $reserved = q("SELECT DISTINCT pr.run_no FROM employee_term_schedule s JOIN payroll_runs pr ON pr.id = s.payroll_run_id
                   WHERE s.term_id = ? AND s.status = 'pending' AND pr.status IN ('draft','computed')", [$termId])->fetchAll(PDO::FETCH_COLUMN);
    $meta = ['doc_no' => trim((string)($d['doc_no'] ?? '')) ?: null, 'branch_id' => (int)($d['branch_id'] ?? 0) ?: null,
        'remarks' => trim((string)($d['remarks'] ?? '')) ?: null, 'trans_date' => ($d['trans_date'] ?? '') ?: $t['trans_date']];
    $before = $t;
    tx(function () use ($t, $termId, $d, $freq, $taken, $meta) {
        update('employee_term_trans', $meta + ['updated_at' => now()], 'id = ?', [$termId]);
        if ($t['status'] !== 'active') {
            return;   // completed: only the record fields above
        }
        $transTerm = isset(TERM_TRANS_TERMS[$d['trans_term'] ?? '']) ? $d['trans_term'] : $t['trans_term'];
        if ($taken === 0) {
            $type = ($d['trans_type'] ?? $t['trans_type']) === 'earning' ? 'earning' : 'deduction';
            $code = (int)($d['code_id'] ?? 0) ?: (int)($type === 'earning' ? $t['earning_type_id'] : $t['deduction_type_id']);
            if (!$code) {
                throw new RuntimeException('Select a transaction code.');
            }
            $c = term_compute(['principal' => $d['principal'], 'monthly_rate' => $d['monthly_rate'] ?? 0, 'terms' => $d['terms'], 'trans_term' => $transTerm,
                'interest_amount' => $d['interest_amount'] ?? null, 'per_pay_principal' => $d['per_pay_principal'] ?? null, 'per_pay_interest' => $d['per_pay_interest'] ?? null], $freq);
            if ($c['principal'] <= 0 || $c['per_pay'] <= 0) {
                throw new RuntimeException('The amount and the amount per payday must be greater than zero.');
            }
            if ($c['terms'] < 1 || $c['terms'] > 520) {
                throw new RuntimeException('Terms must be between 1 and 520 paydays.');
            }
            $start = ($d['start_date'] ?? '') ?: $t['start_date'];
            $dates = term_schedule_dates((int)$t['pay_group_id'], $freq, $start, $c['terms'], $c['trans_term']);
            q("DELETE FROM employee_term_schedule WHERE term_id = ? AND status IN ('pending','cancelled')", [$termId]);
            $sumP = $sumI = 0.0;
            for ($i = 1; $i <= $c['terms']; $i++) {
                $pr = max(0, $i < $c['terms'] ? $c['per_pay_principal'] : round($c['principal'] - $sumP, 2));
                $in = max(0, $i < $c['terms'] ? $c['per_pay_interest'] : round($c['interest_amount'] - $sumI, 2));
                $sumP += $pr;
                $sumI += $in;
                insert('employee_term_schedule', ['term_id' => $termId, 'seq_no' => $i, 'due_date' => $dates[$i - 1]['date'] ?? null, 'cutoff_id' => $dates[$i - 1]['cutoff_id'] ?? null,
                    'principal' => $pr, 'interest' => $in, 'amount' => round($pr + $in, 2), 'status' => 'pending']);
            }
            update('employee_term_trans', ['trans_type' => $type, 'earning_type_id' => $type === 'earning' ? $code : null, 'deduction_type_id' => $type === 'earning' ? null : $code,
                'principal' => $c['principal'], 'monthly_rate' => $c['monthly_rate'], 'interest_amount' => $c['interest_amount'], 'total_amount' => $c['total_amount'],
                'terms' => $c['terms'], 'per_pay_principal' => $c['per_pay_principal'], 'per_pay_interest' => $c['per_pay_interest'], 'trans_term' => $c['trans_term'],
                'start_date' => $start, 'updated_at' => now()], 'id = ?', [$termId]);
        } else {
            // the balance left is spread again over the remaining paydays
            $pending = rows("SELECT * FROM employee_term_schedule WHERE term_id = ? AND status = 'pending' ORDER BY seq_no", [$termId]);
            if (!$pending) {
                return;
            }
            $n = max(1, min(520, (int)($d['remaining_terms'] ?? count($pending))));
            $remP = round(array_sum(array_column($pending, 'principal')), 2);
            $remI = round(array_sum(array_column($pending, 'interest')), 2);
            $start = ($d['start_date'] ?? '') ?: ($pending[0]['due_date'] ?: today());
            $dates = term_schedule_dates((int)$t['pay_group_id'], $freq, $start, $n, $transTerm);
            $seq = max((int)$pending[0]['seq_no'], 1 + (int)val("SELECT MAX(seq_no) FROM employee_term_schedule WHERE term_id = ? AND status IN ('posted','paid')", [$termId]));
            q("DELETE FROM employee_term_schedule WHERE term_id = ? AND status = 'pending'", [$termId]);
            $pp = round($remP / $n, 2);
            $pi = round($remI / $n, 2);
            $sumP = $sumI = 0.0;
            for ($i = 0; $i < $n; $i++) {
                $pr = $i < $n - 1 ? $pp : round($remP - $sumP, 2);
                $in = $i < $n - 1 ? $pi : round($remI - $sumI, 2);
                $sumP += $pr;
                $sumI += $in;
                insert('employee_term_schedule', ['term_id' => $termId, 'seq_no' => $seq + $i, 'due_date' => $dates[$i]['date'] ?? null, 'cutoff_id' => $dates[$i]['cutoff_id'] ?? null,
                    'principal' => max(0, $pr), 'interest' => max(0, $in), 'amount' => round(max(0, $pr) + max(0, $in), 2), 'status' => 'pending']);
            }
            update('employee_term_trans', ['terms' => $seq - 1 + $n, 'per_pay_principal' => $pp, 'per_pay_interest' => $pi, 'trans_term' => $transTerm, 'updated_at' => now()], 'id = ?', [$termId]);
        }
    });
    term_recalc($termId);
    $after = row('SELECT * FROM employee_term_trans WHERE id = ?', [$termId]);
    audit('payroll', 'term_trans_update', 'employee_term_trans', $termId, diff_fields($before, $after) + ($taken ? ['installments_taken' => $taken] : []));
    return $reserved;
}
