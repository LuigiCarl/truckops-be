<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('contact_number')->nullable();
            $table->enum('role', ['Driver', 'Helper']);
            $table->enum('pay_type', ['Daily', 'Trip']);
            $table->decimal('base_rate', 10, 2);
            $table->decimal('ot_per_trip_rate', 10, 2);
            $table->enum('status', ['Active', 'Inactive'])->default('Active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
