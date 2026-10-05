# Upload tracked repo files to the live site. Same path rules as cpanel-deploy-changed.ps1.
$ErrorActionPreference = 'Continue'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

function Get-FtpRemoteBase {
    $remote = '/public_html/ultimate'
    $localCmd = Join-Path $PSScriptRoot 'ftp-deploy.local.cmd'
    if (Test-Path $localCmd) {
        $m = Select-String -Path $localCmd -Pattern '^\s*set\s+FTP_REMOTE=(.+)$' | Select-Object -First 1
        if ($m) { $remote = $m.Matches[0].Groups[1].Value.Trim().Trim('"') }
    }
    return $remote.TrimEnd('/')
}

function Resolve-DeployUpload {
    param([string]$RelPath, [string]$RemoteBase, [string]$RepoRoot)
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
        if ($rel -match '^(employee|admin|deliveries|attendance|todo|stock|store-management-system)/') {
            return @(@{ Local = $rel; RemoteBase = $parentBase; Remote = $rel })
        }
        if ($rel -match '^(includes|erp-laravel|modules|assets|vendor|letterhead|home-ui|weekly-tasks-ui|weekly_tasks|select-module-ui)/' -or $rel -match '^env(\.|$)') {
            return @(@{ Local = $rel; RemoteBase = $parentBase; Remote = $rel })
        }
        if ($rel -match '^(pricing\.php|home\.php)$') {
            return @(@{ Local = $rel; RemoteBase = $parentBase; Remote = $rel })
        }
    }
    return @(@{ Local = $rel; RemoteBase = $RemoteBase; Remote = $rel })
}

function Encode-FtpPath([string]$rel) {
    $parts = ($rel -replace '\\', '/').Split('/') | ForEach-Object { [uri]::EscapeDataString($_) }
    return ($parts -join '/')
}

$git = 'C:\Program Files\Git\bin\git.exe'
if (-not (Test-Path $git)) { $git = (Get-Command git).Source }

$remoteBase = Get-FtpRemoteBase
$tracked = & $git ls-files
$files = $tracked | Where-Object {
    $_ -and
    (Test-Path (Join-Path $root $_)) -and
    $_ -notmatch '(^|/)(node_modules|vendor|\.git)(/|$)' -and
    $_ -notmatch '\.(exe|zip|7z|sql|md)$' -and
    $_ -notmatch '^\.cursor/' -and
    $_ -notmatch '(^|/)ftp-password\.txt$'
}

$uploads = @()
foreach ($rel in $files) {
    $mapped = @(Resolve-DeployUpload -RelPath $rel -RemoteBase $remoteBase -RepoRoot $root)
    if ($mapped.Count -eq 0) { continue }
    $uploads += $mapped
}

$passFile = Join-Path $PSScriptRoot 'ftp-password.txt'
$pass = (Get-Content -LiteralPath $passFile -Raw).Trim()
$hostName = 'ftp.ultitech.io'
$user = 'ultitech.io'
$localCmd = Join-Path $PSScriptRoot 'ftp-deploy.local.cmd'
if (Test-Path $localCmd) {
    $hm = Select-String -Path $localCmd -Pattern '^\s*set\s+FTP_HOST=(.+)$' | Select-Object -First 1
    $um = Select-String -Path $localCmd -Pattern '^\s*set\s+FTP_USER=(.+)$' | Select-Object -First 1
    if ($hm) { $hostName = $hm.Matches[0].Groups[1].Value.Trim().Trim('"') }
    if ($um) { $user = $um.Matches[0].Groups[1].Value.Trim().Trim('"') }
}

