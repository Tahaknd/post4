<?php

return [
    /*
     * Security headers on the pages of the UI (the `web` middleware group only: capture, the SDK stubs, /api JSON and
     * /up are not touched). SECURITY_HEADERS_ENABLED=false turns the whole middleware off.
     * See App\Http\Middleware\SecurityHeaders.
     */
    'headers_enabled' => (bool) env('SECURITY_HEADERS_ENABLED', true),

    /*
     * Content-Security-Policy mode (env SECURITY_CSP_MODE): `report-only` (default: the browser logs violations to the
     * console and blocks nothing), `enforce`, or `off`. Never sent while public/hot exists (the Vite dev server) or on
     * Horizon's pages (they use inline scripts and styles).
     */
    'csp_mode' => env('SECURITY_CSP_MODE', 'report-only'),

    /*
     * The policy. The UI is a Vue SPA served from this origin: scripts, fonts and connections are same-origin only,
     * styles allow inline (Vue's :style bindings and ECharts write style attributes), images allow data: URIs,
     * nothing may frame the page, post a form elsewhere, or load a plugin. The demo page's posthog-js chunk is a
     * same-origin script and the SDK talks to this origin only.
     */
    'csp' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
];
