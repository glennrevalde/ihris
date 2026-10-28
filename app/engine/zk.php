<?php
/**
 * ZKTeco biometric terminals: three ways to get the attendance logs.
 *
 *   native  the built-in client below. It speaks the ZKTeco "ZKEM" protocol of the terminals (UDP or
 *           TCP, port 4370) straight from PHP, on any server (Linux or Windows), no DLL needed. It is
 *           the same protocol zkemkeeper.dll speaks; the packet layout, the communication-key
 *           scrambling and the buffered reads follow the ZKTeco SDK (commpro.c / zkemsdk.c).
 *   sdk     zkemkeeper.dll, the ZKTeco Standalone SDK (ActiveX / COM), used through PHP's COM
 *           extension. Only on a Windows server with the SDK registered (see tools/zkteco/README.txt).
 *   agent   the office PC agent (tools/zkteco/zk_agent.ps1) reads the terminal on the office network
 *           with zkemkeeper.dll and sends the logs to the HRIS over HTTPS with the device token, for an
 *           HRIS hosted on the internet that cannot reach the terminal (index.php?p=bio_push).
 *
 * Every driver returns logs as ['bio_id' => '12', 'time' => 'Y-m-d H:i:s', 'state' => 0..5, 'punch' => n].
 */

const ZK_CONNECT = 1000;
const ZK_EXIT = 1001;
const ZK_ENABLE = 1002;
const ZK_DISABLE = 1003;
const ZK_GET_VERSION = 1100;
const ZK_AUTH = 1102;
const ZK_ACK_OK = 2000;
const ZK_ACK_ERROR = 2001;
const ZK_ACK_DATA = 2002;
const ZK_ACK_UNAUTH = 2005;
const ZK_PREPARE_DATA = 1500;
const ZK_DATA = 1501;
const ZK_FREE_DATA = 1502;
const ZK_PREPARE_BUFFER = 1503;
const ZK_READ_BUFFER = 1504;
const ZK_USERTEMP_RRQ = 9;
const ZK_OPTIONS_RRQ = 11;
const ZK_ATTLOG_RRQ = 13;
const ZK_CLEAR_ATTLOG = 15;
const ZK_GET_FREE_SIZES = 50;
const ZK_GET_TIME = 201;
const ZK_FCT_USER = 5;
const ZK_TCP_HEADER = "\x50\x50\x82\x7d";
const ZK_USHRT_MAX = 65535;

/** How the system reaches a device. */
const ZK_METHODS = [
    'native' => 'Built-in ZKTeco protocol (any server)',
    'sdk' => 'ZKTeco SDK zkemkeeper.dll (Windows server)',
    'agent' => 'Office PC agent (HRIS on the internet)',
];

class ZkError extends RuntimeException
{
}

/** The built-in client (no DLL, no extension). */
class ZkClient
{
    private $sock = null;
    private string $host;
    private int $port;
    private bool $tcp;
    private int $commKey;
    private int $session = 0;
    private int $reply = 0;
    private float $timeout;
    private array $sizes = [];

    public function __construct(string $host, int $port = 4370, bool $tcp = false, int $commKey = 0, float $timeout = 8.0)
    {
        $this->host = trim($host);
        $this->port = $port ?: 4370;
        $this->tcp = $tcp;
        $this->commKey = max(0, $commKey);
        $this->timeout = $timeout;
    }

    public function method(): string
    {
        return 'Built-in protocol, ' . ($this->tcp ? 'TCP' : 'UDP');
    }

    /* ------------------------------------------------------------ packets */

    /** 16-bit one's complement sum of the packet, as zkemsdk computes it. */
    public static function checksum(string $p): int
    {
        $sum = 0;
        $len = strlen($p);
        for ($i = 0; $i + 1 < $len; $i += 2) {
            $sum += ord($p[$i]) | (ord($p[$i + 1]) << 8);
            if ($sum > ZK_USHRT_MAX) {
                $sum -= ZK_USHRT_MAX;
            }
        }
        if ($len % 2) {
            $sum += ord($p[$len - 1]);
        }
        while ($sum > ZK_USHRT_MAX) {
            $sum -= ZK_USHRT_MAX;
        }
        return (~$sum) & 0xFFFF;
    }

