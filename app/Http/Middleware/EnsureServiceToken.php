<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureServiceToken
{
    public function handle(Request $request, Closure $next)
    {
        $expectedToken = trim((string) config('gemini.service_token', ''));
        if ($expectedToken === '') {
            abort(500, 'Service token is not configured.');
        }

        $providedToken = trim((string) $request->bearerToken());
        if (! hash_equals($expectedToken, $providedToken)) {
            abort(401, 'Unauthorized service token.');
        }

        return $next($request);
    }
}
