<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var frontend\models\ComposeForm $model */
/** @var common\models\MailAttachment[] $existingAttachments */
/** @var bool $open */
/** @var string $popupTitle */

use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;
use yii\helpers\Url;

$existingAttachments = $existingAttachments ?? [];
$open = $open ?? false;
$popupTitle = $popupTitle ?? 'New Message';
?>
<div id="compose-popup" class="compose-popup<?= $open ? ' is-open' : '' ?>" aria-hidden="<?= $open ? 'false' : 'true' ?>">
    <div class="compose-popup-card">
        <div class="compose-popup-title">
            <span id="compose-popup-heading"><?= Html::encode($popupTitle) ?></span>
            <div class="compose-popup-controls">
                <button type="button" class="compose-win-btn" id="compose-minimize" title="Minimize" aria-label="Minimize">—</button>
                <button type="button" class="compose-win-btn" id="compose-close" title="Close" aria-label="Close">×</button>
            </div>
        </div>

        <?php $form = ActiveForm::begin([
            'id' => 'compose-form',
            'action' => ['/mail/send'],
            'options' => [
                'class' => 'compose-form',
                'enctype' => 'multipart/form-data',
            ],
        ]); ?>
            <?= Html::activeHiddenInput($model, 'in_reply_to') ?>
            <?= Html::activeHiddenInput($model, 'draft_id') ?>
            <?= $form->field($model, 'to')->textInput([
                'placeholder' => 'Recipients',
                'id' => 'compose-to',
            ])->label(false) ?>
            <?= $form->field($model, 'cc')->textInput(['placeholder' => 'Cc'])->label(false) ?>
            <?= $form->field($model, 'bcc')->textInput(['placeholder' => 'Bcc'])->label(false) ?>
            <?= $form->field($model, 'subject')->textInput([
                'placeholder' => 'Subject',
                'id' => 'compose-subject',
            ])->label(false) ?>
            <?= $form->field($model, 'body')->textarea([
                'rows' => 10,
                'placeholder' => 'Write your message…',
                'class' => 'form-control compose-body',
                'id' => 'compose-body',
            ])->label(false) ?>

            <div class="attach-box">
                <label class="attach-label" for="compose-attachments">
                    <span class="attach-icon">📎</span>
                    Attach PDF or files
                </label>
                <?= $form->field($model, 'attachments[]')->fileInput([
                    'id' => 'compose-attachments',
                    'multiple' => true,
                    'accept' => '.pdf,.png,.jpg,.jpeg,.gif,.doc,.docx,.xls,.xlsx,.txt,.csv,.zip',
                    'class' => 'attach-input',
                ])->label(false) ?>
                <div class="attach-hint">PDF, images, Office docs, ZIP — up to 10 files, 15MB each</div>
                <div id="attach-list" class="attach-list"></div>
            </div>

            <?php if ($existingAttachments): ?>
                <div class="existing-attach">
                    <div class="existing-title">Saved on this draft</div>
                    <?php foreach ($existingAttachments as $file): ?>
                        <div class="attach-chip">
                            <?= Html::encode($file->filename) ?>
                            <span class="muted">(<?= Yii::$app->formatter->asShortSize($file->size) ?>)</span>
                            <?php if ($file->isPdf()): ?>
                                <a href="<?= Url::to(['/mail/attachment', 'id' => $file->id, 'mode' => 'view']) ?>" target="_blank">View PDF</a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="compose-actions">
                <?= Html::submitButton('Send', ['class' => 'send-btn']) ?>
                <?= Html::submitButton('Save draft', [
                    'class' => 'draft-btn',
                    'formaction' => Url::to(['/mail/save-draft']),
                ]) ?>
            </div>
        <?php ActiveForm::end(); ?>
    </div>
</div>
