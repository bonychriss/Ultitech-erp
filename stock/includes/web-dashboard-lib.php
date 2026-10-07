<?php

declare(strict_types=1);

/**
 * Website dashboard numbers for /{company}/website.
 * Visitors, product views and likes come from ultimate.co.tz/ultitech/stats.php.
 * Enquiries, quotes and revenue come from the UltiTech database.
 */

require_once __DIR__ . '/web-services-lib.php';

const WEB_DASH_STATS_KEY = 'd2d3ce729fa2d206fd283b336673d7d640f4f029';
const WEB_DASH_TZ = 'Africa/Dar_es_Salaam';
const WEB_DASH_QUOTED = ['quoted', 'accepted'];

const WEB_DASH_MAX_CUSTOM_DAYS = 400;

/**
 * @return array<string,string>
 */
function webDashRanges(): array
{
    return ['week' => 'This week', '7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', '365' => 'Last 12 months'];
}

/**
 * Resolves the selected period plus the equally long period it is compared with
 * (the same weekdays last week for "This week", otherwise the days just before).
 * A valid from/to pair wins over the preset; the default is this week (Monday to today).
 *
 * @return array{key:string,from:DateTimeImmutable,to:DateTimeImmutable,prevFrom:DateTimeImmutable,prevTo:DateTimeImmutable,length:int}
 */
function webDashPeriod(string $range, string $fromInput = '', string $toInput = ''): array
{
    $tz = new DateTimeZone(WEB_DASH_TZ);
    $today = new DateTimeImmutable('today', $tz);
    $parse = static function (string $value) use ($tz): ?DateTimeImmutable {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz);

        return ($date && $date->format('Y-m-d') === $value) ? $date : null;
    };

    $from = $parse($fromInput);
    $to = $parse($toInput);
    if ($from !== null && $to !== null) {
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if ($to > $today) {
            $to = $today;
        }
        if ($from > $to) {
            $from = $to;
        }
        $maxFrom = $to->modify('-' . (WEB_DASH_MAX_CUSTOM_DAYS - 1) . ' days');
        if ($from < $maxFrom) {
            $from = $maxFrom;
        }
        $key = 'custom';
    } else {
        $key = array_key_exists($range, webDashRanges()) ? $range : 'week';
        $to = $today;
        $from = $key === 'week'
            ? $today->modify('-' . ((int) $today->format('N') - 1) . ' days')
            : $today->modify('-' . ((int) $key - 1) . ' days');
    }

    $length = (int) $from->diff($to)->days + 1;
    $shift = $key === 'week' ? 7 : $length;

    return [
        'key' => $key,
        'from' => $from,
        'to' => $to,
        'prevFrom' => $from->modify('-' . $shift . ' days'),
        'prevTo' => $to->modify('-' . $shift . ' days'),
        'length' => $length,
    ];
}

function webDashStatsUrl(): string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $local = $host === '' || str_starts_with($host, 'localhost') || str_starts_with($host, '127.0.0.1');

    return $local ? 'http://localhost:8081/ultitech/stats.php' : 'https://ultimate.co.tz/ultitech/stats.php';
}

/**
 * @return array{body:string,code:int}
 */
function webDashCurl(string $url, bool $viaOrigin): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_HTTPHEADER => ['X-Ultitech-Key: ' . WEB_DASH_STATS_KEY, 'Accept: application/json'],
        CURLOPT_USERAGENT => 'UltiTech-Website-Dashboard/1.0',
    ];
    if ($viaOrigin) {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $opts[CURLOPT_RESOLVE] = [$host . ':443:' . webSyncOriginIp(), $host . ':80:' . webSyncOriginIp()];
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['body' => is_string($body) ? $body : '', 'code' => $code];
}

/**
 * @return array<string,mixed>|null
 */
function webDashShopStats(string $from, string $to): ?array
{
    $url = webDashStatsUrl() . '?' . http_build_query(['from' => $from, 'to' => $to]);
    $cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ugt-web-dash-' . md5($url) . '.json';
    if (is_file($cache) && time() - (int) filemtime($cache) < 300) {
        $cached = json_decode((string) file_get_contents($cache), true);
        if (is_array($cached)) {
            return $cached;
        }
    }
    if (!function_exists('curl_init')) {
        return null;
    }

    $data = null;
    foreach ([false, true] as $viaOrigin) {
        if ($viaOrigin && str_starts_with($url, 'http://localhost')) {
            break;
        }
        $res = webDashCurl($url, $viaOrigin);
        $json = $res['code'] === 200 ? json_decode($res['body'], true) : null;
        if (is_array($json) && !empty($json['ok'])) {
            $data = $json;
            break;
        }
    }
    if ($data !== null) {
        @file_put_contents($cache, json_encode($data));
    }

    return $data;
}

function webDashDate(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($value, new DateTimeZone(WEB_DASH_TZ)))
            ->setTimezone(new DateTimeZone(WEB_DASH_TZ))
            ->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

