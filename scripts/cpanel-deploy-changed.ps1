# Deploy changed files to StackCP/cPanel via FTPS (curl).
# Usage: powershell -File scripts/cpanel-deploy-changed.ps1
# Optional: -SinceCommit d7870aa  (default: origin/main)
#
# When FTP_REMOTE ends with /ultimate (company folder deploy), remap:
#   ultimate/.htaccess  -> .htaccess
#   ultimate/foo.php    -> foo.php
# and skip the monorepo root .htaccess (it must not overwrite company rules).

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

function Get-FtpRemoteBase {
    $remote = '/public_html/ultimate'
    $localCmd = Join-Path $PSScriptRoot 'ftp-deploy.local.cmd'
    if (Test-Path $localCmd) {
        $m = Select-String -Path $localCmd -Pattern '^\s*set\s+FTP_REMOTE=(.+)$' | Select-Object -First 1
        if ($m) {
            $remote = $m.Matches[0].Groups[1].Value.Trim().Trim('"')
        }
    }
    return $remote.TrimEnd('/')
}

function Resolve-DeployUpload {
    param(
        [string]$RelPath,
        [string]$RemoteBase
    )

    $rel = ($RelPath -replace '\\', '/').TrimStart('/')
    $isUltimateRemote = $RemoteBase -match '/ultimate$'

    if ($isUltimateRemote) {
        if ($rel -eq '.htaccess') {
            return $null  # never overwrite company .htaccess with monorepo root rules
        }
        if ($rel -eq 'ultimate/.htaccess') {
            return @{ Local = $rel; Remote = '.htaccess' }
        }
        if ($rel -match '^ultimate/([^/]+\.php)$') {
            return @{ Local = $rel; Remote = $Matches[1] }
        }
    }

    return @{ Local = $rel; Remote = $rel }
}

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

$remoteBase = Get-FtpRemoteBase
$uploads = @()
foreach ($rel in $files) {
    $mapped = Resolve-DeployUpload -RelPath $rel -RemoteBase $remoteBase
    if ($null -eq $mapped) {
        Write-Host "SKIP (protect company htaccess): $rel"
        continue
    }
    $uploads += $mapped
}

if (-not $uploads) {
    Write-Host 'No deployable files after remaps.'
    exit 0
}

Write-Host "Deploying $($uploads.Count) file(s) to cPanel via FTPS ($remoteBase)..."
$fail = 0
$pass = ''
$passFile = Join-Path $PSScriptRoot 'ftp-password.txt'
if (Test-Path $passFile) {
    $pass = (Get-Content -LiteralPath $passFile -Raw).Trim()
}
$hostName = 'ftp.ultitech.io'
$user = 'ultitech.io'
$localCmd = Join-Path $PSScriptRoot 'ftp-deploy.local.cmd'
if (Test-Path $localCmd) {
    $hm = Select-String -Path $localCmd -Pattern '^\s*set\s+FTP_HOST=(.+)$' | Select-Object -First 1
    $um = Select-String -Path $localCmd -Pattern '^\s*set\s+FTP_USER=(.+)$' | Select-Object -First 1
    if ($hm) { $hostName = $hm.Matches[0].Groups[1].Value.Trim().Trim('"') }
    if ($um) { $user = $um.Matches[0].Groups[1].Value.Trim().Trim('"') }
}

foreach ($item in $uploads) {
    $localRel = $item.Local
    $remoteRel = $item.Remote
    $localPath = Join-Path $root ($localRel -replace '/', [IO.Path]::DirectorySeparatorChar)
    if (-not (Test-Path $localPath)) {
        Write-Host "SKIP (missing): $localRel"
        continue
    }
    $url = "ftp://${hostName}${remoteBase}/$remoteRel"
    & curl.exe --ftp-create-dirs -sS -f -T $localPath -u "${user}:${pass}" $url
    if ($LASTEXITCODE -ne 0) {
        Write-Host "FAILED: $localRel -> $remoteRel"
        $fail++
    } else {
        if ($localRel -eq $remoteRel) {
            Write-Host "OK:     $localRel"
        } else {
            Write-Host "OK:     $localRel -> $remoteRel"
        }
    }
}

if ($fail -gt 0) {
    Write-Host "Finished with $fail failure(s)." -ForegroundColor Red
    exit 1
}

Write-Host 'cPanel file deploy completed.' -ForegroundColor Green
exit 0
