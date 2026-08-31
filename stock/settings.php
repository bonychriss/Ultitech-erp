<?php
// session_start();
require_once 'config/database.php';
require_once 'config/functions.php';
requireLogin();

// Ensure company_logo column exists (for logo upload from settings)
try {
    $col = $pdo->query("SHOW COLUMNS FROM company_settings LIKE 'company_logo'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE company_settings ADD COLUMN company_logo VARCHAR(255) NULL");
    }
} catch (Exception $e) { /* table may not exist yet */ }

// API: return or accept JSON when ?api=1 is present
if (isset($_GET['api']) && $_GET['api']) {
    header('Content-Type: application/json');
    try {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $settings = $pdo->query("SELECT * FROM company_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if (!$settings) {
                $settings = [
                    'company_name' => '', 'company_logo' => '', 'phone' => '', 'email' => '', 'address' => '', 
                    'city' => '', 'country' => '', 'bank_details' => '', 'terms_and_conditions' => '',
                    'currency' => 'USD', 'default_payment_terms' => 'Net 30'
                ];
            }
            if (!isset($settings['company_logo'])) $settings['company_logo'] = '';
            echo json_encode(['ok' => true, 'settings' => $settings]);
            exit;
        }

        // Logo file upload (multipart POST)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['logo']['name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($_FILES['logo']['tmp_name']);
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($mime, $allowed)) {
                echo json_encode(['ok' => false, 'error' => 'Invalid image type. Use JPEG, PNG, GIF or WebP.']);
                exit;
            }
            $ext = (strpos($mime, 'png') !== false) ? 'png' : ((strpos($mime, 'gif') !== false) ? 'gif' : ((strpos($mime, 'webp') !== false) ? 'webp' : 'jpg'));
            $filename = 'company-logo.' . $ext;
            $targetDir = dirname(__DIR__) . '/assets/images/';
            if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);
            $targetPath = $targetDir . $filename;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $targetPath)) {
                $chk = $pdo->query("SELECT id FROM company_settings LIMIT 1")->fetch();
                if ($chk) {
                    $stmt = $pdo->prepare("UPDATE company_settings SET company_logo = ? WHERE id = 1");
                    $stmt->execute([$filename]);
                } else {
                    $pdo->exec("INSERT INTO company_settings (company_name, company_logo) VALUES ('', " . $pdo->quote($filename) . ")");
                }
                echo json_encode(['ok' => true, 'company_logo' => $filename, 'message' => 'Logo uploaded']);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Failed to save logo file.']);
            }
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            if (!is_array($data)) throw new Exception('Invalid JSON payload');

            // Upsert logic
            $chk = $pdo->query("SELECT id FROM company_settings LIMIT 1")->fetch();
            if ($chk) {
                $sql = "UPDATE company_settings SET 
                    company_name = ?, phone = ?, email = ?, address = ?, city = ?, country = ?, bank_details = ?, terms_and_conditions = ?, currency = ?, default_payment_terms = ?
                    WHERE id = 1";
            } else {
                $sql = "INSERT INTO company_settings (company_name, phone, email, address, city, country, bank_details, terms_and_conditions, currency, default_payment_terms) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $data['company_name'] ?? '',
                $data['phone'] ?? '',
                $data['email'] ?? '',
                $data['address'] ?? '',
                $data['city'] ?? '',
                $data['country'] ?? '',
                $data['bank_details'] ?? '',
                $data['terms_and_conditions'] ?? '',
                $data['currency'] ?? 'USD',
                $data['default_payment_terms'] ?? 'Net 30'
            ]);

            echo json_encode(['ok' => true, 'message' => 'Settings saved']);
            exit;
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Fetch Current Settings
$settings = $pdo->query("SELECT * FROM company_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$settings) {
    $settings = [
        'company_name' => '', 'company_logo' => '', 'phone' => '', 'email' => '', 'address' => '',
        'city' => '', 'country' => '', 'bank_details' => '', 'terms_and_conditions' => '',
        'currency' => 'USD', 'default_payment_terms' => 'Net 30'
    ];
}
if (!isset($settings['company_logo'])) $settings['company_logo'] = '';

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';
$page_title = 'Company Settings';
include 'includes/header.php';
?>
<main class="main-content">
    <div class="stock-container">
        <script>
            window.__STOCK_PAGE__ = <?= json_encode([
                'page' => 'settings',
                'data' => [
                    'settings' => $settings,
                    'baseUrl' => $stockBasePath,
                    'apiUrl' => $stockBasePath . 'settings.php?api=1',
                    'assetsImagesUrl' => $rootPath . 'assets/images/',
                    'dashboardUrl' => $stockBasePath . 'dashboard.php',
                    'success' => $success,
                    'error' => $error,
                ]
            ]) ?>;
        </script>
        <link rel="stylesheet" href="<?= htmlspecialchars($stockBasePath) ?>stock-ui/dist/assets/stock-ui.css">
        <div id="root"></div>
        <script type="module" src="<?= htmlspecialchars($stockBasePath) ?>stock-ui/dist/assets/stock-ui.js"></script>
    </div>
</main>
<?php include 'includes/footer.php'; ?>
