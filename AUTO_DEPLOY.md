# Auto-Deploy to InfinityFree

This project includes an automatic deployment system that watches for file changes and automatically uploads them to InfinityFree hosting.

## Quick Start

### Option 1: Using the Batch File (Easiest)
1. Double-click `scripts/start-auto-deploy.bat`
2. The watcher will start and monitor your project for changes
3. When you save a file, it will automatically deploy after 3 seconds of inactivity
4. Press `Ctrl+C` to stop the watcher

### Option 2: Using PowerShell
1. Open PowerShell
2. Navigate to your project directory
3. Run: `.\scripts\auto-deploy.ps1`

### Option 3: Using VS Code/Cursor Tasks
1. Press `Ctrl+Shift+P` (or `Cmd+Shift+P` on Mac)
2. Type "Tasks: Run Task"
3. Select "Start Auto-Deploy to InfinityFree"
4. The watcher will run in a new terminal panel

## How It Works

1. **File Watcher**: Monitors all files in your project directory
2. **Debouncing**: Waits 3 seconds after the last file change before deploying (prevents multiple deployments)
3. **Automatic Upload**: Uses the existing `scripts/deploy.php` script to upload changed files via FTP
4. **Smart Exclusions**: Automatically excludes:
   - Git files (`.git/`)
   - Node modules
   - Upload directories (`assets/uploads/`, `assets/signatures/`)
   - Documentation files (`.md`)
   - Database files
   - Configuration files with secrets

## Configuration

### Adjust PHP Path
If your PHP installation is not at `C:\xampp\php\php.exe`, edit `scripts/auto-deploy.ps1` and update the `$phpExe` variable:

```powershell
$phpExe = "C:\path\to\your\php.exe"
```

### Adjust Debounce Time
To change how long the script waits before deploying (default: 3 seconds), edit `scripts/auto-deploy.ps1`:

```powershell
$debounceSeconds = 5  # Change to 5 seconds
```

### Exclude Additional Files
To exclude more files or directories, edit the `$excludePatterns` array in `scripts/auto-deploy.ps1`:

```powershell
$excludePatterns = @(
    "**\.git\**",
    "**\your-custom-folder\**",
    # Add more patterns here
)
```

## FTP Configuration

Make sure your FTP credentials are configured in `scripts/deploy.config.php`:

```php
return [
    'host' => 'ftpupload.net',
    'port' => 21,
    'user' => 'your_username',
    'pass' => 'your_password',
    'secure' => true,
    'remoteDir' => '/htdocs/',
    // ... other settings
];
```

## Manual Deployment

If you want to deploy manually without waiting for auto-deploy:

### Using VS Code/Cursor Task
1. Press `Ctrl+Shift+P`
2. Type "Tasks: Run Task"
3. Select "Deploy to InfinityFree (One-time)"

### Using Command Line
```bash
C:\xampp\php\php.exe scripts\deploy.php
```

## Troubleshooting

### PowerShell Execution Policy Error
If you get an execution policy error, run PowerShell as Administrator and execute:
```powershell
Set-ExecutionPolicy -ExecutionPolicy RemoteSigned -Scope CurrentUser
```

### PHP Not Found
- Make sure XAMPP is installed
- Update the `$phpExe` path in `scripts/auto-deploy.ps1`
- Or add PHP to your system PATH

### Deployment Fails
- Check your FTP credentials in `scripts/deploy.config.php`
- Verify your InfinityFree FTP server is accessible
- Check the output of the deploy script for specific error messages

### Too Many Deployments
- Increase the `$debounceSeconds` value to wait longer between deployments
- Check if multiple file watchers are running

## Notes

- The auto-deploy script excludes sensitive files like `deploy.config.php` and upload directories
- Changes to upload directories and signatures are NOT automatically deployed (to avoid overwriting user data)
- The script will show a notification for each file change
- Deployment output will be displayed in the console

## Security

⚠️ **Important**: Never commit `scripts/deploy.config.php` to version control. It contains your FTP credentials. It's already excluded in the deployment script.

## Stopping the Watcher

To stop the file watcher:
- Press `Ctrl+C` in the terminal where it's running
- Close the terminal window
- Stop the VS Code/Cursor task if running as a task

---

Happy coding! Your changes will now automatically sync to InfinityFree. 🚀

