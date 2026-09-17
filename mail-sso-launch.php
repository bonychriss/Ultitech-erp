<?php

declare(strict_types=1);

/**
 * ERP ? Mail SSO launcher.
 * Requires an Ultitech session, then redirects to the company Mail app with a short-lived token.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/mail-sso.php';

requireLogin();

$slug = strtolower(trim((string) (
    $_GET['company']
    ?? $_SESSION['company_slug']
    ?? (function_exists('getRequestedCompanySlug') ? getRequestedCompanySlug() : '')
)));

$target = mail_sso_target_url($slug);
if ($target === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Mail SSO is not configured for this company.';
    exit;
}

$email = trim((string) ($_SESSION['email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    // Fall back to username@ if email missing — still need a real mailbox email for mail app user.
    $username = trim((string) ($_SESSION['username'] ?? ''));
    if ($username !== '' && filter_var($username, FILTER_VALIDATE_EMAIL)) {
        $email = $username;
    }
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Your Ultitech account has no email address. Add one in Account settings, then open Mail again.';
    exit;
}

$token = mail_sso_create_token([
    'user_id' => (int) ($_SESSION['user_id'] ?? 0),
    'email' => $email,
    'name' => (string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User'),
    'username' => (string) ($_SESSION['username'] ?? ''),
    'company' => $slug,
], 90);

$sep = str_contains($target, '?') ? '&' : '?';
$dest = $target . $sep . 'sso=' . rawurlencode($token);

header('Location: ' . $dest, true, 302);
exit;
