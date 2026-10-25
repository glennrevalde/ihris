<?php
/**
 * Office PC agent endpoint (tools/zkteco/zk_agent.ps1). The agent runs on a Windows PC of the office
 * network, reads the ZKTeco terminal with zkemkeeper.dll and sends the logs here over HTTPS:
 *
 *   GET  index.php?p=bio_push[&co=<company id>]   Authorization: Bearer <device token>
 *        -> {"ok":true,"device":"...","since":"Y-m-d H:i:s"|null}   (the last log already received)
 *   POST the same address, JSON body {"device":{"serial":"...","firmware":"..."},"logs":[{"bio_id":"1",
 *        "time":"Y-m-d H:i:s","state":0,"verify":1}, ...]}
 *        -> {"ok":true,"read":n,"staged":n,"duplicates":n,"errors":n,"posted":n,"batch":id}
 *
 * The token identifies the device (Biometric Device Setup, connection method Office PC agent); only
 * its SHA-256 hash is stored. The logs go to Import Attendance Logs like a download from the terminal,
 * and are posted to the attendance records at once when the device is set to do so.
 */
$GLOBALS['__system_ctx'] = true;   // no signed-in user: the audit trail records the agent as "system"
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$reply = function (int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
};

$token = '';
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
    $token = $m[1];
}
if ($token === '') {
    $token = (string)($_SERVER['HTTP_X_DEVICE_TOKEN'] ?? '');
}
$body = null;
if (is_post()) {
    $raw = (string)file_get_contents('php://input');
    if (strlen($raw) > 12 * 1024 * 1024) {
        $reply(413, ['ok' => false, 'error' => 'Too many logs in one request: send at most 5,000 at a time.']);
    }
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $reply(400, ['ok' => false, 'error' => 'The request body is not valid JSON.']);
    }
    if ($token === '' && !empty($body['token'])) {
        $token = (string)$body['token'];
    }
}
if (!preg_match('/^zk_[0-9a-f]{40}$/', $token)) {
    $reply(401, ['ok' => false, 'error' => 'Missing or malformed device token. Copy the token from Biometric Device Setup.']);
}
$dev = row("SELECT * FROM bio_devices WHERE push_token_hash = ? AND conn_method = 'agent'", [bio_token_hash($token)]);
if (!$dev || !hash_equals((string)$dev['push_token_hash'], bio_token_hash($token))) {
    $reply(401, ['ok' => false, 'error' => 'Unknown device token, or the device is not set to the Office PC agent method. Generate a new token in Biometric Device Setup.']);
}
if (!$dev['is_active']) {
    $reply(403, ['ok' => false, 'error' => 'This device is inactive in Biometric Device Setup.']);
}
$since = val("SELECT MAX(log_time) FROM log_staging WHERE device_id = ? AND batch_id IN (SELECT id FROM import_batches WHERE file_name LIKE 'agent:%')", [(string)$dev['dev_no']]);

if (!is_post()) {
    $reply(200, ['ok' => true, 'device' => $dev['name'], 'dev_no' => (int)$dev['dev_no'], 'since' => $since ?: null, 'auto_post' => (bool)$dev['auto_post'], 'server_time' => now()]);
}

$logs = [];
$bad = 0;
foreach ((array)($body['logs'] ?? []) as $l) {
    $id = trim((string)($l['bio_id'] ?? $l['user_id'] ?? ''));
    $time = str_replace('T', ' ', trim((string)($l['time'] ?? '')));
    if ($id === '' || strlen($id) > 30 || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $time) || !strtotime($time)) {
        $bad++;
        continue;
    }
    $logs[] = ['bio_id' => ZkClient::cleanId($id), 'time' => date('Y-m-d H:i:s', strtotime($time)), 'state' => (int)($l['state'] ?? 0), 'punch' => (int)($l['verify'] ?? $l['punch'] ?? 0)];
}
if (count($logs) > 5000) {
    $reply(413, ['ok' => false, 'error' => 'Too many logs in one request: send at most 5,000 at a time.']);
}
$info = trim(implode(', ', array_filter([
    !empty($body['device']['serial']) ? 'S/N ' . mb_substr((string)$body['device']['serial'], 0, 40) : '',
    !empty($body['device']['firmware']) ? mb_substr((string)$body['device']['firmware'], 0, 60) : '',
    !empty($body['agent']) ? 'agent ' . mb_substr((string)$body['agent'], 0, 40) : '',
])));
try {
    $st = $logs ? bio_logs_stage($dev, $logs, 'agent', $info, (bool)$dev['auto_post']) : ['read' => 0, 'staged' => 0, 'duplicates' => 0, 'errors' => 0, 'posted' => 0, 'batch' => null];
} catch (Throwable $e) {
    $reply(500, ['ok' => false, 'error' => 'The logs could not be saved: ' . $e->getMessage()]);
}
update('bio_devices', ['last_push_at' => now(), 'last_sync_at' => now(), 'device_info' => $info !== '' ? mb_substr($info, 0, 255) : $dev['device_info'],
    'last_status' => mb_substr(sprintf('Agent: %d log(s) received, %d staged, %d duplicate(s)%s.', count($logs), $st['staged'], $st['duplicates'], $dev['auto_post'] ? ', ' . (int)$st['posted'] . ' posted' : ''), 0, 255)], 'id = ?', [$dev['id']]);
if ($logs) {
    audit('ams', 'device_agent_push', 'bio_devices', (int)$dev['id'], ['read' => count($logs), 'staged' => $st['staged'], 'posted' => $st['posted'], 'rejected' => $bad]);
}
$reply(200, ['ok' => true, 'read' => count($logs), 'rejected' => $bad, 'staged' => $st['staged'], 'duplicates' => $st['duplicates'], 'errors' => $st['errors'] ?? 0,
    'posted' => (int)$st['posted'], 'batch' => $st['batch']]);
