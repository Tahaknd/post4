<?php

return [
    /*
     * Reverse proxies whose X-Forwarded-* headers are trusted (env TRUSTED_PROXIES):
     * unset/empty => null (trust nothing, so a direct client cannot spoof its ip),
     * "*" => every caller, otherwise a comma-separated list of IPs/CIDRs.
     *
     * Every per-ip limiter (capture failures, sign-in throttle) keys on the ip Laravel resolves from this: behind
     * a reverse proxy WITHOUT it set, all clients share the proxy's address and therefore one budget (Docker
     * Desktop's NAT does the same on a dev machine). Set it when a proxy sits in front.
     */
    'trusted' => (static function (): array|string|null {
        $raw = trim((string) env('TRUSTED_PROXIES', ''));

        if ($raw === '') {
            return null;
        }
        if ($raw === '*') {
            return '*';
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $p): bool => $p !== ''));
    })(),
];
