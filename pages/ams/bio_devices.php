<?php
/**
 * Biometric Device Setup: the ZKTeco terminals the attendance logs come from. Each terminal has a
 * device number, a name, how the system reaches it (built-in protocol, the ZKTeco SDK zkemkeeper.dll
 * on a Windows server, or the office PC agent for an HRIS hosted on the internet), its address, port
 * and communication key. Test the connection, read the users and download the logs.
 */
$id = (int)get('id', 0);
$rec = $id ? row('SELECT * FROM bio_devices WHERE id = ?', [$id]) : null;
if ($id && !$rec) {
    flash('error', 'Device not found.');
    redirect('bio_devices');
}
$testOut = $_SESSION['bio_test'][$id] ?? null;
unset($_SESSION['bio_test']);
$newToken = null;
if ($id && !empty($_SESSION['bio_token'][$id]) && $_SESSION['bio_token'][$id]['at'] > time() - 1800) {
    $newToken = $_SESSION['bio_token'][$id]['token'];   // shown once after it is generated, for 30 minutes
}

if (is_post()) {
    $action = (string)post('_action');
    $method = array_key_exists((string)post('conn_method'), ZK_METHODS) ? (string)post('conn_method') : 'native';
    $data = [
        'dev_no' => max(1, (int)post('dev_no')),
        'name' => mb_substr(trim((string)post('name')), 0, 80) ?: 'Terminal',
        'conn_method' => $method,
        'conn_type' => post('conn_type') === 'COM' ? 'COM' : 'NET',
        'comm_address' => mb_substr(trim((string)post('comm_address')), 0, 60),
        'comm_port' => mb_substr(trim((string)post('comm_port')), 0, 20) ?: '4370',
        'dev_password' => (string)max(0, (int)post('dev_password')),
        'protocol' => post('protocol') === 'tcp' ? 'tcp' : 'udp',
        'branch_id' => (int)post('branch_id') ?: null,
        'is_active' => post('is_active') ? 1 : 0,
        'auto_post' => post('auto_post') ? 1 : 0,
    ];
    try {
        if ($action === 'delete' && $id) {
            q('DELETE FROM bio_devices WHERE id = ?', [$id]);
            audit('ams', 'bio_device_delete', 'bio_devices', $id, ['name' => $rec['name']]);
            flash('success', 'Device ' . $rec['name'] . ' deleted.');
            redirect('bio_devices');
        }
        if ($action === 'save') {
            if ($data['comm_address'] === '') {
                throw new RuntimeException('Enter the IP address of the terminal (or the COM port of a serial terminal).');
            }
            if ($data['conn_type'] === 'NET' && !filter_var($data['comm_address'], FILTER_VALIDATE_IP) && !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $data['comm_address'])) {
                throw new RuntimeException('Enter a valid IP address (for example 192.168.1.201) or host name.');
            }
            if ($data['conn_type'] === 'NET' && ((int)$data['comm_port'] < 1 || (int)$data['comm_port'] > 65535)) {
                throw new RuntimeException('The communication port is a number from 1 to 65535 (4370 for ZKTeco terminals).');
            }
            if ($id) {
                update('bio_devices', $data, 'id = ?', [$id]);
                audit('ams', 'bio_device_update', 'bio_devices', $id, diff_fields($rec, $data));
            } else {
                $id = insert('bio_devices', $data);
                audit('ams', 'bio_device_create', 'bio_devices', $id, $data);
            }
            flash('success', 'Device ' . $data['name'] . ' saved.' . ($method === 'agent' && empty($rec['push_token_hash']) ? ' Generate the token of the office PC agent next.' : ''));
            redirect('bio_devices', ['id' => $id]);
        }
        if (!$rec) {
            throw new RuntimeException('Save the device first.');
        }
        if ($action === 'token') {
            if ($rec['conn_method'] !== 'agent') {
                throw new RuntimeException('Set the connection method to Office PC agent and save the device first.');
            }
            $token = bio_new_token();
            update('bio_devices', ['push_token_hash' => bio_token_hash($token), 'push_token_hint' => substr($token, -6)], 'id = ?', [$id]);
            audit('ams', 'bio_device_token', 'bio_devices', $id, ['hint' => substr($token, -6), 'replaced' => !empty($rec['push_token_hash'])]);
            $_SESSION['bio_token'][$id] = ['token' => $token, 'at' => time()];
            flash('success', 'New token generated' . (!empty($rec['push_token_hash']) ? ': the previous token no longer works' : '') . '. Download the agent package now; the token is shown only while you stay on this page.');
            redirect('bio_devices', ['id' => $id]);
        }
        if ($action === 'revoke_token') {
            update('bio_devices', ['push_token_hash' => null, 'push_token_hint' => null], 'id = ?', [$id]);
            unset($_SESSION['bio_token'][$id]);
            audit('ams', 'bio_device_token_revoke', 'bio_devices', $id);
            flash('success', 'Token revoked: the office PC agent of this device can no longer send logs.');
            redirect('bio_devices', ['id' => $id]);
        }
        if ($action === 'test') {
            $zk = zk_client($rec);
            try {
                $zk->connect();
                $info = $zk->info();
                $users = $zk->users();
            } finally {
                $zk->disconnect();
            }
            $text = zk_info_text($info);
            update('bio_devices', ['last_status' => mb_substr('Connection OK, ' . $zk->method() . '. ' . $text, 0, 255), 'device_info' => mb_substr($text, 0, 255)], 'id = ?', [$id]);
            $_SESSION['bio_test'][$id] = ['info' => $text, 'users' => $users];
            audit('ams', 'bio_device_test', 'bio_devices', $id, ['ok' => true, 'users' => count($users)]);
            flash('success', 'Connected to ' . $rec['name'] . ': ' . $text . '. ' . count($users) . ' user(s) registered in the terminal.');
        } elseif ($action === 'download') {
            // v22: Date From / Date To of the logs to download
            $dFrom = (string)post('date_from');
            $dTo = (string)post('date_to');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dTo) || $dTo < $dFrom) {
                throw new RuntimeException('Give the Date from and Date to of the logs to download, the To date on or after the From date.');
            }
            $st = bio_device_download($rec, $dFrom, (bool)post('clear_after'), $dTo);
            flash('success', sprintf('%s: %d log(s) on the device, %d of %s to %s: %d staged, %d duplicate(s). Review and post them below.', $rec['name'], $st['read_device'], $st['read'],
                fmt_date($dFrom), fmt_date($dTo), $st['staged'], $st['duplicates']));
            if (post('clear_after') && !$st['cleared'] && $st['outside']) {
                flash('warning', 'The device log was not cleared: ' . number_format($st['outside']) . ' log(s) on the device are outside these dates and would have been lost.');
            }
            redirect('import_logs', ['batch_id' => $st['batch']]);
        }
    } catch (ZkError $e) {
        if ($rec) {
            update('bio_devices', ['last_status' => mb_substr('ERROR: ' . $e->getMessage(), 0, 255)], 'id = ?', [$id]);
            audit('ams', 'bio_device_test', 'bio_devices', $id, ['ok' => false, 'error' => $e->getMessage()]);
        }
        flash('error', $e->getMessage());
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('bio_devices', array_filter(['id' => $id ?: null]));
}

