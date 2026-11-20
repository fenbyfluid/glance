{{-- A native modal dialog, so it sits in the top layer above the lightbox. Keydowns are kept from reaching the
     lightbox's handlers on the document, which would otherwise take Tab focus back and close on Escape (see share.js). --}}
<dialog
    x-data="shareDialog(@js(route('shared-links.index')), @js((string) \App\Models\SharedLink::DEFAULT_EXPIRY_DAYS), @js(Auth::user()->can('create', \App\Models\SharedLink::class)))"
    x-on:open-share-dialog.window="open($event.detail)"
    x-on:keydown.stop
    x-on:click.self="$root.close()"
    class="m-auto w-[calc(100%-2rem)] max-w-xl p-0 rounded-lg shadow-xl bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 backdrop:bg-black/70"
    aria-labelledby="share-dialog-title"
>
    <div class="p-5 sm:p-6 space-y-6">
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <h2 id="share-dialog-title" class="text-lg font-semibold">{{ __('Share') }}</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 truncate" x-text="name"></p>
            </div>
            <button type="button" x-on:click="$root.close()" class="shrink-0 p-1 -m-1 rounded-md text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 cursor-pointer" aria-label="{{ __('Close') }}">
                <svg class="size-6" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M5.47 5.47a.75.75 0 0 1 1.06 0L12 10.94l5.47-5.47a.75.75 0 1 1 1.06 1.06L13.06 12l5.47 5.47a.75.75 0 1 1-1.06 1.06L12 13.06l-5.47 5.47a.75.75 0 0 1-1.06-1.06L10.94 12 5.47 6.53a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                </svg>
            </button>
        </div>

        <section>
            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Link for people with access') }}</h3>
            <x-copy-input expression="internalUrl" :label="__('Link for people with access')" class="mt-2" />
        </section>

        {{-- Public links need the permission to share (users.can_share); without it there's only the link above. --}}
        @can('create', \App\Models\SharedLink::class)
        <section>
            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Public links') }}</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Anyone with a public link can see what\'s shared (for a directory, everything in it) until the link expires or is revoked.') }}</p>

            <template x-if="error">
                <p class="mt-3 text-sm text-red-600 dark:text-red-400" x-text="error"></p>
            </template>

            <template x-if="loading">
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('Loading…') }}</p>
            </template>

            <template x-if="!loading && links.length === 0">
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('There are no public links to this yet.') }}</p>
            </template>

            <ul class="mt-3 space-y-3">
                <template x-for="link in links" x-bind:key="link.id">
                    <li class="p-3 rounded-md border border-gray-200 dark:border-gray-700 space-y-2">
                        <x-copy-input expression="link.url" :label="__('Public link')" />
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                            <x-text-input type="text" maxlength="255" x-model="link.note" x-on:change="saveNote(link)" x-on:keydown.enter.prevent="$el.blur()" placeholder="{{ __('Note') }}" class="flex-1 min-w-40 text-sm py-1" aria-label="{{ __('Note') }}" />
                            <span class="text-sm text-gray-500 dark:text-gray-400" x-bind:title="new Date(link.expiresAt).toLocaleString()" x-text="@js(__('Expires')) + ' ' + formatRelative(link.expiresAt)"></span>
                            <template x-if="link.creator">
                                <span class="text-sm text-gray-500 dark:text-gray-400" x-text="@js(__('by')) + ' ' + link.creator"></span>
                            </template>
                            <x-danger-icon-button type="button" x-on:click="revoke(link)" class="p-1" title="{{ __('Revoke link') }}" aria-label="{{ __('Revoke link') }}">
                                @include('shared-links.partials.trash-icon')
                            </x-danger-icon-button>
                        </div>
                    </li>
                </template>
            </ul>

            <form x-on:submit.prevent="create()" class="mt-4 flex flex-wrap items-end gap-2">
                <div class="flex-1 min-w-48">
                    <x-input-label for="share-dialog-note" :value="__('Note (only you see it)')" />
                    <x-text-input id="share-dialog-note" type="text" maxlength="255" x-model="note" placeholder="{{ __('e.g. who it\'s for') }}" class="mt-1 block w-full text-sm" />
                </div>
                <div>
                    <x-input-label for="share-dialog-expiry" :value="__('Expires after')" />
                    <select id="share-dialog-expiry" x-model="expiresIn" class="mt-1 block text-sm border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-xs">
                        @foreach(\App\Models\SharedLink::EXPIRY_CHOICES as $days => $label)
                            <option value="{{ $days }}" @selected($days === \App\Models\SharedLink::DEFAULT_EXPIRY_DAYS)>{{ __($label) }}</option>
                        @endforeach
                    </select>
                </div>
                <x-primary-button x-bind:disabled="creating">{{ __('Create link') }}</x-primary-button>
            </form>
        </section>
        @endcan
    </div>
</dialog>
