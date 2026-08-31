<?php
/**
 * Sales Report Document System � core library.
 * Editable document-based sales reports with ERP data integration.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/functions.php';

// ??? Bootstrap ???????????????????????????????????????????????????????????????

function salesReportsBootstrap(): PDO
{
    static $booted = false;
    if (!$booted) {
        salesReportsEnsureSchema();
        $booted = true;
    }
    global $pdo;
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not available.');
    }
    return $pdo;
}

function salesReportsRequireAccess(string $permission = 'view'): void
{
    salesReportsBootstrap();
    requireLogin();
    if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
        $_GET['module'] = 'analytics';
    }
    $_SESSION['active_module'] = 'analytics';

    if (!salesReportsCan($permission)) {
        if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
            || !empty($_GET['json']) || !empty($_POST['json'])) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied.']);
            exit;
        }
        header('Location: ' . salesReportsUrl('index.php', ['error' => 'access_denied']));
        exit;
    }
}

function salesReportsUrl(string $path, array $query = []): string
{
    $path = ltrim(str_replace('\\', '/', $path), '/');
    if (!isset($query['module'])) {
        $query['module'] = 'analytics';
    }
    $base = function_exists('app_url')
        ? app_url('/modules/sales-reports/' . $path)
        : '../../modules/sales-reports/' . $path;
    if ($query !== []) {
        $base .= (str_contains($base, '?') ? '&' : '?') . http_build_query($query);
    }
    return $base;
}

function salesReportsApiUrl(string $file, array $query = []): string
{
    return salesReportsUrl('api/' . ltrim($file, '/'), $query);
}

function salesReportsCompanyId(): int
{
    return (int) ($_SESSION['company_id'] ?? 0);
}

function salesReportsUserId(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}

// ??? Permissions ?????????????????????????????????????????????????????????????

function salesReportsCan(string $action): bool
{
    $action = strtolower(trim($action));
    $isAdmin = function_exists('isAdmin') && isAdmin();
    $isFinance = function_exists('isFinance') && isFinance();
    $isManager = $isAdmin || $isFinance
        || in_array(strtolower((string) ($_SESSION['role'] ?? '')), ['manager', 'company_admin', 'owner'], true);

    return match ($action) {
        'view' => true,
        'create' => $isManager || salesReportsIsSalesStaff(),
        'edit' => $isManager || salesReportsIsSalesStaff(),
        'delete' => $isAdmin || $isManager,
        'export' => true,
        'approve' => $isAdmin || $isManager,
        'restore_version' => $isAdmin || $isManager,
        default => $isAdmin,
    };
}

function salesReportsIsSalesStaff(): bool
{
    $dept = strtolower((string) ($_SESSION['department'] ?? ''));
    return str_contains($dept, 'sales') || str_contains($dept, 'marketing');
}

function salesReportsCanEditReport(array $report): bool
{
    if (!salesReportsCan('edit')) {
        return false;
    }
    $status = strtolower((string) ($report['status'] ?? 'draft'));
    if (in_array($status, ['final', 'archived'], true)) {
        return salesReportsCan('approve');
    }
    return true;
}

// ??? Schema ??????????????????????????????????????????????????????????????????

function salesReportsEnsureSchema(): void
{
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return;
    }
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL DEFAULT 0,
        report_name VARCHAR(255) NOT NULL,
        report_type VARCHAR(50) NOT NULL DEFAULT 'custom',
        template_key VARCHAR(50) NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        prepared_by VARCHAR(255) NULL,
        department VARCHAR(255) NULL,
        branch VARCHAR(255) NULL,
        status ENUM('draft','under_review','approved','final','archived') NOT NULL DEFAULT 'draft',
        description TEXT NULL,
        current_version INT NOT NULL DEFAULT 1,
        created_by INT NULL,
        updated_by INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at TIMESTAMP NULL DEFAULT NULL,
        KEY idx_sr_company (company_id),
        KEY idx_sr_status (status),
        KEY idx_sr_dates (start_date, end_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_report_documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_id INT NOT NULL,
        content LONGTEXT NULL,
        content_html LONGTEXT NULL,
        sections_json LONGTEXT NULL,
        version INT NOT NULL DEFAULT 1,
        created_by INT NULL,
        updated_by INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_srd_report (report_id),
        KEY idx_srd_report (report_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_report_versions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_id INT NOT NULL,
        version_number INT NOT NULL,
        content LONGTEXT NULL,
        content_html LONGTEXT NULL,
        sections_json LONGTEXT NULL,
        change_summary VARCHAR(500) NULL,
        created_by INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_srv_report (report_id, version_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    salesReportsEnsureAutofilledColumn($pdo);

    require_once __DIR__ . '/report-engine.php';
    reportEngineEnsureDomainColumns($pdo);
}

function salesReportsEnsureAutofilledColumn(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM sales_report_documents LIKE 'autofilled_at'")->fetchAll();
        if ($cols === []) {
            $pdo->exec('ALTER TABLE sales_report_documents ADD COLUMN autofilled_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at');
        }
    } catch (Throwable $e) {
        error_log('salesReportsEnsureAutofilledColumn: ' . $e->getMessage());
    }
}

// ??? Templates & default sections ???????????????????????????????????????????

require_once __DIR__ . '/sales-reports-format.php';

function salesReportsTemplates(): array
{
    $department = salesReportsDepartmentSectionKeys();

    return [
        'blank' => [
            'label' => 'Blank Report',
            'type' => 'custom',
            'sections' => ['cover', 'executive_summary', 'conclusion'],
        ],
        'department_quarterly' => [
            'label' => 'Department Quarterly Report',
            'type' => 'quarterly',
            'sections' => $department,
        ],
        'monthly' => [
            'label' => 'Monthly Sales Report',
            'type' => 'monthly',
            'sections' => array_values(array_diff($department, ['individual_sales_performance', 'salesperson_appendix'])),
        ],
        'quarterly' => [
            'label' => 'Quarterly Sales Report',
            'type' => 'quarterly',
            'sections' => $department,
        ],
        'annual' => [
            'label' => 'Annual Sales Report',
            'type' => 'annual',
            'sections' => $department,
        ],
        'performance' => [
            'label' => 'Sales Performance Report',
            'type' => 'performance',
            'sections' => ['cover', 'executive_summary', 'individual_sales_performance', 'quotation_analysis', 'conclusion'],
        ],
        'management' => [
            'label' => 'Management Sales Report',
            'type' => 'management',
            'sections' => ['cover', 'executive_summary', 'individual_sales_performance', 'top_client_contribution', 'key_achievements', 'challenges', 'action_plan', 'conclusion'],
        ],
    ];
}

function salesReportsDefaultSectionKeys(): array
{
    return salesReportsDepartmentSectionKeys();
}

function salesReportsSectionCatalog(): array
{
    return [
        'cover' => 'Cover Page',
        'executive_summary' => 'Executive Summary',
        'individual_sales_performance' => 'Individual Sales Performance',
        'quotation_analysis' => 'Quotation Analysis',
        'top_client_contribution' => 'Top Client Contribution',
        'key_achievements' => 'Key Achievements',
        'challenges' => 'Challenges',
        'delayed_revenue' => 'Potential Revenue Delayed',
        'action_plan' => 'Action Plan',
        'conclusion' => 'Conclusion',
        'salesperson_appendix' => 'Salesperson Sales Overview',
        'sales_overview' => 'Sales Overview',
        'sales_performance' => 'Sales Performance',
        'sales_by_customer' => 'Sales by Customer',
        'sales_by_product' => 'Sales by Product',
        'sales_by_category' => 'Sales by Category',
        'salesperson_performance' => 'Sales Team Performance',
        'payment_analysis' => 'Payment Analysis',
        'outstanding_invoices' => 'Outstanding Invoices',
        'sales_returns' => 'Sales Returns',
        'discount_analysis' => 'Discount Analysis',
        'profitability' => 'Profitability',
        'management_comments' => 'Management Comments',
    ];
}

function salesReportsStatusOptions(): array
{
    return ['draft', 'under_review', 'approved', 'final', 'archived'];
}

function salesReportsFormatStatus(string $status): string
{
    return ucwords(str_replace('_', ' ', $status));
}

// ??? CRUD ????????????????????????????????????????????????????????????????????

function salesReportsList(PDO $pdo, array $filters = []): array
{
    $companyId = salesReportsCompanyId();
    $search = trim((string) ($filters['search'] ?? ''));
    $status = trim((string) ($filters['status'] ?? ''));

    $sql = "SELECT r.*,
                   COALESCE(NULLIF(TRIM(u.full_name), ''), u.username, 'Unknown') AS creator_name
            FROM sales_reports r
            LEFT JOIN users u ON u.id = r.created_by
            WHERE r.deleted_at IS NULL";
    $params = [];

    if ($companyId > 0) {
        $sql .= ' AND r.company_id = ?';
        $params[] = $companyId;
    }
    if ($status !== '' && in_array($status, salesReportsStatusOptions(), true)) {
        $sql .= ' AND r.status = ?';
        $params[] = $status;
    }
    if ($search !== '') {
        $sql .= ' AND (r.report_name LIKE ? OR r.description LIKE ? OR r.prepared_by LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= ' ORDER BY r.updated_at DESC, r.id DESC LIMIT 200';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function salesReportsGet(PDO $pdo, int $id): ?array
{
    $companyId = salesReportsCompanyId();
    $sql = "SELECT r.*,
                   COALESCE(NULLIF(TRIM(cu.full_name), ''), cu.username, 'Unknown') AS creator_name,
                   COALESCE(NULLIF(TRIM(uu.full_name), ''), uu.username, '') AS updater_name
            FROM sales_reports r
            LEFT JOIN users cu ON cu.id = r.created_by
            LEFT JOIN users uu ON uu.id = r.updated_by
            WHERE r.id = ? AND r.deleted_at IS NULL";
    $params = [$id];
    if ($companyId > 0) {
        $sql .= ' AND r.company_id = ?';
        $params[] = $companyId;
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function salesReportsGetDocument(PDO $pdo, int $reportId): ?array
{
    $st = $pdo->prepare('SELECT * FROM sales_report_documents WHERE report_id = ? LIMIT 1');
    $st->execute([$reportId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function salesReportsCreate(PDO $pdo, array $data): int
{
    require_once __DIR__ . '/report-engine.php';
    reportEngineEnsureDomainColumns($pdo);

    $userId = salesReportsUserId();
    $companyId = salesReportsCompanyId();
    $domain = reportEngineNormalizeDomain($data['report_domain'] ?? 'sales');
    $filters = is_array($data['filters'] ?? null) ? $data['filters'] : [];
    $templateKey = trim((string) ($data['template_key'] ?? reportEngineDefaultTemplateKey($domain)));
    $templates = reportEngineTemplates($domain);
    $template = $templates[$templateKey] ?? reset($templates) ?: ['type' => 'custom', 'sections' => ['cover', 'executive_summary', 'conclusion']];

    $status = strtolower(trim((string) ($data['status'] ?? 'draft')));
    if (!in_array($status, salesReportsStatusOptions(), true)) {
        $status = 'draft';
    }

    $defaultName = $domain === 'sales' ? 'Untitled Sales Report' : (reportEngineDomainLabel($domain) . ' � ' . date('M Y'));
    $filtersJson = $filters !== [] ? json_encode($filters, JSON_UNESCAPED_UNICODE) : null;

    $st = $pdo->prepare("INSERT INTO sales_reports
        (company_id, report_name, report_type, report_domain, filters_json, template_key, start_date, end_date,
         prepared_by, department, branch, status, description, created_by, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $st->execute([
        $companyId,
        trim((string) ($data['report_name'] ?? $defaultName)),
        trim((string) ($data['report_type'] ?? $template['type'])),
        $domain,
        $filtersJson,
        $templateKey !== 'blank' ? $templateKey : null,
        $data['start_date'] ?? date('Y-m-01'),
        $data['end_date'] ?? date('Y-m-d'),
        trim((string) ($data['prepared_by'] ?? '')),
        trim((string) ($data['department'] ?? ($_SESSION['department'] ?? reportEngineDomains()[$domain]['department_default'] ?? ''))),
        trim((string) ($data['branch'] ?? '')),
        $status,
        trim((string) ($data['description'] ?? '')),
        $userId ?: null,
        $userId ?: null,
    ]);
    $reportId = (int) $pdo->lastInsertId();

    $reportMeta = array_merge($data, [
        'id' => $reportId,
        'report_domain' => $domain,
        'filters_json' => $filtersJson,
        'start_date' => $data['start_date'] ?? date('Y-m-01'),
        'end_date' => $data['end_date'] ?? date('Y-m-d'),
        'report_name' => $data['report_name'] ?? $defaultName,
        'prepared_by' => $data['prepared_by'] ?? '',
        'department' => $data['department'] ?? '',
    ]);

    $sections = salesReportsBuildInitialSections($pdo, $reportId, $template['sections'], $reportMeta);

    require_once __DIR__ . '/sales-reports-autofill.php';
    require_once __DIR__ . '/report-domain-autofill.php';
    salesReportsEnsureAutofilledColumn($pdo);
    try {
        if ($domain === 'sales') {
            $sections = salesReportsAutofillSections($pdo, $reportMeta, $sections);
        } else {
            $sections = reportEngineAutofillSections($pdo, $reportMeta, $sections);
        }
    } catch (Throwable $e) {
        error_log('salesReportsCreate autofill: ' . $e->getMessage());
    }

    $contentHtml = salesReportsRenderSectionsHtml($sections, $reportMeta);
    $sectionsJson = salesReportsJsonEncode($sections);

    $docSt = $pdo->prepare("INSERT INTO sales_report_documents
        (report_id, content, content_html, sections_json, version, created_by, updated_by, autofilled_at)
        VALUES (?, ?, ?, ?, 1, ?, ?, NOW())");
    $docSt->execute([
        $reportId,
        $sectionsJson,
        $contentHtml,
        $sectionsJson,
        $userId ?: null,
        $userId ?: null,
    ]);

    salesReportsSaveVersion($pdo, $reportId, 1, $sectionsJson, $contentHtml, $sectionsJson, 'Created', $userId);
    return $reportId;
}

function salesReportsBuildInitialSections(PDO $pdo, int $reportId, array $sectionKeys, array $meta): array
{
    require_once __DIR__ . '/report-engine.php';
    $domain = reportEngineNormalizeDomain($meta['report_domain'] ?? 'sales');
    $catalog = $domain === 'sales' ? salesReportsSectionCatalog() : reportEngineSectionCatalog($domain);
    $sections = [];
    $order = 0;
    foreach ($sectionKeys as $key) {
        if (!isset($catalog[$key])) {
            continue;
        }
        $sections[] = [
            'id' => $key . '_' . bin2hex(random_bytes(4)),
            'key' => $key,
            'title' => $catalog[$key],
            'order' => $order++,
            'visible' => true,
            'content' => salesReportsDefaultSectionContent($key, $meta),
        ];
    }
    return $sections;
}

function salesReportsDefaultSectionContent(string $key, array $meta): string
{
    require_once __DIR__ . '/report-engine.php';
    $domain = reportEngineNormalizeDomain($meta['report_domain'] ?? 'sales');
    $catalog = $domain === 'sales' ? salesReportsSectionCatalog() : reportEngineSectionCatalog($domain);
    $title = $catalog[$key] ?? ucfirst(str_replace('_', ' ', $key));

    return match ($key) {
        'cover' => reportEngineBuildCoverHtml($domain, $meta),
        'executive_summary' => salesReportsSectionHeading('Executive Summary')
            . '<p>This report presents the sales performance of the Sales Department for [period]. The department consists of [number] sales personnel, each assigned an individual quarterly sales target of [amount], resulting in a combined departmental target of [amount]. During the reporting period, the Sales Department generated total sales of [amount], achieving [percentage]% of the departmental target. Despite missing the target, the team maintained strong client engagement and continued to secure business opportunities.</p>',
        'individual_sales_performance' => salesReportsSectionHeading('Individual Sales Performance')
            . '<p>A comparative analysis between the previous and current reporting periods was conducted to assess individual sales performance trends and overall departmental progress.</p>'
            . '<p>While some sales personnel recorded significant improvement, individual performance varied due to factors such as customer order cycles, order value, and market conditions.</p>'
            . '<p><strong>Quarter-to-Quarter Performance Benchmark:</strong></p>',
        'quotation_analysis' => salesReportsSectionHeading('Quotation Analysis')
            . '<p>During the reporting period, the Sales Department submitted a total of [quotations] quotations, out of which [converted] were successfully converted into confirmed orders, resulting in a quotation conversion rate of [rate]%. This indicates moderate conversion efficiency and highlights opportunities to further improve follow-up, pricing strategy, and customer engagement to increase sales closure rates.</p>',
        'top_client_contribution' => salesReportsSectionHeading('Top Client Contribution')
            . '<p>Key repeat clients during this quarter included:</p>',
        'key_achievements' => salesReportsSectionHeading('Key Achievements') . salesReportsTplKeyAchievements(),
        'challenges' => salesReportsSectionHeading('Challenges') . salesReportsTplChallenges(),
        'delayed_revenue' => salesReportsSectionHeading('Potential Revenue Delayed to Next Quarter')
            . '<p>During the reporting period, several customer orders with significant revenue potential were not completed within the quarter due to operational and coordination challenges. This consequently impacted the department&rsquo;s overall quarterly sales performance.</p>',
        'action_plan' => salesReportsSectionHeading('Action Plan') . salesReportsTplActionPlan(),
        'conclusion' => salesReportsSectionHeading('Conclusion')
            . '<p>The Sales Department recorded strong performance during the reporting period. Despite challenges such as market competition, delayed quotation approvals, and occasional delivery delays, the team maintained good customer relationships and secured valuable business. With improved communication, stronger follow-up, and continued teamwork, the department is well-positioned to achieve better results in the next quarter.</p>',
        'salesperson_appendix' => '<p><em>Salesperson monthly invoice breakdown will be inserted from system data.</em></p>',
        'sales_overview' => salesReportsSectionHeading('Sales Overview')
            . '<div class="sr-erp-block" data-erp-source="sales_summary" data-erp-mode="snapshot"></div>'
            . '<div class="sr-erp-block" data-erp-source="sales_trend" data-erp-mode="snapshot" data-erp-type="chart"></div>',
        'sales_performance' => salesReportsSectionHeading('Sales Performance')
            . '<div class="sr-erp-block" data-erp-source="team_performance" data-erp-mode="snapshot"></div>',
        'sales_by_customer' => salesReportsSectionHeading('Sales by Customer')
            . '<div class="sr-erp-block" data-erp-source="sales_by_customer" data-erp-mode="snapshot"></div>',
        'sales_by_product' => salesReportsSectionHeading('Sales by Product')
            . '<div class="sr-erp-block" data-erp-source="sales_by_product" data-erp-mode="snapshot"></div>',
        'sales_by_category' => salesReportsSectionHeading('Sales by Category')
            . '<div class="sr-erp-block" data-erp-source="sales_by_category" data-erp-mode="snapshot"></div>',
        'salesperson_performance' => salesReportsSectionHeading('Sales Team Performance')
            . '<div class="sr-erp-block" data-erp-source="team_performance" data-erp-mode="snapshot"></div>',
        'payment_analysis' => salesReportsSectionHeading('Payment Analysis')
            . '<div class="sr-erp-block" data-erp-source="payment_analysis" data-erp-mode="snapshot"></div>',
        'outstanding_invoices' => salesReportsSectionHeading('Outstanding Invoices')
            . '<div class="sr-erp-block" data-erp-source="outstanding_invoices" data-erp-mode="snapshot"></div>',
        'sales_returns' => salesReportsSectionHeading('Sales Returns') . '<p>No returns recorded for this period, or data not available.</p>',
        'discount_analysis' => salesReportsSectionHeading('Discount Analysis')
            . '<div class="sr-erp-block" data-erp-source="discounts" data-erp-mode="snapshot"></div>',
        'profitability' => salesReportsSectionHeading('Profitability')
            . '<div class="sr-erp-block" data-erp-source="profitability" data-erp-mode="snapshot"></div>',
        'management_comments' => salesReportsSectionHeading('Management Comments') . '<p>Enter management observations and recommendations here.</p>',
        default => salesReportsSectionHeading($title) . '<p></p>',
    };
}

function salesReportsJsonEncode(mixed $value): string
{
    $flags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($value, $flags);
    if ($json === false) {
        throw new RuntimeException('Failed to encode report document JSON: ' . json_last_error_msg());
    }
    return $json;
}

function salesReportsFormatPeriod(string $start, string $end): string
{
    if ($start === '' || $end === '') {
        return '';
    }
    return date('d M Y', strtotime($start)) . ' - ' . date('d M Y', strtotime($end));
}

function salesReportsRenderSectionsHtml(array $sections, array $meta = []): string
{
    usort($sections, static fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
    $html = '';
    foreach ($sections as $section) {
        if (empty($section['visible'])) {
            continue;
        }
        $html .= '<div class="sr-section" data-section-id="' . htmlspecialchars((string) ($section['id'] ?? ''), ENT_QUOTES, 'UTF-8') . '" data-section-key="' . htmlspecialchars((string) ($section['key'] ?? ''), ENT_QUOTES, 'UTF-8') . '">';
        $html .= (string) ($section['content'] ?? '');
        $html .= '</div>';
    }
    return $html;
}

function salesReportsUpdate(PDO $pdo, int $id, array $data): bool
{
    $report = salesReportsGet($pdo, $id);
    if (!$report || !salesReportsCanEditReport($report)) {
        return false;
    }
    $fields = [];
    $params = [];
    foreach (['report_name', 'report_type', 'start_date', 'end_date', 'prepared_by', 'department', 'branch', 'status', 'description'] as $col) {
        if (array_key_exists($col, $data)) {
            $fields[] = "{$col} = ?";
            $params[] = $data[$col];
        }
    }
    if ($fields === []) {
        return true;
    }
    $fields[] = 'updated_by = ?';
    $params[] = salesReportsUserId();
    $params[] = $id;
    $st = $pdo->prepare('UPDATE sales_reports SET ' . implode(', ', $fields) . ' WHERE id = ?');
    return $st->execute($params);
}

function salesReportsSaveDocument(PDO $pdo, int $reportId, string $sectionsJson, string $contentHtml, bool $createVersion = true): array
{
    $report = salesReportsGet($pdo, $reportId);
    if (!$report || !salesReportsCanEditReport($report)) {
        return ['success' => false, 'error' => 'Cannot edit this report.'];
    }

    $userId = salesReportsUserId();
    $doc = salesReportsGetDocument($pdo, $reportId);
    $newVersion = (int) ($report['current_version'] ?? 1);

    if ($createVersion) {
        $newVersion++;
        salesReportsSaveVersion($pdo, $reportId, $newVersion, $sectionsJson, $contentHtml, $sectionsJson, 'Document updated', $userId);
        $pdo->prepare('UPDATE sales_reports SET current_version = ?, updated_by = ? WHERE id = ?')
            ->execute([$newVersion, $userId, $reportId]);
    }

    if ($doc) {
        $pdo->prepare("UPDATE sales_report_documents SET content = ?, content_html = ?, sections_json = ?, version = ?, updated_by = ? WHERE report_id = ?")
            ->execute([$sectionsJson, $contentHtml, $sectionsJson, $newVersion, $userId, $reportId]);
    } else {
        $pdo->prepare("INSERT INTO sales_report_documents (report_id, content, content_html, sections_json, version, created_by, updated_by) VALUES (?,?,?,?,?,?,?)")
            ->execute([$reportId, $sectionsJson, $contentHtml, $sectionsJson, $newVersion, $userId, $userId]);
    }

    return ['success' => true, 'version' => $newVersion, 'saved_at' => date('Y-m-d H:i:s')];
}

function salesReportsSaveVersion(PDO $pdo, int $reportId, int $version, string $content, string $html, string $sections, string $summary, int $userId): void
{
    $pdo->prepare("INSERT INTO sales_report_versions (report_id, version_number, content, content_html, sections_json, change_summary, created_by) VALUES (?,?,?,?,?,?,?)")
        ->execute([$reportId, $version, $content, $html, $sections, $summary, $userId]);
}

function salesReportsDelete(PDO $pdo, int $id): bool
{
    if (!salesReportsCan('delete')) {
        return false;
    }
    $report = salesReportsGet($pdo, $id);
    if (!$report) {
        return false;
    }
    $st = $pdo->prepare('UPDATE sales_reports SET deleted_at = NOW(), updated_by = ? WHERE id = ?');
    return $st->execute([salesReportsUserId(), $id]);
}

function salesReportsDuplicate(PDO $pdo, int $id): ?int
{
    if (!salesReportsCan('create')) {
        return null;
    }
    $report = salesReportsGet($pdo, $id);
    $doc = salesReportsGetDocument($pdo, $id);
    if (!$report) {
        return null;
    }
    $newId = salesReportsCreate($pdo, [
        'report_name' => $report['report_name'] . ' (Copy)',
        'report_type' => $report['report_type'],
        'report_domain' => $report['report_domain'] ?? 'sales',
        'template_key' => $report['template_key'] ?? 'blank',
        'start_date' => $report['start_date'],
        'end_date' => $report['end_date'],
        'prepared_by' => $report['prepared_by'],
        'department' => $report['department'],
        'branch' => $report['branch'],
        'status' => 'draft',
        'description' => $report['description'],
        'filters' => json_decode($report['filters_json'] ?? '[]', true) ?: [],
    ]);
    if ($doc && $newId > 0) {
        $sections = $doc['sections_json'] ?? $doc['content'] ?? '[]';
        $html = $doc['content_html'] ?? '';
        salesReportsSaveDocument($pdo, $newId, $sections, $html, false);
    }
    return $newId;
}

function salesReportsVersions(PDO $pdo, int $reportId): array
{
    $st = $pdo->prepare("SELECT v.*, COALESCE(NULLIF(TRIM(u.full_name), ''), u.username, 'Unknown') AS author_name
        FROM sales_report_versions v
        LEFT JOIN users u ON u.id = v.created_by
        WHERE v.report_id = ?
        ORDER BY v.version_number DESC");
    $st->execute([$reportId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function salesReportsRestoreVersion(PDO $pdo, int $reportId, int $versionNumber): array
{
    if (!salesReportsCan('restore_version')) {
        return ['success' => false, 'error' => 'Access denied.'];
    }
    $st = $pdo->prepare('SELECT * FROM sales_report_versions WHERE report_id = ? AND version_number = ? LIMIT 1');
    $st->execute([$reportId, $versionNumber]);
    $ver = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ver) {
        return ['success' => false, 'error' => 'Version not found.'];
    }
    return salesReportsSaveDocument(
        $pdo,
        $reportId,
        (string) ($ver['content'] ?? $ver['sections_json'] ?? '[]'),
        (string) ($ver['content_html'] ?? ''),
        true
    );
}

function salesReportsCurrency(): string
{
    if (function_exists('getSalesSettings')) {
        $s = getSalesSettings();
        if (!empty($s['currency'])) {
            return (string) $s['currency'];
        }
    }
    return 'TZS';
}

function salesReportsFormatMoney(float $amount): string
{
    return salesReportsCurrency() . ' ' . number_format($amount, 0, '.', ',');
}
