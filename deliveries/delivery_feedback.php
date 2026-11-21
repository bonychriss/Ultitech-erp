<?php

declare(strict_types=1);

/**
 * Public mobile page: leave a delivery review + browse similar products.
 * Opened from the QR code printed on the delivery note.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/deliveries-ui/load-data.php';
require_once __DIR__ . '/deliveries-ui/delivery-note-invoice.php';
require_once __DIR__ . '/deliveries-ui/includes/delivery-feedback-lib.php';
require_once __DIR__ . '/deliveries-ui/includes/delivery-feedback-i18n.php';

// Hardening headers for the public feedback surface.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self' https: data: blob:; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' https: data: blob:; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");

if (function_exists('sales_load_stock_image_helpers')) {
    sales_load_stock_image_helpers();
}

$noteId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$token = isset($_GET['t']) ? trim((string) $_GET['t']) : '';
// Reject obviously malformed tokens early.
if ($token !== '' && !preg_match('/^[a-f0-9]{32,64}$/i', $token)) {
    http_response_code(400);
    echo 'Invalid feedback link.';
    exit;
}
$pdoDb = $pdo instanceof PDO ? $pdo : null;
$langCode = deliveries_feedback_resolve_lang(
    isset($_POST['lang']) ? (string) $_POST['lang'] : (isset($_GET['lang']) ? (string) $_GET['lang'] : null)
);
$langPack = deliveries_feedback_lang_pack($langCode);
$L = $langPack['strings'];
$langDir = $langPack['dir'];
@setcookie('dn_feedback_lang', $langCode, [
    'expires' => time() + 60 * 60 * 24 * 365,
    'path' => '/',
    'samesite' => 'Lax',
]);

if (!($pdoDb instanceof PDO) || $noteId <= 0 || $token === '') {
    http_response_code(400);
    echo 'Invalid feedback link.';
    exit;
}

$flashError = '';
$flashOk = false;
$submittedFollowUp = '';
$inquiryOk = false;
$inquiryError = '';
$inquiryWhatsapp = '';
$inquiryProduct = null;

$payload = deliveries_feedback_load($pdoDb, $noteId, $token);
if (empty($payload['ok'])) {
    http_response_code(403);
    echo htmlspecialchars((string) ($payload['error'] ?? 'Access denied.'), ENT_QUOTES, 'UTF-8');
    exit;
}

$note = $payload['note'];
$brand = $payload['brand'] ?? [];
$similar = $payload['similar'] ?? [];
$deliveryItems = $payload['items'] ?? [];
$feedback = $payload['feedback'] ?? ['saved' => false, 'canSubmit' => true, 'rating' => null, 'comment' => ''];

$companyName = trim((string) ($brand['name'] ?? 'Our company'));
$logoUrl = trim((string) ($brand['logoUrl'] ?? ''));
$companyPhone = trim((string) ($brand['phone'] ?? ''));
$companyWebsite = trim((string) ($brand['website'] ?? ''));
$companyWebsiteHref = $companyWebsite;
if ($companyWebsiteHref !== '' && !preg_match('#^https?://#i', $companyWebsiteHref)) {
    $companyWebsiteHref = 'https://' . ltrim($companyWebsiteHref, '/');
}
$companyWebsiteLabel = $companyWebsite !== ''
    ? rtrim((string) preg_replace('#^https?://#i', '', $companyWebsite), '/')
    : $companyName;
$noteNumber = trim((string) ($note['note_number'] ?? ''));
$customerName = trim((string) ($note['customer_name'] ?? 'Customer'));
$canSubmit = !empty($feedback['canSubmit']);
$savedRating = (int) ($feedback['rating'] ?? 0);
$showThanks = false;

$formNonce = deliveries_feedback_form_nonce($noteId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedNonce = trim((string) ($_POST['fb_nonce'] ?? ''));
    $abuseBlocked = deliveries_feedback_honeypot_tripped($_POST)
        || !deliveries_feedback_form_nonce_valid($noteId, $postedNonce);

    $formAction = trim((string) ($_POST['form_action'] ?? 'review'));
    if ($abuseBlocked) {
        // Fail closed without tipping off bots.
        if ($formAction === 'inquiry') {
            $inquiryError = 'Could not save your inquiry. Please refresh and try again.';
        } else {
            $flashError = 'Could not save your review. Please refresh and try again.';
        }
    } elseif ($formAction === 'inquiry') {
        $result = deliveries_feedback_save_inquiry(
            $pdoDb,
            $noteId,
            $token,
            (int) ($_POST['product_id'] ?? 0),
            (string) ($_POST['product_name'] ?? ''),
            (string) ($_POST['product_code'] ?? ''),
            (int) ($_POST['qty'] ?? 1),
            (string) ($_POST['inquiry_message'] ?? ''),
            $companyPhone
        );
        if (!empty($result['ok'])) {
            $inquiryOk = true;
            $inquiryWhatsapp = (string) ($result['whatsapp_url'] ?? '');
            $inquiryProduct = [
                'id' => (int) ($_POST['product_id'] ?? 0),
                'name' => deliveries_feedback_sanitize_user_text((string) ($_POST['product_name'] ?? ''), 180),
                'code' => deliveries_feedback_sanitize_user_text((string) ($_POST['product_code'] ?? ''), 80),
                'image' => '',
                'qty' => max(1, (int) ($_POST['qty'] ?? 1)),
                'message' => deliveries_feedback_sanitize_user_text((string) ($_POST['inquiry_message'] ?? ''), 1000),
            ];
        } else {
            $inquiryError = (string) ($result['error'] ?? 'Could not save your inquiry.');
        }
    } else {
        $rating = isset($_POST['rating']) ? (int) $_POST['rating'] : 0;
        $comment = trim((string) ($_POST['comment'] ?? ''));
        $followUp = strtolower(trim((string) ($_POST['follow_up'] ?? '')));
        if ($followUp === 'yes' || $followUp === 'no') {
            $followLine = 'Follow up: ' . ucfirst($followUp);
            $comment = $comment !== '' ? ($comment . "\n\n" . $followLine) : $followLine;
        }
        $result = deliveries_feedback_save($pdoDb, $noteId, $token, $rating, $comment);
        if (!empty($result['ok'])) {
            $flashOk = true;
            $submittedFollowUp = ($followUp === 'yes') ? 'yes' : (($followUp === 'no') ? 'no' : '');
            $feedback = [
                'saved' => true,
                'canSubmit' => false,
                'rating' => $rating,
                'comment' => $comment,
            ];
            $canSubmit = false;
            $savedRating = $rating;
        } else {
            $flashError = (string) ($result['error'] ?? 'Could not save your review.');
        }
    }
    // Rotate nonce after each POST attempt.
    $formNonce = deliveries_feedback_form_nonce($noteId);
}

// Keep the same thank-you layout after language switch (GET reload).
$showThanks = $flashOk || (!$canSubmit && $savedRating > 0);

function dn_feedback_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function dn_feedback_money(float $amount, string $currency): string
{
    return $currency . ' ' . number_format($amount, 0);
}

/**
 * Build up to 4 PPE slides: similar category products first, then fill from this delivery's lines.
 *
 * @param list<array<string,mixed>> $similar
 * @param list<array<string,mixed>> $items
 * @return list<array<string,mixed>>
 */
