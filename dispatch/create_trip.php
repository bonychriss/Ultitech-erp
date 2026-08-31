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

// Ensure dispatch_routes exists so we can reuse route list
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS dispatch_routes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        route_from VARCHAR(255) NOT NULL,
        route_to VARCHAR(255) NOT NULL,
        price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        price_currency VARCHAR(3) NOT NULL DEFAULT 'TZS',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {
    // Ignore
}
ensure_dispatch_routes_price_currency($pdo);

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
    if (!in_array('attachment_path', $cols, true)) {
        $pdo->exec("ALTER TABLE dispatch_notes ADD COLUMN attachment_path VARCHAR(255) NULL AFTER signature_path");
    }
} catch (Exception $e) {
    // Ignore
}

$error = '';
$success = $_SESSION['create_trip_success'] ?? '';
unset($_SESSION['create_trip_success']);

// Defaults
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

// Signature
$signaturePath = null;
try {
    $stmtSig = $pdo->prepare("SELECT signature_path FROM users WHERE id = ?");
    $stmtSig->execute([$_SESSION['user_id']]);
    $signaturePath = $stmtSig->fetchColumn() ?: null;
} catch (Exception $e) {
    $signaturePath = null;
}

// Routes for From/To dropdowns
$routes = [];
try {
    $stmtRoutes = $pdo->query("SELECT route_from, route_to FROM dispatch_routes ORDER BY created_at DESC");
    $routes = $stmtRoutes ? ($stmtRoutes->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Exception $e) {
    $routes = [];
}

$routesByFrom = [];
foreach ($routes as $r) {
    $fromKey = (string) ($r['route_from'] ?? '');
    $toVal = (string) ($r['route_to'] ?? '');
    if ($fromKey === '' || $toVal === '') {
        continue;
    }
    if (!isset($routesByFrom[$fromKey])) {
        $routesByFrom[$fromKey] = [];
    }
    $routesByFrom[$fromKey][] = $toVal;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tripNumber = trim((string) ($_POST['dispatch_number'] ?? '')) ?: $defaultTripNumber;
    $tripDate = trim((string) ($_POST['dispatch_date'] ?? '')) ?: $defaultDate;
    $from = trim((string) ($_POST['dispatch_from'] ?? ''));
    $to = trim((string) ($_POST['dispatch_to'] ?? ''));
    if ($from === '__OTHER__') {
        $from = trim((string) ($_POST['dispatch_from_other'] ?? ''));
    }
    if ($to === '__OTHER__') {
        $to = trim((string) ($_POST['dispatch_to_other'] ?? ''));
    }
    $cost = trim((string) ($_POST['route_price'] ?? ''));
    $contents = trim((string) ($_POST['contents'] ?? ''));
    $attachmentPath = null;

    // Address/destination is derived from "To"
    $addressTo = $to;

    if ($tripNumber === '' || $tripDate === '' || $from === '' || $to === '' || $contents === '') {
        $error = 'Please fill all required fields.';
    } else {
        try {
            if (!isset($_FILES['attachment']) || !is_array($_FILES['attachment'])) {
                throw new RuntimeException('Attachment is required.');
            }
            $upErr = (int) ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($upErr === UPLOAD_ERR_NO_FILE) {
                throw new RuntimeException('Attachment is required.');
            }
            if ($upErr !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Attachment upload failed (error ' . $upErr . ').');
            }

            $tmpName = (string) ($_FILES['attachment']['tmp_name'] ?? '');
            $origName = (string) ($_FILES['attachment']['name'] ?? '');
            $size = (int) ($_FILES['attachment']['size'] ?? 0);
            if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                throw new RuntimeException('Invalid attachment upload.');
            }
            if ($size > 10 * 1024 * 1024) {
                throw new RuntimeException('Attachment too large (max 10MB).');
            }

            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
            if ($ext === '' || !in_array($ext, $allowed, true)) {
                throw new RuntimeException('Unsupported attachment type. Allowed: PDF, JPG, PNG, WEBP.');
            }

            $baseDir = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
            $destDir = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'trip_attachments';
            if (!is_dir($destDir)) {
                @mkdir($destDir, 0775, true);
            }
            if (!is_dir($destDir)) {
                throw new RuntimeException('Upload folder not available.');
            }

            $safeStem = preg_replace('/[^A-Za-z0-9_-]+/', '-', pathinfo($origName, PATHINFO_FILENAME));
            $safeStem = trim((string) $safeStem, '-');
            if ($safeStem === '') {
                $safeStem = 'attachment';
            }
            $fileName = 'trip_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $safeStem . '.' . $ext;
            $destPath = $destDir . DIRECTORY_SEPARATOR . $fileName;
            if (!move_uploaded_file($tmpName, $destPath)) {
                throw new RuntimeException('Failed to save attachment.');
            }

            // Store web path relative to /
            $attachmentPath = 'uploads/trip_attachments/' . $fileName;

            $stmt = $pdo->prepare("INSERT INTO dispatch_notes
                (type, dispatch_number, dispatch_date, dispatch_from, dispatch_to, route_price, address_to, contents, created_by, signature_path, attachment_path)
                VALUES ('trip', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
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
                $attachmentPath,
            ]);

            $_SESSION['office_trip_success'] = 'Office trip saved successfully.';
            header('Location: office_trips.php?module=dispatch');
            exit;
        } catch (Exception $e) {
            $error = 'Failed to save office trip: ' . $e->getMessage();
        }
    }
}

