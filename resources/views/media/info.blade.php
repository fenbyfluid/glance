@use('App\Media\MediaContentKind')
@php
    $kind = $details->kind;
    $thumbnailUrl = $file?->thumbnail ? route('media.image', ['id' => $file->thumbnail], false) : null;
    $filmstripUrl = $file?->filmstrip ? route('media.image', ['id' => $file->filmstrip], false) : null;
@endphp
<x-app-layout>
    <x-slot name="title">{{ $scene?->title ?: basename($path) }}</x-slot>

    <x-slot name="headElements">
        @vite('resources/js/info.js')
    </x-slot>

    <x-slot name="navigation">
        @include('media.partials.navigation')
    </x-slot>

    @include('media.partials.impersonation-banner')

    <div class="flex flex-col lg:flex-row lg:items-start gap-6 my-6 lg:my-8 mx-4 sm:mx-6 lg:mx-8">
        {{-- The player comes first on small screens, where the sidebar is stacked below it. --}}
        <div class="grow min-w-0 lg:order-last lg:sticky lg:top-8">
            @switch($kind)
                @case(MediaContentKind::Video)
                    {{-- Plays the file directly where the browser can, falling back to a live HLS transcode (info.js).
                         Not playsinline: like the lightbox, iOS Safari plays it in its full screen player. --}}
                    <video
                        data-player-video
                        class="block w-full max-h-[calc(100svh-8rem)] bg-black rounded-lg shadow-sm"
                        controls
                        preload="metadata"
                        @if($thumbnailUrl) poster="{{ $thumbnailUrl }}" @endif
                    >
                        <source src="{{ $fileUrl }}" type="{{ $details->mimeType }}">
                    </video>
                    @break

                @case(MediaContentKind::Audio)
                    <div class="p-6 bg-white dark:bg-gray-800 shadow-sm rounded-lg">
                        <audio class="block w-full" controls preload="metadata" src="{{ $fileUrl }}"></audio>
                    </div>
                    @break

                @case(MediaContentKind::Image)
                    <a href="{{ $fileUrl }}" class="flex justify-center bg-black rounded-lg shadow-sm overflow-hidden">
                        <img class="block max-w-full max-h-[calc(100svh-8rem)] object-contain" src="{{ $fileUrl }}" alt="{{ basename($path) }}">
                    </a>
                    @break

                @case(MediaContentKind::Pdf)
                    <iframe class="block w-full h-[calc(100svh-8rem)] bg-white rounded-lg shadow-sm" src="{{ $fileUrl }}" title="{{ basename($path) }}"></iframe>
                    @break

                @case(MediaContentKind::Text)
                @case(MediaContentKind::Html)
                    {{-- Fetched and rendered the same way as in the lightbox (info.js). --}}
                    <div data-player-text="{{ $kind->value }}" data-src="{{ $fileUrl }}" class="p-6 bg-white dark:bg-gray-800 shadow-sm rounded-lg overflow-x-auto">
                        <p class="text-gray-500 dark:text-gray-400">Loading…</p>
                    </div>
                    @break

                @default
                    <div class="p-10 flex flex-col items-center gap-4 text-center bg-white dark:bg-gray-800 shadow-sm rounded-lg text-gray-500 dark:text-gray-400">
                        {!! $kind->displayIcon() !!}
                        <p>There's no preview for this file.</p>
                        <x-primary-link href="{{ $fileUrl }}" download>Download</x-primary-link>
                    </div>
            @endswitch

            @if($filmstripUrl)
                <details class="mt-4 bg-white dark:bg-gray-800 shadow-sm rounded-lg overflow-hidden">
                    <summary class="px-5 py-3 cursor-pointer select-none text-sm font-medium text-gray-700 dark:text-gray-300">Contact sheet</summary>
                    <img loading="lazy" class="block w-full" src="{{ $filmstripUrl }}" alt="">
                </details>
            @endif
        </div>

        <aside class="lg:w-96 lg:shrink-0 space-y-4">
            <div class="p-5 bg-white dark:bg-gray-800 shadow-sm rounded-lg">
                <h1 class="text-lg font-semibold break-words text-gray-900 dark:text-gray-100">{{ basename($path) }}</h1>
                <div class="mt-3 flex flex-wrap gap-2">
                    <x-primary-link href="{{ $fileUrl }}" download>Download</x-primary-link>
                    <x-secondary-link href="{{ $fileUrl }}">Open original</x-secondary-link>
                </div>
            </div>

            @isset($scene)
                @php
                    $studios = [];
                    for ($studio = $scene->studio; $studio !== null; $studio = $studio->relationLoaded('parentStudio') ? $studio->parentStudio : null) {
                        array_unshift($studios, $studio->name);
                    }

                    $releaseDate = implode('-', array_filter([
                        $scene->release_date_year,
                        $scene->release_date_month ? sprintf('%02d', $scene->release_date_month) : null,
                        $scene->release_date_day ? sprintf('%02d', $scene->release_date_day) : null,
                    ]));
                @endphp
                <section class="p-5 bg-white dark:bg-gray-800 shadow-sm rounded-lg">
                    <div class="flex items-baseline justify-between gap-4">
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Scene</h2>
                        <a href="https://stashdb.org/scenes/{{ $scene->id }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-sm text-indigo-600 dark:text-indigo-400 hover:underline">
                            StashDB
                            <svg class="size-3.5 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M4.25 5.5a.75.75 0 0 0-.75.75v8.5c0 .414.336.75.75.75h8.5a.75.75 0 0 0 .75-.75v-4a.75.75 0 0 1 1.5 0v4A2.25 2.25 0 0 1 12.75 17h-8.5A2.25 2.25 0 0 1 2 14.75v-8.5A2.25 2.25 0 0 1 4.25 4h5a.75.75 0 0 1 0 1.5h-5Z" clip-rule="evenodd" />
                                <path fill-rule="evenodd" d="M6.194 12.753a.75.75 0 0 0 1.06.053L16.5 4.44v2.81a.75.75 0 0 0 1.5 0v-4.5a.75.75 0 0 0-.75-.75h-4.5a.75.75 0 0 0 0 1.5h2.553l-9.056 8.194a.75.75 0 0 0-.053 1.06Z" clip-rule="evenodd" />
                            </svg>
                        </a>
                    </div>
                    @if($scene->title)
                        <h3 class="mt-2 text-base font-semibold text-gray-900 dark:text-gray-100">{{ $scene->title }}</h3>
                    @endif
                    <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                        @if(count($studios) > 0)
                            <dt class="text-gray-500 dark:text-gray-400">Studio</dt>
                            <dd class="text-gray-900 dark:text-gray-100">{{ implode(' – ', $studios) }}</dd>
                        @endif
                        @if($releaseDate !== '')
                            <dt class="text-gray-500 dark:text-gray-400">Released</dt>
                            <dd class="text-gray-900 dark:text-gray-100">{{ $releaseDate }}</dd>
                        @endif
                        @if($scene->code)
                            <dt class="text-gray-500 dark:text-gray-400">Code</dt>
                            <dd class="text-gray-900 dark:text-gray-100">{{ $scene->code }}</dd>
                        @endif
                    </dl>
                    @if($scene->details)
                        <p class="mt-3 text-sm whitespace-pre-line text-gray-700 dark:text-gray-300">{{ $scene->details }}</p>
                    @endif
                    @if($scene->performers->isNotEmpty())
                        <h4 class="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Performers</h4>
                        <ul class="mt-2 flex flex-wrap gap-1.5">
                            @foreach($scene->performers->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE) as $performer)
                                <li @class([
                                    'px-2 py-0.5 text-xs rounded-full',
                                    $performer->gender?->tagClasses() ?? 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
                                ])>
                                    {{ $performer->name }}
                                    @if($performer->disambiguation)
                                        <span class="opacity-75">({{ $performer->disambiguation }})</span>
                                    @endif
                                    @if($performer->pivot->as)
                                        <span class="opacity-75">as {{ $performer->pivot->as }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if($scene->tags->isNotEmpty())
                        <h4 class="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Tags</h4>
                        <ul class="mt-2 flex flex-wrap gap-1.5">
                            @foreach($scene->tags->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE) as $tag)
                                <li class="px-2 py-0.5 text-xs rounded-full bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">{{ $tag->name }}</li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endisset

            @foreach($details->sections() as $heading => $terms)
                <section class="p-5 bg-white dark:bg-gray-800 shadow-sm rounded-lg">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $heading }}</h2>
                    <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                        @foreach($terms as $term => $value)
                            <dt class="text-gray-500 dark:text-gray-400">{{ $term }}</dt>
                            <dd @class(['text-gray-900 dark:text-gray-100 break-words min-w-0', 'font-mono' => $heading === 'Fingerprints'])>{{ $value }}</dd>
                        @endforeach
                    </dl>
                </section>
            @endforeach

            @isset($extractedText)
                <details class="bg-white dark:bg-gray-800 shadow-sm rounded-lg">
                    <summary class="px-5 py-4 cursor-pointer select-none text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Extracted text</summary>
                    <p class="px-5 pb-5 text-sm whitespace-pre-wrap break-words text-gray-700 dark:text-gray-300">{{ $extractedText }}</p>
                </details>
            @endisset

            @if($file === null)
                <p class="px-1 text-sm text-gray-500 dark:text-gray-400">This file hasn't been indexed yet, so only some of its details are known.</p>
            @endif
        </aside>
    </div>
</x-app-layout>
