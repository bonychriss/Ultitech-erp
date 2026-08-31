<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/modules/email/includes/email_bootstrap.php';

try {
    $pdo = email_module_pdo();
    
    $sql = "CREATE TABLE IF NOT EXISTS `module_email_user_settings` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `imap_host` varchar(255) DEFAULT NULL,
        `imap_port` varchar(10) DEFAULT '993',
        `imap_user` varchar(255) DEFAULT NULL,
        `imap_pass` varchar(255) DEFAULT NULL,
        `imap_ssl` varchar(20) DEFAULT 'ssl',
        `smtp_host` varchar(255) DEFAULT NULL,
        `smtp_port` varchar(10) DEFAULT '465',
        `smtp_user` varchar(255) DEFAULT NULL,
        `smtp_pass` varchar(255) DEFAULT NULL,
        `smtp_ssl` varchar(20) DEFAULT 'ssl',
        `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    
    $pdo->exec($sql);
    echo "Table module_email_user_settings created successfully.\n";
} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
}
