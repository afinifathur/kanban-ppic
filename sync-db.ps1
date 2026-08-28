# ============================================================
# KANBAN PPIC - PRODUCTION DATABASE SYNC
# Direction: PRODUCTION -> LOCAL (ONE-WAY ONLY)
# ============================================================

[CmdletBinding()]
param()

$ErrorActionPreference = "Stop"

# Set working directory to script location
$ProjectRoot = $PSScriptRoot
if (-not $ProjectRoot) {
    $ProjectRoot = (Get-Location).Path
}
Set-Location $ProjectRoot

# ============================================================
# CONFIGURATION
# ============================================================

# Production Server (SSH & Docker)
$REMOTE_USER         = "peroniks"
$REMOTE_HOST         = "10.88.8.46"
$REMOTE_PATH         = "/srv/docker/apps/kanban-ppic"
$REMOTE_DB_CONTAINER = "kanban-ppic-db"
$REMOTE_DB_NAME      = "kanban_ppic"
$REMOTE_DB_USER      = "root"
$REMOTE_DB_PASS      = "123456788"
$REMOTE_DUMP_FILE    = "prod_dump.sql"

# Local Laragon MySQL Defaults
$LOCAL_DB_NAME       = "kanban-ppic"
$LOCAL_DB_USER       = "root"
$LOCAL_DB_PASS       = "123456788"
$LOCAL_DB_HOST       = "127.0.0.1"
$LOCAL_DUMP_FILE     = "prod_dump.sql"
$LOCAL_BACKUP_DIR    = Join-Path $ProjectRoot "backups"

# Auto-detect configuration from local .env if present
$envFile = Join-Path $ProjectRoot ".env"
if (Test-Path $envFile) {
    Get-Content $envFile | ForEach-Object {
        $line = $_.Trim()
        if ($line -and -not $line.StartsWith("#") -and $line -match "^([^=]+)=(.*)$") {
            $key = $matches[1].Trim()
            $val = $matches[2].Trim().Trim('"').Trim("'")
            if ($key -eq "DB_DATABASE" -and $val) { $LOCAL_DB_NAME = $val }
            if ($key -eq "DB_USERNAME" -and $val) { $LOCAL_DB_USER = $val }
            if ($key -eq "DB_PASSWORD")          { $LOCAL_DB_PASS = $val }
            if ($key -eq "DB_HOST" -and $val)     { $LOCAL_DB_HOST = $val }
        }
    }
}

$localDumpPath = Join-Path $ProjectRoot $LOCAL_DUMP_FILE

# ============================================================
# UX & LOGGING HELPERS
# ============================================================

function Show-Header {
    Write-Host ""
    Write-Host "==================================================" -ForegroundColor Cyan
    Write-Host "      KANBAN PPIC PRODUCTION DATABASE SYNC        " -ForegroundColor Cyan
    Write-Host "==================================================" -ForegroundColor Cyan
    Write-Host "Direction: PRODUCTION -> LOCAL" -ForegroundColor DarkGray
    Write-Host "Production Target: $REMOTE_USER@$REMOTE_HOST ($REMOTE_DB_NAME)" -ForegroundColor DarkGray
    Write-Host "Local Target     : $LOCAL_DB_HOST ($LOCAL_DB_NAME)" -ForegroundColor DarkGray
    Write-Host "==================================================" -ForegroundColor Cyan
}

function Show-StepHeader([string]$stepNumber, [string]$title) {
    Write-Host ""
    Write-Host "[$stepNumber] $title" -ForegroundColor Yellow
}

function Show-StepSuccess([string]$message = "SUCCESS") {
    Write-Host "  -> $message" -ForegroundColor Green
}

