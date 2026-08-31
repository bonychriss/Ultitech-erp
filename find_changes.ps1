$rootPath = "c:\xampp\htdocs\staff"
$zipFile = "incremental_deploy.zip"
$exclude = @(".git", ".github", ".gemini", ".agent", "node_modules", "staff_deploy_v4.zip", "incremental_deploy.zip", "deploy_full_v2.sql", "stock/stock_setup_full.sql", "stock/database.sql", "DEPLOY_INSTRUCTIONS.md", "create_deploy_zip.php")

# Get today's date at midnight
$today = (Get-Date).Date

$filesToZip = Get-ChildItem -Path $rootPath -Recurse -File | Where-Object { 
    $_.LastWriteTime.Date -eq $today -and 
    $_.FullName -notmatch "\\.git\\" -and
    $_.FullName -notmatch "\\.gemini\\" -and
    $_.FullName -notmatch "\\.agent\\" -and
    $_.Name -notin $exclude
}

Write-Host "Found $($filesToZip.Count) files modified today."

if ($filesToZip.Count -eq 0) {
    Write-Host "No files changed today."
    exit
}

# Create Zip
Compress-Archive -Path $filesToZip.FullName -DestinationPath $zipFile -Force
Write-Host "Created $zipFile with $($filesToZip.Count) files."
