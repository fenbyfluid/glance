<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark:scheme-dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
        <link rel="mask-icon" href="{{ asset('safari-pinned-tab.svg') }}" color="#6b7280">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        @vite('resources/js/app.js')

        @isset($headElements)
            {{ $headElements }}
        @endisset
    </head>
    <body class="font-sans antialiased dark:text-gray-100">
        {{-- pb-px works around a rendering issue where the background doesn't cover the children's bottom margin --}}
        <div class="min-h-screen pb-px bg-gray-100 dark:bg-gray-900" x-data :class="$store.searchModal.open && 'fixed h-screen w-screen'">
            @include('layouts.navigation')

            <!-- Page Heading -->
            @isset($header)
                <header class="bg-white dark:bg-gray-800 shadow-sm">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
            <main>
                {{ $slot }}
            </main>
        </div>
    </body>
</html>