    /** Header + data. Each request carries the next reply number (the terminal echoes it). */
    private function packet(int $cmd, string $data = ''): string
    {
        $this->reply++;
        if ($this->reply >= ZK_USHRT_MAX) {
            $this->reply -= ZK_USHRT_MAX;
        }
        $buf = pack('vvvv', $cmd, 0, $this->session, $this->reply) . $data;
        $buf = pack('vvvv', $cmd, self::checksum($buf), $this->session, $this->reply) . $data;
        return $this->tcp ? ZK_TCP_HEADER . pack('V', strlen($buf)) . $buf : $buf;
    }

    /**
     * Communication key (Comm Key / Comm. Password of the terminal) scrambled with the session id,
     * as the SDK MakeKey() does: bit-reverse the key, add the session, XOR with "ZKSO", swap the two
     * words, then XOR with the tick byte (50) which also takes the third position.
     */
    public static function commKeyBytes(int $key, int $sessionId, int $ticks = 50): string
    {
        $k = 0;
        for ($i = 0; $i < 32; $i++) {
            $k = (($k << 1) | (($key >> $i) & 1)) & 0xFFFFFFFF;
        }
        $k = ($k + $sessionId) & 0xFFFFFFFF;
        $b = array_values(unpack('C4', pack('V', $k)));
        $b = [$b[0] ^ ord('Z'), $b[1] ^ ord('K'), $b[2] ^ ord('S'), $b[3] ^ ord('O')];
        $w = array_values(unpack('v2', pack('C4', ...$b)));
        $b = array_values(unpack('C4', pack('v2', $w[1], $w[0])));
        $t = $ticks & 0xFF;
        return pack('C4', $b[0] ^ $t, $b[1] ^ $t, $t, $b[3] ^ $t);
    }

    private function open(): void
    {
        if ($this->sock) {
            return;
        }
        if ($this->host === '') {
            throw new ZkError('Enter the IP address of the terminal.');
        }
        $err = 0;
        $msg = '';
        $url = ($this->tcp ? 'tcp://' : 'udp://') . $this->host . ':' . $this->port;
        $s = @stream_socket_client($url, $err, $msg, $this->timeout);
        if (!$s) {
            throw new ZkError("Cannot reach the terminal at {$this->host}:{$this->port}" . ($msg ? " ($msg)" : '') . '. Check the IP address and port, that the terminal is on, and that this server is on the same network (or VPN) as the terminal.');
        }
        stream_set_timeout($s, (int)$this->timeout, (int)(($this->timeout - (int)$this->timeout) * 1000000));
        $this->sock = $s;
    }

    /** Send a command and read its answer: [reply command, data]. */
    private function send(int $cmd, string $data = ''): array
    {
        $this->open();
        if (@fwrite($this->sock, $this->packet($cmd, $data)) === false) {
            throw new ZkError('The connection to the terminal was lost.');
        }
        return $this->receive();
    }

    /** Read one protocol packet: [command, data]. */
    private function receive(): array
    {
        $buf = $this->readPacket();
        if ($buf === null || strlen($buf) < 8) {
            throw new ZkError('The terminal did not answer in time. Check the IP address, the protocol (UDP or TCP) and the communication port, and that no other program is connected to the terminal right now.');
        }
        $h = unpack('vcmd/vchk/vsess/vreply', substr($buf, 0, 8));
        $this->reply = $h['reply'];
        return [$h['cmd'], (string)substr($buf, 8), $h['sess']];
    }

    private function readPacket(): ?string
    {
        if (!$this->tcp) {
            $buf = @fread($this->sock, 65536);
            return $buf === false || $buf === '' ? null : $buf;
        }
        $head = $this->readExactly(8);
        if ($head === null || strlen($head) < 8 || substr($head, 0, 4) !== ZK_TCP_HEADER) {
            return null;
        }
        $len = unpack('V', substr($head, 4, 4))[1];
        return $this->readExactly(max(0, min($len, 16 * 1024 * 1024)));
    }

