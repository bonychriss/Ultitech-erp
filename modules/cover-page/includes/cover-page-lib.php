<?php

declare(strict_types=1);

function coverPageBootstrap(): PDO
{
    static $booted = false;
    if (!$booted) {
        require_once dirname(__DIR__, 3) . '/includes/functions.php';
        $booted = true;
    }

    global $pdo;
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not available.');
    }

    return $pdo;
}

function coverPageRequireAccess(): void
{
    coverPageBootstrap();
    requireLogin();
}

function coverPagePublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    // Serve assets/API from the real modules/cover-page tree (not tenant wrappers).
    $base = function_exists('app_url') ? rtrim(app_url('/modules/cover-page'), '/') : '/modules/cover-page';

    return $base . '/' . $relativePath;
}

/**
 * @return array{assetBase:string,apiUrl:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function coverPageLoadReactAssets(): ?array
{
    $uiDir = dirname(__DIR__) . '/frontend';
    $distIndex = $uiDir . '/dist/index.html';
    if (!is_file($distIndex)) {
        return null;
    }

    $distHtml = file_get_contents($distIndex) ?: '';
    preg_match('/src="\.\/assets\/([^"]+\.js)"/', $distHtml, $jsMatch);
    preg_match('/href="\.\/assets\/([^"]+\.css)"/', $distHtml, $cssMatch);
    $jsFile = $jsMatch[1] ?? '';
    $cssFile = $cssMatch[1] ?? '';
    if ($jsFile === '' || $cssFile === '') {
        return null;
    }

    $cssPath = $uiDir . '/dist/assets/' . $cssFile;
    $jsPath = $uiDir . '/dist/assets/' . $jsFile;

    return [
        'assetBase' => coverPagePublicUrl('frontend/dist/assets/'),
        'apiUrl' => coverPagePublicUrl('api/index.php'),
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
    ];
}

function coverPageShellHeadExtras(): string
{
    $parts = [
        '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">',
    ];

    if (function_exists('app_url')) {
        $erpStylePath = dirname(__DIR__, 3) . '/assets/css/style.css';
        $erpStyleVer = is_file($erpStylePath) ? (int) filemtime($erpStylePath) : time();
        $parts[] = '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/assets/css/style.css'), ENT_QUOTES, 'UTF-8') . '?v=' . $erpStyleVer . '">';
        if (function_exists('renderSystemFontHeadMarkup')) {
            ob_start();
            renderSystemFontHeadMarkup();
            $fontMarkup = ob_get_clean();
            if (is_string($fontMarkup) && $fontMarkup !== '') {
                $parts[] = trim($fontMarkup);
            }
        }
        if (function_exists('erp_dark_theme_css_url')) {
            $parts[] = '<link rel="stylesheet" id="erp-dark-theme" href="' . htmlspecialchars(erp_dark_theme_css_url(), ENT_QUOTES, 'UTF-8') . '">';
        }
    }

    return implode("\n    ", $parts);
}

function coverPageEnsureSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cover_pages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NULL,
            user_id INT NOT NULL,
            document_name VARCHAR(150) NOT NULL,
            file_no INT NOT NULL,
            file_total INT NOT NULL,
            doc_from INT NOT NULL,
            doc_to INT NOT NULL,
            period_month TINYINT NOT NULL,
            period_year SMALLINT NOT NULL,
            prepared_by_name VARCHAR(150) NOT NULL,
            prepared_by_designation VARCHAR(150) NULL,
            prepared_date DATE NOT NULL,
            reviewed_by_name VARCHAR(150) NULL,
            reviewed_by_designation VARCHAR(150) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_cover_pages_company_user (company_id, user_id),
            KEY idx_cover_pages_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $done = true;
}

function coverPageCompanyId(): int
{
    return function_exists('currentCompanyId') ? (int) currentCompanyId() : (int) ($_SESSION['company_id'] ?? 0);
}

/**
 * @return array{0:string,1:list<int>}
 */
function coverPageCompanyScope(): array
{
    $companyId = coverPageCompanyId();

    return $companyId > 0 ? ['company_id = ?', [$companyId]] : ['company_id IS NULL', []];
}

