<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;

class ProjectSnapshotController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Project $project): ProjectResource
    {
        Gate::authorize('view', $project);
        $project->load([
            'partDefinitions' => fn ($query) => $query->with('instances')->withCount('instances')->latest(),
            'assemblyGroups' => fn ($query) => $query->withCount(['instances', 'children'])->orderBy('sort_order')->orderBy('name'),
        ]);

        return new ProjectResource($project);
    }
}
