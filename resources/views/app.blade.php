<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-density="{{ $page['props']['settings']['density'] ?? 'comfortable' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#263447">
        <meta name="description" content="NADI — Global Stock Intelligence & Multi-Broker Portfolio Management">

        <title inertia>{{ config('app.name', 'NADI') }}</title>

        <link rel="icon" type="image/svg+xml" href="/icons/nadi-icon.svg">
        <link rel="manifest" href="/manifest.webmanifest">

        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
