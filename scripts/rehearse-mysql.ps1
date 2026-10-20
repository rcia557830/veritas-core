param([string]$MySqlBin='C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin')
$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
$token=[guid]::NewGuid().ToString('N')
$artifacts=Join-Path $root "storage/app/private/rehearsal-$token"
$data=Join-Path $artifacts 'mysql-data'
New-Item -ItemType Directory -Path $data -Force | Out-Null
$listener=[System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback,0)
$listener.Start(); $port=$listener.LocalEndpoint.Port; $listener.Stop()
if ($port -lt 20000) { throw 'High port required.' }
$variables=@{APP_ENV='testing'; DB_CONNECTION='mysql'; DB_HOST='127.0.0.1'; DB_PORT="$port"; DB_DATABASE="veritas_rehearsal_$token"; DB_USERNAME='root'; DB_PASSWORD=''; DB_URL='null'; DB_SOCKET='null'; CACHE_STORE='array'; QUEUE_CONNECTION='sync'; MAIL_MAILER='array'; VERITAS_REHEARSAL_TOKEN=$token; VERITAS_REHEARSAL_DATADIR=$data; VERITAS_REHEARSAL_REPORT="$artifacts/report.json"}
$previous=@{}
function Checked([string]$program,[string[]]$arguments) { & $program @arguments; if ($LASTEXITCODE -ne 0) { throw "$program failed: $LASTEXITCODE" } }
Push-Location $root
try {
    $audit = & php scripts/audit-transfer.php
    if ($LASTEXITCODE -ne 0) { throw 'Source integrity audit failed.' }
    $audit | Set-Content -LiteralPath "$artifacts/source-audit.json" -Encoding UTF8
    foreach($key in $variables.Keys) { $previous[$key]=[Environment]::GetEnvironmentVariable($key,'Process'); [Environment]::SetEnvironmentVariable($key,$variables[$key],'Process') }
    $config=Join-Path $artifacts 'mysql.ini'
    @"
[mysqld]
basedir=$($MySqlBin.Replace('\','/'))/..
datadir=$($data.Replace('\','/'))
bind-address=127.0.0.1
port=$port
mysqlx=0
skip-log-bin
log-error=$($artifacts.Replace('\','/'))/mysql.log
"@ | Set-Content -LiteralPath $config
    Checked "$MySqlBin/mysqld.exe" @("--defaults-file=$config",'--initialize-insecure')
    $server=Start-Process "$MySqlBin/mysqld.exe" -ArgumentList "--defaults-file=$config" -WindowStyle Hidden -PassThru
    $ready=$false
    for($i=0;$i -lt 60;$i++) {
        $ErrorActionPreference='Continue'
        & "$MySqlBin/mysqladmin.exe" --protocol=tcp --host=127.0.0.1 "--port=$port" --user=root ping 2>$null | Out-Null
        $ErrorActionPreference='Stop'
        if ($LASTEXITCODE -eq 0) { $ready=$true; break }; Start-Sleep -Milliseconds 500
    }
    if (!$ready) { throw 'MySQL startup failed.' }
    Checked "$MySqlBin/mysql.exe" @('--protocol=tcp','--host=127.0.0.1',"--port=$port",'--user=root','-e',"CREATE DATABASE veritas_rehearsal_$token CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
    Checked 'php' @('scripts/rehearse-mysql.php')
    if (!(Test-Path -LiteralPath "$artifacts/report.json")) { throw 'Conversion did not produce a success report.' }
    $report=Get-Content -LiteralPath "$artifacts/report.json" -Raw | ConvertFrom-Json
    if (!$report.all_rows_match -or !$report.password_hashes_exact -or !$report.source_unchanged -or $report.immutability_probes_passed -ne 3) { throw 'Conversion verification incomplete.' }
    Checked "$MySqlBin/mysqldump.exe" @('--protocol=tcp','--host=127.0.0.1',"--port=$port",'--user=root','--single-transaction','--no-tablespaces','--set-gtid-purged=OFF','--hex-blob',"--result-file=$artifacts/converted.sql","veritas_rehearsal_$token")
} finally {
    if ($server) {
        $ErrorActionPreference='Continue'
        $actual=& "$MySqlBin/mysql.exe" --protocol=tcp --host=127.0.0.1 "--port=$port" --user=root --raw -N -B -e 'SELECT @@datadir' 2>$null
        if ($LASTEXITCODE -eq 0 -and $actual.Replace('\','/').TrimEnd('/') -eq $data.Replace('\','/').TrimEnd('/')) { & "$MySqlBin/mysqladmin.exe" --protocol=tcp --host=127.0.0.1 "--port=$port" --user=root shutdown }
        $ErrorActionPreference='Stop'
    }
    foreach($key in $previous.Keys) { [Environment]::SetEnvironmentVariable($key,$previous[$key],'Process') }
    Pop-Location
    Write-Output "Rehearsal artifacts: $artifacts"
}
