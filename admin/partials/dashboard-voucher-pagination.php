<?php
/**
 * @var int $page
 * @var int $total_pages
 * @var int $total_voucher_records
 * @var int $voucherListFrom
 * @var int $voucherListTo
 */
$voucherPageNumbers = paginationPageWindow($page, $total_pages, 10);
?>
<div class="voucher-list-pagination">
    <p class="voucher-list-pagination-summary mb-0">
        Showing <strong><?= (int) $voucherListFrom ?></strong>
        to <strong><?= (int) $voucherListTo ?></strong>
        of <strong><?= (int) $total_voucher_records ?></strong> vouchers
    </p>
    <nav class="voucher-list-pagination-nav" aria-label="Voucher list pages">
        <?php if ($page > 1): ?>
            <a href="#" data-page="<?= (int) ($page - 1) ?>" class="page-btn">Previous</a>
        <?php else: ?>
            <span class="page-btn is-disabled">Previous</span>
        <?php endif; ?>
        <?php foreach ($voucherPageNumbers as $pNum): ?>
            <?php if ($pNum === $page): ?>
                <span class="page-btn page-num is-active" aria-current="page"><?= (int) $pNum ?></span>
            <?php else: ?>
                <a href="#" data-page="<?= (int) $pNum ?>" class="page-btn page-num"><?= (int) $pNum ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($page < $total_pages): ?>
            <a href="#" data-page="<?= (int) ($page + 1) ?>" class="page-btn">Next</a>
        <?php else: ?>
            <span class="page-btn is-disabled">Next</span>
        <?php endif; ?>
    </nav>
</div>
