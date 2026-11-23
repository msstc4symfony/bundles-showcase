#!/bin/sh
# Writes the SLO history as TSDB blocks into Prometheus' data volume before Prometheus starts: the raw counters
# (app:slo:backfill), then what that objective's recording rules record over them (app:slo:backfill-recorded).
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
promtool tsdb create-blocks-from openmetrics --max-block-duration=1464h "$staging/history.om" "$staging/tsdb"

# The recorded history spans the raw one, so it ends before the real Prometheus records its first live sample.
start=$(sed -n 2p "$staging/history.om" | awk '{print $NF}')
end=$(tail -n 2 "$staging/history.om" | head -n 1 | awk '{print $NF}')

# promtool tsdb create-blocks-from rules writes a block per rule and per 20-day window at a 5m step (about 1 s
# each), so the rules run as range queries against a throwaway Prometheus on the staged blocks instead. It loads
# new blocks only once a minute, so it restarts for each pass that reads the records of the one before.
printf 'global: {}\n' > "$staging/prometheus.yml"
passes=$(php bin/console app:slo:backfill-recorded --passes)
pass=1
while [ "$pass" -le "$passes" ]; do
    prometheus --config.file="$staging/prometheus.yml" --storage.tsdb.path="$staging/tsdb" \
        --storage.tsdb.retention.time=61d --web.listen-address=127.0.0.1:9091 --log.level=warn &
    pid=$!
    tries=0
    until curl -fs -o /dev/null http://127.0.0.1:9091/-/ready; do
        tries=$((tries + 1))
        if [ "$tries" -gt 100 ] || ! kill -0 "$pid" 2> /dev/null; then
            echo "prometheus-backfill: the throwaway Prometheus did not become ready" >&2
            exit 1
        fi
        sleep 0.2
    done
    php bin/console app:slo:backfill-recorded --pass="$pass" --prometheus-url=http://127.0.0.1:9091 \
        --start="$start" --end="$end" > "$staging/recorded-$pass.om"
    kill "$pid"
    wait "$pid"
    promtool tsdb create-blocks-from openmetrics --max-block-duration=1464h "$staging/recorded-$pass.om" "$staging/tsdb"
    pass=$((pass + 1))
done

# Blocks only: the throwaway Prometheus' WAL and head chunks stay behind.
for meta in "$staging"/tsdb/*/meta.json; do
    mv "${meta%/meta.json}" "$tsdb"/
done
rm -rf "$staging"
chown -R 65534:65534 "$tsdb"
echo "prometheus-backfill: wrote $(ls "$tsdb" | wc -l) blocks, raw and $passes passes of recorded series"
