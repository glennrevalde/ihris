<?php
/**
 * General helper functions (escaping, URLs, flash messages, CSRF, settings, formatting, uploads).
 */

function cfg(string $key, $default = null)
{
    return $GLOBALS['config'][$key] ?? $default;
}

/**
 * Name of the system on every screen, document and e-mail: System Settings, System name; else config app_name
 * (the former default I-HRIS reads as the new name).
 */
function app_name(): string
{
    $n = trim((string)setting('system_name', ''));
    if ($n === '') {
        $n = trim((string)cfg('app_name', ''));
    }
    return ($n === '' || $n === 'I-HRIS') ? 'G-SMaRT HRIS' : $n;
}

/** Two-letter mark of the system name for the logo tile (G-SMaRT HRIS: GS). */
function app_mark(): string
{
    $first = preg_split('/\s+/', app_name())[0] ?? '';
    $caps = preg_replace('/[^A-Z0-9]/', '', $first);
    return strlen($caps) >= 2 ? substr($caps, 0, 2) : strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', app_name()), 0, 2));
}

function h($s): string
{
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function url(string $page, array $params = []): string
{
    // the page named in the call always wins over a 'p' carried along in a set of kept filters
    unset($params['p']);
    $qs = http_build_query(array_merge(['p' => $page], $params));
    return 'index.php?' . $qs;
}

/** Absolute URL (used in emails). */
function abs_url(string $page, array $params = []): string
{
    return rtrim((string)cfg('app_url'), '/') . '/' . url($page, $params);
}

function redirect(string $page, array $params = []): void
{
    header('Location: ' . url($page, $params));
    exit;
}

/**
 * Return-link plumbing. A screen opened from another screen carries `back=<that screen's
 * address>`; the opened screen shows a Back button to it (page_header 'back') and keeps the
 * parameter when it redirects to itself. Only relative index.php addresses are accepted.
 */
function this_url(): string
{
    $qs = $_GET;
    unset($qs['back']);
    return 'index.php?' . http_build_query($qs);
}

function back_url(): ?string
{
    $b = (string)get('back', '');
    if ($b === '' || strpos($b, 'index.php?') !== 0 || preg_match('/[\r\n<>"\']/', $b) || strlen($b) > 700) {
        return null;
    }
    return $b;
}

/** Route name of the screen a Back link points to, for labelling ("Back to Payroll Register"). */
function back_route(): ?string
{
    $b = back_url();
    if (!$b) {
        return null;
    }
    parse_str(substr($b, strlen('index.php?')), $q);
    return isset($q['p']) ? (string)$q['p'] : null;
}

/** url() that also carries the return address of the current screen. */
function url_back(string $page, array $params = []): string
{
    return url($page, array_merge($params, ['back' => this_url()]));
}

/** Parameters to keep on a self-redirect so the Back button survives a save. */
function back_params(): array
{
    return back_url() ? ['back' => back_url()] : [];
}

function redirect_back(string $fallbackPage = 'dashboard'): void
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if ($ref && strpos($ref, 'index.php') !== false) {
        header('Location: ' . $ref);
        exit;
    }
    redirect($fallbackPage);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $message];
}

function flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Read a POST value (trimmed string, or array). */
function post(string $key, $default = null)
{
    if (!isset($_POST[$key])) {
        return $default;
    }
    $v = $_POST[$key];
    return is_string($v) ? trim($v) : $v;
}

function get(string $key, $default = null)
{
    if (!isset($_GET[$key])) {
        return $default;
    }
    $v = $_GET[$key];
    return is_string($v) ? trim($v) : $v;
}

/** POST value or NULL when blank. */
function post_null(string $key)
{
    $v = post($key);
    return ($v === null || $v === '') ? null : $v;
}

function post_num(string $key, $default = 0)
{
    $v = post($key);
    if ($v === null || $v === '') {
        return $default;
    }
    return (float)str_replace(',', '', (string)$v);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(419);
        exit('Invalid or expired form token. Please go back, refresh the page and try again.');
    }
}

/* ------------------------------------------------------------------ settings */

function setting(string $key, $default = null)
{
    static $cache = null;
    if ($cache === null || $key === '__reload__') {
        $cache = [];
        try {
            $cache = pairs('SELECT skey, svalue FROM settings');
        } catch (Throwable $e) {
            $cache = [];
        }
        if ($key === '__reload__') {
            return null;
        }
    }
    return array_key_exists($key, $cache) && $cache[$key] !== null ? $cache[$key] : $default;
}

