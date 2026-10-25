<?php
/**
 * Statutory computations driven entirely by the editable reference tables:
 *  - BIR withholding tax (daily / weekly / semi-monthly / monthly / annual)
 *  - SSS (RA 11199) including EC and MPF
 *  - PhilHealth (RA 11223)
 *  - Pag-IBIG (RA 9679)
 *  - Premium / overtime multipliers
 */

function tax_brackets_for(string $frequency, ?string $asOf = null): array
{
    static $cache = [];
    $asOf = $asOf ?: today();
    $key = $frequency . '|' . $asOf;
    if (!isset($cache[$key])) {
        $eff = val('SELECT MAX(effective_from) FROM tax_brackets WHERE frequency = ? AND effective_from <= ?', [$frequency, $asOf]);
        $cache[$key] = $eff ? rows('SELECT * FROM tax_brackets WHERE frequency = ? AND effective_from = ? ORDER BY comp_from', [$frequency, $eff]) : [];
    }
    return $cache[$key];
}

/** Withholding tax on compensation for one payroll period. */
function compute_withholding_tax(float $taxable, string $frequency, ?string $asOf = null): float
{
    if ($taxable <= 0) {
        return 0.0;
    }
    $brackets = tax_brackets_for($frequency, $asOf);
    $use = null;
    foreach ($brackets as $b) {
        if ($taxable >= (float)$b['comp_from']) {
            $use = $b;
        }
    }
    if (!$use) {
        return 0.0;
    }
    $tax = (float)$use['fixed_tax'] + ($taxable - (float)$use['comp_from']) * (float)$use['rate_pct'] / 100;
    return max(0, r2($tax));
}

function compute_annual_tax(float $annualTaxable, ?string $asOf = null): float
{
    return compute_withholding_tax($annualTaxable, 'annual', $asOf);
}

function sss_contribution(float $monthlyComp, ?string $asOf = null): array
{
    $asOf = $asOf ?: today();
    $eff = val('SELECT MAX(effective_from) FROM sss_table WHERE effective_from <= ?', [$asOf]);
    $zero = ['msc' => 0, 'ee' => 0, 'er' => 0, 'ec' => 0, 'ee_mpf' => 0, 'er_mpf' => 0, 'total' => 0];
    if (!$eff || $monthlyComp <= 0) {
        return $zero;
    }
    $r = row('SELECT * FROM sss_table WHERE effective_from = ? AND range_from <= ? AND (range_to IS NULL OR range_to >= ?) ORDER BY range_from DESC LIMIT 1',
        [$eff, $monthlyComp, $monthlyComp]);
    if (!$r) {
        $r = row('SELECT * FROM sss_table WHERE effective_from = ? AND range_from <= ? ORDER BY range_from DESC LIMIT 1', [$eff, $monthlyComp]);
    }
    if (!$r) {
        return $zero;
    }
    $ee = (float)$r['ee_regular'] + (float)$r['ee_mpf'];
    $er = (float)$r['er_regular'] + (float)$r['er_mpf'];
    return [
        'msc' => (float)$r['msc_regular'] + (float)$r['msc_mpf'],
        'ee' => $ee, 'er' => $er, 'ec' => (float)$r['ec'],
        'ee_mpf' => (float)$r['ee_mpf'], 'er_mpf' => (float)$r['er_mpf'],
        'total' => $ee + $er + (float)$r['ec'],
    ];
}

function philhealth_contribution(float $monthlyBasic, ?string $asOf = null): array
{
    $asOf = $asOf ?: today();
    $p = row('SELECT * FROM philhealth_params WHERE effective_from <= ? ORDER BY effective_from DESC LIMIT 1', [$asOf]);
    if (!$p || $monthlyBasic <= 0) {
        return ['base' => 0, 'premium' => 0, 'ee' => 0, 'er' => 0];
    }
    $base = min(max($monthlyBasic, (float)$p['floor_salary']), (float)$p['ceiling_salary']);
    $premium = round($base * (float)$p['rate_pct'] / 100, 2);
    $ee = round($premium * (float)$p['ee_share_pct'] / 100, 2);
    return ['base' => $base, 'premium' => $premium, 'ee' => $ee, 'er' => round($premium - $ee, 2)];
}

