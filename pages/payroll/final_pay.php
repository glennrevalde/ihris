<?php
/**
 * Final pay / back pay for separated employees: last salary, pro-rated 13th month, unused leave conversion,
 * other pending earnings, less outstanding loans / accountabilities, with year-end tax annualization.
 * Release is due within the DOLE-prescribed period from separation.
 * v17: the items of the breakdown can be edited (particulars, quantity, amount) or deleted, and items can be
 * added. Added items are payroll adjustments of the final pay run (taxable or not, part of the computation);
 * edited and deleted items are kept in final_pay_overrides and applied again after every Recompute.
 * v18: Cancel Calculation voids a final pay that is not released (reason, who and when are kept; its run is
 * cancelled and the pending items it took go back to the next payroll; optionally the employee is reinstated).
 * Undo cancellation brings it back as a draft; a new calculation can be made after a cancellation.
 */

/** Statutory columns of the payroll item that follow an edited deduction line. */
const FP_LINE_COLS = ['WTAX' => 'withholding_tax', 'SSS' => 'sss_ee', 'PHIC' => 'phic_ee', 'HDMF' => 'hdmf_ee', 'HDMF_VOL' => 'hdmf_ee_voluntary'];

/** Key of a breakdown line that survives a recompute: type, code, reference and the rank among the same ones. */
function final_pay_line_keys(array $lines): array
{
    $seen = [];
    $out = [];
    foreach ($lines as $l) {
        $base = $l['line_type'] . '|' . $l['code'] . '|' . ($l['ref_type'] ?? '') . '|' . ($l['ref_id'] ?? '');
        $seen[$base] = ($seen[$base] ?? 0) + 1;
        $out[(int)$l['id']] = mb_substr($base . '|' . $seen[$base], 0, 120);
    }
    return $out;
}

function final_pay_overrides_supported(): bool
{
    static $ok = [];
    $db = function_exists('active_db_name') ? active_db_name() : '';
    if (!isset($ok[$db])) {
        try {
            $ok[$db] = (bool)val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'final_pay_overrides'");
        } catch (Throwable $e) {
            $ok[$db] = false;
        }
    }
    return $ok[$db];
}

/**
 * Apply the edited / deleted items of a final pay to its payroll item (after a computation): the lines are
 * changed or removed and the gross pay, the deductions and the net pay follow.
 */
function final_pay_apply_overrides(int $fpId): void
{
    if (!final_pay_overrides_supported()) {
        return;
    }
    $fp = row('SELECT * FROM final_pays WHERE id = ?', [$fpId]);
    $it = $fp ? row('SELECT * FROM payroll_items WHERE run_id = ?', [$fp['payroll_run_id']]) : null;
    $ovs = $it ? rows('SELECT * FROM final_pay_overrides WHERE final_pay_id = ?', [$fpId]) : [];
    if (!$ovs) {
        return;
    }
    $byKey = [];
    foreach ($ovs as $o) {
        $byKey[$o['line_key']] = $o;
    }
    $lines = rows('SELECT * FROM payroll_item_lines WHERE item_id = ? ORDER BY sort_order, id', [$it['id']]);
    $keys = final_pay_line_keys($lines);
    $dGross = $dDed = $dOther = 0.0;
    $cols = [];
    foreach ($lines as $l) {
        $o = $byKey[$keys[(int)$l['id']]] ?? null;
        if (!$o || !in_array($l['line_type'], ['earning', 'deduction'], true)) {
            continue;
        }
        $old = (float)$l['amount'];
        if ($o['action'] === 'delete') {
            q('DELETE FROM payroll_item_lines WHERE id = ?', [$l['id']]);
            $new = 0.0;
        } else {
            $new = round((float)$o['amount'], 2);
            update('payroll_item_lines', ['amount' => $new, 'description' => $o['description'] !== null && $o['description'] !== '' ? $o['description'] : $l['description'],
                'quantity' => $o['quantity'] !== null ? $o['quantity'] : $l['quantity']], 'id = ?', [$l['id']]);
        }
        $delta = round($new - $old, 2);
        if ($l['line_type'] === 'earning') {
            $dGross += $delta;
        } else {
            $dDed += $delta;
            if (isset(FP_LINE_COLS[$l['code']])) {
                $cols[FP_LINE_COLS[$l['code']]] = ($cols[FP_LINE_COLS[$l['code']]] ?? 0) + $delta;
            } else {
                $dOther += $delta;
            }
        }
    }
    $u = ['gross_pay' => round((float)$it['gross_pay'] + $dGross, 2), 'total_deductions' => round((float)$it['total_deductions'] + $dDed, 2),
        'other_deductions' => round((float)$it['other_deductions'] + $dOther, 2)];
    foreach ($cols as $c => $d) {
        $u[$c] = round((float)$it[$c] + $d, 2);
    }
    $u['net_pay'] = round($u['gross_pay'] - $u['total_deductions'], 2);
    update('payroll_items', $u, 'id = ?', [$it['id']]);
}

