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
                        {{ __('There are no active links. Create one from the share button on a file or directory.') }}
                    </p>
                @else
                    <div class="divide-y divide-gray-200 dark:divide-gray-700 *:py-3 -my-3">
                        @foreach($links as $link)
                            @php
                                // Names can be long, so the name and the directory it's in get a line each (the root
                                // isn't in one).
                                $directory = str_contains($link->path, '/') ? dirname($link->path) : '';

                                // Listed links haven't expired or been revoked, but can still have stopped working.
                                $unusableReason = $link->getUnusableReason();
                            @endphp
                            <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4">
                                <div class="flex-1 min-w-0">
                                    <a href="{{ $link->is_directory ? media_url($link->path.'/') : media_info_url($link->path) }}" class="flex items-center gap-1.5 font-medium text-gray-900 dark:text-gray-100 hover:underline" title="{{ $link->getName() }}">
                                        @if($link->is_directory)
                                            <svg class="size-4 shrink-0 text-gray-400 dark:text-gray-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" role="img" aria-label="{{ __('Directory') }}">
                                                <path d="M3.75 3A1.75 1.75 0 0 0 2 4.75v3.26a3.235 3.235 0 0 1 1.75-.51h12.5c.644 0 1.245.188 1.75.51V6.75A1.75 1.75 0 0 0 16.25 5h-4.836a.25.25 0 0 1-.177-.073L9.823 3.513A1.75 1.75 0 0 0 8.586 3H3.75ZM3.75 9A1.75 1.75 0 0 0 2 10.75v4.5c0 .966.784 1.75 1.75 1.75h12.5A1.75 1.75 0 0 0 18 15.25v-4.5A1.75 1.75 0 0 0 16.25 9H3.75Z" />
                                            </svg>
                                        @endif
                                        <span class="truncate">{{ $link->getName() }}</span>
                                    </a>
                                    @if($link->path !== '')
                                        <a href="{{ media_url($directory.'/') }}" class="block truncate text-sm text-gray-500 dark:text-gray-400 hover:underline" title="/{{ $directory }}">
                                            /{{ $directory }}
                                        </a>
                                    @endif
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
