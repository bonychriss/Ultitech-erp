<?php
/**
 * Require an app-root PHP file with CWD set to that file's directory
 * so legacy relative requires (../includes/...) resolve correctly.
 */
function ultimate_tenant_require(string $appRelativeFile): void
{
    // This file lives in ultimate/; app root is its parent.
    $appRoot = dirname(__DIR__);
    $rel = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($appRelativeFile, '/\\'));
    $full = $appRoot . DIRECTORY_SEPARATOR . $rel;
    if (!is_file($full)) {
        // Avoid Apache ErrorDocument "Oops! Page not found" — bounce to the real app path.
        if (empty($_GET['company_slug'])) {
            $_GET['company_slug'] = 'ultimate';
        }
        $target = '/' . str_replace('\\', '/', $rel);
        $qs = $_GET;
        $query = http_build_query($qs);
        header('Location: ' . $target . ($query !== '' ? ('?' . $query) : ''), true, 302);
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
