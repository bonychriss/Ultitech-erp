<?php
/**
 * System-wide AI integration � encryption, settings, usage, OpenAI proxy.
 * ONE OpenAI key for all companies; data scoped by company_id per request.
 */

if (!function_exists('ai_pdo')) {
    /** Control DB preferred � ai_settings is system-wide. */
    function ai_pdo(): ?PDO
    {
        global $control_pdo, $pdo;
        if ($control_pdo instanceof PDO) {
            return $control_pdo;
        }
        return ($pdo instanceof PDO) ? $pdo : null;
    }
}

if (!function_exists('requireSuperAdmin')) {
    function requireSuperAdmin(): void
    {
        requireLogin();
        if (!function_exists('isSuperAdmin') || !isSuperAdmin()) {
            if (!headers_sent()) {
                http_response_code(403);
            }
            if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
                || strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false
                || !empty($_GET['json']) || !empty($_POST['json'])) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => 'Super Admin access required']);
                exit;
            }
            die('Access denied. Super Admin only.');
        }
    }
}

if (!function_exists('ai_encryption_material')) {
    function ai_encryption_material(): string
    {
        $secret = '';
        if (defined('AI_ENCRYPTION_KEY') && AI_ENCRYPTION_KEY !== '') {
            $secret = (string) AI_ENCRYPTION_KEY;
        }
        if ($secret === '') {
            throw new RuntimeException('AI_ENCRYPTION_KEY is not configured in env.php');
        }
        return hash('sha256', $secret, true);
    }
}

if (!function_exists('ai_encrypt')) {
    function ai_encrypt(string $plaintext): string
    {
        $key = ai_encryption_material();
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Failed to encrypt API key');
        }
        return base64_encode($iv . $tag . $cipher);
    }
}

if (!function_exists('ai_decrypt')) {
    function ai_decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new RuntimeException('Invalid encrypted key format');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $key = ai_encryption_material();
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('Failed to decrypt API key � check AI_ENCRYPTION_KEY');
        }
        return $plain;
    }
}

if (!function_exists('ai_mask_api_key')) {
    function ai_mask_api_key(string $apiKey): string
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            return '';
        }
        $len = strlen($apiKey);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }
        $prefix = substr($apiKey, 0, 3);
        $suffix = substr($apiKey, -4);
        return $prefix . str_repeat('*', max(4, $len - 7)) . $suffix;
    }
}

