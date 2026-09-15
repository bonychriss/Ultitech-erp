<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var \frontend\models\SignupForm $model */

use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;
use yii\helpers\Url;

$this->title = 'Register';
?>
<div class="split-login">
    <section class="split-left">
        <div class="split-left-inner">
            <a class="split-logo" href="<?= Url::to(['/site/login']) ?>">
                <span class="split-logo-mark" aria-hidden="true">
                    <svg viewBox="0 0 40 40" width="40" height="40">
                        <circle cx="20" cy="20" r="20" fill="#1f6feb"/>
                        <path d="M10 14h20v12H10z" fill="none" stroke="#fff" stroke-width="2"/>
                        <path d="M10 14l10 8 10-8" fill="none" stroke="#fff" stroke-width="2"/>
                    </svg>
                </span>
                <span class="split-logo-text">
                    <strong>Mail</strong>
                    <small>ERP Workspace</small>
                </span>
            </a>

            <div class="split-art" aria-hidden="true">
                <svg viewBox="0 0 520 360" xmlns="http://www.w3.org/2000/svg" role="img">
                    <ellipse cx="260" cy="300" rx="180" ry="28" fill="#e8f2ee"/>
                    <rect x="150" y="100" width="220" height="150" rx="18" fill="#d9efe8" stroke="#6f9e94" stroke-width="3"/>
                    <circle cx="260" cy="160" r="28" fill="#4f8a7c"/>
                    <rect x="220" y="200" width="80" height="12" rx="6" fill="#3f6f64" opacity=".35"/>
                    <rect x="235" y="222" width="50" height="10" rx="5" fill="#3f6f64" opacity=".25"/>
                </svg>
            </div>

            <div class="split-left-foot">
                <div>© <?= date('Y') ?> Mail for ERP</div>
                <div>Powered by Yii2</div>
            </div>
        </div>
    </section>

    <section class="split-right">
        <div class="split-form-wrap">
            <h1>Register</h1>

            <?php $form = ActiveForm::begin([
                'id' => 'form-signup',
                'options' => ['class' => 'split-form'],
                'fieldConfig' => [
                    'template' => "{label}\n{input}\n{error}",
                    'labelOptions' => ['class' => 'split-label'],
                    'inputOptions' => ['class' => 'split-input'],
                    'errorOptions' => ['class' => 'split-error'],
                    'options' => ['class' => 'split-field'],
                ],
            ]); ?>

            <?= $form->field($model, 'username')->textInput([
                'autofocus' => true,
                'placeholder' => 'Choose a username',
            ])->label('Username') ?>

            <?= $form->field($model, 'email')->textInput([
                'placeholder' => 'Enter your email',
            ])->label('Email') ?>

            <?= $form->field($model, 'password')->passwordInput([
                'placeholder' => 'Create a password',
            ])->label('Password') ?>

            <?= Html::submitButton('Create Account', [
                'class' => 'split-btn',
                'name' => 'signup-button',
            ]) ?>

            <?php ActiveForm::end(); ?>

            <p class="split-register">
                Already have an account?
                <?= Html::a('Login Now', ['site/login']) ?>
            </p>
        </div>

        <div class="split-right-foot">
            <a href="#">Terms and Services</a>
            <span>Need help? Contact your ERP admin</span>
        </div>
    </section>
</div>
