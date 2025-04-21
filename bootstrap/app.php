<?php

use App\Http\Controllers\MediaController;
use App\Http\Middleware\EnsureUserIsActivated;
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
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (HttpException $exception, Request $request) {
            return app()->make(MediaController::class)->handleHttpException($request, $exception);
        });
    })->create();
