<?php

namespace Tests\Feature;

use App\Models\AssemblyGroup;
use App\Models\PartDefinition;
use App\Models\PartInstance;
use App\Models\Project;
use App\Models\ProjectConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectVersionControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_restore_preserves_ids_and_creates_a_restorable_backup(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['revision' => 1]);
        $part = PartDefinition::factory()->for($project)->create(['name' => 'Original']);
        $instance = PartInstance::factory()->for($part)->create();
        $this->actingAs($user);
        $version = $this->postJson("/api/projects/{$project->id}/versions", ['expected_revision' => 1, 'label' => 'Before'])
            ->assertCreated()->assertJsonMissingPath('data.snapshot')->json('data.id');
        $part->update(['name' => 'Changed']);
        $instance->update(['position_z' => 123]);
        $project->update(['revision' => 2]);
        $this->postJson("/api/projects/{$project->id}/versions/{$version}/restore", ['expected_revision' => 2])
            ->assertOk()->assertJsonPath('data.revision', 3);
        $this->assertSame('Original', $part->fresh()->name);
        $this->assertEquals(0, $instance->fresh()->position_z);
        $backup = $project->projectVersions()->latest('id')->firstOrFail();
        $this->assertNotEquals($version, $backup->id);
        $this->postJson("/api/projects/{$project->id}/versions/{$backup->id}/restore", ['expected_revision' => 3])->assertOk();
        $this->assertSame('Changed', $part->fresh()->name);
        $this->assertEquals(123, $instance->fresh()->position_z);
    }

    public function test_stale_revisions_and_foreign_versions_cannot_replace_a_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['revision' => 1]);
        $other = Project::factory()->for($user)->create(['revision' => 1]);
        $this->actingAs($user);
        $version = $this->postJson("/api/projects/{$other->id}/versions", ['expected_revision' => 1])->assertCreated()->json('data.id');
        $this->postJson("/api/projects/{$project->id}/versions/{$version}/restore", ['expected_revision' => 1])->assertNotFound();
        $this->postJson("/api/projects/{$project->id}/versions", ['expected_revision' => 2])->assertConflict();
        $this->assertSame(0, $project->projectVersions()->count());
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->getJson("/api/projects/{$project->id}/versions")->assertNotFound();
    }

    public function test_restore_recovers_nested_groups_connections_and_visibility(): void
    {
        $project = Project::factory()->create(['revision' => 1]);
        $parent = AssemblyGroup::factory()->for($project)->create();
        $child = AssemblyGroup::factory()->for($project)->create(['parent_id' => $parent->id, 'is_visible' => false]);
        $part = PartDefinition::factory()->for($project)->create();
        $instance = PartInstance::factory()->for($part)->create(['assembly_group_id' => $child->id]);
        $connection = ProjectConnection::factory()->create(['primary_instance_id' => $instance->id]);
        Sanctum::actingAs($project->user, ['projects:write']);
        $version = $this->postJson("/api/projects/{$project->id}/versions", ['expected_revision' => 1])->assertCreated()->json('data.id');
        $connection->delete();
        $child->update(['parent_id' => null, 'is_visible' => true]);
        $this->postJson("/api/projects/{$project->id}/versions/{$version}/restore", ['expected_revision' => 1])->assertOk();
        $this->assertSame($parent->id, $child->fresh()->parent_id);
        $this->assertFalse($child->fresh()->is_visible);
        $this->assertSame($child->id, $instance->fresh()->assembly_group_id);
        $this->assertSame($instance->id, ProjectConnection::query()->findOrFail($connection->id)->primary_instance_id);
    }

    public function test_read_only_token_cannot_create_or_restore_versions(): void
    {
        $project = Project::factory()->create(['revision' => 1]);
        Sanctum::actingAs($project->user, ['projects:write']);
        $version = $this->postJson("/api/projects/{$project->id}/versions", ['expected_revision' => 1])->assertCreated()->json('data.id');
        Sanctum::actingAs($project->user, ['projects:read']);
        $this->getJson("/api/projects/{$project->id}/versions")->assertOk()->assertJsonMissingPath('data.0.snapshot');
        $this->postJson("/api/projects/{$project->id}/versions", ['expected_revision' => 1])->assertForbidden();
        $this->postJson("/api/projects/{$project->id}/versions/{$version}/restore", ['expected_revision' => 1])->assertForbidden();
        $this->assertSame(1, $project->fresh()->revision);
    }
}
