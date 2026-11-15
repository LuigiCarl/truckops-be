<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    use HasFactory;

    public const AB_RATE_KEY = 'ab_rate_per_trip';

    public const DEFAULT_AB_RATE = 1500.00;

    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Get the generalized Asia Brewery flat rate per trip.
     */
    public static function getAbRatePerTrip(): float
    {
        $value = static::query()
            ->where('key', self::AB_RATE_KEY)
            ->value('value');

        return round((float) ($value ?? self::DEFAULT_AB_RATE), 2);
    }

    /**
     * Persist the generalized Asia Brewery flat rate per trip.
     */
    public static function setAbRatePerTrip(float $rate): float
    {
        $rounded = round($rate, 2);

        static::query()->updateOrCreate(
            ['key' => self::AB_RATE_KEY],
            ['value' => number_format($rounded, 2, '.', '')]
        );

        return $rounded;
    }
}
