# Debounced git sync for Cursor hook events (agent / tab edits).
$ErrorActionPreference = 'SilentlyContinue'

$null = [Console]::In.ReadToEnd()

$repoRoot = (Get-Location).Path
$stateDir = Join-Path $repoRoot '.cursor'
$stampFile = Join-Path $stateDir 'auto-sync-pending.txt'
$syncScript = Join-Path $repoRoot 'scripts\git-sync.ps1'

if (-not (Test-Path $stateDir)) {
    New-Item -ItemType Directory -Path $stateDir -Force | Out-Null
}

Set-Content -Path $stampFile -Value ([DateTimeOffset]::UtcNow.ToUnixTimeSeconds()) -Encoding ASCII

Start-Job -ScriptBlock {
    param($Root, $Script, $Stamp, $Delay)
    Start-Sleep -Seconds $Delay
    if (-not (Test-Path $Stamp)) { return }
    $saved = Get-Content $Stamp -ErrorAction SilentlyContinue
    $now = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
    if (($now - [int64]$saved) -lt ($Delay - 2)) { return }
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $Script -RepoRoot $Root
} -ArgumentList $repoRoot, $syncScript, $stampFile, 30 | Out-Null

exit 0
