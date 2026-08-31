<?php

class JsonResponse
{
    public static function send(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $message, int $status = 400, array $extra = []): void
    {
        self::send(array_merge([
            'status' => 'error',
            'message' => $message,
        ], $extra), $status);
    }

    public static function ok(array $data = [], string $message = 'ok'): void
    {
        self::send(array_merge([
            'status' => 'success',
            'message' => $message,
        ], $data));
    }
}
