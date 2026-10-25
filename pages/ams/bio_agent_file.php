<?php
/**
 * Download the office PC agent of a biometric device (Biometric Device Setup > Office PC agent):
 * a .zip with zk_agent.ps1, zk_agent.bat, register_sdk.bat, zk_64bit_php.reg, README.txt, the sdk
 * folder (zkemkeeper.dll) and zk_agent.json filled in for this device. The token is included only
 * right after it was generated (only its hash is stored, so it cannot be recovered later).
 */
$id = (int)get('id', 0);
$dev = $id ? row('SELECT * FROM bio_devices WHERE id = ?', [$id]) : null;
if (!$dev) {
    flash('error', 'Device not found.');
    redirect('bio_devices');
}
$dir = APP_ROOT . '/tools/zkteco';
$token = (!empty($_SESSION['bio_token'][$id]) && $_SESSION['bio_token'][$id]['at'] > time() - 1800) ? $_SESSION['bio_token'][$id]['token'] : 'zk_paste_the_device_token_from_Biometric_Device_Setup';
$co = tenant_company();
$base = bio_public_base();
$cfgJson = json_encode([
    'hris_url' => $base . '/index.php?p=bio_push' . ($co ? '&co=' . (int)$co['id'] : ''),
    'batch_size' => 2000,
    'devices' => [[
        'name' => $dev['name'], 'ip' => $dev['comm_address'], 'port' => (int)$dev['comm_port'] ?: 4370, 'comm_key' => (int)$dev['dev_password'],
        'machine' => (int)$dev['dev_no'] ?: 1, 'token' => $token, 'clear_after_push' => false,
    ]],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

$f = (string)get('f', 'package');
$single = ['README.txt' => 'text/plain', 'zk_agent.ps1' => 'text/plain', 'zk_agent.bat' => 'text/plain', 'register_sdk.bat' => 'text/plain', 'zk_64bit_php.reg' => 'text/plain'];
if ($f === 'json') {
    audit('ams', 'bio_agent_download', 'bio_devices', $id, ['file' => 'zk_agent.json', 'with_token' => strpos($token, 'zk_paste') !== 0]);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="zk_agent.json"');
    echo $cfgJson;
    exit;
}
if (isset($single[$f]) && is_file($dir . '/' . $f)) {
    header('Content-Type: ' . $single[$f] . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $f . '"');
    readfile($dir . '/' . $f);
    exit;
}
if (!class_exists('ZipArchive')) {
    flash('error', 'The zip extension of PHP is off, so the package cannot be built. Download the files one by one from the folder tools/zkteco of the HRIS.');
    redirect('bio_devices', ['id' => $id]);
}
$tmp = tempnam(sys_get_temp_dir(), 'zka');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    flash('error', 'The agent package could not be built.');
    redirect('bio_devices', ['id' => $id]);
}
foreach (['README.txt', 'zk_agent.ps1', 'zk_agent.bat', 'register_sdk.bat', 'zk_64bit_php.reg', 'zk_agent.sample.json'] as $name) {
    if (is_file($dir . '/' . $name)) {
        $zip->addFile($dir . '/' . $name, 'zkteco/' . $name);
    }
}
foreach (glob($dir . '/sdk/*') ?: [] as $file) {
    if (is_file($file)) {
        $zip->addFile($file, 'zkteco/sdk/' . basename($file));
    }
}
$zip->addFromString('zkteco/zk_agent.json', str_replace("\n", "\r\n", $cfgJson));
$zip->close();
audit('ams', 'bio_agent_download', 'bio_devices', $id, ['file' => 'package', 'with_token' => strpos($token, 'zk_paste') !== 0]);
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="zk_agent_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower($dev['name'])) . '.zip"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
@unlink($tmp);
exit;
