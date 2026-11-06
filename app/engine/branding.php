<?php
/**
 * Company logo on the reports (v14): System Settings > Company logo on reports.
 *
 * The logo is uploaded once (PNG, JPG, GIF or WEBP; resized to at most 600 x 240 px) and printed in the
 * letterhead of the reports ticked in the list (one, several or all). It is optional: without a logo,
 * or with "Add Company Logo to Reports" off, the reports keep the text letterhead.
 * Settings: report_logo_path, report_logo_enabled (1/0), report_logo_reports ("all" or route,route ...).
 * The memoranda use the same logo through their own design setting (Letterhead).
 *
 * v27: a logo per company / branch. The logo uploaded in System Settings is the Default logo for all
 * companies and branches; a company or branch can have its own logo (setting report_logo_b{branch id}).
 * Which logo a report prints (report_logo_uris()):
 *  - a report of one company / branch: its own logo, else the default logo;
 *  - only one logo saved in all (the default or one company's): that logo, on every company's reports;
 *  - a report of several (or all) companies: the default logo; without a default, the logos of those
 *    companies side by side (every company with a logo when the report is not limited to some).
 */

/** Where the logo goes in a letterhead (reports and memoranda share the same choices). */
const LOGO_PLACEMENTS = [
    'logo_center' => 'Logo and company name, centered',
    'logo_left' => 'Logo at the left, name beside it',
    'logo_leftmost' => 'Logo at the left most of the report',
    'logo_right' => 'Logo at the right, name beside it',
    'logo_rightmost' => 'Logo at the right most of the report',
];

/** Placement of the logo on the reports (System Settings > Company logo on reports). */
function report_logo_placement(): string
{
    $p = (string)setting('report_logo_placement', 'logo_center');
    return isset(LOGO_PLACEMENTS[$p]) ? $p : 'logo_center';
}

/** Printed reports that can carry the logo: route => [label, group]. */
function report_logo_reports(): array
{
    return [
        'rpt_201_master' => ['Employee 201 File Masterlisting', 'Personnel'],
        'case_print' => ['Disciplinary notices (NTE, notice of decision)', 'Personnel'],
        'dtr_print' => ['Daily Time Record', 'Attendance'],
        'rpt_attendance' => ['Attendance Summary Report', 'Attendance'],
        'rpt_att_logs' => ['Attendance Logs per Employee Listing', 'Attendance'],
        'payslip' => ['Payslip', 'Payroll'],
        'payslips_print' => ['Print Payslips (batch)', 'Payroll'],
        'pay_register' => ['Payroll Batch Register', 'Payroll'],
        'pay_register2' => ['Payroll Batch Register II', 'Payroll'],
        'closing_print' => ['Period Closing report', 'Payroll'],
        'ledger_print' => ['Subsidiary ledger', 'Payroll'],
        'final_pay' => ['Final pay computation', 'Payroll'],
        'rpt_periodical' => ['Periodical Reports', 'Reports'],
        'rpt_monthly_contrib' => ['Monthly Reports', 'Reports'],
        'rpt_ytd_reports' => ['YTD Reports', 'Reports'],
        'rpt_bank_listing' => ['Bank Deposit Listing', 'Reports'],
        'rpt_cash_listing' => ['Cash Payment Listing', 'Reports'],
        'rpt_register' => ['Payroll Register (summary)', 'Reports'],
        'rpt_earn_ded' => ['Earnings & Deductions', 'Reports'],
        'rpt_journal' => ['Payroll Journal (GL)', 'Reports'],
        'rpt_loans' => ['Loan & Ledger Balances', 'Reports'],
        'rpt_13th' => ['13th Month Report', 'Reports'],
    ];
}

/** Absolute path of a stored logo (relative path under uploads/) or null when the file is missing. */
function report_logo_path_file(string $rel): ?string
{
    if ($rel === '' || strpos($rel, '..') !== false) {
        return null;
    }
    $p = upload_dir() . '/' . $rel;
    return is_file($p) ? $p : null;
}

/** Stored default logo file (absolute path) or null. */
function report_logo_file(): ?string
{
    return report_logo_path_file((string)setting('report_logo_path', ''));
}

/** v27: the own logo of a company / branch (absolute path) or null. */
function branch_logo_file(int $branchId): ?string
{
    return $branchId > 0 ? report_logo_path_file((string)setting('report_logo_b' . $branchId, '')) : null;
}

/** v27: every company / branch that has its own logo: branch id => absolute path. */
function branch_logo_files(bool $reload = false): array
{
    static $memo = null;
    if ($memo !== null && !$reload) {
        return $memo;
    }
    $out = [];
    foreach (pairs("SELECT skey, svalue FROM settings WHERE skey LIKE 'report_logo\_b%' AND svalue <> ''") as $k => $v) {
        $id = (int)substr((string)$k, 13);
        if ($id > 0 && ($f = report_logo_path_file((string)$v))) {
            $out[$id] = $f;
        }
    }
    ksort($out);
    return $memo = $out;
}

