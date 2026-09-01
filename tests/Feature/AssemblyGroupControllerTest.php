<?php

namespace Tests\Feature;

use App\Models\AssemblyGroup;
use App\Models\PartDefinition;
use App\Models\PartInstance;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssemblyGroupControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_valid_payload_creates_nested_group_and_returns_201(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $parent = AssemblyGroup::factory()->for($project)->create(['name' => 'Каркас']);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/projects/{$project->id}/assembly-groups", [
            'name' => 'Стойки',
            'parent_id' => $parent->id,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Стойки')
            ->assertJsonPath('data.parent_id', $parent->id)
            ->assertJsonPath('data.is_visible', true);

        $this->assertDatabaseHas('assembly_groups', [
            'project_id' => $project->id,
            'parent_id' => $parent->id,
            'name' => 'Стойки',
        ]);
    }

    public function test_returns_422_when_parent_group_belongs_to_another_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $foreignGroup = AssemblyGroup::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/assembly-groups", [
            'name' => 'Недопустимый узел',
            'parent_id' => $foreignGroup->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        $this->assertDatabaseMissing('assembly_groups', [
            'project_id' => $project->id,
            'name' => 'Недопустимый узел',
        ]);
    }

    public function test_group_of_another_project_returns_404(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $foreignGroup = AssemblyGroup::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson("/api/projects/{$project->id}/assembly-groups/{$foreignGroup->id}", [
            'name' => 'Недоступный узел',
        ])->assertNotFound();
    }

    public function test_returns_422_when_group_is_moved_into_its_descendant(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $parent = AssemblyGroup::factory()->for($project)->create();
        $child = AssemblyGroup::factory()->for($project)->create(['parent_id' => $parent->id]);
        Sanctum::actingAs($user);

        $this->patchJson("/api/projects/{$project->id}/assembly-groups/{$parent->id}", [
            'parent_id' => $child->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        $this->assertNull($parent->refresh()->parent_id);
    }

    public function test_deleting_group_without_contents_moves_children_and_instances_to_parent(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $root = AssemblyGroup::factory()->for($project)->create();
        $group = AssemblyGroup::factory()->for($project)->create(['parent_id' => $root->id]);
        $child = AssemblyGroup::factory()->for($project)->create(['parent_id' => $group->id]);
        $part = PartDefinition::factory()->for($project)->create();
        $instance = PartInstance::factory()->for($project)->for($part)->create(['assembly_group_id' => $group->id]);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/projects/{$project->id}/assembly-groups/{$group->id}")
            ->assertNoContent();

        $this->assertModelMissing($group);
        $this->assertSame($root->id, $child->refresh()->parent_id);
        $this->assertSame($root->id, $instance->refresh()->assembly_group_id);
    }

    public function test_deleting_group_with_contents_removes_descendant_instances(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $group = AssemblyGroup::factory()->for($project)->create();
        $child = AssemblyGroup::factory()->for($project)->create(['parent_id' => $group->id]);
        $part = PartDefinition::factory()->for($project)->create();
        $instance = PartInstance::factory()->for($project)->for($part)->create(['assembly_group_id' => $child->id]);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/projects/{$project->id}/assembly-groups/{$group->id}", [
            'delete_contents' => true,
        ])->assertNoContent();

        $this->assertModelMissing($group);
        $this->assertModelMissing($child);
        $this->assertModelMissing($instance);
        $this->assertModelExists($part);
    }

    public function test_instance_can_be_assigned_only_to_a_group_from_its_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $part = PartDefinition::factory()->for($project)->create();
        $instance = PartInstance::factory()->for($project)->for($part)->create();
        $group = AssemblyGroup::factory()->for($project)->create();
        $foreignGroup = AssemblyGroup::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson("/api/projects/{$project->id}/instances/{$instance->id}", [
            'assembly_group_id' => $group->id,
        ])->assertOk()->assertJsonPath('data.assembly_group_id', $group->id);

        $this->patchJson("/api/projects/{$project->id}/instances/{$instance->id}", [
            'assembly_group_id' => $foreignGroup->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('assembly_group_id');

        $this->assertSame($group->id, $instance->refresh()->assembly_group_id);
    }
}
