<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectEstimate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProjectEstimate> */
class ProjectEstimateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'snapshot' => ['costing' => ['currency' => 'UAH', 'items' => [], 'issues' => []]],
        ];
    }
}
