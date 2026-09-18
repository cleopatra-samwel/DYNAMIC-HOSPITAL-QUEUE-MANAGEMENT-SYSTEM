<#
Detects this machine's current LAN IPv4 address and rewrites backend/.env and
frontend/.env so APP_URL, SERVER_HOST, FRONTEND_URL (CORS + QR/tracking
links), VITE_API_BASE_URL, and VITE_REVERB_HOST all point at it.

Setting SERVER_HOST matters because Laravel's `serve` command defaults its
--host option to the SERVER_HOST env var — so once this is synced, a plain
`php artisan serve` (no flags) binds to the LAN IP too.

Safe to re-run any time; it's a no-op if the IP hasn't changed. Called
automatically by `npm run dev` (frontend) and `composer run serve` (backend).
#>

$ErrorActionPreference = 'Stop'

$config = Get-NetIPConfiguration | Where-Object {
    $null -ne $_.IPv4DefaultGateway -and $_.NetAdapter.Status -eq 'Up'
} | Select-Object -First 1

if (-not $config) {
    Write-Warning 'No active network interface with a default gateway found; leaving .env files untouched.'
    exit 0
}

$ip = $config.IPv4Address.IPAddress
$root = Split-Path -Parent $PSScriptRoot
$backendEnvPath = Join-Path $root 'backend\.env'
$frontendEnvPath = Join-Path $root 'frontend\.env'

$utf8NoBom = [System.Text.UTF8Encoding]::new($false)

if (Test-Path $backendEnvPath) {
    $content = [System.IO.File]::ReadAllText($backendEnvPath, $utf8NoBom)
    $original = $content

    if ($content -notmatch '(?m)^SERVER_HOST=') {
        $content = $content -replace '(?m)^(APP_URL=.*)$', "`$1`nSERVER_HOST=$ip"
    } else {
        $content = $content -replace '(?m)^SERVER_HOST=.*$', "SERVER_HOST=$ip"
    }

    $content = $content -replace '(?m)^APP_URL=http://[^:]+(:\d+)$', "APP_URL=http://$ip`$1"
    $content = $content -replace '(?m)^FRONTEND_URL=http://[^:,]+(:\d+,http://localhost:\d+)$', "FRONTEND_URL=http://$ip`$1"

    if ($content -ne $original) {
        [System.IO.File]::WriteAllText($backendEnvPath, $content, $utf8NoBom)
        Write-Output "Updated backend/.env -> $ip"
    }
} else {
    Write-Warning "$backendEnvPath not found, skipping."
}

if (Test-Path $frontendEnvPath) {
    $content = [System.IO.File]::ReadAllText($frontendEnvPath, $utf8NoBom)
    $original = $content

    $content = $content -replace '(?m)^VITE_API_BASE_URL=http://[^:]+(:\d+/api)$', "VITE_API_BASE_URL=http://$ip`$1"
    $content = $content -replace '(?m)^VITE_REVERB_HOST=.*$', "VITE_REVERB_HOST=$ip"

    if ($content -ne $original) {
        [System.IO.File]::WriteAllText($frontendEnvPath, $content, $utf8NoBom)
        Write-Output "Updated frontend/.env -> $ip"
    }
} else {
    Write-Warning "$frontendEnvPath not found, skipping."
}

Write-Output "LAN IP: $ip"
