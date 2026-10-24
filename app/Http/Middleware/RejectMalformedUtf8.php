<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 400 to a web request whose query or body carries a string that is not valid UTF-8, before anything
 * can store it. Sessions are serialised as JSON (config/session.php) and a failed validation flashes the old
 * input into the session, so one `name=%FF` used to make the session unreadable and signed the user out.
 * First in the web group, ahead of StartSession: the session is never touched. Capture routes do not run the
 * web group and keep their own payload handling.
 */
class RejectMalformedUtf8
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->valid($request->query()) || ! $this->valid($request->request->all()) || ($request->isJson() && ! mb_check_encoding($request->getContent(), 'UTF-8'))) {
            abort(400, 'The request contains text that is not valid UTF-8.');
        }

        return $next($request);
    }

    private function valid(mixed $value): bool
    {
        if (is_string($value)) {
            return mb_check_encoding($value, 'UTF-8');
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (! $this->valid($key) || ! $this->valid($item)) {
                    return false;
                }
            }
        }

        return true;
    }
}
