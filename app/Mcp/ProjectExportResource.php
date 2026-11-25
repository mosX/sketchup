<?php

namespace App\Mcp;

use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

class ProjectExportResource extends Resource implements HasUriTemplate
{
    protected string $description = 'Read a revision-pinned project export through the authenticated MCP session.';

    protected string $mimeType = 'application/octet-stream';

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('woodworking://exports/{project_id}/{expected_revision}/{format}/{view}/{width}/{height}');
    }

    public function handle(Request $request, HttpRequest $http, WoodworkingApiDispatch $api): Response
    {
        $data = $request->validate([
            'project_id' => ['required', 'integer', 'min:1'],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'format' => ['required', 'in:obj,glb,png'],
            'view' => ['required', 'in:isometric,front,rear,side,top'],
            'width' => ['required', 'integer', 'between:320,2048'],
            'height' => ['required', 'integer', 'between:240,2048'],
        ]);
        $projectId = (int) $data['project_id'];
        unset($data['project_id']);
        $response = $api->handle($http, 'GET', "/projects/$projectId/export", $data);
        if (! $response->isSuccessful()) {
            return Response::error("Project export failed with HTTP status {$response->getStatusCode()}.");
        }
        $this->mimeType = $response->headers->get('Content-Type') ?? $this->mimeType;

        return Response::blob((string) $response->getContent());
    }
}
