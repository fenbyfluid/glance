@props([
    // The text, or an Alpine expression for it (in the share dialog, where it changes).
    'value' => null,
    'expression' => null,
    'label',
])

{{-- A read-only text box with a copy button inside its right edge, like the search box's clear button (copyInput in
     share.js). The wrapper takes the text input's border and focus ring, so the two read as one field. --}}
<div
    x-data="copyInput"
    {{ $attributes->merge(['class' => 'flex items-center min-w-0 rounded-md border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-xs focus-within:border-indigo-500 dark:focus-within:border-indigo-600 focus-within:ring-1 focus-within:ring-indigo-500 dark:focus-within:ring-indigo-600']) }}
>
    <input
        x-ref="input"
        type="text"
        readonly
        @if($expression !== null) x-bind:value="{{ $expression }}" @else value="{{ $value }}" @endif
        x-on:click="$el.select()"
        aria-label="{{ $label }}"
        class="block min-w-0 grow border-0 bg-transparent py-2 pl-3 pr-1 text-sm text-gray-700 dark:text-gray-300 focus:ring-0 focus:outline-hidden"
    >
    <button
        type="button"
        x-on:click="copy()"
        x-bind:title="copied ? 'Copied' : 'Copy link'"
        x-bind:aria-label="copied ? 'Copied' : 'Copy link'"
        class="shrink-0 rounded-md p-1 mr-1.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 focus:outline-hidden focus-visible:text-gray-600 dark:focus-visible:text-gray-300 cursor-pointer"
    >
        <svg x-show="!copied" class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M13.887 3.182c.396.037.79.08 1.183.128C16.194 3.45 17 4.414 17 5.517V16.75A2.25 2.25 0 0 1 14.75 19h-9.5A2.25 2.25 0 0 1 3 16.75V5.517c0-1.103.806-2.068 1.93-2.207.393-.048.787-.09 1.183-.128A3.001 3.001 0 0 1 9 1h2c1.373 0 2.531.923 2.887 2.182ZM7.5 4A1.5 1.5 0 0 1 9 2.5h2A1.5 1.5 0 0 1 12.5 4v.5h-5V4Z" clip-rule="evenodd" />
        </svg>
        <svg x-show="copied" style="display: none" class="size-5 text-green-600 dark:text-green-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" />
        </svg>
    </button>
</div>
