<?php
require_once '../includes/functions.php';
require_once __DIR__ . '/dispatch-helpers.php';
requireLogin();

// Ensure dispatch_notes table exists + required columns
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS dispatch_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        dispatch_number VARCHAR(50) NOT NULL,
        dispatch_date DATE NOT NULL,
        dispatch_from VARCHAR(255) NULL,
        dispatch_to VARCHAR(255) NULL,
        route_price DECIMAL(12,2) NULL,
        address_to VARCHAR(255) NOT NULL,
        contents TEXT NOT NULL,
        created_by INT NOT NULL,
        signature_path VARCHAR(255) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {
    // Ignore
}

try {
    $cols = $pdo->query("SHOW COLUMNS FROM dispatch_notes")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('dispatch_from', $cols, true)) {
        $pdo->exec("ALTER TABLE dispatch_notes ADD COLUMN dispatch_from VARCHAR(255) NULL AFTER dispatch_date");
    }
    if (!in_array('dispatch_to', $cols, true)) {
        $pdo->exec("ALTER TABLE dispatch_notes ADD COLUMN dispatch_to VARCHAR(255) NULL AFTER dispatch_from");
    }
    if (!in_array('route_price', $cols, true)) {
        $pdo->exec("ALTER TABLE dispatch_notes ADD COLUMN route_price DECIMAL(12,2) NULL AFTER dispatch_to");
    }
    if (!in_array('type', $cols, true)) {
        $pdo->exec("ALTER TABLE dispatch_notes ADD COLUMN type ENUM('dispatch', 'trip') NOT NULL DEFAULT 'dispatch' AFTER id");
    }
} catch (Exception $e) {
    // Ignore
}

$error = '';
$success = $_SESSION['trip_success'] ?? '';
unset($_SESSION['trip_success']);

$defaultDate = date('Y-m-d');
$defaultTripNumber = '';
try {
    $year = date('y');
    $prefix = "TR-$year-";
    $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(dispatch_number, ?) AS UNSIGNED))
                           FROM dispatch_notes
                           WHERE dispatch_number LIKE ?");
    $stmt->execute([strlen($prefix) + 1, $prefix . '%']);
    $maxNum = $stmt->fetchColumn();
    $nextNum = ($maxNum ? (int) $maxNum : 0) + 1;
    $defaultTripNumber = $prefix . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
} catch (Exception $e) {
    $defaultTripNumber = 'TR-' . date('y') . '-' . str_pad('1', 4, '0', STR_PAD_LEFT);
}

// Fetch current user's signature
$signaturePath = null;
try {
    $stmtSig = $pdo->prepare("SELECT signature_path FROM users WHERE id = ?");
    $stmtSig->execute([$_SESSION['user_id']]);
    $signaturePath = $stmtSig->fetchColumn() ?: null;
} catch (Exception $e) {
    $signaturePath = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tripNumber = trim((string) ($_POST['dispatch_number'] ?? '')) ?: $defaultTripNumber;
    $tripDate = trim((string) ($_POST['dispatch_date'] ?? '')) ?: $defaultDate;
    $from = trim((string) ($_POST['dispatch_from'] ?? ''));
    $to = trim((string) ($_POST['dispatch_to'] ?? ''));
    $cost = trim((string) ($_POST['route_price'] ?? ''));
    $addressTo = trim((string) ($_POST['address_to'] ?? ''));
    $contents = trim((string) ($_POST['contents'] ?? ''));

    if ($addressTo === '' && $to !== '') {
        $addressTo = $to;
    }

    if ($tripNumber === '' || $tripDate === '' || $from === '' || $to === '' || $addressTo === '' || $contents === '') {
        $error = 'Please fill all required fields.';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO dispatch_notes
                (type, dispatch_number, dispatch_date, dispatch_from, dispatch_to, route_price, address_to, contents, created_by, signature_path)
                VALUES ('trip', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $tripNumber,
                $tripDate,
                $from,
                $to,
                ($cost === '' ? null : $cost),
                $addressTo,
                $contents,
                $_SESSION['user_id'],
                $signaturePath,
            ]);
            $_SESSION['trip_success'] = 'Trip log saved successfully.';
            header('Location: trips.php?module=dispatch');
            exit;
        } catch (Exception $e) {
            $error = 'Failed to save trip log: ' . $e->getMessage();
        }
    }
}

