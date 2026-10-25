<?php
/**
 * NTE to-do's (v16).
 *
 * The attendance policy "Habitual tardiness: times late per month" (and the monthly tardiness cap)
 * flags an employee for HR review / Notice to Explain. Besides the flag of the attendance
 * transaction, every flagged employee is listed in the To-do's of the dashboard:
 *   - of the company HR (users who may create disciplinary cases), for every employee of their
 *     company / branch and department assignment: Issue NTE (a draft case with the late days)
 *     or Dismiss with a note;
 *   - of the immediate superior (the supervisor of the employee, or the head of the unit), for the
 *     members of the team: the same to-do, to follow it up with HR.
 * A to-do closes by itself when a disciplinary case is opened for the employee from that month on,
 * or when the late days are corrected and the employee is no longer over the limit.
 *
 * Table nte_todos (migration v14): one row per employee, month and reason.
 */

const NTE_REASONS = ['habitual_tardiness' => 'Habitual tardiness', 'tardiness_cap' => 'Monthly tardiness cap exceeded'];
const NTE_STATUSES = ['open' => 'For NTE', 'nte_issued' => 'NTE issued', 'dismissed' => 'Dismissed', 'cleared' => 'No longer over the limit'];

function nte_supported(): bool
{
    static $ok = [];
    $db = function_exists('active_db_name') ? active_db_name() : '';
    if (!isset($ok[$db])) {
        try {
            $ok[$db] = (bool)val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'nte_todos'");
        } catch (Throwable $e) {
            $ok[$db] = false;
        }
    }
    return $ok[$db];
}

/**
 * Bring the to-do's of a month up to date with the daily time records (current and previous month
 * when $month is null). Returns the number of open to-do's of those months.
 */
