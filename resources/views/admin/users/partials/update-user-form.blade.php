<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('User Information') }}
        </h2>
    </header>

    <form method="post" action="{{ route('admin.users.update', ['user' => $user]) }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="note" :value="__('Note')" />
            <x-text-input id="note" name="note" type="text" class="mt-1 block w-full" :value="old('note', $user->note)" required autocomplete="off" />
            <x-input-error class="mt-2" :messages="$errors->get('note')" />
        </div>

        <x-checkbox-input id="is_admin" name="is_admin" :checked="old('is_admin', $user->is_admin)" :label="__('Admin')" :messages="$errors->get('is_admin')" />

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Save') }}</x-primary-button>

            @if (session('status') === 'user-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-gray-600 dark:text-gray-400"
                >{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>
