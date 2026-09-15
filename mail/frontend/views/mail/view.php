<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var common\models\MailAccount $account */
/** @var common\models\MailMessage $message */
/** @var common\models\MailFolder[] $folders */
/** @var string $folder */

use yii\bootstrap5\Html;
use yii\helpers\Url;

$this->title = $message->subject . ' - Mail';
$this->params['account'] = $account;
$this->params['folders'] = $folders;
$this->params['folder'] = $folder;

$attachments = $message->attachments;
$count = count($attachments);
$attachLabel = $count === 1 ? 'One attachment' : $count . ' attachments';

$this->registerJs(<<<'JS'
(function () {
  function openViewer(url, name, type) {
    var overlay = document.getElementById('attach-viewer');
    var frame = document.getElementById('attach-viewer-frame');
    var img = document.getElementById('attach-viewer-img');
    var title = document.getElementById('attach-viewer-name');
    var download = document.getElementById('attach-viewer-download');
    var openTab = document.getElementById('attach-viewer-open');
    if (!overlay) return;
    title.textContent = name;
    download.href = url.replace('mode=view', 'mode=download');
    openTab.href = url;
    if (type === 'image') {
      frame.style.display = 'none';
      img.style.display = 'block';
      img.src = url;
    } else {
      img.style.display = 'none';
      frame.style.display = 'block';
      frame.src = url;
    }
    overlay.classList.add('is-open');
  }
  function closeViewer() {
    var overlay = document.getElementById('attach-viewer');
    var frame = document.getElementById('attach-viewer-frame');
    var img = document.getElementById('attach-viewer-img');
    if (!overlay) return;
    overlay.classList.remove('is-open');
    if (frame) frame.src = 'about:blank';
    if (img) img.removeAttribute('src');
  }
  document.querySelectorAll('[data-attach-open]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      e.preventDefault();
      openViewer(el.getAttribute('data-url'), el.getAttribute('data-name'), el.getAttribute('data-type'));
    });
  });
  var overlay = document.getElementById('attach-viewer');
  if (overlay) {
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) closeViewer();
    });
  }
  var closeBtn = document.getElementById('attach-viewer-close');
  if (closeBtn) closeBtn.addEventListener('click', closeViewer);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeViewer();
  });
})();
JS);
?>
<div class="mail-read">
    <div class="read-toolbar">
        <a class="tool-btn" href="<?= Url::to(['/mail/index', 'folder' => $folder]) ?>" title="Back">←</a>
        <?= Html::beginForm(['/mail/trash', 'id' => $message->id], 'post', ['class' => 'inline-form']) ?>
            <?= Html::submitButton('Delete', ['class' => 'tool-btn']) ?>
        <?= Html::endForm() ?>
        <?= Html::beginForm(['/mail/star', 'id' => $message->id], 'post', ['class' => 'inline-form']) ?>
            <?= Html::submitButton($message->is_starred ? 'Unstar' : 'Star', ['class' => 'tool-btn']) ?>
        <?= Html::endForm() ?>
        <a class="tool-btn" href="<?= Url::to(['/mail/compose', 'reply' => $message->id]) ?>">Reply</a>
        <a class="tool-btn" href="<?= Url::to(['/mail/compose', 'forward' => $message->id]) ?>">Forward</a>
    </div>

    <h1 class="read-subject"><?= Html::encode($message->subject) ?></h1>

    <div class="read-header">
        <div class="sender-avatar"><?= Html::encode(mb_strtoupper(mb_substr($message->getFromDisplay(), 0, 1))) ?></div>
        <div class="sender-meta">
            <div class="sender-line">
                <strong><?= Html::encode($message->getFromDisplay()) ?></strong>
                <span class="muted">&lt;<?= Html::encode($message->from_email) ?>&gt;</span>
            </div>
            <div class="to-line">to <?= Html::encode($message->formatRecipients($message->getToList()) ?: 'me') ?></div>
        </div>
        <div class="read-date"><?= Yii::$app->formatter->asDatetime($message->date_sent) ?></div>
    </div>

    <div class="read-body">
        <?= $message->getBodyForDisplay() ?>
    </div>

    <?php if ($count > 0): ?>
        <div class="gmail-attach erp-read-attach">
            <div class="gmail-attach-head">
                <div class="gmail-attach-meta">
                    <strong><?= Html::encode($attachLabel) ?></strong>
                </div>
            </div>

            <div class="erp-attach-row erp-read-attach-row">
                <?php foreach ($attachments as $file): ?>
                    <?php
                    $viewUrl = Url::to(['/mail/attachment', 'id' => $file->id, 'mode' => 'view']);
                    $ext = strtolower(pathinfo($file->filename, PATHINFO_EXTENSION));
                    $isPdf = $file->isPdf() || $ext === 'pdf';
                    $isImage = $file->isImage() || in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'], true);
                    $chipClass = ($isPdf || $isImage) ? 'type-hot' : 'type-cool';
                    $letter = $ext !== '' ? strtoupper(mb_substr($ext, 0, 1)) : 'F';
                    if ($isPdf) {
                        $letter = 'P';
                    }
                    $type = $isPdf ? 'pdf' : ($isImage ? 'image' : 'file');
                    ?>
                    <button
                        type="button"
                        class="erp-attach-chip <?= $chipClass ?>"
                        data-attach-open
                        data-url="<?= Html::encode($viewUrl) ?>"
                        data-name="<?= Html::encode($file->filename) ?>"
                        data-type="<?= Html::encode($type) ?>"
                        title="<?= Html::encode($file->filename) ?>"
                    >
                        <span class="erp-attach-ico" aria-hidden="true">
                            <?php if ($isImage): ?>
                                <svg viewBox="0 0 24 24"><path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg>
                            <?php elseif ($isPdf): ?>
                                <span class="erp-attach-letter">P</span>
                            <?php else: ?>
                                <span class="erp-attach-letter"><?= Html::encode($letter) ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="erp-attach-name"><?= Html::encode($file->filename) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="gmail-attach-actions">
                <a class="gmail-pill" href="<?= Url::to(['/mail/compose', 'reply' => $message->id]) ?>"><span aria-hidden="true">↩</span> Reply</a>
                <a class="gmail-pill" href="<?= Url::to(['/mail/compose', 'forward' => $message->id]) ?>"><span aria-hidden="true">↪</span> Forward</a>
            </div>
        </div>

        <div id="attach-viewer" class="attach-viewer" aria-hidden="true">
            <div class="attach-viewer-card" role="dialog" aria-modal="true">
                <div class="attach-viewer-bar">
                    <div class="attach-viewer-title">
                        <strong id="attach-viewer-name">Attachment</strong>
                    </div>
                    <div class="attach-viewer-tools">
                        <a id="attach-viewer-download" href="#">Download</a>
                        <a id="attach-viewer-open" href="#" target="_blank" rel="noopener">Open</a>
                        <button type="button" id="attach-viewer-close" aria-label="Close">×</button>
                    </div>
                </div>
                <div class="attach-viewer-body">
                    <iframe id="attach-viewer-frame" title="Attachment preview"></iframe>
                    <img id="attach-viewer-img" alt="Attachment preview">
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
