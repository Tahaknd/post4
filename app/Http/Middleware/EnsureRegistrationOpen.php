<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registration is open only while no user exists. Both GET and POST /register redirect to /login once
 * an account exists (one rule for both verbs; a 404 would only trap someone who lost a race). This is
 * the fast path: RegisterFirstUser repeats the check under a lock.
 */
class EnsureRegistrationOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        if (User::query()->exists()) {
            return redirect()->route('login')->with('status', 'Registration is closed.');
        }

        return $next($request);
    }
}
