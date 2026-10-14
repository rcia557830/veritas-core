param([string]$BaseUrl = 'http://127.0.0.1:8000')
$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
Push-Location $root
try {
    & php tests/Support/populate-demo-notices.php
    if ($LASTEXITCODE -ne 0) { throw 'Guarded notice population failed.' }
    $credentials = @{}
    Import-Clixml storage/app/private/phase6-demo-credentials.xml | ForEach-Object {
        $credentials[$_.UserName.Split('@')[0]] = $_.GetNetworkCredential().Password
    }
    $env:VERITAS_DEMO_BROWSER_CREDENTIALS = ConvertTo-Json -Compress $credentials
    $artifacts = Join-Path $root ('storage/app/private/notices-browser-' + [guid]::NewGuid().ToString('N'))
    & node tests/Browser/local-demo.mjs $BaseUrl 'C:/Program Files/Google/Chrome/Application/chrome.exe' $artifacts tests/Browser/notices.mjs
    if ($LASTEXITCODE -ne 0) { throw 'Notice browser verification failed.' }
    Write-Output "Browser artifacts: $artifacts"
} finally {
    Remove-Item Env:VERITAS_DEMO_BROWSER_CREDENTIALS -ErrorAction SilentlyContinue
    Pop-Location
}
