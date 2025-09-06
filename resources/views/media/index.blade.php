<x-app-layout>
    <x-slot name="headElements">
        @vite('resources/js/media.js')
    </x-slot>

    <x-slot name="navigation">
        <ol class="grow flex items-center whitespace-nowrap">
            <a href="{{ media_url($path.'/../') }}">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="shrink-0 size-4 text-gray-400 dark:text-grey-600" aria-label="Parent Directory" >
                    <path fill-rule="evenodd" d="M14 13.25a.75.75 0 0 0-.75-.75h-6.5V4.56l.97.97a.75.75 0 0 0 1.06-1.06L6.53 2.22a.75.75 0 0 0-1.06 0L3.22 4.47a.75.75 0 0 0 1.06 1.06l.97-.97v8.69c0 .414.336.75.75.75h7.25a.75.75 0 0 0 .75-.75Z" clip-rule="evenodd" />
                </svg>
            </a>
            @foreach ($breadcrumbs as $crumb)
                @if (!$loop->last)
                    <li class="inline-flex items-center">
                        <a href="{{ $crumb->path }}" class="flex items-center text-md font-normal leading-5 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 focus:outline-hidden focus:text-gray-700 dark:focus:text-gray-300">
                            {{ $crumb->label }}
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="shrink-0 size-5 text-gray-400 dark:text-grey-600">
                                <path fill-rule="evenodd" d="M10.074 2.047a.75.75 0 0 1 .449.961L6.705 13.507a.75.75 0 0 1-1.41-.513L9.113 2.496a.75.75 0 0 1 .961-.449Z" clip-rule="evenodd" />
                            </svg>
                        </a>
                    </li>
                @else
                    <li class="inline-flex items-center text-md truncate font-normal leading-5 text-gray-900 dark:text-gray-100 focus:outline-hidden" aria-current="page">
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
            <template x-if="$store.searchModal.open">
                <div class="z-10">
                    <div x-on:click.prevent="$store.searchModal.open = false" class="fixed top-0 left-0 right-0 bottom-0 bg-gray-900 opacity-20"></div>
                    <div class="fixed w-xl top-1 left-1/2 -ml-72 h-[calc(100vh_-_var(--spacing))] overflow-y-hidden flex flex-col pointer-events-none">
                        @include('search.partials.search-form')
                    </div>
                </div>
            </template>
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

    <div>
        @foreach($contents as $group)
            <div class="my-8 sm:px-6 lg:px-8 gap-4 grid grid-cols-[repeat(auto-fill,320px)] justify-center">
                @foreach($group as $content)
                    <a
                        href="{{ media_url($path.'/'.$content->path) }}"
                        class="block bg-white dark:bg-gray-800 overflow-hidden shadow-xs sm:rounded-lg"
                        {!! $content->kind->lightboxType() ? 'data-fancybox="file" data-type="'.htmlspecialchars($content->kind->lightboxType()).'"' : '' !!}
                        {!! $content->kind ? 'data-kind="'.htmlspecialchars($content->kind->value).'"' : '' !!}
                        {!! isset($content->mimeType) ? 'data-mime="'.htmlspecialchars($content->mimeType).'"' : '' !!}
                    >
                        <div class="px-5 py-4 truncate text-gray-900 dark:text-gray-100">
                            {{ $content->label }}
                            @if($content->kind === \App\Media\MediaContentKind::Directory)
                                {{-- TODO: De-dupe these icons (this is the slash from above) --}}
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="inline align-text-top size-5 text-gray-400 dark:text-grey-600">
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

    @isset($readme)
        {{-- TODO: Can we do anything better with security here? Maybe Markdown? --}}
        <div class="my-12 sm:mx-6 lg:mx-8 p-6 bg-white dark:bg-gray-800 shadow-xs sm:rounded-lg">
            <div class="mx-auto prose dark:prose-invert">
                {!! $readme !!}
            </div>
        </div>
    @endisset
</x-app-layout>
