<?php
/** Mobile card list for purchase orders (included from purchases_list_view.inc.php). */
?>
<div class="po-mobile-list md:hidden">
    <?php if (empty($purchases)): ?>
        <div class="px-4 py-14 text-center text-sm text-slate-500">
            No purchase orders found.
            <a href="domestic_create.php" class="text-violet-600 hover:underline ml-1">Create one</a>
        </div>
    <?php else: ?>
        <?php foreach ($purchases as $po):
            $poId = (int) ($po['id'] ?? 0);
            $status = (string) ($po['status'] ?? '');
            $poTypeRaw = trim((string) ($po['purchase_type'] ?? 'domestic'));
            $poType = in_array($poTypeRaw, ['domestic', 'import'], true) ? $poTypeRaw : 'domestic';
            $isImport = $poType === 'import';
            $itemCount = (int) ($po['item_count'] ?? 0);
            $rowWf = $po['procurement_workflow'] ?? PURCHASE_PROC_STANDARD;
            $createdTs = strtotime((string) ($po['created_at'] ?? '')) ?: 0;
            $createdLabel = $createdTs ? date('M j, Y', $createdTs) : '-';
            $statusLabel = function_exists('purchaseDisplayStatusLabel')
                ? purchaseDisplayStatusLabel($status, $rowWf)
                : ($status !== '' ? $status : '-');
            $canReceive = !in_array($status, ['Received', 'Cancelled'], true)
                && (!function_exists('purchaseStatusesBlockingReceive') || !in_array($status, purchaseStatusesBlockingReceive(), true))
                && (!$isImport || (int) ($po['has_shipment'] ?? 0) === 1);
            $productName = (string) ($po['product_name'] ?? '-');
            $poNumber = (string) ($po['purchase_no'] ?? '-');
        ?>
        <article class="po-mobile-card">
            <div class="po-mobile-card-head">
                <a href="view_po.php?id=<?= $poId ?>" class="po-mobile-po-no"><?= htmlspecialchars($poNumber) ?></a>
                <span class="po-status-pill <?= $poStatusClass($status) ?>"><?= htmlspecialchars($statusLabel) ?></span>
            </div>
            <p class="po-mobile-product" title="<?= htmlspecialchars($productName) ?>"><?= htmlspecialchars($productName) ?></p>
            <?php if ($itemCount > 1): ?>
                <span class="po-mobile-badge">+<?= $itemCount - 1 ?> more items</span>
            <?php endif; ?>
            <?php if (!empty($po['product_code'])): ?>
                <p class="po-mobile-code"><?= htmlspecialchars((string) $po['product_code']) ?></p>
            <?php endif; ?>
            <p class="po-mobile-supplier" title="<?= htmlspecialchars((string) ($po['supplier_name'] ?? '')) ?>">
                <?= htmlspecialchars((string) ($po['supplier_name'] ?? '-')) ?>
            </p>
            <div class="po-mobile-meta">
                <span><?= htmlspecialchars($createdLabel) ?></span>
                <span class="po-mobile-total"><?= htmlspecialchars($formatPoTotalDisplay($po)) ?></span>
            </div>
            <div class="po-mobile-card-foot">
                <div class="po-actions-wrap">
                    <a href="view_po.php?id=<?= $poId ?>" class="po-action-btn view no-underline" title="View PO"><i class="fas fa-eye text-sm"></i></a>
                    <button type="button" class="po-action-btn po-actions-toggle" data-po-id="<?= $poId ?>" title="More actions" aria-expanded="false"><i class="fas fa-ellipsis-v text-sm"></i></button>
                    <div class="po-menu" id="po-menu-<?= $poId ?>" role="menu">
                        <a href="view_po.php?id=<?= $poId ?>"><i class="fas fa-file-alt text-gray-400 w-4"></i> View PO</a>
                        <?php if (function_exists('purchaseOrderEditableStatuses') && in_array($status, purchaseOrderEditableStatuses($rowWf), true)): ?>
                            <a href="edit.php?id=<?= $poId ?>"><i class="fas fa-edit text-blue-500 w-4"></i> Edit</a>
                        <?php endif; ?>
                        <?php if ($canReceive): ?>
                            <a href="domestic_receive.php?id=<?= $poId ?>"><i class="fas fa-check-circle text-green-600 w-4"></i> Receive stock</a>
                        <?php endif; ?>
                        <div class="po-menu-divider"></div>
                        <a href="create.php?clone_from_id=<?= $poId ?>"><i class="fas fa-copy text-gray-400 w-4"></i> Clone order</a>
                        <?php if (function_exists('purchaseCancelableStatuses') && in_array($status, purchaseCancelableStatuses($rowWf), true)): ?>
                            <div class="po-menu-divider"></div>
                            <a href="cancel.php?id=<?= $poId ?>" onclick="return confirm('Cancel this order?');"><i class="fas fa-times-circle text-red-500 w-4"></i> Cancel order</a>
                        <?php endif; ?>
                        <?php if ($isAdmin): ?>
                            <div class="po-menu-divider"></div>
                            <button type="button" class="text-red-600 po-delete" data-po-id="<?= $poId ?>"><i class="fas fa-trash-alt w-4"></i> Delete order</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </article>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
