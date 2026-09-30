<?php
/**
 * Google search checks for CRM customers via Zenserp.
 * https://app.zenserp.com/api/v2/search
 */
declare(strict_types=1);

function crmZenserpApiKey(PDO $pdo): string
{
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'crm_zenserp_api_key' LIMIT 1");
        $stmt->execute();
        return trim((string) $stmt->fetchColumn());
    } catch (Throwable $e) {
        return '';
    }
}

function crmMarketActiveSearch(PDO $pdo): string
{
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'crm_market_active_search' LIMIT 1");
        $stmt->execute();
        $value = strtolower(trim((string) $stmt->fetchColumn()));
    } catch (Throwable $e) {
        $value = '';
    }
    if ($value === 'zenserp' || $value === 'rapid' || $value === 'off') {
        return $value === 'off' ? '' : $value;
    }
    if (crmZenserpApiKey($pdo) !== '') {
        return 'zenserp';
    }

    return 'rapid';
}

function crmMarketSaveActiveSearch(PDO $pdo, string $source): string
{
    $source = strtolower(trim($source));
    if ($source !== 'zenserp' && $source !== 'rapid') {
        $source = 'off';
    }
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('crm_market_active_search', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$source, $source]);

    return $source === 'off' ? '' : $source;
}

function crmZenserpNormalizeApiKey(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/apikey\s*[:=]\s*["\']?([^\s"\']+)/i', $raw, $match)) {
        return trim($match[1], "\"'` ,;");
    }
    if (preg_match('/\b([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})\b/', $raw, $match)) {
        return $match[1];
    }
    if (!preg_match('/\s/', $raw)) {
        return trim($raw, "\"'`");
    }
    if (preg_match_all('/[A-Za-z0-9_\-]{20,}/', $raw, $all) && $all[0] !== []) {
        usort($all[0], static function (string $a, string $b): int {
            return strlen($b) <=> strlen($a);
        });
        return $all[0][0];
    }
    $compact = preg_replace('/\s+/', '', $raw);
    return is_string($compact) ? $compact : '';
}

function crmZenserpSaveApiKey(PDO $pdo, string $key): void
{
    $key = crmZenserpNormalizeApiKey($key);
    if ($key === '' || strlen($key) > 500) {
        throw new InvalidArgumentException('Paste the API key from the Zenserp dashboard.');
    }
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('crm_zenserp_api_key', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$key, $key]);
}

/**
 * @param array<string, scalar> $query
 * @return array{ok:bool,payload:?array,error:string}
 */
function crmZenserpRequest(PDO $pdo, array $query): array
{
    $key = crmZenserpApiKey($pdo);
    if ($key === '') {
        return ['ok' => false, 'payload' => null, 'error' => 'Add a Zenserp API key to search Google for customers.'];
    }
    $query['search_engine'] = 'google';
    $url = 'https://app.zenserp.com/api/v2/search?' . http_build_query($query);
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'payload' => null, 'error' => 'Google search could not start.'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'apikey: ' . $key,
        ],
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $curlError !== '') {
        return ['ok' => false, 'payload' => null, 'error' => 'Google search failed. Try again.'];
    }
    $payload = json_decode((string) $raw, true);
    if (!is_array($payload)) {
        return ['ok' => false, 'payload' => null, 'error' => 'Google search returned an unexpected response.'];
    }
    if ($code === 401 || $code === 403) {
        return ['ok' => false, 'payload' => null, 'error' => 'Zenserp rejected the API key. In Settings, paste the key from the Zenserp dashboard and save it again.'];
    }
    if ($code < 200 || $code >= 300) {
        $message = trim((string) ($payload['error'] ?? $payload['message'] ?? ''));
        return ['ok' => false, 'payload' => null, 'error' => $message !== '' ? $message : 'Google search failed.'];
    }

    return ['ok' => true, 'payload' => $payload, 'error' => ''];
}

/**
 * @return array{ok:bool,query:string,results:array<int,array{title:string,url:string,snippet:string}>,error:string}
 */
function crmZenserpSearch(PDO $pdo, string $query): array
{
    $query = trim($query);
    if ($query === '') {
        return ['ok' => false, 'query' => '', 'results' => [], 'error' => 'This customer has no name to search.'];
    }
    $found = crmZenserpRequest($pdo, [
        'q' => $query,
        'gl' => 'tz',
        'hl' => 'en',
        'num' => 5,
        'location' => 'Dar es Salaam,Tanzania',
    ]);
    if (!$found['ok'] || !is_array($found['payload'])) {
        return ['ok' => false, 'query' => $query, 'results' => [], 'error' => $found['error']];
    }
    $results = [];
    foreach (crmZenserpOrganicRows($found['payload']) as $row) {
        $results[] = [
            'title' => $row['name'],
            'url' => $row['website'],
            'snippet' => $row['address'],
        ];
        if (count($results) >= 5) {
            break;
        }
    }

    return ['ok' => true, 'query' => $query, 'results' => $results, 'error' => ''];
}

/**
 * Google businesses and websites for a Market keyword such as "mining".
 *
 * @return array{ok:bool,rows:list<array<string,mixed>>,error:string}
 */