$list = rows('SELECT d.*, COALESCE(b.branch_code, b.code) AS branch_code, b.name AS branch_name FROM bio_devices d LEFT JOIN branches b ON b.id = d.branch_id ORDER BY d.dev_no, d.name');
$v = fn(string $k, $d = '') => h((string)($rec[$k] ?? $d));
$method = $rec['conn_method'] ?? 'native';
$sdkWhy = ZkSdkClient::unavailable();
$co = tenant_company();
$pushUrl = bio_public_base() . '/index.php?p=bio_push' . ($co ? '&co=' . (int)$co['id'] : '');   // multi-company: the agent names its company
$isErr = $rec && strpos((string)$rec['last_status'], 'ERROR') === 0;
$methodLabel = ['native' => 'Built-in', 'sdk' => 'SDK', 'agent' => 'Agent'];

page_header('Biometric Device Setup', [
    'subtitle' => 'The ZKTeco terminals the attendance logs come from, and how the system reaches each one.',
    'actions' => '<a class="btn" href="' . url('import_logs') . '">Import attendance logs</a>' . ($id ? '<a class="btn btn-primary" href="' . url('bio_devices') . '">+ New device</a>' : ''),
]);
?>
<div class="card flush bio-list">
      <div class="card-head pad"><div><h2>Terminals</h2><div class="sub"><?= count($list) ?> device(s)</div></div></div>
      <div class="table-wrap">
        <table class="table table-compact bio-table">
          <thead><tr><th>Dev ID</th><th>Name</th><th>Method</th><th>Address</th><th>Company / branch</th><th>Last sync</th><th>Status</th><th class="actions"></th></tr></thead>
          <tbody>
          <?php foreach ($list as $d): $err = strpos((string)$d['last_status'], 'ERROR') === 0; ?>
            <tr class="<?= (int)$d['id'] === $id ? 'row-sel' : '' ?><?= $d['is_active'] ? '' : ' row-muted' ?>">
              <td><?= (int)$d['dev_no'] ?></td>
              <td><strong><?= h($d['name']) ?></strong><?= $d['is_active'] ? '' : '<br><small class="muted">inactive</small>' ?></td>
              <td><?= h($methodLabel[$d['conn_method'] ?? 'native'] ?? 'Built-in') ?><br><small class="muted"><?= $d['conn_type'] === 'COM' ? 'serial' : h(($d['conn_method'] ?? 'native') === 'native' ? strtoupper($d['protocol']) : 'zkemkeeper') ?></small></td>
              <td class="mono nowrap"><?= h($d['comm_address']) ?><?= $d['conn_type'] === 'NET' ? '<small class="muted">:' . h($d['comm_port']) . '</small>' : '' ?></td>
              <td><?= h($d['branch_code'] ?: '—') ?></td>
              <td class="small nowrap"><?= h($d['last_sync_at'] ? fmt_dt($d['last_sync_at']) : '—') ?></td>
              <td class="small bio-status<?= $err ? ' is-err' : '' ?>"><?= h(mb_strimwidth((string)($d['last_status'] ?: 'Not tested yet'), 0, 90, '…')) ?></td>
              <td class="actions"><a class="btn btn-sm" href="<?= h(url('bio_devices', ['id' => $d['id']])) ?>">Open</a></td>
            </tr>
          <?php endforeach; ?>
          <?= $list ? '' : empty_row(8, 'No terminal configured yet. Add one with the form.') ?>
          </tbody>
        </table>
      </div>
    </div>


