<?php
/**
 * Talks to the local ACE agent and checks the company token ACE sends back.
 * The browser never receives the token.
 */
declare(strict_types=1);

function aiAgentAceTokenDir(): string
{
    $dir = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'ultitech-ai-agent-tokens';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    return $dir;
}

function aiAgentAceEndpointUrl(): string
{
    $path = function_exists('company_url')
        ? company_url('modules/ai-agent/ace.php')
        : '/modules/ai-agent/ace.php';
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }
    return 'http://127.0.0.1' . $path;
}

function aiAgentAceMintToken(): string
{
    $ctx = aiAgentContext();
    $token = bin2hex(random_bytes(32));
    $record = [
        'user_id' => (int) $ctx['user_id'],
        'company_id' => (int) $ctx['company_id'],
        'company_slug' => strtolower(trim((string) ($_SESSION['company_slug'] ?? ''))),
        'company_name' => (string) $ctx['company_name'],
        'role' => (string) ($_SESSION['role'] ?? ''),
        'department' => (string) ($_SESSION['department'] ?? ''),
        'full_name' => (string) ($_SESSION['full_name'] ?? ''),
        'exp' => time() + 900,
    ];
    $file = aiAgentAceTokenDir() . DIRECTORY_SEPARATOR . hash('sha256', $token) . '.json';
    file_put_contents($file, json_encode($record), LOCK_EX);
    $_SESSION['ai_agent_ace_token'] = $token;
    $_SESSION['ai_agent_ace_token_exp'] = $record['exp'];
    return $token;
}

function aiAgentAceToken(): string
{
    $existing = (string) ($_SESSION['ai_agent_ace_token'] ?? '');
    $exp = (int) ($_SESSION['ai_agent_ace_token_exp'] ?? 0);
    if ($existing !== '' && $exp > time() + 30 && is_file(aiAgentAceTokenDir() . DIRECTORY_SEPARATOR . hash('sha256', $existing) . '.json')) {
        return $existing;
    }
    return aiAgentAceMintToken();
}

/**
 * @return array<string,mixed>|null
 */
function aiAgentAceReadToken(string $token): ?array
{
    $token = trim($token);
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $file = aiAgentAceTokenDir() . DIRECTORY_SEPARATOR . hash('sha256', $token) . '.json';
    if (!is_file($file)) {
        return null;
    }
    $record = json_decode((string) file_get_contents($file), true);
    if (!is_array($record) || (int) ($record['exp'] ?? 0) < time()) {
        @unlink($file);
        return null;
    }
    return $record;
}

/**
 * @param array<string,mixed> $ace
 * @return array<string,mixed>
 */
function aiAgentReplyFromAce(array $ace): array
{
    $reply = [
        'text' => trim((string) ($ace['message'] ?? '')),
        'facts' => '',
        'analysis' => '',
        'invoices' => [],
        'customers' => [],
        'actions' => [],
        'follow_up' => null,
        'source' => 'ace',
        'conversation_id' => (string) ($ace['conversation_id'] ?? ''),
    ];
    foreach ($ace['actions'] ?? [] as $action) {
        if (!is_array($action)) {
            continue;
        }
        $result = json_decode((string) ($action['result'] ?? ''), true);
        if (!is_array($result)) {
            continue;
        }
        if (!empty($result['invoices']) && is_array($result['invoices'])) {
            $reply['invoices'] = $result['invoices'];
        }
        if (!empty($result['customers']) && is_array($result['customers'])) {
            $reply['customers'] = $result['customers'];
        }
        if (!empty($result['follow_up']) && is_array($result['follow_up'])) {
            $reply['follow_up'] = $result['follow_up'];
        }
        if (!empty($result['links']) && is_array($result['links'])) {
            foreach ($result['links'] as $link) {
                if (is_array($link) && !empty($link['url'])) {
                    $reply['actions'][] = [
                        'label' => (string) ($link['label'] ?? 'Open'),
                        'url' => (string) $link['url'],
                    ];
                }
            }
        }
    }
    if ($reply['text'] === '') {
        $reply['text'] = 'ACE did not return a briefing.';
    }
    return $reply;
}

/**
 * @return array<string,mixed>|null
 */
function aiAgentAskAce(string $message, string $task = 'ask'): ?array
{
    $message = trim($message);
    if ($message === '') {
        return null;
    }
    $token = aiAgentAceToken();
    $payload = [
        'message' => $message,
        'erp_api_url' => aiAgentAceEndpointUrl(),
        'erp_token' => $token,
    ];
    $conversationId = (string) ($_SESSION['ai_agent_ace_conversation'] ?? '');
    if ($conversationId !== '') {
        $payload['conversation_id'] = $conversationId;
    }
    if ($task === 'briefing') {
        $payload['message'] = 'Call ultitech_daily_briefing and write today\'s briefing in 3 or 4 short sentences. Use only figures from that tool. Then say which receivables need attention first. Do not create invoices or vouchers.';
    }

    $body = json_encode($payload);
    if ($body === false) {
        return null;
    }
    $ch = curl_init('http://127.0.0.1:8765/api/chat');
    if ($ch === false) {
        return null;
    }
    $resumeSession = session_status() === PHP_SESSION_ACTIVE;
    if ($resumeSession) {
        session_write_close();
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 25,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resumeSession && session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
        error_log('ai-agent ace chat failed status=' . $status);
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || empty($decoded['message'])) {
        return null;
    }
    if (!empty($decoded['conversation_id'])) {
        $_SESSION['ai_agent_ace_conversation'] = (string) $decoded['conversation_id'];
    }
    return aiAgentReplyFromAce($decoded);
}
