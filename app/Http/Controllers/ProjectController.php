<?php

namespace App\Http\Controllers;

use App\Actions\Projects\CreateProject;
use App\Actions\Projects\DeleteProject;
use App\Actions\Projects\RotateProjectKey;
use App\Domain\Projects\ProjectRules;
use App\Domain\Projects\ProjectWriteThrottle;
use App\Http\Requests\Projects\DeleteProjectRequest;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The project pages. `{project}` is bound through Project::visibleTo(user) (see AppServiceProvider), so a project
 * the user cannot open is a 404 for every method here, exactly like one that does not exist.
 *
 * The API key is a page prop of show() ONLY: never a shared prop, never flashed, never in a list. Redirects after a
 * write are 303 on purpose (a 302 after POST/PUT/DELETE may be replayed with the same method by some clients).
 */
class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Project::class);

        return Inertia::render('Projects/Index', [
            'projects' => Project::query()
                ->visibleTo($this->user($request))
                ->orderBy('name')
                ->orderBy('id')
                ->get()
                ->map(fn (Project $project) => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'timezone' => $project->timezone,
                    'created_at' => $project->created_at?->toIso8601String(),
                    'url' => route('projects.show', $project, absolute: false),
                ])
                ->all(),
            'create_url' => route('projects.create', absolute: false),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Project::class);

        return Inertia::render('Projects/Create', [
            'timezones' => ProjectRules::timezones(),
            'default_timezone' => ProjectRules::DEFAULT_TIMEZONE,
            'store_url' => route('projects.store', absolute: false),
            'index_url' => route('projects.index', absolute: false),
        ]);
    }

    public function store(StoreProjectRequest $request, CreateProject $createProject, ProjectWriteThrottle $throttle): RedirectResponse
    {
        $user = $this->user($request);
        $this->throttle($throttle, $user);

        $project = $createProject->handle($request->validated('name'), $request->validated('timezone'), $user);

        return redirect()->route('projects.show', $project, 303)->with('status', 'Project created.');
    }

    public function show(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        $user = $this->user($request);

        return Inertia::render('Projects/Show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'timezone' => $project->timezone,
                'created_at' => $project->created_at?->toIso8601String(),
            ],
            // The one place the key leaves the server: the page of a user who may open this project.
            'api_key' => $project->api_key,
            'api_host' => rtrim((string) config('app.url'), '/'),
            'can' => [
                'rotate_key' => $user->can('rotateKey', $project),
                'delete' => $user->can('delete', $project),
            ],
            'urls' => [
                'index' => route('projects.index', absolute: false),
                'rotate_key' => route('projects.rotate-key', $project, absolute: false),
                'destroy' => route('projects.destroy', $project, absolute: false),
            ],
        ]);
    }

    public function rotateKey(Request $request, Project $project, RotateProjectKey $rotateProjectKey, ProjectWriteThrottle $throttle): RedirectResponse
    {
        Gate::authorize('rotateKey', $project);

        $user = $this->user($request);
        $this->throttle($throttle, $user);

        $rotateProjectKey->handle($project, $user);

        // The new key is on the page the redirect lands on; the flash carries a sentence, never the key.
        return redirect()->route('projects.show', $project, 303)->with('status', 'API key replaced. The old key no longer works.');
    }

    public function destroy(DeleteProjectRequest $request, Project $project, DeleteProject $deleteProject, ProjectWriteThrottle $throttle): RedirectResponse
    {
        $user = $this->user($request);
        $this->throttle($throttle, $user);

        $deleteProject->handle($project, $user, purgeEvents: true);

        return redirect()->route('projects.index', status: 303)->with('status', 'Project deleted. Its events are being removed in the background.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function throttle(ProjectWriteThrottle $throttle, User $user): void
    {
        if (($wait = $throttle->hit($user)) !== null) {
            throw new ThrottleRequestsException("Too many project changes. Try again in {$wait} seconds.", headers: ['Retry-After' => $wait]);
        }
    }
}
