<?php
/**
 * Payroll settings actions (global settings, tax bands, rules).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/payroll-lib.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = payrollDeskBootstrap();
    payrollDeskRequireFinanceOrAdmin();

    $raw = file_get_contents('php://input');
    $payload = [];
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }
    if ($payload === [] && !empty($_POST)) {
        $payload = $_POST;
    }

    $action = strtolower(trim((string) ($payload['action'] ?? '')));
    $result = match ($action) {
        'save_settings' => payrollDeskSaveGlobalSettings($pdo, $payload),
        'save_tax_band' => payrollDeskSaveTaxBand($pdo, $payload),
        'delete_tax_band' => payrollDeskDeleteTaxBand($pdo, (int) ($payload['id'] ?? 0)),
        'save_rule' => payrollDeskSaveRule($pdo, $payload),
        'delete_rule' => payrollDeskDeleteRule($pdo, (int) ($payload['id'] ?? 0)),
        default => throw new InvalidArgumentException('Unknown settings action.'),
    };

    $json = json_encode([
        'success' => true,
        'message' => $result['message'],
        'data' => $result['data'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        throw new RuntimeException('Failed to encode settings response: ' . json_last_error_msg());
    }
    echo $json;
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    $code = str_contains($msg, 'Access denied') ? 403 : 500;
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
}
