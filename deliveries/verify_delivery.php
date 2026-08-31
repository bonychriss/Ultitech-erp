<?php
require_once '../includes/functions.php';
require_once __DIR__ . '/deliveries-ui/delivery-note-invoice.php';

// Debug logging for live site
$debugFile = __DIR__ . '/verify_debug.log';
$logMsg = date('[Y-m-d H:i:s] ') . $_SERVER['REQUEST_METHOD'] . " request for hash: " . ($_GET['hash'] ?? 'MISSING') . "\n";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $logMsg .= "  POST data size: " . strlen(serialize($_POST)) . " bytes\n";
}
@file_put_contents($debugFile, $logMsg, FILE_APPEND);

if (!isset($_GET['hash'])) {
    die("Access Denied: Invalid Hash");
}

// Prevent caching to ensure "Back" button forces a re-check of the signature status
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$hash = $_GET['hash'];
$order = getOrderByVerificationHash($hash);

if (!$order) {
    die("Access Denied: Link Expired or Invalid.");
}

if (deliveries_resolve_sales_invoice_id($pdo, $order) > 0) {
    deliveries_ensure_order_delivery_note($pdo, (int) $order['id'], (int) ($order['created_by'] ?? 0));
    $stmtRefresh = $pdo->prepare('SELECT * FROM delivery_orders WHERE id = ?');
    $stmtRefresh->execute([(int) $order['id']]);
    $order = $stmtRefresh->fetch(PDO::FETCH_ASSOC) ?: $order;
}

$salesInvoiceId = deliveries_resolve_sales_invoice_id($pdo, $order);
$brand = deliveries_load_public_company_branding($pdo, deliveries_resolve_order_company_id($pdo, $order));

// Fetch DN if linked
$dn = null;
if (!empty($order['delivery_note_id'])) {
    $stmtDn = $pdo->prepare("SELECT * FROM delivery_notes WHERE id = ?");
    $stmtDn->execute([$order['delivery_note_id']]);
    $dn = $stmtDn->fetch(PDO::FETCH_ASSOC);
}

// REDIRECT IF ALREADY SIGNED BY CLIENT
// We only auto-redirect if THIS SPECIFIC ORDER has a CLIENT signature.
// This prevents stale signatures on reused Delivery Notes from causing redirects.
$isClientSigned = (strpos($order['signature_path'] ?? '', 'client_') !== false);

if ($isClientSigned) {
    // Check if it was just signed in this session (to avoid loops)
    if (!isset($_GET['success'])) {
        header("Location: final.php?hash=" . urlencode($hash));
        exit;
    }
}

