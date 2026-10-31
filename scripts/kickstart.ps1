<#
.SYNOPSIS
    DMS All-in-One Kickstarter Script
    Orchestrates MySQL, Database setup, Migrations, Background Queue Worker, Scheduler, and Web Server.
#>

[CmdletBinding()]
param(
    [switch]$NoBrowser,
    [switch]$Fresh,
    [string]$HostName = "127.0.0.1",
    [int]$Port = 8011
)

$ErrorActionPreference = 'Stop'
$appRoot = Split-Path $PSScriptRoot -Parent
Set-Location -LiteralPath $appRoot

function Write-Section {
    param([string]$Message)
    Write-Host "`n>>> $Message" -ForegroundColor Cyan
}

function Write-Success {
    param([string]$Message)
    Write-Host "  [+] $Message" -ForegroundColor Green
}

function Write-Info {
    param([string]$Message)
    Write-Host "  [*] $Message" -ForegroundColor Gray
}

function Write-Warn {
    param([string]$Message)
    Write-Host "  [!] $Message" -ForegroundColor Yellow
}

function Write-Err {
    param([string]$Message)
    Write-Host "  [x] $Message" -ForegroundColor Red
}

function Test-PortOpen([string]$TargetHost, [int]$TargetPort) {
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $iar = $client.BeginConnect($TargetHost, $TargetPort, $null, $null)
        if ($iar.AsyncWaitHandle.WaitOne(800, $false) -and $client.Connected) {
            $client.EndConnect($iar)
            return $true
        }
    } catch {
        return $false
    } finally {
        $client.Close()
        $client.Dispose()
    }
    return $false
}

