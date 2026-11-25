<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Projects\AnalyzeProjectGeometry;
use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProjectAssemblyCheckController extends Controller
{
    public function __invoke(Request $request, Project $project, AnalyzeProjectGeometry $analysis): JsonResponse
    {
        Gate::authorize('view', $project);
        $validated = $request->validate(['expected_revision' => ['sometimes', 'integer', 'min:1']]);
        $expected = isset($validated['expected_revision']) ? (int) $validated['expected_revision'] : null;

        return response()->json(['data' => $analysis->checkAssembly($project, $request, $expected)]);
    }
}
