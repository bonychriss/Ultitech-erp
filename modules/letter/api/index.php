<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/functions.php';
requireLogin();

require_once dirname(__DIR__) . '/includes/letter-lib.php';
require_once dirname(__DIR__) . '/includes/letter-approval.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = letterResolvePdo();
if (!($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database unavailable.']);
    exit;
}

try {
    letterApprovalEnsureTable($pdo);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not prepare letter storage.']);
    exit;
}

$companyId = (int) ($_SESSION['company_id'] ?? 0);
$companySlug = strtolower(trim((string) ($_SESSION['company_slug'] ?? '')));
$userId = (int) ($_SESSION['user_id'] ?? 0);
$userName = trim((string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
$userTitle = trim((string) ($_SESSION['department'] ?? $_SESSION['job_title'] ?? ''));
$isAdmin = function_exists('isAdmin') && isAdmin();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string) ($_GET['action'] ?? $_POST['action'] ?? '')));

$input = [];
if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $input = $decoded;
        }
    }
    if (!$input) {
        $input = $_POST;
    }
    if ($action === '' && isset($input['action'])) {
        $action = strtolower(trim((string) $input['action']));
    }
}

function letterApiJson(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function letterApiFind(PDO $pdo, string $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM letter_documents WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function letterApiCanView(array $row, int $userId, bool $isAdmin, int $companyId, string $companySlug): bool
{
    $rowCompany = (int) ($row['company_id'] ?? 0);
    $rowSlug = strtolower(trim((string) ($row['company_slug'] ?? '')));
    if ($companyId > 0 && $rowCompany > 0 && $rowCompany !== $companyId) {
        return false;
    }
    if ($companySlug !== '' && $rowSlug !== '' && $rowSlug !== $companySlug) {
        return false;
    }
    if ($isAdmin) {
        return true;
    }
    if ((int) ($row['author_id'] ?? 0) === $userId) {
        return true;
    }
    // Pending letters visible to any company admin already covered; staff see own only.
    return false;
}

if ($action === '' || $action === 'list') {
    $mine = [];
    $pending = [];
    try {
        if ($companyId > 0) {
            $stmt = $pdo->prepare(
                'SELECT * FROM letter_documents WHERE company_id = ? AND author_id = ? ORDER BY updated_at DESC LIMIT 500'
            );
            $stmt->execute([$companyId, $userId]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT * FROM letter_documents WHERE company_slug = ? AND author_id = ? ORDER BY updated_at DESC LIMIT 500'
            );
            $stmt->execute([$companySlug, $userId]);
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $mine[] = letterApprovalRowToClient($row);
        }

        if ($isAdmin) {
            if ($companyId > 0) {
                $stmt = $pdo->prepare(
                    "SELECT * FROM letter_documents WHERE company_id = ? AND status = 'pending' ORDER BY updated_at DESC LIMIT 500"
                );
                $stmt->execute([$companyId]);
            } else {
                $stmt = $pdo->prepare(
                    "SELECT * FROM letter_documents WHERE company_slug = ? AND status = 'pending' ORDER BY updated_at DESC LIMIT 500"
                );
                $stmt->execute([$companySlug]);
            }
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $pending[] = letterApprovalRowToClient($row);
            }
        }
    } catch (Throwable $e) {
        letterApiJson(['ok' => false, 'error' => 'Failed to list letters.'], 500);
    }
    letterApiJson([
        'ok' => true,
        'letters' => $mine,
        'pending' => $pending,
        'isAdmin' => $isAdmin,
    ]);
}

if ($action === 'get') {
    $id = trim((string) ($_GET['id'] ?? $input['id'] ?? ''));
    if ($id === '') {
        letterApiJson(['ok' => false, 'error' => 'Missing id.'], 400);
    }
    $row = letterApiFind($pdo, $id);
    if (!$row || !letterApiCanView($row, $userId, $isAdmin, $companyId, $companySlug)) {
        letterApiJson(['ok' => false, 'error' => 'Letter not found.'], 404);
    }
    letterApiJson(['ok' => true, 'letter' => letterApprovalRowToClient($row)]);
}

if ($action === 'save' || $action === 'submit') {
    if ($method !== 'POST') {
        letterApiJson(['ok' => false, 'error' => 'POST required.'], 405);
    }
    $id = trim((string) ($input['id'] ?? ''));
    if ($id === '') {
        letterApiJson(['ok' => false, 'error' => 'Missing id.'], 400);
    }
    $form = $input['form'] ?? [];
    if (!is_array($form)) {
        $form = [];
    }
    $title = trim((string) ($input['title'] ?? ''));
    if ($title === '') {
        $subject = trim((string) ($form['subject'] ?? ''));
        $title = $subject !== '' ? $subject : 'Untitled letter';
    }
    $visibility = strtolower(trim((string) ($input['visibility'] ?? 'private'))) === 'public' ? 'public' : 'private';
    $authorName = trim((string) ($input['authorName'] ?? $form['signName'] ?? $userName));
    $now = date('Y-m-d H:i:s');
    $existing = letterApiFind($pdo, $id);

    if ($existing) {
        if (!letterApiCanView($existing, $userId, $isAdmin, $companyId, $companySlug)) {
            letterApiJson(['ok' => false, 'error' => 'Letter not found.'], 404);
        }
        $isAuthor = (int) ($existing['author_id'] ?? 0) === $userId;
        if (!$isAuthor && !$isAdmin) {
            letterApiJson(['ok' => false, 'error' => 'Only the author can edit this letter.'], 403);
        }
        $status = letterApprovalNormalizeStatus($existing['status'] ?? 'draft');
        if ($action === 'submit') {
            if ($status === 'approved' && !$isAdmin) {
                letterApiJson(['ok' => false, 'error' => 'Approved letters cannot be re-submitted.'], 400);
            }
            $status = 'pending';
        } elseif ($status === 'approved' && !$isAdmin) {
            // Author edits after approval ? back to draft (stamp removed until re-approved).
            $status = 'draft';
        } elseif ($status === 'rejected' && $action !== 'submit') {
            $status = 'draft';
        }

        $approverClear = '';
        if ($status !== 'approved') {
            $approverClear = ', approver_id = NULL, approver_name = NULL, approver_title = NULL,
                approver_signature_url = NULL, approved_at = NULL';
        }

        $sql = "UPDATE letter_documents SET
            title = ?, form_json = ?, visibility = ?, status = ?,
            author_name = ?, updated_at = ?{$approverClear}
            WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $title,
            json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $visibility,
            $status,
            $authorName !== '' ? $authorName : $userName,
            $now,
            $id,
        ]);
    } else {
        $status = $action === 'submit' ? 'pending' : 'draft';
        $createdAt = trim((string) ($input['createdAt'] ?? ''));
        if ($createdAt === '') {
            $createdAt = $now;
        } else {
            $ts = strtotime($createdAt);
            $createdAt = $ts ? date('Y-m-d H:i:s', $ts) : $now;
        }
        $stmt = $pdo->prepare(
            'INSERT INTO letter_documents
            (id, company_id, company_slug, author_id, author_name, title, form_json, visibility, status, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $id,
            $companyId,
            $companySlug,
            $userId,
            $authorName !== '' ? $authorName : $userName,
            $title,
            json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $visibility,
            $status,
            $createdAt,
            $now,
        ]);
    }

    $row = letterApiFind($pdo, $id);
    letterApiJson(['ok' => true, 'letter' => letterApprovalRowToClient($row ?: [])]);
}

