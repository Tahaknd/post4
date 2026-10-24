<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">

        <title inertia>{{ config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead
    </head>
    <body>
        <noscript>
            <p class="mx-auto max-w-prose px-4 py-8 text-fg">{{ config('app.name') }} needs JavaScript to run. Enable JavaScript in your browser and reload the page.</p>
        </noscript>

        @inertia
    </body>
</html>
