<?php
// Copy this file to deploy.config.php and fill with your real FTP credentials.
// This file is ignored by Git when named deploy.config.php.
return [
    'host' => 'ftpupload.net',
    'port' => 21,
    'user' => 'if0_40373879',
    'pass' => 'YOUR_PASSWORD_HERE',
    'secure' => true, // FTPS Explicit TLS
    'remoteDir' => '/htdocs/',
    // Change only if your project root is different
    'localRoot' => dirname(__DIR__),
    // Include everything by default
    'include' => ['**/*'],
    // Exclude secrets and non-runtime folders
    'exclude' => [
        '**/.git/**',
        '**/.github/**',
        '**/.vscode/**',
        '**/*.md',
        '**/node_modules/**',
        '**/tasks/**',
        '**/laravel-core/**',
        '**/reset_database.sh',
        '**/database_*.sql',
        'includes/env.php',
        'includes/env.*.php',
        'assets/uploads/**',
        'assets/signatures/**',
    ],
    // Force-include .htaccess inside excluded folders
    'extra' => [
        'assets/uploads/.htaccess',
        'assets/signatures/.htaccess',
    ],
];
