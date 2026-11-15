<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Truck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TripOperationsApiTest extends TestCase
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

    public function test_trip_validates_employee_roles_active_status_and_truck_availability(): void
    {
        $token = $this->authenticateOperator();

        $activeDriver = Employee::create([
            'first_name' => 'Roberto',
            'last_name' => 'Santos',
            'role' => 'Driver',
            'pay_type' => 'Daily',
            'base_rate' => 850,
            'ot_per_trip_rate' => 350,
            'status' => 'Active',
        ]);

        $inactiveDriver = Employee::create([
            'first_name' => 'Old',
            'last_name' => 'Driver',
            'role' => 'Driver',
            'pay_type' => 'Daily',
            'base_rate' => 850,
            'ot_per_trip_rate' => 350,
            'status' => 'Inactive',
        ]);

        $activeHelper = Employee::create([
            'first_name' => 'Joel',
            'last_name' => 'Mendoza',
            'role' => 'Helper',
            'pay_type' => 'Trip',
            'base_rate' => 450,
            'ot_per_trip_rate' => 200,
            'status' => 'Active',
        ]);

        $inactiveHelper = Employee::create([
            'first_name' => 'Old',
            'last_name' => 'Helper',
            'role' => 'Helper',
            'pay_type' => 'Trip',
            'base_rate' => 450,
            'ot_per_trip_rate' => 200,
            'status' => 'Inactive',
        ]);

        $availableTruck = Truck::create(['plate_number' => 'NAA 1041', 'status' => 'Available']);
        $assignedTruck = Truck::create(['plate_number' => 'NBB 2082', 'status' => 'Assigned']);
        $maintenanceTruck = Truck::create(['plate_number' => 'NCC 3093', 'status' => 'Under Maintenance']);
        $inactiveTruck = Truck::create(['plate_number' => 'NDD 4014', 'status' => 'Inactive']);

        // Reject Helper assigned as driver & Driver assigned as helper
        $this->withToken($token)->postJson('/api/v1/trips', [
            'date' => '2026-11-10',
            'truck_id' => $availableTruck->id,
            'driver_id' => $activeHelper->id,
            'helper_id' => $activeDriver->id,
            'regular_trips' => 2,
            'overtime_trips' => 0,
            'ab_rate_per_trip' => 1500,
        ])->assertStatus(422)->assertJsonValidationErrors(['driver_id', 'helper_id']);

        // Reject Inactive driver, Inactive helper, and Under Maintenance / Inactive truck
        $this->withToken($token)->postJson('/api/v1/trips', [
            'date' => '2026-11-10',
            'truck_id' => $maintenanceTruck->id,
            'driver_id' => $inactiveDriver->id,
            'helper_id' => $inactiveHelper->id,
            'regular_trips' => 1,
            'ab_rate_per_trip' => 1500,
        ])->assertStatus(422)->assertJsonValidationErrors(['driver_id', 'helper_id', 'truck_id']);

        $this->withToken($token)->postJson('/api/v1/trips', [
            'date' => '2026-11-10',
            'truck_id' => $inactiveTruck->id,
            'driver_id' => $activeDriver->id,
            'regular_trips' => 1,
            'ab_rate_per_trip' => 1500,
        ])->assertStatus(422)->assertJsonValidationErrors(['truck_id']);

        // Reject 0 regular and 0 overtime trips
        $this->withToken($token)->postJson('/api/v1/trips', [
            'date' => '2026-11-10',
            'truck_id' => $availableTruck->id,
            'driver_id' => $activeDriver->id,
            'regular_trips' => 0,
            'overtime_trips' => 0,
            'ab_rate_per_trip' => 1500,
        ])->assertStatus(422)->assertJsonValidationErrors(['regular_trips']);

        // Reject helper == driver
        $this->withToken($token)->postJson('/api/v1/trips', [
            'date' => '2026-11-10',
            'truck_id' => $availableTruck->id,
            'driver_id' => $activeDriver->id,
            'helper_id' => $activeDriver->id,
            'regular_trips' => 1,
            'ab_rate_per_trip' => 1500,
        ])->assertStatus(422)->assertJsonValidationErrors(['helper_id']);

        // Assigned truck IS allowed (trucks are interchangeable across daily dispatch logs)
        // Daily driver (850 base, 350 OT) + Trip helper (450 base, 200 OT) with 3 regular + 1 OT = 4 trips * 1500 = 6000
        $trip = $this->withToken($token)->postJson('/api/v1/trips', [
            'date' => '2026-11-10',
            'truck_id' => $assignedTruck->id,
            'driver_id' => $activeDriver->id,
            'helper_id' => $activeHelper->id,
            'regular_trips' => 3,
            'overtime_trips' => 1,
            'ab_rate_per_trip' => 1500,
        ])->assertCreated();
        $tripId = $trip->json('id');

        $this->assertEquals(4, $trip->json('total_trips'));
        $this->assertEquals('6000.00', $trip->json('ab_flat_rate_applied'));
        $this->assertEquals('850.00', $trip->json('driver_base_pay_applied'));
        $this->assertEquals('350.00', $trip->json('driver_ot_pay_applied'));
        $this->assertEquals('1200.00', $trip->json('driver_pay_applied'));
        $this->assertEquals('1350.00', $trip->json('helper_base_pay_applied'));
        $this->assertEquals('200.00', $trip->json('helper_ot_pay_applied'));
        $this->assertEquals('1550.00', $trip->json('helper_pay_applied'));

        // Reassigning to maintenance truck or inactive driver on update is rejected
        $this->withToken($token)
            ->putJson("/api/v1/trips/{$tripId}", [
                'truck_id' => $maintenanceTruck->id,
                'driver_id' => $inactiveDriver->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['truck_id', 'driver_id']);

        // Updating non-crew fields succeeds even if the existing driver/truck is later deactivated
        $activeDriver->update(['status' => 'Inactive']);
        $assignedTruck->update(['status' => 'Under Maintenance']);

        $this->withToken($token)
            ->putJson("/api/v1/trips/{$tripId}", [
                'remarks' => 'Verified with paper monitoring sheet',
            ])
            ->assertOk()
            ->assertJsonPath('remarks', 'Verified with paper monitoring sheet');
    }

    public function test_trip_pay_snapshotting_preserved_when_employee_rates_change_and_date_filtering(): void
    {
        $token = $this->authenticateOperator();

        $driver = Employee::create([
            'first_name' => 'Danilo',
            'last_name' => 'Reyes',
            'role' => 'Driver',
            'pay_type' => 'Trip',
            'base_rate' => 700,
            'ot_per_trip_rate' => 350,
            'status' => 'Active',
        ]);

        $helper = Employee::create([
            'first_name' => 'Joel',
            'last_name' => 'Mendoza',
            'role' => 'Helper',
            'pay_type' => 'Trip',
            'base_rate' => 450,
            'ot_per_trip_rate' => 200,
            'status' => 'Active',
        ]);

        $truck = Truck::create(['plate_number' => 'NAA 1041', 'status' => 'Available']);

        $tripRes = $this->withToken($token)->postJson('/api/v1/trips', [
            'date' => '2026-11-10',
            'truck_id' => $truck->id,
            'driver_id' => $driver->id,
            'helper_id' => $helper->id,
            'reference_no' => 'MS-100',
            'regular_trips' => 2,
            'overtime_trips' => 0,
            'ab_rate_per_trip' => 1500,
        ])->assertCreated();
        $tripId = $tripRes->json('id');

        $this->assertEquals(2, $tripRes->json('total_trips'));
        $this->assertEquals('3000.00', $tripRes->json('ab_flat_rate_applied'));
        $this->assertEquals('1400.00', $tripRes->json('driver_pay_applied'));
        $this->assertEquals('900.00', $tripRes->json('helper_pay_applied'));

        // Update trip counts to 2 regular + 2 overtime -> recalculates AB total & crew pay snapshots
        $this->withToken($token)
            ->putJson("/api/v1/trips/{$tripId}", ['regular_trips' => 2, 'overtime_trips' => 2])
            ->assertOk()
            ->assertJsonPath('total_trips', 4)
            ->assertJsonPath('ab_flat_rate_applied', '6000.00')
            ->assertJsonPath('driver_base_pay_applied', '1400.00')
            ->assertJsonPath('driver_ot_pay_applied', '700.00')
            ->assertJsonPath('driver_pay_applied', '2100.00')
            ->assertJsonPath('helper_pay_applied', '1300.00');

        // Update back to 2 regular + 0 overtime
        $this->withToken($token)
            ->putJson("/api/v1/trips/{$tripId}", ['overtime_trips' => 0])
            ->assertOk()
            ->assertJsonPath('ab_flat_rate_applied', '3000.00')
            ->assertJsonPath('driver_pay_applied', '1400.00')
            ->assertJsonPath('helper_pay_applied', '900.00');

        // Change employee rates later -> existing log's snapshotted pay remains intact
        $driver->update(['base_rate' => 950]);
        $this->withToken($token)
            ->patchJson("/api/v1/trips/{$tripId}/status", ['status' => 'Completed'])
            ->assertOk()
            ->assertJsonPath('driver_pay_applied', '1400.00');

        // Date range filtering & invalid date range rejection
        $this->withToken($token)
            ->getJson('/api/v1/trips?start_date=2026-11-15&end_date=2026-11-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);

        $this->withToken($token)
            ->getJson('/api/v1/trips?start_date=2026-11-01&end_date=2026-11-15&status=Completed')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_general_ab_rate_setting_auto_applies_to_new_and_draft_trips_and_locks_completed_trips(): void
    {
        $token = $this->authenticateOperator();

        $driver = Employee::create([
            'first_name' => 'Roberto',
            'last_name' => 'Santos',
            'role' => 'Driver',
            'pay_type' => 'Daily',
            'base_rate' => 850,
            'ot_per_trip_rate' => 350,
            'status' => 'Active',
        ]);

        $truck = Truck::create(['plate_number' => 'NAA 1041', 'status' => 'Available']);

        // Default general rate is 1500.00
        $this->withToken($token)
            ->getJson('/api/v1/trips/ab-rate')
            ->assertOk()
            ->assertJsonPath('ab_rate_per_trip', '1500.00');

        // Creating Completed & Draft trips without ab_rate_per_trip applies default 1500.00
        $completedTrip = $this->withToken($token)->postJson('/api/v1/trips', [
            'date' => '2026-11-10',
            'truck_id' => $truck->id,
            'driver_id' => $driver->id,
            'regular_trips' => 2,
            'overtime_trips' => 0,
            'status' => 'Completed',
        ])->assertCreated();

        $completedTrip->assertJsonPath('ab_rate_per_trip', '1500.00')
            ->assertJsonPath('ab_flat_rate_applied', '3000.00');

        $draftTrip = $this->withToken($token)->postJson('/api/v1/trips', [
            'date' => '2026-11-11',
            'truck_id' => $truck->id,
            'driver_id' => $driver->id,
            'regular_trips' => 3,
            'overtime_trips' => 1,
            'status' => 'Draft',
        ])->assertCreated();

        $draftTrip->assertJsonPath('ab_rate_per_trip', '1500.00')
            ->assertJsonPath('ab_flat_rate_applied', '6000.00');

        // Updating general AB rate updates Draft trips and locks Completed trips
        $this->withToken($token)
            ->putJson('/api/v1/trips/ab-rate', ['ab_rate_per_trip' => 1650])
            ->assertOk()
            ->assertJsonPath('ab_rate_per_trip', '1650.00')
            ->assertJsonPath('updated_draft_trips', 1);

        $this->withToken($token)
            ->getJson("/api/v1/trips/{$draftTrip->json('id')}")
            ->assertOk()
            ->assertJsonPath('ab_rate_per_trip', '1650.00')
            ->assertJsonPath('ab_flat_rate_applied', '6600.00');

        $this->withToken($token)
            ->getJson("/api/v1/trips/{$completedTrip->json('id')}")
            ->assertOk()
            ->assertJsonPath('ab_rate_per_trip', '1500.00')
            ->assertJsonPath('ab_flat_rate_applied', '3000.00');

        // Subsequent trips created without ab_rate_per_trip use the updated 1650.00 rate
        $newTrip = $this->withToken($token)->postJson('/api/v1/trips', [
            'date' => '2026-11-12',
            'truck_id' => $truck->id,
            'driver_id' => $driver->id,
            'regular_trips' => 2,
            'overtime_trips' => 0,
            'status' => 'Draft',
        ])->assertCreated();

        $newTrip->assertJsonPath('ab_rate_per_trip', '1650.00')
            ->assertJsonPath('ab_flat_rate_applied', '3300.00');
    }
}