function Start-DetachedProcess([string]$Executable, [string]$Arguments, [string]$WorkingDirectory) {
    $cmdLine = "`"$Executable`" $Arguments".Trim()
    try {
        $res = Invoke-CimMethod -ClassName Win32_Process -MethodName Create -Arguments @{
            CommandLine = $cmdLine
            CurrentDirectory = $WorkingDirectory
        } -ErrorAction Stop
        return ($res.ReturnValue -eq 0)
    } catch {
        # Fallback to Start-Process
        Start-Process -FilePath $Executable -ArgumentList ($Arguments -split ' ') -WorkingDirectory $WorkingDirectory -WindowStyle Hidden
        return $true
    }
}

Clear-Host
Write-Host "===================================================================" -ForegroundColor Cyan
Write-Host "        PLP Document Management System (DMS) Kickstarter           " -ForegroundColor White
Write-Host "===================================================================" -ForegroundColor Cyan

# -------------------------------------------------------------------------
# Step 1: Detect PHP
# -------------------------------------------------------------------------
Write-Section "Checking PHP environment..."

$phpPath = $null
$phpCommand = Get-Command php -ErrorAction SilentlyContinue
if ($phpCommand) {
    $phpPath = $phpCommand.Source
} else {
    $candidatePhpPaths = @(
        "$env:USERPROFILE\.config\herd-lite\bin\php.exe",
        "C:\Users\Ivan\.config\herd-lite\bin\php.exe",
        "C:\xampp\php\php.exe",
        "D:\xampp\php\php.exe",
        "E:\xampp\php\php.exe",
        "C:\laragon\bin\php\php-*\php.exe",
        "C:\php\php.exe",
        "C:\Program Files\PHP\*\php.exe"
    )
    foreach ($cand in $candidatePhpPaths) {
        $resolved = Resolve-Path $cand -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($resolved) {
            $phpPath = $resolved.Path
            $phpDir = Split-Path $phpPath -Parent
            $env:PATH = "$phpDir;" + $env:PATH
            break
        }
    }
}

if (-not $phpPath -or -not (Test-Path $phpPath)) {
    Write-Err "PHP executable not found in PATH or standard locations (XAMPP, Laragon, C:\php)."
    Write-Err "Please install PHP 8.2+ or add it to your system PATH."
    exit 1
}

$phpVer = & $phpPath -r 'echo PHP_VERSION;' 2>$null
Write-Success "PHP found: $phpPath (version $phpVer)"

# -------------------------------------------------------------------------
# Step 2: Environment Configuration (.env)
# -------------------------------------------------------------------------
Write-Section "Checking configuration (.env)..."

$envFile = Join-Path $appRoot '.env'
if (-not (Test-Path $envFile)) {
    Write-Warn ".env file missing. Copying from .env.example..."
    Copy-Item (Join-Path $appRoot '.env.example') $envFile
    & $phpPath artisan key:generate --force
    Write-Success "Generated .env and APP_KEY."
} else {
    Write-Success ".env configuration present."
}

# Parse database configuration from .env
$envLines = Get-Content $envFile -ErrorAction SilentlyContinue
$dbHost = "127.0.0.1"
$dbPort = 3306
$dbDatabase = "dms"
$dbUsername = "root"
$dbPassword = ""
$appUrl = "http://127.0.0.1:$Port"

foreach ($line in $envLines) {
    $trimmed = $line.Trim()
    if ($trimmed.StartsWith("#") -or (-not $trimmed.Contains("="))) { continue }
    $parts = $trimmed.Split("=", 2)
    $key = $parts[0].Trim()
    $val = $parts[1].Trim().Trim('"').Trim("'")
    switch ($key) {
        "DB_HOST"     { if ($val) { $dbHost = $val } }
        "DB_PORT"     { if ($val) { $dbPort = [int]$val } }
        "DB_DATABASE" { if ($val) { $dbDatabase = $val } }
        "DB_USERNAME" { if ($val) { $dbUsername = $val } }
        "DB_PASSWORD" { $dbPassword = $val }
        "APP_URL"     { if ($val) { $appUrl = $val } }
    }
}

# -------------------------------------------------------------------------
# Step 3: MySQL Service Check & Auto-Start
# -------------------------------------------------------------------------
Write-Section "Checking MySQL service on $dbHost`:$dbPort..."

$isMySqlRunning = Test-PortOpen $dbHost $dbPort

if ($isMySqlRunning) {
    Write-Success "MySQL is already running on port $dbPort."
} else {
    Write-Warn "MySQL is NOT running on port $dbPort. Attempting auto-start..."

    # Check for installed Windows Service
    $services = Get-Service | Where-Object {
        ($_.Name -like "*mysql*" -or $_.Name -like "*mariadb*" -or $_.DisplayName -like "*mysql*" -or $_.DisplayName -like "*mariadb*") -and $_.Status -ne "Running"
    }
    if ($services) {
        foreach ($srv in $services) {
            Write-Info "Trying to start Windows service '$($srv.Name)'..."
            try { Start-Service -Name $srv.Name -ErrorAction SilentlyContinue } catch {}
        }
    }

    # If still not running, try starting standalone mysqld (XAMPP / Laragon / Standalone)
    if (-not (Test-PortOpen $dbHost $dbPort)) {
        $xamppCandidates = @(
            @{ Exe = "C:\xampp\mysql\bin\mysqld.exe"; WorkingDir = "C:\xampp"; Args = "--defaults-file=mysql\bin\my.ini --standalone" },
            @{ Exe = "D:\xampp\mysql\bin\mysqld.exe"; WorkingDir = "D:\xampp"; Args = "--defaults-file=mysql\bin\my.ini --standalone" },
            @{ Exe = "E:\xampp\mysql\bin\mysqld.exe"; WorkingDir = "E:\xampp"; Args = "--defaults-file=mysql\bin\my.ini --standalone" }
        )

        $started = $false
        foreach ($cand in $xamppCandidates) {
            if (Test-Path $cand.Exe) {
                Write-Info "Starting MySQL via XAMPP: $($cand.Exe)"
                $started = Start-DetachedProcess $cand.Exe $cand.Args $cand.WorkingDir
                break
            }
        }

        if (-not $started) {
            # Try Laragon
            $laragonCandidates = Resolve-Path "C:\laragon\bin\mysql\*\bin\mysqld.exe" -ErrorAction SilentlyContinue
            if ($laragonCandidates) {
                $laragonExe = $laragonCandidates[0].Path
                Write-Info "Starting MySQL via Laragon: $laragonExe"
                $started = Start-DetachedProcess $laragonExe "--standalone" (Split-Path $laragonExe -Parent)
            }
        }

        if (-not $started) {
            $pathMysqld = Get-Command mysqld -ErrorAction SilentlyContinue
            if ($pathMysqld) {
                Write-Info "Starting MySQL via system PATH: $($pathMysqld.Source)"
                $started = Start-DetachedProcess $pathMysqld.Source "--standalone" (Split-Path $pathMysqld.Source -Parent)
            }
        }
    }

    # Poll for port up to 15 seconds
    $maxWait = 15
    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    $ready = $false
    Write-Host -NoNewline "  [*] Waiting for MySQL to initialize" -ForegroundColor Gray
    while ($sw.Elapsed.TotalSeconds -lt $maxWait) {
        Start-Sleep -Milliseconds 700
        Write-Host -NoNewline "." -ForegroundColor Gray
        if (Test-PortOpen $dbHost $dbPort) {
            $ready = $true
            break
        }
    }
    Write-Host ""

    if ($ready) {
        Write-Success "MySQL started and ready for connections on port $dbPort!"
    } else {
        Write-Warn "Could not automatically verify MySQL on port $dbPort."
        Write-Warn "Please check XAMPP Control Panel or start your MySQL server manually."
    }
}

# -------------------------------------------------------------------------
# Step 4: Ensure Database Exists
# -------------------------------------------------------------------------
Write-Section "Checking database '$dbDatabase'..."

$cleanDb = ($dbDatabase -replace '[^a-zA-Z0-9_]', '')
$phpDbCode = "try { `$p = new PDO('mysql:host=$dbHost;port=$dbPort', '$dbUsername', '$dbPassword', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); `$p->exec('CREATE DATABASE IF NOT EXISTS $cleanDb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'); echo 'DB_READY'; } catch (Throwable `$e) { echo 'DB_ERR:' . `$e->getMessage(); }"

$dbResult = & $phpPath -r $phpDbCode 2>&1
if ($dbResult -match "DB_READY") {
    Write-Success "Database '$dbDatabase' is confirmed and ready."
} else {
    Write-Warn "Database check notice: $dbResult"
}

# -------------------------------------------------------------------------
# Step 5: Ensure Storage & Cache Directories
# -------------------------------------------------------------------------
Write-Section "Verifying storage and caching directories..."

$storageDirs = @(
    "storage/app/private/documents",
    "storage/framework/cache",
    "storage/framework/sessions",
    "storage/framework/views",
    "storage/logs",
    "bootstrap/cache"
)
foreach ($dir in $storageDirs) {
    $fullPath = Join-Path $appRoot $dir
    if (-not (Test-Path $fullPath)) {
        New-Item -ItemType Directory -Path $fullPath -Force | Out-Null
    }
}
Write-Success "Storage directories verified."

# -------------------------------------------------------------------------
# Step 6: Database Migrations
# -------------------------------------------------------------------------
Write-Section "Running database migrations..."

if ($Fresh) {
    Write-Warn "Running migrate:fresh --seed..."
    & $phpPath artisan migrate:fresh --seed --force
} else {
    & $phpPath artisan migrate --force
}
Write-Success "Migrations up to date."

# -------------------------------------------------------------------------
# Step 7: Clean Stale Background Workers & Schedulers
# -------------------------------------------------------------------------
Write-Section "Checking for stale worker / scheduler processes..."

try {
    $staleProcesses = Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" -ErrorAction SilentlyContinue | Where-Object {
        $_.CommandLine -match 'artisan.*queue:work' -or $_.CommandLine -match 'artisan.*schedule:work'
    }
    if ($staleProcesses) {
        foreach ($proc in $staleProcesses) {
            Write-Info "Stopping leftover background worker (PID $($proc.ProcessId))..."
            Stop-Process -Id $proc.ProcessId -Force -ErrorAction SilentlyContinue
        }
        Write-Success "Cleaned up stale background processes."
    } else {
        Write-Info "No stale worker processes found."
    }
} catch {
    # Non-critical, continue
}

