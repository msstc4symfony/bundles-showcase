#!/bin/sh
# Writes the SLO history (app:slo:backfill) as TSDB blocks into Prometheus' data volume before Prometheus starts.
# Only into an empty TSDB: a second run with a new "now" would overlap the first history and read as counter resets.
set -eu

tsdb=/prometheus
staging="$tsdb/.backfill"
if [ -e "$staging" ]; then
    echo "prometheus-backfill: half-written backfill in $staging, run make down" >&2
    exit 1
fi
if [ -n "$(ls -A "$tsdb")" ]; then
    echo "prometheus-backfill: skipped, $tsdb is not empty (make down drops it)"
    exit 0
fi

# Staged on the volume itself, so each block lands with a rename; the staging directory goes last.
mkdir "$staging"
php bin/console app:slo:backfill > "$staging/history.om"
# promtool reads the whole input once per block: 24h blocks took 23 s, one block per 61d under 1 s.
promtool tsdb create-blocks-from openmetrics --max-block-duration=1464h "$staging/history.om" "$staging/blocks"
for block in "$staging"/blocks/*; do
    mv "$block" "$tsdb"/
done
rm -rf "$staging"
chown -R 65534:65534 "$tsdb"
echo "prometheus-backfill: wrote $(ls "$tsdb" | wc -l) blocks"
