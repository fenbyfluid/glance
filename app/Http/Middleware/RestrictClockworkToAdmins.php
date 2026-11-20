<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns Clockwork off for everyone but admins: their requests aren't recorded and their responses carry no
 * X-Clockwork-* headers or toolbar cookie. The Clockwork routes themselves are gated in routes/web.php.
 *
 * TODO: Requests that never reach the web middleware group (e.g. ones without a matching route) are still recorded,
 *  but only admins can read them.
 */
class RestrictClockworkToAdmins
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!Gate::forUser($request->user('web'))->check('admin')) {
            config(['clockwork.enable' => false]);
        }

        return $next($request);
    }
}
