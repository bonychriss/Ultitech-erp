<!DOCTYPE html>
<html lang="en" class="{{ $htmlClass ?? 'page-register page-trial' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $pageTitle ?? 'Start Free Trial | UltiTech ERP' }}</title>
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
