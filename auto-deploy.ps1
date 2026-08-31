# Auto-Deploy Script for InfinityFree
# Watches for file changes and automatically deploys to InfinityFree via FTP
# Usage: Run this script in PowerShell: .\scripts\auto-deploy.ps1

$ErrorActionPreference = "Continue"

# Configuration
$script:projectRoot = Split-Path -Parent $PSScriptRoot
$script:deployScript = Join-Path $PSScriptRoot "deploy.php"
$script:debounceSeconds = 3  # Wait 3 seconds after last change before deploying
$script:pollInterval = 1  # Check for changes every 1 second

# Try to find PHP executable
$script:phpExe = $null
$possiblePhpPaths = @(
    "C:\xampp\php\php.exe",
    "C:\php\php.exe"
)

foreach ($path in $possiblePhpPaths) {
    if (Test-Path $path) {
        $script:phpExe = $path
        break
    }
}

# Try to find php in PATH
if (-not $script:phpExe) {
    $phpCheck = Get-Command php -ErrorAction SilentlyContinue
    if ($phpCheck) {
        $script:phpExe = "php"
    }
}

$script:excludePatterns = @(
    "*.git*",
    "*\node_modules\*",
    "*\assets\uploads\*",
    "*\assets\signatures\*",
    "*.md",
    "*\tasks\*",
    "*\laravel-core\*",
    "*\database_*.sql",
    "*\reset_database.sh",
    "*\scripts\auto-deploy.ps1",
    "*\scripts\deploy.config.php"
)

# Check if PHP exists
if (-not $script:phpExe) {
    Write-Host "Error: PHP not found!" -ForegroundColor Red
    Write-Host "Please install PHP or update the script with your PHP path." -ForegroundColor Yellow
    Write-Host "Common locations:" -ForegroundColor Yellow
    Write-Host "  - C:\xampp\php\php.exe" -ForegroundColor Gray
    Write-Host "  - C:\php\php.exe" -ForegroundColor Gray
    Write-Host "  - Or add PHP to your system PATH" -ForegroundColor Gray
    exit 1
}

Write-Host "Using PHP: $($script:phpExe)" -ForegroundColor Gray

# Check if deploy script exists
if (-not (Test-Path $script:deployScript)) {
    Write-Host "Error: Deploy script not found at $($script:deployScript)" -ForegroundColor Red
    exit 1
}

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Auto-Deploy to InfinityFree" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Project Root: $($script:projectRoot)" -ForegroundColor Gray
Write-Host "Watching for file changes..." -ForegroundColor Green
Write-Host "Changes will be deployed after $($script:debounceSeconds) seconds of inactivity" -ForegroundColor Gray
Write-Host "Press Ctrl+C to stop" -ForegroundColor Yellow
Write-Host ""

$script:lastChangeTime = $null
$script:deployTimer = $null
$script:isDeploying = $false
$script:fileHashes = @{}

function Should-ExcludeFile {
    param([string]$relativePath)
    
    foreach ($pattern in $script:excludePatterns) {
        if ($relativePath -like $pattern) {
            return $true
        }
    }
    return $false
}

function Deploy-Files {
    if ($script:isDeploying) {
        Write-Host "Deployment already in progress, skipping..." -ForegroundColor Yellow
        return
    }
    
    $script:isDeploying = $true
    Write-Host "`n[$(Get-Date -Format 'HH:mm:ss')] Deploying changes..." -ForegroundColor Cyan
    
    try {
        # Execute deploy script and capture output
        & $script:phpExe $script:deployScript 2>&1 | ForEach-Object {
            Write-Host $_ -ForegroundColor Gray
        }
        $exitCode = $LASTEXITCODE
        
        if ($exitCode -eq 0) {
            Write-Host "[$(Get-Date -Format 'HH:mm:ss')] ✓ Deployment successful!" -ForegroundColor Green
        } else {
            Write-Host "[$(Get-Date -Format 'HH:mm:ss')] ✗ Deployment failed (exit code: $exitCode)" -ForegroundColor Red
        }
    } catch {
        Write-Host "[$(Get-Date -Format 'HH:mm:ss')] ✗ Deployment error: $_" -ForegroundColor Red
    } finally {
        $script:isDeploying = $false
        $script:lastChangeTime = $null
        Write-Host ""
    }
}

