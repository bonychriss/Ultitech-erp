<?php

class JsonResponse
{
    public static function send(array $payload, $status = 200)
    {
        http_response_code((int) $status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload);
        exit;
    }

    public static function error($message, $status = 400, array $extra = array())
    {
        self::send(array_merge(array(
            'status' => 'error',
            'message' => (string) $message,
        ), $extra), (int) $status);
    }

    public static function ok(array $data = array(), $message = 'ok')
    {
        self::send(array_merge(array(
            'status' => 'success',
            'message' => (string) $message,
        ), $data));
    }
}
