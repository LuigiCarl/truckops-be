<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\FiltersByDateRange;
use App\Http\Controllers\Controller;
use App\Models\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollectionController extends Controller
{
    use FiltersByDateRange;

    public function index(Request $request): JsonResponse
    {
        $range = $this->validateDateRange($request);

        $query = $this->applyDateRange(
            Collection::query(),
            'payment_date',
            $range['start_date'],
            $range['end_date']
        );

        return response()->json(
            $query->orderByDesc('payment_date')->orderByDesc('id')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_date' => ['required', 'date'],
            'amount_received' => ['required', 'numeric', 'min:0'],
            'reference_notes' => ['nullable', 'string'],
        ]);

        $collection = Collection::create($validated);

        return response()->json($collection, 201);
    }

    public function show(Collection $collection): JsonResponse
    {
        return response()->json($collection);
    }

    public function update(Request $request, Collection $collection): JsonResponse
    {
        $validated = $request->validate([
            'payment_date' => ['sometimes', 'required', 'date'],
            'amount_received' => ['sometimes', 'required', 'numeric', 'min:0'],
            'reference_notes' => ['nullable', 'string'],
        ]);

        $collection->update($validated);

        return response()->json($collection->fresh());
    }

    public function destroy(Collection $collection): JsonResponse
    {
        $collection->delete();

        return response()->json([
            'message' => 'Collection deleted successfully.',
        ]);
    }
}
