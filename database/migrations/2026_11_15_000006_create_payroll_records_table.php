<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('total_base_pay', 10, 2);
            $table->decimal('total_ot_pay', 10, 2);
            $table->decimal('advance_deduction_applied', 10, 2)->default(0);
            $table->decimal('net_payable', 10, 2);
            $table->enum('status', ['Unpaid', 'Paid'])->default('Unpaid');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_records');
    }
};