/** Compute (or recompute) the final pay run, then the edited / deleted items and the summary. */
function final_pay_recompute(int $fpId): void
{
    $fp = row('SELECT * FROM final_pays WHERE id = ?', [$fpId]);
    payroll_compute((int)$fp['payroll_run_id']);
    final_pay_apply_overrides($fpId);
    final_pay_summarize($fpId);
}
function final_pay_prepare(int $empId, string $sepDate): int
{
    $emp = row('SELECT * FROM employees WHERE id = ?', [$empId]);
    $days = (int)setting('final_pay_days', 30);
    $fp = row("SELECT * FROM final_pays WHERE employee_id = ? AND status NOT IN ('released', 'cancelled') ORDER BY id DESC LIMIT 1", [$empId]);   // a cancelled calculation is kept as is
    $lastPaid = val("SELECT MAX(pr.period_end) FROM payroll_items pi JOIN payroll_runs pr ON pr.id = pi.run_id WHERE pi.employee_id = ? AND pr.status = 'finalized' AND pr.run_type = 'regular'", [$empId]);
    $start = $lastPaid ? min(add_days($lastPaid, 1), $sepDate) : max($emp['date_hired'], date('Y-01-01', strtotime($sepDate)));
    return tx(function () use ($emp, $fp, $start, $sepDate, $days) {
        if ($emp['status'] !== 'separated' || $emp['date_separated'] !== $sepDate) {
            // the computation always uses the separation date on the masterfile
            update('employees', ['date_separated' => $sepDate], 'id = ?', [$emp['id']]);
        }
        if ($fp && $fp['payroll_run_id']) {
            update('payroll_runs', ['period_start' => $start, 'period_end' => $sepDate, 'pay_date' => add_days($sepDate, $days)], 'id = ?', [$fp['payroll_run_id']]);
            update('final_pays', ['separation_date' => $sepDate, 'due_date' => add_days($sepDate, $days), 'status' => 'draft'], 'id = ?', [$fp['id']]);
            return (int)$fp['id'];
        }
        $runId = insert('payroll_runs', ['run_no' => next_number('FP', 'payroll_runs', 'run_no'), 'pay_group_id' => $emp['pay_group_id'], 'run_type' => 'final_pay',
            'period_start' => $start, 'period_end' => $sepDate, 'pay_date' => add_days($sepDate, $days), 'notes' => 'Final pay of ' . full_name($emp, false), 'created_by' => user_id()]);
        return insert('final_pays', ['employee_id' => $emp['id'], 'separation_date' => $sepDate, 'due_date' => add_days($sepDate, $days), 'payroll_run_id' => $runId, 'created_by' => user_id()]);
    });
}

function final_pay_summarize(int $fpId): void
{
    $fp = row('SELECT * FROM final_pays WHERE id = ?', [$fpId]);
    $it = row('SELECT * FROM payroll_items WHERE run_id = ?', [$fp['payroll_run_id']]);
    if (!$it) {
        return;
    }
    $lines = rows('SELECT * FROM payroll_item_lines WHERE item_id = ?', [$it['id']]);
    $sum = fn(callable $f) => round(array_sum(array_map(fn($l) => (float)$l['amount'], array_filter($lines, $f))), 2);
    $salaryCodes = fn($l) => $l['line_type'] === 'earning' && (in_array($l['code'], ['BASIC', 'LEAVE', 'HOLIDAY', 'ABSENT', 'LATE_UT', 'ND'], true) || strpos($l['code'], 'OT_') === 0 || strpos($l['code'], 'PREM_') === 0);
    update('final_pays', [
        'last_salary' => $sum($salaryCodes),
        'prorated_13th' => $sum(fn($l) => $l['line_type'] === 'earning' && $l['code'] === '13TH'),
        'leave_conversion' => $sum(fn($l) => $l['line_type'] === 'earning' && strpos($l['code'], 'LEAVECONV') === 0),
        'other_earnings' => round((float)$it['gross_pay'] - $sum($salaryCodes) - $sum(fn($l) => $l['line_type'] === 'earning' && ($l['code'] === '13TH' || strpos($l['code'], 'LEAVECONV') === 0)), 2),
        'loan_offsets' => $sum(fn($l) => $l['line_type'] === 'deduction' && in_array($l['ref_type'], ['ledger'], true)),
        'other_deductions' => round((float)$it['total_deductions'] - (float)$it['withholding_tax'] - $sum(fn($l) => $l['line_type'] === 'deduction' && $l['ref_type'] === 'ledger'), 2),
        'tax_adjustment' => (float)$it['withholding_tax'] - $sum(fn($l) => $l['code'] === 'TAXREFUND'),
        'net_final_pay' => $it['net_pay'],
    ], 'id = ?', [$fpId]);
}

/** v18: has the database the Cancel Calculation columns (version 16)? */
function final_pay_cancel_supported(): bool
{
    static $ok = null;
    return $ok ??= (bool)val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'final_pays' AND column_name = 'cancelled_at'");
}

/**
 * Cancel Calculation (v18): the final pay computation is voided, e.g. the resignation was withdrawn or the
 * computation was made by mistake. The record stays (status Cancelled, who / when / why) for the audit; its
 * payroll run is cancelled and the pending transactions, benefits and loan schedules it had picked up are free
 * for the next payroll. Optionally the separation is cancelled too (the employee is active again).
 */
