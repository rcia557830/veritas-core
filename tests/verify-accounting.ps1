param(
    [string]$MySqlBin = 'C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin',
    [string]$Browser = 'C:/Program Files/Google/Chrome/Application/chrome.exe',
    [switch]$SkipBrowser,
    [switch]$BrowserOnly
)
$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$token = [guid]::NewGuid().ToString('N')
$database = 'veritas_stage21_' + $token.Substring(0, 16)
$artifacts = Join-Path $root "storage/app/private/increment32-$token"
$data = Join-Path $artifacts 'mysql-data'
New-Item -ItemType Directory -Path $data -Force | Out-Null
$listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, 0)
$listener.Start()
$port = $listener.LocalEndpoint.Port
$listener.Stop()
if ($port -lt 20000) { throw 'Expected a disposable high port.' }
$variables = @{ APP_ENV='testing'; DB_CONNECTION='mysql'; DB_HOST='127.0.0.1'; DB_PORT="$port"; DB_DATABASE=$database; DB_USERNAME='veritas_stage21_test'; DB_PASSWORD=$token; DB_URL='null'; DB_SOCKET='null'; VERITAS_TEST_TOKEN=$token; VERITAS_TEST_DATADIR=$data; BCRYPT_ROUNDS='4'; CACHE_STORE='array'; MAIL_MAILER='array'; QUEUE_CONNECTION='sync' }
$previous = @{}
$server = $null
$web = $null
function Run-Checked([string]$program, [string[]]$arguments) {
    & $program @arguments
    if ($LASTEXITCODE -ne 0) { throw "$program failed with exit code $LASTEXITCODE" }
}
Push-Location $root
try {
    foreach ($name in $variables.Keys) {
        $previous[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
        [Environment]::SetEnvironmentVariable($name, $variables[$name], 'Process')
    }
    $config = Join-Path $artifacts 'mysql.ini'
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
    Run-Checked "$MySqlBin/mysqld.exe" @("--defaults-file=$config", '--initialize-insecure')
    $server = Start-Process "$MySqlBin/mysqld.exe" -ArgumentList "--defaults-file=$config" -WindowStyle Hidden -PassThru
    $ready = $false
    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        $ErrorActionPreference = 'Continue'
        & "$MySqlBin/mysqladmin.exe" --protocol=tcp --host=127.0.0.1 "--port=$port" --user=root ping 2>$null | Out-Null
        $ErrorActionPreference = 'Stop'
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        Start-Sleep -Milliseconds 500
    }
    if (! $ready) { throw 'Disposable MySQL startup failed.' }
    $sql = "CREATE DATABASE $database; CREATE DATABASE veritas_test_guard; CREATE TABLE veritas_test_guard.environment (database_name VARCHAR(100), token VARCHAR(64)); INSERT INTO veritas_test_guard.environment VALUES ('$database','$token'); CREATE USER 'veritas_stage21_test'@'127.0.0.1' IDENTIFIED BY '$token'; GRANT ALL ON $database.* TO 'veritas_stage21_test'@'127.0.0.1'; GRANT SELECT ON veritas_test_guard.* TO 'veritas_stage21_test'@'127.0.0.1'; GRANT SELECT ON performance_schema.* TO 'veritas_stage21_test'@'127.0.0.1';"
    Run-Checked "$MySqlBin/mysql.exe" @('--protocol=tcp', '--host=127.0.0.1', "--port=$port", '--user=root', '-e', $sql)
    $xml = (Get-Content phpunit.mysql.xml -Raw).Replace('veritas_core_test_codex', $database)
    $testConfig = Join-Path $root 'phpunit.disposable.xml'
    $xml | Set-Content -LiteralPath $testConfig
    if (! $BrowserOnly) {
        Run-Checked 'php' @('vendor/bin/phpunit', '-c', $testConfig, '--filter', 'Voucher', '--stop-on-error', '--log-junit', "$artifacts/vouchers.xml")
        Run-Checked 'php' @('vendor/bin/phpunit', '-c', $testConfig, '--log-junit', "$artifacts/regression.xml")
    }
    if (! $SkipBrowser) {
        $prepared = & php tests/Browser/prepare.php --journal --vouchers
        $prepared | Write-Output
        if ($LASTEXITCODE -ne 0 -or "$prepared" -notmatch 'Disposable browser fixtures prepared\.') { throw 'Browser fixture preparation failed.' }
        $listener.Start(); $webPort = $listener.LocalEndpoint.Port; $listener.Stop()
        $web = Start-Process 'php' -ArgumentList @('-S', "127.0.0.1:$webPort", '-t', 'public', 'tests/Browser/server.php') -WindowStyle Hidden -PassThru -RedirectStandardOutput "$artifacts/web.log" -RedirectStandardError "$artifacts/web-error.log"
        Run-Checked 'node' @('tests/Browser/chart-of-accounts.mjs', "http://127.0.0.1:$webPort", $Browser, "$artifacts/browser", 'tests/Browser/vouchers.mjs')
    }
} finally {
    if ($web -and ! $web.HasExited) { Stop-Process -Id $web.Id }
    if ($server) {
        # MySQL may leave its listener alive after the launcher exits on Windows.
        # Verify the live server identity before requesting graceful shutdown.
        $ErrorActionPreference = 'Continue'
        $actualData = & "$MySqlBin/mysql.exe" --protocol=tcp --host=127.0.0.1 "--port=$port" --user=root --raw -N -B -e 'SELECT @@datadir' 2>$null
        if ($LASTEXITCODE -eq 0 -and $actualData.Replace('\','/').TrimEnd('/') -eq $data.Replace('\','/').TrimEnd('/')) {
            & "$MySqlBin/mysqladmin.exe" --protocol=tcp --host=127.0.0.1 "--port=$port" --user=root shutdown
        }
        $ErrorActionPreference = 'Stop'
    }
    if (Test-Path -LiteralPath (Join-Path $root 'phpunit.disposable.xml')) { Remove-Item -LiteralPath (Join-Path $root 'phpunit.disposable.xml') }
    foreach ($name in $previous.Keys) { [Environment]::SetEnvironmentVariable($name, $previous[$name], 'Process') }
    Pop-Location
    Write-Output "Verification artifacts: $artifacts"
}
