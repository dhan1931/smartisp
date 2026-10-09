<#
Levanta el sitio en local con el backend PHP real (php -S).
  -Target local  (por defecto) usa la copia local (scripts\dev-db.local.env): las escrituras están permitidas.
  -Target prod   usa la base real (scripts\dev-db.env): SOLO LECTURA por defecto; -Write las permite y pide confirmación.
Uso:  .\scripts\dev-php.ps1                      (http://localhost:8080, copia local)
      .\scripts\dev-php.ps1 -Target prod         (base real, solo lectura)
#>
param(
    [ValidateSet('local', 'prod')][string]$Target = 'local',
    [int]$Port = 8080,
    [switch]$Write,
    [string]$Php = "$env:USERPROFILE\tools\php83\php.exe"
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$envName = if ($Target -eq 'local') { 'dev-db.local.env' } else { 'dev-db.env' }
$envFile = Join-Path $PSScriptRoot $envName

if (-not (Test-Path $Php)) { throw "No se encuentra PHP en $Php" }
if (-not (Test-Path $envFile)) {
    throw "Falta $envFile. Copie scripts\dev-db.env.example como scripts\$envName y complete los datos."
}

foreach ($line in Get-Content $envFile) {
    if ($line -match '^\s*#' -or $line -notmatch '^\s*([A-Z_]+)\s*=\s*(.*?)\s*$') { continue }
    Set-Item -Path "Env:$($Matches[1])" -Value $Matches[2].Trim('"').Trim("'")
}
foreach ($key in 'MYSQL_HOST', 'MYSQL_DATABASE', 'MYSQL_USER', 'MYSQL_PASSWORD') {
    $value = [Environment]::GetEnvironmentVariable($key, 'Process')
    if ([string]::IsNullOrWhiteSpace($value) -or $value -match '^<.*>$') {
        throw "$key no está definido en scripts\$envName"
    }
}
$dbPort = if ($env:MYSQL_PORT) { [int]$env:MYSQL_PORT } else { 3306 }

if ($Target -eq 'local') {
    $env:SMARTISP_READONLY = '0'
    $env:SMARTISP_UPLOADS_DIR = Join-Path $root 'uploads'
    $mode = 'copia local (escritura permitida)'
} elseif ($Write) {
    Write-Host "ATENCIÓN: las escrituras estarán PERMITIDAS contra $($env:MYSQL_HOST)/$($env:MYSQL_DATABASE)." -ForegroundColor Red
    Write-Host 'Cualquier cambio o borrado desde el panel afecta esa base.' -ForegroundColor Red
    if ((Read-Host 'Escriba ESCRIBIR para continuar') -ne 'ESCRIBIR') { throw 'Cancelado.' }
    $env:SMARTISP_READONLY = '0'
    $mode = 'BASE REAL, ESCRITURA'
} else {
    $env:SMARTISP_READONLY = '1'
    $mode = 'base real, solo lectura'
}

$reachable = Test-NetConnection -ComputerName $env:MYSQL_HOST -Port $dbPort -InformationLevel Quiet -WarningAction SilentlyContinue
if (-not $reachable) {
    Write-Warning "El puerto $dbPort de $($env:MYSQL_HOST) no responde."
    if ($Target -eq 'prod') { Write-Warning 'En hPanel > Bases de datos > MySQL remoto hay que autorizar la IP de esta conexión.' }
}

Write-Host "Sitio: http://localhost:$Port  |  Base: $($env:MYSQL_HOST):$dbPort/$($env:MYSQL_DATABASE)  |  Modo: $mode"
& $Php -c (Join-Path (Split-Path $Php) 'php.ini') -S "127.0.0.1:$Port" -t $root (Join-Path $PSScriptRoot 'dev-router.php')
