<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks direct browser navigation to the JSON API (e.g. someone pasting an
 * /api/... URL in the address bar). It relies on the browser's Fetch Metadata
 * headers: a top-level navigation sends `Sec-Fetch-Mode: navigate` /
 * `Sec-Fetch-Dest: document`, whereas the SPA's fetch()/XHR calls and the
 * media/subtitle sub-resource requests do not. Native app requests (React
 * Native) don't send these headers at all, so they pass through.
 *
 * Note: this deters casual URL-poking, not a determined attacker (a public
 * browser SPA can never fully hide its own API). Toggle via
 * config('moviebox.protect_api').
 */
class BlockBrowserApiAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('moviebox.protect_api', true)) {
            return $next($request);
        }

        $mode = strtolower((string) $request->header('Sec-Fetch-Mode', ''));
        $dest = strtolower((string) $request->header('Sec-Fetch-Dest', ''));

        // A page navigation (typed URL / clicked link / iframe document).
        if ($mode === 'navigate' || $dest === 'document' || $dest === 'iframe') {
            abort(403, 'Accès direct non autorisé.');
        }

        return $next($request);
    }
}
