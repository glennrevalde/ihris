<?php
/**
 * v36: Web app manifest of G-SMaRT HRIS (installable app / home-screen icon, PWA).
 * The name follows System Settings > System name; the app opens index.php full screen, without the browser bar.
 * Served as its own file (no sign-in needed: browsers fetch it without the session).
 */
$name = 'G-SMaRT HRIS';
ob_start();   // a notice or warning printed while loading (debug on) must not spoil the JSON
try {
    require __DIR__ . '/app/bootstrap.php';
    $name = trim((string)app_name()) ?: $name;
} catch (Throwable $e) {
    // the database is not reachable: the default name
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
$short = mb_strlen($name) <= 12 ? $name : (preg_replace('/\s+.*$/u', '', $name) . ' HRIS');
if (mb_strlen($short) > 12) {
    $short = 'HRIS';
}
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo json_encode([
    'id' => './index.php',
    'name' => $name,
    'short_name' => $short,
    'description' => 'Personnel, Payroll and Attendance',
    'start_url' => './index.php?source=app',
    'scope' => './',
    'display' => 'standalone',
    'display_override' => ['standalone', 'minimal-ui'],
    'orientation' => 'any',
    'background_color' => '#0c1533',
    'theme_color' => '#0c1533',
    'categories' => ['business', 'productivity'],
    'icons' => [
        ['src' => 'assets/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/icons/icon-maskable-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
        ['src' => 'assets/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
    'shortcuts' => [
        ['name' => 'Time Clock', 'url' => './index.php?p=my_clock', 'icons' => [['src' => 'assets/icons/icon-192.png', 'sizes' => '192x192']]],
        ['name' => 'My Daily Time Record', 'url' => './index.php?p=my_dtr', 'icons' => [['src' => 'assets/icons/icon-192.png', 'sizes' => '192x192']]],
        ['name' => 'My Payslips', 'url' => './index.php?p=my_payslips', 'icons' => [['src' => 'assets/icons/icon-192.png', 'sizes' => '192x192']]],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
