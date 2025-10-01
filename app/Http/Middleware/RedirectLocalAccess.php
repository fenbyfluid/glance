<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;

/**
 * Redirect to internal DNS name if request is coming from the same public IP address as us.
 *
 * This dual-hostname approach is used as a more reliable alternative to split-horizon DNS.
 */
class RedirectLocalAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse|null
     */
    public function handle($request, Closure $next)
    {
        $host = $request->host();
        $hostLocal = self::getExternalHostIfLocal($host) !== null;

        if ($hostLocal || $request->expectsJson()) {
            return $next($request);
        }

        $remoteAddress = $request->ip();
        $usingIpv6 = str_contains($remoteAddress, ':');

        $ourRemoteAddress = Cache::remember('remote-address-'.($usingIpv6 ? 'v6' : 'v4'), 3600, function () use ($usingIpv6) {
            $ourRemoteAddress = Http::withOptions([
                'force_ip_resolve' => $usingIpv6 ? 'v6' : 'v4',
            ])->get(URL::route('utility.discover_ip'))->throw()->body();

            return rtrim($ourRemoteAddress);
        });

        if ($usingIpv6) {
            // TODO: Allow this to be configurable, /64 is standard, we have a /56
            $networkPrefixLength = 56;
            $theirNetworkPrefix = inet_pton($remoteAddress);
            $ourNetworkPrefix = inet_pton($ourRemoteAddress);
            $actuallyLocal = strncmp($theirNetworkPrefix, $ourNetworkPrefix, floor($networkPrefixLength / 8)) === 0;
        } else {
            $actuallyLocal = $remoteAddress === $ourRemoteAddress;
        }

        if (!$actuallyLocal) {
            return $next($request);
        }

        $newUrl = preg_replace('/^([^.]+)\./', '$1-local.', $request->fullUrl(), 1);

        return Redirect::to($newUrl);
    }

    public static function getExternalHostIfLocal(string $host): ?string
    {
        if (preg_match('/^([^.]+)-local(\..*)$/', $host, $matches) !== 1) {
            return null;
        }

        return $matches[1].$matches[2];
    }
}
