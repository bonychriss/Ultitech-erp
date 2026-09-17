<?php

declare(strict_types=1);

namespace frontend\controllers;

use common\models\LoginForm;
use common\models\MailAccount;
use common\models\MailAttachment;
use common\models\MailMessage;
use common\models\User;
use common\services\AttachmentStorageService;
use common\services\ImapSyncService;
use common\services\MailSchemaService;
use common\services\MailSsoService;
use common\services\SmtpSendService;
use frontend\models\ComposeForm;
use frontend\models\SignupForm;
use Yii;
use yii\filters\AccessControl;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UploadedFile;
use yii\web\JsonResponseFormatter;

/**
 * JSON API for the React mail frontend.
 */
class ApiController extends Controller
{
    /** SPA uses session cookies; CSRF is unreliable on some cPanel PATH_INFO setups. */
    public $enableCsrfValidation = false;

    public function behaviors(): array
    {
        return [
            'contentNegotiator' => [
                'class' => ContentNegotiator::class,
                'formats' => [
                    'application/json' => Response::FORMAT_JSON,
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'login' => ['post'],
                    'signup' => ['post'],
                    'logout' => ['post'],
                    'sign-out-mailbox' => ['post'],
                    'send' => ['post'],
                    'draft' => ['post'],
                    'star' => ['post'],
                    'trash' => ['post'],
                    'sync' => ['post'],
                    'accounts' => ['get', 'post'],
                    'account' => ['get', 'put', 'delete'],
                    'available-mailboxes' => ['get'],
                    'claim-mailbox' => ['post'],
                    'pool-accounts' => ['get', 'post'],
                    'pool-account' => ['delete'],
                ],
            ],
            'access' => [
                'class' => AccessControl::class,
                'except' => ['bootstrap', 'login', 'signup'],
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
                'denyCallback' => static function () {
                    Yii::$app->response->format = Response::FORMAT_JSON;
                    Yii::$app->response->statusCode = 401;
                    Yii::$app->response->data = ['ok' => false, 'message' => 'Unauthorized'];
                    Yii::$app->end();
                },
            ],
        ];
    }

