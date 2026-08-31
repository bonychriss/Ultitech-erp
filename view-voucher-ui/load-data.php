<?php

declare(strict_types=1);

/**
 * Load structured voucher view payload for the React UI and JSON API.
 *
 * @return array{ok:bool,error?:string,code?:int,payload?:array}
 */
function vv_load_view_payload(PDO $pdo, int $voucherId, array $opts = []): array
{
    $returnFinance = !empty($opts['returnFinance']);
    $moduleQs = isset($opts['moduleQs']) ? (string) $opts['moduleQs'] : '';

    $stmt = $pdo->prepare(
        'SELECT pv.*, u.full_name AS creator_name, u.department AS creator_department, '
        . 'ua.full_name AS approver_name, ua.email AS approver_email, ua.role AS approver_role '
        . 'FROM payment_vouchers pv '
        . 'LEFT JOIN users u ON pv.created_by = u.id '
        . 'LEFT JOIN users ua ON pv.approved_by = ua.id '
        . 'WHERE pv.id = ?'
    );
    $stmt->execute([$voucherId]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$voucher) {
        return ['ok' => false, 'error' => 'Voucher not found', 'code' => 404];
    }

    $isRestricted = !empty($voucher['is_restricted']) && (int) $voucher['is_restricted'] === 1;
    $isCreator = ((int) ($voucher['created_by'] ?? 0)) === (int) ($_SESSION['user_id'] ?? 0);
    if ($isRestricted && !isAdmin() && !isFinance() && !$isCreator) {
        return ['ok' => false, 'error' => 'Access denied', 'code' => 403];
    }

    $stmt = $pdo->prepare('SELECT * FROM voucher_items WHERE voucher_id = ? ORDER BY id');
    $stmt->execute([$voucherId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $attachments = function_exists('getVoucherAttachments') ? getVoucherAttachments($voucherId) : [];
    $linkedSalesOrders = function_exists('fetchLinkedSalesOrdersForVoucher')
        ? fetchLinkedSalesOrdersForVoucher($voucher)
        : [];

    $isPaid = isset($voucher['is_paid']) && (int) $voucher['is_paid'] === 1;
    $isPosted = isset($voucher['is_posted']) && (int) $voucher['is_posted'] === 1;
    $statusLower = strtolower((string) ($voucher['status'] ?? 'pending'));

    $payeeRaw = trim((string) ($voucher['payee_name'] ?? ''));
    $isPlaceholderPayee = ($payeeRaw === '' || stripos($payeeRaw, '(draft') === 0);
    $isIncompleteCore = $isPlaceholderPayee || (float) ($voucher['total_amount'] ?? 0) <= 0 || count($items) === 0;
    $isDraftDerived = ($statusLower === 'pending') && $isIncompleteCore;
    $blockFinanceForIncomplete = $isIncompleteCore;

    $statusLabel = ucfirst($statusLower ?: 'pending');
    if ($isPosted) {
        $statusLabel = 'Posted';
    } elseif ($isPaid) {
        $statusLabel = 'Paid';
    }
    $statusClass = 'vv-status-' . preg_replace('/[^a-z0-9_-]/', '', $statusLower ?: 'pending');
    if ($isPosted) {
        $statusClass = 'vv-status-posted';
    } elseif ($isPaid) {
        $statusClass = 'vv-status-paid';
    }

    // GM display + signature
    $gmDisplay = trim((string) ($voucher['general_manager'] ?? '')) !== '' ? (string) $voucher['general_manager'] : '';
    if (($voucher['status'] ?? '') === 'approved') {
        $approverEmail = strtolower(trim((string) ($voucher['approver_email'] ?? '')));
        if ($approverEmail === 'rajabmwanyika@gmail.com') {
            if ($gmDisplay === '' || strtoupper($gmDisplay) === 'RAJAB') {
                $gmDisplay = 'RAJABU MWANYIKA';
            }
        } elseif ($approverEmail === 'rajabmsomali@gmail.com') {
            if ($gmDisplay === '') {
                $gmDisplay = trim((string) ($voucher['approver_name'] ?? ''));
            }
        }
    }
    if ($gmDisplay === '' && ($voucher['status'] ?? '') === 'approved' && !empty($voucher['approved_by'])) {
        $gmDisplay = trim((string) ($voucher['approver_name'] ?? ''));
    }
    $gmSigRel = null;
    if (!empty($voucher['approved_by']) && ($voucher['status'] ?? '') === 'approved') {
        $rawPath = function_exists('getUserSignaturePathById') ? getUserSignaturePathById((int) $voucher['approved_by']) : null;
        if ($rawPath) {
            $gmSigRel = app_url('/' . ltrim($rawPath, '/'));
        }
    }

    // User photos, signatures, phones
    $userPhotos = [];
    $userPhotosById = [];
    $signaturesById = [];
    $signaturesByName = [];
    $phonesByName = [];
    try {
        $uStmt = $pdo->query('SELECT lower(full_name) as name, profile_photo, signature_path, id, phone FROM users');
        while ($uRow = $uStmt->fetch(PDO::FETCH_ASSOC)) {
            $uName = function_exists('normalizePersonNameKey') ? normalizePersonNameKey($uRow['name']) : strtolower(trim((string) $uRow['name']));
            if (!empty($uRow['profile_photo'])) {
                $userPhotos[$uName] = $uRow['profile_photo'];
                $userPhotosById[(int) $uRow['id']] = $uRow['profile_photo'];
            }
            if (!empty($uRow['phone'])) {
                $phonesByName[$uName] = $uRow['phone'];
            }
            if (!empty($uRow['signature_path'])) {
                $fullSig = function_exists('mediaUrlFromPath') ? mediaUrlFromPath($uRow['signature_path']) : '';
                if ($fullSig !== '') {
                    $signaturesByName[$uName] = $fullSig;
                    $signaturesById[(int) $uRow['id']] = $fullSig;
                }
            }
        }
    } catch (Throwable $e) { /* ignore */ }

    try {
        $__aps = $pdo->prepare("SELECT approver_id, approver_name, role, status, signature_path, approved_at FROM approvals WHERE voucher_id = ? AND status = 'approved'");
        $__aps->execute([$voucherId]);
        while ($row = $__aps->fetch(PDO::FETCH_ASSOC)) {
            $aid = isset($row['approver_id']) && $row['approver_id'] !== null ? (int) $row['approver_id'] : null;
            $sig = $row['signature_path'] ?? '';
            if (!$sig) {
                continue;
            }
            $fullSig = function_exists('mediaUrlFromPath') ? mediaUrlFromPath($sig) : '';
            if ($fullSig === '') {
                continue;
            }
            if ($aid && !isset($signaturesById[$aid])) {
                $signaturesById[$aid] = $fullSig;
            }
            if (!empty($row['approver_name'])) {
                $lowName = function_exists('normalizePersonNameKey') ? normalizePersonNameKey($row['approver_name']) : strtolower(trim((string) $row['approver_name']));
                if (!isset($signaturesByName[$lowName])) {
                    $signaturesByName[$lowName] = $fullSig;
                }
            }
        }
    } catch (Throwable $e) { /* ignore */ }

    // Approvals list + role map
    $approvalsList = [];
    $roleStatusMap = [];
    try {
        $apStmt = $pdo->prepare('SELECT * FROM approvals WHERE voucher_id = ? ORDER BY created_at ASC, id ASC');
        $apStmt->execute([$voucherId]);
        $approvalsList = $apStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($approvalsList as $ap) {
            $r = strtolower(trim((string) ($ap['role'] ?? '')));
            if ($r !== '') {
                $roleStatusMap[$r] = strtolower(trim((string) ($ap['status'] ?? '')));
            }
        }
    } catch (Throwable $e) {
        $approvalsList = [];
    }

    // Approval flow stages (reuse include � expects $voucher_id)
    $voucher_id = $voucherId;
    $allStages = [];
    $vvApprovalTotal = 0;
    $vvApprovalDone = 0;
    $vvApprovalSummaryClass = 'vv-approval-summary--progress';
    require __DIR__ . '/../includes/voucher-approval-flow-data.php';

    // Build approval table slots
    $normalizePersonName = static function ($name) {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $name)));
    };
    $vvStageByRole = [];
    foreach ($allStages as $vvStage) {
        $vvRoleKey = strtolower(trim((string) ($vvStage['role'] ?? '')));
        if ($vvRoleKey !== '') {
            $vvStageByRole[$vvRoleKey] = $vvStage;
        }
    }
    $vvStagePick = static function (array $keys) use ($vvStageByRole) {
        foreach ($keys as $key) {
            $k = strtolower(trim((string) $key));
            if ($k !== '' && isset($vvStageByRole[$k])) {
                return $vvStageByRole[$k];
            }
        }
        return null;
    };
    $vvStageName = static function ($stage) {
        if (!is_array($stage)) {
            return '';
        }
        return trim((string) ($stage['approver_name'] ?? ''));
    };
    $vvStageApproved = static function ($stage) {
        if (!is_array($stage)) {
            return false;
        }
        return strtolower((string) ($stage['status'] ?? '')) === 'approved';
    };
    $vvApplicantStage = $vvStagePick(['applicant']);
    $vvCheckStage = $vvStagePick(['check', 'checked by', 'checker']);
    $vvDeptStage = $vvStagePick(['department manager', 'dept manager']);
    $vvGmStage = $vvStagePick(['general manager', 'gm']);
    $vvPickName = static function ($headerVal, $stage) use ($vvStageName) {
        $headerVal = trim((string) $headerVal);
        if ($headerVal !== '') {
            return $headerVal;
        }
        return $vvStageName($stage);
    };
    $approvalSlots = [
        [
            'label' => 'Applicant',
            'name' => $vvPickName($voucher['applicant'] ?? '', $vvApplicantStage),
            'approved' => ((isset($roleStatusMap['applicant']) && $roleStatusMap['applicant'] === 'approved') || $vvStageApproved($vvApplicantStage)),
            'sig' => null,
        ],
        [
            'label' => 'Check',
            'name' => $vvPickName($voucher['checked_by'] ?? '', $vvCheckStage),
            'approved' => ((isset($roleStatusMap['checked by']) && $roleStatusMap['checked by'] === 'approved') || $vvStageApproved($vvCheckStage)),
            'sig' => null,
        ],
        [
            'label' => 'Dept Manager',
            'name' => $vvPickName($voucher['department_manager'] ?? '', $vvDeptStage),
            'approved' => ((isset($roleStatusMap['department manager']) && $roleStatusMap['department manager'] === 'approved') || $vvStageApproved($vvDeptStage)),
            'sig' => null,
        ],
        [
            'label' => 'General Manager',
            'name' => $vvPickName($gmDisplay !== '' ? $gmDisplay : ($voucher['general_manager'] ?? ''), $vvGmStage),
            'approved' => $statusLower === 'approved',
            'sig' => $gmSigRel,
        ],
    ];
    foreach ($approvalSlots as $idx => $slot) {
        $nameKey = $normalizePersonName($slot['name']);
        if (empty($approvalSlots[$idx]['sig']) && $nameKey !== '' && !empty($signaturesByName[$nameKey])) {
            $approvalSlots[$idx]['sig'] = $signaturesByName[$nameKey];
        }
    }

    // Swift proxy
    $swiftProxy = null;
    if (!empty($voucher['swift_document'])) {
        $p = (string) $voucher['swift_document'];
        if (strpos($p, 'assets/') === 0) {
            $relForProxy = $p;
        } elseif (preg_match('#^https?://#i', $p)) {
            $relForProxy = $p;
        } else {
            $relForProxy = 'assets/uploads/vouchers/' . ltrim($p, '/');
        }
        $swiftProxy = preg_match('#^https?://#i', $relForProxy)
            ? $relForProxy
            : (app_url('/proxy_pdf.php') . '?file=' . urlencode(ltrim($relForProxy, '/')));
    }

    // Format attachments for frontend
    $attachmentRows = [];
    foreach ($attachments as $att) {
        $rel = ltrim((string) ($att['file_path'] ?? ''), '/');
        $name = (string) ($att['original_name'] ?? basename($rel));
        $mime = strtolower((string) ($att['mime_type'] ?? ''));
        $isImg = strpos($mime, 'image/') === 0 || preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $rel);
        $ext = strtoupper(pathinfo($name, PATHINFO_EXTENSION) ?: ($isImg ? 'IMG' : 'FILE'));
        $diskPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $fileSizeLabel = '';
        if (is_file($diskPath)) {
            $bytes = (int) filesize($diskPath);
            if ($bytes > 0) {
                $fileSizeLabel = $bytes < 1024 ? ($bytes . ' B') : ($bytes < 1048576 ? ((int) round($bytes / 1024) . ' KB') : (number_format($bytes / 1048576, 1) . ' MB'));
            }
        }
        $attachmentRows[] = [
            'id' => (int) ($att['id'] ?? 0),
            'name' => $name,
            'proxyLink' => app_url('/proxy_pdf.php') . '?file=' . urlencode($rel),
            'isImage' => $isImg,
            'typeLabel' => $isImg ? 'Image' : ($ext === 'PDF' ? 'PDF' : $ext),
            'fileSizeLabel' => $fileSizeLabel,
        ];
    }

    $salesOrderDocs = [];
    foreach ($linkedSalesOrders as $linkedSalesOrder) {
        $soId = (int) ($linkedSalesOrder['id'] ?? 0);
        $soNo = (string) ($linkedSalesOrder['order_number'] ?? ('SO-' . $soId));
        $soPdfLink = function_exists('salesOrderPrintPdfUrl') ? salesOrderPrintPdfUrl($soId) : '#';
        $salesOrderDocs[] = ['id' => $soId, 'orderNumber' => $soNo, 'pdfLink' => $soPdfLink];
    }

    // Comments
    $comments = [];
    try {
        $stmtC = $pdo->prepare(
            'SELECT al.*, u.full_name, u.role FROM approval_logs al '
            . 'LEFT JOIN users u ON al.user_id = u.id '
            . "WHERE al.voucher_id = ? AND al.comments IS NOT NULL AND al.comments != '' "
            . 'ORDER BY al.created_at DESC'
        );
        $stmtC->execute([$voucherId]);
        $comments = $stmtC->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { /* ignore */ }

    // Permissions + URLs (tenant-prefixed so Back never drops /{company}/)
    $vvJoinQs = static function (string $url, string $qs): string {
        $qs = ltrim($qs, '?&');
        if ($qs === '') {
            return $url;
        }
        return $url . (strpos($url, '?') !== false ? '&' : '?') . $qs;
    };
    $dashPath = isAdmin() ? 'admin/dashboard.php' : 'employee/dashboard.php';
    $allPath = isAdmin() ? 'admin/all-vouchers.php' : 'employee/my-vouchers.php';
    $backLink = function_exists('company_url') ? company_url($dashPath) : app_url($dashPath);
    $backLink = $vvJoinQs($backLink, $moduleQs);
    $homeLink = $backLink;
    $allLink = function_exists('company_url') ? company_url($allPath) : app_url($allPath);
    $allLink = $vvJoinQs($allLink, $moduleQs !== '' ? $moduleQs : 'module=voucher');
    if ($returnFinance) {
        $backLink = function_exists('company_url')
            ? company_url('modules/finance/my_expenses.php')
            : app_url('modules/finance/my_expenses.php');
    }
    $approvedByAdmin = !empty($voucher['approved_by']) && (isset($voucher['approver_role']) && defined('ROLE_ADMIN') && $voucher['approver_role'] === ROLE_ADMIN);
    $canMarkPaid = !$isPaid && $statusLower === 'approved' && (
        isAdmin() || (isFinance() && $approvedByAdmin && !$isDraftDerived && !$blockFinanceForIncomplete)
    );
    $canPost = $isPaid && !$isPosted && (isFinance() || isAdmin()) && $statusLower === 'approved';
    $vvShowEdit = (function_exists('canEditVoucher') && canEditVoucher($voucherId, (int) ($_SESSION['user_id'] ?? 0)) && !$isPosted && !$returnFinance)
        || (function_exists('canLimitedEditApprovedVoucher') && canLimitedEditApprovedVoucher($voucherId, (int) ($_SESSION['user_id'] ?? 0)));
    $canDeleteAttachment = function_exists('canEditVoucher') && canEditVoucher($voucherId, (int) ($_SESSION['user_id'] ?? 0));

    $userPendingApprovals = [];
    $pendingCount = 0;
    try {
        $uid = function_exists('resolveVoucherSessionUserId') ? resolveVoucherSessionUserId($pdo) : (int) ($_SESSION['user_id'] ?? 0);
        $uname = trim((string) ($_SESSION['full_name'] ?? ''));
        if ($uname === '') {
            $uname = trim((string) ($_SESSION['username'] ?? ''));
        }
        if ($uname === '' && function_exists('resolveVoucherSessionDisplayName')) {
            $uname = resolveVoucherSessionDisplayName($pdo);
        }
        if ($uid > 0 && function_exists('getUserPendingVoucherApprovals')) {
            $userPendingApprovals = getUserPendingVoucherApprovals($pdo, $voucherId, $uid, $uname, $voucher);
        }
        $qc = $pdo->prepare("SELECT COUNT(*) FROM approvals WHERE voucher_id = ? AND status = 'pending'");
        $qc->execute([$voucherId]);
        $pendingCount = (int) $qc->fetchColumn();
    } catch (Throwable $e) {
        $pendingCount = 0;
    }

    $finAccounts = [];
    try {
        $finAccounts = $pdo->query("SELECT id, name, type, current_balance, currency FROM financial_accounts WHERE status = 'active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { /* ignore */ }

    $voucherLogoUrl = function_exists('getCompanyLogoUrl') ? getCompanyLogoUrl() : '';
    if ($voucherLogoUrl === '' && function_exists('getCompanySetting')) {
        $voucherLogoUrl = function_exists('mediaUrlFromPath') ? mediaUrlFromPath(getCompanySetting('company_logo', '')) : '';
    }
    if ($voucherLogoUrl === '') {
        $voucherLogoUrl = app_url('/assets/images/Untitled.jpg');
    }

    $postedImgRel = file_exists(dirname(__DIR__) . '/assets/images/posted-trim.png')
        ? app_url('/assets/images/posted-trim.png')
        : app_url('/assets/images/posted.png');
    $paidImgRel = app_url('/assets/images/IMG_6470.PNG');

    $notifyTarget = null;
    if ($statusLower === 'pending' && function_exists('getVoucherNotificationTarget')) {
        $nt = getVoucherNotificationTarget($voucher, $_SESSION['full_name'] ?? '');
        if ($nt && !empty($nt['link'])) {
            $notifyTarget = ['role' => (string) ($nt['role'] ?? ''), 'link' => (string) $nt['link']];
        }
    }

    $whatsappGroupLink = function_exists('getWhatsAppGroupLink') ? getWhatsAppGroupLink() : null;
    $shareMsg = 'Hello, Payment Voucher ' . ($voucher['voucher_no'] ?? '') . ' has been generated for ' . ($voucher['payee_name'] ?? 'N/A') . ' and is ready for review.';
    $shareUrl = $whatsappGroupLink ? ('https://api.whatsapp.com/send?text=' . urlencode($shareMsg)) : null;

    $profileSig = '';
    try {
        $profileSig = function_exists('getUserSignaturePathById') ? (getUserSignaturePathById((int) ($_SESSION['user_id'] ?? 0)) ?: '') : '';
    } catch (Throwable $e) {
        $profileSig = '';
    }
    if ($profileSig !== '') {
        $profileSig = app_url('/' . ltrim($profileSig, '/'));
    }

    // Resolve stage photos for stepper
    $stagesForUi = [];
    foreach ($allStages as $st) {
        $displayName = trim((string) ($st['approver_name'] ?? ''));
        $photoUrl = '';
        if (function_exists('resolve_approver_profile_photo')) {
            $photoUrl = resolve_approver_profile_photo($st, $userPhotos, $userPhotosById);
        }
        $nameKey = function_exists('normalizePersonNameKey') ? normalizePersonNameKey($displayName) : strtolower(trim($displayName));
        $waPhone = $phonesByName[$nameKey] ?? '';
        $stagesForUi[] = [
            'id' => (int) ($st['id'] ?? 0),
            'role' => (string) ($st['role'] ?? ''),
            'approver_name' => $displayName,
            'status' => (string) ($st['status'] ?? 'pending'),
            'approved_at' => $st['approved_at'] ?? null,
            'photoUrl' => $photoUrl,
            'whatsappPhone' => $waPhone,
        ];
    }

    $declaredCount = isset($voucher['supporting_documents']) ? (int) $voucher['supporting_documents'] : 0;
    $visibleAttachmentCount = count($attachmentRows) + count($salesOrderDocs) + ($swiftProxy ? 1 : 0);
    $mismatch = ($declaredCount > 0 && empty($attachmentRows) && empty($salesOrderDocs));
    $headerCount = $mismatch ? max($declaredCount, $visibleAttachmentCount) : $visibleAttachmentCount;

    $paidBeforeProperApproval = $isPaid && ($statusLower !== 'approved' || ($voucher['approver_role'] ?? null) !== (defined('ROLE_ADMIN') ? ROLE_ADMIN : 'admin'));
    $showAnomaly = $paidBeforeProperApproval && (isAdmin() || isFinance());

    $editHref = 'edit-voucher.php?id=' . $voucherId . $moduleQs;
    if (!isAdmin()) {
        $editHref = 'employee/edit-voucher.php?id=' . $voucherId . $moduleQs;
    }

    return [
        'ok' => true,
        'payload' => [
            'voucher' => [
                'id' => (int) $voucher['id'],
                'voucher_no' => (string) ($voucher['voucher_no'] ?? ''),
                'payee_name' => (string) ($voucher['payee_name'] ?? ''),
                'prepared_by' => (string) ($voucher['prepared_by'] ?? ''),
                'creator_name' => (string) ($voucher['creator_name'] ?? ''),
                'description' => (string) ($voucher['description'] ?? ''),
                'supporting_documents' => (string) ($voucher['supporting_documents'] ?? '0'),
                'currency' => (string) ($voucher['currency'] ?? 'TZS'),
                'total_amount' => (float) ($voucher['total_amount'] ?? 0),
                'date_created' => (string) ($voucher['date_created'] ?? ''),
                'status' => $statusLower,
                'applicant' => (string) ($voucher['applicant'] ?? ''),
                'department_manager' => (string) ($voucher['department_manager'] ?? ''),
                'checked_by' => (string) ($voucher['checked_by'] ?? ''),
                'general_manager' => (string) ($voucher['general_manager'] ?? ''),
            ],
            'items' => array_map(static function ($item) {
                return [
                    'payment_type' => (string) ($item['payment_type'] ?? ''),
                    'budget_type' => (string) ($item['budget_type'] ?? ''),
                    'name' => (string) ($item['name'] ?? ''),
                    'amount' => (float) ($item['amount'] ?? 0),
                    'description' => (string) ($item['description'] ?? ''),
                ];
            }, $items),
            'approvalSlots' => $approvalSlots,
            'approvalStages' => $stagesForUi,
            'approvalSummary' => [
                'done' => (int) $vvApprovalDone,
                'total' => (int) $vvApprovalTotal,
                'className' => $vvApprovalSummaryClass,
            ],
            'attachments' => $attachmentRows,
            'salesOrderDocs' => $salesOrderDocs,
            'swiftProxy' => $swiftProxy,
            'documents' => [
                'headerCount' => $headerCount,
                'declaredCount' => $declaredCount,
                'mismatch' => $mismatch,
                'hasSupporting' => !empty($attachmentRows) || !empty($salesOrderDocs) || !empty($swiftProxy),
            ],
            'comments' => array_map(static function ($vc) {
                return [
                    'full_name' => (string) ($vc['full_name'] ?? ''),
                    'action' => (string) ($vc['action'] ?? ''),
                    'comments' => (string) ($vc['comments'] ?? ''),
                    'created_at' => (string) ($vc['created_at'] ?? ''),
                ];
            }, $comments),
            'status' => [
                'label' => $statusLabel,
                'className' => $statusClass,
                'isPaid' => $isPaid,
                'isPosted' => $isPosted,
                'isDraft' => $isDraftDerived,
                'showAnomaly' => $showAnomaly,
            ],
            'media' => [
                'logoUrl' => $voucherLogoUrl,
                'postedStampUrl' => $postedImgRel,
                'paidStampUrl' => $paidImgRel,
                'fallbackLogoUrl' => app_url('/assets/images/Untitled.jpg'),
            ],
            'permissions' => [
                'isAdmin' => isAdmin(),
                'isFinance' => isFinance(),
                'canMarkPaid' => $canMarkPaid,
                'canPost' => $canPost,
                'canEdit' => $vvShowEdit,
                'canDeleteAttachment' => $canDeleteAttachment,
                'canFinalApprove' => isAdmin() && in_array($statusLower, [defined('STATUS_PENDING') ? STATUS_PENDING : 'pending', defined('STATUS_CONFIRMING') ? STATUS_CONFIRMING : 'confirming'], true),
                'canReject' => isAdmin() && in_array($statusLower, [defined('STATUS_PENDING') ? STATUS_PENDING : 'pending', defined('STATUS_CONFIRMING') ? STATUS_CONFIRMING : 'confirming'], true),
                'blockFinalApproveOnConfirming' => $statusLower === 'confirming',
            ],
            'actions' => [
                'backUrl' => $backLink,
                'editHref' => $editHref,
                'markPaidUrl' => isAdmin() ? 'mark-paid.php' : 'employee/mark-paid.php',
                'approveUrl' => 'employee/approve_voucher.php',
                'deleteAttachmentUrl' => app_url('/delete_attachment.php'),
                'viewVoucherPostUrl' => 'view-voucher.php?id=' . $voucherId,
                'returnFinance' => $returnFinance,
            ],
            'finAccounts' => array_map(static function ($acc) {
                return [
                    'id' => (int) ($acc['id'] ?? 0),
                    'name' => (string) ($acc['name'] ?? ''),
                    'currency' => (string) ($acc['currency'] ?? 'TZS'),
                    'current_balance' => (float) ($acc['current_balance'] ?? 0),
                ];
            }, $finAccounts),
            'userPendingApprovals' => array_map(static function ($ua) {
                return [
                    'id' => (int) ($ua['id'] ?? 0),
                    'role' => (string) ($ua['role'] ?? ''),
                    'role_key' => (string) ($ua['role_key'] ?? ''),
                    'approver_name' => (string) ($ua['approver_name'] ?? ''),
                    'is_final_approval' => !empty($ua['is_final_approval']),
                ];
            }, $userPendingApprovals),
            'pendingCount' => $pendingCount,
            'notifyTarget' => $notifyTarget,
            'shareUrl' => $shareUrl,
            'profileSignature' => $profileSig,
            'breadcrumbs' => [
                'home' => $homeLink,
                'all' => $allLink,
            ],
        ],
    ];
}