function pagibig_contribution(float $monthlyComp, ?string $asOf = null, float $voluntary = 0): array
{
    $asOf = $asOf ?: today();
    $eff = val('SELECT MAX(effective_from) FROM pagibig_table WHERE effective_from <= ?', [$asOf]);
    if (!$eff || $monthlyComp <= 0) {
        return ['base' => 0, 'ee' => 0, 'er' => 0, 'voluntary' => 0];
    }
    $r = row('SELECT * FROM pagibig_table WHERE effective_from = ? AND range_from <= ? AND (range_to IS NULL OR range_to >= ?) ORDER BY range_from DESC LIMIT 1',
        [$eff, $monthlyComp, $monthlyComp]);
    if (!$r) {
        return ['base' => 0, 'ee' => 0, 'er' => 0, 'voluntary' => 0];
    }
    $base = min($monthlyComp, (float)$r['max_comp']);
    $vol = setting('pagibig_voluntary_allowed', '1') === '1' ? max(0, $voluntary) : 0;
    return [
        'base' => $base,
        'ee' => round($base * (float)$r['ee_rate_pct'] / 100, 2),
        'er' => round($base * (float)$r['er_rate_pct'] / 100, 2),
        'voluntary' => round($vol, 2),
    ];
}

function premium_rates(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (rows('SELECT * FROM premium_rates') as $r) {
            $cache[$r['day_type']] = ['work' => (float)$r['work_multiplier'], 'ot' => (float)$r['ot_multiplier'], 'label' => $r['label']];
        }
        $cache += ['ordinary' => ['work' => 1.0, 'ot' => 1.25, 'label' => 'Ordinary day']];
    }
    return $cache;
}

/** Late / undertime minutes to deduct, applying grace period and bracket table if configured. */
function deductible_late_minutes(int $minutes, bool $isUndertime = false, ?int $graceOverride = null): int
{
    if ($minutes <= 0) {
        return 0;
    }
    if (!$isUndertime) {
        $grace = $graceOverride ?? (int)setting('grace_minutes', 0);
        if ($minutes <= $grace) {
            return 0;
        }
        if (setting('late_grace_mode', 'full') === 'excess') {
            $minutes -= $grace;
        }
    }
    if (setting('late_deduction_mode', 'per_minute') === 'bracket') {
        static $br = null;
        if ($br === null) {
            $br = rows('SELECT * FROM late_brackets ORDER BY from_minutes');
        }
        foreach ($br as $b) {
            if ($minutes >= (int)$b['from_minutes'] && $minutes <= (int)$b['to_minutes']) {
                return (int)$b['deduct_minutes'];
            }
        }
    }
    return $minutes;
}

/** Periods per month by pay frequency (used to spread monthly amounts). */
function periods_per_month(string $frequency, int $daysPerYear = 261): float
{
    switch ($frequency) {
        case 'monthly':
            return 1;
        case 'semi_monthly':
            return 2;
        case 'weekly':
            return 52 / 12;
        default:
            return $daysPerYear / 12;
    }
}

function frequency_days(string $frequency): int
{
    return ['daily' => 1, 'weekly' => 7, 'semi_monthly' => 15, 'monthly' => 31][$frequency] ?? 15;
}

/** Comp. rates (Payroll Parameters): code => ['day' => % of hourly rate, 'night' => night premium %]. */
function pay_rates(bool $reset = false): array
{
    static $cache = null;
    if ($reset) {
        $cache = null;
    }
    if ($cache === null) {
        $cache = [];
        try {
            foreach (rows('SELECT * FROM pay_rates ORDER BY sort_order') as $r) {
                $cache[$r['code']] = ['day' => (float)$r['day_pct'], 'night' => (float)$r['night_pct'], 'label' => $r['label']];
            }
        } catch (PDOException $e) {
        }
        $defaults = ['reg' => [100, 10], 'reg_ot' => [125, 12.5], 'rd' => [130, 13], 'rd_ot' => [169, 16.9], 'lh' => [200, 20], 'lh_ot' => [260, 26],
            'lh_rd' => [260, 26], 'lh_rd_ot' => [338, 33.8], 'sh' => [130, 13], 'sh_ot' => [169, 16.9], 'sh_rd' => [150, 15], 'sh_rd_ot' => [195, 19.5]];
        foreach ($defaults as $k => $v) {
            $cache[$k] = $cache[$k] ?? ['day' => $v[0], 'night' => $v[1], 'label' => $k];
        }
    }
    return $cache;
}

/** Periods per month of a withholding-tax table. */
function tax_table_ppm(string $table): float
{
    return ['monthly' => 1, 'semi_monthly' => 2, 'weekly' => 52 / 12, 'daily' => 261 / 12, 'annual' => 1 / 12][$table] ?? 2;
}
