<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
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

        Model::preventLazyLoading(!$this->app->isProduction());

        if (config('media.trust_x_send_file', false)) {
            BinaryFileResponse::trustXSendfileTypeHeader();
        }

        Gate::define('admin', function (User $user) {
            return $user->is_admin;
        });

        // TODO: We probably need to split this up into "file" and "tree" variations, we need to be able to allow access
        //       to browse the hierarchy if there is a visible sub-path without access to the files in the parent path.
        // TODO: Only used by MediaController::stream() now, decide if we want the gate everywhere with caching instead.
        Gate::define('view-media', function (User $user, string $path) {
            $accessControlInfo = $user->getPathAccessInfo($path);

            return $accessControlInfo[''];
        });

        // Ignore cache hits in the Clockwork trace (they're quick).
        app('clockwork.cache')->addFilter(function ($record) {
            return $record['type'] !== 'hit';
        });
    }
}
