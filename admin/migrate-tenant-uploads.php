<?php
/**
 * Platform admin: migrate legacy stock/uploads/products ? storage/tenant_{id}/products
 */
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

$pageError = '';
$pageTrace = '';

set_exception_handler(function ($e) use (&$pageError, &$pageTrace) {
    $pageError = $e->getMessage();
    $pageTrace = $e->getFile() . ':' . $e->getLine();
    http_response_code(500);
});

$migrateScript = __DIR__ . '/../stock/modules/uploads/migrate_tenant_uploads.php';
if (!is_file($migrateScript)) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body style="font-family:system-ui;padding:2rem">';
    echo '<h1>Migration script missing</h1>';
    echo '<p>Upload <code>stock/modules/uploads/migrate_tenant_uploads.php</code> to the server.</p>';
    echo '</body></html>';
    exit;
}

try {
    require_once __DIR__ . '/../includes/functions.php';
} catch (Throwable $e) {
    $pageError = 'Bootstrap failed: ' . $e->getMessage();
    $pageTrace = $e->getFile() . ':' . $e->getLine();
}

if ($pageError === '' && !function_exists('migrate_tenant_uploads_run')) {
    define('MIGRATE_TENANT_UPLOADS_SKIP_ENTRY', true);
    try {
        require_once $migrateScript;
    } catch (Throwable $e) {
        $pageError = 'Migration load failed: ' . $e->getMessage();
        $pageTrace = $e->getFile() . ':' . $e->getLine();
    }
}

if ($pageError === '' && !function_exists('migrate_tenant_uploads_run')) {
    $pageError = 'Migration functions not loaded. Re-upload stock/modules/uploads/migrate_tenant_uploads.php';
}

/**
 * Platform operators who may run tenant upload migration (not company-only staff).
 */
function migrate_tenant_uploads_can_access()
{
    if (!function_exists('isLoggedIn') || !isLoggedIn()) {
        return false;
    }
    if (function_exists('isPlatformOperator') && isPlatformOperator()) {
        return true;
    }
    if (function_exists('isSuperAdmin') && isSuperAdmin()) {
        return true;
    }
    return false;
}

/**
 * @return array<string, array<string, mixed>>
 */
function migrate_tenant_uploads_list_companies()
{
    if (!function_exists('migrate_tenant_uploads_company_map')) {
        return array();
    }
    return migrate_tenant_uploads_company_map();
}

function migrate_tenant_uploads_apply_session_company($slug)
{
    $slug = strtolower(trim((string) $slug));
    $map = migrate_tenant_uploads_list_companies();
    if (!isset($map[$slug])) {
        return false;
    }
    $row = $map[$slug];
    $_SESSION['company_id'] = (int) $row['id'];
    $_SESSION['company_slug'] = $slug;
    $_SESSION['migrate_tenant_company'] = $slug;
    if (!empty($row['id']) && function_exists('switchActiveCompany')) {
        @switchActiveCompany((int) $row['id']);
    }
    $name = '';
    try {
        $meta = isset($GLOBALS['control_pdo']) ? $GLOBALS['control_pdo'] : null;
        if ($meta instanceof PDO) {
            $st = $meta->prepare('SELECT company_name FROM companies WHERE id = ? LIMIT 1');
            $st->execute(array((int) $row['id']));
            $name = trim((string) $st->fetchColumn());
        }
    } catch (Throwable $e) {
    }
    if ($name !== '') {
        $_SESSION['company_name'] = $name;
    }

    return true;
}

$self = 'migrate-tenant-uploads.php';
$loginUrl = function_exists('app_url')
    ? app_url('/login.php?next=admin/migrate-tenant-uploads.php')
    : '/login.php?next=admin/migrate-tenant-uploads.php';

