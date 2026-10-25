<#
  G-SMaRT HRIS - ZKTeco office PC agent
  -------------------------------------
  Reads the attendance logs of the ZKTeco terminals on the office network with the ZKTeco SDK
  (zkemkeeper.dll) and sends them to the HRIS over HTTPS. Use it when the HRIS is hosted on the
  internet and cannot reach the terminal (Biometric Device Setup, connection method "Office PC agent").

  Run it with zk_agent.bat (it starts the 32-bit PowerShell that the 32-bit zkemkeeper.dll needs).
    zk_agent.bat           read every terminal of zk_agent.json and send the new logs
    zk_agent.bat -Test     connect, show the terminal information and the HRIS answer, send nothing

  Settings: zk_agent.json next to this file (downloaded from Biometric Device Setup, with the token).
  Every run is written to zk_agent.log. Schedule it with Task Scheduler (see README.txt).
#>
param(
    [string]$Config = (Join-Path $PSScriptRoot 'zk_agent.json'),
    [switch]$Test
)

$ErrorActionPreference = 'Stop'
$AgentVersion = 'zk_agent.ps1 1.0'
$LogFile = Join-Path $PSScriptRoot 'zk_agent.log'
$Clsid = '{00853A19-BD51-419B-9269-2DABE57EB61F}'

function Write-Log([string]$msg) {
    $line = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss') + '  ' + $msg
    Write-Host $line
    try { Add-Content -Path $LogFile -Value $line -Encoding UTF8 } catch { }
}

function New-Zkem {
    foreach ($id in @('zkemkeeper.ZKEM.1', 'zkemkeeper.ZKEM')) {
        try { return New-Object -ComObject $id } catch { }
    }
    try { return [Activator]::CreateInstance([Type]::GetTypeFromCLSID([Guid]$Clsid)) } catch { }
    throw 'zkemkeeper.dll is not registered on this PC. Copy the ZKTeco SDK DLLs into the sdk folder and run register_sdk.bat as administrator.'
}

function Invoke-Hris([string]$url, [string]$token, [string]$method, $body) {
    $headers = @{ Authorization = 'Bearer ' + $token; 'X-Device-Token' = $token }
    if ($method -eq 'GET') {
        return Invoke-RestMethod -Uri $url -Method Get -Headers $headers -TimeoutSec 60
    }
    $json = $body | ConvertTo-Json -Depth 6 -Compress
    $bytes = [Text.Encoding]::UTF8.GetBytes($json)
    return Invoke-RestMethod -Uri $url -Method Post -Headers $headers -ContentType 'application/json; charset=utf-8' -Body $bytes -TimeoutSec 300
}

function Read-Terminal($dev) {
    $zk = New-Zkem
    $machine = 1
    if ($dev.machine) { $machine = [int]$dev.machine }
    $port = 4370
    if ($dev.port) { $port = [int]$dev.port }
    if ($dev.comm_key -and [int]$dev.comm_key -gt 0) { [void]$zk.SetCommPassword([int]$dev.comm_key) }
    if (-not $zk.Connect_Net([string]$dev.ip, $port)) {
        $err = 0
        [void]$zk.GetLastError([ref]$err)
        throw ('Cannot connect to the terminal ' + $dev.ip + ':' + $port + ' (zkemkeeper error ' + $err + '). Check the IP address, the port and the Comm Key of the terminal.')
    }
    $info = @{ serial = ''; firmware = '' }
    $logs = New-Object System.Collections.ArrayList
    try {
        $sn = ''; $fw = ''
        try { [void]$zk.GetSerialNumber($machine, [ref]$sn); $info.serial = $sn } catch { }
        try { [void]$zk.GetFirmwareVersion($machine, [ref]$fw); $info.firmware = $fw } catch { }
        [void]$zk.EnableDevice($machine, $false)
        if ($zk.ReadGeneralLogData($machine)) {
            $id = ''; $verify = 0; $inout = 0; $y = 0; $mo = 0; $d = 0; $h = 0; $mi = 0; $s = 0; $work = 0
            $ssr = $true
            try {
                while ($zk.SSR_GetGeneralLogData($machine, [ref]$id, [ref]$verify, [ref]$inout, [ref]$y, [ref]$mo, [ref]$d, [ref]$h, [ref]$mi, [ref]$s, [ref]$work)) {
                    [void]$logs.Add(@{ bio_id = [string]$id; time = ('{0:0000}-{1:00}-{2:00} {3:00}:{4:00}:{5:00}' -f $y, $mo, $d, $h, $mi, $s); state = [int]$inout; verify = [int]$verify })
                }
            } catch { $ssr = $false }
            if (-not $ssr -and $logs.Count -eq 0) {
                # black & white terminals: the numeric functions
                [void]$zk.ReadGeneralLogData($machine)
                $tm = 0; $num = 0; $em = 0
                while ($zk.GetGeneralLogData($machine, [ref]$tm, [ref]$num, [ref]$em, [ref]$verify, [ref]$inout, [ref]$y, [ref]$mo, [ref]$d, [ref]$h, [ref]$mi)) {
                    [void]$logs.Add(@{ bio_id = [string]$num; time = ('{0:0000}-{1:00}-{2:00} {3:00}:{4:00}:00' -f $y, $mo, $d, $h, $mi); state = [int]$inout; verify = [int]$verify })
                }
            }
        }
    } finally {
        [void]$zk.EnableDevice($machine, $true)
    }
    $clearLog = [bool]($dev.clear_after_push -eq $true) -and -not $Test
    return @{ zk = $zk; machine = $machine; info = $info; logs = $logs; clear_log = $clearLog }
}