if ($action === 'approve') {
    if ($method !== 'POST') {
        letterApiJson(['ok' => false, 'error' => 'POST required.'], 405);
    }
    if (!$isAdmin) {
        letterApiJson(['ok' => false, 'error' => 'Only an admin can approve letters.'], 403);
    }
    $id = trim((string) ($input['id'] ?? ''));
    if ($id === '') {
        letterApiJson(['ok' => false, 'error' => 'Missing id.'], 400);
    }
    $row = letterApiFind($pdo, $id);
    if (!$row || !letterApiCanView($row, $userId, $isAdmin, $companyId, $companySlug)) {
        letterApiJson(['ok' => false, 'error' => 'Letter not found.'], 404);
    }
    $status = letterApprovalNormalizeStatus($row['status'] ?? '');
    if ($status !== 'pending' && $status !== 'draft') {
        // Allow re-approve from draft/pending; approved is idempotent.
        if ($status !== 'approved') {
            letterApiJson(['ok' => false, 'error' => 'Letter is not awaiting approval.'], 400);
        }
    }

    $approverName = $userName !== '' ? $userName : 'Administrator';
    $approverTitle = $userTitle !== '' ? $userTitle : 'Administrator';
    $approverSig = letterApprovalResolveSignatureUrl($userId);
    $now = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        "UPDATE letter_documents SET
            status = 'approved',
            approver_id = ?,
            approver_name = ?,
            approver_title = ?,
            approver_signature_url = ?,
            approved_at = ?,
            updated_at = ?
         WHERE id = ?"
    );
    $stmt->execute([$userId, $approverName, $approverTitle, $approverSig, $now, $now, $id]);

    $fresh = letterApiFind($pdo, $id);
    letterApiJson(['ok' => true, 'letter' => letterApprovalRowToClient($fresh ?: [])]);
}

