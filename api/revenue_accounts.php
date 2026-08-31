<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/revenue_account_helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

requireLogin();
if (!isFinance() && !isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$action = trim((string) ($_POST['action'] ?? ''));

try {
    global $pdo;
    revenue_ensure_account_schema($pdo);
    revenue_ensure_default_accounts($pdo);

    if ($action === 'list_main' || $action === 'list_categories') {
        echo json_encode(['success' => true, 'accounts' => revenue_fetch_categories($pdo)]);
        exit;
    }

    if ($action === 'list_sub') {
        $parentId = (int) ($_POST['parent_id'] ?? 0);
        echo json_encode(['success' => true, 'accounts' => revenue_fetch_account_options($pdo, $parentId)]);
        exit;
    }

    if ($action === 'next_code') {
        $parentId = !empty($_POST['parent_id']) ? (int) $_POST['parent_id'] : null;
        echo json_encode(['success' => true, 'code' => revenue_next_gl_code($pdo, $parentId)]);
        exit;
    }

    if ($action === 'create_main' || $action === 'create_sub') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $parentId = ($action === 'create_sub') ? (int) ($_POST['parent_id'] ?? 0) : null;

        if ($action === 'create_sub' && $parentId <= 0) {
            throw new InvalidArgumentException('Select a main revenue account first.');
        }

        $created = revenue_create_gl_account(
            $pdo,
            $name,
            $parentId ?: null,
            $description !== '' ? $description : null
        );

        echo json_encode([
            'success' => true,
            'message' => ($action === 'create_main') ? 'Main revenue account created.' : 'Sub-account created.',
            'account' => $created,
        ]);
        exit;
    }

    throw new InvalidArgumentException('Invalid action');
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
