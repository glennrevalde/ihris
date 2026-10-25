<?php
/**
 * Retroactive pay adjustment: when a salary increase / promotion / wage order is approved with a past
 * effectivity date, recompute the differential for every finalized payroll period already paid at the
 * old rate and queue it as a taxable earning for the next payroll run.
 */
function retro_compute(int $empId, string $effectiveDate, float $oldRate, float $newRate, string $rateType, ?int $historyId = null): ?array
{
    if ($oldRate <= 0 || abs($newRate - $oldRate) < 0.005) {
        return null;
    }
    $factor = $newRate / $oldRate - 1;
    $items = rows("SELECT pi.*, pr.period_start, pr.period_end, pr.run_no
                   FROM payroll_items pi JOIN payroll_runs pr ON pr.id = pi.run_id
                   WHERE pi.employee_id = ? AND pr.status = 'finalized' AND pr.run_type IN ('regular','final_pay') AND pr.period_end >= ?
                   ORDER BY pr.period_start", [$empId, $effectiveDate]);
    $amount = 0.0;
    $parts = [];
    foreach ($items as $it) {
        $paidRate = $rateType === 'daily' ? (float)$it['daily_rate'] : (float)$it['monthly_rate'];
        if (abs($paidRate - $oldRate) > 0.01) {
            continue; // already paid at a different (e.g. new) rate
        }
        $sensitive = (float)$it['basic_pay'] - (float)$it['absence_deduction'] - (float)$it['late_deduction'] - (float)$it['undertime_deduction']
            + (float)$it['holiday_pay'] + (float)$it['premium_pay'] + (float)$it['ot_pay'] + (float)$it['nd_pay'];
        $start = max($it['period_start'], $effectiveDate);
        $fraction = (days_between($start, $it['period_end']) + 1) / (days_between($it['period_start'], $it['period_end']) + 1);
        $diff = round($sensitive * $fraction * $factor, 2);
        if ($diff != 0) {
            $amount += $diff;
            $parts[] = sprintf('%s (%s–%s): %s × %.4f × %.4f = %s', $it['run_no'], $it['period_start'], $it['period_end'], number_format($sensitive, 2), $fraction, $factor, number_format($diff, 2));
        }
    }
    $amount = round($amount, 2);
    if (abs($amount) < 0.01) {
        return null;
    }
    $emp = row('SELECT first_name, last_name FROM employees WHERE id = ?', [$empId]);
    $id = insert('retro_adjustments', [
        'employee_id' => $empId, 'history_id' => $historyId, 'effective_date' => $effectiveDate, 'old_rate' => $oldRate, 'new_rate' => $newRate,
        'rate_type' => $rateType, 'amount' => $amount, 'breakdown' => implode("\n", $parts),
    ]);
    $pe = insert('pending_earnings', [
        'employee_id' => $empId, 'source' => 'retro', 'source_id' => $id,
        'description' => 'Retroactive pay differential effective ' . fmt_date($effectiveDate), 'amount' => $amount, 'tax_treatment' => 'taxable', 'created_by' => user_id(),
    ]);
    update('retro_adjustments', ['pending_earning_id' => $pe], 'id = ?', [$id]);
    audit('payroll', 'retro_compute', 'retro_adjustments', $id, ['employee' => $emp['last_name'] ?? $empId, 'amount' => $amount]);
    return ['id' => $id, 'amount' => $amount, 'periods' => count($parts)];
}