$dirSet = @{}
foreach ($item in $uploads) {
    $remote = ($item.Remote -replace '\\', '/').Trim('/')
    $slash = $remote.LastIndexOf('/')
    $relDir = if ($slash -lt 0) { '' } else { $remote.Substring(0, $slash) }
    $base = $item.RemoteBase.TrimEnd('/')
    $full = if ($relDir) { "$base/$relDir" } else { $base }
    $dirSet[$full] = $true
}
$dirList = @($dirSet.Keys | Sort-Object { $_.Length })
$dirFile = Join-Path $env:TEMP 'ultitech-deploy-dirs.txt'
$utf8 = New-Object System.Text.UTF8Encoding $false
[IO.File]::WriteAllLines($dirFile, $dirList, $utf8)
$mkdirPhp = Join-Path $env:TEMP 'ultitech-deploy-mkdir.php'
$mkdirSource = @'
<?php
$pass = trim(file_get_contents($argv[1]));
$dirs = file($argv[2], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
function open_ftps($pass) {
    $ftp = @ftp_ssl_connect("ftp.ultitech.io", 21, 60);
    if (!$ftp || !@ftp_login($ftp, "ultitech.io", $pass) || !@ftp_pasv($ftp, true)) {
        return null;
    }
    return $ftp;
}
$ftp = open_ftps($pass);
if (!$ftp) {
    fwrite(STDERR, "FTPS login failed\n");
    exit(1);
}
$known = array();
$fail = 0;
$created = 0;
$ops = 0;
foreach ($dirs as $dir) {
    $dir = str_replace("\\", "/", trim($dir));
    if ($dir === "") continue;
    $parts = explode("/", trim($dir, "/"));
    $built = "";
    foreach ($parts as $part) {
        if ($part === "") continue;
        $built .= "/" . $part;
        if (isset($known[$built])) continue;
        if ($ops >= 70) {
            @ftp_close($ftp);
            $ftp = open_ftps($pass);
            $ops = 0;
            if (!$ftp) { fwrite(STDERR, "FTPS reconnect failed\n"); exit(1); }
        }
        $resp = @ftp_raw($ftp, "MKD " . $built);
        $ops++;
        $line = (is_array($resp) && isset($resp[0])) ? $resp[0] : "";
        if ($line === "") {
            @ftp_close($ftp);
            $ftp = open_ftps($pass);
            $ops = 1;
            if (!$ftp) { fwrite(STDERR, "FTPS reconnect failed\n"); exit(1); }
            $resp = @ftp_raw($ftp, "MKD " . $built);
            $line = (is_array($resp) && isset($resp[0])) ? $resp[0] : "";
        }
        $code = substr($line, 0, 3);
        if ($code === "257" || $code === "550") {
            $known[$built] = true;
            if ($code === "257") $created++;
            continue;
        }
        fwrite(STDERR, "mkdir failed: $built ($line)\n");
        $fail++;
        break;
    }
}
echo "Directories ready (" . count($known) . " created $created, failed $fail)\n";
exit($fail > 0 ? 1 : 0);
'@
[IO.File]::WriteAllText($mkdirPhp, $mkdirSource, $utf8)
Write-Host "Creating $($dirList.Count) remote folder(s)..."
& 'c:\xampp\php\php.exe' $mkdirPhp $passFile $dirFile
if ($LASTEXITCODE -ne 0) {
    Write-Host "Some folders could not be created. Continuing with the file upload."
}
Remove-Item -LiteralPath $dirFile, $mkdirPhp -Force -ErrorAction SilentlyContinue

Write-Host "Uploading $($uploads.Count) file(s) from the repo..."
$chunkSize = 120
$fail = 0
$ok = 0
$chunks = [Math]::Ceiling($uploads.Count / $chunkSize)
for ($c = 0; $c -lt $chunks; $c++) {
    $slice = $uploads | Select-Object -Skip ($c * $chunkSize) -First $chunkSize
    $cfg = Join-Path $env:TEMP ("ultitech-deploy-" + $c + ".txt")
    $lines = New-Object System.Collections.Generic.List[string]
    $first = $true
    foreach ($item in $slice) {
        $localPath = (Join-Path $root ($item.Local -replace '/', [IO.Path]::DirectorySeparatorChar))
        if (-not (Test-Path -LiteralPath $localPath)) { continue }
        $localFwd = ([IO.Path]::GetFullPath($localPath)) -replace '\\', '/'
        $remote = Encode-FtpPath $item.Remote
        $base = $item.RemoteBase.TrimEnd('/')
        $url = "ftp://${hostName}${base}/$remote"
        if (-not $first) { $lines.Add('--next') }
        $first = $false
        $auth = ($user + ':' + $pass) -replace '"', '""'
        $lines.Add('user = "' + $auth + '"')
        $lines.Add('upload-file = "' + ($localFwd -replace '"', '""') + '"')
        $lines.Add('url = "' + $url + '"')
    }
    $utf8 = New-Object System.Text.UTF8Encoding $false
    [IO.File]::WriteAllText($cfg, (($lines -join "`r`n") + "`r`n"), $utf8)
    & curl.exe --parallel --parallel-max 8 --ftp-create-dirs -sS -k --ssl-reqd -K $cfg
    $chunkExit = $LASTEXITCODE
    Remove-Item -LiteralPath $cfg -Force -ErrorAction SilentlyContinue
    if ($chunkExit -eq 0) {
        $ok += $slice.Count
        Write-Host "CHUNK $($c + 1)/$chunks ok ($ok uploaded)"
        continue
    }
    Write-Host "CHUNK $($c + 1)/$chunks retrying one by one (exit $chunkExit)..."
    foreach ($item in $slice) {
        $localPath = (Join-Path $root ($item.Local -replace '/', [IO.Path]::DirectorySeparatorChar))
        if (-not (Test-Path -LiteralPath $localPath)) { continue }
        $remote = Encode-FtpPath $item.Remote
        $base = $item.RemoteBase.TrimEnd('/')
        $url = "ftp://${hostName}${base}/$remote"
        & curl.exe --ftp-create-dirs -sS -f -k --ssl-reqd -T $localPath -u ($user + ':' + $pass) $url
        if ($LASTEXITCODE -ne 0) {
            Write-Host "FAILED: $($item.Local) -> $base/$($item.Remote)"
            $fail++
        } else {
            $ok++
        }
    }
    Write-Host "CHUNK $($c + 1)/$chunks retry done ($ok uploaded, $fail failed)"
}

if ($fail -gt 0) {
    Write-Host "Finished with $fail failed file(s). $ok file(s) uploaded."
    exit 1
}
Write-Host "Repo upload completed. $ok file(s)."
exit 0
