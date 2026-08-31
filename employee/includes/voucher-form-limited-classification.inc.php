<?php
/** Limited edit panel: purpose, sales order links, read-only items, and document uploads. */
$selectedSalesOrderIds = $vfSelectedSalesOrderIds;
$selectedSalesOrderLabel = $vfSalesOrderLabel;
$vfLimitedItems = $vfExistingItems ?? [];
$vfLimitedAttachments = $vfAttachments ?? [];
$vfLimitedSupportingDocs = (int) ($vfSupportingDocs ?? 0);
?>
<div class="alert alert-info mb-3" role="status">
    <strong>Limited edit mode.</strong> You may update <strong>Purpose</strong>, link <strong>Sales Orders / quotations</strong>, and <strong>add supporting documents</strong> on this approved voucher (including after posting).
    Payee, amounts, payment line items, approvals, and existing attachments cannot be changed here.
</div>

<section class="cv-card mb-3">
    <div class="cv-card-header"><span class="dot dot-voucher"><i class="fas fa-file-invoice"></i></span> Voucher Summary (read-only)</div>
    <div class="cv-card-body">
        <div class="row g-2">
            <div class="col-md-3"><small class="text-muted d-block">Voucher No.</small><strong><?= htmlspecialchars($vfVoucherNo !== '' ? $vfVoucherNo : ('#' . $vfVoucherId)) ?></strong></div>
            <div class="col-md-3"><small class="text-muted d-block">Payee</small><strong><?= htmlspecialchars($vfPayeeName !== '' ? $vfPayeeName : '—') ?></strong></div>
            <div class="col-md-3"><small class="text-muted d-block">Amount</small><strong><?= htmlspecialchars($vfCurrency) ?> <?= number_format($vfTotalAmount, 2) ?></strong></div>
            <div class="col-md-3"><small class="text-muted d-block">Status</small><strong><?= htmlspecialchars($vfStatusLabel) ?></strong></div>
        </div>
        <?php if (trim((string) ($vfDescription ?? '')) !== ''): ?>
        <div class="mt-3">
            <small class="text-muted d-block">Description</small>
            <div class="small"><?= nl2br(htmlspecialchars((string) $vfDescription)) ?></div>
        </div>
        <?php endif; ?>
    </div>
</section>

