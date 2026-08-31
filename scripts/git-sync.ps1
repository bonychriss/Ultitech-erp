# Sync local changes to GitHub (add, commit if dirty, push).
param(
    [string]$RepoRoot = (Split-Path -Parent (Split-Path -Parent $PSScriptRoot))
)

$ErrorActionPreference = 'Stop'

function Get-GitExe {
    $paths = @(
        'C:\Program Files\Git\cmd\git.exe',
        'C:\Program Files\Git\bin\git.exe'
    )
    foreach ($p in $paths) {
        if (Test-Path $p) { return $p }
    }
    $cmd = Get-Command git -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    throw 'Git not found. Install Git for Windows first.'
}

function Write-SyncLog {
    param([string]$Message)
    $logDir = Join-Path $RepoRoot '.cursor'
    if (-not (Test-Path $logDir)) {
        New-Item -ItemType Directory -Path $logDir -Force | Out-Null
    }
    $line = "[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message
    Add-Content -Path (Join-Path $logDir 'auto-sync.log') -Value $line -Encoding UTF8
}

try {
    Set-Location $RepoRoot
    $git = Get-GitExe
    $env:Path = "C:\Program Files\Git\cmd;C:\Program Files\GitHub CLI;" + $env:Path

    & $git add -A 2>&1 | Out-Null
    $status = & $git status --porcelain 2>&1
    if (-not $status) {
        Write-SyncLog 'No changes to sync.'
        exit 0
    }

    $message = "Auto-sync $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')"
    & $git commit -m $message 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) {
        Write-SyncLog 'Commit failed (nothing to commit or hook rejected).'
        exit 1
    }

    & $git push origin HEAD 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) {
        Write-SyncLog 'Push failed. Check network or GitHub auth.'
        exit 1
    }

    Write-SyncLog "Synced: $message"
    exit 0
}
catch {
    Write-SyncLog ("Error: " + $_.Exception.Message)
    exit 1
}
