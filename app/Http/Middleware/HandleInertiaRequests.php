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
     * The asset version: a hash of the Vite manifest (see Inertia\Middleware::version()), so a
     * deploy that rebuilds public/build makes open tabs reload instead of running stale code.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
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
