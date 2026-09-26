<#
Starts everything the hospital queue system needs, each in its own window:

  1. Backend API            php artisan serve         (LAN IP, port 8000)
  2. Reverb (WebSockets)    php artisan reverb:start  (port 8080) - live queue updates and
                                                       the "calling patient" announcements
  3. Scheduler              php artisan schedule:work - the waiting-too-long alerts
  4. Frontend               npm run dev               (port 5173)

Run from the project root:   .\scripts\start-all.ps1
Close a window to stop that part. Safe to run again; a port already in use just
means that part is already running.
#>

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$backend = Join-Path $root 'backend'
$frontend = Join-Path $root 'frontend'

# Point backend/.env and frontend/.env at this machine's current LAN IP first,
# so QR codes and phones use an address that actually works.
& (Join-Path $PSScriptRoot 'sync-lan-ip.ps1')

function Start-Window([string]$title, [string]$folder, [string]$command) {
    $script = "`$Host.UI.RawUI.WindowTitle = '$title'; Set-Location '$folder'; $command"
    Start-Process powershell -ArgumentList '-NoExit', '-NoProfile', '-Command', $script
}

Start-Window 'Queue API'   $backend  'php artisan serve'
Start-Window 'Reverb'      $backend  'php artisan reverb:start'
Start-Window 'Scheduler'   $backend  'php artisan schedule:work'
Start-Window 'Frontend'    $frontend 'npm run dev'

Write-Output 'Started: Queue API, Reverb, Scheduler and Frontend (four windows).'
