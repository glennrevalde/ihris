<?php
/**
 * Subsidiary ledgers (loans, cash advances, payables and accrued benefits) tracked like receivables:
 * principal, amortization schedule, payments per cutoff, running balance and full-payment date.
 */

const LEDGER_TYPES = [
    'gov_loan' => 'Government Loan (SSS / Pag-IBIG)',
    'company_loan' => 'Company Loan',
    'cash_advance' => 'Cash Advance',
    'payable' => 'Other Payable / Accountability',
    'accrued_benefit' => 'Accrued Benefit',
];

/** Create a loan / payable ledger with its opening charge and (optionally) its payroll deduction. */
function ledger_create(array $d): int
{
    return tx(function () use ($d) {
        $principal = round((float)$d['principal'], 2);
        $interest = round($principal * (float)($d['interest_rate_pct'] ?? 0) / 100, 2);
        $total = $principal + $interest;
        $id = insert('ledgers', [
            'ledger_no' => next_number('LG', 'ledgers', 'ledger_no'), 'employee_id' => $d['employee_id'], 'ledger_type' => $d['ledger_type'],
            'sub_type' => $d['sub_type'], 'reference_no' => $d['reference_no'] ?? null, 'principal' => $principal,
            'interest_rate_pct' => $d['interest_rate_pct'] ?? 0, 'term_months' => $d['term_months'] ?? null, 'total_payable' => $total,
            'amortization' => $d['amortization'] ?? 0, 'start_date' => $d['start_date'] ?? today(), 'balance' => 0,
            'deduction_type_id' => $d['deduction_type_id'] ?? null, 'remarks' => $d['remarks'] ?? null,
        ]);
        ledger_post($id, $d['start_date'] ?? today(), 'Principal' . ($interest > 0 ? ' + interest (' . $d['interest_rate_pct'] . '%)' : ''), $total, 0);
        if (!empty($d['deduction_type_id']) && $d['ledger_type'] !== 'accrued_benefit') {
            $dt = row('SELECT * FROM deduction_types WHERE id = ?', [$d['deduction_type_id']]);
            insert('employee_deductions', [
                'employee_id' => $d['employee_id'], 'deduction_type_id' => $d['deduction_type_id'], 'ledger_id' => $id,
                'amount' => $d['amortization'], 'start_date' => $d['start_date'] ?? today(), 'written_consent' => !empty($d['written_consent']) ? 1 : 0,
                'status' => ($dt && $dt['requires_approval']) ? 'pending' : 'active', 'remarks' => 'Amortization of ' . $d['sub_type'], 'created_by' => user_id(),
            ]);
        }
        audit('payroll', 'ledger_create', 'ledgers', $id, $d);
        return $id;
    });
}

/** Post a movement; returns the new balance. */
function ledger_post(int $ledgerId, string $date, string $desc, float $increase, float $decrease, ?int $runId = null): float
{
    $l = row('SELECT * FROM ledgers WHERE id = ? FOR UPDATE', [$ledgerId]);
    $bal = round((float)$l['balance'] + $increase - $decrease, 2);
    insert('ledger_entries', [
        'ledger_id' => $ledgerId, 'entry_date' => $date, 'payroll_run_id' => $runId, 'description' => $desc,
        'increase' => round($increase, 2), 'decrease' => round($decrease, 2), 'balance' => $bal, 'created_by' => user_id(),
    ]);
    $upd = ['balance' => $bal];
    if ($l['ledger_type'] !== 'accrued_benefit') {
        if ($bal <= 0.004 && $decrease > 0) {
            $upd['status'] = 'paid';
            $upd['full_payment_date'] = $date;
            q("UPDATE employee_deductions SET status = 'completed' WHERE ledger_id = ? AND status IN ('active','pending')", [$ledgerId]);
        } elseif ($bal > 0 && $l['status'] === 'paid') {
            $upd['status'] = 'active';
            $upd['full_payment_date'] = null;
        }
    }
    update('ledgers', $upd, 'id = ?', [$ledgerId]);
    return $bal;
}

