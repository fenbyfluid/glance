@isset($accessControlOverrideUser)
    <div class="mt-2 bg-red-700 text-white shadow-sm">
        <form method="post" action="{{ route('admin.users.impersonate', ['user' => null]) }}" class="max-w-7xl mx-auto py-2 px-4 sm:px-6 lg:px-8 flex justify-between items-center">
            @csrf
            <div>
                Browsing as
                <span class="font-bold">{{ $accessControlOverrideUser->note }}</span>
            </div>
            <x-danger-button>
                Stop Testing
            </x-danger-button>
        </form>
    </div>
@endisset
