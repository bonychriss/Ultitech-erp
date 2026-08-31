<?php
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

$purchaseId = (int) ($_GET['purchase_id'] ?? 0);
if ($purchaseId <= 0) {
    redirect('index.php');
}

$queryError = null;
$tableExists = false;
$fkColumn = 'purchase_id';
$tableCols = [];

try {
    $tableExists = (bool) $pdo->query("SHOW TABLES LIKE 'stocks_purchase_attachments'")->fetchColumn();
    if ($tableExists) {
        $tableCols = $pdo->query("SHOW COLUMNS FROM stocks_purchase_attachments")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('purchase_id', $tableCols, true) && in_array('po_id', $tableCols, true)) {
            $fkColumn = 'po_id';
        }
    }
} catch (Throwable $e) {
    $tableExists = false;
}

try {
    $selectCols = ['id', 'file_name', 'file_path'];
    if (in_array('file_type', $tableCols, true)) {
        $selectCols[] = 'file_type';
    } else {
        $selectCols[] = 'NULL AS file_type';
    }
    if (in_array('file_size', $tableCols, true)) {
        $selectCols[] = 'file_size';
    } else {
        $selectCols[] = 'NULL AS file_size';
    }
    if (in_array('created_at', $tableCols, true)) {
        $selectCols[] = 'created_at';
    } else {
        $selectCols[] = 'NULL AS created_at';
    }

    $stmt = $pdo->prepare("SELECT " . implode(', ', $selectCols) . "
                           FROM stocks_purchase_attachments
                           WHERE $fkColumn = ?
                           ORDER BY id ASC");
    $stmt->execute([$purchaseId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $rows = [];
    $queryError = $e->getMessage();
}

if (count($rows) === 1) {
    $path = (string) ($rows[0]['file_path'] ?? '');
    // Stored like: uploads/purchases/XYZ.pdf (relative to /stock/)
    $url = '/stock/' . ltrim($path, '/');
    header('Location: ' . $url);
    exit;
}

$page_title = 'Purchase attachments';
include '../../includes/header.php';
?>

<link href="/stock/assets/css/style.css" rel="stylesheet">
<link href="/assets/css/sales-mobile.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = { corePlugins: { preflight: false } };
</script>
<style>
    .prod-shell {
        font-family: 'Outfit', system-ui, -apple-system, sans-serif;
        font-size: 16px;
        color: #374151;
    }
    .prod-btn-primary {
        background-color: #2563EB !important;
        color: #fff !important;
        border-color: #2563EB !important;
    }
    .prod-btn-primary:hover {
        background-color: #1D4ED8 !important;
        border-color: #1D4ED8 !important;
        color: #fff !important;
    }
    .att-table thead tr.att-table-head th {
        background-color: #1c2331 !important;
        color: #ffffff !important;
        font-weight: 700 !important;
        border-bottom: 2px solid #151a24 !important;
        vertical-align: middle;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.04em;
    }
    .att-table thead tr.att-table-head th:not(:last-child) {
        border-right: 1px solid rgba(255, 255, 255, 0.08);
    }
</style>

<main class="main-content prod-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100">
                <a href="index.php" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-arrow-left text-sm"></i> Purchase orders
                </a>
                <div class="flex items-center gap-2 min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 truncate m-0 inline-flex items-center gap-2">
                        <i class="fas fa-paperclip text-[#2563EB]"></i><span>Attachments</span>
                    </h1>
                </div>
                <div class="flex-1 min-w-[8px]"></div>
                <a href="view_po.php?id=<?php echo (int) $purchaseId; ?>" class="btn prod-btn-primary px-4 py-2 rounded-md text-base font-semibold shadow-sm inline-flex items-center gap-2 border-0 no-underline">
                    <i class="fas fa-file-invoice text-sm"></i> View PO
                </a>
            </div>
            <div class="px-4 py-2 flex flex-wrap items-center gap-2 text-base bg-gray-50/80 border-b border-gray-100">
                <span class="text-gray-600"><i class="fas fa-info-circle text-gray-400 me-1"></i>Select a document to open in a new tab.</span>
            </div>
        </div>

        <div class="px-4 pt-4">
            <?php if (empty($rows)): ?>
                <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-5 text-center text-gray-600">
                    <i class="fas fa-inbox text-4xl text-gray-300 mb-3"></i>
                    <div class="fw-bold text-gray-900 mb-1">No attachments</div>
                    <div class="text-gray-500">This purchase order has no uploaded documents.</div>
                    <?php if (!$tableExists): ?>
                        <div class="mt-3 text-sm text-rose-700 bg-rose-50 border border-rose-200 rounded-lg px-3 py-2 d-inline-block">
                            Attachments table not found: <code>stocks_purchase_attachments</code>
                        </div>
                    <?php elseif ($queryError): ?>
                        <div class="mt-3 text-sm text-rose-700 bg-rose-50 border border-rose-200 rounded-lg px-3 py-2 d-inline-block">
                            Could not load attachments: <?php echo htmlspecialchars($queryError); ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                    <div class="px-4 py-2 border-b border-gray-100 bg-gray-50/50 d-flex flex-wrap align-items-center gap-2">
                        <span class="text-gray-700 fw-semibold"><?php echo count($rows); ?> file<?php echo count($rows) === 1 ? '' : 's'; ?></span>
                        <span class="text-gray-300">|</span>
                        <span class="text-gray-600">Click Open to preview/download.</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 att-table">
                            <thead>
                                <tr class="att-table-head">
                                    <th class="ps-3 py-3" style="width: 55%;">File</th>
                                    <th class="py-3" style="width: 15%;">Type</th>
                                    <th class="py-3 text-end" style="width: 10%;">Size</th>
                                    <th class="py-3" style="width: 15%;">Uploaded</th>
                                    <th class="py-3 text-end pe-3" style="width: 5%;">Open</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <?php
                                    $path = (string) ($r['file_path'] ?? '');
                                    $url = '/stock/' . ltrim($path, '/');
                                    $size = (int) ($r['file_size'] ?? 0);
                                    $sizeKb = $size > 0 ? number_format($size / 1024, 1) . ' KB' : '';
                                    $fileType = strtolower((string) ($r['file_type'] ?? ''));
                                    $fileName = (string) ($r['file_name'] ?? 'Document');
                                    $icon = 'fa-file';
                                    if (str_contains($fileType, 'pdf') || str_ends_with(strtolower($fileName), '.pdf')) $icon = 'fa-file-pdf';
                                    elseif (str_contains($fileType, 'image') || preg_match('/\\.(png|jpe?g|gif|webp)$/i', $fileName)) $icon = 'fa-file-image';
                                    elseif (preg_match('/\\.(xls|xlsx|csv)$/i', $fileName)) $icon = 'fa-file-excel';
                                    elseif (preg_match('/\\.(doc|docx)$/i', $fileName)) $icon = 'fa-file-word';
                                    ?>
                                    <tr class="border-b border-gray-100">
                                        <td class="ps-3 py-3">
                                            <div class="d-flex align-items-start gap-2">
                                                <span class="inline-flex align-items-center justify-content-center rounded border bg-gray-50 text-gray-500 flex-shrink-0" style="width:34px;height:34px;">
                                                    <i class="fas <?php echo $icon; ?>"></i>
                                                </span>
                                                <div class="min-w-0">
                                                    <div class="fw-semibold text-gray-900 text-truncate" style="max-width: 720px;"><?php echo htmlspecialchars($fileName); ?></div>
                                                    <div class="text-xs text-gray-500 text-truncate" style="max-width: 720px;"><?php echo htmlspecialchars($path); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-3 text-gray-700"><?php echo htmlspecialchars((string) ($r['file_type'] ?? '')); ?></td>
                                        <td class="py-3 text-end tabular-nums text-gray-700"><?php echo htmlspecialchars($sizeKb); ?></td>
                                        <td class="py-3 text-gray-600 whitespace-nowrap">
                                            <?php echo !empty($r['created_at']) ? date('M d, Y H:i', strtotime((string) $r['created_at'])) : ''; ?>
                                        </td>
                                        <td class="py-3 text-end pe-3">
                                            <a class="btn btn-sm btn-outline-primary" href="<?php echo htmlspecialchars($url); ?>" target="_blank" rel="noopener">
                                                <i class="fas fa-external-link-alt me-1"></i> Open
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php include '../../includes/footer.php'; ?>

