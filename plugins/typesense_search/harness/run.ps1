# Every battery through Docker Desktop: builds the php image when missing, starts the private Typesense, runs
# run_all.sh inside the php service. Output and exit code are run_all.sh's; results land in results/ (results/mysql/
# for the MySQL mode). -Db mysql (H9) starts the mysql service and runs core's SQL on it instead of SQLite.
# Usage: .\run.ps1 [-Db sqlite|mysql] [-Rebuild]
param([ValidateSet('sqlite', 'mysql')][string]$Db = 'sqlite', [switch]$Rebuild)
. (Join-Path $PSScriptRoot 'docker\common.ps1')
Initialize-Harness -Rebuild:$Rebuild
$Mode = @()
if ($Db -eq 'mysql') {
    $Mode = Start-MysqlMode
}
& docker @Compose run --rm @Mode php sh run_all.sh
exit $LASTEXITCODE
