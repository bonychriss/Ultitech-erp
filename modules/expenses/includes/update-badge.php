<?php

declare(strict_types=1);

/**
 * Bump when shipping a notable expenses module update (badge shows for 24 hours).
 */
const EXPENSES_MODULE_UPDATE_RELEASED_AT = '2026-06-30T12:00:00';

/**
 * @return array{label:string,type:string,expiresAt:string}|null
 */
function expenses_module_update_badge(): ?array
{
    static $cached = null;
    static $resolved = false;
    if ($resolved) {
        return $cached;
    }
    $resolved = true;

    try {
        $releasedAt = new DateTimeImmutable(EXPENSES_MODULE_UPDATE_RELEASED_AT);
        $expiresAt = $releasedAt->modify('+24 hours');
        $now = new DateTimeImmutable('now', $releasedAt->getTimezone());
        if ($now >= $expiresAt) {
            $cached = null;

            return null;
        }

        $cached = [
            'label' => 'New',
            'type' => 'update',
            'expiresAt' => $expiresAt->format(DateTimeInterface::ATOM),
        ];
    } catch (Throwable $e) {
        $cached = null;
    }

    return $cached;
}

/**
 * @param array{label?:string,type?:string}|string|null $badge
 */
function expenses_module_update_badge_html($badge = null): string
{
    if (is_string($badge) && $badge !== '') {
        return '<span class="badge bg-danger ms-2">' . htmlspecialchars($badge) . '</span>';
    }

    if (!is_array($badge) || empty($badge['label'])) {
        return '';
    }

    $label = htmlspecialchars((string) $badge['label']);
    $type = (string) ($badge['type'] ?? 'update');
    $class = $type === 'update' ? 'exp-module-update-badge' : 'badge bg-danger ms-2';

    return '<span class="' . $class . '" title="Recently updated">'
        . $label
        . '</span>';
}
