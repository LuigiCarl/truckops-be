<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataApiTest extends TestCase
{
    use RefreshDatabase;

    private function authenticateOperator(): string
    {
        User::factory()->create([
            'email' => 'admin@truckops.local',
            'password' => 'password',
        ]);

        return $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@truckops.local',
            'password' => 'password',
        ])->json('token');
    }

    public function test_employees_crud_filtering_and_validation(): void
    {
        $token = $this->authenticateOperator();

        $this->withToken($token)->postJson('/api/v1/employees', [
            'first_name' => 'Invalid',
            'last_name' => 'Person',
            'role' => 'Manager',
            'pay_type' => 'Monthly',
            'base_rate' => -100,
            'ot_per_trip_rate' => -50,
        ])->assertStatus(422)->assertJsonValidationErrors([
            'role',
            'pay_type',
            'base_rate',
            'ot_per_trip_rate',
        ]);

        $driver = $this->withToken($token)->postJson('/api/v1/employees', [
            'first_name' => 'Roberto',
            'last_name' => 'Santos',
            'contact_number' => '09171234501',
            'role' => 'Driver',
            'pay_type' => 'Daily',
            'base_rate' => 850,
            'ot_per_trip_rate' => 350,
        ])->assertCreated();

        $helper = $this->withToken($token)->postJson('/api/v1/employees', [
            'first_name' => 'Marco',
            'last_name' => 'Villanueva',
            'role' => 'Helper',
            'pay_type' => 'Trip',
            'base_rate' => 450,
            'ot_per_trip_rate' => 200,
        ])->assertCreated();

        $driverId = $driver->json('id');

        $this->withToken($token)
            ->getJson('/api/v1/employees?role=Driver&status=Active')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $driverId);

        $this->withToken($token)
            ->getJson('/api/v1/employees?role=Helper')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $helper->json('id'));

        $this->withToken($token)
            ->getJson("/api/v1/employees/{$driverId}")
            ->assertOk()
            ->assertJsonStructure(['id', 'cash_advances', 'payroll_records']);

        $this->withToken($token)
            ->putJson("/api/v1/employees/{$driverId}", [
                'contact_number' => '09179999999',
                'base_rate' => 900,
            ])
            ->assertOk()
            ->assertJsonPath('contact_number', '09179999999')
            ->assertJsonPath('base_rate', '900.00');

        $this->withToken($token)
            ->patchJson("/api/v1/employees/{$driverId}/status", ['status' => 'Inactive'])
            ->assertOk()
            ->assertJsonPath('status', 'Inactive');

        $this->withToken($token)
            ->getJson('/api/v1/employees?status=Inactive')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_trucks_crud_filtering_and_unique_plate_validation(): void
    {
        $token = $this->authenticateOperator();

        $truckRes = $this->withToken($token)->postJson('/api/v1/trucks', [
            'plate_number' => 'NAA 1041',
            'description' => 'Isuzu Elf Closed Van',
            'registration_expiry' => '2027-03-15',
        ])->assertCreated();
        $truckId = $truckRes->json('id');

        $this->withToken($token)->postJson('/api/v1/trucks', [
            'plate_number' => 'NAA 1041',
            'description' => 'Duplicate Plate',
        ])->assertStatus(422)->assertJsonValidationErrors(['plate_number']);

        $this->withToken($token)
            ->getJson('/api/v1/trucks?status=Available')
            ->assertOk()
            ->assertJsonCount(1);

        $this->withToken($token)
            ->getJson("/api/v1/trucks/{$truckId}")
            ->assertOk()
            ->assertJsonStructure(['id', 'trips', 'expenses']);

        $this->withToken($token)
            ->putJson("/api/v1/trucks/{$truckId}", [
                'plate_number' => 'NAA 1041',
                'description' => 'Isuzu Elf Updated',
            ])
            ->assertOk()
            ->assertJsonPath('description', 'Isuzu Elf Updated');

        $this->withToken($token)
            ->patchJson("/api/v1/trucks/{$truckId}/status", ['status' => 'Under Maintenance'])
            ->assertOk()
            ->assertJsonPath('status', 'Under Maintenance');
    }
}
