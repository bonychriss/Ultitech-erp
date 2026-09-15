<?php

declare(strict_types=1);

namespace frontend\controllers;

use common\models\MailAccount;
use common\models\MailAttachment;
use common\models\MailMessage;
use common\services\AttachmentStorageService;
use common\services\ImapSyncService;
use common\services\SmtpSendService;
use frontend\models\ComposeForm;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UploadedFile;

class MailController extends Controller
{
    public $layout = 'mail';

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        // Legacy PHP mail UI retired — React SPA is the product surface.
        $this->redirect(['/app/index']);
        return false;
    }

    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'star' => ['post'],
                    'trash' => ['post'],
                    'sync' => ['post'],
                    'send' => ['post'],
                    'save-draft' => ['post'],
                ],
            ],
        ];
    }

    public function actionIndex(string $folder = 'inbox', ?string $q = null): string
    {
        $account = $this->requireAccount();
        $query = MailMessage::find()->where(['account_id' => $account->id]);

        if ($folder === 'starred') {
            $query->andWhere(['is_starred' => 1])->andWhere(['not', ['is_draft' => 1]]);
        } else {
            $folderModel = $account->getFolderBySlug($folder);
            if (!$folderModel) {
                throw new NotFoundHttpException('Folder not found.');
            }
            $query->andWhere(['folder_id' => $folderModel->id]);
        }

        if ($q !== null && trim($q) !== '') {
            $term = trim($q);
            $query->andWhere([
                'or',
                ['like', 'subject', $term],
                ['like', 'from_name', $term],
                ['like', 'from_email', $term],
                ['like', 'snippet', $term],
                ['like', 'body_text', $term],
            ]);
        }

        $dataProvider = new ActiveDataProvider([
            'query' => $query->with('attachments')->orderBy(['date_sent' => SORT_DESC, 'id' => SORT_DESC]),
            'pagination' => ['pageSize' => 50],
        ]);

        $flashCompose = Yii::$app->session->getFlash('composePopup');
        if (is_array($flashCompose) && empty($this->view->params['composeOpen'])) {
            $composeModel = new ComposeForm();
            $composeModel->setAttributes($flashCompose);
            $this->view->params['composeModel'] = $composeModel;
            $this->view->params['composeOpen'] = true;
            $this->view->params['composeTitle'] = 'New Message';
        }

        return $this->render('index', [
            'account' => $account,
            'folder' => $folder,
            'q' => $q,
            'dataProvider' => $dataProvider,
            'folders' => $account->folders,
        ]);
    }

    public function actionView(int $id): string
    {
        $account = $this->requireAccount();
        $message = $this->findMessage($account->id, $id);
        $message->markRead(true);

        return $this->render('view', [
            'account' => $account,
            'message' => $message,
            'folders' => $account->folders,
            'folder' => $message->folder->slug ?? 'inbox',
        ]);
    }

    public function actionCompose(?int $reply = null, ?int $forward = null, ?int $draft = null): string|Response
    {
        $account = $this->requireAccount();
        $form = new ComposeForm();
        $form->setScenario('send');
        $title = 'New Message';
        $attachments = [];
        $returnFolder = 'inbox';

        if ($draft) {
            $message = $this->findMessage($account->id, $draft);
            $form->draft_id = $message->id;
            $form->to = $message->formatRecipients($message->getToList());
            $form->cc = $message->formatRecipients($message->getCcList());
            $form->subject = $message->subject;
            $form->body = (string) $message->body_text;
            $attachments = $message->attachments;
            $title = 'Edit draft';
            $returnFolder = 'drafts';
        } elseif ($reply) {
            $message = $this->findMessage($account->id, $reply);
            $form->to = $message->from_name
                ? $message->from_name . ' <' . $message->from_email . '>'
                : $message->from_email;
            $form->subject = $this->prefixSubject($message->subject, 'Re:');
            $form->in_reply_to = $message->message_id_header;
            $form->body = $this->quoteBody($message);
            $title = 'Reply';
        } elseif ($forward) {
            $message = $this->findMessage($account->id, $forward);
            $form->subject = $this->prefixSubject($message->subject, 'Fwd:');
            $form->body = "\n\n---------- Forwarded message ----------\n"
                . 'From: ' . $message->getFromDisplay() . ' <' . $message->from_email . ">\n"
                . 'Date: ' . Yii::$app->formatter->asDatetime($message->date_sent) . "\n"
                . 'Subject: ' . $message->subject . "\n\n"
                . (string) $message->body_text;
            $title = 'Forward';
        }

        // Reuse inbox UI and open the compose popup on top.
        $this->view->params['composeModel'] = $form;
        $this->view->params['composeOpen'] = true;
        $this->view->params['composeTitle'] = $title;
        $this->view->params['composeAttachments'] = $attachments;

        return $this->actionIndex($returnFolder);
    }

    public function actionSend(): Response
    {
        $account = $this->requireAccount();
        $form = new ComposeForm();
        $form->setScenario('send');
        $form->load(Yii::$app->request->post());
        $form->attachments = UploadedFile::getInstances($form, 'attachments');

        if ($form->validate()) {
            $result = (new SmtpSendService())->send($account, $form->attributes, $form->attachments ?: []);
            if ($result['ok']) {
                Yii::$app->session->setFlash('success', $result['message']);
                return $this->redirect(['index', 'folder' => 'sent']);
            }
            Yii::$app->session->setFlash('error', $result['message']);
        } else {
            $errors = $form->getFirstErrors();
            Yii::$app->session->setFlash('error', $errors ? reset($errors) : 'Please fill To and Subject.');
            Yii::$app->session->setFlash('composePopup', $form->attributes);
        }
        return $this->redirect(['index']);
    }

    public function actionSaveDraft(): Response
    {
        $account = $this->requireAccount();
        $form = new ComposeForm();
        $form->load(Yii::$app->request->post());
        $form->attachments = UploadedFile::getInstances($form, 'attachments');
        $draft = (new SmtpSendService())->saveDraft(
            $account,
            $form->attributes,
            $form->draft_id,
            $form->attachments ?: [],
        );
        Yii::$app->session->setFlash('success', 'Draft saved.');
        return $this->redirect(['compose', 'draft' => $draft->id]);
    }

    public function actionAttachment(int $id, string $mode = 'download'): Response
    {
        $account = $this->requireAccount();
        $attachment = $this->findOwnedAttachment($account->id, $id);
        $path = (new AttachmentStorageService())->absolutePath($attachment);
        if ($path === null) {
            throw new NotFoundHttpException('Attachment file is missing.');
        }

        $inline = $mode === 'view' && ($attachment->isPdf() || $attachment->isImage());
        return Yii::$app->response->sendFile(
            $path,
            $attachment->filename,
            [
                'mimeType' => $attachment->mime_type ?: 'application/octet-stream',
                'inline' => $inline,
            ],
        );
    }

    public function actionStar(int $id): Response
    {
        $account = $this->requireAccount();
        $message = $this->findMessage($account->id, $id);
        $message->toggleStar();
        return $this->redirect(Yii::$app->request->referrer ?: ['index']);
    }

    public function actionTrash(int $id): Response
    {
        $account = $this->requireAccount();
        $message = $this->findMessage($account->id, $id);
        $trash = $account->getFolderBySlug('trash');
        if ($trash && (int) $message->folder_id === (int) $trash->id) {
            foreach ($message->attachments as $file) {
                if ($file->storage_path && is_file($file->storage_path)) {
                    @unlink($file->storage_path);
                }
            }
            $message->delete();
            Yii::$app->session->setFlash('success', 'Message deleted forever.');
        } elseif ($trash) {
            $oldFolder = $message->folder;
            $message->folder_id = $trash->id;
            $message->save(false, ['folder_id', 'updated_at']);
            $oldFolder?->refreshUnreadCount();
            $trash->refreshUnreadCount();
            Yii::$app->session->setFlash('success', 'Moved to Trash.');
        }
        return $this->redirect(['index', 'folder' => 'inbox']);
    }

    public function actionSync(): Response
    {
        $account = $this->requireAccount();
        $result = (new ImapSyncService())->sync($account);
        Yii::$app->session->setFlash($result['ok'] ? 'success' : 'warning', $result['message']);
        return $this->redirect(['index']);
    }

    private function requireAccount(): MailAccount
    {
        $account = MailAccount::find()
            ->where(['user_id' => Yii::$app->user->id, 'is_active' => 1])
            ->orderBy(['id' => SORT_ASC])
            ->one();
        if (!$account) {
            Yii::$app->session->setFlash('info', 'Connect an email account to get started.');
            $this->redirect(['account/create'])->send();
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

    private function findOwnedAttachment(int $accountId, int $attachmentId): MailAttachment
    {
        $attachment = MailAttachment::find()
            ->alias('a')
            ->innerJoin(['m' => MailMessage::tableName()], 'm.id = a.message_id')
            ->where(['a.id' => $attachmentId, 'm.account_id' => $accountId])
            ->one();
        if (!$attachment) {
            throw new NotFoundHttpException('Attachment not found.');
        }
        return $attachment;
    }

    private function prefixSubject(string $subject, string $prefix): string
    {
        if (stripos($subject, $prefix) === 0) {
            return $subject;
        }
        return $prefix . ' ' . $subject;
    }

    private function quoteBody(MailMessage $message): string
    {
        $when = Yii::$app->formatter->asDatetime($message->date_sent);
        $who = $message->getFromDisplay();
        $quoted = preg_replace('/^/m', '> ', (string) $message->body_text) ?? '';
        return "\n\nOn {$when}, {$who} wrote:\n{$quoted}";
    }
}
