<?php

namespace App\Http\Middleware;

use App\Http\ApiErrors;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers for the UI pages, registered on the `web` group only (bootstrap/app.php). Capture and the SDK stubs
 * never run it (they answer cross-origin SDKs and set `nosniff` themselves); the insight JSON API lives in the web group
 * but is skipped here too, its answers are data and not documents.
 *
 *  - X-Content-Type-Options, Referrer-Policy, Permissions-Policy, X-Frame-Options (DENY): always.
 *    Cross-Origin-Opener-Policy only where the browser honours it (https, localhost): on a plain-http LAN or `.test`
 *    address Chrome ignores it and logs a console ERROR on every page ("... because the URL's origin was untrustworthy"),
 *    which the browser e2e over plain http caught. Strict-Transport-Security only when the request itself is secure (never over plain http, where it would
 *    be ignored anyway and would be a trap behind a TLS-terminating proxy that is not trusted).
 *  - Content-Security-Policy-Report-Only (default) or Content-Security-Policy (config security.csp_mode), not on Horizon's
 *    pages (inline scripts and styles of the package) and not while public/hot exists (Vite's dev server serves
 *    scripts and the HMR socket from another origin).
 * A header a controller already set is left alone.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('security.headers_enabled') || ApiErrors::isApiRequest($request)) {
            return $response;
        }

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'X-Frame-Options' => 'DENY',
        ];

        if ($this->isTrustworthy($request)) {
            $headers['Cross-Origin-Opener-Policy'] = 'same-origin';
        }

        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }

        $csp = $this->contentSecurityPolicy($request);
        if ($csp !== null) {
            $headers[$csp[0]] = $csp[1];
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /** The origins browsers treat as secure contexts: https, and the loopback names (a plain-http address is not). */
    private function isTrustworthy(Request $request): bool
    {
        $host = strtolower($request->getHost());

        return $request->isSecure() || in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true) || str_ends_with($host, '.localhost');
    }

    /** @return array{0: string, 1: string}|null header name and value */
    private function contentSecurityPolicy(Request $request): ?array
    {
        $mode = config('security.csp_mode');

        if (! in_array($mode, ['report-only', 'enforce'], true)
            || $request->is('horizon', 'horizon/*')
            || file_exists(public_path('hot'))) {
            return null;
        }

        return [$mode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only', (string) config('security.csp')];
    }
}
