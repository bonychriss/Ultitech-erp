<?php
/**
 * Formal approvals table — 2 rows × 4 columns (label | value | label | value).
 * PHP 7.0 compatible (no nullable/void return types).
 *
 * Expects: $voucher, $roleStatusMap, $signaturesByName, $gmDisplay, $gmSigRel, $statusLower
 */
if (!isset($normalizePersonName) || !is_callable($normalizePersonName)) {
    $normalizePersonName = function ($name) {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $name)));
    };
}

$vvSigImgMax = !empty($GLOBALS['vvApprovalsSigMaxHeight']) ? (int) $GLOBALS['vvApprovalsSigMaxHeight'] : 40;

$vvStageByRole = array();
if (!empty($allStages) && is_array($allStages)) {
    foreach ($allStages as $vvStage) {
        $vvRoleKey = strtolower(trim((string) (isset($vvStage['role']) ? $vvStage['role'] : '')));
        if ($vvRoleKey === '') {
            continue;
        }
        $vvStageByRole[$vvRoleKey] = $vvStage;
    }
}

// Fallback: build stages from approvals when $allStages was not loaded (e.g. include order / debug)
if (empty($vvStageByRole) && isset($pdo, $voucher_id) && (int) $voucher_id > 0) {
    try {
        $vvApStmt = $pdo->prepare('SELECT role, approver_name, status FROM approvals WHERE voucher_id = ? ORDER BY id ASC');
        $vvApStmt->execute(array((int) $voucher_id));
        while ($vvApRow = $vvApStmt->fetch(PDO::FETCH_ASSOC)) {
            $vvRoleKey = strtolower(trim((string) (isset($vvApRow['role']) ? $vvApRow['role'] : '')));
            if ($vvRoleKey === '') {
                continue;
            }
            if (!isset($vvStageByRole[$vvRoleKey]) || (isset($vvApRow['status']) && $vvApRow['status'] === 'approved')) {
                $vvStageByRole[$vvRoleKey] = $vvApRow;
            }
        }
    } catch (Exception $e) {
    }
}

$vvStagePick = function (array $keys) use ($vvStageByRole) {
    foreach ($keys as $key) {
        $k = strtolower(trim((string) $key));
        if ($k !== '' && isset($vvStageByRole[$k])) {
            return $vvStageByRole[$k];
        }
    }
    return null;
};

$vvStageName = function ($stage) {
    if (!is_array($stage)) {
        return '';
    }
    return trim((string) (isset($stage['approver_name']) ? $stage['approver_name'] : ''));
};

$vvStageApproved = function ($stage) {
    if (!is_array($stage)) {
        return false;
    }
    return strtolower((string) (isset($stage['status']) ? $stage['status'] : '')) === 'approved';
};

$vvApplicantStage = $vvStagePick(array('applicant'));
$vvCheckStage = $vvStagePick(array('check', 'checked by', 'checker'));
$vvDeptStage = $vvStagePick(array('department manager', 'dept manager'));
$vvGmStage = $vvStagePick(array('general manager', 'gm'));

$vvPickName = function ($headerVal, $stage) use ($vvStageName) {
    $headerVal = trim((string) $headerVal);
    if ($headerVal !== '') {
        return $headerVal;
    }
    return $vvStageName($stage);
};

$vvApprovalSlots = array(
    array(
        'label' => 'Applicant',
        'name' => $vvPickName(isset($voucher['applicant']) ? $voucher['applicant'] : '', $vvApplicantStage),
        'approved' => (
            (isset($roleStatusMap['applicant']) && $roleStatusMap['applicant'] === 'approved')
            || $vvStageApproved($vvApplicantStage)
        ),
        'sig' => null,
    ),
    array(
        'label' => 'Check',
        'name' => $vvPickName(isset($voucher['checked_by']) ? $voucher['checked_by'] : '', $vvCheckStage),
        'approved' => (
            (isset($roleStatusMap['checked by']) && $roleStatusMap['checked by'] === 'approved')
            || $vvStageApproved($vvCheckStage)
        ),
        'sig' => null,
    ),
    array(
        'label' => 'Dept Manager',
        'name' => $vvPickName(isset($voucher['department_manager']) ? $voucher['department_manager'] : '', $vvDeptStage),
        'approved' => (
            (isset($roleStatusMap['department manager']) && $roleStatusMap['department manager'] === 'approved')
            || $vvStageApproved($vvDeptStage)
        ),
        'sig' => null,
    ),
    array(
        'label' => 'General Manager',
        'name' => $vvPickName(
            isset($gmDisplay) && trim((string) $gmDisplay) !== '' ? $gmDisplay : (isset($voucher['general_manager']) ? $voucher['general_manager'] : ''),
            $vvGmStage
        ),
        'approved' => strtolower((string) (isset($statusLower) ? $statusLower : '')) === 'approved',
        'sig' => isset($gmSigRel) ? $gmSigRel : null,
    ),
);

foreach ($vvApprovalSlots as $vvIdx => $vvSlot) {
    $nameKey = $normalizePersonName($vvSlot['name']);
    if (empty($vvApprovalSlots[$vvIdx]['sig']) && $nameKey !== '' && !empty($signaturesByName[$nameKey])) {
        $vvApprovalSlots[$vvIdx]['sig'] = $signaturesByName[$nameKey];
    }
}

$vvApprovalTableRows = array(
    array($vvApprovalSlots[0], $vvApprovalSlots[1]),
    array($vvApprovalSlots[2], $vvApprovalSlots[3]),
);
?>
<table class="vv-approvals-table" style="width:100%; max-width:100%; border-collapse:collapse; table-layout:fixed; margin-top:10px; margin-bottom:20px; border:1px solid #000;">
    <colgroup>
        <col class="vv-approval-col-label">
        <col class="vv-approval-col-value">
        <col class="vv-approval-col-label">
        <col class="vv-approval-col-value">
    </colgroup>
    <tbody>
    <?php foreach ($vvApprovalTableRows as $vvPair): ?>
        <tr class="vv-approval-grid-row">
            <?php foreach ($vvPair as $vvSlot): ?>
                <td class="vv-approval-label"><?= htmlspecialchars($vvSlot['label']) ?></td>
                <td class="vv-approval-value">
                    <div class="vv-approval-signatory">
                        <div class="vv-approval-name-wrap">
                            <?php if ($vvSlot['name'] !== ''): ?>
                                <span class="vv-approval-name"><?= htmlspecialchars($vvSlot['name']) ?></span>
                            <?php endif; ?>
                            <?php
                            $vvShowSig = !empty($vvSlot['approved']) && $vvSlot['name'] !== '' && !empty($vvSlot['sig']);
                            if ($vvShowSig):
                                $vvSigH = (int) max(20, min(44, $vvSigImgMax));
                            ?>
                                <img src="<?= htmlspecialchars((string) $vvSlot['sig']) ?>" alt="Signature" class="vv-approval-signature vv-approval-signature--inline" style="max-height:<?= $vvSigH ?>px;width:auto;" onerror="this.style.display='none';">
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
            <?php endforeach; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
