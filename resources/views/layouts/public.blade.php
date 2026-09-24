<!DOCTYPE html>
<html lang="@yield('html_lang', 'ar')" dir="@yield('html_dir', 'rtl')">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', ($publicSite ?? \App\Support\Site\PublicSite::current())->appName())</title>
    @include('partials.public-site-head')
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=cairo:400,500,600,700,800&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="overflow-x-hidden bg-[#F3FCFA] text-[#172024] antialiased" style="font-family: var(--hs-font)">
    @include('partials.public-topbar')
    @yield('content')
    @include('partials.public-site-footer')
    @include('partials.ai-assistant-widget')
</body>
</html>
