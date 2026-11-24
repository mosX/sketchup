<?php

namespace Tests\Feature;

use App\Models\CatalogMaterial;
use App\Models\PartDefinition;
use App\Models\PartInstance;
use App\Models\Project;
use App\Models\ProjectFastener;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectFastenerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_screw_row_is_placed_atomically_and_counted_together_with_manual_supplies(): void
    {
        $project = Project::factory()->create();
        $sourcePart = PartDefinition::factory()->for($project)->create(['length' => 500, 'width' => 100, 'thickness' => 18]);
        [$source, $target] = PartInstance::factory()->count(2)->for($project)->for($sourcePart)->create();
        Sanctum::actingAs($project->user, ['projects:read', 'projects:write']);
        $screwId = $this->postJson('/api/v1/materials', [
            'name' => 'Screw', 'kind' => 'consumable', 'price' => 200, 'pack_quantity' => 100,
            'fastener_diameter' => 3.4, 'fastener_length' => 25,
        ])->assertCreated()
            ->assertJsonPath('data.unit', 'шт.')
            ->assertJsonPath('data.fastener_type', 'screw')
            ->assertJsonPath('data.fastener_diameter', '3.40')
            ->json('data.id');
        $base = "/api/v1/projects/{$project->id}";
        $payload = [
            'source_instance_id' => $source->id, 'target_instance_id' => $target->id, 'catalog_material_id' => $screwId,
            'face' => 'top', 'center_u' => 20, 'center_v' => 40, 'sink' => 2,
        ];
        $row = ['count' => 3, 'row_axis' => 'u', 'spacing' => 40];

        $this->postJson("$base/fasteners", [...$payload, ...$row, 'row_axis' => 'v'])
            ->assertUnprocessable()->assertJsonValidationErrors('center_u');
        $this->assertDatabaseCount('project_fasteners', 0);
        $created = $this->postJson("$base/fasteners", [...$payload, ...$row])->assertCreated()
            ->assertJsonCount(3, 'data')->assertJsonPath('data.0.fastener_type', 'screw');
        $this->assertSame([20, 60, 100], array_column($created->json('data'), 'center_u'));
        $this->assertSame(2, $project->refresh()->revision);

        $this->putJson("$base/materials", [
            'default_material_id' => null, 'assignments' => [],
            'supplies' => [['material_id' => $screwId, 'quantity' => 2]],
        ])->assertOk();
        $this->getJson("$base/analysis")->assertOk()
            ->assertJsonPath('data.manufacturing.costing.items.0.scene_quantity', 3)
            ->assertJsonPath('data.manufacturing.costing.items.0.manual_quantity', 2)
            ->assertJsonPath('data.manufacturing.costing.items.0.quantity', 5)
            ->assertJsonPath('data.manufacturing.costing.items.0.packs', 1)
            ->assertJsonPath('data.manufacturing.costing.items.0.consumed_minor', 1000);

        $id = $created->json('data.0.id');
        CatalogMaterial::query()->findOrFail($screwId)->update(['fastener_length' => 40]);
        $this->putJson("$base/fasteners/$id", [...$payload, 'center_u' => 25.5])->assertOk()
            ->assertJsonPath('data.center_u', 25.5)->assertJsonPath('data.length', 25);
        $this->getJson($base)->assertOk()->assertJsonCount(3, 'data.fasteners');
        $this->deleteJson("$base/fasteners", ['ids' => array_column($created->json('data'), 'id')])->assertNoContent();
        $this->assertDatabaseCount('project_fasteners', 0);
    }

    public function test_rows_and_copies_accept_a_distinct_second_detail_per_position(): void
    {
        $original = ProjectFastener::factory()->create();
        $project = Project::query()->findOrFail($original->project_id);
        $source = $project->partInstances()->findOrFail($original->source_instance_id);
        [$left, $right] = PartInstance::factory()->count(2)->for($project)->for($source->partDefinition)->create();
        $foreign = ProjectFastener::factory()->create();
        Sanctum::actingAs($project->user, ['projects:write']);
        $path = "/api/v1/projects/{$project->id}/fasteners";
        $placement = [
            ...$original->only(['source_instance_id', 'catalog_material_id', 'face', 'center_u', 'center_v', 'sink']),
            'count' => 3, 'row_axis' => 'u', 'spacing' => 10,
        ];
        $this->postJson($path, [...$placement, 'row_target_instance_ids' => [$left->id, $right->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('row_target_instance_ids');
        $this->postJson($path, [...$placement, 'row_target_instance_ids' => [$left->id, null, $foreign->source_instance_id]])
            ->assertUnprocessable()->assertJsonValidationErrors('target_instance_id');
        $this->assertSame(1, $project->projectFasteners()->count());
        $saved = $this->postJson($path, [...$placement, 'row_target_instance_ids' => [$left->id, null, $right->id]])->assertCreated();
        $this->assertSame([$left->id, null, $right->id], array_column($saved->json('data'), 'target_instance_id'));

        $ids = array_column($saved->json('data'), 'id');
        $copy = ['fastener_ids' => array_reverse($ids), 'source_instance_id' => $source->id, 'face' => 'bottom', 'dry_run' => false, 'expected_revision' => 2];
        $this->postJson("$path/copy", [...$copy, 'row_target_instance_ids' => [$left->id, $right->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('row_target_instance_ids');
        $this->postJson("$path/copy", [...$copy, 'row_target_instance_ids' => [$left->id, null, $source->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('row_target_instance_ids.2');
        $copied = $this->postJson("$path/copy", [...$copy, 'row_target_instance_ids' => [$right->id, $left->id, null]])->assertCreated();
        $this->assertSame([20, 30, 40], array_column($copied->json('data.placements'), 'center_u'));
        $this->assertSame([$right->id, $left->id, null], array_column($copied->json('data.placements'), 'target_instance_id'));
    }

    public function test_nail_rows_preserve_their_type_in_copies_and_count_with_manual_supplies(): void
    {
        $project = Project::factory()->create();
        $stock = PartDefinition::factory()->for($project)->create(['length' => 500, 'width' => 100, 'thickness' => 18]);
        [$source, $destination] = PartInstance::factory()->count(2)->for($project)->for($stock)->create();
        Sanctum::actingAs($project->user, ['projects:read', 'projects:write']);
        $catalogItem = ['name' => 'Nail', 'kind' => 'consumable', 'price' => 60, 'pack_quantity' => 100, 'fastener_type' => 'nail'];
        $this->postJson('/api/v1/materials', $catalogItem)->assertUnprocessable()
            ->assertJsonValidationErrors(['fastener_diameter', 'fastener_length']);
        $dimensions = ['fastener_diameter' => 2.5, 'fastener_length' => 40];
        $id = $this->postJson('/api/v1/materials', [...$catalogItem, ...$dimensions])
            ->assertCreated()->assertJsonPath('data.fastener_type', 'nail')->assertJsonPath('data.unit', 'шт.')->json('data.id');
        $base = "/api/v1/projects/{$project->id}";
        $placement = ['source_instance_id' => $source->id, 'catalog_material_id' => $id, 'face' => 'top', 'center_u' => 20, 'center_v' => 40, 'sink' => 0];
        $row = $this->postJson("$base/fasteners", [...$placement, 'count' => 2, 'row_axis' => 'u', 'spacing' => 40])
            ->assertCreated()->assertJsonPath('data.1.fastener_type', 'nail')->assertJsonPath('data.1.center_u', 60);
        $ids = array_column($row->json('data'), 'id');

        $this->postJson("$base/fasteners/drilling", [
            'fastener_ids' => $ids, 'clearance_diameter' => 3, 'pilot' => false, 'dry_run' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('drilling');
        $this->putJson("/api/v1/materials/$id", [...$catalogItem, ...$dimensions, 'fastener_type' => 'screw'])
            ->assertOk()->assertJsonPath('data.fastener_type', 'screw');
        $this->postJson("$base/fasteners/copy", [
            'fastener_ids' => $ids, 'source_instance_id' => $destination->id, 'face' => 'top',
            'mirror_u' => true, 'dry_run' => false, 'expected_revision' => 2,
        ])->assertCreated()->assertJsonPath('data.placements.0.fastener_type', 'nail')
            ->assertJsonPath('data.placements.0.center_u', 480);
        $this->putJson("$base/materials", [
            'default_material_id' => null, 'assignments' => [],
            'supplies' => [['material_id' => $id, 'quantity' => 3]],
        ])->assertOk();
        $this->getJson("$base/analysis")->assertOk()
            ->assertJsonPath('data.manufacturing.costing.items.0.scene_quantity', 4)
            ->assertJsonPath('data.manufacturing.costing.items.0.manual_quantity', 3)
            ->assertJsonPath('data.manufacturing.costing.items.0.quantity', 7)
            ->assertJsonPath('data.manufacturing.costing.items.0.consumed_minor', 420);
    }

    public function test_copying_previews_and_mirrors_a_row_with_its_drilling_settings_without_machining_the_destination(): void
    {
        $project = Project::factory()->create();
        $stock = PartDefinition::factory()->for($project)->create(['length' => 500, 'width' => 100, 'thickness' => 18]);
        $destinationStock = PartDefinition::factory()->for($project)->create(['length' => 600, 'width' => 120, 'thickness' => 18]);
        $source = PartInstance::factory()->for($project)->for($stock)->create(['position_z' => 18]);
        $target = PartInstance::factory()->for($project)->for($stock)->create(['position_z' => 0]);
        $destination = PartInstance::factory()->for($project)->for($destinationStock)->create();
        $material = CatalogMaterial::factory()->consumable()->create(['user_id' => $project->user_id]);
        $first = ProjectFastener::factory()->create([
            'project_id' => $project->id, 'source_instance_id' => $source->id, 'target_instance_id' => $target->id,
            'catalog_material_id' => $material->id, 'center_u' => 20, 'center_v' => 30, 'sink' => 1,
        ]);
        $second = $project->projectFasteners()->create([...$first->getAttributes(), 'center_u' => 77.5]);
        Sanctum::actingAs($project->user, ['projects:write']);
        $path = "/api/v1/projects/{$project->id}/fasteners";
        $ids = [$first->id, $second->id];
        $this->postJson("$path/drilling", [
            'fastener_ids' => $ids, 'clearance_diameter' => 4,
            'countersink' => true, 'countersink_diameter' => 8, 'countersink_depth' => 2,
            'pilot' => true, 'pilot_diameter' => 2.5, 'pilot_depth' => 7,
            'dry_run' => false, 'expected_revision' => 1,
        ])->assertOk();
        $material->update(['fastener_diameter' => 5, 'fastener_length' => 40]);
        $copy = ['fastener_ids' => $ids, 'source_instance_id' => $destination->id, 'face' => 'bottom', 'mirror_u' => true, 'mirror_v' => true];
        $preview = $this->postJson("$path/copy", [...$copy, 'dry_run' => true])
            ->assertOk()->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.placements.0.center_u', 580)
            ->assertJsonPath('data.placements.1.center_u', 522.5)
            ->assertJsonPath('data.placements.0.center_v', 90)
            ->assertJsonPath('data.placements.0.sink', 1)
            ->assertJsonPath('data.placements.0.length', 25)
            ->assertJsonPath('data.placements.0.diameter', 3.4)
            ->assertJsonPath('data.placements.0.target_instance_id', null)
            ->assertJsonPath('data.drilling_settings.clearance_diameter', 4)
            ->assertJsonPath('data.drilling_settings.countersink_diameter', 8)
            ->assertJsonPath('data.drilling_settings.countersink_depth', 2)
            ->assertJsonPath('data.drilling_settings.pilot_diameter', 2.5)
            ->assertJsonPath('data.drilling_settings.pilot_depth', 7);
        $this->assertDatabaseCount('project_fasteners', 2);
        $this->postJson("$path/copy", [...$copy, 'dry_run' => false, 'expected_revision' => 1])->assertConflict();
        $saved = $this->postJson("$path/copy", [...$copy, 'dry_run' => false, 'expected_revision' => $preview->json('data.revision')])
            ->assertCreated()->assertJsonPath('data.revision', 3)->assertJsonCount(2, 'data.placements');
        $this->assertSame([], $destinationStock->refresh()->operations);
        $this->postJson("$path/copy", [...$copy, 'dry_run' => true])->assertUnprocessable()->assertJsonValidationErrors('face');
        $this->deleteJson($path, ['ids' => array_column($saved->json('data.placements'), 'id')])->assertNoContent();
        $this->assertDatabaseCount('project_fasteners', 2);
    }

    public function test_copying_rejects_mixed_faces_and_out_of_bounds_rows_as_a_batch(): void
    {
        $first = ProjectFastener::factory()->create();
        $project = Project::query()->findOrFail($first->project_id);
        $stock = PartDefinition::factory()->for($project)->create(['length' => 40, 'width' => 50, 'thickness' => 18]);
        $destination = PartInstance::factory()->for($project)->for($stock)->create();
        $second = $project->projectFasteners()->create([...$first->getAttributes(), 'center_u' => 39, 'face' => 'bottom']);
        $foreign = ProjectFastener::factory()->create();
        Sanctum::actingAs($project->user, ['projects:write']);
        $path = "/api/v1/projects/{$project->id}/fasteners/copy";
        $payload = [
            'fastener_ids' => [$first->id, $second->id], 'source_instance_id' => $destination->id,
            'face' => 'top', 'dry_run' => false, 'expected_revision' => 1,
        ];

        $this->postJson($path, [...$payload, 'fastener_ids' => [$first->id, $foreign->id]])->assertNotFound();
        $this->postJson($path, $payload)->assertUnprocessable()->assertJsonValidationErrors('fastener_ids');
        $second->update(['face' => 'top']);
        $this->postJson($path, $payload)->assertUnprocessable()->assertJsonValidationErrors('face');
        $this->assertSame(2, $project->projectFasteners()->count());
        $this->assertSame(1, $project->refresh()->revision);
    }

    public function test_fasteners_cannot_use_parts_or_catalog_items_from_other_projects_or_accounts(): void
    {
        $fastener = ProjectFastener::factory()->create();
        $project = Project::query()->findOrFail($fastener->project_id);
        $other = ProjectFastener::factory()->create();
        Sanctum::actingAs($project->user, ['projects:read', 'projects:write']);
        $base = "/api/v1/projects/{$project->id}/fasteners";
        $payload = $fastener->only(['source_instance_id', 'catalog_material_id', 'face', 'center_u', 'center_v', 'sink']);

        $this->postJson($base, [...$payload, 'target_instance_id' => $other->source_instance_id])
            ->assertUnprocessable()->assertJsonValidationErrors('target_instance_id');
        $this->postJson($base, [...$payload, 'catalog_material_id' => $other->catalog_material_id])
            ->assertUnprocessable()->assertJsonValidationErrors('catalog_material_id');
        $this->deleteJson($base, ['ids' => [$fastener->id, $other->id]])->assertNotFound();
        $this->assertModelExists($fastener);
        $this->assertModelExists($other);
    }

    public function test_drilling_previews_a_shared_board_and_a_rotated_mirrored_target_then_applies_and_undoes_atomically(): void
    {
        $project = Project::factory()->create();
        $stock = PartDefinition::factory()->for($project)->create(['length' => 500, 'width' => 100, 'thickness' => 20]);
        $targetStock = PartDefinition::factory()->for($project)->create(['length' => 200, 'width' => 200, 'thickness' => 20]);
        $source = PartInstance::factory()->for($project)->for($stock)->create(['position_z' => 30]);
        $untouched = PartInstance::factory()->for($project)->for($stock)->create();
        $target = PartInstance::factory()->for($project)->for($targetStock)->create([
            'position_z' => 10, 'rotation_z' => 90, 'mirrored' => true,
        ]);
        $screw = CatalogMaterial::factory()->consumable()->create(['user_id' => $project->user_id]);
        $fastener = ProjectFastener::factory()->create([
            'project_id' => $project->id, 'source_instance_id' => $source->id, 'target_instance_id' => $target->id,
            'catalog_material_id' => $screw->id, 'center_u' => 300, 'center_v' => 70,
        ]);
        Sanctum::actingAs($project->user, ['projects:write']);
        $path = "/api/v1/projects/{$project->id}/fasteners/drilling";
        $settings = [
            'fastener_ids' => [$fastener->id], 'clearance_diameter' => 4,
            'pilot' => true, 'pilot_diameter' => 2.5, 'pilot_depth' => 7,
        ];

        $preview = $this->postJson($path, [...$settings, 'dry_run' => true])
            ->assertOk()->assertJsonPath('data.revision', 1)
            ->assertJsonPath('data.parts.0.operations.0.through', true)
            ->assertJsonPath('data.parts.0.operations.0.depth', 20)
            ->assertJsonPath('data.parts.1.operations.0.face', 'top')
            ->assertJsonPath('data.parts.1.operations.0.center_u', 80)
            ->assertJsonPath('data.parts.1.operations.0.center_v', 50)
            ->assertJsonPath('data.parts.1.operations.0.depth', 7)
            ->assertJsonPath('data.parts.1.operations.0.through', false);
        $this->assertDatabaseCount('part_definitions', 2);
        $this->assertSame([], $targetStock->refresh()->operations);

        $this->postJson($path, [...$settings, 'dry_run' => false, 'expected_revision' => 2])->assertConflict();
        $applied = $this->postJson($path, [...$settings, 'dry_run' => false, 'expected_revision' => 1])
            ->assertOk()->assertJsonPath('data.revision', 2);
        $this->assertSame($preview->json('data.created_operation_ids'), $applied->json('data.created_operation_ids'));
        $this->assertNotSame($stock->id, $source->refresh()->part_definition_id);
        $this->assertSame($stock->id, $untouched->refresh()->part_definition_id);
        $this->assertCount(1, $source->partDefinition->operations);
        $this->assertCount(1, $targetStock->refresh()->operations);
        $this->postJson($path, [...$settings, 'dry_run' => false, 'expected_revision' => 2])
            ->assertOk()->assertJsonPath('data.revision', 2)->assertJsonCount(0, 'data.created_operation_ids');

        $this->postJson($path, [...$settings, 'dry_run' => false, 'expected_revision' => 2, 'remove_operation_ids' => $applied->json('data.created_operation_ids')])
            ->assertOk()->assertJsonPath('data.revision', 3);
        $this->assertSame([], $source->partDefinition->refresh()->operations);
        $this->assertSame([], $targetStock->refresh()->operations);
    }

    public function test_a_row_without_second_instances_creates_one_through_hole_per_position(): void
    {
        $first = ProjectFastener::factory()->create();
        $project = Project::query()->findOrFail($first->project_id);
        $second = $project->projectFasteners()->create([...$first->getAttributes(), 'center_u' => $first->center_u + 10]);
        Sanctum::actingAs($project->user, ['projects:write']);

        $response = $this->postJson("/api/v1/projects/{$project->id}/fasteners/drilling", [
            'fastener_ids' => [$first->id, $second->id], 'clearance_diameter' => 4, 'pilot' => false,
            'dry_run' => false, 'expected_revision' => $project->revision,
        ])->assertOk()->assertJsonCount(2, 'data.parts.0.operations');

        $this->assertSame([20, 30], array_column($response->json('data.parts.0.operations'), 'center_u'));
        $this->assertSame([true, true], array_column($response->json('data.parts.0.operations'), 'through'));
        $this->assertSame(2, $project->refresh()->revision);
    }

    public function test_countersink_can_be_added_to_an_existing_hole_and_undone_independently(): void
    {
        $fastener = ProjectFastener::factory()->create();
        $project = Project::query()->findOrFail($fastener->project_id);
        $source = PartInstance::query()->findOrFail($fastener->source_instance_id);
        Sanctum::actingAs($project->user, ['projects:write']);
        $path = "/api/v1/projects/{$project->id}/fasteners/drilling";
        $settings = ['fastener_ids' => [$fastener->id], 'clearance_diameter' => 4, 'pilot' => false];

        $this->postJson($path, [...$settings, 'dry_run' => false, 'expected_revision' => 1])->assertOk();
        $original = $source->partDefinition->operations;
        $settings += ['countersink' => true, 'countersink_diameter' => 8, 'countersink_depth' => 2];
        $preview = $this->postJson($path, [...$settings, 'dry_run' => true])
            ->assertOk()->assertJsonPath('data.revision', 2)
            ->assertJsonCount(1, 'data.parts.0.operations')
            ->assertJsonPath('data.parts.0.operations.0.diameter', 8)
            ->assertJsonPath('data.parts.0.operations.0.bottom_diameter', 4)
            ->assertJsonPath('data.parts.0.operations.0.depth', 2)
            ->assertJsonPath('data.parts.0.operations.0.through', false);
        $this->assertSame($original, $source->partDefinition->refresh()->operations);

        $this->postJson($path, [...$settings, 'dry_run' => false, 'expected_revision' => 2])
            ->assertOk()->assertJsonPath('data.revision', 3)
            ->assertJsonPath('data.created_operation_ids', $preview->json('data.created_operation_ids'));
        $this->assertCount(2, $source->partDefinition->refresh()->operations);
        $this->postJson($path, [...$settings, 'dry_run' => false, 'expected_revision' => 3])
            ->assertOk()->assertJsonPath('data.revision', 3)->assertJsonCount(0, 'data.created_operation_ids');
        $part = $source->partDefinition;
        $partPath = "/api/v1/projects/{$project->id}/parts/{$part->id}";
        $operations = $part->operations;
        $operations[1]['bottom_diameter'] = 8;
        $this->patchJson($partPath, ['operations' => $operations])
            ->assertUnprocessable()->assertJsonValidationErrors('operations.1.bottom_diameter');
        $this->patchJson($partPath, ['operations' => $part->operations])
            ->assertOk()->assertJsonPath('data.operations.1.bottom_diameter', 4);
        $this->postJson($path, [
            ...$settings, 'dry_run' => false, 'expected_revision' => 4,
            'remove_operation_ids' => $preview->json('data.created_operation_ids'),
        ])->assertOk()->assertJsonPath('data.revision', 5);
        $this->assertEquals($original, $source->partDefinition->refresh()->operations);
    }

    public function test_invalid_countersinks_do_not_create_the_passage_hole(): void
    {
        $fastener = ProjectFastener::factory()->create(['center_u' => 3]);
        $project = Project::query()->findOrFail($fastener->project_id);
        $part = PartInstance::query()->findOrFail($fastener->source_instance_id)->partDefinition;
        Sanctum::actingAs($project->user, ['projects:write']);
        $path = "/api/v1/projects/{$project->id}/fasteners/drilling";
        $settings = [
            'fastener_ids' => [$fastener->id], 'pilot' => false,
            'clearance_diameter' => 4, 'countersink' => true, 'countersink_diameter' => 5,
            'countersink_depth' => 2, 'dry_run' => false, 'expected_revision' => 1,
        ];

        $this->postJson($path, [...$settings, 'countersink_diameter' => 4])->assertUnprocessable()->assertJsonValidationErrors('countersink_diameter');
        $this->postJson($path, [...$settings, 'countersink_diameter' => 8])->assertUnprocessable()->assertJsonValidationErrors('drilling');
        $this->postJson($path, [...$settings, 'countersink_depth' => $part->thickness])->assertUnprocessable()->assertJsonValidationErrors('drilling');
        $this->assertSame([], $part->refresh()->operations);
        $this->assertSame(1, $project->refresh()->revision);
    }

    public function test_an_unreachable_pilot_blocks_all_changes_in_a_drilling_batch(): void
    {
        $fastener = ProjectFastener::factory()->create(['length' => 1]);
        $project = Project::query()->findOrFail($fastener->project_id);
        $source = PartInstance::query()->findOrFail($fastener->source_instance_id);
        $target = PartInstance::factory()->for($project)->for($source->partDefinition)->create(['position_z' => -2000]);
        $fastener->update(['target_instance_id' => $target->id]);
        Sanctum::actingAs($project->user, ['projects:write']);

        $this->postJson("/api/v1/projects/{$project->id}/fasteners/drilling", [
            'fastener_ids' => [$fastener->id], 'clearance_diameter' => 4, 'pilot' => true,
            'pilot_diameter' => 2, 'pilot_depth' => 4, 'dry_run' => false, 'expected_revision' => $project->revision,
        ])->assertUnprocessable()->assertJsonValidationErrors('drilling');
        $this->assertSame([], $source->partDefinition->refresh()->operations);
        $this->assertSame(1, $project->refresh()->revision);
    }

    public function test_version_restore_preserves_screws_and_their_instance_references(): void
    {
        $fastener = ProjectFastener::factory()->create();
        $project = Project::query()->findOrFail($fastener->project_id);
        Sanctum::actingAs($project->user, ['projects:read', 'projects:write']);
        $base = "/api/v1/projects/{$project->id}/fasteners";
        $versions = "/api/projects/{$project->id}/versions";
        $version = $this->postJson($versions, ['label' => 'With fastener', 'expected_revision' => $project->revision])
            ->assertCreated()->json('data.id');
        $this->deleteJson($base, ['ids' => [$fastener->id]])->assertNoContent();

        $this->postJson("$versions/$version/restore", ['expected_revision' => $project->refresh()->revision])->assertOk();
        $this->getJson($base)->assertOk()->assertJsonPath('data.0.source_instance_id', $fastener->source_instance_id);
    }
}
