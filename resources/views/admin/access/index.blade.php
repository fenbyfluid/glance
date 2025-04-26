<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Manage Access') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
                <form method="post" action="{{ route('admin.access.store') }}" class="space-y-6">
                    @csrf

                    <div class="grid grid-flow-row gap-x-4 divide-y *:py-2 mt-12" style="grid-template-columns: 1fr repeat({{ 2 + count($users) }}, min-content);">
                        <div class="col-span-full grid grid-cols-subgrid grid-flow-col items-center border-gray-200">
                            <div></div>
                            @foreach($users as $user)
                                <div class="relative">
                                    <div class="absolute -top-6 left-3 origin-bottom-left -rotate-45">{{ $user->note }}</div>
                                </div>
                            @endforeach
                        </div>
                        @foreach($entries as $entry)
                            <div class="col-span-full grid grid-cols-subgrid grid-flow-col items-center border-gray-200">
                                <div class="px-3">/{{ $entry->path }}</div>
                                @foreach($users as $user)
                                    <x-checkbox-input
                                        id="allow_{{ $entry->id }}_{{ $user->id }}"
                                        name="allow[{{ $entry->id }}_{{ $user->id }}]"
                                        :checked="old('allow.'.$entry->id.'_'.$user->id, isset($allows[$entry->id.'_'.$user->id]))"
                                    />
                                @endforeach
                                <x-danger-icon-button
                                    x-data=""
                                    x-on:click.prevent="$dispatch('open-modal', { name: 'confirm-access-deletion', data: { path: '/{{ $entry->path }}', route: '{{ route('admin.access.destroy', ['access' => $entry]) }}' } })"
                                    type="button"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="size-4">
                                        <path fill-rule="evenodd" d="M5 3.25V4H2.75a.75.75 0 0 0 0 1.5h.3l.815 8.15A1.5 1.5 0 0 0 5.357 15h5.285a1.5 1.5 0 0 0 1.493-1.35l.815-8.15h.3a.75.75 0 0 0 0-1.5H11v-.75A2.25 2.25 0 0 0 8.75 1h-1.5A2.25 2.25 0 0 0 5 3.25Zm2.25-.75a.75.75 0 0 0-.75.75V4h3v-.75a.75.75 0 0 0-.75-.75h-1.5ZM6.05 6a.75.75 0 0 1 .787.713l.275 5.5a.75.75 0 0 1-1.498.075l-.275-5.5A.75.75 0 0 1 6.05 6Zm3.9 0a.75.75 0 0 1 .712.787l-.275 5.5a.75.75 0 0 1-1.498-.075l.275-5.5a.75.75 0 0 1 .786-.711Z" clip-rule="evenodd" />
                                    </svg>
                                </x-danger-icon-button>
                            </div>
                        @endforeach
                        {{-- TODO: It would be nice to use Alpine to create an additional new row the first time the text input is typed into --}}
                        <div class="col-span-full grid grid-cols-subgrid grid-flow-col items-center border-gray-200">
                            <x-text-input id="path_new" name="path_new" type="text" class="block w-full" :value="transform(old('path_new', ''), fn($s) => '/'.$s)" autocomplete="off" />
                            @foreach($users as $user)
                                <x-checkbox-input
                                    id="allow_new_{{ $user->id }}"
                                    name="allow[new_{{ $user->id }}]"
                                    :checked="old('allow.new_'.$user->id, false)"
                                />
                            @endforeach
                            {{--
                            <x-primary-icon-button
                                x-data=""
                                x-on:click.prevent=""
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="size-4">
                                    <path d="M8.75 3.75a.75.75 0 0 0-1.5 0v3.5h-3.5a.75.75 0 0 0 0 1.5h3.5v3.5a.75.75 0 0 0 1.5 0v-3.5h3.5a.75.75 0 0 0 0-1.5h-3.5v-3.5Z" />
                                </svg>
                            </x-primary-icon-button>
                            <x-danger-icon-button
                                x-data=""
                                x-on:click.prevent=""
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="size-4">
                                    <path d="M3.75 7.25a.75.75 0 0 0 0 1.5h8.5a.75.75 0 0 0 0-1.5h-8.5Z" />
                                </svg>
                            </x-danger-icon-button>
                            --}}
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>{{ __('Save') }}</x-primary-button>

                        @if (session('status') === 'access-updated')
                            <p
                                x-data="{ show: true }"
                                x-show="show"
                                x-transition
                                x-init="setTimeout(() => show = false, 2000)"
                                class="text-sm text-gray-600 dark:text-gray-400"
                            >{{ __('Saved.') }}</p>
                        @else
                            <x-input-error :messages="$errors->get('path_new')" />
                        @endif
                    </div>
                </form>

                <x-modal name="confirm-access-deletion" :show="$errors->accessDeletion->isNotEmpty()" focusable>
                    <form method="post" :action="data?.route" class="p-6">
                        @csrf
                        @method('delete')

                        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                            {{ __('Are you sure you want to delete this rule?') }}
                        </h2>

                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            {{ __('Save any other changes to access rules before continuing.') }}
                        </p>

                        <p x-text="data?.path" class="mt-3 text-gray-600 dark:text-gray-400"></p>

                        <div class="mt-6 flex justify-end">
                            <x-secondary-button x-on:click="$dispatch('close')">
                                {{ __('Cancel') }}
                            </x-secondary-button>

                            <x-danger-button class="ms-3">
                                {{ __('Delete') }}
                            </x-danger-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </div>
    </div>
</x-app-layout>
