<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Projects\ExportProject;
use App\Exceptions\ProjectRevisionConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProjectExportRequest;
use App\Http\Resources\AssemblyGroupResource;
use App\Http\Resources\PartDefinitionResource;
use App\Http\Resources\ProjectFastenerResource;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ProjectExportController extends Controller
{
    public function __invoke(ProjectExportRequest $request, Project $project, ExportProject $export): JsonResponse|Response
    {
        $options = $request->safe()->only(['format', 'width', 'height', 'view']);
        $options += ['width' => 1200, 'height' => 900, 'view' => 'isometric'];
        $format = $options['format'];
        $mime = match ($format) {
            'obj' => 'text/plain',
            'glb' => 'model/gltf-binary',
            'png' => 'image/png',
        };
        $metadataOnly = $request->boolean('metadata');
        $expected = $request->validated('expected_revision');
        [$revision, $model] = DB::transaction(function () use ($project, $request, $expected, $metadataOnly): array {
            $snapshot = Project::query()->lockForUpdate()->findOrFail($project->id);
            if ($expected !== null && (int) $expected !== $snapshot->revision) {
                throw new ProjectRevisionConflictException((int) $expected, $snapshot->revision);
            }
            if ($metadataOnly) {
                return [$snapshot->revision, []];
            }

            return [$snapshot->revision, [
                'parts' => PartDefinitionResource::collection($snapshot->partDefinitions()->with('instances')->get())->resolve($request),
                'groups' => AssemblyGroupResource::collection($snapshot->assemblyGroups()->get())->resolve($request),
                'fasteners' => ProjectFastenerResource::collection($snapshot->projectFasteners()->orderBy('id')->get())->resolve($request),
                'render_settings' => $snapshot->script_data['render_settings'] ?? $snapshot->script_data['base_render_settings'] ?? [],
            ]];
        });
        $link = route('api.project.export', ['project' => $project, ...$options, 'expected_revision' => $revision]);
        $resourceUri = "woodworking://exports/{$project->id}/$revision/$format/{$options['view']}/{$options['width']}/{$options['height']}";
        $fileName = "project-{$project->id}-r$revision.$format";
        if ($metadataOnly) {
            return response()->json([
                'download_url' => $link,
                'resource_uri' => $resourceUri,
                'filename' => $fileName,
                'mime_type' => $mime,
                'revision' => $revision,
                'authorization' => 'Use the same Bearer API key as for this MCP call.',
            ]);
        }
        $bytes = $export->handle($model, $options);

        return response($bytes, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => "attachment; filename=\"$fileName\"",
            'Content-Length' => strlen($bytes),
            'Cache-Control' => 'private, no-store',
            'X-Woodworking-Export-Url' => $link,
            'X-Woodworking-Export-Resource' => $resourceUri,
            'X-Woodworking-Export-File' => $fileName,
            'X-Woodworking-Export-Revision' => $revision,
        ]);
    }
}
