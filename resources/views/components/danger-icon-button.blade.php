<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center border border-transparent rounded-md font-semibold text-xs text-red-600 uppercase tracking-widest hover:text-red-500 active:text-red-700 focus:outline-hidden focus:ring-2 focus:ring-red-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 disabled:opacity-25 cursor-pointer disabled:cursor-default transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
