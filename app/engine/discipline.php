<?php
/**
 * Employee discipline: twin-notice due process (Notice to Explain -> opportunity to be heard ->
 * evaluation -> Notice of Decision) with automatic progressive-penalty suggestion from the
 * Code of Conduct matrix, counting prior offenses within a rolling period.
 */

const CASE_STAGES = [
    'draft' => 'Draft',
    'nte_issued' => 'NTE Issued',
    'explanation_received' => 'Explanation Received',
    'hearing_scheduled' => 'Hearing Scheduled',
    'for_decision' => 'For Evaluation / Decision',
    'decided' => 'NOD Issued',
    'closed' => 'Closed',
];

const PENALTY_LABELS = [
    'exonerated' => 'Exonerated / No penalty',
    'verbal_warning' => 'Verbal Warning',
    'written_warning' => 'Written Warning',
    'suspension' => 'Suspension',
    'dismissal' => 'Dismissal',
];

function penalty_text(?array $p): string
{
    if (!$p) {
        return 'No matching penalty defined';
    }
    $t = PENALTY_LABELS[$p['penalty_type']] ?? label($p['penalty_type']);
    if ($p['penalty_type'] === 'suspension' && $p['min_days']) {
        $t .= ' (' . $p['min_days'] . ($p['max_days'] && $p['max_days'] != $p['min_days'] ? '–' . $p['max_days'] : '') . ' days)';
    }
    if (!empty($p['notes'])) {
        $t .= ' — ' . $p['notes'];
    }
    return $t;
}

/**
 * Number of prior sanctioned offenses of the same category within the rolling look-back period.
 * v17: No. of Days to reset Penalty of the offense (optional): when the previous offense is more than that many
 * days before, the count starts again (the offense is a first offense again). E.g. 90 days on tardiness: a
 * second tardiness 120 days after the last one is treated as the first offense. Blank = no reset.
 */
function count_prior_offenses(int $empId, int $offenseId, string $asOf, ?int $excludeCaseId = null): int
{
    $months = (int)setting('discipline_lookback_months', 12);
    $since = date('Y-m-d', strtotime($asOf . " -$months months"));
    $dates = q("SELECT incident_date FROM disciplinary_cases
                WHERE employee_id = ? AND offense_id = ? AND incident_date >= ? AND incident_date <= ?
                  AND status IN ('decided','closed') AND (decision IS NULL OR decision <> 'exonerated')"
        . ($excludeCaseId ? ' AND id <> ' . (int)$excludeCaseId : '') . ' ORDER BY incident_date DESC', [$empId, $offenseId, $since, $asOf])->fetchAll(PDO::FETCH_COLUMN);
    $reset = offense_reset_days($offenseId);
    if (!$reset) {
        return count($dates);
    }
    $n = 0;
    $cur = $asOf;
    foreach ($dates as $d) {
        if (days_between((string)$d, $cur) > $reset) {
            break;   // the chain of offenses was broken by more than the reset days: counted again from one
        }
        $n++;
        $cur = (string)$d;
    }
    return $n;
}

/** No. of Days to reset Penalty of an offense (null = the offenses do not reset). */
function offense_reset_days(int $offenseId): ?int
{
    static $cache = [];
    if (!array_key_exists($offenseId, $cache)) {
        $v = null;
        try {
            $v = val('SELECT reset_days FROM conduct_offenses WHERE id = ?', [$offenseId]);
        } catch (Throwable $e) {
            $v = null;   // before the version-15 upgrade
        }
        $cache[$offenseId] = $v !== null && (int)$v > 0 ? (int)$v : null;
    }
    return $cache[$offenseId];
}

/** Suggested progressive penalty for the next offense. */
function suggest_penalty(int $empId, int $offenseId, string $incidentDate, ?int $excludeCaseId = null): array
{
    $prior = count_prior_offenses($empId, $offenseId, $incidentDate, $excludeCaseId);
    $n = $prior + 1;
    $pens = rows('SELECT * FROM conduct_penalties WHERE offense_id = ? ORDER BY offense_no', [$offenseId]);
    $pick = null;
    foreach ($pens as $p) {
        if ((int)$p['offense_no'] <= $n) {
            $pick = $p;
        }
    }
    return ['prior' => $prior, 'offense_no' => $n, 'penalty' => $pick, 'text' => penalty_text($pick)];
}

function ordinal(int $n): string
{
    $s = ['th', 'st', 'nd', 'rd'];
    $v = $n % 100;
    return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
}

function case_event(int $caseId, string $stage, ?string $notes = null): void
{
    $u = current_user();
    insert('case_events', ['case_id' => $caseId, 'stage' => $stage, 'notes' => $notes, 'user_id' => $u['id'] ?? null, 'username' => $u['username'] ?? 'system']);
}

/** Create a draft case (manual, or referred from attendance / evaluation). Returns case id. */
function create_case(int $empId, int $offenseId, string $incidentDate, string $details, ?string $provision, string $source = 'manual'): int
{
    $sug = suggest_penalty($empId, $offenseId, $incidentDate);
    $id = insert('disciplinary_cases', [
        'case_no' => next_number('DC', 'disciplinary_cases', 'case_no'), 'employee_id' => $empId, 'offense_id' => $offenseId,
        'incident_date' => $incidentDate, 'incident_details' => $details, 'policy_provision' => $provision,
        'offense_count' => $sug['offense_no'], 'suggested_penalty' => ordinal($sug['offense_no']) . ' offense: ' . $sug['text'],
        'source' => $source, 'created_by' => user_id(),
    ]);
    case_event($id, 'draft', 'Case created (' . $source . '). Suggested penalty: ' . ordinal($sug['offense_no']) . ' offense — ' . $sug['text']);
    audit('pis', 'case_create', 'disciplinary_cases', $id, ['employee_id' => $empId, 'offense_id' => $offenseId, 'source' => $source]);
    return $id;
}

function case_full(int $id): ?array
{
    return row('SELECT c.*, o.category, o.code AS offense_code, o.description AS offense_desc, e.first_name, e.last_name, e.middle_name, e.suffix,
                       e.employee_no, e.position, e.default_branch_id, ou.name AS org_unit_name
                FROM disciplinary_cases c
                JOIN conduct_offenses o ON o.id = c.offense_id
                JOIN employees e ON e.id = c.employee_id
                LEFT JOIN org_units ou ON ou.id = e.org_unit_id
                WHERE c.id = ?', [$id]);
}
