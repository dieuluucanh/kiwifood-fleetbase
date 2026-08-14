<?php

namespace App\Console\Commands;

use App\Support\Geo;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Materialize the `driver_activity_daily` report table.
 *
 * For each target day and each driver that has GPS pings (or an online/offline
 * transition) that day, computes:
 *   - distance_km          : sum of haversine distance between consecutive pings
 *   - moving_duration_*    : time between pings classified as moving (speed > 5 km/h
 *                            or segment > 50 m), each segment capped at 5 minutes
 *   - online_duration_*    : online time within the day from `driver_status_log`
 *   - fleet (single)       : most-recently-assigned fleet (one fleet per driver-day)
 *
 * Re-running a date is idempotent (rows are keyed by company+driver+date).
 *
 * Usage:
 *   php artisan driver-activity:aggregate                 # yesterday
 *   php artisan driver-activity:aggregate --date=today
 *   php artisan driver-activity:aggregate --date=2026-08-10
 *   php artisan driver-activity:aggregate --from=2026-08-01 --to=2026-08-12
 *   php artisan driver-activity:aggregate --driver=<uuid> --from=... --to=...
 */
class AggregateDriverActivity extends Command
{
    protected $signature = 'driver-activity:aggregate
        {--date= : A single date to aggregate (today|yesterday|YYYY-MM-DD)}
        {--from= : Start date for a range (YYYY-MM-DD)}
        {--to= : End date for a range (inclusive, YYYY-MM-DD)}
        {--driver= : Restrict to a single driver uuid}
        {--morph= : Override the positions subject_type morph string}';

    protected $description = 'Aggregate per-driver daily activity (distance, movement, online duration, fleet) into driver_activity_daily.';

    /** positions.subject_type for driver points (Fleetbase Driver morph class). */
    private const DEFAULT_DRIVER_MORPH = 'Fleetbase\\FleetOps\\Models\\Driver';

    /** A segment with a time gap larger than this is treated as a device/idle break (no distance). */
    private const SEGMENT_GAP_BREAK_SECONDS = 3600;

    /** Speed (km/h) at/above which a segment counts as "moving". */
    private const MOVING_SPEED_THRESHOLD_KMH = 5.0;

    /** A segment shorter than this distance (km) only counts as moving if speed is high. */
    private const MIN_MOVING_DISTANCE_KM = 0.05;

    /** Cap a single moving segment at this many seconds (avoid over-counting long gaps). */
    private const MAX_MOVING_SEGMENT_SECONDS = 300;

