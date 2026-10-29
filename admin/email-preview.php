<?php
declare(strict_types=1);

/**
 * Admin-only preview of the new-employee welcome email (nothing is sent).
 * URL: /{company}/admin/email-preview.php
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/manage-users-ui/lib.php';
manageUsersUiRequireAdmin();

$in = static fn (string $key, string $default): string => trim((string) ($_GET[$key] ?? $default));

$sample = [
    'name' => $in('name', 'Zaza Juma'),
    'email' => $in('email', 'zaza@example.com'),
    'department' => $in('department', 'Sales'),
    'tagline' => $in('tagline', manageUsersCompanyTagline()),
];
$company = manageUsersCompanyLabel();
$loginUrl = manageUsersAbsoluteUrl(function_exists('company_url') ? company_url('login') : 'login.php');
$logo = function_exists('getCompanyLogoUrl') ? manageUsersAbsoluteUrl((string) getCompanyLogoUrl()) : '';

$html = manageUsersWelcomeEmailHtml([
    'first' => manageUsersFirstName($sample['name']),
    'email' => $sample['email'],
    'department' => $sample['department'],
    'company' => $company,
    'loginUrl' => $loginUrl,
    'logo' => $logo,
    'tagline' => $sample['tagline'],
]);

if (isset($_GET['raw'])) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo $html;
    exit;
}

$subject = 'Welcome to ' . ($company !== '' ? $company : 'the team') . ' - your account is ready';
$rawUrl = '?' . http_build_query(array_merge($sample, ['raw' => 1]));
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
    .ep-bar { position: sticky; top: 0; z-index: 2; background: #fff; border-bottom: 1px solid #e5e7eb; padding: 12px 20px; }
    .ep-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
    .ep-top h1 { margin: 0; font-size: 1.05rem; }
    .ep-top a { color: #4f46e5; font-size: 0.875rem; font-weight: 600; text-decoration: none; }
    .ep-meta { margin-top: 4px; font-size: 0.8125rem; color: #6b7280; }
    .ep-meta strong { color: #111827; }
    form { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; margin-top: 12px; }
    label { display: flex; flex-direction: column; gap: 4px; font-size: 0.75rem; font-weight: 600; color: #374151; }
    input { height: 34px; padding: 0 10px; border: 1px solid #e2e8f0; border-radius: 8px; font: inherit; font-size: 0.8125rem; min-width: 170px; }
    button, .ep-btn { height: 34px; padding: 0 14px; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; font: inherit; font-size: 0.8125rem; font-weight: 600; color: #374151; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; }
    button[type=submit] { background: #7c3aed; border-color: #7c3aed; color: #fff; }
    .ep-size { display: inline-flex; gap: 6px; margin-left: auto; }
    .ep-size button.is-on { background: #ede9fe; border-color: #c4b5fd; color: #5b21b6; }
    .ep-stage { display: flex; justify-content: center; padding: 24px 12px 40px; }
    iframe { width: 760px; max-width: 100%; height: 1400px; border: 0; border-radius: 12px; background: #fff; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08); transition: width .2s ease; }
</style>
</head>
<body>
<div class="ep-bar">
    <div class="ep-top">
        <h1>Welcome email preview</h1>
        <a href="<?= $h(function_exists('company_url') ? company_url('admin/manage-users.php?module=voucher') : 'manage-users.php') ?>">&larr; Back to User Management</a>
    </div>
    <div class="ep-meta">Subject: <strong><?= $h($subject) ?></strong> &middot; Preview only, no email is sent.</div>
    <form method="get">
        <label>Full name <input name="name" value="<?= $h($sample['name']) ?>"></label>
        <label>Email <input name="email" value="<?= $h($sample['email']) ?>"></label>
        <label>Department <input name="department" value="<?= $h($sample['department']) ?>"></label>
        <label>Tagline <input name="tagline" value="<?= $h($sample['tagline']) ?>" placeholder="(none)"></label>
        <button type="submit">Update preview</button>
        <a class="ep-btn" href="<?= $h($rawUrl) ?>" target="_blank" rel="noopener">Open raw HTML</a>
        <span class="ep-size">
            <button type="button" class="is-on" data-w="760">Desktop</button>
            <button type="button" data-w="390">Mobile</button>
        </span>
    </form>
</div>
<div class="ep-stage">
    <iframe id="epFrame" src="<?= $h($rawUrl) ?>" title="Email preview"></iframe>
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
