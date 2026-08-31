<?php
/**
 * Build approval flow stages and summary counts for voucher view pages.
 * Sets: $allStages, $vvApprovalTotal, $vvApprovalDone, $vvApprovalSummaryClass
 */
$allStages = [];
$vvApprovalTotal = 0;
$vvApprovalDone = 0;
$vvApprovalSummaryClass = 'vv-approval-summary--progress';

if (!isset($pdo, $voucher_id, $voucher) || !is_array($voucher)) {
    return;
}

$normalizeApprovalRoleKey = static function ($role): string {
    $r = strtolower(trim((string) $role));
    $aliases = [
        'gm' => 'general manager',
        'general manager' => 'general manager',
        'dept manager' => 'department manager',
        'department manager' => 'department manager',
        'checker' => 'checked by',
        'check' => 'checked by',
        'checked by' => 'checked by',
        'applicant' => 'applicant',
    ];

    return $aliases[$r] ?? $r;
};

try {
    $voucherStatus = strtolower(trim((string) ($voucher['status'] ?? '')));
    $voucherGmFinalized = in_array($voucherStatus, ['approved', 'posted'], true);
    $gmName = trim((string) ($voucher['general_manager'] ?? ''));
    if ($gmName === '' && $voucherGmFinalized) {
        $gmName = trim((string) ($voucher['approver_name'] ?? ''));
    }
    $gmApprovedAt = !empty($voucher['approved_at']) ? $voucher['approved_at'] : null;

    $requiredRoles = [
        'applicant' => ['label' => 'Applicant', 'name' => trim((string) ($voucher['applicant'] ?? ''))],
        'department manager' => ['label' => 'Department Manager', 'name' => trim((string) ($voucher['department_manager'] ?? ''))],
        'checked by' => ['label' => 'Checked By', 'name' => trim((string) ($voucher['checked_by'] ?? ''))],
        'general manager' => ['label' => 'General Manager', 'name' => $gmName !== '' ? $gmName : trim((string) ($voucher['general_manager'] ?? ''))],
    ];

    $buildDedupMap = static function (array $rows, array $roleNames = []) use ($normalizeApprovalRoleKey): array {
        $map = [];
        foreach ($rows as $st) {
            $rKey = $normalizeApprovalRoleKey($st['role'] ?? '');
            if ($rKey === '') {
                continue;
            }
            if (!isset($map[$rKey])) {
                $map[$rKey] = $st;
                continue;
            }
            $expectedName = trim((string) ($roleNames[$rKey] ?? ''));
            $curName = trim((string) ($map[$rKey]['approver_name'] ?? ''));
            $newName = trim((string) ($st['approver_name'] ?? ''));
            if ($expectedName !== '') {
                $curMatch = strcasecmp($curName, $expectedName) === 0;
                $newMatch = strcasecmp($newName, $expectedName) === 0;
                if ($newMatch && !$curMatch) {
                    $map[$rKey] = $st;
                    continue;
                }
                if ($curMatch && !$newMatch) {
                    continue;
                }
            }
            if (($st['status'] ?? '') === 'approved' && ($map[$rKey]['status'] ?? '') !== 'approved') {
                $map[$rKey] = $st;
            }
        }
        return $map;
    };

    $roleNameLookup = [];
    foreach ($requiredRoles as $rk => $meta) {
        $roleNameLookup[$rk] = trim((string) ($meta['name'] ?? ''));
    }

    $stq = $pdo->prepare('SELECT id, approver_id, approver_name, role, status, approved_at FROM approvals WHERE voucher_id = ? ORDER BY id ASC');
    $stq->execute([(int) $voucher_id]);
    $allStagesRaw = $stq->fetchAll();
    $dedupMap = $buildDedupMap($allStagesRaw, $roleNameLookup);

    // payment_vouchers is the source of truth for assignee names; repair stale approvals rows.
    $needsApprovalSync = false;
    foreach (['applicant', 'department manager', 'checked by'] as $roleKey) {
        $expected = $requiredRoles[$roleKey]['name'] ?? '';
        if ($expected === '') {
            continue;
        }
        if (!isset($dedupMap[$roleKey])) {
            $needsApprovalSync = true;
            break;
        }
        $actual = trim((string) ($dedupMap[$roleKey]['approver_name'] ?? ''));
        if ($actual === '' || strcasecmp($actual, $expected) !== 0) {
            $needsApprovalSync = true;
            break;
        }
    }
    if ($needsApprovalSync && function_exists('syncVoucherApprovalAssignees')) {
        syncVoucherApprovalAssignees($pdo, (int) $voucher_id, [
            'Applicant' => $requiredRoles['applicant']['name'],
            'Department Manager' => $requiredRoles['department manager']['name'],
            'Checked By' => $requiredRoles['checked by']['name'],
        ]);
        $stq->execute([(int) $voucher_id]);
        $allStagesRaw = $stq->fetchAll();
        $dedupMap = $buildDedupMap($allStagesRaw, $roleNameLookup);
    }

    $allStages = array_values($dedupMap);
    foreach ($requiredRoles as $roleKey => $meta) {
        if (!isset($dedupMap[$roleKey])) {
            $stageStatus = 'pending';
            $stageApprovedAt = null;
            $stageName = $meta['name'];
            if ($roleKey === 'general manager' && $voucherGmFinalized && $gmName !== '') {
                $stageStatus = 'approved';
                $stageName = $gmName;
                $stageApprovedAt = $gmApprovedAt;
            }
            $allStages[] = [
                'id' => 0,
                'approver_id' => (int) ($voucher['approved_by'] ?? 0),
                'approver_name' => $stageName,
                'role' => $meta['label'],
                'status' => $stageStatus,
                'approved_at' => $stageApprovedAt,
            ];
        }
    }

    // GM is finalized on the voucher (admin approval) but often has no row in approvals — sync for display.
    if ($voucherGmFinalized && $gmName !== '') {
        foreach ($allStages as &$stageRow) {
            $roleKey = $normalizeApprovalRoleKey($stageRow['role'] ?? '');
            if ($roleKey !== 'general manager') {
                continue;
            }
            if (strtolower((string) ($stageRow['status'] ?? '')) === 'rejected') {
                continue;
            }
            $stageRow['status'] = 'approved';
            $stageRow['approver_name'] = $gmName;
            if (empty($stageRow['approved_at']) && $gmApprovedAt) {
                $stageRow['approved_at'] = $gmApprovedAt;
            }
        }
        unset($stageRow);
    }

    // Display always follows payment_vouchers assignee names.
    foreach ($allStages as &$stageRow) {
        $roleKey = $normalizeApprovalRoleKey($stageRow['role'] ?? '');
        if (!isset($requiredRoles[$roleKey])) {
            continue;
        }
        $expectedName = trim((string) ($requiredRoles[$roleKey]['name'] ?? ''));
        if ($expectedName !== '') {
            $stageRow['approver_name'] = $expectedName;
        }
    }
    unset($stageRow);

    $roleOrder = array_keys($requiredRoles);
    usort($allStages, static function ($a, $b) use ($roleOrder, $normalizeApprovalRoleKey) {
        $ra = $normalizeApprovalRoleKey($a['role'] ?? '');
        $rb = $normalizeApprovalRoleKey($b['role'] ?? '');
        $ia = array_search($ra, $roleOrder, true);
        $ib = array_search($rb, $roleOrder, true);
        $ia = ($ia === false) ? 99 : $ia;
        $ib = ($ib === false) ? 99 : $ib;
        if ($ia !== $ib) {
            return $ia <=> $ib;
        }
        return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
    });

    $vvApprovalTotal = count($allStages);
    foreach ($allStages as $stageRow) {
        if (strtolower((string) ($stageRow['status'] ?? '')) === 'approved') {
            $vvApprovalDone++;
        }
    }
} catch (Throwable $e) {
    $allStages = [];
    $vvApprovalTotal = 0;
    $vvApprovalDone = 0;
}

$vvApprovalSummaryClass = ($vvApprovalTotal > 0 && $vvApprovalDone >= $vvApprovalTotal)
    ? 'vv-approval-summary--done'
    : 'vv-approval-summary--progress';