function coverPageIsAdmin(): bool
{
    return function_exists('isAdmin') ? (bool) isAdmin() : (($_SESSION['role'] ?? '') === 'admin');
}

/**
 * "Prepared by" comes from the logged-in user; users have no designation column,
 * so department (or role) stands in for it.
 *
 * @return array{id:int,name:string,designation:string}
 */
function coverPageCurrentUser(): array
{
    $name = trim((string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
    $designation = '';
    foreach (['job_title', 'position', 'department'] as $key) {
        $value = trim((string) ($_SESSION[$key] ?? ''));
        if ($value !== '') {
            $designation = $value;
            break;
        }
    }
    if ($designation === '') {
        $designation = ucwords(str_replace('_', ' ', trim((string) ($_SESSION['role'] ?? ''))));
    }

    return [
        'id' => (int) ($_SESSION['user_id'] ?? 0),
        'name' => $name,
        'designation' => $designation,
    ];
}

/**
 * @return array<string,mixed>
 */
function coverPageFormatRow(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'user_id' => (int) $row['user_id'],
        'document_name' => (string) $row['document_name'],
        'file_no' => (int) $row['file_no'],
        'file_total' => (int) $row['file_total'],
        'doc_from' => (int) $row['doc_from'],
        'doc_to' => (int) $row['doc_to'],
        'period_month' => (int) $row['period_month'],
        'period_year' => (int) $row['period_year'],
        'prepared_by_name' => (string) $row['prepared_by_name'],
        'prepared_by_designation' => (string) ($row['prepared_by_designation'] ?? ''),
        'prepared_date' => (string) $row['prepared_date'],
        'reviewed_by_name' => (string) ($row['reviewed_by_name'] ?? ''),
        'reviewed_by_designation' => (string) ($row['reviewed_by_designation'] ?? ''),
        'created_at' => (string) $row['created_at'],
    ];
}

/**
 * Users see their own cover pages; admins see every cover page in the company.
 *
 * @return list<array<string,mixed>>
 */
function coverPageList(PDO $pdo): array
{
    coverPageEnsureSchema($pdo);
    [$scopeSql, $params] = coverPageCompanyScope();
    $sql = 'SELECT * FROM cover_pages WHERE ' . $scopeSql;
    if (!coverPageIsAdmin()) {
        $sql .= ' AND user_id = ?';
        $params[] = (int) ($_SESSION['user_id'] ?? 0);
    }
    $sql .= ' ORDER BY created_at DESC, id DESC LIMIT 500';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return array_map('coverPageFormatRow', $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

/**
 * @return array<string,mixed>|null
 */
function coverPageFindEditable(PDO $pdo, int $id): ?array
{
    coverPageEnsureSchema($pdo);
    [$scopeSql, $scopeParams] = coverPageCompanyScope();
    $st = $pdo->prepare('SELECT * FROM cover_pages WHERE id = ? AND ' . $scopeSql . ' LIMIT 1');
    $st->execute(array_merge([$id], $scopeParams));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    if (!coverPageIsAdmin() && (int) $row['user_id'] !== (int) ($_SESSION['user_id'] ?? 0)) {
        return null;
    }

    return $row;
}

/**
 * Document names users can pick; "FILE" is printed under the name on every cover.
 *
 * @return list<string>
 */
function coverPageDocumentNames(): array
{
    return ['Revenue', 'Expenses', 'Logs & Bank Statement'];
}

/**
 * @return array{data:array<string,mixed>,errors:array<string,string>}
 */
function coverPageValidate(array $input): array
{
    $errors = [];
    $text = static function ($key, int $max) use ($input): string {
        $value = trim(preg_replace('/\s+/u', ' ', (string) ($input[$key] ?? '')) ?? '');
        return mb_substr($value, 0, $max);
    };
    $int = static function ($key) use ($input): ?int {
        $raw = trim((string) ($input[$key] ?? ''));
        return preg_match('/^\d{1,6}$/', $raw) ? (int) $raw : null;
    };

    $data = [
        'document_name' => $text('document_name', 150),
        'file_no' => $int('file_no'),
        'file_total' => $int('file_total'),
        'doc_from' => $int('doc_from'),
        'doc_to' => $int('doc_to'),
        'period_month' => $int('period_month'),
        'period_year' => $int('period_year'),
        'reviewed_by_name' => $text('reviewed_by_name', 150),
        'reviewed_by_designation' => $text('reviewed_by_designation', 150),
    ];

    $chosenName = null;
    foreach (coverPageDocumentNames() as $allowed) {
        if (strcasecmp($allowed, $data['document_name']) === 0) {
            $chosenName = $allowed;
            break;
        }
    }
    if ($chosenName === null) {
        $errors['document_name'] = 'Choose the name of the document.';
    } else {
        $data['document_name'] = $chosenName;
    }
    if ($data['file_no'] === null || $data['file_no'] < 1) {
        $errors['file_no'] = 'Enter the file number.';
    }
    if ($data['file_total'] === null || $data['file_total'] < 1) {
        $errors['file_total'] = 'Enter the total number of files.';
    } elseif ($data['file_no'] !== null && $data['file_no'] > $data['file_total']) {
        $errors['file_no'] = 'File number cannot be more than the total files.';
    }
    if ($data['doc_from'] === null || $data['doc_from'] < 1) {
        $errors['doc_from'] = 'Enter the first document number.';
    }
    if ($data['doc_to'] === null || $data['doc_to'] < 1) {
        $errors['doc_to'] = 'Enter the last document number.';
    } elseif ($data['doc_from'] !== null && $data['doc_to'] < $data['doc_from']) {
        $errors['doc_to'] = 'Last document number cannot be lower than the first.';
    }
    if ($data['period_month'] === null || $data['period_month'] < 1 || $data['period_month'] > 12) {
        $errors['period_month'] = 'Choose the month.';
    }
    if ($data['period_year'] === null || $data['period_year'] < 2000 || $data['period_year'] > 2100) {
        $errors['period_year'] = 'Enter a valid year.';
    }

    return ['data' => $data, 'errors' => $errors];
}

function coverPageSave(PDO $pdo, array $data, ?int $id = null): int
{
    coverPageEnsureSchema($pdo);
    $reviewedName = $data['reviewed_by_name'] !== '' ? $data['reviewed_by_name'] : null;
    $reviewedDesignation = $data['reviewed_by_designation'] !== '' ? $data['reviewed_by_designation'] : null;

    if ($id !== null) {
        $st = $pdo->prepare('UPDATE cover_pages SET document_name = ?, file_no = ?, file_total = ?, doc_from = ?, doc_to = ?,
            period_month = ?, period_year = ?, reviewed_by_name = ?, reviewed_by_designation = ? WHERE id = ?');
        $st->execute([
            $data['document_name'], $data['file_no'], $data['file_total'], $data['doc_from'], $data['doc_to'],
            $data['period_month'], $data['period_year'], $reviewedName, $reviewedDesignation, $id,
        ]);

        return $id;
    }

    $user = coverPageCurrentUser();
    $companyId = coverPageCompanyId();
    $st = $pdo->prepare('INSERT INTO cover_pages (company_id, user_id, document_name, file_no, file_total, doc_from, doc_to,
        period_month, period_year, prepared_by_name, prepared_by_designation, prepared_date, reviewed_by_name, reviewed_by_designation)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?)');
    $st->execute([
        $companyId > 0 ? $companyId : null, $user['id'], $data['document_name'], $data['file_no'], $data['file_total'],
        $data['doc_from'], $data['doc_to'], $data['period_month'], $data['period_year'],
        $user['name'], $user['designation'] !== '' ? $user['designation'] : null, $reviewedName, $reviewedDesignation,
    ]);

    return (int) $pdo->lastInsertId();
}

function coverPageFetchPayload(PDO $pdo): array
{
    return [
        'user' => coverPageCurrentUser(),
        'isAdmin' => coverPageIsAdmin(),
        'documentNames' => coverPageDocumentNames(),
        'today' => date('Y-m-d'),
        'covers' => coverPageList($pdo),
        'links' => [
            'modules' => function_exists('company_url') ? company_url('select-module') : '/select-module.php',
        ],
    ];
}
