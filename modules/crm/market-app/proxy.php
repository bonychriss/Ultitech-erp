<?php
/**
 * Same-origin reverse proxy to Client Market (Next.js on 127.0.0.1:3000).
 * The browser never talks to localhost; Apache/PHP does.
 */
declare(strict_types=1);

$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = (string) (parse_url($requestUri, PHP_URL_PATH) ?? '/');
$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$pathInfo = (string) ($_SERVER['PATH_INFO'] ?? '');

if ($pathInfo !== '') {
    $prefix = rtrim($scriptName, '/');
    $rest = $pathInfo[0] === '/' ? $pathInfo : '/' . $pathInfo;
} else {
    $marker = '/market-app';
    $pos = strpos($path, $marker);
    $prefix = $pos === false ? rtrim($scriptName, '/') : rtrim(substr($path, 0, $pos + strlen($marker)), '/');
    if (str_ends_with($scriptName, '/proxy.php')) {
        $prefix = rtrim($scriptName, '/');
    }
    $rest = $pos === false ? '/' : substr($path, $pos + strlen($marker));
    if (str_starts_with((string) $rest, '/proxy.php')) {
        $rest = substr((string) $rest, strlen('/proxy.php')) ?: '/';
    }
}
if ($rest === '' || $rest === false) {
    $rest = '/';
}
if ($rest[0] !== '/') {
    $rest = '/' . $rest;
}

$query = (string) ($_SERVER['QUERY_STRING'] ?? '');
$pathAndQuery = $rest . ($query !== '' ? '?' . $query : '');

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
    || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
$host = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? ''))[0]);
$publicOrigin = ($https ? 'https' : 'http') . '://' . $host;

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$body = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? (string) file_get_contents('php://input') : '';

$forward = [];
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') !== 0 || !is_string($value) || $value === '') {
        continue;
    }
    $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
    $skip = ['Host', 'Connection', 'Keep-Alive', 'Transfer-Encoding', 'Te', 'Trailer', 'Upgrade', 'Content-Length', 'Accept-Encoding'];
    if (in_array($name, $skip, true)) {
        continue;
    }
    $forward[] = $name . ': ' . $value;
}
$forward[] = 'X-Forwarded-Host: ' . $host;
$forward[] = 'X-Forwarded-Proto: ' . ($https ? 'https' : 'http');
$forward[] = 'X-Forwarded-Prefix: ' . $prefix;
if ($body !== '' && empty($_SERVER['CONTENT_TYPE'])) {
    $forward[] = 'Content-Type: application/octet-stream';
} elseif (!empty($_SERVER['CONTENT_TYPE'])) {
    $forward[] = 'Content-Type: ' . (string) $_SERVER['CONTENT_TYPE'];
}

$fetched = crmMarketProxyFetch($pathAndQuery, $method, $forward, $body);
if ($fetched === null) {
    crmMarketEnsureNext();
    $fetched = crmMarketProxyFetch($pathAndQuery, $method, $forward, $body);
}
if ($fetched === null) {
    crmMarketProxyDown($prefix, crmMarketLastProxyError());
}
$raw = $fetched['raw'];
$code = $fetched['code'];
$headerSize = $fetched['headerSize'];

$headerBlob = substr($raw, 0, $headerSize);
$payload = substr($raw, $headerSize);
$contentType = '';
$statusLine = 'HTTP/1.1 ' . ($code > 0 ? $code : 502);
foreach (preg_split("/\r\n/", $headerBlob) ?: [] as $i => $line) {
    if ($i === 0 && strncmp($line, 'HTTP/', 5) === 0) {
        $statusLine = $line;
        continue;
    }
    if ($line === '' || strpos($line, ':') === false) {
        continue;
    }
    [$hName, $hVal] = array_map('trim', explode(':', $line, 2));
    $low = strtolower($hName);
    if (in_array($low, ['connection', 'keep-alive', 'transfer-encoding', 'content-encoding', 'content-length'], true)) {
        continue;
    }
    if ($low === 'content-type') {
        $contentType = $hVal;
    }
    header($hName . ': ' . $hVal, false);
}
header($statusLine, true, $code > 0 ? $code : 502);

$isText = $contentType === ''
    || (bool) preg_match('#(text/|javascript|json|xml|x-component|application/javascript)#i', $contentType);

if ($isText && $payload !== '') {
    $payload = crmMarketProxyRewrite((string) $payload, $prefix, $publicOrigin);
    if (stripos($contentType, 'text/html') !== false) {
        $payload = crmMarketProxyInjectPrefixScript((string) $payload, $prefix);
    }
}

header('Content-Length: ' . (string) strlen($payload));
echo $payload;
exit;

function crmMarketProxyRewrite(string $body, string $prefix, string $publicOrigin): string
{
    $from = ['http://localhost:3000', 'http://127.0.0.1:3000', 'https://localhost:3000'];
    $to = $publicOrigin . $prefix;
    $body = str_replace($from, $to, $body);
    $pairs = [
        '"/_next/' => '"' . $prefix . '/_next/',
        "'/_next/" => "'" . $prefix . '/_next/',
        '`/_next/' => '`' . $prefix . '/_next/',
        '"/api/' => '"' . $prefix . '/api/',
        "'/api/" => "'" . $prefix . '/api/',
        '\\/_next\\/' => str_replace('/', '\\/', $prefix) . '\\/_next\\/',
        '\\/api\\/' => str_replace('/', '\\/', $prefix) . '\\/api\\/',
    ];
    return strtr($body, $pairs);
}

