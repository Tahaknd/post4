<?php

namespace App\Http\Middleware;

use App\Http\ApiErrors;
use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /** The navigation lists at most this many projects; the project list page shows them all. */
    public const int PROJECT_LIMIT = 50;

    /** @var array{list: list<array{id: int, name: string, url: string}>, truncated: bool}|null the nav projects of this request, read once */
    private ?array $navProjects = null;

    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * The JSON API is not an Inertia surface: an `X-Inertia` header on an `/api/*` request must not start the
     * version check (an empty 409 with `X-Inertia-Location`) or the page protocol; the request is answered as JSON.
     */
    public function handle(Request $request, Closure $next)
    {
        if (ApiErrors::isApiRequest($request)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

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
     * Props shared with every page. Values safe for anyone who can load the page, as plain data (never an
     * Eloquent model); secrets (project keys, ClickHouse settings) never belong here. `auth`, `flash`,
     * `projects` and `projectsTruncated` are closures, so they are only evaluated when a page is rendered (a redirect never reads
     * them, and a partial reload skips them).
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
            'auth' => fn () => [
                'user' => $this->user($request),
            ],
            // The project menu of the navigation: id, name and the server-built URL, at most PROJECT_LIMIT, never a key.
            // The project being viewed is always in the list; `projectsTruncated` says there are more.
            'projects' => fn () => $this->navProjects($request)['list'],
            'projectsTruncated' => fn () => $this->navProjects($request)['truncated'],
            'flash' => fn () => [
                // Not every request that renders a page has a session (404 pages of unmatched routes).
                'status' => $request->hasSession() ? $request->session()->get('status') : null,
            ],
        ];
    }

    /**
     * @return array{list: list<array{id: int, name: string, url: string}>, truncated: bool}
     */
    private function navProjects(Request $request): array
    {
        if ($this->navProjects !== null) {
            return $this->navProjects;
        }

        $user = $request->user();

        if ($user === null) {
            return $this->navProjects = ['list' => [], 'truncated' => false];
        }

        // One more than the limit tells whether the list is cut.
        $found = Project::query()->visibleTo($user)->orderBy('name')->orderBy('id')->limit(self::PROJECT_LIMIT + 1)->get(['id', 'name']);
        $truncated = $found->count() > self::PROJECT_LIMIT;
        $projects = $found->take(self::PROJECT_LIMIT)->all();

        // The project on screen must be in the menu even when it sorts past the cut (route binding has already
        // limited it to what the user may open).
        $current = $request->route('project');
        if ($current instanceof Project && ! in_array($current->getKey(), array_map(fn (Project $project) => $project->getKey(), $projects), true)) {
            array_pop($projects);
            $projects[] = $current;
            usort($projects, fn (Project $a, Project $b) => [mb_strtolower($a->name), $a->id] <=> [mb_strtolower($b->name), $b->id]);
        }

        return $this->navProjects = [
            'list' => array_map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'url' => route('projects.show', $project, absolute: false),
            ], $projects),
            'truncated' => $truncated,
        ];
    }

    /**
     * @return array{id: int, name: string, email: string, is_admin: bool}|null
     */
    private function user(Request $request): ?array
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_admin' => $user->is_admin === true,
        ];
    }
}