/** Is there any logo at all (the default or one of a company / branch)? */
function report_logo_any(): bool
{
    return report_logo_file() !== null || branch_logo_files() !== [];
}

/**
 * v27: the logos (absolute paths) a report prints for the companies / branches it covers ($branchIds: the
 * company of the report, or the companies of its selection; empty = all companies).
 */
function report_logo_files_for(array $branchIds = []): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $branchIds))));
    $default = report_logo_file();
    $own = branch_logo_files();
    if (count($ids) === 1) {
        if (isset($own[$ids[0]])) {
            return [$own[$ids[0]]];
        }
        if ($default) {
            return [$default];
        }
        return count($own) === 1 ? array_values($own) : [];   // the only logo saved prints on every company's reports
    }
    if ($default) {
        return [$default];
    }
    if (count($own) === 1) {
        return array_values($own);
    }
    $pick = $ids ? array_intersect_key($own, array_flip($ids)) : $own;
    return array_slice(array_values(array_unique($pick)), 0, 6);
}

/** A stored logo file as a data URI (prints and saves as PDF without a separate request), '' when unreadable. */
function report_logo_file_uri(?string $f): string
{
    static $memo = [];
    if (!$f) {
        return '';
    }
    if (!isset($memo[$f])) {
        $mime = function_exists('mime_content_type') ? (string)mime_content_type($f) : 'image/png';
        $memo[$f] = in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true) ? 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($f)) : '';
    }
    return $memo[$f];
}

/**
 * The logo as a data URI, '' without logo: the default logo, or with $branchId the logo of that company /
 * branch (its own, else the default, else the only logo saved).
 */
function report_logo_uri(?int $branchId = null): string
{
    if ($branchId === null) {
        $f = report_logo_file();
        if (!$f) {
            $own = branch_logo_files();
            $f = count($own) === 1 ? reset($own) : null;
        }
        return report_logo_file_uri($f);
    }
    $fs = report_logo_files_for([$branchId]);
    return report_logo_file_uri($fs[0] ?? null);
}

/** Should the report of this route print the logo? */
function report_logo_on(?string $route = null): bool
{
    $route = $route ?? (string)($_GET['p'] ?? '');
    if (setting('report_logo_enabled', '0') !== '1' || !report_logo_any()) {
        return false;
    }
    $sel = (string)setting('report_logo_reports', '');
    if ($sel === 'all') {
        return isset(report_logo_reports()[$route]);
    }
    return in_array($route, array_filter(explode(',', $sel)), true);
}

/**
 * <img> of the logo(s) for the letterhead of the current report ('' when not selected). $branchIds: the company /
 * branch of the report, or the companies of its selection (empty = all); several logos sit side by side.
 */
function report_logo_img(string $class = 'lh-logo', ?string $route = null, array $branchIds = []): string
{
    if (!report_logo_on($route)) {
        return '';
    }
    $imgs = '';
    foreach (report_logo_files_for($branchIds) as $f) {
        $uri = report_logo_file_uri($f);
        if ($uri !== '') {
            $imgs .= '<img class="' . h($class) . '" src="' . h($uri) . '" alt="Company logo">';
        }
    }
    if ($imgs === '' || substr_count($imgs, '<img') < 2) {
        return $imgs;
    }
    return '<span class="' . h($class) . '-set">' . $imgs . '</span>';   // several companies: their logos together
}

/** v27: the companies / branches of the employees of a payroll run (for the logos of a run-wide report). */
function report_run_branch_ids(int $runId): array
{
    return array_map('intval', array_column(rows('SELECT DISTINCT default_branch_id AS b FROM payroll_items WHERE run_id = ? AND default_branch_id IS NOT NULL', [$runId]), 'b'));
}

/**
 * Save an uploaded logo (resized), replacing the previous one. Returns the stored relative path.
 * v27: $branchId > 0 stores the logo of that company / branch (replacing its previous own logo).
 */
function report_logo_store(string $field, int $branchId = 0): ?string
{
    $f = $_FILES[$field] ?? null;
    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed (error code ' . $f['error'] . ').');
    }
    if ($f['size'] > 4 * 1048576) {
        throw new RuntimeException('The logo is too large: up to 4 MB.');
    }
    $info = @getimagesize($f['tmp_name']);
    $types = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif'];
    if (defined('IMAGETYPE_WEBP')) {
        $types[IMAGETYPE_WEBP] = 'webp';
    }
    if (!$info || !isset($types[$info[2]])) {
        throw new RuntimeException('Upload a PNG, JPG, GIF or WEBP image.');
    }
    $dir = upload_dir() . '/branding';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = 'logo_' . ($branchId > 0 ? 'b' . $branchId . '_' : '') . date('YmdHis') . '.' . ($info[2] === IMAGETYPE_JPEG ? 'jpg' : 'png');
    $dest = $dir . '/' . $name;
    $saved = false;
    if (function_exists('imagecreatefromstring')) {
        $src = @imagecreatefromstring((string)file_get_contents($f['tmp_name']));
        if ($src) {
            [$w, $h] = [imagesx($src), imagesy($src)];
            $k = min(1, 600 / max(1, $w), 240 / max(1, $h));
            $nw = max(1, (int)round($w * $k));
            $nh = max(1, (int)round($h * $k));
            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $saved = $info[2] === IMAGETYPE_JPEG ? imagejpeg($dst, $dest, 88) : imagepng($dst, $dest, 6);
            imagedestroy($src);
            imagedestroy($dst);
        }
    }
    if (!$saved && !move_uploaded_file($f['tmp_name'], $dest) && !copy($f['tmp_name'], $dest)) {
        throw new RuntimeException('Could not store the logo.');
    }
    $old = $branchId > 0 ? branch_logo_file($branchId) : report_logo_file();
    if ($old && realpath($old) !== realpath($dest)) {
        @unlink($old);
    }
    return 'branding/' . $name;
}

