<?php

namespace App\Http\Middleware;

use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureTokenProjectScope
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        $project = $request->route('project');

        if ($token instanceof PersonalAccessToken) {
            $projectAbilities = collect($token->abilities ?? [])
                ->filter(fn (string $ability): bool => str_starts_with($ability, 'project:'));

            if ($projectAbilities->isEmpty()) {
                return $next($request);
            }

            if ($project === null) {
                abort(Response::HTTP_FORBIDDEN);
            }

            $projectId = $project instanceof Project ? $project->getKey() : (int) $project;

            if (! $projectAbilities->contains("project:{$projectId}")) {
                abort(Response::HTTP_NOT_FOUND);
            }
        }

        return $next($request);
    }
}
