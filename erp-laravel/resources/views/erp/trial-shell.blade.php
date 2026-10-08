<!DOCTYPE html>
<html lang="en" class="{{ $htmlClass ?? 'page-register page-trial' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $pageTitle ?? 'Start Free Trial | UltiTech ERP' }}</title>
    @php
        $faviconPartial = rtrim((string) config('erp.app_root'), '\\/') . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'favicon.php';
        if (is_file($faviconPartial)) {
            require_once $faviconPartial;
            echo erp_favicon_tags();
        }
        $seoPartial = rtrim((string) config('erp.app_root'), '\\/') . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'seo.php';
        if (is_file($seoPartial)) {
            require_once $seoPartial;
            echo erp_seo_tags([
                'title' => 'Start a Free Trial | UltiTech ERP',
                'description' => 'Create your company account and try UltiTech ERP free for 14 days: sales, inventory, payroll, expenses and reports in one cloud system. No card needed.',
                'path' => '/free-trial.php',
            ]);
        }
    @endphp
    @if (!empty($headMarkup))
        {!! $headMarkup !!}
    @endif
</head>
<body class="{{ $bodyClass ?? 'page-register page-trial' }}">
    <noscript>
        <div style="padding:2rem;font-family:sans-serif;">JavaScript is required to register.</div>
    </noscript>
    <div id="root"></div>
    @if (!empty($footerScripts))
        {!! $footerScripts !!}
    @endif
</body>
</html>
