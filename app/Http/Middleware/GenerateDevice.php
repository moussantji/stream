<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GenerateDevice
{
    /**
     * Handle an incoming request.
     *
     * A device identity is generated lazily (once per hour), not per request:
     * a fresh identity on every request would invalidate the cached bearer
     * token (which is device-bound), look like device-rotation to the
     * upstream (rate-limit/429 bait) and add a synchronous disk write to
     * every response.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $path = storage_path('app/moviebox/device.json');

        if (! is_file($path) || (time() - @filemtime($path)) > 3600) {
            file_put_contents($path, json_encode([
                'device_id' => bin2hex(random_bytes(16)),
                'gaid' => (string) Str::uuid(),
            ], JSON_PRETTY_PRINT));
        }

        return $next($request);
    }
}
