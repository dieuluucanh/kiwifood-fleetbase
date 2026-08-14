<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Materialized per-driver-per-day activity summary.
 *
 * Grain: one row per (company_uuid, driver_uuid, activity_date). A driver in
 * multiple fleets is attributed to a single fleet (most-recently-assigned) to
 * avoid double-counting when reports sum across fleets.
 *
 * Populated by `php artisan driver-activity:aggregate` from `positions`
 * (distance + movement duration) and `driver_status_log` (online duration).
 * Registered as a Report Builder data source ("Driver Activity") via
 * `App\Support\Reporting\DriverActivityReportSchema`.
 *
 * Both `*_seconds` (aggregatable integer) and `*_display` ("H:MM" string)
 * columns are stored because the report engine applies column transformers on
 * the client only (server-side `processResults` is a stub) — the integer column
 * powers SUM across days/fleets while the display column guarantees correct
 * hours:minutes rendering in the report UI/exports.
 *
 * Table name is intentionally UNPREFIXED (see driver_status_log migration note).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('driver_activity_daily', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('_key')->unique();
            $table->uuid('uuid')->unique();
            $table->uuid('company_uuid');

            // Driver (denormalized at aggregation time for fast reporting)
            $table->uuid('driver_uuid');
            // Named driver_publicid (not driver_public_id): the report Table builder
            // auto-hides columns ending in _id/_uuid, which would hide the Driver ID.
            $table->string('driver_publicid', 191)->nullable();
            $table->string('driver_name', 191)->nullable();

            // Single attributed fleet (denormalized; nullable if driver has none)
            $table->uuid('fleet_uuid')->nullable();
            $table->string('fleet_name', 191)->nullable();

            $table->date('activity_date');

            // Metrics
            $table->decimal('distance_km', 10, 3)->default(0);
            $table->unsignedInteger('moving_duration_seconds')->default(0);
            $table->string('moving_duration_display', 16)->default('0:00');
            $table->unsignedInteger('online_duration_seconds')->default(0);
            $table->string('online_duration_display', 16)->default('0:00');

            // Provenance / diagnostics
            $table->unsignedInteger('ping_count')->default(0);
            $table->dateTime('first_ping_at')->nullable();
            $table->dateTime('last_ping_at')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Idempotent re-aggregation (upsert keyed on this unique index)
            $table->unique(['company_uuid', 'driver_uuid', 'activity_date'], 'driver_day_unique');
            $table->index(['company_uuid', 'activity_date']);
            $table->index(['driver_uuid', 'activity_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_activity_daily');
    }
};
