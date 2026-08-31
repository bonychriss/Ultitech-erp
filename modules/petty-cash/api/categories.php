<?php

require_once __DIR__ . '/../includes/petty-cash-lib.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    pettyCashDeskRequireAccess();
    $scope = pettyCashDeskScope();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $rows = getAllPettyCashCategories();
        $categories = [];
        foreach ($rows as $row) {
            $categories[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'code' => (string) ($row['code'] ?? ''),
                'voucher_count' => (int) ($row['voucher_count'] ?? 0),
            ];
        }
        echo json_encode([
            'can_manage' => (bool) $scope['can_manage'],
            'categories' => $categories,
            'urls' => ['desk' => pettyCashModuleUrl('index.php')],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('Category name is required.');
    }

    $created = createPettyCashCategory($name);
    if (is_int($created)) {
        echo json_encode(['ok' => true, 'id' => $created]);
        exit;
    }
    if (is_string($created) && stripos($created, 'already exists') !== false) {
        echo json_encode(['ok' => true, 'message' => $created]);
        exit;
    }
    throw new RuntimeException(is_string($created) ? $created : 'Could not create category.');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
