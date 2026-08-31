<?php
$path = __DIR__ . '/../frontend/src/pages/RevenueDeskPage.jsx';
$content = file_get_contents($path);

$content = preg_replace(
    '/<div className="rev-desk-loading">\s*<Loader2 className="animate-spin" size=\{20\} \/>\s*Loading revenues[^\n]*/',
    '<div className="rev-desk-loading" aria-live="polite" aria-busy="true">' . "\n"
    . '          <Loader2 className="animate-spin" size={20} aria-hidden="true" />' . "\n"
    . '          <span>Loading revenues</span>',
    $content
);

$content = preg_replace(
    '/placeholder="Search voucher, customer, narration[^"]*"/',
    'placeholder="Search voucher, customer, narration"',
    $content
);

file_put_contents($path, $content);
echo "Fixed {$path}\n";
