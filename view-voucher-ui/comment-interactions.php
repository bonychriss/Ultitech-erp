<?php
declare(strict_types=1);

/**
 * Reactions + threaded replies under approval_logs comments (view voucher).
 */

function vv_approval_reaction_catalog(): array
{
    return [
        'thumbs_up' => ['emoji' => '👍', 'label' => 'Helpful'],
        'check' => ['emoji' => '✅', 'label' => 'Acknowledged'],
        'eyes' => ['emoji' => '👀', 'label' => 'Seen'],
    ];
}

function vv_ensure_approval_comment_tables(?PDO $explicitPdo = null): bool
{
    global $pdo;
    $usePdo = $explicitPdo instanceof PDO ? $explicitPdo : (($pdo instanceof PDO) ? $pdo : null);
    if (!($usePdo instanceof PDO)) {
        return false;
    }
    try {
        if (!function_exists('tableExists') || !tableExists('approval_comment_reactions', $usePdo)) {
            $usePdo->exec(
                "CREATE TABLE IF NOT EXISTS approval_comment_reactions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    approval_log_id INT NOT NULL,
                    voucher_id INT NOT NULL,
                    user_id INT NOT NULL,
                    reaction VARCHAR(32) NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_acr_user_reaction (approval_log_id, user_id, reaction),
                    KEY idx_acr_voucher (voucher_id),
                    KEY idx_acr_log (approval_log_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        if (!function_exists('tableExists') || !tableExists('approval_comment_replies', $usePdo)) {
            $usePdo->exec(
                "CREATE TABLE IF NOT EXISTS approval_comment_replies (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    approval_log_id INT NOT NULL,
                    voucher_id INT NOT NULL,
                    user_id INT NOT NULL,
                    body TEXT NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_acrep_log (approval_log_id),
                    KEY idx_acrep_voucher (voucher_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        return true;
    } catch (Throwable $e) {
        error_log('vv_ensure_approval_comment_tables: ' . $e->getMessage());
        return false;
    }
}

function vv_load_comment_reactions(PDO $pdo, array $logIds, int $viewerId): array
{
    $out = [];
    foreach ($logIds as $id) {
        $out[(int) $id] = [];
    }
    if ($logIds === [] || !function_exists('tableExists') || !tableExists('approval_comment_reactions', $pdo)) {
        return $out;
    }
    $placeholders = implode(',', array_fill(0, count($logIds), '?'));
    try {
        $stmt = $pdo->prepare(
            "SELECT approval_log_id, reaction, user_id
             FROM approval_comment_reactions
             WHERE approval_log_id IN ($placeholders)"
        );
        $stmt->execute(array_values($logIds));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return $out;
    }
    $catalog = vv_approval_reaction_catalog();
    $bucket = [];
    foreach ($rows as $row) {
        $logId = (int) ($row['approval_log_id'] ?? 0);
        $key = (string) ($row['reaction'] ?? '');
        if ($logId <= 0 || !isset($catalog[$key])) {
            continue;
        }
        if (!isset($bucket[$logId][$key])) {
            $bucket[$logId][$key] = ['count' => 0, 'mine' => false];
        }
        $bucket[$logId][$key]['count']++;
        if ((int) ($row['user_id'] ?? 0) === $viewerId) {
            $bucket[$logId][$key]['mine'] = true;
        }
    }
    foreach ($bucket as $logId => $byKey) {
        $list = [];
        foreach ($catalog as $key => $meta) {
            if (empty($byKey[$key]['count'])) {
                continue;
            }
            $list[] = [
                'key' => $key,
                'emoji' => $meta['emoji'],
                'label' => $meta['label'],
                'count' => (int) $byKey[$key]['count'],
                'mine' => !empty($byKey[$key]['mine']),
            ];
        }
        $out[$logId] = $list;
    }
    return $out;
}

function vv_actor_display_name(PDO $pdo, int $userId): string
{
    $name = trim((string) ($_SESSION['full_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    if ($userId <= 0) {
        return 'Someone';
    }
    try {
        $st = $pdo->prepare('SELECT full_name, username FROM users WHERE id = ? LIMIT 1');
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $name = trim((string) ($row['full_name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($row['username'] ?? ''));
        }
    } catch (Throwable $e) {
        $name = '';
    }
    return $name !== '' ? $name : 'Someone';
}

function vv_voucher_display_no(PDO $pdo, int $voucherId): string
{
    if ($voucherId <= 0) {
        return '';
    }
    try {
        $st = $pdo->prepare('SELECT voucher_no FROM payment_vouchers WHERE id = ? LIMIT 1');
        $st->execute([$voucherId]);
        return trim((string) ($st->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Notify comment author (and voucher creator when different) about a reaction or reply.
 */
function vv_notify_approval_comment_activity(
    PDO $pdo,
    int $voucherId,
    int $approvalLogId,
    int $actorUserId,
    string $kind,
    string $detail = ''
): void {
    if ($voucherId <= 0 || $approvalLogId <= 0 || $actorUserId <= 0 || !function_exists('createNotification')) {
        return;
    }
    try {
        $st = $pdo->prepare(
            'SELECT user_id, comments FROM approval_logs WHERE id = ? AND voucher_id = ? LIMIT 1'
        );
        $st->execute([$approvalLogId, $voucherId]);
        $log = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$log) {
            return;
        }

        $recipientIds = [];
        $commentAuthorId = (int) ($log['user_id'] ?? 0);
        if ($commentAuthorId > 0 && $commentAuthorId !== $actorUserId) {
            $recipientIds[$commentAuthorId] = true;
        }
        try {
            $vst = $pdo->prepare('SELECT created_by FROM payment_vouchers WHERE id = ? LIMIT 1');
            $vst->execute([$voucherId]);
            $creatorId = (int) ($vst->fetchColumn() ?: 0);
            if ($creatorId > 0 && $creatorId !== $actorUserId) {
                $recipientIds[$creatorId] = true;
            }
        } catch (Throwable $e) {
            /* ignore */
        }
        if ($recipientIds === []) {
            return;
        }

        $actorName = vv_actor_display_name($pdo, $actorUserId);
        $voucherNo = vv_voucher_display_no($pdo, $voucherId);
        $voucherBit = $voucherNo !== '' ? ' on voucher ' . $voucherNo : '';
        $snippet = trim((string) ($log['comments'] ?? ''));
        if (function_exists('mb_strlen') && mb_strlen($snippet) > 80) {
            $snippet = mb_substr($snippet, 0, 77) . '…';
        } elseif (strlen($snippet) > 80) {
            $snippet = substr($snippet, 0, 77) . '…';
        }

        if ($kind === 'reaction') {
            $catalog = vv_approval_reaction_catalog();
            $label = (string) ($catalog[$detail]['label'] ?? 'a reaction');
            $emoji = (string) ($catalog[$detail]['emoji'] ?? '');
            $title = 'Voucher comment reaction';
            $message = $actorName . ' reacted ' . ($emoji !== '' ? $emoji . ' ' : '') . '(' . $label . ')'
                . $voucherBit . '.'
                . ($snippet !== '' ? ' Comment: "' . $snippet . '"' : '');
            $type = 'info';
        } else {
            $title = 'Voucher comment reply';
            $replyBit = trim($detail);
            if (function_exists('mb_strlen') && mb_strlen($replyBit) > 120) {
                $replyBit = mb_substr($replyBit, 0, 117) . '…';
            } elseif (strlen($replyBit) > 120) {
                $replyBit = substr($replyBit, 0, 117) . '…';
            }
            $message = $actorName . ' replied' . $voucherBit . '.'
                . ($replyBit !== '' ? ' "' . $replyBit . '"' : '');
            $type = 'info';
        }

        foreach (array_keys($recipientIds) as $targetUserId) {
            createNotification([
                'user_id' => (int) $targetUserId,
                'audience' => 'user',
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'voucher_id' => $voucherId,
            ]);
        }
    } catch (Throwable $e) {
        error_log('vv_notify_approval_comment_activity: ' . $e->getMessage());
    }
}

function vv_load_comment_replies(PDO $pdo, array $logIds): array
{
    $out = [];
    foreach ($logIds as $id) {
        $out[(int) $id] = [];
    }
    if ($logIds === [] || !function_exists('tableExists') || !tableExists('approval_comment_replies', $pdo)) {
        return $out;
    }
    $placeholders = implode(',', array_fill(0, count($logIds), '?'));
    try {
        $stmt = $pdo->prepare(
            "SELECT r.id, r.approval_log_id, r.user_id, r.body, r.created_at, u.full_name
             FROM approval_comment_replies r
             LEFT JOIN users u ON u.id = r.user_id
             WHERE r.approval_log_id IN ($placeholders)
             ORDER BY r.created_at ASC, r.id ASC"
        );
        $stmt->execute(array_values($logIds));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return $out;
    }
    foreach ($rows as $row) {
        $logId = (int) ($row['approval_log_id'] ?? 0);
        if ($logId <= 0) {
            continue;
        }
        $out[$logId][] = [
            'id' => (int) ($row['id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'full_name' => (string) ($row['full_name'] ?? 'User'),
            'body' => (string) ($row['body'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
    return $out;
}
