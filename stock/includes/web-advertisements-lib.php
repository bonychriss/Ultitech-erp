<?php

declare(strict_types=1);

/**
 * Website module � email product advertisements (localhost first).
 */

function webAdEnsureTables(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS website_email_campaigns (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(191) NOT NULL DEFAULT \'\',
            subject VARCHAR(255) NOT NULL DEFAULT \'\',
            intro_html MEDIUMTEXT NULL,
            product_ids MEDIUMTEXT NULL,
            audience_mode VARCHAR(32) NOT NULL DEFAULT \'all\',
            audience_ids MEDIUMTEXT NULL,
            status VARCHAR(32) NOT NULL DEFAULT \'draft\',
            scheduled_at DATETIME NULL,
            sent_at DATETIME NULL,
            sent_count INT NOT NULL DEFAULT 0,
            fail_count INT NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_status_sched (status, scheduled_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS website_email_campaign_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            campaign_id INT UNSIGNED NOT NULL,
            customer_id INT NULL,
            email VARCHAR(191) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT \'pending\',
            error_text TEXT NULL,
            sent_at DATETIME NULL,
            KEY idx_campaign (campaign_id),
            KEY idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM website_email_campaigns')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('headline', $cols, true)) {
            $pdo->exec('ALTER TABLE website_email_campaigns ADD COLUMN headline VARCHAR(255) NULL AFTER intro_html');
        }
        if (!in_array('cta_label', $cols, true)) {
            $pdo->exec('ALTER TABLE website_email_campaigns ADD COLUMN cta_label VARCHAR(64) NULL AFTER headline');
        }
    } catch (Throwable $e) {
        // older MySQL / missing table � create already ran
    }

    $ready = true;
}

function webAdShopHost(): string
{
    if (function_exists('webSyncShopHost')) {
        $host = (string) webSyncShopHost();
    } else {
        $host = 'https://ultimate.co.tz';
    }

    return rtrim($host, '/');
}

function webAdCompanyName(): string
{
    $name = trim((string) ($_SESSION['company_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    if (function_exists('mailer_active_company_name')) {
        $name = trim((string) mailer_active_company_name());
        if ($name !== '') {
            return $name;
        }
    }

    return 'Ultimate';
}

/**
 * @return list<array{id:int,name:string,email:string,company_name:string}>
 */
function webAdCustomersWithEmail(PDO $pdo, string $q = '', int $limit = 200): array
{
    $cols = [];
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM customers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return [];
    }
    if ($cols === [] || !in_array('email', $cols, true)) {
        return [];
    }

    $nameExpr = in_array('company_name', $cols, true)
        ? 'COALESCE(NULLIF(TRIM(company_name), \'\'), NULLIF(TRIM(contact_person), \'\'), email)'
        : (in_array('name', $cols, true)
            ? 'COALESCE(NULLIF(TRIM(name), \'\'), email)'
            : 'email');

    $where = ["email IS NOT NULL", "TRIM(email) <> ''", "email LIKE '%@%'"];
    if (in_array('status', $cols, true)) {
        $where[] = "LOWER(TRIM(COALESCE(status, 'active'))) IN ('active', '1', '')";
    }

    $params = [];
    $q = trim($q);
    if ($q !== '') {
        $like = '%' . $q . '%';
        $parts = ['email LIKE ?', $nameExpr . ' LIKE ?'];
        $params[] = $like;
        $params[] = $like;
        if (in_array('contact_person', $cols, true)) {
            $parts[] = 'contact_person LIKE ?';
            $params[] = $like;
        }
        $where[] = '(' . implode(' OR ', $parts) . ')';
    }

    $limit = max(1, min(500, $limit));
    $sql = 'SELECT id, email, ' . $nameExpr . ' AS company_name
            FROM customers
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY company_name ASC
            LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'email' => $email,
            'company_name' => trim((string) ($row['company_name'] ?? $email)),
            'name' => trim((string) ($row['company_name'] ?? $email)),
        ];
    }

    return $out;
}

function webAdPublicBaseUrl(): string
{
    if (function_exists('app_url')) {
        $base = rtrim((string) app_url('/'), '/');
        if ($base !== '' && !str_starts_with($base, 'http')) {
            $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
            $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
            $base = ($https ? 'https' : 'http') . '://' . $host . '/' . ltrim($base, '/');
        }

        return rtrim($base, '/');
    }
    $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));

    return ($https ? 'https' : 'http') . '://' . ($host !== '' ? $host : 'localhost');
}

function webAdAbsoluteUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    if (str_starts_with($url, '//')) {
        return 'https:' . $url;
    }

    return webAdPublicBaseUrl() . '/' . ltrim($url, '/');
}

function webAdProductImageUrl(int $productId, string $filename): string
{
    if ($productId < 1) {
        return '';
    }
    if (!function_exists('stock_product_list_image_url')) {
        $fn = dirname(__DIR__) . '/config/functions.php';
        if (is_file($fn)) {
            require_once $fn;
        }
    }
    if (function_exists('stock_product_list_image_url')) {
        return webAdAbsoluteUrl((string) stock_product_list_image_url($productId, $filename, 'medium'));
    }
    if ($filename !== '' && preg_match('#^https?://#i', $filename)) {
        return $filename;
    }

    return '';
}

/**
 * @return list<string>
 */
function webAdCategories(PDO $pdo): array
{
    $names = [];
    foreach (webAdSearchProducts($pdo, '', 5000) as $row) {
        $cat = trim((string) ($row['category_name'] ?? ''));
        if ($cat !== '') {
            $names[$cat] = true;
        }
    }
    $list = array_keys($names);
    natcasesort($list);

    return array_values($list);
}

/**
 * @return list<array<string,mixed>>
 */
function webAdSearchProducts(PDO $pdo, string $q = '', int $limit = 40, string $category = ''): array
{
    if (!function_exists('webSyncActiveProducts')) {
        require_once __DIR__ . '/web-services-lib.php';
    }

    $q = mb_strtolower(trim($q));
    $category = trim($category);
    $all = webSyncActiveProducts($pdo);
    $out = [];
    foreach ($all as $row) {
        $catName = trim((string) ($row['category_name'] ?? ''));
        if ($category !== '' && strcasecmp($catName, $category) !== 0) {
            continue;
        }
        if ($q !== '') {
            $hay = mb_strtolower(
                trim((string) ($row['name'] ?? '')) . ' '
                . trim((string) ($row['product_code'] ?? '')) . ' '
                . $catName
            );
            if (!str_contains($hay, $q)) {
                continue;
            }
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $file = trim((string) ($row['image'] ?? ''));
        $out[] = [
            'id' => $id,
            'name' => trim((string) ($row['name'] ?? '')),
            'product_code' => trim((string) ($row['product_code'] ?? '')),
            'unit_price' => (float) ($row['unit_price'] ?? 0),
            'category_name' => $catName,
            'image' => $file,
            'image_url' => webAdProductImageUrl($id, $file),
            'stock_qty' => (float) ($row['stock_qty'] ?? 0),
        ];
        if (count($out) >= $limit) {
            break;
        }
    }

    return $out;
}

/**
 * @param list<int> $ids
 * @return list<array<string,mixed>>
 */
function webAdProductsByIds(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }
    $map = [];
    foreach (webAdSearchProducts($pdo, '', 5000) as $row) {
        $map[(int) $row['id']] = $row;
    }
    $out = [];
    foreach ($ids as $id) {
        if (isset($map[$id])) {
            $out[] = $map[$id];
        }
    }

    return $out;
}

/**
 * Alibaba-style B2B product campaign email: banner + 2-column product cards.
 *
 * @param list<array<string,mixed>> $products
 * @param array{intro_html?:string,headline?:string,cta_label?:string} $opts
 */
