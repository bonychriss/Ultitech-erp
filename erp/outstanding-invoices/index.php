<?php
require_once '../../includes/functions.php';
requireLogin();
ensureOutstandingInvoicesSchema();
ensurePaymentVoucherDueDateColumn($pdo);
if (function_exists('backfillOutstandingCreditVoucherPayables')) {
    backfillOutstandingCreditVoucherPayables();
}
if (function_exists('backfillOutstandingInvoiceDueDates')) {
    backfillOutstandingInvoiceDueDates();
}
$rootPath = '../../';
$modulesLink = '../../select-module.php';
$logoBase = '../../';

$success = (string) ($_SESSION['outstanding_invoice_success'] ?? '');
unset($_SESSION['outstanding_invoice_success']);
$error = '';

// Handle Actions (Create, Delete/Pay)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['set_payable_due_date'])) {
        $dueDate = trim((string) ($_POST['due_date'] ?? ''));
        if (!isFinance()) {
            $error = 'Only Finance users can change payable due dates.';
        } elseif (!verify_csrf($_POST['csrf_token'] ?? '')) {
            $error = 'Your session has expired. Refresh the page and try again.';
        } elseif (!validOutstandingDueDate($dueDate)) {
            $error = 'Please enter a valid due date.';
        } else {
            $pdo->beginTransaction();
            try {
                $find = $pdo->prepare("SELECT source_payment_voucher_id FROM erp_outstanding_invoices WHERE id = ? AND type = 'payable' AND status = 'outstanding' FOR UPDATE");
                $find->execute([(int) ($_POST['id'] ?? 0)]);
                $row = $find->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    throw new RuntimeException('Outstanding payable not found.');
                }
                if (!empty($row['source_payment_voucher_id'])) {
                    $findVoucher = $pdo->prepare('SELECT id FROM payment_vouchers WHERE id = ? FOR UPDATE');
                    $findVoucher->execute([(int) $row['source_payment_voucher_id']]);
                    if (!$findVoucher->fetchColumn()) {
                        throw new RuntimeException('Linked payment voucher not found.');
                    }
                    $updateVoucher = $pdo->prepare('UPDATE payment_vouchers SET due_date = ? WHERE id = ?');
                    $updateVoucher->execute([$dueDate, (int) $row['source_payment_voucher_id']]);
                }
                $pdo->prepare('UPDATE erp_outstanding_invoices SET due_date = ? WHERE id = ?')->execute([$dueDate, (int) $_POST['id']]);
                $pdo->commit();
                $_SESSION['outstanding_invoice_success'] = 'Payable due date updated.';
                header('Location: ?' . http_build_query($_GET), true, 303);
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Unable to update payable due date: ' . $e->getMessage());
                $error = 'Could not update the payable due date. Please try again.';
            }
        }
    }
    if (isset($_POST['create_invoice'])) {
        $type = $_POST['type'] ?? 'receivable'; // receivable or payable
        $date = $_POST['invoice_date'] ?? date('Y-m-d');
        $name = trim($_POST['entity_name'] ?? '');
        $invoiceNo = trim($_POST['invoice_number'] ?? '');
        $narration = trim($_POST['narration'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        
        // Handle Attachment Upload
        $attachmentPath = null;
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../../assets/uploads/invoices/';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }
            
            $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
            $newFilename = uniqid('inv_') . '.' . $ext;
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $newFilename)) {
                $attachmentPath = 'assets/uploads/invoices/' . $newFilename;
            }
        }

        $dateObj = DateTime::createFromFormat('Y-m-d', (string) $date);
        if (empty($name) || $amount <= 0) {
            $error = 'Please provide a valid Name and Amount.';
        } elseif (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
            $error = 'Please provide a valid date.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO erp_outstanding_invoices (type, invoice_date, entity_name, invoice_number, narration, amount, attachment) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$type, $date, $name, $invoiceNo, $narration, $amount, $attachmentPath])) {
                $_SESSION['outstanding_invoice_success'] = 'Invoice added successfully.';
                $returnQuery = $_GET;
                unset($returnQuery['entry']);
                header('Location: ?' . http_build_query($returnQuery), true, 303);
                exit;
            } else {
                $error = 'Failed to add invoice.';
            }
        }
    }
    
    // Mark as Paid (Soft delete or status update) - User asked for "Outstanding", so marking paid removes it from list usually.
    // Mark as Paid (Soft delete or status update) - User asked for "Outstanding", so marking paid removes it from list usually.
    if (isset($_POST['mark_paid'])) {
        if (!isFinance()) {
            $error = 'Only Finance users can mark invoices as paid.';
        } else {
            $id = (int)$_POST['id'];
            $stmt = $pdo->prepare("DELETE FROM erp_outstanding_invoices WHERE id = ?");
            if ($stmt->execute([$id])) {
                $success = 'Invoice marked as paid and removed.';
            }
        }
    }

    // Delete Invoice
    if (isset($_POST['delete_invoice'])) {
        if (!isFinance()) {
            $error = 'Only Finance users can delete invoices.';
        } else {
            $id = (int)$_POST['id'];
            // Get attachment to delete file if exists
            $stmt = $pdo->prepare("SELECT attachment FROM erp_outstanding_invoices WHERE id = ?");
            $stmt->execute([$id]);
            $inv = $stmt->fetch();
            
            $stmt = $pdo->prepare("DELETE FROM erp_outstanding_invoices WHERE id = ?");
            if ($stmt->execute([$id])) {
                if ($inv && !empty($inv['attachment']) && file_exists('../../' . $inv['attachment'])) {
                    @unlink('../../' . $inv['attachment']);
                }
                $success = 'Invoice deleted successfully.';
            }
        }
    }
}

// Fetch Data
// Default tab: Receivables
$activeTab = (($_GET['tab'] ?? 'receivables') === 'payables') ? 'payables' : 'receivables';
$entryMode = (($_GET['entry'] ?? '') === 'new');
$typeFilter = ($activeTab === 'payables') ? 'payable' : 'receivable';

$invoiceTotals = ['receivable' => 0.0, 'payable' => 0.0];
$invoices = [];
foreach (outstandingInvoiceRows($pdo) as $row) {
    if (isset($invoiceTotals[$row['type']])) {
        $invoiceTotals[$row['type']] += (float) $row['amount'];
    }
    if ($row['type'] === $typeFilter) $invoices[] = $row;
}

function oi_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function oi_due_date_form(array $invoice): void
{
    $currentDate = validOutstandingDueDate((string) ($invoice['due_date'] ?? '')) ? (string) $invoice['due_date'] : '';
    ?>
    <details class="oi-due-editor"><summary class="oi-row-action-item"><?= $currentDate === '' ? 'Set due date' : 'Change due date' ?></summary>
    <form method="post" class="oi-due-form">
        <input type="hidden" name="csrf_token" value="<?= oi_h(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int) $invoice['id'] ?>">
        <input type="hidden" name="set_payable_due_date" value="1">
        <input type="date" name="due_date" value="<?= oi_h($currentDate) ?>" aria-label="Payable due date" required>
        <button type="submit">Save date</button>
    </form>
    </details>
    <?php
}

/**
 * @return array{date:string,label:string,state:string}
 */
function oi_due_date_info($value): array
{
    $rawDate = trim((string) $value);
    $dueDate = DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate);
    if (!$dueDate || $dueDate->format('Y-m-d') !== $rawDate) {
        return ['date' => '-', 'label' => 'Due date not set', 'state' => 'missing'];
    }

    $days = (int) (new DateTimeImmutable('today'))->diff($dueDate)->format('%r%a');
    if ($days < 0) {
        $overdueDays = abs($days);
        $label = 'Overdue by ' . $overdueDays . ' ' . ($overdueDays === 1 ? 'day' : 'days');
        $state = 'overdue';
    } elseif ($days === 0) {
        $label = 'Due today';
        $state = 'today';
    } else {
        $label = $days . ' ' . ($days === 1 ? 'day' : 'days') . ' left';
        $state = 'upcoming';
    }

    return ['date' => $dueDate->format('d/m/Y'), 'label' => $label, 'state' => $state];
}

/**
 * @param array<string, scalar|null> $extra
 */