    private function readExactly(int $n): ?string
    {
        $out = '';
        $deadline = microtime(true) + $this->timeout;
        while (strlen($out) < $n && microtime(true) < $deadline) {
            $chunk = @fread($this->sock, $n - strlen($out));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->sock);
                if (!empty($meta['timed_out']) || !empty($meta['eof'])) {
                    break;
                }
                usleep(10000);
                continue;
            }
            $out .= $chunk;
        }
        return $out === '' ? null : $out;
    }

    /* ------------------------------------------------------------ session */

    public function connect(): void
    {
        $this->session = 0;
        $this->reply = ZK_USHRT_MAX - 1;   // the first request goes out with reply number 0
        [$cmd, , $sess] = $this->send(ZK_CONNECT);
        $this->session = $sess;
        if ($cmd === ZK_ACK_UNAUTH) {
            [$cmd] = $this->send(ZK_AUTH, self::commKeyBytes($this->commKey, $this->session));
            if ($cmd === ZK_ACK_UNAUTH) {
                throw new ZkError('The terminal has a communication key and ' . ($this->commKey ? 'the key ' . $this->commKey . ' is not the one set in it' : 'no key was entered here (0)')
                    . '. On the terminal open Menu, COMM. (Communication), Comm Key (on some models System, Comm. Password) and enter that number in Comm. key here. It is not the administrator password of the terminal.');
            }
        }
        if ($cmd !== ZK_ACK_OK) {
            throw new ZkError('The terminal refused the connection (answer ' . $cmd . '). Check the device number and the communication port.');
        }
    }

    public function disconnect(): void
    {
        if (!$this->sock) {
            return;
        }
        try {
            @fwrite($this->sock, $this->packet(ZK_EXIT));
            $this->readPacket();
        } catch (Throwable $e) {
            // closing anyway
        }
        @fclose($this->sock);
        $this->sock = null;
    }

    public function disableDevice(): void
    {
        try {
            $this->send(ZK_DISABLE);
        } catch (ZkError $e) {
            // optional on some firmwares
        }
    }

    public function enableDevice(): void
    {
        try {
            $this->send(ZK_ENABLE);
        } catch (ZkError $e) {
            // ignore
        }
    }

    private function option(string $name): string
    {
        try {
            [$cmd, $data] = $this->send(ZK_OPTIONS_RRQ, $name . "\0");
        } catch (ZkError $e) {
            return '';
        }
        if ($cmd !== ZK_ACK_OK) {
            return '';
        }
        $data = trim(str_replace("\0", '', $data));
        $p = strpos($data, '=');
        return $p === false ? $data : trim(substr($data, $p + 1));
    }

    /** Model, serial number, firmware and clock of the terminal (best effort). */
    public function info(): array
    {
        $out = ['name' => $this->option('~DeviceName'), 'serial' => $this->option('~SerialNumber'), 'firmware' => '', 'time' => ''];
        try {
            [$cmd, $data] = $this->send(ZK_GET_VERSION);
            if ($cmd === ZK_ACK_OK) {
                $out['firmware'] = trim(str_replace("\0", '', $data));
            }
            [$cmd, $data] = $this->send(ZK_GET_TIME);
            if ($cmd === ZK_ACK_OK && strlen($data) >= 4) {
                $out['time'] = (string)self::decodeTime(unpack('V', substr($data, 0, 4))[1]);
            }
        } catch (ZkError $e) {
            // optional
        }
        $s = $this->sizes();
        $out['users'] = $s['users'] ?? null;
        $out['records'] = $s['records'] ?? null;
        return $out;
    }

    /** Memory usage of the terminal: users, fingers, records and capacities. */
    public function sizes(): array
    {
        if ($this->sizes) {
            return $this->sizes;
        }
        try {
            [$cmd, $data] = $this->send(ZK_GET_FREE_SIZES);
        } catch (ZkError $e) {
            return [];
        }
        if ($cmd !== ZK_ACK_OK || strlen($data) < 80) {
            return [];
        }
        $f = array_values(unpack('V20', substr($data, 0, 80)));
        return $this->sizes = ['users' => $f[4], 'fingers' => $f[6], 'records' => $f[8], 'cards' => $f[12], 'users_cap' => $f[15], 'records_cap' => $f[16]];
    }

    /* ------------------------------------------------------------ data */

    /** After PREPARE_DATA: the DATA packets up to ACK_OK. */
    private function collectData(string $prepared): string
    {
        $size = strlen($prepared) >= 4 ? unpack('V', substr($prepared, 0, 4))[1] : 0;
        $out = '';
        $deadline = microtime(true) + max(30, $this->timeout * 4);
        while (microtime(true) < $deadline) {
            [$cmd, $data] = $this->receive();
            if ($cmd === ZK_DATA) {
                $out .= $data;
                continue;
            }
            if ($cmd === ZK_ACK_OK) {
                break;
            }
            throw new ZkError('The terminal stopped sending data (answer ' . $cmd . ').');
        }
        if ($size && strlen($out) > $size) {
            $out = substr($out, 0, $size);
        }
        return $out;
    }

    /** Buffered read (CMD_PREPARE_BUFFER + CMD_READ_BUFFER chunks), with the old direct read as fallback. */
    private function readBuffer(int $command, int $fct = 0, int $ext = 0): string
    {
        [$cmd, $data] = $this->send(ZK_PREPARE_BUFFER, pack('c', 1) . pack('v', $command) . pack('V', $fct) . pack('V', $ext));
        if ($cmd === ZK_DATA) {
            return $data;
        }
        if ($cmd !== ZK_ACK_OK && $cmd !== ZK_PREPARE_DATA) {
            return $this->readDirect($command, $fct);
        }
        $size = strlen($data) >= 5 ? unpack('V', substr($data, 1, 4))[1] : 0;
        $max = $this->tcp ? 0xFFC0 : 16 * 1024;
        $out = '';
        for ($start = 0; $start < $size; $start += $max) {
            $len = min($max, $size - $start);
            $chunk = null;
            for ($try = 0; $try < 3 && $chunk === null; $try++) {
                [$cmd, $data] = $this->send(ZK_READ_BUFFER, pack('V', $start) . pack('V', $len));
                if ($cmd === ZK_DATA) {
                    $chunk = $data;
                } elseif ($cmd === ZK_PREPARE_DATA) {
                    $chunk = $this->collectData($data);
                }
            }
            if ($chunk === null) {
                throw new ZkError('The terminal did not send part of its data (bytes ' . $start . ' to ' . ($start + $len) . ').');
            }
            $out .= $chunk;
        }
        try {
            $this->send(ZK_FREE_DATA);
        } catch (ZkError $e) {
            // ignore
        }
        return $out;
    }

    /** Older firmwares: the command itself answers with PREPARE_DATA + DATA. */
    private function readDirect(int $command, int $fct = 0): string
    {
        [$cmd, $data] = $this->send($command, $fct ? pack('C', $fct) . str_repeat("\0", 4) : str_repeat("\0", 5));
        if ($cmd === ZK_PREPARE_DATA) {
            $out = $this->collectData($data);
            try {
                $this->send(ZK_FREE_DATA);
            } catch (ZkError $e) {
                // ignore
            }
            return $out;
        }
        if ($cmd === ZK_DATA || $cmd === ZK_ACK_OK) {
            return $data;
        }
        return '';
    }

    /** Users of the terminal: [['uid' => internal no., 'bio_id' => user ID, 'name' => ..., 'privilege' => n], ...] */
    public function userList(): array
    {
        $s = $this->sizes();
        if ($s && !$s['users']) {
            return [];
        }
        $raw = $this->readBuffer(ZK_USERTEMP_RRQ, ZK_FCT_USER);
        if (strlen($raw) <= 4) {
            return [];
        }
        $total = unpack('V', substr($raw, 0, 4))[1];
        $raw = substr($raw, 4);
        $count = $s['users'] ?? 0;
        $size = $count ? (int)round($total / $count) : 0;
        if (!in_array($size, [28, 72], true)) {
            $size = strlen($raw) % 72 === 0 ? 72 : 28;
        }
        $out = [];
        for ($i = 0; $i + $size <= strlen($raw); $i += $size) {
            $r = substr($raw, $i, $size);
            if ($size === 28) {
                // uid(2) privilege(1) password(5) name(8) card(4) pad(1) group(1) timezone(2) user id(4)
                $uid = unpack('v', substr($r, 0, 2))[1];
                $name = self::cstr(substr($r, 8, 8));
                $userId = (string)unpack('V', substr($r, 24, 4))[1];
                $priv = ord($r[2]);
            } else {
                // uid(2) privilege(1) password(8) name(24) card(4) pad(1) group(7) pad(1) user id(24)
                $uid = unpack('v', substr($r, 0, 2))[1];
                $priv = ord($r[2]);
                $name = self::cstr(substr($r, 11, 24));
                $userId = self::cstr(substr($r, 48, 24));
            }
            $out[] = ['uid' => $uid, 'bio_id' => $userId, 'name' => $name, 'privilege' => $priv];
        }
        return $out;
    }

    /** [bio_id => name] */
    public function users(): array
    {
        $out = [];
        foreach ($this->userList() as $u) {
            if ($u['bio_id'] !== '') {
                $out[self::cleanId($u['bio_id'])] = $u['name'];
            }
        }
        return $out;
    }

    /** Attendance records of the terminal. */
    public function attendance(): array
    {
        $s = $this->sizes();
        if ($s && !$s['records']) {
            return [];
        }
        $raw = $this->readBuffer(ZK_ATTLOG_RRQ);
        if (strlen($raw) < 4) {
            return [];
        }
        $total = unpack('V', substr($raw, 0, 4))[1];
        if ($total === strlen($raw) - 4) {
            $raw = substr($raw, 4);
        } else {
            $total = strlen($raw);
        }
        $records = $s['records'] ?? 0;
        $size = $records ? (int)round($total / $records) : 0;
        if (!in_array($size, [8, 16, 40], true)) {
            foreach ([40, 16, 8] as $c) {
                if (strlen($raw) % $c === 0) {
                    $size = $c;
                    break;
                }
            }
        }
        $uidMap = [];
        if ($size === 8) {
            // the short record carries the internal user number: map it to the user ID
            foreach ($this->userList() as $u) {
                $uidMap[$u['uid']] = $u['bio_id'];
            }
        }
        $out = [];
        for ($i = 0; $i + $size <= strlen($raw); $i += $size) {
            $r = substr($raw, $i, $size);
            if ($size === 8) {
                // uid(2) status(1) time(4) punch(1)
                $uid = unpack('v', substr($r, 0, 2))[1];
                $user = (string)($uidMap[$uid] ?? $uid);
                $state = ord($r[2]);
                $ts = unpack('V', substr($r, 3, 4))[1];
                $punch = ord($r[7]);
            } elseif ($size === 16) {
                // user id(4) time(4) status(1) punch(1) reserved(2) work code(4)
                $user = (string)unpack('V', substr($r, 0, 4))[1];
                $ts = unpack('V', substr($r, 4, 4))[1];
                $state = ord($r[8]);
                $punch = ord($r[9]);
            } else {
                // uid(2) user id(24) status(1) time(4) punch(1) reserved(8)
                $user = self::cstr(substr($r, 2, 24));
                $state = ord($r[26]);
                $ts = unpack('V', substr($r, 27, 4))[1];
                $punch = ord($r[31]);
            }
            $time = self::decodeTime($ts);
            if ($time && $user !== '') {
                $out[] = ['bio_id' => self::cleanId($user), 'time' => $time, 'state' => $state, 'punch' => $punch];
            }
        }
        return $out;
    }

    public function clearAttendance(): void
    {
        $this->send(ZK_CLEAR_ATTLOG);
    }

    private static function cstr(string $s): string
    {
        $p = strpos($s, "\0");
        return trim($p === false ? $s : substr($s, 0, $p));
    }

    public static function cleanId(string $id): string
    {
        $id = trim($id);
        return ltrim($id, '0') !== '' ? ltrim($id, '0') : $id;
    }

    /** ZK packed timestamp -> 'Y-m-d H:i:s'. */
    public static function decodeTime(int $t): ?string
    {
        if ($t <= 0) {
            return null;
        }
        $second = $t % 60;
        $t = intdiv($t, 60);
        $minute = $t % 60;
        $t = intdiv($t, 60);
        $hour = $t % 24;
        $t = intdiv($t, 24);
        $day = $t % 31 + 1;
        $t = intdiv($t, 31);
        $month = $t % 12 + 1;
        $t = intdiv($t, 12);
        $year = $t + 2000;
        if ($year < 2000 || $year > 2099 || !checkdate($month, $day, $year)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
    }

    public static function encodeTime(string $dt): int
    {
        $t = strtotime($dt);
        return ((((((int)date('Y', $t) - 2000) * 12 + (int)date('n', $t) - 1) * 31 + (int)date('j', $t) - 1) * 24 + (int)date('G', $t)) * 60 + (int)date('i', $t)) * 60 + (int)date('s', $t);
    }
}

