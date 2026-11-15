<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\FiltersByDateRange;
use App\Http\Controllers\Controller;
use App\Models\CashAdvance;
use App\Models\Employee;
use App\Models\PayrollRecord;
use App\Models\Trip;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollController extends Controller
{
    use FiltersByDateRange;

    public function index(Request $request): JsonResponse
    {
        $range = $this->validateFlexiblePeriodRange($request);

        $query = PayrollRecord::query()
            ->with('employee')
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($range['start_date'], fn ($q) => $q->whereDate('period_start', '>=', $range['start_date']))
            ->when($range['end_date'], fn ($q) => $q->whereDate('period_end', '<=', $range['end_date']))
            ->orderByDesc('period_end')
            ->orderByDesc('id');

        return response()->json($query->get());
    }

    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ]);

        $periodStart = $validated['period_start'];
        $periodEnd = $validated['period_end'];

        $records = DB::transaction(function () use ($periodStart, $periodEnd): array {
            $hasOverlap = PayrollRecord::query()
                ->whereDate('period_start', '<=', $periodEnd)
                ->whereDate('period_end', '>=', $periodStart)
                ->where(function (Builder $query) use ($periodStart, $periodEnd): void {
                    $query->whereDate('period_start', '!=', $periodStart)
                        ->orWhereDate('period_end', '!=', $periodEnd);
                })
                ->exists();

            if ($hasOverlap) {
                throw ValidationException::withMessages([
                    'period_start' => ['This payroll period overlaps with an existing payroll period.'],
                ]);
            }

            $completedTrips = Trip::query()
                ->where('status', 'Completed')
                ->whereDate('date', '>=', $periodStart)
                ->whereDate('date', '<=', $periodEnd)
                ->orderBy('date')
                ->orderBy('id')
                ->get();

            $employeeEntries = [];

            foreach ($completedTrips as $trip) {
                $employeeEntries[$trip->driver_id][] = [
                    'trip' => $trip,
                    'base_pay' => (float) $trip->driver_base_pay_applied,
                    'ot_pay' => (float) $trip->driver_ot_pay_applied,
                ];

                if ($trip->helper_id !== null) {
                    $employeeEntries[$trip->helper_id][] = [
                        'trip' => $trip,
                        'base_pay' => (float) ($trip->helper_base_pay_applied ?? 0),
                        'ot_pay' => (float) ($trip->helper_ot_pay_applied ?? 0),
                    ];
                }
            }

            $activeEmployeeIds = array_keys($employeeEntries);

            // Remove stale Unpaid records for this exact period if trips were cancelled or reassigned
            $staleQuery = PayrollRecord::query()
                ->whereDate('period_start', $periodStart)
                ->whereDate('period_end', $periodEnd)
                ->where('status', 'Unpaid');

            if ($activeEmployeeIds === []) {
                $staleQuery->delete();

                return [];
            }

            $staleQuery->whereNotIn('employee_id', $activeEmployeeIds)->delete();

            $employees = Employee::query()
                ->whereIn('id', $activeEmployeeIds)
                ->get()
                ->keyBy('id');

            $generated = [];

            foreach ($employeeEntries as $employeeId => $entries) {
                $employee = $employees->get($employeeId);

                if (! $employee) {
                    continue;
                }

                $existingPaid = PayrollRecord::query()
                    ->where('employee_id', $employeeId)
                    ->whereDate('period_start', $periodStart)
                    ->whereDate('period_end', $periodEnd)
                    ->where('status', 'Paid')
                    ->first();

                if ($existingPaid) {
                    $generated[] = $existingPaid->load('employee');

                    continue;
                }

                if ($employee->pay_type === 'Daily') {
                    $dailyPayByDate = [];
                    foreach ($entries as $entry) {
                        if ($entry['base_pay'] > 0) {
                            $dateKey = $entry['trip']->date->format('Y-m-d');
                            $dailyPayByDate[$dateKey] = max($dailyPayByDate[$dateKey] ?? 0.0, $entry['base_pay']);
                        }
                    }
                    $totalBasePay = round(array_sum($dailyPayByDate), 2);
                } else {
                    $totalBasePay = round(array_sum(array_column($entries, 'base_pay')), 2);
                }

                $totalOtPay = round(array_sum(array_column($entries, 'ot_pay')), 2);
                $grossPay = round($totalBasePay + $totalOtPay, 2);

                $activeAdvances = CashAdvance::query()
                    ->where('employee_id', $employeeId)
                    ->where('status', 'Active')
                    ->where('remaining_balance', '>', 0)
                    ->whereDate('date_issued', '<=', $periodEnd)
                    ->orderBy('date_issued')
                    ->orderBy('id')
                    ->get();

                $plannedDeduction = 0.0;
                foreach ($activeAdvances as $advance) {
                    $plannedDeduction += min((float) $advance->fixed_deduction_rate, (float) $advance->remaining_balance);
                }

                $advanceDeduction = round(min($plannedDeduction, $grossPay), 2);
                $netPayable = round(max(0, $grossPay - $advanceDeduction), 2);

                $existingUnpaid = PayrollRecord::query()
                    ->where('employee_id', $employeeId)
                    ->whereDate('period_start', $periodStart)
                    ->whereDate('period_end', $periodEnd)
                    ->first();

                $payload = [
                    'employee_id' => $employeeId,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'total_base_pay' => $totalBasePay,
                    'total_ot_pay' => $totalOtPay,
                    'advance_deduction_applied' => $advanceDeduction,
                    'net_payable' => $netPayable,
                    'status' => 'Unpaid',
                ];

                if ($existingUnpaid) {
                    $existingUnpaid->update($payload);
                    $record = $existingUnpaid->fresh();
                } else {
                    $record = PayrollRecord::create($payload);
                }

                $generated[] = $record->load('employee');
            }

            return $generated;
        });

        return response()->json($records, 201);
    }

    public function show(PayrollRecord $payroll): JsonResponse
    {
        return response()->json($payroll->load('employee'));
    }

    public function pay(PayrollRecord $payroll): JsonResponse
    {
        $result = DB::transaction(function () use ($payroll): ?PayrollRecord {
            $lockedPayroll = PayrollRecord::query()
                ->whereKey($payroll->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPayroll->status === 'Paid') {
                return null;
            }

            $remainingToDeduct = (float) $lockedPayroll->advance_deduction_applied;
            $actualDeducted = 0.0;

            if ($remainingToDeduct > 0) {
                $activeAdvances = CashAdvance::query()
                    ->where('employee_id', $lockedPayroll->employee_id)
                    ->where('status', 'Active')
                    ->where('remaining_balance', '>', 0)
                    ->whereDate('date_issued', '<=', $lockedPayroll->period_end->format('Y-m-d'))
                    ->orderBy('date_issued')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($activeAdvances as $advance) {
                    if ($remainingToDeduct <= 0) {
                        break;
                    }

                    $deduct = min(
                        $remainingToDeduct,
                        (float) $advance->fixed_deduction_rate,
                        (float) $advance->remaining_balance
                    );

                    $newBalance = round((float) $advance->remaining_balance - $deduct, 2);

                    if ($newBalance <= 0) {
                        $advance->remaining_balance = 0;
                        $advance->status = 'Paid';
                    } else {
                        $advance->remaining_balance = $newBalance;
                    }

                    $advance->save();
                    $actualDeducted = round($actualDeducted + $deduct, 2);
                    $remainingToDeduct = round($remainingToDeduct - $deduct, 2);
                }
            }

            $grossPay = round((float) $lockedPayroll->total_base_pay + (float) $lockedPayroll->total_ot_pay, 2);
            $lockedPayroll->advance_deduction_applied = $actualDeducted;
            $lockedPayroll->net_payable = round(max(0, $grossPay - $actualDeducted), 2);
            $lockedPayroll->status = 'Paid';
            $lockedPayroll->save();

            return $lockedPayroll->fresh()->load('employee.cashAdvances');
        });

        if ($result === null) {
            return response()->json([
                'message' => 'Payroll record is already paid.',
            ], 422);
        }

        return response()->json($result);
    }
}
