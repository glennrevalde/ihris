<?php
/**
 * Payroll register / payroll reporting data (M.A.D.A.S. Payroll Batch Register).
 * Every payslip line carries the register column it belongs to (payroll_item_lines.reg_col);
 * lines created before that column existed are mapped from their code.
 */

/** Register column of an earning line without one. */
function reg_earn_col(string $code): string
{
    $c = strtoupper($code);
    $map = ['BASIC' => 'basic', 'VL' => 'vl', 'SL' => 'sl', 'EL' => 'elol', 'OL' => 'elol', 'PM' => 'elol',
        'ABSENT' => 'absent', 'LATE' => 'lateut', 'UT' => 'lateut', '13TH' => '13th', 'RETRO' => 'incadj', 'ADJ' => 'incadj', 'HOL' => 'legal'];
    if (isset($map[$c])) {
        return $map[$c];
    }
    if (strpos($c, 'NP') === 0 || strpos($c, 'ND') === 0) {
        return 'np';
    }
    if (strpos($c, 'LH') === 0) {
        return 'legal';
    }
    if (strpos($c, 'SH') === 0) {
        return 'special';
    }
    if (strpos($c, 'OT') === 0 || strpos($c, 'RD') === 0) {
        return 'overtime';
    }
    return 'others';
}

/** Register column of any payslip line (normalized to a column that exists in the register). */
function reg_col_of_line(array $l): string
{
    $col = (string)($l['reg_col'] ?? '');
    if ($col === '') {
        $col = $l['line_type'] === 'deduction' ? ps_col_of_code((string)$l['code']) : reg_earn_col((string)$l['code']);
    }
    if ($col === 'other') {
        $col = 'others';
    }
    $valid = $l['line_type'] === 'deduction' ? REG_DED_COLS : REG_EARN_COLS;
    return isset($valid[$col]) ? $col : 'others';
}

/**
 * Rows of the payroll register of a run.
 * $f: org_unit_id, branch_id, employee_group_id, employee_type_id, q
 */
