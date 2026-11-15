<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Truck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TruckController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Truck::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderBy('plate_number');

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plate_number' => ['required', 'string', 'max:50', 'unique:trucks,plate_number'],
            'description' => ['nullable', 'string', 'max:255'],
            'registration_expiry' => ['nullable', 'date'],
            'status' => ['sometimes', 'in:Available,Assigned,Under Maintenance,Inactive'],
        ]);

        $truck = Truck::create([
            ...$validated,
            'status' => $validated['status'] ?? 'Available',
        ]);

        return response()->json($truck, 201);
    }

    public function show(Truck $truck): JsonResponse
    {
        return response()->json(
            $truck->load(['trips', 'expenses'])
        );
    }

    public function update(Request $request, Truck $truck): JsonResponse
    {
        $validated = $request->validate([
            'plate_number' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('trucks', 'plate_number')->ignore($truck->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'registration_expiry' => ['nullable', 'date'],
            'status' => ['sometimes', 'required', 'in:Available,Assigned,Under Maintenance,Inactive'],
        ]);

        $truck->update($validated);

        return response()->json($truck->fresh());
    }

    public function updateStatus(Request $request, Truck $truck): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:Available,Assigned,Under Maintenance,Inactive'],
        ]);

        $truck->update(['status' => $validated['status']]);

        return response()->json($truck->fresh());
    }
}