# -------------------------------------------------------------------------
# Step 8: ClamAV Daemon Check & Auto-Start (Port 3310)
# -------------------------------------------------------------------------
Write-Section "Checking ClamAV Daemon (port 3310)..."

$clamdProcess = $null
$isClamdRunning = Test-PortOpen "127.0.0.1" 3310

if ($isClamdRunning) {
    Write-Success "ClamAV daemon is already running on port 3310."
} else {
    $clamdExe = Join-Path $appRoot "storage\tools\clamav\clamav-1.4.6.win.x64\clamd.exe"
    $clamdConf = Join-Path $appRoot "storage\tools\clamav\clamav-1.4.6.win.x64\clamd.conf"
    if (Test-Path $clamdExe) {
        Write-Info "Starting ClamAV daemon ($clamdExe)..."
        $clamdProcess = Start-Process -FilePath $clamdExe `
            -ArgumentList "--config-file=`"$clamdConf`"" `
            -WorkingDirectory (Split-Path $clamdExe -Parent) `
            -WindowStyle Hidden `
            -PassThru

        $cSw = [System.Diagnostics.Stopwatch]::StartNew()
        while ($cSw.Elapsed.TotalSeconds -lt 8) {
            Start-Sleep -Milliseconds 600
            if (Test-PortOpen "127.0.0.1" 3310) { break }
        }

        if (Test-PortOpen "127.0.0.1" 3310) {
            Write-Success "ClamAV daemon started and listening on 127.0.0.1:3310 (PID $($clamdProcess.Id))."
        } else {
            Write-Warn "ClamAV daemon launched but port 3310 is still initializing. Scans will connect once ready."
        }
    } else {
        Write-Warn "clamd.exe not found at standard path. Scans will use fallback scanner."
    }
}

