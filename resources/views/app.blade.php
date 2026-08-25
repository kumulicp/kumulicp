<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <!-- Scripts -->
        @routes(group: \App\Support\Ziggy::groupFor(auth()->user()))
        @vite('resources/js/app.js')
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
        @auth
        <script src="https://js.stripe.com/v3"></script>
        @endauth
    </body>
</html>