/* ------------------------------------------------------------------ selection shown on the reports (v14) */

/**
 * The filters a report was run with, in words ("Organizational unit: Payroll Section", "Company /
 * Branch: HO — Head Office" ...), for the result header on screen and the printed report.
 * $f uses the usual filter keys; $extra adds [label => value] pairs of the report itself.
 */
function report_selection(array $f, array $extra = []): array
{
    $out = [];
    $one = fn(string $sql, $id) => (string)val($sql, [(int)$id]);
    // v20: a filter can hold one, two or more values
    if ($ids = id_list($f['branch_id'] ?? null)) {
        $out[] = (count($ids) > 1 ? 'Companies / Branches: ' : 'Company / Branch: ') . implode(', ', array_map(fn($id) => $one("SELECT CONCAT(COALESCE(NULLIF(branch_code, ''), code), ' — ', name) FROM branches WHERE id = ?", $id), $ids));
    }
    $units = id_list($f['org_unit_id'] ?? ($f['ou'] ?? null));
    if ($units) {
        $names = [];
        foreach ($units as $unit) {
            $u = row('SELECT name, unit_type FROM org_units WHERE id = ?', [(int)$unit]);
            $names[] = $u ? $u['name'] . ($u['unit_type'] ? ' (' . label($u['unit_type']) . ')' : '') : '#' . (int)$unit;
        }
        $out[] = (count($units) > 1 ? 'Organizational units: ' : 'Organizational unit: ') . implode(', ', $names);
    }
    if (!empty($f['pay_group_id'])) {
        $out[] = 'Pay code: ' . $one("SELECT CONCAT(code, ' — ', name) FROM pay_groups WHERE id = ?", $f['pay_group_id']);
    }
    if ($ids = id_list($f['employee_group_id'] ?? null)) {
        $out[] = (count($ids) > 1 ? 'Employee groups: ' : 'Employee group: ') . implode(', ', array_map(fn($id) => $one('SELECT name FROM emp_groups WHERE id = ?', $id), $ids));
    }
    if (!empty($f['class_group_id'])) {
        $out[] = 'Class group: ' . $one('SELECT name FROM class_groups WHERE id = ?', $f['class_group_id']);
    }
    if (!empty($f['employee_type_id'])) {
        $out[] = 'Employee type: ' . $one('SELECT name FROM emp_types WHERE id = ?', $f['employee_type_id']);
    }
    $emp = $f['employee_id'] ?? ($f['emp'] ?? null);
    if (!empty($emp) && ctype_digit((string)$emp)) {
        $e = row('SELECT employee_no, last_name, first_name FROM employees WHERE id = ?', [(int)$emp]);
        if ($e) {
            $out[] = 'Employee: ' . $e['last_name'] . ', ' . $e['first_name'] . ' (' . $e['employee_no'] . ')';
        }
    }
    if (!empty($f['emp_from']) || !empty($f['emp_to'])) {
        $out[] = 'Employees: ' . ($f['emp_from'] ?: 'first') . ' to ' . ($f['emp_to'] ?: 'last');
    }
    if (!empty($f['code'])) {
        $out[] = 'Code: ' . $f['code'];
    }
    if (isset($f['q']) && trim((string)$f['q']) !== '') {
        $out[] = 'Find: "' . trim((string)$f['q']) . '"';
    }
    foreach ($extra as $l => $v) {
        if ($v !== null && $v !== '') {
            $out[] = $l . ': ' . $v;
        }
    }
    return $out;
}

/** "Selection: …" line of a report (screen: under the result title; print: in the header). */
function report_selection_html(array $f, array $extra = [], bool $print = false): string
{
    $s = report_selection($f, $extra);
    $txt = $s ? implode('; ', $s) : 'All companies / branches and all organizational units';
    return $print ? '<div class="doc-selection"><strong>Selection:</strong> ' . h($txt) . '</div>'
        : '<div class="rpt-selection"><span>Selection</span>' . h($txt) . '</div>';
}
