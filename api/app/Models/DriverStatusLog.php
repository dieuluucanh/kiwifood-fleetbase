<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Append-only log of driver online/offline transitions, written by the patched
 * `DriverController::toggleOnline()`. Consumed by `AggregateDriverActivity` to
 * compute per-day online duration.
 */
class DriverStatusLog extends Model
{
    protected $table = 'driver_status_log';

    protected $guarded = ['id'];

    protected $casts = [
        'online'     => 'boolean',
        'occurred_at' => 'datetime',
    ];

    /**
     * Fleetbase convention: generate a `uuid` + `_key` on create when not set.
     * (The patched controller / aggregator insert via `DB::table`, which sets
     * these explicitly; this hook covers any Eloquent-created rows.)
     */
    protected static function booted(): void
    {
        static::creating(function (self $log) {
            if (empty($log->uuid)) {
                $log->uuid = Str::uuid()->toString();
            }
            if (empty($log->_key)) {
                $log->_key = Str::lower(Str::random(16));
            }
        });
    }
}