/**
 * zkemkeeper.dll (ZKTeco Standalone SDK, COM class CZKEM) through PHP's COM extension.
 * Needs PHP on Windows with php_com_dotnet enabled and the SDK registered; see tools/zkteco/README.txt.
 */
class ZkSdkClient
{
    const CLSID = '{00853A19-BD51-419B-9269-2DABE57EB61F}';
    const PROGIDS = ['zkemkeeper.ZKEM.1', 'zkemkeeper.ZKEM', self::CLSID];
    private $zk = null;
    private string $host;
    private int $port;
    private int $commKey;
    private int $machine;

    public function __construct(string $host, int $port = 4370, int $commKey = 0, int $machine = 1)
    {
        $this->host = trim($host);
        $this->port = $port ?: 4370;
        $this->commKey = max(0, $commKey);
        $this->machine = max(1, $machine);
    }

    public function method(): string
    {
        return 'ZKTeco SDK (zkemkeeper.dll)';
    }

    /** Is the SDK usable from this PHP? Returns null when yes, or the reason. */
    public static function unavailable(): ?string
    {
        if (stripos(PHP_OS, 'WIN') !== 0) {
            return 'The ZKTeco SDK (zkemkeeper.dll) only runs on Windows; this server runs ' . PHP_OS . '. Use the built-in protocol, or the office PC agent.';
        }
        if (!class_exists('COM')) {
            return 'PHP\'s COM extension is off. Add extension=com_dotnet to php.ini and restart the web server.';
        }
        return null;
    }

