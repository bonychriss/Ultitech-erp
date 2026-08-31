<?php
require_once __DIR__ . '/includes/functions.php';

@ini_set('display_errors', '1');
@error_reporting(E_ALL);

function dbg_out($label, $value = null)
{
    if ($value === null) {
        echo htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . "<br>\n";
        return;
    }
    echo '<strong>' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . ':</strong> '
        . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . "<br>\n";
}

function dbg_table_exists($pdo, $table)
{
    if (!($pdo instanceof PDO)) return false;
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE ?");
        $st->execute([$table]);
        return (bool) $st->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function dbg_column_exists($pdo, $table, $column)
{
    if (!($pdo instanceof PDO)) return false;
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $st->execute([$column]);
        return (bool) $st->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function dbg_active_company_slugs($pdo)
{
    if (!dbg_table_exists($pdo, 'companies') || !dbg_column_exists($pdo, 'companies', 'company_slug')) {
        return [];
    }
    try {
        $sql = "SELECT company_slug FROM companies WHERE TRIM(company_slug) <> ''";
        if (dbg_column_exists($pdo, 'companies', 'status')) {
            $sql .= " AND LOWER(TRIM(status)) = 'active'";
        }
        $sql .= " ORDER BY id ASC LIMIT 100";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $slug = strtolower(trim((string) $r));
            if ($slug !== '') $out[] = $slug;
        }
        return $out;
    } catch (Exception $e) {
        return [];
    }
}

function dbg_clear_auth()
{
    if (function_exists('clearAuthSession')) {
        clearAuthSession();
    } else {
        unset($_SESSION['user_id'], $_SESSION['company_id'], $_SESSION['company_slug'], $_SESSION['company_name']);
    }
}

$controlPdo = isset($control_pdo) && ($control_pdo instanceof PDO) ? $control_pdo : (isset($pdo) ? $pdo : null);

echo "<h2>ULTITECH LOGIN DEBUG</h2>\n";
dbg_out('Time', date('c'));
dbg_out('PHP', PHP_VERSION);
dbg_out('Host', $_SERVER['HTTP_HOST'] ?? '');
dbg_out('Current DB', ($controlPdo instanceof PDO) ? (string) $controlPdo->query('SELECT DATABASE()')->fetchColumn() : 'N/A');
echo "<hr>\n";

dbg_out('users table', dbg_table_exists($controlPdo, 'users') ? 'YES' : 'NO');
dbg_out('companies table', dbg_table_exists($controlPdo, 'companies') ? 'YES' : 'NO');
dbg_out('users.company_id', dbg_column_exists($controlPdo, 'users', 'company_id') ? 'YES' : 'NO');
dbg_out('companies.company_slug', dbg_column_exists($controlPdo, 'companies', 'company_slug') ? 'YES' : 'NO');
echo "<hr>\n";

$identifier = trim((string) ($_POST['user'] ?? $_GET['user'] ?? ''));
$password = (string) ($_POST['password'] ?? $_GET['password'] ?? '');
$forcedSlug = strtolower(trim((string) ($_POST['slug'] ?? $_GET['slug'] ?? '')));

?>
<form method="post" style="margin-bottom:12px;">
    <label>Email/Username</label><br>
    <input name="user" value="<?= htmlspecialchars($identifier, ENT_QUOTES, 'UTF-8') ?>" style="width:320px;"><br><br>
    <label>Password</label><br>
    <input name="password" type="password" value="<?= htmlspecialchars($password, ENT_QUOTES, 'UTF-8') ?>" style="width:320px;"><br><br>
    <label>Forced Slug (optional)</label><br>
    <input name="slug" value="<?= htmlspecialchars($forcedSlug, ENT_QUOTES, 'UTF-8') ?>" style="width:320px;"><br><br>
    <button type="submit">Run Debug</button>
</form>
<?php

if ($identifier === '' || $password === '') {
    dbg_out('Info', 'Enter user + password, then Run Debug.');
    exit;
}

echo "<hr>\n";
dbg_out('Identifier', $identifier);
dbg_out('Password length', strlen($password));
dbg_out('Forced slug', $forcedSlug !== '' ? $forcedSlug : '(none)');

$resolvedSlug = '';
$resolvedCompanyId = 0;
$userRow = null;

if ($controlPdo instanceof PDO && dbg_table_exists($controlPdo, 'users')) {
    try {
        $sql = "SELECT id, username, email";
        if (dbg_column_exists($controlPdo, 'users', 'company_id')) $sql .= ", company_id";
        if (dbg_column_exists($controlPdo, 'users', 'is_active')) $sql .= ", is_active";
        if (dbg_column_exists($controlPdo, 'users', 'status')) $sql .= ", status";
        if (dbg_column_exists($controlPdo, 'users', 'approval_status')) $sql .= ", approval_status";
        $sql .= " FROM users WHERE (username = ? OR email = ?) ORDER BY id DESC LIMIT 1";
        $st = $controlPdo->prepare($sql);
        $st->execute([$identifier, $identifier]);
        $userRow = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        dbg_out('User lookup error', $e->getMessage());
    }
}

if ($userRow) {
    dbg_out('User row found', 'YES');
    dbg_out('User ID', (string) ($userRow['id'] ?? ''));
    dbg_out('Username', (string) ($userRow['username'] ?? ''));
    dbg_out('Email', (string) ($userRow['email'] ?? ''));
    if (isset($userRow['is_active'])) dbg_out('is_active', (string) $userRow['is_active']);
    if (isset($userRow['status'])) dbg_out('status', (string) $userRow['status']);
    if (isset($userRow['approval_status'])) dbg_out('approval_status', (string) $userRow['approval_status']);

    $resolvedCompanyId = (int) ($userRow['company_id'] ?? 0);
    dbg_out('company_id', (string) $resolvedCompanyId);

    if ($resolvedCompanyId > 0 && dbg_table_exists($controlPdo, 'companies')) {
        try {
            $stc = $controlPdo->prepare("SELECT id, company_name, company_slug, status, db_name FROM companies WHERE id = ? LIMIT 1");
            $stc->execute([$resolvedCompanyId]);
            $co = $stc->fetch(PDO::FETCH_ASSOC);
            if ($co) {
                dbg_out('Company name', (string) ($co['company_name'] ?? ''));
                dbg_out('Company slug', (string) ($co['company_slug'] ?? ''));
                dbg_out('Company status', (string) ($co['status'] ?? ''));
                dbg_out('Company db_name', (string) ($co['db_name'] ?? ''));
                $resolvedSlug = strtolower(trim((string) ($co['company_slug'] ?? '')));
            } else {
                dbg_out('Company row', 'NOT FOUND');
            }
        } catch (Exception $e) {
            dbg_out('Company lookup error', $e->getMessage());
        }
    }
} else {
    dbg_out('User row found', 'NO');
}

echo "<hr>\n";
dbg_out('Resolved slug', $resolvedSlug !== '' ? $resolvedSlug : '(none)');

$attempts = [];
if ($forcedSlug !== '') $attempts[] = $forcedSlug;
if ($resolvedSlug !== '' && !in_array($resolvedSlug, $attempts, true)) $attempts[] = $resolvedSlug;
$attempts[] = '';
$fallbackSlugs = dbg_active_company_slugs($controlPdo);
foreach ($fallbackSlugs as $slug) {
    if (!in_array($slug, $attempts, true)) {
        $attempts[] = $slug;
    }
}

$attempts = array_slice($attempts, 0, 25);
dbg_out('Total attempts planned', (string) count($attempts));

$ok = false;
$okSlug = '';

foreach ($attempts as $i => $slug) {
    dbg_clear_auth();
    $label = $slug !== '' ? $slug : '(no slug)';
    $pass = authenticate($identifier, $password, $slug !== '' ? $slug : null);
    dbg_out('Attempt ' . ($i + 1), $label . ' => ' . ($pass ? 'PASS' : 'FAIL'));
    if ($pass) {
        $ok = true;
        $okSlug = $slug;
        dbg_out('Session user_id', (string) ($_SESSION['user_id'] ?? ''));
        dbg_out('Session company_id', (string) ($_SESSION['company_id'] ?? ''));
        dbg_out('Session company_slug', (string) ($_SESSION['company_slug'] ?? ''));
        break;
    }
}

echo "<hr>\n";
if ($ok) {
    $finalSlug = strtolower(trim((string) ($_SESSION['company_slug'] ?? $okSlug)));
    $target = $finalSlug !== '' ? company_url('select-module', $finalSlug) : app_url('/select-module.php');
    dbg_out('RESULT', 'SUCCESS');
    dbg_out('Winning slug', $okSlug !== '' ? $okSlug : '(no slug)');
    dbg_out('Redirect target', $target);
} else {
    dbg_out('RESULT', 'FAIL');
    dbg_out('Hint', 'Check password hash, user active/approval/status flags, company status, and tenant DB sync.');
}

echo "<hr>\n";
dbg_out('IMPORTANT', 'Delete debug_login.php after troubleshooting.');
