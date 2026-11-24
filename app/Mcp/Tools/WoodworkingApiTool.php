<?php

namespace App\Mcp\Tools;

use Illuminate\Http\Request as HttpRequest;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Facade;
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

    public function handle(Request $request, HttpRequest $http, Router $router): Response|ResponseFactory
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
            'woodworking_apply_commands' => ['dry_run' => true, ...$payload],
            default => $payload,
        };

        $subrequest = HttpRequest::create(
            '/api/v1'.$path,
            $this->method,
            $this->method === 'GET' ? $payload : [],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => $http->headers->get('Authorization'),
                'CONTENT_TYPE' => 'application/json',
                'REMOTE_ADDR' => $http->ip(),
                'HTTP_HOST' => $http->getHttpHost(),
                'HTTPS' => $http->isSecure() ? 'on' : 'off',
            ],
            content: $this->method === 'GET' ? null : json_encode($payload, JSON_THROW_ON_ERROR),
        );
        $subrequest->setUserResolver($http->getUserResolver());

        // Give form requests and resources the API context while preserving the MCP route.
        $application = app();
        $application->instance('request', $subrequest);
        Facade::clearResolvedInstance('request');

        try {
            $response = (clone $router)->dispatch($subrequest);
        } finally {
            $application->instance('request', $http);
            $application->instance(Route::class, $http->route());
            Facade::clearResolvedInstance('request');
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

        return Response::structured($data);
    }
}
