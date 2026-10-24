<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Home', [
            // Plain arrays, never models; the project key is not a page prop.
            'projects' => Project::query()
                ->visibleTo($request->user())
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Project $project) => ['id' => $project->id, 'name' => $project->name])
                ->all(),
        ]);
    }
}
