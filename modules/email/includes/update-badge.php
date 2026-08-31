<?php

declare(strict_types=1);

/**
 * Bump when shipping a notable Mail module update.
 * Badge + guideline stay active until EXPENSES-style window ends (14 days).
 */
const EMAIL_MODULE_UPDATE_VERSION = '2026-08-10-attachments';
const EMAIL_MODULE_UPDATE_RELEASED_AT = '2026-08-10T12:00:00';
const EMAIL_MODULE_UPDATE_WINDOW_DAYS = 14;

/**
 * @return array{label:string,type:string,expiresAt:string,version:string}|null
 */
function email_module_update_badge(): ?array
{
    static $cached = null;
    static $resolved = false;
    if ($resolved) {
        return $cached;
    }
    $resolved = true;

    try {
        $releasedAt = new DateTimeImmutable(EMAIL_MODULE_UPDATE_RELEASED_AT);
        $expiresAt = $releasedAt->modify('+' . (int) EMAIL_MODULE_UPDATE_WINDOW_DAYS . ' days');
        $now = new DateTimeImmutable('now', $releasedAt->getTimezone());
        if ($now >= $expiresAt) {
            $cached = null;

            return null;
        }

        $cached = [
            'label' => 'New',
            'type' => 'update',
            'version' => EMAIL_MODULE_UPDATE_VERSION,
            'expiresAt' => $expiresAt->format(DateTimeInterface::ATOM),
        ];
    } catch (Throwable $e) {
        $cached = null;
    }

    return $cached;
}

/**
 * @return list<string>
 */
function email_module_update_highlights(): array
{
    return [
        'Attach files when composing or replying to messages',
        'Inbound attachments sync from the company mailbox',
        'Company logo appears at the top of outbound mail',
        'Cleaner Mail desk for inbox, sent, and replies',
    ];
}

/**
 * Config blob for select-module React UI.
 *
 * @return array<string,mixed>|null
 */
function email_module_update_campaign(bool $returnedFromEmail = false): ?array
{
    $badge = email_module_update_badge();
    if ($badge === null) {
        return null;
    }

    $version = (string) ($badge['version'] ?? EMAIL_MODULE_UPDATE_VERSION);
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $ratedVersion = (string) ($_SESSION['email_update_rated_version'] ?? '');
    $visitedVersion = (string) ($_SESSION['email_update_visited_version'] ?? '');
    $alreadyRated = $ratedVersion === $version;
    $visitedThisVersion = $visitedVersion === $version;

    $rateApi = function_exists('company_url')
        ? company_url('modules/email/api/rate_update.php')
        : (function_exists('app_url')
            ? app_url('/modules/email/api/rate_update.php')
            : 'modules/email/api/rate_update.php');

    $mailHref = function_exists('company_url')
        ? (company_url('modules/email/index') . '?module=email&folder=inbox')
        : (function_exists('app_url')
            ? (app_url('/modules/email/index.php') . '?module=email&folder=inbox')
            : 'modules/email/index.php?module=email&folder=inbox');

    return [
        'active' => true,
        'version' => $version,
        'userId' => $userId,
        'badge' => (string) ($badge['label'] ?? 'New'),
        'expiresAt' => (string) ($badge['expiresAt'] ?? ''),
        'mailHref' => $mailHref,
        'rateApi' => $rateApi,
        'highlights' => email_module_update_highlights(),
        'guideline' => [
            'title' => 'Mail app update',
            'body' => 'New features are ready in Mail. Open it for a quick look.',
            'cta' => 'Review Mail',
            'dismiss' => 'Maybe later',
        ],
        'rating' => [
            'ask' => $returnedFromEmail && $visitedThisVersion && !$alreadyRated,
            'title' => 'How was the Mail update?',
            'body' => 'Thanks for reviewing Mail. Rate this improvement so we can keep refining it.',
            'submit' => 'Submit rating',
            'skip' => 'Not now',
        ],
    ];
}

function email_module_mark_update_visited(): void
{
    $badge = email_module_update_badge();
    if ($badge === null) {
        return;
    }
    $_SESSION['email_update_visited_version'] = (string) ($badge['version'] ?? EMAIL_MODULE_UPDATE_VERSION);
}

function email_module_mark_update_rated(): void
{
    $badge = email_module_update_badge();
    $version = $badge !== null
        ? (string) ($badge['version'] ?? EMAIL_MODULE_UPDATE_VERSION)
        : EMAIL_MODULE_UPDATE_VERSION;
    $_SESSION['email_update_rated_version'] = $version;
}