function Show-FatalError([string]$stage, [string]$reason, [string]$localDbStatus, [string]$backupLocation = "") {
    Write-Host ""
    Write-Host "==================================================" -ForegroundColor Red
    Write-Host "                 SYNC FAILED                      " -ForegroundColor Red
    Write-Host "==================================================" -ForegroundColor Red
    Write-Host "Failed Stage    : $stage" -ForegroundColor Red
    Write-Host "Error Reason    : $reason" -ForegroundColor Red
    Write-Host "Local DB Status : $localDbStatus" -ForegroundColor Yellow
    if ($backupLocation) {
        Write-Host "Local Backup    : $backupLocation" -ForegroundColor Green
    }
    Write-Host "==================================================" -ForegroundColor Red
    Write-Host ""
    exit 1
}

# ============================================================
# MYSQL BINARY DETECTION
# ============================================================

Show-Header

$mysqlPath = "mysql"
$mysqldumpPath = "mysqldump"

# Detect mysql.exe
if (-not (Get-Command "mysql" -ErrorAction SilentlyContinue)) {
    $mysqlExe = Get-ChildItem "C:\laragon\bin\mysql" -Filter "mysql.exe" -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($mysqlExe) {
        $mysqlPath = $mysqlExe.FullName
    }
    else {
        Show-FatalError -stage "Binary Detection" `
                        -reason "mysql.exe was not found in PATH or C:\laragon\bin\mysql." `
                        -localDbStatus "SAFE - No changes made."
    }
}

# Detect mysqldump.exe
if (-not (Get-Command "mysqldump" -ErrorAction SilentlyContinue)) {
    $mysqldumpExe = Get-ChildItem "C:\laragon\bin\mysql" -Filter "mysqldump.exe" -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($mysqldumpExe) {
        $mysqldumpPath = $mysqldumpExe.FullName
    }
    else {
        Show-FatalError -stage "Binary Detection" `
                        -reason "mysqldump.exe was not found in PATH or C:\laragon\bin\mysql." `
                        -localDbStatus "SAFE - No changes made."
    }
}

Write-Host "MySQL Binary    : $mysqlPath" -ForegroundColor DarkGray
Write-Host "mysqldump Binary: $mysqldumpPath" -ForegroundColor DarkGray

# ============================================================
# [0/5] LOCAL SAFEGUARD BACKUP
# ============================================================

Show-StepHeader "0/5" "Creating LOCAL safeguard backup..."

if (-not (Test-Path $LOCAL_BACKUP_DIR)) {
    New-Item -ItemType Directory -Path $LOCAL_BACKUP_DIR -Force | Out-Null
}

$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$backupFileName = "local_backup_$timestamp.sql"
$backupFilePath = Join-Path $LOCAL_BACKUP_DIR $backupFileName

try {
    & $mysqldumpPath -h $LOCAL_DB_HOST -u $LOCAL_DB_USER "--password=$LOCAL_DB_PASS" --no-tablespaces --result-file="$backupFilePath" $LOCAL_DB_NAME
    $dumpExit = $LASTEXITCODE
}
catch {
    $dumpExit = 1
}

if ($dumpExit -ne 0 -or (-not (Test-Path $backupFilePath)) -or ((Get-Item $backupFilePath).Length -eq 0)) {
    Show-FatalError -stage "[0/5] Local Safeguard Backup" `
                    -reason "Failed to create local safeguard backup or backup file is empty." `
                    -localDbStatus "SAFE - Aborted before touching production or local data."
}

Show-StepSuccess "Backup saved -> backups/$backupFileName"

# ============================================================
# [1/5] CREATE PRODUCTION DUMP
# ============================================================

Show-StepHeader "1/5" "Creating PRODUCTION database dump..."

$remoteDumpCmd = "docker exec -i $REMOTE_DB_CONTAINER mysqldump --no-tablespaces -u$REMOTE_DB_USER -p$REMOTE_DB_PASS $REMOTE_DB_NAME > $REMOTE_PATH/$REMOTE_DUMP_FILE"

try {
    ssh "${REMOTE_USER}@${REMOTE_HOST}" $remoteDumpCmd
    $sshExit = $LASTEXITCODE
}
catch {
    $sshExit = 1
}

