<section class="space-y-6">
    <div class="space-x-3">
        <x-danger-button
            x-data=""
            x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')"
        >{{ __('Delete User') }}</x-danger-button>

        <form method="post" action="{{ route('admin.users.impersonate', ['user' => $user]) }}" class="inline">
            @csrf
            <x-secondary-button type="submit">{{ __('Test Permissions') }}</x-secondary-button>
        </form>
    </div>
</section>

<x-modal name="confirm-user-deletion" :show="$errors->userDeletion->isNotEmpty()" focusable>
    <form method="post" action="{{ route('admin.users.destroy', ['user' => $user]) }}" class="p-6">
        @csrf
        @method('delete')

        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('Are you sure you want to delete this user?') }}
        </h2>

        <div class="mt-6 flex justify-end">
            <x-secondary-button x-on:click="$dispatch('close')">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-danger-button class="ms-3">
                {{ __('Delete User') }}
            </x-danger-button>
        </div>
    </form>
</x-modal>
