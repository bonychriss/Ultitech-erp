<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

// Ensure recipients table exists (per-user address book for dispatch invoices)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dispatch_invoice_recipients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            label VARCHAR(120) NULL,
            company_name VARCHAR(190) NOT NULL,
            address TEXT NULL,
            email VARCHAR(190) NULL,
            phone VARCHAR(80) NULL,
            tin VARCHAR(80) NULL,
            vrn VARCHAR(80) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            INDEX idx_dir_user (user_id, is_active),
            CONSTRAINT fk_dir_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Throwable $e) {
    // ignore
}

// Ensure per-user invoice footer/payment settings exist
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dispatch_invoice_footer_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            ac_number VARCHAR(80) NULL,
            ac_name VARCHAR(190) NULL,
            bank_name VARCHAR(190) NULL,
            phones VARCHAR(190) NULL,
            address_line VARCHAR(255) NULL,
            website VARCHAR(190) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_footer_user (user_id),
            CONSTRAINT fk_footer_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Throwable $e) {
    // ignore
}

$uid = (int) ($_SESSION['user_id'] ?? 0);

// Carry over dispatch filters (prefer POST so submitted form values win)
$dateFrom = trim((string) ($_POST['date_from'] ?? ($_GET['date_from'] ?? '')));
$dateTo = trim((string) ($_POST['date_to'] ?? ($_GET['date_to'] ?? '')));
$routeFrom = trim((string) ($_POST['route_from'] ?? ($_GET['route_from'] ?? '')));
$routeTo = trim((string) ($_POST['route_to'] ?? ($_GET['route_to'] ?? '')));
$customer = trim((string) ($_POST['customer'] ?? ($_GET['customer'] ?? '')));

// Default range: current month to today
if ($dateFrom === '') {
    $dateFrom = date('Y-m-01');
}
if ($dateTo === '') {
    $dateTo = date('Y-m-d');
}

$invoiceDate = trim((string) ($_POST['invoice_date'] ?? ($_GET['invoice_date'] ?? date('Y-m-d'))));
$invoiceNumber = trim((string) ($_POST['invoice_number'] ?? ($_GET['invoice_number'] ?? '')));

$error = '';

// Load saved footer settings (prefill)
$footer = [
    'ac_number' => '56010030012996',
    'ac_name' => 'ULTIMATE GENERAL TRADING COMPANY',
    'bank_name' => 'UNITED BANK FOR AFRICA (UBA)',
    'phones' => '+255 758 767 749 | +255 656 336 024',
    'address_line' => 'House No 14, Atisoko Street, Mikocheni B, Dar es Salaam',
    'website' => 'www.ultimate.co.tz',
];
try {
    $stF = $pdo->prepare("SELECT ac_number, ac_name, bank_name, phones, address_line, website FROM dispatch_invoice_footer_settings WHERE user_id = ? LIMIT 1");
    $stF->execute([$uid]);
    $rF = $stF->fetch(PDO::FETCH_ASSOC);
    if ($rF) {
        foreach ($footer as $k => $v) {
            if (array_key_exists($k, $rF) && trim((string) $rF[$k]) !== '') {
                $footer[$k] = (string) $rF[$k];
            }
        }
    }
} catch (Throwable $e) {
    // ignore
}

