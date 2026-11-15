<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashAdvance extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'date_issued',
        'total_amount',
        'fixed_deduction_rate',
        'remaining_balance',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'date_issued' => 'date:Y-m-d',
            'total_amount' => 'decimal:2',
            'fixed_deduction_rate' => 'decimal:2',
            'remaining_balance' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
