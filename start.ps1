<#
.SYNOPSIS
  Offbeat - one-command local start for Windows.
.DESCRIPTION
  if ($env_['DB_ROOT_PASSWORD']) { $env:MYSQL_PWD = $env_['DB_ROOT_PASSWORD'] }
  .\start.ps1 -Compat    Docker stack using pre-pulled php:8.3-apache + mysql:5.7 images
  .\start.ps1 -Native    No Docker: PHP built-in server + a MySQL/MariaDB already on this machine
  .\start.ps1 -Stop      Stop whatever this script started

  If PowerShell blocks the script, run it once as:
      powershell -ExecutionPolicy Bypass -File .\start.ps1
#>
[CmdletBinding()]
param(
  [switch]$Compat,
  [switch]$Native,
  [switch]$Stop
)
$ErrorActionPreference = 'Stop'
Set-Location -Path $PSScriptRoot

function Say  { param($m) Write-Host "`n==> $m" -ForegroundColor Green }
function Die  { param($m) Write-Host "`nError: $m" -ForegroundColor Red; exit 1 }
function Have { param($c) [bool](Get-Command $c -ErrorAction SilentlyContinue) }
function Secret { param([int]$n = 48) -join ((1..$n) | ForEach-Object { '0123456789abcdef'[(Get-Random -Maximum 16)] }) }

# ---------------------------------------------------------------- .env
if (-not (Test-Path '.env')) {
  Say 'Creating .env with random local database secrets'
  @(
    'APP_PORT=8088', 'APP_ENV=local', 'DB_DATABASE=offbeat', 'DB_USERNAME=offbeat',
    "DB_PASSWORD=$(Secret)", "DB_ROOT_PASSWORD=$(Secret)"
  ) | Set-Content -Path '.env' -Encoding ASCII
}
$env_ = @{}
Get-Content '.env' | Where-Object { $_ -match '^\s*[A-Z_][A-Z0-9_]*\s*=' } | ForEach-Object {
  $k, $v = $_ -split '=', 2
  $env_[$k.Trim()] = $v.Trim().Trim('"', "'")
}
$Port = if ($env_['APP_PORT']) { $env_['APP_PORT'] } else { '8088' }
$ComposeFile = if ($Compat) { 'compose.compat.yaml' } else { 'compose.yaml' }

# ---------------------------------------------------------------- private admin credentials
function Ensure-AccessFile {
  if (-not (Test-Path 'storage')) { New-Item -ItemType Directory -Path 'storage' | Out-Null }
  if (-not (Test-Path 'storage/local-access.json')) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'
    $pw = -join ((1..28) | ForEach-Object { $chars[(Get-Random -Maximum $chars.Length)] })
    [ordered]@{ email = 'admin@offbeat.local'; password = $pw } |
      ConvertTo-Json | Set-Content 'storage/local-access.json' -Encoding ASCII
  }
  Get-Content 'storage/local-access.json' -Raw | ConvertFrom-Json
}

# ---------------------------------------------------------------- stop
if ($Stop) {
  if (Test-Path 'storage/native-server.pid') {
    $pidValue = (Get-Content 'storage/native-server.pid' -Raw).Trim()
    if ($pidValue -match '^\d+$') {
      Get-Process -Id ([int]$pidValue) -ErrorAction SilentlyContinue | Stop-Process -Force
    }
    Remove-Item 'storage/native-server.pid' -ErrorAction SilentlyContinue
    Say 'Stopped the native PHP server.'
  }
  if (Have 'docker') {
    foreach ($f in 'compose.yaml', 'compose.compat.yaml') {
      docker compose -f $f down 2>$null | Out-Null
    }
  }
  Say 'Stopped. The database volume and uploads are preserved.'
  exit 0
}

