<?php
// Lightweight security scan for evidence collection (do not expose publicly long-term)
// Usage (temporary): /scripts/security_scan.php?key=CHANGE_ME
// Returns JSON with any suspicious files found under assets/uploads and the web root.

header('Content-Type: application/json; charset=UTF-8');

$authKey = isset($_GET['key']) ? $_GET['key'] : '';
if ($authKey === '' || $authKey === 'CHANGE_ME') {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Forbidden: set ?key=YOUR_TEMP_KEY before use']);
  exit;
}

$root = realpath(__DIR__ . '/..');
if ($root === false) {
  echo json_encode(['ok' => false, 'error' => 'Unable to resolve project root']);
  exit;
}

$targets = [
  $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads',
  $root, // shallow scan at root for dangerous extensions (limited depth)
];

$dangerousExt = ['php','phtml','phar','php7','exe','sh','sql'];
$maxDepth = 4; // prevent excessive traversal from root

function scanDirLimited($base, $depth, $maxDepth, $dangerousExt) {
  global $root;
  $results = [];
  if (!is_dir($base)) return $results;

  $items = @scandir($base);
  if ($items === false) return $results;

  foreach ($items as $item) {
    if ($item === '.' || $item === '..') continue;
    $path = $base . DIRECTORY_SEPARATOR . $item;
    if (is_dir($path)) {
      if ($depth < $maxDepth) {
        $results = array_merge($results, scanDirLimited($path, $depth + 1, $maxDepth, $dangerousExt));
      }
    } else {
      $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
      if (in_array($ext, $dangerousExt, true)) {
        // Allow-list known legitimate project scripts at root level
        $basename = basename($path);
        $allowedRoot = [
          'index.php','login.php','logout.php','setup.php','setup_admin.php','setup_database.php','health.php','health-security.php','messages.php','notifications.php','voucher-preview.php','erp','admin','employee','includes','scripts','tasks'
        ];
        // If within uploads, any .php etc is suspicious by default
        $isUploads = strpos($path, DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR) !== false;
        $suspicious = $isUploads || (!in_array($basename, $allowedRoot, true) && $depth > 0);
        if ($suspicious) {
          $results[] = [
            'path' => str_replace($root, '', $path),
            'size' => @filesize($path),
            'mtime' => @filemtime($path),
            'ext' => $ext,
          ];
        }
      }
    }
  }
  return $results;
}

$findings = [];
foreach ($targets as $i => $dir) {
  $depthLimit = ($i === 0) ? 12 : 2; // deep dive in uploads, shallow at root
  $findings = array_merge($findings, scanDirLimited($dir, 0, $depthLimit, $dangerousExt));
}

echo json_encode([
  'ok' => true,
  'root' => $root,
  'findings_count' => count($findings),
  'findings' => $findings,
], JSON_PRETTY_PRINT);