if ($sshExit -ne 0) {
    Show-FatalError -stage "[1/5] Production Database Dump" `
                    -reason "Failed to create mysqldump on production server via SSH/Docker." `
                    -localDbStatus "SAFE - Local database unchanged." `
                    -backupLocation $backupFilePath
}

Show-StepSuccess "Production dump created at $REMOTE_PATH/$REMOTE_DUMP_FILE"

# ============================================================
# [2/5] DOWNLOAD PRODUCTION DUMP
# ============================================================

Show-StepHeader "2/5" "Downloading production dump..."

$scpSource = "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PATH}/${REMOTE_DUMP_FILE}"

try {
    scp $scpSource $localDumpPath
    $scpExit = $LASTEXITCODE
}
catch {
    $scpExit = 1
}

if ($scpExit -ne 0 -or (-not (Test-Path $localDumpPath)) -or ((Get-Item $localDumpPath).Length -eq 0)) {
    Show-FatalError -stage "[2/5] Download Production Dump" `
                    -reason "Failed to download production dump via SCP or downloaded file is empty." `
                    -localDbStatus "SAFE - Local database unchanged." `
                    -backupLocation $backupFilePath
}

$dumpSize = [math]::Round(((Get-Item $localDumpPath).Length / 1MB), 2)
Show-StepSuccess "Downloaded $LOCAL_DUMP_FILE ($dumpSize MB)"

# ============================================================
# [3/5] IMPORT INTO LOCAL DATABASE
# ============================================================

Show-StepHeader "3/5" "Importing production database into LOCAL ($LOCAL_DB_NAME)..."

try {
    cmd.exe /c "`"$mysqlPath`" -h $LOCAL_DB_HOST -u $LOCAL_DB_USER --password=$LOCAL_DB_PASS $LOCAL_DB_NAME < `"$localDumpPath`""
    $importExit = $LASTEXITCODE
}
catch {
    $importExit = 1
}

if ($importExit -ne 0) {
    Show-FatalError -stage "[3/5] Import Local Database" `
                    -reason "Failed to import production dump into local MySQL database '$LOCAL_DB_NAME'." `
                    -localDbStatus "WARNING - Import interrupted. Use local backup to restore if needed." `
                    -backupLocation $backupFilePath
}

Show-StepSuccess "Database '$LOCAL_DB_NAME' updated with production data."

# ============================================================
# [4/5] CLEAR LOCAL LARAVEL CACHE
# ============================================================

Show-StepHeader "4/5" "Clearing LOCAL Laravel cache..."

try {
    php artisan optimize:clear
    $artisanExit = $LASTEXITCODE
}
catch {
    $artisanExit = 1
}

if ($artisanExit -eq 0) {
    Show-StepSuccess "Laravel local cache cleared."
}
else {
    Write-Host "  -> WARNING: Failed to clear local cache. You can run 'php artisan optimize:clear' manually." -ForegroundColor Yellow
}

# ============================================================
# [5/5] CLEANUP TEMPORARY FILES
# ============================================================

Show-StepHeader "5/5" "Cleaning temporary files..."

# Clean remote temporary file
try {
    ssh "${REMOTE_USER}@${REMOTE_HOST}" "rm -f $REMOTE_PATH/$REMOTE_DUMP_FILE"
}
catch {
    Write-Host "  -> Note: Remote temp file cleanup skipped." -ForegroundColor Gray
}

# Clean local temporary dump
if (Test-Path $localDumpPath) {
    Remove-Item $localDumpPath -Force -ErrorAction SilentlyContinue
}

Show-StepSuccess "Temporary dump files removed. Local safeguard backup preserved:"
Write-Host "  -> $backupFilePath" -ForegroundColor DarkCyan

# ============================================================
# COMPLETION BANNER
# ============================================================

Write-Host ""
Write-Host "==================================================" -ForegroundColor Green
Write-Host "     KANBAN PPIC DATABASE SYNC COMPLETE           " -ForegroundColor Green
Write-Host "     PRODUCTION -> LOCAL                          " -ForegroundColor Green
Write-Host "==================================================" -ForegroundColor Green
Write-Host ""
