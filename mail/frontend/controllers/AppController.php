<?php

declare(strict_types=1);

namespace frontend\controllers;

use common\services\MailSsoService;
use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Serves the React SPA from frontend/web/app.
 * Rewrites asset URLs + injects API base so cPanel paths work without Vite rebuild guesses.
 * Also consumes Ultitech ERP SSO tokens (?sso=...) for seamless login.
 */
class AppController extends Controller
{
    public $layout = false;

    public function actionIndex(): string|Response
    {
        $sso = trim((string) Yii::$app->request->get('sso', ''));
        if ($sso !== '') {
            $payload = MailSsoService::verify($sso);
            if ($payload !== null) {
                $user = MailSsoService::findOrCreateUser($payload);
                if ($user !== null) {
                    Yii::$app->user->login($user, 3600 * 24 * 30);
                }
            }
            // Always strip token from URL (success or fail → login screen if needed)
            $base = rtrim(Yii::$app->request->getBaseUrl(), '/');
            $script = Yii::$app->request->getScriptUrl();
            $showScript = (bool) (Yii::$app->urlManager->showScriptName ?? false);
            if ($showScript) {
                return $this->redirect($script . '/app');
            }
            return $this->redirect($base . '/app');
        }

        $index = Yii::getAlias('@frontend/web/app/index.html');
        if (!is_file($index)) {
            throw new NotFoundHttpException(
                'React app is not built yet. Run: cd frontend/react && npm run build',
            );
        }

        $html = (string) file_get_contents($index);
        $base = rtrim(Yii::$app->request->getBaseUrl(), '/');
        $appBase = $base . '/app';
        $apiBase = $base . '/index.php';
        $assetsDir = Yii::getAlias('@frontend/web/app/assets');

        $js = $this->findAsset($assetsDir, 'js');
        $css = $this->findAsset($assetsDir, 'css');
        $bust = (string) time();

        if ($js) {
            $html = preg_replace(
                '#<script type="module"[^>]*src="[^"]+"></script>#',
                '<script type="module" crossorigin src="' . htmlspecialchars($appBase . '/assets/' . $js, ENT_QUOTES) . '?v=' . $bust . '"></script>',
                $html,
                1,
            ) ?? $html;
        } else {
            $html = preg_replace(
                '#(src|href)="[^"]*?/app/(assets/[^"]+)"#',
                '$1="' . $appBase . '/$2"',
                $html,
            ) ?? $html;
        }

        if ($css) {
            $html = preg_replace(
                '#<link rel="stylesheet"[^>]*href="[^"]*"\s*/?>#',
                '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($appBase . '/assets/' . $css, ENT_QUOTES) . '?v=' . $bust . '">',
                $html,
                1,
            ) ?? $html;
        }

        $inject = '<script>window.__MAIL_API_BASE__=' . json_encode($apiBase, JSON_UNESCAPED_SLASHES)
            . ';window.__MAIL_WEB_BASE__=' . json_encode($base, JSON_UNESCAPED_SLASHES)
            . ';</script>';
        if (str_contains($html, '<head>')) {
            $html = preg_replace('#<head>#', '<head>' . $inject, $html, 1) ?? ($inject . $html);
        } else {
            $html = $inject . $html;
        }

        Yii::$app->response->format = Response::FORMAT_RAW;
        Yii::$app->response->headers->set('Content-Type', 'text/html; charset=UTF-8');
        Yii::$app->response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        return $html;
    }

    private function findAsset(string $dir, string $ext): ?string
    {
        if (!is_dir($dir)) {
            return null;
        }
        $matches = glob($dir . '/index-*.' . $ext) ?: [];
        if (!$matches) {
            return null;
        }
        usort($matches, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        return basename($matches[0]);
    }
}
