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
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $path = storage_path('app/moviebox/device.json');

        file_put_contents($path, json_encode([
            'device_id' => bin2hex(random_bytes(16)),
            'gaid' => (string) \Illuminate\Support\Str::uuid(),
        ], JSON_PRETTY_PRINT));

        return $next($request);

        return $next($request);
    }
}