    private function sdkError(string $what): ZkError
    {
        $code = 0;
        try {
            $this->zk->GetLastError($code);
        } catch (Throwable $e) {
            $code = 0;
        }
        $known = [-1 => 'the SDK is not connected', -2 => 'read / write error', -3 => 'wrong data size', -4 => 'not enough space',
            -5 => 'the data already exists', -100 => 'not supported or no data', 0 => 'no data', 4 => 'wrong parameter'];
        return new ZkError($what . ' (zkemkeeper error ' . $code . (isset($known[$code]) ? ', ' . $known[$code] : '') . ').');
    }

    public function connect(): void
    {
        if ($why = self::unavailable()) {
            throw new ZkError($why);
        }
        $last = '';
        foreach (self::PROGIDS as $id) {
            try {
                $this->zk = new COM($id);
                break;
            } catch (Throwable $e) {
                $last = $e->getMessage();
            }
        }
        if (!$this->zk) {
            throw new ZkError('zkemkeeper.dll is not registered on this server (' . trim($last) . '). Run tools/zkteco/register_sdk.bat as administrator. With 64-bit PHP also run the "64-bit PHP" step of tools/zkteco/README.txt.');
        }
        if ($this->commKey) {
            $this->zk->SetCommPassword($this->commKey);
        }
        if (!$this->zk->Connect_Net($this->host, $this->port)) {
            throw $this->sdkError('zkemkeeper could not connect to ' . $this->host . ':' . $this->port . '. Check the IP address, the port and the Comm Key of the terminal');
        }
    }

