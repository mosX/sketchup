<?php

namespace App\Actions\Connections;

use App\Models\Project;
use App\Models\ProjectConnection;
use Illuminate\Support\Facades\DB;

class UpdateProjectConnection
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Project $project, ProjectConnection $connection, array $attributes): ProjectConnection
    {
        return DB::transaction(function () use ($attributes, $connection, $project): ProjectConnection {
            $connection = ProjectConnection::query()->lockForUpdate()->findOrFail($connection->id);
            $parametersChanged = array_key_exists('parameters', $attributes)
                && $attributes['parameters'] !== $connection->parameters;

            if ($parametersChanged) {
                $attributes['parameters'] = [
                    ...($connection->parameters ?? []),
                    ...$attributes['parameters'],
                ];
                $attributes['is_verified'] = false;

                if ($connection->machining_status === 'generated') {
                    $attributes['machining_status'] = 'outdated';
                }
            }

            $connection->update($attributes);
            $project->increment('revision');

            return $connection->refresh();
        });
    }
}