function Schedule-Deploy {
    param([string]$fileName)
    
    $script:lastChangeTime = Get-Date
    
    # Clear existing timer
    if ($script:deployTimer) {
        $script:deployTimer.Stop()
        $script:deployTimer.Dispose()
    }
    
    # Create new timer
    $script:deployTimer = New-Object System.Timers.Timer
    $script:deployTimer.Interval = $script:debounceSeconds * 1000
    $script:deployTimer.AutoReset = $false
    $script:deployTimer.Add_Elapsed({
        Deploy-Files
    })
    $script:deployTimer.Start()
}

function Get-FileSignature {
    param([string]$filePath)
    try {
        if (Test-Path $filePath -PathType Leaf) {
            $file = Get-Item $filePath -ErrorAction SilentlyContinue
            if ($file) {
                return "$($file.LastWriteTime.Ticks)-$($file.Length)"
            }
        }
    } catch {
        return $null
    }
    return $null
}

function Check-Files {
    try {
        $files = Get-ChildItem -Path $script:projectRoot -Recurse -File -ErrorAction SilentlyContinue
        
        foreach ($file in $files) {
            $fullPath = $file.FullName
            $relativePath = $fullPath.Replace($script:projectRoot, "").Replace("\", "/").TrimStart("/")
            
            # Check if excluded
            if (Should-ExcludeFile -relativePath $relativePath) {
                continue
            }
            
            $currentSig = Get-FileSignature -filePath $fullPath
            $oldSig = $script:fileHashes[$fullPath]
            
            if ($null -ne $currentSig) {
                if ($null -ne $oldSig -and $oldSig -ne $currentSig) {
                    # File changed
                    Write-Host "[$(Get-Date -Format 'HH:mm:ss')] Changed: $relativePath" -ForegroundColor Yellow
                    Schedule-Deploy -fileName $relativePath
                    $script:fileHashes[$fullPath] = $currentSig
                } elseif ($null -eq $oldSig) {
                    # New file
                    $script:fileHashes[$fullPath] = $currentSig
                }
            } elseif ($null -ne $oldSig) {
                # File deleted
                Write-Host "[$(Get-Date -Format 'HH:mm:ss')] Deleted: $relativePath" -ForegroundColor Yellow
                $script:fileHashes.Remove($fullPath)
                Schedule-Deploy -fileName $relativePath
            }
        }
        
        # Clean up hashes for files that no longer exist
        $keysToRemove = @()
        foreach ($key in $script:fileHashes.Keys) {
            if (-not (Test-Path $key -ErrorAction SilentlyContinue)) {
                $keysToRemove += $key
            }
        }
        foreach ($key in $keysToRemove) {
            $script:fileHashes.Remove($key)
        }
    } catch {
        # Ignore errors during file scanning
    }
}

# Initialize: scan all files
Write-Host "Scanning files for initial state..." -ForegroundColor Gray
Check-Files
Write-Host "Initial scan complete. Watching for changes..." -ForegroundColor Green
Write-Host ""

# Main polling loop
try {
    while ($true) {
        Start-Sleep -Seconds $script:pollInterval
        Check-Files
    }
} catch {
    Write-Host "`nError: $_" -ForegroundColor Red
} finally {
    # Cleanup
    if ($script:deployTimer) {
        $script:deployTimer.Stop()
        $script:deployTimer.Dispose()
    }
    Write-Host "`nWatcher stopped." -ForegroundColor Yellow
}
