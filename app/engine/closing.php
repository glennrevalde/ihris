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
        update('period_closings', ['runs' => count($byRun), 'ledger_entries' => $sum['ledger'], 'period_locked' => $locked, 'log' => implode("\n", $log)], 'id = ?', [$id]);
        return $id;
    });
    audit('payroll', 'period_close', 'period_closings', $id, ['period' => $c['period_start'] . ' - ' . $c['period_end'], 'pay_code' => $c['pay_group_code'], 'filters' => array_filter($f), 'log' => $log]);
    return $id;
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
