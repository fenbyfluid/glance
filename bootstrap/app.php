<?php

use App\Http\Controllers\MediaController;
use App\Http\Middleware\AddNoIndexHeader;
use App\Http\Middleware\EnsureUserIsActivated;
use App\Http\Middleware\RedirectLocalAccess;
use App\Http\Middleware\RestrictClockworkToAdmins;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/_health',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'activated' => EnsureUserIsActivated::class,
        ])->web([
            AddNoIndexHeader::class,
            RestrictClockworkToAdmins::class,
        ], [
            RedirectLocalAccess::class,
        ]);

        // The Clockwork routes get the web group (routes/web.php) for the session, but its toolbar's client metrics
        // PUT carries no CSRF token. That endpoint checks a per-request update token of its own.
        $middleware->validateCsrfTokens(except: [
            '__clockwork/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (HttpException $exception, Request $request) {
            return app()->make(MediaController::class)->handleHttpException($request, $exception);
        });
    })->create();
