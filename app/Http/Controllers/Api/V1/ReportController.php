<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\FiltersByDateRange;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\PayrollRecord;
use App\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    use FiltersByDateRange;

    public function grossRevenueExport(Request $request): JsonResponse|StreamedResponse
    {
        $range = $this->validateDateRange($request);

        $query = Trip::query()
            ->with(['truck', 'driver', 'helper'])
            ->where('status', 'Completed');

        $this->applyDateRange($query, 'date', $range['start_date'], $range['end_date']);

        $trips = $query->orderBy('date')->orderBy('id')->get();
        $totalCompletedTrips = (int) $trips->sum('total_trips');
        $totalRevenue = round((float) $trips->sum('ab_flat_rate_applied'), 2);

        if ($request->query('format') === 'json') {
            return response()->json([
                'data' => $trips,
                'summary' => [
                    'total_completed_trips' => $totalCompletedTrips,
                    'total_gross_revenue' => $totalRevenue,
                ],
            ]);
        }

        $rows = $trips->map(fn (Trip $trip): array => [
            $trip->date->format('Y-m-d'),
            $trip->reference_no ?? '',
            $trip->truck?->plate_number ?? '',
            $trip->driver ? "{$trip->driver->first_name} {$trip->driver->last_name}" : '',
            $trip->helper ? "{$trip->helper->first_name} {$trip->helper->last_name}" : '',
            (string) $trip->regular_trips,
            (string) $trip->overtime_trips,
            (string) $trip->total_trips,
            number_format((float) $trip->ab_rate_per_trip, 2, '.', ''),
            number_format((float) $trip->ab_flat_rate_applied, 2, '.', ''),
        ])->all();

        $rows[] = [
            'TOTAL',
            '',
            '',
            '',
            '',
            '',
            '',
            "Trips: {$totalCompletedTrips}",
            '',
            number_format($totalRevenue, 2, '.', ''),
        ];

        return $this->streamCsv(
            'gross-revenue-report.csv',
            ['Date', 'Reference No', 'Truck Plate', 'Driver', 'Helper', 'Regular Trips', 'OT Trips', 'Total Trips', 'AB Rate / Trip', 'Total AB Revenue'],
            $rows
        );
    }

    public function expensesExport(Request $request): JsonResponse|StreamedResponse
    {
        $range = $this->validateDateRange($request);

        $query = Expense::query()
            ->with(['category', 'truck'])
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->filled('truck_id'), fn ($q) => $q->where('truck_id', $request->integer('truck_id')));

        $this->applyDateRange($query, 'date', $range['start_date'], $range['end_date']);

        $expenses = $query->orderBy('date')->orderBy('id')->get();
        $totalExpenses = round((float) $expenses->sum('amount'), 2);

        if ($request->query('format') === 'json') {
            return response()->json([
                'data' => $expenses,
                'summary' => [
                    'total_records' => $expenses->count(),
                    'total_expenses' => $totalExpenses,
                ],
            ]);
        }

        $rows = $expenses->map(fn (Expense $expense): array => [
            $expense->date->format('Y-m-d'),
            $expense->category?->name ?? '',
            $expense->truck?->plate_number ?? 'General',
            $expense->description ?? '',
            number_format((float) $expense->amount, 2, '.', ''),
        ])->all();

        $rows[] = [
            'TOTAL',
            '',
            '',
            "Records: {$expenses->count()}",
            number_format($totalExpenses, 2, '.', ''),
        ];

        return $this->streamCsv(
            'expenses-report.csv',
            ['Date', 'Category', 'Truck Plate', 'Description', 'Amount'],
            $rows
        );
    }

    public function payrollExport(Request $request): JsonResponse|StreamedResponse
    {
        $range = $this->validateFlexiblePeriodRange($request);

        $records = PayrollRecord::query()
            ->with('employee')
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($range['start_date'], fn ($q) => $q->whereDate('period_start', '>=', $range['start_date']))
            ->when($range['end_date'], fn ($q) => $q->whereDate('period_end', '<=', $range['end_date']))
            ->orderBy('period_end')
            ->orderBy('id')
            ->get();

        $totalBase = round((float) $records->sum('total_base_pay'), 2);
        $totalOt = round((float) $records->sum('total_ot_pay'), 2);
        $totalAdvance = round((float) $records->sum('advance_deduction_applied'), 2);
        $totalNet = round((float) $records->sum('net_payable'), 2);

        if ($request->query('format') === 'json') {
            return response()->json([
                'data' => $records,
                'summary' => [
                    'total_records' => $records->count(),
                    'total_base_pay' => $totalBase,
                    'total_ot_pay' => $totalOt,
                    'total_advance_deduction' => $totalAdvance,
                    'total_net_payable' => $totalNet,
                ],
            ]);
        }

        $rows = $records->map(fn (PayrollRecord $record): array => [
            $record->period_start->format('Y-m-d'),
            $record->period_end->format('Y-m-d'),
            $record->employee ? "{$record->employee->first_name} {$record->employee->last_name}" : '',
            $record->employee?->role ?? '',
            $record->employee?->pay_type ?? '',
            number_format((float) $record->total_base_pay, 2, '.', ''),
            number_format((float) $record->total_ot_pay, 2, '.', ''),
            number_format((float) $record->advance_deduction_applied, 2, '.', ''),
            number_format((float) $record->net_payable, 2, '.', ''),
            $record->status,
        ])->all();

        $rows[] = [
            'TOTAL',
            '',
            "Records: {$records->count()}",
            '',
            '',
            number_format($totalBase, 2, '.', ''),
            number_format($totalOt, 2, '.', ''),
            number_format($totalAdvance, 2, '.', ''),
            number_format($totalNet, 2, '.', ''),
            '',
        ];

        return $this->streamCsv(
            'payroll-report.csv',
            ['Period Start', 'Period End', 'Employee', 'Role', 'Pay Type', 'Base Pay', 'OT Pay', 'Advance Deduction', 'Net Payable', 'Status'],
            $rows
        );
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     */
    private function streamCsv(string $filename, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            // UTF-8 BOM so Excel opens PHP/peso characters cleanly
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
