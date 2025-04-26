<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center text-gray-800 dark:text-gray-200 border border-transparent rounded-md font-semibold text-xs uppercase tracking-widest hover:text-gray-700 dark:hover:text-white focus:text-gray-700 dark:focus:text-white active:text-gray-900 dark:active:text-gray-300 focus:outline-hidden focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 disabled:opacity-25 cursor-pointer disabled:cursor-default transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