function crmZenserpMarketSearch(PDO $pdo, string $keyword, string $location): array
{
    $keyword = trim($keyword);
    $location = trim($location) ?: 'Tanzania';
    if ($keyword === '') {
        return ['ok' => false, 'rows' => [], 'error' => 'Enter a search term.'];
    }
    $q = $keyword;
    if (!preg_match('/\bcompan/i', $q)) {
        $q .= ' companies';
    }
    if (stripos($q, $location) === false) {
        $q .= ' in ' . $location;
    }
    $places = [
        'tanzania' => ['gl' => 'tz', 'location' => 'Dar es Salaam,Tanzania'],
        'kenya' => ['gl' => 'ke', 'location' => 'Nairobi,Kenya'],
        'uganda' => ['gl' => 'ug', 'location' => 'Kampala,Uganda'],
        'rwanda' => ['gl' => 'rw', 'location' => 'Kigali,Rwanda'],
        'south africa' => ['gl' => 'za', 'location' => 'Johannesburg,South Africa'],
        'united arab emirates' => ['gl' => 'ae', 'location' => 'Dubai,United Arab Emirates'],
        'india' => ['gl' => 'in', 'location' => 'Mumbai,India'],
        'united kingdom' => ['gl' => 'gb', 'location' => 'London,United Kingdom'],
        'united states' => ['gl' => 'us', 'location' => 'New York,United States'],
        'china' => ['gl' => 'cn', 'location' => 'Shanghai,China'],
    ];
    $place = $places[strtolower($location)] ?? ['gl' => 'tz', 'location' => ''];
    $params = [
        'q' => $q,
        'gl' => $place['gl'],
        'hl' => 'en',
        'num' => 10,
    ];
    if ($place['location'] !== '') {
        $params['location'] = $place['location'];
    }
    $found = crmZenserpRequest($pdo, $params);
    if (!$found['ok'] && $place['location'] !== '') {
        unset($params['location']);
        $found = crmZenserpRequest($pdo, $params);
    }
    if (!$found['ok'] || !is_array($found['payload'])) {
        return ['ok' => false, 'rows' => [], 'error' => $found['error']];
    }

    $rows = [];
    $seen = [];
    foreach (array_merge(crmZenserpLocalRows($found['payload']), crmZenserpOrganicRows($found['payload'])) as $row) {
        $key = strtolower($row['website'] !== '' ? $row['website'] : $row['name']);
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $row['type'] = $row['type'] !== '' ? $row['type'] : ucfirst($keyword);
        $row['city'] = $row['city'] !== '' ? $row['city'] : $location;
        $rows[] = $row;
        if (count($rows) >= 20) {
            break;
        }
    }

    return ['ok' => true, 'rows' => $rows, 'error' => ''];
}

/**
 * @return list<array<string,mixed>>
 */
function crmZenserpLocalRows(array $payload): array
{
    $lists = [];
    foreach (['local_results', 'local_pack', 'places', 'maps_results', 'maps'] as $key) {
        if (!isset($payload[$key]) || !is_array($payload[$key])) {
            continue;
        }
        $node = $payload[$key];
        if (isset($node['places']) && is_array($node['places'])) {
            $node = $node['places'];
        }
        if (array_is_list($node)) {
            $lists[] = $node;
        }
    }
    $out = [];
    foreach ($lists as $list) {
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['title'] ?? $item['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $url = trim((string) ($item['url'] ?? $item['website'] ?? $item['link'] ?? ''));
            $out[] = [
                'id' => 'zs-' . substr(sha1($url !== '' ? $url : $name), 0, 16),
                'name' => $name,
                'phone' => trim((string) ($item['phone'] ?? $item['phone_number'] ?? '')),
                'address' => trim((string) ($item['address'] ?? '')),
                'website' => $url,
                'email' => '',
                'rating' => isset($item['rating']) ? (float) $item['rating'] : null,
                'type' => trim((string) ($item['type'] ?? $item['category'] ?? 'Google')),
                'city' => trim((string) ($item['city'] ?? '')),
            ];
        }
    }

    return $out;
}

/**
 * @return list<array<string,mixed>>
 */
function crmZenserpOrganicRows(array $payload): array
{
    $rows = $payload['organic'] ?? $payload['organic_results'] ?? [];
    if (!is_array($rows)) {
        return [];
    }
    $skip = ['wikipedia.org', 'youtube.com', 'facebook.com', 'instagram.com', 'twitter.com', 'x.com', 'tiktok.com', 'google.com', 'google.co.tz'];
    $out = [];
    foreach ($rows as $item) {
        if (!is_array($item)) {
            continue;
        }
        $url = trim((string) ($item['url'] ?? $item['link'] ?? ''));
        $title = trim((string) ($item['title'] ?? ''));
        if ($url === '' || $title === '') {
            continue;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        $blocked = false;
        foreach ($skip as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                $blocked = true;
                break;
            }
        }
        if ($blocked) {
            continue;
        }
        $name = trim((string) preg_replace('/\s+[\|\-??:].*$/u', '', $title));
        if ($name === '') {
            $name = $title;
        }
        $out[] = [
            'id' => 'zs-' . substr(sha1($url), 0, 16),
            'name' => $name,
            'phone' => '',
            'address' => trim((string) ($item['description'] ?? $item['snippet'] ?? '')),
            'website' => $url,
            'email' => '',
            'rating' => null,
            'type' => 'Website',
            'city' => '',
        ];
    }

    return $out;
}
