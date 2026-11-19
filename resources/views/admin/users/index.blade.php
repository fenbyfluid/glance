<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Manage Users') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
                <div class="divide-y *:py-2 -my-2">
                    @foreach($users as $user)
                        <div class="flex items-center gap-3 border-gray-200 dark:border-gray-700">
                            <div class="flex-1 min-w-0 truncate">{{ $user->note }}</div>
                            {{-- One fixed-width slot per status, empty when it doesn't apply, so the icons line up
                                 in columns down the list. --}}
                            <div class="flex items-center gap-2 text-gray-500 dark:text-gray-400">
                                <span class="size-5">
                                    @if($user->is_admin)
                                        <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" role="img" aria-label="{{ __('Admin') }}">
                                            <title>{{ __('Admin') }}</title>
                                            <path fill-rule="evenodd" d="M9.661 2.237a.531.531 0 0 1 .678 0 11.947 11.947 0 0 0 7.078 2.749.5.5 0 0 1 .479.425c.069.52.104 1.05.104 1.59 0 5.162-3.26 9.563-7.834 11.256a.48.48 0 0 1-.332 0C5.26 16.564 2 12.163 2 7c0-.538.035-1.069.104-1.589a.5.5 0 0 1 .48-.425 11.947 11.947 0 0 0 7.077-2.75Zm4.196 5.954a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd" />
                                        </svg>
                                    @endif
                                </span>
                                <span class="size-5">
                                    @if($user->can_share)
                                        <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" role="img" aria-label="{{ __('Can create public links') }}">
                                            <title>{{ __('Can create public links') }}</title>
                                            <path d="M12.232 4.232a2.5 2.5 0 0 1 3.536 3.536l-1.225 1.224a.75.75 0 0 0 1.061 1.06l1.224-1.224a4 4 0 0 0-5.656-5.656l-3 3a4 4 0 0 0 .225 5.865.75.75 0 0 0 .977-1.138 2.5 2.5 0 0 1-.142-3.667l3-3Z" />
                                            <path d="M11.603 7.963a.75.75 0 0 0-.977 1.138 2.5 2.5 0 0 1 .142 3.667l-3 3a2.5 2.5 0 0 1-3.536-3.536l1.225-1.224a.75.75 0 0 0-1.061-1.06l-1.224 1.224a4 4 0 1 0 5.656 5.656l3-3a4 4 0 0 0-.225-5.865Z" />
                                        </svg>
                                    @endif
                                </span>
                                <span class="size-5">
                                    @empty($user->username)
                                        <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" role="img" aria-label="{{ __('Pending') }}">
                                            <title>{{ __('Pending: hasn\'t accepted their invite yet') }}</title>
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm.75-13a.75.75 0 0 0-1.5 0v5c0 .414.336.75.75.75h4a.75.75 0 0 0 0-1.5h-3.25V5Z" clip-rule="evenodd" />
                                        </svg>
                                    @endempty
                                </span>
                            </div>
                            <div class="flex items-center gap-2">
                                {{-- Browses as the user, for checking what their access rules let them see. --}}
                                <form method="post" action="{{ route('admin.users.impersonate', ['user' => $user]) }}">
                                    @csrf
                                    <x-secondary-button type="submit">{{ __('Test Permissions') }}</x-secondary-button>
                                </form>
                                <x-primary-link href="{{ route('admin.users.edit', [ 'user' => $user ]) }}">
                                    {{ __('Edit') }}
                                </x-primary-link>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
                <x-primary-link href="{{ route('admin.users.create') }}">
                    {{ __('Create User') }}
                </x-primary-link>
            </div>
        </div>
    </div>
</x-app-layout>
