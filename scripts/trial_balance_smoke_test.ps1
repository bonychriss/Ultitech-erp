param(
    [string]$Url = 'http://localhost/public_html/ultimate/accounting/trial-balance?module=accounting'
)

$ErrorActionPreference = 'Stop'
$uri = [Uri]$Url
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$sessionCookie = [Environment]::GetEnvironmentVariable('TRIAL_BALANCE_SESSION_COOKIE')

if ($sessionCookie) {
    $match = [regex]::Match($sessionCookie, '^(?:PVSSESSID=)?([^;]+)$')
    if (-not $match.Success) {
        Write-Error 'TRIAL_BALANCE_SESSION_COOKIE must contain only the session ID, optionally prefixed with PVSSESSID=.'
        exit 2
    }

    $cookie = New-Object System.Net.Cookie('PVSSESSID', $match.Groups[1].Value, '/', $uri.Host)
    $session.Cookies.Add($uri, $cookie)
}

try {
    $response = Invoke-WebRequest -Uri $uri -WebSession $session -UseBasicParsing -TimeoutSec 30
} catch {
    Write-Error "Request failed: $($_.Exception.Message)"
    exit 1
}

if ($response.StatusCode -ne 200) {
    Write-Error "Expected HTTP 200 after redirects; got HTTP $($response.StatusCode)."
    exit 1
}

$finalUri = [Uri]$response.BaseResponse.ResponseUri
if (-not $sessionCookie -and $finalUri.AbsolutePath -match '/login/?$') {
    Write-Output "PASS: Trial Balance route is reachable and redirects unauthenticated users to login ($($finalUri.AbsolutePath))."
    Write-Output 'SKIP: Authenticated report rendering. Set TRIAL_BALANCE_SESSION_COOKIE to a valid local PVSSESSID to test the report itself.'
    exit 0
}

if ($finalUri.AbsolutePath -match '/login/?$') {
    Write-Error 'The provided session did not authenticate; report checks cannot run.'
    exit 1
}

if ($finalUri.AbsolutePath -notmatch '/accounting/trial-balance(?:\.php)?/?$') {
    Write-Error "Unexpected final URL: $($finalUri.AbsoluteUri)"
    exit 1
}

$html = [string]$response.Content
$required = @(
    'Trial Balance',
    'Nominal',
    'Debit',
    'Credit',
    'Net',
    'Total Variance',
    'glrTrialSearch',
    'glrTrialRows',
    'glrTrialPagination',
    'data-account-row',
    'from_date',
    'to_date'
)

foreach ($text in $required) {
    if (-not $html.Contains($text)) {
        Write-Error "Report is missing expected content: $text"
        exit 1
    }
}

if ($html -match '(?i)(Fatal error|Parse error|Uncaught (?:PDOException|Error|Exception)|Warning:|Notice:)') {
    Write-Error 'The report response contains a PHP error or warning.'
    exit 1
}

Write-Output "PASS: Authenticated Trial Balance page returned HTTP 200 at $($finalUri.AbsoluteUri)."
Write-Output "PASS: Found all $($required.Count) report, filter, search, and pagination markers."
