<?php
require_once __DIR__ . '/includes/config.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Arima Font Debug</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=arima:400,500,600,700">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Arima:wght@400;500;600;700&display=swap">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 24px;
            color: #111;
        }
        .sample {
            margin-top: 16px;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 28px;
            font-family: 'Arima', Arial, sans-serif;
        }
        .mono {
            font-family: Consolas, monospace;
            font-size: 13px;
            white-space: pre-wrap;
            background: #f7f7f7;
            padding: 10px;
            border-radius: 6px;
            border: 1px solid #ececec;
        }
        .ok { color: #0f7a2a; font-weight: 700; }
        .bad { color: #b00020; font-weight: 700; }
    </style>
</head>
<body>
    <h2>Arima Font Debug</h2>
    <p>This page checks whether <strong>Arima</strong> is actually loaded and applied in this browser.</p>
    <div id="status"></div>
    <div class="sample" id="sample">The quick brown fox jumps over 1234567890</div>
    <h3>Details</h3>
    <div class="mono" id="details">Running checks...</div>

    <script>
        async function runFontDebug() {
            const statusEl = document.getElementById('status');
            const detailsEl = document.getElementById('details');
            const sampleEl = document.getElementById('sample');

            const lines = [];
            lines.push('User agent: ' + navigator.userAgent);
            lines.push('URL: ' + location.href);

            // Wait a bit for linked fonts to load.
            if (document.fonts && document.fonts.ready) {
                try {
                    await document.fonts.ready;
                    lines.push('document.fonts.ready: resolved');
                } catch (e) {
                    lines.push('document.fonts.ready: rejected -> ' + String(e));
                }
            } else {
                lines.push('document.fonts API: not supported');
            }

            const check400 = document.fonts ? document.fonts.check("400 24px 'Arima'") : false;
            const check700 = document.fonts ? document.fonts.check("700 24px 'Arima'") : false;
            lines.push("document.fonts.check 400: " + check400);
            lines.push("document.fonts.check 700: " + check700);

            let loadedArimaFaces = 0;
            if (document.fonts) {
                try {
                    document.fonts.forEach((f) => {
                        if ((f.family || '').toLowerCase().includes('arima')) {
                            loadedArimaFaces++;
                        }
                    });
                } catch (e) {
                    lines.push('font face iteration error: ' + String(e));
                }
            }
            lines.push('Loaded Arima font faces found: ' + loadedArimaFaces);

            const computed = getComputedStyle(sampleEl).fontFamily || '';
            lines.push('Computed font-family on sample: ' + computed);

            const ok = (check400 || check700 || loadedArimaFaces > 0) && computed.toLowerCase().includes('arima');
            statusEl.innerHTML = ok
                ? '<p class="ok">PASS: Arima appears to be loaded and applied.</p>'
                : '<p class="bad">FAIL: Arima is not loading/applied. Browser is likely using fallback font.</p>';

            detailsEl.textContent = lines.join('\n');
        }

        runFontDebug();
    </script>
</body>
</html>

