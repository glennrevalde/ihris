<?php
/**
 * Period Closing / Payroll Closing (M.A.D.A.S. "Pay Close"), the last step of the payroll cycle.
 *
 * Parameters: Pay Code and Period to Close, plus Company / Branch, Employee Group and Employee Type.
 * Closing posts the finalized payroll of the period: salary payments to the subsidiary ledgers
 * (loans, advances, payables), deductions and transactions marked taken, installments of the
 * transactions with terms, queued earnings and retroactive pay, the 13th month accrual and payment,
 * offset leave credits, and records the posted totals (gross, government contributions, tax, net).
 * When everything of the period is posted, the period is locked (paid) and leaves the attendance screens.
 *
 * Runs of the period: the regular run of the cutoff and every other finalized, not yet posted run of the
 * pay code paid on or before the period's pay date (special, 13th month, adjustments), so nothing is left
 * behind. A run still in draft or computed blocks the closing.
 *
 * v29: only the Super Admin can re-open a closed period (closing_reopen()): every posting of the closing is
 * reversed, the period is unlocked and the closing keeps its record, marked re-opened, with who, when and why.
 */

/** Pay date that ends the window of a period (its pay date, or the period end). */
function closing_window_end(array $c): string
{
    return $c['pay_date'] ?: $c['period_end'];
}

