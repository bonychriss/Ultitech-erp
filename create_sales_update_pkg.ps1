$source = 'c:\xampp\htdocs\public_html'
$staging = 'c:\xampp\htdocs\public_html\sales_update_pkg'
$zipFile = 'c:\xampp\htdocs\public_html\sales_updates.zip'

# Clean up previous runs
if (Test-Path $staging) { Remove-Item $staging -Recurse -Force }
if (Test-Path $zipFile) { Remove-Item $zipFile -Force }

# Create directories
New-Item -ItemType Directory -Path "$staging" | Out-Null

# Find files modified today (6/17/2026) under modules/sales, sales, modules/analytics
$filesList = [System.Collections.Generic.List[PSObject]]::new()
$scanned = Get-ChildItem -Path "$source\modules\sales", "$source\sales", "$source\modules\analytics" -Filter *.php -Recurse -ErrorAction SilentlyContinue | Where-Object { $_.LastWriteTime -ge (Get-Date "2026-06-17 00:00:00") }
foreach ($f in $scanned) { $filesList.Add($f) }

# Add specific files if changed
$specific = @("sidebar.php", "includes\ai_assistant_helper.php", "includes\config.php")
foreach ($sp in $specific) {
    $spPath = Join-Path $source $sp
    if (Test-Path $spPath) {
        $spItem = Get-Item $spPath
        if ($spItem.LastWriteTime -ge (Get-Date "2026-06-17 00:00:00")) {
            $filesList.Add($spItem)
        }
    }
}
$files = $filesList

Write-Host "Found $($files.Count) files modified today."

foreach ($file in $files) {
    # Compute relative path
    $relPath = $file.FullName.Substring($source.Length + 1)
    $destFile = Join-Path $staging $relPath
    $destDir = Split-Path $destFile -Parent
    
    # Ensure destination directory exists
    if (-not (Test-Path $destDir)) {
        New-Item -ItemType Directory -Path $destDir | Out-Null
    }
    
    Copy-Item $file.FullName -Destination $destFile
    Write-Host "Included: $relPath"
}

# Zip it
if (Test-Path $staging) {
    Compress-Archive -Path "$staging\*" -DestinationPath $zipFile -Force
    Write-Host "Successfully created $zipFile"
} else {
    Write-Host "No files found to zip."
}