function nte_scan(?string $month = null): int
{
    if (!nte_supported()) {
        return 0;
    }
    static $done = [];
    $months = $month ? [date('Y-m-01', strtotime($month))] : [date('Y-m-01'), date('Y-m-01', strtotime(date('Y-m-01') . ' -1 month'))];
    $grace = (int)setting('grace_minutes', 0);
    $thCount = max(1, (int)setting('habitual_tardy_count', 5));
    $thMin = max(1, (int)setting('tardy_cap_minutes', 120));
    $open = 0;
    foreach ($months as $mStart) {
        $key = (function_exists('active_db_name') ? active_db_name() : '') . '|' . $mStart;
        if (isset($done[$key])) {
            continue;
        }
        $done[$key] = true;
        $mEnd = date('Y-m-t', strtotime($mStart));
        $rows = rows("SELECT e.id, e.supervisor_id, COUNT(*) AS cnt, COALESCE(SUM(d.late_minutes), 0) AS mins
                      FROM dtr d JOIN employees e ON e.id = d.employee_id
                      WHERE d.work_date BETWEEN ? AND ? AND d.status = 'present' AND d.day_type = 'ordinary' AND d.late_minutes > ? AND e.status = 'active'
                      GROUP BY e.id, e.supervisor_id HAVING COUNT(*) >= ? OR COALESCE(SUM(d.late_minutes), 0) >= ?", [$mStart, $mEnd, $grace, $thCount, $thMin]);
        $found = [];
        foreach ($rows as $r) {
            $reasons = [];
            if ((int)$r['cnt'] >= $thCount) {
                $reasons[] = 'habitual_tardiness';
            }
            if ((int)$r['mins'] >= $thMin) {
                $reasons[] = 'tardiness_cap';
            }
            foreach ($reasons as $reason) {
                $found[(int)$r['id'] . '|' . $reason] = true;
                q("INSERT INTO nte_todos (employee_id, period_month, reason, late_count, late_minutes, supervisor_id, status, created_at, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, 'open', ?, ?)
                   ON DUPLICATE KEY UPDATE late_count = VALUES(late_count), late_minutes = VALUES(late_minutes), supervisor_id = VALUES(supervisor_id),
                     status = IF(status = 'cleared', 'open', status), updated_at = VALUES(updated_at)",
                    [(int)$r['id'], $mStart, $reason, (int)$r['cnt'], (int)$r['mins'], $r['supervisor_id'] ?: null, now(), now()]);
            }
        }
        foreach (rows("SELECT id, employee_id, reason FROM nte_todos WHERE period_month = ? AND status = 'open'", [$mStart]) as $t) {
            if (!isset($found[$t['employee_id'] . '|' . $t['reason']])) {
                q("UPDATE nte_todos SET status = 'cleared', updated_at = ? WHERE id = ?", [now(), $t['id']]);   // late days corrected
                continue;
            }
            // a disciplinary case opened for the employee from that month on closes the to-do
            $case = val('SELECT id FROM disciplinary_cases WHERE employee_id = ? AND created_at >= ? ORDER BY id DESC LIMIT 1', [$t['employee_id'], $mStart . ' 00:00:00']);
            if ($case) {
                q("UPDATE nte_todos SET status = 'nte_issued', case_id = ?, handled_at = COALESCE(handled_at, ?), updated_at = ? WHERE id = ?", [$case, now(), now(), $t['id']]);
                continue;
            }
            $open++;
        }
    }
    return $open;
}

/**
 * The open to-do's the current user has to see: ['hr' => rows, 'team' => rows].
 * HR = may create disciplinary cases (employees of the user's assignment); team = the members of the
 * user's team (supervisor or unit head), when the user is not already seeing them as HR.
 */
function nte_todos_for_user(): array
{
    $out = ['hr' => [], 'team' => []];
    if (!nte_supported() || !current_user()) {
        return $out;
    }
    $isHr = can('cases.create');
    $team = function_exists('my_team_ids') ? my_team_ids() : [];
    if (!$isHr && !$team) {
        return $out;
    }
    nte_scan();
    $sel = "SELECT t.*, e.employee_no, e.first_name, e.last_name, e.middle_name, e.suffix, e.position, ou.name AS unit_name,
                   CONCAT(s.first_name, ' ', s.last_name) AS supervisor_name
            FROM nte_todos t JOIN employees e ON e.id = t.employee_id LEFT JOIN org_units ou ON ou.id = e.org_unit_id
            LEFT JOIN employees s ON s.id = e.supervisor_id
            WHERE t.status = 'open'";
    // one to-do per employee and month (both reasons on the same line)
    $group = function (array $rows): array {
        $g = [];
        foreach ($rows as $r) {
            $k = $r['employee_id'] . '|' . $r['period_month'];
            if (!isset($g[$k])) {
                $g[$k] = $r + ['reasons' => [], 'ids' => []];
            }
            $g[$k]['reasons'][] = $r['reason'];
            $g[$k]['ids'][] = (int)$r['id'];
        }
        return array_values($g);
    };
    if ($isHr) {
        $out['hr'] = $group(rows($sel . user_scope_sql('e') . ' ORDER BY t.period_month DESC, t.late_count DESC, e.last_name, t.reason'));
    }
    if ($team) {
        $rows = $group(rows($sel . ' AND t.employee_id IN (' . implode(',', array_map('intval', $team)) . ') ORDER BY t.period_month DESC, e.last_name, t.reason'));
        $seen = [];
        foreach ($out['hr'] as $r) {
            $seen[$r['employee_id'] . '|' . $r['period_month']] = true;
        }
        $out['team'] = array_values(array_filter($rows, fn($r) => !isset($seen[$r['employee_id'] . '|' . $r['period_month']])));
    }
    return $out;
}

/** Reasons of a (grouped) to-do, in words. */
function nte_reason_text(array $t): string
{
    return implode(', ', array_map(fn($r) => NTE_REASONS[$r] ?? $r, $t['reasons'] ?? [$t['reason']]));
}

/** Close every open to-do of the employee for the month of to-do $id (NTE issued or dismissed). */
function nte_close(int $id, string $status, ?int $caseId = null, ?string $note = null): ?array
{
    $t = nte_supported() ? row('SELECT * FROM nte_todos WHERE id = ?', [$id]) : null;
    if (!$t) {
        return null;
    }
    q("UPDATE nte_todos SET status = ?, case_id = COALESCE(?, case_id), note = COALESCE(?, note), handled_by = ?, handled_at = ?, updated_at = ?
       WHERE employee_id = ? AND period_month = ? AND status = 'open'", [$status, $caseId, $note, user_id(), now(), now(), $t['employee_id'], $t['period_month']]);
    return $t;
}

/** One line of a to-do: "Late 6 times (95 minutes) in October 2026". */
function nte_todo_text(array $t): string
{
    return 'Late ' . (int)$t['late_count'] . ' time' . ((int)$t['late_count'] === 1 ? '' : 's') . ' (' . (int)$t['late_minutes'] . ' minutes) in ' . date('F Y', strtotime($t['period_month']));
}

/** Address of the draft case (Notice to Explain) for a to-do: the late days of the month in the details. */
function nte_case_url(array $t): string
{
    $mStart = $t['period_month'];
    $mEnd = date('Y-m-t', strtotime($mStart));
    $days = rows("SELECT work_date, late_minutes FROM dtr WHERE employee_id = ? AND work_date BETWEEN ? AND ? AND status = 'present' AND day_type = 'ordinary' AND late_minutes > ? ORDER BY work_date",
        [$t['employee_id'], $mStart, $mEnd, (int)setting('grace_minutes', 0)]);
    $list = implode(', ', array_map(fn($d) => date('M j', strtotime($d['work_date'])) . ' (' . (int)$d['late_minutes'] . ' min)', $days));
    $offense = val("SELECT id FROM conduct_offenses WHERE is_active = 1 AND (code LIKE '%TARD%' OR category LIKE '%tard%' OR description LIKE '%tard%') ORDER BY id LIMIT 1");
    return url('cases', array_filter([
        'new' => 1, 'employee_id' => $t['employee_id'], 'source' => 'attendance', 'offense_id' => $offense ?: null,
        'date' => $days ? end($days)['work_date'] : $mEnd, 'nte_todo' => $t['id'],
        'details' => nte_reason_text($t) . ': ' . nte_todo_text($t) . ($list ? '. Late on ' . $list . '.' : '.'),
    ]));
}
