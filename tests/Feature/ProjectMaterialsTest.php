<?php

namespace Tests\Feature;

use App\Models\CatalogMaterial;
use App\Models\PartDefinition;
use App\Models\PartInstance;
use App\Models\Project;
use App\Models\ProjectEstimate;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectMaterialsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_project_uses_catalog_sizes_and_prices_and_freezes_an_estimate(): void
    {
        $project = Project::factory()->create();
        $board = CatalogMaterial::factory()->create(['user_id' => $project->user_id]);
        $thickBoard = CatalogMaterial::factory()->create(['user_id' => $project->user_id, 'length_mm' => 3000, 'width_mm' => 120, 'thickness_mm' => 50]);
        $screws = CatalogMaterial::factory()->consumable()->create(['user_id' => $project->user_id]);
        $rail = PartDefinition::factory()->for($project)->create(['length' => 1000, 'width' => 100, 'thickness' => 30]);
        $leg = PartDefinition::factory()->for($project)->create(['length' => 600, 'width' => 120, 'thickness' => 50]);
        PartInstance::factory()->count(2)->for($project)->for($rail)->create();
        PartInstance::factory()->for($project)->for($leg)->create();
        Sanctum::actingAs($project->user, ['projects:read', 'projects:write']);
        $base = "/api/v1/projects/{$project->id}";

        $this->putJson("$base/materials", [
            'default_material_id' => $board->id,
            'assignments' => [['part_id' => $leg->id, 'material_id' => $thickBoard->id]],
            'supplies' => [['material_id' => $screws->id, 'quantity' => 24]],
        ])->assertOk();

        $this->getJson("$base/analysis?linear_stock_length_mm=7000")
            ->assertOk()
            ->assertJsonPath('data.manufacturing.linear_cutting.0.stock_length', 5000)
            ->assertJsonPath('data.manufacturing.linear_cutting.1.stock_length', 3000)
            ->assertJsonCount(2, 'data.manufacturing.linear_cutting.0.bars.0.cuts')
            ->assertJsonPath('data.manufacturing.costing.complete', true)
            ->assertJsonPath('data.manufacturing.costing.items.0.consumed_minor', 12158)
            ->assertJsonPath('data.manufacturing.costing.items.2.consumed_minor', 4800)
            ->assertJsonPath('data.manufacturing.costing.items.2.purchase_minor', 20000)
            ->assertJsonPath('data.manufacturing.costing.purchase_minor', 80000);

        $id = $this->postJson("$base/estimates")->assertCreated()->json('data.id');
        $this->putJson("/api/v1/materials/{$board->id}", [
            'name' => $board->name, 'kind' => 'board', 'length_mm' => 5000,
            'width_mm' => 100, 'thickness_mm' => 30, 'price' => 400,
        ])->assertOk();

        $this->getJson("$base/analysis")->assertOk()->assertJsonPath('data.manufacturing.costing.purchase_minor', 90000);
        $this->getJson("$base/estimates/$id")->assertOk()->assertJsonPath('data.manufacturing.costing.purchase_minor', 80000);
    }

    public function test_report_skips_unassigned_incompatible_and_oversized_parts_and_marks_missing_prices(): void
    {
        $project = Project::factory()->create();
        $board = CatalogMaterial::factory()->create(['user_id' => $project->user_id, 'price' => null, 'length_mm' => 1000]);
        $sheet = CatalogMaterial::factory()->create([
            'user_id' => $project->user_id, 'kind' => 'sheet', 'length_mm' => 2440, 'width_mm' => 1220, 'thickness_mm' => 18,
        ]);
        $assignments = [];
        foreach ([
            [300, 100, 30, $board->id],
            [300, 110, 30, $board->id],
            [1100, 100, 30, $board->id],
            [300, 100, 30, null],
            [500, 250, 18, $sheet->id],
        ] as [$length, $width, $thickness, $materialId]) {
            $part = PartDefinition::factory()->for($project)->create(compact('length', 'width', 'thickness'));
            PartInstance::factory()->for($project)->for($part)->create();
            if ($materialId !== null) {
                $assignments[] = ['part_id' => $part->id, 'material_id' => $materialId];
            }
        }
        Sanctum::actingAs($project->user, ['projects:read', 'projects:write']);
        $base = "/api/v1/projects/{$project->id}";
        $this->putJson("$base/materials", ['default_material_id' => null, 'assignments' => $assignments, 'supplies' => []])->assertOk();

        $response = $this->getJson("$base/analysis")->assertOk()
            ->assertJsonPath('data.manufacturing.costing.complete', false)
            ->assertJsonPath('data.manufacturing.costing.items.0.purchase_minor', null)
            ->assertJsonPath('data.manufacturing.costing.items.1.purchase_minor', 30000)
            ->assertJsonPath('data.manufacturing.sheet_cutting.0.stock.length', 2440)
            ->assertJsonCount(3, 'data.manufacturing.costing.issues');

        $this->assertEqualsCanonicalizing(['unassigned', 'incompatible', 'oversized'], array_column($response->json('data.manufacturing.costing.issues'), 'reason'));
        $this->assertGreaterThan(0, $response->json('data.manufacturing.sheet_cutting.0.sheets.0.reusable_area'));
    }

    public function test_one_catalog_board_can_produce_multiple_widths_and_preserves_the_kerf(): void
    {
        $project = Project::factory()->create();
        $board = CatalogMaterial::factory()->create(['user_id' => $project->user_id]);
        $wide = PartDefinition::factory()->for($project)->create(['length' => 1000, 'width' => 60.5, 'thickness' => 30]);
        $narrow = PartDefinition::factory()->for($project)->create(['length' => 1500, 'width' => 35.5, 'thickness' => 30]);
        PartInstance::factory()->count(2)->for($project)->for($wide)->create();
        PartInstance::factory()->for($project)->for($narrow)->create();
        Sanctum::actingAs($project->user, ['projects:read', 'projects:write']);
        $base = "/api/v1/projects/{$project->id}";
        $this->putJson("$base/materials", ['default_material_id' => $board->id, 'assignments' => [], 'supplies' => []])->assertOk();

        $response = $this->getJson("$base/analysis?kerf_mm=4")->assertOk();

        $response
            ->assertJsonCount(1, 'data.manufacturing.linear_cutting.0.bars')
            ->assertJsonCount(1, 'data.manufacturing.linear_cutting.0.bars.0.rip_cuts')
            ->assertJsonPath('data.manufacturing.linear_cutting.0.bars.0.rip_cuts.0.position', 60.5)
            ->assertJsonPath('data.manufacturing.linear_cutting.0.bars.0.strips.1.offset', 64.5)
            ->assertJsonPath('data.manufacturing.linear_cutting.0.bars.0.strips.1.width', 35.5)
            ->assertJsonPath('data.manufacturing.linear_cutting.0.bars.0.strips.0.cuts.1.start', 1014)
            ->assertJsonCount(2, 'data.manufacturing.linear_cutting.0.bars.0.offcuts')
            ->assertJsonPath('data.manufacturing.costing.purchase_minor', 30000)
            ->assertJsonPath('data.manufacturing.costing.consumed_minor', 11808);

        $this->getJson("$base/analysis?kerf_mm=4.1")->assertOk()
            ->assertJsonCount(2, 'data.manufacturing.linear_cutting.0.bars')
            ->assertJsonPath('data.manufacturing.costing.purchase_minor', 60000);
    }

    public function test_catalog_and_project_plan_enforce_ownership_and_item_types(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $ownBoard = CatalogMaterial::factory()->create(['user_id' => $user->id]);
        $otherBoard = CatalogMaterial::factory()->create();
        $otherPart = PartDefinition::factory()->create();
        $otherEstimate = ProjectEstimate::factory()->create();
        Sanctum::actingAs($user, ['projects:read', 'projects:write']);
        $base = "/api/v1/projects/{$project->id}";

        $this->getJson('/api/v1/materials')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ownBoard->id);
        $this->putJson("/api/v1/materials/{$otherBoard->id}", [])->assertForbidden();
        $this->putJson("$base/materials", [
            'default_material_id' => $otherBoard->id,
            'assignments' => [['part_id' => $otherPart->id, 'material_id' => $ownBoard->id]],
            'supplies' => [['material_id' => $ownBoard->id, 'quantity' => 2]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['default_material_id', 'assignments.0.part_id', 'supplies.0.material_id']);
        $this->getJson("$base/estimates/{$otherEstimate->id}")->assertNotFound();
    }

    public function test_catalog_creates_packaged_consumables_and_requires_dimensions_for_stock(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['projects:read', 'projects:write']);

        $this->postJson('/api/v1/materials', ['name' => 'Board', 'kind' => 'board', 'price' => 20])
            ->assertUnprocessable()->assertJsonValidationErrors(['length_mm', 'width_mm', 'thickness_mm']);
        $this->postJson('/api/v1/materials', ['name' => 'Glue', 'kind' => 'consumable', 'price' => 85.50, 'unit' => 'ml', 'pack_quantity' => 250])
            ->assertCreated()->assertJsonPath('data.pack_quantity', '250.000')->assertJsonPath('data.price', '85.50');
    }
}
