<?php

namespace App\Http;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

/**
 * Whether the signed-in pages of THIS request are served with Inertia's encrypted history.
 *
 * Inertia encrypts the history state with `crypto.subtle`, and browsers only expose that in a SECURE CONTEXT (https, or
 * localhost). On a plain-http address that is not localhost (a LAN or server IP, the normal self-hosted case) the
 * encryption throws in the browser and the page transition never completes: nothing signed-in could be opened, the
 * sign-in itself would hang. So encryption is on only where the browser can do it, and the page is served normally
 * (no encrypted history) everywhere else.
 *
 * `INERTIA_ENCRYPT_HISTORY` (config `inertia.history.mode`): `auto` (default) decides per request with
 * {@see self::secureContext()}, `true` always encrypts (only sensible when every visitor uses https or localhost),
 * `false` never does. https is read from the request, so behind a reverse proxy TRUSTED_PROXIES must be set for
 * X-Forwarded-Proto to count (the app is otherwise reached over plain http as far as it can tell).
 */
final class HistoryEncryption
{
    public static function enabled(Request $request): bool
    {
        return match (strtolower(trim((string) config('inertia.history.mode', 'auto')))) {
            'true', '1', 'on', 'yes' => true,
            'false', '0', 'off', 'no' => false,
            default => self::secureContext($request),
        };
    }

    /** https, or a host browsers treat as potentially trustworthy: localhost, *.localhost, 127.x.x.x, [::1]. */
    public static function secureContext(Request $request): bool
    {
        if ($request->isSecure()) {
            return true;
        }

        try {
            $host = strtolower($request->getHost());
        } catch (SuspiciousOperationException) {
            return false;
        }

        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || preg_match('/\A127(\.[0-9]{1,3}){3}\z/', $host) === 1
            || $host === '[::1]'
            || $host === '::1';
    }
}