# ---------------------------------------------------------------- native
if ($Native) {
  if (-not (Have 'php')) { Die 'php was not found on PATH. Install PHP 8.1+ (windows.php.net) with pdo_mysql and fileinfo enabled, or use Docker Desktop instead.' }
  $phpIni = (& php --ini 2>$null | Select-String 'Loaded Configuration File:\s+\(none\)')
  if ($phpIni) {
    $phpPath = (Get-Command php).Source
    $phpDir = Split-Path -Parent $phpPath
    $template = Join-Path $phpDir 'php.ini-development'
    if (Test-Path $template) {
      if (-not (Test-Path 'storage')) { New-Item -ItemType Directory -Path 'storage' | Out-Null }
      $nativeIni = Join-Path $PSScriptRoot 'storage/native-php.ini'
      $iniText = Get-Content $template -Raw
      $iniText = $iniText -replace '(?m)^;\s*extension_dir\s*=.*$', ('extension_dir = "' + (Join-Path $phpDir 'ext') + '"')
      $iniText = $iniText -replace '(?m)^;\s*extension=fileinfo\s*$', 'extension=fileinfo'
      $iniText = $iniText -replace '(?m)^;\s*extension=pdo_mysql\s*$', 'extension=pdo_mysql'
      Set-Content -Path $nativeIni -Value $iniText -Encoding ASCII
      $env:PHPRC = $nativeIni
      Say 'Configured PHP extensions from the bundled development template'
    }
  }
  & php -r "exit(extension_loaded('pdo_mysql') && extension_loaded('fileinfo') ? 0 : 1);"
  if ($LASTEXITCODE -ne 0) { Die 'PHP is missing pdo_mysql or fileinfo. Enable extension=pdo_mysql and extension=fileinfo in php.ini.' }

  $mysqlCmd = @('mariadb', 'mysql') | Where-Object { Have $_ } | Select-Object -First 1
  if (-not $mysqlCmd) {
    $workbenchClient = 'C:\Program Files\MySQL\MySQL Workbench 8.0 CE\mysql.exe'
    if (Test-Path $workbenchClient) { $mysqlCmd = $workbenchClient }
  }
  if (-not $mysqlCmd) { Die 'No mysql/mariadb client on PATH. -Native needs a local MySQL or MariaDB server.' }

  Say "Preparing the '$($env_['DB_DATABASE'])' database on 127.0.0.1:3306"
  $rootArgs = @('-h', '127.0.0.1', '-u', 'root')
  if ($env_['DB_ROOT_PASSWORD']) { $env:MYSQL_PWD = $env_['DB_ROOT_PASSWORD'] }
  $probeErrorAction = $ErrorActionPreference
  $ErrorActionPreference = 'Continue'
  & $mysqlCmd @rootArgs -e 'SELECT 1' 1>$null 2>$null
  if ($LASTEXITCODE -ne 0) {
    Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
    $rootArgs = @('-h', '127.0.0.1', '-u', 'root')
    & $mysqlCmd @rootArgs -e 'SELECT 1' 1>$null 2>$null
    if ($LASTEXITCODE -ne 0) {
      $ErrorActionPreference = $probeErrorAction
      Die 'Cannot reach the local database as root. Start MySQL/MariaDB, or set DB_ROOT_PASSWORD in .env.'
    }
  }
  $ErrorActionPreference = $probeErrorAction
  $sql = @"
CREATE DATABASE IF NOT EXISTS ``$($env_['DB_DATABASE'])`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$($env_['DB_USERNAME'])'@'127.0.0.1' IDENTIFIED BY '$($env_['DB_PASSWORD'])';
ALTER USER '$($env_['DB_USERNAME'])'@'127.0.0.1' IDENTIFIED BY '$($env_['DB_PASSWORD'])';
GRANT ALL PRIVILEGES ON ``$($env_['DB_DATABASE'])``.* TO '$($env_['DB_USERNAME'])'@'127.0.0.1';
FLUSH PRIVILEGES;
"@
  $sql | & $mysqlCmd @rootArgs
  Get-Content 'database/schema.sql' -Raw | & $mysqlCmd @rootArgs $($env_['DB_DATABASE'])

  $env:DB_HOST = '127.0.0.1'; $env:DB_PORT = '3306'
  foreach ($k in 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'APP_ENV') {
    if ($env_.ContainsKey($k)) { Set-Item -Path "env:$k" -Value $env_[$k] }
  }
  if (-not (Test-Path 'public/uploads')) { New-Item -ItemType Directory -Path 'public/uploads' | Out-Null }

  Say 'Seeding demonstration content'
  $access = Ensure-AccessFile
  $env:SEED_ADMIN_EMAIL = $access.email; $env:SEED_ADMIN_PASSWORD = $access.password
  & php scripts/seed.php
  if ($LASTEXITCODE -ne 0) { Die 'Seeding failed.' }
  & php scripts/demo-login.php

  if (Test-Path 'storage/native-server.pid') {
    $old = (Get-Content 'storage/native-server.pid' -Raw).Trim()
    if ($old -match '^\d+$') { Get-Process -Id ([int]$old) -ErrorAction SilentlyContinue | Stop-Process -Force }
  }
  Say 'Starting the PHP built-in server'
  $server = Start-Process -FilePath 'php' -ArgumentList @('-S', "127.0.0.1:$Port", '-t', 'public') `
    -RedirectStandardOutput 'storage/native-server.log' -RedirectStandardError 'storage/native-server.err.log' `
    -NoNewWindow -PassThru
  $server.Id | Set-Content 'storage/native-server.pid'
  Start-Sleep -Seconds 2
  if ($server.HasExited) { Die 'The PHP server exited immediately. See storage/native-server.err.log' }
}
# ---------------------------------------------------------------- docker
else {
  if (-not (Have 'docker')) { Die 'docker was not found. Install Docker Desktop, or use .\start.ps1 -Native' }
  docker info *> $null
  if ($LASTEXITCODE -ne 0) { Die 'The Docker daemon is not reachable. Start Docker Desktop, or use .\start.ps1 -Native' }

  Say "Starting the Docker stack ($ComposeFile)"
  $upArgs = @('compose', '-f', $ComposeFile, 'up', '-d', '--wait')
  if (-not $Compat) { $upArgs += '--build' }
  & docker @upArgs
  if ($LASTEXITCODE -ne 0) {
    if (-not $Compat) { Die 'The stack failed to start. If Docker cannot extract the MySQL 8.4 layers, retry with: .\start.ps1 -Compat' }
    Die "The compatibility stack failed to start. See: docker compose -f $ComposeFile logs"
  }
  docker compose -f $ComposeFile exec -T -u root web sh -c 'chown -R www-data:www-data public/uploads && chmod 2775 public/uploads'

  Say 'Seeding demonstration content'
  $access = Ensure-AccessFile
  docker compose -f $ComposeFile exec -T -e "SEED_ADMIN_EMAIL=$($access.email)" -e "SEED_ADMIN_PASSWORD=$($access.password)" web php scripts/seed.php
  if ($LASTEXITCODE -ne 0) { Die 'Seeding failed.' }
  docker compose -f $ComposeFile exec -T web php scripts/demo-login.php
}

Write-Host @"

  Offbeat is running:  http://localhost:$Port

  Demo administrator:  demo.admin@offbeat.local      / OffbeatAdmin!2026
  Demo traveller:      demo.traveller@offbeat.local  / OffbeatDemo!2026
  Private admin:       storage/local-access.json (generated, git-ignored)

  Stop with: .\start.ps1 -Stop
"@ -ForegroundColor Cyan