<section class="cv-card mb-3">
    <div class="cv-card-header"><span class="dot dot-payment"><i class="fas fa-list"></i></span> Payment Items (read-only)</div>
    <div class="cv-card-body">
        <?php if (!empty($vfLimitedItems)): ?>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0 vf-limited-items-table">
                <thead class="table-light">
                    <tr>
                        <th>Payment Type</th>
                        <th>Budget Type</th>
                        <th>Name</th>
                        <th class="text-end">Amount</th>
                        <th>Item Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vfLimitedItems as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($item['payment_type'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($item['budget_type'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($item['name'] ?? '')) ?></td>
                        <td class="text-end"><?= htmlspecialchars($vfCurrency) ?> <?= number_format((float) ($item['amount'] ?? 0), 2) ?></td>
                        <td><?= htmlspecialchars((string) ($item['description'] ?? '')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-light fw-semibold">
                        <td colspan="3" class="text-end">Total</td>
                        <td class="text-end"><?= htmlspecialchars($vfCurrency) ?> <?= number_format($vfTotalAmount, 2) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php else: ?>
        <p class="text-muted mb-0 small">No payment line items recorded.</p>
        <?php endif; ?>
    </div>
</section>

<section class="cv-card mb-3">
    <div class="cv-card-header"><span class="dot dot-docs"><i class="fas fa-paperclip"></i></span> Supporting Documents</div>
    <div class="cv-card-body">
        <div class="form-group mb-3">
            <label for="supporting_documents">Supporting Documents (Qty.)</label>
            <input type="number" id="supporting_documents" name="supporting_documents" class="form-control" style="max-width:120px;" min="0" value="<?= max($vfLimitedSupportingDocs, count($vfLimitedAttachments)) ?>" readonly>
            <div class="help-text">Count updates automatically when you upload files.</div>
        </div>
        <div class="form-group mb-3">
            <label for="supporting_files">Upload files (Images, PDF, Office docs)</label>
            <div class="cv-upload-box" id="supporting-files-box">
                <label for="supporting_files" class="cv-upload-trigger">
                    <span class="cv-upload-icon"><i class="fas fa-cloud-upload-alt"></i></span>
                    <span>Drag &amp; drop files here or click to browse</span>
                    <span class="cv-upload-btn">Choose File</span>
                </label>
                <input type="file" id="supporting_files" class="cv-upload-input" name="supporting_files[]" multiple accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.svg,.bmp,.doc,.docx,.xls,.xlsx,image/*,application/pdf">
            </div>
            <div class="help-text" id="supporting-files-selected">No file chosen</div>
            <div class="cv-files-indicator" id="supporting-files-indicator">
                <i class="fas fa-check-circle"></i>
                <span id="supporting-files-indicator-text">Files attached</span>
            </div>
            <div class="help-text">Attach invoices, receipts, quotations, etc. New files are added without removing existing ones.</div>
        </div>
        <?php if (!empty($vfLimitedAttachments)): ?>
        <div class="form-group mb-0">
            <label>Existing attachments (<?= count($vfLimitedAttachments) ?>)</label>
            <div class="vf-existing-attachments" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;">
                <?php foreach ($vfLimitedAttachments as $att):
                    $rel = ltrim((string) ($att['file_path'] ?? ''), '/');
                    $proxyLink = $vfProxyPdfBase . '?file=' . urlencode($rel);
                    $name = (string) ($att['original_name'] ?? basename($rel));
                ?>
                <a href="<?= htmlspecialchars($proxyLink) ?>" target="_blank" rel="noopener" class="cv-btn" style="padding:6px 10px;font-size:12px;text-decoration:none;"><?= htmlspecialchars($name) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="help-text mb-0">No attachments yet.</div>
        <?php endif; ?>
    </div>
</section>

<section class="cv-card mb-3">
    <div class="cv-card-header"><span class="dot dot-payment"><i class="fas fa-tags"></i></span> Classification</div>
    <div class="cv-card-body">
        <div class="form-group mb-3">
            <label for="voucher_purpose">Purpose</label>
            <select id="voucher_purpose" name="voucher_purpose" class="form-select">
                <option value="general" <?= $vfPurpose === 'general' ? 'selected' : '' ?>>General Payment</option>
                <option value="stock_purchase" <?= $vfPurpose === 'stock_purchase' ? 'selected' : '' ?>>Stock Purchase</option>
            </select>
            <div class="help-text">Choose Stock Purchase when this voucher should link to a procurement purchase order.</div>
        </div>
        <div class="form-group mb-0">
            <label for="linked_sales_order_ids">Link Sales Order(s) / Quotation (from Sales Module)</label>
            <div class="so-picker" id="sales-order-picker">
                <input type="hidden" id="linked_sales_order_ids" name="linked_sales_order_ids" value="<?= htmlspecialchars(implode(',', $selectedSalesOrderIds)) ?>">
                <input type="hidden" id="linked_sales_order_id" name="linked_sales_order_id" value="<?= !empty($selectedSalesOrderIds) ? (int) $selectedSalesOrderIds[0] : '' ?>">
                <button type="button" class="so-picker-trigger" id="so-picker-trigger">
                    <span id="so-picker-trigger-label"><?= htmlspecialchars($selectedSalesOrderLabel !== '' ? $selectedSalesOrderLabel : 'Search sales order by number, customer, or status...') ?></span>
                    <i class="fas fa-chevron-down so-caret"></i>
                </button>
                <div class="so-picker-dropdown" id="so-picker-dropdown">
                    <div class="so-picker-search-wrap">
                        <input type="text" id="so-picker-search" class="so-picker-search" placeholder="Search sales order by number, customer, or status...">
                    </div>
                    <div class="so-picker-chips" id="so-picker-chips">
                        <button type="button" class="so-chip active" data-so-chip="all">All</button>
                        <button type="button" class="so-chip" data-so-chip="paid">Paid</button>
                        <button type="button" class="so-chip" data-so-chip="invoiced">Invoiced</button>
                        <button type="button" class="so-chip" data-so-chip="partial">Partial</button>
                    </div>
                    <div class="so-picker-results" id="so-picker-results">
                        <?php foreach ($salesOrders as $so): ?>
                            <?php
                                $soId = (int) ($so['id'] ?? 0);
                                $soNo = (string) ($so['order_number'] ?? ('SO-' . $soId));
                                $soCustomer = (string) ($so['customer_name'] ?? 'Unknown Customer');
                                $soSalesperson = (string) ($so['salesperson_name'] ?? 'Unassigned');
                                $soStatusRaw = trim((string) ($so['status'] ?? ''));
                                $soStatusLower = strtolower($soStatusRaw);
                                $soStatusClass = 'other';
                                if (strpos($soStatusLower, 'paid') !== false) {
                                    $soStatusClass = 'paid';
                                } elseif (strpos($soStatusLower, 'invoice') !== false) {
                                    $soStatusClass = 'invoiced';
                                } elseif (strpos($soStatusLower, 'partial') !== false) {
                                    $soStatusClass = 'partial';
                                }
                            ?>
                            <div
                                class="so-option<?= in_array($soId, $selectedSalesOrderIds, true) ? ' is-selected' : '' ?>"
                                data-so-id="<?= $soId ?>"
                                data-so-number="<?= htmlspecialchars($soNo, ENT_QUOTES) ?>"
                                data-so-customer="<?= htmlspecialchars($soCustomer, ENT_QUOTES) ?>"
                                data-so-salesperson="<?= htmlspecialchars($soSalesperson, ENT_QUOTES) ?>"
                                data-so-status="<?= htmlspecialchars($soStatusLower, ENT_QUOTES) ?>"
                                data-so-chip="<?= htmlspecialchars($soStatusClass, ENT_QUOTES) ?>"
                            >
                                <span class="so-option-check"><i class="fas fa-check"></i></span>
                                <div class="so-option-main">
                                    <div class="so-option-number"><?= htmlspecialchars($soNo) ?></div>
                                    <span class="so-badge <?= htmlspecialchars($soStatusClass) ?>"><?= htmlspecialchars($soStatusRaw !== '' ? ucfirst($soStatusRaw) : 'Open') ?></span>
                                </div>
                                <div class="so-option-subrow">
                                    <div class="so-option-customer"><?= htmlspecialchars($soCustomer) ?></div>
                                    <div class="so-option-salesperson" title="<?= htmlspecialchars($soSalesperson) ?>">Salesperson: <?= htmlspecialchars($soSalesperson) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="so-picker-empty" id="so-picker-empty" style="display:none;">No matching sales orders found.</div>
                    </div>
                    <div class="so-picker-meta">
                        <div class="so-picker-meta-left">
                            <i class="fas fa-search" aria-hidden="true"></i>
                            <span id="so-picker-meta">Type to search more results...</span>
                        </div>
                        <div class="so-picker-meta-right">
                            <span>Show</span>
                            <select id="so-picker-page-size" class="so-picker-page-size" aria-label="Sales orders per page">
                                <option value="5" selected>5</option>
                                <option value="15">15</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="help-text">Linked sales orders appear as quotation PDFs on the voucher and can autofill stock purchase PO items.</div>
        </div>
    </div>
</section>

<input type="hidden" name="limited_classification_update" value="1">

<div class="cv-form-actions cv-form-actions--after-approvals">
    <a href="<?= $vfCancelUrl ?>" class="cv-btn cv-btn-form-action">Cancel</a>
    <?php if ($isVfEdit && $vfViewUrl !== ''): ?>
        <a href="<?= htmlspecialchars($vfViewUrl) ?>" class="cv-btn cv-btn-form-action">View Voucher</a>
    <?php endif; ?>
    <button type="submit" class="cv-btn cv-btn-primary cv-btn-form-action">Save Changes</button>
</div>
