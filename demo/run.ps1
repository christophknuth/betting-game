<#
.SYNOPSIS
    Starts the read-only demo: MariaDB with seeded data plus a PHP server.

.DESCRIPTION
    Everything runs in two containers, nothing is installed on the host.
    Stop again with demo/stop.ps1.

.PARAMETER Port
    Host port for the demo API. Defaults to 8080.
#>
param([int]$Port = 8080)

# The readiness loop deliberately probes a database that is not up yet, and
# podman writes those attempts to stderr. With 'Stop' PowerShell would turn the
# first attempt into a terminating error, so failures are checked via
# $LASTEXITCODE instead.
$ErrorActionPreference = 'Continue'

$root = Split-Path -Parent $PSScriptRoot
$network = 'betting-demo'
$dbName = 'betting-demo-db'
$apiName = 'betting-demo-api'
$image = 'betting-demo-php'

Write-Host '==> Preparing network' -ForegroundColor Cyan
podman network create $network *> $null

Write-Host '==> Removing previous demo containers' -ForegroundColor Cyan
podman container rm --force $dbName *> $null
podman container rm --force $apiName *> $null

Write-Host '==> Starting MariaDB with schema and demo data' -ForegroundColor Cyan
podman run -d --name $dbName --network $network `
    -e MARIADB_ROOT_PASSWORD=secret `
    -e MARIADB_DATABASE=betting_game `
    -v "$root\database\schema.sql:/docker-entrypoint-initdb.d/01-schema.sql:ro" `
    -v "$root\demo\seed.sql:/docker-entrypoint-initdb.d/02-seed.sql:ro" `
    mariadb:11.3 | Out-Null

Write-Host -NoNewline '    waiting for the database'
$ready = $false
for ($i = 0; $i -lt 60; $i++) {
    podman exec $dbName mariadb -uroot -psecret -e 'SELECT 1' *> $null
    if ($LASTEXITCODE -eq 0) { $ready = $true; break }
    Write-Host -NoNewline '.'
    Start-Sleep -Seconds 2
}
Write-Host ''

if (-not $ready) {
    Write-Host 'Database did not become ready. Logs:' -ForegroundColor Red
    podman logs --tail 30 $dbName
    exit 1
}

# The seed only lands if the predictions are there - guard against a half-run init
$rows = podman exec $dbName mariadb -uroot -psecret -N -B -e 'SELECT COUNT(*) FROM betting_game.prediction'
if ([int]$rows -eq 0) {
    Write-Host 'Demo data was not loaded.' -ForegroundColor Red
    exit 1
}
Write-Host "    demo data loaded ($rows predictions)" -ForegroundColor DarkGray

Write-Host '==> Building the PHP image' -ForegroundColor Cyan
podman build -q -t $image -f "$root\demo\Containerfile" $root | Out-Null

Write-Host '==> Starting the API' -ForegroundColor Cyan
podman run -d --name $apiName --network $network `
    -e DB_HOST=$dbName `
    -e DB_DATABASE=betting_game `
    -e DB_USERNAME=root `
    -e DB_PASSWORD=secret `
    -p "${Port}:8080" `
    -v "${root}:/app" `
    $image | Out-Null

Start-Sleep -Seconds 2

Write-Host ''
Write-Host "Demo is running on http://localhost:$Port" -ForegroundColor Green
Write-Host ''
Write-Host '  Overview          ' -NoNewline; Write-Host "http://localhost:$Port/" -ForegroundColor Yellow
Write-Host '  Seeded data       ' -NoNewline; Write-Host "http://localhost:$Port/demo-data" -ForegroundColor Yellow
Write-Host '  All predictions   ' -NoNewline; Write-Host "http://localhost:$Port/predictions" -ForegroundColor Yellow
Write-Host "  Alice's tips      " -NoNewline; Write-Host "http://localhost:$Port/participants/1/predictions" -ForegroundColor Yellow
Write-Host '  A result          ' -NoNewline; Write-Host "http://localhost:$Port/events/41/result" -ForegroundColor Yellow
Write-Host ''
Write-Host '  Stop with: demo\stop.ps1' -ForegroundColor DarkGray
