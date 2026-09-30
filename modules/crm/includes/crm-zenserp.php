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

function crmZenserpSaveApiKey(PDO $pdo, string $key): void
{
    $key = trim($key);
    if ($key === '' || strlen($key) > 200) {
        throw new InvalidArgumentException('Enter a Zenserp API key.');
    }
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('crm_zenserp_api_key', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$key, $key]);
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
    $key = crmZenserpApiKey($pdo);
    if ($key === '') {
        return ['ok' => false, 'query' => $query, 'results' => [], 'error' => 'Add a Zenserp API key to check customers on Google.'];
    }

    $url = 'https://app.zenserp.com/api/v2/search?' . http_build_query([
        'q' => $query,
        'search_engine' => 'google',
        'gl' => 'tz',
        'hl' => 'en',
        'num' => 5,
        'location' => 'Dar es Salaam,Tanzania',
    ]);

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'query' => $query, 'results' => [], 'error' => 'Google check could not start.'];
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
        return ['ok' => false, 'query' => $query, 'results' => [], 'error' => 'Google check failed. Try again.'];
    }

    $payload = json_decode((string) $raw, true);
    if (!is_array($payload)) {
        return ['ok' => false, 'query' => $query, 'results' => [], 'error' => 'Google check returned an unexpected response.'];
    }
    if ($code === 401 || $code === 403) {
        $message = trim((string) ($payload['error'] ?? $payload['message'] ?? ''));
        return ['ok' => false, 'query' => $query, 'results' => [], 'error' => $message !== '' ? $message : 'Zenserp rejected the API key.'];
    }
    if ($code < 200 || $code >= 300) {
        $message = trim((string) ($payload['error'] ?? $payload['message'] ?? ''));
        return ['ok' => false, 'query' => $query, 'results' => [], 'error' => $message !== '' ? $message : 'Google check failed.'];
    }

    $rows = $payload['organic'] ?? $payload['organic_results'] ?? [];
    if (!is_array($rows)) {
        $rows = [];
    }
    $results = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $link = trim((string) ($row['url'] ?? $row['link'] ?? ''));
        $title = trim((string) ($row['title'] ?? ''));
        if ($link === '' || $title === '') {
            continue;
        }
        $results[] = [
            'title' => $title,
            'url' => $link,
            'snippet' => trim((string) ($row['description'] ?? $row['snippet'] ?? '')),
        ];
        if (count($results) >= 5) {
            break;
        }
    }

    return ['ok' => true, 'query' => $query, 'results' => $results, 'error' => ''];
}
