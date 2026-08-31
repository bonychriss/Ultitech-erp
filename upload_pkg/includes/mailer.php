<?php
require_once __DIR__ . '/../config_mail.php';
require_once __DIR__ . '/SimpleSMTP.php';

function sendEmail($to, $subject, $body, $isHtml = true) {
    try {
        $mail = new SimpleSMTP(
            SMTP_HOST,
            SMTP_PORT,
            SMTP_USER,
            SMTP_PASS,
            SMTP_SECURE
        );
        
        return $mail->send(
            SMTP_FROM_EMAIL,
            SMTP_FROM_NAME,
            $to,
            $subject,
            $body,
            $isHtml
        );
    } catch (Exception $e) {
        error_log("Mailer Error: " . $e->getMessage());
        return false;
    }
}
