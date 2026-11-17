<x-app-layout>
    <x-slot name="headElements">
        @vite('resources/js/media.js')
    </x-slot>

    <x-slot name="navigation">
        @include('media.partials.navigation')
    </x-slot>

    @include('media.partials.impersonation-banner')

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
                        @if($content->kind !== \App\Media\MediaContentKind::Directory)
                            data-info-url="{{ media_info_url($path.'/'.$content->path) }}"
                        @endif
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
