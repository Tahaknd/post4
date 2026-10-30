<?php

namespace App\Http\Middleware;

use App\Http\ApiErrors;
use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

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
     * The `url` of a page, which the browser writes into its address bar when the page loads. Inertia's default comes
     * from Request::fullUrl(), which sorts the query keys and keeps only the LAST of a repeated key (`?e=a&e=b` became
     * `?e=b`, silently changing a shared link). This one is the path and the query string exactly as they were sent.
     */
    public function urlResolver(): Closure
    {
        return fn (Request $request): string => self::relativeUrl($request);
    }

    /**
     * A version mismatch (a deploy changed the assets) makes the client reload this location: it must be the one that was
     * asked for, repeated keys included, so the same resolver builds it.
     */
    public function onVersionChange(Request $request, Response $response): Response
    {
        if ($request->hasSession()) {
            $request->session()->reflash();
        }

        $response = Inertia::location($request->getSchemeAndHttpHost().self::relativeUrl($request));
        $response->headers->set('X-Inertia-Version', Inertia::getVersion());

        return $response;
    }

    /**
     * The path as the framework normalises it (so a request-target of the form `//host/x` or an absolute URI can never turn
     * into another origin), a trailing slash as sent, then the RAW query string; an empty `?` is dropped.
     */
    private static function relativeUrl(Request $request): string
    {
        $uri = $request->getRequestUri();
        $path = Str::start(Str::after($request->url(), $request->getSchemeAndHttpHost()), '/');
        if ($path !== '/' && Str::endsWith(Str::before($uri, '?'), '/')) {
            $path .= '/';
        }
        $query = Str::contains($uri, '?') ? Str::after($uri, '?') : '';

        return $query === '' ? $path : $path.'?'.$query;
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
            // The Trends page of the project on screen (the navigation links to it), null on pages without a project.
            'trendsUrl' => function () use ($request) {
                $current = $request->route('project');

                return $current instanceof Project ? route('projects.insights.trends', $current, absolute: false) : null;
            },
            // The Funnel page of the project on screen, the same way.
            'funnelUrl' => function () use ($request) {
                $current = $request->route('project');

                return $current instanceof Project ? route('projects.insights.funnel', $current, absolute: false) : null;
            },
            // The Retention page of the project on screen, the same way.
            'retentionUrl' => function () use ($request) {
                $current = $request->route('project');

                return $current instanceof Project ? route('projects.insights.retention', $current, absolute: false) : null;
            },
            // The dashboard list of the project on screen, the same way.
            'dashboardsUrl' => function () use ($request) {
                $current = $request->route('project');

                return $current instanceof Project ? route('projects.dashboards.index', $current, absolute: false) : null;
            },
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
