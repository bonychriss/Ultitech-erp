<?php

declare(strict_types=1);

namespace frontend\controllers;

use Yii;
use yii\web\Controller;

class SiteController extends Controller
{
    public function actions(): array
    {
        return [
            'error' => [
                'class' => \yii\web\ErrorAction::class,
            ],
        ];
    }

    public function actionIndex()
    {
        return $this->redirect(['/app/index']);
    }
}
