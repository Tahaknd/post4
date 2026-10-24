<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * The asset version: an md5 of public/build/manifest.json, or null when nothing is built. A
     * deploy that rebuilds the assets changes the manifest, so open tabs get a full reload (HTTP 409)
     * instead of running stale code. The parent implementation is not used on purpose: it returns a
     * hash of ASSET_URL as soon as that is set, which never changes between builds and would defeat
     * the check.
     */
    public function version(Request $request): ?string
    {
        $manifest = public_path('build/manifest.json');

        if (! is_file($manifest)) {
            return null;
        }

        return hash_file('md5', $manifest) ?: null;
    }

    /**
     * Props shared with every page. Keep this to values that are safe for anyone who can load the
     * page: there is no user here yet, and secrets (project keys, ClickHouse settings) never belong.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name'),
            ],
        ];
    }
}
