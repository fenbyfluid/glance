<!DOCTYPE html>
{{-- Shown for a moment while opening a shared link: browsers are forwarded straight to the file, link previews read
     the tags (see SharedLinkController::show()). --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <meta http-equiv="refresh" content="0; url={{ $targetUrl }}">

        <title>{{ $title }} – {{ config('app.name', 'Laravel') }}</title>

        <meta property="og:site_name" content="{{ config('app.name', 'Laravel') }}">
        <meta property="og:type" content="website">
        <meta property="og:title" content="{{ $title }}">
        <meta property="og:description" content="{{ $description }}">
        <meta property="og:url" content="{{ $linkUrl }}">
        @if($previewUrl)
            <meta property="og:image" content="{{ $previewUrl }}">
            <meta name="twitter:card" content="summary_large_image">
        @else
            <meta name="twitter:card" content="summary">
        @endif

        <script>window.location.replace(@js($targetUrl));</script>
    </head>
    <body>
        <p><a href="{{ $targetUrl }}">{{ $title }}</a></p>
    </body>
</html>