<div class="grid grid-2 bio-layout">
  <div class="card bio-form">
    <div class="card-head"><div><h2><?= $rec ? h($rec['name']) : 'New device' ?></h2><div class="sub"><?= $rec ? 'Device ' . (int)$rec['dev_no'] . ($rec['device_info'] ? ', ' . h(mb_strimwidth((string)$rec['device_info'], 0, 70, '…')) : '') : 'Add a terminal' ?></div></div></div>
    <form method="post" id="bioForm">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
      <p class="label-line">Connection method</p>
      <div class="method-list">
        <?php foreach (ZK_METHODS as $k => $lbl): ?>
          <label class="access-mode"><input type="radio" name="conn_method" value="<?= $k ?>" <?= checked($method === $k) ?> data-bio-method>
            <span><strong><?= h($lbl) ?></strong><small><?= h(['native' => 'This server talks to the terminal on UDP / TCP port 4370. No DLL needed. The server must reach the terminal (same network or VPN).',
                'sdk' => $sdkWhy ? 'Not available here: ' . $sdkWhy : 'PHP calls zkemkeeper.dll through COM on this Windows server.',
                'agent' => 'For an HRIS on the internet: a Windows PC of the office reads the terminal and sends the logs with a device token.'][$k]) ?></small></span></label>
        <?php endforeach; ?>
      </div>
      <div class="form-grid cols-2 tight">
        <div class="field"><label>Dev ID (machine no.)</label><input type="number" min="1" name="dev_no" value="<?= $v('dev_no', (string)(count($list) + 1)) ?>" required></div>
        <div class="field"><label>Device name</label><input name="name" value="<?= $v('name') ?>" required maxlength="80" placeholder="Main gate"></div>
        <div class="field"><label>Conn. type</label><select name="conn_type"><?= options(['NET' => 'NET (Ethernet / Wi-Fi)', 'COM' => 'COM (serial)'], $rec['conn_type'] ?? 'NET') ?></select></div>
        <div class="field" data-bio-only="native"><label>Protocol</label><select name="protocol"><?= options(['udp' => 'UDP', 'tcp' => 'TCP'], $rec['protocol'] ?? 'udp') ?></select></div>
        <div class="field"><label>IP address</label><input name="comm_address" class="mono" value="<?= $v('comm_address') ?>" placeholder="192.168.1.201" required maxlength="60"></div>
        <div class="field"><label>Port</label><input name="comm_port" value="<?= $v('comm_port', '4370') ?>" inputmode="numeric" maxlength="20"></div>
        <div class="field wide"><label>Comm. key (0 = none)</label><input type="number" min="0" name="dev_password" value="<?= $v('dev_password', '0') ?>">
          <span class="help">The Comm Key of the terminal: Menu, COMM., Comm Key (some models: System, Comm. Password). It is not the administrator password.</span></div>
        <div class="field wide"><label>Company / branch of this device</label><select name="branch_id"><?= options(pairs("SELECT id, CONCAT(COALESCE(branch_code, code), ' — ', name) FROM branches WHERE is_active = 1 ORDER BY company_code, branch_code, code"), $rec['branch_id'] ?? null, 'None') ?></select></div>
        <div class="field wide"><label class="check"><input type="checkbox" name="is_active" value="1" <?= checked(!isset($rec['is_active']) || $rec['is_active']) ?>> Active</label>
          <label class="check"><input type="checkbox" name="auto_post" value="1" <?= checked(!empty($rec['auto_post'])) ?>> Post the logs at once when the agent or the scheduled download (tools/zk_download.php) brings them; otherwise they wait in Import Attendance Logs</label></div>
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" name="_action" value="save">Save device</button>
        <?php if ($rec && $method !== 'agent'): ?><button class="btn" name="_action" value="test" formnovalidate>Test connection</button><?php endif; ?>
        <?php if ($rec): ?><button class="btn btn-danger-outline" name="_action" value="delete" formnovalidate data-confirm="Delete the device <?= h($rec['name']) ?>? The logs already downloaded stay.">Delete</button><?php endif; ?>
      </div>
    </form>
    <?php if ($rec && $method !== 'agent' && $rec['conn_type'] === 'NET'): ?>
    <form method="post" class="bio-download">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
      <p class="label-line">Download logs</p>
      <div class="form-grid cols-2 tight">
        <div class="field"><label>Date from</label><input type="date" name="date_from" value="<?= h(date('Y-m-01')) ?>" required></div>
        <div class="field"><label>Date to</label><input type="date" name="date_to" value="<?= h(today()) ?>" required></div>
        <div class="field wide"><label class="check"><input type="checkbox" name="clear_after" value="1"> Clear the terminal log after the download</label></div>
      </div>
      <button class="btn btn-primary" name="_action" value="download">Download logs</button>
    </form>
    <?php endif; ?>
  </div>
  <div class="bio-main">
    <?php if (!$rec): ?>
    <div class="card">
      <h2>Connecting a ZKTeco terminal</h2>
      <ol class="agent-steps">
        <li>On the terminal note the IP address (Menu, COMM., Ethernet), the port (4370) and the Comm Key (Menu, COMM., Comm Key; 0 when none).</li>
        <li>HRIS on the office network: choose <strong>Built-in ZKTeco protocol</strong>. Nothing to install; the server talks to the terminal directly.</li>
        <li>HRIS on a Windows server with the ZKTeco SDK registered: <strong>ZKTeco SDK zkemkeeper.dll</strong> can be used instead.</li>
        <li>HRIS on the internet: choose <strong>Office PC agent</strong>, save, generate the token and install the agent package on a Windows PC of the office.</li>
        <li>Save, press Test connection, then match the user IDs of the terminal with the Biometric ID of each employee.</li>
      </ol>
    </div>
    <?php endif; ?>
    <?php if ($rec && $rec['last_status']): ?>
      <div class="callout <?= $isErr ? 'bad' : 'good' ?> bio-last"><strong><?= $isErr ? 'Last attempt failed' : 'Last result' ?></strong><span><?= h(preg_replace('/^ERROR:\s*/', '', (string)$rec['last_status'])) ?></span>
        <?php if ($rec['device_info'] && !$isErr): ?><small class="muted"><?= h($rec['device_info']) ?></small><?php endif; ?></div>
    <?php endif; ?>

    <?php if ($rec && $method === 'agent'): ?>
    <div class="card agent-card">
      <div class="card-head"><div><h2>Office PC agent</h2><div class="sub">A Windows PC of the office reads this terminal with zkemkeeper.dll and sends the logs to this address.</div></div></div>
      <div class="field"><label>Address the agent sends to</label>
        <div class="copy-row"><input id="pushUrl" class="mono" value="<?= h($pushUrl) ?>" readonly><button type="button" class="btn btn-sm" data-copy="pushUrl">Copy</button></div></div>
      <?php if ($newToken): ?>
        <div class="field"><label>Device token (copy it now, it is not shown again)</label>
          <div class="copy-row"><input id="pushToken" class="mono" value="<?= h($newToken) ?>" readonly><button type="button" class="btn btn-sm" data-copy="pushToken">Copy</button></div></div>
      <?php endif; ?>
      <dl class="kv agent-kv">
        <dt>Token</dt><dd><?= $rec['push_token_hash'] ? 'Set, ends with ' . h((string)$rec['push_token_hint']) : 'Not generated yet' ?></dd>
        <dt>Last logs received</dt><dd><?= h($rec['last_push_at'] ? fmt_dt($rec['last_push_at']) : 'Never') ?></dd>
        <dt>Received logs</dt><dd><?= $rec['auto_post'] ? 'Posted to the attendance records at once' : 'Kept in Import Attendance Logs for review' ?></dd>
      </dl>
      <div class="form-actions">
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
          <button class="btn btn-primary" name="_action" value="token"<?= $rec['push_token_hash'] ? ' data-confirm="Generate a new token? The office PC agent stops working until it gets the new token."' : '' ?>><?= $rec['push_token_hash'] ? 'Generate a new token' : 'Generate token' ?></button></form>
        <a class="btn" href="<?= h(url('bio_agent_file', ['id' => $id, 'f' => 'package'])) ?>">Download the agent package (.zip)</a>
        <?php if ($rec['push_token_hash']): ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-danger-outline" name="_action" value="revoke_token" data-confirm="Revoke the token? The office PC agent can no longer send logs.">Revoke token</button></form>
        <?php endif; ?>
      </div>
      <ol class="agent-steps">
        <li>Unzip the package on a Windows PC of the office network, for example to <code>C:\zkteco</code>. <?= $newToken ? 'It already contains this device and its token in <code>zk_agent.json</code>.' : 'Generate a token first so that <code>zk_agent.json</code> contains it, or paste the token in that file.' ?></li>
        <li>Copy the DLLs of the ZKTeco SDK (zkemkeeper.dll, zkemsdk.dll and the communication DLLs) into <code>C:\zkteco\sdk</code>, then right-click <code>register_sdk.bat</code> and choose Run as administrator.</li>
        <li>Run <code>zk_agent.bat -Test</code>, then <code>zk_agent.bat</code>. Schedule it every 15 minutes with Task Scheduler (the command is in README.txt).</li>
      </ol>
    </div>
    <?php endif; ?>

    <?php if ($testOut): ?>
    <div class="card flush">
      <div class="card-head pad"><div><h2>Users in the terminal</h2><div class="sub"><?= h($testOut['info']) ?>. Match each user ID with the Biometric ID of the employee record.</div></div>
        <input type="search" class="table-search" data-target="bioUsers" placeholder="Find user"></div>
      <div class="table-wrap table-scroll">
        <table class="table table-compact" id="bioUsers">
          <thead><tr><th>User ID</th><th>Name in the terminal</th><th>Employee in <?= h(app_name()) ?></th></tr></thead>
          <tbody>
          <?php $bioMap = [];
          foreach (rows("SELECT biometric_id, employee_no, last_name, first_name, middle_name, suffix FROM employees WHERE biometric_id IS NOT NULL AND biometric_id <> ''") as $e) {
              $bioMap[ZkClient::cleanId((string)$e['biometric_id'])] = full_name($e) . ' (' . $e['employee_no'] . ')';
          }
          foreach ($testOut['users'] as $uid => $name): ?>
            <tr class="<?= isset($bioMap[(string)$uid]) ? '' : 'row-warn' ?>"><td class="mono"><?= h((string)$uid) ?></td><td><?= h($name ?: '—') ?></td><td><?= h($bioMap[(string)$uid] ?? 'Not linked to an employee') ?></td></tr>
          <?php endforeach; ?>
          <?= $testOut['users'] ? '' : empty_row(3, 'No user registered in the terminal.') ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php page_footer();
