<?php
require_once '../includes/functions.php';
requireLogin();

$id = (int) ($_GET['id'] ?? 0);
$error = '';
$trip = null;

try {
    $stmt = $pdo->prepare("SELECT dn.*, u.full_name
                           FROM dispatch_notes dn
                           LEFT JOIN users u ON dn.created_by = u.id
                           WHERE dn.id = ? AND dn.type = 'trip'
                           LIMIT 1");
    $stmt->execute([$id]);
    $trip = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Exception $e) {
    $trip = null;
}

if (!$trip) {
    $error = 'Trip record not found.';
}

$page_title = 'Trip details';
$modQ = 'module=dispatch';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($page_title) ?> - <?= htmlspecialchars(COMPANY_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <link href="/stock/assets/css/style.css" rel="stylesheet">
    <link href="/assets/css/style.css?v=<?= time() ?>" rel="stylesheet">
    <link href="/assets/css/sales-mobile.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { corePlugins: { preflight: false } };
    </script>
    <style>
        .mov-shell {
            font-family: 'Outfit', system-ui, -apple-system, sans-serif;
            font-size: 16px;
            color: #374151;
        }
        .mov-btn-primary {
            background-color: #2563EB !important;
            color: #fff !important;
            border-color: #2563EB !important;
        }
        .mov-btn-primary:hover {
            background-color: #1D4ED8 !important;
            border-color: #1D4ED8 !important;
            color: #fff !important;
        }
        .dash-card {
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
            overflow: hidden;
        }
        .sig-preview { max-width: 260px; max-height: 130px; object-fit: contain; }
    </style>
</head>
<body class="dashboard dispatch-page">
<?php
$rootPath = '/';
$logoBase = '/';
$modulesLink = '/select-module.php';
require_once __DIR__ . '/../includes/header_employee.php';
?>

<main class="main-content mov-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto px-0">
        <div class="dispatch-page-sticky bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-3 py-2 sm:px-4 sm:py-3 flex flex-wrap items-center gap-2 sm:gap-3 border-b border-gray-100">
                <div class="flex items-center gap-2 min-w-0 flex-grow sm:flex-grow-0">
                    <h1 class="text-base sm:text-xl font-bold text-gray-900 truncate m-0 inline-flex items-center gap-2 leading-tight">
                        <i class="fas fa-car text-[#2563EB] text-sm sm:text-base"></i><span>Trip details</span>
                    </h1>
                </div>
                <div class="flex-1 min-w-[8px] hidden sm:block"></div>
                <div class="d-flex gap-2 flex-wrap w-100 w-sm-auto">
                    <a href="office_trips.php?<?= htmlspecialchars($modQ) ?>" class="text-sm sm:text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline justify-center">
                        <i class="fas fa-arrow-left text-xs sm:text-sm"></i> Office trips
                    </a>
                    <a href="create_trip.php?<?= htmlspecialchars($modQ) ?>" class="btn mov-btn-primary rounded-md px-3 py-2 fw-semibold border-0 inline-flex items-center gap-2 no-underline justify-center">
                        <i class="fas fa-plus text-xs sm:text-sm"></i> New trip
                    </a>
                </div>
            </div>
            <div class="px-3 py-1.5 sm:px-4 sm:py-2 flex flex-wrap items-center gap-2 text-xs sm:text-base bg-gray-50/80 border-b border-gray-100 leading-snug">
                <span class="text-gray-600"><i class="fas fa-calendar text-gray-400 me-1 text-[0.7rem] sm:text-sm"></i><?= date('l, d M Y') ?></span>
                <span class="text-gray-300 hidden sm:inline">|</span>
                <span class="text-gray-500 sm:text-gray-600">View the full office trip record.</span>
            </div>
        </div>

        <div class="px-4 pt-4 space-y-4">
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger mb-0 rounded-lg border-0 shadow-sm"><?= htmlspecialchars($error) ?></div>
            <?php else: ?>
                <div class="dash-card">
                    <div class="px-4 py-3 border-b border-gray-100 d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2">
                        <div>
                            <div class="fw-bold text-gray-900"><?= htmlspecialchars((string) ($trip['dispatch_number'] ?? 'Trip')) ?></div>
                            <div class="text-sm text-gray-500 mt-1">
                                <?= htmlspecialchars((string) ($trip['dispatch_date'] ?? '')) ?>
                                <span class="text-gray-300">|</span>
                                <?= htmlspecialchars((string) (($trip['dispatch_from'] ?? '-') . ' ? ' . ($trip['dispatch_to'] ?? '-'))) ?>
                            </div>
                        </div>
                        <div class="text-sm text-gray-600">
                            Created by <strong class="text-gray-900"><?= htmlspecialchars((string) ($trip['full_name'] ?? 'User')) ?></strong>
                        </div>
                    </div>

                    <div class="p-4">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="text-xs text-uppercase text-gray-500 fw-semibold">Cost</div>
                                <div class="mt-1 fs-5 fw-bold text-gray-900 tabular-nums">TZS <?= number_format((float) ($trip['route_price'] ?? 0), 2) ?></div>
                            </div>
                            <div class="col-md-8">
                                <div class="text-xs text-uppercase text-gray-500 fw-semibold">Address / destination</div>
                                <div class="mt-1 text-gray-900"><?= htmlspecialchars((string) ($trip['address_to'] ?? '')) ?></div>
                            </div>

                            <div class="col-12">
                                <div class="text-xs text-uppercase text-gray-500 fw-semibold">Notes</div>
                                <div class="mt-2 border border-gray-200 rounded-lg p-3 bg-gray-50 text-gray-900" style="white-space: pre-wrap;">
                                    <?= htmlspecialchars((string) ($trip['contents'] ?? '')) ?>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="text-xs text-uppercase text-gray-500 fw-semibold">Attachment</div>
                                <?php $att = (string) ($trip['attachment_path'] ?? ''); ?>
                                <?php if ($att !== ''): ?>
                                    <div class="mt-2 d-flex align-items-center gap-2">
                                        <i class="far fa-file"></i>
                                        <a class="fw-semibold text-[#2563EB] no-underline" href="<?= htmlspecialchars(app_url($att)) ?>" target="_blank" rel="noopener">Open attachment</a>
                                    </div>
                                <?php else: ?>
                                    <div class="mt-2 text-gray-500">No attachment.</div>
                                <?php endif; ?>
                            </div>

                            <div class="col-md-6">
                                <div class="text-xs text-uppercase text-gray-500 fw-semibold">Signature</div>
                                <?php $sig = (string) ($trip['signature_path'] ?? ''); ?>
                                <?php if ($sig !== ''): ?>
                                    <div class="mt-2 border border-gray-200 rounded-md p-2 bg-gray-50 d-inline-block">
                                        <img src="<?= htmlspecialchars(app_url($sig)) ?>" class="sig-preview" alt="Signature">
                                    </div>
                                <?php else: ?>
                                    <div class="mt-2 text-gray-500">No signature saved.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../stock/includes/footer.php'; ?>
</body>
</html>