    public function disconnect(): void
    {
        if ($this->zk) {
            try {
                $this->zk->EnableDevice($this->machine, true);
                $this->zk->Disconnect();
            } catch (Throwable $e) {
            }
            $this->zk = null;
        }
    }

    public function disableDevice(): void
    {
        try {
            $this->zk->EnableDevice($this->machine, false);
        } catch (Throwable $e) {
        }
    }

    public function enableDevice(): void
    {
        try {
            $this->zk->EnableDevice($this->machine, true);
        } catch (Throwable $e) {
        }
    }

    public function info(): array
    {
        $sn = '';
        $fw = '';
        $y = $mo = $d = $h = $mi = $s = 0;
        try {
            $this->zk->GetSerialNumber($this->machine, $sn);
            $this->zk->GetFirmwareVersion($this->machine, $fw);
            $this->zk->GetDeviceTime($this->machine, $y, $mo, $d, $h, $mi, $s);
        } catch (Throwable $e) {
        }
        return ['name' => '', 'serial' => (string)$sn, 'firmware' => (string)$fw, 'time' => $y ? sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $h, $mi, $s) : '', 'users' => null, 'records' => null];
    }

    public function users(): array
    {
        $out = [];
        if (!$this->zk->ReadAllUserID($this->machine)) {
            return $out;
        }
        $id = '';
        $name = '';
        $pw = '';
        $priv = 0;
        $en = false;
        try {
            while ($this->zk->SSR_GetAllUserInfo($this->machine, $id, $name, $pw, $priv, $en)) {
                $out[ZkClient::cleanId((string)$id)] = trim((string)$name);
            }
        } catch (Throwable $e) {
            // black & white terminals without SSR functions
            $num = 0;
            while ($this->zk->GetAllUserInfo($this->machine, $num, $name, $pw, $priv, $en)) {
                $out[(string)$num] = trim((string)$name);
            }
        }
        return $out;
    }

    public function attendance(): array
    {
        $out = [];
        if (!$this->zk->ReadGeneralLogData($this->machine)) {
            return $out;   // no log, or an error: GetLastError 0 means no data
        }
        $id = '';
        $verify = $inout = $y = $mo = $d = $h = $mi = $s = $work = 0;
        try {
            while ($this->zk->SSR_GetGeneralLogData($this->machine, $id, $verify, $inout, $y, $mo, $d, $h, $mi, $s, $work)) {
                $out[] = ['bio_id' => ZkClient::cleanId((string)$id), 'time' => sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $h, $mi, $s), 'state' => (int)$inout, 'punch' => (int)$verify];
            }
        } catch (Throwable $e) {
            $tm = $num = $em = 0;
            while ($this->zk->GetGeneralLogData($this->machine, $tm, $num, $em, $verify, $inout, $y, $mo, $d, $h, $mi)) {
                $out[] = ['bio_id' => (string)$num, 'time' => sprintf('%04d-%02d-%02d %02d:%02d:00', $y, $mo, $d, $h, $mi), 'state' => (int)$inout, 'punch' => (int)$verify];
            }
        }
        return $out;
    }

    public function clearAttendance(): void
    {
        if (!$this->zk->ClearGLog($this->machine)) {
            throw $this->sdkError('The terminal log could not be cleared');
        }
        $this->zk->RefreshData($this->machine);
    }
}