/** Runs concerned by the closing of a period: [finalized runs, runs blocking the closing]. */
function closing_runs(array $c): array
{
    $end = closing_window_end($c);
    $runs = rows("SELECT r.*, (SELECT COUNT(*) FROM payroll_items pi WHERE pi.run_id = r.id) AS items,
                         (SELECT COUNT(*) FROM payroll_items pi WHERE pi.run_id = r.id AND pi.posted_at IS NULL) AS unposted
                  FROM payroll_runs r WHERE r.pay_group_id = ? AND r.status IN ('draft','computed','finalized')
                    AND (r.cutoff_id = ? OR (r.run_type <> 'regular' AND r.pay_date <= ?) OR (r.cutoff_id IS NULL AND r.run_type = 'regular' AND r.period_end <= ?))
                    AND (r.status <> 'finalized' OR r.posted_at IS NULL OR r.cutoff_id = ?)
                  ORDER BY r.pay_date, r.id", [$c['pay_group_id'], $c['id'], $end, $c['period_end'], $c['id']]);
    $ready = array_values(array_filter($runs, fn($r) => $r['status'] === 'finalized'));
    $blocking = array_values(array_filter($runs, fn($r) => $r['status'] !== 'finalized' && ((int)$r['cutoff_id'] === (int)$c['id'] || $r['run_type'] !== 'final_pay')));
    return [$ready, $blocking];
}

/** SQL filter of payroll items by the closing parameters (alias pi, employees e). */
function closing_item_filter(array $f): array
{
    $w = [];
    $p = [];
    if (!empty($f['branch_id'])) {
        $w[] = '(pi.default_branch_id = ? OR e.default_branch_id = ?)';
        $p[] = (int)$f['branch_id'];
        $p[] = (int)$f['branch_id'];
    }
    if (!empty($f['employee_group_id'])) {
        $w[] = 'e.employee_group_id = ?';
        $p[] = (int)$f['employee_group_id'];
    }
    if (!empty($f['employee_type_id'])) {
        $w[] = 'e.employee_type_id = ?';
        $p[] = (int)$f['employee_type_id'];
    }
    return [$w ? ' AND ' . implode(' AND ', $w) : '', $p];
}

/** Items of the finalized runs still to post, with the closing filters. */
function closing_items(array $runIds, array $f): array
{
    if (!$runIds) {
        return [];
    }
    [$w, $p] = closing_item_filter($f);
    return rows('SELECT pi.* FROM payroll_items pi JOIN employees e ON e.id = pi.employee_id WHERE pi.run_id IN (' . in_list($runIds) . ') AND pi.posted_at IS NULL' . $w,
        array_merge(array_values($runIds), $p));
}

/** Preview of a closing: what would be posted. */
function closing_preview(array $c, array $f): array
{
    [$ready, $blocking] = closing_runs($c);
    $items = closing_items(array_map(fn($r) => (int)$r['id'], $ready), $f);
    $t = ['employees' => count(array_unique(array_column($items, 'employee_id'))), 'items' => count($items), 'gross' => 0, 'deductions' => 0, 'net' => 0,
        'sss_ee' => 0, 'sss_er' => 0, 'phic_ee' => 0, 'phic_er' => 0, 'hdmf_ee' => 0, 'hdmf_er' => 0, 'tax' => 0];
    foreach ($items as $it) {
        $t['gross'] += (float)$it['gross_pay'];
        $t['deductions'] += (float)$it['total_deductions'];
        $t['net'] += (float)$it['net_pay'];
        $t['sss_ee'] += (float)$it['sss_ee'];
        $t['sss_er'] += (float)$it['sss_er'] + (float)$it['sss_ec'];
        $t['phic_ee'] += (float)$it['phic_ee'];
        $t['phic_er'] += (float)$it['phic_er'];
        $t['hdmf_ee'] += (float)$it['hdmf_ee'];
        $t['hdmf_er'] += (float)$it['hdmf_er'];
        $t['tax'] += (float)$it['withholding_tax'];
    }
    $loans = $items ? (int)val("SELECT COUNT(*) FROM payroll_item_lines WHERE item_id IN (" . in_list(array_column($items, 'id')) . ") AND line_type = 'deduction' AND ref_type IN ('ledger','term_trans')", array_column($items, 'id')) : 0;
    return ['ready' => $ready, 'blocking' => $blocking, 'items' => $items, 'totals' => array_map(fn($v) => is_float($v) ? round($v, 2) : $v, $t), 'loan_lines' => $loans];
}

/**
 * Close a period: post every item of the selection and record the closing. Throws RuntimeException when
 * the closing is not possible (backup not confirmed, a run not finalized, nothing to post).
 * Returns the period_closings id.
 */
function closing_execute(int $cutoffId, array $f, bool $backupConfirmed): int
{
    $c = pt_period($cutoffId);
    if (!$c) {
        throw new RuntimeException('Select the period to close.');
    }
    if (!$backupConfirmed) {
        throw new RuntimeException('Confirm that the database backup was done before closing the period.');
    }
    $pv = closing_preview($c, $f);
    if ($pv['blocking']) {
        throw new RuntimeException('Finalize or cancel these payroll runs first: ' . implode(', ', array_map(fn($r) => $r['run_no'] . ' (' . $r['status'] . ')', $pv['blocking'])) . '.');
    }
    if (!$pv['items']) {
        throw new RuntimeException($pv['ready'] ? 'Everything of this selection is already posted.' : 'No finalized payroll run of this period to close. Process and finalize the payroll first.');
    }
    $log = [];
    $id = tx(function () use ($c, $f, $pv, &$log) {
        $no = next_number('PC', 'period_closings', 'closing_no');
        $tot = $pv['totals'];
        unset($tot['items']);
        $id = insert('period_closings', ['closing_no' => $no, 'pay_group_id' => $c['pay_group_id'], 'cutoff_id' => $c['id'], 'period_start' => $c['period_start'], 'period_end' => $c['period_end'],
            'branch_id' => ($f['branch_id'] ?? null) ?: null, 'employee_group_id' => ($f['employee_group_id'] ?? null) ?: null, 'employee_type_id' => ($f['employee_type_id'] ?? null) ?: null,
            'backup_confirmed' => 1, 'closed_by' => user_id(), 'closed_at' => now(), 'runs' => 0] + $tot);
        $byRun = [];
        foreach ($pv['items'] as $it) {
            $byRun[(int)$it['run_id']][] = (int)$it['id'];
        }
        $sum = ['items' => 0, 'ledger' => 0, 'deductions' => 0, 'transactions' => 0, 'installments' => 0, 'earnings' => 0, 'accruals' => 0, 'leave' => 0];
        foreach ($pv['ready'] as $r) {
            if (empty($byRun[(int)$r['id']])) {
                continue;
            }
            $n = payroll_post_items((int)$r['id'], $byRun[(int)$r['id']], $id);
            foreach ($n as $k => $v) {
                $sum[$k] += $v;
            }
            $log[] = sprintf('%s: %d employee(s) posted', $r['run_no'], $n['items']);
        }
        $log[] = sprintf('Salaries and government deductions posted: gross %s, SSS %s, PhilHealth %s, Pag-IBIG %s, tax %s, net %s',
            number_format($pv['totals']['gross'], 2), number_format($pv['totals']['sss_ee'] + $pv['totals']['sss_er'], 2), number_format($pv['totals']['phic_ee'] + $pv['totals']['phic_er'], 2),
            number_format($pv['totals']['hdmf_ee'] + $pv['totals']['hdmf_er'], 2), number_format($pv['totals']['tax'], 2), number_format($pv['totals']['net'], 2));
        $log[] = sprintf('Employee ledgers updated: %d ledger entr(ies), %d installment(s) of transactions with terms, %d 13th month accrual(s)', $sum['ledger'], $sum['installments'], $sum['accruals']);
        $log[] = sprintf('Payments: %d one-time deduction(s) completed, %d transaction(s) and %d queued earning(s) included', $sum['deductions'], $sum['transactions'], $sum['earnings']);
        if ($sum['leave']) {
            $log[] = sprintf('Leaves: %d offset leave credit(s)', $sum['leave']);
        }
        // the period is paid when nothing of it is left to post
        [$ready] = closing_runs($c);
        $left = (int)array_sum(array_map(fn($r) => (int)val('SELECT COUNT(*) FROM payroll_items WHERE run_id = ? AND posted_at IS NULL', [$r['id']]), $ready));
        $locked = 0;
        $before = (string)val('SELECT status FROM attendance_cutoffs WHERE id = ?', [$c['id']]);
        $hasRegular = (bool)array_filter($ready, fn($r) => (int)$r['cutoff_id'] === (int)$c['id'] && $r['run_type'] === 'regular');
        if ($left === 0 && $hasRegular) {
            q("UPDATE attendance_cutoffs SET status = 'locked' WHERE id = ?", [$c['id']]);
            q('UPDATE attendance_summaries SET is_closed = 1, closed_at = IFNULL(closed_at, NOW()) WHERE cutoff_id = ?', [$c['id']]);
            $locked = 1;
            $log[] = 'Period locked: it is paid and no longer offered on the attendance screens';
        } else {
            $log[] = $left ? sprintf('%d employee(s) of the period are still to close (other companies / groups / types)', $left)
                : 'The regular payroll of the period is not finalized yet: the period stays open';
        }
        $upd = ['runs' => count($byRun), 'ledger_entries' => $sum['ledger'], 'period_locked' => $locked, 'log' => implode("\n", $log)];
        if (closing_reopen_ready()) {
            $upd['cutoff_status_before'] = $before !== '' && $before !== 'locked' ? $before : 'approved';
        }
        update('period_closings', $upd, 'id = ?', [$id]);
        return $id;
    });
    audit('payroll', 'period_close', 'period_closings', $id, ['period' => $c['period_start'] . ' - ' . $c['period_end'], 'pay_code' => $c['pay_group_code'], 'filters' => array_filter($f), 'log' => $log]);
    return $id;
}

/** v29: the re-opening columns of period_closings exist (database version 22). */
function closing_reopen_ready(): bool
{
    static $ok = [];
    $db = active_db_name();
    return $ok[$db] ??= mig_column_exists('period_closings', 'reopened_at');
}

/** v29: may the signed-in user re-open closed periods? Only the Super Admin. */
function closing_can_reopen(): bool
{
    return closing_reopen_ready() && is_super_user();
}

/**
 * v29: why a closing cannot be re-opened (null = it can): already re-opened, or a later period of the same pay code
 * is still closed (periods are re-opened from the latest back, so the ledgers and balances unwind in order).
 */
function closing_reopen_block(array $pc): ?string
{
    if (($pc['status'] ?? 'closed') === 'reopened') {
        return 'This closing was already re-opened.';
    }
    $later = rows("SELECT closing_no FROM period_closings WHERE pay_group_id = ? AND id <> ? AND status = 'closed' AND period_end > ? ORDER BY period_end DESC, id DESC",
        [(int)$pc['pay_group_id'], (int)$pc['id'], $pc['period_end']]);
    if ($later) {
        return 'A later period of this pay code is closed: re-open ' . implode(', ', array_column($later, 'closing_no')) . ' first.';
    }
    return null;
}

/** v29: recompute a ledger after payroll entries were taken out: running balances, balance, paid status. */
function closing_ledger_recalc(int $ledgerId): void
{
    $l = row('SELECT * FROM ledgers WHERE id = ?', [$ledgerId]);
    if (!$l) {
        return;
    }
    $bal = 0.0;
    foreach (rows('SELECT id, increase, decrease, balance FROM ledger_entries WHERE ledger_id = ? ORDER BY id', [$ledgerId]) as $e) {
        $bal = round($bal + (float)$e['increase'] - (float)$e['decrease'], 2);
        if (abs($bal - (float)$e['balance']) > 0.004) {
            q('UPDATE ledger_entries SET balance = ? WHERE id = ?', [$bal, $e['id']]);
        }
    }
    $upd = ['balance' => $bal];
    if ($l['ledger_type'] !== 'accrued_benefit' && $l['status'] === 'paid' && $bal > 0.004) {
        // the payment that settled it is undone: the loan is running again, and so is its payroll deduction
        $upd['status'] = 'active';
        $upd['full_payment_date'] = null;
        q("UPDATE employee_deductions SET status = 'active' WHERE ledger_id = ? AND status = 'completed'", [$ledgerId]);
    }
    update('ledgers', $upd, 'id = ?', [$ledgerId]);
}

/**
 * v29 Re-open a closed period (Super Admin only). Every posting of the closing is reversed, item by item:
 *  - the ledger entries of its payroll items (loan and advance payments, 13th month paid and accrued, leave
 *    conversions paid) are removed and the ledgers recomputed (a loan the payroll had settled is running again);
 *  - one-time deductions completed by it are active again; transactions, queued earnings and retroactive pay
 *    included by it are pending again; installments of transactions with terms are pending again (with the
 *    shortfall installment it added taken out); offset leave credited from overtime is taken back;
 *  - the payroll items and runs are unposted: the runs are finalized again, waiting for Period Closing, or, when
 *    $toComputed, back to Computed so they can be corrected and recomputed;
 *  - the period is unlocked (back to the status it had before the closing) and appears again in Period to Close.
 * The closing keeps its record, marked re-opened, with who re-opened it, when, why and what was reversed.
 * Returns the reversal log.
 */
function closing_reopen(int $closingId, string $reason, bool $backupConfirmed, bool $toComputed = false): array
{
    if (!closing_reopen_ready()) {
        throw new RuntimeException('The database is not upgraded yet (version 22 is needed to re-open a closed period).');
    }
    if (!is_super_user()) {
        throw new RuntimeException('Only the Super Admin can re-open a closed period.');
    }
    $pc = row('SELECT pc.*, pg.code AS pay_group_code FROM period_closings pc JOIN pay_groups pg ON pg.id = pc.pay_group_id WHERE pc.id = ?', [$closingId]);
    if (!$pc) {
        throw new RuntimeException('Period closing not found.');
    }
    if ($why = closing_reopen_block($pc)) {
        throw new RuntimeException($why);
    }
    $reason = trim($reason);
    if (mb_strlen($reason) < 5) {
        throw new RuntimeException('Give the reason for re-opening this period (at least 5 characters).');
    }
    if (!$backupConfirmed) {
        throw new RuntimeException('Confirm that the database backup was done before re-opening the period.');
    }
    $log = [];
    tx(function () use ($pc, $toComputed, $reason, &$log) {
        $items = rows('SELECT pi.*, r.run_no, r.run_type, r.period_end AS run_period_end FROM payroll_items pi JOIN payroll_runs r ON r.id = pi.run_id WHERE pi.closing_id = ? ORDER BY pi.run_id, pi.id', [(int)$pc['id']]);
        $n = ['items' => 0, 'ledger' => 0, 'deductions' => 0, 'transactions' => 0, 'installments' => 0, 'earnings' => 0, 'leave' => 0];
        $ledgers = [];
        $terms = [];
        $runs = [];
        foreach ($items as $it) {
            $runId = (int)$it['run_id'];
            $runs[$runId] = $it['run_no'];
            foreach (rows('SELECT * FROM payroll_item_lines WHERE item_id = ?', [$it['id']]) as $l) {
                if ($l['line_type'] === 'deduction' && $l['ref_type'] === 'employee_deduction') {
                    $cat = val('SELECT dt.category FROM employee_deductions ed JOIN deduction_types dt ON dt.id = ed.deduction_type_id WHERE ed.id = ?', [$l['ref_id']]);
                    if ($cat === 'adhoc') {
                        $n['deductions'] += q("UPDATE employee_deductions SET status = 'active' WHERE id = ? AND status = 'completed'", [$l['ref_id']])->rowCount();
                    }
                } elseif ($l['ref_type'] === 'employee_transaction' && $l['code'] !== 'WTAX_X') {
                    $n['transactions'] += q("UPDATE employee_transactions SET status = 'pending', payroll_run_id = NULL WHERE id = ? AND status = 'included' AND payroll_run_id = ?", [$l['ref_id'], $runId])->rowCount();
                } elseif ($l['ref_type'] === 'term_trans' && $l['line_type'] !== 'info') {
                    $sc = row('SELECT * FROM employee_term_schedule WHERE id = ?', [$l['ref_id']]);
                    if ($sc && $sc['status'] === 'posted' && (int)$sc['payroll_run_id'] === $runId) {
                        $amount = (float)$sc['amount'];
                        // the shortfall installment added when the run took less than the installment
                        $short = row("SELECT * FROM employee_term_schedule WHERE term_id = ? AND status = 'pending' AND reference = ? ORDER BY id DESC LIMIT 1",
                            [$sc['term_id'], mb_substr('Shortfall of installment ' . $sc['seq_no'] . ' (' . $it['run_no'] . ')', 0, 80)]);
                        if ($short) {
                            $amount += (float)$short['amount'];
                            q('DELETE FROM employee_term_schedule WHERE id = ?', [$short['id']]);
                            q('UPDATE employee_term_trans SET terms = GREATEST(terms - 1, 0) WHERE id = ?', [$sc['term_id']]);
                        }
                        update('employee_term_schedule', ['status' => 'pending', 'payroll_run_id' => null, 'posted_at' => null, 'reference' => null, 'amount' => round($amount, 2)], 'id = ?', [$sc['id']]);
                        $terms[(int)$sc['term_id']] = true;
                        $n['installments']++;
                    }
                } elseif ($l['line_type'] === 'earning' && $l['ref_type'] === 'pending_earning') {
                    $pe = row('SELECT * FROM pending_earnings WHERE id = ?', [$l['ref_id']]);
                    if ($pe && $pe['status'] === 'included' && (int)$pe['payroll_run_id'] === $runId) {
                        update('pending_earnings', ['status' => 'pending', 'payroll_run_id' => null], 'id = ?', [$pe['id']]);
                        if ($pe['source'] === 'retro') {
                            q("UPDATE retro_adjustments SET status = 'pending' WHERE id = ? AND status = 'included'", [$pe['source_id']]);
                        }
                        $n['earnings']++;
                    }
                } elseif ($l['code'] === 'OT_OFFSET' && (float)$l['quantity'] > 0 && ($olt = offset_leave_type_id())) {
                    $hpd = employee_hours_per_day(att_employee((int)$it['employee_id']) ?: []);
                    $bal = leave_balance_row((int)$it['employee_id'], $olt, (int)substr((string)$it['run_period_end'], 0, 4));
                    q('UPDATE leave_balances SET adjustment = adjustment - ? WHERE id = ?', [round((float)$l['quantity'] / $hpd, 2), $bal['id']]);
                    $n['leave']++;
                }
            }
            // the ledger entries this run posted for the employee (payments, 13th month paid and accrued, conversions)
            foreach (rows('SELECT le.id, le.ledger_id FROM ledger_entries le JOIN ledgers l ON l.id = le.ledger_id WHERE le.payroll_run_id = ? AND l.employee_id = ?', [$runId, (int)$it['employee_id']]) as $le) {
                q('DELETE FROM ledger_entries WHERE id = ?', [$le['id']]);
                $ledgers[(int)$le['ledger_id']] = true;
                $n['ledger']++;
            }
            update('payroll_items', ['posted_at' => null, 'closing_id' => null], 'id = ?', [$it['id']]);
            $n['items']++;
        }
        foreach (array_keys($ledgers) as $lid) {
            closing_ledger_recalc($lid);
        }
        foreach (array_keys($terms) as $tid) {
            term_recalc($tid);
        }
        foreach ($runs as $runId => $runNo) {
            update('payroll_runs', ['posted_at' => null, 'posted_by' => null], 'id = ?', [$runId]);
            $log[] = sprintf('%s: %d employee(s) unposted', $runNo, (int)val('SELECT COUNT(*) FROM payroll_items WHERE run_id = ? AND posted_at IS NULL', [$runId]));
        }
        $log[] = sprintf('Employee ledgers: %d entr(ies) removed, %d ledger(s) recomputed', $n['ledger'], count($ledgers));
        $log[] = sprintf('Pending again: %d one-time deduction(s), %d transaction(s), %d queued earning(s), %d installment(s) of transactions with terms', $n['deductions'], $n['transactions'], $n['earnings'], $n['installments']);
        if ($n['leave']) {
            $log[] = sprintf('Leaves: %d offset leave credit(s) taken back', $n['leave']);
        }
        // the period is no longer fully paid: unlocked, whichever closing of it locked it (the last one of a period
        // closed company by company), back to the status it had before that closing
        if ($pc['cutoff_id'] && (string)val('SELECT status FROM attendance_cutoffs WHERE id = ?', [(int)$pc['cutoff_id']]) === 'locked') {
            $lockedBy = (int)$pc['period_locked'] ? $pc : row('SELECT * FROM period_closings WHERE cutoff_id = ? AND period_locked = 1 ORDER BY id DESC LIMIT 1', [(int)$pc['cutoff_id']]);
            $was = (string)($lockedBy['cutoff_status_before'] ?? '');
            $to = in_array($was, ['open', 'summarized', 'approved'], true) ? $was : 'approved';
            q("UPDATE attendance_cutoffs SET status = ? WHERE id = ? AND status = 'locked'", [$to, (int)$pc['cutoff_id']]);
            $log[] = 'Period unlocked (' . $to . '): it is offered again in Period to Close and on the attendance screens';
        }
        if ($toComputed) {
            foreach ($runs as $runId => $runNo) {
                $posted = (int)val('SELECT COUNT(*) FROM payroll_items WHERE run_id = ? AND posted_at IS NOT NULL', [$runId]);
                $type = (string)val('SELECT run_type FROM payroll_runs WHERE id = ?', [$runId]);
                if ($posted || $type === 'final_pay') {
                    $log[] = sprintf('%s stays finalized: %s', $runNo, $posted ? $posted . ' employee(s) of it are posted by another closing' : 'a final pay run');
                    continue;
                }
                update('payroll_runs', ['status' => 'computed', 'finalized_at' => null, 'finalized_by' => null], 'id = ? AND status = ?', [$runId, 'finalized']);
                $log[] = $runNo . ' back to Computed: correct and recompute it, then finalize it again';
            }
        } else {
            $log[] = 'Payroll run(s) stay finalized, waiting for Period Closing';
        }
        update('period_closings', ['status' => 'reopened', 'reopened_by' => user_id(), 'reopened_at' => now(), 'reopen_reason' => mb_substr($reason, 0, 255),
            'reopen_log' => implode("\n", $log)], 'id = ?', [(int)$pc['id']]);
    });
    audit('payroll', 'period_reopen_closing', 'period_closings', $closingId, ['closing_no' => $pc['closing_no'], 'pay_code' => $pc['pay_group_code'],
        'period' => $pc['period_start'] . ' - ' . $pc['period_end'], 'reason' => $reason, 'to_computed' => $toComputed, 'log' => $log]);
    return $log;
}

/**
 * Database backup of the company (Period Closing > Download backup): a MySQL 5.5 compatible .sql file of
 * every table of the active company database, streamed to the browser.
 */
function closing_stream_backup(): void
{
    $db = active_db_name();
    $name = preg_replace('/[^A-Za-z0-9_]/', '_', $db) . '_backup_' . date('Ymd_His') . '.sql';
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: no-store');
    echo "-- G-SMaRT HRIS backup of database `$db`, " . date('Y-m-d H:i:s') . "\n-- Restore: select the database in phpMyAdmin and import this file.\n";
    echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
    $pdo = db();
    foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $t) {
        $table = $t[0];
        $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '', $table) . '`')->fetch(PDO::FETCH_NUM);
        echo "DROP TABLE IF EXISTS `$table`;\n" . $create[1] . ";\n";
        $offset = 0;
        while (true) {
            $rows = $pdo->query('SELECT * FROM `' . str_replace('`', '', $table) . '` LIMIT ' . $offset . ', 500')->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                break;
            }
            $cols = '`' . implode('`, `', array_keys($rows[0])) . '`';
            $vals = [];
            foreach ($rows as $r) {
                $vals[] = '(' . implode(', ', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $r)) . ')';
            }
            echo "INSERT INTO `$table` ($cols) VALUES\n" . implode(",\n", $vals) . ";\n";
            $offset += 500;
            flush();
        }
        echo "\n";
    }
    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
    audit('payroll', 'database_backup', 'database', $db);
    exit;
}
