<?php

declare(strict_types=1);

function sms_label_allowed_per_page(): array
{
    return [1, 2, 4, 6, 8];
}

function sms_label_resolve_per_page($value): int
{
    $perPage = (int) $value;
    if (!in_array($perPage, sms_label_allowed_per_page(), true)) {
        return 1;
    }

    return $perPage;
}

function sms_label_begin_download_request(): ?string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }

    $now = time();
    $lastFinished = (int) ($_SESSION['sms_label_last_download_at'] ?? 0);
    if ($lastFinished > 0 && ($now - $lastFinished) < 4) {
        return 'Please wait a few seconds before generating another PDF.';
    }

    $lockAt = (int) ($_SESSION['sms_label_download_lock_at'] ?? 0);
    if ($lockAt > 0 && ($now - $lockAt) < 120) {
        return 'A PDF is already being generated. Please wait for it to finish.';
    }

    $_SESSION['sms_label_download_lock_at'] = $now;

    return null;
}

function sms_label_finish_download_request(bool $success): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    unset($_SESSION['sms_label_download_lock_at']);
    if ($success) {
        $_SESSION['sms_label_last_download_at'] = time();
    }
}

function sms_label_image_disk_path(int $productId, string $filename, string $size = 'large'): string
{
    if ($productId < 1 || !function_exists('stock_resolve_product_image_file') || !function_exists('stock_image_company_context')) {
        return '';
    }

    $size = in_array($size, ['thumbnail', 'medium', 'large', 'original'], true) ? $size : 'large';
    $raw = trim(str_replace('\\', '/', $filename));
    if (preg_match('#^https?://#i', $raw)) {
        $raw = basename(parse_url($raw, PHP_URL_PATH) ?: '');
    }
    if (preg_match('#(?:^|/)products/\d+/(?:thumbnail|medium|large|original)/(.+)$#i', $raw, $matches)) {
        $raw = $matches[1];
    }
    $basename = basename($raw);

    $ctx = stock_image_company_context();
    $disk = stock_resolve_product_image_file($productId, $size, $basename, $ctx['slug'], (int) $ctx['company_id']);
    if (($disk === null || !is_file($disk)) && $basename !== '') {
        $disk = stock_resolve_product_image_file($productId, $size, '', $ctx['slug'], (int) $ctx['company_id']);
    }

    return ($disk !== null && is_file($disk)) ? (string) $disk : '';
}

function sms_label_exec_available(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    if (!function_exists('exec')) {
        $available = false;

        return false;
    }

    $disabled = strtolower((string) ini_get('disable_functions'));
    $disabledList = array_filter(array_map('trim', explode(',', $disabled)));
    $available = !in_array('exec', $disabledList, true);

    return $available;
}

function sms_find_python_binary(): string
{
    if (!sms_label_exec_available()) {
        return '';
    }

    $candidates = [
        getenv('SMS_PYTHON_BIN') ?: '',
        'python3',
        'python',
        'py -3',
    ];

    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate === '') {
            continue;
        }

        $output = [];
        $exitCode = 1;
        @exec($candidate . ' --version 2>&1', $output, $exitCode);
        $joined = implode(' ', $output);
        if ($exitCode === 0 && stripos($joined, 'python') !== false) {
            return $candidate;
        }
    }

    return '';
}

/**
 * @return array{labels: array<int, array{code: string, name: string, image_path: string}>, per_page: int, pages: array<int, array<int, array{code: string, name: string, image_path: string}>>}
 */
