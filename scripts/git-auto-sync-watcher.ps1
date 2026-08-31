# Watches the repo and pushes changes after a quiet period (debounced).
param(
    [string]$RepoRoot = (Split-Path -Parent (Split-Path -Parent $PSScriptRoot)),
    [int]$DebounceSeconds = 45,
    [int]$PollSeconds = 5
)

$ErrorActionPreference = 'SilentlyContinue'

$stateDir = Join-Path $RepoRoot '.cursor'
$lockFile = Join-Path $stateDir 'auto-sync-watcher.lock'
$syncScript = Join-Path $RepoRoot 'scripts\git-sync.ps1'
$ignorePattern = '(\\\.git\\|\\node_modules\\|\\vendor\\|\\\.cursor\\auto-sync|\\storage\\|\\uploads\\|\\backups\\|\\tmp\\|\\logs\\|Thumbs\.db|\.log$|\.tmp$)'

if (-not (Test-Path $stateDir)) {
    New-Item -ItemType Directory -Path $stateDir -Force | Out-Null
}

if (Test-Path $lockFile) {
    $existingPid = Get-Content $lockFile -ErrorAction SilentlyContinue
    if ($existingPid -and (Get-Process -Id ([int]$existingPid) -ErrorAction SilentlyContinue)) {
        exit 0
    }
}

Set-Content -Path $lockFile -Value $PID -Encoding ASCII

function Get-LatestChangeUtc {
    $latest = (Get-Item $RepoRoot).LastWriteTimeUtc
    Get-ChildItem -Path $RepoRoot -Recurse -File -Force -ErrorAction SilentlyContinue |
        Where-Object { $_.FullName -notmatch $ignorePattern } |
        ForEach-Object {
            if ($_.LastWriteTimeUtc -gt $latest) {
                $latest = $_.LastWriteTimeUtc
            }
        }
    return $latest
}

$lastSeenChange = Get-LatestChangeUtc
$quietSince = [DateTime]::UtcNow

while ($true) {
    Start-Sleep -Seconds $PollSeconds

    $latestChange = Get-LatestChangeUtc
    if ($latestChange -gt $lastSeenChange) {
        $lastSeenChange = $latestChange
        $quietSince = [DateTime]::UtcNow
        continue
    }

    $quietFor = ([DateTime]::UtcNow - $quietSince).TotalSeconds
    if ($quietFor -ge $DebounceSeconds) {
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $syncScript -RepoRoot $RepoRoot
        $quietSince = [DateTime]::UtcNow
        $lastSeenChange = Get-LatestChangeUtc
    }
}
