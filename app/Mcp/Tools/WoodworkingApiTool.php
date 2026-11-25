<?php

namespace App\Mcp\Tools;

use App\Mcp\WoodworkingApiDispatch;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class WoodworkingApiTool extends Tool
{
    /** @param array<string, mixed> $inputSchema */
    public function __construct(
        string $name,
        string $description,
        private readonly string $method,
        private readonly string $path,
        private readonly array $inputSchema,
    ) {
        $this->name = $name;
        $this->description = $description;
        $this->title = str($name)->after('woodworking_')->headline()->toString();
    }

    public function toArray(): array
    {
        $schema = $this->inputSchema;
        $schema['properties'] = (object) $schema['properties'];

        return [...parent::toArray(), 'inputSchema' => $schema];
    }

    public function handle(Request $request, HttpRequest $http, WoodworkingApiDispatch $api): Response|ResponseFactory
    {
        $arguments = $request->all();
        $unknown = array_diff_key($arguments, $this->inputSchema['properties']);

        if ($unknown !== []) {
            return Response::error('Unknown tool arguments: '.implode(', ', array_keys($unknown)));
        }

        preg_match_all('/\{([a-z_]+)\}/', $this->path, $matches);
        $identifiers = $request->validate(array_fill_keys($matches[1], ['required', 'integer', 'min:1']));
        $path = strtr($this->path, collect($identifiers)->mapWithKeys(
            fn (mixed $value, string $key): array => ['{'.$key.'}' => (string) (int) $value],
        )->all());
        $payload = Arr::except($arguments, $matches[1]);
        $payload = match ($this->name) {
            'woodworking_instantiate_project_template' => [
                ...Arr::except($payload, ['width', 'depth', 'height']),
                'dimensions' => Arr::only($payload, ['width', 'depth', 'height']),
            ],
            'woodworking_apply_commands', 'woodworking_place_fasteners', 'woodworking_copy_fastener_row', 'woodworking_prepare_fastener_drilling', 'woodworking_update_project_materials', 'woodworking_run_project_script' => ['dry_run' => true, ...$payload],
            'woodworking_create_catalog_fastener', 'woodworking_update_catalog_fastener' => ['kind' => 'consumable', ...$payload],
            'woodworking_export_project' => [
                ...Arr::except($payload, ['inline_preview']),
                'metadata' => (int) (($payload['format'] ?? null) !== 'png' || ($payload['inline_preview'] ?? true) === false),
            ],
            default => $payload,
        };

        $response = $api->handle($http, $this->method, $path, $payload);

        if ($this->name === 'woodworking_export_project' && $response->isSuccessful() && $response->headers->get('Content-Type') === 'image/png') {
            $link = $response->headers->get('X-Woodworking-Export-Url');
            $fileName = $response->headers->get('X-Woodworking-Export-File');
            $resourceUri = $response->headers->get('X-Woodworking-Export-Resource');

            return Response::make([
                Response::image((string) $response->getContent(), 'image/png'),
                Response::resourceLink($resourceUri, $fileName, 'image/png', description: 'Download via MCP resources/read.'),
            ])->withStructuredContent([
                'download_url' => $link,
                'resource_uri' => $resourceUri,
                'filename' => $fileName,
                'mime_type' => 'image/png',
                'revision' => (int) $response->headers->get('X-Woodworking-Export-Revision'),
            ]);
        }

        $data = json_decode((string) $response->getContent(), true);
        if (! is_array($data)) {
            $data = $response->isSuccessful() ? [] : ['message' => 'The API returned a non-JSON response.'];
        }

        if (! $response->isSuccessful()) {
            return Response::error(json_encode([
                'status' => $response->getStatusCode(),
                'response' => $response->getStatusCode() >= 500 ? ['message' => 'Internal API error.'] : $data,
            ], JSON_THROW_ON_ERROR));
        }
        if ($this->name === 'woodworking_export_project') {
            return Response::make(Response::resourceLink($data['resource_uri'], $data['filename'], $data['mime_type']))
                ->withStructuredContent($data);
        }

        return Response::structured($data);
    }
}
