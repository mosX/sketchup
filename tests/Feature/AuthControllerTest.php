<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_valid_registration_creates_user_and_returns_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Анна Мельник',
            'email' => 'anna@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.email', 'anna@example.com')
            ->assertJsonStructure(['token']);

        $this->assertDatabaseHas('users', [
            'name' => 'Анна Мельник',
            'email' => 'anna@example.com',
        ]);
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'anna@example.com']);

        $this->postJson('/api/register', [
            'name' => 'Анна Мельник',
            'email' => 'anna@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_valid_credentials_return_token(): void
    {
        User::factory()->create([
            'email' => 'anna@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'anna@example.com',
            'password' => 'password123',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.email', 'anna@example.com')
            ->assertJsonStructure(['token']);
    }

    public function test_invalid_credentials_return_401(): void
    {
        User::factory()->create(['email' => 'anna@example.com']);

        $this->postJson('/api/login', [
            'email' => 'anna@example.com',
            'password' => 'wrong-password',
        ])->assertUnauthorized()->assertJsonPath('errors.general.0', 'Неверный email или пароль.');
    }
}
