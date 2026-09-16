<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/includes/quote-requests-lib.php';

requireLogin();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION['active_module'] = 'sales';
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'sales';
}

$salesDb = function_exists('sales_pdo') ? sales_pdo() : ($GLOBALS['pdo'] ?? null);
$requests = salesQuoteRequestsFetchAll($salesDb instanceof PDO ? $salesDb : null);

// Group by quote_number for multi-item website checkouts
$groups = [];
foreach ($requests as $row) {
    $key = trim((string) ($row['quote_number'] ?? ''));
    if ($key === '') {
        $key = 'row-' . (int) ($row['id'] ?? 0);
    }
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'quote_number' => $key,
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'customer_email' => (string) ($row['customer_email'] ?? ''),
            'customer_phone' => (string) ($row['customer_phone'] ?? ''),
            'notes' => (string) ($row['notes'] ?? ''),
            'status' => (string) ($row['status'] ?? 'new'),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'items' => [],
        ];
    }
    $groups[$key]['items'][] = $row;
    if ($groups[$key]['created_at'] === '' || strcmp((string) ($row['created_at'] ?? ''), $groups[$key]['created_at']) > 0) {
        $groups[$key]['created_at'] = (string) ($row['created_at'] ?? '');
    }
}

$page_title = 'Quote requests';
$employeeHeaderTitle = 'Quote requests';
$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--exp-desk';
$module = 'sales';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - ERP</title>
    <script>
    (function() {
        var t = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
    })();
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body.page-quote-requests { background: #f8fafc; font-family: Inter, system-ui, sans-serif; }
        .qr-wrap { padding: 1rem 1.25rem 2rem; max-width: 1200px; margin: 0 auto; }
        .qr-head { display: flex; justify-content: space-between; gap: 1rem; align-items: end; margin-bottom: 1rem; flex-wrap: wrap; }
        .qr-head h1 { margin: 0; font-size: 1.35rem; font-weight: 700; color: #0f172a; }
        .qr-head p { margin: 0.25rem 0 0; color: #64748b; font-size: 0.9rem; }
        .qr-count { background: #fee2e2; color: #b91c1c; font-weight: 700; font-size: 0.78rem; padding: 0.35rem 0.65rem; border-radius: 999px; }
        .qr-card {
            background: #fff; border: 1px solid #e2e8f0; border-radius: 0.75rem;
            margin-bottom: 0.85rem; overflow: hidden;
        }
        .qr-card-head {
            display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap;
            padding: 0.85rem 1rem; border-bottom: 1px solid #f1f5f9; background: #fafafa;
        }
        .qr-card-head strong { font-size: 0.95rem; color: #0f172a; }
        .qr-card-head span { display: block; color: #64748b; font-size: 0.82rem; margin-top: 0.15rem; }
        .qr-status {
            align-self: start; font-size: 0.72rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.04em; padding: 0.28rem 0.55rem; border-radius: 999px;
            background: #dbeafe; color: #1d4ed8;
        }
        .qr-items { list-style: none; margin: 0; padding: 0; }
        .qr-items li {
            display: grid; grid-template-columns: 1fr auto; gap: 0.75rem;
            padding: 0.75rem 1rem; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem;
        }
        .qr-items li:last-child { border-bottom: 0; }
        .qr-items em { font-style: normal; color: #64748b; font-size: 0.8rem; }
        .qr-notes { padding: 0 1rem 0.9rem; color: #475569; font-size: 0.85rem; }
        .qr-empty {
            background: #fff; border: 1px dashed #cbd5e1; border-radius: 0.75rem;
            padding: 2.5rem 1rem; text-align: center; color: #64748b;
        }
    </style>
</head>
<body class="dashboard page-quote-requests">
<?php include __DIR__ . '/../../../includes/header_employee.php'; ?>

<div class="qr-wrap">
    <div class="qr-head">
        <div>
            <h1>Quote requests</h1>
            <p>Website quotation requests from Roadmaster Spares storefront.</p>
        </div>
        <span class="qr-count"><?= count($groups) ?> <?= count($groups) === 1 ? 'request' : 'requests' ?></span>
    </div>

    <?php if ($groups === []): ?>
        <div class="qr-empty">
            <p class="mb-0">No website quote requests yet.</p>
            <p class="mb-0" style="margin-top:0.35rem;font-size:0.85rem;">Requests submitted from the storefront checkout or product page will show here.</p>
        </div>
    <?php else: ?>
        <?php foreach ($groups as $group): ?>
            <article class="qr-card">
                <header class="qr-card-head">
                    <div>
                        <strong><?= htmlspecialchars((string) $group['quote_number']) ?></strong>
                        <span>
                            <?= htmlspecialchars((string) $group['customer_name']) ?>
                            <?php if ($group['customer_email'] !== ''): ?>
                                ù <?= htmlspecialchars((string) $group['customer_email']) ?>
                            <?php endif; ?>
                            <?php if ($group['customer_phone'] !== ''): ?>
                                ù <?= htmlspecialchars((string) $group['customer_phone']) ?>
                            <?php endif; ?>
                        </span>
                        <?php if ($group['created_at'] !== ''): ?>
                            <span><?= htmlspecialchars((string) $group['created_at']) ?></span>
                        <?php endif; ?>
                    </div>
                    <span class="qr-status"><?= htmlspecialchars((string) $group['status']) ?></span>
                </header>
                <ul class="qr-items">
                    <?php foreach ($group['items'] as $item): ?>
                        <li>
                            <div>
                                <strong><?= htmlspecialchars((string) ($item['product_name'] ?? 'Product')) ?></strong>
                                <em>
                                    SKU <?= htmlspecialchars((string) ($item['product_sku'] ?? '-')) ?>
                                </em>
                            </div>
                            <div>Qty <?= htmlspecialchars((string) ($item['quantity'] ?? 1)) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (trim((string) $group['notes']) !== ''): ?>
                    <div class="qr-notes">
                        <strong>Additional requirements:</strong>
                        <?= nl2br(htmlspecialchars((string) $group['notes'])) ?>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</body>
</html>
