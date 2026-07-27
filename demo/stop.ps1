<#
.SYNOPSIS
    Stops and removes the read-only demo containers.
#>
$ErrorActionPreference = 'SilentlyContinue'

podman container rm --force betting-demo-api *> $null
podman container rm --force betting-demo-db *> $null
podman network rm betting-demo *> $null

Write-Host 'Demo stopped.' -ForegroundColor Green