// Load saved recipients
$recipients = [];
try {
    $st = $pdo->prepare("SELECT id, label, company_name, address, email, phone, tin, vrn
                         FROM dispatch_invoice_recipients
                         WHERE user_id = ? AND is_active = 1
                         ORDER BY COALESCE(label, company_name) ASC, id DESC");
    $st->execute([$uid]);
    $recipients = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $recipients = [];
}

$selectedRecipientId = (int) ($_POST['recipient_id'] ?? ($_GET['recipient_id'] ?? 0));
$label = trim((string) ($_POST['label'] ?? ''));
$companyNameTo = trim((string) ($_POST['company_name'] ?? ''));
$addressTo = trim((string) ($_POST['address'] ?? ''));
$emailTo = trim((string) ($_POST['email'] ?? ''));
$phoneTo = trim((string) ($_POST['phone'] ?? ''));
$tinTo = trim((string) ($_POST['tin'] ?? ''));
$vrnTo = trim((string) ($_POST['vrn'] ?? ''));

$footer['ac_number'] = trim((string) ($_POST['ac_number'] ?? $footer['ac_number']));
$footer['ac_name'] = trim((string) ($_POST['ac_name'] ?? $footer['ac_name']));
$footer['bank_name'] = trim((string) ($_POST['bank_name'] ?? $footer['bank_name']));
$footer['phones'] = trim((string) ($_POST['phones'] ?? $footer['phones']));
$footer['address_line'] = trim((string) ($_POST['address_line'] ?? $footer['address_line']));
$footer['website'] = trim((string) ($_POST['website'] ?? $footer['website']));

// Pre-fill from selected recipient on initial GET
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $selectedRecipientId > 0) {
    foreach ($recipients as $r) {
        if ((int) $r['id'] === $selectedRecipientId) {
            $label = (string) ($r['label'] ?? '');
            $companyNameTo = (string) ($r['company_name'] ?? '');
            $addressTo = (string) ($r['address'] ?? '');
            $emailTo = (string) ($r['email'] ?? '');
            $phoneTo = (string) ($r['phone'] ?? '');
            $tinTo = (string) ($r['tin'] ?? '');
            $vrnTo = (string) ($r['vrn'] ?? '');
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        $save = isset($_POST['save_recipient']) && $_POST['save_recipient'] === '1';
        $useSelected = $selectedRecipientId > 0 && ($companyNameTo === '');

        if ($useSelected) {
            foreach ($recipients as $r) {
                if ((int) $r['id'] === $selectedRecipientId) {
                    $companyNameTo = (string) ($r['company_name'] ?? '');
                    $addressTo = (string) ($r['address'] ?? '');
                    $emailTo = (string) ($r['email'] ?? '');
                    $phoneTo = (string) ($r['phone'] ?? '');
                    $tinTo = (string) ($r['tin'] ?? '');
                    $vrnTo = (string) ($r['vrn'] ?? '');
                    break;
                }
            }
        }

        if ($companyNameTo === '') {
            $error = 'Please enter who the invoice is sent to (Company/Name).';
        } else {
            // Save footer/payment info (always save for next time)
            try {
                $stUpF = $pdo->prepare("
                    INSERT INTO dispatch_invoice_footer_settings
                        (user_id, ac_number, ac_name, bank_name, phones, address_line, website)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        ac_number = VALUES(ac_number),
                        ac_name = VALUES(ac_name),
                        bank_name = VALUES(bank_name),
                        phones = VALUES(phones),
                        address_line = VALUES(address_line),
                        website = VALUES(website),
                        updated_at = NOW()
                ");
                $stUpF->execute([
                    $uid,
                    $footer['ac_number'] !== '' ? $footer['ac_number'] : null,
                    $footer['ac_name'] !== '' ? $footer['ac_name'] : null,
                    $footer['bank_name'] !== '' ? $footer['bank_name'] : null,
                    $footer['phones'] !== '' ? $footer['phones'] : null,
                    $footer['address_line'] !== '' ? $footer['address_line'] : null,
                    $footer['website'] !== '' ? $footer['website'] : null,
                ]);
            } catch (Throwable $e) {
                // ignore
            }

            // Save to address book (optional)
            if ($save) {
                try {
                    if ($selectedRecipientId > 0) {
                        $stU = $pdo->prepare("
                            UPDATE dispatch_invoice_recipients
                            SET label = ?, company_name = ?, address = ?, email = ?, phone = ?, tin = ?, vrn = ?, updated_at = NOW()
                            WHERE id = ? AND user_id = ?
                            LIMIT 1
                        ");
                        $stU->execute([
                            $label !== '' ? $label : null,
                            $companyNameTo,
                            $addressTo !== '' ? $addressTo : null,
                            $emailTo !== '' ? $emailTo : null,
                            $phoneTo !== '' ? $phoneTo : null,
                            $tinTo !== '' ? $tinTo : null,
                            $vrnTo !== '' ? $vrnTo : null,
                            $selectedRecipientId,
                            $uid,
                        ]);
                    } else {
                        $stI = $pdo->prepare("
                            INSERT INTO dispatch_invoice_recipients
                                (user_id, label, company_name, address, email, phone, tin, vrn)
                            VALUES
                                (?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stI->execute([
                            $uid,
                            $label !== '' ? $label : null,
                            $companyNameTo,
                            $addressTo !== '' ? $addressTo : null,
                            $emailTo !== '' ? $emailTo : null,
                            $phoneTo !== '' ? $phoneTo : null,
                            $tinTo !== '' ? $tinTo : null,
                            $vrnTo !== '' ? $vrnTo : null,
                        ]);
                        $selectedRecipientId = (int) $pdo->lastInsertId();
                    }
                } catch (Throwable $e) {
                    // do not block invoice generation
                }
            }

            // Go to printable invoice
            $q = [
                'module' => 'dispatch',
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'route_from' => $routeFrom,
                'route_to' => $routeTo,
                'customer' => $customer,
                'invoice_date' => $invoiceDate,
                'invoice_number' => $invoiceNumber,
            ];
            if ($selectedRecipientId > 0) {
                $q['recipient_id'] = $selectedRecipientId;
            } else {
                // fallback: pass details explicitly (not saved)
                $q['to_name'] = $companyNameTo;
                $q['to_address'] = $addressTo;
                $q['to_email'] = $emailTo;
                $q['to_phone'] = $phoneTo;
                $q['to_tin'] = $tinTo;
                $q['to_vrn'] = $vrnTo;
            }

            header('Location: invoice.php?' . http_build_query($q));
            exit;
        }
    }
}

// Default invoice number suggestion
if ($invoiceNumber === '') {
    $invoiceNumber = 'INV-' . date('Ym') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Prepare Invoice - <?= htmlspecialchars(COMPANY_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <link href="/assets/css/style.css?v=<?= time() ?>" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { corePlugins: { preflight: false } };</script>
    <style>
        .prep-shell { font-family: 'Outfit', system-ui, -apple-system, sans-serif; font-size: 16px; color: #374151; }
        .cardx { border: 1px solid #e5e7eb; border-radius: 14px; background: #fff; box-shadow: 0 1px 2px rgba(15,23,42,.04); overflow: hidden; }
        .subtle { color:#6b7280; }
        .req::after { content:' *'; color:#ef4444; }
        .form-control, .form-select { border-radius: 10px; }
        .dash-card { border: 1px solid #e5e7eb; border-radius: 14px; background: #fff; box-shadow: 0 1px 2px rgba(15,23,42,.04); overflow: hidden; }
        .mov-btn-primary { background-color: #2563EB !important; color: #fff !important; border-color: #2563EB !important; }
        .mov-btn-primary:hover { background-color: #1D4ED8 !important; border-color: #1D4ED8 !important; color: #fff !important; }
        .small-label { font-size: 0.72rem; letter-spacing: 0.08em; text-transform: uppercase; color: #6b7280; font-weight: 800; }
        .divider { height:1px; background:#eef2f7; margin: 12px 0; }
    </style>
</head>
<body class="dashboard dispatch-page prep-shell">
<?php
$rootPath = '/';
$logoBase = '/';
$modulesLink = '/select-module.php';
require_once __DIR__ . '/../includes/header_employee.php';
?>

<main class="main-content bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto px-0">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100">
                <a href="index.php?module=dispatch" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-arrow-left text-sm"></i> Dispatch
                </a>
                <div class="flex items-center gap-2 min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 truncate m-0 inline-flex items-center gap-2">
                        <i class="fas fa-file-invoice text-[#2563EB]"></i><span>Generate invoice</span>
                    </h1>
                </div>
                <div class="flex-1 min-w-[8px]"></div>
                <a href="records.php?module=dispatch&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&route_from=<?= urlencode($routeFrom) ?>&route_to=<?= urlencode($routeTo) ?>" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-list text-sm"></i> Records
                </a>
            </div>
            <div class="px-4 py-2 flex flex-wrap items-center gap-2 text-base bg-gray-50/80 border-b border-gray-100">
                <span class="text-gray-600"><i class="fas fa-info-circle text-gray-400 me-1"></i>Pick invoice header + recipient, then choose the dispatch date range.</span>
            </div>
        </div>

        <div class="px-4 pt-4">
            <div class="dash-card">
                <div class="px-4 py-3 border-b border-gray-100 d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div>
                        <div class="fw-bold text-gray-900">Invoice details</div>
                        <div class="small subtle">
                            Route: <strong class="text-gray-900"><?= htmlspecialchars(trim($routeFrom . ($routeFrom !== '' && $routeTo !== '' ? ' -> ' : '') . $routeTo) ?: 'All') ?></strong>
                            - Customer: <strong class="text-gray-900"><?= htmlspecialchars($customer !== '' ? $customer : 'All') ?></strong>
                        </div>
                    </div>
                    <a class="btn btn-outline-secondary rounded-md px-3 fw-semibold" href="index.php?module=dispatch">
                        Back
                    </a>
                </div>

                <div class="p-4">
                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="post" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="route_from" value="<?= htmlspecialchars($routeFrom) ?>">
                        <input type="hidden" name="route_to" value="<?= htmlspecialchars($routeTo) ?>">
                        <input type="hidden" name="customer" value="<?= htmlspecialchars($customer) ?>">

                        <div class="col-12">
                            <div class="small-label">Invoice header</div>
                        </div>

                        <div class="col-12 col-lg-4">
                            <label class="form-label fw-semibold req">Invoice number</label>
                            <input type="text" class="form-control" name="invoice_number" value="<?= htmlspecialchars($invoiceNumber) ?>" required>
                        </div>
                        <div class="col-12 col-lg-4">
                            <label class="form-label fw-semibold req">Invoice date</label>
                            <input type="date" class="form-control" name="invoice_date" value="<?= htmlspecialchars($invoiceDate) ?>" required>
                        </div>
                        <div class="col-12 col-lg-4">
                            <label class="form-label fw-semibold">Recipient (optional label)</label>
                            <input type="text" class="form-control" name="label" id="label" value="<?= htmlspecialchars($label) ?>" placeholder="e.g. Wichman System">
                        </div>

                        <div class="col-12">
                            <div class="divider"></div>
                            <div class="small-label">Dispatch records range</div>
                        </div>

                        <div class="col-6 col-lg-3">
                            <label class="form-label fw-semibold req">From</label>
                            <input type="date" class="form-control" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" required>
                        </div>
                        <div class="col-6 col-lg-3">
                            <label class="form-label fw-semibold req">To</label>
                            <input type="date" class="form-control" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" required>
                        </div>
                        <div class="col-12 col-lg-6">
                            <div class="small subtle mt-2">
                                <i class="fas fa-calendar text-gray-400 me-1"></i>
                                <strong class="text-gray-900"><?= htmlspecialchars($dateFrom) ?></strong> to <strong class="text-gray-900"><?= htmlspecialchars($dateTo) ?></strong>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="divider"></div>
                            <div class="small-label">Send to</div>
                            <div class="small subtle mt-1">Select a saved recipient to auto-fill, or enter new details below.</div>
                        </div>

                        <div class="col-12 col-lg-6">
                            <label class="form-label fw-semibold">Saved recipients</label>
                            <select name="recipient_id" id="recipient_id" class="form-select">
                                <option value="0">-- New recipient / manual entry --</option>
                                <?php foreach ($recipients as $r): ?>
                                    <?php
                                        $rid = (int) ($r['id'] ?? 0);
                                        $lbl = trim((string) ($r['label'] ?? ''));
                                        $nm = trim((string) ($r['company_name'] ?? ''));
                                        $opt = $lbl !== '' ? ($lbl . ' - ' . $nm) : $nm;
                                    ?>
                                    <option value="<?= $rid ?>" <?= $rid === $selectedRecipientId ? 'selected' : '' ?>
                                            data-company="<?= htmlspecialchars((string) ($r['company_name'] ?? ''), ENT_QUOTES) ?>"
                                            data-address="<?= htmlspecialchars((string) ($r['address'] ?? ''), ENT_QUOTES) ?>"
                                            data-email="<?= htmlspecialchars((string) ($r['email'] ?? ''), ENT_QUOTES) ?>"
                                            data-phone="<?= htmlspecialchars((string) ($r['phone'] ?? ''), ENT_QUOTES) ?>"
                                            data-tin="<?= htmlspecialchars((string) ($r['tin'] ?? ''), ENT_QUOTES) ?>"
                                            data-vrn="<?= htmlspecialchars((string) ($r['vrn'] ?? ''), ENT_QUOTES) ?>"
                                            data-label="<?= htmlspecialchars((string) ($r['label'] ?? ''), ENT_QUOTES) ?>"
                                    ><?= htmlspecialchars($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Selecting a saved recipient will auto-fill the fields.</div>
                        </div>

                        <div class="col-12 col-lg-8">
                            <label class="form-label fw-semibold req">Invoice to (Company / Name)</label>
                            <input type="text" class="form-control" name="company_name" id="company_name" value="<?= htmlspecialchars($companyNameTo) ?>" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Address</label>
                            <textarea class="form-control" name="address" id="address" rows="2" placeholder="Address"><?= htmlspecialchars($addressTo) ?></textarea>
                        </div>

                        <div class="col-12 col-lg-4">
                            <label class="form-label fw-semibold">Email</label>
                            <input type="email" class="form-control" name="email" id="email" value="<?= htmlspecialchars($emailTo) ?>" placeholder="email@example.com">
                        </div>
                        <div class="col-12 col-lg-4">
                            <label class="form-label fw-semibold">Phone</label>
                            <input type="text" class="form-control" name="phone" id="phone" value="<?= htmlspecialchars($phoneTo) ?>" placeholder="+255 ...">
                        </div>
                        <div class="col-6 col-lg-2">
                            <label class="form-label fw-semibold">TIN</label>
                            <input type="text" class="form-control" name="tin" id="tin" value="<?= htmlspecialchars($tinTo) ?>">
                        </div>
                        <div class="col-6 col-lg-2">
                            <label class="form-label fw-semibold">VRN</label>
                            <input type="text" class="form-control" name="vrn" id="vrn" value="<?= htmlspecialchars($vrnTo) ?>">
                        </div>

                        <div class="col-12">
                            <div class="divider"></div>
                            <div class="small-label">Payment info (invoice footer)</div>
                            <div class="small subtle mt-1">These details will be saved and printed on the invoice footer.</div>
                        </div>

                        <div class="col-12 col-lg-4">
                            <label class="form-label fw-semibold">A/C NUMBER</label>
                            <input type="text" class="form-control" name="ac_number" value="<?= htmlspecialchars($footer['ac_number']) ?>">
                        </div>
                        <div class="col-12 col-lg-4">
                            <label class="form-label fw-semibold">A/C NAME</label>
                            <input type="text" class="form-control" name="ac_name" value="<?= htmlspecialchars($footer['ac_name']) ?>">
                        </div>
                        <div class="col-12 col-lg-4">
                            <label class="form-label fw-semibold">BANK NAME</label>
                            <input type="text" class="form-control" name="bank_name" value="<?= htmlspecialchars($footer['bank_name']) ?>">
                        </div>

                        <div class="col-12 col-lg-6">
                            <label class="form-label fw-semibold">Phones</label>
                            <input type="text" class="form-control" name="phones" value="<?= htmlspecialchars($footer['phones']) ?>" placeholder="+255 ... | +255 ...">
                        </div>
                        <div class="col-12 col-lg-6">
                            <label class="form-label fw-semibold">Website</label>
                            <input type="text" class="form-control" name="website" value="<?= htmlspecialchars($footer['website']) ?>" placeholder="www.example.com">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Address</label>
                            <input type="text" class="form-control" name="address_line" value="<?= htmlspecialchars($footer['address_line']) ?>">
                        </div>

                        <div class="col-12 d-flex align-items-center justify-content-between flex-wrap gap-2 mt-2">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1" id="save_recipient" name="save_recipient">
                                <label class="form-check-label fw-semibold" for="save_recipient">
                                    Save these details for next time
                                </label>
                            </div>
                            <button type="submit" class="btn btn-primary px-4 fw-semibold">
                                <i class="fas fa-file-invoice me-2"></i>Generate invoice
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
(function(){
    var sel = document.getElementById('recipient_id');
    if (!sel) return;
    function fillFromOption(opt){
        if (!opt) return;
        document.getElementById('label').value = opt.getAttribute('data-label') || '';
        document.getElementById('company_name').value = opt.getAttribute('data-company') || '';
        document.getElementById('address').value = opt.getAttribute('data-address') || '';
        document.getElementById('email').value = opt.getAttribute('data-email') || '';
        document.getElementById('phone').value = opt.getAttribute('data-phone') || '';
        document.getElementById('tin').value = opt.getAttribute('data-tin') || '';
        document.getElementById('vrn').value = opt.getAttribute('data-vrn') || '';
    }
    sel.addEventListener('change', function(){
        var opt = sel.options[sel.selectedIndex];
        if (sel.value === '0') return;
        fillFromOption(opt);
    });
    // initial select
    if (sel.value !== '0') {
        fillFromOption(sel.options[sel.selectedIndex]);
    }
})();
</script>
</body>
</html>

