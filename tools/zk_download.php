<?php
/**
 * Scheduled download of the ZKTeco terminals (built-in protocol or ZKTeco SDK), for an HRIS server on
 * the office network. Run it from cron (Linux) or Task Scheduler (Windows), for example every 15 minutes:
 *
 *   Linux:    0,15,30,45 * * * *  php /var/www/html/ihris/tools/zk_download.php
 *   Windows:  schtasks /Create /TN "GSMaRT HRIS ZK download" /SC MINUTE /MO 15 /TR "C:\xampp\php\php.exe C:\xampp\htdocs\ihris\tools\zk_download.php"
 *
 * Every active terminal set to the built-in protocol or the SDK is read; the logs of the last 7 days
 * are staged in Import Attendance Logs, and posted to the attendance records at once when the device
 * has "Post the logs at once" ticked. Every company of a multi-company installation is done in turn.
 * Options: --days=N (default 7), --company=ID (one company only).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this file from the command line.');
}
$_SERVER['REQUEST_METHOD'] = 'GET';
require dirname(__DIR__) . '/app/bootstrap.php';

$opt = getopt('', ['days::', 'company::']);
$days = max(1, (int)($opt['days'] ?? 7));
$only = isset($opt['company']) ? (int)$opt['company'] : 0;
$since = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
$failed = 0;

$run = function (string $label) use ($since, &$failed) {
    db_upgrade();
    foreach (rows("SELECT * FROM bio_devices WHERE is_active = 1 AND conn_type = 'NET' AND conn_method IN ('native','sdk') ORDER BY dev_no") as $dev) {
        try {
            $st = bio_device_download($dev, $since, false);
            if ($dev['auto_post'] && $st['staged']) {
                $p = staging_post((int)$st['batch']);
                $st['posted'] = $p['posted'];
            }
            printf("%s  %s / %s: %d read, %d staged, %d duplicate(s), %d posted\n", date('Y-m-d H:i:s'), $label, $dev['name'], $st['read'], $st['staged'], $st['duplicates'], $st['posted'] ?? 0);
        } catch (Throwable $e) {
            $failed++;
            update('bio_devices', ['last_status' => mb_substr('ERROR: ' . $e->getMessage(), 0, 255)], 'id = ?', [$dev['id']]);
            printf("%s  %s / %s: ERROR %s\n", date('Y-m-d H:i:s'), $label, $dev['name'], $e->getMessage());
        }
    }
};

if (function_exists('master_db') && master_db()) {
    foreach (mrows("SELECT * FROM companies WHERE status = 'active' AND db_status = 'ready' ORDER BY id") as $co) {
        if ($only && (int)$co['id'] !== $only) {
            continue;
        }
        try {
            with_company_db($co['db_name'], fn() => $run($co['name']));
        } catch (Throwable $e) {
            $failed++;
            printf("%s  %s: ERROR %s\n", date('Y-m-d H:i:s'), $co['name'], $e->getMessage());
        }
    }
} else {
    $run((string)setting('company_name', 'Company'));
}
exit($failed ? 1 : 0);
