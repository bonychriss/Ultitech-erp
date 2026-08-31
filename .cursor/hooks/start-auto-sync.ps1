# Start background file watcher when a Cursor session opens.
$ErrorActionPreference = 'SilentlyContinue'

$null = [Console]::In.ReadToEnd()

$repoRoot = (Get-Location).Path
$watcherScript = Join-Path $repoRoot 'scripts\git-auto-sync-watcher.ps1'
$lockFile = Join-Path $repoRoot '.cursor\auto-sync-watcher.lock'

if (-not (Test-Path $watcherScript)) {
    exit 0
}

if (Test-Path $lockFile) {
    $existingPid = Get-Content $lockFile -ErrorAction SilentlyContinue
    if ($existingPid -and (Get-Process -Id ([int]$existingPid) -ErrorAction SilentlyContinue)) {
        exit 0
    }
}

Start-Process powershell.exe -ArgumentList @(
    '-NoProfile',
    '-ExecutionPolicy', 'Bypass',
    '-WindowStyle', 'Hidden',
    '-File', $watcherScript,
    '-RepoRoot', $repoRoot
) | Out-Null

exit 0
