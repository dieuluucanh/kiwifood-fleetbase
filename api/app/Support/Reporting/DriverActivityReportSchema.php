<?php

namespace App\Support\Reporting;

use Fleetbase\Support\Reporting\Contracts\ReportSchema;
use Fleetbase\Support\Reporting\ReportSchemaRegistry;
use Fleetbase\Support\Reporting\Schema\Column;
use Fleetbase\Support\Reporting\Schema\Relationship;
use Fleetbase\Support\Reporting\Schema\Table;

/**
 * Registers the "Driver Activity" data source with the Fleetbase Report Builder.
 *
 * The underlying `driver_activity_daily` table is a materialized per-driver-
 * per-day summary (distance / movement / online duration / fleet) populated by
 * `App\Console\Commands\AggregateDriverActivity`. Because the Report Builder UI
 * discovers data sources dynamically via `reports/tables?extension=fleet-ops`,
 * registering this table here is all that is required for it to surface in the
 * console — no frontend change needed.
 *
 * Column/aggregate argument order follows the vendored `Schema\Column` API:
 * `Column::sum($computedName, $field)`, `Column::count($computedName, $field)`.
 *
 * Both `*_seconds` (aggregatable integer) and `*_display` ("H:MM" string)
 * columns are exposed: the report engine applies column transformers on the
 * client only (server-side `processResults` is a stub), so the pre-formatted
 * display column is what guarantees correct hours:minutes rendering, while the
 * integer column powers SUM/AVG across days/fleets.
 */
class DriverActivityReportSchema implements ReportSchema
{
    public function registerReportSchema(ReportSchemaRegistry $registry): void
    {
        $registry->registerTable(
            Table::make('driver_activity_daily')
                ->label('Driver Activity')
                ->description('Per-driver daily distance moved, movement duration, online duration, and attributed fleet.')
                ->category('Operations')
                ->extension('fleet-ops')
                ->excludeColumns(['id', '_key', 'uuid', 'company_uuid', 'driver_uuid', 'fleet_uuid', 'meta', 'deleted_at'])
                ->maxRows(50000)
                ->cacheTtl(3600)
                ->columns([
                    // Named "driver_publicid" (not "driver_public_id") because the
                    // Report Builder's Table builder auto-hides columns ending in
                    // "_id"/"_uuid" as foreign keys — this would hide the Driver ID.
                    Column::make('driver_publicid', 'string')
                        ->label('Driver ID')
                        ->description('Driver public identifier')
                        ->searchable()
                        ->filterable()
                        ->sortable(),

                    Column::make('driver_name', 'string')
                        ->label('Driver Name')
                        ->description('Driver display name (denormalized at aggregation time)')
                        ->searchable()
                        ->filterable()
                        ->sortable(),

                    Column::make('activity_date', 'date')
                        ->label('Date')
                        ->description('The calendar day this activity row covers')
                        ->filterable()
                        ->sortable()
                        ->aggregatable(),

                    Column::make('distance_km', 'decimal')
                        ->label('Distance (km)')
                        ->description('Total distance moved during the day, in kilometers')
                        ->filterable()
                        ->sortable()
                        ->aggregatable(),

                    Column::make('moving_duration_seconds', 'integer')
                        ->label('Movement (seconds)')
                        ->description('Time the driver was moving (seconds) — aggregatable backing field')
                        ->sortable()
                        ->aggregatable(),

                    Column::make('moving_duration_display', 'string')
                        ->label('Movement Duration')
                        ->description('Time the driver was moving, formatted as H:MM')
                        ->sortable(),

                    Column::make('online_duration_seconds', 'integer')
                        ->label('Online (seconds)')
                        ->description('Time the driver was online (seconds) — aggregatable backing field')
                        ->sortable()
                        ->aggregatable(),

                    Column::make('online_duration_display', 'string')
                        ->label('Online Duration')
                        ->description('Time the driver was online, formatted as H:MM')
                        ->sortable(),

                    Column::make('fleet_name', 'string')
                        ->label('Fleet')
                        ->description('Attributed fleet name (single fleet per driver-day)')
                        ->filterable()
                        ->sortable(),
                ])
                ->computedColumns([
                    Column::sum('total_distance', 'distance_km')->label('Total Distance (km)'),
                    Column::sum('total_moving_seconds', 'moving_duration_seconds')->label('Total Movement (seconds)'),
                    Column::sum('total_online_seconds', 'online_duration_seconds')->label('Total Online (seconds)'),
                    Column::avg('avg_distance', 'distance_km')->label('Avg Distance (km)'),
                    Column::count('day_count', 'id')->label('Days'),
                ])
                ->relationships([
                    Relationship::hasAutoJoin('driver', 'drivers')
                        ->label('Driver')
                        ->columns([
                            Column::make('public_id', 'string')->label('Driver Public ID'),
                            Column::make('online', 'boolean')->label('Driver Online'),
                            Column::make('status', 'string')->label('Driver Status'),
                        ]),

                    Relationship::hasAutoJoin('fleet', 'fleets')
                        ->label('Fleet')
                        ->columns([
                            Column::make('public_id', 'string')->label('Fleet Public ID'),
                            Column::make('name', 'string')->label('Fleet Name'),
                            Column::make('status', 'string')->label('Fleet Status'),
                        ]),
                ])
        );
    }
}
