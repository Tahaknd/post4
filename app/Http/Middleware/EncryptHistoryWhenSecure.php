<?php

namespace App\Http\Middleware;

use App\Http\HistoryEncryption;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/** Inertia's EncryptHistory, but only where the browser can encrypt (see {@see HistoryEncryption}). */
class EncryptHistoryWhenSecure
{
    public function handle(Request $request, Closure $next): Response
    {
        Inertia::encryptHistory(HistoryEncryption::enabled($request));

        return $next($request);
    }
}
