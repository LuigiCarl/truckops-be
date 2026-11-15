<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'first_name',
        'last_name',
        'contact_number',
        'role',
        'pay_type',
        'base_rate',
        'ot_per_trip_rate',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'base_rate' => 'decimal:2',
            'ot_per_trip_rate' => 'decimal:2',
        ];
    }

    public function driverTrips(): HasMany
    {
        return $this->hasMany(Trip::class, 'driver_id');
    }

    public function helperTrips(): HasMany
    {
        return $this->hasMany(Trip::class, 'helper_id');
    }

    public function cashAdvances(): HasMany
    {
        return $this->hasMany(CashAdvance::class);
    }

    public function payrollRecords(): HasMany
    {
        return $this->hasMany(PayrollRecord::class);
    }
}
