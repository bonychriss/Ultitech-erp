<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var common\models\MailAccount $account */
/** @var string $folder */
/** @var string|null $q */
/** @var yii\data\ActiveDataProvider $dataProvider */
/** @var common\models\MailFolder[] $folders */

use yii\bootstrap5\Html;
use yii\helpers\Url;
use yii\widgets\LinkPager;

$this->title = ucfirst($folder) . ' - Mail';
$this->params['account'] = $account;
$this->params['folders'] = $folders;
$this->params['folder'] = $folder;

$titles = [
    'inbox' => 'Inbox',
    'starred' => 'Starred',
    'sent' => 'Sent',
    'drafts' => 'Drafts',
    'trash' => 'Trash',
    'spam' => 'Spam',
    'archive' => 'Archive',
];

$unread = 0;
foreach ($folders as $f) {
    if ($f->slug === $folder) {
        $unread = (int) $f->unread_count;
        break;
    }
}

$pageTitle = $titles[$folder] ?? ucfirst($folder);
?>
<div class="mail-toolbar erp-toolbar">
    <div class="erp-toolbar-title">
        <h1><?= Html::encode($pageTitle) ?></h1>
        <?php if ($unread > 0): ?>
            <div class="erp-unread-meta"><?= (int) $unread ?> unread</div>
        <?php elseif ($q): ?>
            <div class="erp-unread-meta">Results for “<?= Html::encode($q) ?>”</div>
        <?php else: ?>
            <div class="erp-unread-meta">All caught up</div>
        <?php endif; ?>
    </div>
</div>

<div class="mail-list erp-mail-list">
    <?php if ($dataProvider->getCount() === 0): ?>
        <div class="empty-state">
            <h2>No conversations</h2>
            <p>Nothing in <?= Html::encode($pageTitle) ?> yet.</p>
        </div>
    <?php else: ?>
        <?php foreach ($dataProvider->getModels() as $message): ?>
            <?php
            /** @var common\models\MailMessage $message */
            $unreadClass = !$message->is_read ? 'unread' : '';
            $href = $message->is_draft
                ? Url::to(['/mail/compose', 'draft' => $message->id])
                : Url::to(['/mail/view', 'id' => $message->id]);
            $from = $folder === 'sent' || $folder === 'drafts'
                ? ($message->formatRecipients($message->getToList()) ?: '(no recipients)')
                : $message->getFromDisplay();
            $attachments = $message->attachments;
            ?>
            <div class="mail-row erp-row <?= $unreadClass ?>">
                <label class="erp-check">
                    <input type="checkbox" tabindex="-1">
                </label>
                <?= Html::beginForm(['/mail/star', 'id' => $message->id], 'post', ['class' => 'star-form']) ?>
                    <button type="submit" class="star-btn <?= $message->is_starred ? 'on' : '' ?>" title="Star">
                        <svg viewBox="0 0 24 24"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>
                    </button>
                <?= Html::endForm() ?>
                <a class="mail-row-link erp-row-link" href="<?= $href ?>">
                    <?php if (!$message->is_read): ?>
                        <span class="erp-unread-dot" aria-hidden="true"></span>
                    <?php else: ?>
                        <span class="erp-unread-dot is-empty" aria-hidden="true"></span>
                    <?php endif; ?>
                    <span class="col-from"><?= Html::encode($from) ?></span>
                    <span class="col-content">
                        <span class="erp-line">
                            <span class="subject"><?= Html::encode($message->subject ?: '(no subject)') ?></span>
                            <?php if ($message->snippet): ?>
                                <span class="snippet"> — <?= Html::encode($message->snippet) ?></span>
                            <?php endif; ?>
                        </span>
                        <?php if ($attachments): ?>
                            <span class="erp-attach-row">
                                <?php foreach ($attachments as $file): ?>
                                    <?php
                                    $ext = strtolower(pathinfo($file->filename, PATHINFO_EXTENSION));
                                    $isPdf = $file->isPdf() || $ext === 'pdf';
                                    $isImage = $file->isImage() || in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'], true);
                                    $shortName = $file->filename;
                                    if (mb_strlen($shortName) > 16) {
                                        $shortName = mb_substr($shortName, 0, 14) . '…';
                                    }
                                    ?>
                                    <span class="erp-attach-chip" title="<?= Html::encode($file->filename) ?>">
                                        <?= $this->render('_file_type_icon', [
                                            'isPdf' => $isPdf,
                                            'isImage' => $isImage,
                                            'filename' => $file->filename,
                                        ]) ?>
                                        <span class="erp-attach-name"><?= Html::encode($shortName) ?></span>
                                    </span>
                                <?php endforeach; ?>
                            </span>
                        <?php endif; ?>
                    </span>
                    <span class="col-meta">
                        <span class="date"><?= Yii::$app->formatter->asDatetime($message->date_sent, 'php:j M') ?></span>
                    </span>
                </a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="mail-pager">
    <?= LinkPager::widget([
        'pagination' => $dataProvider->pagination,
        'options' => ['class' => 'pagination'],
    ]) ?>
</div>