function dn_feedback_slider_products(array $similar, array $items): array
{
    $slides = [];
    $seen = [];
    $push = static function (array $row) use (&$slides, &$seen): void {
        if (count($slides) >= 4) {
            return;
        }
        $id = (int) ($row['id'] ?? 0);
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            return;
        }
        $key = $id > 0 ? 'id:' . $id : 'name:' . mb_strtolower($name);
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $words = preg_split('/\s+/', $name) ?: [$name];
        $short = (string) ($words[0] ?? $name);
        if (mb_strlen($short) < 3 && isset($words[1])) {
            $short = $short . ' ' . $words[1];
        }
        if (mb_strlen($short) > 14) {
            $short = mb_substr($short, 0, 14);
        }
        $slides[] = [
            'id' => $id,
            'name' => $name,
            'short' => $short,
            'category_name' => (string) ($row['category_name'] ?? ''),
            'brand' => (string) ($row['brand'] ?? ''),
            'description' => trim((string) ($row['description'] ?? '')),
            'unit_price' => (float) ($row['unit_price'] ?? 0),
            'currency' => (string) ($row['currency'] ?? 'TZS'),
            'image_url' => (string) ($row['image_url'] ?? ''),
            'product_code' => (string) ($row['product_code'] ?? ''),
        ];
    };

    foreach ($similar as $row) {
        $push($row);
    }

    if (count($slides) < 2) {
        foreach ($items as $item) {
            $pid = (int) ($item['product_id'] ?? 0);
            $name = trim((string) ($item['product_name'] ?? $item['name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($item['description'] ?? ''));
            }
            $img = '';
            if ($pid > 0 && function_exists('stock_product_list_image_url')) {
                $file = trim((string) ($item['main_image'] ?? $item['image'] ?? ''));
                if ($file !== '') {
                    $img = (string) stock_product_list_image_url($pid, $file, 'large');
                }
            }
            $push([
                'id' => $pid,
                'name' => $name,
                'category_name' => (string) ($item['category_name'] ?? 'PPE'),
                'brand' => (string) ($item['brand'] ?? ''),
                'description' => trim((string) ($item['product_description'] ?? $item['description'] ?? '')),
                'unit_price' => (float) ($item['unit_price'] ?? $item['price'] ?? 0),
                'currency' => (string) ($item['currency'] ?? 'TZS'),
                'image_url' => $img,
                'product_code' => (string) ($item['product_code'] ?? $item['code'] ?? ''),
            ]);
        }
    }

    return $slides;
}

$sliderProducts = dn_feedback_slider_products(is_array($similar) ? $similar : [], is_array($deliveryItems) ? $deliveryItems : []);
$sliderColors = ['#ffffff', '#ffffff', '#ffffff', '#ffffff'];
$inquiryProduct = $inquiryProduct ?? null;
if (is_array($inquiryProduct) && ($inquiryProduct['image'] ?? '') === '' && !empty($inquiryProduct['id'])) {
    foreach ($sliderProducts as $sp) {
        if ((int) ($sp['id'] ?? 0) === (int) $inquiryProduct['id']) {
            $inquiryProduct['image'] = (string) ($sp['image_url'] ?? '');
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= dn_feedback_h($langCode) ?>" dir="<?= dn_feedback_h($langDir) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow">
    <title>Feedback · <?= dn_feedback_h($noteNumber !== '' ? $noteNumber : 'Delivery') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,550;9..144,650&family=Poppins:wght@500;600;700&family=Source+Sans+3:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: #111827;
            --muted: #4b5563;
            --line: #dbe3ee;
            --bg: #fff;
            --card: #fff;
            --brand: #2563eb;
            --brand-hover: #1d4ed8;
            --mood: #f5a524;
            --mood-ring: #e8eefc;
        }
        * { box-sizing: border-box; }
        .fb-hp {
            position: absolute !important;
            left: -10000px !important;
            top: auto !important;
            width: 1px !important;
            height: 1px !important;
            overflow: hidden !important;
            opacity: 0 !important;
            pointer-events: none !important;
        }
        html, body {
            margin: 0;
            background: #fff;
            background-color: #fff;
        }
        body {
            font-family: "Source Sans 3", system-ui, -apple-system, "Segoe UI", sans-serif;
            color: var(--ink);
            line-height: 1.45;
            min-height: 100%;
        }
        .wrap {
            max-width: 26rem;
            margin: 0 auto;
            padding: 4.25rem 1rem 2.5rem;
        }
        .wrap.wrap--thanks {
            max-width: 42rem;
        }
        .page-top {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 50;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.55rem 0.75rem;
            background: #fff;
            border-bottom: 1px solid transparent;
            transition: border-color 0.2s ease;
        }
        .page-top.is-scrolled {
            border-bottom-color: #e5e7eb;
        }
        .page-logo {
            max-height: 52px;
            max-width: 190px;
            object-fit: contain;
            display: block;
            background: #fff;
        }
        .lang-bar {
            flex-shrink: 0;
        }
        .lang-select {
            appearance: none;
            border: 1px solid #e5e7eb;
            background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none'%3E%3Cpath d='M6 9l6 6 6-6' stroke='%236b7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") no-repeat right 0.55rem center;
            padding: 0.45rem 1.7rem 0.45rem 0.7rem;
            font: inherit;
            font-size: 0.8rem;
            font-weight: 500;
            color: #0f172a;
            cursor: pointer;
            min-width: 7.5rem;
        }
        .lang-select:focus {
            outline: none;
            border-color: #93b4f5;
        }
        .visually-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
        html[dir="rtl"] .page-top {
            flex-direction: row-reverse;
        }
        html[dir="rtl"] .lang-select {
            background-position: left 0.55rem center;
            padding: 0.45rem 0.7rem 0.45rem 1.7rem;
        }
        .brand-bar {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            margin-bottom: 0.85rem;
            padding: 0 0.15rem;
        }
        .brand-name {
            font-size: 0.8rem;
            color: var(--muted);
            font-weight: 500;
        }
        .feedback-card {
            position: relative;
            background: var(--card);
            border-radius: 1rem;
            border: 0;
            box-shadow: none;
            padding: 0.9rem 1rem 0.85rem;
            margin-bottom: 0.85rem;
        }
        .feedback-title {
            margin: 0;
            font-family: Fraunces, Georgia, "Times New Roman", serif;
            font-size: 1.35rem;
            font-weight: 500;
            letter-spacing: -0.02em;
            color: #0b1220;
            line-height: 1.2;
        }
        .feedback-sub {
            margin: 0.2rem 0 0.7rem;
            color: var(--muted);
            font-size: 0.9rem;
            font-weight: 400;
            line-height: 1.3;
        }
        .moods {
            display: flex;
            justify-content: space-between;
            gap: 0.25rem;
            margin: 0 0 0.7rem;
        }
        .moods input { position: absolute; opacity: 0; pointer-events: none; }
        .mood {
            width: 2.55rem;
            height: 2.55rem;
            border: 0;
            border-radius: 999px;
            background: transparent;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #9ca3af;
            transition: background 0.15s ease, color 0.15s ease, transform 0.15s ease;
        }
        .mood svg { width: 1.55rem; height: 1.55rem; display: block; }
        .mood.is-on {
            background: var(--mood-ring);
            color: var(--mood);
            transform: translateY(-1px);
        }
        .mood.is-on svg circle[fill="none"] {
            fill: rgba(245, 165, 36, 0.12);
        }
        .field-label {
            display: block;
            margin: 0 0 0.3rem;
            font-size: 0.88rem;
            font-weight: 400;
            color: #1f2937;
            line-height: 1.3;
        }
        textarea {
            width: 100%;
            min-height: 3.25rem;
            border: 1.5px solid #c7d7f5;
            border-radius: 0.55rem;
            padding: 0.5rem 0.65rem;
            font: inherit;
            font-size: 0.9rem;
            resize: vertical;
            outline: none;
            background: #fff;
        }
        textarea:focus { border-color: #93b4f5; box-shadow: none; }
        .follow {
            margin: 0.65rem 0 0.7rem;
        }
        .follow-options {
            display: flex;
            gap: 1.1rem;
            margin-top: 0.35rem;
        }
        .follow-options label {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.9rem;
            font-weight: 400;
            color: #1f2937;
            cursor: pointer;
        }
        .follow-options input {
            width: 0.95rem;
            height: 0.95rem;
            accent-color: var(--brand);
        }
        .actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
        }
        .btn {
            appearance: none;
            border-radius: 0.55rem;
            font: inherit;
            font-weight: 500;
            padding: 0.5rem 0.85rem;
            cursor: pointer;
        }
        .btn-primary {
            border: 0;
            background: var(--brand);
            color: #fff;
        }
        .btn-primary:hover { background: var(--brand-hover); }
        .btn-secondary {
            border: 1px solid #d1d5db;
            background: #fff;
            color: #374151;
            text-decoration: none;
            text-align: center;
        }
        .btn:disabled { opacity: 0.55; cursor: default; }
        .flash {
            border-radius: 0.75rem;
            padding: 0.7rem 0.85rem;
            margin-bottom: 0.85rem;
            font-size: 0.875rem;
        }
        .flash-ok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .flash-err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .feedback-card--thanks {
            padding: 0;
            background: transparent;
            border: 0;
            box-shadow: none;
        }
        .thanks-card {
            background: transparent;
            border: 0;
            border-radius: 0;
            overflow: visible;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            box-shadow: none;
        }
        .thanks-banner {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            text-align: left;
            padding: 0.9rem 0.95rem;
            border-radius: 0.65rem;
            background: #e8f5ee;
            width: 100%;
            box-sizing: border-box;
        }
        .thanks-banner-check {
            flex-shrink: 0;
            width: 1.55rem;
            height: 1.55rem;
            border-radius: 999px;
            background: #0f766e;
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .thanks-banner-check svg {
            width: 0.85rem;
            height: 0.85rem;
            display: block;
        }
        .thanks-banner-copy {
            min-width: 0;
        }
        .thanks-banner-title {
            margin: 0;
            font-family: Poppins, "Source Sans 3", sans-serif;
            font-size: 0.9rem;
            font-weight: 600;
            color: #111827;
            line-height: 1.3;
        }
        .thanks-banner-text {
            margin: 0.2rem 0 0;
            font-size: 0.8rem;
            font-weight: 400;
            color: #3f6f5c;
            line-height: 1.4;
        }
        .thanks-main {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.55rem;
            text-align: center;
            padding: 1.1rem 0.85rem 0.85rem;
            background: transparent;
        }
        .thanks-hero-icon {
            width: 3.75rem;
            height: 3.75rem;
            flex-shrink: 0;
            color: #0f766e;
        }
        .thanks-hero-icon img,
        .thanks-hero-icon svg {
            width: 100%;
            height: 100%;
            display: block;
            object-fit: contain;
        }
        .thanks-title {
            margin: 0;
            font-family: Poppins, "Source Sans 3", sans-serif;
            font-size: 1.35rem;
            font-weight: 600;
            color: #111827;
            letter-spacing: -0.02em;
            line-height: 1.2;
        }
        .thanks-text {
            margin: 0;
            font-size: 0.88rem;
            font-weight: 400;
            color: #6b7280;
            line-height: 1.4;
            max-width: 18rem;
        }
        .thanks-follow {
            margin: 0;
            padding: 0.65rem 0.9rem;
            border-radius: 0.6rem;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 0.84rem;
            line-height: 1.4;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .thanks-follow svg {
            width: 1.05rem;
            height: 1.05rem;
            flex-shrink: 0;
            display: block;
        }
        .thanks-follow--no {
            background: #f8fafc;
            color: #64748b;
        }
        html[dir="rtl"] .thanks-banner,
        html[dir="rtl"] .thanks-follow {
            text-align: right;
        }
        html[dir="rtl"] .thanks-banner {
            flex-direction: row;
        }
        html[dir="rtl"] .thanks-main {
            text-align: center;
        }
        .saved {
            color: #065f46;
            font-weight: 500;
            margin: 0;
        }
        .empty {
            color: var(--muted);
            font-size: 0.875rem;
            margin: 0;
            padding: 1rem;
            text-align: center;
        }
        .similar-head {
            max-width: 26rem;
            margin: 0.55rem auto 0.35rem;
            padding: 0 1rem;
            font-family: Poppins, "Source Sans 3", sans-serif;
            font-size: 1.05rem;
            font-weight: 600;
            color: #0f172a;
            text-align: center;
        }
        .full-range-link {
            max-width: 26rem;
            margin: 0.35rem auto 1rem;
            padding: 0 1rem;
            text-align: center;
            font-size: 0.9rem;
            line-height: 1.4;
            color: #64748b;
        }
        .full-range-link a {
            color: #0f766e;
            font-weight: 600;
            text-decoration: none;
        }
        .full-range-link a:hover {
            text-decoration: underline;
        }
        .ppe-stage {
            position: relative;
            width: 100%;
            height: min(70vh, 36rem);
            min-height: 24rem;
            margin: 0 0 0.85rem;
            overflow: hidden;
            border-radius: 0;
            user-select: none;
            touch-action: pan-y;
            background: #fff;
            border: 0;
            box-shadow: none;
        }
        .ppe-slide {
            position: absolute;
            inset: 0;
            overflow: hidden;
            display: none;
            z-index: 0;
            background: #fff;
        }
        .ppe-slide.is-active { display: block; z-index: 2; }
        .ppe-slide.is-incoming { display: block; z-index: 1; }
        .ppe-frame {
            position: absolute;
            inset: 0 0 6.5rem 0;
            display: grid;
            place-items: center;
        }
        .ppe-layer {
            position: absolute;
            inset: 0;
            pointer-events: none;
            will-change: transform;
        }
        .ppe-layer[data-layer="word"] {
            z-index: 1;
        }
        .ppe-layer[data-layer="shadow"] {
            z-index: 2;
        }
        .ppe-word {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            font-family: Poppins, "Source Sans 3", sans-serif;
            font-weight: 700;
            font-size: clamp(3.4rem, 18vw, 6.4rem);
            line-height: 1;
            letter-spacing: 0.02em;
            /* Soft slate watermark: readable on white, stays behind the product */
            color: rgba(100, 116, 139, 0.28);
            white-space: nowrap;
            text-transform: uppercase;
            padding: 0 0.75rem;
            user-select: none;
            max-width: 100%;
            overflow: hidden;
        }
        .ppe-shadow {
            position: absolute;
            left: 50%;
            top: 72%;
            width: min(58%, 14rem);
            height: 1.15rem;
            transform: translateX(-50%);
            border-radius: 50%;
            background: radial-gradient(closest-side, rgba(15, 23, 42, 0.18), transparent 72%);
        }
        .ppe-layer[data-layer="bottle"] {
            pointer-events: none;
            z-index: 3;
        }
        .ppe-bottle {
            position: absolute;
            left: 50%;
            top: 46%;
            width: min(68vw, 17rem);
            height: min(58vw, 15rem);
            transform: translate(-50%, -50%);
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            cursor: pointer;
            pointer-events: auto;
            z-index: 4;
        }
        .ppe-bottle img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            object-position: center;
            background: transparent;
            filter: drop-shadow(0 16px 20px rgba(15, 23, 42, 0.18));
        }
        .ppe-bottle img.is-cutout {
            /* Soft product shadow hugs the opaque cutout only */
            filter: drop-shadow(0 18px 16px rgba(15, 23, 42, 0.22));
        }
        .ppe-bottle-fallback {
            width: 70%;
            height: 70%;
            border-radius: 0.75rem;
            background: #fff;
            display: grid;
            place-items: center;
            color: #6b7280;
            font-family: Poppins, sans-serif;
            font-weight: 600;
            font-size: 0.85rem;
            text-align: center;
            padding: 0.5rem;
        }
        .ppe-ui {
            position: absolute;
            inset: 0;
            z-index: 10;
            pointer-events: none;
            color: #0f172a;
            font-family: Poppins, "Source Sans 3", sans-serif;
        }
        .ppe-ui button,
        .ppe-ui a {
            pointer-events: auto;
            background: none;
            border: 0;
            color: inherit;
            font: inherit;
            cursor: pointer;
            padding: 0;
            text-decoration: none;
        }
        .ppe-meta {
            position: absolute;
            left: 50%;
            bottom: 3.85rem;
            transform: translateX(-50%);
            text-align: center;
            width: min(92%, 20rem);
            pointer-events: none;
            z-index: 5;
        }
        .ppe-meta-name {
            font-family: Poppins, "Source Sans 3", sans-serif;
            font-size: 1.05rem;
            font-weight: 600;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            color: #0f172a;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .ppe-meta-price {
            margin-top: 0.2rem;
            font-size: 0.95rem;
            font-weight: 700;
            color: #0f172a;
        }
        .ppe-ui .ppe-shop {
            position: absolute;
            left: 50%;
            bottom: 0.4rem;
            transform: translateX(-50%);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            font-family: Poppins, "Source Sans 3", sans-serif;
            font-weight: 600;
            font-size: 0.78rem;
            letter-spacing: 0.08em;
            padding: 0.65rem 1.15rem;
            color: #fff;
            background: #7c3aed;
            border: 0;
            border-radius: 0.65rem;
            box-shadow: none;
            cursor: pointer;
            white-space: nowrap;
            transition: background 0.15s ease, transform 0.15s ease;
        }
        .ppe-ui .ppe-shop:hover {
            background: #6d28d9;
            transform: translate(-50%, -1px);
        }
        .ppe-ui .ppe-shop:active { transform: translate(-50%, 0); }
        .ppe-ui .ppe-shop svg {
            width: 1.05rem;
            height: 1.05rem;
            flex-shrink: 0;
            display: block;
        }
        .ppe-ui .ppe-arrow {
            position: absolute;
            top: auto;
            bottom: 0.35rem;
            transform: none;
            width: 3.1rem;
            height: 3.1rem;
            display: grid;
            place-items: center;
            border-radius: 999px;
            background: transparent;
            border: 1.5px solid #111827;
            color: #111827;
            transition: background 0.15s ease, transform 0.15s ease, border-color 0.15s ease;
        }
        .ppe-ui .ppe-arrow:hover {
            background: transparent;
            border-color: #0f172a;
            transform: scale(1.05);
        }
        .ppe-arrow--prev { left: 0.55rem; }
        .ppe-arrow--next { right: 0.55rem; }
        .ppe-arrow svg { width: 1.35rem; height: 1.35rem; display: block; }
        .inquiry-sheet {
            position: fixed;
            inset: 0;
            z-index: 80;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(15, 23, 42, 0.45);
            padding: 1rem;
        }
        .inquiry-sheet.is-open { display: flex; }
        .inquiry-panel {
            position: relative;
            width: min(100%, 22.5rem);
            background: #fff;
            border-radius: 0.8rem;
            padding: 0.65rem 0.85rem 0.7rem;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.18);
            max-height: min(90vh, 28rem);
            overflow: auto;
        }
        .inquiry-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.4rem;
            margin-bottom: 0.45rem;
        }
        .inquiry-heading {
            margin: 0;
            font-family: Poppins, "Source Sans 3", sans-serif;
            font-size: 0.98rem;
            font-weight: 600;
            color: #0f172a;
            line-height: 1.15;
        }
        .inquiry-close {
            border: 0;
            background: transparent;
            width: 1.55rem;
            height: 1.55rem;
            border-radius: 999px;
            font-size: 1.05rem;
            line-height: 1;
            cursor: pointer;
            color: #64748b;
            flex-shrink: 0;
        }
        .inquiry-close:hover { background: #f1f5f9; color: #0f172a; }
        .inquiry-product {
            display: grid;
            grid-template-columns: 2.4rem 1fr;
            gap: 0.5rem;
            align-items: center;
            padding-bottom: 0.45rem;
            margin-bottom: 0.45rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .inquiry-thumb {
            width: 2.4rem;
            height: 2.4rem;
            object-fit: contain;
            background: #fff;
            border-radius: 0.35rem;
            border: 1px solid #eef2f7;
        }
        .inquiry-title {
            margin: 0;
            font-family: Poppins, "Source Sans 3", sans-serif;
            font-size: 0.82rem;
            font-weight: 600;
            color: #0f172a;
            line-height: 1.25;
        }
        .inquiry-label {
            display: block;
            margin: 0 0 0.2rem;
            font-size: 0.78rem;
            color: #0f172a;
            font-weight: 600;
        }
        .inquiry-field { margin-bottom: 0.42rem; }
        .inquiry-qty {
            display: grid;
            grid-template-columns: 2rem 3.25rem 2rem;
            width: max-content;
            border: 1px solid #e5e7eb;
            border-radius: 0.45rem;
            overflow: hidden;
            background: #fff;
        }
        .inquiry-qty button {
            border: 0;
            background: #f3f4f6;
            color: #0f172a;
            font-size: 1rem;
            line-height: 1;
            cursor: pointer;
            padding: 0.28rem 0;
        }
        .inquiry-qty button:hover { background: #e5e7eb; }
        .inquiry-qty input {
            width: 100%;
            border: 0;
            border-left: 1px solid #e5e7eb;
            border-right: 1px solid #e5e7eb;
            text-align: center;
            font: inherit;
            font-size: 0.9rem;
            font-weight: 500;
            padding: 0.28rem 0.25rem;
            outline: none;
            background: #fff;
            -moz-appearance: textfield;
        }
        .inquiry-qty input::-webkit-outer-spin-button,
        .inquiry-qty input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        .inquiry-panel textarea {
            width: 100%;
            border: 1px solid #e5e7eb;
            border-radius: 0.45rem;
            padding: 0.35rem 0.5rem;
            font: inherit;
            font-size: 0.85rem;
            outline: none;
            background: #fff;
            min-height: 2.1rem;
            max-height: 4rem;
            resize: vertical;
        }
        .inquiry-panel textarea:focus { border-color: #a78bfa; }
        .inquiry-actions {
            display: grid;
            gap: 0.25rem;
            margin-top: 0.05rem;
        }
        .inquiry-submit {
            width: 100%;
            border: 0;
            border-radius: 0.5rem;
            background: #6d28d9;
            color: #fff;
            font: inherit;
            font-weight: 600;
            font-size: 0.85rem;
            padding: 0.48rem 0.8rem;
            cursor: pointer;
            text-align: center;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
        }
        .inquiry-submit:hover { background: #5b21b6; }
        .inquiry-submit svg { width: 0.95rem; height: 0.95rem; display: block; }
        .inquiry-or {
            margin: 0;
            text-align: center;
            font-size: 0.7rem;
            color: #94a3b8;
            line-height: 1;
        }
        .inquiry-wa {
            display: none;
            width: 100%;
            box-sizing: border-box;
            text-align: center;
            text-decoration: none;
            font-size: 0.82rem;
            font-weight: 600;
            color: #16a34a;
            padding: 0.42rem 0.8rem;
            border: 1.5px solid #16a34a;
            border-radius: 0.5rem;
            background: #fff;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
        }
        .inquiry-wa.is-on { display: inline-flex; }
        .inquiry-wa:hover { background: #f0fdf4; }
        .inquiry-wa svg { width: 0.95rem; height: 0.95rem; display: block; }
        .inquiry-flash {
            margin: 0 0 0.35rem;
            padding: 0.3rem 0.45rem;
            border-radius: 0.4rem;
            font-size: 0.76rem;
        }
        .inquiry-flash-ok { background: #ecfdf5; color: #065f46; }
        .inquiry-flash-err { background: #fef2f2; color: #991b1b; }
        .details-sheet {
            position: fixed;
            inset: 0;
            z-index: 75;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(15, 23, 42, 0.45);
            padding: 1rem;
        }
        .details-sheet.is-open { display: flex; }
        .details-panel {
            position: relative;
            width: min(100%, 52rem);
            background: #fff;
            border-radius: 0;
            padding: 1.15rem 1.15rem 1.15rem;
            max-height: min(92vh, 36rem);
            overflow: auto;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.18);
        }
        .details-close {
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            z-index: 2;
            border: 0;
            background: #eef1f4;
            width: 2rem;
            height: 2rem;
            border-radius: 0;
            font-size: 1.05rem;
            line-height: 1;
            cursor: pointer;
            color: #475569;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .details-close:hover {
            background: #dbe3ee;
            color: #0f172a;
        }
        .details-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }
        @media (min-width: 720px) {
            .details-grid {
                grid-template-columns: 1.05fr 1fr;
                gap: 1.35rem;
                align-items: stretch;
            }
            .details-panel { padding: 1.35rem 1.4rem 1.25rem; }
        }
        .details-image-card {
            position: relative;
            border: 1px solid #e5e7eb;
            border-radius: 0;
            background: #fff;
            min-height: 14rem;
            display: grid;
            place-items: center;
            padding: 0.85rem;
            overflow: hidden;
            cursor: zoom-in;
        }
        .details-image-card img {
            max-width: 100%;
            max-height: min(48vh, 22rem);
            object-fit: contain;
            display: block;
            transition: transform 0.35s ease;
            transform-origin: center center;
        }
        .details-image-card:hover img {
            transform: scale(1.18);
        }
        .details-zoom {
            position: absolute;
            right: 0.65rem;
            bottom: 0.65rem;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border: 0;
            border-radius: 0;
            background: #4b5563;
            color: #fff;
            font-size: 0.72rem;
            font-weight: 500;
            padding: 0.4rem 0.7rem;
            pointer-events: none;
            transition: background 0.15s ease, opacity 0.15s ease;
            opacity: 0.92;
        }
        .details-image-card:hover .details-zoom {
            background: #1f2937;
            opacity: 1;
        }
        .details-zoom svg { width: 0.85rem; height: 0.85rem; display: block; }
        .details-info {
            display: flex;
            flex-direction: column;
            min-width: 0;
            padding-top: 0.15rem;
            padding-right: 1.5rem;
        }
        .details-category {
            margin: 0 0 0.35rem;
            font-size: 0.72rem;
            font-weight: 500;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #ea580c;
        }
        .details-name {
            margin: 0;
            font-family: Poppins, "Source Sans 3", sans-serif;
            font-size: clamp(1.05rem, 3.2vw, 1.35rem);
            font-weight: 500;
            color: #0f172a;
            line-height: 1.25;
            text-transform: uppercase;
            letter-spacing: 0.01em;
        }
        .details-price-pill {
            display: inline-flex;
            align-self: flex-start;
            margin-top: 0.65rem;
            padding: 0.28rem 0.7rem;
            border-radius: 0;
            background: #fef3c7;
            color: #92400e;
            font-size: 0.78rem;
            font-weight: 500;
        }
        .details-desc {
            margin: 0.7rem 0 0;
            font-size: 0.9rem;
            color: #64748b;
            line-height: 1.45;
            white-space: pre-wrap;
        }
        .details-desc.is-empty { color: #94a3b8; font-style: italic; }
        .details-actions {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.55rem;
            margin-top: auto;
            padding-top: 1rem;
        }
        .details-actions .btn-request {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            border: 0;
            border-radius: 0;
            background: #7c3aed;
            color: #fff;
            font: inherit;
            font-weight: 500;
            font-size: 0.84rem;
            letter-spacing: 0.04em;
            padding: 0.72rem 0.75rem;
            cursor: pointer;
            transition: background 0.15s ease, transform 0.15s ease;
        }
        .details-actions .btn-request:hover {
            background: #6d28d9;
            transform: translateY(-1px);
        }
        .details-actions .btn-request:active { transform: translateY(0); }
        .details-actions .btn-request svg { width: 1rem; height: 1rem; }
        .foot {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            flex-wrap: wrap;
            text-align: center;
            color: #94a3b8;
            font-size: 0.75rem;
            margin: 0.75rem 0 1.25rem;
        }
        .foot-logo {
            width: 1.1rem;
            height: 1.1rem;
            object-fit: contain;
            display: block;
            opacity: 0.9;
        }
        .foot-brand {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            color: #64748b;
            font-weight: 500;
            text-decoration: none;
        }
        .foot-brand:hover {
            color: #334155;
        }
        .foot-brand:hover .foot-logo {
            opacity: 1;
        }
        @media (min-width: 640px) {
            .ppe-stage {
                max-width: 40rem;
                margin-left: auto;
                margin-right: auto;
                height: min(68vh, 38rem);
            }
            .ppe-bottle {
                width: min(52%, 18rem);
                height: 16rem;
            }
        }
    </style>
</head>
<body>
    <header class="page-top" id="page-top">
        <?php if ($logoUrl !== ''): ?>
            <img class="page-logo" src="<?= dn_feedback_h($logoUrl) ?>" alt="<?= dn_feedback_h($companyName) ?>">
        <?php else: ?>
            <span class="page-logo" aria-hidden="true"></span>
        <?php endif; ?>
        <div class="lang-bar">
            <label class="visually-hidden" for="lang-select"><?= dn_feedback_h(deliveries_feedback_t($L, 'language', 'Language')) ?></label>
            <select class="lang-select" id="lang-select" aria-label="<?= dn_feedback_h(deliveries_feedback_t($L, 'language', 'Language')) ?>">
                <?php foreach (deliveries_feedback_languages() as $code => $meta): ?>
                    <option value="<?= dn_feedback_h($code) ?>"<?= $code === $langCode ? ' selected' : '' ?>><?= dn_feedback_h($meta['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </header>
    <div class="wrap<?= $showThanks ? ' wrap--thanks' : '' ?>">
        <div class="brand-bar">
            <span class="brand-name"><?= dn_feedback_h($companyName) ?><?php if ($noteNumber !== ''): ?> · <?= dn_feedback_h($noteNumber) ?><?php endif; ?></span>
        </div>

        <?php if ($flashError !== ''): ?>
            <div class="flash flash-err"><?= dn_feedback_h($flashError) ?></div>
        <?php endif; ?>

        <section class="feedback-card<?= $showThanks ? ' feedback-card--thanks' : '' ?>" id="review">
            <?php if ($showThanks): ?>
                <div class="thanks-card">
                    <div class="thanks-banner" role="status">
                        <span class="thanks-banner-check" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none">
                                <path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>
                        <div class="thanks-banner-copy">
                            <p class="thanks-banner-title"><?= dn_feedback_h(deliveries_feedback_t($L, 'thanks_banner_title')) ?></p>
                            <p class="thanks-banner-text"><?= dn_feedback_h(deliveries_feedback_t($L, 'thanks_banner_text')) ?></p>
                        </div>
                    </div>

                    <div class="thanks-main">
                        <h1 class="thanks-title"><?= dn_feedback_h(deliveries_feedback_t($L, 'thanks_title')) ?></h1>
                        <div class="thanks-hero-icon" aria-hidden="true">
                            <img
                                width="120"
                                height="120"
                                src="https://img.icons8.com/external-outlines-amoghdesign/120/0f766e/external-agreement-military-and-war-outlines-amoghdesign.png"
                                srcset="https://img.icons8.com/external-outlines-amoghdesign/120/0f766e/external-agreement-military-and-war-outlines-amoghdesign.png 1x,
                                        https://img.icons8.com/external-outlines-amoghdesign/240/0f766e/external-agreement-military-and-war-outlines-amoghdesign.png 2x"
                                alt=""
                                decoding="async"
                            >
                        </div>
                        <p class="thanks-text"><?= dn_feedback_h(deliveries_feedback_t($L, 'thanks_help')) ?></p>
                    </div>

                    <?php if ($savedRating > 0 && $savedRating <= 3): ?>
                        <p class="thanks-follow">
                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M4 13v-2a8 8 0 0 1 16 0v2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                <path d="M4 13v3.2A1.8 1.8 0 0 0 5.8 18H7v-5H4Zm16 0v3.2A1.8 1.8 0 0 1 18.2 18H17v-5h3Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                                <path d="M12 19v1.2a2 2 0 0 0 2 2h1.2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                            </svg>
                            <span><?= dn_feedback_h(deliveries_feedback_t($L, 'thanks_follow_yes')) ?></span>
                        </p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <h1 class="feedback-title"><?= dn_feedback_h(deliveries_feedback_t($L, 'give_feedback')) ?></h1>
                <p class="feedback-sub"><?= dn_feedback_h(deliveries_feedback_t($L, 'how_delivery')) ?></p>
                <form method="post" action="" id="feedback-form" autocomplete="off">
                    <input type="hidden" name="fb_nonce" value="<?= dn_feedback_h($formNonce) ?>">
                    <div class="fb-hp" aria-hidden="true">
                        <label for="dn_fb_hp">Leave blank</label>
                        <input type="text" name="dn_fb_hp" id="dn_fb_hp" value="" tabindex="-1" autocomplete="new-password">
                    </div>
                    <input type="hidden" name="lang" value="<?= dn_feedback_h($langCode) ?>">
                    <div class="moods" role="radiogroup" aria-label="<?= dn_feedback_h(deliveries_feedback_t($L, 'satisfaction')) ?>">
                        <?php
                        $moodLabels = [
                            1 => deliveries_feedback_t($L, 'mood_1'),
                            2 => deliveries_feedback_t($L, 'mood_2'),
                            3 => deliveries_feedback_t($L, 'mood_3'),
                            4 => deliveries_feedback_t($L, 'mood_4'),
                            5 => deliveries_feedback_t($L, 'mood_5'),
                        ];
                        $moodPaths = [
                            1 => '<circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M8.2 9.2c.4-.5.9-.8 1.5-.8s1.1.3 1.5.8M12.8 9.2c.4-.5.9-.8 1.5-.8s1.1.3 1.5.8" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M8.2 15.6c1.1-1.4 2.4-2.1 3.8-2.1s2.7.7 3.8 2.1" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
                            2 => '<circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="9" cy="10" r="1" fill="currentColor"/><circle cx="15" cy="10" r="1" fill="currentColor"/><path d="M8.4 15.2c1-1.1 2.2-1.7 3.6-1.7s2.6.6 3.6 1.7" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
                            3 => '<circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="9" cy="10" r="1" fill="currentColor"/><circle cx="15" cy="10" r="1" fill="currentColor"/><path d="M8.5 14.8h7" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
                            4 => '<circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="9" cy="10" r="1" fill="currentColor"/><circle cx="15" cy="10" r="1" fill="currentColor"/><path d="M8.4 14.2c1 1.2 2.2 1.8 3.6 1.8s2.6-.6 3.6-1.8" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
                            5 => '<circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="9" cy="10" r="1" fill="currentColor"/><circle cx="15" cy="10" r="1" fill="currentColor"/><path d="M8.2 13.8c1.1 1.8 2.4 2.7 3.8 2.7s2.7-.9 3.8-2.7" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
                        ];
                        for ($i = 1; $i <= 5; $i++):
                        ?>
                            <input type="radio" name="rating" id="mood<?= $i ?>" value="<?= $i ?>"<?= $i === 4 ? ' checked' : '' ?> required>
                            <label class="mood<?= $i === 4 ? ' is-on' : '' ?>" for="mood<?= $i ?>" data-mood="<?= $i ?>" title="<?= dn_feedback_h($moodLabels[$i]) ?>" aria-label="<?= dn_feedback_h($moodLabels[$i]) ?>">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><?= $moodPaths[$i] ?></svg>
                            </label>
                        <?php endfor; ?>
                    </div>

                    <label class="field-label" for="comment"><?= dn_feedback_h(deliveries_feedback_t($L, 'thoughts')) ?></label>
                    <textarea id="comment" name="comment" maxlength="2000" placeholder=""></textarea>

                    <div class="follow">
                        <span class="field-label"><?= dn_feedback_h(deliveries_feedback_t($L, 'follow_up')) ?></span>
                        <div class="follow-options">
                            <label><input type="radio" name="follow_up" value="yes" checked> <?= dn_feedback_h(deliveries_feedback_t($L, 'yes')) ?></label>
                            <label><input type="radio" name="follow_up" value="no"> <?= dn_feedback_h(deliveries_feedback_t($L, 'no')) ?></label>
                        </div>
                    </div>

                    <div class="actions">
                        <button class="btn btn-primary" type="submit"><?= dn_feedback_h(deliveries_feedback_t($L, 'send')) ?></button>
                        <a class="btn btn-secondary" href="#similar"><?= dn_feedback_h(deliveries_feedback_t($L, 'cancel')) ?></a>
                    </div>
                </form>
            <?php endif; ?>
        </section>

    </div>

    <h2 class="similar-head" id="similar"><?= dn_feedback_h(deliveries_feedback_t($L, 'you_may_also_like')) ?></h2>
    <?php if ($sliderProducts === []): ?>
        <p class="empty"><?= dn_feedback_h(deliveries_feedback_t($L, 'no_products')) ?></p>
    <?php else: ?>
        <section class="ppe-stage" id="ppe-stage" aria-roledescription="carousel" aria-label="<?= dn_feedback_h(deliveries_feedback_t($L, 'related_products')) ?>">
            <?php foreach ($sliderProducts as $idx => $product):
                $bg = $sliderColors[$idx % count($sliderColors)];
                $img = (string) ($product['image_url'] ?? '');
                $short = (string) ($product['short'] ?? 'PPE');
                $fullName = (string) ($product['name'] ?? '');
                ?>
                <article
                    class="ppe-slide<?= $idx === 0 ? ' is-active' : '' ?>"
                    data-index="<?= $idx ?>"
                    data-product-id="<?= (int) ($product['id'] ?? 0) ?>"
                    data-product-name="<?= dn_feedback_h($fullName) ?>"
                    data-product-code="<?= dn_feedback_h((string) ($product['product_code'] ?? '')) ?>"
                    data-product-image="<?= dn_feedback_h($img) ?>"
                    data-product-brand="<?= dn_feedback_h((string) ($product['brand'] ?? '')) ?>"
                    data-product-category="<?= dn_feedback_h((string) ($product['category_name'] ?? '')) ?>"
                    data-product-description="<?= dn_feedback_h((string) ($product['description'] ?? '')) ?>"
                    style="background:<?= dn_feedback_h($bg) ?>"
                    aria-hidden="<?= $idx === 0 ? 'false' : 'true' ?>"
                >
                    <div class="ppe-frame">
                        <div class="ppe-layer" data-layer="word">
                            <div class="ppe-word"><?= dn_feedback_h($short) ?></div>
                        </div>
                        <div class="ppe-layer" data-layer="shadow">
                            <div class="ppe-shadow"></div>
                        </div>
                        <div class="ppe-layer" data-layer="bottle">
                            <div class="ppe-bottle">
                                <?php if ($img !== ''): ?>
                                    <img src="<?= dn_feedback_h($img) ?>" alt="<?= dn_feedback_h($fullName) ?>">
                                <?php else: ?>
                                    <div class="ppe-bottle-fallback"><?= dn_feedback_h($short) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="ppe-meta" data-meta>
                        <div class="ppe-meta-name"><?= dn_feedback_h($fullName) ?></div>
                    </div>
                </article>
            <?php endforeach; ?>

            <div class="ppe-ui">
                <button type="button" class="ppe-shop" id="ppe-ask-us">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M12 5v14M7.5 9.5c0-1.5 1.8-2.5 4.5-2.5s4.5 1 4.5 2.7c0 1.9-2 2.6-4.5 3.1S7.5 14 7.5 16c0 1.8 2 2.8 4.5 2.8s4.5-1.1 4.5-2.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/>
                    </svg>
                    <?= dn_feedback_h(deliveries_feedback_t($L, 'request_price_caps')) ?>
                </button>
                <button type="button" class="ppe-arrow ppe-arrow--prev" aria-label="<?= dn_feedback_h(deliveries_feedback_t($L, 'prev_product')) ?>">
                    <svg viewBox="0 0 28 28" fill="none" aria-hidden="true">
                        <path d="M24 14H4M12 6l-8 8 8 8" stroke="#111827" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <button type="button" class="ppe-arrow ppe-arrow--next" aria-label="<?= dn_feedback_h(deliveries_feedback_t($L, 'next_product')) ?>">
                    <svg viewBox="0 0 28 28" fill="none" aria-hidden="true">
                        <path d="M4 14h20M16 6l8 8-8 8" stroke="#111827" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </div>
        </section>

        <div class="details-sheet" id="details-sheet" aria-hidden="true">
            <div class="details-panel" role="dialog" aria-modal="true" aria-labelledby="details-name">
                <button type="button" class="details-close" id="details-close" aria-label="<?= dn_feedback_h(deliveries_feedback_t($L, 'close')) ?>">×</button>
                <div class="details-grid">
                    <div class="details-image-card">
                        <img id="details-image" src="" alt="">
                        <span class="details-zoom" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="6.5" stroke="#fff" stroke-width="1.8"/><path d="M16.2 16.2L20 20" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/></svg>
                            <?= dn_feedback_h(deliveries_feedback_t($L, 'hover_zoom')) ?>
                        </span>
                    </div>
                    <div class="details-info">
                        <p class="details-category" id="details-category"><?= dn_feedback_h(deliveries_feedback_t($L, 'product')) ?></p>
                        <h3 class="details-name" id="details-name"><?= dn_feedback_h(deliveries_feedback_t($L, 'product')) ?></h3>
                        <span class="details-price-pill"><?= dn_feedback_h(deliveries_feedback_t($L, 'price_on_request')) ?></span>
                        <p class="details-desc" id="details-desc"></p>
                        <div class="details-actions">
                            <button type="button" class="btn-request" id="details-request">
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M12 5v14M7.5 9.5c0-1.5 1.8-2.5 4.5-2.5s4.5 1 4.5 2.7c0 1.9-2 2.6-4.5 3.1S7.5 14 7.5 16c0 1.8 2 2.8 4.5 2.8s4.5-1.1 4.5-2.8" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/>
                                    <circle cx="12" cy="12" r="9" stroke="#fff" stroke-width="1.8"/>
                                </svg>
                                <?= dn_feedback_h(deliveries_feedback_t($L, 'request_price')) ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="inquiry-sheet" id="inquiry-sheet" aria-hidden="true">
            <div class="inquiry-panel" role="dialog" aria-modal="true" aria-labelledby="inquiry-heading">
                <div class="inquiry-head">
                    <h2 class="inquiry-heading" id="inquiry-heading"><?= dn_feedback_h(deliveries_feedback_t($L, 'request_quote_title')) ?></h2>
                    <button type="button" class="inquiry-close" id="inquiry-close" aria-label="<?= dn_feedback_h(deliveries_feedback_t($L, 'close')) ?>">×</button>
                </div>
                <?php if ($inquiryOk): ?>
                    <p class="inquiry-flash inquiry-flash-ok"><?= dn_feedback_h(deliveries_feedback_t($L, 'thanks_inquiry')) ?></p>
                <?php elseif ($inquiryError !== ''): ?>
                    <p class="inquiry-flash inquiry-flash-err"><?= dn_feedback_h($inquiryError) ?></p>
                <?php endif; ?>
                <div class="inquiry-product">
                    <img class="inquiry-thumb" id="inquiry-thumb" src="" alt="" hidden>
                    <h3 class="inquiry-title" id="inquiry-title"><?= dn_feedback_h(deliveries_feedback_t($L, 'product')) ?></h3>
                </div>
                <form method="post" action="#similar" id="inquiry-form" autocomplete="off">
                    <input type="hidden" name="form_action" value="inquiry">
                    <input type="hidden" name="fb_nonce" value="<?= dn_feedback_h($formNonce) ?>">
                    <input type="hidden" name="lang" value="<?= dn_feedback_h($langCode) ?>">
                    <input type="hidden" name="product_id" id="inquiry-product-id" value="">
                    <input type="hidden" name="product_name" id="inquiry-product-name" value="">
                    <input type="hidden" name="product_code" id="inquiry-product-code" value="">
                    <div class="fb-hp" aria-hidden="true">
                        <label for="dn_fb_hp_inq">Leave blank</label>
                        <input type="text" name="dn_fb_hp" id="dn_fb_hp_inq" value="" tabindex="-1" autocomplete="new-password">
                    </div>
                    <div class="inquiry-field">
                        <label class="inquiry-label" for="inquiry-qty"><?= dn_feedback_h(deliveries_feedback_t($L, 'quantity')) ?></label>
                        <div class="inquiry-qty">
                            <button type="button" id="inquiry-qty-minus" aria-label="-">−</button>
                            <input type="number" name="qty" id="inquiry-qty" min="1" max="9999" value="1" required>
                            <button type="button" id="inquiry-qty-plus" aria-label="+">+</button>
                        </div>
                    </div>
                    <div class="inquiry-field">
                        <label class="inquiry-label" for="inquiry-message"><?= dn_feedback_h(deliveries_feedback_t($L, 'message_optional')) ?></label>
                        <textarea name="inquiry_message" id="inquiry-message" maxlength="1000" placeholder="<?= dn_feedback_h(deliveries_feedback_t($L, 'message_placeholder')) ?>"></textarea>
                    </div>
                    <div class="inquiry-actions">
                        <button class="inquiry-submit" type="submit">
                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 11.5l15-7-7 15-2.2-5.8L4 11.5z" stroke="#fff" stroke-width="1.7" stroke-linejoin="round"/></svg>
                            <?= dn_feedback_h(deliveries_feedback_t($L, 'request_price')) ?>
                        </button>
                        <p class="inquiry-or" id="inquiry-or" hidden><?= dn_feedback_h(deliveries_feedback_t($L, 'or')) ?></p>
                        <?php if ($inquiryWhatsapp !== ''): ?>
                            <a class="inquiry-wa is-on" id="inquiry-wa" href="<?= dn_feedback_h($inquiryWhatsapp) ?>" target="_blank" rel="noopener">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#16a34a" d="M12.04 2c-5.46 0-9.91 4.43-9.91 9.9 0 1.75.46 3.45 1.32 4.95L2 22l5.3-1.39c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.9-4.44 9.9-9.9S17.5 2 12.04 2zm5.76 14.04c-.24.68-1.4 1.25-1.93 1.33-.5.07-1.13.1-1.82-.11-.42-.13-.96-.28-1.65-.55-2.9-1.26-4.79-4.19-4.93-4.39-.14-.2-1.15-1.53-1.15-2.92s.73-2.07.99-2.35c.26-.28.56-.35.75-.35h.54c.17 0 .4-.06.62.47.24.57.8 1.97.87 2.11.07.14.12.3.02.49-.1.19-.14.3-.28.47-.14.16-.3.36-.42.49-.14.14-.28.29-.12.56.16.28.71 1.17 1.52 1.9 1.05.94 1.93 1.23 2.21 1.37.28.14.44.12.6-.07.16-.19.7-.81.88-1.09.19-.28.37-.23.62-.14.26.1 1.63.77 1.91.91.28.14.47.21.54.33.07.11.07.66-.17 1.34z"/></svg>
                                <?= dn_feedback_h(deliveries_feedback_t($L, 'continue_whatsapp')) ?>
                            </a>
                        <?php else: ?>
                            <a class="inquiry-wa" id="inquiry-wa" href="#" target="_blank" rel="noopener">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#16a34a" d="M12.04 2c-5.46 0-9.91 4.43-9.91 9.9 0 1.75.46 3.45 1.32 4.95L2 22l5.3-1.39c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.9-4.44 9.9-9.9S17.5 2 12.04 2zm5.76 14.04c-.24.68-1.4 1.25-1.93 1.33-.5.07-1.13.1-1.82-.11-.42-.13-.96-.28-1.65-.55-2.9-1.26-4.79-4.19-4.93-4.39-.14-.2-1.15-1.53-1.15-2.92s.73-2.07.99-2.35c.26-.28.56-.35.75-.35h.54c.17 0 .4-.06.62.47.24.57.8 1.97.87 2.11.07.14.12.3.02.49-.1.19-.14.3-.28.47-.14.16-.3.36-.42.49-.14.14-.28.29-.12.56.16.28.71 1.17 1.52 1.9 1.05.94 1.93 1.23 2.21 1.37.28.14.44.12.6-.07.16-.19.7-.81.88-1.09.19-.28.37-.23.62-.14.26.1 1.63.77 1.91.91.28.14.47.21.54.33.07.11.07.66-.17 1.34z"/></svg>
                                <?= dn_feedback_h(deliveries_feedback_t($L, 'continue_whatsapp')) ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($companyWebsiteLabel !== ''): ?>
        <p class="full-range-link">
            Visit our full range website at
            <?php if ($companyWebsiteHref !== ''): ?>
                <a href="<?= dn_feedback_h($companyWebsiteHref) ?>" target="_blank" rel="noopener noreferrer">
                    <?= dn_feedback_h($companyWebsiteLabel) ?>
                </a>
            <?php else: ?>
                <strong><?= dn_feedback_h($companyWebsiteLabel) ?></strong>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <?php
    $ultitechSite = defined('ERP_PUBLIC_SITE_URL') ? (string) ERP_PUBLIC_SITE_URL : 'https://ultitech.io';
    $ultitechLogo = rtrim($ultitechSite, '/') . '/assets/brand/ultitech-logo-512.png';
    if (is_file(dirname(__DIR__) . '/assets/brand/ultitech-logo-512.png')) {
        $ultitechLogoLocal = '../assets/brand/ultitech-logo-512.png';
        if (function_exists('app_url')) {
            $ultitechLogoLocal = (string) app_url('/assets/brand/ultitech-logo-512.png');
        }
        $ultitechLogo = $ultitechLogoLocal;
    }
    ?>
    <p class="foot">
        <span><?= dn_feedback_h(deliveries_feedback_t($L, 'powered_by')) ?></span>
        <a class="foot-brand" href="<?= dn_feedback_h(rtrim($ultitechSite, '/') . '/') ?>" target="_blank" rel="noopener" title="Ultitech ERP">
            <img class="foot-logo" src="<?= dn_feedback_h($ultitechLogo) ?>" alt="Ultitech ERP" width="18" height="18">
            <span>Ultitech ERP</span>
        </a>
    </p>
    <script type="application/ld+json">
    <?= json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'WebPage',
        'name' => 'Delivery feedback',
        'isPartOf' => [
            '@type' => 'SoftwareApplication',
            'name' => 'Ultitech ERP',
            'alternateName' => ['UltiTech ERP', 'Ultitech'],
            'url' => rtrim($ultitechSite, '/') . '/',
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'publisher' => [
                '@type' => 'Organization',
                'name' => 'Ultitech',
                'url' => rtrim($ultitechSite, '/') . '/',
                'logo' => rtrim($ultitechSite, '/') . '/assets/brand/ultitech-logo-512.png',
            ],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>
    </script>

    <script>
    (function () {
        var langSelect = document.getElementById('lang-select');
        if (langSelect) {
            langSelect.addEventListener('change', function () {
                var url = new URL(window.location.href);
                url.searchParams.set('lang', langSelect.value);
                window.location.href = url.toString();
            });
        }

        var pageTop = document.getElementById('page-top');
        if (pageTop) {
            var syncTopLine = function () {
                pageTop.classList.toggle('is-scrolled', window.scrollY > 4);
            };
            syncTopLine();
            window.addEventListener('scroll', syncTopLine, { passive: true });
        }

        var moods = document.querySelectorAll('.mood');
        var inputs = document.querySelectorAll('.moods input');
        function paint(n) {
            moods.forEach(function (label) {
                var v = parseInt(label.getAttribute('data-mood'), 10);
                label.classList.toggle('is-on', v === n);
            });
        }
        inputs.forEach(function (input) {
            input.addEventListener('change', function () {
                paint(parseInt(input.value, 10) || 0);
            });
        });
        paint(4);

        var stage = document.getElementById('ppe-stage');
        if (!stage) return;

        var slides = Array.prototype.slice.call(stage.querySelectorAll('.ppe-slide'));
        var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var index = 0;
        var busy = false;
        var lastInteract = 0;
        var coverPts = [
            [0, 0], [0.031, 0.006], [0.134, 0.012], [0.206, 0.023], [0.278, 0.047],
            [0.34, 0.07], [0.412, 0.105], [0.485, 0.158], [0.546, 0.246], [0.583, 0.55],
            [0.62, 0.825], [0.69, 0.9], [0.79, 0.94], [0.85, 0.956], [0.93, 0.975], [1, 1]
        ];
        var factors = { word: 0.40, shadow: 0.30, bottle: 0.15, fruit: 0.55, corner: 0.75 };

        function smoothstep(k) { return k * k * (3 - 2 * k); }
        function coverAt(t) {
            if (t <= 0) return 0;
            if (t >= 1) return 1;
            for (var i = 0; i < coverPts.length - 1; i++) {
                var a = coverPts[i], b = coverPts[i + 1];
                if (t >= a[0] && t <= b[0]) {
                    var u = (t - a[0]) / (b[0] - a[0]);
                    return a[1] + (b[1] - a[1]) * smoothstep(u);
                }
            }
            return 1;
        }
        function easeOutQuad(x) { return 1 - (1 - x) * (1 - x); }

        function markInteract() { lastInteract = Date.now(); }

        function setActive(i) {
            slides.forEach(function (slide, n) {
                slide.classList.toggle('is-active', n === i);
                slide.classList.remove('is-incoming');
                slide.style.clipPath = '';
                slide.querySelectorAll('.ppe-layer').forEach(function (layer) {
                    layer.style.transform = '';
                });
                slide.setAttribute('aria-hidden', n === i ? 'false' : 'true');
            });
            index = i;
        }

        function go(to, dir) {
            if (busy || slides.length < 2) return;
            var n = slides.length;
            to = ((to % n) + n) % n;
            if (to === index) return;
            if (dir == null) dir = to > index ? 1 : -1;
            markInteract();

            if (reduce) {
                setActive(to);
                return;
            }

            busy = true;
            var from = index;
            var A = slides[from];
            var B = slides[to];
            B.classList.add('is-incoming');
            B.style.display = 'block';
            A.style.zIndex = '2';
            B.style.zIndex = '1';
            index = to;

            var start = performance.now();
            var duration = 970;
            var width = stage.clientWidth || 1;

            function frame(now) {
                var t = Math.min(1, (now - start) / duration);
                var c = coverAt(t);
                if (dir > 0) {
                    A.style.clipPath = 'inset(0 ' + (c * 100) + '% 0 0)';
                } else {
                    A.style.clipPath = 'inset(0 0 0 ' + (c * 100) + '%)';
                }
                A.querySelectorAll('.ppe-layer').forEach(function (layer) {
                    layer.style.transform = 'translateX(' + (-dir * c * 0.14 * width) + 'px)';
                });

                var k = easeOutQuad(Math.max(0, Math.min(1, (t - 0.5) / 0.42)));
                B.querySelectorAll('.ppe-layer').forEach(function (layer) {
                    var key = layer.getAttribute('data-layer') || 'bottle';
                    var f = factors[key] != null ? factors[key] : 0.3;
                    layer.style.transform = 'translateX(' + (dir * (1 - k) * f * width) + 'px)';
                });

                if (t < 1 || k < 1) {
                    requestAnimationFrame(frame);
                    return;
                }
                A.classList.remove('is-active');
                A.style.display = '';
                A.style.clipPath = '';
                A.style.zIndex = '';
                A.querySelectorAll('.ppe-layer').forEach(function (layer) { layer.style.transform = ''; });
                B.classList.remove('is-incoming');
                B.classList.add('is-active');
                B.style.zIndex = '';
                B.querySelectorAll('.ppe-layer').forEach(function (layer) { layer.style.transform = ''; });
                slides.forEach(function (slide, n) {
                    slide.setAttribute('aria-hidden', n === to ? 'false' : 'true');
                    if (n !== to) {
                        slide.classList.remove('is-active');
                        slide.style.display = '';
                    }
                });
                busy = false;
            }
            requestAnimationFrame(frame);
        }

        stage.querySelector('.ppe-arrow--prev').addEventListener('click', function () {
            go(index - 1, -1);
        });
        stage.querySelector('.ppe-arrow--next').addEventListener('click', function () {
            go(index + 1, 1);
        });
        window.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowLeft') go(index - 1, -1);
            if (e.key === 'ArrowRight') go(index + 1, 1);
        });

        var px = 0, py = 0;
        stage.addEventListener('pointerdown', function (e) {
            px = e.clientX; py = e.clientY;
        });
        window.addEventListener('pointerup', function (e) {
            var dx = e.clientX - px;
            var dy = e.clientY - py;
            if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy)) {
                if (dx < 0) go(index + 1, 1);
                else go(index - 1, -1);
            }
        });

        setInterval(function () {
            if (document.hidden) return;
            if (Date.now() - lastInteract < 6000) return;
            go(index + 1, 1);
        }, 2600);

        setActive(0);

        var sheet = document.getElementById('inquiry-sheet');
        var askBtn = document.getElementById('ppe-ask-us');
        var companyPhone = <?= json_encode($companyPhone, JSON_UNESCAPED_UNICODE) ?>;
        var noteLabel = <?= json_encode($noteNumber !== '' ? $noteNumber : ('#' . $noteId), JSON_UNESCAPED_UNICODE) ?>;
        var customerLabel = <?= json_encode($customerName, JSON_UNESCAPED_UNICODE) ?>;
        var inquiryJustSaved = <?= $inquiryOk ? 'true' : 'false' ?>;
        var inquiryHadError = <?= $inquiryError !== '' ? 'true' : 'false' ?>;
        var inquiryWaUrl = <?= json_encode($inquiryWhatsapp, JSON_UNESCAPED_UNICODE) ?>;
        var inquirySavedProduct = <?= json_encode($inquiryProduct ?: null, JSON_UNESCAPED_UNICODE) ?>;

        function productFromSlide(slide) {
            if (!slide) return null;
            return {
                id: parseInt(slide.getAttribute('data-product-id') || '0', 10) || 0,
                name: slide.getAttribute('data-product-name') || '',
                code: slide.getAttribute('data-product-code') || '',
                image: slide.getAttribute('data-product-image') || '',
                brand: slide.getAttribute('data-product-brand') || '',
                category: slide.getAttribute('data-product-category') || '',
                description: slide.getAttribute('data-product-description') || ''
            };
        }

        function currentProduct() {
            return productFromSlide(slides[index]);
        }

        function buildWhatsAppUrl(product, qty, message) {
            var digits = String(companyPhone || '').replace(/\D+/g, '');
            if (!digits) return '';
            if (digits.length === 10 && digits.charAt(0) === '0') {
                digits = '255' + digits.slice(1);
            }
            var text = 'Hi, I\'d like a price for *' + product.name + '* from my delivery feedback link (' + noteLabel + ').'
                + '\nCustomer: ' + customerLabel
                + '\nQty: ' + qty;
            if (message) text += '\nNote: ' + message;
            text += '\nPlease send the price / quote.';
            return 'https://wa.me/' + digits + '?text=' + encodeURIComponent(text);
        }

        var detailsSheet = document.getElementById('details-sheet');

        function openDetails(product) {
            if (!detailsSheet || !product || !product.name) return;
            document.getElementById('details-name').textContent = product.name;
            document.getElementById('details-category').textContent = product.category || product.brand || 'Product';
            var img = document.getElementById('details-image');
            var live = slides[index] ? slides[index].querySelector('.ppe-bottle img') : null;
            var src = (live && live.src) ? live.src : (product.image || '');
            if (src) {
                img.src = src;
                img.alt = product.name;
                img.style.display = '';
            } else {
                img.removeAttribute('src');
                img.style.display = 'none';
            }
            var desc = document.getElementById('details-desc');
            if (product.description) {
                desc.textContent = product.description;
                desc.classList.remove('is-empty');
            } else {
                desc.textContent = <?= json_encode(deliveries_feedback_t($L, 'ask_specs'), JSON_UNESCAPED_UNICODE) ?>;
                desc.classList.add('is-empty');
            }
            detailsSheet.classList.add('is-open');
            detailsSheet.setAttribute('aria-hidden', 'false');
        }

        function closeDetails() {
            if (!detailsSheet) return;
            detailsSheet.classList.remove('is-open');
            detailsSheet.setAttribute('aria-hidden', 'true');
        }

        function syncInquiryWhatsApp(product) {
            var wa = document.getElementById('inquiry-wa');
            var orEl = document.getElementById('inquiry-or');
            if (!wa) return;
            var qty = document.getElementById('inquiry-qty').value || '1';
            var msg = document.getElementById('inquiry-message').value || '';
            var url = inquiryWaUrl || buildWhatsAppUrl(product, qty, msg);
            if (url) {
                wa.href = url;
                wa.classList.add('is-on');
                if (orEl) orEl.hidden = false;
            } else {
                wa.classList.remove('is-on');
                if (orEl) orEl.hidden = true;
            }
        }

        function openInquiry(product) {
            if (!sheet || !product || !product.name) return;
            closeDetails();
            document.getElementById('inquiry-product-id').value = String(product.id || 0);
            document.getElementById('inquiry-product-name').value = product.name;
            document.getElementById('inquiry-product-code').value = product.code || '';
            document.getElementById('inquiry-title').textContent = product.name;
            var thumb = document.getElementById('inquiry-thumb');
            var live = slides[index] ? slides[index].querySelector('.ppe-bottle img') : null;
            var src = (live && live.src) ? live.src : (product.image || '');
            if (src) {
                thumb.src = src;
                thumb.hidden = false;
            } else {
                thumb.removeAttribute('src');
                thumb.hidden = true;
            }
            syncInquiryWhatsApp(product);
            sheet.classList.add('is-open');
            sheet.setAttribute('aria-hidden', 'false');
        }

        function closeInquiry() {
            if (!sheet) return;
            sheet.classList.remove('is-open');
            sheet.setAttribute('aria-hidden', 'true');
        }

        if (askBtn) {
            askBtn.addEventListener('click', function () {
                openInquiry(currentProduct());
            });
        }
        // Open details when the product image is tapped (layers use pointer-events:none).
        stage.addEventListener('click', function (e) {
            var bottle = e.target.closest ? e.target.closest('.ppe-bottle') : null;
            if (!bottle) return;
            var slide = bottle.closest('.ppe-slide');
            if (!slide) return;
            e.preventDefault();
            e.stopPropagation();
            var i = parseInt(slide.getAttribute('data-index') || '0', 10) || 0;
            if (i !== index && !busy) go(i, i > index ? 1 : -1);
            openDetails(productFromSlide(slide));
        });
        var detailsClose = document.getElementById('details-close');
        var detailsRequest = document.getElementById('details-request');
        if (detailsClose) detailsClose.addEventListener('click', closeDetails);
        if (detailsRequest) {
            detailsRequest.addEventListener('click', function () {
                openInquiry(currentProduct());
            });
        }
        if (detailsSheet) {
            detailsSheet.addEventListener('click', function (e) {
                if (e.target === detailsSheet) closeDetails();
            });
        }
        var closeBtn = document.getElementById('inquiry-close');
        if (closeBtn) closeBtn.addEventListener('click', closeInquiry);
        if (sheet) {
            sheet.addEventListener('click', function (e) {
                if (e.target === sheet) closeInquiry();
            });
        }
        function inquiryProductDraft() {
            return {
                id: parseInt(document.getElementById('inquiry-product-id').value || '0', 10) || 0,
                name: document.getElementById('inquiry-product-name').value || '',
                code: document.getElementById('inquiry-product-code').value || ''
            };
        }
        function bumpQty(delta) {
            var input = document.getElementById('inquiry-qty');
            if (!input) return;
            var next = Math.max(1, Math.min(9999, (parseInt(input.value, 10) || 1) + delta));
            input.value = String(next);
            syncInquiryWhatsApp(inquiryProductDraft());
        }
        var minusBtn = document.getElementById('inquiry-qty-minus');
        var plusBtn = document.getElementById('inquiry-qty-plus');
        if (minusBtn) minusBtn.addEventListener('click', function () { bumpQty(-1); });
        if (plusBtn) plusBtn.addEventListener('click', function () { bumpQty(1); });
        ['inquiry-qty', 'inquiry-message'].forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('input', function () {
                syncInquiryWhatsApp(inquiryProductDraft());
            });
        });
        if (document.getElementById('inquiry-wa') && document.getElementById('inquiry-wa').classList.contains('is-on')) {
            var orEl = document.getElementById('inquiry-or');
            if (orEl) orEl.hidden = false;
        }
        if (inquiryJustSaved || inquiryHadError) {
            var saved = inquirySavedProduct || currentProduct() || { id: 0, name: 'Product', code: '', image: '' };
            if (saved.qty) document.getElementById('inquiry-qty').value = String(saved.qty);
            if (saved.message != null) document.getElementById('inquiry-message').value = String(saved.message || '');
            openInquiry(saved);
            if (inquiryJustSaved && inquiryWaUrl) {
                setTimeout(function () {
                    window.open(inquiryWaUrl, '_blank', 'noopener');
                }, 400);
            }
        }

        /**
         * BCUT-style studio background cut: flood-fill near-white from the image
         * edges so the product sits on the page with no visible photo rectangle.
         */
        function cutStudioBackground(img) {
            if (!img || img.dataset.cutout === '1') return;
            if (!img.naturalWidth || !img.naturalHeight) return;

            function runCut(source) {
                try {
                    var maxSide = 900;
                    var scale = Math.min(1, maxSide / Math.max(source.naturalWidth || source.width, source.naturalHeight || source.height));
                    var w = Math.max(1, Math.round((source.naturalWidth || source.width) * scale));
                    var h = Math.max(1, Math.round((source.naturalHeight || source.height) * scale));
                    var canvas = document.createElement('canvas');
                    canvas.width = w;
                    canvas.height = h;
                    var ctx = canvas.getContext('2d', { willReadFrequently: true });
                    ctx.drawImage(source, 0, 0, w, h);
                    var imageData = ctx.getImageData(0, 0, w, h);
                    var data = imageData.data;
                    var n = w * h;
                    var visited = new Uint8Array(n);
                    var queue = new Int32Array(n);
                    var qh = 0, qt = 0;

                    function lum(i) {
                        return 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
                    }
                    function sat(i) {
                        var r = data[i], g = data[i + 1], b = data[i + 2];
                        return Math.max(r, g, b) - Math.min(r, g, b);
                    }
                    function isBg(i) {
                        // Near-white / light grey studio backdrop (not product whites with contrast neighbors handled by flood)
                        return lum(i) >= 232 && sat(i) <= 28;
                    }
                    function softBg(i) {
                        return lum(i) >= 210 && sat(i) <= 40;
                    }
                    function push(p) {
                        if (p < 0 || p >= n || visited[p]) return;
                        var i = p * 4;
                        // Expand through soft near-white so the flood reaches into the fringe
                        if (!isBg(i) && !softBg(i)) return;
                        visited[p] = 1;
                        queue[qt++] = p;
                    }
                    function pushStrict(p) {
                        if (p < 0 || p >= n || visited[p]) return;
                        if (!isBg(p * 4)) return;
                        visited[p] = 1;
                        queue[qt++] = p;
                    }

                    // Seed from all edge pixels that look like studio white
                    for (var x = 0; x < w; x++) {
                        pushStrict(x);
                        pushStrict((h - 1) * w + x);
                    }
                    for (var y = 0; y < h; y++) {
                        pushStrict(y * w);
                        pushStrict(y * w + (w - 1));
                    }

                    while (qh < qt) {
                        var p = queue[qh++];
                        var px = p % w;
                        var py = (p / w) | 0;
                        if (px > 0) push(p - 1);
                        if (px + 1 < w) push(p + 1);
                        if (py > 0) push(p - w);
                        if (py + 1 < h) push(p + w);
                    }

                    // Soften fringe: any near-white pixel next to removed bg fades out
                    for (var p2 = 0; p2 < n; p2++) {
                        if (!visited[p2]) continue;
                        var i2 = p2 * 4;
                        var L = lum(i2);
                        var S = sat(i2);
                        if (L >= 242 && S <= 18) {
                            data[i2 + 3] = 0;
                        } else if (L >= 220) {
                            var fade = Math.max(0, Math.min(1, (L - 210) / 35));
                            data[i2 + 3] = Math.round(data[i2 + 3] * (1 - fade * 0.92));
                            if (data[i2 + 3] < 18) data[i2 + 3] = 0;
                        } else {
                            data[i2 + 3] = 0;
                        }
                    }

                    // Clean leftover white halo on product edges
                    for (var p3 = 0; p3 < n; p3++) {
                        var i3 = p3 * 4;
                        if (data[i3 + 3] === 0) continue;
                        var x3 = p3 % w;
                        var y3 = (p3 / w) | 0;
                        var clearN = 0;
                        for (var dy = -1; dy <= 1; dy++) {
                            for (var dx = -1; dx <= 1; dx++) {
                                if (!dx && !dy) continue;
                                var nx = x3 + dx, ny = y3 + dy;
                                if (nx < 0 || ny < 0 || nx >= w || ny >= h) { clearN++; continue; }
                                if (data[((ny * w + nx) * 4) + 3] < 20) clearN++;
                            }
                        }
                        if (clearN >= 3 && lum(i3) >= 215 && sat(i3) <= 35) {
                            data[i3 + 3] = Math.round(data[i3 + 3] * 0.15);
                            if (data[i3 + 3] < 24) data[i3 + 3] = 0;
                        }
                    }

                    ctx.putImageData(imageData, 0, 0);
                    img.src = canvas.toDataURL('image/png');
                    img.dataset.cutout = '1';
                    img.classList.add('is-cutout');
                } catch (err) {
                    // Canvas tainted / blocked — leave original image.
                }
            }

            // Prefer the already-decoded DOM image (same-origin, no CORS flag).
            try {
                runCut(img);
                if (img.dataset.cutout === '1') return;
            } catch (e) {}

            var work = new Image();
            work.onload = function () { runCut(work); };
            work.src = img.currentSrc || img.src;
        }

        Array.prototype.forEach.call(stage.querySelectorAll('.ppe-bottle img'), function (img) {
            if (img.complete && img.naturalWidth) cutStudioBackground(img);
            else img.addEventListener('load', function () { cutStudioBackground(img); }, { once: true });
        });
    })();
    </script>
</body>
</html>
