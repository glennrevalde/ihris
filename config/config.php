<?php
/**
 * I-HRIS configuration.
 * Edit the database credentials below to match your MySQL server.
 * The defaults fit a standard XAMPP install (user root, no password, database "ihris").
 * APP_URL is used to build the approve/return/disallow links inside leave emails.
 */
$config = [
    'db_host'  => getenv('IHRIS_DB_HOST') ?: 'localhost',
    'db_port'  => (int)(getenv('IHRIS_DB_PORT') ?: 3306),
    'db_name'  => getenv('IHRIS_DB_NAME') ?: 'hris_web',
    'db_user'  => getenv('IHRIS_DB_USER') ?: 'root',
    'db_pass'  => getenv('IHRIS_DB_PASS') !== false ? getenv('IHRIS_DB_PASS') : 'Jeglen',

    'app_name' => 'I-HRIS',
    'app_url'  => getenv('IHRIS_APP_URL') ?: 'http://localhost/ihris',
    'timezone' => 'Asia/Manila',

    // Show PHP errors on screen (set to false in production)
    'debug'    => true,

    // Max upload size for documents / imports (bytes)
    'max_upload' => 10 * 1024 * 1024,
];

// Optional: config/config.local.php may return an array that overrides the values above.
if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_merge($config, require __DIR__ . '/config.local.php');
}

return $config;