function webDashPhoneTail(string $phone): string
{
    $digits = preg_replace('/\D/', '', $phone) ?? '';

    return strlen($digits) >= 9 ? substr($digits, -9) : '';
}

/**
 * One row per website enquiry (quote number), deleted ones left out.
 *
 * @return list<array{quote_number:string,date:string,status:string,email:string,phone:string}>
 */
function webDashEnquiries(PDO $pdo): array
{
    try {
        $rows = $pdo->query(
            "SELECT quote_number, MIN(created_at) AS created_at, MAX(LOWER(TRIM(status))) AS status,
                    MAX(LOWER(TRIM(COALESCE(customer_email, '')))) AS email, MAX(COALESCE(customer_phone, '')) AS phone
             FROM website_quote_requests
             WHERE COALESCE(quote_number, '') <> '' AND LOWER(TRIM(COALESCE(status, ''))) <> 'deleted'
             GROUP BY quote_number"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $list = [];
    foreach ($rows as $row) {
        $date = webDashDate((string) $row['created_at']);
        if ($date === null) {
            continue;
        }
        $list[] = [
            'quote_number' => (string) $row['quote_number'],
            'date' => $date,
            'status' => (string) $row['status'],
            'email' => (string) $row['email'],
            'phone' => webDashPhoneTail((string) $row['phone']),
        ];
    }

    return $list;
}

/**
 * Invoices for customers who first reached us through a website enquiry, dated on or after that enquiry.
 *
 * @param list<array{quote_number:string,date:string,status:string,email:string,phone:string}> $enquiries
 * @return array{invoices:list<array{date:string,amount:float,customer_id:int}>,enquiry_customers:array<string,list<int>>}
 */
function webDashWebsiteInvoices(PDO $pdo, array $enquiries): array
{
    $empty = ['invoices' => [], 'enquiry_customers' => []];
    if ($enquiries === []) {
        return $empty;
    }
    try {
        $customers = $pdo->query("SELECT id, LOWER(TRIM(COALESCE(email, ''))) AS email, COALESCE(phone, '') AS phone FROM customers")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return $empty;
    }

    $byEmail = [];
    $byPhone = [];
    foreach ($customers as $customer) {
        $id = (int) $customer['id'];
        if ($customer['email'] !== '') {
            $byEmail[$customer['email']][] = $id;
        }
        $tail = webDashPhoneTail((string) $customer['phone']);
        if ($tail !== '') {
            $byPhone[$tail][] = $id;
        }
    }

    $firstEnquiry = [];
    $enquiryCustomers = [];
    foreach ($enquiries as $enquiry) {
        $ids = array_values(array_unique(array_merge(
            $enquiry['email'] !== '' ? ($byEmail[$enquiry['email']] ?? []) : [],
            $enquiry['phone'] !== '' ? ($byPhone[$enquiry['phone']] ?? []) : []
        )));
        $enquiryCustomers[$enquiry['quote_number']] = $ids;
        foreach ($ids as $id) {
            if (!isset($firstEnquiry[$id]) || $enquiry['date'] < $firstEnquiry[$id]) {
                $firstEnquiry[$id] = $enquiry['date'];
            }
        }
    }
    if ($firstEnquiry === []) {
        return $empty;
    }

    $ids = array_keys($firstEnquiry);
    try {
        $stmt = $pdo->prepare(
            'SELECT customer_id, invoice_date, total_amount FROM invoices
             WHERE customer_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ")
               AND COALESCE(status, '') NOT IN ('draft', 'cancelled')"
        );
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return $empty;
    }

    $invoices = [];
    foreach ($rows as $row) {
        $id = (int) $row['customer_id'];
        $date = substr((string) $row['invoice_date'], 0, 10);
        if ($date >= ($firstEnquiry[$id] ?? '9999-12-31')) {
            $invoices[] = ['date' => $date, 'amount' => (float) $row['total_amount'], 'customer_id' => $id];
        }
    }

    return ['invoices' => $invoices, 'enquiry_customers' => $enquiryCustomers];
}

function webDashChange(float $now, float $before): ?float
{
    if ($before <= 0) {
        return null;
    }

    return round(($now - $before) / $before * 100, 1);
}

function webDashRate(float $part, float $whole): ?float
{
    return $whole > 0 ? round($part / $whole * 100, 1) : null;
}

/**
 * @return array<string,mixed>
 */
function webDashboardData(PDO $pdo, string $range, string $fromInput = '', string $toInput = ''): array
{
    $ranges = webDashRanges();
    $p = webDashPeriod($range, $fromInput, $toInput);
    $from = $p['from'];
    $to = $p['to'];
    $fromStr = $from->format('Y-m-d');
    $toStr = $to->format('Y-m-d');
    $prevFromStr = $p['prevFrom']->format('Y-m-d');
    $prevToStr = $p['prevTo']->format('Y-m-d');

    $period = static function (string $date) use ($fromStr, $toStr, $prevFromStr, $prevToStr): ?string {
        if ($date >= $fromStr && $date <= $toStr) {
            return 'cur';
        }

        return ($date >= $prevFromStr && $date <= $prevToStr) ? 'prev' : null;
    };

    $days = [];
    for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
        $days[$d->format('Y-m-d')] = [
            'date' => $d->format('Y-m-d'),
            'visitors' => 0, 'product_views' => 0, 'likes' => 0,
            'enquiries' => 0, 'quotes' => 0, 'revenue' => 0.0,
        ];
    }
    $blank = ['visitors' => 0, 'product_views' => 0, 'likes' => 0, 'enquiries' => 0, 'quotes' => 0, 'converted' => 0, 'revenue' => 0.0, 'sales' => 0];
    $totals = ['cur' => $blank, 'prev' => $blank];

    $shop = webDashShopStats($prevFromStr, $toStr);
    foreach ((array) ($shop['days'] ?? []) as $row) {
        $date = (string) ($row['date'] ?? '');
        $which = $period($date);
        if ($which === null) {
            continue;
        }
        foreach (['visitors', 'product_views', 'likes'] as $key) {
            $value = (int) ($row[$key] ?? 0);
            $totals[$which][$key] += $value;
            if ($which === 'cur') {
                $days[$date][$key] = $value;
            }
        }
    }

    $enquiries = webDashEnquiries($pdo);
    $sales = webDashWebsiteInvoices($pdo, $enquiries);
    $invoiceDates = [];
    foreach ($sales['invoices'] as $invoice) {
        $invoiceDates[$invoice['customer_id']][] = $invoice['date'];
        $which = $period($invoice['date']);
        if ($which !== null) {
            $totals[$which]['revenue'] += $invoice['amount'];
            $totals[$which]['sales']++;
            if ($which === 'cur') {
                $days[$invoice['date']]['revenue'] += $invoice['amount'];
            }
        }
    }

    foreach ($enquiries as $enquiry) {
        $date = $enquiry['date'];
        $which = $period($date);
        if ($which === null) {
            continue;
        }
        $quoted = in_array($enquiry['status'], WEB_DASH_QUOTED, true);
        $converted = false;
        foreach ($sales['enquiry_customers'][$enquiry['quote_number']] ?? [] as $customerId) {
            foreach ($invoiceDates[$customerId] ?? [] as $invoiceDate) {
                $converted = $converted || $invoiceDate >= $date;
            }
        }
        $totals[$which]['enquiries']++;
        $totals[$which]['quotes'] += $quoted ? 1 : 0;
        $totals[$which]['converted'] += $converted ? 1 : 0;
        if ($which === 'cur') {
            $days[$date]['enquiries']++;
            $days[$date]['quotes'] += $quoted ? 1 : 0;
        }
    }

    [$cur, $prev] = [$totals['cur'], $totals['prev']];
    $conversion = webDashRate($cur['enquiries'], $cur['visitors']);
    $prevConversion = webDashRate($prev['enquiries'], $prev['visitors']);

    return [
        'range' => $p['key'],
        'ranges' => array_map(static fn ($key, $label) => ['key' => (string) $key, 'label' => $label], array_keys($ranges), $ranges),
        'from' => $fromStr,
        'to' => $toStr,
        'today' => (new DateTimeImmutable('today', new DateTimeZone(WEB_DASH_TZ)))->format('Y-m-d'),
        'length' => $p['length'],
        'max_custom_days' => WEB_DASH_MAX_CUSTOM_DAYS,
        'shop_connected' => $shop !== null,
        'tracking_since' => $shop['tracking_since'] ?? null,
        'likes_total' => (int) ($shop['likes_total'] ?? 0),
        'totals' => $cur,
        'changes' => [
            'visitors' => webDashChange($cur['visitors'], $prev['visitors']),
            'product_views' => webDashChange($cur['product_views'], $prev['product_views']),
            'enquiries' => webDashChange($cur['enquiries'], $prev['enquiries']),
            'quotes' => webDashChange($cur['quotes'], $prev['quotes']),
            'revenue' => webDashChange($cur['revenue'], $prev['revenue']),
            'sales' => webDashChange($cur['sales'], $prev['sales']),
            'likes' => webDashChange($cur['likes'], $prev['likes']),
            'conversion' => ($conversion !== null && $prevConversion !== null) ? round($conversion - $prevConversion, 1) : null,
        ],
        'rates' => [
            'visitor_to_enquiry' => $conversion,
            'enquiry_to_quote' => webDashRate($cur['quotes'], $cur['enquiries']),
            'enquiry_to_sale' => webDashRate($cur['converted'], $cur['enquiries']),
        ],
        'days' => array_values($days),
        'top_viewed' => array_slice((array) ($shop['top_viewed'] ?? []), 0, 8),
        'top_liked' => array_slice((array) ($shop['top_liked'] ?? []), 0, 8),
    ];
}

