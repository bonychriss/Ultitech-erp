<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var string $content */

use common\widgets\Alert;
use frontend\assets\MailAsset;
use frontend\models\ComposeForm;
use yii\bootstrap5\Html;
use yii\helpers\Json;
use yii\helpers\Url;

MailAsset::register($this);

$folder = $this->params['folder'] ?? 'inbox';
$folders = $this->params['folders'] ?? [];
$account = $this->params['account'] ?? null;
$q = Yii::$app->request->get('q', '');

$composeModel = $this->params['composeModel'] ?? new ComposeForm();
$composeOpen = (bool) ($this->params['composeOpen'] ?? false);
$composeTitle = $this->params['composeTitle'] ?? 'New Message';
$existingAttachments = $this->params['composeAttachments'] ?? [];

$username = Yii::$app->user->identity->username ?? 'User';
$displayName = $username === 'demo' ? 'System Admin' : ucwords(str_replace(['.', '_', '-'], ' ', $username));
$roleLabel = $username === 'demo' ? 'Admin' : 'User';
$initial = mb_strtoupper(mb_substr($displayName, 0, 1));

$inboxUnread = 0;
foreach ($folders as $f) {
    if ($f->slug === 'inbox') {
        $inboxUnread = (int) $f->unread_count;
        break;
    }
}

if ($composeOpen) {
    $openOpts = [
        'title' => $composeTitle,
        'to' => $composeModel->to,
        'subject' => $composeModel->subject,
        'body' => $composeModel->body,
        'inReplyTo' => $composeModel->in_reply_to,
        'draftId' => $composeModel->draft_id,
    ];
    $this->registerJs(
        'window.__MAIL_OPEN_COMPOSE__ = ' . Json::htmlEncode($openOpts) . ';',
        \yii\web\View::POS_HEAD,
    );
}

$icons = [
    'modules' => 'M4 8h4V4H4v4zm6 12h4v-4h-4v4zm-6 0h4v-4H4v4zm0-6h4v-4H4v4zm6 0h4v-4h-4v4zm6-10v4h4V4h-4zm-6 4h4V4h-4v4zm6 6h4v-4h-4v4zm0 6h4v-4h-4v4z',
    'inbox' => 'M18 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM6 4h5v8l-2.5-1.5L6 12V4z',
    'starred' => 'M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z',
    'sent' => 'M2.01 21L23 12 2.01 3 2 10l15 2-15 2z',
    'drafts' => 'M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z',
    'archive' => 'M20.54 5.23l-1.39-1.68C18.88 3.21 18.47 3 18 3H6c-.47 0-.88.21-1.16.55L3.46 5.23C3.17 5.57 3 6.02 3 6.5V19c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6.5c0-.48-.17-.93-.46-1.27zM12 17.5L6.5 12H10v-2h4v2h3.5L12 17.5zM5.12 5l.81-1h12l.94 1H5.12z',
    'spam' => 'M12 2L1 21h22L12 2zm0 3.99L19.53 19H4.47L12 5.99zM11 16h2v2h-2v-2zm0-6h2v4h-2v-4z',
    'trash' => 'M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z',
    'paint' => 'M12 3c-4.97 0-9 4.03-9 9s4.03 9 9 9c.83 0 1.5-.67 1.5-1.5 0-.39-.15-.74-.39-1.01-.23-.26-.38-.61-.38-.99 0-.83.67-1.5 1.5-1.5H16c2.76 0 5-2.24 5-5 0-4.42-4.03-8-9-8zm-5.5 9c-.83 0-1.5-.67-1.5-1.5S5.67 9 6.5 9 8 9.67 8 10.5 7.33 12 6.5 12zm3-4C8.67 8 8 7.33 8 6.5S8.67 5 9.5 5s1.5.67 1.5 1.5S10.33 8 9.5 8zm5 0c-.83 0-1.5-.67-1.5-1.5S13.67 5 14.5 5s1.5.67 1.5 1.5S15.33 8 14.5 8zm3 4c-.83 0-1.5-.67-1.5-1.5S16.67 9 17.5 9s1.5.67 1.5 1.5-.67 1.5-1.5 1.5z',
];

$folderOrder = ['inbox', 'starred', 'sent', 'drafts', 'archive', 'spam', 'trash'];
$bySlug = [];
foreach ($folders as $f) {
    $bySlug[$f->slug] = $f;
}
$orderedFolders = [];
foreach ($folderOrder as $slug) {
    if (isset($bySlug[$slug])) {
        $orderedFolders[] = $bySlug[$slug];
        unset($bySlug[$slug]);
    }
}
foreach ($bySlug as $f) {
    $orderedFolders[] = $f;
}
?>
<?php $this->beginPage() ?>
<!DOCTYPE html>
<html lang="<?= Yii::$app->language ?>">
<head>
    <meta charset="<?= Yii::$app->charset ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php $this->registerCsrfMetaTags() ?>
    <title><?= Html::encode($this->title ?: 'Mail') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?php $this->head() ?>
