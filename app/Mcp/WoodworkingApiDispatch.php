<?php

namespace App\Mcp;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response;

class WoodworkingApiDispatch
{
    public function __construct(private Router $router) {}

    /** @param array<string, mixed> $payload */
    public function handle(Request $http, string $method, string $path, array $payload = []): Response
    {
        $subrequest = Request::create(
            '/api/v1'.$path,
            $method,
            $method === 'GET' ? $payload : [],
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => $http->headers->get('Authorization'),
                'CONTENT_TYPE' => 'application/json',
                'REMOTE_ADDR' => $http->ip(),
                'HTTP_HOST' => $http->getHttpHost(),
                'HTTPS' => $http->isSecure() ? 'on' : 'off',
            ],
            content: $method === 'GET' ? null : json_encode($payload, JSON_THROW_ON_ERROR),
        );
        $subrequest->setUserResolver($http->getUserResolver());

        // Give form requests and resources the API context while preserving the MCP route.
        $application = app();
        $application->instance('request', $subrequest);
        Facade::clearResolvedInstance('request');

        try {
            return (clone $this->router)->dispatch($subrequest);
        } finally {
            $application->instance('request', $http);
            $application->instance(Route::class, $http->route());
            Facade::clearResolvedInstance('request');
        }
    }
}
