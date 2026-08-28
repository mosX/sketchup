<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProjectRevisionConflictException extends Exception
{
    public function __construct(
        public readonly int $expectedRevision,
        public readonly int $currentRevision,
    ) {
        parent::__construct('The project changed after the agent loaded it.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => [
                'expected_revision' => ['Reload the project and retry with its current revision.'],
            ],
            'expected_revision' => $this->expectedRevision,
            'current_revision' => $this->currentRevision,
        ], Response::HTTP_CONFLICT);
    }
}
