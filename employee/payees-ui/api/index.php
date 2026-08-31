<?php
/**
 * JSON API for the employee "Manage Payees" React page.
 * Handles list (GET) and create/edit/delete (POST) against the tenant `payees` table.
 */
require_once __DIR__ . '/../../../includes/functions.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

if (function_exists('voucher_bootstrap_operational_pdo')) {
    voucher_bootstrap_operational_pdo();
}

function payees_json($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Read a POST field either from form-encoded or JSON body. */
function payees_input(array $body, string $key, string $default = ''): string
{
    if (array_key_exists($key, $_POST)) {
        return trim((string) $_POST[$key]);
    }
    if (array_key_exists($key, $body)) {
        return trim((string) $body[$key]);
    }
    return $default;
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        payees_json(['ok' => false, 'error' => 'Database connection is not available.'], 500);
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $rows = [];
        try {
            $stmt = $pdo->query('SELECT id, name, type, tin, contact_details FROM payees WHERE is_active = 1 ORDER BY name ASC');
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $rows = [];
        }
        $payees = array_map(static function ($r) {
            return [
                'id' => (int) $r['id'],
                'name' => (string) $r['name'],
                'type' => (string) ($r['type'] ?? 'Other'),
                'tin' => (string) ($r['tin'] ?? ''),
                'contact_details' => (string) ($r['contact_details'] ?? ''),
            ];
        }, $rows);
        payees_json(['ok' => true, 'payees' => $payees]);
    }

    if ($method !== 'POST') {
        payees_json(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    $rawBody = file_get_contents('php://input') ?: '';
    $body = [];
    if ($rawBody !== '') {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $body = $decoded;
        }
    }

    $action = payees_input($body, 'action', 'create');

    if ($action === 'delete') {
        $id = (int) payees_input($body, 'id', '0');
        if ($id <= 0) {
            payees_json(['ok' => false, 'error' => 'Invalid payee.'], 422);
        }
        $stmt = $pdo->prepare('UPDATE payees SET is_active = 0 WHERE id = ?');
        $stmt->execute([$id]);
        payees_json(['ok' => true, 'message' => 'Payee removed successfully.']);
    }

    if ($action === 'create' || $action === 'edit') {
        $name = payees_input($body, 'name');
        $type = payees_input($body, 'type', 'Other');
        $tin = payees_input($body, 'tin');
        $contact = payees_input($body, 'contact_details');

        if ($name === '') {
            payees_json(['ok' => false, 'error' => 'Payee name is required.'], 422);
        }

        if ($action === 'create') {
            $chk = $pdo->prepare('SELECT id FROM payees WHERE name = ? AND is_active = 1');
            $chk->execute([$name]);
            if ($chk->fetch()) {
                payees_json(['ok' => false, 'error' => 'A payee with this name already exists.'], 409);
            }
            $stmt = $pdo->prepare('INSERT INTO payees (name, type, tin, contact_details) VALUES (?, ?, ?, ?)');
            $stmt->execute([$name, $type, $tin, $contact]);
            $id = (int) $pdo->lastInsertId();

            if ($type === 'Supplier') {
                try {
                    $chk = $pdo->prepare('SELECT id FROM stocks_suppliers WHERE name = ?');
                    $chk->execute([$name]);
                    if (!$chk->fetchColumn()) {
                        $pdo->prepare('INSERT INTO stocks_suppliers (name, contact_details) VALUES (?, ?)')
                            ->execute([$name, $contact]);
                    }
                } catch (Throwable $e) { /* non-fatal */ }
            }

            payees_json([
                'ok' => true,
                'message' => 'Payee added successfully.',
                'payee' => ['id' => $id, 'name' => $name, 'type' => $type, 'tin' => $tin, 'contact_details' => $contact],
            ]);
        }

        // edit
        $id = (int) payees_input($body, 'id', '0');
        if ($id <= 0) {
            payees_json(['ok' => false, 'error' => 'Invalid payee.'], 422);
        }
        $stmt = $pdo->prepare('UPDATE payees SET name = ?, type = ?, tin = ?, contact_details = ? WHERE id = ?');
        $stmt->execute([$name, $type, $tin, $contact, $id]);

        if ($type === 'Supplier') {
            try {
                $chk = $pdo->prepare('SELECT id FROM stocks_suppliers WHERE name = ?');
                $chk->execute([$name]);
                $sId = $chk->fetchColumn();
                if ($sId) {
                    $pdo->prepare('UPDATE stocks_suppliers SET contact_details = ? WHERE id = ?')->execute([$contact, $sId]);
                } else {
                    $pdo->prepare('INSERT INTO stocks_suppliers (name, contact_details) VALUES (?, ?)')->execute([$name, $contact]);
                }
            } catch (Throwable $e) { /* non-fatal */ }
        }

        payees_json([
            'ok' => true,
            'message' => 'Payee updated successfully.',
            'payee' => ['id' => $id, 'name' => $name, 'type' => $type, 'tin' => $tin, 'contact_details' => $contact],
        ]);
    }

    payees_json(['ok' => false, 'error' => 'Unknown action.'], 400);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    if (stripos($msg, 'Duplicate entry') !== false) {
        payees_json(['ok' => false, 'error' => 'A payee with this name already exists.'], 409);
    }
    payees_json(['ok' => false, 'error' => 'Something went wrong. Please try again.'], 500);
}
