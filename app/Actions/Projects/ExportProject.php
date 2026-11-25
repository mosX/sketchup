<?php

namespace App\Actions\Projects;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class ExportProject
{
    /**
     * @param  array<string, mixed>  $model
     * @param  array<string, mixed>  $options
     */
    public function handle(array $model, array $options): string
    {
        if (collect($model['parts'])->every(fn (array $part): bool => empty($part['instances']))) {
            abort(422, 'The project has no visible instances.');
        }
        $process = Process::path(base_path())->timeout(50)
            ->input(json_encode(['model' => $model, ...$options], JSON_THROW_ON_ERROR));
        if ($binary = config('services.woodworking.chromium')) {
            $process->env(['WOODWORKING_CHROMIUM_BINARY' => $binary]);
        }
        try {
            $result = $process->run([config('services.woodworking.node', 'node'), resource_path('js/editor/projectExportCli.js')]);
        } catch (RuntimeException) {
            abort(503, 'Project renderer is unavailable.');
        }
        if (! $result->successful()) {
            if (str_contains($result->errorOutput(), 'The project has no visible instances.')) {
                abort(422, 'The project has no visible instances.');
            }
            logger()->warning('Project export renderer failed', ['message' => mb_substr($result->errorOutput(), 0, 1200)]);
            abort(503, 'Project renderer is unavailable. Check the configured Node and Chromium binaries.');
        }
        $output = $result->output();
        $valid = match ($options['format']) {
            'png' => str_starts_with($output, "\x89PNG\r\n\x1a\n"),
            'glb' => str_starts_with($output, 'glTF'),
            'obj' => str_starts_with($output, 'o '),
        };
        abort_unless($valid, 503, 'The renderer did not return a valid file.');

        return $output;
    }
}
