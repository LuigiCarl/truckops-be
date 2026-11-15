<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_requests_return_401_json(): void
    {
        $this->getJson('/api/v1/auth/user')->assertUnauthorized();
        $this->getJson('/api/v1/dashboard/summary')->assertUnauthorized();
        $this->getJson('/api/v1/employees')->assertUnauthorized();
        $this->getJson('/api/v1/trips')->assertUnauthorized();
    }

    public function test_login_validation_and_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'admin@truckops.local',
            'password' => 'password',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'not-an-email',
        ])->assertStatus(422)->assertJsonValidationErrors(['email', 'password']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@truckops.local',
            'password' => 'wrong-password',
        ])->assertUnauthorized();
    }

    public function test_operator_can_login_fetch_user_and_logout_revoking_token(): void
    {
        User::factory()->create([
            'name' => 'Operator Admin',
            'email' => 'admin@truckops.local',
            'password' => 'password',
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@truckops.local',
            'password' => 'password',
        ])->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);

        $token = $login->json('token');

        $this->withToken($token)
            ->getJson('/api/v1/auth/user')
            ->assertOk()
            ->assertJsonPath('email', 'admin@truckops.local');

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Reset Sanctum auth guard state to verify the deleted bearer token is rejected
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/v1/auth/user')
            ->assertUnauthorized();
    }

    public function test_database_seeder_is_idempotent_and_seeds_baseline_data(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('trucks', 4);
        $this->assertDatabaseCount('expense_categories', 5);
        $this->assertDatabaseCount('employees', 4);
        $this->assertDatabaseCount('trips', 5);
        $this->assertDatabaseCount('collections', 2);
        $this->assertDatabaseCount('expenses', 4);
        $this->assertDatabaseCount('cash_advances', 2);
        $this->assertDatabaseCount('payroll_records', 4);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@truckops.local',
            'password' => 'password',
        ])->assertOk();
    }
}
