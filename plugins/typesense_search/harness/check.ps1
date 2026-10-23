# The code checks through Docker Desktop: composer install once (vendor/ lives on the "vendor" volume, not in
# the checkout), then phpcs with the repository's phpcs.xml.dist on the plugin and the repository-wide
# composer dev:phpstan. Exit 0 when both pass. Usage: .\check.ps1 [-Reinstall] [-Rebuild]
param([switch]$Reinstall, [switch]$Rebuild)
. (Join-Path $PSScriptRoot 'docker\common.ps1')
Initialize-Harness -Rebuild:$Rebuild

& docker @Compose run --rm php test -f /app/vendor/autoload.php
if ($Reinstall -or $LASTEXITCODE -ne 0) {
    Write-Host '== composer install (first run; a few minutes)'
    & docker @Compose run --rm --workdir /app php composer install --no-interaction --no-progress
    if ($LASTEXITCODE -ne 0) {
        Write-Host 'composer install failed'
        exit 2
    }
}

Write-Host '== phpcs plugins/typesense_search'
& docker @Compose run --rm --workdir /app php phpcs --standard=phpcs.xml.dist --runtime-set ignore_warnings_on_exit 1 plugins/typesense_search
$phpcs = $LASTEXITCODE
Write-Host '== phpstan (composer dev:phpstan)'
& docker @Compose run --rm --workdir /app php composer dev:phpstan -- --memory-limit=2G --no-progress
$phpstan = $LASTEXITCODE

Write-Host ''
Write-Host ('phpcs:   ' + $(if ($phpcs -eq 0) { 'no errors' } else { "exit $phpcs" }))
Write-Host ('phpstan: ' + $(if ($phpstan -eq 0) { 'no errors' } else { "exit $phpstan" }))
exit [int]($phpcs -ne 0 -or $phpstan -ne 0)
