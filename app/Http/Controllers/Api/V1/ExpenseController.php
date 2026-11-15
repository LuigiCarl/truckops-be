<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\FiltersByDateRange;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    use FiltersByDateRange;

    public function categories(): JsonResponse
    {
        return response()->json(
            ExpenseCategory::query()->orderBy('name')->get()
        );
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:expense_categories,name'],
        ]);

        $category = ExpenseCategory::create($validated);

        return response()->json($category, 201);
    }

    public function index(Request $request): JsonResponse
    {
        $range = $this->validateDateRange($request);

        $query = Expense::query()
            ->with(['category', 'truck'])
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->filled('truck_id'), fn ($q) => $q->where('truck_id', $request->integer('truck_id')));

        $this->applyDateRange($query, 'date', $range['start_date'], $range['end_date']);

        return response()->json(
            $query->orderByDesc('date')->orderByDesc('id')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'category_id' => ['required', 'exists:expense_categories,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'truck_id' => ['nullable', 'exists:trucks,id'],
        ]);

        $expense = Expense::create($validated);

        return response()->json($expense->load(['category', 'truck']), 201);
    }

    public function show(Expense $expense): JsonResponse
    {
        return response()->json($expense->load(['category', 'truck']));
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['sometimes', 'required', 'date'],
            'category_id' => ['sometimes', 'required', 'exists:expense_categories,id'],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'truck_id' => ['nullable', 'exists:trucks,id'],
        ]);

        $expense->update($validated);

        return response()->json($expense->fresh()->load(['category', 'truck']));
    }

    public function destroy(Expense $expense): JsonResponse
    {
        $expense->delete();

        return response()->json([
            'message' => 'Expense deleted successfully.',
        ]);
    }
}
