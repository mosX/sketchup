<?php

namespace Tests\Feature;

use App\Models\AssemblyGroup;
use App\Models\PartDefinition;
use App\Models\PartInstance;
use App\Models\Project;
use App\Models\ProjectFastener;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectAssemblyCheckTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_geometry_check_uses_saved_snapshot_and_preserves_results_and_coverage(): void
    {
        $project = Project::factory()->create(['revision' => 4]);
        $group = AssemblyGroup::factory()->for($project)->create(['is_visible' => false]);
        $part = PartDefinition::factory()->for($project)->create(['operations' => [['id' => 'hole', 'type' => 'drill', 'status' => 'applied']]]);
        $source = PartInstance::factory()->for($part)->create(['assembly_group_id' => $group->id]);
        $fastener = ProjectFastener::factory()->create(['project_id' => $project->id, 'source_instance_id' => $source->id]);
        Sanctum::actingAs($project->user, ['projects:read']);
        Process::fake([
            '*' => function (PendingProcess $process) use ($source, $fastener): mixed {
                $input = json_decode($process->input, true, flags: JSON_THROW_ON_ERROR);
                $this->assertTrue($input['assembly']);
                $this->assertSame($source->id, $input['parts'][0]['instances'][0]['id']);
                $this->assertSame('applied', $input['parts'][0]['operations'][0]['status']);
                $this->assertFalse($input['groups'][0]['is_visible']);
                $this->assertSame($fastener->id, $input['fasteners'][0]['id']);

                return Process::result('{"intersections":{"status":"partial","failed_pairs":1,"issues":[]},"fasteners":{"total":1}}');
            },
        ]);
        $path = "/api/v1/projects/{$project->id}/assembly-check";
        $this->getJson($path.'?expected_revision=4')->assertOk()
            ->assertJsonPath('data.project_id', $project->id)
            ->assertJsonPath('data.revision', 4)
            ->assertJsonPath('data.intersections.status', 'partial')
            ->assertJsonPath('data.intersections.failed_pairs', 1)
            ->assertJsonPath('data.fasteners.total', 1);
        $this->assertSame(4, $project->fresh()->revision);
        $this->getJson($path.'?expected_revision=3')->assertConflict()->assertJsonPath('current_revision', 4);
        $this->getJson($path.'?expected_revision=bad')->assertUnprocessable();
        Process::assertRanTimes(fn (PendingProcess $process): bool => str_contains(implode(' ', (array) $process->command), 'fastenerAnalysisCli.js'), 1);
    }

    public function test_geometry_runner_failure_is_reported_as_unavailable(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user, ['projects:read']);
        Process::fake(['*' => Process::result(errorOutput: 'node failed', exitCode: 1)]);

        $this->getJson("/api/v1/projects/{$project->id}/assembly-check")->assertStatus(503);
    }
}
