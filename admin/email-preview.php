<?php
declare(strict_types=1);

/**
 * Admin-only preview of the new-employee welcome email (nothing is sent).
 * URL: /{company}/admin/email-preview.php
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/manage-users-ui/lib.php';
if (!defined('ERP_SKIP_COOKIE_CONSENT')) {
    define('ERP_SKIP_COOKIE_CONSENT', true);
}
manageUsersUiRequireAdmin();

$characters = manageUsersWelcomeCharacters();
$savedCharacter = manageUsersWelcomeCharacter();
$notice = '';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    $pick = (string) ($_POST['character'] ?? '');
    if (verify_csrf($_POST['csrf'] ?? '') && manageUsersSaveWelcomeCharacter($pick)) {
        $savedCharacter = $pick;
        $notice = 'Saved. New employee emails will use "' . $characters[$pick]['label'] . '".';
    } else {
        $notice = 'Could not save the character. Please try again.';
    }
}

$in = static fn (string $key, string $default): string => trim((string) ($_GET[$key] ?? $default));

$sample = [
    'name' => $in('name', 'Zaza Juma'),
    'email' => $in('email', 'zaza@example.com'),
    'department' => $in('department', 'Sales'),
    'character' => $in('character', $savedCharacter),
];
if (!array_key_exists($sample['character'], $characters)) {
    $sample['character'] = $savedCharacter;
}
$company = manageUsersCompanyLabel();
$loginUrl = manageUsersAbsoluteUrl(function_exists('company_url') ? company_url('login') : 'login.php');
$logo = function_exists('getCompanyLogoUrl') ? manageUsersAbsoluteUrl((string) getCompanyLogoUrl()) : '';

if (isset($_GET['raw'])) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo manageUsersWelcomeEmailHtml([
        'first' => manageUsersFirstName($sample['name']),
        'email' => $sample['email'],
        'department' => $sample['department'],
        'company' => $company,
        'loginUrl' => $loginUrl,
        'logo' => $logo,
        'character' => $sample['character'],
    ]);
    exit;
}

$subject = 'Welcome to ' . ($company !== '' ? $company : 'the team') . ' - your account is ready';
$query = static fn (array $extra = []): string => '?' . http_build_query(array_merge($sample, $extra));
$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Email preview - Welcome email</title>
<style>
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background: #eef0f4; color: #1f2937; }
    .ep-bar { background: #fff; border-bottom: 1px solid #e5e7eb; padding: 12px 20px; }
    .ep-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
    .ep-top h1 { margin: 0; font-size: 1.05rem; }
    .ep-top a { color: #4f46e5; font-size: 0.875rem; font-weight: 600; text-decoration: none; }
    .ep-meta { margin-top: 4px; font-size: 0.8125rem; color: #6b7280; }
    .ep-meta strong { color: #111827; }
    .ep-notice { margin-top: 8px; padding: 8px 12px; border-radius: 8px; background: #ecfdf5; color: #065f46; font-size: 0.8125rem; font-weight: 600; }
    form.ep-fields { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; margin-top: 12px; }
    label { display: flex; flex-direction: column; gap: 4px; font-size: 0.75rem; font-weight: 600; color: #374151; }
    input[type=text], input:not([type]) { height: 34px; padding: 0 10px; border: 1px solid #e2e8f0; border-radius: 8px; font: inherit; font-size: 0.8125rem; min-width: 170px; }
    button, .ep-btn { height: 34px; padding: 0 14px; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; font: inherit; font-size: 0.8125rem; font-weight: 600; color: #374151; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; }
    button.ep-primary { background: #7c3aed; border-color: #7c3aed; color: #fff; }
    .ep-size { display: inline-flex; gap: 6px; margin-left: auto; }
    .ep-size button.is-on { background: #ede9fe; border-color: #c4b5fd; color: #5b21b6; }
    .ep-chars-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 14px; font-size: 0.75rem; font-weight: 700; color: #374151; text-transform: uppercase; letter-spacing: .04em; }
    .ep-chars { display: flex; gap: 10px; overflow-x: auto; padding: 8px 0 2px; }
    .ep-char { flex: 0 0 auto; width: 108px; padding: 8px; border: 2px solid #e5e7eb; border-radius: 12px; background: #fff; text-align: center; text-decoration: none; color: #374151; font-size: 0.72rem; font-weight: 600; position: relative; }
    .ep-char:hover { border-color: #c4b5fd; }
    .ep-char.is-on { border-color: #7c3aed; box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.15); }
    .ep-char img, .ep-char .ep-none { display: block; width: 100%; height: 72px; object-fit: contain; margin-bottom: 6px; }
    .ep-char .ep-none { display: flex; align-items: center; justify-content: center; color: #9ca3af; font-size: 1.5rem; }
    .ep-char .ep-saved { position: absolute; top: 4px; right: 4px; padding: 1px 6px; border-radius: 999px; background: #16a34a; color: #fff; font-size: 0.62rem; }
    .ep-chars { flex-wrap: wrap; overflow: visible; }
    .ep-group { flex: 0 0 100%; margin-top: 4px; font-size: 0.7rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .05em; }
    .ep-stage { display: flex; justify-content: center; padding: 24px 12px 40px; }
    iframe { width: 760px; max-width: 100%; height: 1000px; border: 0; border-radius: 12px; background: #fff; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08); transition: width .2s ease; }
</style>
</head>
<body>
<div class="ep-bar">
    <div class="ep-top">
        <h1>Welcome email preview</h1>
        <a href="<?= $h(function_exists('company_url') ? company_url('admin/manage-users.php?module=voucher') : 'manage-users.php') ?>">&larr; Back to User Management</a>
    </div>
    <div class="ep-meta">Subject: <strong><?= $h($subject) ?></strong> &middot; Preview only, no email is sent.</div>
    <?php if ($notice !== ''): ?><div class="ep-notice"><?= $h($notice) ?></div><?php endif; ?>

    <form class="ep-fields" method="get">
        <input type="hidden" name="character" value="<?= $h($sample['character']) ?>">
        <label>Full name <input name="name" value="<?= $h($sample['name']) ?>"></label>
        <label>Email <input name="email" value="<?= $h($sample['email']) ?>"></label>
        <label>Department <input name="department" value="<?= $h($sample['department']) ?>"></label>
        <button type="submit" class="ep-primary">Update preview</button>
        <a class="ep-btn" href="<?= $h($query(['raw' => 1])) ?>" target="_blank" rel="noopener">Open raw HTML</a>
        <span class="ep-size">
            <button type="button" class="is-on" data-w="760">Desktop</button>
            <button type="button" data-w="390">Mobile</button>
        </span>
    </form>

    <div class="ep-chars-head">
        <span>Character</span>
        <form method="post" action="<?= $h($query()) ?>">
            <input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>">
            <input type="hidden" name="character" value="<?= $h($sample['character']) ?>">
            <button type="submit" class="ep-primary"<?= $sample['character'] === $savedCharacter ? ' disabled style="opacity:.5;cursor:default"' : '' ?>>
                <?= $sample['character'] === $savedCharacter ? 'In use for new employee emails' : 'Use "' . $h($characters[$sample['character']]['label']) . '" for new employee emails' ?>
            </button>
        </form>
    </div>
    <div class="ep-chars">
        <?php $lastGroup = ''; foreach ($characters as $key => $c): ?>
            <?php if ($c['group'] !== $lastGroup): $lastGroup = $c['group']; ?>
                <span class="ep-group"><?= $h($lastGroup) ?></span>
            <?php endif; ?>
            <?php $thumb = manageUsersWelcomeCharacterUrl($key); ?>
            <a class="ep-char<?= $key === $sample['character'] ? ' is-on' : '' ?>" href="<?= $h($query(['character' => $key])) ?>">
                <?php if ($key === $savedCharacter): ?><span class="ep-saved">In use</span><?php endif; ?>
                <?php if ($thumb !== ''): ?>
                    <img src="<?= $h($thumb) ?>" alt="">
                <?php else: ?>
                    <span class="ep-none">&#8709;</span>
                <?php endif; ?>
                <?= $h($c['label']) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<div class="ep-stage">
    <iframe id="epFrame" src="<?= $h($query(['raw' => 1])) ?>" title="Email preview"></iframe>
</div>
<script>
(function () {
    var frame = document.getElementById('epFrame');
    var btns = document.querySelectorAll('.ep-size button');
    btns.forEach(function (b) {
        b.addEventListener('click', function () {
            btns.forEach(function (x) { x.classList.toggle('is-on', x === b); });
            frame.style.width = b.getAttribute('data-w') + 'px';
        });
    });
    frame.addEventListener('load', function () {
        try { frame.style.height = (frame.contentDocument.documentElement.scrollHeight + 20) + 'px'; } catch (e) {}
    });
})();
</script>
</body>
</html>
