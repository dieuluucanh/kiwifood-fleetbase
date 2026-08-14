# Kiwifood server patches (bind-mounted over the published Docker image)

These files are **exact copies of the deployed package sources** with minimal
bug-fix patches applied. They are mounted into the containers via
`docker-compose.override.yml`, overlaying files inside
`/fleetbase/api/vendor/...` — no image rebuild or Packagist fork needed.

## What each patch fixes

| File | Fix |
|---|---|
| `fleetops-api/.../Events/DriverLocationChanged.php` | **R2a** — `broadcastOn()` built the company channel from `session('company')`, which is empty on the queue worker → events were published to a dead `company.` channel and the console fleet map (subscribed to `company.{uuid}`) never received realtime location deltas. Now captured from `$driver->company_uuid` in the constructor. |
| `fleetops-api/.../Events/VehicleLocationChanged.php` | Same fix for vehicles. |
| `fleetops-api/.../Controllers/Api/v1/DriverController.php` | **R3** — `track()` and `toggleOnline()` use `updateQuietly()`, which suppresses cache-invalidation events; console showed stale positions/online status for minutes. Now explicitly invalidates `LiveCacheService` (+ API model cache on toggle) and broadcasts the online change in realtime. **R4** — `toggleOnline()` also inserts a `driver_status_log` row on each real online↔offline transition (the `updateQuietly()` call blocks model observers, so the transition can't be captured any other way). That log feeds the per-day "online duration" column of the Driver Activity report (`App\Console\Commands\AggregateDriverActivity`). |
| `core-api/.../SocketCluster/SocketClusterService.php` | **R2b** — `send()` did handshake → publish → **close** for every channel (4 fresh websocket connections per broadcast event), saturating the single queue worker at peak. Now reuses one persistent connection and self-heals on failure. |

## Version pinning — read before `docker compose pull`

These copies were made from **fleetops-api 0.6.58** and **core-api 1.6.54**
(the versions pinned in `api/composer.lock`, which the published image
installs). Verify what the running image actually contains:

```bash
docker compose exec application composer show fleetbase/fleetops-api fleetbase/core-api
```

If you later upgrade the image (`docker compose pull` a newer `fleetbase/fleetbase-api`)
the mounted files may no longer match the package inside → **remove these mounts
or re-create the patches from the new versions**. (Once upstream fixes land —
see "Upstream" below — the mounts can simply be deleted.)

## Applying / reverting

```bash
# apply (after merging docker-compose.override.yml)
docker compose up -d --scale queue=3
docker compose restart application queue scheduler   # Octane/queue workers must reload code

# revert = remove the patch volume lines from docker-compose.override.yml, then same restart
```

Octane (FrankenPHP) and queue workers keep code in memory — a container
**restart is required** after changing any of these files.

## Upstream

These should be submitted as PRs to `fleetbase/fleetops` and
`fleetbase/core-api` so future `:latest` images include the fixes and the
bind-mounts can be dropped.