    public function beforeAction($action): bool
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        Yii::$app->response->formatters[Response::FORMAT_JSON] = [
            'class' => JsonResponseFormatter::class,
            'encodeOptions' => JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        ];
        if (!Yii::$app->user->isGuest) {
            MailSchemaService::ensurePoolColumns();
        }
        return parent::beforeAction($action);
    }

    public function actionBootstrap(): array
    {
        $user = Yii::$app->user;
        $payload = [
            'ok' => true,
            'csrf' => Yii::$app->request->getCsrfToken(),
            'csrfParam' => Yii::$app->request->csrfParam,
            'authenticated' => !$user->isGuest,
            'user' => null,
            'account' => null,
            'is_mail_admin' => false,
            'available_mailboxes' => 0,
        ];

        if (!$user->isGuest) {
            MailSchemaService::ensurePoolColumns();
            $payload['user'] = [
                'id' => $user->id,
                'username' => $user->identity->username,
                'email' => $user->identity->email,
            ];
            $payload['is_mail_admin'] = $this->isMailAdmin();
            $account = $this->findAccount(false);
            if ($account) {
                $payload['account'] = $this->serializeAccount($account);
                $payload['folders'] = array_map([$this, 'serializeFolder'], $account->folders);
            } else {
                $payload['folders'] = [];
                $payload['available_mailboxes'] = count($this->listClaimableMailboxes());
            }
            $welcome = Yii::$app->session->getFlash('mail_welcome');
            if (is_string($welcome) && trim($welcome) !== '') {
                $payload['message'] = trim($welcome);
            }
        }

        return $payload;
    }

    public function actionLogin(): array
    {
        $model = new LoginForm();
        $model->username = (string) Yii::$app->request->post('username', '');
        $model->password = (string) Yii::$app->request->post('password', '');
        $model->rememberMe = (bool) Yii::$app->request->post('rememberMe', true);

        if ($model->login()) {
            $payload = $this->actionBootstrap();
            $payload['message'] = 'Welcome back! You are signed in.';
            return $payload;
        }

        Yii::$app->response->statusCode = 422;
        return [
            'ok' => false,
            'message' => implode(' ', $model->getFirstErrors()) ?: 'Login failed.',
        ];
    }

    public function actionSignup(): array
    {
        $body = Yii::$app->request->post();
        if ($body === []) {
            $decoded = json_decode(Yii::$app->request->rawBody, true);
            $body = is_array($decoded) ? $decoded : [];
        }

        $model = new SignupForm();
        $model->username = trim((string) ($body['username'] ?? ''));
        $model->email = trim((string) ($body['email'] ?? ''));
        $model->password = (string) ($body['password'] ?? '');
        $passwordConfirm = (string) ($body['password_confirm'] ?? $body['passwordConfirm'] ?? '');

        if ($model->password !== '' && $model->password !== $passwordConfirm) {
            Yii::$app->response->statusCode = 422;
            return ['ok' => false, 'message' => 'Password confirmation does not match.'];
        }

        $ok = $model->signup(
            Yii::$app->mailer,
            (string) (Yii::$app->params['supportEmail'] ?? 'support@example.com'),
            (string) (Yii::$app->name ?: 'Mail'),
        );

        if ($ok !== true) {
            Yii::$app->response->statusCode = 422;
            return [
                'ok' => false,
                'message' => implode(' ', $model->getFirstErrors()) ?: 'Registration failed.',
                'errors' => $model->getErrors(),
            ];
        }

        $user = User::findOne(['username' => $model->username]);
        if (!$user) {
            Yii::$app->response->statusCode = 500;
            return ['ok' => false, 'message' => 'Account created but login failed.'];
        }

        Yii::$app->user->login($user, 3600 * 24 * 30);
        $payload = $this->actionBootstrap();
        $payload['message'] = 'Registration successful. Your account has been created.';
        return $payload;
    }

    public function actionLogout(): array
    {
        Yii::$app->user->logout();
        return ['ok' => true];
    }

    /** Leave the active mailbox and return to the account picker (stay signed into the app). */
    public function actionSignOutMailbox(): array
    {
        $this->setMailboxSignedOut(true);
        Yii::$app->session->remove('mail_active_account_id');

        return [
            'ok' => true,
            'message' => 'Signed out of mailbox.',
            'mailboxes' => $this->listClaimableMailboxes(),
        ];
    }

    public function actionFolders(): array
    {
        $account = $this->findAccount();
        return [
            'ok' => true,
            'folders' => array_map([$this, 'serializeFolder'], $account->folders),
            'account' => $this->serializeAccount($account),
        ];
    }

    public function actionMessages(string $folder = 'inbox', ?string $q = null): array
    {
        $account = $this->findAccount();
        $query = MailMessage::find()->where(['account_id' => $account->id]);
        $term = $q !== null ? trim($q) : '';
        $searching = $term !== '';

        if ($searching) {
            $query->andWhere([
                'or',
                ['like', 'subject', $term],
                ['like', 'from_name', $term],
                ['like', 'from_email', $term],
                ['like', 'to_emails', $term],
                ['like', 'cc_emails', $term],
                ['like', 'snippet', $term],
                ['like', 'body_text', $term],
            ]);
        } elseif ($folder === 'starred') {
            $query->andWhere(['is_starred' => 1])->andWhere(['not', ['is_draft' => 1]]);
        } else {
            $folderModel = $account->getFolderBySlug($folder);
            if (!$folderModel) {
                throw new NotFoundHttpException('Folder not found.');
            }
            $query->andWhere(['folder_id' => $folderModel->id]);
        }

        $messages = $query->with(['attachments', 'folder'])
            ->orderBy(['date_sent' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($searching ? 150 : 100)
            ->all();

        return [
            'ok' => true,
            'folder' => $folder,
            'q' => $term,
            'searching' => $searching,
            'messages' => array_map([$this, 'serializeMessageList'], $messages),
        ];
    }

    public function actionMessage(int $id): array
    {
        $account = $this->findAccount();
        $message = $this->findMessage($account->id, $id);
        $message->markRead(true);

        return [
            'ok' => true,
            'message' => $this->serializeMessageDetail($message),
        ];
    }

    public function actionSend(): array
    {
        $account = $this->findAccount();
        $form = new ComposeForm();
        $form->setScenario('send');
        $form->load(Yii::$app->request->post(), 'ComposeForm') || $form->setAttributes(Yii::$app->request->post());
        $form->attachments = UploadedFile::getInstancesByName('attachments');

        if (!$form->validate()) {
            Yii::$app->response->statusCode = 422;
            return ['ok' => false, 'message' => implode(' ', $form->getFirstErrors())];
        }

        $result = (new SmtpSendService())->send($account, $form->attributes, $form->attachments ?: []);
        if (!$result['ok']) {
            Yii::$app->response->statusCode = 400;
        }
        return $result;
    }

    public function actionDraft(): array
    {
        $account = $this->findAccount();
        $form = new ComposeForm();
        $form->load(Yii::$app->request->post(), 'ComposeForm') || $form->setAttributes(Yii::$app->request->post());
        $form->attachments = UploadedFile::getInstancesByName('attachments');
        $draft = (new SmtpSendService())->saveDraft(
            $account,
            $form->attributes,
            $form->draft_id ? (int) $form->draft_id : null,
            $form->attachments ?: [],
        );

        return ['ok' => true, 'message' => 'Draft saved.', 'id' => $draft->id];
    }

    public function actionStar(int $id): array
    {
        $account = $this->findAccount();
        $message = $this->findMessage($account->id, $id);
        $message->toggleStar();
        return ['ok' => true, 'is_starred' => (bool) $message->is_starred];
    }

    public function actionTrash(int $id): array
    {
        $account = $this->findAccount();
        $message = $this->findMessage($account->id, $id);
        $trash = $account->getFolderBySlug('trash');
        if ($trash && (int) $message->folder_id === (int) $trash->id) {
            foreach ($message->attachments as $file) {
                if ($file->storage_path && is_file($file->storage_path)) {
                    @unlink($file->storage_path);
                }
            }
            $message->delete();
            return ['ok' => true, 'message' => 'Deleted forever.'];
        }
        if ($trash) {
            $old = $message->folder;
            $message->folder_id = $trash->id;
            $message->save(false, ['folder_id', 'updated_at']);
            $old?->refreshUnreadCount();
            $trash->refreshUnreadCount();
        }
        return ['ok' => true, 'message' => 'Moved to Trash.'];
    }

    public function actionSync(): array
    {
        try {
            $account = $this->findAccount();
            // Release session lock so /api/messages and other calls are not blocked
            // while IMAP connects (wrong password / slow host can take a long time).
            if (Yii::$app->has('session', true)) {
                Yii::$app->session->close();
            }
            return (new ImapSyncService())->sync($account);
        } catch (\Throwable $e) {
            Yii::error($e->getMessage() . "\n" . $e->getTraceAsString(), __METHOD__);
            Yii::$app->response->statusCode = 500;
            return [
                'ok' => false,
                'message' => 'Sync failed: ' . $e->getMessage(),
                'imported' => 0,
            ];
        }
    }

    public function actionAttachment(int $id, string $mode = 'download')
    {
        Yii::$app->response->format = Response::FORMAT_RAW;
        $account = $this->findAccount();
        $attachment = MailAttachment::find()
            ->alias('a')
            ->innerJoin(['m' => MailMessage::tableName()], 'm.id = a.message_id')
            ->where(['a.id' => $id, 'm.account_id' => $account->id])
            ->one();
        if (!$attachment) {
            throw new NotFoundHttpException('Attachment not found.');
        }
        $path = (new AttachmentStorageService())->absolutePath($attachment);
        if ($path === null) {
            throw new NotFoundHttpException('File missing.');
        }
        $inline = $mode === 'view' && ($attachment->isPdf() || $attachment->isImage());
        return Yii::$app->response->sendFile($path, $attachment->filename, [
            'mimeType' => $attachment->mime_type ?: 'application/octet-stream',
            'inline' => $inline,
        ]);
    }

    public function actionAccounts(): array
    {
        MailSchemaService::ensurePoolColumns();
        if (Yii::$app->request->isPost) {
            return $this->saveAccount(null);
        }

        $accounts = MailAccount::find()
            ->where(['user_id' => Yii::$app->user->id])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        return [
            'ok' => true,
            'accounts' => array_map([$this, 'serializeAccountDetail'], $accounts),
            'is_mail_admin' => $this->isMailAdmin(),
        ];
    }

    /** Team / company mailboxes the current user can log into. */
    public function actionAvailableMailboxes(): array
    {
        MailSchemaService::ensurePoolColumns();

        return [
            'ok' => true,
            'mailboxes' => $this->listClaimableMailboxes(),
        ];
    }

    /** User picks a team mailbox and enters the password admin shared with them. */
    public function actionClaimMailbox(): array
    {
        MailSchemaService::ensurePoolColumns();
        $body = Yii::$app->request->getBodyParams();
        if (!is_array($body) || $body === []) {
            $decoded = json_decode(Yii::$app->request->rawBody, true);
            $body = is_array($decoded) ? $decoded : [];
        }

        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        $id = (int) ($body['id'] ?? 0);

        if (($email === '' && $id < 1) || $password === '') {
            Yii::$app->response->statusCode = 422;
            return ['ok' => false, 'message' => 'Choose a mailbox and enter its password.'];
        }

        $uid = (int) Yii::$app->user->id;
        $ownedQuery = MailAccount::find()->where(['user_id' => $uid, 'is_active' => 1]);
        if ($id > 0) {
            $ownedQuery->andWhere(['id' => $id]);
        } elseif ($email !== '') {
            $ownedQuery->andWhere(['email' => $email]);
        }
        $owned = $ownedQuery->one();
        if ($owned) {
            $probe = (new ImapSyncService())->testLogin($owned, $password);
            if (!$probe['ok']) {
                Yii::$app->response->statusCode = 422;
                return [
                    'ok' => false,
                    'message' => 'Login failed. Check the password your admin sent you.',
                ];
            }
            $owned->imap_password_plain = $password;
            $owned->smtp_password_plain = $password;
            $owned->save(false);
            $this->setMailboxSignedOut(false);
            Yii::$app->session->set('mail_active_account_id', (int) $owned->id);

            return [
                'ok' => true,
                'message' => 'Mailbox connected. You will not need to enter this password again.',
                'account' => $this->serializeAccount($owned),
                'folders' => array_map([$this, 'serializeFolder'], $owned->folders),
            ];
        }

        if ($this->findAccount(false)) {
            // Allow connecting another address; block only if this email is already linked.
            $dupQuery = MailAccount::find()->where([
                'user_id' => $uid,
                'is_active' => 1,
            ]);
            if ($id > 0) {
                $tpl = MailAccount::findOne($id);
                if ($tpl) {
                    $dupQuery->andWhere(['email' => $tpl->email]);
                } elseif ($email !== '') {
                    $dupQuery->andWhere(['email' => $email]);
                }
            } elseif ($email !== '') {
                $dupQuery->andWhere(['email' => $email]);
            }
            if ($dupQuery->exists()) {
                Yii::$app->response->statusCode = 422;
                return ['ok' => false, 'message' => 'That mailbox is already connected to your account.'];
            }
        }

        $company = $this->currentCompany();
        $unclaimed = MailAccount::find()
            ->where(['is_active' => 1])
            ->andWhere(['or', ['company' => $company], ['company' => '']])
            ->andWhere(['or', ['user_id' => null], ['user_id' => 0]]);
        if ($id > 0) {
            $unclaimed->andWhere(['id' => $id]);
        } else {
            $unclaimed->andWhere(['email' => $email]);
        }
        $account = $unclaimed->one();

        $template = null;
        if (!$account) {
            $templateQuery = MailAccount::find()
                ->where(['is_active' => 1])
                ->andWhere(['or', ['company' => $company], ['company' => '']]);
            if ($id > 0) {
                $templateQuery->andWhere(['id' => $id]);
            } else {
                $templateQuery->andWhere(['email' => $email]);
            }
            $template = $templateQuery->orderBy(['id' => SORT_ASC])->one();
            if (!$template) {
                Yii::$app->response->statusCode = 404;
                return ['ok' => false, 'message' => 'That mailbox is not available. Ask admin to create it.'];
            }
            $account = $template;
        }

        $probe = (new ImapSyncService())->testLogin($account, $password);
        if (!$probe['ok']) {
            Yii::$app->response->statusCode = 422;
            return [
                'ok' => false,
                'message' => 'Login failed. Check the password your admin sent you.',
            ];
        }

        // Unclaimed pool row → bind to this user. Already-used company mailbox → clone for this user.
        if ($template !== null || !$account->isUnclaimed()) {
            $account = $this->cloneMailboxForUser($account, $password);
            if ($account === null) {
                Yii::$app->response->statusCode = 500;
                return ['ok' => false, 'message' => 'Could not connect that mailbox.'];
            }
        } else {
            $account->user_id = (int) Yii::$app->user->id;
            $account->company = $account->company !== '' ? $account->company : $company;
            $account->imap_password_plain = $password;
            $account->smtp_password_plain = $password;
            $account->imap_username = $account->imap_username ?: $account->email;
            $account->smtp_username = $account->smtp_username ?: $account->email;
            $account->save(false);
            $account->ensureDefaultFolders();
        }

        $this->setMailboxSignedOut(false);
        Yii::$app->session->set('mail_active_account_id', (int) $account->id);

        return [
            'ok' => true,
            'message' => 'Mailbox connected. You will not need to enter this password again.',
            'account' => $this->serializeAccount($account),
            'folders' => array_map([$this, 'serializeFolder'], $account->folders),
        ];
    }

    /**
     * Distinct company mailboxes this user can still connect to
     * (unclaimed pool first, then shared addresses already used by teammates).
     * After mailbox sign-out, lists this user's own accounts so they can pick again.
     *
     * @return list<array{id:int,email:string,display_name:string,account_type?:string}>
     */
    private function listClaimableMailboxes(): array
    {
        $company = $this->currentCompany();
        $uid = (int) Yii::$app->user->id;
        $signedOut = $this->isMailboxSignedOut();

        if ($signedOut) {
            $owned = MailAccount::find()
                ->where(['user_id' => $uid, 'is_active' => 1])
                ->orderBy(['email' => SORT_ASC, 'id' => SORT_ASC])
                ->all();
            $mailboxes = [];
            $seen = [];
            foreach ($owned as $a) {
                $email = strtolower(trim((string) $a->email));
                if ($email === '' || isset($seen[$email])) {
                    continue;
                }
                $seen[$email] = true;
                $mailboxes[] = [
                    'id' => (int) $a->id,
                    'email' => $a->email,
                    'display_name' => $a->display_name,
                    'account_type' => (string) ($a->account_type ?? ''),
                ];
            }
            if ($mailboxes !== []) {
                return $mailboxes;
            }
        }

        $mine = MailAccount::find()
            ->select(['email'])
            ->where(['user_id' => $uid, 'is_active' => 1])
            ->column();
        $mineLower = array_map('strtolower', $mine);

        $rows = MailAccount::find()
            ->where(['is_active' => 1])
            ->andWhere(['or', ['company' => $company], ['company' => '']])
            ->orderBy([
                // Prefer unclaimed rows, then oldest template for stable ids
                'user_id' => SORT_ASC,
                'id' => SORT_ASC,
            ])
            ->all();

        $mailboxes = [];
        $seen = [];
        foreach ($rows as $a) {
            $email = strtolower(trim((string) $a->email));
            if ($email === '' || str_ends_with($email, '@mail.local')) {
                continue;
            }
            if (in_array($email, $mineLower, true) || isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;
            $mailboxes[] = [
                'id' => (int) $a->id,
                'email' => $a->email,
                'display_name' => $a->display_name,
                'account_type' => (string) ($a->account_type ?? ''),
            ];
        }

        return $mailboxes;
    }

    private function cloneMailboxForUser(MailAccount $template, string $password): ?MailAccount
    {
        $account = new MailAccount([
            'user_id' => (int) Yii::$app->user->id,
            'created_by' => (int) Yii::$app->user->id,
            'company' => $template->company !== '' ? $template->company : $this->currentCompany(),
            'account_type' => (string) ($template->account_type ?? ''),
            'email' => $template->email,
            'display_name' => $template->display_name,
            'imap_host' => $template->imap_host,
            'imap_port' => (int) $template->imap_port,
            'imap_encryption' => $template->imap_encryption,
            'imap_username' => $template->imap_username ?: $template->email,
            'smtp_host' => $template->smtp_host,
            'smtp_port' => (int) $template->smtp_port,
            'smtp_encryption' => $template->smtp_encryption,
            'smtp_username' => $template->smtp_username ?: $template->email,
            'is_active' => 1,
        ]);
        $account->imap_password_plain = $password;
        $account->smtp_password_plain = $password;
        if (!$account->save(false)) {
            return null;
        }
        $account->ensureDefaultFolders();

        return $account;
    }

    /** Admin: list / create unclaimed team mailboxes. */
    public function actionPoolAccounts(): array
    {
        if (!$this->isMailAdmin()) {
            Yii::$app->response->statusCode = 403;
            return ['ok' => false, 'message' => 'Only mail admins can manage team mailboxes.'];
        }
        MailSchemaService::ensurePoolColumns();

        if (Yii::$app->request->isPost) {
            return $this->savePoolAccount();
        }

        $rows = MailAccount::find()
            ->where(['company' => $this->currentCompany()])
            ->andWhere(['or', ['user_id' => null], ['user_id' => 0]])
            ->orderBy(['email' => SORT_ASC])
            ->all();

        return [
            'ok' => true,
            'mailboxes' => array_map([$this, 'serializePoolMailbox'], $rows),
        ];
    }

    public function actionPoolAccount(int $id): array
    {
        if (!$this->isMailAdmin()) {
            Yii::$app->response->statusCode = 403;
            return ['ok' => false, 'message' => 'Only mail admins can manage team mailboxes.'];
        }
        $model = MailAccount::find()
            ->where(['id' => $id, 'company' => $this->currentCompany()])
            ->andWhere(['or', ['user_id' => null], ['user_id' => 0]])
            ->one();
        if (!$model) {
            throw new NotFoundHttpException('Mailbox not found.');
        }
        if (strtoupper((string) Yii::$app->request->method) === 'DELETE') {
            $model->delete();
            return ['ok' => true, 'message' => 'Team mailbox removed.'];
        }
        return ['ok' => true, 'mailbox' => $this->serializePoolMailbox($model)];
    }

    private function savePoolAccount(): array
    {
        $body = Yii::$app->request->getBodyParams();
        if (!is_array($body) || $body === []) {
            $decoded = json_decode(Yii::$app->request->rawBody, true);
            $body = is_array($decoded) ? $decoded : [];
        }

        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = trim((string) ($body['imap_password'] ?? $body['password'] ?? ''));
        if ($email === '' || $password === '') {
            Yii::$app->response->statusCode = 422;
            return ['ok' => false, 'message' => 'Email and mailbox password are required.'];
        }

        $exists = MailAccount::find()
            ->where(['email' => $email, 'company' => $this->currentCompany()])
            ->andWhere(['or', ['user_id' => null], ['user_id' => 0]])
            ->exists();
        if ($exists) {
            Yii::$app->response->statusCode = 422;
            return ['ok' => false, 'message' => 'That team mailbox is already listed.'];
        }

        $domain = substr(strrchr($email, '@') ?: '', 1) ?: 'localhost';
        $display = trim((string) ($body['display_name'] ?? ''));
        if ($display === '') {
            $display = strtoupper(str_replace(['.', '-', '_'], ' ', explode('@', $email)[0] ?? 'MAIL'));
        }

        $model = new MailAccount([
            'user_id' => null,
            'created_by' => (int) Yii::$app->user->id,
            'company' => $this->currentCompany(),
            'account_type' => strtolower(trim((string) ($body['account_type'] ?? (explode('@', $email)[0] ?? '')))),
            'email' => $email,
            'display_name' => $display,
            'imap_host' => trim((string) ($body['imap_host'] ?? ('mail.' . $domain))),
            'imap_port' => (int) ($body['imap_port'] ?? 993),
            'imap_encryption' => (string) ($body['imap_encryption'] ?? 'ssl'),
            'imap_username' => trim((string) ($body['imap_username'] ?? $email)),
            'smtp_host' => trim((string) ($body['smtp_host'] ?? ('mail.' . $domain))),
            'smtp_port' => (int) ($body['smtp_port'] ?? 465),
            'smtp_encryption' => (string) ($body['smtp_encryption'] ?? 'ssl'),
            'smtp_username' => trim((string) ($body['smtp_username'] ?? $email)),
            'is_active' => 1,
        ]);
        $model->imap_password_plain = $password;
        $model->smtp_password_plain = trim((string) ($body['smtp_password'] ?? $password));

        $probe = (new ImapSyncService())->testLogin($model, $password);
        if (!$probe['ok']) {
            Yii::$app->response->statusCode = 422;
            return [
                'ok' => false,
                'message' => 'Could not verify IMAP with that password. Create the mailbox in StackCP first, then try again.',
            ];
        }

        if (!$model->save()) {
            Yii::$app->response->statusCode = 422;
            $errors = $model->getFirstErrors();
            return [
                'ok' => false,
                'message' => $errors ? reset($errors) : 'Could not save team mailbox.',
            ];
        }

        return [
            'ok' => true,
            'message' => 'Team mailbox ready. Staff can log into it from the Mail login screen.',
            'mailbox' => $this->serializePoolMailbox($model),
        ];
    }

    private function serializePoolMailbox(MailAccount $a): array
    {
        return [
            'id' => $a->id,
            'email' => $a->email,
            'display_name' => $a->display_name,
            'account_type' => (string) ($a->account_type ?? ''),
            'imap_host' => $a->imap_host,
            'imap_port' => (int) $a->imap_port,
            'smtp_host' => $a->smtp_host,
            'smtp_port' => (int) $a->smtp_port,
            'has_password' => $a->getDecryptedImapPassword() !== '',
            'created_at' => $a->created_at,
        ];
    }

    private function isMailAdmin(): bool
    {
        if (Yii::$app->user->isGuest) {
            return false;
        }
        $username = strtolower((string) Yii::$app->user->identity->username);
        $email = strtolower((string) Yii::$app->user->identity->email);
        return $username === 'admin'
            || str_starts_with($username, 'admin')
            || str_starts_with($email, 'admin@')
            || $email === 'sales@roadmasterspares.com';
    }

    private function currentCompany(): string
    {
        $company = MailSsoService::expectedCompany();
        if ($company !== '') {
            return $company;
        }
        return 'roadmaster';
    }

    public function actionAccount(int $id): array
    {
        $model = $this->findOwnedAccount($id);
        $method = strtoupper((string) Yii::$app->request->method);

        if ($method === 'DELETE') {
            $model->delete();
            return ['ok' => true, 'message' => 'Account removed.'];
        }

        if ($method === 'PUT' || $method === 'POST') {
            return $this->saveAccount($model);
        }

        return [
            'ok' => true,
            'account' => $this->serializeAccountDetail($model),
        ];
    }

    private function saveAccount(?MailAccount $model): array
    {
        $isNew = $model === null;
        if ($isNew) {
            $model = new MailAccount([
                'user_id' => Yii::$app->user->id,
                'imap_port' => 993,
                'imap_encryption' => 'ssl',
                'smtp_port' => 587,
                'smtp_encryption' => 'tls',
                'is_active' => 1,
            ]);
        }

        $body = Yii::$app->request->getBodyParams();
        if (!is_array($body) || $body === []) {
            $raw = Yii::$app->request->rawBody;
            $decoded = json_decode($raw, true);
            $body = is_array($decoded) ? $decoded : [];
        }

        MailSchemaService::ensurePoolColumns();
        $fields = [
            'email',
            'display_name',
            'account_type',
            'imap_host',
            'imap_port',
            'imap_encryption',
            'imap_username',
            'smtp_host',
            'smtp_port',
            'smtp_encryption',
            'smtp_username',
        ];
        foreach ($fields as $field) {
            if (array_key_exists($field, $body)) {
                $value = $body[$field];
                if ($field === 'account_type') {
                    $value = strtolower(trim((string) $value));
                }
                $model->$field = $value;
            }
        }

        $imapPass = trim((string) ($body['imap_password'] ?? $body['imap_password_plain'] ?? ''));
        $smtpPass = trim((string) ($body['smtp_password'] ?? $body['smtp_password_plain'] ?? ''));
        $model->imap_password_plain = $imapPass;
        $model->smtp_password_plain = $smtpPass;
        $model->user_id = (int) Yii::$app->user->id;

        if ($isNew) {
            $dup = MailAccount::find()
                ->where([
                    'user_id' => (int) Yii::$app->user->id,
                    'email' => strtolower(trim((string) $model->email)),
                    'is_active' => 1,
                ])
                ->exists();
            if ($dup) {
                Yii::$app->response->statusCode = 422;
                return ['ok' => false, 'message' => 'That mailbox is already connected.'];
            }
        }

        if ($isNew && ($imapPass === '' || $smtpPass === '')) {
            Yii::$app->response->statusCode = 422;
            return [
                'ok' => false,
                'message' => 'IMAP and SMTP passwords are required.',
            ];
        }

        if (!$model->save()) {
            Yii::$app->response->statusCode = 422;
            $errors = $model->getFirstErrors();
            return [
                'ok' => false,
                'message' => $errors ? reset($errors) : 'Could not save account.',
                'errors' => $model->getErrors(),
            ];
        }

        // When a password was supplied, verify IMAP before reporting success.
        if ($imapPass !== '') {
            $model->refresh();
            $probe = (new ImapSyncService())->testLogin($model, $imapPass);
            if (!$probe['ok']) {
                Yii::$app->response->statusCode = 422;
                return [
                    'ok' => false,
                    'message' => $probe['message']
                        . ' Re-check the password in StackCP Email Accounts and paste it again.',
                    'account' => $this->serializeAccountDetail($model),
                ];
            }
        }

        $this->setMailboxSignedOut(false);
        Yii::$app->session->set('mail_active_account_id', (int) $model->id);

        return [
            'ok' => true,
            'message' => $isNew ? 'Mailbox connected.' : 'Account updated. IMAP login verified.',
            'account' => $this->serializeAccountDetail($model),
        ];
    }

    private function findOwnedAccount(int $id): MailAccount
    {
        $model = MailAccount::findOne(['id' => $id, 'user_id' => Yii::$app->user->id]);
        if (!$model) {
            throw new NotFoundHttpException('Account not found.');
        }
        return $model;
    }

    private function findAccount(bool $required = true): ?MailAccount
    {
        if ($this->isMailboxSignedOut()) {
            if ($required) {
                Yii::$app->response->statusCode = 404;
                Yii::$app->response->data = ['ok' => false, 'message' => 'No mail account connected.'];
                Yii::$app->end();
            }
            return null;
        }

        $uid = (int) Yii::$app->user->id;
        $preferredId = (int) Yii::$app->session->get('mail_active_account_id', 0);
        $account = null;
        if ($preferredId > 0) {
            $account = MailAccount::findOne([
                'id' => $preferredId,
                'user_id' => $uid,
                'is_active' => 1,
            ]);
        }
        if (!$account) {
            $account = MailAccount::find()
                ->where(['user_id' => $uid, 'is_active' => 1])
                ->orderBy(['id' => SORT_DESC])
                ->one();
        }
        if (!$account && $required) {
            Yii::$app->response->statusCode = 404;
            Yii::$app->response->data = ['ok' => false, 'message' => 'No mail account connected.'];
            Yii::$app->end();
        }
        return $account;
    }

    private function isMailboxSignedOut(): bool
    {
        return (bool) Yii::$app->session->get('mail_mailbox_signed_out', false);
    }

    private function setMailboxSignedOut(bool $signedOut): void
    {
        if ($signedOut) {
            Yii::$app->session->set('mail_mailbox_signed_out', true);
            return;
        }
        Yii::$app->session->remove('mail_mailbox_signed_out');
    }

    private function findMessage(int $accountId, int $id): MailMessage
    {
        $message = MailMessage::findOne(['id' => $id, 'account_id' => $accountId]);
        if (!$message) {
            throw new NotFoundHttpException('Message not found.');
        }
        return $message;
    }

    private function serializeAccount(MailAccount $account): array
    {
        return [
            'id' => $account->id,
            'email' => $account->email,
            'display_name' => $account->display_name,
            'account_type' => (string) ($account->account_type ?? ''),
            'last_synced_at' => $account->last_synced_at,
        ];
    }

    private function serializeAccountDetail(MailAccount $account): array
    {
        return [
            'id' => $account->id,
            'email' => $account->email,
            'display_name' => $account->display_name,
            'account_type' => (string) ($account->account_type ?? ''),
            'imap_host' => $account->imap_host,
            'imap_port' => (int) $account->imap_port,
            'imap_encryption' => $account->imap_encryption,
            'imap_username' => $account->imap_username,
            'smtp_host' => $account->smtp_host,
            'smtp_port' => (int) $account->smtp_port,
            'smtp_encryption' => $account->smtp_encryption,
            'smtp_username' => $account->smtp_username,
            'is_active' => (bool) $account->is_active,
            'last_synced_at' => $account->last_synced_at,
            'has_imap_password' => $account->imap_password !== '',
            'has_smtp_password' => $account->smtp_password !== '',
        ];
    }

    private function serializeFolder($folder): array
    {
        return [
            'id' => $folder->id,
            'name' => $folder->name,
            'slug' => $folder->slug,
            'unread_count' => (int) $folder->unread_count,
            'sort_order' => (int) $folder->sort_order,
        ];
    }

    private function serializeMessageList(MailMessage $m): array
    {
        $attachments = array_map(static function (MailAttachment $a) {
            return [
                'id' => $a->id,
                'filename' => $a->filename,
                'mime_type' => $a->mime_type,
                'size' => $a->size,
                'size_label' => Yii::$app->formatter->asShortSize($a->size),
                'is_pdf' => $a->isPdf(),
                'is_image' => $a->isImage(),
            ];
        }, $m->isRelationPopulated('attachments') ? $m->attachments : []);

        return [
            'id' => $m->id,
            'folder_id' => $m->folder_id,
            'folder_slug' => $m->folder->slug ?? null,
            'from_email' => $m->from_email,
            'from_name' => $m->from_name,
            'from_display' => $m->getFromDisplay(),
            'to_display' => $m->formatRecipients($m->getToList()),
            'subject' => $m->subject,
            'snippet' => $m->snippet,
            'is_read' => (bool) $m->is_read,
            'is_starred' => (bool) $m->is_starred,
            'is_draft' => (bool) $m->is_draft,
            'has_attachments' => (bool) $m->has_attachments,
            'attachments' => $attachments,
            'date_sent' => $m->date_sent,
            'date_label' => $this->formatMessageDateLabel($m->date_sent),
        ];
    }

    private function formatMessageDateLabel(?int $timestamp): string
    {
        if (!$timestamp) {
            return '';
        }
        $tz = Yii::$app->timeZone ?: date_default_timezone_get() ?: 'UTC';
        $dt = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone($tz));
        $now = new \DateTimeImmutable('now', new \DateTimeZone($tz));

        // Today → time only; this year → "Sep 17, 1:14 PM"; older → include year.
        if ($dt->format('Y-m-d') === $now->format('Y-m-d')) {
            return $dt->format('g:i A');
        }
        if ($dt->format('Y') === $now->format('Y')) {
            return $dt->format('M j, g:i A');
        }
        return $dt->format('M j, Y g:i A');
    }

    private function serializeMessageDetail(MailMessage $m): array
    {
        $base = $this->serializeMessageList($m);
        $base['body_html'] = $m->getBodyForDisplay();
        $base['body_text'] = $m->body_text;
        $base['cc_display'] = $m->formatRecipients($m->getCcList());
        $base['message_id_header'] = $m->message_id_header;
        $base['date_full'] = Yii::$app->formatter->asDatetime($m->date_sent);
        $base['attachments'] = array_map(static function (MailAttachment $a) {
            return [
                'id' => $a->id,
                'filename' => $a->filename,
                'mime_type' => $a->mime_type,
                'size' => $a->size,
                'size_label' => Yii::$app->formatter->asShortSize($a->size),
                'is_pdf' => $a->isPdf(),
                'is_image' => $a->isImage(),
            ];
        }, $m->attachments);
        return $base;
    }
}
