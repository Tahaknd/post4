<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `api.no-store`: the answer of an insight endpoint is private to the signed-in user and changes
 * while a run progresses, so no cache (browser, proxy) may keep it. Errors thrown further in are responses by the
 * time they pass here, so they are covered too.
 */
class NoStoreResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
