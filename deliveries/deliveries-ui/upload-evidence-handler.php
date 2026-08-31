<?php

declare(strict_types=1);

/**
 * Upload delivery evidence photos (shared by legacy form and React API).
 *
 * @return array{ok:bool,error?:string,data?:array}
 */
function deliveries_process_evidence_upload(PDO $pdo, int $orderId, array $files): array
{
    if (function_exists('ensureDeliveriesSchema')) {
        ensureDeliveriesSchema();
    }

    if ($orderId <= 0) {
        return ['ok' => false, 'error' => 'Missing order ID.'];
    }

    try {
        $stmt = $pdo->prepare('SELECT id FROM delivery_orders WHERE id = ?');
        $stmt->execute([$orderId]);
        if (!$stmt->fetch()) {
            return ['ok' => false, 'error' => 'Order not found.'];
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    if (empty($files['evidence_files']['name'])) {
        return ['ok' => false, 'error' => 'No photos selected.'];
    }

    $names = $files['evidence_files']['name'];
    if (!is_array($names)) {
        $names = [$names];
        $files['evidence_files'] = [
            'name' => [$files['evidence_files']['name']],
            'type' => [$files['evidence_files']['type']],
            'tmp_name' => [$files['evidence_files']['tmp_name']],
            'error' => [$files['evidence_files']['error']],
            'size' => [$files['evidence_files']['size']],
        ];
    }

    $uploadedCount = 0;
    $targetDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'evidence';
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0775, true);
    }

    $allowed = ['jpg', 'jpeg', 'png', 'webp'];

    foreach ($files['evidence_files']['name'] as $i => $name) {
        if (($files['evidence_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
        $size = (int) ($files['evidence_files']['size'][$i] ?? 0);
        if (!in_array($ext, $allowed, true) || $size > 5 * 1024 * 1024) {
            continue;
        }

        $newFilename = 'pod_' . $orderId . '_' . time() . '_' . $i . '.' . $ext;
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $newFilename;
        $dbPath = 'uploads/evidence/' . $newFilename;

        if (move_uploaded_file($files['evidence_files']['tmp_name'][$i], $targetPath)) {
            $stmtIns = $pdo->prepare(
                "INSERT INTO delivery_evidence (delivery_order_id, type, file_path, created_at) VALUES (?, 'photo_extra', ?, NOW())"
            );
            $stmtIns->execute([$orderId, $dbPath]);
            $uploadedCount++;
        }
    }

    if ($uploadedCount <= 0) {
        return ['ok' => false, 'error' => 'No valid photos were uploaded.'];
    }

    return [
        'ok' => true,
        'data' => [
            'uploadedCount' => $uploadedCount,
            'message' => "Successfully uploaded {$uploadedCount} evidence photo(s).",
        ],
    ];
}
