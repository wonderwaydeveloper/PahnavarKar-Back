<!DOCTYPE html>
<html lang="fa" dir="rtl" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#FBFCFD">
    @include('partials.public-meta', [
        'pageTitle' => $pageTitle,
        'pageDescription' => $pageDescription,
        'canonicalUrl' => $canonicalUrl,
    ])
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}?v=4">
    <link rel="apple-touch-icon" href="{{ asset('favicon.png') }}?v=4">
    @include('partials.theme-bootstrap')
    @vite('resources/js/theme.js')
    @yield('head')
</head>
<body class="public-content-page bg-slate-50 font-sans text-slate-900 antialiased">
    @yield('before-header')
    @include('partials.public-header')

    @yield('content')

    @include('partials.public-footer')
    @yield('scripts')
</body>
</html>