    public function handle(): int
    {
        if (!Schema::hasTable('positions') || !Schema::hasTable('driver_activity_daily')) {
            $this->error('Required tables are missing. Run `php artisan migrate` first.');

            return self::FAILURE;
        }

        $dates    = $this->resolveDates();
        $morph    = $this->option('morph') ?: self::DEFAULT_DRIVER_MORPH;
        $driver   = $this->option('driver');

        if ($dates->isEmpty()) {
            $this->error('No dates to aggregate. Use --date or --from/--to.');

            return self::FAILURE;
        }

        $this->info(sprintf('Aggregating driver activity for %d day(s) using morph [%s].', $dates->count(), $morph));

        $totalRows = 0;
        $errors = 0;

        foreach ($dates as $date) {
            try {
                $rows = $this->aggregateDate($date, $morph, $driver);
                $totalRows += $rows;
                $this->info(sprintf('  %s : %d driver-day row(s) written', $date->toDateString(), $rows));
            } catch (\Throwable $e) {
                $errors++;
                $this->error(sprintf('  %s : FAILED — %s', $date->toDateString(), $e->getMessage()));
            }
        }

        $this->info(sprintf('Done. %d row(s) written, %d error(s).', $totalRows, $errors));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Build the list of dates to process from the options.
     *
     * @return \Illuminate\Support\Collection<int, Carbon>
     */
    private function resolveDates()
    {
        $from = $this->option('from');
        $to   = $this->option('to');
        $date = $this->option('date');

        if ($from && $to) {
            return $this->dateRange(Carbon::parse($from), Carbon::parse($to));
        }

        if ($from || $to) {
            $single = Carbon::parse($from ?: $to);
            return collect([$single]);
        }

        if ($date) {
            return collect([$this->parseNamedDate($date)]);
        }

        // default: yesterday
        return collect([Carbon::yesterday()]);
    }

    private function parseNamedDate(string $date): Carbon
    {
        if ($date === 'today') {
            return Carbon::today();
        }
        if ($date === 'yesterday') {
            return Carbon::yesterday();
        }

        return Carbon::parse($date);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Carbon>
     */
    private function dateRange(Carbon $from, Carbon $to)
    {
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $dates = collect();
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $dates->push($d->copy());
        }

        return $dates;
    }

    /**
     * Aggregate a single date. Returns the number of driver-day rows written.
     */
    private function aggregateDate(Carbon $date, string $morph, ?string $driverFilter): int
    {
        $dayStart = $date->copy()->startOfDay();
        $dayEnd   = $date->copy()->endOfDay();
        $dayNext  = $date->copy()->addDay()->startOfDay();
        $isToday  = $date->isToday();

        $drivers = $this->collectDriversForDay($dayStart, $dayNext, $morph, $driverFilter);

        if ($drivers->isEmpty()) {
            return 0;
        }

        $written = 0;
        foreach ($drivers as $d) {
            try {
                $written += (int) $this->aggregateDriverDay(
                    $d->company_uuid,
                    $d->driver_uuid,
                    $date,
                    $dayStart,
                    $dayEnd,
                    $isToday,
                    $morph
                );
            } catch (\Throwable $e) {
                $this->error(sprintf('    driver %s on %s : %s', $d->driver_uuid, $date->toDateString(), $e->getMessage()));
            }
        }

        return $written;
    }

    /**
     * Distinct (company_uuid, driver_uuid) pairs that have positions or status
     * transitions within the day. Optionally filtered to a single driver.
     */
    private function collectDriversForDay(Carbon $dayStart, Carbon $dayNext, string $morph, ?string $driverFilter)
    {
        $fromPositions = DB::table('positions')
            ->selectRaw('DISTINCT subject_uuid AS driver_uuid, company_uuid')
            ->where('subject_type', $morph)
            ->where('created_at', '>=', $dayStart)
            ->where('created_at', '<', $dayNext)
            ->whereNull('deleted_at')
            ->when($driverFilter, fn ($q) => $q->where('subject_uuid', $driverFilter))
            ->get();

        $fromStatus = DB::table('driver_status_log')
            ->selectRaw('DISTINCT driver_uuid, company_uuid')
            ->where('occurred_at', '>=', $dayStart)
            ->where('occurred_at', '<', $dayNext)
            ->when($driverFilter, fn ($q) => $q->where('driver_uuid', $driverFilter))
            ->get();

        return $fromPositions
            ->merge($fromStatus)
            ->unique(fn ($d) => $d->company_uuid . '|' . $d->driver_uuid)
            ->values();
    }

    /**
     * Aggregate one driver-day and upsert the row. Returns 1 if a row was written.
     */
    private function aggregateDriverDay(
        string $companyUuid,
        string $driverUuid,
        Carbon $date,
        Carbon $dayStart,
        Carbon $dayEnd,
        bool $isToday,
        string $morph
    ): int {
        // --- pings ---
        $pings = DB::table('positions')
            ->selectRaw('ST_X(coordinates) AS lng, ST_Y(coordinates) AS lat, speed, created_at')
            ->where('subject_uuid', $driverUuid)
            ->where('company_uuid', $companyUuid)
            ->where('subject_type', $morph)
            ->where('created_at', '>=', $dayStart)
            ->where('created_at', '<', $dayEnd->copy()->addSecond()) // inclusive of last second of day
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'asc')
            ->get();

        [$distanceKm, $movingSeconds, $firstPingAt, $lastPingAt] = $this->computeDistanceAndMovement($pings);

        // --- online duration from status log ---
        $onlineSeconds = $this->computeOnlineDuration($driverUuid, $dayStart, $dayEnd, $isToday);

        // --- driver identity + fleet attribution ---
        [$driverPublicId, $driverName] = $this->resolveDriverIdentity($driverUuid);
        [$fleetUuid, $fleetName] = $this->resolveFleet($driverUuid);

        return $this->upsertRow(
            $companyUuid,
            $driverUuid,
            $date,
            $driverPublicId,
            $driverName,
            $fleetUuid,
            $fleetName,
            $distanceKm,
            $movingSeconds,
            $onlineSeconds,
            $pings->count(),
            $firstPingAt,
            $lastPingAt
        );
    }

    /**
     * @return array{0:float,1:int,2:?string,3:?string} [distance_km, moving_seconds, first_ping_at, last_ping_at]
     */
    private function computeDistanceAndMovement($pings): array
    {
        $distanceKm   = 0.0;
        $movingSeconds = 0;
        $firstPingAt   = null;
        $lastPingAt    = null;

        $prev = null;

        foreach ($pings as $p) {
            $lat = $p->lat !== null ? (float) $p->lat : null;
            $lng = $p->lng !== null ? (float) $p->lng : null;
            $t   = $p->created_at ? Carbon::parse($p->created_at) : null;

            if ($t !== null) {
                $firstPingAt = $firstPingAt ?? $t->toDateTimeString();
                $lastPingAt  = $t->toDateTimeString();
            }

            // No GPS fix → skip, keep last good prev to bridge the gap.
            if ($lat === null || $lng === null || ($lat == 0.0 && $lng == 0.0)) {
                continue;
            }

            if ($prev !== null && $t !== null) {
                $gap = (int) $t->diffInSeconds($prev['t']);
                if ($gap > 0 && $gap <= self::SEGMENT_GAP_BREAK_SECONDS) {
                    $segKm = Geo::haversineKm($prev['lat'], $prev['lng'], $lat, $lng);
                    $distanceKm += $segKm;

                    $speed   = (float) ($p->speed ?? 0);
                    $moving  = ($speed > self::MOVING_SPEED_THRESHOLD_KMH) || ($segKm > self::MIN_MOVING_DISTANCE_KM);
                    if ($moving) {
                        $movingSeconds += min($gap, self::MAX_MOVING_SEGMENT_SECONDS);
                    }
                }
            }

            $prev = ['lat' => $lat, 'lng' => $lng, 't' => $t];
        }

        return [$distanceKm, $movingSeconds, $firstPingAt, $lastPingAt];
    }

