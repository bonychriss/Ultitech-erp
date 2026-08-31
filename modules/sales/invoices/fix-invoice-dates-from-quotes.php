<?php
/**
 * Fix invoices whose invoice_date was copied from the quotation date
 * instead of the conversion/creation date.
 *
 * Browser (must be logged in as admin):
 *   .../modules/sales/invoices/fix-invoice-dates-from-quotes.php
 *   .../modules/sales/invoices/fix-invoice-dates-from-quotes.php?apply=1
 *   .../modules/sales/invoices/fix-invoice-dates-from-quotes.php?format=json
 *   .../modules/sales/invoices/fix-invoice-dates-from-quotes.php?apply=1&format=json
 *
 * CLI:
 *   php fix-invoice-dates-from-quotes.php
 *   php fix-invoice-dates-from-quotes.php --apply
 *   php fix-invoice-dates-from-quotes.php --apply --json
 *
 * Safe to re-run: only updates rows where invoice_date still equals quote_date
 * and created_at is a different day.
 */

declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!$isCli) {
    requireLogin();
    if (!function_exists('isAdmin') || !isAdmin()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Admin access required.\n";
        exit(1);
    }
}

$apply = false;
$format = 'html';

if ($isCli) {
    global $argv;
    $apply = in_array('--apply', $argv ?? [], true);
    $format = in_array('--json', $argv ?? [], true) ? 'json' : 'text';
} else {
    $apply = !empty($_GET['apply']);
    $format = strtolower(trim((string) ($_GET['format'] ?? 'html')));
    if (!in_array($format, ['html', 'json'], true)) {
        $format = 'html';
    }
}

/**
 * @return array{
 *   database:string,
 *   mode:string,
 *   candidates:int,
 *   updated:int,
 *   skipped:list<array<string,mixed>>,
 *   changes:list<array<string,mixed>>,
 *   message:string
 * }
 */
