<?php

namespace Tests\Feature;

use App\Models\AssemblyGroup;
use App\Models\PartDefinition;
use App\Models\PartInstance;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectTemplateControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_owner_can_capture_and_instantiate_a_scaled_project_template(): void
    {
        $user = User::factory()->create();
        $source = Project::factory()->for($user)->create(['name' => 'Исходный стол']);
        $group = AssemblyGroup::factory()->for($source)->create(['name' => 'Каркас']);
        $part = PartDefinition::factory()->for($source)->create([
            'name' => 'Стойка',
            'length' => 100,
            'width' => 50,
            'thickness' => 20,
            'operations' => [[
                'id' => 'groove-1', 'type' => 'groove', 'face' => 'top',
                'center_u' => 10, 'center_v' => 5, 'path_angle' => 0,
                'groove_length' => 30, 'width' => 4, 'depth' => 5,
            ]],
        ]);
        $primary = PartInstance::factory()->for($source)->for($part)->for($group, 'assemblyGroup')->create([
            'position_x' => 0, 'position_y' => 0, 'position_z' => 0,
        ]);
        $secondary = PartInstance::factory()->for($source)->for($part)->for($group, 'assemblyGroup')->create([
            'position_x' => 0, 'position_y' => 0, 'position_z' => 0,
        ]);
        $source->projectConnections()->create([
            'primary_instance_id' => $primary->id,
            'secondary_instance_id' => $secondary->id,
            'type' => 'dowel',
            'parameters' => ['dowel_diameter' => 8, 'dowel_count' => 2, 'dowel_depth' => 25],
            'is_verified' => true,
        ]);
        Sanctum::actingAs($user);

        $templateResponse = $this->postJson('/api/project-templates', [
            'project_id' => $source->id,
            'name' => 'Параметрический стол',
        ]);

        $templateResponse
            ->assertCreated()
            ->assertJsonPath('data.name', 'Параметрический стол')
            ->assertJsonPath('data.base_dimensions.width', 100)
            ->assertJsonPath('data.base_dimensions.depth', 50)
            ->assertJsonPath('data.base_dimensions.height', 20);

        $templateId = $templateResponse->json('data.id');
        $projectResponse = $this->postJson("/api/project-templates/{$templateId}/instantiate", [
            'name' => 'Большой стол',
            'dimensions' => ['width' => 200, 'depth' => 150, 'height' => 80],
        ]);

        $projectResponse->assertCreated()->assertJsonPath('data.name', 'Большой стол');

        $project = Project::query()->findOrFail($projectResponse->json('data.id'));
        $scaledPart = $project->partDefinitions()->with('instances')->firstOrFail();
        $scaledInstance = $scaledPart->instances->first();
        $scaledOperation = $scaledPart->operations[0];

        $this->assertSame(200.0, $scaledPart->length);
        $this->assertSame(150.0, $scaledPart->width);
        $this->assertSame(80.0, $scaledPart->thickness);
        $this->assertSame(50.0, $scaledInstance->position_x);
        $this->assertSame(1, $project->assemblyGroups()->count());
        $this->assertSame(1, $project->projectConnections()->count());
        $this->assertFalse($project->projectConnections()->firstOrFail()->is_verified);
        $this->assertNotNull($scaledInstance->assembly_group_id);
        $this->assertSame(20.0, (float) $scaledOperation['center_u']);
        $this->assertSame(15.0, (float) $scaledOperation['center_v']);
        $this->assertSame(60.0, (float) $scaledOperation['groove_length']);
        $this->assertSame(20.0, (float) $scaledOperation['depth']);
    }

    public function test_template_endpoints_hide_another_users_template(): void
    {
        $user = User::factory()->create();
        $foreignTemplate = ProjectTemplate::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/project-templates/{$foreignTemplate->id}/instantiate", [
            'name' => 'Чужая копия',
            'dimensions' => ['width' => 1000, 'depth' => 600, 'height' => 750],
        ])->assertNotFound();

        $this->deleteJson("/api/project-templates/{$foreignTemplate->id}")->assertNotFound();
    }

    public function test_instantiation_rejects_invalid_dimensions(): void
    {
        $user = User::factory()->create();
        $template = ProjectTemplate::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/project-templates/{$template->id}/instantiate", [
            'name' => 'Неверный проект',
            'dimensions' => ['width' => 0, 'depth' => 600],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'dimensions.width',
            'dimensions.height',
        ]);
    }

    public function test_global_api_key_can_manage_templates(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $globalToken = $user->createToken('Global', ['projects:read', 'projects:write'])->plainTextToken;

        $response = $this->withToken($globalToken)->postJson('/api/v1/project-templates', [
            'project_id' => $project->id,
            'name' => 'API template',
        ]);

        $response->assertCreated()->assertJsonPath('data.name', 'API template');
    }

    public function test_project_scoped_api_key_cannot_access_account_templates(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $scopedToken = $user->createToken('Scoped', ['projects:read', 'projects:write', "project:{$project->id}"])->plainTextToken;

        $this->withToken($scopedToken)
            ->getJson('/api/v1/project-templates')
            ->assertForbidden();
    }
}
