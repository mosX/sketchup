<?php

namespace Database\Factories;

use App\Models\CatalogMaterial;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CatalogMaterial> */
class CatalogMaterialFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Pine board',
            'kind' => 'board',
            'length_mm' => 5000,
            'width_mm' => 100,
            'thickness_mm' => 30,
            'price' => 300,
        ];
    }

    public function consumable(): static
    {
        return $this->state([
            'name' => 'Screws',
            'kind' => 'consumable',
            'length_mm' => null,
            'width_mm' => null,
            'thickness_mm' => null,
            'unit' => '??.',
            'pack_quantity' => 100,
            'price' => 200,
        ]);
    }
}
