<?php
/** @var array<int, array<string, mixed>> $recent_vouchers */
if (empty($recent_vouchers)): ?>
    <tr><td colspan="5" class="text-center py-4">No recent vouchers found.</td></tr>
<?php else:
    foreach ($recent_vouchers as $v):
        $st = strtolower((string) ($v['status'] ?? ''));
        $cls = 'sp-pending';
        if ($st === 'approved') {
            $cls = 'sp-approved';
        } elseif ($st === 'rejected') {
            $cls = 'sp-rejected';
        }
        $payeeInitial = strtoupper(substr((string) ($v['payee_name'] ?? 'U'), 0, 1));
        ?>
        <tr>
            <td>
                <a href="../employee/view-voucher.php?id=<?= (int) $v['id'] ?>" class="fw-bold text-dark text-decoration-none">
                    <?= htmlspecialchars((string) $v['voucher_no']) ?>
                </a>
                <small class="d-block text-muted"><?= date('d M, Y', strtotime((string) $v['date_created'])) ?></small>
            </td>
            <td>
                <div class="d-flex align-items-center">
                    <div class="activity-avatar me-2" style="width:28px;height:28px;font-size:10px;">
                        <?= htmlspecialchars($payeeInitial) ?>
                    </div>
                    <span class="fw-500"><?= htmlspecialchars((string) $v['payee_name']) ?></span>
                </div>
            </td>
            <td class="fw-bold"><?= number_format((float) $v['total_amount'], 2) ?> <small class="text-muted"><?= htmlspecialchars((string) $v['currency']) ?></small></td>
            <td>
                <span class="status-pill <?= $cls ?>"><?= ucfirst($st) ?></span>
            </td>
            <td class="text-end">
                <a href="../employee/edit-voucher.php?id=<?= (int) $v['id'] ?>" class="btn btn-light btn-sm rounded-circle" title="Edit"><i class="fas fa-pencil-alt text-muted"></i></a>
                <a href="../employee/view-voucher.php?id=<?= (int) $v['id'] ?>" class="btn btn-light btn-sm rounded-circle" title="View"><i class="fas fa-eye text-muted"></i></a>
            </td>
        </tr>
    <?php endforeach;
endif;
