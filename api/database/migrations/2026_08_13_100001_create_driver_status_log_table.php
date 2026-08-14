<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only log of driver online/offline transitions.
 *
 * `fleetbase_drivers.online` is a single boolean overwritten in place and
 * `DriverController::toggleOnline()` uses `updateQuietly()` (which suppresses
 * Eloquent events), so there is no history to derive "online duration" from.
 * This table is written by the patched `toggleOnline()` on each transition and
 * is consumed by `driver-activity:aggregate` to compute online duration per
 * driver per day.
 *
 * NOTE: table name is intentionally UNPREFIXED. The Fleetbase connection carries
 * any configured table prefix automatically, so unprefixed names here match the
 * convention used by the vendored report schema (`Table::make('orders')`, etc.).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('driver_status_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('_key')->unique();
            $table->uuid('uuid')->unique();
            $table->uuid('company_uuid')->index();
            $table->uuid('driver_uuid')->index();
            $table->boolean('online')->default(false);
            $table->dateTime('occurred_at')->index();
            $table->string('source', 64)->nullable();
            $table->timestamps();

            $table->index(['driver_uuid', 'occurred_at']);
            $table->index(['company_uuid', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_status_log');
    }
};
