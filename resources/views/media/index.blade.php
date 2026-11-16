<x-app-layout>
    <x-slot name="headElements">
        @vite('resources/js/media.js')
    </x-slot>

    <x-slot name="navigation">
        <div class="space-x-8 ms-4 sm:ms-10 flex grow">
            <ol class="grow flex items-center whitespace-nowrap">
                <a href="{{ media_url($path.'/../') }}">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="shrink-0 size-4 text-gray-400 dark:text-gray-600" aria-label="Parent Directory" >
                        <path fill-rule="evenodd" d="M14 13.25a.75.75 0 0 0-.75-.75h-6.5V4.56l.97.97a.75.75 0 0 0 1.06-1.06L6.53 2.22a.75.75 0 0 0-1.06 0L3.22 4.47a.75.75 0 0 0 1.06 1.06l.97-.97v8.69c0 .414.336.75.75.75h7.25a.75.75 0 0 0 .75-.75Z" clip-rule="evenodd" />
                    </svg>
                </a>
                @foreach ($breadcrumbs as $crumb)
                    @if (!$loop->last)
                        <li class="hidden sm:inline-flex items-center">
                            <a href="{{ $crumb->path }}" class="flex items-center text-md font-normal leading-5 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 focus:outline-hidden focus:text-gray-700 dark:focus:text-gray-300">
                                {{ $crumb->label }}
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="shrink-0 size-5 text-gray-400 dark:text-gray-600">
                                    <path fill-rule="evenodd" d="M10.074 2.047a.75.75 0 0 1 .449.961L6.705 13.507a.75.75 0 0 1-1.41-.513L9.113 2.496a.75.75 0 0 1 .961-.449Z" clip-rule="evenodd" />
                                </svg>
                            </a>
                        </li>
                    @else
                        <li class="hidden sm:inline-flex items-center text-md truncate font-normal leading-5 text-gray-900 dark:text-gray-100 focus:outline-hidden" aria-current="page">
                            {{ $crumb->label }}
                        </li>
                    @endif
                @endforeach
            </ol>
            <div x-data class="flex items-center shrink-0">
                <a href="#" x-on:click.prevent="$store.searchModal.toggle(); $store.searchModal.open && $nextTick(() => document.getElementById('query').focus())" class="size-6 shrink-0 text-base text-gray-400 select-none sm:text-sm/6">
                    <svg x-show="!$store.searchModal.open" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                        <path fill-rule="evenodd" d="M10.5 3.75a6.75 6.75 0 1 0 0 13.5 6.75 6.75 0 0 0 0-13.5ZM2.25 10.5a8.25 8.25 0 1 1 14.59 5.28l4.69 4.69a.75.75 0 1 1-1.06 1.06l-4.69-4.69A8.25 8.25 0 0 1 2.25 10.5Z" clip-rule="evenodd" />
                    </svg>
                    <svg x-show="$store.searchModal.open" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                        <path fill-rule="evenodd" d="M5.47 5.47a.75.75 0 0 1 1.06 0L12 10.94l5.47-5.47a.75.75 0 1 1 1.06 1.06L13.06 12l5.47 5.47a.75.75 0 1 1-1.06 1.06L12 13.06l-5.47 5.47a.75.75 0 0 1-1.06-1.06L10.94 12 5.47 6.53a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                    </svg>
                </a>
                {{-- Safari fills the area behind its toolbar with a solid colour while a fixed element reaches the
                     bottom of the screen, which hid the search results there. So the overlay isn't fixed: it's moved to
                     the body and placed over the viewport at the current scroll position (the page doesn't scroll while
                     search is open, see layouts.app). Only the top half of the backdrop is fixed, which Safari tints its
                     status bar from. Sizes come from innerHeight, as the CSS viewport units didn't match each other or
                     the visible area on iOS 26, leaving undimmed gaps. innerHeight stops at the top of Safari's toolbar,
                     so the overlay overshoots it by --search-overscan (past the bottom of the screen, where it isn't
                     seen), and the results get the same bottom padding so the last one still scrolls clear of the
                     toolbar. The backdrop overshoots the top too, where the page shows through the status bar once
                     Safari collapses its toolbar on scrolling. --}}
                <template x-teleport="body">
                    <div class="contents">
                        <template x-if="$store.searchModal.open">
                            <div x-data="{ top: window.scrollY, height: window.innerHeight, overscan: 120 }" x-on:resize.window="height = window.innerHeight" x-bind:style="{ top: top + 'px', height: (height + overscan) + 'px', '--search-overscan': overscan + 'px' }" class="absolute left-0 right-0 z-10 flex flex-col items-center overflow-y-hidden pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)] safe-area-x">
                                <div x-on:click.prevent="$store.searchModal.open = false" x-bind:style="{ top: -overscan + 'px', height: (height / 2 + overscan) + 'px' }" class="fixed inset-x-0 bg-black/72"></div>
                                <div x-on:click.prevent="$store.searchModal.open = false" x-bind:style="{ top: (height / 2) + 'px' }" class="absolute inset-x-0 bottom-0 bg-black/72"></div>
                                <div class="px-4 w-full sm:w-xl mt-4 z-10 overflow-y-hidden pointer-events-none">
                                    @include('search.partials.search-form')
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </div>
    </x-slot>

    @isset($accessControlOverrideUser)
        <div class="mt-2 bg-red-700 text-white shadow-sm">
            <form method="post" action="{{ route('admin.users.impersonate', ['user' => null]) }}" class="max-w-7xl mx-auto py-2 px-4 sm:px-6 lg:px-8 flex justify-between items-center">
                @csrf
                <div>
                    Browsing as
                    <span class="font-bold">{{ $accessControlOverrideUser->note }}</span>
                </div>
                <x-danger-button>
                    Stop Testing
                </x-danger-button>
            </form>
        </div>
    @endisset

    <div class="my-8 mx-4 sm:mx-6 lg:mx-8 empty:hidden">{{ $contents->links('media.pagination') }}</div>

    <div>
        @foreach($contents as $group)
            <div class="my-8 sm:px-6 lg:px-8 gap-4 grid grid-cols-[repeat(auto-fill,320px)] justify-center">
                @foreach($group as $content)
                    <a
                        href="{{ media_url($path.'/'.$content->path) }}"
                        class="block bg-white dark:bg-gray-800 overflow-hidden shadow-sm rounded-lg"
                        {!! $content->kind->lightboxType() ? 'data-fancybox="file" data-type="'.htmlspecialchars($content->kind->lightboxType()).'"' : '' !!}
                        {!! $content->kind ? 'data-kind="'.htmlspecialchars($content->kind->value).'"' : '' !!}
                        {!! isset($content->mimeType) ? 'data-mime="'.htmlspecialchars($content->mimeType).'"' : '' !!}
                    >
                        <div class="px-5 py-4 truncate text-gray-900 dark:text-gray-100">
                            {{ $content->label }}
                            @if($content->kind === \App\Media\MediaContentKind::Directory)
                                {{-- TODO: De-dupe these icons (this is the slash from above) --}}
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="inline align-text-top size-5 text-gray-400 dark:text-gray-600">
                                    <path fill-rule="evenodd" d="M10.074 2.047a.75.75 0 0 1 .449.961L6.705 13.507a.75.75 0 0 1-1.41-.513L9.113 2.496a.75.75 0 0 1 .961-.449Z" clip-rule="evenodd" />
                                </svg>
                            @endif
                        </div>
                        @if($content->kind->canThumbnail())
                            <div class="bg-black relative overflow-hidden h-[180px] flex justify-center items-center">
                                <img
                                    loading="lazy"
                                    src="{{ $content->thumbnail ? route('media.image', ['id' => $content->thumbnail], false) : '' }}"
                                    alt="{{ $content->label }}"
                                    {!! $content->filmstrip ? 'data-filmstrip="'.htmlspecialchars(route('media.image', ['id' => $content->filmstrip], false)).'"' : '' !!}
                                />
                            </div>
                        @endif
                    </a>
                @endforeach
            </div>
        @endforeach
    </div>

    <div class="my-8 mx-4 sm:mx-6 lg:mx-8 empty:hidden">{{ $contents->links('media.pagination') }}</div>

    @isset($readme)
        {{-- TODO: Can we do anything better with security here? Maybe Markdown? --}}
        <div class="my-8 sm:mx-6 lg:mx-8 p-6 bg-white dark:bg-gray-800 shadow-sm rounded-lg">
            <div class="mx-auto prose dark:prose-invert">
                {!! $readme !!}
            </div>
        </div>
    @endisset
</x-app-layout>
