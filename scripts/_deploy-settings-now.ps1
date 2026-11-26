# Temporary: deploy undeployed settings files since 48300307
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
        [string]$RemoteBase,
        [string]$RepoRoot
    )

    $rel = ($RelPath -replace '\\', '/').TrimStart('/')
    $isUltimateRemote = $RemoteBase -match '/ultimate$'
    $parentBase = ($RemoteBase -replace '/ultimate$', '')

    if ($isUltimateRemote) {
        if ($rel -eq '.htaccess') { return @() }
        if ($rel -eq 'ultimate/.htaccess') {
            return @(@{ Local = $rel; RemoteBase = $RemoteBase; Remote = '.htaccess' })
        }
        if ($rel -match '^ultimate/([^/]+\.php)$') {
            return @(@{ Local = $rel; RemoteBase = $RemoteBase; Remote = $Matches[1] })
        }
        if ($rel -match '^((?:employee|admin|attendance|stock|deliveries|todo)/.+|[^/]+)\.php$') {
            $stubRel = "ultimate/$rel"
            $stubPath = Join-Path $RepoRoot ($stubRel -replace '/', [IO.Path]::DirectorySeparatorChar)
            if ((Test-Path $stubPath) -and ($rel -notmatch '^ultimate/')) {
                return @(
                    @{ Local = $stubRel; RemoteBase = $RemoteBase; Remote = $rel },
                    @{ Local = $rel; RemoteBase = $parentBase; Remote = $rel }
                )
            }
        }
        if ($rel -match '^(employee|admin|deliveries|attendance|todo|stock|store-management-system|view-voucher-ui|login-ui)/') {
            return @(@{ Local = $rel; RemoteBase = $parentBase; Remote = $rel })
        }
        if ($rel -match '^(includes|accounting|erp|erp-laravel|modules|assets|vendor|letterhead|home-ui|weekly-tasks-ui|weekly_tasks|select-module-ui|ultitech-accounting)/' -or $rel -match '^env(\.|$)') {
            return @(@{ Local = $rel; RemoteBase = $parentBase; Remote = $rel })
        }
        if ($rel -match '^(pricing\.php|home\.php)$') {
            return @(@{ Local = $rel; RemoteBase = $parentBase; Remote = $rel })
        }
    }

    return @(@{ Local = $rel; RemoteBase = $RemoteBase; Remote = $rel })
}

$settingsPattern = '^(admin/(company-settings|email-settings|time-settings|whatsapp-settings|settings|stock-purchase-settings|network-settings|list-company-users|management)|admin/(company-settings|email-settings|time-settings|whatsapp-settings|settings|stock-purchase-settings|network-settings)\.php|ultimate/admin/settings\.php|includes/header_employee\.php|erp-laravel/app/Domains/Admin/AdminShell\.php|employee/(account\.php|includes/account-settings-view\.inc\.php))'

$changed = & $git diff --name-only --diff-filter=ACMRT 48300307 HEAD
$files = @()
foreach ($path in $changed) {
    if (-not $path) { continue }
    $norm = ($path -replace '\\', '/').Trim('"')
    if ($norm -notmatch $settingsPattern) { continue }
    $local = Join-Path $root ($norm -replace '/', [IO.Path]::DirectorySeparatorChar)
    if (-not (Test-Path $local)) { continue }
    if (Test-Path $local -PathType Container) { continue }
    $files += $norm
}

if (-not $files) {
    Write-Host 'No settings files to deploy.'
    exit 0
}

$remoteBase = Get-FtpRemoteBase
$uploads = @()
foreach ($rel in $files) {
    $mapped = @(Resolve-DeployUpload -RelPath $rel -RemoteBase $remoteBase -RepoRoot $root)
    if ($mapped.Count -eq 0) {
        Write-Host "SKIP (protect company paths): $rel"
        continue
    }
    $uploads += $mapped
}

# De-dupe by remote destination
$seen = @{}
$unique = @()
foreach ($item in $uploads) {
    $key = "$($item.RemoteBase)/$($item.Remote)"
    if ($seen.ContainsKey($key)) { continue }
    $seen[$key] = $true
    $unique += $item
}
$uploads = $unique

Write-Host "Deploying $($uploads.Count) settings upload(s) to cPanel via FTPS..."

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

$fail = 0
$ok = 0
foreach ($item in $uploads) {
    $localRel = $item.Local
    $remoteRel = $item.Remote
    $itemBase = if ($item.RemoteBase) { $item.RemoteBase } else { $remoteBase }
    $localPath = Join-Path $root ($localRel -replace '/', [IO.Path]::DirectorySeparatorChar)
    if (-not (Test-Path $localPath)) {
        Write-Host "SKIP (missing): $localRel"
        continue
    }
    $url = "ftp://${hostName}${itemBase}/$remoteRel"
    & curl.exe --ftp-create-dirs -sS -f -k --ssl-reqd -T $localPath -u ($user + ':' + $pass) $url
    if ($LASTEXITCODE -ne 0) {
        Write-Host "FAILED: $localRel -> ${itemBase}/$remoteRel"
        $fail++
    } else {
        Write-Host "OK:     $localRel -> ${itemBase}/$remoteRel"
        $ok++
    }
}

Write-Host "Finished. OK=$ok FAIL=$fail"
if ($fail -gt 0) { exit 1 }
Write-Host 'Settings deploy completed.' -ForegroundColor Green
exit 0
