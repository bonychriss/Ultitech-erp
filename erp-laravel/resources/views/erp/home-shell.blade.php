<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $pageTitle ?? 'UltiTech ERP | Welcome' }}</title>
    @if (!empty($headMarkup))
        {!! $headMarkup !!}
    @endif
</head>
<body class="{{ $bodyClass ?? 'home-ui-page' }}">
    <noscript>
        <div style="padding:2rem;font-family:sans-serif;">JavaScript is required to view this page.</div>
    </noscript>
    <div id="root"></div>
    @if (!empty($footerScripts))
        {!! $footerScripts !!}
    @endif
</body>
</html>
