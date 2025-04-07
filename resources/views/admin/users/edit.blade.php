<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Edit User') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @isset($invite_url)
                <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
                    <a href="{{ $invite_url }}">{{ $invite_url }}</a>
                </div>
            @endisset

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
                <div class="max-w-xl">
                    @include('admin.users.partials.update-user-form')
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
                <div class="max-w-xl">
                    @include('admin.users.partials.delete-user-form')
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
