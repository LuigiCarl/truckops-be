<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Trip extends Model
{
    use HasFactory;

    protected $fillable = [
        'date',
        'truck_id',
        'driver_id',
        'helper_id',
        'reference_no',
        'regular_trips',
        'overtime_trips',
        'total_trips',
        'is_overtime',
        'ab_rate_per_trip',
        'ab_flat_rate_applied',
        'driver_base_pay_applied',
        'driver_ot_pay_applied',
        'driver_pay_applied',
        'helper_base_pay_applied',
        'helper_ot_pay_applied',
        'helper_pay_applied',
        'remarks',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'regular_trips' => 'integer',
            'overtime_trips' => 'integer',
            'total_trips' => 'integer',
            'is_overtime' => 'boolean',
            'ab_rate_per_trip' => 'decimal:2',
            'ab_flat_rate_applied' => 'decimal:2',
            'driver_base_pay_applied' => 'decimal:2',
            'driver_ot_pay_applied' => 'decimal:2',
            'driver_pay_applied' => 'decimal:2',
            'helper_base_pay_applied' => 'decimal:2',
            'helper_ot_pay_applied' => 'decimal:2',
            'helper_pay_applied' => 'decimal:2',
        ];
    }

    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'driver_id');
    }

    public function helper(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'helper_id');
    }
}
