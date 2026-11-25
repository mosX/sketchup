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
use Tests\TestCase;

class ProjectAssemblyInstructionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_sequence_check_reads_saved_steps_and_respects_the_expected_revision(): void
    {
        $project = Project::factory()->create();
        $part = PartDefinition::factory()->for($project)->create(['name' => 'Underside']);
        $first = PartInstance::factory()->for($part)->create();
        $second = PartInstance::factory()->for($part)->create();
        $fastener = ProjectFastener::factory()->create([
            'project_id' => $project->id, 'source_instance_id' => $first->id, 'target_instance_id' => $second->id,
        ]);
        $steps = [['title' => 'Fasten', 'instance_ids' => [$first->id], 'fastener_ids' => [$fastener->id]]];
        $project->forceFill(['assembly_instructions' => $steps])->save();
        $this->actingAs($project->user);
        Process::fake([
            '*' => function (PendingProcess $process) use ($steps, $first, $fastener): mixed {
                $input = json_decode($process->input, true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($steps, $input['steps']);
                $this->assertSame(['id' => $first->id, 'name' => 'Underside'], $input['instances'][0]);
                $this->assertSame($fastener->id, $input['fasteners'][0]['id']);

                return Process::result('{"status":"issues","step_count":1,"issue_count":2,"issues":[]}');
            },
        ]);

        $path = "/api/v1/projects/{$project->id}/assembly-instructions/check";
        $this->getJson($path.'?expected_revision=1')->assertOk()
            ->assertJsonPath('data.revision', 1)->assertJsonPath('data.issue_count', 2);
        $this->getJson($path.'?expected_revision=2')->assertConflict();
        $this->getJson($path.'?expected_revision=bad')->assertUnprocessable();
        $this->assertSame($steps, $project->fresh()->assembly_instructions);
    }

    public function test_suggests_ordered_steps_and_previews_then_saves_custom_steps(): void
    {
        $project = Project::factory()->create(['revision' => 1]);
        $group = AssemblyGroup::factory()->for($project)->create(['name' => 'Крышка']);
        $board = PartDefinition::factory()->for($project)->create();
        $base = PartInstance::factory()->for($board)->create();
        $lid = PartInstance::factory()->for($board)->create(['assembly_group_id' => $group->id]);
        $screw = ProjectFastener::factory()->create([
            'project_id' => $project->id,
            'source_instance_id' => $lid->id,
            'target_instance_id' => $base->id,
        ]);
        $path = "/api/v1/projects/{$project->id}/assembly-instructions";
        $this->actingAs($project->user);

        $this->getJson($path)->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('suggested_steps.0.instance_ids', [$base->id])
            ->assertJsonPath('suggested_steps.1.title', 'Крышка')
            ->assertJsonPath('suggested_steps.1.instance_ids', [$lid->id]);

        $steps = [
            ['title' => 'Подготовьте основание', 'instance_ids' => [$base->id]],
            [
                'title' => 'Закрепите крышку',
                'instruction' => 'Установите крышку на основание.',
                'notes' => 'Проверьте зазор справа.',
                'instance_ids' => [$lid->id],
                'highlight_instance_ids' => [$lid->id, $base->id],
                'fastener_ids' => [$screw->id],
                'camera' => ['position' => ['x' => 250, 'y' => -480, 'z' => 640], 'target' => ['x' => 0, 'y' => 0, 'z' => 10]],
            ],
        ];
        $this->putJson($path, ['steps' => $steps])->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('revision', 1);
        $this->assertNull($project->fresh()->assembly_instructions);

        $commit = ['steps' => $steps, 'dry_run' => false, 'expected_revision' => 1];
        $this->putJson($path, $commit)->assertOk()->assertJsonPath('revision', 2);
        $this->getJson($path)->assertOk()
            ->assertJsonPath('data.1.notes', 'Проверьте зазор справа.')
            ->assertJsonPath('data.1.highlight_instance_ids', [$lid->id, $base->id])
            ->assertJsonPath('data.1.fastener_ids', [$screw->id])
            ->assertJsonPath('data.1.camera.position.y', -480);
        $this->putJson($path, $commit)->assertConflict()->assertJsonPath('current_revision', 2);
        $this->putJson($path, [...$commit, 'expected_revision' => 2])->assertOk()->assertJsonPath('revision', 2);
        $this->putJson($path, [...$commit, 'steps' => [], 'expected_revision' => 2])->assertOk()->assertJsonPath('revision', 3);
        $this->getJson($path)->assertJsonPath('data', []);
    }

    public function test_rejects_foreign_references_missing_revision_and_invalid_camera(): void
    {
        $project = Project::factory()->create();
        $foreignFastener = ProjectFastener::factory()->create();
        $path = "/api/v1/projects/{$project->id}/assembly-instructions";
        $this->actingAs($project->user);
        $step = ['title' => 'Fix the lid', 'instance_ids' => []];

        $this->putJson($path, ['dry_run' => false, 'steps' => [$step]])->assertUnprocessable()
            ->assertJsonValidationErrors('expected_revision');
        $point = ['x' => 1, 'y' => 2, 'z' => 3];
        $this->putJson($path, ['steps' => [[...$step, 'camera' => ['position' => $point, 'target' => $point]]]])
            ->assertUnprocessable()->assertJsonValidationErrors('steps.0.camera');
        $this->putJson($path, ['steps' => [[...$step, 'camera' => ['position' => ['x' => 1], 'target' => $point]]]])
            ->assertUnprocessable()->assertJsonValidationErrors('steps.0.camera.position');
        $this->putJson($path, ['steps' => [[...$step,
            'instance_ids' => [$foreignFastener->source_instance_id],
            'highlight_instance_ids' => [$foreignFastener->source_instance_id],
            'fastener_ids' => [$foreignFastener->id],
        ]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['steps.0.instance_ids', 'steps.0.highlight_instance_ids', 'steps.0.fastener_ids']);
        $this->assertNull($project->fresh()->assembly_instructions);
    }

    public function test_read_key_cannot_write_and_project_key_cannot_access_other_instructions(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->for($project->user)->create();
        $this->withToken($project->user->createToken('Guide', ['projects:read', "project:{$project->id}"])->plainTextToken);

        $this->getJson("/api/v1/projects/{$project->id}/assembly-instructions")->assertOk();
        $this->putJson("/api/v1/projects/{$project->id}/assembly-instructions", ['steps' => []])->assertForbidden();
        $this->getJson("/api/v1/projects/{$other->id}/assembly-instructions")->assertNotFound();
    }
}