// My trip logs
$trips = [];
try {
    $stmt = $pdo->prepare("SELECT dn.*, u.full_name
                           FROM dispatch_notes dn
                           LEFT JOIN users u ON dn.created_by = u.id
                           WHERE dn.type = 'trip' AND dn.created_by = ?
                           ORDER BY dn.dispatch_date DESC, dn.id DESC
                           LIMIT 50");
    $stmt->execute([$_SESSION['user_id']]);
    $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $trips = [];
}

$page_title = 'My logs';
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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
        .sig-preview { max-height: 48px; max-width: 100%; object-fit: contain; }

        @media (max-width: 767px) {
            .dispatch-toolbar-secondary {
                width: 100%;
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 6px;
            }
            .dispatch-toolbar-secondary a {
                min-height: 36px;
                padding-top: 0.35rem !important;
                padding-bottom: 0.35rem !important;
                font-size: 0.8125rem !important;
                display: inline-flex !important;
                align-items: center;
                justify-content: center;
            }
            /* Keep logs table in desktop view on mobile */
            .trip-logs-wrapper { overflow-x: auto !important; -webkit-overflow-scrolling: touch; }
            .trip-logs-table { min-width: 980px !important; width: 100% !important; }
        }

        /* Mobile success bottom sheet */
        @media (max-width: 767.98px) {
            body.trip-success-sheet-open { overflow: hidden; touch-action: none; }
        }
        .trip-success-sheet-backdrop { display: none; }
        .trip-success-sheet { display: none; }
        @media (max-width: 767.98px) {
            .trip-success-sheet-backdrop {
                display: block;
                position: fixed;
                inset: 0;
                background: rgba(15, 23, 42, 0.48);
                z-index: 1080;
                opacity: 0;
                visibility: hidden;
                transition: opacity 0.28s ease, visibility 0.28s ease;
            }
            .trip-success-sheet-backdrop.is-visible { opacity: 1; visibility: visible; }
            .trip-success-sheet {
                display: block;
                position: fixed;
                left: 0; right: 0; bottom: 0;
                max-height: min(58vh, 420px);
                background: #fff;
                border-radius: 1.25rem 1.25rem 0 0;
                box-shadow: 0 -12px 40px rgba(0, 0, 0, 0.18);
                z-index: 1090;
                transform: translateY(105%);
                transition: transform 0.32s cubic-bezier(0.32, 0.72, 0, 1);
                padding-bottom: max(1rem, env(safe-area-inset-bottom, 0px));
            }
            .trip-success-sheet.is-visible { transform: translateY(0); }
            .trip-success-sheet-handle {
                width: 40px; height: 5px;
                background: #d1d5db;
                border-radius: 999px;
                margin: 12px auto 8px;
            }
        }
    </style>
</head>
<body class="dashboard">
<?php
$rootPath = '/';
$logoBase = '/';
$modulesLink = '/select-module.php';
require_once __DIR__ . '/../includes/header_employee.php';
?>

<main class="main-content mov-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto px-0">
        <div class="dispatch-page-sticky bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-3 py-2 sm:px-4 sm:py-3 flex flex-wrap items-start sm:items-center gap-2 sm:gap-3 border-b border-gray-100">
                <div class="w-full sm:flex-1 sm:min-w-0 flex items-center min-w-0">
                    <h1 class="text-base sm:text-xl font-bold text-gray-900 truncate m-0 inline-flex items-center gap-1.5 sm:gap-2 leading-tight">
                        <i class="fas fa-car text-[#2563EB] text-sm sm:text-base"></i><span>My logs</span>
                    </h1>
                </div>
                <div class="flex-1 min-w-[8px] hidden sm:block"></div>
                <div class="dispatch-toolbar-secondary flex flex-wrap items-center gap-2 w-full sm:w-auto justify-stretch sm:justify-end">
                    <a href="index.php?<?= htmlspecialchars($modQ) ?>" class="text-sm sm:text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-2.5 py-1.5 sm:px-3 sm:py-2 bg-white inline-flex items-center gap-1.5 sm:gap-2 no-underline flex-1 sm:flex-initial justify-center">
                        <i class="fas fa-chart-line text-xs sm:text-sm"></i> Overview
                    </a>
                    <a href="routes.php?<?= htmlspecialchars($modQ) ?>" class="text-sm sm:text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-2.5 py-1.5 sm:px-3 sm:py-2 bg-white inline-flex items-center gap-1.5 sm:gap-2 no-underline flex-1 sm:flex-initial justify-center">
                        <i class="fas fa-map-marked-alt text-xs sm:text-sm"></i> Routes
                    </a>
                </div>
                <a href="/select-module.php" class="hidden sm:inline-flex text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white items-center gap-2 no-underline justify-center">
                    <i class="fas fa-th-large text-sm"></i> Modules
                </a>
            </div>
            <div class="px-3 py-1.5 sm:px-4 sm:py-2 flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-0.5 sm:gap-2 text-xs sm:text-base bg-gray-50/80 border-b border-gray-100 leading-snug">
                <span class="text-gray-600"><i class="fas fa-calendar text-gray-400 me-1 text-[0.7rem] sm:text-sm"></i><?= date('l, d M Y') ?></span>
                <span class="text-gray-300 hidden sm:inline">|</span>
                <span class="text-gray-500 sm:text-gray-600">Record your trips and operational logs.</span>
            </div>
        </div>

        <div class="px-4 pt-4 space-y-4">
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger mb-0 rounded-lg border-0 shadow-sm"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success !== ''): ?>
                <div class="alert alert-success mb-0 rounded-lg border-0 shadow-sm d-none d-md-block"><?= htmlspecialchars($success) ?></div>
                <div class="d-md-none trip-success-sheet-backdrop" id="tripSuccessBackdrop" aria-hidden="true"></div>
                <div class="d-md-none trip-success-sheet" id="tripSuccessSheet" role="dialog" aria-modal="true" aria-labelledby="tripSuccessSheetTitle">
                    <div class="trip-success-sheet-handle" aria-hidden="true"></div>
                    <div class="px-4 pb-4 pt-0 text-center">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-success bg-opacity-10 text-success mb-3" style="width: 56px; height: 56px;">
                            <i class="fas fa-check fa-lg"></i>
                        </div>
                        <h2 id="tripSuccessSheetTitle" class="h5 fw-bold text-gray-900 mb-2">Saved</h2>
                        <p class="text-gray-600 mb-4 small"><?= htmlspecialchars($success) ?></p>
                        <button type="button" class="btn mov-btn-primary w-100 py-2 rounded-pill fw-semibold border-0" id="tripSuccessSheetDismiss">OK</button>
                    </div>
                </div>
            <?php endif; ?>

            <div class="dash-card">
                <div class="px-4 py-3 border-b border-gray-100">
                    <div class="fw-bold text-gray-900">Add trip log</div>
                    <div class="text-sm text-gray-500 mt-1">Track an operational trip (date, route, cost, and notes).</div>
                </div>
                <div class="p-4">
                    <form method="POST" class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small text-uppercase text-gray-500 fw-semibold mb-1">Trip number</label>
                            <input type="text" name="dispatch_number" class="form-control rounded-md border-gray-300 bg-gray-50" value="<?= htmlspecialchars($defaultTripNumber) ?>" readonly required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-uppercase text-gray-500 fw-semibold mb-1">Date</label>
                            <input type="date" name="dispatch_date" class="form-control rounded-md border-gray-300 bg-gray-50" value="<?= htmlspecialchars($defaultDate) ?>" readonly required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-uppercase text-gray-500 fw-semibold mb-1">From <span class="text-danger">*</span></label>
                            <input type="text" name="dispatch_from" class="form-control rounded-md border-gray-300" required placeholder="e.g. Office">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-uppercase text-gray-500 fw-semibold mb-1">To <span class="text-danger">*</span></label>
                            <input type="text" name="dispatch_to" class="form-control rounded-md border-gray-300" required placeholder="e.g. Site / Branch">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-uppercase text-gray-500 fw-semibold mb-1">Cost (optional)</label>
                            <input type="number" step="0.01" name="route_price" class="form-control rounded-md border-gray-300" placeholder="0.00">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-uppercase text-gray-500 fw-semibold mb-1">Signature (account)</label>
                            <?php if (!empty($signaturePath)): ?>
                                <div class="border border-gray-200 rounded-md p-2 bg-gray-50 d-inline-block">
                                    <img src="<?= htmlspecialchars(app_url($signaturePath)) ?>" class="sig-preview" alt="Signature">
                                </div>
                            <?php else: ?>
                                <div class="text-muted small">No signature saved on your account.</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label small text-uppercase text-gray-500 fw-semibold mb-1">Address / destination <span class="text-danger">*</span></label>
                            <input type="text" name="address_to" class="form-control rounded-md border-gray-300" required placeholder="e.g. MUSOMA">
                        </div>
                        <div class="col-12">
                            <label class="form-label small text-uppercase text-gray-500 fw-semibold mb-1">Notes <span class="text-danger">*</span></label>
                            <textarea name="contents" class="form-control rounded-md border-gray-300" rows="4" required placeholder="Purpose, items, remarks…"></textarea>
                        </div>
                        <div class="col-12 d-flex justify-content-end pt-1">
                            <button type="submit" class="btn mov-btn-primary px-4 py-2 rounded-md fw-semibold border-0">
                                <i class="fas fa-save me-2"></i>Save log
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="dash-card">
                <div class="px-4 py-3 border-b border-gray-100 d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2">
                    <div class="fw-bold text-gray-900">My recent logs</div>
                    <span class="text-sm text-gray-500"><?= count($trips) ?> record<?= count($trips) === 1 ? '' : 's' ?></span>
                </div>
                <?php if (empty($trips)): ?>
                    <div class="p-5 text-center text-gray-500">
                        <i class="far fa-folder-open fa-3x mb-3 opacity-50 d-block"></i>
                        <p class="mb-0">No logs yet.</p>
                    </div>
                <?php else: ?>
                    <div class="dash-table-wrapper trip-logs-wrapper">
                        <table class="table table-hover align-middle mb-0 dash-table trip-logs-table">
                            <thead>
                                <tr class="dash-table-head">
                                    <th class="ps-3 py-3">#</th>
                                    <th class="py-3">Date</th>
                                    <th class="py-3">Route</th>
                                    <th class="py-3">Address</th>
                                    <th class="py-3 text-end">Cost</th>
                                    <th class="py-3">Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($trips as $t): ?>
                                    <tr class="border-b border-gray-100 hover:bg-gray-50/80">
                                        <td class="ps-3 py-3 fw-bold text-gray-900"><?= htmlspecialchars($t['dispatch_number'] ?? '') ?></td>
                                        <td class="py-3 text-gray-700"><?= htmlspecialchars((string) ($t['dispatch_date'] ?? '')) ?></td>
                                        <td class="py-3 text-gray-700"><?= htmlspecialchars(($t['dispatch_from'] ?? '-') . ' ? ' . ($t['dispatch_to'] ?? '-')) ?></td>
                                        <td class="py-3 text-gray-700"><?= htmlspecialchars((string) ($t['address_to'] ?? '')) ?></td>
                                        <td class="py-3 text-end fw-bold tabular-nums text-gray-900">TZS <?= number_format((float) ($t['route_price'] ?? 0), 2) ?></td>
                                        <td class="py-3 text-gray-700"><?= htmlspecialchars((string) ($t['contents'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<?php if ($success !== ''): ?>
<script>
(function () {
    var sheet = document.getElementById('tripSuccessSheet');
    var backdrop = document.getElementById('tripSuccessBackdrop');
    var btn = document.getElementById('tripSuccessSheetDismiss');
    if (!sheet || !backdrop) return;

    var mq = window.matchMedia('(max-width: 767.98px)');
    var autoTimer;

    function openSheet() {
        if (!mq.matches) return;
        sheet.setAttribute('aria-hidden', 'false');
        document.body.classList.add('trip-success-sheet-open');
        requestAnimationFrame(function () {
            backdrop.classList.add('is-visible');
            sheet.classList.add('is-visible');
        });
        window.clearTimeout(autoTimer);
        autoTimer = window.setTimeout(closeSheet, 6000);
    }

    function closeSheet() {
        window.clearTimeout(autoTimer);
        backdrop.classList.remove('is-visible');
        sheet.classList.remove('is-visible');
        document.body.classList.remove('trip-success-sheet-open');
        window.setTimeout(function () {
            if (!sheet.classList.contains('is-visible')) sheet.setAttribute('aria-hidden', 'true');
        }, 350);
    }

    function init() { if (mq.matches) openSheet(); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();

    mq.addEventListener('change', function (e) { if (!e.matches) closeSheet(); });
    if (btn) btn.addEventListener('click', closeSheet);
    backdrop.addEventListener('click', closeSheet);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && sheet.classList.contains('is-visible')) closeSheet(); });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../stock/includes/footer.php'; ?>