function oi_url(array $extra = []): string
{
    $query = $_GET;
    unset($query['company_slug']);
    foreach ($extra as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    return '?' . http_build_query($query);
}

function oi_voucher_attachment_url(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    $relativePath = ltrim($path, '/');
    if (!str_starts_with($relativePath, 'assets/')
        && !str_starts_with($relativePath, 'storage/')
        && !str_starts_with($relativePath, 'uploads/')) {
        $relativePath = 'assets/uploads/vouchers/' . $relativePath;
    }
    $proxyUrl = function_exists('app_url') ? app_url('/proxy_pdf.php') : '../../proxy_pdf.php';
    return $proxyUrl . '?file=' . urlencode($relativePath);
}

$search = trim((string) ($_GET['q'] ?? ''));
if (strlen($search) > 80) {
    $search = substr($search, 0, 80);
}
$sortKey = strtolower(trim((string) ($_GET['sort'] ?? 'date')));
$sortDir = strtolower(trim((string) ($_GET['dir'] ?? 'desc')));
if (!in_array($sortKey, ['date', 'name', 'number', 'amount'], true)) {
    $sortKey = 'date';
}
if (!in_array($sortDir, ['asc', 'desc'], true)) {
    $sortDir = 'desc';
}

$displayRows = $invoices;
if ($search !== '') {
    $needle = function_exists('mb_strtolower') ? mb_strtolower($search) : strtolower($search);
    $displayRows = array_values(array_filter($displayRows, static function (array $row) use ($needle): bool {
        $hay = implode(' ', [
            (string) ($row['entity_name'] ?? ''),
            (string) ($row['invoice_number'] ?? ''),
            (string) ($row['narration'] ?? ''),
        ]);
        $hay = function_exists('mb_strtolower') ? mb_strtolower($hay) : strtolower($hay);
        return str_contains($hay, $needle);
    }));
}
if ($sortKey !== 'date' || $sortDir !== 'desc') {
    $mult = $sortDir === 'asc' ? 1 : -1;
    usort($displayRows, static function (array $a, array $b) use ($sortKey, $mult): int {
        switch ($sortKey) {
            case 'name':
                $cmp = strcasecmp((string) ($a['entity_name'] ?? ''), (string) ($b['entity_name'] ?? ''));
                break;
            case 'number':
                $cmp = strnatcasecmp((string) ($a['invoice_number'] ?? ''), (string) ($b['invoice_number'] ?? ''));
                break;
            case 'amount':
                $cmp = ((float) ($a['amount'] ?? 0)) <=> ((float) ($b['amount'] ?? 0));
                break;
            default:
                $cmp = strcmp((string) ($a['invoice_date'] ?? ''), (string) ($b['invoice_date'] ?? ''));
                break;
        }
        if ($cmp === 0) {
            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        }
        return $cmp * $mult;
    });
}

$pageRows = $displayRows;
$rangeFrom = $pageRows === [] ? 0 : 1;

$sourceVoucherData = [];
$sourceVoucherByNumber = [];
if ($activeTab === 'payables' && function_exists('tableExists') && tableExists('payment_vouchers', $pdo)) {
    $sourceVoucherIds = [];
    $sourceVoucherNumbers = [];
    foreach ($pageRows as $row) {
        $sourceVoucherId = (int) ($row['source_payment_voucher_id'] ?? 0);
        if ($sourceVoucherId > 0) {
            $sourceVoucherIds[$sourceVoucherId] = $sourceVoucherId;
        }
        $sourceVoucherNumber = trim((string) ($row['invoice_number'] ?? ''));
        if ($sourceVoucherNumber !== '') {
            $sourceVoucherNumbers[$sourceVoucherNumber] = $sourceVoucherNumber;
        }
    }

    $lookupConditions = [];
    $lookupParams = [];
    if ($sourceVoucherIds !== []) {
        $placeholders = implode(',', array_fill(0, count($sourceVoucherIds), '?'));
        $lookupConditions[] = "id IN ($placeholders)";
        array_push($lookupParams, ...array_values($sourceVoucherIds));
    }
    if ($sourceVoucherNumbers !== []) {
        $placeholders = implode(',', array_fill(0, count($sourceVoucherNumbers), '?'));
        $lookupConditions[] = "voucher_no IN ($placeholders)";
        array_push($lookupParams, ...array_values($sourceVoucherNumbers));
    }
    if ($lookupConditions !== []) {
        $voucherStmt = $pdo->prepare('SELECT id, voucher_no, swift_document FROM payment_vouchers WHERE ' . implode(' OR ', $lookupConditions));
        $voucherStmt->execute($lookupParams);
        foreach ($voucherStmt->fetchAll(PDO::FETCH_ASSOC) as $voucher) {
            $sourceVoucherId = (int) $voucher['id'];
            $voucherNumber = trim((string) ($voucher['voucher_no'] ?? ''));
            $voucherUrl = function_exists('company_url')
                ? company_url('employee/view-voucher.php')
                : (function_exists('app_url') ? app_url('/employee/view-voucher.php') : '../../employee/view-voucher.php');
            $sourceVoucherData[$sourceVoucherId] = [
                'voucher_no' => $voucherNumber,
                'voucher_url' => $voucherUrl . '?' . http_build_query(['id' => $sourceVoucherId, 'module' => 'voucher']) . '#attachments',
                'attachments' => [],
            ];
            if ($voucherNumber !== '') {
                $sourceVoucherByNumber[$voucherNumber] = $sourceVoucherId;
            }
            $swiftDocument = trim((string) ($voucher['swift_document'] ?? ''));
            if ($swiftDocument !== '') {
                $sourceVoucherData[$sourceVoucherId]['attachments'][] = [
                    'name' => 'Payment proof',
                    'url' => oi_voucher_attachment_url($swiftDocument),
                    'title' => 'Payment proof',
                ];
            }
        }

        $attachmentVoucherIds = array_keys($sourceVoucherData);
        if ($attachmentVoucherIds !== [] && tableExists('voucher_attachments', $pdo)) {
            $placeholders = implode(',', array_fill(0, count($attachmentVoucherIds), '?'));
            $attachmentStmt = $pdo->prepare("SELECT voucher_id, file_path, original_name FROM voucher_attachments WHERE voucher_id IN ($placeholders) ORDER BY id");
            $attachmentStmt->execute($attachmentVoucherIds);
            foreach ($attachmentStmt->fetchAll(PDO::FETCH_ASSOC) as $attachment) {
                $sourceVoucherId = (int) $attachment['voucher_id'];
                $filePath = (string) ($attachment['file_path'] ?? '');
                $fileName = trim((string) ($attachment['original_name'] ?? ''));
                if ($fileName === '') {
                    $fileName = basename(str_replace('\\', '/', $filePath));
                }
                $sourceVoucherData[$sourceVoucherId]['attachments'][] = [
                    'name' => $fileName,
                    'url' => oi_voucher_attachment_url($filePath),
                    'title' => $fileName,
                ];
            }
        }
    }
}

function oi_sort_url(string $key, string $currentKey, string $currentDir): string
{
    $next = 'desc';
    if ($currentKey === $key) {
        $next = $currentDir === 'desc' ? 'asc' : 'desc';
    } elseif ($key === 'name' || $key === 'number') {
        $next = 'asc';
    }
    return oi_url(['sort' => $key, 'dir' => $next, 'p' => null]);
}

$isFinanceUser = isFinance();
$entityLabel = $activeTab === 'receivables' ? 'Customer' : 'Supplier';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Outstanding Invoices - <?= COMPANY_NAME ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        body { background: #f3f4f6; }
        .main-content { max-width: 1200px; margin: 0 auto; padding: 20px; }
        html body .main-content.oi-page { max-width: 1400px !important; margin: 0 auto !important; padding: 0.35rem 22px 2.4rem !important; background: transparent; }
        @media (max-width: 780px) {
            html body .main-content.oi-page { padding-left: 16px !important; padding-right: 16px !important; }
        }
        .oi-table a.badge-att { background: #e0f2fe !important; color: #0369a1 !important; font-weight: 600; opacity: 1; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .page-title { font-size: 1.5rem; font-weight: bold; color: #111; margin: 0; align-self: flex-start; transform: translateY(-2px); }
        .page-header-right { position: relative; display: flex; align-items: center; gap: 10px; margin-left: auto; }
        body.page-outstanding-invoices .employee-header--outstanding { z-index: 12000 !important; overflow: visible !important; }
        body.page-outstanding-invoices .employee-header--outstanding .header-content { overflow: visible !important; }
        body.page-outstanding-invoices .employee-header--outstanding .page-header-right { z-index: 12001 !important; overflow: visible !important; }
        .new-invoice-btn {
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 10px 16px;
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 6px 14px rgba(37, 99, 235, 0.2);
        }
        body.page-outstanding-invoices .employee-header--outstanding .new-invoice-btn {
            height: 32px;
            min-height: 32px;
            padding: 0 10px;
            border-radius: 10px !important;
            font-size: 0.78rem;
        }
        .new-invoice-btn:hover { background: #1d4ed8; }
        .oi-new-type-picker { position: absolute; top: calc(100% + 8px); right: 0; z-index: 12002; display: grid; width: min(250px, calc(100vw - 24px)); gap: 6px; padding: 8px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.16); }
        .oi-new-type-picker[hidden] { display: none; }
        .oi-new-type-link { display: flex; align-items: center; gap: 10px; min-height: 48px; padding: 7px 9px; border: 1px solid #e8edf3; border-radius: 8px; background: #fff; color: #0f172a; text-decoration: none; }
        .oi-new-type-link:hover { border-color: #bfdbfe; background: #f8fafc; }
        .oi-new-type-icon { display: inline-flex; width: 32px; height: 32px; flex: 0 0 32px; align-items: center; justify-content: center; border-radius: 8px; background: #eff6ff; color: #2563eb; }
        .oi-new-type-icon--payables { background: #ecfdf5; color: #059669; }
        .oi-new-type-icon svg { width: 18px; height: 18px; }
        .oi-new-type-copy { display: grid; gap: 2px; min-width: 0; }
        .oi-new-type-copy strong { font-size: 0.8rem; font-weight: 700; }
        .oi-new-type-copy small { color: #64748b; font-size: 0.68rem; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-new-type-picker,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-new-type-link { background: #111b21; border-color: #2a3942; color: #e9edef; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-new-type-icon { background: #17345b; color: #bfdbfe; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-new-type-icon--payables { background: #123b32; color: #a7f3d0; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-new-type-link:hover { background: #202c33; border-color: #40515b; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-new-type-copy small { color: #b0bec5; }
        .oi-header-search { width: 100%; max-width: none; min-width: 0; margin: 0; position: relative; }
        .oi-header-search--desktop { width: 100%; }
        body.page-outstanding-invoices .employee-header--outstanding .header-content { display: grid !important; grid-template-columns: minmax(0, 1fr) minmax(16rem, 26rem) minmax(0, 1fr); column-gap: 1rem; align-items: center; }
        body.page-outstanding-invoices .employee-header--outstanding .header-left { display: none !important; }
        body.page-outstanding-invoices .employee-header--outstanding .employee-header-page-heading--with-center { grid-column: 1; min-width: 0 !important; margin: 0 !important; }
        body.page-outstanding-invoices .employee-header--outstanding .employee-header-center-slot { grid-column: 2; width: 100%; max-width: none; padding: 0 !important; transform: translateY(18px); }
        body.page-outstanding-invoices .employee-header--outstanding .header-right { grid-column: 3; justify-self: end; margin: 0 !important; }
        .oi-header-search-field { position: relative; display: flex; align-items: center; width: 100%; height: 2.25rem; border: 1px solid #e2e8f0; border-radius: 9999px; background: #fff; overflow: hidden; }
        .oi-header-search-field:focus-within { border-color: #a5b4fc; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12); }
        .oi-header-search-icon { position: absolute; left: 0.9rem; width: 1rem; height: 1rem; color: #94a3b8; pointer-events: none; }
        .oi-header-search-input { width: 100%; min-width: 0; height: 100%; padding: 0 5.2rem 0 2.5rem; border: 0 !important; border-radius: 9999px !important; outline: 0 !important; box-shadow: none !important; background: transparent !important; color: #0f172a; font: inherit; font-size: 0.8125rem; }
        .oi-header-search-input::-webkit-search-cancel-button { display: none; }
        .oi-header-search-clear { position: absolute; right: 2.6rem; width: 1.8rem; height: 1.8rem; display: inline-flex; align-items: center; justify-content: center; border-radius: 50%; color: #64748b; text-decoration: none; }
        .oi-header-search-clear:hover { background: #f1f5f9; color: #334155; }
        .oi-header-search-submit { position: absolute; right: 0.2rem; width: 2rem; height: 2rem; display: inline-flex; align-items: center; justify-content: center; border: 0; border-radius: 50%; background: transparent; color: #475569; cursor: pointer; }
        .oi-header-search-submit:hover { background: transparent; color: #0f172a; }
        .oi-header-search-submit svg { width: 1rem; height: 1rem; }
        .oi-header-search--mobile { display: none; }
        .oi-account-switcher { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; width: min(40rem, 100%); margin: 41px auto 53px; }
        .oi-account-card { display: flex; min-width: 0; min-height: 60px; align-items: center; gap: 10px; padding: 8px 12px; border: 1px solid #e8edf3; border-radius: 10px; background: #fff; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); color: #0f172a; text-decoration: none; transition: border-color 0.15s ease, box-shadow 0.15s ease; }
        .oi-account-card:hover { border-color: var(--oi-account-accent); box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08); }
        .oi-account-card--receivables { --oi-account-accent: #087cff; }
        .oi-account-card--payables { --oi-account-accent: #00b67a; }
        .oi-account-card.is-active { border-color: var(--oi-account-accent); box-shadow: 0 0 0 1px var(--oi-account-accent); }
        .oi-account-icon { display: inline-flex; width: 32px; height: 32px; flex: 0 0 32px; align-items: center; justify-content: center; border-radius: 9px; background: #eff6ff; color: #2563eb; }
        .oi-account-card--payables .oi-account-icon { background: #ecfdf5; color: #059669; }
        .oi-account-icon svg { width: 17px; height: 17px; }
        .oi-account-copy { display: grid; min-width: 0; gap: 3px; }
        .oi-account-copy strong { color: #334155; font-size: 0.74rem; font-weight: 650; }
        .oi-account-copy small { color: #64748b; font-size: 0.68rem; }
        .oi-account-total { margin-left: auto; color: #0f172a; font-size: 0.88rem; font-weight: 750; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .oi-account-chevron { width: 16px; height: 16px; flex: 0 0 16px; color: #94a3b8; }
        .oi-header-search--mobile { display: none; }
        .oi-header-breadcrumb { display: inline-flex; flex-wrap: wrap; align-items: center; gap: 0.3rem; margin: 0; color: #64748b; font-size: 0.76rem; line-height: 1.25; }
        .oi-header-breadcrumb a { color: #2563eb; text-decoration: none; }
        .oi-header-breadcrumb a:hover { text-decoration: underline; }
        .oi-header-breadcrumb .oi-breadcrumb-separator { color: #94a3b8; }
        .oi-header-breadcrumb .oi-breadcrumb-current { color: #64748b; font-weight: 600; }
        @media (max-width: 1023.98px) {
            body.page-outstanding-invoices .employee-header--outstanding .header-content { display: flex !important; }
            body.page-outstanding-invoices .employee-header--outstanding .employee-header-center-slot { display: none !important; }
            .oi-header-search--mobile { display: block; width: 100%; max-width: none; min-width: 0; margin: 0.75rem 0 0.625rem; }
        }
        @media (max-width: 767.98px) {
            body.page-outstanding-invoices .employee-header--outstanding .header-content { display: grid !important; grid-template-columns: 2.5rem minmax(0, 1fr) auto; column-gap: 0.5rem; row-gap: 0.2rem; align-items: start !important; padding: 8px 12px !important; }
            body.page-outstanding-invoices .employee-header--outstanding .header-left { display: flex !important; grid-column: 1; grid-row: 1; margin: 0 !important; padding: 0 !important; align-self: start; }
            body.page-outstanding-invoices .employee-header--outstanding .employee-header-page-heading--with-center { grid-column: 2; grid-row: 1; min-width: 0 !important; margin: 0 !important; padding: 0 !important; }
            body.page-outstanding-invoices .employee-header--outstanding .employee-header-page-title { font-size: 16px !important; line-height: 1.15 !important; white-space: normal; }
            body.page-outstanding-invoices .employee-header--outstanding .employee-header-page-subtitle { margin: 4px 0 0 !important; font-size: 11px !important; line-height: 1.2 !important; }
            body.page-outstanding-invoices .employee-header--outstanding .header-right { grid-column: 3; grid-row: 1; margin: 0 !important; align-self: start !important; }
        }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-header-breadcrumb a { color: #7ab8ff; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-header-breadcrumb .oi-breadcrumb-current { color: #b0bec5; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-account-card { background: #111b21; border-color: #2a3942; color: #e9edef; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-account-card.is-active { border-color: var(--oi-account-accent); }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-account-copy strong,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-account-total { color: #e9edef; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-account-copy small { color: #8696a0; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-account-icon { background: rgba(37, 99, 235, 0.2); color: #93c5fd; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-account-card--payables .oi-account-icon { background: rgba(5, 150, 105, 0.2); color: #6ee7b7; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-account-chevron { color: #8696a0; }
        @media (max-width: 620px) {
            .oi-account-switcher { grid-template-columns: repeat(2, minmax(0, 1fr)); width: 100%; gap: 8px; margin: 12px auto 16px; }
            .oi-account-card {
                position: relative;
                display: grid;
                grid-template-columns: 42px minmax(0, 1fr) 14px;
                grid-template-rows: minmax(44px, 1fr) auto;
                align-items: start;
                min-height: 96px;
                gap: 6px 7px;
                padding: 8px;
            }
            .oi-account-icon {
                grid-column: 1;
                grid-row: 1;
                align-self: center;
                width: 42px;
                height: 42px;
                border-radius: 12px;
            }
            .oi-account-icon svg { width: 23px; height: 23px; }
            .oi-account-copy {
                grid-column: 2;
                grid-row: 1;
                align-self: center;
                padding-left: 7px;
                border-left: 1px solid #e8edf3;
            }
            .oi-account-copy strong { font-size: 0.7rem; line-height: 1.2; }
            .oi-account-copy small { font-size: 0.62rem; line-height: 1.2; }
            .oi-account-total {
                grid-column: 1 / -1;
                grid-row: 2;
                margin: 0;
                justify-self: end;
                text-align: right;
                font-size: clamp(0.62rem, 2.5vw, 0.78rem);
                white-space: nowrap;
            }
            .oi-account-chevron { grid-column: 3; grid-row: 1; align-self: center; justify-self: end; width: 14px; }
            html[data-theme="dark"] body.page-outstanding-invoices .oi-account-copy { border-left-color: #2a3942; }
        }
        
        .card { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 20px; margin-bottom: 20px; }
        
        .oi-form-card { background: #fff; border: 1px solid #e7edf3; border-radius: 16px; box-shadow: 0 8px 22px rgba(15, 23, 42, 0.04); margin-bottom: 20px; }
        .oi-form-head { display: flex; align-items: center; gap: 0.75rem; padding: 1rem 1.15rem 0.85rem; border-bottom: 1px solid #eef2f6; }
        .oi-form-back { margin-left: auto; color: #475569; font-size: 0.82rem; font-weight: 700; text-decoration: none; white-space: nowrap; }
        .oi-form-back:hover { color: #1d4ed8; }
        .oi-form-head h2 { margin: 0; font-size: 1.05rem; font-weight: 760; color: #0f172a; }
        .oi-form-head p { margin: 0.1rem 0 0; color: #64748b; font-size: 0.82rem; }
        .oi-form-mark { width: 38px; height: 38px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex: 0 0 auto; }
        .oi-form-mark svg { width: 20px; height: 20px; }
        .oi-form-mark--in { background: #e8f8ee; color: #16a34a; }
        .oi-form-mark--out { background: #fdecec; color: #dc2626; }
        .oi-form { padding: 1rem 1.15rem 1.1rem; }
        .oi-form-grid { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 0.9rem 1rem; }
        .oi-span-3 { grid-column: span 3; }
        .oi-span-4 { grid-column: span 4; }
        .oi-span-5 { grid-column: span 5; }
        .oi-span-8 { grid-column: span 8; }
        .oi-span-12 { grid-column: span 12; }
        .oi-field { display: flex; flex-direction: column; gap: 0.35rem; min-width: 0; }
        .oi-field > label { font-size: 0.76rem; font-weight: 700; color: #475569; letter-spacing: 0.01em; }
        .oi-field > label em { color: #dc2626; font-style: normal; }
        .oi-field input[type="text"], .oi-field input[type="date"], .oi-field input[type="number"] { width: 100%; height: 2.6rem; border: 1px solid #d7dee7; border-radius: 12px !important; background: #fff; color: #0f172a; padding: 0 0.85rem; font-size: 0.9rem; font-weight: 550; transition: border-color 0.15s, box-shadow 0.15s; }
        .oi-field input::placeholder { color: #94a3b8; font-weight: 500; }
        .oi-field input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15); }
        .oi-money { display: flex; align-items: stretch; border: 1px solid #d7dee7; border-radius: 12px; overflow: hidden; background: #fff; transition: border-color 0.15s, box-shadow 0.15s; }
        .oi-money:focus-within { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15); }
        .oi-money span { display: flex; align-items: center; padding: 0 0.75rem; background: #f1f5f9; color: #475569; font-size: 0.78rem; font-weight: 800; border-right: 1px solid #d7dee7; }
        .oi-field .oi-money input[type="number"] { border: 0; border-radius: 0 !important; box-shadow: none; text-align: right; font-variant-numeric: tabular-nums; font-weight: 700; }
        .oi-file { position: relative; display: flex; align-items: center; gap: 0.75rem; min-height: 2.9rem; padding: 0.35rem 0.45rem; border: 1px dashed #cbd5e1; border-radius: 12px; background: #f8fafc; cursor: pointer; transition: border-color 0.15s, background 0.15s; }
        .oi-file:hover { border-color: #2563eb; background: #f1f6ff; }
        .oi-file input[type="file"] { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
        .oi-file-btn { display: inline-flex; align-items: center; gap: 0.4rem; height: 2.1rem; padding: 0 0.8rem; border-radius: 10px; background: #fff; border: 1px solid #d7dee7; color: #0f172a; font-size: 0.82rem; font-weight: 700; white-space: nowrap; }
        .oi-file-btn svg { width: 15px; height: 15px; }
        .oi-file-name { color: #64748b; font-size: 0.84rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
        .oi-file.has-file .oi-file-name { color: #0f172a; font-weight: 650; }
        .oi-form-foot { display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1rem; padding-top: 0.9rem; border-top: 1px solid #eef2f6; }
        .oi-btn-primary, .oi-btn-ghost { height: 2.5rem; padding: 0 1.1rem; border-radius: 12px; font-size: 0.88rem; font-weight: 700; cursor: pointer; }
        .oi-btn-primary { background: #2563eb; color: #fff; border: 1px solid #2563eb; box-shadow: 0 6px 14px rgba(37, 99, 235, 0.2); }
        .oi-btn-primary:hover { background: #1d4ed8; border-color: #1d4ed8; }
        .oi-btn-ghost { background: #fff; color: #334155; border: 1px solid #d7dee7; }
        .oi-btn-ghost:hover { background: #f8fafc; }
        html[data-theme="dark"] .oi-form-card { background: #1e293b; border-color: #334155; }
        html[data-theme="dark"] .oi-form-head, html[data-theme="dark"] .oi-form-foot { border-color: #334155; }
        html[data-theme="dark"] .oi-form-head h2 { color: #f8fafc; }
        html[data-theme="dark"] .oi-form-back { color: #cbd5e1; }
        html[data-theme="dark"] .oi-field > label { color: #cbd5e1; }
        html[data-theme="dark"] .oi-field input[type="text"],
        html[data-theme="dark"] .oi-field input[type="date"],
        html[data-theme="dark"] .oi-field input[type="number"],
        html[data-theme="dark"] .oi-money,
        html[data-theme="dark"] .oi-file-btn,
        html[data-theme="dark"] .oi-btn-ghost { background: #0f172a; color: #f8fafc; border-color: #334155; }
        html[data-theme="dark"] .oi-money span { background: #1e293b; color: #cbd5e1; border-color: #334155; }
        html[data-theme="dark"] .oi-file { background: #0f172a; border-color: #475569; }
        html[data-theme="dark"] .oi-file.has-file .oi-file-name { color: #f8fafc; }
        @media (max-width: 900px) {
            .oi-span-3, .oi-span-4, .oi-span-5 { grid-column: span 6; }
            .oi-span-8 { grid-column: span 12; }
        }
        @media (max-width: 560px) {
            .oi-span-3, .oi-span-4, .oi-span-5, .oi-span-8 { grid-column: span 12; }
            .oi-form-foot { flex-direction: column-reverse; }
            .oi-btn-primary, .oi-btn-ghost { width: 100%; }
        }
        
        .oi-rank-card { background: transparent; border: 0; border-radius: 0; box-shadow: none; margin-bottom: 20px; overflow: visible; }
        .oi-rank-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 0.75rem; padding: 0 0 0.65rem; }
        .oi-rank-head h2 { margin: 0; font-size: 1.05rem; font-weight: 760; color: #0f172a; }
        .oi-rank-head p { margin: 0.15rem 0 0; color: #64748b; font-size: 0.82rem; }
        .oi-search { display: flex; align-items: center; gap: 0.35rem; flex-wrap: wrap; }
        .oi-search input { height: 2.25rem; border: 1px solid #e2e8f0; border-radius: 999px !important; padding: 0 0.95rem; min-width: 290px; font-weight: 550; }
        .oi-rank-card .oi-search input[type="search"] { border-radius: 999px !important; -webkit-appearance: none; appearance: none; background-clip: padding-box; }
        .oi-rank-card .oi-search input[type="search"]:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15); }
        .oi-search button, .oi-clear { height: 2.25rem; border-radius: 999px; border: 1px solid #e2e8f0; background: #fff; color: #334155; font-weight: 700; padding: 0 0.75rem; text-decoration: none; display: inline-flex; align-items: center; cursor: pointer; }
        .oi-mobile-invoice-list { display: none; }
        .oi-mobile-invoice-card {
            display: flex;
            flex-direction: column;
            gap: 0.55rem;
            width: 100%;
            padding: 0.7rem 0.8rem;
            border: 1px solid #e2e8f0;
            border-radius: 15px !important;
            background: #fff;
            color: #0f172a;
            text-align: left;
            font: inherit;
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
        }
        .oi-mobile-invoice-card:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }
        .oi-mobile-invoice-card-top { display: flex; align-items: center; justify-content: space-between; gap: 0.65rem; min-width: 0; }
        .oi-mobile-invoice-party { display: flex; align-items: center; gap: 0.55rem; min-width: 0; }
        .oi-mobile-invoice-avatar {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 2rem;
            width: 2rem;
            height: 2rem;
            border-radius: 50%;
            background: #312e81;
            color: #e0e7ff;
            font-size: 0.65rem;
            font-weight: 700;
        }
        .oi-mobile-invoice-identity { display: flex; flex-direction: column; min-width: 0; gap: 0.12rem; }
        .oi-mobile-invoice-number,
        .oi-mobile-invoice-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .oi-mobile-invoice-number { color: #0f172a; font-size: 0.76rem; font-weight: 800; }
        .oi-mobile-invoice-name { color: #64748b; font-size: 0.66rem; font-weight: 600; }
        .oi-mobile-invoice-aside { display: flex; flex: 0 0 auto; flex-direction: column; align-items: flex-end; gap: 0.2rem; }
        .oi-mobile-invoice-amount { color: #0f172a; font-size: 0.69rem; font-weight: 800; white-space: nowrap; }
        .oi-mobile-invoice-status { padding: 0.12rem 0.42rem; border-radius: 999px; font-size: 0.58rem; font-weight: 700; white-space: nowrap; }
        .oi-mobile-invoice-details { display: flex; flex-wrap: wrap; align-items: center; gap: 0.55rem; min-width: 0; padding-top: 0.5rem; border-top: 1px solid #e2e8f0; color: #64748b; }
        .oi-mobile-invoice-fact { display: inline-flex; align-items: center; gap: 0.3rem; min-width: 0; font-size: 0.61rem; }
        .oi-mobile-invoice-fact svg { width: 0.8rem; height: 0.8rem; flex: 0 0 auto; }
        .oi-mobile-invoice-date { flex: 0 0 auto; }
        .oi-mobile-invoice-due { flex: 0 0 100%; }
        .oi-mobile-invoice-divider { width: 1px; height: 0.8rem; flex: 0 0 1px; background: #cbd5e1; }
        .oi-mobile-invoice-description {
            display: flex;
            flex: 1 1 0;
            width: 0;
            min-width: 0;
            overflow: hidden;
        }
        .oi-mobile-invoice-description-text {
            display: block;
            width: 100%;
            max-width: 18ch;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .oi-mobile-invoice-chevron { width: 0.9rem; height: 0.9rem; flex: 0 0 auto; margin-left: auto; color: #94a3b8; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-mobile-invoice-card { background: #111b21; border-color: #2a3942; color: #e9edef; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-mobile-invoice-number,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-mobile-invoice-amount { color: #e9edef !important; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-mobile-invoice-name,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-mobile-invoice-details { color: #b0bec5 !important; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-mobile-invoice-details { border-color: #2a3942; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-mobile-invoice-divider { background: #475569; }
        .oi-table-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 14px; background: #fff; }
        .oi-table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 820px; font-size: 0.78rem; }
        .oi-table thead th { background: #f8fafc; color: #0f172a; font-size: 0.75rem; font-weight: 600; text-align: left; padding: 0.55rem 0.45rem; white-space: nowrap; border: 0; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        .oi-table th:first-child, .oi-table td:first-child { padding-left: 0.75rem; }
        .oi-table th:last-child, .oi-table td:last-child { padding-right: 0.75rem; }
        .oi-table thead th a { color: inherit; text-decoration: none; }
        .oi-table thead th a:hover { color: #f8fafc; }
        .oi-table tbody td { padding: 0.55rem 0.45rem; border-bottom: 1px solid #f1f5f9; font-size: 0.78rem; vertical-align: middle; text-align: left; }
        .oi-table tbody tr:hover td { background: #ecfdf5; }
        .oi-col-sn { width: 52px; color: #64748b; font-weight: 700; }
        .oi-table .oi-num, .oi-table thead th.oi-num { text-align: right; }
        .oi-table .oi-actions, .oi-table thead th.oi-actions { text-align: right; width: 1%; white-space: nowrap; }
        .oi-table a.oi-attachment-link { display: inline-flex; align-items: center; gap: 0.35rem; max-width: 13rem; padding: 0.3rem 0.45rem; border: 1px solid #e2e8f0 !important; border-radius: 8px; background: #f8fafc !important; color: #1d4ed8 !important; font-size: 0.75rem; font-weight: 650; text-decoration: none; vertical-align: middle; }
        .oi-table a.oi-attachment-link:hover { text-decoration: underline; }
        .oi-table a.oi-attachment-link svg { width: 0.9rem; height: 0.9rem; flex: 0 0 auto; }
        .oi-table a.oi-attachment-link span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .oi-pv-attachments { display: grid; justify-items: start; gap: 0.3rem; }
        .oi-status-badge { display: inline-flex; align-items: center; padding: 0.22rem 0.55rem; border-radius: 999px; background: #ecfdf5; color: #047857; font-size: 0.72rem; font-weight: 700; white-space: nowrap; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-status-badge { background: #123026; color: #b7f7d8; }
        .oi-row-actions { position: relative; display: inline-flex; }
        .oi-row-actions-trigger { width: 2rem; height: 2rem; display: inline-flex; align-items: center; justify-content: center; padding: 0; border: 1px solid transparent; border-radius: 8px; background: transparent; color: #64748b; cursor: pointer; list-style: none; }
        .oi-row-actions-trigger::-webkit-details-marker { display: none; }
        .oi-row-actions-trigger:hover, .oi-row-actions[open] .oi-row-actions-trigger { border-color: #e2e8f0; background: #f8fafc; color: #0f172a; }
        .oi-row-actions-trigger svg { width: 18px; height: 18px; }
        .oi-row-actions-menu { position: absolute; top: calc(100% + 0.3rem); right: 0; z-index: 50; display: grid; min-width: 9rem; gap: 0.15rem; padding: 0.3rem; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.14); }
        .oi-row-action-item { display: flex; width: 100%; align-items: center; padding: 0.55rem 0.65rem; border: 0; border-radius: 5px; background: transparent; color: #334155; font: inherit; font-size: 0.82rem; font-weight: 650; text-align: left; text-decoration: none; cursor: pointer; }
        .oi-row-action-item:hover { background: #f1f5f9; color: #0f172a; }
        .oi-row-action-item--delete { color: #b91c1c; }
        .oi-row-action-item--delete:hover { background: #fef2f2; color: #991b1b; }
        .oi-invoice-row { cursor: pointer; }
        .oi-invoice-row:focus-visible td { outline: 2px solid #2563eb; outline-offset: -2px; }
        .oi-details-overlay { position: fixed; inset: 0; z-index: 10000; display: none; align-items: center; justify-content: center; padding: 1rem; background: rgba(15, 23, 42, 0.45); }
        .oi-details-modal { width: min(32rem, calc(100vw - 1.5rem)); max-height: calc(100vh - 2rem); overflow: hidden; display: flex; flex-direction: column; border: 1px solid #e2e8f0; border-radius: 16px; background: #fff; box-shadow: 0 20px 50px rgba(15, 23, 42, 0.2); }
        .oi-details-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem; padding: 1rem 1.15rem 0.85rem; border-bottom: 1px solid #e2e8f0; }
        .oi-details-head-main { min-width: 0; }
        .oi-details-head h2 { margin: 0; color: #0f172a; font-size: 1.1rem; font-weight: 800; line-height: 1.25; }
        .oi-details-head p { margin: 0.2rem 0 0; color: #0f172a; font-size: 0.875rem; font-weight: 600; }
        .oi-details-close { width: 2rem; height: 2rem; flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center; border: 0; border-radius: 8px; background: transparent; color: #64748b; cursor: pointer; }
        .oi-details-close:hover { background: #f1f5f9; color: #0f172a; }
        .oi-details-close svg { width: 1rem; height: 1rem; }
        .oi-details-meta { display: flex; align-items: center; gap: 0.65rem; flex-wrap: wrap; margin-top: 0.55rem; color: #0f172a; font-size: 0.8125rem; }
        .oi-details-type { padding: 0.25rem 0.55rem; border-radius: 999px; background: #e8f8ee; color: #15803d; font-size: 0.75rem; font-weight: 700; }
        .oi-details-type--payable { background: #fdecec; color: #b91c1c; }
        .oi-details-body { padding: 1rem 1.15rem; display: grid; gap: 0.9rem; overflow-y: auto; }
        .oi-details-list { display: grid; gap: 0.5rem; margin: 0; }
        .oi-details-row { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; font-size: 0.875rem; }
        .oi-details-row dt { margin: 0; color: #0f172a; font-weight: 600; flex: 0 0 auto; }
        .oi-details-row dd { min-width: 0; margin: 0; color: #0f172a; font-weight: 600; text-align: right; overflow-wrap: anywhere; }
        .oi-details-row dd.oi-details-amount { color: #16a34a; font-weight: 700; font-variant-numeric: tabular-nums; }
        .oi-details-attachments { margin-top: 0.85rem; padding-top: 0.85rem; border-top: 1px solid #e2e8f0; }
        .oi-details-attachments[hidden] { display: none; }
        .oi-details-attachments-label { margin-bottom: 0.4rem; color: #64748b; font-size: 0.68rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; }
        .oi-details-attachment-list { display: grid; justify-items: start; gap: 0.4rem; }
        .oi-details-attachment-link { display: inline-flex; align-items: center; gap: 0.4rem; max-width: 100%; padding: 0.4rem 0.55rem; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; color: #1d4ed8; font-size: 0.8rem; font-weight: 600; line-height: 1.25; text-decoration: none; }
        .oi-details-attachment-link:hover { background: #eff6ff; border-color: #bfdbfe; color: #1e40af; }
        .oi-details-attachment-link svg { width: 0.95rem; height: 0.95rem; }
        .oi-details-attachment-link span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .oi-name { color: #0f172a; font-weight: 700; }
        .oi-desc { color: #475569; max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .oi-due-date { display: flex; flex-direction: column; gap: 0.12rem; white-space: nowrap; }
        .oi-due-status { color: #64748b; font-size: 0.72rem; }
        .oi-due-status--overdue { color: #b91c1c; font-weight: 700; }
        .oi-due-status--upcoming { color: #047857; font-weight: 650; }
        .oi-due-status--today { color: #b45309; font-weight: 700; }
        .oi-due-status--missing { color: #9ca3af; }
        .oi-due-form { display: flex; flex-wrap: wrap; gap: 0.3rem; padding: 0 0.65rem 0.4rem; }
        .oi-due-form input, .oi-due-form button { font: inherit; max-width: 100%; }
        .oi-due-editor summary { list-style: none; }
        .oi-due-editor summary::-webkit-details-marker { display: none; }
        .oi-amt { font-variant-numeric: tabular-nums; font-weight: 650; }
        .oi-muted { color: #9ca3af; font-size: 0.8rem; }
        .oi-empty { padding: 2rem 1rem; text-align: center; color: #475569; border-top: 1px dashed #cbd5e1; }
        .oi-empty strong { display: block; color: #0f172a; font-size: 1.02rem; margin-bottom: 0.3rem; }
        .oi-pager { display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem 0.95rem; color: #64748b; font-size: 0.82rem; flex-wrap: wrap; }
        .oi-total { color: #059669; font-weight: 750; font-size: 0.95rem; }
        .oi-pages { display: flex; gap: 0.3rem; flex-wrap: wrap; }
        .oi-pages a, .oi-pages span { min-width: 2rem; height: 2rem; padding: 0 0.45rem; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; color: #334155; border: 1px solid #e2e8f0; background: #fff; font-weight: 700; }
        .oi-pages span { background: #1e293b; color: #fff; border-color: #1e293b; }

        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 0.75rem; font-weight: 500; }
        .badge-att { background: #e0f2fe; color: #0369a1; text-decoration: none; }

        html[data-theme="dark"] .oi-table-wrap,
        html[data-theme="dark"] .oi-search input,
        html[data-theme="dark"] .oi-search button,
        html[data-theme="dark"] .oi-clear,
        html[data-theme="dark"] .oi-pages a { background: #111b21; color: #e9edef; border-color: #2a3942; }
        html[data-theme="dark"] .oi-rank-head h2,
        html[data-theme="dark"] .oi-name,
        html[data-theme="dark"] .oi-empty strong { color: #f8fafc; }
        html[data-theme="dark"] .oi-table thead th { background: #111b21; color: #8696a0; border-color: #2a3942; }
        html[data-theme="dark"] .oi-table tbody td,
        html[data-theme="dark"] .oi-table tbody tr:nth-child(even) td { background: #111b21; color: #e9edef; border-color: #2a3942; }
        html[data-theme="dark"] .oi-table tbody tr:hover td { background: #202c33; }
        html[data-theme="dark"] .oi-desc { color: #cbd5e1; }
        @media (max-width: 780px) {
            .oi-rank-head { flex-direction: column; }
            .oi-search, .oi-search input { width: 100%; }
            .oi-search input { min-width: 0; flex: 1; }
            .oi-pager { flex-direction: column; align-items: flex-start; }
        }
        @media (max-width: 767.98px) {
            .oi-mobile-invoice-list { display: grid; gap: 0.55rem; }
            .oi-mobile-invoice-list > .oi-row-actions { justify-self: end; }
            .oi-table-wrap { display: none; }
        }
        
        .oi-btn-primary, .oi-btn-ghost, .oi-file-btn { border-radius: 12px !important; }
        .oi-search button { border-radius: 999px !important; }
        .btn-pay { border-radius: 8px !important; font-weight: 600; }
        .oi-actions .icon-btn { border-radius: 8px !important; display: inline-flex; align-items: center; justify-content: center; }
        .btn-cancel, .btn-delete { border-radius: 10px !important; }
        .btn-pay { background: transparent; border: 1px solid #10b981; color: #10b981; padding: 4px 10px; cursor: pointer; font-size: 0.8rem; }
        .btn-pay:hover { background: #10b981; color: #fff; }
        html[data-theme="dark"] body.page-outstanding-invoices,
        html[data-theme="dark"] body.page-outstanding-invoices .layout-main-wrapper,
        html[data-theme="dark"] body.page-outstanding-invoices .layout-main-wrapper > .flex-grow-1,
        html[data-theme="dark"] body.page-outstanding-invoices .main-content.oi-page {
            background: #0b141a !important;
            background-color: #0b141a !important;
            color: #e9edef !important;
        }
        html[data-theme="dark"] body.page-outstanding-invoices .employee-header.employee-header--page-context {
            background: #0b141a !important;
            background-color: #0b141a !important;
            border: 0 !important;
            box-shadow: none !important;
        }
        html[data-theme="dark"] body.page-outstanding-invoices .employee-header-page-title { color: #e9edef !important; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-header-search-field { background: #111b21; border-color: #2a3942; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-header-search-input { color: #e9edef; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-header-search-input::placeholder { color: #8696a0; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-header-search-icon { color: #8696a0; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-header-search-clear { color: #b0bec5; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-header-search-clear:hover { background: #26343c; color: #e9edef; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-header-search-submit,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-header-search-submit:hover { background: transparent; color: #e9edef; }
        html[data-theme="dark"] body.page-outstanding-invoices .tabs { border-bottom-color: #2a3942; }
        html[data-theme="dark"] body.page-outstanding-invoices .tab-link { color: #b0bec5; }
        html[data-theme="dark"] body.page-outstanding-invoices .tab-link.active { color: #00a884; border-bottom-color: #00a884; }
        html[data-theme="dark"] body.page-outstanding-invoices .tab-link:hover { color: #e9edef; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-form-card,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-table-wrap { background: #111b21; border-color: #2a3942; box-shadow: none; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-row-actions-trigger { color: #b0bec5; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-row-actions-trigger:hover,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-row-actions[open] .oi-row-actions-trigger { background: #202c33; border-color: #2a3942; color: #e9edef; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-row-actions-menu { background: #111b21; border-color: #2a3942; box-shadow: 0 8px 20px rgba(0, 0, 0, 0.35); }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-row-action-item { color: #e9edef; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-row-action-item:hover { background: #202c33; color: #fff; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-row-action-item--delete { color: #fca5a5; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-row-action-item--delete:hover { background: #3b1b20; color: #fecaca; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-modal { background: #1e293b; border-color: #334155; box-shadow: 0 20px 50px rgba(0, 0, 0, 0.45); }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-head { border-color: #2a3942; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-head h2,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-head p,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-meta,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-row dt,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-row dd { color: #f8fafc; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-row dd.oi-details-amount { color: #4ade80; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-head p,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-meta,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-attachments-label { color: #b0bec5; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-close { background: #111b21; border-color: #2a3942; color: #b0bec5; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-close:hover { background: #202c33; color: #e9edef; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-attachments { border-color: #334155; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-type { background: #123026; color: #b7f7d8; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-type--payable { background: #3b1b20; color: #fecaca; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-attachment-link,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-table a.oi-attachment-link { background: #0b141a !important; border-color: #2a3942 !important; color: #93c5fd !important; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-details-attachment-link:hover,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-table a.oi-attachment-link:hover { background: #202c33 !important; border-color: #334155 !important; color: #bfdbfe !important; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-form-head,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-form-foot { border-color: #2a3942; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-form-head h2,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-rank-head h2,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-empty strong,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-name { color: #e9edef; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-form-head p,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-rank-head p,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-form-back,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-pager,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-empty,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-muted,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-col-sn,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-desc,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-file-name { color: #b0bec5; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-field > label { color: #d3dde2; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-field input[type="text"],
        html[data-theme="dark"] body.page-outstanding-invoices .oi-field input[type="date"],
        html[data-theme="dark"] body.page-outstanding-invoices .oi-field input[type="number"],
        html[data-theme="dark"] body.page-outstanding-invoices .oi-money,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-file,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-file-btn,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-btn-ghost,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-search input,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-search button,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-clear,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-pages a {
            background: #0b141a;
            color: #e9edef;
            border-color: #2a3942;
        }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-field input::placeholder,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-search input::placeholder { color: #8696a0; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-money span { background: #111b21; color: #b0bec5; border-color: #2a3942; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-file:hover { background: #14222a; border-color: #00a884; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-table thead th {
            background: #111b21 !important;
            background-color: #111b21 !important;
            color: #8696a0 !important;
            border-bottom-color: #2a3942 !important;
            box-shadow: inset 0 -1px 0 #2a3942 !important;
        }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-table tbody td,
        html[data-theme="dark"] body.page-outstanding-invoices .oi-table tbody tr:nth-child(even) td {
            background: #111b21 !important;
            background-color: #111b21 !important;
            color: #e9edef !important;
            border-bottom-color: #2a3942 !important;
        }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-table tbody tr:hover td {
            background: #202c33 !important;
            background-color: #202c33 !important;
        }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-due-status { color: #cbd5e1 !important; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-due-status--overdue { color: #fca5a5 !important; font-weight: 700; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-due-status--upcoming { color: #86efac !important; font-weight: 650; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-due-status--today { color: #fcd34d !important; font-weight: 700; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-due-status--missing { color: #94a3b8 !important; font-weight: 400; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-total { color: #34d399; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-success-notice { background: #123026 !important; color: #b7f7d8 !important; }
        html[data-theme="dark"] body.page-outstanding-invoices .oi-error-notice { background: #3b1b20 !important; color: #fecaca !important; }
    </style>
</head>
<body class="page-outstanding-invoices">
    <?php
    $hideHeaderCompanyBranding = true;
    $employeeHeaderTitle = 'Outstanding Invoices';
    $employeeHeaderExtraClass = 'employee-header--outstanding';
    $outstandingHomeUrl = function_exists('company_url')
        ? company_url('select-module')
        : (function_exists('app_url') ? app_url('/select-module.php') : '../../select-module.php');
    $outstandingInvoicesUrl = function_exists('company_url')
        ? company_url('erp/outstanding-invoices/index.php') . '?module=outstanding'
        : (function_exists('app_url') ? app_url('/erp/outstanding-invoices/index.php?module=outstanding') : 'index.php?module=outstanding');
    $employeeHeaderSubtitle = '<nav class="oi-header-breadcrumb" aria-label="Breadcrumb">'
        . '<a href="' . oi_h($outstandingHomeUrl) . '">Home</a>'
        . '<span class="oi-breadcrumb-separator">/</span>'
        . '<a href="' . oi_h($outstandingInvoicesUrl) . '">Outstanding Invoices</a>'
        . '<span class="oi-breadcrumb-separator">/</span>'
        . '<span class="oi-breadcrumb-current">' . ($activeTab === 'receivables' ? 'Receivables' : 'Payables') . '</span>'
        . '</nav>';
    $searchHiddenInputs = '';
    foreach ($_GET as $queryKey => $queryValue) {
        if (in_array((string) $queryKey, ['q', 'p', 'company_slug'], true) || is_array($queryValue)) {
            continue;
        }
        $searchHiddenInputs .= '<input type="hidden" name="' . oi_h((string) $queryKey) . '" value="' . oi_h((string) $queryValue) . '">';
    }
    $searchFieldMarkup = '<div class="oi-header-search-field">'
        . '<svg class="oi-header-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg>'
        . '<input class="oi-header-search-input" type="search" name="q" value="' . oi_h($search) . '" placeholder="Search ' . strtolower($entityLabel) . ', invoice, description" maxlength="80" aria-label="Search invoices">'
        . ($search !== '' ? '<a class="oi-header-search-clear" href="' . oi_h(oi_url(['q' => null, 'p' => null])) . '" aria-label="Clear search" title="Clear search"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m18 6-12 12M6 6l12 12"></path></svg></a>' : '')
        . '<button class="oi-header-search-submit" type="submit" aria-label="Search invoices" title="Search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg></button>'
        . '</div>';
    $employeeHeaderCenterHtml = !$entryMode
        ? '<form class="oi-header-search oi-header-search--desktop" method="get" action="">' . $searchHiddenInputs . $searchFieldMarkup . '</form>'
        : '';
    $mobileSearchMarkup = !$entryMode
        ? '<form class="oi-header-search oi-header-search--mobile" method="get" action="">' . $searchHiddenInputs . $searchFieldMarkup . '</form>'
        : '';
    $employeeHeaderRightHtml = '
        <div class="page-header-right">
            <button type="button" class="new-invoice-btn" aria-haspopup="true" aria-expanded="false" aria-controls="new-outstanding-invoice-panel">New</button>
            <section class="oi-new-type-picker" id="new-outstanding-invoice-panel" aria-label="Choose invoice type" hidden>
                <a href="' . oi_h(oi_url(['tab' => 'receivables', 'entry' => 'new', 'q' => null, 'p' => null, 'sort' => null, 'dir' => null])) . '" class="oi-new-type-link">
                    <span class="oi-new-type-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3.75h12a1 1 0 0 1 1 1v15.5l-3-1.8-4 1.8-4-1.8-3 1.8V4.75a1 1 0 0 1 1-1Z"/><path d="M9 8h6M9 12h6M9 16h3"/></svg></span>
                    <span class="oi-new-type-copy"><strong>Receivables</strong><small>Customer invoice</small></span>
                </a>
                <a href="' . oi_h(oi_url(['tab' => 'payables', 'entry' => 'new', 'q' => null, 'p' => null, 'sort' => null, 'dir' => null])) . '" class="oi-new-type-link">
                    <span class="oi-new-type-icon oi-new-type-icon--payables" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="14" rx="2"/><path d="M7 7V5a2 2 0 0 1 2-2h9v4M3 11h18"/><path d="M16 14h2"/></svg></span>
                    <span class="oi-new-type-copy"><strong>Payables</strong><small>Supplier bill</small></span>
                </a>
            </section>
        </div>
    ';
    include '../../includes/header_employee.php';
    ?>
    
    <div class="main-content oi-page">
        <?php if ($success): ?><div class="oi-success-notice" style="background:#dcfce7; color:#166534; padding:10px; border-radius:6px; margin-bottom:15px;"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="oi-error-notice" style="background:#fee2e2; color:#991b1b; padding:10px; border-radius:6px; margin-bottom:15px;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <?php if (!$entryMode): ?>
        <?= $mobileSearchMarkup ?>
        <nav class="oi-account-switcher" aria-label="Outstanding invoice type">
            <a href="<?= oi_h(oi_url(['tab' => 'receivables', 'q' => null, 'p' => null, 'sort' => null, 'dir' => null])) ?>" class="oi-account-card oi-account-card--receivables <?= $activeTab === 'receivables' ? 'is-active' : '' ?>" <?= $activeTab === 'receivables' ? 'aria-current="page"' : '' ?>>
                <span class="oi-account-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3.75h12a1 1 0 0 1 1 1v15.5l-3-1.8-4 1.8-4-1.8-3 1.8V4.75a1 1 0 0 1 1-1Z"/><path d="M9 8h6M9 12h6M9 16h3"/></svg></span>
                <span class="oi-account-copy"><strong>Receivables</strong><small>Customer Invoices</small></span>
                <span class="oi-account-total">TZS <?= number_format($invoiceTotals['receivable'], 2) ?></span>
                <svg class="oi-account-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
            </a>
            <a href="<?= oi_h(oi_url(['tab' => 'payables', 'q' => null, 'p' => null, 'sort' => null, 'dir' => null])) ?>" class="oi-account-card oi-account-card--payables <?= $activeTab === 'payables' ? 'is-active' : '' ?>" <?= $activeTab === 'payables' ? 'aria-current="page"' : '' ?>>
                <span class="oi-account-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="14" rx="2"/><path d="M7 7V5a2 2 0 0 1 2-2h9v4M3 11h18"/><path d="M16 14h2"/></svg></span>
                <span class="oi-account-copy"><strong>Payables</strong><small>Supplier Bills</small></span>
                <span class="oi-account-total">TZS <?= number_format($invoiceTotals['payable'], 2) ?></span>
                <svg class="oi-account-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
            </a>
        </nav>

        <?php endif; ?>

        <?php if ($entryMode): ?>
        <section class="oi-form-card">
            <div class="oi-form-head">
                <span class="oi-form-mark oi-form-mark--<?= $activeTab === 'receivables' ? 'in' : 'out' ?>" aria-hidden="true">
                    <?php if ($activeTab === 'receivables'): ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M19 12l-7 7-7-7"/></svg>
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5"/><path d="M5 12l7-7 7 7"/></svg>
                    <?php endif; ?>
                </span>
                <div>
                    <h2>Add <?= $activeTab === 'receivables' ? 'receivable' : 'payable' ?></h2>
                    <p><?= $activeTab === 'receivables' ? 'Record money a customer still owes you.' : 'Record money you still owe a supplier.' ?></p>
                </div>
                <a class="oi-form-back" href="<?= oi_h(oi_url(['entry' => null, 'q' => null, 'p' => null, 'sort' => null, 'dir' => null])) ?>">Back to outstanding invoices</a>
            </div>
            <form method="POST" enctype="multipart/form-data" class="oi-form" id="oiAddForm">
                <input type="hidden" name="create_invoice" value="1">
                <input type="hidden" name="type" value="<?= $typeFilter ?>">
                <div class="oi-form-grid">
                    <div class="oi-field oi-span-3">
                        <label for="oiDate">Date <em>*</em></label>
                        <input type="date" id="oiDate" name="invoice_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="oi-field oi-span-5">
                        <label for="oiEntity"><?= $entityLabel ?> name <em>*</em></label>
                        <input type="text" id="oiEntity" name="entity_name" placeholder="e.g. John Doe / ABC Corp" required autocomplete="off">
                    </div>
                    <div class="oi-field oi-span-4">
                        <label for="oiNumber">Invoice number</label>
                        <input type="text" id="oiNumber" name="invoice_number" placeholder="e.g. INV-001" autocomplete="off">
                    </div>
                    <div class="oi-field oi-span-8">
                        <label for="oiNarration">Description</label>
                        <input type="text" id="oiNarration" name="narration" placeholder="Details of goods or service">
                    </div>
                    <div class="oi-field oi-span-4">
                        <label for="oiAmount">Amount <em>*</em></label>
                        <div class="oi-money">
                            <span>TZS</span>
                            <input type="number" id="oiAmount" step="0.01" min="0.01" name="amount" placeholder="0.00" required>
                    </div>
                    </div>
                    <div class="oi-field oi-span-12">
                        <label for="oiFile">Attachment (invoice)</label>
                        <label class="oi-file" for="oiFile">
                            <input type="file" id="oiFile" name="attachment" accept=".pdf,.jpg,.jpeg,.png">
                            <span class="oi-file-btn">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.4 11.1l-9.2 9.2a6 6 0 0 1-8.5-8.5l9.2-9.2a4 4 0 0 1 5.7 5.7l-9.2 9.2a2 2 0 0 1-2.8-2.8l8.5-8.5"/></svg>
                                Choose file
                            </span>
                            <span class="oi-file-name" data-empty="PDF, JPG or PNG (optional)">PDF, JPG or PNG (optional)</span>
                        </label>
                    </div>
                </div>
                <div class="oi-form-foot">
                    <button type="reset" class="oi-btn-ghost">Clear</button>
                    <button type="submit" class="oi-btn-primary">+ Add invoice</button>
                </div>
            </form>
        </section>
        <?php endif; ?>

        <!-- List -->
        <?php if (!$entryMode): ?>
        <section class="oi-rank-card">
            <?php if ($pageRows === []): ?>
                <div class="oi-empty">
                    <strong><?= $search !== '' ? 'No invoices match that search.' : 'No outstanding invoices found.' ?></strong>
                </div>
            <?php else: ?>
            <div class="oi-mobile-invoice-list" aria-label="<?= $activeTab === 'payables' ? 'Payable invoices' : 'Receivable invoices' ?>">
                <?php foreach ($pageRows as $inv): ?>
                    <?php
                        $mobileEntityName = trim((string) ($inv['entity_name'] ?? ''));
                        $mobileNameParts = preg_split('/\s+/', $mobileEntityName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                        $mobileInitials = count($mobileNameParts) > 1
                            ? strtoupper(substr($mobileNameParts[0], 0, 1) . substr($mobileNameParts[1], 0, 1))
                            : strtoupper(substr($mobileEntityName, 0, 3));
                        $mobileInvoiceNumber = trim((string) ($inv['invoice_number'] ?? ''));
                        $mobileInvoiceDate = date('d/m/Y', strtotime($inv['invoice_date']));
                        $mobileInvoiceDescription = trim((string) ($inv['narration'] ?? ''));
                        $mobileInvoiceType = $activeTab === 'payables' ? 'Payable' : 'Receivable';
                        $mobileInvoiceDetails = $mobileInvoiceType . ' / ' . ($mobileInvoiceDescription !== '' ? $mobileInvoiceDescription : 'Invoice');
                        $mobileDueDate = oi_due_date_info($inv['due_date'] ?? null);
                    ?>
                    <button
                        type="button"
                        class="oi-mobile-invoice-card"
                        aria-label="View invoice details for <?= oi_h($mobileEntityName) ?>"
                        data-invoice-id="<?= (int) $inv['id'] ?>"
                        data-invoice-type="<?= $mobileInvoiceType ?>"
                        data-invoice-entity="<?= oi_h($mobileEntityName) ?>"
                        data-invoice-number="<?= oi_h($mobileInvoiceNumber) ?>"
                        data-invoice-date="<?= oi_h($mobileInvoiceDate) ?>"
                        data-invoice-description="<?= oi_h((string) ($inv['narration'] ?? '')) ?>"
                        data-invoice-attachments="[]"
                        data-invoice-amount="<?= oi_h(number_format((float) $inv['amount'], 2)) ?>">
                        <span class="oi-mobile-invoice-card-top">
                            <span class="oi-mobile-invoice-party">
                                <span class="oi-mobile-invoice-avatar" aria-hidden="true"><?= oi_h($mobileInitials) ?></span>
                                <span class="oi-mobile-invoice-identity">
                                    <span class="oi-mobile-invoice-number"><?= oi_h($mobileInvoiceNumber !== '' ? $mobileInvoiceNumber : $mobileEntityName) ?></span>
                                    <span class="oi-mobile-invoice-name"><?= oi_h($mobileEntityName) ?></span>
                                </span>
                            </span>
                            <span class="oi-mobile-invoice-aside">
                                <span class="oi-mobile-invoice-amount">TZS <?= number_format((float) $inv['amount'], 2) ?></span>
                                <span class="oi-mobile-invoice-status oi-status-badge"><?= !empty($inv['partially_paid']) ? 'Partially paid' : 'Outstanding' ?></span>
                            </span>
                            <svg class="oi-mobile-invoice-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"></path></svg>
                        </span>
                        <span class="oi-mobile-invoice-details">
                            <span class="oi-mobile-invoice-fact oi-mobile-invoice-date">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3 11h18"></path></svg>
                                <?= oi_h($mobileInvoiceDate) ?>
                            </span>
                            <span class="oi-mobile-invoice-divider" aria-hidden="true"></span>
                            <span class="oi-mobile-invoice-fact oi-mobile-invoice-description">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="M7 9h10M7 15h5"></path></svg>
                                <span class="oi-mobile-invoice-description-text" title="<?= oi_h($mobileInvoiceDetails) ?>"><?= oi_h($mobileInvoiceDetails) ?></span>
                            </span>
                            <?php if ($mobileDueDate !== null): ?>
                            <span class="oi-mobile-invoice-fact oi-mobile-invoice-due">
                                <span class="oi-due-status oi-due-status--<?= oi_h($mobileDueDate['state']) ?>"><?= oi_h($mobileDueDate['state'] === 'missing' ? $mobileDueDate['label'] : 'Due ' . $mobileDueDate['date'] . ' · ' . $mobileDueDate['label']) ?></span>
                            </span>
                            <?php endif; ?>
                        </span>
                    </button>
                    <?php if ($activeTab === 'payables' && $isFinanceUser): ?>
                        <details class="oi-row-actions">
                            <summary class="oi-row-actions-trigger" aria-label="Payable actions" title="Payable actions">
                                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.8"></circle><circle cx="12" cy="12" r="1.8"></circle><circle cx="12" cy="19" r="1.8"></circle></svg>
                            </summary>
                            <div class="oi-row-actions-menu"><?php oi_due_date_form($inv); ?></div>
                        </details>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <div class="oi-table-wrap">
            <table class="oi-table">
                <thead>
                    <tr>
                        <th class="oi-col-sn">No.</th>
                        <?php
                        $heads = [
                            'date' => ['Date', ''],
                            'name' => [$entityLabel, ''],
                            'number' => [$activeTab === 'payables' ? 'Payment voucher no' : 'Invoice no', ''],
                        ];
                        foreach ($heads as $key => $meta):
                        ?>
                            <th class="<?= oi_h($meta[1]) ?>"><a href="<?= oi_h(oi_sort_url($key, $sortKey, $sortDir)) ?>"><?= oi_h($meta[0]) ?><?php if ($sortKey === $key): ?> <?= $sortDir === 'asc' ? '&#8593;' : '&#8595;' ?><?php endif; ?></a></th>
                        <?php endforeach; ?>
                        <th>Due date</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th class="oi-num"><a href="<?= oi_h(oi_sort_url('amount', $sortKey, $sortDir)) ?>">Amount<?php if ($sortKey === 'amount'): ?> <?= $sortDir === 'asc' ? '&#8593;' : '&#8595;' ?><?php endif; ?></a></th>
                        <?php if ($isFinanceUser): ?><th class="oi-actions">Action</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                        <?php foreach ($pageRows as $rowIndex => $inv): ?>
                        <?php
                            $attachmentPath = str_replace('\\', '/', (string) ($inv['attachment'] ?? ''));
                            $attachmentName = $attachmentPath !== '' ? basename($attachmentPath) : '';
                            $attachmentExtension = strtoupper((string) pathinfo($attachmentName, PATHINFO_EXTENSION));
                            $attachmentLabel = $activeTab === 'receivables' ? 'Customer invoice' : 'Supplier invoice';
                            $sourceVoucherId = (int) ($inv['source_payment_voucher_id'] ?? 0);
                            if ($sourceVoucherId <= 0) {
                                $sourceVoucherId = $sourceVoucherByNumber[trim((string) ($inv['invoice_number'] ?? ''))] ?? 0;
                            }
                            $sourceVoucher = $sourceVoucherData[$sourceVoucherId] ?? null;
                            $detailAttachments = [];
                            if ($activeTab === 'payables' && $sourceVoucher !== null) {
                                $detailAttachments[] = [
                                    'label' => 'Payment voucher',
                                    'name' => $sourceVoucher['voucher_no'],
                                    'url' => $sourceVoucher['voucher_url'],
                                    'title' => 'Open payment voucher ' . $sourceVoucher['voucher_no'],
                                ];
                                foreach ($sourceVoucher['attachments'] as $voucherAttachment) {
                                    $detailAttachments[] = [
                                        'label' => 'Payment voucher attachment',
                                        'name' => $voucherAttachment['name'],
                                        'url' => $voucherAttachment['url'],
                                        'title' => $voucherAttachment['title'],
                                    ];
                                }
                            } elseif ($attachmentPath !== '') {
                                $detailAttachments[] = [
                                    'label' => $attachmentLabel,
                                    'name' => $attachmentName,
                                    'url' => function_exists('app_url') ? app_url('/' . ltrim($attachmentPath, '/')) : '/' . ltrim($attachmentPath, '/'),
                                    'title' => $attachmentLabel . ($attachmentExtension !== '' ? ' · ' . $attachmentExtension : '') . ': ' . $attachmentName,
                                ];
                            }
                            $detailAttachmentsJson = json_encode($detailAttachments, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: '[]';
                        ?>
                        <tr class="oi-invoice-row" tabindex="0" aria-label="View invoice details for <?= oi_h((string) $inv['entity_name']) ?>"
                            data-invoice-id="<?= (int) $inv['id'] ?>"
                            data-invoice-type="<?= $activeTab === 'receivables' ? 'Receivable' : 'Payable' ?>"
                            data-invoice-entity="<?= oi_h((string) $inv['entity_name']) ?>"
                            data-invoice-number="<?= oi_h((string) ($inv['invoice_number'] ?? '')) ?>"
                            data-invoice-date="<?= oi_h(date('d/m/Y', strtotime($inv['invoice_date']))) ?>"
                            data-invoice-description="<?= oi_h((string) ($inv['narration'] ?? '')) ?>"
                            data-invoice-attachments="<?= oi_h($detailAttachmentsJson) ?>"
                            data-invoice-amount="<?= oi_h(number_format((float) $inv['amount'], 2)) ?>">
                            <td class="oi-col-sn"><?= $rangeFrom + $rowIndex ?></td>
                            <td><?= date('d/m/Y', strtotime($inv['invoice_date'])) ?></td>
                            <td><span class="oi-name"><?= htmlspecialchars($inv['entity_name']) ?></span></td>
                            <td><?= htmlspecialchars(($inv['invoice_number'] ?? '') !== '' ? $inv['invoice_number'] : '-') ?></td>
                            <?php $dueDateInfo = oi_due_date_info($inv['due_date'] ?? null); ?>
                            <td><?= oi_h($dueDateInfo['date']) ?></td>
                            <td><div class="oi-desc" title="<?= htmlspecialchars((string) $inv['narration']) ?>"><?= htmlspecialchars((string) $inv['narration']) !== '' ? htmlspecialchars((string) $inv['narration']) : '<span class="oi-muted">-</span>' ?></div></td>
                            <td><span class="oi-due-status oi-due-status--<?= oi_h($dueDateInfo['state']) ?>"><?= oi_h($dueDateInfo['label']) ?></span><?php if (!empty($inv['partially_paid'])): ?><br><span class="oi-status-badge">Partially paid</span><?php endif; ?></td>
                            <td class="oi-num"><span class="oi-amt"><?= number_format($inv['amount'], 2) ?></span></td>
                            <?php if ($isFinanceUser): ?>
                            <td class="oi-actions">
                                <details class="oi-row-actions">
                                    <summary class="oi-row-actions-trigger" aria-label="Row actions" title="Row actions">
                                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.8"></circle><circle cx="12" cy="12" r="1.8"></circle><circle cx="12" cy="19" r="1.8"></circle></svg>
                                    </summary>
                                    <div class="oi-row-actions-menu">
                                    <?php if ($activeTab === 'payables') oi_due_date_form($inv); ?>
                                    <form method="POST" onsubmit="return confirm('Mark this invoice as Paid?');">
                                        <input type="hidden" name="mark_paid" value="1">
                                        <input type="hidden" name="id" value="<?= $inv['id'] ?>">
                                        <button type="submit" class="oi-row-action-item">Mark Paid</button>
                                    </form>
                                    <button type="button" class="oi-row-action-item oi-row-action-item--delete" onclick="confirmDelete(<?= $inv['id'] ?>)">Delete</button>
                                    </div>
                                </details>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>

        </section>
        <?php endif; ?>
    </div>
    <div id="oiInvoiceDetailsModal" class="oi-details-overlay" role="presentation" style="display:none;">
        <section class="oi-details-modal" role="dialog" aria-modal="true" aria-labelledby="oiInvoiceDetailsTitle" aria-describedby="oiInvoiceDetailsEntity">
            <header class="oi-details-head">
                <div class="oi-details-head-main">
                    <h2 id="oiInvoiceDetailsTitle">Invoice details</h2>
                    <p id="oiInvoiceDetailsEntity"></p>
                    <div class="oi-details-meta">
                        <span class="oi-details-type" id="oiInvoiceDetailsType"></span>
                        <span id="oiInvoiceDetailsDate"></span>
                    </div>
                </div>
                <button type="button" class="oi-details-close" id="oiInvoiceDetailsClose" aria-label="Close invoice details">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m18 6-12 12M6 6l12 12"></path></svg>
                </button>
            </header>
            <div class="oi-details-body">
                <dl class="oi-details-list">
                    <div class="oi-details-row"><dt>Invoice number</dt><dd id="oiInvoiceDetailsNumber"></dd></div>
                    <div class="oi-details-row"><dt>Description</dt><dd id="oiInvoiceDetailsDescription"></dd></div>
                    <div class="oi-details-row"><dt>Amount</dt><dd id="oiInvoiceDetailsAmount" class="oi-details-amount"></dd></div>
                </dl>
                <section class="oi-details-attachments" id="oiInvoiceDetailsAttachments" hidden>
                    <div class="oi-details-attachments-label">Attachments</div>
                    <div class="oi-details-attachment-list" id="oiInvoiceDetailsAttachmentList"></div>
                </section>
            </div>
        </section>
    </div>
    <!-- Custom Confirmation Modal -->
    <div id="deleteModal" class="custom-modal-overlay">
        <div class="custom-modal">
            <div class="modal-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                    <line x1="12" y1="9" x2="12" y2="13"></line>
                    <line x1="12" y1="17" x2="12.01" y2="17"></line>
                </svg>
            </div>
            <h3>Delete Invoice?</h3>
            <p>Are you sure you want to permanently delete this invoice? This action cannot be undone.</p>
            <div class="modal-actions">
                <button onclick="closeDeleteModal()" class="btn-cancel">Cancel</button>
                <form method="POST" id="deleteForm" style="display:inline;">
                    <input type="hidden" name="delete_invoice" value="1">
                    <input type="hidden" id="delete_id" name="id" value="">
                    <button type="submit" class="btn-delete">Yes, Delete</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        var activeInvoiceDetailsRow = null;
        var invoiceDetailsBodyOverflow = '';
        function openInvoiceDetails(row, attachmentSource) {
            var modal = document.getElementById('oiInvoiceDetailsModal');
            var invoiceNumber = row.dataset.invoiceNumber || '';
            document.getElementById('oiInvoiceDetailsTitle').textContent = invoiceNumber || 'Invoice details';
            document.getElementById('oiInvoiceDetailsEntity').textContent = row.dataset.invoiceEntity || '-';
            var typeLabel = document.getElementById('oiInvoiceDetailsType');
            typeLabel.textContent = 'Outstanding ' + (row.dataset.invoiceType || 'invoice').toLowerCase();
            typeLabel.classList.toggle('oi-details-type--payable', row.dataset.invoiceType === 'Payable');
            document.getElementById('oiInvoiceDetailsDate').textContent = row.dataset.invoiceDate || '-';
            document.getElementById('oiInvoiceDetailsNumber').textContent = invoiceNumber || '-';
            document.getElementById('oiInvoiceDetailsDescription').textContent = row.dataset.invoiceDescription || '-';
            document.getElementById('oiInvoiceDetailsAmount').textContent = 'TZS ' + (row.dataset.invoiceAmount || '0.00');
            var attachmentSection = document.getElementById('oiInvoiceDetailsAttachments');
            var attachmentList = document.getElementById('oiInvoiceDetailsAttachmentList');
            var attachments = [];
            try {
                attachments = JSON.parse((attachmentSource || row).dataset.invoiceAttachments || '[]');
            } catch (error) {
                attachments = [];
            }
            attachmentList.replaceChildren();
            attachments.forEach(function (item) {
                if (!item || !item.url) return;
                var link = document.createElement('a');
                var label = String(item.label || 'Attachment');
                var name = String(item.name || label);
                link.className = 'oi-details-attachment-link';
                link.href = String(item.url);
                link.target = '_blank';
                link.rel = 'noopener';
                link.title = String(item.title || name);
                link.setAttribute('aria-label', link.title);
                link.textContent = label === 'Payment voucher' || label === 'Customer invoice' || label === 'Supplier invoice'
                    ? label
                    : name;
                attachmentList.appendChild(link);
            });
            attachmentSection.hidden = attachmentList.childElementCount === 0;
            activeInvoiceDetailsRow = row;
            invoiceDetailsBodyOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            modal.style.display = 'flex';
            document.getElementById('oiInvoiceDetailsClose').focus();
        }
        function closeInvoiceDetails() {
            var modal = document.getElementById('oiInvoiceDetailsModal');
            if (!modal || modal.style.display === 'none') return;
            modal.style.display = 'none';
            document.body.style.overflow = invoiceDetailsBodyOverflow;
            if (activeInvoiceDetailsRow) activeInvoiceDetailsRow.focus();
            activeInvoiceDetailsRow = null;
        }
        document.querySelectorAll('.oi-invoice-row').forEach(function (row) {
            row.addEventListener('click', function (event) {
                if (event.target.closest('a, button, input, select, textarea, form, summary, details')) return;
                openInvoiceDetails(row);
            });
            row.addEventListener('keydown', function (event) {
                if (event.target !== row || (event.key !== 'Enter' && event.key !== ' ')) return;
                event.preventDefault();
                openInvoiceDetails(row);
            });
        });
        document.querySelectorAll('.oi-mobile-invoice-card').forEach(function (card) {
            card.addEventListener('click', function () {
                var attachmentSource = Array.from(document.querySelectorAll('.oi-invoice-row')).find(function (row) {
                    return row.dataset.invoiceId === card.dataset.invoiceId;
                });
                openInvoiceDetails(card, attachmentSource || card);
            });
        });
        document.getElementById('oiInvoiceDetailsClose').addEventListener('click', closeInvoiceDetails);
        document.getElementById('oiInvoiceDetailsModal').addEventListener('click', function (event) {
            if (event.target === this) closeInvoiceDetails();
        });

        document.addEventListener('DOMContentLoaded', function () {
            const successNotice = document.querySelector('.oi-success-notice');
            if (successNotice && successNotice.textContent.trim() === 'Invoice deleted successfully.') {
                window.setTimeout(function () {
                    successNotice.remove();
                }, 5000);
            }

            const trigger = document.querySelector('.new-invoice-btn');
            const panel = document.getElementById('new-outstanding-invoice-panel');

            if (!trigger || !panel) {
                return;
            }

            trigger.addEventListener('click', function () {
                const isHidden = panel.hidden;
                panel.hidden = !isHidden;
                trigger.setAttribute('aria-expanded', String(!isHidden));
            });
        });
    </script>

    <style>
        .custom-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(2px);
        }
        .custom-modal {
            background: white;
            padding: 24px;
            border-radius: 12px;
            width: 90%;
            max-width: 400px;
            text-align: center;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            animation: modalPop 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        @keyframes modalPop {
            0% { transform: scale(0.9); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }
        .modal-icon {
            width: 48px;
            height: 48px;
            background: #fee2e2;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }
        .custom-modal h3 {
            margin: 0 0 8px;
            font-size: 1.125rem;
            color: #111827;
        }
        .custom-modal p {
            margin: 0 0 24px;
            color: #6b7280;
            font-size: 0.875rem;
            line-height: 1.5;
        }
        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
        }
        .btn-cancel {
            padding: 8px 16px;
            border: 1px solid #d1d5db;
            background: white;
            color: #374151;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            font-size: 0.875rem;
        }
        .btn-cancel:hover { background: #f3f4f6; }
        .btn-delete {
            padding: 8px 16px;
            border: none;
            background: #dc2626;
            color: white;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            font-size: 0.875rem;
        }
        .btn-delete:hover { background: #b91c1c; }
    </style>

    <script>
        (function () {
            var form = document.getElementById('oiAddForm');
            var input = document.getElementById('oiFile');
            if (!form || !input) return;
            var box = input.closest('.oi-file');
            var nameEl = box ? box.querySelector('.oi-file-name') : null;
            function syncFileName() {
                if (!box || !nameEl) return;
                var file = input.files && input.files[0];
                nameEl.textContent = file ? file.name : nameEl.getAttribute('data-empty');
                box.classList.toggle('has-file', !!file);
            }
            input.addEventListener('change', syncFileName);
            form.addEventListener('reset', function () { window.setTimeout(syncFileName, 0); });
        })();

        function confirmDelete(id) {
            document.querySelectorAll('.oi-row-actions[open]').forEach(function (menu) { menu.open = false; });
            document.getElementById('delete_id').value = id;
            document.getElementById('deleteModal').style.display = 'flex';
        }
        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }
        // Close on clicking outside
        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) closeDeleteModal();
        });
        // Close on Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeInvoiceDetails();
                closeDeleteModal();
                document.querySelectorAll('.oi-row-actions[open]').forEach(function (menu) { menu.open = false; });
            }
        });
        document.addEventListener('click', function (event) {
            var activeMenu = event.target.closest ? event.target.closest('.oi-row-actions') : null;
            document.querySelectorAll('.oi-row-actions[open]').forEach(function (menu) {
                if (menu !== activeMenu) menu.open = false;
            });
        });
    </script>
</body>
</html>
