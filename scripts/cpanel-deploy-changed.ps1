# Deploy changed files to StackCP/cPanel via FTPS (curl).
# Usage: powershell -File scripts/cpanel-deploy-changed.ps1
# Optional: -SinceCommit d7870aa  (default: origin/main)

param(
    [string]$SinceCommit = 'origin/main'
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

$git = 'C:\Program Files\Git\bin\git.exe'
if (-not (Test-Path $git)) {
    $git = (Get-Command git -ErrorAction SilentlyContinue).Source
}
if (-not $git) { throw 'Git not found' }

$prevEap = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
& $git fetch origin main 2>&1 | Out-Null
$ErrorActionPreference = $prevEap

$changed = & $git diff --name-only --diff-filter=ACMRT "$SinceCommit" HEAD
if (-not $changed) {
    Write-Host 'No changed files to deploy.'
    exit 0
}

$files = $changed | Where-Object {
    $_ -and
    (Test-Path (Join-Path $root $_)) -and
    $_ -notmatch '(^|/)(node_modules|vendor|\.git)(/|$)' -and
    $_ -notmatch '\.(exe|zip|7z|sql|md)$'
}

if (-not $files) {
    Write-Host 'No deployable files after filters.'
    exit 0
}

Write-Host "Deploying $($files.Count) file(s) to cPanel via FTPS..."
$fail = 0
foreach ($rel in $files) {
    & "$PSScriptRoot\ftp-upload.bat" $rel
    if ($LASTEXITCODE -ne 0) { $fail++ }
}

if ($fail -gt 0) {
    Write-Host "Finished with $fail failure(s)." -ForegroundColor Red
    exit 1
}

Write-Host 'cPanel file deploy completed.' -ForegroundColor Green
exit 0
