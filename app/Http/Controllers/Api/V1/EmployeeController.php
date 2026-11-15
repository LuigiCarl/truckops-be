<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Employee::query()
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderBy('last_name')
            ->orderBy('first_name');

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'role' => ['required', 'in:Driver,Helper'],
            'pay_type' => ['required', 'in:Daily,Trip'],
            'base_rate' => ['required', 'numeric', 'min:0'],
            'ot_per_trip_rate' => ['required', 'numeric', 'min:0'],
            'status' => ['sometimes', 'in:Active,Inactive'],
        ]);

        $employee = Employee::create([
            ...$validated,
            'status' => $validated['status'] ?? 'Active',
        ]);

        return response()->json($employee, 201);
    }

    public function show(Employee $employee): JsonResponse
    {
        return response()->json(
            $employee->load(['cashAdvances', 'payrollRecords'])
        );
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'role' => ['sometimes', 'required', 'in:Driver,Helper'],
            'pay_type' => ['sometimes', 'required', 'in:Daily,Trip'],
            'base_rate' => ['sometimes', 'required', 'numeric', 'min:0'],
            'ot_per_trip_rate' => ['sometimes', 'required', 'numeric', 'min:0'],
            'status' => ['sometimes', 'required', 'in:Active,Inactive'],
        ]);

        $employee->update($validated);

        return response()->json($employee->fresh());
    }

    public function updateStatus(Request $request, Employee $employee): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:Active,Inactive'],
        ]);

        $employee->update(['status' => $validated['status']]);

        return response()->json($employee->fresh());
    }
}
