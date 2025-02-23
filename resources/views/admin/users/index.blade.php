<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Manage Users') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="divide-y *:py-2 -my-2">
                    @foreach($users as $user)
                        <div class="flex items-center gap-3">
                            <div class="flex-1">{{ $user->note }}</div>
                            @empty($user->username)
                                <div>{{ __('Pending') }}</div>
                            @endempty
                            @if($user->is_admin)
                                <div>{{ __('Admin') }}</div>
                            @endif
                            <div>
                                <x-secondary-link href="{{ route('admin.users.edit', [ 'user' => $user ]) }}">
                                    {{ __('Edit') }}
                                </x-secondary-link>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <x-primary-link href="{{ route('admin.users.create') }}">
                    {{ __('Create User') }}
                </x-primary-link>
            </div>
        </div>
    </div>
</x-app-layout>
