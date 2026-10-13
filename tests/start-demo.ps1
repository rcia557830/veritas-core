param(
    [switch]$GenerateCredentials,
    [switch]$PrepareOnly,
    [int]$Port = 8086
)
$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$database = Join-Path $root 'database/veritas_demo.sqlite'
$vault = Join-Path $root 'storage/app/private/phase6-demo-credentials.xml'
if (Test-Path (Join-Path $root 'bootstrap/cache/config.php')) { throw 'Cached configuration detected. Use a separate uncached checkout for the demo.' }
if ($GenerateCredentials -and ((Test-Path $database) -or (Test-Path $vault))) { throw 'Generated credential initialization requires a new demo database and vault. Existing files are preserved.' }
$variables = @{ APP_ENV='local'; APP_DEBUG='false'; APP_URL="http://127.0.0.1:$Port"; DB_CONNECTION='sqlite'; DB_DATABASE=$database; DB_URL='null'; DB_FOREIGN_KEYS='true'; VERITAS_DEMO_DATA='true'; SESSION_DRIVER='database'; SESSION_CONNECTION='null'; SESSION_DOMAIN='null'; SESSION_COOKIE='veritas_phase6_demo_session'; SESSION_SECURE_COOKIE='false'; CACHE_STORE='array'; QUEUE_CONNECTION='sync'; MAIL_MAILER='log'; APP_TIMEZONE='Asia/Manila' }
$previous = @{}
function Run-Checked([string[]]$arguments) {
    & php @arguments
    if ($LASTEXITCODE -ne 0) { throw "Demo command failed (exit $LASTEXITCODE)." }
}
Push-Location $root
try {
    if ($GenerateCredentials) {
        $credentials = @()
        foreach ($role in @('super-admin', 'owner', 'office-manager', 'bookkeeper')) {
            $bytes = New-Object byte[] 24
            $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
            try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
            $password = [Convert]::ToBase64String($bytes)
            $credentials += [pscredential]::new("$role@demo.example.test", (ConvertTo-SecureString $password -AsPlainText -Force))
            $variables['VERITAS_DEMO_' + $role.Replace('-', '_').ToUpperInvariant() + '_PASSWORD'] = $password
        }
        # Windows DPAPI encrypts SecureString values for this Windows account and machine.
        $credentials | Export-Clixml -LiteralPath $vault
    }
    foreach ($name in $variables.Keys) {
        $previous[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
        [Environment]::SetEnvironmentVariable($name, $variables[$name], 'Process')
    }
    if (! (Test-Path -LiteralPath $database)) { New-Item -ItemType File -Path $database | Out-Null }
    # Guarded before migrations; never uses the business connection from .env.
    Run-Checked @('tests/Support/prepare-demo.php')
    if ($GenerateCredentials) {
        Run-Checked @('artisan', 'db:seed', '--class=DemoDataSeeder', '--no-interaction')
        Write-Output "Encrypted demo credentials: $vault (passwords decryptable only by this Windows account on this machine)."
    } else {
        Run-Checked @('artisan', 'demo:seed', '--confirm')
    }
    # Do not pass provisioning secrets to the web server.
    foreach ($name in @($variables.Keys | Where-Object { $_ -like 'VERITAS_DEMO_*_PASSWORD' })) {
        [Environment]::SetEnvironmentVariable($name, $null, 'Process')
    }
    Run-Checked @('artisan', 'migrate:status', '--no-interaction')
    if (! $PrepareOnly) { Run-Checked @('artisan', 'serve', '--host=127.0.0.1', "--port=$Port") }
} finally {
    foreach ($name in $previous.Keys) { [Environment]::SetEnvironmentVariable($name, $previous[$name], 'Process') }
    Pop-Location
}
