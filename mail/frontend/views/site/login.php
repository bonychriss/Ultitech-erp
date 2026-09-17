<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var \common\models\LoginForm $model */

use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;
use yii\helpers\Url;

$this->title = 'Login';
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
                    <defs>
                        <linearGradient id="g1" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0%" stop-color="#d9efe8"/>
                            <stop offset="100%" stop-color="#b7d8cf"/>
                        </linearGradient>
                    </defs>
                    <ellipse cx="260" cy="300" rx="180" ry="28" fill="#e8f2ee"/>
                    <rect x="140" y="90" width="240" height="170" rx="18" fill="url(#g1)" stroke="#6f9e94" stroke-width="3"/>
                    <path d="M140 115l120 78 120-78" fill="none" stroke="#3f6f64" stroke-width="4" stroke-linecap="round"/>
                    <circle cx="120" cy="70" r="10" fill="#7eb8ad"/>
                    <circle cx="400" cy="80" r="7" fill="#c26b6b"/>
                    <circle cx="390" cy="250" r="9" fill="#7eb8ad"/>
                    <path d="M80 220c30-40 70-40 100 0" fill="none" stroke="#8bb5ab" stroke-width="8" stroke-linecap="round"/>
                    <path d="M350 210c25-35 60-35 85 0" fill="none" stroke="#8bb5ab" stroke-width="8" stroke-linecap="round"/>
                    <rect x="205" y="155" width="110" height="14" rx="7" fill="#3f6f64" opacity=".35"/>
                    <rect x="220" y="180" width="80" height="10" rx="5" fill="#3f6f64" opacity=".25"/>
                    <g transform="translate(95 240)">
                        <circle cx="18" cy="10" r="12" fill="#2f5d52"/>
                        <rect x="6" y="22" width="24" height="28" rx="8" fill="#4f8a7c"/>
                    </g>
                    <g transform="translate(390 235)">
                        <circle cx="18" cy="10" r="12" fill="#2f5d52"/>
                        <rect x="6" y="22" width="24" height="28" rx="8" fill="#6aa89a"/>
                    </g>
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
            <h1>Login</h1>

            <?php $form = ActiveForm::begin([
                'id' => 'login-form',
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
                'placeholder' => 'Enter your username',
                'autocomplete' => 'username',
            ])->label('Username') ?>

            <?= $form->field($model, 'password', [
                'template' => "{label}\n{input}\n{error}\n<div class=\"split-forgot\">" .
                    Html::a('Forgot Password?', ['site/request-password-reset']) .
                    "</div>",
            ])->passwordInput([
                'placeholder' => 'Enter your password',
                'autocomplete' => 'current-password',
            ])->label('Password') ?>

            <div class="split-remember">
                <?= $form->field($model, 'rememberMe', [
                    'template' => "{input} {label}\n{error}",
                    'options' => ['class' => 'split-check'],
                    'labelOptions' => ['class' => ''],
                ])->checkbox(['class' => ''], false) ?>
            </div>

            <?= Html::submitButton('Login to Mail', [
                'class' => 'split-btn',
                'name' => 'login-button',
            ]) ?>

            <?php ActiveForm::end(); ?>

            <p class="split-register">
                Don't have an account?
                <?= Html::a('Register Now', ['site/signup']) ?>
            </p>

        </div>

        <div class="split-right-foot">
            <a href="#">Terms and Services</a>
            <span>Need help? Contact your ERP admin</span>
        </div>
    </section>
</div>
