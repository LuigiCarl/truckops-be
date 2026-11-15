<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CashAdvance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashAdvanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = CashAdvance::query()
            ->with('employee')
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('date_issued')
            ->orderByDesc('id');

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'date_issued' => ['required', 'date'],
            'total_amount' => ['required', 'numeric', 'gt:0'],
            'fixed_deduction_rate' => ['required', 'numeric', 'gt:0'],
        ]);

        $advance = CashAdvance::create([
            'employee_id' => $validated['employee_id'],
            'date_issued' => $validated['date_issued'],
            'total_amount' => $validated['total_amount'],
            'fixed_deduction_rate' => $validated['fixed_deduction_rate'],
            'remaining_balance' => $validated['total_amount'],
            'status' => 'Active',
        ]);

        return response()->json($advance->load('employee'), 201);
    }

    public function show(CashAdvance $advance): JsonResponse
    {
        return response()->json($advance->load('employee'));
    }

    public function update(Request $request, CashAdvance $advance): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['sometimes', 'required', 'exists:employees,id'],
            'date_issued' => ['sometimes', 'required', 'date'],
            'total_amount' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'fixed_deduction_rate' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'remaining_balance' => ['sometimes', 'required', 'numeric', 'min:0'],
            'status' => ['sometimes', 'required', 'in:Active,Paid'],
        ]);

        $effectiveTotal = array_key_exists('total_amount', $validated)
            ? (float) $validated['total_amount']
            : (float) $advance->total_amount;

        if (array_key_exists('total_amount', $validated) && ! array_key_exists('remaining_balance', $validated)) {
            $delta = $effectiveTotal - (float) $advance->total_amount;
            $adjusted = (float) $advance->remaining_balance + $delta;
            $validated['remaining_balance'] = round(max(0.0, min($effectiveTotal, $adjusted)), 2);
        }

        $effectiveRemaining = array_key_exists('remaining_balance', $validated)
            ? (float) $validated['remaining_balance']
            : (float) $advance->remaining_balance;

        if ($effectiveRemaining > $effectiveTotal) {
            return response()->json([
                'message' => 'Remaining balance cannot exceed total amount.',
                'errors' => [
                    'remaining_balance' => ['The remaining balance may not be greater than the total amount.'],
                ],
            ], 422);
        }

        if ($effectiveRemaining <= 0) {
            $validated['remaining_balance'] = 0;
            $validated['status'] = 'Paid';
        } elseif (! array_key_exists('status', $validated) && $advance->status === 'Paid') {
            $validated['status'] = 'Active';
        }

        $advance->update($validated);

        return response()->json($advance->fresh()->load('employee'));
    }
}