// --- Not logged in: prompt to sign in ---
if ($pageError === '' && (!function_exists('isLoggedIn') || !isLoggedIn())) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Sign in ù Upload migration</title>
        <style>
            body { font-family: system-ui, sans-serif; padding: 2rem; max-width: 480px; margin: 0 auto; line-height: 1.5; }
            .btn { display: inline-block; background: #2563eb; color: #fff; padding: 10px 18px; border-radius: 8px; text-decoration: none; font-weight: 600; }
            .muted { color: #64748b; font-size: 14px; }
        </style>
    </head>
    <body>
        <h1>Upload migration</h1>
        <p>Sign in with your <strong>platform administrator</strong> account (e.g. system admin), then choose which company to migrate.</p>
        <p><a class="btn" href="<?= htmlspecialchars($loginUrl) ?>">Sign in</a></p>
        <p class="muted">After login you will return here to pick Ultimate or Roadmaster.</p>
    </body>
    </html>
    <?php
    exit;
}

// --- Logged in but not platform admin ---
if ($pageError === '' && !migrate_tenant_uploads_can_access()) {
    header('Content-Type: text/html; charset=utf-8');
    $roleLabel = htmlspecialchars((string) ($_SESSION['role'] ?? 'unknown'));
    $emailLabel = htmlspecialchars((string) ($_SESSION['email'] ?? $_SESSION['username'] ?? 'user'));
    $reloginUrl = function_exists('app_url')
        ? app_url('/logout.php?next=admin/migrate-tenant-uploads.php')
        : '/logout.php?next=admin/migrate-tenant-uploads.php';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Administrator required</title>
        <style>
            body { font-family: system-ui, sans-serif; padding: 2rem; max-width: 560px; margin: 0 auto; line-height: 1.5; }
            .btn { display: inline-block; background: #2563eb; color: #fff; padding: 10px 18px; border-radius: 8px; text-decoration: none; font-weight: 600; margin: 8px 8px 0 0; }
            .muted { color: #64748b; font-size: 14px; }
            ol { padding-left: 1.25rem; }
        </style>
    </head>
    <body>
        <h1>Platform administrator required</h1>
        <p>You are signed in as <strong><?= $emailLabel ?></strong> (role: <strong><?= $roleLabel ?></strong>). This migration tool needs a <strong>control-plane admin</strong> session, not a company employee login.</p>
        <p class="muted">If you opened Ultimate/Roadmaster login first, the system logged you into that company only.</p>
        <ol>
            <li>Click <strong>Sign out &amp; open admin login</strong> below.</li>
            <li>Sign in with your platform admin email and password on the <strong>main</strong> login page (not /ultimate/login or /roadmaster/login).</li>
            <li>You will return here to choose Ultimate or Roadmaster for migration.</li>
        </ol>
        <p>
            <a class="btn" href="<?= htmlspecialchars($reloginUrl) ?>">Sign out &amp; open admin login</a>
        </p>
    </body>
    </html>
    <?php
    exit;
}

// --- Company selection (POST or GET select_company) ---
if ($pageError === '' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_company'])) {
    $pick = strtolower(trim((string) $_POST['select_company']));
    if (migrate_tenant_uploads_apply_session_company($pick)) {
        header('Location: ' . $self . '?company=' . rawurlencode($pick));
        exit;
    }
    $pageError = 'Invalid company selected.';
}

if ($pageError === '' && isset($_GET['select_company'])) {
    $pick = strtolower(trim((string) $_GET['select_company']));
    if (migrate_tenant_uploads_apply_session_company($pick)) {
        header('Location: ' . $self . '?company=' . rawurlencode($pick));
        exit;
    }
    $pageError = 'Invalid company selected.';
}

// Only run migration UI after explicit company choice (?company=ultimate|roadmaster).
// Do not inherit $_SESSION['company_slug'] from normal login (that skipped the picker).
$companySlug = '';
if ($pageError === '' && isset($_GET['company']) && trim((string) $_GET['company']) !== '') {
    $companySlug = strtolower(trim((string) $_GET['company']));
    migrate_tenant_uploads_apply_session_company($companySlug);
}

$dryRun = isset($_GET['dry_run']) && (string) $_GET['dry_run'] === '1';
$doRun = isset($_GET['run']) && (string) $_GET['run'] === '1';
$result = null;

if ($pageError === '' && $doRun && $companySlug !== '') {
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');
    try {
        $result = migrate_tenant_uploads_run($companySlug, $dryRun);
    } catch (Throwable $e) {
        $pageError = $e->getMessage();
        $pageTrace = $e->getFile() . ':' . $e->getLine();
    }
}

if ($pageError !== '') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Migration error</title></head>';
    echo '<body style="font-family:system-ui;padding:2rem;max-width:720px">';
    echo '<h1 style="color:#b91c1c">Migration error</h1>';
    echo '<p>' . htmlspecialchars($pageError) . '</p>';
    if ($pageTrace !== '') {
        echo '<pre style="background:#f1f5f9;padding:1rem;font-size:12px;overflow:auto">' . htmlspecialchars($pageTrace) . '</pre>';
    }
    echo '<p><a href="' . htmlspecialchars($self) . '">Back</a></p></body></html>';
    exit;
}

if ($doRun && $result !== null) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$companies = migrate_tenant_uploads_list_companies();

// --- No company chosen yet: ask which company ---
if ($companySlug === '' || !isset($companies[$companySlug])) {
    header('Content-Type: text/html; charset=utf-8');
    $userLabel = htmlspecialchars((string) ($_SESSION['email'] ?? $_SESSION['username'] ?? 'Administrator'));
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Choose company ù Upload migration</title>
        <style>
            body { font-family: system-ui, sans-serif; padding: 2rem; max-width: 640px; line-height: 1.5; }
            h1 { margin-bottom: 0.25rem; }
            .sub { color: #64748b; margin-bottom: 1.5rem; }
            .grid { display: grid; gap: 12px; }
            .card {
                display: block; border: 1px solid #e2e8f0; border-radius: 10px; padding: 1rem 1.25rem;
                text-decoration: none; color: inherit; background: #fff;
            }
            .card:hover { border-color: #2563eb; background: #f8fafc; }
            .card strong { display: block; font-size: 1.1rem; }
            .card span { color: #64748b; font-size: 14px; }
            .logout { margin-top: 2rem; font-size: 14px; }
            .logout a { color: #64748b; }
        </style>
    </head>
    <body>
        <h1>Which company?</h1>
        <p class="sub">Signed in as <?= $userLabel ?>. Select the company whose product uploads you want to migrate into isolated tenant storage.</p>
        <div class="grid">
            <?php foreach ($companies as $slug => $row):
                $id = (int) (isset($row['id']) ? $row['id'] : 0);
                $label = ucfirst($slug);
                ?>
            <a class="card" href="<?= htmlspecialchars($self) ?>?select_company=<?= rawurlencode($slug) ?>">
                <strong><?= htmlspecialchars($label) ?></strong>
                <span>Migrate to <code>storage/tenant_<?= $id ?>/products/</code></span>
            </a>
            <?php endforeach; ?>
        </div>
        <p class="logout"><a href="<?= htmlspecialchars(function_exists('app_url') ? app_url('/admin/management.php') : '/admin/management.php') ?>">? Admin management</a></p>
    </body>
    </html>
    <?php
    exit;
}

// --- Company selected: migration actions ---
$companyRow = $companies[$companySlug];
$tenantId = (int) (isset($companyRow['id']) ? $companyRow['id'] : 0);
$companyLabel = ucfirst($companySlug);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Migrate uploads ù <?= htmlspecialchars($companyLabel) ?></title>
    <style>
        body { font-family: system-ui, sans-serif; padding: 2rem; max-width: 640px; line-height: 1.5; }
        code { background: #f1f5f9; padding: 2px 6px; border-radius: 4px; }
        ul { padding-left: 1.25rem; }
        a { color: #2563eb; }
        .badge { display: inline-block; background: #dbeafe; color: #1e40af; padding: 4px 10px; border-radius: 6px; font-size: 13px; font-weight: 600; }
        .switch { margin-bottom: 1.5rem; font-size: 14px; }
    </style>
</head>
<body>
    <p class="badge">Company: <?= htmlspecialchars($companyLabel) ?> ù tenant_<?= (int) $tenantId ?></p>
    <h1>Migrate tenant uploads</h1>
    <p class="switch"><a href="<?= htmlspecialchars($self) ?>">? Choose a different company</a></p>
    <p>Copies <code>stock/uploads/products/{id}/</code> into <code>storage/tenant_<?= (int) $tenantId ?>/products/{id}/</code> for product IDs in the <strong><?= htmlspecialchars($companyLabel) ?></strong> database. Legacy files are not deleted.</p>
    <ul>
        <li><a href="<?= htmlspecialchars($self) ?>?company=<?= rawurlencode($companySlug) ?>&amp;dry_run=1&amp;run=1">Dry run (preview counts)</a></li>
        <li><a href="<?= htmlspecialchars($self) ?>?company=<?= rawurlencode($companySlug) ?>&amp;run=1" onclick="return confirm('Copy <?= htmlspecialchars($companyLabel) ?> product folders to storage/tenant_<?= (int) $tenantId ?>?');">Run migration</a></li>
    </ul>
    <p style="color:#64748b;font-size:14px">Logged in as <?= htmlspecialchars((string) ($_SESSION['email'] ?? $_SESSION['username'] ?? '')) ?>.</p>
</body>
</html>
