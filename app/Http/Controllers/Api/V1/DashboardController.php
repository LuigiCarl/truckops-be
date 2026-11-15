<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\FiltersByDateRange;
use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\PayrollRecord;
use App\Models\Trip;
use App\Models\Truck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use FiltersByDateRange;

    public function summary(Request $request): JsonResponse
    {
        $range = $this->validateDateRange($request);
        $startDate = $range['start_date'];
        $endDate = $range['end_date'];

        $activeTrucks = Truck::query()
            ->whereIn('status', ['Available', 'Assigned'])
            ->count();

        $activeEmployees = Employee::query()
            ->where('status', 'Active')
            ->count();

        $completedTripsQuery = $this->applyDateRange(
            Trip::query()->where('status', 'Completed'),
            'date',
            $startDate,
            $endDate
        );

        $totalCompletedTrips = (int) (clone $completedTripsQuery)->sum('total_trips');
        $calculatedEarnings = round((float) $completedTripsQuery->sum('ab_flat_rate_applied'), 2);

        $totalExpenses = round((float) $this->applyDateRange(
            Expense::query(),
            'date',
            $startDate,
            $endDate
        )->sum('amount'), 2);

        $payrollInRangeQuery = $this->applyDateRange(
            PayrollRecord::query(),
            'period_end',
            $startDate,
            $endDate
        );

        $totalPayrollPaid = round((float) (clone $payrollInRangeQuery)
            ->where('status', 'Paid')
            ->sum('net_payable'), 2);

        $totalPayrollUnpaid = round((float) (clone $payrollInRangeQuery)
            ->where('status', 'Unpaid')
            ->sum('net_payable'), 2);

        $totalEmployeeCompensation = round((float) (clone $payrollInRangeQuery)
            ->selectRaw('COALESCE(SUM(total_base_pay + total_ot_pay), 0) as total')
            ->value('total'), 2);

        $totalCollections = round((float) $this->applyDateRange(
            Collection::query(),
            'payment_date',
            $startDate,
            $endDate
        )->sum('amount_received'), 2);

        $outstandingAbBalance = round($calculatedEarnings - $totalCollections, 2);
        $netCashFlow = round($totalCollections - ($totalExpenses + $totalPayrollPaid), 2);

        return response()->json([
            'active_trucks' => $activeTrucks,
            'active_employees' => $activeEmployees,
            'total_completed_trips' => $totalCompletedTrips,
            'calculated_earnings' => $calculatedEarnings,
            'total_expenses' => $totalExpenses,
            'total_payroll_paid' => $totalPayrollPaid,
            'total_payroll_unpaid' => $totalPayrollUnpaid,
            'total_employee_compensation' => $totalEmployeeCompensation,
            'total_collections' => $totalCollections,
            'outstanding_ab_balance' => $outstandingAbBalance,
            'net_cash_flow' => $netCashFlow,
        ]);
    }
}
