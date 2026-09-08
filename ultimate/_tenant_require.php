<?php
/**
 * Require an app-root PHP file with CWD set to that file's directory
 * so legacy relative requires (../includes/...) resolve correctly.
 */
function ultimate_tenant_require(string $appRelativeFile): void
{
    // This file lives in ultimate/; app root is its parent.
    $appRoot = dirname(__DIR__);
    $full = $appRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($appRelativeFile, '/\\'));
    if (!is_file($full)) {
        http_response_code(404);
        echo 'Not found.';
        exit;
    }
    if (empty($_GET['company_slug'])) {
        $_GET['company_slug'] = 'ultimate';
    }
    $previousCwd = getcwd() ?: null;
    chdir(dirname($full));
    try {
        require $full;
    } finally {
        if ($previousCwd !== null) {
            @chdir($previousCwd);
        }
    }
}
