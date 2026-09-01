# Export local Enerpize bundle and deploy to live (products + images, no re-fetch).
param(
    [int]$SinceId = 0,
    [switch]$ImagesOnly,
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'
$php = 'C:\xampp\php\php.exe'
$root = Split-Path -Parent $PSScriptRoot
$bundle = Join-Path $PSScriptRoot 'deploy\roadmaster-enerpize-bundle'
$sshKey = Join-Path $env:USERPROFILE '.ssh\ultitech_cpanel'
$sshHost = 'ultitech.io@ssh.us.stackcp.com'
$remoteRoot = '/home/sites/42b/a/a953b07082/public_html'
$remoteBundle = "$remoteRoot/scripts/deploy/roadmaster-enerpize-bundle"

$exportArgs = @("$root\scripts\export-roadmaster-enerpize-bundle.php")
if ($SinceId -gt 0) { $exportArgs += "--since-id=$SinceId" }
& $php @exportArgs
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

scp -i $sshKey -r $bundle "${sshHost}:$remoteBundle"
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

$importCmd = "cd $remoteRoot && HTTP_HOST=ultitech.io /usr/bin/php scripts/import-roadmaster-enerpize-bundle.php"
if ($ImagesOnly) { $importCmd += ' --images-only' }
if ($DryRun) { $importCmd += ' --dry-run' }

ssh -i $sshKey $sshHost $importCmd
