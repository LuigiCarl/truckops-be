<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

trait FiltersByDateRange
{
    /**
     * @return array{start_date: ?string, end_date: ?string}
     */
    protected function validateDateRange(
        Request $request,
        string $startKey = 'start_date',
        string $endKey = 'end_date'
    ): array {
        $validated = $request->validate([
            $startKey => ['nullable', 'date'],
            $endKey => [
                'nullable',
                'date',
                Rule::when($request->filled($startKey), ["after_or_equal:{$startKey}"]),
            ],
        ]);

        return [
            'start_date' => $validated[$startKey] ?? null,
            'end_date' => $validated[$endKey] ?? null,
        ];
    }

    /**
     * Validate either start_date/end_date or period_start/period_end query params.
     *
     * @return array{start_date: ?string, end_date: ?string}
     */
    protected function validateFlexiblePeriodRange(Request $request): array
    {
        $standard = $this->validateDateRange($request, 'start_date', 'end_date');
        $period = $this->validateDateRange($request, 'period_start', 'period_end');

        $startDate = $standard['start_date'] ?? $period['start_date'];
        $endDate = $standard['end_date'] ?? $period['end_date'];

        if ($startDate !== null && $endDate !== null && $endDate < $startDate) {
            $request->validate([
                'end_date' => ['after_or_equal:start_date'],
            ]);
        }

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
    }

    protected function applyDateRange(Builder $query, string $column, ?string $startDate, ?string $endDate): Builder
    {
        return $query
            ->when($startDate, fn (Builder $q) => $q->whereDate($column, '>=', $startDate))
            ->when($endDate, fn (Builder $q) => $q->whereDate($column, '<=', $endDate));
    }
}
