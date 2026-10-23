# Dot-sourced by ..\run.ps1, ..\battery.ps1 and ..\check.ps1: the compose command, the Docker daemon check, the
# image build, and LF line endings for the shell scripts the containers run. Callers run
# "& docker @Compose run ..." and read $LASTEXITCODE: a function would swallow the container's output.
$Compose = @('compose', '-f', (Join-Path $PSScriptRoot 'compose.yaml'))
$HarnessDir = Split-Path -Parent $PSScriptRoot
$Checkout = (Resolve-Path (Join-Path $HarnessDir '..\..\..')).Path

# Build the php image when it is missing (or on -Rebuild) after checking that the daemon answers.
function Initialize-Harness
{
    param([switch]$Rebuild)
    $null = & docker version --format '{{.Server.Version}}' 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Host 'Docker Desktop is not running (docker version got no answer from the daemon). Start it and retry.'
        exit 2
    }
    Repair-LineEndings
    $null = & docker image inspect rs_typesense_harness/php 2>&1
    if ($Rebuild -or $LASTEXITCODE -ne 0) {
        Write-Host '== building the php image (first run; a few minutes)'
        & docker @Compose build php
        if ($LASTEXITCODE -ne 0) {
            Write-Host 'image build failed'
            exit 2
        }
    }
}

# A checkout made before the .gitattributes files (or with core.autocrlf on) can hold the harness *.sh files
# and the plugin's *.php files with CRLF: sh rejects the one, phpcs the other. Rewrite them with LF: that is what
# git itself writes for them, so git status stays clean.
function Repair-LineEndings
{
    $plugin = Split-Path -Parent $HarnessDir
    $files = @(Get-ChildItem -Path $HarnessDir -Filter '*.sh') + @(Get-ChildItem -Path $plugin -Filter '*.php' -Recurse)
    foreach ($file in $files) {
        $text = [IO.File]::ReadAllText($file.FullName)
        if ($text.Contains("`r")) {
            [IO.File]::WriteAllText($file.FullName, $text.Replace("`r", ''))
            Write-Host "converted $($file.Name) to LF line endings"
        }
    }
}
