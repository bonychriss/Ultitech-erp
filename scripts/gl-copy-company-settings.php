<?php
/**
 * Copy accounting settings from one company to others:
 *   - links Balances accounts to ledger accounts,
 *   - voucher category (budget type) -> ledger account mappings, creating missing ledger accounts by code/name/type,
 *   - exchange rates the target does not have yet,
 *   - backfills the ledger and copies the autosync / report source switches when the backfill is clean.
 *
 * Usage: php scripts/gl-copy-company-settings.php --from=ultimate [--to=roadmaster] [--dry-run]
 * Without --to, every other active company is updated.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$opts = getopt('', ['from:', 'to::', 'dry-run']);
$fromSlug = strtolower(trim((string) ($opts['from'] ?? '')));
$toSlug = strtolower(trim((string) ($opts['to'] ?? '')));
$dryRun = array_key_exists('dry-run', $opts);
if ($fromSlug === '') {
    fwrite(STDERR, "Usage: php scripts/gl-copy-company-settings.php --from=<slug> [--to=<slug>] [--dry-run]\n");
    exit(1);
}

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/general_ledger_setup.php';
require_once dirname(__DIR__) . '/includes/fa_gl_linking.php';
require_once dirname(__DIR__) . '/includes/gl/gl_reports.php';
require_once dirname(__DIR__) . '/includes/gl/gl_bridge.php';

$control = $GLOBALS['control_pdo'] ?? $GLOBALS['pdo'] ?? null;
if (!($control instanceof PDO)) {
    fwrite(STDERR, "No control database connection.\n");
    exit(1);
}
$companies = $control->query("SELECT id, company_slug, db_name, db_host, db_user, db_pass FROM companies WHERE status = 'active'")
    ->fetchAll(PDO::FETCH_ASSOC) ?: [];

$connect = static function (array $c): ?PDO {
    $pdo = connectToTenantDatabase((string) $c['db_name'], $c['db_host'] ?: null, $c['db_user'] ?: null, $c['db_pass'] ?: null);
    if ($pdo instanceof PDO) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }
    return $pdo instanceof PDO ? $pdo : null;
};

$source = null;
$targets = [];
foreach ($companies as $c) {
    $slug = strtolower((string) $c['company_slug']);
    if ($slug === $fromSlug) {
        $source = $c;
    } elseif ($toSlug === '' || $slug === $toSlug) {
        $targets[] = $c;
    }
}
if (!$source || !$targets) {
    fwrite(STDERR, "Source or target company not found.\n");
    exit(1);
}

$src = $connect($source);
if (!$src || !gl_ensure_schema($src)) {
    fwrite(STDERR, "Source ledger is not available.\n");
    exit(1);
}
$srcMap = $src->query('SELECT m.category, a.code, a.name, LOWER(a.type) AS type
                       FROM gl_category_map m JOIN erp_accounts a ON a.id = m.account_id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$srcRates = $src->query('SELECT currency, rate_date, rate, note FROM gl_exchange_rates')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$srcMeta = [
    'autosync' => gl_meta_get($src, 'autosync'),
    'report_source' => gl_meta_get($src, 'report_source'),
];

foreach ($targets as $t) {
    $slug = (string) $t['company_slug'];
    echo "== {$slug} ({$t['db_name']})" . ($dryRun ? ' [dry run]' : '') . "\n";
    $pdo = $connect($t);
    if (!$pdo) {
        echo "  cannot connect, skipped\n";
        continue;
    }
    if (!gl_ensure_schema($pdo)) {
        echo "  ledger tables could not be created, skipped\n";
        continue;
    }

    $links = $dryRun ? ['linked' => 0, 'errors' => []] : fa_gl_sync_all($pdo);
    echo "  accounts linked to ledger: {$links['linked']}" . ($links['errors'] ? ' (errors: ' . implode(' | ', $links['errors']) . ')' : '') . "\n";

    $mapped = 0;
    foreach ($srcMap as $m) {
        $code = (string) ($m['code'] ?? '');
        $name = (string) ($m['name'] ?? '');
        $type = (string) ($m['type'] ?? 'expense');
        $find = $pdo->prepare('SELECT id FROM erp_accounts WHERE LOWER(type) = ? AND LOWER(TRIM(name)) = LOWER(?) ORDER BY id LIMIT 1');
        $find->execute([$type, $name]);
        $accountId = (int) ($find->fetchColumn() ?: 0);
        $isNew = $accountId <= 0;
        if ($isNew && !$dryRun) {
            // System ledger codes (e.g. 2100 Accounts Payable) must stay free for their own account.
            $codeTaken = $code === '';
            foreach (gl_system_account_defs() as [$sysCode, $sysName]) {
                if ($sysCode === $code && strcasecmp($sysName, $name) !== 0) {
                    $codeTaken = true;
                }
            }
            if (!$codeTaken) {
                $st = $pdo->prepare('SELECT 1 FROM erp_accounts WHERE code = ? LIMIT 1');
                $st->execute([$code]);
                $codeTaken = (bool) $st->fetchColumn();
            }
            $newCode = $codeTaken ? fa_gl_next_code_for_type($pdo, $type, $code !== '' ? substr($code, 0, 1) : '1') : $code;
            $accountId = fa_gl_create_erp_account($pdo, $newCode, $name, $type, 'Copied from ' . $fromSlug . ' voucher category mapping');
            $code = $newCode;
        }
        if (!$dryRun && $accountId) {
            $pdo->prepare('INSERT INTO gl_category_map (category, account_id) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE account_id = account_id')
                ->execute([(string) $m['category'], (int) $accountId]);
        }
        $mapped++;
        echo "  map '{$m['category']}' -> {$code} {$name}" . ($isNew ? ' (new account)' : '') . "\n";
    }

    $rates = 0;
    if (!$dryRun) {
        $ins = $pdo->prepare('INSERT IGNORE INTO gl_exchange_rates (currency, rate_date, rate, note) VALUES (?, ?, ?, ?)');
        foreach ($srcRates as $r) {
            $ins->execute([$r['currency'], $r['rate_date'], $r['rate'], $r['note']]);
            $rates += $ins->rowCount();
        }
    }
    echo "  exchange rates added: {$rates}\n";

    $res = gl_bridge_sync_all($pdo, ['dry_run' => $dryRun]);
    echo "  ledger backfill: {$res['posted']} posted, {$res['reversed']} reversed"
        . ($res['errors'] ? ', errors: ' . implode(' | ', array_slice($res['errors'], 0, 3)) : '') . "\n";

    if (!$dryRun && !$res['errors']) {
        foreach ($srcMeta as $key => $value) {
            if ($value !== null) {
                gl_meta_set($pdo, $key, (string) $value);
            }
        }
        echo "  autosync=" . ($srcMeta['autosync'] ?? '-') . ', report_source=' . ($srcMeta['report_source'] ?? '-') . "\n";
    }
}
