<?php
/**
 * Ultimate.co.tz  ?  UltiTech product link
 *
 * Upload this ONE file to the ultimate.co.tz cPanel. Do not replace the
 * website homepage. Put it in its own folder, for example:
 *
 *   /home/ultimate/public_html/ultitech/products.php
 *
 * Then open:
 *
 *   https://ultimate.co.tz/ultitech/products.php
 *
 * Edit the two settings below before you test.
 */

declare(strict_types=1);

// UltiTech address for Ultimate General Trading products.
// Ask whoever hosts UltiTech if this URL is different on the live server.
const ULTITECH_CATALOG_URL = 'https://ultitech.io/api/storefront/catalog.php?company_slug=ultimate';

// Same secret UltiTech uses as STOREFRONT_SYNC_TOKEN
// (or system setting "storefront_sync_token"). Leave blank until you have it.
const ULTITECH_API_TOKEN = '';

// How long this file remembers the last good product list (seconds).
const ULTITECH_CACHE_SECONDS = 600;

// ---------------------------------------------------------------------------

function ultitech_cache_file(): string
{
    $dir = __DIR__ . '/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir . '/ultimate-products.json';
}

function ultitech_read_cache(): ?array
{
    $file = ultitech_cache_file();
    if (!is_file($file)) {
        return null;
    }
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function ultitech_write_cache(array $payload): void
{
    $file = ultitech_cache_file();
    @file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * @return array{ok:bool, from_cache:bool, error:string, payload:array}
 */
function ultitech_load_catalog(bool $force = false): array
{
    $cached = ultitech_read_cache();
    $freshEnough = is_array($cached)
        && isset($cached['fetched_at'])
        && (time() - (int) $cached['fetched_at']) < ULTITECH_CACHE_SECONDS
        && !empty($cached['products']);

    if (!$force && $freshEnough) {
        return ['ok' => true, 'from_cache' => true, 'error' => '', 'payload' => $cached];
    }

    if (ULTITECH_API_TOKEN === '') {
        return [
            'ok' => false,
            'from_cache' => false,
            'error' => 'ULTITECH_API_TOKEN is still empty. Paste the UltiTech storefront token at the top of this file.',
            'payload' => $cached ?? [],
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'ok' => false,
            'from_cache' => false,
            'error' => 'PHP cURL is not enabled on this cPanel. In cPanel open Select PHP Version ? Extensions ? curl.',
            'payload' => $cached ?? [],
        ];
    }

    $ch = curl_init(ULTITECH_CATALOG_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . ULTITECH_API_TOKEN,
        ],
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $errno !== 0) {
        $message = 'Could not reach UltiTech. ' . ($err !== '' ? $err : 'Check ULTITECH_CATALOG_URL.');
        if (is_array($cached) && !empty($cached['products'])) {
            return ['ok' => true, 'from_cache' => true, 'error' => $message . ' Showing the last saved list.', 'payload' => $cached];
        }
        return ['ok' => false, 'from_cache' => false, 'error' => $message, 'payload' => []];
    }

    $json = json_decode((string) $body, true);
    if (!is_array($json) || empty($json['success'])) {
        $apiError = is_array($json) ? (string) ($json['error'] ?? 'UltiTech did not return products.') : 'UltiTech did not return JSON (HTTP ' . $code . ').';
        if ($code === 401) {
            $apiError = 'UltiTech refused the token. The value in ULTITECH_API_TOKEN must match STOREFRONT_SYNC_TOKEN on UltiTech.';
        }
        if (is_array($cached) && !empty($cached['products'])) {
            return ['ok' => true, 'from_cache' => true, 'error' => $apiError . ' Showing the last saved list.', 'payload' => $cached];
        }
        return ['ok' => false, 'from_cache' => false, 'error' => $apiError, 'payload' => []];
    }

    $products = [];
    foreach ($json['products'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $products[] = [
            'id' => (int) ($row['id'] ?? 0),
            'sku' => (string) ($row['sku'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'price' => (float) ($row['price'] ?? 0),
            'currency' => (string) ($row['currency'] ?? 'TZS'),
            'stock_qty' => (float) ($row['stock_qty'] ?? 0),
            'category' => (string) ($row['category'] ?? ''),
            'brand' => (string) ($row['brand'] ?? ''),
            'image_url' => (string) ($row['image_url'] ?? ''),
        ];
    }

    $payload = [
        'success' => true,
        'source' => 'ultitech',
        'company' => 'ultimate',
        'fetched_at' => time(),
        'synced_at' => (string) ($json['synced_at'] ?? date('c')),
        'count' => count($products),
        'categories' => array_values($json['categories'] ?? []),
        'products' => $products,
    ];
    ultitech_write_cache($payload);

    return ['ok' => true, 'from_cache' => false, 'error' => '', 'payload' => $payload];
}

$format = strtolower((string) ($_GET['format'] ?? ''));
if ($format === 'json') {
    $force = isset($_GET['refresh']);
    $result = ultitech_load_catalog($force);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!$result['ok']) {
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'error' => $result['error'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $payload = $result['payload'];
    $payload['from_cache'] = $result['from_cache'];
    if ($result['error'] !== '') {
        $payload['warning'] = $result['error'];
    }
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        $found = null;
        foreach ($payload['products'] ?? [] as $product) {
            if ((int) ($product['id'] ?? 0) === $id) {
                $found = $product;
                break;
            }
        }
        if ($found === null) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Product not found'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(['success' => true, 'product' => $found], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$preview = null;
$previewError = '';
if (isset($_GET['test'])) {
    $preview = ultitech_load_catalog(true);
    $previewError = (string) ($preview['error'] ?? '');
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

header('Content-Type: text/html; charset=utf-8');
$self = h((string) ($_SERVER['PHP_SELF'] ?? 'products.php'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Link ultimate.co.tz to UltiTech products</title>
<style>
  :root { color-scheme: light; }
  body { margin: 0; font-family: Georgia, "Times New Roman", serif; background: #f4f1ea; color: #1c1915; }
  main { max-width: 820px; margin: 0 auto; padding: 32px 20px 64px; }
  h1 { font-size: 1.8rem; line-height: 1.25; margin: 0 0 8px; }
  h2 { font-size: 1.2rem; margin: 32px 0 8px; }
  p, li { line-height: 1.5; }
  .lead { font-size: 1.05rem; }
  .card { background: #fff; border: 1px solid #e2d9cc; border-radius: 12px; padding: 16px 18px; margin: 12px 0; }
  code, pre { font-family: Consolas, "Courier New", monospace; font-size: 0.92rem; }
  pre { background: #1c1915; color: #f4f1ea; padding: 14px; border-radius: 10px; overflow: auto; }
  ol { padding-left: 1.2rem; }
  table { width: 100%; border-collapse: collapse; background: #fff; }
  th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #e2d9cc; vertical-align: top; }
  th { background: #efe8dc; }
  .ok { color: #0f6b3a; }
  .bad { color: #9b1c1c; }
  a { color: #8a4b08; }
</style>
</head>
<body>
<main>
  <h1>Show UltiTech products on ultimate.co.tz</h1>
  <p class="lead">The file that puts UltiTech products onto the shop, and sends new orders back, is <code>sync.php</code> in this same folder. Upload it to <code>public_html/ultitech/sync.php</code> and open it with the key written at the top of that file.</p>

  <h2>1. In UltiTech</h2>
  <div class="card">
    <ol>
      <li>Open the <strong>Ultimate</strong> company (not Roadmaster).</li>
      <li>Enter each product with a name, description, selling price, category, and photo. The website uses the product <strong>selling price</strong> (<code>unit_price</code>).</li>
      <li>Leave the product <strong>active</strong>. Inactive products are not sent to the website.</li>
      <li>On the UltiTech server, set <code>STOREFRONT_SYNC_TOKEN</code> to a long random password. The same value can also be stored as system setting <code>storefront_sync_token</code>.</li>
      <li>Confirm this address opens only with that token (it should not be public without the token):<br>
        <code><?= h(ULTITECH_CATALOG_URL) ?></code>
      </li>
    </ol>
  </div>

  <h2>2. On ultimate.co.tz cPanel</h2>
  <div class="card">
    <ol>
      <li>Create a folder <code>public_html/ultitech/</code>. Do not overwrite the site <code>index.php</code>.</li>
      <li>Upload this file as <code>public_html/ultitech/products.php</code>.</li>
      <li>Edit the top of the file and paste the UltiTech token into <code>ULTITECH_API_TOKEN</code>.</li>
      <li>If UltiTech is not at ultitech.io, change <code>ULTITECH_CATALOG_URL</code>. Keep <code>company_slug=ultimate</code> so Roadmaster products are not used.</li>
      <li>Open <a href="<?= $self ?>?test=1">this page with <code>?test=1</code></a>. You should see Ultimate products and their prices.</li>
    </ol>
  </div>

  <h2>3. What the website must do</h2>
  <div class="card">
    <p>The public shop pages should read products from this address on your own site:</p>
    <pre><?= $self ?>?format=json</pre>
    <p>One product:</p>
    <pre><?= $self ?>?format=json&amp;id=123</pre>
    <p>The token stays inside this file. Visitors never see it. The list is saved for <?= (int) (ULTITECH_CACHE_SECONDS / 60) ?> minutes. Add <code>&amp;refresh=1</code> to force a new read from UltiTech.</p>
    <p>Whoever builds the website should display <code>name</code>, <code>description</code>, <code>price</code>, <code>currency</code>, <code>image_url</code>, and <code>stock_qty</code> from that JSON. The number in <code>price</code> is the UltiTech selling price.</p>
  </div>

  <h2>Fields</h2>
  <table>
    <thead><tr><th>Field</th><th>Use on the website</th></tr></thead>
    <tbody>
      <tr><td><code>id</code></td><td>UltiTech product id. Use it for the product page link.</td></tr>
      <tr><td><code>sku</code></td><td>Product code.</td></tr>
      <tr><td><code>name</code></td><td>Product title.</td></tr>
      <tr><td><code>description</code></td><td>Product details.</td></tr>
      <tr><td><code>price</code></td><td>Selling price from UltiTech. Show this number.</td></tr>
      <tr><td><code>currency</code></td><td>Usually TZS, unless the product uses another currency in UltiTech.</td></tr>
      <tr><td><code>stock_qty</code></td><td>Quantity in stock. Hide or mark ùout of stockù when this is 0.</td></tr>
      <tr><td><code>category</code></td><td>Category name.</td></tr>
      <tr><td><code>brand</code></td><td>Brand, when the product has one.</td></tr>
      <tr><td><code>image_url</code></td><td>Photo hosted on UltiTech. Use it as the image address.</td></tr>
    </tbody>
  </table>

  <h2>Example for the website (PHP)</h2>
  <pre><?= h(<<<'PHP'
<?php
$json = file_get_contents('https://ultimate.co.tz/ultitech/products.php?format=json');
$data = json_decode($json, true);
foreach ($data['products'] ?? [] as $product) {
    echo htmlspecialchars($product['name'])
        . ' ù '
        . number_format((float) $product['price'], 2)
        . ' '
        . htmlspecialchars($product['currency']);
}
PHP
) ?></pre>

<?php if (is_array($preview)): ?>
  <h2>Test result</h2>
  <?php if ($previewError !== ''): ?>
    <p class="bad"><?= h($previewError) ?></p>
  <?php endif; ?>
  <?php if (!empty($preview['ok'])): ?>
    <?php
      $rows = $preview['payload']['products'] ?? [];
      $when = (int) ($preview['payload']['fetched_at'] ?? 0);
    ?>
    <p class="ok"><?= count($rows) ?> products<?= !empty($preview['from_cache']) ? ' (saved copy)' : ' (just read from UltiTech)' ?><?= $when ? ' ù ' . h(date('Y-m-d H:i', $when)) : '' ?>.</p>
    <table>
      <thead><tr><th>Name</th><th>Price</th><th>Stock</th><th>Category</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($rows, 0, 30) as $product): ?>
        <tr>
          <td><?= h((string) ($product['name'] ?? '')) ?><br><small><?= h((string) ($product['sku'] ?? '')) ?></small></td>
          <td><?= h(number_format((float) ($product['price'] ?? 0), 2)) ?> <?= h((string) ($product['currency'] ?? '')) ?></td>
          <td><?= h((string) ($product['stock_qty'] ?? '0')) ?></td>
          <td><?= h((string) ($product['category'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (count($rows) > 30): ?>
      <p>Showing the first 30. The JSON feed includes all <?= count($rows) ?>.</p>
    <?php endif; ?>
  <?php endif; ?>
<?php else: ?>
  <p><a href="<?= $self ?>?test=1">Test the connection</a></p>
<?php endif; ?>
</main>
</body>
</html>
