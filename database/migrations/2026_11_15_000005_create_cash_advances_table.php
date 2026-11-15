<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('date_issued');
            $table->decimal('total_amount', 10, 2);
            $table->decimal('fixed_deduction_rate', 10, 2);
            $table->decimal('remaining_balance', 10, 2);
            $table->enum('status', ['Active', 'Paid'])->default('Active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_advances');
    }
};
