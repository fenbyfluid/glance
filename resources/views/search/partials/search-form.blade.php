<form x-data="{{ Js::from([ 'name' => route('search.name'), 'content' => route('search.content'), 'epoch' => 0, 'loading' => false, 'overheated' => false, 'results' => [] ]) }}" x-on:submit.prevent="performSearch" class="h-full flex flex-col">
    {{-- On compact screens (see app.css) the search box gets out of the way of the results: this wrapper becomes the
         scroller, with the box as a sticky header inside it that collapsingSearchHeader slides away while scrolling
         down and back while scrolling up (and when typing in it). The results then scroll under the box. On spacious
         screens the wrapper is a plain column with the box fixed above the results, which scroll by themselves.
         The wrapper isn't flex on compact screens, which would squash the results to fit rather than scroll them.
         Whichever element scrolls must be pointer-events-auto, see below. --}}
    <div x-data="collapsingSearchHeader" x-on:scroll="onScroll" x-on:touchstart.passive="onTouchStart" x-on:touchend="onTouchEnd" x-on:touchcancel="onTouchEnd" x-on:resize.window="onResize" class="min-h-0 spacious:flex spacious:flex-col compact:overflow-y-auto compact:overscroll-contain compact:rounded-t-lg compact:pointer-events-auto">
        <div x-ref="header" x-on:focusin="show" x-on:input="show" class="compact:sticky compact:top-0 compact:z-10">
            <label for="query" class="block sr-only">{{ __('Search Query') }}</label>
            {{-- Fixed height so the box's bottom edge lines up with the bottom of the navigation bar: the search modal's
                 mt-4 offset plus h-12 equals the nav's h-16. The input has py-0 so its own padding can't stretch it. --}}
            <div class="flex items-center h-12 rounded-t-lg bg-white dark:bg-gray-800 px-2 sm:px-4 shadow-sm has-[input:focus-within]:outline-2 has-[input:focus-within]:-outline-offset-2 has-[input:focus-within]:outline-indigo-600 pointer-events-auto">
                <svg x-show="!loading" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-6 shrink-0 text-base text-gray-400 select-none sm:text-sm/6">
                    <path fill-rule="evenodd" d="M10.5 3.75a6.75 6.75 0 1 0 0 13.5 6.75 6.75 0 0 0 0-13.5ZM2.25 10.5a8.25 8.25 0 1 1 14.59 5.28l4.69 4.69a.75.75 0 1 1-1.06 1.06l-4.69-4.69A8.25 8.25 0 0 1 2.25 10.5Z" clip-rule="evenodd" />
                </svg>
                <svg x-show="loading" style="display: none" role="status" aria-label="{{ __('Searching…') }}" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="size-6 shrink-0 text-indigo-600 dark:text-indigo-400 select-none animate-spin">
                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2.5" class="opacity-25" />
                    <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
                </svg>
                <input id="query" type="text" name="query" placeholder="" autofocus x-on:input.debounce="$event.target.form.requestSubmit()" class="block rounded-lg min-w-0 grow pl-2 py-0 text-base text-gray-900 dark:text-gray-100 dark:bg-gray-800 placeholder:text-gray-400 dark:placeholder:text-gray-500 focus:ring-0 sm:text-sm/6 border-none" />
            </div>
            <div x-on:change="$event.target.form.requestSubmit()" class="p-2 sm:px-4 bg-white dark:bg-gray-800 rounded-b-lg border-t border-gray-300 dark:border-gray-700 shadow-sm flex justify-between gap-4 sm:gap-8 pointer-events-auto">
                <div class="flex flex-col gap-1">
                    <div class="flex items-center gap-2">
                        <input id="index-name" type="radio" name="index" value="name" checked class="text-indigo-600 focus:ring-indigo-600 dark:bg-gray-900 dark:border-gray-600 dark:checked:bg-current dark:checked:border-transparent dark:focus:ring-offset-gray-800" x-data x-on:change="onSearchIndexChange">
                        <label for="index-name" class="grow-1">{{ __('File Names') }}</label>
                    </div>
                    <div class="flex items-center gap-2">
                        <input id="index-content" type="radio" name="index" value="content" class="text-indigo-600 focus:ring-indigo-600 dark:bg-gray-900 dark:border-gray-600 dark:checked:bg-current dark:checked:border-transparent dark:focus:ring-offset-gray-800" x-data x-on:change="onSearchIndexChange">
                        <label for="index-content" class="grow-1">{{ __('Text Content') }}</label>
                    </div>
                    <div class="flex items-center gap-2">
                        <input id="path" type="checkbox" name="path" value="{{ $path ?? "" }}" @disabled(!isset($path)) @checked(isset($path)) class="peer text-indigo-600 focus:ring-indigo-600 disabled:border-gray-300 disabled:text-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:checked:bg-current dark:checked:border-transparent dark:focus:ring-offset-gray-800 dark:disabled:bg-gray-800 dark:disabled:border-gray-700 dark:disabled:text-gray-700 dark:disabled:checked:bg-current">
                        <label for="path" class="grow-1 peer-disabled:text-gray-400 dark:peer-disabled:text-gray-500">{{ __('Current Directory') }}</label>
                    </div>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-1 gap-x-4">
                    @foreach(\App\Media\MediaContentKind::cases() as $kind)
                        <div class="flex items-center gap-2">
                            <input id="kind-{{ $kind->value }}" type="checkbox" name="kind[]" value="{{ $kind->value }}" data-content-searchable="{{ (\App\Jobs\ExtractTextJob::canExtractText($kind) || $kind === \App\Media\MediaContentKind::Video) ? 1 : 0 }}" class="peer text-indigo-600 focus:ring-indigo-600 disabled:border-gray-300 disabled:text-gray-300 dark:bg-gray-900 dark:border-gray-600 dark:checked:bg-current dark:checked:border-transparent dark:focus:ring-offset-gray-800 dark:disabled:bg-gray-800 dark:disabled:border-gray-700 dark:disabled:text-gray-700 dark:disabled:checked:bg-current">
                            <label for="kind-{{ $kind->value }}" class="grow-1 peer-disabled:text-gray-400 dark:peer-disabled:text-gray-500">{{ __($kind->displayName()) }}</label>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- The scroller must be pointer-events-auto: WebKit's async scrolling ignores scroll containers with
             pointer-events: none (inherited from the search modal wrapper), even when their children accept events,
             so results became unscrollable in Safari (stuck after a few seconds on macOS, not at all on iOS). It's
             sized to its content (min-h-0 lets it shrink to fit) rather than growing to fill the modal, so the empty
             area below the results doesn't swallow clicks meant for the backdrop. --}}
        <div class="mt-3 min-h-0 spacious:overflow-y-auto spacious:overscroll-contain rounded-t-lg pointer-events-auto transition-opacity" :class="loading && 'opacity-60'">
            <div class="space-y-3 pb-(--search-overscan)">
                <template x-for="result in results" :key="result.link">
                    <a :href="result.link" class="p-2 sm:p-4 bg-white dark:bg-gray-800 shadow-sm rounded-lg flex gap-2 sm:gap-3 pointer-events-auto">
                        <div class="shrink-0 grow-0 overflow-hidden w-[160px] h-[90px] rounded-sm bg-black flex justify-center items-center">
                            <template x-if="result.thumbnail">
                                <img loading="lazy" :src="`/_image/${result.thumbnail}.avif`" class="max-w-full max-h-full" />
                            </template>
                            @foreach(\App\Media\MediaContentKind::cases() as $kind)
                                <template x-if="!result.thumbnail && result.kind == '{{ $kind->value }}'">
                                    <div class="text-white flex gap-1">
                                        {!! $kind->displayIcon() !!}
                                        <span>{{ __($kind->displayName()) }}</span>
                                    </div>
                                </template>
                            @endforeach
                        </div>
                        <div class="flex flex-col overflow-hidden">
                            <span x-text="result.name" class="overflow-hidden overflow-ellipsis whitespace-nowrap"></span>
                            <span x-text="result.path + '/'" class="overflow-hidden overflow-ellipsis whitespace-nowrap text-sm text-gray-500 dark:text-gray-400"></span>
                            <span x-text="result.text ?? ''" class="overflow-hidden overflow-ellipsis line-clamp-2 pt-0.5 leading-5.5"></span>
                        </div>
                    </a>
                </template>

                <template x-if="overheated">
                    <div class="p-2 sm:p-4 bg-white dark:bg-gray-800 shadow-sm rounded-lg text-center pointer-events-auto">
                        Search overheated, results may be incomplete
                        <div class="text-sm text-gray-500 dark:text-gray-400">
                            Try a more specific search query if you have not found what you're looking for
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</form>