function setting_num(string $key, float $default = 0): float
{
    $v = setting($key, null);
    return ($v === null || $v === '') ? $default : (float)$v;
}

function set_setting(string $key, $value): void
{
    q('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)', [$key, $value]);
    setting('__reload__');
}

/* ---------------------------------------------------------------- formatting */

function money($n, int $dec = 2): string
{
    return number_format((float)$n, $dec);
}

function peso($n): string
{
    $n = (float)$n;
    return ($n < 0 ? '-' : '') . '₱' . number_format(abs($n), 2);
}

function r2($n): float
{
    return round((float)$n + 0.0000001 * ((float)$n <=> 0), 2);
}

function fmt_date($d, string $fmt = 'M d, Y'): string
{
    if (!$d || $d === '0000-00-00') {
        return '';
    }
    $ts = strtotime((string)$d);
    return $ts ? date($fmt, $ts) : '';
}

function fmt_dt($d, string $fmt = 'M d, Y h:i A'): string
{
    return fmt_date($d, $fmt);
}

function fmt_time($d): string
{
    return $d ? date('h:i A', strtotime((string)$d)) : '';
}

function fmt_minutes($m): string
{
    $m = (int)$m;
    if ($m <= 0) {
        return '0';
    }
    return sprintf('%dh %02dm', intdiv($m, 60), $m % 60);
}

function hours($minutes): float
{
    return round(((int)$minutes) / 60, 2);
}

