<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->foreignId('truck_id')->constrained('trucks')->restrictOnDelete();
            $table->foreignId('driver_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('helper_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('reference_no')->nullable();
            $table->unsignedInteger('regular_trips')->default(1);
            $table->unsignedInteger('overtime_trips')->default(0);
            $table->unsignedInteger('total_trips')->default(1);
            $table->boolean('is_overtime')->default(false);
            $table->decimal('ab_rate_per_trip', 10, 2);
            $table->decimal('ab_flat_rate_applied', 10, 2);
            $table->decimal('driver_base_pay_applied', 10, 2)->default(0);
            $table->decimal('driver_ot_pay_applied', 10, 2)->default(0);
            $table->decimal('driver_pay_applied', 10, 2);
            $table->decimal('helper_base_pay_applied', 10, 2)->nullable();
            $table->decimal('helper_ot_pay_applied', 10, 2)->nullable();
            $table->decimal('helper_pay_applied', 10, 2)->nullable();
            $table->text('remarks')->nullable();
            $table->enum('status', ['Draft', 'Completed', 'Cancelled'])->default('Draft');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
