<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-50">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'MaintenanceFlow') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <script>
        // Pass authentication and session state directly from Laravel to Vue SPA
        window.__INITIAL_STATE__ = {
            user: @json(auth()->user()),
        };
    </script>
</head>

<body class="h-full font-sans antialiased text-slate-900">
    <div id="app"></div>
</body>

</html>