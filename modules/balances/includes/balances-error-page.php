<?php
/**
 * Balances — standalone error / unavailable page (space-themed layout).
 * Safe to include before database bootstrap (no external deps).
 */
declare(strict_types=1);

if (!function_exists('balances_render_error_page')) {
    /**
     * @param array{
     *   title?: string,
     *   headline?: string,
     *   back_url?: string,
     *   retry_url?: string,
     *   home_url?: string,
     *   back_label?: string,
     *   retry_label?: string,
     *   home_label?: string,
     *   error_code?: string,
     *   log_context?: string,
     *   http_code?: int
     * } $options
     */
    function balances_render_error_page(string $message, array $options = []): void
    {
        $title = trim((string) ($options['title'] ?? 'Page unavailable'));
        $headline = trim((string) ($options['headline'] ?? 'Oops! Page unavailable'));
        $backUrl = trim((string) ($options['back_url'] ?? 'accounts.php'));
        $retryUrl = trim((string) ($options['retry_url'] ?? ''));
        $homeUrl = trim((string) ($options['home_url'] ?? $backUrl));
        $backLabel = trim((string) ($options['back_label'] ?? 'Go Back'));
        $retryLabel = trim((string) ($options['retry_label'] ?? 'Try again'));
        $homeLabel = trim((string) ($options['home_label'] ?? 'Go Home'));
        $errorCode = trim((string) ($options['error_code'] ?? '500'));
        $logContext = trim((string) ($options['log_context'] ?? 'balances'));
        $httpCode = (int) ($options['http_code'] ?? 200);

        $safeMessage = trim($message);
        if ($safeMessage === '') {
            $safeMessage = "The page you're looking for doesn't exist or has been moved.";
        }

        error_log($logContext . ': ' . $safeMessage);

        if (!headers_sent()) {
            http_response_code($httpCode);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }

        $esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $codeChars = preg_split('//u', $errorCode, -1, PREG_SPLIT_NO_EMPTY) ?: ['5', '0', '0'];
        if (count($codeChars) < 3) {
            $codeChars = array_pad($codeChars, 3, '0');
        }
        $codeLeft = $esc($codeChars[0]);
        $codeMid = $esc($codeChars[1] ?? '0');
        $codeRight = $esc($codeChars[count($codeChars) - 1]);
        if (strlen($errorCode) === 3) {
            $codeLeft = $esc($errorCode[0]);
            $codeMid = $esc($errorCode[1]);
            $codeRight = $esc($errorCode[2]);
        }

        $useHistoryBack = ($retryUrl === '' || $retryUrl === '#back');
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $esc($title) ?> - Balances</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bal-purple-light: #a78bfa;
            --bal-purple: #7c3aed;
            --bal-purple-dark: #5b21b6;
            --bal-text: #1e1b4b;
            --bal-muted: #64748b;
            --bal-bg: #f8fafc;
        }
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Inter', system-ui, sans-serif;
            background: var(--bal-bg);
            color: var(--bal-text);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1.25rem;
            overflow-x: hidden;
        }
        .bal-unavail {
            width: 100%;
            max-width: 720px;
            text-align: center;
            animation: bal-unavail-in 0.45s cubic-bezier(0.22, 1, 0.36, 1);
        }
        @keyframes bal-unavail-in {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .bal-unavail__hero {
            position: relative;
            margin: 0 auto 1.5rem;
            max-width: 520px;
            min-height: 220px;
        }
        .bal-unavail__stars {
            position: absolute;
            inset: 0;
            pointer-events: none;
        }
        .bal-unavail__star {
            position: absolute;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #c4b5fd;
            opacity: 0.65;
        }
        .bal-unavail__star--sm { width: 4px; height: 4px; opacity: 0.45; }
        .bal-unavail__star--plus {
            width: auto;
            height: auto;
            background: none;
            color: #c4b5fd;
            font-size: 14px;
            line-height: 1;
            opacity: 0.55;
        }
        .bal-unavail__code-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            position: relative;
            z-index: 1;
        }
        .bal-unavail__digit {
            font-size: clamp(5rem, 18vw, 7.5rem);
            font-weight: 800;
            line-height: 1;
            letter-spacing: -0.04em;
            background: linear-gradient(180deg, var(--bal-purple-light) 0%, var(--bal-purple) 55%, var(--bal-purple-dark) 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            user-select: none;
        }
        .bal-unavail__portal {
            width: clamp(88px, 22vw, 118px);
            height: clamp(88px, 22vw, 118px);
            margin: 0 0.15rem;
            position: relative;
            flex-shrink: 0;
        }
        .bal-unavail__portal svg {
            width: 100%;
            height: 100%;
            display: block;
            filter: drop-shadow(0 8px 20px rgba(91, 33, 182, 0.18));
        }
        .bal-unavail__rocket {
            position: absolute;
            left: 4%;
            top: 18%;
            width: 52px;
            height: 52px;
            transform: rotate(-35deg);
            opacity: 0.95;
        }
        .bal-unavail__planet {
            position: absolute;
            right: 6%;
            top: 12%;
            width: 44px;
            height: 44px;
        }
        .bal-unavail__cloud {
            position: absolute;
            left: 50%;
            bottom: -8px;
            transform: translateX(-50%);
            width: min(320px, 80%);
            height: 36px;
            background: radial-gradient(ellipse at center, rgba(226, 232, 240, 0.9) 0%, rgba(248, 250, 252, 0) 72%);
            pointer-events: none;
        }
        .bal-unavail__headline-wrap {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.65rem;
            flex-wrap: wrap;
            margin-bottom: 0.75rem;
        }
        .bal-unavail__spark {
            color: var(--bal-purple);
            font-size: 1.1rem;
            opacity: 0.85;
            line-height: 1;
        }
        .bal-unavail__headline {
            margin: 0;
            font-size: clamp(1.5rem, 4vw, 2rem);
            font-weight: 800;
            color: var(--bal-text);
            letter-spacing: -0.02em;
        }
        .bal-unavail__message {
            margin: 0 auto 2rem;
            max-width: 420px;
            font-size: 1rem;
            line-height: 1.6;
            color: var(--bal-muted);
        }
        .bal-unavail__actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: center;
            gap: 0.85rem;
        }
        .bal-unavail__btn {
            min-width: 148px;
            min-height: 48px;
            padding: 0.75rem 1.35rem;
            border-radius: 999px;
            font-size: 0.9375rem;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            border: 1px solid transparent;
            transition: transform 0.12s ease, box-shadow 0.15s ease, background 0.15s ease;
        }
        .bal-unavail__btn:active { transform: scale(0.98); }
        .bal-unavail__btn--home {
            color: #fff;
            background: linear-gradient(135deg, var(--bal-purple-light) 0%, var(--bal-purple) 50%, var(--bal-purple-dark) 100%);
            box-shadow: 0 10px 24px rgba(124, 58, 237, 0.28);
        }
        .bal-unavail__btn--home:hover {
            box-shadow: 0 14px 28px rgba(124, 58, 237, 0.34);
        }
        .bal-unavail__btn--back {
            color: var(--bal-text);
            background: #fff;
            border-color: #e2e8f0;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06);
        }
        .bal-unavail__btn--back:hover {
            background: #f8fafc;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.08);
        }
        @media (max-width: 520px) {
            .bal-unavail__actions {
                flex-direction: column;
                width: 100%;
            }
            .bal-unavail__btn {
                width: 100%;
                max-width: 280px;
            }
        }
    </style>
