<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Materialized per-driver-per-day activity summary. Registered as the
 * "Driver Activity" Report Builder data source.
 */
class DriverActivityDaily extends Model
{
    protected $table = 'driver_activity_daily';

    protected $guarded = ['id'];

    protected $casts = [
        'activity_date'              => 'date',
        'distance_km'                => 'decimal:3',
        'moving_duration_seconds'    => 'integer',
        'online_duration_seconds'    => 'integer',
        'ping_count'                 => 'integer',
        'first_ping_at'              => 'datetime',
        'last_ping_at'               => 'datetime',
        'meta'                       => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $row) {
            if (empty($row->uuid)) {
                $row->uuid = Str::uuid()->toString();
            }
            if (empty($row->_key)) {
                $row->_key = Str::lower(Str::random(16));
            }
        });
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(\Fleetbase\FleetOps\Models\Driver::class, 'driver_uuid', 'uuid');
    }

    public function fleet(): BelongsTo
    {
        return $this->belongsTo(\Fleetbase\FleetOps\Models\Fleet::class, 'fleet_uuid', 'uuid');
    }
}
