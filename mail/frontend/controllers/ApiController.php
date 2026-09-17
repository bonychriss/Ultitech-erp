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
                    'send' => ['post'],
                    'draft' => ['post'],
                    'star' => ['post'],
                    'trash' => ['post'],
                    'sync' => ['post'],
                    'accounts' => ['get', 'post'],
                    'account' => ['get', 'put', 'delete'],
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
        ];

        if (!$user->isGuest) {
            $payload['user'] = [
                'id' => $user->id,
                'username' => $user->identity->username,
                'email' => $user->identity->email,
            ];
            $account = $this->findAccount(false);
            if ($account) {
                $payload['account'] = $this->serializeAccount($account);
                $payload['folders'] = array_map([$this, 'serializeFolder'], $account->folders);
            } else {
                $payload['folders'] = [];
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
        ];
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

        $fields = [
            'email',
            'display_name',
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
                $model->$field = $body[$field];
            }
        }

        $imapPass = trim((string) ($body['imap_password'] ?? $body['imap_password_plain'] ?? ''));
        $smtpPass = trim((string) ($body['smtp_password'] ?? $body['smtp_password_plain'] ?? ''));
        $model->imap_password_plain = $imapPass;
        $model->smtp_password_plain = $smtpPass;
        $model->user_id = (int) Yii::$app->user->id;

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

        return [
            'ok' => true,
            'message' => $isNew ? 'Mailbox connected.' : 'Account updated.',
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
        $account = MailAccount::find()
            ->where(['user_id' => Yii::$app->user->id, 'is_active' => 1])
            ->orderBy(['id' => SORT_ASC])
            ->one();
        if (!$account && $required) {
            Yii::$app->response->statusCode = 404;
            Yii::$app->response->data = ['ok' => false, 'message' => 'No mail account connected.'];
            Yii::$app->end();
        }
        return $account;
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
            'last_synced_at' => $account->last_synced_at,
        ];
    }

    private function serializeAccountDetail(MailAccount $account): array
    {
        return [
            'id' => $account->id,
            'email' => $account->email,
            'display_name' => $account->display_name,
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
            'date_label' => Yii::$app->formatter->asDatetime($m->date_sent, 'php:M j'),
        ];
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