</head>
<body>
    <main class="bal-unavail" role="alertdialog" aria-labelledby="balErrorHeadline" aria-describedby="balErrorMsg">
        <div class="bal-unavail__hero" aria-hidden="true">
            <div class="bal-unavail__stars">
                <span class="bal-unavail__star" style="left:8%;top:12%"></span>
                <span class="bal-unavail__star bal-unavail__star--sm" style="left:18%;top:42%"></span>
                <span class="bal-unavail__star bal-unavail__star--plus" style="left:28%;top:8%">+</span>
                <span class="bal-unavail__star" style="right:22%;top:18%"></span>
                <span class="bal-unavail__star bal-unavail__star--sm" style="right:10%;top:38%"></span>
                <span class="bal-unavail__star bal-unavail__star--plus" style="right:28%;top:6%">+</span>
                <span class="bal-unavail__star bal-unavail__star--sm" style="left:42%;top:4%"></span>
            </div>

            <div class="bal-unavail__rocket">
                <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M32 8c8 10 12 22 12 34 0 2-1 4-3 5l-9 5-9-5c-2-1-3-3-3-5C20 30 24 18 32 8z" fill="#8b5cf6"/>
                    <circle cx="32" cy="28" r="6" fill="#ede9fe"/>
                    <path d="M26 44l-6 12 8-4 8 4-6-12" fill="#fbbf24"/>
                    <path d="M28 47c0 0 2 4 4 4s4-4 4-4" stroke="#f59e0b" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </div>

            <div class="bal-unavail__planet">
                <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="24" cy="26" r="14" fill="#8b5cf6"/>
                    <ellipse cx="24" cy="30" rx="20" ry="5" fill="#c4b5fd" opacity="0.85"/>
                    <circle cx="20" cy="22" r="3" fill="#ede9fe" opacity="0.55"/>
                </svg>
            </div>

            <div class="bal-unavail__code-row">
                <span class="bal-unavail__digit"><?= $codeLeft ?></span>
                <div class="bal-unavail__portal">
                    <svg viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="60" cy="60" r="54" fill="#7c3aed"/>
                        <circle cx="60" cy="60" r="46" fill="#6d28d9"/>
                        <circle cx="60" cy="60" r="38" fill="#eef2ff"/>
                        <ellipse cx="60" cy="72" rx="22" ry="8" fill="#e2e8f0"/>
                        <rect x="44" y="34" width="32" height="38" rx="16" fill="#fff" stroke="#cbd5e1" stroke-width="2"/>
                        <rect x="48" y="40" width="24" height="18" rx="8" fill="#334155"/>
                        <path d="M54 48h12" stroke="#94a3b8" stroke-width="2" stroke-linecap="round"/>
                        <rect x="52" y="62" width="16" height="10" rx="3" fill="#e2e8f0"/>
                        <rect x="54" y="64" width="4" height="4" rx="1" fill="#ef4444"/>
                        <rect x="59" y="64" width="4" height="4" rx="1" fill="#eab308"/>
                        <rect x="64" y="64" width="4" height="4" rx="1" fill="#22c55e"/>
                        <path d="M72 52l8 4-8 4" fill="#fff" stroke="#cbd5e1" stroke-width="1.5" stroke-linejoin="round"/>
                        <circle cx="60" cy="60" r="54" fill="none" stroke="#a78bfa" stroke-width="4" opacity="0.35"/>
                    </svg>
                </div>
                <span class="bal-unavail__digit"><?= $codeRight ?></span>
            </div>
            <div class="bal-unavail__cloud"></div>
        </div>

        <div class="bal-unavail__headline-wrap">
            <span class="bal-unavail__spark" aria-hidden="true">? ? ?</span>
            <h1 id="balErrorHeadline" class="bal-unavail__headline"><?= $esc($headline) ?></h1>
            <span class="bal-unavail__spark" aria-hidden="true">? ? ?</span>
        </div>

        <p id="balErrorMsg" class="bal-unavail__message"><?= $esc($safeMessage) ?></p>

        <div class="bal-unavail__actions">
            <?php if ($homeUrl !== ''): ?>
                <a class="bal-unavail__btn bal-unavail__btn--home" href="<?= $esc($homeUrl) ?>">
                    <i class="fas fa-house" aria-hidden="true"></i>
                    <?= $esc($homeLabel) ?>
                </a>
            <?php endif; ?>
            <?php if ($useHistoryBack): ?>
                <button type="button" class="bal-unavail__btn bal-unavail__btn--back" onclick="history.back()">
                    <i class="fas fa-arrow-left" aria-hidden="true"></i>
                    <?= $esc($backLabel) ?>
                </button>
            <?php elseif ($retryUrl !== ''): ?>
                <a class="bal-unavail__btn bal-unavail__btn--back" href="<?= $esc($retryUrl) ?>">
                    <i class="fas fa-arrow-left" aria-hidden="true"></i>
                    <?= $esc($retryLabel !== 'Try again' ? $retryLabel : $backLabel) ?>
                </a>
            <?php elseif ($backUrl !== '' && $backUrl !== $homeUrl): ?>
                <a class="bal-unavail__btn bal-unavail__btn--back" href="<?= $esc($backUrl) ?>">
                    <i class="fas fa-arrow-left" aria-hidden="true"></i>
                    <?= $esc($backLabel) ?>
                </a>
            <?php else: ?>
                <button type="button" class="bal-unavail__btn bal-unavail__btn--back" onclick="history.back()">
                    <i class="fas fa-arrow-left" aria-hidden="true"></i>
                    <?= $esc($backLabel) ?>
                </button>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
        <?php
        exit;
    }
}
