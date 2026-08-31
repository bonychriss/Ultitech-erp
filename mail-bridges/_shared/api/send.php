<?php

require_once __DIR__ . '/../src/bootstrap.php';

Auth::requireApiKey($CONFIG);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    JsonResponse::error('POST required.', 405);
}

$body = bridge_json_body();
$to = trim((string) ($body['to'] ?? ''));
$subject = trim((string) ($body['subject'] ?? ''));
$html = (string) ($body['body'] ?? $body['html'] ?? '');
$isHtml = !isset($body['is_html']) || (bool) $body['is_html'];
$fromName = isset($body['from_name']) ? trim((string) $body['from_name']) : null;

if ($to === '' || $subject === '' || $html === '') {
    JsonResponse::error('Fields required: to, subject, body');
}

try {
    $smtp = new SmtpService($CONFIG);
    $result = $smtp->send($to, $subject, $html, $isHtml, null, $fromName);
    JsonResponse::ok(['result' => $result], 'sent');
} catch (Throwable $e) {
    JsonResponse::error($e->getMessage(), 500);
}
