$source = 'c:\xampp\htdocs\staff'
$staging = 'c:\xampp\htdocs\staff\upload_pkg'
$zipFile = 'c:\xampp\htdocs\staff\updates.zip'

# Clean up previous runs
if (Test-Path $staging) { Remove-Item $staging -Recurse -Force }
if (Test-Path $zipFile) { Remove-Item $zipFile -Force }

# Create directories
New-Item -ItemType Directory -Path "$staging" | Out-Null
New-Item -ItemType Directory -Path "$staging\api" | Out-Null
New-Item -ItemType Directory -Path "$staging\includes" | Out-Null
New-Item -ItemType Directory -Path "$staging\employee" | Out-Null

# List of files to copy
$files = @(
    'forgot-password.php',
    'reset-password.php',
    'config_mail.php',
    'account.php',
    'api\get_user_email.php',
    'includes\mailer.php',
    'includes\SimpleSMTP.php',
    'includes\functions.php',
    'employee\account.php',
    'test_email_debug.php',
    'test_connectivity.php'
)

foreach ($file in $files) {
    $srcPath = "$source\$file"
    $destPath = "$staging\$file"
    if (Test-Path $srcPath) {
        Copy-Item $srcPath -Destination $destPath
        Write-Host "Included: $file"
    }
    else {
        Write-Warning "Missing file: $file"
    }
}

# Zip it
Compress-Archive -Path "$staging\*" -DestinationPath $zipFile
Write-Host "Created $zipFile"
