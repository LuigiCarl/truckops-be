<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CashAdvanceController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\PayrollController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\TripController;
use App\Http\Controllers\Api\V1\TruckController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Auth
    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/user', [AuthController::class, 'user']);

        // Dashboard
        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);

        // Master Data: Employees
        Route::apiResource('employees', EmployeeController::class)->except(['destroy']);
        Route::patch('/employees/{employee}/status', [EmployeeController::class, 'updateStatus']);

        // Master Data: Trucks
        Route::apiResource('trucks', TruckController::class)->except(['destroy']);
        Route::patch('/trucks/{truck}/status', [TruckController::class, 'updateStatus']);

        // Operations: Trips
        Route::get('/trips/ab-rate', [TripController::class, 'getAbRate']);
        Route::put('/trips/ab-rate', [TripController::class, 'updateAbRate']);
        Route::apiResource('trips', TripController::class)->except(['destroy']);
        Route::patch('/trips/{trip}/status', [TripController::class, 'updateStatus']);

        // Operations: Collections
        Route::apiResource('collections', CollectionController::class);

        // Operations: Expenses (categories route must precede expenses/{expense})
        Route::get('/expenses/categories', [ExpenseController::class, 'categories']);
        Route::post('/expenses/categories', [ExpenseController::class, 'storeCategory']);
        Route::apiResource('expenses', ExpenseController::class);

        // Payroll & Cash Advances
        Route::apiResource('advances', CashAdvanceController::class)->except(['destroy']);

        Route::get('/payroll', [PayrollController::class, 'index']);
        Route::post('/payroll/generate', [PayrollController::class, 'generate']);
        Route::get('/payroll/{payroll}', [PayrollController::class, 'show']);
        Route::patch('/payroll/{payroll}/pay', [PayrollController::class, 'pay']);

        // Reports (Exports)
        Route::get('/reports/gross-revenue/export', [ReportController::class, 'grossRevenueExport']);
        Route::get('/reports/expenses/export', [ReportController::class, 'expensesExport']);
        Route::get('/reports/payroll/export', [ReportController::class, 'payrollExport']);
    });
});
