<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Demo admin for local WhatsApp console.
 */
class m260915_000002_seed_demo_user extends Migration
{
    public function safeUp(): void
    {
        $now = time();
        $this->insert('{{%user}}', [
            'username' => 'admin',
            'auth_key' => \Yii::$app->security->generateRandomString(),
            'password_hash' => \Yii::$app->security->generatePasswordHash('admin123'),
            'email' => 'admin@whatsapp.local',
            'status' => 10,
            'created_at' => $now,
            'updated_at' => $now,
            'verification_token' => null,
        ]);
    }

    public function safeDown(): void
    {
        $this->delete('{{%user}}', ['username' => 'admin']);
    }
}