/** Get (or create) an employee's accrued-benefit ledger, e.g. "13th Month Pay" or "Leave Conversion". */
function ledger_accrual(int $empId, string $subType): array
{
    $l = row("SELECT * FROM ledgers WHERE employee_id = ? AND ledger_type = 'accrued_benefit' AND sub_type = ? AND status = 'active' ORDER BY id DESC LIMIT 1", [$empId, $subType]);
    if ($l) {
        return $l;
    }
    $id = insert('ledgers', [
        'ledger_no' => next_number('LG', 'ledgers', 'ledger_no'), 'employee_id' => $empId, 'ledger_type' => 'accrued_benefit',
        'sub_type' => $subType, 'principal' => 0, 'total_payable' => 0, 'balance' => 0, 'start_date' => today(),
    ]);
    return row('SELECT * FROM ledgers WHERE id = ?', [$id]);
}

/**
 * Projected amortization schedule of a ledger, built from its transaction (start) date, not from today:
 * a loan or payable encoded backwards (migration from a manual system, a payroll processed on an
 * earlier date) gets the payroll dates it really falls on. The first installment is the pay period
 * that ends on or after the start date; each date is the pay date of that period (the defined period
 * when it exists, otherwise the pattern of the pay code's periods: the same period-end days of the
 * month and the same days to the pay date). Installments covered by the payments applied are marked
 * paid; the rest are due. Rows: no, date, period_end, payment, balance, status (paid | due).
 */
function ledger_schedule(array $l, string $frequency = 'semi_monthly'): array
{
    $out = [];
    $amort = (float)$l['amortization'];
    if ($amort <= 0 || $l['ledger_type'] === 'accrued_benefit') {
        return $out;
    }
    $total = (float)$l['total_payable'];
    $bal = (float)$l['balance'];
    if ($total < $bal) {
        $total = $bal;   // charges posted after the opening entry
    }
    $paid = round(max(0, $total - $bal), 2);
    $start = (string)($l['start_date'] ?: (val('SELECT MIN(entry_date) FROM ledger_entries WHERE ledger_id = ?', [$l['id']]) ?: substr((string)$l['created_at'], 0, 10)));
    $pg = (int)val('SELECT pay_group_id FROM employees WHERE id = ?', [$l['employee_id']]);
    $count = $amort > 0 ? (int)min(600, ceil(($total - 0.004) / $amort)) : 0;
    $cum = 0.0;
    foreach (pay_period_dates($pg, $frequency, $start, $count) as $i => $p) {
        $pay = round(min($amort, $total - $cum), 2);
        if ($pay <= 0) {
            break;
        }
        $cum = round($cum + $pay, 2);
        $out[] = ['no' => $i + 1, 'date' => $p['pay_date'], 'period_end' => $p['period_end'], 'payment' => $pay, 'balance' => round($total - $cum, 2),
            'status' => $cum <= $paid + 0.004 ? 'paid' : 'due'];
    }
    return $out;
}

/**
 * The next $count pay periods of a pay code from a date: [period_end, pay_date] of the periods ending on
 * or after $from. Defined periods (Period Parameters) are used as they are; beyond them, or before the
 * first one, the dates follow the pattern of the defined periods (their period-end days of the month and
 * the days from period end to pay date), or the pay frequency when no period is defined.
 */
function pay_period_dates(int $payGroupId, string $frequency, string $from, int $count): array
{
    $out = [];
    if ($count <= 0) {
        return $out;
    }
    $defined = [];
    $endDays = [];
    $offsets = [];
    $byEnd = [];
    // defined payroll periods, then the pay dates of the regular payroll runs (the actual payroll dates)
    $src = $payGroupId ? array_merge(rows('SELECT period_end, pay_date FROM attendance_cutoffs WHERE pay_group_id = ? ORDER BY period_end', [$payGroupId]),
        rows("SELECT period_end, pay_date FROM payroll_runs WHERE pay_group_id = ? AND run_type = 'regular' AND pay_date IS NOT NULL ORDER BY period_end", [$payGroupId])) : [];
    foreach ($src as $c) {
        $defined[$c['period_end']] = $c['pay_date'] ?: ($defined[$c['period_end']] ?? $c['period_end']);
        $t = strtotime($c['period_end']);
        $key = (int)date('j', $t) === (int)date('t', $t) ? 31 : (int)date('j', $t);
        $endDays[$key] = true;
        if ($c['pay_date']) {
            $o = (int)round((strtotime($c['pay_date']) - $t) / 86400);
            $offsets[] = $o;
            $byEnd[$key][] = $o;
        }
    }
    sort($offsets);
    $median = function (array $a) {
        sort($a);
        return $a ? $a[intdiv(count($a), 2)] : 0;
    };
    $offset = $median($offsets);
    if (in_array($frequency, ['weekly', 'daily'], true)) {
        $d = date('Y-m-d', strtotime($from . ' -1 day'));
        while (count($out) < $count) {
            $d = next_pay_date($d, $frequency);
            $out[] = ['period_end' => $d, 'pay_date' => $defined[$d] ?? date('Y-m-d', strtotime($d . " +$offset day"))];
        }
        return $out;
    }
    if (!$endDays) {
        $endDays = $frequency === 'monthly' ? [31 => true] : [15 => true, 31 => true];
    }
    $days = array_keys($endDays);
    sort($days);
    $month = strtotime(date('Y-m-01', strtotime($from)));
    for ($guard = 0; count($out) < $count && $guard < 1200; $guard++) {
        $last = (int)date('t', $month);
        foreach ($days as $dd) {
            $end = date('Y-m-', $month) . str_pad((string)min($dd, $last), 2, '0', STR_PAD_LEFT);
            if ($end < $from || count($out) >= $count) {
                continue;
            }
            $te = strtotime($end);
            $k = (int)date('j', $te) === (int)date('t', $te) ? 31 : (int)date('j', $te);
            $o = isset($byEnd[$k]) ? $median($byEnd[$k]) : $offset;
            $out[] = ['period_end' => $end, 'pay_date' => $defined[$end] ?? date('Y-m-d', strtotime($end . " +$o day"))];
        }
        $month = strtotime(date('Y-m-01', $month) . ' +1 month');
    }
    return $out;
}

