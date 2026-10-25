<?php
/**
 * Serves uploaded files (documents, photos, selfies, case attachments) to authorized users only.
 * The /uploads folder itself is blocked from direct web access by .htaccess.
 */
$rel = (string)get('f', '');
$rel = str_replace(['\\', "\0"], ['/', ''], $rel);
if ($rel === '' || strpos($rel, '..') !== false || !preg_match('#^[A-Za-z0-9_\-/\.]+$#', $rel)) {
    http_response_code(400);
    exit('Invalid file.');
}
// each company reads only its own upload folder (Company 1: uploads/, others: uploads/company_<id>/)
$root = realpath(upload_dir());
$path = realpath(upload_dir() . '/' . $rel);
if (!$root || !$path || !upload_path_allowed($rel) || strpos($path, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
    http_response_code(404);
    exit('File not found.');
}
if (!can_any(['employees.view', 'web_verify.view', 'cases.view', 'leave_applications.view', 'approvals.view', 'dtr.view'])) {
    $me = my_employee_id();
    $owns = $me && (
        val('SELECT COUNT(*) FROM employees WHERE id = ? AND photo_path = ?', [$me, $rel]) ||
        val('SELECT COUNT(*) FROM attendance_logs WHERE employee_id = ? AND photo_path = ?', [$me, $rel]) ||
        val('SELECT COUNT(*) FROM employee_documents WHERE employee_id = ? AND file_path = ?', [$me, $rel]) ||
        val('SELECT COUNT(*) FROM leave_applications WHERE employee_id = ? AND attachment = ?', [$me, $rel])
    );
    if (!$owns) {
        http_response_code(403);
        exit('Access denied.');
    }
}
$mime = function_exists('mime_content_type') ? (mime_content_type($path) ?: 'application/octet-stream') : 'application/octet-stream';
$inline = preg_match('#^(image/|application/pdf|text/plain)#', $mime);
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . basename($path) . '"');
readfile($path);
exit;
