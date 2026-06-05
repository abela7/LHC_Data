<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Shop')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="store-body">
    <header class="store-topbar">
        <a href="{{ route('shop.index') }}" class="store-brand">VHC&nbsp;Shop</a>
        <span class="store-topbar-note">Demo storefront</span>
    </header>
    <main class="store-main">
        @yield('content')
    </main>
</body>
</html>