function crmMarketProxyInjectPrefixScript(string $html, string $prefix): string
{
    $p = json_encode($prefix, JSON_UNESCAPED_SLASHES);
    $script = '<script>(function(){var p=' . $p . ';if(!p)return;function fix(u){if(typeof u!=="string")return u;if(/^https?:\\/\\//i.test(u)){try{var x=new URL(u);if(x.origin===location.origin&&x.pathname.indexOf(p)!==0){x.pathname=p+x.pathname;return x.href}}catch(e){}return u}if(u.charAt(0)==="/"&&u.indexOf(p)!==0&&u.indexOf("//")!==0)return p+u;return u}var f=window.fetch;window.fetch=function(i,n){if(typeof i==="string")i=fix(i);else if(i instanceof Request){var u=fix(i.url);if(u!==i.url)i=new Request(u,i)}return f.call(this,i,n)};})();</script>';
    if (stripos($html, '<head>') !== false) {
        return preg_replace('/<head>/i', '<head>' . $script, $html, 1) ?? $html;
    }
    return $script . $html;
}

function crmMarketLastProxyError(?string $set = null): string
{
    static $last = '';
    if ($set !== null) {
        $last = $set;
    }
    return $last;
}

/**
 * @param list<string> $forward
 * @return array{raw:string,code:int,headerSize:int}|null
 */
function crmMarketProxyFetch(string $pathAndQuery, string $method, array $forward, string $body): ?array
{
    if (!function_exists('curl_init')) {
        crmMarketLastProxyError('PHP curl extension is not enabled.');
        return null;
    }

    $hosts = ['127.0.0.1', 'localhost'];
    $errors = [];
    foreach ($hosts as $h) {
        $target = 'http://' . $h . ':3000' . $pathAndQuery;
        $headers = $forward;
        $headers[] = 'Host: ' . $h . ':3000';
        $ch = curl_init($target);
        if ($ch === false) {
            $errors[] = $h . ': curl_init failed';
            continue;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $method === 'GET' || $method === 'HEAD' ? null : $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_ENCODING => '',
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if ($raw !== false && $errno === 0) {
            return ['raw' => (string) $raw, 'code' => $code, 'headerSize' => $headerSize];
        }
        $errors[] = $h . ': [' . $errno . '] ' . $err;
    }
    crmMarketLastProxyError(implode(' | ', $errors));
    return null;
}

function crmMarketEnsureNext(): void
{
    $fp = @fsockopen('127.0.0.1', 3000, $errno, $errstr, 0.4);
    if (is_resource($fp)) {
        fclose($fp);
        return;
    }

    $flag = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uop-client-market-spawn';
    if (is_file($flag) && (time() - (int) filemtime($flag)) < 40) {
        usleep(1500000);
        return;
    }
    @file_put_contents($flag, (string) time());

    $root = dirname(__DIR__);
    $bat = $root . DIRECTORY_SEPARATOR . 'start-client-market.bat';
    $frontend = $root . DIRECTORY_SEPARATOR . 'client Market' . DIRECTORY_SEPARATOR . 'frontend';

    if (PHP_OS_FAMILY === 'Windows' && is_file($bat)) {
        pclose(popen('cmd /C start "ClientMarket" /MIN ' . escapeshellarg($bat), 'r'));
        usleep(4000000);
        return;
    }

    if (is_dir($frontend)) {
        $log = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uop-client-market.log';
        $cmd = 'cd ' . escapeshellarg($frontend) . ' && (npm run start > ' . escapeshellarg($log) . ' 2>&1 &)';
        @exec($cmd);
        usleep(4000000);
    }
}

function crmMarketProxyDown(string $prefix, string $detail = ''): void
{
    http_response_code(502);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Client Market</title>';
    echo '<style>body{font-family:Segoe UI,sans-serif;background:#f8fafc;color:#0f172a;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center}div{max-width:40rem;padding:2rem;line-height:1.45}h1{font-size:1.25rem}code{background:#e2e8f0;padding:.1rem .35rem;border-radius:.25rem}</style></head><body><div>';
    echo '<h1>Client Market is not running on this server</h1>';
    echo '<p>Search is proxied by PHP to Next.js on port <code>3000</code> on the same machine as Apache. Starting it on your laptop does not help if the site is <code>ultitech.io</code>.</p>';
    echo '<p>On the <strong>ultitech.io</strong> server, run:</p>';
    echo '<p><code>modules/crm/start-client-market.bat</code> (Windows) or <code>cd "modules/crm/client Market/frontend" && npm run start</code> (Linux). Leave it running.</p>';
    echo '<p>Prefix: <code>' . htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8') . '</code></p>';
    if ($detail !== '') {
        echo '<p>Connect error: <code>' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</code></p>';
    }
    echo '</div></body></html>';
    exit;
}