function sales_fix_invoice_dates_from_quotes(PDO $db, bool $apply): array
{
    $invCols = $db->query('SHOW COLUMNS FROM invoices')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    $hasCreatedAt = in_array('created_at', $invCols, true);
    $hasDueDate = in_array('due_date', $invCols, true);

    $sql = '
        SELECT
            i.id,
            i.invoice_number,
            i.invoice_date,
            ' . ($hasDueDate ? 'i.due_date,' : 'NULL AS due_date,') . '
            ' . ($hasCreatedAt ? 'i.created_at,' : 'NULL AS created_at,') . '
            i.order_id,
            so.order_number,
            so.quote_date,
            so.valid_until
        FROM invoices i
        INNER JOIN sales_orders so ON so.id = i.order_id
        WHERE i.order_id IS NOT NULL
          AND i.order_id > 0
          AND so.quote_date IS NOT NULL
          AND so.quote_date <> \'\'
          AND i.invoice_date IS NOT NULL
          AND DATE(i.invoice_date) = DATE(so.quote_date)
    ';

    if ($hasCreatedAt) {
        $sql .= '
          AND (
                i.created_at IS NULL
                OR DATE(i.created_at) <> DATE(so.quote_date)
          )
        ';
    }

    $sql .= ' ORDER BY i.id ASC';

    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $changes = [];
    $skipped = [];

    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $quoteDate = substr((string) $row['quote_date'], 0, 10);
        $oldInvoiceDate = substr((string) $row['invoice_date'], 0, 10);
        $oldDueDate = $row['due_date'] !== null ? substr((string) $row['due_date'], 0, 10) : null;
        $validUntil = $row['valid_until'] !== null ? substr((string) $row['valid_until'], 0, 10) : '';

        $createdRaw = (string) ($row['created_at'] ?? '');
        $newInvoiceDate = '';
        if ($createdRaw !== '' && preg_match('/^(\d{4}-\d{2}-\d{2})/', $createdRaw, $m)) {
            $newInvoiceDate = $m[1];
        }
        if ($newInvoiceDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $newInvoiceDate)) {
            $skipped[] = [
                'id' => $id,
                'invoice_number' => (string) $row['invoice_number'],
                'reason' => 'no created_at to use as invoice date',
            ];
            continue;
        }

        $newDueDate = null;
        if ($hasDueDate) {
            if (
                preg_match('/^\d{4}-\d{2}-\d{2}$/', $quoteDate)
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', $validUntil)
            ) {
                $termDays = (int) round((strtotime($validUntil) - strtotime($quoteDate)) / 86400);
                if ($termDays < 0) {
                    $termDays = 30;
                }
                $newDueDate = date('Y-m-d', strtotime('+' . $termDays . ' days', strtotime($newInvoiceDate)));
            } elseif ($oldDueDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $oldDueDate) && $oldDueDate >= $newInvoiceDate) {
                $newDueDate = $oldDueDate;
            } else {
                $newDueDate = date('Y-m-d', strtotime('+30 days', strtotime($newInvoiceDate)));
            }
        }

        if ($newInvoiceDate === $oldInvoiceDate && ($newDueDate === null || $newDueDate === $oldDueDate)) {
            continue;
        }

        $changes[] = [
            'id' => $id,
            'invoice_number' => (string) $row['invoice_number'],
            'order_number' => (string) ($row['order_number'] ?? ''),
            'old_invoice_date' => $oldInvoiceDate,
            'new_invoice_date' => $newInvoiceDate,
            'old_due_date' => $oldDueDate,
            'new_due_date' => $newDueDate,
        ];
    }

    $updated = 0;
    if ($apply && $changes !== []) {
        $db->beginTransaction();
        try {
            if ($hasDueDate) {
                $stmt = $db->prepare('UPDATE invoices SET invoice_date = ?, due_date = ? WHERE id = ?');
                foreach ($changes as $u) {
                    $stmt->execute([$u['new_invoice_date'], $u['new_due_date'], $u['id']]);
                    $updated++;
                }
            } else {
                $stmt = $db->prepare('UPDATE invoices SET invoice_date = ? WHERE id = ?');
                foreach ($changes as $u) {
                    $stmt->execute([$u['new_invoice_date'], $u['id']]);
                    $updated++;
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    $dbName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
    $message = $apply
        ? ('Updated ' . $updated . ' invoice(s).')
        : ('Dry-run: ' . count($changes) . ' invoice(s) would be updated. Re-run with apply=1 to write.');

    return [
        'database' => $dbName,
        'mode' => $apply ? 'apply' : 'dry-run',
        'candidates' => count($rows),
        'updated' => $updated,
        'skipped' => $skipped,
        'changes' => $changes,
        'message' => $message,
    ];
}

try {
    $db = function_exists('sales_pdo') ? sales_pdo() : $pdo;
    if (!$db instanceof PDO) {
        throw new RuntimeException('Sales database connection is unavailable.');
    }
    $result = sales_fix_invoice_dates_from_quotes($db, $apply);
} catch (Throwable $e) {
    $payload = [
        'ok' => false,
        'error' => $e->getMessage(),
    ];
    if ($format === 'json' || $isCli) {
        if (!$isCli && !headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . ($isCli ? PHP_EOL : '');
    } else {
        http_response_code(500);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Invoice date fix</title></head><body>';
        echo '<h1>Invoice date fix failed</h1><pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
        echo '</body></html>';
    }
    exit(1);
}

$payload = ['ok' => true] + $result;

if ($format === 'json' || ($isCli && $format !== 'html')) {
    if (!$isCli && !headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    if ($isCli && $format === 'text') {
        echo 'Database: ' . $result['database'] . PHP_EOL;
        echo 'Mode: ' . strtoupper($result['mode']) . PHP_EOL;
        echo 'Candidates: ' . $result['candidates'] . PHP_EOL;
        echo 'Will update / updated: ' . ($apply ? $result['updated'] : count($result['changes'])) . PHP_EOL;
        foreach ($result['changes'] as $u) {
            $duePart = $u['new_due_date'] !== null
                ? sprintf(' | due %s -> %s', (string) $u['old_due_date'], (string) $u['new_due_date'])
                : '';
            echo sprintf(
                "#%d %s (order %s): invoice %s -> %s%s\n",
                $u['id'],
                $u['invoice_number'],
                $u['order_number'],
                $u['old_invoice_date'],
                $u['new_invoice_date'],
                $duePart
            );
        }
        foreach ($result['skipped'] as $s) {
            echo 'SKIP #' . $s['id'] . ' ' . $s['invoice_number'] . ': ' . $s['reason'] . PHP_EOL;
        }
        echo $result['message'] . PHP_EOL;
    } else {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . ($isCli ? PHP_EOL : '');
    }
    exit(0);
}

$scriptName = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'fix-invoice-dates-from-quotes.php'));
$self = htmlspecialchars($scriptName, ENT_QUOTES, 'UTF-8');
$applyUrl = htmlspecialchars($scriptName . '?apply=1', ENT_QUOTES, 'UTF-8');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fix invoice dates from quotations</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 1.5rem; color: #111; background: #f7f7f5; }
        .card { background: #fff; border: 1px solid #ddd; padding: 1.25rem 1.5rem; max-width: 960px; }
        h1 { font-size: 1.25rem; margin: 0 0 0.75rem; }
        .meta { color: #555; margin-bottom: 1rem; }
        .ok { color: #0a7a2f; font-weight: 600; }
        .warn { color: #9a6700; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 0.9rem; }
        th, td { text-align: left; padding: 0.45rem 0.5rem; border-bottom: 1px solid #eee; }
        th { color: #555; font-weight: 600; }
        .btn { display: inline-block; margin-top: 1rem; margin-right: 0.5rem; padding: 0.55rem 0.9rem; background: #111; color: #fff; text-decoration: none; border-radius: 4px; }
        .btn.secondary { background: #555; }
        code { background: #f0f0ec; padding: 0.1rem 0.35rem; border-radius: 3px; }
    </style>
</head>
<body>
<div class="card">
    <h1>Fix invoice dates from quotations</h1>
    <div class="meta">
        Database: <code><?= htmlspecialchars($result['database'], ENT_QUOTES, 'UTF-8') ?></code><br>
        Mode: <strong><?= htmlspecialchars(strtoupper($result['mode']), ENT_QUOTES, 'UTF-8') ?></strong><br>
        Candidates: <?= (int) $result['candidates'] ?>
    </div>
    <p class="<?= $apply ? 'ok' : 'warn' ?>"><?= htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8') ?></p>

    <?php if ($result['changes'] === [] && $result['skipped'] === []): ?>
        <p>Nothing to fix. Existing invoices already use conversion dates (or have no matching quote-date mismatch).</p>
    <?php else: ?>
        <?php if ($result['changes'] !== []): ?>
            <table>
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Order</th>
                        <th>Invoice date</th>
                        <th>Due date</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($result['changes'] as $u): ?>
                    <tr>
                        <td><?= htmlspecialchars($u['invoice_number'], ENT_QUOTES, 'UTF-8') ?> (#<?= (int) $u['id'] ?>)</td>
                        <td><?= htmlspecialchars($u['order_number'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($u['old_invoice_date'], ENT_QUOTES, 'UTF-8') ?> ? <?= htmlspecialchars($u['new_invoice_date'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?php if ($u['new_due_date'] !== null): ?>
                                <?= htmlspecialchars((string) $u['old_due_date'], ENT_QUOTES, 'UTF-8') ?> ? <?= htmlspecialchars((string) $u['new_due_date'], ENT_QUOTES, 'UTF-8') ?>
                            <?php else: ?>
                                ù
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($result['skipped'] !== []): ?>
            <h2 style="font-size:1rem;margin-top:1.25rem;">Skipped</h2>
            <ul>
                <?php foreach ($result['skipped'] as $s): ?>
                    <li><?= htmlspecialchars($s['invoice_number'], ENT_QUOTES, 'UTF-8') ?> (#<?= (int) $s['id'] ?>): <?= htmlspecialchars($s['reason'], ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!$apply && $result['changes'] !== []): ?>
        <a class="btn" href="<?= $applyUrl ?>">Apply changes</a>
    <?php endif; ?>
    <a class="btn secondary" href="<?= $self ?>">Refresh / dry-run</a>
</div>
</body>
</html>
