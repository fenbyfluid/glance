<x-app-layout>
    <x-slot name="headElements">
        @vite('resources/js/media.js')
    </x-slot>

    <x-slot name="navigation">
        <ol class="flex items-center whitespace-nowrap">
            @foreach ($breadcrumbs as $crumb)
                @if (!$loop->last)
                    <li class="inline-flex items-center">
                        <a href="{{ route('dashboard', ['path' => $crumb->path]) }}" class="flex items-center text-md font-normal leading-5 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 focus:outline-hidden focus:text-gray-700 dark:focus:text-gray-300">
                            {{ $crumb->label }}
                            <svg class="shrink-0 size-5 text-gray-400 dark:text-grey-600" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                <path d="M6 13L10 3" stroke="currentColor" stroke-linecap="round"></path>
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
    </x-slot>

    <div>
        @foreach($contents as $group)
            <div class="my-12 sm:px-6 lg:px-8 gap-4 grid grid-cols-[repeat(auto-fill,320px)] justify-center">
                @foreach($group as $content)
                    <a {!! isset($content->thumbnail) ? 'data-fancybox="thumbnails"' : '' !!} href="{{ route('dashboard', ['path' => $path . '/' . $content->name]) }}" class="block bg-white dark:bg-gray-800 overflow-hidden shadow-xs sm:rounded-lg">
                        @isset($content->thumbnail)
                            <div class="bg-black">
                                <img class="mx-auto w-auto h-[180px]" loading="lazy" src="{{ route('dashboard', ['path' => $content->thumbnail]) }}" alt="{{ $content->name }}" {!! isset($content->mimeType) ? 'data-mime="'.htmlspecialchars($content->mimeType).'"' : '' !!} />
                            </div>
                        @else
                            <div class="p-6 text-gray-900 dark:text-gray-100">
                                {{ $content->name }}
                            </div>
                        @endisset
                    </a>
                @endforeach
            </div>
        @endforeach
    </div>
</x-app-layout>