function today(): string
{
    return date('Y-m-d');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function add_days(string $date, int $n): string
{
    return date('Y-m-d', strtotime($date . ' ' . ($n >= 0 ? '+' : '') . $n . ' days'));
}

function days_between(string $from, string $to): int
{
    return (int)round((strtotime($to) - strtotime($from)) / 86400);
}

/** Iterate dates from..to inclusive. */
function date_range(string $from, string $to): array
{
    $out = [];
    $d = $from;
    $guard = 0;
    while ($d <= $to && $guard++ < 2000) {
        $out[] = $d;
        $d = add_days($d, 1);
    }
    return $out;
}

function month_name(int $m): string
{
    return date('F', mktime(0, 0, 0, $m, 1, 2000));
}

function label($s): string
{
    $map = [
        'semi_monthly' => 'Semi-Monthly', 'project_based' => 'Project-Based', 'nte_issued' => 'NTE Issued',
        'dept_head' => 'Department Head', 'hr' => 'HR', 'mwe' => 'MWE', 'sss' => 'SSS',
        'gov_loan' => 'Government Loan', 'accrued_benefit' => 'Accrued Benefit', 'per_event' => 'Per Event',
        'service_based' => 'Length-of-Service Based', 'special_rest' => 'Special Day on Rest Day',
        'regular_rest' => 'Regular Holiday on Rest Day', 'on_leave' => 'On Leave', 'de_minimis' => 'De Minimis',
        'other_benefit' => 'Other Benefit (90K)', 'non_taxable' => 'Non-Taxable', 'final_pay' => 'Final Pay',
        'thirteenth' => '13th Month', 'percent_basic' => '% of Basic', 'unauthorized_ot' => 'Unauthorized OT', 'no_consent' => 'No written consent (Art. 113)',
        'cutoff_not_approved' => 'Attendance cutoff not approved', 'no_attendance' => 'No attendance summary', 'negative_net' => 'Negative net pay',
        'deduction_deferred' => 'Deduction deferred', 'zero_pay' => 'No earnings', 'attendance_exceptions' => 'Attendance exceptions', 'thirteenth_deadline' => '13th month deadline',
        'ot' => 'OT', 'nd' => 'ND',
    ];
    $s = (string)$s;
    return $map[$s] ?? ucwords(str_replace('_', ' ', $s));
}

function full_name(?array $e, bool $lastFirst = true): string
{
    if (!$e) {
        return '';
    }
    $mi = !empty($e['middle_name']) ? ' ' . mb_substr($e['middle_name'], 0, 1) . '.' : '';
    $sfx = !empty($e['suffix']) ? ' ' . $e['suffix'] : '';
    if ($lastFirst) {
        return ($e['last_name'] ?? '') . $sfx . ', ' . ($e['first_name'] ?? '') . $mi;
    }
    return ($e['first_name'] ?? '') . $mi . ' ' . ($e['last_name'] ?? '') . $sfx;
}

function badge($status, ?string $text = null): string
{
    $s = (string)$status;
    $cls = [
        'active' => 'green', 'approved' => 'green', 'finalized' => 'green', 'present' => 'green', 'paid' => 'green',
        'verified' => 'green', 'released' => 'green', 'posted' => 'green', 'resolved' => 'green', 'closed' => 'gray',
        'sent' => 'green', 'decided' => 'blue', 'included' => 'green', 'completed' => 'green', 'auto' => 'gray',
        'pending' => 'amber', 'draft' => 'gray', 'computed' => 'blue', 'open' => 'amber', 'summarized' => 'blue',
        'waiting' => 'gray', 'logged' => 'blue', 'queued' => 'gray', 'submitted' => 'blue', 'locked' => 'gray',
        'returned' => 'amber', 'nte_issued' => 'amber', 'explanation_received' => 'blue', 'hearing_scheduled' => 'blue',
        'for_decision' => 'purple', 'incomplete' => 'amber', 'on_leave' => 'blue', 'rest_day' => 'gray',
        'holiday' => 'purple', 'no_schedule' => 'gray',
        'disallowed' => 'red', 'rejected' => 'red', 'cancelled' => 'red', 'absent' => 'red', 'failed' => 'red',
        'separated' => 'red', 'stopped' => 'gray', 'suspended' => 'red', 'skipped' => 'gray',
    ][$s] ?? 'gray';
    return '<span class="badge badge-' . $cls . '">' . h($text ?? label($s)) . '</span>';
}

/** <option> list. $opts = [value => label]. */
function options(array $opts, $selected = null, ?string $placeholder = null): string
{
    $html = $placeholder !== null ? '<option value="">' . h($placeholder) . '</option>' : '';
    foreach ($opts as $v => $l) {
        $sel = ((string)$v === (string)$selected && $selected !== null && $selected !== '') ? ' selected' : '';
        $html .= '<option value="' . h($v) . '"' . $sel . '>' . h($l) . '</option>';
    }
    return $html;
}

function checked($v): string
{
    return $v ? ' checked' : '';
}

/* ---------------------------------------------------------------- audit */

function audit(string $module, string $action, ?string $entity = null, $entityId = null, $details = null): void
{
    $u = current_user();
    if (is_array($details)) {
        $details = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    try {
        insert('audit_log', [
            'user_id'    => $u['id'] ?? null,
            'username'   => $u['username'] ?? (PHP_SAPI === 'cli' || !empty($GLOBALS['__system_ctx']) ? 'system' : 'guest'),
            'module'     => $module,
            'action'     => $action,
            'entity'     => $entity,
            'entity_id'  => $entityId !== null ? (string)$entityId : null,
            'details'    => $details !== null ? mb_substr((string)$details, 0, 60000) : null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        // never block the main action because of audit failures
    }
}

/** Return only the fields that changed between two arrays (for audit details). */
function diff_fields(array $old, array $new): array
{
    $d = [];
    foreach ($new as $k => $v) {
        $o = $old[$k] ?? null;
        if ((string)$o !== (string)$v) {
            $d[$k] = ['from' => $o, 'to' => $v];
        }
    }
    return $d;
}

/* ---------------------------------------------------------------- numbering */

function next_number(string $prefix, string $table, string $column): string
{
    $year = date('Y');
    $like = $prefix . '-' . $year . '-%';
    $last = val("SELECT `$column` FROM `$table` WHERE `$column` LIKE ? ORDER BY `$column` DESC LIMIT 1", [$like]);
    $n = $last ? ((int)substr((string)$last, strrpos((string)$last, '-') + 1)) + 1 : 1;
    return sprintf('%s-%s-%04d', $prefix, $year, $n);
}

/* ---------------------------------------------------------------- uploads */

/**
 * Save an uploaded file. Returns ['path' => relative path, 'name' => original name] or null.
 * Throws RuntimeException on validation failure.
 */
function save_upload(string $field, string $subdir, array $allowedExt, ?int $index = null): ?array
{
    $f = $_FILES[$field] ?? null;
    if (!$f) {
        return null;
    }
    if ($index !== null) {
        $f = ['name' => $f['name'][$index], 'tmp_name' => $f['tmp_name'][$index], 'error' => $f['error'][$index], 'size' => $f['size'][$index]];
    }
    if ($f['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed (error code ' . $f['error'] . ').');
    }
    if ($f['size'] > (int)cfg('max_upload', 10485760)) {
        throw new RuntimeException('File is too large.');
    }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        throw new RuntimeException('File type .' . $ext . ' is not allowed. Allowed: ' . implode(', ', $allowedExt));
    }
    $dir = upload_dir() . '/' . $subdir;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        if (!rename($f['tmp_name'], $dest)) {
            throw new RuntimeException('Could not store uploaded file.');
        }
    }
    return ['path' => $subdir . '/' . $name, 'name' => basename($f['name'])];
}

/** Store a base64 data-URL image (selfie capture). Returns relative path. */
function save_data_url_image(string $dataUrl, string $subdir): ?string
{
    if (!preg_match('#^data:image/(png|jpeg|jpg|webp);base64,#', $dataUrl, $m)) {
        return null;
    }
    $bin = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
    if ($bin === false || strlen($bin) > 3 * 1024 * 1024 || @getimagesizefromstring($bin) === false) {
        return null;
    }
    $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
    $dir = upload_dir() . '/' . $subdir . '/' . date('Y-m');
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    file_put_contents($dir . '/' . $name, $bin);
    return $subdir . '/' . date('Y-m') . '/' . $name;
}

function upload_url(?string $rel): string
{
    return $rel ? url('file', ['f' => $rel]) : '';
}

/* ---------------------------------------------------------------- misc */

function json_response($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function csv_download(string $filename, array $header, array $rowsData): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if ($header) {
        fputcsv($out, $header, ',', '"', '\\');
    }
    foreach ($rowsData as $r) {
        fputcsv($out, array_values($r), ',', '"', '\\');
    }
    fclose($out);
    exit;
}

/** Years of service (decimal) as of a date. */
function years_of_service(string $hired, ?string $asOf = null): float
{
    $a = new DateTime($hired);
    $b = new DateTime($asOf ?: today());
    $diff = $a->diff($b);
    return $diff->invert ? 0 : $diff->y + $diff->m / 12 + $diff->d / 365;
}

function months_of_service(string $hired, ?string $asOf = null): int
{
    $a = new DateTime($hired);
    $b = new DateTime($asOf ?: today());
    $diff = $a->diff($b);
    return $diff->invert ? 0 : $diff->y * 12 + $diff->m;
}

function haversine_m(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $R = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * $R * asin(min(1, sqrt($a)));
}

/**
 * v16 incremental search: every word typed must be found in one of the columns, so "rey mor",
 * "morilla rey" and "EMP-002" all find "Morilla, Rey (EMP-002)". Returns the SQL condition
 * (without AND) and adds the parameters; '' when nothing is typed.
 */
function search_words_sql(array $cols, string $q, array &$params): string
{
    $words = array_slice(array_values(array_filter(preg_split('/[\s,]+/u', trim($q)) ?: [], fn($w) => $w !== '')), 0, 6);
    if (!$words) {
        return '';
    }
    $parts = [];
    foreach ($words as $w) {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $w) . '%';
        $or = [];
        foreach ($cols as $c) {
            $or[] = "$c LIKE ?";
            $params[] = $like;
        }
        $parts[] = '(' . implode(' OR ', $or) . ')';
    }
    return '(' . implode(' AND ', $parts) . ')';
}

function employee_options(bool $activeOnly = true, ?string $extraWhere = null, array $params = [], bool $scoped = true): array
{
    // only the employees of the user's company / branch and department assignment (app/engine/scope.php)
    $sql = "SELECT id, CONCAT(last_name, ', ', first_name, ' (', employee_no, ')') FROM employees WHERE 1=1"
        . ($activeOnly ? " AND status='active'" : '') . ($extraWhere ? " AND $extraWhere" : '')
        . ($scoped && function_exists('scope_sql') ? scope_sql('employees') : '') . ' ORDER BY last_name, first_name';
    return pairs($sql, $params);
}

/* ---------------------------------------------------------------- v20: filters with one, two or more values */

/** The ids of a filter: branch_id[]=1&branch_id[]=3, a single id or "1,3" (0 and blanks dropped). */
function id_list($v): array
{
    if ($v === null || $v === '' || $v === []) {
        return [];
    }
    $parts = is_array($v) ? $v : explode(',', (string)$v);
    $out = [];
    foreach ($parts as $p) {
        if (is_scalar($p) && (int)$p > 0) {
            $out[(int)$p] = (int)$p;
        }
    }
    return array_values($out);
}

/** The departments chosen and every unit under them. */
function org_units_with_descendants($ids): array
{
    $all = [];
    foreach (id_list($ids) as $id) {
        foreach (org_descendant_ids($id) as $d) {
            $all[(int)$d] = (int)$d;
        }
    }
    return array_values($all);
}

/** "col IN (1,2,3)" for a list of ids (already integers). */
function sql_in(string $col, array $ids): string
{
    $ids = id_list($ids);
    return $ids ? $col . ' IN (' . implode(',', $ids) . ')' : '1=1';
}

/**
 * A filter that takes one, two or more values (v20): a compact drop-down of check boxes with a search,
 * All and Clear. Nothing ticked = all. Posts name[]=id for each ticked value.
 * $opt: 'id' (element id), 'class' (extra class of the field), 'search' (default: more than 8 options).
 */
function multi_pick(string $name, string $caption, array $options, $selected, string $allLabel, array $opt = []): string
{
    $sel = array_map('strval', id_list($selected));
    $chosen = array_values(array_filter(array_map('strval', array_keys($options)), fn($k) => in_array($k, $sel, true)));
    $summary = !$chosen ? $allLabel : (count($chosen) <= 2 ? implode(', ', array_map(fn($k) => (string)$options[$k], $chosen)) : count($chosen) . ' selected');
    $id = $opt['id'] ?? ('mp_' . preg_replace('/\W/', '', $name) . '_' . substr(md5($caption . $name . microtime()), 0, 5));
    $search = $opt['search'] ?? (count($options) > 8);
    $h = '<div class="mfield' . (!empty($opt['class']) ? ' ' . h($opt['class']) : '') . '"><span class="mfield-cap" id="' . h($id) . '_cap">' . h($caption) . '</span>'
        . '<details class="mpick' . ($chosen ? ' has-pick' : '') . '" data-mpick data-all="' . h($allLabel) . '" id="' . h($id) . '">'
        . '<summary aria-labelledby="' . h($id) . '_cap ' . h($id) . '_sum"><span class="mpick-sum" id="' . h($id) . '_sum">' . h($summary) . '</span>'
        . ($chosen ? '<span class="mpick-n">' . count($chosen) . '</span>' : '<span class="mpick-n" hidden></span>') . '</summary>'
        . '<div class="mpick-panel" role="group" aria-label="' . h($caption) . '">'
        . '<div class="mpick-head">' . ($search ? '<input type="search" class="mpick-find" placeholder="Find" aria-label="Find in ' . h($caption) . '" autocomplete="off">' : '')
        . '<button type="button" class="pl-btn" data-mpick-all>All</button><button type="button" class="pl-btn" data-mpick-clear>Clear</button></div><div class="mpick-list">';
    foreach ($options as $k => $l) {
        $k = (string)$k;
        $h .= '<label class="mpick-opt" data-search="' . h(mb_strtolower((string)$l)) . '"><input type="checkbox" name="' . h($name) . '[]" value="' . h($k) . '"'
            . (in_array($k, $sel, true) ? ' checked' : '') . '><span>' . h((string)$l) . '</span></label>';
    }
    if (!$options) {
        $h .= '<p class="muted small mpick-none">Nothing to choose.</p>';
    }
    return $h . '</div><div class="mpick-foot"><span class="muted small">Nothing ticked = ' . h(mb_strtolower($allLabel)) . '</span><button type="button" class="btn btn-sm btn-primary" data-mpick-done>Done</button></div></div></details></div>';
}

/** The values chosen in a multi filter, in words (for the Selection line and printed reports). */
function multi_pick_text(array $options, $selected, string $allLabel): string
{
    $ids = id_list($selected);
    if (!$ids) {
        return $allLabel;
    }
    return implode(', ', array_map(fn($i) => (string)($options[$i] ?? ('#' . $i)), $ids));
}