$page_title = 'Create trip';
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
        }
        .dash-card {
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
            overflow: hidden;
        }
        .sig-preview { max-width: 220px; max-height: 110px; object-fit: contain; }

        .trip-attach-drop {
            border: 2px dashed #cbd5e1;
            border-radius: 14px;
            background: #fff;
            padding: 22px 16px;
            text-align: center;
            transition: border-color .15s ease, box-shadow .15s ease, background-color .15s ease;
        }
        .trip-attach-drop:hover { border-color: rgba(37, 99, 235, 0.55); }
        .trip-attach-drop.is-dragover {
            border-color: rgba(37, 99, 235, 0.8);
            box-shadow: 0 0 0 .2rem rgba(37, 99, 235, 0.12);
            background: rgba(37, 99, 235, 0.03);
        }
        .trip-attach-ico {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: #f1f5f9;
            color: #64748b;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 10px;
        }
        .trip-attach-title { font-weight: 700; color: #111827; }
        .trip-attach-sub { color: #6b7280; font-size: 0.875rem; margin-top: 4px; }
        .trip-attach-file { margin-top: 10px; font-size: 0.9rem; color: #374151; }
        .trip-attach-file small { color: #6b7280; }

        @media (max-width: 767.98px) {
            .trip-attach-drop { padding: 14px 12px; border-radius: 12px; }
            .trip-attach-ico { width: 38px; height: 38px; border-radius: 10px; margin-bottom: 8px; }
            .trip-attach-title { font-size: 0.95rem; }
            .trip-attach-sub { font-size: 0.8rem; }
            .trip-attach-drop .btn { padding: 0.45rem 0.85rem; }
        }
    </style>
</head>
<body class="dashboard dispatch-page">
<?php
$rootPath = '/';
$logoBase = '/';
$modulesLink = '/select-module.php';
require_once __DIR__ . '/../includes/header_employee.php';
?>

<main class="main-content mov-shell bg-[#F9F9F9] min-h-[50vh] pb-8 dispatch-dash-mobile">
    <div class="max-w-full mx-auto px-0">
        <div class="dispatch-page-sticky bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-3 py-2 sm:px-4 sm:py-3 flex flex-wrap items-center gap-2 sm:gap-3 border-b border-gray-100">
                <div class="flex items-center gap-2 min-w-0 flex-grow sm:flex-grow-0">
                    <h1 class="text-base sm:text-xl font-bold text-gray-900 truncate m-0 inline-flex items-center gap-2 leading-tight">
                        <i class="fas fa-car text-[#2563EB] text-sm sm:text-base"></i><span>Create office trip</span>
                    </h1>
                </div>
                <div class="flex-1 min-w-[8px] hidden sm:block"></div>
                <div class="d-flex gap-2 flex-wrap w-100 w-sm-auto">
                    <a href="office_trips.php?<?= htmlspecialchars($modQ) ?>" class="text-sm sm:text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline justify-center">
                        <i class="fas fa-arrow-left text-xs sm:text-sm"></i> Office trips
                    </a>
                    <a href="index.php?<?= htmlspecialchars($modQ) ?>" class="text-sm sm:text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline justify-center">
                        <i class="fas fa-chart-line text-xs sm:text-sm"></i> Overview
                    </a>
                    <a href="routes.php?<?= htmlspecialchars($modQ) ?>" class="text-sm sm:text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline justify-center">
                        <i class="fas fa-map-marked-alt text-xs sm:text-sm"></i> Routes
                    </a>
                </div>
            </div>
            <div class="px-3 py-1.5 sm:px-4 sm:py-2 flex flex-wrap items-center gap-2 text-xs sm:text-base bg-gray-50/80 border-b border-gray-100 leading-snug">
                <span class="text-gray-600"><i class="fas fa-calendar text-gray-400 me-1 text-[0.7rem] sm:text-sm"></i><?= date('l, d M Y') ?></span>
                <span class="text-gray-300 hidden sm:inline">|</span>
                <span class="text-gray-500 sm:text-gray-600">Log internal trips (bank, errands, inspections, deliveries, etc.).</span>
            </div>
        </div>

        <div class="px-4 pt-4 space-y-4">
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger mb-0 rounded-lg border-0 shadow-sm"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success !== ''): ?>
                <div class="alert alert-success mb-0 rounded-lg border-0 shadow-sm"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <div class="dash-card">
                <div class="px-4 py-3 border-b border-gray-100">
                    <div class="fw-bold text-gray-900">Trip details</div>
                    <div class="text-sm text-gray-500 mt-1">Fill required fields and save.</div>
                </div>
                <div class="p-4">
                    <form method="POST" class="row g-3" enctype="multipart/form-data">
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
                            <?php if (empty($routesByFrom)): ?>
                                <input type="text" name="dispatch_from" class="form-control rounded-md border-gray-300" required placeholder="e.g. Office">
                                <div class="text-xs text-gray-500 mt-1">
                                    No saved routes found. Add routes in <a class="text-[#2563EB] fw-semibold no-underline" href="routes.php?<?= htmlspecialchars($modQ) ?>">Routes</a>.
                                </div>
                            <?php else: ?>
                                <select name="dispatch_from" id="trip_from" class="form-select rounded-md border-gray-300" required>
                                    <option value="">Select from</option>
                                    <?php foreach (array_keys($routesByFrom) as $fromOpt): ?>
                                        <option value="<?= htmlspecialchars($fromOpt) ?>"><?= htmlspecialchars($fromOpt) ?></option>
                                    <?php endforeach; ?>
                                    <option value="__OTHER__">Other...</option>
                                </select>
                                <input type="text" name="dispatch_from_other" id="trip_from_other" class="form-control rounded-md border-gray-300 mt-2 d-none" placeholder="Type location...">
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-uppercase text-gray-500 fw-semibold mb-1">To <span class="text-danger">*</span></label>
                            <?php if (empty($routesByFrom)): ?>
                                <input type="text" name="dispatch_to" class="form-control rounded-md border-gray-300" required placeholder="e.g. Bank / Branch">
                            <?php else: ?>
                                <select name="dispatch_to" id="trip_to" class="form-select rounded-md border-gray-300" required>
                                    <option value="">Select to</option>
                                </select>
                                <input type="text" name="dispatch_to_other" id="trip_to_other" class="form-control rounded-md border-gray-300 mt-2 d-none" placeholder="Type location...">
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-uppercase text-gray-500 fw-semibold mb-1">Cost (optional)</label>
                            <input type="number" step="0.01" name="route_price" class="form-control rounded-md border-gray-300" placeholder="0.00">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-uppercase text-gray-500 fw-semibold mb-1">Attachment <span class="text-danger">*</span></label>
                            <input type="file" name="attachment" id="trip_attachment" class="d-none" accept=".pdf,image/*" required>
                            <div class="trip-attach-drop" id="tripAttachDrop" role="button" tabindex="0" aria-label="Upload attachment">
                                <div class="trip-attach-ico"><i class="far fa-file"></i></div>
                                <div class="trip-attach-title">Upload a file</div>
                                <div class="trip-attach-sub">Maximum File Size: 10 MB</div>
                                <div class="mt-3">
                                    <span class="btn btn-sm mov-btn-primary rounded-md px-3 py-2 fw-semibold border-0">Upload</span>
                                </div>
                                <div class="trip-attach-file d-none" id="tripAttachFile"></div>
                            </div>
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
                            <label class="form-label small text-uppercase text-gray-500 fw-semibold mb-1">Notes <span class="text-danger">*</span></label>
                            <textarea name="contents" class="form-control rounded-md border-gray-300" rows="4" required placeholder="Purpose, items, remarks..."></textarea>
                        </div>
                        <div class="col-12 d-flex justify-content-end pt-1">
                            <button type="submit" class="btn mov-btn-primary px-4 py-2 rounded-md fw-semibold border-0">
                                <i class="fas fa-save me-2"></i>Save trip
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<?php if (!empty($routesByFrom)): ?>
<script>
    (function () {
        const routesByFrom = <?= json_encode($routesByFrom) ?>;
        const fromSel = document.getElementById('trip_from');
        const toSel = document.getElementById('trip_to');
        const fromOther = document.getElementById('trip_from_other');
        const toOther = document.getElementById('trip_to_other');

        function setHidden(el, hide) {
            if (!el) return;
            el.classList.toggle('d-none', hide);
            if (hide) el.value = '';
        }

        function refreshToOptions() {
            const fromVal = fromSel?.value || '';
            if (!toSel) return;
            toSel.innerHTML = '<option value="">Select to</option>';

            const options = routesByFrom[fromVal] || [];
            options.forEach(function (to) {
                const opt = document.createElement('option');
                opt.value = to;
                opt.textContent = to;
                toSel.appendChild(opt);
            });
            toSel.appendChild(new Option('Other...', '__OTHER__'));
        }

        function onFromChange() {
            const isOther = fromSel.value === '__OTHER__';
            setHidden(fromOther, !isOther);
            if (!isOther) refreshToOptions();
            if (isOther) {
                if (toSel) toSel.innerHTML = '<option value="__OTHER__">Other...</option>';
                setHidden(toOther, false);
                if (toSel) toSel.value = '__OTHER__';
            }
        }

        function onToChange() {
            const isOther = toSel?.value === '__OTHER__';
            setHidden(toOther, !isOther);
        }

        if (fromSel) fromSel.addEventListener('change', onFromChange);
        if (toSel) toSel.addEventListener('change', onToChange);

        // init
        if (fromSel) {
            refreshToOptions();
        }
    })();
</script>
<?php endif; ?>

<script>
    (function () {
        const input = document.getElementById('trip_attachment');
        const drop = document.getElementById('tripAttachDrop');
        const fileInfo = document.getElementById('tripAttachFile');
        if (!input || !drop) return;

        function humanSize(bytes) {
            if (!bytes && bytes !== 0) return '';
            const units = ['B', 'KB', 'MB', 'GB'];
            let b = bytes;
            let u = 0;
            while (b >= 1024 && u < units.length - 1) { b /= 1024; u++; }
            return (u === 0 ? b : b.toFixed(1)) + ' ' + units[u];
        }

        function render() {
            const f = input.files && input.files[0] ? input.files[0] : null;
            if (!fileInfo) return;
            if (!f) {
                fileInfo.classList.add('d-none');
                fileInfo.textContent = '';
                return;
            }
            fileInfo.classList.remove('d-none');
            fileInfo.innerHTML = '<strong>' + (f.name || 'Selected file') + '</strong><br><small>' + humanSize(f.size) + '</small>';
        }

        drop.addEventListener('click', function () { input.click(); });
        drop.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
        });
        input.addEventListener('change', render);

        ;['dragenter', 'dragover'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) {
                e.preventDefault();
                e.stopPropagation();
                drop.classList.add('is-dragover');
            });
        });
        ;['dragleave', 'drop'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) {
                e.preventDefault();
                e.stopPropagation();
                drop.classList.remove('is-dragover');
            });
        });
        drop.addEventListener('drop', function (e) {
            const dt = e.dataTransfer;
            if (!dt || !dt.files || !dt.files.length) return;
            input.files = dt.files;
            render();
        });
    })();
</script>

<?php require_once __DIR__ . '/../stock/includes/footer.php'; ?>
</body>
</html>

