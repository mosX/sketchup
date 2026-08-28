<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreApiTokenRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->tokenCan('*') || $request->user()->tokenCan('tokens:manage'), Response::HTTP_FORBIDDEN);

        $tokens = $request->user()->tokens()->latest()->get()->map(fn (PersonalAccessToken $token): array => [
            'id' => $token->id,
            'name' => $token->name,
            'abilities' => $token->abilities ?? [],
            'last_used_at' => $token->last_used_at,
            'expires_at' => $token->expires_at,
            'created_at' => $token->created_at,
        ]);

        return response()->json(['data' => $tokens]);
    }

    public function store(StoreApiTokenRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $abilities = $validated['abilities'];

        if (isset($validated['project_id'])) {
            $abilities[] = 'project:'.$validated['project_id'];
        }

        $expiresAt = now()->addDays((int) ($validated['expires_in_days'] ?? 30));
        $newToken = $request->user()->createToken($validated['name'], $abilities, $expiresAt);

        return response()->json([
            'data' => [
                'id' => $newToken->accessToken->id,
                'name' => $validated['name'],
                'abilities' => $abilities,
                'expires_at' => $expiresAt,
            ],
            'token' => $newToken->plainTextToken,
        ], Response::HTTP_CREATED);
    }

    public function destroy(Request $request, int $apiToken): Response
    {
        abort_unless($request->user()->tokenCan('*') || $request->user()->tokenCan('tokens:manage'), Response::HTTP_FORBIDDEN);

        $request->user()->tokens()->whereKey($apiToken)->firstOrFail()->delete();

        return response()->noContent();
    }
}
