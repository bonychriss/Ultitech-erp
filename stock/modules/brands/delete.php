<?php
// stock/modules/brands/delete.php
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

if (isset($_GET['id'])) {
    $id = (int) $_GET['id'];

    $stmt = $pdo->prepare('SELECT logo FROM brands WHERE id = ?');
    $stmt->execute([$id]);
    $brand = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($brand) {
        $logo = (string) ($brand['logo'] ?? '');
        if ($logo !== '') {
            $paths = [
                __DIR__ . '/../../uploads/brands/' . $logo,
            ];
            if (function_exists('stock_brand_upload_dir')) {
                $paths[] = rtrim(str_replace('\\', '/', (string) stock_brand_upload_dir()), '/') . '/' . $logo;
            }
            foreach ($paths as $logoPath) {
                if (is_file($logoPath)) {
                    @unlink($logoPath);
                }
            }
        }

        $pdo->prepare('DELETE FROM brands WHERE id = ?')->execute([$id]);
    }
}

header('Location: index.php?delete=success');
exit;
