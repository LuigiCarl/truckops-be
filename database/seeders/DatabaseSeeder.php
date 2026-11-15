<?php

namespace Database\Seeders;

use App\Models\CashAdvance;
use App\Models\Collection;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PayrollRecord;
use App\Models\SystemSetting;
use App\Models\Trip;
use App\Models\Truck;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $monthStart = Carbon::now()->startOfMonth();
        $expiringSoonDate = Carbon::now()->addDays(18)->toDateString();

        // Single Operator Admin account
        User::query()->updateOrCreate(
            ['email' => 'admin@truckops.local'],
            [
                'name' => 'Operator Admin',
                'password' => 'password',
            ]
        );

        // 4 Company-owned Trucks
        $trucksData = [
            [
                'plate_number' => 'NAA 1041',
                'description' => 'Isuzu Elf Closed Van (6-Wheeler)',
                'registration_expiry' => '2027-03-15',
                'status' => 'Available',
            ],
            [
                'plate_number' => 'NBB 2082',
                'description' => 'Isuzu Forward Dropside (6-Wheeler)',
                'registration_expiry' => '2027-06-20',
                'status' => 'Available',
            ],
            [
                'plate_number' => 'NCC 3093',
                'description' => 'Fuso Canter Wing Van (6-Wheeler)',
                'registration_expiry' => '2027-08-10',
                'status' => 'Available',
            ],
            [
                'plate_number' => 'NDD 4014',
                'description' => 'Hino 300 Series Hauler (6-Wheeler)',
                'registration_expiry' => $expiringSoonDate,
                'status' => 'Available',
            ],
        ];

        $trucksByPlate = [];
        foreach ($trucksData as $truck) {
            $trucksByPlate[$truck['plate_number']] = Truck::query()->updateOrCreate(
                ['plate_number' => $truck['plate_number']],
                $truck
            );
        }

        // Default Expense Categories
        $categoriesByName = [];
        foreach (['Fuel', 'Maintenance', 'Office', 'Meals', 'Toll & Parking'] as $categoryName) {
            $categoriesByName[$categoryName] = ExpenseCategory::query()->firstOrCreate(['name' => $categoryName]);
        }

        // Sample Employees (mix of Daily and Trip-paid Drivers and Helpers)
        $employeesData = [
            [
                'first_name' => 'Roberto',
                'last_name' => 'Santos',
                'contact_number' => '09171234501',
                'role' => 'Driver',
                'pay_type' => 'Daily',
                'base_rate' => 850.00,
                'ot_per_trip_rate' => 350.00,
                'status' => 'Active',
            ],
            [
                'first_name' => 'Danilo',
                'last_name' => 'Reyes',
                'contact_number' => '09171234502',
                'role' => 'Driver',
                'pay_type' => 'Trip',
                'base_rate' => 700.00,
                'ot_per_trip_rate' => 350.00,
                'status' => 'Active',
            ],
            [
                'first_name' => 'Marco',
                'last_name' => 'Villanueva',
                'contact_number' => '09171234503',
                'role' => 'Helper',
                'pay_type' => 'Daily',
                'base_rate' => 550.00,
                'ot_per_trip_rate' => 200.00,
                'status' => 'Active',
            ],
            [
                'first_name' => 'Joel',
                'last_name' => 'Mendoza',
                'contact_number' => '09171234504',
                'role' => 'Helper',
                'pay_type' => 'Trip',
                'base_rate' => 450.00,
                'ot_per_trip_rate' => 200.00,
                'status' => 'Active',
            ],
        ];

        $employeesByName = [];
        foreach ($employeesData as $employee) {
            $key = "{$employee['first_name']} {$employee['last_name']}";
            $employeesByName[$key] = Employee::query()->updateOrCreate(
                [
                    'first_name' => $employee['first_name'],
                    'last_name' => $employee['last_name'],
                ],
                $employee
            );
        }

        // Generalized Asia Brewery flat rate per trip
        SystemSetting::setAbRatePerTrip(1500.00);

        // Current-month Daily Dispatch Logs inside Asia Brewery (3 Completed, 1 Draft, 1 Cancelled = 12 completed trips)
        $tripsData = [
            [
                'reference_no' => 'AB-2026-1001',
                'date' => $monthStart->copy()->addDay()->toDateString(),
                'truck_id' => $trucksByPlate['NAA 1041']->id,
                'driver_id' => $employeesByName['Roberto Santos']->id,
                'helper_id' => $employeesByName['Marco Villanueva']->id,
                'regular_trips' => 3,
                'overtime_trips' => 1,
                'total_trips' => 4,
                'is_overtime' => true,
                'ab_rate_per_trip' => 1500.00,
                'ab_flat_rate_applied' => 6000.00,
                'driver_base_pay_applied' => 850.00,
                'driver_ot_pay_applied' => 350.00,
                'driver_pay_applied' => 1200.00,
                'helper_base_pay_applied' => 550.00,
                'helper_ot_pay_applied' => 200.00,
                'helper_pay_applied' => 750.00,
                'remarks' => 'Verified against AB Monitoring Sheet',
                'status' => 'Completed',
            ],
            [
                'reference_no' => 'AB-2026-1002',
                'date' => $monthStart->copy()->addDays(4)->toDateString(),
                'truck_id' => $trucksByPlate['NBB 2082']->id,
                'driver_id' => $employeesByName['Danilo Reyes']->id,
                'helper_id' => $employeesByName['Joel Mendoza']->id,
                'regular_trips' => 3,
                'overtime_trips' => 0,
                'total_trips' => 3,
                'is_overtime' => false,
                'ab_rate_per_trip' => 1500.00,
                'ab_flat_rate_applied' => 4500.00,
                'driver_base_pay_applied' => 2100.00,
                'driver_ot_pay_applied' => 0.00,
                'driver_pay_applied' => 2100.00,
                'helper_base_pay_applied' => 1350.00,
                'helper_ot_pay_applied' => 0.00,
                'helper_pay_applied' => 1350.00,
                'remarks' => 'Signed Monitoring Sheet log',
                'status' => 'Completed',
            ],
            [
                'reference_no' => 'AB-2026-1003',
                'date' => $monthStart->copy()->addDays(7)->toDateString(),
                'truck_id' => $trucksByPlate['NCC 3093']->id,
                'driver_id' => $employeesByName['Roberto Santos']->id,
                'helper_id' => null,
                'regular_trips' => 4,
                'overtime_trips' => 1,
                'total_trips' => 5,
                'is_overtime' => true,
                'ab_rate_per_trip' => 1500.00,
                'ab_flat_rate_applied' => 7500.00,
                'driver_base_pay_applied' => 850.00,
                'driver_ot_pay_applied' => 350.00,
                'driver_pay_applied' => 1200.00,
                'helper_base_pay_applied' => null,
                'helper_ot_pay_applied' => null,
                'helper_pay_applied' => null,
                'remarks' => 'Verified high-volume plant dispatch',
                'status' => 'Completed',
            ],
            [
                'reference_no' => 'AB-2026-1004',
                'date' => $monthStart->copy()->addDays(10)->toDateString(),
                'truck_id' => $trucksByPlate['NBB 2082']->id,
                'driver_id' => $employeesByName['Danilo Reyes']->id,
                'helper_id' => $employeesByName['Joel Mendoza']->id,
                'regular_trips' => 2,
                'overtime_trips' => 1,
                'total_trips' => 3,
                'is_overtime' => true,
                'ab_rate_per_trip' => 1500.00,
                'ab_flat_rate_applied' => 4500.00,
                'driver_base_pay_applied' => 1400.00,
                'driver_ot_pay_applied' => 350.00,
                'driver_pay_applied' => 1750.00,
                'helper_base_pay_applied' => 900.00,
                'helper_ot_pay_applied' => 200.00,
                'helper_pay_applied' => 1100.00,
                'remarks' => 'Awaiting Monitoring Sheet sign-off',
                'status' => 'Draft',
            ],
            [
                'reference_no' => 'AB-2026-1005',
                'date' => $monthStart->copy()->addDays(8)->toDateString(),
                'truck_id' => $trucksByPlate['NDD 4014']->id,
                'driver_id' => $employeesByName['Danilo Reyes']->id,
                'helper_id' => null,
                'regular_trips' => 2,
                'overtime_trips' => 0,
                'total_trips' => 2,
                'is_overtime' => false,
                'ab_rate_per_trip' => 1500.00,
                'ab_flat_rate_applied' => 3000.00,
                'driver_base_pay_applied' => 1400.00,
                'driver_ot_pay_applied' => 0.00,
                'driver_pay_applied' => 1400.00,
                'helper_base_pay_applied' => null,
                'helper_ot_pay_applied' => null,
                'helper_pay_applied' => null,
                'remarks' => 'Cancelled due to line maintenance inside AB plant',
                'status' => 'Cancelled',
            ],
        ];

        foreach ($tripsData as $trip) {
            Trip::query()->updateOrCreate(
                ['reference_no' => $trip['reference_no']],
                $trip
            );
        }

        // Current-month AB Collections
        $collectionsData = [
            [
                'payment_date' => $monthStart->copy()->addDays(6)->toDateString(),
                'amount_received' => 9000.00,
                'reference_notes' => 'BDO Check #0048192 — Initial AB Cabuyao batch remittance',
            ],
            [
                'payment_date' => $monthStart->copy()->addDays(13)->toDateString(),
                'amount_received' => 4800.00,
                'reference_notes' => 'Bank Transfer REF-992014 — Cavite hauling settlement',
            ],
        ];

        foreach ($collectionsData as $collection) {
            Collection::query()->updateOrCreate(
                ['reference_notes' => $collection['reference_notes']],
                $collection
            );
        }

        // Current-month Operational & Maintenance Expenses
        $expensesData = [
            [
                'date' => $monthStart->copy()->addDays(2)->toDateString(),
                'category_id' => $categoriesByName['Fuel']->id,
                'truck_id' => $trucksByPlate['NAA 1041']->id,
                'amount' => 3200.00,
                'description' => 'Diesel refill — Petron SLEX Mamplasan (65L)',
            ],
            [
                'date' => $monthStart->copy()->addDays(5)->toDateString(),
                'category_id' => $categoriesByName['Maintenance']->id,
                'truck_id' => $trucksByPlate['NDD 4014']->id,
                'amount' => 1850.00,
                'description' => 'Brake lining adjustment and oil top-up',
            ],
            [
                'date' => $monthStart->copy()->addDays(8)->toDateString(),
                'category_id' => $categoriesByName['Office']->id,
                'truck_id' => null,
                'amount' => 950.00,
                'description' => 'Logbooks, waybill folders, and printer ink',
            ],
            [
                'date' => $monthStart->copy()->addDays(10)->toDateString(),
                'category_id' => $categoriesByName['Toll & Parking']->id,
                'truck_id' => $trucksByPlate['NBB 2082']->id,
                'amount' => 640.00,
                'description' => 'SLEX & CALAX Autosweep RFID reload',
            ],
        ];

        foreach ($expensesData as $expense) {
            Expense::query()->updateOrCreate(
                [
                    'date' => $expense['date'],
                    'description' => $expense['description'],
                ],
                $expense
            );
        }

        // Active Cash Advances (Vale)
        $advancesData = [
            [
                'employee_id' => $employeesByName['Roberto Santos']->id,
                'date_issued' => $monthStart->toDateString(),
                'total_amount' => 3000.00,
                'fixed_deduction_rate' => 500.00,
                'remaining_balance' => 2500.00,
                'status' => 'Active',
            ],
            [
                'employee_id' => $employeesByName['Danilo Reyes']->id,
                'date_issued' => $monthStart->toDateString(),
                'total_amount' => 1500.00,
                'fixed_deduction_rate' => 500.00,
                'remaining_balance' => 1500.00,
                'status' => 'Active',
            ],
        ];

        foreach ($advancesData as $advance) {
            CashAdvance::query()->updateOrCreate(
                [
                    'employee_id' => $advance['employee_id'],
                    'date_issued' => $advance['date_issued'],
                ],
                $advance
            );
        }

        // 1st Half Cutoff Payroll Records (Days 1–15)
        $periodStart = $monthStart->toDateString();
        $periodEnd = $monthStart->copy()->addDays(14)->toDateString();

        $payrollData = [
            [
                'employee_id' => $employeesByName['Roberto Santos']->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'total_base_pay' => 1700.00,
                'total_ot_pay' => 700.00,
                'advance_deduction_applied' => 500.00,
                'net_payable' => 1900.00,
                'status' => 'Paid',
            ],
            [
                'employee_id' => $employeesByName['Marco Villanueva']->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'total_base_pay' => 550.00,
                'total_ot_pay' => 200.00,
                'advance_deduction_applied' => 0.00,
                'net_payable' => 750.00,
                'status' => 'Unpaid',
            ],
            [
                'employee_id' => $employeesByName['Danilo Reyes']->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'total_base_pay' => 2100.00,
                'total_ot_pay' => 0.00,
                'advance_deduction_applied' => 500.00,
                'net_payable' => 1600.00,
                'status' => 'Unpaid',
            ],
            [
                'employee_id' => $employeesByName['Joel Mendoza']->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'total_base_pay' => 1350.00,
                'total_ot_pay' => 0.00,
                'advance_deduction_applied' => 0.00,
                'net_payable' => 1350.00,
                'status' => 'Unpaid',
            ],
        ];

        foreach ($payrollData as $record) {
            PayrollRecord::query()->updateOrCreate(
                [
                    'employee_id' => $record['employee_id'],
                    'period_start' => $record['period_start'],
                    'period_end' => $record['period_end'],
                ],
                $record
            );
        }
    }
}