</head>
<body class="mail-app">
<?php $this->beginBody() ?>
<?= Alert::widget(['options' => ['class' => 'mail-flash']]) ?>
<div class="gmail-shell erp-shell">
    <header class="erp-top">
        <div class="erp-top-left">
            <a href="<?= Url::to(['/mail/index']) ?>" class="erp-logo" aria-label="Ultimate Trading">
                <span class="erp-logo-mark">
                    <span class="erp-logo-bar gold"></span>
                    <span class="erp-logo-bar dark"></span>
                    <span class="erp-logo-bar gold"></span>
                </span>
                <span class="erp-logo-text">
                    <span>ULTIMATE</span>
                    <span>TRADING</span>
                </span>
            </a>
            <button type="button" class="erp-menu-btn" id="erp-nav-toggle" aria-label="Toggle menu">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h18v2H3v-2z"/></svg>
            </button>
        </div>

        <form class="erp-search" method="get" action="<?= Url::to(['/mail/index']) ?>">
            <input type="hidden" name="folder" value="<?= Html::encode(in_array($folder, ['compose', 'settings'], true) ? 'inbox' : $folder) ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
            <input type="search" name="q" value="<?= Html::encode((string) $q) ?>" placeholder="Search mail..." autocomplete="off">
        </form>

        <div class="erp-top-actions">
            <?php if ($account): ?>
                <?= Html::beginForm(['/mail/sync'], 'post', ['class' => 'erp-sync-form']) ?>
                    <button type="submit" class="erp-sync-btn">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 6V3L8 7l4 4V8c2.76 0 5 2.24 5 5a5 5 0 0 1-8.9 3.1l-1.46 1.46A7 7 0 0 0 19 13c0-3.87-3.13-7-7-7zm-7 7c0-1.48.48-2.85 1.3-3.96l1.46 1.46A4.98 4.98 0 0 0 7 13c0 2.76 2.24 5 5 5v-3l4 4-4 4v-3c-3.87 0-7-3.13-7-7z"/></svg>
                        Sync
                    </button>
                <?= Html::endForm() ?>
            <?php endif; ?>
            <button type="button" class="erp-new-mail" id="compose-open-btn">New mail</button>
            <div class="erp-user-menu">
                <?= Html::a('Accounts', ['/account/index'], ['class' => 'erp-quiet-link']) ?>
                <?= Html::beginForm(['/site/logout'], 'post', ['class' => 'logout-form']) ?>
                    <?= Html::submitButton('Sign out', ['class' => 'erp-quiet-link btn-reset']) ?>
                <?= Html::endForm() ?>
            </div>
        </div>
    </header>

    <div class="erp-body">
        <aside class="gmail-nav erp-nav" id="erp-nav">
            <div class="erp-profile">
                <div class="erp-avatar"><?= Html::encode($initial) ?></div>
                <div class="erp-profile-text">
                    <div class="erp-profile-name"><?= Html::encode($displayName) ?></div>
                    <div class="erp-profile-role"><?= Html::encode($roleLabel) ?></div>
                </div>
            </div>

            <div class="erp-nav-label">MAIN</div>
            <nav class="folder-list">
                <a class="folder-item" href="<?= Url::to(['/']) ?>">
                    <svg viewBox="0 0 24 24"><path d="<?= $icons['modules'] ?>"/></svg>
                    <span class="folder-name">All Modules</span>
                </a>
                <?php foreach ($orderedFolders as $f):
                    $active = $folder === $f->slug ? 'active' : '';
                    $path = $icons[$f->slug] ?? $icons['inbox'];
                    $showBadge = (int) $f->unread_count > 0 && $f->slug === 'inbox';
                ?>
                    <a class="folder-item <?= $active ?>" href="<?= Url::to(['/mail/index', 'folder' => $f->slug]) ?>">
                        <svg viewBox="0 0 24 24"><path d="<?= $path ?>"/></svg>
                        <span class="folder-name"><?= Html::encode($f->name) ?></span>
                        <?php if ($showBadge): ?>
                            <span class="badge"><?= (int) $f->unread_count ?></span>
                        <?php endif; ?>
                    </a>
                    <?php if ($f->slug === 'drafts'): ?>
                        <?php
                        $hasArchive = false;
                        foreach ($orderedFolders as $of) {
                            if ($of->slug === 'archive') {
                                $hasArchive = true;
                                break;
                            }
                        }
                        ?>
                        <?php if (!$hasArchive): ?>
                            <span class="folder-item is-muted" title="Coming soon">
                                <svg viewBox="0 0 24 24"><path d="<?= $icons['archive'] ?>"/></svg>
                                <span class="folder-name">Archive</span>
                            </span>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>

            <a class="folder-item erp-personalization" href="<?= Url::to(['/account/index']) ?>">
                <svg viewBox="0 0 24 24"><path d="<?= $icons['paint'] ?>"/></svg>
                <span class="folder-name">Personalization</span>
            </a>
        </aside>

        <section class="gmail-main erp-main" data-inbox-unread="<?= (int) $inboxUnread ?>">
            <?= $content ?>
        </section>
    </div>
</div>

<?php if ($account): ?>
    <?= $this->render('/mail/_compose_popup', [
        'model' => $composeModel,
        'open' => $composeOpen,
        'popupTitle' => $composeTitle,
        'existingAttachments' => $existingAttachments,
    ]) ?>
<?php endif; ?>

<?php
$this->registerJs(<<<'JS'
(function () {
  var btn = document.getElementById('erp-nav-toggle');
  var nav = document.getElementById('erp-nav');
  if (btn && nav) {
    btn.addEventListener('click', function () {
      document.body.classList.toggle('erp-nav-collapsed');
    });
  }
})();
JS);
?>

<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
