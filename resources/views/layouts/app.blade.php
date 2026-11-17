<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark:scheme-dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
        <link rel="mask-icon" href="{{ asset('safari-pinned-tab.svg') }}" color="#6b7280">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

        <title>@isset($title){{ $title }} – @endisset{{ config('app.name', 'Laravel') }}</title>

        @vite('resources/js/app.js')

        @isset($headElements)
            {{ $headElements }}
        @endisset
    </head>
    {{-- The body background fills the canvas, which shows behind Safari's bars and when over-scrolling --}}
    <body class="font-sans antialiased dark:text-gray-100 bg-gray-100 dark:bg-gray-900">
        {{-- The bottom padding keeps the end of the page clear of Safari's toolbar / the home indicator (the page
             extends under them with viewport-fit=cover). It's at least 1px to work around a rendering issue where
             the background doesn't cover the children's bottom margin.
             While search is open the page stops scrolling (overflow hidden on the root) rather than being made fixed:
             Safari fills the area behind its toolbar with a solid colour when what's at the bottom edge of the screen
             is inside a fixed element, which hid the search results. --}}
        <div class="min-h-screen pb-[max(1px,env(safe-area-inset-bottom))] bg-gray-100 dark:bg-gray-900" x-data x-effect="document.documentElement.classList.toggle('overflow-hidden', $store.searchModal.open)">
            @include('layouts.navigation')

            <!-- Page Heading -->
            @isset($header)
                <header class="bg-white dark:bg-gray-800 shadow-sm safe-area-x">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
            <main class="safe-area-x">
                {{ $slot }}
            </main>
        </div>
    </body>
</html>
