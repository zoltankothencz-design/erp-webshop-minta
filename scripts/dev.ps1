# Fejlesztői segédszkript. Használat: .\scripts\dev.ps1 start | stop | status | console <parancs> | test
param(
    [Parameter(Position = 0)][string]$Cmd = 'help',
    [Parameter(ValueFromRemainingArguments = $true)][string[]]$Rest
)
$ErrorActionPreference = 'Stop'
$Root  = Split-Path -Parent $PSScriptRoot
$Php   = "$Root\.tools\php\php.exe"
$Ini   = "$Root\.tools\php\php.ini"
$Maria = "$Root\.tools\mariadb\bin"
$Data  = "$Root\.tools\data"
$Logs  = "$Root\storage\logs"

function Test-Port([int]$port) {
    try { $c = New-Object Net.Sockets.TcpClient; $c.Connect('127.0.0.1', $port); $c.Close(); return $true } catch { return $false }
}
function Start-Php([int]$port, [string]$docroot, [string]$router, [string]$name) {
    if (Test-Port $port) { Write-Host "$name már fut (port $port)"; return }
    Start-Process -FilePath $Php -ArgumentList @('-c', $Ini, '-S', "127.0.0.1:$port", '-t', $docroot, $router) `
        -WindowStyle Hidden -RedirectStandardError "$Logs\$name.err.log" -RedirectStandardOutput "$Logs\$name.out.log"
    Write-Host "$name indul: http://127.0.0.1:$port"
}

switch ($Cmd) {
    'start' {
        if (-not (Test-Path $Data)) { & "$Maria\mariadb-install-db.exe" --datadir=$Data --port=3307 | Out-Null }
        if (-not (Test-Port 3307)) {
            Start-Process -FilePath "$Maria\mariadbd.exe" -ArgumentList @("--datadir=$Data", '--port=3307', '--bind-address=127.0.0.1', '--console') `
                -WindowStyle Hidden -RedirectStandardError "$Logs\mariadb.err.log" -RedirectStandardOutput "$Logs\mariadb.out.log"
            1..30 | ForEach-Object { if (-not (Test-Port 3307)) { Start-Sleep -Milliseconds 500 } }
        }
        Write-Host "MariaDB: port 3307"
        Start-Php 8081 "$Root\mock" "$Root\mock\index.php" 'mock'
        Start-Php 8080 "$Root\public" "$Root\public\index.php" 'shop'
    }
    'stop' { Get-Process mariadbd, php -ErrorAction SilentlyContinue | Stop-Process -Force; Write-Host 'Leállítva.' }
    'status' { foreach ($p in 3307, 8080, 8081) { "{0}: {1}" -f $p, $(if (Test-Port $p) { 'fut' } else { 'nem fut' }) } }
    'console' { & $Php -c $Ini "$Root\bin\console.php" @Rest }
    'test' { & $Php -c $Ini "$Root\tests\run.php" }
    default { Write-Host 'Használat: .\scripts\dev.ps1 start | stop | status | console <parancs> | test' }
}
