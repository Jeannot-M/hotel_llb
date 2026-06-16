<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{{ $title ?? "LULUABOURG" }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/favicon.png') }}">
    
    <!-- PWA Settings -->
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="theme-color" content="#000000">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Luluabourg CRM">
    <link rel="apple-touch-icon" href="{{ asset('images/logo.png') }}">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>

<body class="min-h-screen bg-black text-white selection:bg-crm-yellow selection:text-black">

    <!-- CRM Layout -->
    <div class="min-h-screen flex flex-col">

        <!-- Page content -->
        <main class="flex-1 min-w-0 min-h-screen overflow-y-auto bg-black">
            <div class="max-w-[1600px] mx-auto w-full px-4 md:px-8 lg:px-12">
                {{ $slot }}
            </div>
        </main>

    </div>

    @fluxScripts
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js').then((registration) => {
                    console.log('SW registered:', registration);
                }).catch((error) => {
                    console.log('SW registration failed:', error);
                });
            });
        }

        // Expiration de session → redirection vers login
        const originalFetch = window.fetch;
        window.fetch = async (...args) => {
            const response = await originalFetch(...args);
            if (response.status === 419) {
                window.location.href = '/login';
            }
            return response;
        };
    </script>
</body>

</html>