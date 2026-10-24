# One battery through Docker Desktop, the private Typesense started first. Output and exit code are the
# battery's (0 green, 1 a failure, 2 skipped). -Db mysql (H9) runs it on the mysql service instead of SQLite.
# Usage: .\battery.ps1 A3_schema [-Db sqlite|mysql] [-Rebuild]
param([string]$Name, [ValidateSet('sqlite', 'mysql')][string]$Db = 'sqlite', [switch]$Rebuild)
if ($Name -eq '') {
    Write-Host 'Usage: .\battery.ps1 <battery> [-Db sqlite|mysql], e.g. .\battery.ps1 A3_schema'
    exit 2
}
$Name = [IO.Path]::GetFileNameWithoutExtension(($Name -replace '^ab[\/]', ''))
if (-not (Test-Path (Join-Path $PSScriptRoot "ab\$Name.php"))) {
    Write-Host "no battery ab/$Name.php"
    exit 2
}
. (Join-Path $PSScriptRoot 'docker\common.ps1')
Initialize-Harness -Rebuild:$Rebuild
$Mode = @()
if ($Db -eq 'mysql') {
    $Mode = Start-MysqlMode
}
& docker @Compose run --rm @Mode php php "ab/$Name.php"
exit $LASTEXITCODE
