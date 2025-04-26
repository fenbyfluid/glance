@props([
    'disabled' => false,
    'value' => '1',
    'label' => '',
    'messages' => [],
])

<div class="flex gap-2">
    <div class="flex h-6 shrink-0 items-center">
        <div class="group grid size-4 grid-cols-1">
            <input type="checkbox" @disabled($disabled) value="{{ $value }}" {{ $attributes->merge(['class' => 'col-start-1 row-start-1 appearance-none rounded-xs border border-gray-300 bg-white checked:border-indigo-600 checked:bg-indigo-600 indeterminate:border-indigo-600 indeterminate:bg-indigo-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:border-gray-300 disabled:bg-gray-100 disabled:checked:bg-gray-100 forced-colors:appearance-auto']) }}>
            <svg class="pointer-events-none col-start-1 row-start-1 size-3.5 self-center justify-self-center stroke-white group-has-disabled:stroke-gray-950/25" viewBox="0 0 14 14" fill="none">
                <path class="opacity-0 group-has-checked:opacity-100" d="M3 8L6 11L11 3.5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                <path class="opacity-0 group-has-indeterminate:opacity-100" d="M3 7H11" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </div>
    </div>
    @if ($label || $messages)
        <div class="text-sm/6">
            @if ($label)
                <label {{ $attributes->merge(['class' => 'font-medium text-gray-700 dark:text-gray-300']) }}>
                    {{ $label }}
                </label>
            @endif
            @if ($messages)
                <ul {{ $attributes->merge(['class' => 'text-red-600 dark:text-red-400']) }}>
                    @foreach ((array) $messages as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
</div>