function final_pay_cancel(array $fp, string $reason, bool $reinstate): void
{
    tx(function () use ($fp, $reason, $reinstate) {
        if ($fp['payroll_run_id']) {
            $runId = (int)$fp['payroll_run_id'];
            update('payroll_runs', ['status' => 'cancelled'], 'id = ?', [$runId]);
            q("UPDATE pending_earnings SET payroll_run_id = NULL WHERE payroll_run_id = ? AND status = 'pending'", [$runId]);
            q("UPDATE employee_transactions SET payroll_run_id = NULL WHERE payroll_run_id = ? AND status = 'pending'", [$runId]);
            q("UPDATE employee_term_schedule SET payroll_run_id = NULL WHERE payroll_run_id = ? AND status = 'pending'", [$runId]);
            q("UPDATE payroll_exceptions SET status = 'resolved', resolution = 'Final pay calculation cancelled', resolved_by = ?, resolved_at = NOW() WHERE run_id = ? AND status = 'open'", [user_id(), $runId]);
        }
        update('final_pays', ['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => user_id(), 'cancel_reason' => mb_substr($reason, 0, 255) ?: null], 'id = ?', [$fp['id']]);
        if ($reinstate) {
            $e = row('SELECT * FROM employees WHERE id = ?', [$fp['employee_id']]);
            if ($e && $e['status'] === 'separated') {   // same as Reinstate on the 201 file
                employee_apply_changes((int)$e['id'], ['status' => 'active', 'date_separated' => null, 'separation_type' => null, 'separation_reason' => null], today(), 'Reinstated: final pay calculation cancelled', 'status_change');
            } elseif ($e && $e['date_separated']) {
                update('employees', ['date_separated' => null], 'id = ?', [$e['id']]);   // the computation had written the separation date
            }
        }
    });
}

if (is_post()) {
    $act = post('_action');
    if ($act === 'compute') {
        $empId = (int)post('employee_id');
        $sep = post('separation_date');
        if (!$empId || !$sep) {
            flash('error', 'Select the employee and the separation date.');
            redirect('final_pay');
        }
        $fpId = final_pay_prepare($empId, $sep);
        final_pay_recompute($fpId);
        audit('payroll', 'final_pay_compute', 'final_pays', $fpId);
        flash('success', 'Final pay computed. Review the breakdown, then approve and release.');
        redirect('final_pay', ['id' => $fpId]);
    }
    $fp = row('SELECT * FROM final_pays WHERE id = ?', [(int)post('id')]);
    if ($fp && in_array($act, ['line_edit', 'line_delete', 'line_restore', 'line_add', 'adj_delete'], true)) {
        // v17: edit / delete / add items of the breakdown (not once released)
        if ($fp['status'] === 'released') {
            flash('error', 'A released final pay can no longer be changed.');
            redirect('final_pay', ['id' => (int)$fp['id']]);
        }
        $it = row('SELECT * FROM payroll_items WHERE run_id = ?', [$fp['payroll_run_id']]);
        try {
            if ($act === 'line_add') {
                $desc = trim((string)post('description'));
                $amt = round((float)str_replace(',', '', (string)post('amount')), 2);
                if ($desc === '' || $amt <= 0) {
                    throw new RuntimeException('Enter the particulars and an amount greater than zero.');
                }
                $aid = insert('payroll_adjustments', ['run_id' => $fp['payroll_run_id'], 'employee_id' => $fp['employee_id'], 'adj_type' => post('adj_type') === 'deduction' ? 'deduction' : 'earning',
                    'description' => mb_substr($desc, 0, 255), 'amount' => $amt, 'taxable' => post('taxable') ? 1 : 0, 'reason' => 'Final pay item added', 'created_by' => user_id()]);
                audit('payroll', 'final_pay_item_add', 'final_pays', $fp['id'], ['adjustment_id' => $aid, 'description' => $desc, 'amount' => $amt]);
                $msg = 'Item added to the final pay.';
            } elseif ($act === 'adj_delete') {
                $a = row('SELECT * FROM payroll_adjustments WHERE id = ? AND run_id = ?', [(int)post('adj_id'), $fp['payroll_run_id']]);
                if (!$a) {
                    throw new RuntimeException('Item not found.');
                }
                q('DELETE FROM payroll_adjustments WHERE id = ?', [$a['id']]);
                audit('payroll', 'final_pay_item_delete', 'final_pays', $fp['id'], $a);
                $msg = 'Added item deleted.';
            } else {
                if (!final_pay_overrides_supported()) {
                    throw new RuntimeException('Open the site once to upgrade the database (version 15) before editing final pay items.');
                }
                $key = (string)post('line_key');
                if ($act === 'line_restore') {
                    q('DELETE FROM final_pay_overrides WHERE final_pay_id = ? AND line_key = ?', [$fp['id'], $key]);
                    audit('payroll', 'final_pay_item_restore', 'final_pays', $fp['id'], ['line' => $key]);
                    $msg = 'Item restored to the computed value.';
                } else {
                    $lines = $it ? rows('SELECT * FROM payroll_item_lines WHERE item_id = ? ORDER BY sort_order, id', [$it['id']]) : [];
                    $keys = final_pay_line_keys($lines);
                    $line = null;
                    foreach ($lines as $l) {
                        if ($keys[(int)$l['id']] === $key) {   // the key survives a recompute (the line id does not)
                            $line = $l;
                        }
                    }
                    if (!$line || !in_array($line['line_type'], ['earning', 'deduction'], true)) {
                        throw new RuntimeException('Item not found. Recompute and try again.');
                    }
                    if ($line['ref_type'] === 'adjustment') {
                        throw new RuntimeException('An added item is changed by deleting it and adding it again.');
                    }
                    $prev = row('SELECT * FROM final_pay_overrides WHERE final_pay_id = ? AND line_key = ?', [$fp['id'], $key]);
                    $data = ['final_pay_id' => $fp['id'], 'line_key' => $key, 'action' => $act === 'line_delete' ? 'delete' : 'edit',
                        'description' => $act === 'line_edit' ? (mb_substr(trim((string)post('description')), 0, 255) ?: null) : null,
                        'quantity' => $act === 'line_edit' && post('quantity') !== null && post('quantity') !== '' ? round((float)post('quantity'), 2) : null,
                        'amount' => $act === 'line_edit' ? round((float)str_replace(',', '', (string)post('amount')), 2) : null,
                        'original_description' => $prev['original_description'] ?? $line['description'], 'original_amount' => $prev['original_amount'] ?? $line['amount'],
                        'created_by' => user_id(), 'created_at' => now()];
                    if ($prev) {
                        update('final_pay_overrides', $data, 'id = ?', [$prev['id']]);
                    } else {
                        insert('final_pay_overrides', $data);
                    }
                    audit('payroll', 'final_pay_item_' . $data['action'], 'final_pays', $fp['id'], ['line' => $line['description'], 'from' => $line['amount'], 'to' => $data['amount']]);
                    $msg = $act === 'line_delete' ? 'Item deleted from the final pay.' : 'Item changed.';
                }
            }
            update('payroll_runs', ['status' => 'draft'], 'id = ?', [$fp['payroll_run_id']]);
            final_pay_recompute((int)$fp['id']);
            update('final_pays', ['status' => 'draft'], 'id = ?', [$fp['id']]);
            flash('success', $msg . ($fp['status'] === 'approved' ? ' The final pay is back to draft: approve it again.' : ''));
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect('final_pay', ['id' => (int)$fp['id']]);
    }
    if ($fp && in_array($act, ['cancel', 'uncancel'], true)) {
        if (!final_pay_cancel_supported()) {
            flash('error', 'Open the site once to upgrade the database (version 16) before cancelling a final pay calculation.');
        } elseif ($act === 'cancel' && in_array($fp['status'], ['draft', 'approved'], true)) {
            $reason = trim((string)post('cancel_reason'));
            if ($reason === '') {
                flash('error', 'Give the reason of the cancellation.');
                redirect('final_pay', ['id' => (int)$fp['id']]);
            }
            final_pay_cancel($fp, $reason, (bool)post('reinstate'));
            audit('payroll', 'final_pay_cancel', 'final_pays', $fp['id'], ['reason' => $reason, 'reinstated' => (bool)post('reinstate'), 'net' => $fp['net_final_pay']]);
            flash('success', 'Final pay calculation cancelled' . (post('reinstate') ? ' and the employee reinstated.' : '. A new calculation can be made for the employee at any time.'));
        } elseif ($act === 'uncancel' && $fp['status'] === 'cancelled') {
            // undo: only when no other calculation was made since, and the run still exists
            $other = val("SELECT id FROM final_pays WHERE employee_id = ? AND id <> ? AND status <> 'cancelled'", [$fp['employee_id'], $fp['id']]);
            $run = $fp['payroll_run_id'] ? payroll_run((int)$fp['payroll_run_id']) : null;
            if ($other) {
                flash('error', 'Another final pay calculation was made for this employee after the cancellation: use that one.');
            } elseif (!$run) {
                flash('error', 'The payroll run of this calculation was deleted: compute the final pay again.');
            } else {
                $e = row('SELECT status, date_separated FROM employees WHERE id = ?', [$fp['employee_id']]);
                if (!$e['date_separated']) {
                    update('employees', ['date_separated' => $fp['separation_date']], 'id = ?', [$fp['employee_id']]);
                }
                update('payroll_runs', ['status' => 'draft'], 'id = ?', [$run['id']]);
                update('final_pays', ['status' => 'draft', 'cancelled_at' => null, 'cancelled_by' => null, 'cancel_reason' => null], 'id = ?', [$fp['id']]);
                final_pay_recompute((int)$fp['id']);
                audit('payroll', 'final_pay_uncancel', 'final_pays', $fp['id']);
                flash('success', 'Cancellation undone: the final pay was computed again and is back to draft.');
            }
        }
        redirect('final_pay', ['id' => (int)$fp['id']]);
    }
    if ($fp && $fp['status'] === 'cancelled') {
        flash('error', 'This final pay calculation was cancelled. Undo the cancellation first, or compute a new one.');
        redirect('final_pay', ['id' => (int)$fp['id']]);
    }
    if ($fp && $act === 'recompute' && $fp['status'] !== 'released') {
        update('payroll_runs', ['status' => 'draft'], 'id = ?', [$fp['payroll_run_id']]);
        final_pay_recompute((int)$fp['id']);
        update('final_pays', ['status' => 'draft'], 'id = ?', [$fp['id']]);
        flash('success', 'Final pay recomputed' . (final_pay_overrides_supported() && val('SELECT COUNT(*) FROM final_pay_overrides WHERE final_pay_id = ?', [$fp['id']]) ? ', with the edited and deleted items kept.' : '.'));
    } elseif ($fp && $act === 'approve' && $fp['status'] === 'draft') {
        update('final_pays', ['status' => 'approved'], 'id = ?', [$fp['id']]);
        audit('payroll', 'final_pay_approve', 'final_pays', $fp['id']);
        flash('success', 'Final pay approved.');
    } elseif ($fp && $act === 'release' && $fp['status'] === 'approved') {
        $run = payroll_run((int)$fp['payroll_run_id']);
        if ($run['status'] === 'draft') {
            final_pay_recompute((int)$fp['id']);
        }
        q("UPDATE payroll_exceptions SET status = 'resolved', resolution = 'Reviewed with final pay approval', resolved_by = ?, resolved_at = NOW() WHERE run_id = ? AND status = 'open' AND blocking = 0", [user_id(), $run['id']]);
        payroll_finalize((int)$run['id']);
        $e = row('SELECT * FROM employees WHERE id = ?', [$fp['employee_id']]);
        if ($e['status'] !== 'separated') {
            employee_apply_changes((int)$e['id'], ['status' => 'separated', 'date_separated' => $fp['separation_date'], 'separation_type' => $e['separation_type'] ?: 'resignation'], $fp['separation_date'], 'Final pay released', 'separation');
        }
        // leave credits converted in the final pay are closed
        q("UPDATE leave_balances lb JOIN leave_types lt ON lt.id = lb.leave_type_id SET lb.converted = lb.converted + GREATEST(0, lb.carried_over + lb.earned + lb.adjustment - lb.used - lb.converted - lb.forfeited), lb.closed = 1
           WHERE lb.employee_id = ? AND lb.year = ? AND lt.convertible = 1", [$fp['employee_id'], (int)substr($fp['separation_date'], 0, 4)]);
        q("UPDATE ledgers SET status = 'paid', full_payment_date = ? WHERE employee_id = ? AND ledger_type = 'accrued_benefit' AND status = 'active'", [today(), $fp['employee_id']]);
        update('final_pays', ['status' => 'released', 'release_date' => today()], 'id = ?', [$fp['id']]);
        audit('payroll', 'final_pay_release', 'final_pays', $fp['id']);
        flash('success', 'Final pay released' . (today() > $fp['due_date'] ? ' (after the ' . setting('final_pay_days', 30) . '-day period).' : ' within the prescribed period.'));
    }
    redirect('final_pay', ['id' => (int)post('id')]);
}

$id = (int)get('id', 0);
if ($id) {
    $fp = row('SELECT fp.*, e.first_name, e.last_name, e.middle_name, e.suffix, e.employee_no, e.position, e.separation_type, e.separation_reason, e.date_hired, e.default_branch_id
               FROM final_pays fp JOIN employees e ON e.id = fp.employee_id WHERE fp.id = ?', [$id]);
    $it = row('SELECT * FROM payroll_items WHERE run_id = ?', [$fp['payroll_run_id']]);
    $lines = $it ? rows('SELECT * FROM payroll_item_lines WHERE item_id = ? ORDER BY sort_order', [$it['id']]) : [];
    $exs = rows("SELECT * FROM payroll_exceptions WHERE run_id = ? AND status = 'open'", [$fp['payroll_run_id']]);
    $run = $fp['payroll_run_id'] ? payroll_run((int)$fp['payroll_run_id']) : null;
    $run = $run ?: ['id' => null, 'period_start' => $fp['separation_date'], 'period_end' => $fp['separation_date'], 'status' => 'cancelled'];
    $cancelled = $fp['status'] === 'cancelled';
    if (get('print')) {
        print_header('Final Pay — ' . full_name($fp));
        echo company_letterhead(row('SELECT * FROM branches WHERE id = ?', [$fp['default_branch_id']]));
        echo '<h1>Final Pay Computation' . ($cancelled ? ' (CANCELLED)' : '') . '</h1><table class="doc-table"><tbody><tr><th>Employee</th><td>' . h(full_name($fp, false)) . ' (' . h($fp['employee_no']) . ')</td><th>Position</th><td>' . h($fp['position']) . '</td></tr>'
            . '<tr><th>Date hired</th><td>' . h(fmt_date($fp['date_hired'])) . '</td><th>Separation</th><td>' . h(fmt_date($fp['separation_date'])) . ' — ' . h(label($fp['separation_type'])) . '</td></tr>'
            . '<tr><th>Covered period</th><td>' . h(fmt_date($run['period_start'])) . ' – ' . h(fmt_date($run['period_end'])) . '</td><th>Release due</th><td>' . h(fmt_date($fp['due_date'])) . '</td></tr></tbody></table>';
        echo '<table class="doc-table"><thead><tr><th>Particulars</th><th class="num">Amount</th></tr></thead><tbody>';
        foreach ($lines as $l) {
            if (in_array($l['line_type'], ['earning', 'deduction'], true)) {
                echo '<tr><td>' . ($l['line_type'] === 'deduction' ? 'Less: ' : '') . h($l['description']) . '</td><td class="num">' . ($l['line_type'] === 'deduction' ? '(' . money($l['amount']) . ')' : money($l['amount'])) . '</td></tr>';
            }
        }
        echo '</tbody><tfoot><tr><td>NET FINAL PAY</td><td class="num">' . peso($fp['net_final_pay']) . '</td></tr></tfoot></table>';
        foreach ($lines as $l) {
            if ($l['code'] === 'ANNUALIZED') {
                echo '<p class="small">' . h($l['description']) . '</p>';
            }
        }
        echo '<p class="small">I acknowledge receipt of the amount stated above as full payment of my final pay, and that the computation has been explained to me.</p>'
            . '<div class="sig-row"><div class="sig"><div class="line">&nbsp;</div><small>Prepared by (Payroll)</small></div><div class="sig"><div class="line">&nbsp;</div><small>Approved by</small></div><div class="sig"><div class="line">' . h(full_name($fp, false)) . '</div><small>Received by / date</small></div></div>';
        print_footer();
        exit;
    }
    $acts = '<a class="btn" target="_blank" href="' . url('final_pay', ['id' => $id, 'print' => 1]) . '">Print computation</a> ';
    if (!in_array($fp['status'], ['released', 'cancelled'], true)) {
        $acts .= '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="_action" value="recompute"><input type="hidden" name="id" value="' . $id . '"><button class="btn">Recompute</button></form> ';
        if (can('final_pay.generate') && final_pay_cancel_supported()) {
            $acts .= '<button type="button" class="btn btn-danger-outline" data-modal-open="fpCancel">Cancel calculation</button> ';
        }
    }
    if ($cancelled && can('final_pay.generate')) {
        $acts .= '<form method="post" class="inline" data-confirm="Undo the cancellation? The final pay is computed again and goes back to draft.">' . csrf_field() . '<input type="hidden" name="_action" value="uncancel"><input type="hidden" name="id" value="' . $id . '"><button class="btn">Undo cancellation</button></form> '
            . '<a class="btn btn-primary" href="' . url('final_pay', ['employee_id' => $fp['employee_id']]) . '">New calculation</a> ';
    }
    if ($fp['status'] === 'draft') {
        $acts .= '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="_action" value="approve"><input type="hidden" name="id" value="' . $id . '"><button class="btn btn-primary">Approve</button></form> ';
    }
    if ($fp['status'] === 'approved') {
        $acts .= '<form method="post" class="inline" data-confirm="Release the final pay? Loan balances are offset and the record is finalized.">' . csrf_field() . '<input type="hidden" name="_action" value="release"><input type="hidden" name="id" value="' . $id . '"><button class="btn btn-success">Release</button></form>';
    }
    page_header('Final Pay — ' . full_name($fp, false), ['back' => url('final_pay'), 'actions' => $acts,
        'subtitle' => 'Separated ' . h(fmt_date($fp['separation_date'])) . ($fp['separation_type'] ? ' (' . h(label($fp['separation_type'])) . ')' : '') . ', release due ' . h(fmt_date($fp['due_date'])) . '. Status: ' . badge($fp['status'])]);
    if ($cancelled) {
        echo '<div class="alert alert-warning fp-cancelled"><span><strong>Calculation cancelled</strong>' . (!empty($fp['cancelled_at']) ? ' on ' . h(fmt_date($fp['cancelled_at'])) : '')
            . (!empty($fp['cancelled_by']) ? ' by ' . h((string)val('SELECT COALESCE(NULLIF(full_name, \'\'), username) FROM users WHERE id = ?', [$fp['cancelled_by']])) : '')
            . (!empty($fp['cancel_reason']) ? '. Reason: ' . h($fp['cancel_reason']) : '') . '. The amounts below are kept for reference only; they are not paid.</span></div>';
    }
    $overdue = !in_array($fp['status'], ['released', 'cancelled'], true) && today() > $fp['due_date'];
    if ($overdue) {
        echo '<div class="alert alert-error">The ' . (int)setting('final_pay_days', 30) . '-day release period (DOLE Labor Advisory No. 06-2020) has lapsed.</div>';
    }
    ?>
    <div class="stats">
      <?= stat_card('Last salary', peso($fp['last_salary']), h(fmt_date($run['period_start'])) . ' – ' . h(fmt_date($run['period_end']))) ?>
      <?= stat_card('Pro-rated 13th month', peso($fp['prorated_13th'])) ?>
      <?= stat_card('Leave conversion', peso($fp['leave_conversion'])) ?>
      <?= stat_card('Other earnings', peso($fp['other_earnings'])) ?>
      <?= stat_card('Loan / accountability offsets', peso($fp['loan_offsets'])) ?>
      <?= stat_card('Tax (annualized)', peso($fp['tax_adjustment']), $fp['tax_adjustment'] < 0 ? 'refund' : '') ?>
      <?= stat_card('Net final pay', peso($fp['net_final_pay']), '', 'good') ?>
    </div>
    <?php foreach ($exs as $x): ?><div class="alert alert-<?= $x['blocking'] ? 'error' : 'info' ?>"><?= h($x['message']) ?></div><?php endforeach; ?>
    <?php
    // v17: edit / delete / add items
    $editable = !in_array($fp['status'], ['released', 'cancelled'], true) && can('final_pay.generate');
    $keys = final_pay_line_keys($lines);
    $ovs = [];
    if (final_pay_overrides_supported()) {
        foreach (rows('SELECT * FROM final_pay_overrides WHERE final_pay_id = ? ORDER BY id', [$id]) as $o) {
            $ovs[$o['line_key']] = $o;
        }
    }
    $removed = array_filter($ovs, fn($o) => $o['action'] === 'delete');
    $adjs = rows('SELECT * FROM payroll_adjustments WHERE run_id = ? AND employee_id = ? ORDER BY id', [$fp['payroll_run_id'], $fp['employee_id']]);
    ?>
    <div class="card flush fp-break">
      <div class="card-head"><div><h2>Breakdown</h2><div class="sub"><?= $editable ? 'Edit or delete an item, or add one. Changed items are kept when the final pay is recomputed.' : ($cancelled ? 'Cancelled: the items are shown for reference.' : 'Released: the items can no longer be changed.') ?></div></div>
        <div class="fp-break-actions"><?php if ($it): ?><a class="btn btn-sm" href="<?= url('payslip', ['id' => $it['id']]) ?>">Open as payslip</a><?php endif; ?>
          <?php if ($editable): ?><button type="button" class="btn btn-sm btn-primary" data-modal-open="fpAdd">+ Add item</button><?php endif; ?></div></div>
      <div class="table-wrap"><table class="table compact"><thead><tr><th>Type</th><th>Particulars</th><th class="num">Qty</th><th class="num">Amount</th><?php if ($editable): ?><th class="actions"></th><?php endif; ?></tr></thead><tbody>
      <?php foreach ($lines as $l):
          $k = $keys[(int)$l['id']];
          $o = $ovs[$k] ?? null;
          $canEdit = $editable && in_array($l['line_type'], ['earning', 'deduction'], true); ?>
        <tr class="<?= $l['line_type'] === 'employer' ? 'row-muted' : '' ?><?= $o ? ' row-edited' : '' ?>">
          <td><?= h(label($l['line_type'])) ?></td>
          <td><?= h($l['description']) ?><?php if ($o): ?><br><small class="fp-edited">Edited, computed <?= money($o['original_amount']) ?><?= $o['original_description'] && $o['original_description'] !== $l['description'] ? ' as "' . h($o['original_description']) . '"' : '' ?></small><?php endif; ?>
            <?php if ($l['ref_type'] === 'adjustment'): ?><br><small class="muted">Added item</small><?php endif; ?></td>
          <td class="num"><?= $l['quantity'] !== null ? (float)$l['quantity'] : '' ?></td><td class="num"><?= money($l['amount']) ?></td>
          <?php if ($editable): ?><td class="actions nowrap">
            <?php if ($canEdit && $l['ref_type'] === 'adjustment'): ?>
              <form method="post" class="inline" data-confirm="Delete this added item?"><?= csrf_field() ?><input type="hidden" name="_action" value="adj_delete"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="adj_id" value="<?= (int)$l['ref_id'] ?>"><button class="btn btn-xs btn-danger-outline">Delete</button></form>
            <?php elseif ($canEdit): ?>
              <button type="button" class="btn btn-xs" data-modal-open="fpEdit" data-field-line-id="<?= (int)$l['id'] ?>" data-field-line-key="<?= h($k) ?>" data-field-description="<?= h($l['description']) ?>" data-field-quantity="<?= $l['quantity'] !== null ? h((string)(float)$l['quantity']) : '' ?>" data-field-amount="<?= h(number_format((float)$l['amount'], 2, '.', '')) ?>">Edit</button>
              <form method="post" class="inline" data-confirm="Delete this item from the final pay?"><?= csrf_field() ?><input type="hidden" name="_action" value="line_delete"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="line_id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="line_key" value="<?= h($k) ?>"><button class="btn btn-xs btn-danger-outline">Delete</button></form>
              <?php if ($o): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="_action" value="line_restore"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="line_key" value="<?= h($k) ?>"><button class="btn btn-xs" title="Back to the computed value">Undo</button></form><?php endif; ?>
            <?php endif; ?>
          </td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <?php if ($removed): ?>
        <div class="fp-removed"><strong>Deleted items</strong>
          <?php foreach ($removed as $o): ?>
            <div class="fp-removed-row"><span><?= h($o['original_description']) ?> <span class="muted">(<?= money($o['original_amount']) ?>)</span></span>
              <?php if ($editable): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="_action" value="line_restore"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="line_key" value="<?= h($o['line_key']) ?>"><button class="btn btn-xs">Restore</button></form><?php endif; ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php if ($editable): ?>
    <div class="modal" id="fpEdit"><div class="modal-box"><h2>Edit item</h2>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="line_edit"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="line_id"><input type="hidden" name="line_key">
        <div class="form-grid">
          <div class="field wide"><label>Particulars</label><input name="description" maxlength="255" required></div>
          <div class="field"><label>Qty</label><input type="number" step="0.01" min="0" name="quantity"></div>
          <div class="field"><label>Amount</label><input type="number" step="0.01" name="amount" required></div>
        </div>
        <p class="small muted">The gross pay, the deductions and the net pay follow the new amount. The tax is not computed again from an edited earning: to add a taxable earning, use Add item.</p>
        <div class="form-actions"><button class="btn btn-primary">Save item</button><button type="button" class="btn" data-modal-close>Cancel</button></div></form></div></div>
    <div class="modal" id="fpAdd"><div class="modal-box"><h2>Add item</h2>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="line_add"><input type="hidden" name="id" value="<?= $id ?>">
        <div class="form-grid">
          <div class="field"><label>Type</label><select name="adj_type"><?= options(['earning' => 'Earning (added to the pay)', 'deduction' => 'Deduction (taken from the pay)'], 'earning') ?></select></div>
          <div class="field"><label class="check" style="margin-top:26px"><input type="checkbox" name="taxable" value="1" checked> Taxable earning</label></div>
          <div class="field wide"><label>Particulars</label><input name="description" maxlength="255" required placeholder="e.g. Unpaid commission, Uniform not returned"></div>
          <div class="field"><label>Amount</label><input type="number" step="0.01" min="0.01" name="amount" required></div>
        </div>
        <p class="small muted">The final pay is computed again with the item (taxable earnings enter the year-end tax annualization).</p>
        <div class="form-actions"><button class="btn btn-primary">Add item</button><button type="button" class="btn" data-modal-close>Cancel</button></div></form></div></div>
    <?php endif; ?>
    <?php if (!in_array($fp['status'], ['released', 'cancelled'], true) && can('final_pay.generate') && final_pay_cancel_supported()):
        $empNow = row('SELECT status FROM employees WHERE id = ?', [$fp['employee_id']]); ?>
    <div class="modal" id="fpCancel"><div class="modal-box"><h2>Cancel calculation</h2>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="cancel"><input type="hidden" name="id" value="<?= $id ?>">
        <p class="muted small">The final pay of <?= h(full_name($fp, false)) ?> (net <?= peso($fp['net_final_pay']) ?>) is voided: it will not be released or paid. The record is kept as Cancelled with the reason, and the transactions, benefits and loan schedules it included go back to the next payroll.</p>
        <div class="form-grid">
          <div class="field wide"><label>Reason</label><input name="cancel_reason" maxlength="255" required placeholder="e.g. Resignation withdrawn, computed by mistake"></div>
          <div class="field wide"><label class="check"><input type="checkbox" name="reinstate" value="1"<?= $empNow['status'] === 'separated' ? '' : ' checked' ?>> The separation is cancelled too: the employee stays / becomes active</label>
            <small class="help"><?= $empNow['status'] === 'separated' ? 'The employee is separated on the 201 file: ticking this reinstates him / her (same as Reinstate on the employee record).' : 'The employee is still active on the 201 file: ticking this also clears the separation date written by the computation.' ?></small></div>
        </div>
        <div class="form-actions"><button class="btn btn-danger">Cancel calculation</button><button type="button" class="btn" data-modal-close>Keep it</button></div></form></div></div>
    <?php endif; ?>
    <?php
    page_footer();
    exit;
}
$list = rows('SELECT fp.*, e.first_name, e.last_name, e.middle_name, e.suffix, e.employee_no FROM final_pays fp JOIN employees e ON e.id = fp.employee_id WHERE 1=1' . user_scope_sql('e') . ' ORDER BY fp.created_at DESC');
$pendingSep = rows("SELECT e.* FROM employees e WHERE e.status = 'separated' AND e.id NOT IN (SELECT employee_id FROM final_pays WHERE status <> 'cancelled')" . user_scope_sql('e') . ' ORDER BY e.date_separated DESC');
$pre = get('employee_id') ? row('SELECT * FROM employees WHERE id = ?', [(int)get('employee_id')]) : null;
page_header('Final Pay', ['subtitle' => 'Consolidates last salary, pro-rated 13th month, unused leave conversion and loan / accountability offsets — to be released within ' . (int)setting('final_pay_days', 30) . ' days from separation.']);
?>
<div class="grid grid-2">
  <div class="card"><h2>Compute final pay</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="compute">
      <div class="form-grid">
        <div class="field wide"><label>Employee</label><select name="employee_id" required><?= options(employee_options(false), $pre['id'] ?? null, '— select —') ?></select></div>
        <div class="field"><label>Separation date (last day)</label><input type="date" name="separation_date" value="<?= h($pre['date_separated'] ?? today()) ?>" required></div>
      </div>
      <div class="form-actions"><button class="btn btn-primary">Compute</button></div>
    </form>
  </div>
  <div class="card"><h2>Separated without final pay</h2>
    <?php if (!$pendingSep): ?><p class="muted">None.</p><?php else: ?><ul><?php foreach ($pendingSep as $e): ?><li><a href="<?= url('final_pay', ['employee_id' => $e['id']]) ?>"><?= h(full_name($e)) ?></a> — separated <?= h(fmt_date($e['date_separated'])) ?></li><?php endforeach; ?></ul><?php endif; ?>
  </div>
</div>
<div class="card"><table class="table"><thead><tr><th>Employee</th><th>Separation</th><th>Due</th><th class="num">Net final pay</th><th>Status</th><th>Released</th><th></th></tr></thead><tbody>
<?php if (!$list) echo empty_row(7); foreach ($list as $f): ?>
  <tr class="<?= $f['status'] === 'cancelled' ? 'row-muted' : (!in_array($f['status'], ['released', 'cancelled'], true) && today() > $f['due_date'] ? 'row-bad' : '') ?>"><td><?= h(full_name($f)) ?></td><td><?= h(fmt_date($f['separation_date'])) ?></td><td><?= h(fmt_date($f['due_date'])) ?></td><td class="num"><?= money($f['net_final_pay']) ?></td>
    <td><?= badge($f['status']) ?></td><td><?= h(fmt_date($f['release_date'])) ?></td><td class="actions"><a class="btn btn-sm btn-primary" href="<?= url('final_pay', ['id' => $f['id']]) ?>">Open</a></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php page_footer();
