{{-- The admin pages, for the Manage menus in layouts.navigation. $component is the link component to use; only the
     responsive one shows which page is current. --}}
@foreach([
    'admin.users.index' => __('Users'),
    'admin.access.index' => __('Access'),
    'admin.links.index' => __('Links'),
] as $routeName => $label)
    @if($component === 'responsive-nav-link')
        <x-responsive-nav-link :href="route($routeName)" :active="request()->routeIs($routeName)">{{ $label }}</x-responsive-nav-link>
    @else
        <x-dropdown-link :href="route($routeName)">{{ $label }}</x-dropdown-link>
    @endif
@endforeach
