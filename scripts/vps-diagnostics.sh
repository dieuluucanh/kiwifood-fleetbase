#!/usr/bin/env bash
# ============================================================================
# Kiwifood Fleetbase VPS diagnostics — run ON THE VPS from the repo directory.
# Collects before/after evidence for the driver-tracking fixes.
# Usage: bash scripts/vps-diagnostics.sh
# ============================================================================
set -u

echo "======================================================================"
echo "1) CONTAINER RESOURCE USAGE (look for socket/queue/mysql CPU saturation)"
echo "======================================================================"
docker stats --no-stream --format "table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}"

echo
echo "======================================================================"
echo "2) QUEUE BACKLOG — broadcast jobs waiting (sampled 6x, 5s apart)"
echo "    Healthy: ~0. Growing during working hours = worker saturated."
echo "======================================================================"
for i in 1 2 3 4 5 6; do
  printf "queues:default length = %s\n" "$(docker compose exec -T cache redis-cli LLEN queues:default 2>/dev/null | tr -d '\r')"
  [ "$i" -lt 6 ] && sleep 5
done

echo
echo "======================================================================"
echo "3) FAILED JOBS (should be empty)"
echo "======================================================================"
docker compose exec -T application php artisan queue:failed 2>/dev/null | head -20

echo
echo "======================================================================"
echo "4) TRACK PING GAPS per driver, last 60 min"
echo "    Long gaps while a driver is on shift = app killed in background (R1)."
echo "======================================================================"
docker compose logs --since 60m application 2>/dev/null \
  | grep -oE 'drivers/[^/]+/track' \
  | sort | uniq -c | sort -rn | head -30
echo "(count of track pings per driver id in the last hour)"

echo
echo "======================================================================"
echo "5) HTTP ERRORS on track / toggle-online, last 60 min"
echo "    429 = throttled (raise THROTTLE_REQUESTS_PER_MINUTE)"
echo "    5xx/timeouts = server overload"
echo "======================================================================"
docker compose logs --since 60m httpd 2>/dev/null \
  | grep -E 'track|toggle-online' \
  | grep -oE '" (429|5[0-9][0-9]|4[0-9][0-9]) ' | sort | uniq -c

echo
echo "======================================================================"
echo "6) DEPLOYED PACKAGE VERSIONS (patches in ./patches match 0.6.58 / 1.6.54)"
echo "======================================================================"
docker compose exec -T application composer show fleetbase/fleetops-api fleetbase/core-api 2>/dev/null | grep -E "^name|^versions"

echo
echo "======================================================================"
echo "7) REDIS MEMORY (queue+cache pressure)"
echo "======================================================================"
docker compose exec -T cache redis-cli INFO memory 2>/dev/null | grep -E "used_memory_human|maxmemory" | tr -d '\r'

echo
echo "======================================================================"
echo "8) SOCKETCLUSTER PROCESSES (10 workers + 10 brokers before the fix; 2+2 after)"
echo "======================================================================"
docker compose top socket 2>/dev/null | grep -c node

echo
echo "Done. Manual checks:"
echo "  - Console Chrome DevTools > Network > WS > socketcluster frames: watch for"
echo "    'driver.location_changed' on channel 'company.{your-company-uuid}' while a"
echo "    driver moves. Before R2a fix: absent (events go to broken 'company.' channel)."
echo "  - Realtime curl test (needs a driver token from the DB):"
echo "    curl -X POST https://<api-host>/int/v1/drivers/<driver_id>/track \\"
echo "      -H 'Authorization: Bearer <token>' -H 'Content-Type: application/json' \\"
echo "      -d '{\"latitude\":10.7769,\"longitude\":106.7009}'"
echo "    then watch the console marker move within ~5s WITHOUT opening the app."