if ($action === 'reject') {
    if ($method !== 'POST') {
        letterApiJson(['ok' => false, 'error' => 'POST required.'], 405);
    }
    if (!$isAdmin) {
        letterApiJson(['ok' => false, 'error' => 'Only an admin can reject letters.'], 403);
    }
    $id = trim((string) ($input['id'] ?? ''));
    if ($id === '') {
        letterApiJson(['ok' => false, 'error' => 'Missing id.'], 400);
    }
    $row = letterApiFind($pdo, $id);
    if (!$row || !letterApiCanView($row, $userId, $isAdmin, $companyId, $companySlug)) {
        letterApiJson(['ok' => false, 'error' => 'Letter not found.'], 404);
    }
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        "UPDATE letter_documents SET
            status = 'rejected',
            approver_id = NULL,
            approver_name = NULL,
            approver_title = NULL,
            approver_signature_url = NULL,
            approved_at = NULL,
            updated_at = ?
         WHERE id = ?"
    );
    $stmt->execute([$now, $id]);
    $fresh = letterApiFind($pdo, $id);
    letterApiJson(['ok' => true, 'letter' => letterApprovalRowToClient($fresh ?: [])]);
}

if ($action === 'delete') {
    if ($method !== 'POST') {
        letterApiJson(['ok' => false, 'error' => 'POST required.'], 405);
    }
    $id = trim((string) ($input['id'] ?? ''));
    if ($id === '') {
        letterApiJson(['ok' => false, 'error' => 'Missing id.'], 400);
    }
    $row = letterApiFind($pdo, $id);
    if (!$row || !letterApiCanView($row, $userId, $isAdmin, $companyId, $companySlug)) {
        letterApiJson(['ok' => false, 'error' => 'Letter not found.'], 404);
    }
    $isAuthor = (int) ($row['author_id'] ?? 0) === $userId;
    if (!$isAuthor && !$isAdmin) {
        letterApiJson(['ok' => false, 'error' => 'Not allowed.'], 403);
    }
    $stmt = $pdo->prepare('DELETE FROM letter_documents WHERE id = ?');
    $stmt->execute([$id]);
    letterApiJson(['ok' => true]);
}

letterApiJson(['ok' => false, 'error' => 'Unknown action.'], 400);
