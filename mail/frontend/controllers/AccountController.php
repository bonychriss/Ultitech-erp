<?php

declare(strict_types=1);

namespace frontend\controllers;

use common\models\MailAccount;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class AccountController extends Controller
{
    public $layout = 'mail';

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        // Account settings live in the React app now.
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
                    'delete' => ['post'],
                ],
            ],
        ];
    }

    public function actionIndex(): string
    {
        $accounts = MailAccount::find()
            ->where(['user_id' => Yii::$app->user->id])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        return $this->render('index', [
            'accounts' => $accounts,
            'folders' => [],
            'folder' => 'settings',
            'account' => $accounts[0] ?? null,
        ]);
    }

    public function actionCreate(): string|Response
    {
        $model = new MailAccount([
            'user_id' => Yii::$app->user->id,
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
        ]);

        if ($model->load(Yii::$app->request->post())) {
            $model->user_id = Yii::$app->user->id;
            if ($model->imap_password_plain === '' || $model->smtp_password_plain === '') {
                $model->addError('imap_password_plain', 'IMAP and SMTP passwords are required.');
            } elseif ($model->save()) {
                Yii::$app->session->setFlash('success', 'Mailbox connected.');
                return $this->redirect(['mail/index']);
            }
        }

        return $this->render('form', [
            'model' => $model,
            'folders' => [],
            'folder' => 'settings',
            'account' => null,
            'title' => 'Add email account',
        ]);
    }

    public function actionUpdate(int $id): string|Response
    {
        $model = $this->findModel($id);
        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->session->setFlash('success', 'Account updated.');
            return $this->redirect(['index']);
        }

        return $this->render('form', [
            'model' => $model,
            'folders' => $model->folders,
            'folder' => 'settings',
            'account' => $model,
            'title' => 'Edit email account',
        ]);
    }

    public function actionDelete(int $id): Response
    {
        $this->findModel($id)->delete();
        Yii::$app->session->setFlash('success', 'Account removed.');
        return $this->redirect(['index']);
    }

    private function findModel(int $id): MailAccount
    {
        $model = MailAccount::findOne(['id' => $id, 'user_id' => Yii::$app->user->id]);
        if (!$model) {
            throw new NotFoundHttpException('Account not found.');
        }
        return $model;
    }
}
