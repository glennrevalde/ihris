<?php
/**
 * Application bootstrap: configuration, session, error handling and library includes.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('UPLOAD_DIR', APP_ROOT . '/uploads');

$GLOBALS['config'] = require APP_ROOT . '/config/config.php';

date_default_timezone_set($GLOBALS['config']['timezone']);

if (!empty($GLOBALS['config']['debug'])) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    session_name('IHRISSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/access_ui.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/crud.php';
require_once __DIR__ . '/manual.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/pdf.php';
require_once __DIR__ . '/xlsx.php';
require_once __DIR__ . '/engine/org.php';
require_once __DIR__ . '/engine/statutory.php';
require_once __DIR__ . '/engine/leave.php';
require_once __DIR__ . '/engine/attendance.php';
require_once __DIR__ . '/engine/discipline.php';
require_once __DIR__ . '/engine/evaluation.php';
require_once __DIR__ . '/engine/ledger.php';
require_once __DIR__ . '/engine/payroll.php';
require_once __DIR__ . '/engine/closing.php';
require_once __DIR__ . '/engine/termtrans.php';
require_once __DIR__ . '/engine/csvimport.php';
require_once __DIR__ . '/engine/dataimport.php';
require_once __DIR__ . '/engine/quickref.php';
require_once __DIR__ . '/engine/scope.php';
require_once __DIR__ . '/engine/branding.php';
require_once __DIR__ . '/engine/memos.php';
require_once __DIR__ . '/engine/nte.php';
require_once __DIR__ . '/engine/calendar.php';
require_once __DIR__ . '/engine/loanreq.php';   // v20: Employee Loan / Deduction Request
require_once __DIR__ . '/engine/pwreset.php';   // v21: password resets by the Super Admin
require_once __DIR__ . '/engine/payslip.php';
require_once __DIR__ . '/engine/register.php';
require_once __DIR__ . '/engine/payreports.php';
require_once __DIR__ . '/engine/masterlist.php';
require_once __DIR__ . '/engine/retro.php';
require_once __DIR__ . '/engine/employee.php';
require_once __DIR__ . '/engine/imports.php';
require_once __DIR__ . '/engine/zk.php';
require_once __DIR__ . '/engine/provision.php';
require_once __DIR__ . '/migrations.php';
