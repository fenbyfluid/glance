<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('User Information') }}
        </h2>
    </header>

    <form method="post" action="{{ route('admin.users.store') }}" class="mt-6 space-y-6">
        @csrf

        <div>
            <x-input-label for="note" :value="__('Note')" />
            <x-text-input id="note" name="note" type="text" class="mt-1 block w-full" :value="old('note', '')" required autocomplete="off" />
            <x-input-error class="mt-2" :messages="$errors->get('note')" />
        </div>

        <x-checkbox-input id="is_admin" name="is_admin" :checked="old('is_admin', false)" :label="__('Admin')" :messages="$errors->get('is_admin')" />

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Create') }}</x-primary-button>
        </div>
    </form>
</section>
