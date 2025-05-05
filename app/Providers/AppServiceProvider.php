<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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

        Model::shouldBeStrict();

        if ($this->app->isProduction()) {
            Model::handleLazyLoadingViolationUsing(function ($model, $relation) {
                $class = get_class($model);

                Log::info("Attempted to lazy load [$relation] on model [$class].");
            });
        }

        if (config('media.trust_x_send_file', false)) {
            BinaryFileResponse::trustXSendfileTypeHeader();
        }

        Gate::define('admin', function (User $user) {
            return $user->is_admin;
        });

        // Ignore cache hits in the Clockwork trace (they're quick).
        app('clockwork.cache')->addFilter(function ($record) {
            return $record['type'] !== 'hit';
        });
    }
}