if ([IntPtr]::Size -ne 4) {
    Write-Log 'Note: this is 64-bit PowerShell. The 32-bit zkemkeeper.dll needs the 32-bit one: start the agent with zk_agent.bat.'
}
[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12

if (-not (Test-Path $Config)) {
    Write-Log ('Settings file not found: ' + $Config + '. Download zk_agent.json from Biometric Device Setup.')
    exit 2
}
$cfg = Get-Content -Path $Config -Raw | ConvertFrom-Json
$batchSize = 2000
if ($cfg.batch_size) { $batchSize = [Math]::Max(100, [Math]::Min(5000, [int]$cfg.batch_size)) }
$failed = 0

foreach ($dev in $cfg.devices) {
    $url = $cfg.hris_url
    if ($dev.hris_url) { $url = $dev.hris_url }
    $name = [string]$dev.name
    try {
        $state = Invoke-Hris $url $dev.token 'GET' $null
        $since = [string]$state.since
        Write-Log ("[$name] HRIS device '" + $state.device + "', last log received: " + ($(if ($since) { $since } else { 'none yet' })))
        $read = Read-Terminal $dev
        $all = $read.logs
        $new = @($all | Where-Object { -not $since -or $_.time -gt $since } | Sort-Object { $_.time })
        Write-Log ("[$name] terminal S/N " + $read.info.serial + ', ' + $all.Count + ' log(s) in the terminal, ' + $new.Count + ' new')
        if ($Test) {
            if ($new.Count) { Write-Log ("[$name] first new log: " + $new[0].bio_id + ' ' + $new[0].time + ' (test mode: nothing sent)') }
            [void]$read.zk.Disconnect()
            continue
        }
        $sent = 0
        for ($i = 0; $i -lt $new.Count; $i += $batchSize) {
            $part = @($new[$i..([Math]::Min($i + $batchSize, $new.Count) - 1)])
            $res = Invoke-Hris $url $dev.token 'POST' @{ agent = $AgentVersion; device = $read.info; logs = $part }
            $sent += $part.Count
            Write-Log ("[$name] sent " + $part.Count + ': ' + $res.staged + ' staged, ' + $res.duplicates + ' duplicate(s), ' + $res.errors + ' not matched, ' + $res.posted + ' posted')
        }
        if ($sent -eq 0) {
            [void](Invoke-Hris $url $dev.token 'POST' @{ agent = $AgentVersion; device = $read.info; logs = @() })
        }
        if ($read.clear_log -and $sent -gt 0) {
            if ($read.zk.ClearGLog($read.machine)) { [void]$read.zk.RefreshData($read.machine); Write-Log "[$name] terminal log cleared" }
        }
        [void]$read.zk.Disconnect()
    } catch {
        $failed++
        $msg = $_.Exception.Message
        if ($_.ErrorDetails -and $_.ErrorDetails.Message) { $msg = $msg + ' ' + $_.ErrorDetails.Message }
        Write-Log ("[$name] ERROR: " + $msg)
    }
}
if ($failed) { exit 1 }
exit 0