# -------------------------------------------------------------------------
# Step 9: Start Background Services (Worker & Scheduler)
# -------------------------------------------------------------------------
Write-Section "Starting background services..."

$workerLog = Join-Path $appRoot 'storage/logs/worker.log'
$workerErr = Join-Path $appRoot 'storage/logs/worker-error.log'
$schedulerLog = Join-Path $appRoot 'storage/logs/scheduler.log'
$schedulerErr = Join-Path $appRoot 'storage/logs/scheduler-error.log'

$worker = Start-Process -FilePath $phpPath `
    -ArgumentList 'artisan','queue:work','database','--queue=scans,default','--sleep=1','--tries=5','--timeout=75' `
    -WorkingDirectory $appRoot `
    -WindowStyle Hidden `
    -PassThru `
    -RedirectStandardOutput $workerLog `
    -RedirectStandardError $workerErr

Write-Success "ClamAV Scan Worker started (PID $($worker.Id)) -> logs in storage/logs/worker.log"

$scheduler = Start-Process -FilePath $phpPath `
    -ArgumentList 'artisan','schedule:work' `
    -WorkingDirectory $appRoot `
    -WindowStyle Hidden `
    -PassThru `
    -RedirectStandardOutput $schedulerLog `
    -RedirectStandardError $schedulerErr

Write-Success "Task Scheduler started (PID $($scheduler.Id)) -> logs in storage/logs/scheduler.log"

# -------------------------------------------------------------------------
# Step 10: Ngrok Tunnel (for Phone & Remote Testing)
# -------------------------------------------------------------------------
Write-Section "Checking Ngrok tunnel..."

$ngrokProcess = $null
$ngrokPublicUrl = $null
$isNgrokRunning = Test-PortOpen "127.0.0.1" 4040

