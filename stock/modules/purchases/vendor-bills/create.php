<?php
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/functions.php';
requireLogin();

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$page_title = 'Create Vendor Bill';

$company_id = (int) (currentCompanyId() ?? 0);
$user_id = (int) ($_SESSION['user_id'] ?? 0);

$errors = [];
$tablesReady = vendorBillTableExists($pdo);

$suppliers = [];
$purchaseOrders = [];
$stockItems = [];

$form = [
    'supplier_id' => '',
    'purchase_order_id' => '',
    'supplier_invoice_number' => '',
    'bill_date' => date('Y-m-d'),
    'due_date' => '',
    'currency' => 'TZS',
    'exchange_rate' => '1',
    'notes' => '',
    'lines' => [
        [
            'stock_item_id' => '',
            'description' => '',
            'quantity' => '1',
            'unit_cost' => '',
            'tax_rate' => '0',
        ],
    ],
];

$prefillPoId = (int) ($_GET['po_id'] ?? 0);
if ($prefillPoId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $form['purchase_order_id'] = (string) $prefillPoId;
}

/**
 * @return array{sql:string,params:array<int,mixed>}
 */
$companyScope = static function (PDO $pdo, string $table, int $companyId): array {
    if ($companyId > 0 && function_exists('columnExists') && columnExists($table, 'company_id', $pdo)) {
        return ['sql' => ' WHERE company_id = ?', 'params' => [$companyId]];
    }
    return ['sql' => '', 'params' => []];
};

