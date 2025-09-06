<x-app-layout>
    <x-slot name="navigation">
        <div class="flex items-center whitespace-nowrap">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ __('Search') }}
            </h2>
        </div>
    </x-slot>

    <div class="py-12 max-w-2xl mx-auto sm:px-6 lg:px-8">
        @include('search.partials.search-form')
    </div>
</x-app-layout>