if (!function_exists('ensureAiSchema')) {
    function ensureAiSchema(?PDO $explicit = null): bool
    {
        $pdo = $explicit ?? ai_pdo();
        if (!$pdo instanceof PDO) {
            return false;
        }
        static $done = false;
        if ($done) {
            return true;
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS ai_settings (
              id INT AUTO_INCREMENT PRIMARY KEY,
              provider VARCHAR(50) NOT NULL DEFAULT 'openai',
              api_key_encrypted TEXT NOT NULL,
              model_name VARCHAR(100) NOT NULL DEFAULT 'gpt-4o-mini',
              is_enabled TINYINT(1) NOT NULL DEFAULT 1,
              daily_limit INT NOT NULL DEFAULT 500,
              created_by INT NULL,
              updated_by INT NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS ai_usage_logs (
              id INT AUTO_INCREMENT PRIMARY KEY,
              company_id INT NOT NULL,
              user_id INT NOT NULL,
              module_name VARCHAR(100) NULL,
              request_type VARCHAR(100) NULL,
              prompt_tokens INT NOT NULL DEFAULT 0,
              completion_tokens INT NOT NULL DEFAULT 0,
              total_tokens INT NOT NULL DEFAULT 0,
              estimated_cost DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY idx_ai_usage_company_day (company_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS ai_logs (
              id INT AUTO_INCREMENT PRIMARY KEY,
              company_id INT NOT NULL,
              user_id INT NOT NULL,
              module_name VARCHAR(100) NULL,
              question TEXT NOT NULL,
              response TEXT NOT NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY idx_ai_logs_company (company_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            $done = true;
            return true;
        } catch (Throwable $e) {
            error_log('ensureAiSchema: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('ai_fetch_settings_row')) {
    function ai_fetch_settings_row(?PDO $pdo = null): ?array
    {
        $pdo = $pdo ?? ai_pdo();
        if (!$pdo || !ensureAiSchema($pdo)) {
            return null;
        }
        $st = $pdo->query('SELECT * FROM ai_settings ORDER BY id ASC LIMIT 1');
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        return $row ?: null;
    }
}

if (!function_exists('ai_settings_for_api')) {
    /** Public-safe settings (masked key only). */
    function ai_settings_for_api(): array
    {
        $row = ai_fetch_settings_row();
        if (!$row) {
            return [
                'configured' => false,
                'provider' => 'openai',
                'api_key_masked' => '',
                'model_name' => 'gpt-4o-mini',
                'is_enabled' => false,
                'daily_limit' => 500,
            ];
        }
        $masked = '';
        try {
            $plain = ai_decrypt((string) $row['api_key_encrypted']);
            $masked = ai_mask_api_key($plain);
        } catch (Throwable $e) {
            $masked = '(encrypted � re-enter key)';
        }
        return [
            'configured' => true,
            'provider' => $row['provider'] ?? 'openai',
            'api_key_masked' => $masked,
            'model_name' => $row['model_name'] ?? 'gpt-4o-mini',
            'is_enabled' => (bool) ($row['is_enabled'] ?? 0),
            'daily_limit' => (int) ($row['daily_limit'] ?? 500),
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}

if (!function_exists('ai_save_settings')) {
    function ai_save_settings(array $data, int $userId): bool
    {
        $pdo = ai_pdo();
        if (!$pdo || !ensureAiSchema($pdo)) {
            return false;
        }
        $row = ai_fetch_settings_row($pdo);
        $model = trim((string) ($data['model_name'] ?? 'gpt-4o-mini'));
        $enabled = !empty($data['is_enabled']) ? 1 : 0;
        $dailyLimit = max(1, (int) ($data['daily_limit'] ?? 500));
        $newKey = trim((string) ($data['api_key'] ?? ''));

        if ($row) {
            $encrypted = $row['api_key_encrypted'];
            if ($newKey !== '') {
                $encrypted = ai_encrypt($newKey);
            }
            $st = $pdo->prepare(
                'UPDATE ai_settings SET provider = ?, api_key_encrypted = ?, model_name = ?,
                 is_enabled = ?, daily_limit = ?, updated_by = ? WHERE id = ?'
            );
            return $st->execute(['openai', $encrypted, $model, $enabled, $dailyLimit, $userId, (int) $row['id']]);
        }

        if ($newKey === '') {
            throw new InvalidArgumentException('API key is required for initial setup');
        }
        $st = $pdo->prepare(
            'INSERT INTO ai_settings (provider, api_key_encrypted, model_name, is_enabled, daily_limit, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        return $st->execute(['openai', ai_encrypt($newKey), $model, $enabled, $dailyLimit, $userId, $userId]);
    }
}

if (!function_exists('ai_get_decrypted_api_key')) {
    function ai_get_decrypted_api_key(): string
    {
        $row = ai_fetch_settings_row();
        if (!$row || empty($row['api_key_encrypted'])) {
            throw new RuntimeException('OpenAI API key is not configured');
        }
        return ai_decrypt((string) $row['api_key_encrypted']);
    }
}

if (!function_exists('ai_company_usage_today')) {
    function ai_company_usage_today(int $companyId): int
    {
        $pdo = ai_pdo();
        if (!$pdo || $companyId <= 0) {
            return 0;
        }
        ensureAiSchema($pdo);
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM ai_usage_logs WHERE company_id = ? AND DATE(created_at) = CURDATE()'
        );
        $st->execute([$companyId]);
        return (int) $st->fetchColumn();
    }
}

if (!function_exists('ai_check_company_limit')) {
    function ai_check_company_limit(int $companyId): array
    {
        $settings = ai_fetch_settings_row();
        $limit = (int) ($settings['daily_limit'] ?? 500);
        $used = ai_company_usage_today($companyId);
        return [
            'allowed' => $used < $limit,
            'used' => $used,
            'limit' => $limit,
            'remaining' => max(0, $limit - $used),
        ];
    }
}

if (!function_exists('ai_estimate_cost')) {
    function ai_estimate_cost(int $promptTokens, int $completionTokens, string $model): float
    {
        $model = strtolower($model);
        if (strpos($model, 'gpt-4o-mini') !== false) {
            return round(($promptTokens * 0.15 + $completionTokens * 0.60) / 1000000, 4);
        }
        return round(($promptTokens + $completionTokens) * 0.002 / 1000, 4);
    }
}

if (!function_exists('ai_log_usage')) {
    function ai_log_usage(int $companyId, int $userId, string $module, string $type, int $prompt, int $completion, float $cost): void
    {
        $pdo = ai_pdo();
        if (!$pdo) {
            return;
        }
        ensureAiSchema($pdo);
        $st = $pdo->prepare(
            'INSERT INTO ai_usage_logs (company_id, user_id, module_name, request_type, prompt_tokens, completion_tokens, total_tokens, estimated_cost)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$companyId, $userId, $module, $type, $prompt, $completion, $prompt + $completion, $cost]);
    }
}

if (!function_exists('ai_log_chat')) {
    function ai_log_chat(int $companyId, int $userId, string $module, string $question, string $response): void
    {
        $pdo = ai_pdo();
        if (!$pdo) {
            return;
        }
        ensureAiSchema($pdo);
        $st = $pdo->prepare(
            'INSERT INTO ai_logs (company_id, user_id, module_name, question, response) VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([$companyId, $userId, $module, $question, $response]);
    }
}

if (!function_exists('ai_openai_request')) {
    function ai_openai_request(array $messages, ?string $model = null): array
    {
        $settings = ai_fetch_settings_row();
        if (!$settings || !(int) ($settings['is_enabled'] ?? 0)) {
            throw new RuntimeException('AI assistant is disabled by the system administrator');
        }
        $apiKey = ai_get_decrypted_api_key();
        $model = $model ?: ($settings['model_name'] ?? 'gpt-4o-mini');

        $payload = json_encode([
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.4,
            'max_tokens' => 800,
        ]);

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('OpenAI request failed: ' . $err);
        }
        $json = json_decode($body, true);
        if ($httpCode >= 400 || !is_array($json)) {
            $msg = is_array($json) ? ($json['error']['message'] ?? $body) : $body;
            throw new RuntimeException('OpenAI error (' . $httpCode . '): ' . $msg);
        }

        $choice = $json['choices'][0]['message']['content'] ?? '';
        $usage = $json['usage'] ?? [];
        return [
            'content' => trim((string) $choice),
            'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            'model' => $model,
        ];
    }
}

if (!function_exists('ai_test_connection')) {
    function ai_test_connection(): array
    {
        $result = ai_openai_request([
            ['role' => 'user', 'content' => 'Reply with exactly: OK'],
        ]);
        return [
            'success' => stripos($result['content'], 'ok') !== false,
            'message' => $result['content'],
            'tokens' => $result['prompt_tokens'] + $result['completion_tokens'],
        ];
    }
}

if (!function_exists('ai_build_context_summary')) {
    /**
     * Summarized company-scoped context � never full table dumps.
     */
    function ai_build_context_summary(int $userId, int $companyId, string $role): string
    {
        global $pdo;
        $lines = [];
        $lines[] = 'User role: ' . $role;
        $lines[] = 'Company ID: ' . $companyId;

        $isSuper = function_exists('isSuperAdmin') && isSuperAdmin();
        $isAdmin = function_exists('isAdmin') && isAdmin();

        if ($pdo instanceof PDO) {
            if (tableExists('weekly_missions', $pdo)) {
                try {
                    $sql = 'SELECT COUNT(*) AS total,
                            SUM(CASE WHEN status = \'Completed\' OR completed_at IS NOT NULL THEN 1 ELSE 0 END) AS done
                            FROM weekly_missions WHERE user_id = ?';
                    $params = [$userId];
                    if (!$isAdmin && !$isSuper) {
                        /* employee � own missions only (already filtered) */
                    }
                    $st = $pdo->prepare($sql);
                    $st->execute($params);
                    $wm = $st->fetch(PDO::FETCH_ASSOC);
                    if ($wm) {
                        $lines[] = 'Weekly missions (you): ' . (int) $wm['done'] . ' completed of ' . (int) $wm['total'];
                    }
                } catch (Throwable $e) {
                }
            }

            if (($isAdmin || $isSuper) && tableExists('payment_vouchers', $pdo)) {
                try {
                    $st = $pdo->query(
                        "SELECT COUNT(*) AS c, SUM(CASE WHEN LOWER(status) = 'pending' THEN 1 ELSE 0 END) AS pending
                         FROM payment_vouchers"
                    );
                    $v = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
                    if ($v) {
                        $lines[] = 'Payment vouchers (company): ' . (int) $v['c'] . ' total, ' . (int) $v['pending'] . ' pending';
                    }
                } catch (Throwable $e) {
                }
            } elseif (tableExists('payment_vouchers', $pdo)) {
                try {
                    $st = $pdo->prepare(
                        "SELECT COUNT(*) AS c FROM payment_vouchers WHERE created_by = ?"
                    );
                    $st->execute([$userId]);
                    $lines[] = 'Your payment vouchers: ' . (int) $st->fetchColumn();
                } catch (Throwable $e) {
                }
            }
        }

        if ($isSuper) {
            $meta = ai_pdo();
            if ($meta && tableExists('ai_usage_logs', $meta)) {
                try {
                    $st = $meta->query('SELECT COUNT(*) FROM ai_usage_logs WHERE DATE(created_at) = CURDATE()');
                    $lines[] = 'System AI requests today (all companies): ' . (int) $st->fetchColumn();
                } catch (Throwable $e) {
                }
            }
        }

        return implode("\n", $lines);
    }
}

if (!function_exists('ai_handle_ask')) {
    function ai_handle_ask(int $userId, int $companyId, string $question, string $module = 'general'): array
    {
        $question = trim($question);
        if ($question === '') {
            throw new InvalidArgumentException('Question is required');
        }
        if (strlen($question) > 2000) {
            throw new InvalidArgumentException('Question is too long (max 2000 characters)');
        }
        if ($companyId <= 0) {
            throw new RuntimeException('Company context is required');
        }

        $limit = ai_check_company_limit($companyId);
        if (!$limit['allowed']) {
            throw new RuntimeException(
                'Your company has reached the daily AI limit (' . $limit['limit'] . ' requests). Please try again tomorrow.'
            );
        }

        $role = strtolower(trim((string) ($_SESSION['role'] ?? 'employee')));
        $context = ai_build_context_summary($userId, $companyId, $role);

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a helpful ERP assistant for Ultimate ERP. Answer briefly, directly, and professionally (max 2-3 sentences or 50 words). '
                    . 'Use only the summarized context provided. Do not invent database records. '
                    . 'If data is insufficient, say so.',
            ],
            [
                'role' => 'user',
                'content' => "Context (summarized, company-scoped):\n" . $context . "\n\nQuestion: " . $question,
            ],
        ];

        $result = ai_openai_request($messages);
        $cost = ai_estimate_cost($result['prompt_tokens'], $result['completion_tokens'], $result['model']);

        ai_log_usage($companyId, $userId, $module, 'ask', $result['prompt_tokens'], $result['completion_tokens'], $cost);
        ai_log_chat($companyId, $userId, $module, $question, $result['content']);

        return [
            'answer' => $result['content'],
            'usage' => [
                'prompt_tokens' => $result['prompt_tokens'],
                'completion_tokens' => $result['completion_tokens'],
                'remaining_today' => max(0, $limit['remaining'] - 1),
            ],
        ];
    }
}

if (!function_exists('ai_usage_report_by_company')) {
    function ai_usage_report_by_company(int $days = 30): array
    {
        $pdo = ai_pdo();
        if (!$pdo) {
            return [];
        }
        ensureAiSchema($pdo);
        $st = $pdo->prepare(
            'SELECT company_id, COUNT(*) AS requests, SUM(total_tokens) AS tokens, SUM(estimated_cost) AS cost
             FROM ai_usage_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY company_id ORDER BY requests DESC'
        );
        $st->execute([$days]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
