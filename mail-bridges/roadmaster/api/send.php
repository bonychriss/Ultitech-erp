<?php

require_once __DIR__ . '/../src/bootstrap.php';

Auth::requireApiKey($CONFIG);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    JsonResponse::error('POST required.', 405);
}

$body = bridge_json_body();
$to = trim((string) (isset($body['to']) ? $body['to'] : ''));
$subject = trim((string) (isset($body['subject']) ? $body['subject'] : ''));
$html = '';
if (isset($body['body'])) {
    $html = (string) $body['body'];
} elseif (isset($body['html'])) {
    $html = (string) $body['html'];
}
$isHtml = !isset($body['is_html']) || (bool) $body['is_html'];
$fromName = isset($body['from_name']) ? trim((string) $body['from_name']) : null;

if ($to === '' || $subject === '' || $html === '') {
    JsonResponse::error('Fields required: to, subject, body');
}

try {
    $smtp = new SmtpService($CONFIG);
    $result = $smtp->send($to, $subject, $html, $isHtml, null, $fromName);
    JsonResponse::ok(array('result' => $result), 'sent');
} catch (Exception $e) {
    JsonResponse::error($e->getMessage(), 500);
}
