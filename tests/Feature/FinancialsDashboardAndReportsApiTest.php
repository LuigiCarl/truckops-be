<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollRecord;
use App\Models\Trip;
use App\Models\Truck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialsDashboardAndReportsApiTest extends TestCase
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

    public function test_collections_expenses_dashboard_summary_and_reports_exports(): void
    {
        $token = $this->authenticateOperator();

        // Expense Categories: GET & POST + duplicate check
        $fuelCat = $this->withToken($token)
            ->postJson('/api/v1/expenses/categories', ['name' => 'Fuel'])
            ->assertCreated();
        $fuelCategoryId = $fuelCat->json('id');

        $this->withToken($token)
            ->postJson('/api/v1/expenses/categories', ['name' => 'Fuel'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->withToken($token)
            ->getJson('/api/v1/expenses/categories')
            ->assertOk()
            ->assertJsonCount(1);

        // Setup master data + completed trips
        $truck = Truck::create(['plate_number' => 'NAA 1041', 'status' => 'Available']);
        $driver = Employee::create([
            'first_name' => 'Roberto',
            'last_name' => 'Santos',
            'role' => 'Driver',
            'pay_type' => 'Trip',
            'base_rate' => 700,
            'ot_per_trip_rate' => 350,
            'status' => 'Active',
        ]);

        Trip::create([
            'date' => '2026-11-10',
            'truck_id' => $truck->id,
            'driver_id' => $driver->id,
            'reference_no' => 'MS-010',
            'regular_trips' => 3,
            'overtime_trips' => 0,
            'total_trips' => 3,
            'is_overtime' => false,
            'ab_rate_per_trip' => 1500,
            'ab_flat_rate_applied' => 4500,
            'driver_base_pay_applied' => 2100,
            'driver_ot_pay_applied' => 0,
            'driver_pay_applied' => 2100,
            'status' => 'Completed',
        ]);

        Trip::create([
            'date' => '2026-11-12',
            'truck_id' => $truck->id,
            'driver_id' => $driver->id,
            'reference_no' => 'MS-011',
            'regular_trips' => 1,
            'overtime_trips' => 1,
            'total_trips' => 2,
            'is_overtime' => true,
            'ab_rate_per_trip' => 2500,
            'ab_flat_rate_applied' => 5000,
            'driver_base_pay_applied' => 700,
            'driver_ot_pay_applied' => 350,
            'driver_pay_applied' => 1050,
            'status' => 'Completed',
        ]);

        // Outside-range trip (Dec) to verify dashboard date filtering
        Trip::create([
            'date' => '2026-12-01',
            'truck_id' => $truck->id,
            'driver_id' => $driver->id,
            'regular_trips' => 2,
            'overtime_trips' => 0,
            'total_trips' => 2,
            'is_overtime' => false,
            'ab_rate_per_trip' => 2000,
            'ab_flat_rate_applied' => 4000,
            'driver_base_pay_applied' => 1400,
            'driver_ot_pay_applied' => 0,
            'driver_pay_applied' => 1400,
            'status' => 'Completed',
        ]);

        // Payroll: 1 Paid + 1 Unpaid in Nov
        PayrollRecord::create([
            'employee_id' => $driver->id,
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-15',
            'total_base_pay' => 700,
            'total_ot_pay' => 350,
            'advance_deduction_applied' => 200,
            'net_payable' => 850,
            'status' => 'Paid',
        ]);

        PayrollRecord::create([
            'employee_id' => $driver->id,
            'period_start' => '2026-11-16',
            'period_end' => '2026-11-30',
            'total_base_pay' => 700,
            'total_ot_pay' => 0,
            'advance_deduction_applied' => 0,
            'net_payable' => 700,
            'status' => 'Unpaid',
        ]);

        // Collections full CRUD
        $tempCol = $this->withToken($token)->postJson('/api/v1/collections', [
            'payment_date' => '2026-11-13',
            'amount_received' => 1000,
        ])->assertCreated();
        $this->withToken($token)->getJson('/api/v1/collections/'.$tempCol->json('id'))->assertOk();
        $this->withToken($token)
            ->putJson('/api/v1/collections/'.$tempCol->json('id'), ['amount_received' => 1200])
            ->assertOk()
            ->assertJsonPath('amount_received', '1200.00');
        $this->withToken($token)->deleteJson('/api/v1/collections/'.$tempCol->json('id'))->assertOk();

        $this->withToken($token)->postJson('/api/v1/collections', [
            'payment_date' => '2026-11-14',
            'amount_received' => 6000,
            'reference_notes' => 'AB Payment #1',
        ])->assertCreated();

        $this->withToken($token)
            ->getJson('/api/v1/collections?start_date=2026-11-01&end_date=2026-11-30')
            ->assertOk()
            ->assertJsonCount(1);

        // Expenses full CRUD
        $tempExp = $this->withToken($token)->postJson('/api/v1/expenses', [
            'date' => '2026-11-10',
            'category_id' => $fuelCategoryId,
            'amount' => 500,
        ])->assertCreated();
        $this->withToken($token)->getJson('/api/v1/expenses/'.$tempExp->json('id'))->assertOk();
        $this->withToken($token)
            ->putJson('/api/v1/expenses/'.$tempExp->json('id'), ['amount' => 600])
            ->assertOk()
            ->assertJsonPath('amount', '600.00');
        $this->withToken($token)->deleteJson('/api/v1/expenses/'.$tempExp->json('id'))->assertOk();

        $this->withToken($token)->postJson('/api/v1/expenses', [
            'date' => '2026-11-11',
            'category_id' => $fuelCategoryId,
            'amount' => 2500,
            'description' => 'Diesel full tank',
            'truck_id' => $truck->id,
        ])->assertCreated();

        $this->withToken($token)
            ->getJson("/api/v1/expenses?category_id={$fuelCategoryId}&truck_id={$truck->id}&end_date=2026-11-30")
            ->assertOk()
            ->assertJsonCount(1);

        // Dashboard Summary validation + metrics for November
        $this->withToken($token)
            ->getJson('/api/v1/dashboard/summary?start_date=2026-11-30&end_date=2026-11-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);

        // Nov Trips: 3 + 2 = 5 | Earnings: 4500 + 5000 = 9500 | Collections: 6000 => Outstanding: 3500
        // Nov Expenses: 2500 | Payroll Paid: 850 | Net Cash Flow: 6000 - (2500 + 850) = 2650
        // Nov Compensation (gross): (700 + 350) + 700 = 1750 | Payroll Unpaid: 700
        $this->withToken($token)
            ->getJson('/api/v1/dashboard/summary?start_date=2026-11-01&end_date=2026-11-30')
            ->assertOk()
            ->assertJson([
                'active_trucks' => 1,
                'active_employees' => 1,
                'total_completed_trips' => 5,
                'calculated_earnings' => 9500,
                'total_expenses' => 2500,
                'total_payroll_paid' => 850,
                'total_payroll_unpaid' => 700,
                'total_employee_compensation' => 1750,
                'total_collections' => 6000,
                'outstanding_ab_balance' => 3500,
                'net_cash_flow' => 2650,
            ]);

        // Reports: CSV (with UTF-8 BOM & summary row) and JSON
        $revCsv = $this->withToken($token)
            ->get('/api/v1/reports/gross-revenue/export?start_date=2026-11-01&end_date=2026-11-30')
            ->assertOk();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $revCsv->streamedContent());
        $this->assertStringContainsString('9500.00', $revCsv->streamedContent());

        $this->withToken($token)
            ->getJson('/api/v1/reports/gross-revenue/export?format=json&start_date=2026-11-01&end_date=2026-11-30')
            ->assertOk()
            ->assertJsonPath('summary.total_completed_trips', 5);

        $expCsv = $this->withToken($token)
            ->get('/api/v1/reports/expenses/export?start_date=2026-11-01&end_date=2026-11-30')
            ->assertOk();
        $this->assertStringContainsString('2500.00', $expCsv->streamedContent());

        $this->withToken($token)
            ->getJson('/api/v1/reports/expenses/export?format=json')
            ->assertOk()
            ->assertJsonPath('summary.total_records', 1);

        $payCsv = $this->withToken($token)
            ->get('/api/v1/reports/payroll/export?start_date=2026-11-01&end_date=2026-11-15')
            ->assertOk();
        $this->assertStringContainsString('850.00', $payCsv->streamedContent());

        $this->withToken($token)
            ->getJson('/api/v1/reports/payroll/export?format=json&period_start=2026-11-01&period_end=2026-11-30')
            ->assertOk()
            ->assertJsonPath('summary.total_records', 2);
    }
}
