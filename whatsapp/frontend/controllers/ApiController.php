<?php

declare(strict_types=1);

namespace frontend\controllers;

use common\models\LoginForm;
use common\models\WaCampaign;
use common\models\WaContact;
use common\models\WaMessage;
use common\models\WaSetting;
use common\services\WhatsAppCloudService;
use Yii;
use yii\filters\AccessControl;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;

class ApiController extends Controller
{
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
                    'logout' => ['post'],
                    'send' => ['post'],
                    'contacts' => ['get', 'post'],
                    'contact' => ['put', 'delete'],
                    'campaigns' => ['get', 'post'],
                    'campaign-send' => ['post'],
                    'settings' => ['get', 'put'],
                    'webhook' => ['get', 'post'],
                ],
            ],
            'access' => [
                'class' => AccessControl::class,
                'except' => ['bootstrap', 'login', 'webhook'],
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
        return parent::beforeAction($action);
    }

    public function actionBootstrap(): array
    {
        $user = Yii::$app->user;
        $payload = [
            'ok' => true,
            'authenticated' => !$user->isGuest,
            'user' => null,
            'settingsConfigured' => false,
            'stats' => [
                'customers' => 0,
                'staff' => 0,
                'sentToday' => 0,
            ],
        ];

        if (!$user->isGuest) {
            $uid = (int) $user->id;
            $payload['user'] = [
                'id' => $uid,
                'username' => $user->identity->username,
                'email' => $user->identity->email,
            ];
            $svc = WhatsAppCloudService::forUser($uid);
            $payload['settingsConfigured'] = $svc->getSetting()->isConfigured();
            $payload['stats'] = [
                'customers' => (int) WaContact::find()->where(['user_id' => $uid, 'type' => WaContact::TYPE_CUSTOMER, 'is_active' => 1])->count(),
                'staff' => (int) WaContact::find()->where(['user_id' => $uid, 'type' => WaContact::TYPE_STAFF, 'is_active' => 1])->count(),
                'sentToday' => (int) WaMessage::find()
                    ->where(['user_id' => $uid, 'direction' => WaMessage::DIR_OUT])
                    ->andWhere(['>=', 'created_at', strtotime('today')])
                    ->count(),
            ];
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
            return $this->actionBootstrap();
        }

        Yii::$app->response->statusCode = 422;
        return [
            'ok' => false,
            'message' => implode(' ', $model->getFirstErrors()) ?: 'Login failed.',
        ];
    }

    public function actionLogout(): array
    {
        Yii::$app->user->logout();
        return ['ok' => true];
    }

    public function actionContacts(): array
    {
        $uid = (int) Yii::$app->user->id;
        if (Yii::$app->request->isPost) {
            $body = $this->body();
            $contact = new WaContact([
                'user_id' => $uid,
                'name' => trim((string) ($body['name'] ?? '')),
                'phone' => WaContact::normalizePhone((string) ($body['phone'] ?? '')),
                'type' => (string) ($body['type'] ?? WaContact::TYPE_CUSTOMER),
                'tags' => trim((string) ($body['tags'] ?? '')),
                'notes' => (string) ($body['notes'] ?? ''),
                'is_active' => true,
            ]);
            if (!$contact->save()) {
                Yii::$app->response->statusCode = 422;
                return ['ok' => false, 'message' => implode(' ', $contact->getFirstErrors())];
            }
            return ['ok' => true, 'contact' => $this->serializeContact($contact)];
        }

        $type = (string) Yii::$app->request->get('type', '');
        $q = WaContact::find()->where(['user_id' => $uid])->orderBy(['name' => SORT_ASC]);
        if (in_array($type, [WaContact::TYPE_CUSTOMER, WaContact::TYPE_STAFF], true)) {
            $q->andWhere(['type' => $type]);
        }
        $search = trim((string) Yii::$app->request->get('q', ''));
        if ($search !== '') {
            $q->andWhere(['or',
                ['like', 'name', $search],
                ['like', 'phone', $search],
                ['like', 'tags', $search],
            ]);
        }

        return [
            'ok' => true,
            'contacts' => array_map([$this, 'serializeContact'], $q->all()),
        ];
    }

    public function actionContact(int $id): array
    {
        $uid = (int) Yii::$app->user->id;
        $contact = WaContact::findOne(['id' => $id, 'user_id' => $uid]);
        if (!$contact) {
            Yii::$app->response->statusCode = 404;
            return ['ok' => false, 'message' => 'Contact not found'];
        }

        if (Yii::$app->request->isDelete) {
            $contact->delete();
            return ['ok' => true];
        }

        $body = $this->body();
        $contact->name = trim((string) ($body['name'] ?? $contact->name));
        if (isset($body['phone'])) {
            $contact->phone = WaContact::normalizePhone((string) $body['phone']);
        }
        if (isset($body['type'])) {
            $contact->type = (string) $body['type'];
        }
        if (isset($body['tags'])) {
            $contact->tags = trim((string) $body['tags']);
        }
        if (array_key_exists('notes', $body)) {
            $contact->notes = (string) $body['notes'];
        }
        if (isset($body['is_active'])) {
            $contact->is_active = (bool) $body['is_active'];
        }
        if (!$contact->save()) {
            Yii::$app->response->statusCode = 422;
            return ['ok' => false, 'message' => implode(' ', $contact->getFirstErrors())];
        }
        return ['ok' => true, 'contact' => $this->serializeContact($contact)];
    }

    public function actionSend(): array
    {
        $uid = (int) Yii::$app->user->id;
        $body = $this->body();
        $text = trim((string) ($body['body'] ?? ''));
        $matter = trim((string) ($body['matter'] ?? 'general')) ?: 'general';
        $contactId = isset($body['contact_id']) ? (int) $body['contact_id'] : null;
        $phone = (string) ($body['phone'] ?? '');

        if ($contactId) {
            $contact = WaContact::findOne(['id' => $contactId, 'user_id' => $uid]);
            if (!$contact) {
                Yii::$app->response->statusCode = 404;
                return ['ok' => false, 'message' => 'Contact not found'];
            }
            $phone = $contact->phone;
        }

        if ($text === '' || $phone === '') {
            Yii::$app->response->statusCode = 422;
            return ['ok' => false, 'message' => 'Phone and message body are required.'];
        }

        $svc = WhatsAppCloudService::forUser($uid);
        $msg = $svc->sendAndLog($uid, $phone, $text, $matter, $contactId);

        return [
            'ok' => $msg->status === WaMessage::STATUS_SENT,
            'message' => $msg->status === WaMessage::STATUS_SENT
                ? 'Message sent.'
                : ($msg->error_message ?: 'Send failed.'),
            'item' => $this->serializeMessage($msg),
        ];
    }

    public function actionMessages(): array
    {
        $uid = (int) Yii::$app->user->id;
        $limit = min(200, max(1, (int) Yii::$app->request->get('limit', 50)));
        $rows = WaMessage::find()
            ->where(['user_id' => $uid])
            ->orderBy(['id' => SORT_DESC])
            ->limit($limit)
            ->all();

        return [
            'ok' => true,
            'messages' => array_map([$this, 'serializeMessage'], $rows),
        ];
    }

    public function actionCampaigns(): array
    {
        $uid = (int) Yii::$app->user->id;
        if (Yii::$app->request->isPost) {
            $body = $this->body();
            $campaign = new WaCampaign([
                'user_id' => $uid,
                'title' => trim((string) ($body['title'] ?? '')),
                'body' => trim((string) ($body['body'] ?? '')),
                'audience' => (string) ($body['audience'] ?? WaCampaign::AUDIENCE_CUSTOMERS),
                'status' => WaCampaign::STATUS_DRAFT,
            ]);
            if (!$campaign->save()) {
                Yii::$app->response->statusCode = 422;
                return ['ok' => false, 'message' => implode(' ', $campaign->getFirstErrors())];
            }
            return ['ok' => true, 'campaign' => $this->serializeCampaign($campaign)];
        }

        $rows = WaCampaign::find()->where(['user_id' => $uid])->orderBy(['id' => SORT_DESC])->limit(50)->all();
        return [
            'ok' => true,
            'campaigns' => array_map([$this, 'serializeCampaign'], $rows),
        ];
    }

    public function actionCampaignSend(int $id): array
    {
        $uid = (int) Yii::$app->user->id;
        $campaign = WaCampaign::findOne(['id' => $id, 'user_id' => $uid]);
        if (!$campaign) {
            Yii::$app->response->statusCode = 404;
            return ['ok' => false, 'message' => 'Campaign not found'];
        }

        $q = WaContact::find()->where(['user_id' => $uid, 'is_active' => 1]);
        if ($campaign->audience === WaCampaign::AUDIENCE_CUSTOMERS) {
            $q->andWhere(['type' => WaContact::TYPE_CUSTOMER]);
        } elseif ($campaign->audience === WaCampaign::AUDIENCE_STAFF) {
            $q->andWhere(['type' => WaContact::TYPE_STAFF]);
        }
        $contacts = $q->all();

        $campaign->status = WaCampaign::STATUS_SENDING;
        $campaign->total = count($contacts);
        $campaign->sent_count = 0;
        $campaign->failed_count = 0;
        $campaign->save(false);

        $svc = WhatsAppCloudService::forUser($uid);
        foreach ($contacts as $contact) {
            $msg = $svc->sendAndLog(
                $uid,
                $contact->phone,
                $campaign->body,
                'campaign',
                (int) $contact->id,
                (int) $campaign->id,
            );
            if ($msg->status === WaMessage::STATUS_SENT) {
                $campaign->sent_count++;
            } else {
                $campaign->failed_count++;
            }
        }

        $campaign->status = WaCampaign::STATUS_DONE;
        $campaign->sent_at = time();
        $campaign->save(false);

        return [
            'ok' => true,
            'message' => sprintf('Sent %d, failed %d.', $campaign->sent_count, $campaign->failed_count),
            'campaign' => $this->serializeCampaign($campaign),
        ];
    }

    public function actionSettings(): array
    {
        $uid = (int) Yii::$app->user->id;
        $svc = WhatsAppCloudService::forUser($uid);
        $setting = $svc->getSetting();

        if (Yii::$app->request->isPut || Yii::$app->request->isPost) {
            $body = $this->body();
            if (isset($body['phone_number_id'])) {
                $setting->phone_number_id = trim((string) $body['phone_number_id']);
            }
            if (isset($body['business_account_id'])) {
                $setting->business_account_id = trim((string) $body['business_account_id']);
            }
            if (array_key_exists('access_token', $body) && trim((string) $body['access_token']) !== '') {
                $setting->access_token = trim((string) $body['access_token']);
            }
            if (isset($body['webhook_verify_token'])) {
                $setting->webhook_verify_token = trim((string) $body['webhook_verify_token']);
            }
            if (isset($body['display_phone'])) {
                $setting->display_phone = trim((string) $body['display_phone']);
            }
            if (isset($body['auto_reply_enabled'])) {
                $setting->auto_reply_enabled = (bool) $body['auto_reply_enabled'];
            }
            if (array_key_exists('auto_reply_text', $body)) {
                $setting->auto_reply_text = (string) $body['auto_reply_text'];
            }
            $setting->save(false);
        }

        return [
            'ok' => true,
            'settings' => $this->serializeSettings($setting),
            'webhookUrl' => Yii::$app->request->hostInfo . Yii::$app->request->baseUrl . '/index.php/api/webhook',
        ];
    }

    /**
     * Meta webhook verification + inbound messages.
     */
    public function actionWebhook()
    {
        $request = Yii::$app->request;

        if ($request->isGet) {
            $mode = (string) $request->get('hub_mode', $request->get('hub.mode', ''));
            $token = (string) $request->get('hub_verify_token', $request->get('hub.verify_token', ''));
            $challenge = (string) $request->get('hub_challenge', $request->get('hub.challenge', ''));

            $setting = WaSetting::find()
                ->where(['webhook_verify_token' => $token])
                ->one();

            if ($mode === 'subscribe' && $setting && $challenge !== '') {
                Yii::$app->response->format = Response::FORMAT_RAW;
                Yii::$app->response->content = $challenge;
                return $challenge;
            }

            Yii::$app->response->statusCode = 403;
            return ['ok' => false, 'message' => 'Verification failed'];
        }

        $payload = $this->body();
        $entries = $payload['entry'] ?? [];
        if (!is_array($entries)) {
            return ['ok' => true];
        }

        foreach ($entries as $entry) {
            $changes = $entry['changes'] ?? [];
            if (!is_array($changes)) {
                continue;
            }
            foreach ($changes as $change) {
                $value = $change['value'] ?? [];
                $messages = $value['messages'] ?? [];
                if (!is_array($messages)) {
                    continue;
                }
                $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');
                $setting = $phoneNumberId !== ''
                    ? WaSetting::findOne(['phone_number_id' => $phoneNumberId])
                    : null;
                if (!$setting) {
                    continue;
                }

                foreach ($messages as $incoming) {
                    $from = WaContact::normalizePhone((string) ($incoming['from'] ?? ''));
                    $text = (string) ($incoming['text']['body'] ?? '');
                    if ($from === '' || $text === '') {
                        continue;
                    }

                    $contact = WaContact::findOne(['user_id' => $setting->user_id, 'phone' => $from]);
                    $msg = new WaMessage([
                        'user_id' => $setting->user_id,
                        'contact_id' => $contact?->id,
                        'direction' => WaMessage::DIR_IN,
                        'phone' => $from,
                        'body' => $text,
                        'status' => WaMessage::STATUS_RECEIVED,
                        'wa_message_id' => (string) ($incoming['id'] ?? ''),
                        'matter' => 'inbound',
                    ]);
                    $msg->save(false);

                    if ($setting->auto_reply_enabled && trim((string) $setting->auto_reply_text) !== '') {
                        $svc = new WhatsAppCloudService($setting);
                        $svc->sendAndLog(
                            (int) $setting->user_id,
                            $from,
                            (string) $setting->auto_reply_text,
                            'auto_reply',
                            $contact?->id,
                        );
                    }
                }
            }
        }

        return ['ok' => true];
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $body = Yii::$app->request->getBodyParams();
        if ($body === []) {
            $decoded = json_decode(Yii::$app->request->rawBody, true);
            $body = is_array($decoded) ? $decoded : [];
        }
        return $body;
    }

    private function serializeContact(WaContact $c): array
    {
        return [
            'id' => (int) $c->id,
            'name' => $c->name,
            'phone' => $c->phone,
            'type' => $c->type,
            'tags' => $c->tags,
            'notes' => $c->notes,
            'is_active' => (bool) $c->is_active,
        ];
    }

    private function serializeMessage(WaMessage $m): array
    {
        return [
            'id' => (int) $m->id,
            'contact_id' => $m->contact_id ? (int) $m->contact_id : null,
            'campaign_id' => $m->campaign_id ? (int) $m->campaign_id : null,
            'direction' => $m->direction,
            'phone' => $m->phone,
            'body' => $m->body,
            'status' => $m->status,
            'matter' => $m->matter,
            'error_message' => $m->error_message,
            'created_at' => (int) $m->created_at,
        ];
    }

    private function serializeCampaign(WaCampaign $c): array
    {
        return [
            'id' => (int) $c->id,
            'title' => $c->title,
            'body' => $c->body,
            'audience' => $c->audience,
            'status' => $c->status,
            'total' => (int) $c->total,
            'sent_count' => (int) $c->sent_count,
            'failed_count' => (int) $c->failed_count,
            'sent_at' => $c->sent_at ? (int) $c->sent_at : null,
            'created_at' => (int) $c->created_at,
        ];
    }

    private function serializeSettings(WaSetting $s): array
    {
        $token = (string) $s->access_token;
        return [
            'phone_number_id' => $s->phone_number_id,
            'business_account_id' => $s->business_account_id,
            'display_phone' => $s->display_phone,
            'webhook_verify_token' => $s->webhook_verify_token,
            'access_token_set' => $token !== '',
            'access_token_masked' => $token !== '' ? (substr($token, 0, 6) . '…' . substr($token, -4)) : '',
            'auto_reply_enabled' => (bool) $s->auto_reply_enabled,
            'auto_reply_text' => (string) $s->auto_reply_text,
            'configured' => $s->isConfigured(),
        ];
    }
}