/** The driver of a configured device (native or SDK). The agent method has no driver on the server. */
function zk_client(array $dev)
{
    $method = $dev['conn_method'] ?? 'native';
    if ($method === 'agent') {
        throw new ZkError('This device is read by the office PC agent, which sends the logs by itself. Use Download logs on the agent PC, or change the connection method.');
    }
    if (($dev['conn_type'] ?? 'NET') === 'COM') {
        throw new ZkError('Serial (COM) terminals cannot be read from the web server. Connect the terminal to the network, or export the log from the terminal and import the file.');
    }
    if ($method === 'sdk') {
        return new ZkSdkClient((string)$dev['comm_address'], (int)$dev['comm_port'] ?: 4370, (int)$dev['dev_password'], (int)$dev['dev_no'] ?: 1);
    }
    return new ZkClient((string)$dev['comm_address'], (int)$dev['comm_port'] ?: 4370, ($dev['protocol'] ?? 'udp') === 'tcp', (int)$dev['dev_password']);
}

/** One line describing the terminal from info(). */
function zk_info_text(array $i): string
{
    $parts = array_filter([
        $i['name'] ?? '',
        !empty($i['serial']) ? 'S/N ' . $i['serial'] : '',
        !empty($i['firmware']) ? $i['firmware'] : '',
        isset($i['users']) && $i['users'] !== null ? $i['users'] . ' user(s)' : '',
        isset($i['records']) && $i['records'] !== null ? $i['records'] . ' log(s)' : '',
        !empty($i['time']) ? 'clock ' . $i['time'] : '',
    ]);
    return $parts ? implode(', ', $parts) : 'ZKTeco terminal';
}

/**
 * Put downloaded or pushed logs in the import staging area (Import Attendance Logs), and post them to
 * the attendance records at once when asked. Returns the staging counts.
 */
