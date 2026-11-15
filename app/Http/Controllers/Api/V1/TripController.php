<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\FiltersByDateRange;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\SystemSetting;
use App\Models\Trip;
use App\Models\Truck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TripController extends Controller
{
    use FiltersByDateRange;

    public function index(Request $request): JsonResponse
    {
        $range = $this->validateDateRange($request);

        $query = Trip::query()
            ->with(['truck', 'driver', 'helper'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('truck_id'), fn ($q) => $q->where('truck_id', $request->integer('truck_id')))
            ->when($request->filled('driver_id'), fn ($q) => $q->where('driver_id', $request->integer('driver_id')));

        $this->applyDateRange($query, 'date', $range['start_date'], $range['end_date']);

        return response()->json(
            $query->orderByDesc('date')->orderByDesc('id')->get()
        );
    }

    public function getAbRate(): JsonResponse
    {
        return response()->json([
            'ab_rate_per_trip' => number_format(SystemSetting::getAbRatePerTrip(), 2, '.', ''),
        ]);
    }

    public function updateAbRate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ab_rate_per_trip' => ['required', 'numeric', 'min:0'],
        ]);

        $ratePerTrip = SystemSetting::setAbRatePerTrip((float) $validated['ab_rate_per_trip']);

        $draftTrips = Trip::query()->where('status', 'Draft')->get();
        foreach ($draftTrips as $draftTrip) {
            $draftTrip->update([
                'ab_rate_per_trip' => $ratePerTrip,
                'ab_flat_rate_applied' => round((int) $draftTrip->total_trips * $ratePerTrip, 2),
            ]);
        }

        return response()->json([
            'ab_rate_per_trip' => number_format($ratePerTrip, 2, '.', ''),
            'updated_draft_trips' => $draftTrips->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'truck_id' => ['required', 'exists:trucks,id'],
            'driver_id' => ['required', 'exists:employees,id'],
            'helper_id' => ['nullable', 'exists:employees,id', 'different:driver_id'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'regular_trips' => ['sometimes', 'integer', 'min:0'],
            'overtime_trips' => ['sometimes', 'integer', 'min:0'],
            'is_overtime' => ['sometimes', 'boolean'],
            'ab_rate_per_trip' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'ab_flat_rate_applied' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'driver_pay_applied' => ['nullable', 'numeric', 'min:0'],
            'helper_pay_applied' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:Draft,Completed,Cancelled'],
        ]);

        [$driver, $helper] = $this->validateCrewAndTruck($validated);

        [$regularTrips, $overtimeTrips, $totalTrips, $isOvertime] = $this->resolveTripCounts($validated);

        $ratePerTrip = isset($validated['ab_rate_per_trip'])
            ? round((float) $validated['ab_rate_per_trip'], 2)
            : (isset($validated['ab_flat_rate_applied'])
                ? round((float) $validated['ab_flat_rate_applied'], 2)
                : SystemSetting::getAbRatePerTrip());
        $totalAbRevenue = round($totalTrips * $ratePerTrip, 2);

        $driverPayBreakdown = $this->computeEmployeePay($driver, $regularTrips, $overtimeTrips);
        $driverTotalPay = array_key_exists('driver_pay_applied', $validated) && $validated['driver_pay_applied'] !== null
            ? round((float) $validated['driver_pay_applied'], 2)
            : $driverPayBreakdown['total'];

        $helperBasePay = null;
        $helperOtPay = null;
        $helperTotalPay = null;
        if ($helper !== null) {
            $helperPayBreakdown = $this->computeEmployeePay($helper, $regularTrips, $overtimeTrips);
            $helperBasePay = $helperPayBreakdown['base'];
            $helperOtPay = $helperPayBreakdown['ot'];
            $helperTotalPay = array_key_exists('helper_pay_applied', $validated) && $validated['helper_pay_applied'] !== null
                ? round((float) $validated['helper_pay_applied'], 2)
                : $helperPayBreakdown['total'];
        }

        $trip = Trip::create([
            'date' => $validated['date'],
            'truck_id' => $validated['truck_id'],
            'driver_id' => $validated['driver_id'],
            'helper_id' => $helper?->id,
            'reference_no' => $validated['reference_no'] ?? null,
            'regular_trips' => $regularTrips,
            'overtime_trips' => $overtimeTrips,
            'total_trips' => $totalTrips,
            'is_overtime' => $isOvertime,
            'ab_rate_per_trip' => $ratePerTrip,
            'ab_flat_rate_applied' => $totalAbRevenue,
            'driver_base_pay_applied' => $driverPayBreakdown['base'],
            'driver_ot_pay_applied' => $driverPayBreakdown['ot'],
            'driver_pay_applied' => $driverTotalPay,
            'helper_base_pay_applied' => $helperBasePay,
            'helper_ot_pay_applied' => $helperOtPay,
            'helper_pay_applied' => $helperTotalPay,
            'remarks' => $validated['remarks'] ?? null,
            'status' => $validated['status'] ?? 'Draft',
        ]);

        return response()->json($trip->load(['truck', 'driver', 'helper']), 201);
    }

    public function show(Trip $trip): JsonResponse
    {
        return response()->json($trip->load(['truck', 'driver', 'helper']));
    }

    public function update(Request $request, Trip $trip): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['sometimes', 'required', 'date'],
            'truck_id' => ['sometimes', 'required', 'exists:trucks,id'],
            'driver_id' => ['sometimes', 'required', 'exists:employees,id'],
            'helper_id' => ['nullable', 'exists:employees,id'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'regular_trips' => ['sometimes', 'integer', 'min:0'],
            'overtime_trips' => ['sometimes', 'integer', 'min:0'],
            'is_overtime' => ['sometimes', 'boolean'],
            'ab_rate_per_trip' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'ab_flat_rate_applied' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'driver_pay_applied' => ['nullable', 'numeric', 'min:0'],
            'helper_pay_applied' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
            'status' => ['sometimes', 'required', 'in:Draft,Completed,Cancelled'],
        ]);

        [$driver, $helper] = $this->validateCrewAndTruck($validated, $trip);

        [$regularTrips, $overtimeTrips, $totalTrips, $isOvertime] = $this->resolveTripCounts($validated, $trip);

        $validated['regular_trips'] = $regularTrips;
        $validated['overtime_trips'] = $overtimeTrips;
        $validated['total_trips'] = $totalTrips;
        $validated['is_overtime'] = $isOvertime;

        $ratePerTrip = isset($validated['ab_rate_per_trip'])
            ? round((float) $validated['ab_rate_per_trip'], 2)
            : (isset($validated['ab_flat_rate_applied'])
                ? round((float) $validated['ab_flat_rate_applied'], 2)
                : ($trip->status === 'Draft'
                    ? SystemSetting::getAbRatePerTrip()
                    : round((float) $trip->ab_rate_per_trip, 2)));

        $validated['ab_rate_per_trip'] = $ratePerTrip;
        $validated['ab_flat_rate_applied'] = round($totalTrips * $ratePerTrip, 2);

        $driverChanged = array_key_exists('driver_id', $validated) && (int) $validated['driver_id'] !== (int) $trip->driver_id;
        $helperChanged = array_key_exists('helper_id', $validated) && $validated['helper_id'] !== $trip->helper_id;
        $countsChanged = $regularTrips !== (int) $trip->regular_trips || $overtimeTrips !== (int) $trip->overtime_trips;

        if (($driverChanged || $countsChanged) && ! array_key_exists('driver_pay_applied', $validated)) {
            $driverPayBreakdown = $this->computeEmployeePay($driver, $regularTrips, $overtimeTrips);
            $validated['driver_base_pay_applied'] = $driverPayBreakdown['base'];
            $validated['driver_ot_pay_applied'] = $driverPayBreakdown['ot'];
            $validated['driver_pay_applied'] = $driverPayBreakdown['total'];
        }

        if ($helper === null) {
            $validated['helper_base_pay_applied'] = null;
            $validated['helper_ot_pay_applied'] = null;
            $validated['helper_pay_applied'] = null;
        } elseif (($helperChanged || $countsChanged) && ! array_key_exists('helper_pay_applied', $validated)) {
            $helperPayBreakdown = $this->computeEmployeePay($helper, $regularTrips, $overtimeTrips);
            $validated['helper_base_pay_applied'] = $helperPayBreakdown['base'];
            $validated['helper_ot_pay_applied'] = $helperPayBreakdown['ot'];
            $validated['helper_pay_applied'] = $helperPayBreakdown['total'];
        }

        $trip->update($validated);

        return response()->json($trip->fresh()->load(['truck', 'driver', 'helper']));
    }

    public function updateStatus(Request $request, Trip $trip): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:Draft,Completed,Cancelled'],
        ]);

        $trip->update(['status' => $validated['status']]);

        return response()->json($trip->fresh()->load(['truck', 'driver', 'helper']));
    }

    /**
     * Validate driver role/status, helper role/status, crew distinctness, and truck availability.
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: Employee, 1: ?Employee}
     */
    private function validateCrewAndTruck(array $validated, ?Trip $existingTrip = null): array
    {
        $errors = [];

        $driverId = $validated['driver_id'] ?? $existingTrip?->driver_id;
        $driverChanged = $existingTrip === null
            || (array_key_exists('driver_id', $validated) && (int) $validated['driver_id'] !== (int) $existingTrip->driver_id);

        $driver = Employee::findOrFail($driverId);

        if ($driver->role !== 'Driver') {
            $errors['driver_id'][] = 'The selected employee must have the Driver role.';
        }

        if ($driverChanged && $driver->status !== 'Active') {
            $errors['driver_id'][] = 'The selected driver must be Active.';
        }

        $helperId = array_key_exists('helper_id', $validated)
            ? $validated['helper_id']
            : $existingTrip?->helper_id;

        $helper = null;
        if ($helperId !== null) {
            if ((int) $helperId === (int) $driverId) {
                $errors['helper_id'][] = 'The helper id and driver id must be different.';
            }

            $helper = Employee::findOrFail($helperId);
            $helperChanged = $existingTrip === null
                || (array_key_exists('helper_id', $validated) && (int) $validated['helper_id'] !== (int) ($existingTrip->helper_id ?? 0));

            if ($helper->role !== 'Helper') {
                $errors['helper_id'][] = 'The selected employee must have the Helper role.';
            }

            if ($helperChanged && $helper->status !== 'Active') {
                $errors['helper_id'][] = 'The selected helper must be Active.';
            }
        }

        $truckId = $validated['truck_id'] ?? $existingTrip?->truck_id;
        $truckChanged = $existingTrip === null
            || (array_key_exists('truck_id', $validated) && (int) $validated['truck_id'] !== (int) $existingTrip->truck_id);

        if ($truckChanged && $truckId !== null) {
            $truck = Truck::findOrFail($truckId);
            if (in_array($truck->status, ['Under Maintenance', 'Inactive'], true)) {
                $errors['truck_id'][] = "The selected truck is currently {$truck->status} and cannot be assigned.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$driver, $helper];
    }

    /**
     * Resolve regular, overtime, total trip counts, and overtime flag.
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: int, 1: int, 2: int, 3: bool}
     */
    private function resolveTripCounts(array $validated, ?Trip $existingTrip = null): array
    {
        $hasRegular = array_key_exists('regular_trips', $validated);
        $hasOvertime = array_key_exists('overtime_trips', $validated);

        if ($hasRegular || $hasOvertime) {
            $regularTrips = $hasRegular
                ? (int) $validated['regular_trips']
                : ($existingTrip ? (int) $existingTrip->regular_trips : 0);
            $overtimeTrips = $hasOvertime
                ? (int) $validated['overtime_trips']
                : ($existingTrip ? (int) $existingTrip->overtime_trips : 0);
        } elseif (array_key_exists('is_overtime', $validated)) {
            $overtimeFlag = (bool) $validated['is_overtime'];
            if ($existingTrip && (int) $existingTrip->total_trips > 1) {
                $regularTrips = $overtimeFlag ? (int) $existingTrip->regular_trips : max(1, (int) $existingTrip->regular_trips);
                $overtimeTrips = $overtimeFlag ? max(1, (int) $existingTrip->overtime_trips) : 0;
            } else {
                $regularTrips = $overtimeFlag ? 0 : 1;
                $overtimeTrips = $overtimeFlag ? 1 : 0;
            }
        } elseif ($existingTrip) {
            $regularTrips = (int) $existingTrip->regular_trips;
            $overtimeTrips = (int) $existingTrip->overtime_trips;
        } else {
            $regularTrips = 1;
            $overtimeTrips = 0;
        }

        $totalTrips = $regularTrips + $overtimeTrips;
        if ($totalTrips < 1) {
            throw ValidationException::withMessages([
                'regular_trips' => ['Total trips made in a day must be at least 1 (regular + overtime).'],
            ]);
        }

        return [$regularTrips, $overtimeTrips, $totalTrips, $overtimeTrips > 0];
    }

    /**
     * Compute regular base pay, per-trip OT pay, and total pay snapshot for an employee.
     *
     * @return array{base: float, ot: float, total: float}
     */
    private function computeEmployeePay(Employee $employee, int $regularTrips, int $overtimeTrips): array
    {
        $basePay = 0.0;
        if ($regularTrips > 0) {
            $basePay = $employee->pay_type === 'Daily'
                ? round((float) $employee->base_rate, 2)
                : round($regularTrips * (float) $employee->base_rate, 2);
        }

        $otPay = round($overtimeTrips * (float) $employee->ot_per_trip_rate, 2);
        $totalPay = round($basePay + $otPay, 2);

        return [
            'base' => $basePay,
            'ot' => $otPay,
            'total' => $totalPay,
        ];
    }
}
