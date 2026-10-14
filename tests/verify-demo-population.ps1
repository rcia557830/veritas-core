param([string]$BaseUrl = 'http://127.0.0.1:8000')
$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
Push-Location $root
try {
    # Refuses business databases before logging in or exercising the workflow.
    & php tests/Support/verify-demo-population.php
    if ($LASTEXITCODE -ne 0) { throw 'The configured database is not a verified isolated demo.' }
    $credentials = @{}
    Import-Clixml storage/app/private/phase6-demo-credentials.xml | ForEach-Object {
        $credentials[$_.UserName.Split('@')[0]] = $_.GetNetworkCredential().Password
    }
    $env:VERITAS_DEMO_BROWSER_CREDENTIALS = ConvertTo-Json -Compress $credentials
    $artifacts = Join-Path $root ('storage/app/private/population-browser-' + [guid]::NewGuid().ToString('N'))
    & node tests/Browser/local-demo.mjs $BaseUrl 'C:/Program Files/Google/Chrome/Application/chrome.exe' $artifacts tests/Browser/populated-demo.mjs
    if ($LASTEXITCODE -ne 0) { throw 'Browser verification failed.' }
    & php tests/Support/verify-demo-population.php
    if ($LASTEXITCODE -ne 0) { throw 'Post-browser integrity verification failed.' }
} finally {
    Remove-Item Env:VERITAS_DEMO_BROWSER_CREDENTIALS -ErrorAction SilentlyContinue
    Pop-Location
}
