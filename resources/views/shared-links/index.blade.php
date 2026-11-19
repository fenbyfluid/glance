<x-app-layout>
    <x-slot name="title">{{ $heading }}</x-slot>

    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ $heading }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
                @if(session('status') === 'link-revoked')
                    <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">{{ __('The link has been revoked.') }}</p>
                @endif

                @if($links->isEmpty())
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ __('There are no active links. Create one from the share button on a file.') }}
                    </p>
                @else
                    <div class="divide-y divide-gray-200 dark:divide-gray-700 *:py-3 -my-3">
                        @foreach($links as $link)
                            @php
                                // File names can be long, so the name and the directory it's in get a line each.
                                $directory = str_contains($link->path, '/') ? dirname($link->path) : '';

                                // Listed links haven't expired or been revoked, but can still have stopped working.
                                $unusableReason = $link->getUnusableReason();
                            @endphp
                            <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4">
                                <div class="flex-1 min-w-0">
                                    <a href="{{ media_info_url($link->path) }}" class="block truncate font-medium text-gray-900 dark:text-gray-100 hover:underline" title="{{ basename($link->path) }}">
                                        {{ basename($link->path) }}
                                    </a>
                                    <a href="{{ media_url($directory.'/') }}" class="block truncate text-sm text-gray-500 dark:text-gray-400 hover:underline" title="/{{ $directory }}">
                                        /{{ $directory }}
                                    </a>
                                    <div class="mt-0.5 text-sm text-gray-500 dark:text-gray-400 flex flex-wrap gap-x-3">
                                        @if($link->note)
                                            <span class="text-gray-700 dark:text-gray-300">{{ $link->note }}</span>
                                        @endif
                                        @if($showCreator)
                                            <span>{{ __('by :name', ['name' => $link->user->note ?: $link->user->username]) }}</span>
                                        @endif
                                        <span title="{{ $link->created_at->toDayDateTimeString() }}">{{ __('created :when', ['when' => $link->created_at->diffForHumans()]) }}</span>
                                        <span title="{{ $link->expires_at->toDayDateTimeString() }}">{{ __('expires :when', ['when' => $link->expires_at->diffForHumans()]) }}</span>
                                    </div>
                                    @if($unusableReason !== null)
                                        <p class="mt-1 flex items-center gap-1.5 text-sm font-medium text-amber-700 dark:text-amber-400">
                                            <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd" />
                                            </svg>
                                            {{ __('Not working: :reason', ['reason' => __($unusableReason)]) }}
                                        </p>
                                    @endif
                                </div>
                                {{-- Stretched, so the button matches the field's height. --}}
                                <div class="flex items-stretch gap-2 shrink-0">
                                    <x-copy-input :value="$link->url()" :label="__('Public link')" class="grow sm:grow-0 sm:w-72" />
                                    <form method="POST" action="{{ route('shared-links.destroy', ['sharedLink' => $link]) }}" class="flex" onsubmit="return confirm(@js(__('Revoke this link? Anyone using it will lose access.')))">
                                        @csrf
                                        @method('DELETE')
                                        <x-danger-icon-button class="px-2" title="{{ __('Revoke link') }}" aria-label="{{ __('Revoke link') }}">
                                            @include('shared-links.partials.trash-icon')
                                        </x-danger-icon-button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
