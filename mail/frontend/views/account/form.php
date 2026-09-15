<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var common\models\MailAccount $model */
/** @var common\models\MailAccount|null $account */
/** @var array $folders */
/** @var string $title */

use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;

$this->title = $title;
$this->params['account'] = $account;
$this->params['folders'] = $folders ?: ($account?->folders ?? []);
$this->params['folder'] = 'settings';
?>
<div class="settings-panel">
    <h1><?= Html::encode($title) ?></h1>
    <p class="muted">Use your company mailbox IMAP/SMTP settings (Gmail, Microsoft 365, cPanel, etc.).</p>

    <?php $form = ActiveForm::begin(['options' => ['class' => 'account-form']]); ?>
        <div class="form-grid">
            <?= $form->field($model, 'email')->textInput(['type' => 'email']) ?>
            <?= $form->field($model, 'display_name')->textInput() ?>
        </div>

        <h2 class="section-title">Incoming (IMAP)</h2>
        <div class="form-grid">
            <?= $form->field($model, 'imap_host')->textInput(['placeholder' => 'imap.gmail.com']) ?>
            <?= $form->field($model, 'imap_port')->textInput(['type' => 'number']) ?>
            <?= $form->field($model, 'imap_encryption')->dropDownList(['ssl' => 'SSL', 'tls' => 'TLS', 'none' => 'None']) ?>
            <?= $form->field($model, 'imap_username')->textInput() ?>
            <?= $form->field($model, 'imap_password_plain')->passwordInput(['placeholder' => $model->isNewRecord ? '' : 'Leave blank to keep current']) ?>
        </div>

        <h2 class="section-title">Outgoing (SMTP)</h2>
        <div class="form-grid">
            <?= $form->field($model, 'smtp_host')->textInput(['placeholder' => 'smtp.gmail.com']) ?>
            <?= $form->field($model, 'smtp_port')->textInput(['type' => 'number']) ?>
            <?= $form->field($model, 'smtp_encryption')->dropDownList(['tls' => 'TLS', 'ssl' => 'SSL', 'none' => 'None']) ?>
            <?= $form->field($model, 'smtp_username')->textInput() ?>
            <?= $form->field($model, 'smtp_password_plain')->passwordInput(['placeholder' => $model->isNewRecord ? '' : 'Leave blank to keep current']) ?>
        </div>

        <div class="compose-actions">
            <?= Html::submitButton($model->isNewRecord ? 'Connect account' : 'Save changes', ['class' => 'send-btn']) ?>
            <?= Html::a('Cancel', ['/account/index'], ['class' => 'draft-btn']) ?>
        </div>
    <?php ActiveForm::end(); ?>
</div>
