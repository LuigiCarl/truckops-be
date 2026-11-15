<?php

namespace Tests\Feature;

use App\Models\CashAdvance;
use App\Models\Employee;
use App\Models\PayrollRecord;
use App\Models\Trip;
use App\Models\Truck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollAndAdvancesApiTest extends TestCase
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

    public function test_cash_advances_crud_and_balance_guards(): void
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

        $advanceRes = $this->withToken($token)->postJson('/api/v1/advances', [
            'employee_id' => $driver->id,
            'date_issued' => '2026-11-05',
            'total_amount' => 1200,
            'fixed_deduction_rate' => 400,
        ])->assertCreated();
        $advanceId = $advanceRes->json('id');

        $this->assertEquals('1200.00', $advanceRes->json('remaining_balance'));
        $this->assertEquals('Active', $advanceRes->json('status'));

        // Correcting total_amount from 1200 -> 1000 syncs remaining_balance via delta
        $this->withToken($token)
            ->putJson("/api/v1/advances/{$advanceId}", ['total_amount' => 1000])
            ->assertOk()
            ->assertJsonPath('remaining_balance', '1000.00');

        // Reject remaining_balance > total_amount
        $this->withToken($token)
            ->putJson("/api/v1/advances/{$advanceId}", ['remaining_balance' => 1500])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['remaining_balance']);

        // Setting remaining_balance to 0 auto-marks Paid; increasing re-activates
        $this->withToken($token)
            ->putJson("/api/v1/advances/{$advanceId}", ['remaining_balance' => 0])
            ->assertOk()
            ->assertJsonPath('status', 'Paid');

        $this->withToken($token)
            ->putJson("/api/v1/advances/{$advanceId}", ['remaining_balance' => 300])
            ->assertOk()
            ->assertJsonPath('status', 'Active');
    }

    public function test_payroll_calculation_date_issued_boundary_and_overlap_guard(): void
    {
        $token = $this->authenticateOperator();

        $dailyDriver = Employee::create([
            'first_name' => 'Roberto',
            'last_name' => 'Santos',
            'role' => 'Driver',
            'pay_type' => 'Daily',
            'base_rate' => 850,
            'ot_per_trip_rate' => 350,
            'status' => 'Active',
        ]);

        $tripHelper = Employee::create([
            'first_name' => 'Joel',
            'last_name' => 'Mendoza',
            'role' => 'Helper',
            'pay_type' => 'Trip',
            'base_rate' => 450,
            'ot_per_trip_rate' => 200,
            'status' => 'Active',
        ]);

        $truck = Truck::create(['plate_number' => 'NAA 1041', 'status' => 'Available']);

        // 2 daily logs on 2026-11-10 (one with higher Daily driver snapshot 900 -> Daily takes max 900, Trip helper sums 450 + 450 = 900)
        Trip::create([
            'date' => '2026-11-10',
            'truck_id' => $truck->id,
            'driver_id' => $dailyDriver->id,
            'helper_id' => $tripHelper->id,
            'regular_trips' => 1,
            'overtime_trips' => 0,
            'total_trips' => 1,
            'is_overtime' => false,
            'ab_rate_per_trip' => 4500,
            'ab_flat_rate_applied' => 4500,
            'driver_base_pay_applied' => 850,
            'driver_ot_pay_applied' => 0,
            'driver_pay_applied' => 850,
            'helper_base_pay_applied' => 450,
            'helper_ot_pay_applied' => 0,
            'helper_pay_applied' => 450,
            'status' => 'Completed',
        ]);

        Trip::create([
            'date' => '2026-11-10',
            'truck_id' => $truck->id,
            'driver_id' => $dailyDriver->id,
            'helper_id' => $tripHelper->id,
            'regular_trips' => 1,
            'overtime_trips' => 0,
            'total_trips' => 1,
            'is_overtime' => false,
            'ab_rate_per_trip' => 4500,
            'ab_flat_rate_applied' => 4500,
            'driver_base_pay_applied' => 900,
            'driver_ot_pay_applied' => 0,
            'driver_pay_applied' => 900,
            'helper_base_pay_applied' => 450,
            'helper_ot_pay_applied' => 0,
            'helper_pay_applied' => 450,
            'status' => 'Completed',
        ]);

        // 1 OT trip on 2026-11-11
        Trip::create([
            'date' => '2026-11-11',
            'truck_id' => $truck->id,
            'driver_id' => $dailyDriver->id,
            'helper_id' => $tripHelper->id,
            'regular_trips' => 0,
            'overtime_trips' => 1,
            'total_trips' => 1,
            'is_overtime' => true,
            'ab_rate_per_trip' => 4500,
            'ab_flat_rate_applied' => 4500,
            'driver_base_pay_applied' => 0,
            'driver_ot_pay_applied' => 350,
            'driver_pay_applied' => 350,
            'helper_base_pay_applied' => 0,
            'helper_ot_pay_applied' => 200,
            'helper_pay_applied' => 200,
            'status' => 'Completed',
        ]);

        // Draft and Cancelled logs must be ignored
        Trip::create([
            'date' => '2026-11-12',
            'truck_id' => $truck->id,
            'driver_id' => $dailyDriver->id,
            'regular_trips' => 2,
            'overtime_trips' => 0,
            'total_trips' => 2,
            'is_overtime' => false,
            'ab_rate_per_trip' => 2250,
            'ab_flat_rate_applied' => 4500,
            'driver_base_pay_applied' => 850,
            'driver_ot_pay_applied' => 0,
            'driver_pay_applied' => 850,
            'status' => 'Draft',
        ]);

        // Advance issued INSIDE period (2026-11-08) IS deducted
        $inPeriodAdvance = CashAdvance::create([
            'employee_id' => $dailyDriver->id,
            'date_issued' => '2026-11-08',
            'total_amount' => 1000,
            'fixed_deduction_rate' => 500,
            'remaining_balance' => 1000,
            'status' => 'Active',
        ]);

        // Advance issued AFTER period_end (2026-11-18) must NOT be deducted in 11-01..11-15 payroll
        $futureAdvance = CashAdvance::create([
            'employee_id' => $dailyDriver->id,
            'date_issued' => '2026-11-18',
            'total_amount' => 800,
            'fixed_deduction_rate' => 400,
            'remaining_balance' => 800,
            'status' => 'Active',
        ]);

        $gen = $this->withToken($token)->postJson('/api/v1/payroll/generate', [
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-15',
        ])->assertCreated();

        $byEmp = collect($gen->json())->keyBy('employee_id');

        // Driver: Base 900 (max on 11-10) + OT 350 = 1250; Advance = 500 (future 400 ignored) => Net = 750
        $driverPayroll = $byEmp->get($dailyDriver->id);
        $this->assertEquals('900.00', $driverPayroll['total_base_pay']);
        $this->assertEquals('350.00', $driverPayroll['total_ot_pay']);
        $this->assertEquals('500.00', $driverPayroll['advance_deduction_applied']);
        $this->assertEquals('750.00', $driverPayroll['net_payable']);

        // Helper: Base 900 (2 * 450) + OT 200 = 1100; Advance = 0 => Net = 1100
        $helperPayroll = $byEmp->get($tripHelper->id);
        $this->assertEquals('900.00', $helperPayroll['total_base_pay']);
        $this->assertEquals('200.00', $helperPayroll['total_ot_pay']);
        $this->assertEquals('1100.00', $helperPayroll['net_payable']);

        // Overlapping period (2026-11-10..2026-11-20) is rejected with 422
        $this->withToken($token)->postJson('/api/v1/payroll/generate', [
            'period_start' => '2026-11-10',
            'period_end' => '2026-11-20',
        ])->assertStatus(422)->assertJsonValidationErrors(['period_start']);

        // Idempotent re-generation for exact same period succeeds
        $this->withToken($token)->postJson('/api/v1/payroll/generate', [
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-15',
        ])->assertCreated()->assertJsonCount(2);

        // Pay driver payroll -> only inPeriodAdvance reduces (1000 -> 500); futureAdvance stays 800
        $this->withToken($token)
            ->patchJson('/api/v1/payroll/'.$driverPayroll['id'].'/pay')
            ->assertOk()
            ->assertJsonPath('status', 'Paid');

        $this->assertEquals('500.00', $inPeriodAdvance->fresh()->remaining_balance);
        $this->assertEquals('800.00', $futureAdvance->fresh()->remaining_balance);

        // Double pay rejected
        $this->withToken($token)
            ->patchJson('/api/v1/payroll/'.$driverPayroll['id'].'/pay')
            ->assertStatus(422);

        // Flexible query filters (start_date/end_date & period_start/period_end)
        $this->withToken($token)
            ->getJson('/api/v1/payroll?start_date=2026-11-01&end_date=2026-11-15&status=Paid')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_fifo_advance_deduction_gross_cap_and_stale_cleanup(): void
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

        $truck = Truck::create(['plate_number' => 'NBB 2082', 'status' => 'Available']);

        // 1 trip earning 700 gross pay
        $trip = Trip::create([
            'date' => '2026-11-18',
            'truck_id' => $truck->id,
            'driver_id' => $driver->id,
            'regular_trips' => 1,
            'overtime_trips' => 0,
            'total_trips' => 1,
            'is_overtime' => false,
            'ab_rate_per_trip' => 4500,
            'ab_flat_rate_applied' => 4500,
            'driver_base_pay_applied' => 700,
            'driver_ot_pay_applied' => 0,
            'driver_pay_applied' => 700,
            'status' => 'Completed',
        ]);

        // Two advances totaling 800 per cycle (500 older + 300 newer) -> capped at 700 gross
        $olderAdvance = CashAdvance::create([
            'employee_id' => $driver->id,
            'date_issued' => '2026-11-01',
            'total_amount' => 500,
            'fixed_deduction_rate' => 500,
            'remaining_balance' => 500,
            'status' => 'Active',
        ]);

        $newerAdvance = CashAdvance::create([
            'employee_id' => $driver->id,
            'date_issued' => '2026-11-10',
            'total_amount' => 600,
            'fixed_deduction_rate' => 300,
            'remaining_balance' => 600,
            'status' => 'Active',
        ]);

        $gen = $this->withToken($token)->postJson('/api/v1/payroll/generate', [
            'period_start' => '2026-11-16',
            'period_end' => '2026-11-30',
        ])->assertCreated();

        $payrollId = $gen->json('0.id');
        $this->assertEquals('700.00', $gen->json('0.advance_deduction_applied'));
        $this->assertEquals('0.00', $gen->json('0.net_payable'));

        // Pay -> olderAdvance fully settled (500 -> 0 Paid); remaining 200 deducted from newerAdvance (600 -> 400 Active)
        $this->withToken($token)
            ->patchJson("/api/v1/payroll/{$payrollId}/pay")
            ->assertOk()
            ->assertJsonPath('status', 'Paid');

        $this->assertEquals('0.00', $olderAdvance->fresh()->remaining_balance);
        $this->assertEquals('Paid', $olderAdvance->fresh()->status);
        $this->assertEquals('400.00', $newerAdvance->fresh()->remaining_balance);
        $this->assertEquals('Active', $newerAdvance->fresh()->status);

        // Stale unpaid record cleanup in another period
        $trip2 = Trip::create([
            'date' => '2026-12-05',
            'truck_id' => $truck->id,
            'driver_id' => $driver->id,
            'regular_trips' => 1,
            'overtime_trips' => 0,
            'total_trips' => 1,
            'is_overtime' => false,
            'ab_rate_per_trip' => 4500,
            'ab_flat_rate_applied' => 4500,
            'driver_base_pay_applied' => 700,
            'driver_ot_pay_applied' => 0,
            'driver_pay_applied' => 700,
            'status' => 'Completed',
        ]);

        $this->withToken($token)->postJson('/api/v1/payroll/generate', [
            'period_start' => '2026-12-01',
            'period_end' => '2026-12-15',
        ])->assertCreated()->assertJsonCount(1);

        $trip2->update(['status' => 'Cancelled']);

        $this->withToken($token)->postJson('/api/v1/payroll/generate', [
            'period_start' => '2026-12-01',
            'period_end' => '2026-12-15',
        ])->assertCreated()->assertJsonCount(0);

        $this->assertDatabaseMissing('payroll_records', [
            'employee_id' => $driver->id,
            'period_start' => '2026-12-01',
            'period_end' => '2026-12-15',
        ]);
    }

    public function test_paying_payroll_syncs_when_advance_already_settled_before_payout(): void
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

        $advance = CashAdvance::create([
            'employee_id' => $driver->id,
            'date_issued' => '2026-11-01',
            'total_amount' => 400,
            'fixed_deduction_rate' => 500,
            'remaining_balance' => 400,
            'status' => 'Active',
        ]);

        $payroll = PayrollRecord::create([
            'employee_id' => $driver->id,
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-15',
            'total_base_pay' => 700,
            'total_ot_pay' => 0,
            'advance_deduction_applied' => 400,
            'net_payable' => 300,
            'status' => 'Unpaid',
        ]);

        // Advance settled separately before payroll payout
        $advance->update(['remaining_balance' => 0, 'status' => 'Paid']);

        $this->withToken($token)
            ->patchJson("/api/v1/payroll/{$payroll->id}/pay")
            ->assertOk()
            ->assertJsonPath('status', 'Paid')
            ->assertJsonPath('advance_deduction_applied', '0.00')
            ->assertJsonPath('net_payable', '700.00');
    }
}