// Handle Client Signature Submission
$success = false;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['signature_data'])) {
    try {
        $recipientName = trim($_POST['recipient_name'] ?? $order['client_name']);
        $data = $_POST['signature_data'];
        
        // Basic validation
        if (empty($data)) throw new Exception("Signature data is missing.");

        // SECURITY WORKAROUND: Raw base64 is sent to bypass firewall blocking
        // Reconstruct if it happens to have the prefix (for backward compatibility or local tests)
        if (strpos($data, ',') !== false) {
             list(, $data) = explode(',', $data);
        }
        $data = base64_decode($data);
        
        $filename = 'client_' . $order['id'] . '_' . time() . '.png';
        $dir = ensureSignatureDir();
        file_put_contents($dir . '/' . $filename, $data);
        $signaturePath = 'assets/signatures/' . $filename;

        // 1. Update DN with receiver signature
        if ($dn) {
            $up = $pdo->prepare("UPDATE delivery_notes SET receiver_signature_path = ? WHERE id = ?");
            $up->execute([$signaturePath, $dn['id']]);
        }
        
        // 2. Update Delivery Order (Status & Signature)
        $upOrder = $pdo->prepare("UPDATE delivery_orders SET 
                                    status = 'delivered', 
                                    signature_path = ?, 
                                    recipient_name = ?,
                                    completion_time = NOW() 
                                  WHERE id = ?");
        $upOrder->execute([$signaturePath, $recipientName, $order['id']]);

        // Post/Redirect/Get (PRG) Pattern
        // Redirect to the NEW final.php success page using full URL
        $redirectUrl = app_url('deliveries/final.php') . '?hash=' . urlencode($hash) . '&success=1';
        @file_put_contents($debugFile, "[SUCCESS] Redirecting to: " . $redirectUrl . "\n", FILE_APPEND);
        
        header("Location: " . $redirectUrl);
        exit;

    } catch (Exception $e) {
        $error = "Error saving signature: " . $e->getMessage();
        @file_put_contents($debugFile, "[ERROR] " . $e->getMessage() . "\n", FILE_APPEND);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Delivery Verification - #<?= $order['invoice_ref'] ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js"></script>
    <script>
        // Force reload on back button (fix for BFCache/Safari/Mobile)
        window.addEventListener( "pageshow", function ( event ) {
            var historyTraversal = event.persisted || 
                                   ( typeof window.performance != "undefined" && 
                                     window.performance.navigation.type === 2 );
            if ( historyTraversal ) {
                // Handle page restore.
                window.location.reload();
            }
        });
    </script>
    <style>
        :root {
            --primary: #1e3a8a;
            --accent: #eab308;
            --bg: #f8fafc;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text-main);
            margin: 0;
            padding: 0;
            line-height: 1.5;
        }

        .public-wrapper {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }

        .brand-header {
            text-align: center;
            padding: 40px 0;
        }
        .brand-header img {
            max-height: 60px;
            margin-bottom: 15px;
        }
        .brand-header h1 {
            font-size: 20px;
            font-weight: 800;
            margin: 0;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: -0.02em;
        }

        .status-badge {
            display: inline-block;
            background: #e0f2fe;
            color: #0369a1;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 0;
            margin-bottom: 20px;
            text-transform: uppercase;
        }

        .v-card {
            background: #fff;
            border: 1px solid var(--border);
            padding: 24px;
            margin-bottom: 24px;
        }
        .v-card h2 {
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin: 0 0 16px 0;
            border-bottom: 1px solid var(--border);
            padding-bottom: 10px;
        }

        .info-row {
            margin-bottom: 15px;
        }
        .info-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            display: block;
            margin-bottom: 4px;
        }
        .info-value {
            font-size: 15px;
            font-weight: 700;
        }

        .dn-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px;
            background: #fff9e6;
            border: 1.5px solid #fde68a;
            color: #92400e;
            text-decoration: none;
            font-weight: 700;
            font-size: 14px;
            margin-bottom: 24px;
            transition: all 0.2s;
        }
        .dn-link:hover {
            background: #fef3c7;
            transform: translateY(-1px);
        }

        .sig-container {
            border: 1.5px solid var(--border);
            background: #fff;
            position: relative;
            touch-action: none;
            margin-bottom: 15px;
        }
        .sig-canvas {
            width: 100%;
            height: 200px;
            display: block;
            cursor: crosshair;
        }
        .sig-actions {
            padding: 10px;
            border-top: 1px solid var(--border);
            text-align: right;
        }
        .btn-clear {
            background: none;
            border: none;
            color: #ef4444;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .btn-submit {
            width: 100%;
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 18px;
            font-size: 16px;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.2s;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .btn-submit:hover {
            background: #1e3a8a;
            box-shadow: 0 4px 12px rgba(30, 58, 138, 0.2);
        }
        .btn-submit:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .success-screen {
            text-align: center;
            padding: 60px 20px;
        }
        .success-icon {
            width: 80px;
            height: 80px;
            background: #dcfce7;
            color: #166534;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }
    </style>
</head>
<body>

    <div class="public-wrapper">
        <div class="brand-header">
            <?php if ($brand['logoUrl'] !== ''): ?>
            <img src="<?= htmlspecialchars($brand['logoUrl']) ?>"
                 onerror="this.onerror=null; this.src='<?= app_url('assets/images/logo.svg') ?>';"
                 alt="<?= htmlspecialchars($brand['name']) ?>">
            <?php endif; ?>
            <h1><?= htmlspecialchars($brand['name']) ?></h1>
        </div>

        <?php if ($error): ?>
            <div style="background: #fee2e2; color: #b91c1c; padding: 15px; border-radius: 6px; margin-bottom: 20px; text-align: center; border: 1px solid #fecaca;">
                <strong>Submission Error:</strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div style="text-align: center;">
            <div class="status-badge">Awaiting Recipient Verification</div>
        </div>

        <!-- VIEW ATTACHED DOCUMENTS -->
        <?php 
        $hasFiles = $dn || $salesInvoiceId > 0 || !empty($order['invoice_file']) || !empty($order['receipt_file']) || !empty($order['package_image']);
        ?>

        <?php if ($hasFiles): ?>
            <div class="v-card">
                <h2>VIEW ATTACHED DOCUMENTS</h2>
                <div style="display:grid; grid-template-columns: 1fr; gap:10px;">
                    
                    <?php if ($dn): 
                         // Unsigned state here
                    ?>
                        <a href="view_delivery_note.php?id=<?= $dn['id'] ?>&hash=<?= $hash ?>" target="_blank" class="dn-link" style="margin-bottom:0; background:#f0f9ff; border-color:#bae6fd; color:#0369a1; justify-content: space-between;">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                <div>
                                    <div style="font-size:14px; font-weight:700;">Delivery Note</div>
                                    <div style="font-size:11px; opacity:0.8;">Sign to Enable Download</div>
                                </div>
                            </div>
                        </a>
                    <?php endif; ?>

                    <?php if ($salesInvoiceId > 0): ?>
                        <a href="public_invoice.php?id=<?= (int) $salesInvoiceId ?>&hash=<?= urlencode($hash) ?>" target="_blank" class="dn-link" style="margin-bottom:0; background:#f0fdf4; border-color:#bbf7d0; color:#15803d; justify-content: space-between;">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                                <div>
                                    <div style="font-size:14px; font-weight:700;">Invoice <?= htmlspecialchars((string) ($order['invoice_ref'] ?? '')) ?></div>
                                    <div style="font-size:11px; opacity:0.8;">View / Download</div>
                                </div>
                            </div>
                            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        </a>
                    <?php endif; ?>

                    <?php if (!empty($order['invoice_file'])): ?>
                        <a href="<?= app_url($order['invoice_file']) ?>" download target="_blank" class="dn-link" style="margin-bottom:0; background:#f0fdf4; border-color:#bbf7d0; color:#15803d; justify-content: space-between;">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                                <div>
                                    <div style="font-size:14px; font-weight:700;">Invoice</div>
                                    <div style="font-size:11px; opacity:0.8;">Download Attachment</div>
                                </div>
                            </div>
                            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        </a>
                    <?php endif; ?>

                    <?php if (!empty($order['receipt_file']) || !empty($order['package_image'])): 
                        $receiptPath = !empty($order['receipt_file']) ? $order['receipt_file'] : $order['package_image'];
                    ?>
                        <a href="<?= app_url($receiptPath) ?>" download target="_blank" class="dn-link" style="margin-bottom:0; background:#faf5ff; border-color:#e9d5ff; color:#7e22ce; justify-content: space-between;">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                <div>
                                    <div style="font-size:14px; font-weight:700;">Receipt / Proof</div>
                                    <div style="font-size:11px; opacity:0.8;">Download Attachment</div>
                                </div>
                            </div>
                            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        </a>
                    <?php endif; ?>

                </div>
            </div>
        <?php endif; ?>

        <form id="verifyForm" method="POST">
            <input type="hidden" name="signature_data" id="signature_data">
            
            <div class="v-card">
                <h2>Digital Signature</h2>
                <p style="font-size:13px; color:var(--text-muted); margin-bottom:20px;">Please sign within the box below to confirm receipt of goods.</p>
                
                <div class="input-group" style="margin-bottom: 20px;">
                    <label class="label">Recipient Name</label>
                    <input type="text" name="recipient_name" class="input" value="<?= htmlspecialchars($order['client_name']) ?>" required placeholder="Enter Your Name">
                </div>

                <div class="sig-container">
                    <canvas id="signature-pad" class="sig-canvas"></canvas>
                    <div class="sig-actions">
                        <button type="button" class="btn-clear" onclick="clearSignature()">Clear Signature</button>
                    </div>
                </div>

                <button type="submit" class="btn-submit" id="submitBtn">Verify & Sign Delivery</button>
            </div>
        </form>
    </div>

    <script>
        const canvas = document.getElementById('signature-pad');
        if (canvas) {
            const signaturePad = new SignaturePad(canvas, {
                backgroundColor: 'rgba(255, 255, 255, 0)',
                penColor: '#0f172a'
            });

            function resizeCanvas() {
                const ratio =  Math.max(window.devicePixelRatio || 1, 1);
                canvas.width = canvas.offsetWidth * ratio;
                canvas.height = canvas.offsetHeight * ratio;
                canvas.getContext("2d").scale(ratio, ratio);
                signaturePad.clear();
            }

            window.addEventListener("resize", resizeCanvas);
            resizeCanvas();

            function clearSignature() {
                signaturePad.clear();
            }

            const form = document.getElementById('verifyForm');
            form.onsubmit = (e) => {
                if (signaturePad.isEmpty()) {
                    e.preventDefault();
                    Swal.fire('Signature Required', 'Please sign the box to confirm delivery.', 'warning');
                    return false;
                }
                
                // SECURITY WORKAROUND: Extract raw base64 string
                // Firewalls often block "data:image/png;base64," pattern.
                // We strip the prefix and send only the body.
                const fullData = signaturePad.toDataURL(); // "data:image/png;base64,iVBOR..."
                const rawBase64 = fullData.split(',')[1];
                document.getElementById('signature_data').value = rawBase64;
                
                Swal.fire({
                    title: 'Verifying...',
                    allowOutsideClick: false,
                    didOpen: () => { Swal.showLoading(); }
                });
            };
        }
    </script>
</body>
</html>