function webAdBuildProductHtml(array $products, string $introHtml = '', array $opts = []): string
{
    $shop = webAdShopHost();
    $company = htmlspecialchars(webAdCompanyName(), ENT_QUOTES, 'UTF-8');
    $intro = trim($introHtml !== '' ? $introHtml : (string) ($opts['intro_html'] ?? ''));
    $headline = trim((string) ($opts['headline'] ?? ''));
    if ($headline === '') {
        $headline = 'Recommended products for your business';
    }
    $cta = trim((string) ($opts['cta_label'] ?? ''));
    if ($cta === '') {
        $cta = 'Inquire Now';
    }
    $headlineEsc = htmlspecialchars($headline, ENT_QUOTES, 'UTF-8');
    $ctaEsc = htmlspecialchars($cta, ENT_QUOTES, 'UTF-8');

    $cards = [];
    foreach ($products as $p) {
        $name = htmlspecialchars((string) ($p['name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $code = htmlspecialchars((string) ($p['product_code'] ?? ''), ENT_QUOTES, 'UTF-8');
        $price = number_format((float) ($p['unit_price'] ?? 0), 2);
        $cat = htmlspecialchars((string) ($p['category_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $img = webAdAbsoluteUrl((string) ($p['image_url'] ?? ''));
        if ($img === '' && !empty($p['image'])) {
            $img = webAdProductImageUrl((int) ($p['id'] ?? 0), (string) $p['image']);
        }
        $link = $shop . '/search?q=' . rawurlencode((string) (($p['product_code'] ?? '') !== '' ? $p['product_code'] : $p['name']));
        $linkEsc = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
        $imgBlock = $img !== ''
            ? '<a href="' . $linkEsc . '" style="display:block;background:#f5f5f5;"><img src="' . htmlspecialchars($img, ENT_QUOTES, 'UTF-8') . '" alt="' . $name . '" width="280" style="display:block;width:100%;max-width:280px;height:200px;object-fit:contain;margin:0 auto;border:0;"></a>'
            : '<a href="' . $linkEsc . '" style="display:block;height:200px;background:#f5f5f5;text-align:center;line-height:200px;color:#999;text-decoration:none;font-size:13px;">No image</a>';

        $meta = $code;
        if ($cat !== '') {
            $meta .= ($meta !== '' ? ' � ' : '') . $cat;
        }

        $cards[] = '<td width="50%" valign="top" style="padding:8px;box-sizing:border-box;">
          <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border:1px solid #eee;background:#fff;">
            <tr><td style="padding:10px 10px 0;">' . $imgBlock . '</td></tr>
            <tr><td style="padding:12px 14px 4px;">
              <a href="' . $linkEsc . '" style="color:#222;text-decoration:none;font-size:14px;font-weight:700;line-height:1.35;display:block;min-height:38px;">' . $name . '</a>
              <div style="color:#999;font-size:11px;margin-top:6px;">' . $meta . '</div>
              <div style="margin-top:10px;color:#7c3aed;font-size:18px;font-weight:800;">TZS ' . $price . '</div>
              <div style="margin:12px 0 6px;"><a href="' . $linkEsc . '" style="display:inline-block;background:#7c3aed;color:#fff;text-decoration:none;padding:9px 16px;font-size:13px;font-weight:700;">' . $ctaEsc . '</a></div>
            </td></tr>
          </table>
        </td>';
    }

    $rowsHtml = '';
    for ($i = 0, $n = count($cards); $i < $n; $i += 2) {
        $left = $cards[$i];
        $right = $cards[$i + 1] ?? '<td width="50%" style="padding:8px;"></td>';
        $rowsHtml .= '<tr>' . $left . $right . '</tr>';
    }

    $introBlock = $intro !== ''
        ? '<div style="padding:0 8px 16px;color:#555;font-size:14px;line-height:1.6;">' . $intro . '</div>'
        : '<div style="padding:0 8px 16px;color:#555;font-size:14px;line-height:1.6;">Hand-picked products from <strong>' . $company . '</strong>. Request a quote or order directly.</div>';

    return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f0f0f0;font-family:Arial,Helvetica,sans-serif;">
      <div style="max-width:640px;margin:0 auto;background:#fff;">
        <div style="padding:16px 20px;border-bottom:1px solid #eee;">
          <div style="font-size:20px;font-weight:800;color:#222;letter-spacing:-0.02em;">' . $company . '</div>
          <div style="font-size:12px;color:#999;margin-top:2px;">Wholesale &amp; supply</div>
        </div>
        <div style="background:linear-gradient(135deg,#7c3aed 0%,#6d28d9 100%);padding:28px 22px;color:#fff;">
          <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;opacity:.9;">Product campaign</div>
          <div style="font-size:24px;font-weight:800;line-height:1.25;margin-top:8px;">' . $headlineEsc . '</div>
        </div>
        <div style="padding:20px 12px 8px;">
          ' . $introBlock . '
          <table width="100%" cellpadding="0" cellspacing="0" role="presentation">' . $rowsHtml . '</table>
        </div>
        <div style="padding:18px 20px 28px;border-top:1px solid #eee;margin-top:8px;">
          <a href="' . htmlspecialchars($shop, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:#222;color:#fff;text-decoration:none;padding:11px 20px;font-size:13px;font-weight:700;">Browse full catalogue</a>
          <p style="margin:16px 0 0;color:#aaa;font-size:11px;line-height:1.5;">You are receiving this because you are a customer of ' . $company . '. Prices may change; reply to this email or inquire on the product page.</p>
        </div>
      </div>
    </body></html>';
}

function webAdListCampaigns(PDO $pdo, int $limit = 40): array
{
    webAdEnsureTables($pdo);
    $limit = max(1, min(100, $limit));
    $rows = $pdo->query(
        'SELECT id, name, subject, audience_mode, status, scheduled_at, sent_at, sent_count, fail_count, created_at
         FROM website_email_campaigns
         ORDER BY id DESC
         LIMIT ' . $limit
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'subject' => (string) $row['subject'],
            'audience_mode' => (string) $row['audience_mode'],
            'status' => (string) $row['status'],
            'scheduled_at' => $row['scheduled_at'],
            'sent_at' => $row['sent_at'],
            'sent_count' => (int) $row['sent_count'],
            'fail_count' => (int) $row['fail_count'],
            'created_at' => $row['created_at'],
        ];
    }, $rows);
}

/**
 * @param array<string,mixed> $input
 * @return array{ok:bool,id?:int,error?:string,campaign?:array<string,mixed>}
 */
function webAdSaveCampaign(PDO $pdo, array $input, int $userId): array
{
    webAdEnsureTables($pdo);

    $name = trim((string) ($input['name'] ?? ''));
    $subject = trim((string) ($input['subject'] ?? ''));
    $intro = trim((string) ($input['intro_html'] ?? ''));
    $headline = trim((string) ($input['headline'] ?? ''));
    $ctaLabel = trim((string) ($input['cta_label'] ?? 'Inquire Now'));
    if ($ctaLabel === '') {
        $ctaLabel = 'Inquire Now';
    }
    $audienceMode = strtolower(trim((string) ($input['audience_mode'] ?? 'all')));
    if (!in_array($audienceMode, ['all', 'selected'], true)) {
        $audienceMode = 'all';
    }
    $productIds = array_values(array_unique(array_filter(array_map('intval', (array) ($input['product_ids'] ?? [])), static fn (int $id): bool => $id > 0)));
    $audienceIds = array_values(array_unique(array_filter(array_map('intval', (array) ($input['audience_ids'] ?? [])), static fn (int $id): bool => $id > 0)));
    $scheduleMode = strtolower(trim((string) ($input['schedule_mode'] ?? 'now')));
    $scheduledAt = trim((string) ($input['scheduled_at'] ?? ''));
    $status = 'draft';

    if ($subject === '') {
        return ['ok' => false, 'error' => 'Subject is required.'];
    }
    if ($productIds === []) {
        return ['ok' => false, 'error' => 'Select at least one product.'];
    }
    if ($audienceMode === 'selected' && $audienceIds === []) {
        return ['ok' => false, 'error' => 'Select at least one customer, or choose all customers.'];
    }
    if ($name === '') {
        $name = $subject;
    }

    if ($scheduleMode === 'later') {
        if ($scheduledAt === '') {
            return ['ok' => false, 'error' => 'Pick a date and time to schedule the send.'];
        }
        $ts = strtotime($scheduledAt);
        if ($ts === false) {
            return ['ok' => false, 'error' => 'Invalid schedule time.'];
        }
        $scheduledAt = date('Y-m-d H:i:s', $ts);
        $status = 'scheduled';
    } elseif ($scheduleMode === 'now') {
        $scheduledAt = null;
        $status = 'draft';
    } else {
        $scheduledAt = null;
        $status = 'draft';
    }

    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        'INSERT INTO website_email_campaigns
          (name, subject, intro_html, headline, cta_label, product_ids, audience_mode, audience_ids, status, scheduled_at, created_by, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $name,
        $subject,
        $intro,
        $headline !== '' ? $headline : null,
        $ctaLabel,
        json_encode($productIds, JSON_UNESCAPED_UNICODE),
        $audienceMode,
        json_encode($audienceIds, JSON_UNESCAPED_UNICODE),
        $status,
        $scheduledAt,
        $userId > 0 ? $userId : null,
        $now,
        $now,
    ]);
    $id = (int) $pdo->lastInsertId();

    return [
        'ok' => true,
        'id' => $id,
        'campaign' => [
            'id' => $id,
            'status' => $status,
            'scheduled_at' => $scheduledAt,
        ],
    ];
}

/**
 * @return list<array{id:int,email:string,company_name:string}>
 */
function webAdResolveAudience(PDO $pdo, string $mode, array $ids): array
{
    if ($mode === 'selected') {
        $wanted = array_fill_keys(array_map('intval', $ids), true);
        $out = [];
        foreach (webAdCustomersWithEmail($pdo, '', 500) as $c) {
            if (isset($wanted[(int) $c['id']])) {
                $out[] = $c;
            }
        }

        return $out;
    }

    return webAdCustomersWithEmail($pdo, '', 500);
}

/**
 * @return array{ok:bool,sent?:int,failed?:int,error?:string,status?:string}
 */
function webAdSendCampaign(PDO $pdo, int $campaignId): array
{
    webAdEnsureTables($pdo);
    $mailer = dirname(__DIR__, 2) . '/includes/mailer.php';
    if (is_file($mailer)) {
        require_once $mailer;
    }
    if (!function_exists('sendEmail')) {
        return ['ok' => false, 'error' => 'Mailer is not available.'];
    }

    $stmt = $pdo->prepare('SELECT * FROM website_email_campaigns WHERE id = ? LIMIT 1');
    $stmt->execute([$campaignId]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$campaign) {
        return ['ok' => false, 'error' => 'Campaign not found.'];
    }

    $productIds = json_decode((string) ($campaign['product_ids'] ?? '[]'), true);
    if (!is_array($productIds)) {
        $productIds = [];
    }
    $audienceIds = json_decode((string) ($campaign['audience_ids'] ?? '[]'), true);
    if (!is_array($audienceIds)) {
        $audienceIds = [];
    }
    $products = webAdProductsByIds($pdo, $productIds);
    if ($products === []) {
        return ['ok' => false, 'error' => 'No products found for this campaign.'];
    }

    $audience = webAdResolveAudience($pdo, (string) $campaign['audience_mode'], $audienceIds);
    if ($audience === []) {
        return ['ok' => false, 'error' => 'No customers with email addresses were found.'];
    }

    $pdo->prepare('UPDATE website_email_campaigns SET status = ?, updated_at = ? WHERE id = ?')
        ->execute(['sending', date('Y-m-d H:i:s'), $campaignId]);

    $body = webAdBuildProductHtml($products, (string) ($campaign['intro_html'] ?? ''), [
        'headline' => (string) ($campaign['headline'] ?? ''),
        'cta_label' => (string) ($campaign['cta_label'] ?? 'Inquire Now'),
    ]);
    $subject = (string) $campaign['subject'];
    $sent = 0;
    $failed = 0;
    $lastError = '';

    $log = $pdo->prepare(
        'INSERT INTO website_email_campaign_logs (campaign_id, customer_id, email, status, error_text, sent_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );

    foreach ($audience as $customer) {
        $email = (string) $customer['email'];
        $ok = false;
        $err = '';
        try {
            $ok = (bool) sendEmail($email, $subject, $body, true, [], 'website');
            if (!$ok) {
                $err = trim((string) ($GLOBALS['MAILER_LAST_ERROR'] ?? 'Send failed'));
            }
        } catch (Throwable $e) {
            $ok = false;
            $err = $e->getMessage();
        }
        if ($ok) {
            $sent++;
            $log->execute([$campaignId, (int) $customer['id'], $email, 'sent', null, date('Y-m-d H:i:s')]);
        } else {
            $failed++;
            $lastError = $err !== '' ? $err : 'Send failed';
            $log->execute([$campaignId, (int) $customer['id'], $email, 'failed', $lastError, date('Y-m-d H:i:s')]);
        }
    }

    $status = $sent > 0 ? 'sent' : 'failed';
    $pdo->prepare(
        'UPDATE website_email_campaigns
         SET status = ?, sent_count = ?, fail_count = ?, sent_at = ?, last_error = ?, updated_at = ?
         WHERE id = ?'
    )->execute([
        $status,
        $sent,
        $failed,
        date('Y-m-d H:i:s'),
        $lastError !== '' ? $lastError : null,
        date('Y-m-d H:i:s'),
        $campaignId,
    ]);

    return [
        'ok' => $sent > 0,
        'sent' => $sent,
        'failed' => $failed,
        'status' => $status,
        'error' => $sent > 0 ? null : ($lastError !== '' ? $lastError : 'No emails were sent.'),
    ];
}

/**
 * @return array{ok:bool,processed:int,results:list<array<string,mixed>>}
 */
function webAdProcessDueCampaigns(PDO $pdo): array
{
    webAdEnsureTables($pdo);
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        "SELECT id FROM website_email_campaigns
         WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= ?
         ORDER BY scheduled_at ASC
         LIMIT 10"
    );
    $stmt->execute([$now]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $results = [];
    foreach ($ids as $id) {
        $results[] = ['id' => $id] + webAdSendCampaign($pdo, $id);
    }

    return ['ok' => true, 'processed' => count($results), 'results' => $results];
}

/**
 * @return array<string,mixed>
 */
function webAdEmailPageData(PDO $pdo): array
{
    if (!function_exists('webSyncShopHost')) {
        require_once __DIR__ . '/web-services-lib.php';
    }
    webAdEnsureTables($pdo);
    webAdProcessDueCampaigns($pdo);

    $websiteUrl = function_exists('company_url') ? company_url('website') : '/website';
    $emailUrl = function_exists('company_url') ? company_url('website/advertisements/email') : '/website/advertisements/email';
    $join = static function (string $url, string $query): string {
        return $url . (str_contains($url, '?') ? '&' : '?') . $query;
    };

    $catalogueUrl = '';
    $salesFn = dirname(__DIR__, 2) . '/modules/sales/functions.php';
    if (!function_exists('sales_catalogue_url') && is_file($salesFn)) {
        require_once $salesFn;
    }
    if (function_exists('sales_catalogue_url')) {
        $catalogueUrl = sales_catalogue_url('email_ad', $emailUrl);
    }

    return [
        'pageTitle' => 'Email advertisement',
        'siteHost' => preg_replace('#^https?://#i', '', webAdShopHost()) ?: 'ultimate.co.tz',
        'companyName' => webAdCompanyName(),
        'customers' => webAdCustomersWithEmail($pdo, '', 200),
        'products' => webAdSearchProducts($pdo, '', 40),
        'categories' => webAdCategories($pdo),
        'campaigns' => webAdListCampaigns($pdo, 40),
        'apiUrl' => $join($websiteUrl, 'ajax=ad-email'),
        'dashboardUrl' => function_exists('company_url') ? company_url('website/dashboard') : '/website/dashboard',
        'emailUrl' => $emailUrl,
        'catalogueUrl' => $catalogueUrl,
    ];
}

/**
 * Handle website.php?ajax=ad-email JSON actions.
 */
function webAdHandleAjax(PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Sign in required.']);
        return;
    }

    $raw = file_get_contents('php://input');
    $body = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($body)) {
        $body = $_POST;
    }
    $action = strtolower(trim((string) ($body['action'] ?? $_GET['action'] ?? '')));

    try {
        if ($action === 'search_products') {
            echo json_encode([
                'ok' => true,
                'products' => webAdSearchProducts(
                    $pdo,
                    (string) ($body['q'] ?? ''),
                    40,
                    (string) ($body['category'] ?? '')
                ),
            ]);
            return;
        }
        if ($action === 'products_by_ids') {
            $ids = array_map('intval', (array) ($body['ids'] ?? []));
            echo json_encode(['ok' => true, 'products' => webAdProductsByIds($pdo, $ids)]);
            return;
        }
        if ($action === 'search_customers') {
            echo json_encode(['ok' => true, 'customers' => webAdCustomersWithEmail($pdo, (string) ($body['q'] ?? ''), 200)]);
            return;
        }
        if ($action === 'list') {
            echo json_encode(['ok' => true, 'campaigns' => webAdListCampaigns($pdo, 40)]);
            return;
        }
        if ($action === 'process_due') {
            echo json_encode(webAdProcessDueCampaigns($pdo));
            return;
        }
        if ($action === 'save') {
            $saved = webAdSaveCampaign($pdo, $body, $userId);
            echo json_encode($saved);
            return;
        }
        if ($action === 'save_and_send') {
            $saved = webAdSaveCampaign($pdo, $body + ['schedule_mode' => 'now'], $userId);
            if (empty($saved['ok']) || empty($saved['id'])) {
                echo json_encode($saved);
                return;
            }
            $send = webAdSendCampaign($pdo, (int) $saved['id']);
            echo json_encode(['ok' => !empty($send['ok']), 'id' => (int) $saved['id']] + $send);
            return;
        }
        if ($action === 'send') {
            $id = (int) ($body['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Missing campaign id.']);
                return;
            }
            echo json_encode(webAdSendCampaign($pdo, $id));
            return;
        }

        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}
