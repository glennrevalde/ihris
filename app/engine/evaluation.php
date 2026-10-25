<?php
/**
 * Dual-track performance evaluation:
 *  - Quantitative: weighted KPIs (actual vs target) -> weighted achievement % -> 5-point score
 *  - Qualitative: Likert 1-5 competency ratings from self / supervisor / peer (360) sources
 *  - Overall rating feeds merit increase, promotion eligibility, step progression and
 *    (for consistent under-performance) the disciplinary workflow.
 */

function merit_row(float $score): ?array
{
    return row('SELECT * FROM merit_matrix WHERE min_score <= ? ORDER BY min_score DESC LIMIT 1', [$score]);
}

/** Convert KPI achievement % to the 5-point scale (100% of target = 4.0, 125% = 5.0). */
function quant_to_scale(float $pct): float
{
    return round(max(1, min(5, $pct / 25)), 2);
}

function eval_compute(int $evalId): array
{
    $ev = row('SELECT e.*, c.quant_weight, c.qual_weight, c.self_weight, c.supervisor_weight, c.peer_weight
               FROM evaluations e JOIN evaluation_cycles c ON c.id = e.cycle_id WHERE e.id = ?', [$evalId]);
    // Quantitative
    $kpis = rows('SELECT * FROM evaluation_kpis WHERE evaluation_id = ?', [$evalId]);
    $wsum = 0;
    $acc = 0;
    foreach ($kpis as $k) {
        $ach = null;
        if ($k['actual'] !== null && (float)$k['target'] != 0) {
            if ($k['lower_is_better']) {
                $ach = (float)$k['actual'] <= 0 ? 150 : (float)$k['target'] / (float)$k['actual'] * 100;
            } else {
                $ach = (float)$k['actual'] / (float)$k['target'] * 100;
            }
            $ach = round(max(0, min(150, $ach)), 2);
            $wsum += (float)$k['weight'];
            $acc += (float)$k['weight'] * $ach;
        }
        update('evaluation_kpis', ['achievement_pct' => $ach], 'id = ?', [$k['id']]);
    }
    $quantPct = $wsum > 0 ? round($acc / $wsum, 2) : null;
    $quantScore = $quantPct !== null ? quant_to_scale($quantPct) : null;

    // Qualitative (weighted by rater source; missing sources are re-normalized)
    $src = rows('SELECT rater_type, AVG(rating) AS avg_rating FROM evaluation_ratings WHERE evaluation_id = ? GROUP BY rater_type', [$evalId]);
    $weights = ['self' => (float)$ev['self_weight'], 'supervisor' => (float)$ev['supervisor_weight'], 'peer' => (float)$ev['peer_weight']];
    $qs = 0;
    $qw = 0;
    foreach ($src as $s) {
        $w = $weights[$s['rater_type']] ?? 0;
        $qs += $w * (float)$s['avg_rating'];
        $qw += $w;
    }
    $qualScore = $qw > 0 ? round($qs / $qw, 2) : null;

    // Overall
    $overall = null;
    if ($quantScore !== null && $qualScore !== null) {
        $tw = (float)$ev['quant_weight'] + (float)$ev['qual_weight'];
        $overall = round(($quantScore * (float)$ev['quant_weight'] + $qualScore * (float)$ev['qual_weight']) / ($tw ?: 1), 2);
    } elseif ($quantScore !== null || $qualScore !== null) {
        $overall = $quantScore ?? $qualScore;
    }
    $data = ['quant_pct' => $quantPct, 'quant_score' => $quantScore, 'qual_score' => $qualScore, 'overall_score' => $overall];
    if ($overall !== null) {
        $m = merit_row($overall);
        $data['rating_label'] = $m['rating_label'] ?? null;
        $data['merit_increase_pct'] = $m['increase_pct'] ?? 0;
        $data['step_increase'] = $m['step_increase'] ?? 0;
        $data['promotion_eligible'] = $m['promotion_eligible'] ?? 0;
        $under = eval_underperforming((int)$ev['employee_id'], (int)$ev['cycle_id'], $overall);
        $data['disciplinary_referral'] = $under ? 1 : 0;
        $rec = [];
        if (($m['increase_pct'] ?? 0) > 0) {
            $rec[] = 'Merit increase of ' . rtrim(rtrim($m['increase_pct'], '0'), '.') . '%';
        }
        if (($m['step_increase'] ?? 0) > 0) {
            $rec[] = 'Salary step progression +' . $m['step_increase'];
        }
        if (!empty($m['promotion_eligible'])) {
            $rec[] = 'Eligible for promotion';
        }
        if (!empty($m['disciplinary_flag'])) {
            $rec[] = 'Performance improvement plan';
        }
        if ($under) {
            $rec[] = 'Consistently below standard for two consecutive cycles — refer to disciplinary workflow';
        }
        $data['recommendation'] = $rec ? implode('; ', $rec) . '.' : 'Maintain current compensation.';
    }
    update('evaluations', $data, 'id = ?', [$evalId]);
    return $data;
}

/** True when this and the previous finalized evaluation are both below the threshold. */
function eval_underperforming(int $empId, int $cycleId, float $score): bool
{
    $th = setting_num('underperform_threshold', 2.5);
    if ($score >= $th) {
        return false;
    }
    $cycle = row('SELECT * FROM evaluation_cycles WHERE id = ?', [$cycleId]);
    $prev = row("SELECT e.overall_score FROM evaluations e JOIN evaluation_cycles c ON c.id = e.cycle_id
                 WHERE e.employee_id = ? AND e.status = 'finalized' AND c.period_end < ? ORDER BY c.period_end DESC LIMIT 1",
        [$empId, $cycle['period_start']]);
    return $prev && $prev['overall_score'] !== null && (float)$prev['overall_score'] < $th;
}