function register_data(int $runId, array $f = []): array
{
    $run = payroll_run($runId);
    if (!$run) {
        throw new RuntimeException('Payroll run not found.');
    }
    $where = ['pi.run_id = ?'];
    $params = [$runId];
    // v20: Department, Co. / Branch and Employee group take one, two or more values
    if ($units = org_units_with_descendants($f['org_unit_id'] ?? null)) {
        $where[] = sql_in('pi.org_unit_id', $units);
    }
    // a Co./Branch filter lists the employees of those branches and everyone who rendered hours there on a
    // multi-location entry; those show only the part of their pay that belongs to each branch chosen
    $branchIds = id_list($f['branch_id'] ?? null);
    if ($branchIds) {
        $where[] = '(' . sql_in('pi.default_branch_id', $branchIds) . ' OR EXISTS (SELECT 1 FROM payroll_branch_items pbx WHERE pbx.item_id = pi.id AND ' . sql_in('pbx.branch_id', $branchIds) . '))';
    }
    if ($groups = id_list($f['employee_group_id'] ?? null)) {
        $where[] = sql_in('e.employee_group_id', $groups);
    }
    if (!empty($f['employee_type_id'])) {
        $where[] = 'e.employee_type_id = ?';
        $params[] = (int)$f['employee_type_id'];
    }
    if (!empty($f['q'])) {
        $where[] = '(e.employee_no LIKE ? OR e.last_name LIKE ? OR e.first_name LIKE ?)';
        $like = '%' . $f['q'] . '%';
        array_push($params, $like, $like, $like);
    }
    $items = rows('SELECT pi.*, e.employee_no, e.first_name, e.last_name, e.middle_name, e.suffix, e.biometric_id, ou.name AS unit_name, b.name AS branch_name,
                          b.code AS branch_code, eg.code AS group_code
                   FROM payroll_items pi JOIN employees e ON e.id = pi.employee_id
                   LEFT JOIN org_units ou ON ou.id = pi.org_unit_id LEFT JOIN branches b ON b.id = pi.default_branch_id
                   LEFT JOIN emp_groups eg ON eg.id = e.employee_group_id
                   WHERE ' . implode(' AND ', $where) . user_scope_sql('e') . ' ORDER BY ou.name, e.last_name, e.first_name', $params);
    // the employer-share buckets always exist, so a filter that matches nobody reads as zero, not as missing keys
    $employer = ['sss_er' => 0.0, 'sss_ec' => 0.0, 'phic_er' => 0.0, 'hdmf_er' => 0.0];
    if (!$items) {
        return ['run' => $run, 'rows' => [], 'totals' => register_blank_row(), 'employer' => $employer, 'others_e' => [], 'others_d' => []];
    }
    $ids = array_map(fn($i) => (int)$i['id'], $items);
    $lines = rows('SELECT * FROM payroll_item_lines WHERE item_id IN (' . implode(',', $ids) . ')');
    $byItem = [];
    foreach ($lines as $l) {
        $byItem[(int)$l['item_id']][] = $l;
    }
    $parts = [];
    if ($branchIds) {
        foreach (rows('SELECT pb.*, b.name AS part_branch_name, COALESCE(b.branch_code, b.code) AS part_branch_code FROM payroll_branch_items pb
                       JOIN branches b ON b.id = pb.branch_id WHERE pb.item_id IN (' . implode(',', $ids) . ')') as $pb) {
            $parts[(int)$pb['item_id']][] = $pb;
        }
    }
    $rows = [];
    $othE = [];
    $othD = [];
    $totals = register_blank_row();
    foreach ($items as $it) {
      // one line per company / branch chosen for an employee who worked in several of them (each line = the part of that branch)
      $itemParts = [];
      foreach ($branchIds as $bid) {
          if ($pt = register_branch_part($parts[(int)$it['id']] ?? [], $bid)) {
              $itemParts[] = $pt;
          }
      }
      foreach ($itemParts ?: [null] as $part) {
        $r = register_blank_row();
        $r['item'] = $it;
        $r['part'] = $part;
        foreach ($byItem[(int)$it['id']] ?? [] as $l) {
            if ($part && !register_line_in_part($l, $part)) {
                continue;
            }
            $amt = (float)$l['amount'];
            $col = reg_col_of_line($l);
            if ($l['line_type'] === 'earning') {
                if (in_array($col, ['absent', 'lateut'], true)) {
                    $r['earn'][$col] += abs($amt);
                } else {
                    $r['earn'][$col] = ($r['earn'][$col] ?? 0) + $amt;
                }
                if ($col === 'others') {
                    // details of Others (double-click the column header): one column per item
                    $lbl = register_other_label($l);
                    $r['oe'][$lbl] = ($r['oe'][$lbl] ?? 0) + $amt;
                    $othE[$lbl] = true;
                }
            } elseif ($l['line_type'] === 'deduction') {
                $r['ded'][$col] = ($r['ded'][$col] ?? 0) + $amt;
                if ($col === 'others') {
                    $lbl = register_other_label($l);
                    $r['od'][$lbl] = ($r['od'][$lbl] ?? 0) + $amt;
                    $othD[$lbl] = true;
                }
            }
        }
        if ($part) {
            // the branch part: its own gross, the deductions only where they are taken (deduction source)
            $r['gross'] = (float)$part['gross'];
            $r['total_ded'] = $part['is_home'] ? (float)$it['total_deductions'] : 0.0;
            $r['net'] = round($r['gross'] - $r['total_ded'], 2);
            $erAll = (float)$it['sss_er'] + (float)$it['sss_ec'] + (float)$it['phic_er'] + (float)$it['hdmf_er'];
            $share = $erAll > 0 ? (float)$part['er_share_alloc'] / $erAll : 0.0;
            foreach ($employer as $k => $v) {
                $employer[$k] += round((float)$it[$k] * $share, 2);
            }
        } else {
            $r['gross'] = (float)$it['gross_pay'];
            $r['total_ded'] = (float)$it['total_deductions'];
            $r['net'] = (float)$it['net_pay'];
            foreach ($employer as $k => $v) {
                $employer[$k] += (float)$it[$k];
            }
        }
        foreach ($r['earn'] as $k => $v) {
            $totals['earn'][$k] += $v;
        }
        foreach ($r['ded'] as $k => $v) {
            $totals['ded'][$k] += $v;
        }
        foreach (['oe', 'od'] as $g) {
            foreach ($r[$g] as $k => $v) {
                $totals[$g][$k] = ($totals[$g][$k] ?? 0) + $v;
            }
        }
        $totals['gross'] += $r['gross'];
        $totals['total_ded'] += $r['total_ded'];
        $totals['net'] += $r['net'];
        $rows[] = $r;
      }
    }
    ksort($othE, SORT_NATURAL | SORT_FLAG_CASE);
    ksort($othD, SORT_NATURAL | SORT_FLAG_CASE);
    return ['run' => $run, 'rows' => $rows, 'totals' => $totals, 'employer' => $employer, 'others_e' => array_keys($othE), 'others_d' => array_keys($othD)];
}

function register_blank_row(): array
{
    return ['earn' => array_fill_keys(array_keys(REG_EARN_COLS), 0.0), 'ded' => array_fill_keys(array_keys(REG_DED_COLS), 0.0),
        'gross' => 0.0, 'total_ded' => 0.0, 'net' => 0.0, 'item' => null, 'part' => null, 'oe' => [], 'od' => []];
}

/**
 * Column name of an earning / deduction that falls under Others: its name without the detail the
 * payslip adds (amounts, dates, "— reference only"), e.g. "Position Allowance", "Meal Subsidy".
 */
function register_other_label(array $l): string
{
    $d = trim((string)$l['description']);
    $d = preg_replace('/\s+[—–-]\s+.*$/u', '', $d);
    $d = preg_replace('/\s*\((?:[^()]*\d[^()]*)\)\s*$/u', '', $d);
    $d = trim((string)$d);
    return $d !== '' ? mb_substr($d, 0, 40) : (string)$l['code'];
}

/**
 * The part of a multi-location payroll item that belongs to one branch, or null when the item is not split
 * (single location: the whole item belongs to its branch). is_home marks the branch that carries the
 * deductions (deduction source; runs before version 4: the default employer) and the lines without a branch.
 */
function register_branch_part(array $branchRows, int $branchId): ?array
{
    if (count($branchRows) < 2) {
        return null;
    }
    $row = null;
    foreach ($branchRows as $b) {
        if ((int)$b['branch_id'] === $branchId) {
            $row = $b;
        }
    }
    if (!$row) {
        return null;
    }
    $hasSource = (bool)array_filter($branchRows, fn($b) => !empty($b['is_deduction_source']));
    $row['is_home'] = $hasSource ? !empty($row['is_deduction_source']) : !empty($row['is_default']);
    $row['source_code'] = '';
    foreach ($branchRows as $b) {
        if ($hasSource ? !empty($b['is_deduction_source']) : !empty($b['is_default'])) {
            $row['source_code'] = $b['part_branch_code'] ?? '';
        }
    }
    $row['locations'] = count($branchRows);
    return $row;
}

/** Whether a payslip line belongs to a branch part (earnings of the branch; deductions and unassigned lines at home). */
function register_line_in_part(array $l, array $part): bool
{
    if ($l['line_type'] === 'deduction') {
        return !empty($part['is_home']);
    }
    return (int)$l['branch_id'] === (int)$part['branch_id'] || ($l['branch_id'] === null && !empty($part['is_home']));
}

/** Register rows grouped by department (sub-totals per department). */
function register_groups(array $data, string $by = 'unit_name'): array
{
    $g = [];
    foreach ($data['rows'] as $r) {
        $key = (string)($r['item'][$by] ?? '—');
        if (!isset($g[$key])) {
            $g[$key] = ['rows' => [], 'totals' => register_blank_row()];
        }
        $g[$key]['rows'][] = $r;
        foreach ($r['earn'] as $k => $v) {
            $g[$key]['totals']['earn'][$k] += $v;
        }
        foreach ($r['ded'] as $k => $v) {
            $g[$key]['totals']['ded'][$k] += $v;
        }
        foreach (['oe', 'od'] as $x) {
            foreach ($r[$x] as $k => $v) {
                $g[$key]['totals'][$x][$k] = ($g[$key]['totals'][$x][$k] ?? 0) + $v;
            }
        }
        $g[$key]['totals']['gross'] += $r['gross'];
        $g[$key]['totals']['total_ded'] += $r['total_ded'];
        $g[$key]['totals']['net'] += $r['net'];
    }
    ksort($g);
    return $g;
}

/**
 * Register as CSV rows (header + data), used by the Export button. $expand: 'e' and / or 'd' replace the
 * Others column of the earnings / deductions by one column per item (as on screen after a double-click).
 */
function register_csv(array $data, string $expand = ''): array
{
    $expE = strpos($expand, 'e') !== false && !empty($data['others_e']);
    $expD = strpos($expand, 'd') !== false && !empty($data['others_d']);
    $colsE = [];
    foreach (REG_EARN_COLS as $c => $lbl) {
        if ($c === 'others' && $expE) {
            foreach ($data['others_e'] as $o) {
                $colsE[] = ['oe', $o, $o];
            }
        } else {
            $colsE[] = ['earn', $c, $lbl];
        }
    }
    $colsD = [];
    foreach (REG_DED_COLS as $c => $lbl) {
        if ($c === 'others' && $expD) {
            foreach ($data['others_d'] as $o) {
                $colsD[] = ['od', $o, $o];
            }
        } else {
            $colsD[] = ['ded', $c, $lbl];
        }
    }
    $head = array_merge(['Employee no.', 'Employee name', 'Department', 'Branch'], array_column($colsE, 2), ['Gross'], array_column($colsD, 2), ['Total deductions', 'Net pay']);
    $val = fn(array $r, array $c) => number_format((float)($r[$c[0]][$c[1]] ?? 0), 2, '.', '');
    $out = [];
    foreach ($data['rows'] as $r) {
        $branch = !empty($r['part']) ? $r['part']['part_branch_name'] . ' (part of ' . (int)$r['part']['locations'] . ' locations)' : $r['item']['branch_name'];
        $line = [$r['item']['employee_no'], full_name($r['item']), $r['item']['unit_name'], $branch];
        foreach ($colsE as $c) {
            $line[] = $val($r, $c);
        }
        $line[] = number_format($r['gross'], 2, '.', '');
        foreach ($colsD as $c) {
            $line[] = $val($r, $c);
        }
        $line[] = number_format($r['total_ded'], 2, '.', '');
        $line[] = number_format($r['net'], 2, '.', '');
        $out[] = $line;
    }
    $t = $data['totals'];
    $line = ['', 'TOTAL', '', ''];
    foreach ($colsE as $c) {
        $line[] = $val($t, $c);
    }
    $line[] = number_format($t['gross'], 2, '.', '');
    foreach ($colsD as $c) {
        $line[] = $val($t, $c);
    }
    $line[] = number_format($t['total_ded'], 2, '.', '');
    $line[] = number_format($t['net'], 2, '.', '');
    $out[] = $line;
    return [$head, $out];
}

/** Year-to-date figures per employee (YTD report). */
function ytd_data(int $year, array $f = []): array
{
    $where = ["pr.status = 'finalized'", 'YEAR(pr.period_end) = ?'];
    $params = [$year];
    if (!empty($f['org_unit_id'])) {
        $where[] = 'pi.org_unit_id IN (' . implode(',', array_map('intval', org_descendant_ids((int)$f['org_unit_id']))) . ')';
    }
    if (!empty($f['branch_id'])) {
        $where[] = 'pi.default_branch_id = ?';
        $params[] = (int)$f['branch_id'];
    }
    if (!empty($f['pay_group_id'])) {
        $where[] = 'pr.pay_group_id = ?';
        $params[] = (int)$f['pay_group_id'];
    }
    if (!empty($f['employee_id'])) {
        $where[] = 'pi.employee_id = ?';
        $params[] = (int)$f['employee_id'];
    }
    if (!empty($f['month'])) {
        $where[] = 'MONTH(pr.period_end) = ?';
        $params[] = (int)$f['month'];
    }
    return rows('SELECT e.id, e.employee_no, e.first_name, e.last_name, e.middle_name, e.suffix, e.tin, e.status, ou.name AS unit_name,
                        COUNT(*) AS pays,
                        COALESCE(SUM(pi.basic_pay),0) basic, COALESCE(SUM(pi.basic_earned),0) basic_earned,
                        COALESCE(SUM(pi.ot_pay),0) ot, COALESCE(SUM(pi.premium_pay),0) prem, COALESCE(SUM(pi.nd_pay),0) nd,
                        COALESCE(SUM(pi.holiday_pay),0) hol,
                        COALESCE(SUM(pi.benefits_nontax),0) nontax, COALESCE(SUM(pi.benefits_other),0) other_nontax,
                        COALESCE(SUM(pi.other_earnings),0) other_earn, COALESCE(SUM(pi.gross_pay),0) gross,
                        COALESCE(SUM(pi.sss_ee),0) sss, COALESCE(SUM(pi.phic_ee),0) phic, COALESCE(SUM(pi.hdmf_ee + pi.hdmf_ee_voluntary),0) hdmf,
                        COALESCE(SUM(pi.sss_er),0) sss_er, COALESCE(SUM(pi.sss_ec),0) sss_ec, COALESCE(SUM(pi.phic_er),0) phic_er, COALESCE(SUM(pi.hdmf_er),0) hdmf_er,
                        COALESCE(SUM(pi.taxable_income),0) taxable, COALESCE(SUM(pi.withholding_tax),0) tax,
                        COALESCE(SUM(pi.other_deductions),0) other_ded, COALESCE(SUM(pi.total_deductions),0) total_ded, COALESCE(SUM(pi.net_pay),0) net
                 FROM payroll_items pi JOIN payroll_runs pr ON pr.id = pi.run_id JOIN employees e ON e.id = pi.employee_id
                 LEFT JOIN org_units ou ON ou.id = pi.org_unit_id
                 WHERE ' . implode(' AND ', $where) . user_scope_sql('e') . '
                 GROUP BY e.id, e.employee_no, e.first_name, e.last_name, e.middle_name, e.suffix, e.tin, e.status, ou.name
                 ORDER BY ou.name, e.last_name, e.first_name', $params);
}

/** Payroll journal (GL) of a run: account code => [debit, credit]. */
function payroll_journal(int $runId): array
{
    $data = register_data($runId);
    $run = $data['run'];
    $acct = [];
    $add = function (string $code, string $name, float $debit, float $credit) use (&$acct) {
        if (abs($debit) < 0.005 && abs($credit) < 0.005) {
            return;
        }
        $code = $code ?: '—';
        if (!isset($acct[$code])) {
            $acct[$code] = ['code' => $code, 'name' => $name, 'debit' => 0.0, 'credit' => 0.0];
        }
        $acct[$code]['debit'] += $debit;
        $acct[$code]['credit'] += $credit;
    };
    $glNames = pairs('SELECT code, name FROM gl_accounts');
    $gl = fn(string $key, string $default) => (string)(setting($key, $default) ?: $default);
    $ids = array_map(fn($r) => (int)$r['item']['id'], $data['rows']);
    if (!$ids) {
        return ['run' => $run, 'accounts' => [], 'debit' => 0, 'credit' => 0];
    }
    // earnings by GL account (benefit / transaction codes carry their own account)
    foreach (rows('SELECT l.*, bt.gl_code AS ben_gl, dt.gl_code AS ded_gl, ft.gl_code AS fix_gl, et.gl_code AS earn_gl
                   FROM payroll_item_lines l
                   LEFT JOIN benefit_types bt ON l.ref_type = "benefit_type" AND bt.id = l.ref_id
                   LEFT JOIN deduction_types dt ON l.ref_type = "employee_deduction" AND dt.id = l.ref_id
                   LEFT JOIN fixed_trans_codes ft ON l.ref_type = "fixed_trans" AND ft.code = SUBSTRING(l.code, 4)
                   LEFT JOIN earning_types et ON l.ref_type = "employee_transaction" AND et.code = SUBSTRING(l.code, 4)
                   WHERE l.item_id IN (' . implode(',', $ids) . ')') as $l) {
        $amt = (float)$l['amount'];
        $col = reg_col_of_line($l);
        if ($l['line_type'] === 'earning') {
            $code = $l['ben_gl'] ?: ($l['fix_gl'] ?: ($l['earn_gl'] ?: null));
            if (!$code) {
                $code = [
                    'basic' => $gl('gl_salaries', '5000'), 'vl' => $gl('gl_salaries', '5000'), 'sl' => $gl('gl_salaries', '5000'), 'elol' => $gl('gl_salaries', '5000'),
                    'absent' => $gl('gl_salaries', '5000'), 'lateut' => $gl('gl_salaries', '5000'),
                    'overtime' => $gl('gl_ot', '5010'), 'np' => $gl('gl_np', '5030'), 'legal' => $gl('gl_premium', '5020'), 'special' => $gl('gl_premium', '5020'),
                    '13th' => $gl('gl_13th', '5040'), 'incadj' => $gl('gl_salaries', '5000'),
                ][$col] ?? $gl('gl_allow', '5050');
            }
            $add((string)$code, $glNames[$code] ?? label($col), $amt > 0 ? $amt : 0, $amt < 0 ? -$amt : 0);
        } elseif ($l['line_type'] === 'deduction') {
            $code = $l['ded_gl'] ?: null;
            if (!$code) {
                $code = ['sss' => $gl('gl_sss_pay', '2100'), 'phic' => $gl('gl_phic_pay', '2110'), 'hdmf' => $gl('gl_hdmf_pay', '2120'), 'wtax' => $gl('gl_wtax_pay', '2130'),
                    'sss_loan' => $gl('gl_sss_loan', '2140'), 'hdmf_loan' => $gl('gl_hdmf_loan', '2150')][$col] ?? $gl('gl_advances', '1150');
            }
            $add((string)$code, $glNames[$code] ?? label($col), 0, $amt);
        } elseif ($l['line_type'] === 'employer') {
            $add($gl('gl_er_contrib', '5060'), $glNames[$gl('gl_er_contrib', '5060')] ?? 'Employer contributions', $amt, 0);
            $code = ['SSS_ER' => $gl('gl_sss_pay', '2100'), 'SSS_EC' => $gl('gl_sss_pay', '2100'), 'PHIC_ER' => $gl('gl_phic_pay', '2110'), 'HDMF_ER' => $gl('gl_hdmf_pay', '2120')][$l['code']] ?? $gl('gl_sss_pay', '2100');
            $add((string)$code, $glNames[$code] ?? 'Contributions payable', 0, $amt);
        }
    }
    $add($gl('gl_net_pay', '2200'), $glNames[$gl('gl_net_pay', '2200')] ?? 'Net pay payable', 0, $data['totals']['net']);
    $debit = 0;
    $credit = 0;
    foreach ($acct as $a) {
        $debit += $a['debit'];
        $credit += $a['credit'];
    }
    ksort($acct);
    return ['run' => $run, 'accounts' => array_values($acct), 'debit' => $debit, 'credit' => $credit];
}
