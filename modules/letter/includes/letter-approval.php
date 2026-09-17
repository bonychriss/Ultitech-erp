<?php
declare(strict_types=1);

/**
 * Server-backed letter documents + approval (stamp only when approved).
 */

function letterApprovalEnsureTable(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS letter_documents (
            id VARCHAR(64) NOT NULL,
            company_id INT NOT NULL DEFAULT 0,
            company_slug VARCHAR(64) NOT NULL DEFAULT '',
            author_id INT NOT NULL DEFAULT 0,
            author_name VARCHAR(255) NOT NULL DEFAULT '',
            title VARCHAR(500) NOT NULL DEFAULT '',
            form_json LONGTEXT NULL,
            visibility VARCHAR(20) NOT NULL DEFAULT 'private',
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            approver_id INT NULL,
            approver_name VARCHAR(255) NULL,
            approver_title VARCHAR(255) NULL,
            approver_signature_url VARCHAR(500) NULL,
            approved_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_letter_company_status (company_id, status),
            KEY idx_letter_author (author_id),
            KEY idx_letter_slug (company_slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $done = true;
}

function letterApprovalNormalizeStatus(?string $status): string
{
    $s = strtolower(trim((string) $status));
    if (in_array($s, ['draft', 'pending', 'approved', 'rejected'], true)) {
        return $s;
    }
    return 'draft';
}

function letterApprovalResolveSignatureUrl(int $userId): string
{
    if ($userId <= 0 || !function_exists('getUserSignaturePathById')) {
        return '';
    }
    $sigPath = getUserSignaturePathById($userId);
    if (!is_string($sigPath) || $sigPath === '') {
        return '';
    }
    $signatureUrl = '';
    if (function_exists('mediaUrlFromPath')) {
        $signatureUrl = (string) mediaUrlFromPath($sigPath);
    } elseif (function_exists('app_url')) {
        $signatureUrl = rtrim((string) app_url('/' . ltrim($sigPath, '/')), '/');
    } else {
        $signatureUrl = '/' . ltrim($sigPath, '/');
    }
    if ($signatureUrl !== '') {
        $sigFs = dirname(__DIR__, 3) . '/' . ltrim(str_replace('\\', '/', $sigPath), '/');
        $sigVer = @filemtime($sigFs) ?: time();
        $signatureUrl .= (strpos($signatureUrl, '?') === false ? '?' : '&') . 'v=' . (int) $sigVer;
    }
    return $signatureUrl;
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function letterApprovalRowToClient(array $row): array
{
    $form = [];
    $raw = (string) ($row['form_json'] ?? '');
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $form = $decoded;
        }
    }
    $status = letterApprovalNormalizeStatus($row['status'] ?? 'draft');
    return [
        'id' => (string) ($row['id'] ?? ''),
        'title' => (string) ($row['title'] ?? ''),
        'form' => $form,
        'authorName' => (string) ($row['author_name'] ?? ''),
        'authorId' => (int) ($row['author_id'] ?? 0),
        'visibility' => strtolower((string) ($row['visibility'] ?? 'private')) === 'public' ? 'public' : 'private',
        'status' => $status,
        'approverId' => (int) ($row['approver_id'] ?? 0) ?: null,
        'approverName' => (string) ($row['approver_name'] ?? ''),
        'approverTitle' => (string) ($row['approver_title'] ?? ''),
        'approverSignatureUrl' => (string) ($row['approver_signature_url'] ?? ''),
        'approvedAt' => (string) ($row['approved_at'] ?? ''),
        'createdAt' => (string) ($row['created_at'] ?? ''),
        'updatedAt' => (string) ($row['updated_at'] ?? ''),
    ];
}