if ($company_id <= 0) {
    $errors[] = 'Company context is required. Please sign in again or select a company.';
} elseif (!$tablesReady) {
    $errors[] = 'Vendor Bills are not available on this database yet. Please run the Phase 3B migration.';
} else {
    try {
        if (function_exists('tableExists') && tableExists('stocks_suppliers', $pdo)) {
            $scope = $companyScope($pdo, 'stocks_suppliers', $company_id);
            $stmt = $pdo->prepare('SELECT id, name FROM stocks_suppliers' . $scope['sql'] . ' ORDER BY name ASC');
            $stmt->execute($scope['params']);
            $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        if (function_exists('tableExists') && tableExists('stocks_purchase_orders', $pdo)) {
            $poCols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $hasPoTotal = in_array('total_amount', $poCols, true);
            $hasPoCompany = in_array('company_id', $poCols, true);
            $supplierJoin = (function_exists('tableExists') && tableExists('stocks_suppliers', $pdo))
                ? 'LEFT JOIN stocks_suppliers ss ON ss.id = p.supplier_id'
                : '';
            $supplierName = $supplierJoin !== ''
                ? 'COALESCE(ss.name, CONCAT(\'Supplier #\', p.supplier_id))'
                : 'CONCAT(\'Supplier #\', p.supplier_id)';
            $totalSelect = $hasPoTotal ? 'p.total_amount' : 'NULL AS total_amount';

            $poSql = "SELECT p.id, p.po_number, p.supplier_id, {$supplierName} AS supplier_name, {$totalSelect}
                      FROM stocks_purchase_orders p
                      {$supplierJoin}";
            $poParams = [];
            if ($hasPoCompany && $company_id > 0) {
                $poSql .= ' WHERE p.company_id = ?';
                $poParams[] = $company_id;
            }
            $poSql .= ' ORDER BY p.id DESC LIMIT 500';
            $poStmt = $pdo->prepare($poSql);
            $poStmt->execute($poParams);
            $purchaseOrders = $poStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        if (function_exists('tableExists') && tableExists('stocks_items', $pdo)) {
            $scope = $companyScope($pdo, 'stocks_items', $company_id);
            $stmt = $pdo->prepare(
                'SELECT id, sku, name FROM stocks_items' . $scope['sql'] . ' ORDER BY name ASC LIMIT 1000'
            );
            $stmt->execute($scope['params']);
            $stockItems = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) {
        error_log('Vendor bill create load error: ' . $e->getMessage());
        $errors[] = 'Unable to load form data. Please try again later.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $company_id > 0 && $tablesReady && empty($errors)) {
  $csrfOk = true;
  if (function_exists('verify_csrf')) {
      $csrfOk = verify_csrf($_POST['csrf_token'] ?? '');
      if (!$csrfOk) {
          $errors[] = 'Your session has expired. Please refresh the page and try again.';
      }
  }

  if ($csrfOk) {
      $form['supplier_id'] = (string) ((int) ($_POST['supplier_id'] ?? 0));
      $form['purchase_order_id'] = trim((string) ($_POST['purchase_order_id'] ?? ''));
      $form['supplier_invoice_number'] = trim((string) ($_POST['supplier_invoice_number'] ?? ''));
      $form['bill_date'] = trim((string) ($_POST['bill_date'] ?? ''));
      $form['due_date'] = trim((string) ($_POST['due_date'] ?? ''));
      $form['currency'] = strtoupper(trim((string) ($_POST['currency'] ?? 'TZS'))) ?: 'TZS';
      $form['exchange_rate'] = trim((string) ($_POST['exchange_rate'] ?? '1'));
      $form['notes'] = trim((string) ($_POST['notes'] ?? ''));

      $postedLines = $_POST['lines'] ?? [];
      if (!is_array($postedLines)) {
          $postedLines = [];
      }

      $form['lines'] = [];
      foreach ($postedLines as $row) {
          if (!is_array($row)) {
              continue;
          }
          $form['lines'][] = [
              'stock_item_id' => trim((string) ($row['stock_item_id'] ?? '')),
              'description' => trim((string) ($row['description'] ?? '')),
              'quantity' => trim((string) ($row['quantity'] ?? '')),
              'unit_cost' => trim((string) ($row['unit_cost'] ?? '')),
              'tax_rate' => trim((string) ($row['tax_rate'] ?? '0')),
          ];
      }

      if ((int) $form['supplier_id'] <= 0) {
          $errors[] = 'Please select a supplier.';
      }

      if (count($form['lines']) === 0) {
          $errors[] = 'At least one line item is required.';
      }

      $linesForSave = [];
      foreach ($form['lines'] as $idx => $line) {
          $qty = (float) ($line['quantity'] !== '' ? $line['quantity'] : 0);
          $unitCost = (float) ($line['unit_cost'] !== '' ? $line['unit_cost'] : 0);
          $stockItemId = (int) ($line['stock_item_id'] ?? 0);
          $description = $line['description'];

          $isEmptyLine = $stockItemId <= 0
              && $description === ''
              && $qty <= 0
              && $unitCost <= 0;

          if ($isEmptyLine) {
              continue;
          }

          if ($stockItemId <= 0 && $description === '') {
              $errors[] = 'Line ' . ($idx + 1) . ': enter a description or select a stock item.';
          }

          if ($qty <= 0) {
              $errors[] = 'Line ' . ($idx + 1) . ': quantity must be greater than zero.';
          }

          $linesForSave[] = [
              'stock_item_id' => $stockItemId > 0 ? $stockItemId : null,
              'description' => $description !== '' ? $description : null,
              'quantity' => $line['quantity'],
              'unit_cost' => $line['unit_cost'],
              'tax_rate' => $line['tax_rate'] !== '' ? $line['tax_rate'] : '0',
          ];
      }

      if (count($linesForSave) === 0 && empty($errors)) {
          $errors[] = 'At least one valid line item is required.';
      }

      $previewTotals = count($linesForSave) > 0 ? calculateVendorBillTotals($linesForSave) : null;
      if ($previewTotals !== null && $previewTotals['total_amount'] <= 0 && empty($errors)) {
          $errors[] = 'Total amount must be greater than zero.';
      }

      if (empty($errors)) {
          $poId = $form['purchase_order_id'] !== '' ? (int) $form['purchase_order_id'] : null;
          $header = [
              'supplier_id' => (int) $form['supplier_id'],
              'purchase_order_id' => $poId,
              'linked_stock_po_id' => $poId,
              'supplier_invoice_number' => $form['supplier_invoice_number'] !== '' ? $form['supplier_invoice_number'] : null,
              'bill_date' => $form['bill_date'],
              'due_date' => $form['due_date'] !== '' ? $form['due_date'] : null,
              'currency' => $form['currency'],
              'exchange_rate' => $form['exchange_rate'],
              'notes' => $form['notes'] !== '' ? $form['notes'] : null,
          ];

          try {
              $result = saveVendorBillDraft($pdo, $company_id, $user_id, $header, $linesForSave);

              if (!empty($result['success']) && !empty($result['id'])) {
                  $newBillId = (int) $result['id'];

                  // Phase 4D: redirect to view.php?id={id} when view page exists.
                  $viewPath = __DIR__ . '/view.php';
                  if (is_file($viewPath)) {
                      header('Location: view.php?id=' . $newBillId);
                  } else {
                      header('Location: index.php?success=created');
                  }
                  exit;
              }

              $errors[] = (string) ($result['message'] ?? 'Unable to save vendor bill.');
          } catch (Throwable $e) {
              error_log('Vendor bill create save error: ' . $e->getMessage());
              $errors[] = 'Unable to save vendor bill. Please try again.';
          }
      }
  }
}

$displayTotals = ['subtotal' => 0.0, 'tax_amount' => 0.0, 'total_amount' => 0.0];
if (!empty($form['lines'])) {
    try {
        $normalized = [];
        foreach ($form['lines'] as $line) {
            $normalized[] = [
                'stock_item_id' => $line['stock_item_id'] !== '' ? (int) $line['stock_item_id'] : null,
                'description' => $line['description'],
                'quantity' => $line['quantity'] !== '' ? $line['quantity'] : 0,
                'unit_cost' => $line['unit_cost'] !== '' ? $line['unit_cost'] : 0,
                'tax_rate' => $line['tax_rate'] !== '' ? $line['tax_rate'] : 0,
            ];
        }
        if (count($normalized) > 0) {
            $displayTotals = calculateVendorBillTotals($normalized);
        }
    } catch (Throwable $e) {
        // Display totals stay at zero on preview failure.
    }
}

$stockItemsJson = json_encode(array_map(static function (array $item): array {
    return [
        'id' => (int) $item['id'],
        'label' => trim((string) (($item['sku'] ?? '') !== '' ? $item['sku'] . ' ù ' : '') . ($item['name'] ?? '')),
        'name' => (string) ($item['name'] ?? ''),
    ];
}, $stockItems), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

include __DIR__ . '/../../../includes/header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
    body { font-family: 'Inter', sans-serif; -webkit-font-smoothing: antialiased; }
    .main-content { background: #f8fafc; color: #0f172a; }
    .vb-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; }
    .vb-lines-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .vb-lines-table { min-width: 960px; }
    .vb-line-tax, .vb-line-total { background: #f8fafc; }
</style>

<main class="main-content">
    <div class="min-h-screen pb-12">

        <div class="px-6 sm:px-8 pt-6 pb-2">
            <nav class="text-xs text-gray-500 mb-3" aria-label="Breadcrumb">
                <a href="<?= htmlspecialchars($stockBasePath ?? 'stock/') ?>dashboard.php" class="hover:text-gray-800">Stock</a>
                <span class="mx-1">/</span>
                <a href="../index.php" class="hover:text-gray-800">Purchases</a>
                <span class="mx-1">/</span>
                <a href="index.php" class="hover:text-gray-800">Vendor Bills</a>
                <span class="mx-1">/</span>
                <span class="text-gray-800">Create</span>
            </nav>

            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold text-slate-900">Create Vendor Bill</h1>
                    <p class="text-sm text-slate-500 mt-1 max-w-2xl">
                        Record a supplier invoice before requesting payment.
                    </p>
                </div>
                <a href="index.php"
                   class="inline-flex items-center justify-center gap-2 px-4 py-2 border border-slate-200 rounded-lg text-sm text-slate-700 hover:bg-white bg-slate-50">
                    <i class="fas fa-arrow-left text-xs"></i>
                    Vendor Bills
                </a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="px-6 sm:px-8 mt-4">
                <div class="rounded-lg border border-red-200 bg-red-50 text-red-900 px-4 py-3 text-sm">
                    <p class="font-medium mb-1">Please correct the following:</p>
                    <ul class="list-disc list-inside space-y-0.5">
                        <?php foreach ($errors as $err): ?>
                            <li><?= htmlspecialchars($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($company_id > 0 && $tablesReady): ?>
        <form method="post" action="create.php" id="vendor-bill-form" class="px-6 sm:px-8 mt-6 space-y-6">
            <?php if (function_exists('csrf_token')): ?>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <?php endif; ?>

            <div class="vb-card p-5 sm:p-6">
                <h2 class="text-sm font-semibold text-slate-800 uppercase tracking-wide mb-4">Bill details</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                    <div>
                        <label for="supplier_id" class="block text-xs text-slate-500 mb-1">Supplier <span class="text-red-500">*</span></label>
                        <select name="supplier_id" id="supplier_id" required
                                class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white">
                            <option value="">Select supplier</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?= (int) $supplier['id'] ?>"
                                    <?= (string) $form['supplier_id'] === (string) $supplier['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $supplier['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="purchase_order_id" class="block text-xs text-slate-500 mb-1">Linked purchase order</label>
                        <select name="purchase_order_id" id="purchase_order_id"
                                class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white">
                            <option value="">None</option>
                            <?php foreach ($purchaseOrders as $po): ?>
                                <?php
                                $poLabel = (string) ($po['po_number'] ?? ('PO #' . $po['id']));
                                $poSupplier = (string) ($po['supplier_name'] ?? '');
                                $poTotal = isset($po['total_amount']) && $po['total_amount'] !== null
                                    ? ' ù ' . number_format((float) $po['total_amount'], 2)
                                    : '';
                                ?>
                                <option value="<?= (int) $po['id'] ?>"
                                    data-supplier-id="<?= (int) ($po['supplier_id'] ?? 0) ?>"
                                    <?= (string) $form['purchase_order_id'] === (string) $po['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($poLabel . ($poSupplier !== '' ? ' (' . $poSupplier . ')' : '') . $poTotal) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="supplier_invoice_number" class="block text-xs text-slate-500 mb-1">Supplier invoice number</label>
                        <input type="text" name="supplier_invoice_number" id="supplier_invoice_number"
                               value="<?= htmlspecialchars($form['supplier_invoice_number']) ?>"
                               class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm"
                               placeholder="e.g. INV-2026-001">
                    </div>
                    <div>
                        <label for="bill_date" class="block text-xs text-slate-500 mb-1">Bill date <span class="text-red-500">*</span></label>
                        <input type="date" name="bill_date" id="bill_date" required
                               value="<?= htmlspecialchars($form['bill_date']) ?>"
                               class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm">
                    </div>
                    <div>
                        <label for="due_date" class="block text-xs text-slate-500 mb-1">Due date</label>
                        <input type="date" name="due_date" id="due_date"
                               value="<?= htmlspecialchars($form['due_date']) ?>"
                               class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm">
                    </div>
                    <div>
                        <label for="currency" class="block text-xs text-slate-500 mb-1">Currency</label>
                        <input type="text" name="currency" id="currency" maxlength="3"
                               value="<?= htmlspecialchars($form['currency']) ?>"
                               class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm uppercase">
                    </div>
                    <div>
                        <label for="exchange_rate" class="block text-xs text-slate-500 mb-1">Exchange rate</label>
                        <input type="number" name="exchange_rate" id="exchange_rate" step="0.000001" min="0.000001"
                               value="<?= htmlspecialchars($form['exchange_rate']) ?>"
                               class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm">
                    </div>
                    <div class="md:col-span-2 xl:col-span-3">
                        <label for="notes" class="block text-xs text-slate-500 mb-1">Notes</label>
                        <textarea name="notes" id="notes" rows="2"
                                  class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm"
                                  placeholder="Internal notes (optional)"><?= htmlspecialchars($form['notes']) ?></textarea>
                    </div>
                </div>
            </div>

            <div class="vb-card p-5 sm:p-6">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                    <h2 class="text-sm font-semibold text-slate-800 uppercase tracking-wide">Line items</h2>
                    <button type="button" id="btn-add-line"
                            class="inline-flex items-center gap-2 px-3 py-2 text-sm border border-indigo-200 text-indigo-700 rounded-lg hover:bg-indigo-50">
                        <i class="fas fa-plus text-xs"></i>
                        Add Line
                    </button>
                </div>

                <div class="vb-lines-scroll">
                    <table class="vb-lines-table w-full text-sm" id="lines-table">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-200 text-left">
                                <th class="px-2 py-2 text-xs font-medium text-slate-600 w-48">Stock item</th>
                                <th class="px-2 py-2 text-xs font-medium text-slate-600 min-w-[160px]">Description</th>
                                <th class="px-2 py-2 text-xs font-medium text-slate-600 w-24 text-right">Qty</th>
                                <th class="px-2 py-2 text-xs font-medium text-slate-600 w-28 text-right">Unit cost</th>
                                <th class="px-2 py-2 text-xs font-medium text-slate-600 w-24 text-right">Tax %</th>
                                <th class="px-2 py-2 text-xs font-medium text-slate-600 w-28 text-right">Tax amt</th>
                                <th class="px-2 py-2 text-xs font-medium text-slate-600 w-28 text-right">Line total</th>
                                <th class="px-2 py-2 w-16"></th>
                            </tr>
                        </thead>
                        <tbody id="lines-body">
                            <?php foreach ($form['lines'] as $i => $line): ?>
                            <tr class="vb-line-row border-b border-slate-100" data-line-index="<?= (int) $i ?>">
                                <td class="px-2 py-2 align-top">
                                    <select name="lines[<?= (int) $i ?>][stock_item_id]" class="vb-stock-item w-full px-2 py-1.5 border border-slate-200 rounded text-sm bg-white">
                                        <option value="">ù None ù</option>
                                        <?php foreach ($stockItems as $item): ?>
                                            <option value="<?= (int) $item['id'] ?>"
                                                data-name="<?= htmlspecialchars((string) $item['name'], ENT_QUOTES, 'UTF-8') ?>"
                                                <?= (string) ($line['stock_item_id'] ?? '') === (string) $item['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars(trim((string) (($item['sku'] ?? '') !== '' ? $item['sku'] . ' ù ' : '') . ($item['name'] ?? ''))) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="px-2 py-2 align-top">
                                    <input type="text" name="lines[<?= (int) $i ?>][description]"
                                           value="<?= htmlspecialchars((string) ($line['description'] ?? '')) ?>"
                                           class="vb-description w-full px-2 py-1.5 border border-slate-200 rounded text-sm"
                                           placeholder="Required if no stock item">
                                </td>
                                <td class="px-2 py-2 align-top">
                                    <input type="number" name="lines[<?= (int) $i ?>][quantity]" step="0.0001" min="0"
                                           value="<?= htmlspecialchars((string) ($line['quantity'] ?? '1')) ?>"
                                           class="vb-qty w-full px-2 py-1.5 border border-slate-200 rounded text-sm text-right">
                                </td>
                                <td class="px-2 py-2 align-top">
                                    <input type="number" name="lines[<?= (int) $i ?>][unit_cost]" step="0.0001" min="0"
                                           value="<?= htmlspecialchars((string) ($line['unit_cost'] ?? '')) ?>"
                                           class="vb-unit-cost w-full px-2 py-1.5 border border-slate-200 rounded text-sm text-right">
                                </td>
                                <td class="px-2 py-2 align-top">
                                    <input type="number" name="lines[<?= (int) $i ?>][tax_rate]" step="0.0001" min="0"
                                           value="<?= htmlspecialchars((string) ($line['tax_rate'] ?? '0')) ?>"
                                           class="vb-tax-rate w-full px-2 py-1.5 border border-slate-200 rounded text-sm text-right">
                                </td>
                                <td class="px-2 py-2 align-top text-right">
                                    <span class="vb-line-tax inline-block px-2 py-1.5 text-sm text-slate-600">0.00</span>
                                </td>
                                <td class="px-2 py-2 align-top text-right">
                                    <span class="vb-line-total inline-block px-2 py-1.5 text-sm font-medium text-slate-800">0.00</span>
                                </td>
                                <td class="px-2 py-2 align-top text-center">
                                    <button type="button" class="vb-remove-line text-red-600 hover:text-red-800 p-1" title="Remove line">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-6 flex flex-col sm:flex-row sm:justify-end">
                    <dl class="w-full sm:w-80 space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Subtotal</dt>
                            <dd class="font-medium text-slate-900" id="total-subtotal"><?= number_format((float) $displayTotals['subtotal'], 2) ?></dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Tax amount</dt>
                            <dd class="font-medium text-slate-900" id="total-tax"><?= number_format((float) $displayTotals['tax_amount'], 2) ?></dd>
                        </div>
                        <div class="flex justify-between border-t border-slate-200 pt-2">
                            <dt class="text-slate-700 font-semibold">Total amount</dt>
                            <dd class="font-semibold text-indigo-700 text-lg" id="total-grand"><?= number_format((float) $displayTotals['total_amount'], 2) ?></dd>
                        </div>
                    </dl>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3 pb-8">
                <button type="submit"
                        class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 shadow-sm">
                    <i class="fas fa-save text-xs"></i>
                    Save as Draft
                </button>
                <a href="index.php" class="text-sm text-slate-600 hover:text-slate-900">Cancel</a>
                <p class="text-xs text-slate-400 w-full sm:w-auto">Saves as draft only. Posting to Accounts Payable is a separate step.</p>
            </div>
        </form>
        <?php endif; ?>

    </div>
</main>

<template id="line-row-template">
    <tr class="vb-line-row border-b border-slate-100" data-line-index="__INDEX__">
        <td class="px-2 py-2 align-top">
            <select name="lines[__INDEX__][stock_item_id]" class="vb-stock-item w-full px-2 py-1.5 border border-slate-200 rounded text-sm bg-white">
                <option value="">ù None ù</option>
            </select>
        </td>
        <td class="px-2 py-2 align-top">
            <input type="text" name="lines[__INDEX__][description]" class="vb-description w-full px-2 py-1.5 border border-slate-200 rounded text-sm" placeholder="Required if no stock item">
        </td>
        <td class="px-2 py-2 align-top">
            <input type="number" name="lines[__INDEX__][quantity]" step="0.0001" min="0" value="1" class="vb-qty w-full px-2 py-1.5 border border-slate-200 rounded text-sm text-right">
        </td>
        <td class="px-2 py-2 align-top">
            <input type="number" name="lines[__INDEX__][unit_cost]" step="0.0001" min="0" value="" class="vb-unit-cost w-full px-2 py-1.5 border border-slate-200 rounded text-sm text-right">
        </td>
        <td class="px-2 py-2 align-top">
            <input type="number" name="lines[__INDEX__][tax_rate]" step="0.0001" min="0" value="0" class="vb-tax-rate w-full px-2 py-1.5 border border-slate-200 rounded text-sm text-right">
        </td>
        <td class="px-2 py-2 align-top text-right">
            <span class="vb-line-tax inline-block px-2 py-1.5 text-sm text-slate-600">0.00</span>
        </td>
        <td class="px-2 py-2 align-top text-right">
            <span class="vb-line-total inline-block px-2 py-1.5 text-sm font-medium text-slate-800">0.00</span>
        </td>
        <td class="px-2 py-2 align-top text-center">
            <button type="button" class="vb-remove-line text-red-600 hover:text-red-800 p-1" title="Remove line">
                <i class="fas fa-times"></i>
            </button>
        </td>
    </tr>
</template>

<script>
(function () {
    const stockItems = <?= $stockItemsJson ?: '[]' ?>;
    const linesBody = document.getElementById('lines-body');
    const template = document.getElementById('line-row-template');
    const poSelect = document.getElementById('purchase_order_id');
    const supplierSelect = document.getElementById('supplier_id');

    function formatMoney(n) {
        return (Math.round(n * 100) / 100).toFixed(2);
    }

    function calcLine(row) {
        const qty = parseFloat(row.querySelector('.vb-qty')?.value) || 0;
        const unitCost = parseFloat(row.querySelector('.vb-unit-cost')?.value) || 0;
        const taxRate = parseFloat(row.querySelector('.vb-tax-rate')?.value) || 0;
        const subtotal = qty * unitCost;
        const tax = subtotal * taxRate / 100;
        const total = subtotal + tax;
        row.querySelector('.vb-line-tax').textContent = formatMoney(tax);
        row.querySelector('.vb-line-total').textContent = formatMoney(total);
        return { subtotal, tax, total };
    }

    function recalcTotals() {
        let subtotal = 0;
        let tax = 0;
        document.querySelectorAll('.vb-line-row').forEach(function (row) {
            const r = calcLine(row);
            subtotal += r.subtotal;
            tax += r.tax;
        });
        document.getElementById('total-subtotal').textContent = formatMoney(subtotal);
        document.getElementById('total-tax').textContent = formatMoney(tax);
        document.getElementById('total-grand').textContent = formatMoney(subtotal + tax);
    }

    function fillStockOptions(selectEl) {
        stockItems.forEach(function (item) {
            const opt = document.createElement('option');
            opt.value = String(item.id);
            opt.textContent = item.label;
            opt.dataset.name = item.name || '';
            selectEl.appendChild(opt);
        });
    }

    function reindexRows() {
        document.querySelectorAll('.vb-line-row').forEach(function (row, idx) {
            row.dataset.lineIndex = String(idx);
            row.querySelectorAll('[name^="lines["]').forEach(function (el) {
                const field = el.name.replace(/^lines\[\d+\]/, '');
                el.name = 'lines[' + idx + ']' + field;
            });
        });
    }

    function bindRow(row) {
        row.querySelectorAll('.vb-qty, .vb-unit-cost, .vb-tax-rate').forEach(function (el) {
            el.addEventListener('input', recalcTotals);
        });
        const stockSel = row.querySelector('.vb-stock-item');
        if (stockSel) {
            stockSel.addEventListener('change', function () {
                const opt = stockSel.options[stockSel.selectedIndex];
                const desc = row.querySelector('.vb-description');
                if (opt && opt.value && opt.dataset.name && desc && !desc.value.trim()) {
                    desc.value = opt.dataset.name;
                }
                recalcTotals();
            });
        }
        const removeBtn = row.querySelector('.vb-remove-line');
        if (removeBtn) {
            removeBtn.addEventListener('click', function () {
                const rows = document.querySelectorAll('.vb-line-row');
                if (rows.length <= 1) {
                    alert('At least one line item is required.');
                    return;
                }
                row.remove();
                reindexRows();
                recalcTotals();
            });
        }
    }

    document.getElementById('btn-add-line')?.addEventListener('click', function () {
        const idx = document.querySelectorAll('.vb-line-row').length;
        const html = template.innerHTML.replace(/__INDEX__/g, String(idx));
        const wrap = document.createElement('tbody');
        wrap.innerHTML = html.trim();
        const row = wrap.firstElementChild;
        fillStockOptions(row.querySelector('.vb-stock-item'));
        linesBody.appendChild(row);
        bindRow(row);
        recalcTotals();
    });

    document.querySelectorAll('.vb-line-row').forEach(bindRow);

    if (poSelect && supplierSelect) {
        poSelect.addEventListener('change', function () {
            const opt = poSelect.options[poSelect.selectedIndex];
            const sid = opt ? opt.getAttribute('data-supplier-id') : '';
            if (sid && sid !== '0') {
                supplierSelect.value = sid;
            }
        });
    }

    document.getElementById('vendor-bill-form')?.addEventListener('submit', function (e) {
        const rows = document.querySelectorAll('.vb-line-row');
        if (rows.length < 1) {
            e.preventDefault();
            alert('At least one line item is required.');
            return;
        }
        let valid = 0;
        rows.forEach(function (row) {
            const stockId = row.querySelector('.vb-stock-item')?.value;
            const desc = (row.querySelector('.vb-description')?.value || '').trim();
            const qty = parseFloat(row.querySelector('.vb-qty')?.value) || 0;
            if (qty > 0 && (stockId || desc)) {
                valid++;
            }
        });
        if (valid < 1) {
            e.preventDefault();
            alert('Enter at least one line with quantity and either a stock item or description.');
        }
    });

    recalcTotals();
})();
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
