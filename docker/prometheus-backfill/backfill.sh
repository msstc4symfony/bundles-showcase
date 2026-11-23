#!/bin/sh
# Writes the SLO history (app:slo:backfill) as TSDB blocks into Prometheus' data volume before Prometheus starts.
# Only into an empty TSDB: a second run with a new "now" would overlap the first history and read as counter resets.
set -eu

tsdb=/prometheus
if [ -n "$(ls -A "$tsdb")" ]; then
    echo "prometheus-backfill: skipped, $tsdb is not empty (make down drops it)"
    exit 0
fi

work=$(mktemp -d)
php bin/console app:slo:backfill > "$work/history.om"
# promtool reads the whole input once per block: 24h blocks took 23 s, one block per 61d under 1 s.
promtool tsdb create-blocks-from openmetrics --max-block-duration=1464h "$work/history.om" "$work/blocks"
# Moved only once complete, so a failed run leaves the TSDB empty and the next make up retries.
mv "$work/blocks"/* "$tsdb"/
chown -R 65534:65534 "$tsdb"
rm -rf "$work"
echo "prometheus-backfill: wrote $(ls "$tsdb" | wc -l) blocks"
