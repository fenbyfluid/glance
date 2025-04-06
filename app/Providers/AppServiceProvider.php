<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
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
        if (config('media.trust_x_send_file', false)) {
            BinaryFileResponse::trustXSendfileTypeHeader();
        }

        Gate::define('admin', function ($user) {
            return $user->is_admin;
        });
    }
}
