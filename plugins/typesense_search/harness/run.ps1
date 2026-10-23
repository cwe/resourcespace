# Every battery through Docker Desktop: builds the php image when missing, starts the private Typesense, runs
# run_all.sh inside the php service. Output and exit code are run_all.sh's; results land in results/.
# Usage: .\run.ps1 [-Rebuild]
param([switch]$Rebuild)
. (Join-Path $PSScriptRoot 'docker\common.ps1')
Initialize-Harness -Rebuild:$Rebuild
& docker @Compose run --rm php sh run_all.sh
exit $LASTEXITCODE
