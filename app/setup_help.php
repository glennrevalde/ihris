<?php
/**
 * Shown by index.php when the database cannot be reached or has not been imported yet.
 * Expects $e (PDOException) from the caller.
 */
$c = $GLOBALS['config'];
// the database is not available here, so the name comes from the config file only
$appName = trim((string)($c['app_name'] ?? ''));
$appName = ($appName === '' || $appName === 'I-HRIS') ? 'G-SMaRT HRIS' : $appName;
$code = (int)($e->errorInfo[1] ?? 0);
$msg = $e->getMessage();
if (!$code && preg_match('/SQLSTATE\[\w+\] \[(\d+)\]/', $msg, $m)) {
    $code = (int)$m[1];
}

$db = '<code>' . h($c['db_name']) . '</code>';
switch (true) {
    case !empty($upgradeError):
        $title = 'The automatic database upgrade could not finish';
        $steps = [
            'The MySQL account in <code>config/config.php</code> needs permission to ALTER / CREATE tables in ' . $db . '.',
            'Or run the upgrade yourself: in Navicat or phpMyAdmin select ' . $db . ', then import, in order, the <code>database/upgrade_to_v2_mysql55.sql</code> to <code>upgrade_to_v5_mysql55.sql</code> scripts not applied yet (each can safely run again).',
            'Reload this page.',
        ];
        break;
    case $code === 1049:
        $title = 'The database has not been created yet';
        $steps = [
            'Open phpMyAdmin (XAMPP: <code>http://localhost/phpmyadmin</code>).',
            'Click <b>Import</b>, choose <code>database/ihris_mysql55.sql</code> from the ' . h($appName) . ' folder and click <b>Go</b>. It creates the ' . $db . ' database.',
            'Reload this page.',
        ];
        break;
    case $code === 1146:
        $title = 'The ' . $appName . ' tables are missing';
        $steps = [
            'The database ' . $db . ' exists but is empty or incomplete.',
            'In phpMyAdmin click <b>Import</b>, choose <code>database/ihris_mysql55.sql</code> and click <b>Go</b>.',
            'Reload this page.',
        ];
        break;
    case in_array($code, [1044, 1045, 1698], true):
        $title = 'MySQL refused the login';
        $steps = [
            'The MySQL user <code>' . h($c['db_user']) . '</code> was rejected (wrong password, or no access to ' . $db . ').',
            'Open <code>config/config.php</code> and set <code>db_user</code> and <code>db_pass</code> to your MySQL account.',
            'Reload this page.',
        ];
        break;
    case in_array($code, [2002, 2003, 2005, 2006], true):
        $title = 'Cannot reach the MySQL server';
        $steps = [
            'Nothing is answering at <code>' . h($c['db_host']) . ':' . (int)$c['db_port'] . '</code>.',
            'Start <b>MySQL</b> in the XAMPP Control Panel, or correct <code>db_host</code> / <code>db_port</code> in <code>config/config.php</code>.',
            'Reload this page.',
        ];
        break;
    default:
        $title = 'The database is not ready';
        $steps = [
            'Import <code>database/ihris_mysql55.sql</code> in phpMyAdmin.',
            'Check the settings in <code>config/config.php</code>.',
            'Reload this page.',
        ];
}

http_response_code(503);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Database setup - <?= h($appName) ?></title>
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="guest">
<div class="guest-wrap">
  <div class="login-card" style="max-width:560px">
    <div class="brand-mark"><?= h(substr(preg_replace('/[^A-Z0-9]/', '', explode(' ', $appName)[0]), 0, 2) ?: 'HR') ?></div>
    <h1><?= h($title) ?></h1>
    <p class="muted" style="margin-bottom:14px"><?= h($appName) ?> could not open its database. To fix it:</p>
    <ol style="padding-left:20px;line-height:1.7">
      <?php foreach ($steps as $s): ?><li><?= $s ?></li><?php endforeach; ?>
    </ol>
    <table class="table" style="margin-top:14px;font-size:13px">
      <tr><th style="width:40%">Host : port</th><td><code><?= h($c['db_host']) ?>:<?= (int)$c['db_port'] ?></code></td></tr>
      <tr><th>Database</th><td><code><?= h($c['db_name']) ?></code></td></tr>
      <tr><th>User</th><td><code><?= h($c['db_user']) ?></code></td></tr>
    </table>
    <?php if (!empty($c['debug'])): ?>
      <p class="small muted" style="margin-top:12px;word-break:break-word">MySQL said: <?= h($msg) ?></p>
    <?php endif; ?>
    <p style="margin-top:16px"><a class="btn btn-primary btn-block" href="index.php">Try again</a></p>
  </div>
</div>
</body>
</html>
