<?php

declare(strict_types=1);

if (!function_exists('error404_lottie_filename')) {
    function error404_lottie_filename(): string
    {
        return '404 Error page not found.lottie';
    }
}

if (!function_exists('error404_lottie_url')) {
    function error404_lottie_url(): string
    {
        $path = '/assets/animations/' . rawurlencode(error404_lottie_filename());
        if (function_exists('app_url')) {
            return (string) app_url($path);
        }
        if (defined('APP_BASE_PATH') && (string) APP_BASE_PATH !== '') {
            return rtrim((string) APP_BASE_PATH, '/') . $path;
        }

        return $path;
    }
}

if (!function_exists('error404_lottie_player_markup')) {
    function error404_lottie_player_markup(string $wrapperClass = 'error404-lottie', int $size = 280): string
    {
        $src = htmlspecialchars(error404_lottie_url(), ENT_QUOTES, 'UTF-8');
        $class = htmlspecialchars($wrapperClass, ENT_QUOTES, 'UTF-8');

        return '<div class="' . $class . '" style="width:min(' . $size . 'px,70vw);height:min(' . $size . 'px,70vw);margin:0 auto 1rem;">'
            . '<dotlottie-player src="' . $src . '" background="transparent" speed="1" style="width:100%;height:100%;" loop autoplay></dotlottie-player>'
            . '</div>';
    }
}

if (!function_exists('render404Page')) {
    /**
     * @param array{
     *   title?: string,
     *   message?: string,
     *   actionsHtml?: string,
     *   statusCode?: int,
     *   pageTitle?: string
     * } $options
     */
    function render404Page(array $options = []): void
    {
        $statusCode = (int) ($options['statusCode'] ?? 404);
        $pageTitle = (string) ($options['pageTitle'] ?? 'Page Not Found');
        $title = (string) ($options['title'] ?? 'Page not found');
        $message = (string) ($options['message'] ?? 'The page you are looking for might have been removed, renamed, or is temporarily unavailable.');
        $actionsHtml = (string) ($options['actionsHtml'] ?? '');

        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: text/html; charset=UTF-8');
        }

        $safePageTitle = htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8');
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $lottie = error404_lottie_player_markup();

        echo '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>' . $safePageTitle . '</title>
  <script src="https://unpkg.com/@dotlottie/player-component@latest/dist/dotlottie-player.js"></script>
  <style>
    :root {
      --e404-bg: #f0f2f8;
      --e404-text: #0f172a;
      --e404-muted: #64748b;
      --e404-border: #e2e8f0;
      --e404-primary: #4361ee;
      --e404-primary-dark: #3a56d4;
    }
    html[data-theme="dark"] {
      --e404-bg: #0f172a;
      --e404-text: #f1f5f9;
      --e404-muted: #94a3b8;
      --e404-border: #334155;
      --e404-primary: #6366f1;
      --e404-primary-dark: #4f46e5;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1.5rem;
      font-family: "DM Sans", "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
      color: var(--e404-text);
      background: var(--e404-bg);
    }
    .error404-page {
      width: min(36rem, 100%);
      text-align: center;
    }
    h1 {
      margin: 0 0 0.65rem;
      font-size: 1.45rem;
      letter-spacing: -0.02em;
    }
    p {
      margin: 0 auto;
      max-width: 24rem;
      color: var(--e404-muted);
      font-size: 0.95rem;
      line-height: 1.6;
    }
    .error404-actions {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 0.55rem;
      margin-top: 1.35rem;
    }
    .error404-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 2.45rem;
      padding: 0.5rem 1.15rem;
      border-radius: 9999px;
      border: 1px solid transparent;
      font: inherit;
      font-size: 0.875rem;
      font-weight: 600;
      text-decoration: none;
      cursor: pointer;
      background: transparent;
    }
    .error404-btn-primary {
      background: var(--e404-primary);
      border-color: var(--e404-primary);
      color: #fff;
    }
    .error404-btn-primary:hover {
      background: var(--e404-primary-dark);
      border-color: var(--e404-primary-dark);
    }
    .error404-btn-secondary {
      background: transparent;
      border-color: var(--e404-border);
      color: var(--e404-text);
    }
    .error404-btn-secondary:hover {
      background: rgba(148, 163, 184, 0.12);
    }
    @media (prefers-reduced-motion: reduce) {
      dotlottie-player { display: none; }
    }
  </style>
  <script>
    (function () {
      var t = localStorage.getItem("theme") || "light";
      document.documentElement.setAttribute("data-theme", t);
    })();
  </script>
</head>
<body>
  <main class="error404-page" role="alert" aria-live="polite">
    ' . $lottie . '
    <h1>' . $safeTitle . '</h1>
    <p>' . $safeMessage . '</p>
    ' . ($actionsHtml !== '' ? '<div class="error404-actions">' . $actionsHtml . '</div>' : '') . '
  </main>
</body>
</html>';
        exit;
    }
}

if (!function_exists('renderCompanyNotFoundPage')) {
    function renderCompanyNotFoundPage(string $message = 'Company not found.'): void
    {
        $homePath = '/';
        $loginPath = '/login.php';
        if (function_exists('app_url')) {
            $homeUrl = app_url($homePath);
            $loginUrl = app_url($loginPath);
        } elseif (defined('APP_BASE_PATH') && (string) APP_BASE_PATH !== '') {
            $base = rtrim((string) APP_BASE_PATH, '/');
            $homeUrl = $base . '/';
            $loginUrl = $base . $loginPath;
        } else {
            $homeUrl = $homePath;
            $loginUrl = $loginPath;
        }
        $safeHome = htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8');
        $safeLogin = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        $actionsHtml = '<a class="error404-btn error404-btn-primary" href="' . $safeHome . '">Home</a>'
            . '<a class="error404-btn error404-btn-secondary" href="' . $safeLogin . '">Login</a>';

        render404Page([
            'pageTitle' => 'Company not found',
            'title' => 'Company not found',
            'message' => $safeMessage . ' This company page is unavailable or the link is incorrect.',
            'actionsHtml' => $actionsHtml,
        ]);
    }
}