    /**
     * Online time (seconds) within [dayStart, dayEnd] for a driver, derived from
     * driver_status_log transitions. Returns 0 if there is no log history.
     */
    private function computeOnlineDuration(string $driverUuid, Carbon $dayStart, Carbon $dayEnd, bool $isToday): int
    {
        $events = DB::table('driver_status_log')
            ->select(['online', 'occurred_at'])
            ->where('driver_uuid', $driverUuid)
            ->where('occurred_at', '<=', $dayEnd)
            ->orderBy('occurred_at', 'asc')
            ->get();

        if ($events->isEmpty()) {
            return 0;
        }

        $onlineSeconds = 0;
        $state         = null;     // null = unknown, true = online, false = offline
        $stateSince    = null;     // Carbon when the current state became effective

        foreach ($events as $ev) {
            $t        = Carbon::parse($ev->occurred_at);
            $newState = (bool) $ev->online;

            if ($state === true && $stateSince !== null) {
                $start = $stateSince->copy()->max($dayStart);
                $end   = $t->copy()->min($dayEnd);
                if ($end->gt($start)) {
                    $onlineSeconds += (int) $end->diffInSeconds($start);
                }
            }

            $state      = $newState;
            $stateSince = $t;
        }

        // If still online at the end of the considered events, count to day end (or now).
        if ($state === true && $stateSince !== null) {
            $start = $stateSince->copy()->max($dayStart);
            $end   = $isToday ? min(Carbon::now(), $dayEnd) : $dayEnd;
            if ($end->gt($start)) {
                $onlineSeconds += (int) $end->diffInSeconds($start);
            }
        }

        return (int) max(0, $onlineSeconds);
    }

    /**
     * @return array{0:?string,1:?string} [public_id, name]
     */
    private function resolveDriverIdentity(string $driverUuid): array
    {
        $driver = DB::table('drivers')
            ->where('uuid', $driverUuid)
            ->first(['public_id', 'user_uuid']);

        if (!$driver) {
            return [null, null];
        }

        $name = null;
        if ($driver->user_uuid) {
            $name = DB::table('users')->where('uuid', $driver->user_uuid)->value('name');
        }

        return [$driver->public_id, $name];
    }

    /**
     * @return array{0:?string,1:?string} [fleet_uuid, fleet_name]
     */
    private function resolveFleet(string $driverUuid): array
    {
        $assignment = DB::table('fleet_drivers')
            ->where('driver_uuid', $driverUuid)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->first(['fleet_uuid']);

        if (!$assignment || !$assignment->fleet_uuid) {
            return [null, null];
        }

        $fleetName = DB::table('fleets')->where('uuid', $assignment->fleet_uuid)->value('name');

        return [$assignment->fleet_uuid, $fleetName];
    }

    private function upsertRow(
        string $companyUuid,
        string $driverUuid,
        Carbon $date,
        ?string $driverPublicId,
        ?string $driverName,
        ?string $fleetUuid,
        ?string $fleetName,
        float $distanceKm,
        int $movingSeconds,
        int $onlineSeconds,
        int $pingCount,
        ?string $firstPingAt,
        ?string $lastPingAt
    ): int {
        $values = [
            'driver_publicid'           => $driverPublicId,
            'driver_name'               => $driverName,
            'fleet_uuid'               => $fleetUuid,
            'fleet_name'               => $fleetName,
            'distance_km'              => round($distanceKm, 3),
            'moving_duration_seconds'  => $movingSeconds,
            'moving_duration_display' => Geo::formatHoursMinutes($movingSeconds),
            'online_duration_seconds'  => $onlineSeconds,
            'online_duration_display' => Geo::formatHoursMinutes($onlineSeconds),
            'ping_count'               => $pingCount,
            'first_ping_at'            => $firstPingAt,
            'last_ping_at'             => $lastPingAt,
            'meta'                     => json_encode(['source' => 'driver_status_log']),
            'updated_at'               => now(),
        ];

        $existing = DB::table('driver_activity_daily')
            ->where('company_uuid', $companyUuid)
            ->where('driver_uuid', $driverUuid)
            ->where('activity_date', $date->toDateString())
            ->first(['id']);

        if ($existing) {
            DB::table('driver_activity_daily')->where('id', $existing->id)->update($values);

            return 1;
        }

        $values['company_uuid']  = $companyUuid;
        $values['driver_uuid']    = $driverUuid;
        $values['activity_date']  = $date->toDateString();
        $values['uuid']           = Str::uuid()->toString();
        $values['_key']           = Str::lower(Str::random(16));
        $values['created_at']     = now();

        DB::table('driver_activity_daily')->insert($values);

        return 1;
    }
}