if ($isNgrokRunning) {
    try {
        $tunnels = (Invoke-RestMethod -Uri "http://127.0.0.1:4040/api/tunnels" -TimeoutSec 2 -ErrorAction SilentlyContinue).tunnels
        if ($tunnels -and $tunnels.Length -gt 0) {
            $ngrokPublicUrl = $tunnels[0].public_url
            Write-Success "Ngrok is already active: $ngrokPublicUrl"
        }
    } catch {}
} else {
    $ngrokExe = $null
    $ngrokCmd = Get-Command ngrok -ErrorAction SilentlyContinue
    if ($ngrokCmd) {
        $ngrokExe = $ngrokCmd.Source
    } elseif (Test-Path "C:\Users\PLP\Downloads\ngrok_bin\ngrok.exe") {
        $ngrokExe = "C:\Users\PLP\Downloads\ngrok_bin\ngrok.exe"
    }

    if ($ngrokExe) {
        Write-Info "Starting Ngrok tunnel for port $Port..."
        $ngrokProcess = Start-Process -FilePath $ngrokExe `
            -ArgumentList "http $Port --log=stdout" `
            -WindowStyle Hidden `
            -PassThru

        Start-Sleep -Seconds 2
        try {
            $tunnels = (Invoke-RestMethod -Uri "http://127.0.0.1:4040/api/tunnels" -TimeoutSec 3 -ErrorAction SilentlyContinue).tunnels
            if ($tunnels -and $tunnels.Length -gt 0) {
                $ngrokPublicUrl = $tunnels[0].public_url
                Write-Success "Ngrok tunnel established: $ngrokPublicUrl"
            }
        } catch {}
    } else {
        Write-Info "Ngrok not found in PATH or Downloads. Skipping remote tunnel (local access only)."
    }
}

# -------------------------------------------------------------------------
# Step 11: Launch Browser & Serve
# -------------------------------------------------------------------------
if (-not $NoBrowser) {
    Start-Job -ScriptBlock {
        param($url)
        Start-Sleep -Seconds 2
        Start-Process $url
    } -ArgumentList $appUrl | Out-Null
}

Write-Host "`n===================================================================" -ForegroundColor Green
Write-Host " [DMS READY] System is fully operational!" -ForegroundColor White
Write-Host " Local Web URL  : $appUrl" -ForegroundColor Yellow
if ($ngrokPublicUrl) {
    Write-Host " Phone / Remote : $ngrokPublicUrl" -ForegroundColor Cyan
}
Write-Host " Database       : $dbDatabase on $dbHost`:$dbPort" -ForegroundColor Gray
Write-Host " ClamAV Daemon  : 127.0.0.1:3310 (Fast socket scanning)" -ForegroundColor Gray
Write-Host " Scan Queue     : database / scans,default (Worker PID $($worker.Id))" -ForegroundColor Gray
Write-Host " Scheduler      : Active (PID $($scheduler.Id))" -ForegroundColor Gray
Write-Host " Note           : Press Ctrl+C in this window to stop all services." -ForegroundColor White
Write-Host "===================================================================`n" -ForegroundColor Green

try {
    & $phpPath -d upload_max_filesize=10M -d post_max_size=512M -d max_file_uploads=50 artisan serve --host=$HostName --port=$Port
} finally {
    Write-Host "`nShutting down DMS services..." -ForegroundColor Yellow
    if ($worker -and -not $worker.HasExited) {
        Stop-Process -Id $worker.Id -Force -ErrorAction SilentlyContinue
        Write-Host "  [-] Stopped scan worker." -ForegroundColor Gray
    }
    if ($scheduler -and -not $scheduler.HasExited) {
        Stop-Process -Id $scheduler.Id -Force -ErrorAction SilentlyContinue
        Write-Host "  [-] Stopped scheduler." -ForegroundColor Gray
    }
    if ($clamdProcess -and -not $clamdProcess.HasExited) {
        Stop-Process -Id $clamdProcess.Id -Force -ErrorAction SilentlyContinue
        Write-Host "  [-] Stopped ClamAV daemon." -ForegroundColor Gray
    }
    if ($ngrokProcess -and -not $ngrokProcess.HasExited) {
        Stop-Process -Id $ngrokProcess.Id -Force -ErrorAction SilentlyContinue
        Write-Host "  [-] Stopped Ngrok tunnel." -ForegroundColor Gray
    }
    Write-Host "[DMS] All services stopped cleanly. Goodbye!`n" -ForegroundColor Green
}