function bio_logs_stage(array $dev, array $logs, string $source, string $info = '', bool $post = false): array
{
    $batch = insert('import_batches', ['batch_type' => 'device', 'file_name' => ($source === 'agent' ? 'agent:' : 'device:') . $dev['name'] . ' (' . $dev['comm_address'] . ')',
        'branch_id' => $dev['branch_id'], 'total_rows' => count($logs), 'uploaded_by' => user_id()]);
    $rows = [];
    $n = 0;
    foreach ($logs as $l) {
        $rows[] = ['line_no' => ++$n, 'bio_id' => (string)$l['bio_id'], 'log_time' => $l['time'],
            'punch_type' => zk_punch_type((int)$l['state']), 'device_id' => (string)$dev['dev_no'], 'branch_id' => $dev['branch_id'],
            'raw_text' => sprintf('%s %s state=%d verify=%d', $l['bio_id'], $l['time'], (int)$l['state'], (int)($l['punch'] ?? 0))];
    }
    $st = staging_insert($rows, 'device', $batch);
    update('import_batches', ['imported' => $st['staged'], 'duplicates' => $st['duplicates'], 'flagged' => $st['errors']], 'id = ?', [$batch]);
    $posted = null;
    if ($post && $st['staged']) {
        $posted = staging_post($batch);
    }
    return array_merge($st, ['read' => count($logs), 'batch' => $batch, 'posted' => $posted['posted'] ?? 0]);
}

/**
 * Download the attendance log of a configured device into the import staging area.
 * v22: $since / $until (Date From / Date To, YYYY-MM-DD or a date-time) keep only the logs of those dates; the device
 * log is cleared after the download only when every log read was inside the dates (none is lost).
 * Returns ['read' => n, 'staged' => n, 'duplicates' => n, 'info' => '...', 'batch' => id, 'outside' => n, 'cleared' => bool].
 */
function bio_device_download(array $dev, ?string $since = null, bool $clearAfter = false, ?string $until = null): array
{
    $from = $since ? str_replace('T', ' ', $since) : null;
    if ($from && strlen($from) === 10) {
        $from .= ' 00:00:00';
    }
    $to = $until ? str_replace('T', ' ', $until) : null;
    if ($to && strlen($to) === 10) {
        $to .= ' 23:59:59';
    }
    $inRange = fn($l) => (!$from || $l['time'] >= $from) && (!$to || $l['time'] <= $to);
    $zk = zk_client($dev);
    $info = '';
    $cleared = false;
    $outside = 0;
    try {
        $zk->connect();
        $info = zk_info_text($zk->info());
        $zk->disableDevice();
        $logs = $zk->attendance();
        $outside = count(array_filter($logs, fn($l) => !$inRange($l)));
        if ($clearAfter && $logs && !$outside) {
            $zk->clearAttendance();
            $cleared = true;
        }
        $zk->enableDevice();
    } finally {
        $zk->disconnect();
    }
    $read = count($logs);
    if ($from || $to) {
        $logs = array_values(array_filter($logs, $inRange));
    }
    $st = bio_logs_stage($dev, $logs, 'device', $info);
    update('bio_devices', ['last_sync_at' => now(), 'device_info' => mb_substr($info, 0, 255),
        'last_status' => mb_substr(sprintf('%d log(s) read, %d in the dates, %d staged, %d duplicate(s).', $read, count($logs), $st['staged'], $st['duplicates']), 0, 255)], 'id = ?', [$dev['id']]);
    audit('ams', 'device_download', 'bio_devices', (int)$dev['id'], ['read' => $read, 'in_dates' => count($logs), 'staged' => $st['staged'], 'from' => $from, 'to' => $to,
        'cleared' => $cleared, 'method' => $dev['conn_method'] ?? 'native']);
    return array_merge($st, ['info' => $info, 'outside' => $outside, 'cleared' => $cleared, 'read_device' => $read]);
}

/** Map the ZK "state" byte to a punch type. */
function zk_punch_type(int $state): string
{
    switch ($state) {
        case 0:
            return 'in';
        case 1:
            return 'out';
        case 2:
        case 4:
            return 'break_out';
        case 3:
        case 5:
            return 'break_in';
        default:
            return 'unknown';
    }
}

/** Address of the HRIS as the browser sees it (the agent uses the same one), else the configured app_url. */
function bio_public_base(): string
{
    if (!empty($_SERVER['HTTP_HOST'])) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
        return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $dir;
    }
    return rtrim((string)cfg('app_url'), '/');
}

/** A new push token for the office PC agent (shown once; only its hash is kept). */
function bio_new_token(): string
{
    return 'zk_' . bin2hex(random_bytes(20));
}

function bio_token_hash(string $token): string
{
    return hash('sha256', $token);
}