function sms_build_label_payload(PDO $pdo, array $productIds, array $quantities, int $perPage, string $stockBasePath = ''): array
{
    $productIds = array_values(array_filter(array_map('intval', $productIds), static function ($id) {
        return (int) $id > 0;
    }));
    if (empty($productIds)) {
        throw new RuntimeException('No products selected.');
    }

    $perPage = sms_label_resolve_per_page($perPage);
    $imageSize = $perPage === 1 ? 'large' : 'medium';

    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $imageSql = function_exists('stock_product_main_image_sql')
        ? stock_product_main_image_sql($pdo, 'p')
        : 'p.main_image';

    $sql = "SELECT p.id, p.product_code, p.name, c.name AS category_name, {$imageSql} AS image_file
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.id IN ({$placeholders})
            ORDER BY p.name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($productIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $labels = [];
    foreach ($rows as $row) {
        $pid = (int) $row['id'];
        $qty = max(1, min(99, (int) ($quantities[$pid] ?? 1)));
        $imagePath = sms_label_image_disk_path($pid, (string) ($row['image_file'] ?? ''), $imageSize);

        $label = [
            'code' => (string) ($row['product_code'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'category' => (string) ($row['category_name'] ?? ''),
            'image_path' => $imagePath,
        ];

        for ($i = 0; $i < $qty; $i++) {
            $labels[] = $label;
        }
    }

    if (empty($labels)) {
        throw new RuntimeException('No labels to generate.');
    }

    $pages = array_chunk($labels, $perPage);

    return [
        'labels' => $labels,
        'per_page' => $perPage,
        'pages' => $pages,
    ];
}

function sms_generate_label_pdf_via_python(array $payload): string
{
    $script = __DIR__ . '/labels/generate_labels.py';
    if (!is_file($script)) {
        throw new RuntimeException('Label PDF generator is missing.');
    }

    $python = sms_find_python_binary();
    if ($python === '') {
        throw new RuntimeException('Python is not available on this server.');
    }

    $inputFile = tempnam(sys_get_temp_dir(), 'sms_lbl_in_');
    $outputFile = tempnam(sys_get_temp_dir(), 'sms_lbl_out_') . '.pdf';
    if ($inputFile === false || $outputFile === '') {
        throw new RuntimeException('Unable to create temporary files for PDF generation.');
    }

    $jsonPayload = json_encode([
        'per_page' => (int) ($payload['per_page'] ?? 1),
        'labels' => $payload['labels'] ?? [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($jsonPayload === false) {
        @unlink($inputFile);
        throw new RuntimeException('Failed to encode label data.');
    }

    file_put_contents($inputFile, $jsonPayload);

    $command = $python . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($inputFile) . ' ' . escapeshellarg($outputFile) . ' 2>&1';
    $output = [];
    $exitCode = 1;
    exec($command, $output, $exitCode);

    @unlink($inputFile);

    if ($exitCode !== 0 || !is_file($outputFile)) {
        @unlink($outputFile);
        $message = trim(implode("\n", $output));
        if ($message === '') {
            $message = 'Python PDF generation failed. Ensure Python and reportlab are installed.';
        }
        throw new RuntimeException($message);
    }

    $binary = file_get_contents($outputFile);
    @unlink($outputFile);

    if ($binary === false || $binary === '') {
        throw new RuntimeException('Generated PDF file is empty.');
    }

    return $binary;
}

function sms_generate_label_pdf_binary(array $payload): string
{
    $errors = [];

    if (sms_find_python_binary() !== '') {
        try {
            return sms_generate_label_pdf_via_python($payload);
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }

    require_once __DIR__ . '/labels/generate_labels_php.php';

    try {
        return sms_generate_label_pdf_via_php($payload);
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    $message = $errors !== [] ? (string) end($errors) : 'PDF generation failed.';
    if (count($errors) > 1) {
        $message = 'PDF generation failed. ' . $message;
    }

    throw new RuntimeException($message);
}

function sms_label_company_id(): int
{
    if (function_exists('stock_image_company_context')) {
        $ctx = stock_image_company_context();

        return (int) ($ctx['company_id'] ?? 0);
    }

    return (int) ($_SESSION['company_id'] ?? 0);
}

function sms_label_user_id(): int
{
    return (int) ($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
}

function sms_ensure_label_placements_table(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS product_label_placements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL DEFAULT 0,
        product_id INT NOT NULL,
        placed_by INT NULL,
        placed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_company_product (company_id, product_id),
        INDEX idx_company (company_id),
        INDEX idx_product (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $ready = true;
}

function sms_toggle_label_placed(PDO $pdo, int $productId, bool $placed): bool
{
    sms_ensure_label_placements_table($pdo);

    $companyId = sms_label_company_id();
    $userId = sms_label_user_id();

    if ($productId < 1) {
        throw new RuntimeException('Invalid product.');
    }

    if ($placed) {
        $stmt = $pdo->prepare('INSERT INTO product_label_placements (company_id, product_id, placed_by)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE placed_by = VALUES(placed_by), updated_at = CURRENT_TIMESTAMP');
        $stmt->execute([$companyId, $productId, $userId > 0 ? $userId : null]);

        return true;
    }

    $stmt = $pdo->prepare('DELETE FROM product_label_placements WHERE company_id = ? AND product_id = ?');
    $stmt->execute([$companyId, $productId]);

    return false;
}

function sms_count_label_placed(PDO $pdo): int
{
    sms_ensure_label_placements_table($pdo);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM product_label_placements WHERE company_id = ?');
    $stmt->execute([sms_label_company_id()]);

    return (int) $stmt->fetchColumn();
}

/**
 * @return array<int, array<string, mixed>>
 */
function sms_fetch_label_products(PDO $pdo, array $filters = []): array
{
    sms_ensure_label_placements_table($pdo);

    $search = trim((string) ($filters['search'] ?? ''));
    $categoryId = (int) ($filters['category_id'] ?? 0);
    $placedFilter = (string) ($filters['placed'] ?? 'all');
    if (!in_array($placedFilter, ['all', 'placed', 'unplaced'], true)) {
        $placedFilter = 'all';
    }

    $labelCompanyId = sms_label_company_id();
    $imageSql = function_exists('stock_product_main_image_sql')
        ? stock_product_main_image_sql($pdo, 'p')
        : 'p.main_image';

    $where = 'WHERE 1=1';
    $params = [];
    if ($search !== '') {
        $wildcard = '%' . $search . '%';
        try {
            $productCols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            $productCols = [];
        }
        if (in_array('sku', $productCols, true)) {
            $where .= ' AND (p.name LIKE ? OR p.product_code LIKE ? OR p.sku LIKE ?)';
            $params[] = $wildcard;
            $params[] = $wildcard;
            $params[] = $wildcard;
        } else {
            $where .= ' AND (p.name LIKE ? OR p.product_code LIKE ?)';
            $params[] = $wildcard;
            $params[] = $wildcard;
        }
    }
    if ($categoryId > 0) {
        $where .= ' AND p.category_id = ?';
        $params[] = $categoryId;
    }
    if ($placedFilter === 'placed') {
        $where .= ' AND plp.id IS NOT NULL';
    } elseif ($placedFilter === 'unplaced') {
        $where .= ' AND plp.id IS NULL';
    }

    $sql = "SELECT p.id, p.product_code, p.name, {$imageSql} AS image_file, c.name AS category_name,
                   CASE WHEN plp.id IS NULL THEN 0 ELSE 1 END AS label_placed
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN product_label_placements plp
                ON plp.product_id = p.id AND plp.company_id = ?
            {$where}
            ORDER BY label_placed ASC, p.name ASC
            LIMIT 500";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$labelCompanyId], $params));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stockBasePath = function_exists('app_url') ? app_url('stock/') : '../stock/';

    return array_map(static function (array $row) use ($stockBasePath): array {
        $pid = (int) ($row['id'] ?? 0);
        $imageFile = (string) ($row['image_file'] ?? '');
        $imageUrl = '';
        if ($pid > 0 && function_exists('stock_product_list_image_url')) {
            $imageUrl = (string) stock_product_list_image_url($pid, $imageFile, 'medium', $stockBasePath);
        }

        return [
            'id' => (string) $pid,
            'productCode' => (string) ($row['product_code'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'categoryName' => (string) ($row['category_name'] ?? ''),
            'imageUrl' => $imageUrl,
            'labelPlaced' => (int) ($row['label_placed'] ?? 0) === 1,
        ];
    }, $rows);
}