/** Projected schedule as a table (ledger screen, Payroll Inquiry subsidiary view). */
function ledger_schedule_table(array $sched): string
{
    $h = '<div class="table-wrap sched-wrap"><table class="table compact sched-table"><thead><tr><th>#</th><th>Payroll date</th><th class="num">Payment</th><th class="num">Balance</th><th>Status</th></tr></thead><tbody>';
    foreach ($sched as $s) {
        $h .= '<tr class="' . ($s['status'] === 'paid' ? 'sched-paid' : '') . '"><td>' . (int)$s['no'] . '</td><td class="nowrap">' . h(fmt_date($s['date']))
            . (!empty($s['period_end']) && $s['period_end'] !== $s['date'] ? '<br><small class="muted">period to ' . h(fmt_date($s['period_end'], 'M d')) . '</small>' : '')
            . '</td><td class="num">' . money($s['payment']) . '</td><td class="num">' . money($s['balance']) . '</td><td class="small">' . ($s['status'] === 'paid' ? '<span class="state-on">Paid</span>' : 'Due') . '</td></tr>';
    }
    return $h . '</tbody></table></div>';
}

/** The same day a month later (the last day of the month stays the last day). */
function ledger_add_month(string $date): string
{
    $t = strtotime($date);
    $first = strtotime(date('Y-m-01', $t) . ' +1 month');
    if ((int)date('j', $t) === (int)date('t', $t)) {
        return date('Y-m-t', $first);
    }
    return date('Y-m-', $first) . str_pad((string)min((int)date('j', $t), (int)date('t', $first)), 2, '0', STR_PAD_LEFT);
}

/** The next installment still due on a projected schedule (null when everything is paid). */
function ledger_schedule_next(array $sched): ?array
{
    foreach ($sched as $s) {
        if ($s['status'] === 'due') {
            return $s;
        }
    }
    return null;
}

function next_pay_date(string $date, string $frequency): string
{
    $t = strtotime($date);
    switch ($frequency) {
        case 'monthly':
            return date('Y-m-t', strtotime(date('Y-m-01', $t) . ' +1 month'));
        case 'weekly':
            return date('Y-m-d', $t + 7 * 86400);
        case 'daily':
            return date('Y-m-d', $t + 86400);
        default:
            $d = (int)date('j', $t);
            if ($d < 15) {
                return date('Y-m-15', $t);
            }
            if ($d < (int)date('t', $t)) {
                return date('Y-m-t', $t);
            }
            return date('Y-m-15', strtotime(date('Y-m-01', $t) . ' +1 month'));
    }
}

/**
 * Recompute the running balance of every entry of a ledger (date order), then the ledger's balance,
 * status and full-payment date. Used after an entry is edited or deleted.
 */
