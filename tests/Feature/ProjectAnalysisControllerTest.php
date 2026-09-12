<?php

namespace Tests\Feature;

use App\Models\PartDefinition;
use App\Models\PartInstance;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectAnalysisControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_analysis_returns_diagnostics_bill_of_materials_and_cutting_maps(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['revision' => 4]);
        $rail = PartDefinition::factory()->for($project)->create([
            'name' => 'Рейка',
            'material' => 'Сосна',
            'length' => 1000,
            'width' => 70,
            'thickness' => 45,
            'operations' => [['id' => 'cut-1', 'type' => 'cross_cut']],
        ]);
        $panel = PartDefinition::factory()->for($project)->create([
            'name' => 'Панель',
            'material' => 'Фанера',
            'length' => 1200,
            'width' => 600,
            'thickness' => 18,
        ]);
        PartInstance::factory()->count(2)->for($project)->for($rail)->create();
        PartInstance::factory()->count(2)->for($project)->for($panel)->create();
        Sanctum::actingAs($user, ['projects:read']);

        $response = $this->getJson("/api/v1/projects/{$project->id}/analysis");

        $response
            ->assertOk()
            ->assertJsonPath('data.project_id', $project->id)
            ->assertJsonPath('data.revision', 4)
            ->assertJsonPath('data.diagnostics.summary.part_count', 2)
            ->assertJsonPath('data.manufacturing.summary.unique_part_count', 2)
            ->assertJsonPath('data.manufacturing.summary.instance_count', 4)
            ->assertJsonPath('data.manufacturing.bill_of_materials.0.quantity', 2)
            ->assertJsonPath('data.manufacturing.bill_of_materials.1.quantity', 2)
            ->assertJsonCount(1, 'data.manufacturing.linear_cutting')
            ->assertJsonCount(1, 'data.manufacturing.linear_cutting.0.bars')
            ->assertJsonCount(1, 'data.manufacturing.sheet_cutting')
            ->assertJsonCount(1, 'data.manufacturing.sheet_cutting.0.sheets');
    }

    public function test_analysis_returns_404_for_project_outside_token_scope(): void
    {
        $user = User::factory()->create();
        $allowedProject = Project::factory()->for($user)->create();
        $otherProject = Project::factory()->for($user)->create();
        $token = $user->createToken('Scoped analysis', ['projects:read', "project:{$allowedProject->id}"])->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/v1/projects/{$otherProject->id}/analysis")
            ->assertNotFound();
    }

    public function test_analysis_uses_validated_cutting_settings(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $part = PartDefinition::factory()->for($project)->create([
            'length' => 900,
            'width' => 70,
            'thickness' => 45,
        ]);
        PartInstance::factory()->count(2)->for($project)->for($part)->create();
        Sanctum::actingAs($user, ['projects:read']);

        $response = $this->getJson("/api/v1/projects/{$project->id}/analysis?kerf_mm=4&edge_margin_mm=20&linear_stock_length_mm=2000&sheet_length_mm=2500&sheet_width_mm=1250");

        $response
            ->assertOk()
            ->assertJsonPath('data.manufacturing.assumptions.kerf_mm', 4)
            ->assertJsonPath('data.manufacturing.assumptions.edge_margin_mm', 20)
            ->assertJsonPath('data.manufacturing.assumptions.linear_stock_length_mm', 2000)
            ->assertJsonPath('data.manufacturing.assumptions.sheet_size_mm.length', 2500)
            ->assertJsonPath('data.manufacturing.assumptions.sheet_size_mm.width', 1250)
            ->assertJsonPath('data.manufacturing.linear_cutting.0.bars.0.waste_length', 152);
    }

    public function test_analysis_rejects_invalid_cutting_settings(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        Sanctum::actingAs($user, ['projects:read']);

        $this->getJson("/api/v1/projects/{$project->id}/analysis?kerf_mm=-1&linear_stock_length_mm=50")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['kerf_mm', 'linear_stock_length_mm']);
    }
}
