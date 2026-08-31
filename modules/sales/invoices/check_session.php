<?php
/**
 * Online Session Inspector
 * Access via: https://ultitech.io/modules/sales/invoices/check_session.php?company_slug=ultimate
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once dirname(__DIR__, 3) . '/includes/config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== Session & Auth Diagnostic ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "SID: " . session_id() . "\n";
echo "Session Name: " . session_name() . "\n";
echo "Session Cookie Params: " . json_encode(session_get_cookie_params()) . "\n";
echo "Session Content: " . json_encode($_SESSION) . "\n";

echo "\n--- Validation Checks ---\n";
$isLoggedIn = isset($_SESSION['user_id']);
echo "isLoggedIn(): " . ($isLoggedIn ? "YES (User ID: {$_SESSION['user_id']})" : "NO") . "\n";

$companySlug = getRequestedCompanySlug();
echo "Requested Slug: '$companySlug'\n";

$sessionCompanyId = $_SESSION['company_id'] ?? null;
echo "Session Company ID: " . var_export($sessionCompanyId, true) . "\n";

$currentCompanyId = currentCompanyId();
echo "currentCompanyId(): " . var_export($currentCompanyId, true) . "\n";

if ($isLoggedIn) {
    if (isCompanyScopingEnabled() && empty($_SESSION['company_id'])) {
        echo "WARNING: isCompanyScopingEnabled() is true but session company_id is empty!\n";
    }

    if (isCompanyScopingEnabled() && $companySlug !== '') {
        $requestedCompany = findCompanyBySlug($companySlug);
        if (!$requestedCompany) {
            echo "ERROR: Requested company with slug '$companySlug' not found in DB!\n";
        } else {
            $requestedCompanyId = (int) ($requestedCompany['id'] ?? 0);
            echo "Requested Company ID from DB: $requestedCompanyId\n";
            echo "Requested Company Status: " . ($requestedCompany['status'] ?? 'N/A') . "\n";
            if ($requestedCompanyId !== (int) $sessionCompanyId) {
                echo "FAIL: Mismatch! Requested Company ID ($requestedCompanyId) != Session Company ID ($sessionCompanyId)\n";
                echo "This will trigger clearAuthSession() and redirect to login!\n";
            } else {
                echo "SUCCESS: Company ID matches Session Company ID.\n";
            }
        }
    }
} else {
    echo "User is not logged in. requireLogin() will redirect to: " . ($companySlug !== '' ? company_login_url($companySlug) : app_url('/login.php')) . "\n";
}

echo "\nDiagnostic complete.\n";
