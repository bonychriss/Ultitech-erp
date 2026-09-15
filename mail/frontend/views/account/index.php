<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var common\models\MailAccount[] $accounts */
/** @var common\models\MailAccount|null $account */
/** @var array $folders */

use yii\bootstrap5\Html;
use yii\helpers\Url;

$this->title = 'Accounts - Mail';
$this->params['account'] = $account;
$this->params['folders'] = $account ? $account->folders : [];
$this->params['folder'] = 'settings';
?>
<div class="settings-panel">
    <div class="settings-head">
        <h1>Email accounts</h1>
        <a class="send-btn" href="<?= Url::to(['/account/create']) ?>">Add account</a>
    </div>

    <?php if (!$accounts): ?>
        <div class="empty-state">
            <h2>No mailbox yet</h2>
            <p>Connect IMAP/SMTP credentials to sync and send like Gmail.</p>
            <a class="send-btn" href="<?= Url::to(['/account/create']) ?>">Connect mailbox</a>
        </div>
    <?php else: ?>
        <div class="account-cards">
            <?php foreach ($accounts as $row): ?>
                <div class="account-card">
                    <div>
                        <strong><?= Html::encode($row->display_name ?: $row->email) ?></strong>
                        <div class="muted"><?= Html::encode($row->email) ?></div>
                        <div class="muted">IMAP <?= Html::encode($row->imap_host) ?> · SMTP <?= Html::encode($row->smtp_host) ?></div>
                    </div>
                    <div class="account-actions">
                        <a href="<?= Url::to(['/account/update', 'id' => $row->id]) ?>">Edit</a>
                        <?= Html::beginForm(['/account/delete', 'id' => $row->id], 'post') ?>
                            <?= Html::submitButton('Remove', ['class' => 'link-danger btn-reset', 'data-confirm' => 'Remove this account and all synced mail?']) ?>
                        <?= Html::endForm() ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
