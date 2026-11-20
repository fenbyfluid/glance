<?php

namespace App\Providers;

use App\Auth\PathAccess;
use App\Auth\SharedLinkGuard;
use App\Models\User;
use App\Utilities\OverrideMimeTypeGuesser;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;
use Laravel\Scout\EngineManager;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Mime\MimeTypes;
use TeamTNT\Scout\Engines\TNTSearchEngine;
use TeamTNT\TNTSearch\Stemmer\PorterStemmer;
use TeamTNT\TNTSearch\TNTSearch;

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
        ini_set('memory_limit', '-1');

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

        MimeTypes::getDefault()->registerGuesser(new OverrideMimeTypeGuesser);

        Gate::define('admin', function (User $user) {
            return $user->is_admin;
        });

        Auth::extend('shared-link', function ($app) {
            $guard = new SharedLinkGuard($app['request']);
            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });

        // Searches are heavy, and anyone holding a shared link can run them as a guest. By IP rather than session, as
        // opening the link again starts a fresh session.
        RateLimiter::for('search', function (Request $request) {
            if (!PathAccess::isGuestRequest($request)) {
                return Limit::none();
            }

            return Limit::perMinute(30)->by('guest-search|'.$request->ip());
        });

        Horizon::auth(function ($request) {
            return Gate::check('admin', [$request->user()]);
        });

        // Ignore cache hits in the Clockwork trace (they're quick).
        app('clockwork.cache')->addFilter(function ($record) {
            return $record['type'] !== 'hit';
        });

        // Duplicate the tntsearch driver with one with a stemmer configured.
        // Unfortunately this is fairly hacky as we don't have anyway to get a fresh original instance out of Scout.
        $this->app[EngineManager::class]->extend('tntsearch_stemmed', function () {
            $tnt = new TNTSearch;

            $driver = config('database.default');
            $config = config('scout.tntsearch') + config("database.connections.{$driver}");

            $config['stemmer'] = PorterStemmer::class;

            $tnt->loadConfig($config);
            $tnt->setDatabaseHandle(app('db')->connection()->getPdo());
            $tnt->engine->maxDocs = config('scout.tntsearch.maxDocs', 500);

            // TODO: This doesn't appear to change anything.
            $tnt->asYouType(true);

            return new TNTSearchEngine($tnt);
        });
    }
}
