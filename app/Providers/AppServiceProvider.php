<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::useBuildDirectory('_build');

        if (config('media.trust_x_send_file', false)) {
            BinaryFileResponse::trustXSendfileTypeHeader();
        }

        Gate::define('admin', function (User $user) {
            return $user->is_admin;
        });

        Gate::define('view-media', function (User $user, string $path) {
            // TODO: Implement access control checks
            return true;
        });

        // Ignore cache hits in the Clockwork trace (they're quick).
        app('clockwork.cache')->addFilter(function ($record) {
            return $record['type'] !== 'hit';
        });
    }
}
