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
     * Full-screen video hero background with 404 messaging.
     *
     * @param array{
     *   title?: string,
     *   message?: string,
     *   actionsHtml?: string,
     *   statusCode?: int,
     *   pageTitle?: string,
     *   homeUrl?: string
     * } $options
     */
    function render404Page(array $options = []): void
    {
        $statusCode = (int) ($options['statusCode'] ?? 404);
        $pageTitle = (string) ($options['pageTitle'] ?? 'Page Not Found');
        $title = (string) ($options['title'] ?? 'Oops! Page not found');
        $message = (string) ($options['message'] ?? 'The page you are looking for might have been removed, renamed, or is temporarily unavailable.');
        $actionsHtml = (string) ($options['actionsHtml'] ?? '');
        $homeUrl = (string) ($options['homeUrl'] ?? '');

        if ($homeUrl === '' && $actionsHtml !== '' && preg_match('/href="([^"]+)"/', $actionsHtml, $m)) {
            $homeUrl = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
        if ($homeUrl === '') {
            if (function_exists('app_url')) {
                $homeUrl = (string) app_url('/select-module.php');
            } else {
                $homeUrl = '/select-module.php';
            }
        }

        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: text/html; charset=UTF-8');
        }

        $safePageTitle = htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8');
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $safeHome = htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8');
        $videoUrl = 'https://d8j0ntlcm91z4.cloudfront.net/user_38xzZboKViGWJOttwIXH07lWA1P/hf_20260803_192301_9231ed6b-c55c-4a48-909c-4ebe11cf2e11.mp4';

        echo '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>' . $safePageTitle . '</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700&amp;display=swap" rel="stylesheet">
  <style>
    * { box-sizing: border-box; }
    html, body {
      height: 100%;
      margin: 0;
    }
    body {
      font-family: "Geist", -apple-system, BlinkMacSystemFont, sans-serif;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
      background: #0a0a0a;
      color: #fff;
      overflow: hidden;
    }

    .hero {
      position: relative;
      height: 100vh;
      width: 100%;
      overflow: hidden;
    }
    .hero-fallback {
      position: absolute;
      inset: 0;
      background:
        radial-gradient(ellipse at 30% 20%, rgba(80,80,90,.45), transparent 55%),
        linear-gradient(160deg, #1a1a1f 0%, #0b0b0d 55%, #121218 100%);
      z-index: 0;
    }
    .hero-video {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      object-fit: cover;
      background: #111;
    }
    .hero-ui {
      position: relative;
      z-index: 10;
      display: flex;
      flex-direction: column;
      height: 100%;
    }

    .content {
      margin-top: auto;
      display: flex;
      width: 100%;
      flex-direction: column;
      align-items: flex-start;
      padding: 0 1.25rem 2rem;
    }
    @media (min-width: 640px) {
      .content { padding: 0 2rem 3rem; }
    }
    @media (min-width: 1024px) {
      .content { padding: 0 3rem 4rem; }
    }

    .copy { max-width: 36rem; }
    .eyebrow {
      margin: 0 0 0.5rem;
      font-size: 0.75rem;
      font-weight: 600;
      letter-spacing: 0.18em;
      text-transform: uppercase;
      color: rgba(255,255,255,.60);
    }
    .headline {
      margin: 0;
      font-size: 1.875rem;
      font-weight: 600;
      line-height: 1.1;
      letter-spacing: -0.025em;
      color: #fff;
    }
    .lede {
      margin: 1rem 0 0;
      max-width: 32rem;
      font-size: 0.875rem;
      line-height: 1.6;
      color: rgba(255,255,255,.70);
    }
    @media (min-width: 640px) {
      .headline { font-size: 2.25rem; }
      .lede { font-size: 1rem; }
    }
    @media (min-width: 1024px) {
      .headline { font-size: 3.5rem; }
    }

    .cta-wrap { margin-top: 1.5rem; }
    @media (min-width: 640px) {
      .cta-wrap {
        margin-top: 2rem;
        display: inline-flex;
        align-items: center;
        border-radius: 9999px;
        background: #fff;
        padding: 0.375rem;
      }
    }
    .cta {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border: none;
      border-radius: 9999px;
      padding: 0.75rem 1.5rem;
      font: inherit;
      font-size: 0.875rem;
      font-weight: 500;
      color: #fff;
      text-decoration: none;
      cursor: pointer;
      background: linear-gradient(to bottom, #2B2B2B, #101010);
      transition: opacity .2s;
    }
    .cta:hover { opacity: 0.9; }
  </style>
</head>
<body>
  <section class="hero" aria-label="Page not found">
    <div class="hero-fallback" aria-hidden="true"></div>
    <video class="hero-video" autoplay loop muted playsinline preload="auto">
      <source src="' . htmlspecialchars($videoUrl, ENT_QUOTES, 'UTF-8') . '" type="video/mp4">
    </video>

    <div class="hero-ui">
      <div class="content">
        <div class="copy">
          <p class="eyebrow">Error 404</p>
          <h1 class="headline">' . $safeTitle . '</h1>
          <p class="lede">' . $safeMessage . '</p>
          <div class="cta-wrap">
            <a class="cta" href="' . $safeHome . '">Back to Home</a>
          </div>
        </div>
      </div>
    </div>
  </section>

  <script>
    (function () {
      var video = document.querySelector(".hero-video");
      if (!video) return;
      var play = video.play();
      if (play && typeof play.catch === "function") {
        play.catch(function () {});
      }
    })();
  </script>
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
            'homeUrl' => $homeUrl,
        ]);
    }
}