function ledger_rebalance(int $ledgerId): void
{
    $l = row('SELECT * FROM ledgers WHERE id = ?', [$ledgerId]);
    if (!$l) {
        return;
    }
    $bal = 0.0;
    $lastPayDate = null;
    $sumInc = 0.0;
    foreach (rows('SELECT * FROM ledger_entries WHERE ledger_id = ? ORDER BY entry_date, id', [$ledgerId]) as $e) {
        $bal = round($bal + (float)$e['increase'] - (float)$e['decrease'], 2);
        $sumInc += (float)$e['increase'];
        if ((float)$e['decrease'] > 0) {
            $lastPayDate = $e['entry_date'];
        }
        if (abs((float)$e['balance'] - $bal) > 0.004) {
            update('ledger_entries', ['balance' => $bal], 'id = ?', [$e['id']]);
        }
    }
    $upd = ['balance' => $bal];
    if ($l['ledger_type'] !== 'accrued_benefit') {
        $upd['total_payable'] = round($sumInc, 2);
        if ($l['status'] !== 'cancelled') {
            if ($bal <= 0.004 && $sumInc > 0) {
                $upd['status'] = 'paid';
                $upd['full_payment_date'] = $lastPayDate;
                q("UPDATE employee_deductions SET status = 'completed' WHERE ledger_id = ? AND status IN ('active','pending')", [$ledgerId]);
            } else {
                $upd['status'] = 'active';
                $upd['full_payment_date'] = null;
                if ($l['status'] === 'paid') {
                    q("UPDATE employee_deductions SET status = 'active' WHERE ledger_id = ? AND status = 'completed'", [$ledgerId]);
                }
            }
        }
    }
    update('ledgers', $upd, 'id = ?', [$ledgerId]);
}

/** Edit a subsidiary ledger entry (date, description, charge, payment) with the reason; balances follow. */
function ledger_entry_update(int $entryId, string $date, string $desc, float $increase, float $decrease, string $reason): array
{
    $e = row('SELECT * FROM ledger_entries WHERE id = ?', [$entryId]);
    if (!$e) {
        throw new RuntimeException('Ledger entry not found.');
    }
    $l = row('SELECT * FROM ledgers WHERE id = ?', [$e['ledger_id']]);
    if (!$l || $l['status'] === 'cancelled') {
        throw new RuntimeException('Entries of a cancelled ledger cannot be changed.');
    }
    $desc = trim($desc);
    $reason = trim($reason);
    $increase = round(max(0, $increase), 2);
    $decrease = round(max(0, $decrease), 2);
    if ($desc === '' || $reason === '') {
        throw new RuntimeException('The description and the reason for the change are required.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
        throw new RuntimeException('Enter a valid date.');
    }
    if ($increase > 0 && $decrease > 0) {
        throw new RuntimeException('An entry is either a charge or a payment, not both.');
    }
    if ($increase <= 0 && $decrease <= 0) {
        throw new RuntimeException('Enter the amount of the charge or of the payment.');
    }
    $new = ['entry_date' => $date, 'description' => mb_substr($desc, 0, 255), 'increase' => $increase, 'decrease' => $decrease];
    tx(function () use ($entryId, $new, $reason, $e) {
        update('ledger_entries', $new + ['edited_at' => now(), 'edited_by' => user_id(), 'edit_note' => mb_substr($reason, 0, 255)], 'id = ?', [$entryId]);
        ledger_rebalance((int)$e['ledger_id']);
    });
    $diff = diff_fields($e, $new);
    audit('payroll', 'ledger_entry_update', 'ledgers', $e['ledger_id'], ['entry' => $entryId, 'changes' => $diff, 'reason' => $reason]);
    return $diff;
}

/** Delete a subsidiary ledger entry with the reason; balances follow. */
function ledger_entry_delete(int $entryId, string $reason): void
{
    $e = row('SELECT * FROM ledger_entries WHERE id = ?', [$entryId]);
    if (!$e) {
        throw new RuntimeException('Ledger entry not found.');
    }
    $l = row('SELECT * FROM ledgers WHERE id = ?', [$e['ledger_id']]);
    if (!$l || $l['status'] === 'cancelled') {
        throw new RuntimeException('Entries of a cancelled ledger cannot be changed.');
    }
    if (trim($reason) === '') {
        throw new RuntimeException('Give the reason for deleting the entry.');
    }
    tx(function () use ($e) {
        q('DELETE FROM ledger_entries WHERE id = ?', [$e['id']]);
        ledger_rebalance((int)$e['ledger_id']);
    });
    audit('payroll', 'ledger_entry_delete', 'ledgers', $e['ledger_id'], ['entry' => $e, 'reason' => trim($reason)]);
}
