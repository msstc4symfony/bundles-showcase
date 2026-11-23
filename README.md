# msstc4symfony bundles showcase

Three small Symfony 8 services that run every [msstc4symfony](https://github.com/msstc4symfony) bundle
together, the way a production system would. The repository serves three purposes:

- **Integration stand.** Each service uses the bundles next to a different third-party stack:
  HTTP client only, Doctrine with Messenger, or mostly Messenger.
- **Showcase.** Run `make up` and `make demo-traffic`, then follow one request through logs,
  metrics and spans in Grafana.
- **Release gate.** A new bundle tag reaches this repository through a Dependabot PR. Behat e2e
  has to be green before the PR is merged.

```text
client ──HTTP──▶ gateway ──HTTP──▶ orders ──AMQP: ProcessPayment──▶ billing
                                     ▲                                 │
                                     └──── AMQP: PaymentProcessed ─────┘
```

| Service | What it does | Processes |
|---|---|---|
| `gateway` | Public `POST /orders` and `GET /orders/{id}`. Proxies to orders and makes `POST` idempotent through the `Idempotency-Key` header, using a Redis cache and a Redis lock. | RoadRunner |
| `orders` | Stores the order as `pending`, then sends `ProcessPayment` and indexes the order in Elasticsearch through FOSElasticaBundle. Applies `PaymentProcessed` and moves the order to `paid` or `declined`. | RoadRunner + `messenger:consume payment_processed`; one-shots `orders-migrate` (schema) and `orders-search-index` (Elasticsearch index) before start |
| `billing` | Records the payment. Amounts above `BILLING_DECLINE_ABOVE` (100000) are declined. | RoadRunner (service routes) + `messenger:consume process_payment` |

Infrastructure: PostgreSQL 18, Redis 7, RabbitMQ 4, Elasticsearch 9 (single node, used by orders). Observability: Prometheus, Alertmanager, Loki, Tempo, Alloy and Grafana.

## Run it

```bash
make up            # build and start everything, wait until healthy
make demo-traffic  # ~60 orders over a minute; prints a trace id to follow
make e2e           # Behat scenarios; @chaos (stops Redis / orders / Elasticsearch) runs last
make down          # stop and drop volumes
```

| What | URL |
|---|---|
| Gateway API | http://localhost:8080/orders |
| Grafana (anonymous admin) | http://localhost:3000, dashboards "Showcase — services" and "Showcase — profiling spans"; 18 generated SLO dashboards in the folder "SLO" |
| Prometheus | http://localhost:9090 |
| Alertmanager | http://localhost:9093 (SLO alerts go to the webhook recorder `alert-recorder`, see [SLO alerts](#slo-alerts)) |
| Tempo API | http://localhost:3200 (traces at `/api/v2/traces/<trace id>`; browse them in Grafana → Explore → Tempo) |
| RabbitMQ management | http://localhost:15672 (guest / guest) |

All ports bind to `127.0.0.1` only. When a port is already taken, override it:
`SHOWCASE_GATEWAY_PORT`, `SHOWCASE_GRAFANA_PORT`, `SHOWCASE_PROMETHEUS_PORT`, `SHOWCASE_ALERTMANAGER_PORT`, `SHOWCASE_TEMPO_PORT`,
`SHOWCASE_RABBITMQ_PORT`, `SHOWCASE_POSTGRES_PORT`.

## Follow one request

```bash
curl -si -XPOST localhost:8080/orders -H 'Content-Type: application/json' \
     -H 'traceparent: 00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01' -d '{"amount":5000}'
```

Use a fresh trace id for each try (`openssl rand -hex 16`), or the Loki query below mixes the runs. The response
carries `traceresponse` with the same trace id (without `traceparent` the gateway starts a trace and
returns it there). In Grafana, open Explore → Loki and run:

```logql
{service=~".+"} | json | trace_id="4bf92f3577b34da6a3ce929d0e0e4736"
```

The same trace id shows up in four processes: `gateway`, `orders`, `billing-worker` and `orders-worker`. In each
of them, `extra_caller` names the caller (`showcase:gateway`, `showcase:orders`, `showcase:billing`, sent in W3C
`baggage`), and `parent_span_id` is a `span_id` logged by that caller.

The gateway sends its baggage to orders only (`msstc4symfony_tracing.baggage.allowed_hosts`, the host of
`ORDERS_URL`); other hosts get `traceparent` and `tracestate` alone. The e2e stand checks it with
`baggage-echo`, a host off the list: the gateway calls it directly and through a 307 from orders
(`GET /_probe/baggage` and `/_probe/redirect`, both on only when `BAGGAGE_ECHO_URL` is set), and the echo
logs that neither call carried baggage.

`gateway-sampled` is the gateway with `msstc4symfony_tracing.sampling.ratio` 0.5 (`TRACING_SAMPLING_RATIO`; the
gateway itself keeps 1). Each new trace's id decides whether it is sampled: its `traceparent` flags are `03` or `02`, a
sampled one sends `tracestate: ot=th:8` (the OpenTelemetry threshold of 0.5), and only sampled traces reach Tempo. The
e2e stand works out each decision from the trace id and checks the flags, the echo's headers and Tempo against it.

The spans of that trace are in Tempo too: every service exports them through
[tracing-bridge-otlp](https://github.com/msstc4symfony/tracing-bridge-otlp) to Alloy's OTLP receiver
(`OTEL_EXPORTER_OTLP_ENDPOINT=http://alloy:4318`), which forwards them to Tempo. In Loki the `trace_id` of a log line
is a link (derived field) that opens the trace in Tempo; from a span, "Logs for this span" goes back to Loki. The span
ids are the `span_id` values of the logs; an outgoing HTTP call's CLIENT span is the `trace_span_id` of its profiling
record, and the called service's request span is its child (tracing-bundle 2.4 sends that id in `traceparent`).

The logs also go over OTLP (`logs.enabled` of tracing-bridge-otlp, Monolog handler `otlp`): Alloy forwards them to
Loki's OTLP endpoint, where they carry the label `service_name`, no `service` label, and `trace_id` / `span_id` as
structured metadata, next to the stderr lines Alloy ships with the label `service` (e2e `traces.feature`). In Grafana the
`trace_id` of both kinds links to Tempo.

Switching a running stack from the 1.x bundles: drain RabbitMQ first — the tracing stamp class moved in 2.0 and
messages stamped by 1.x cannot be decoded.

## What each bundle contributes

| Bundle | Seen here as |
|---|---|
| [logger-bundle](https://github.com/msstc4symfony/logger-bundle) | One JSON log record per line on stderr; `json_top_level_extra` lifts `trace_id`, `span_id` and `parent_span_id` to the top level of the record, while `caller`, `runtime_id` and the container id stay in `extra` |
| [tracing-bundle](https://github.com/msstc4symfony/tracing-bundle) | W3C `traceparent`/`tracestate`/`baggage` carried across HTTP and RabbitMQ in both directions, `traceresponse` on every response; trace-id-based sampling with the OpenTelemetry threshold in `tracestate` (`gateway-sampled`); a fresh runtime id for every request and message on reused workers (`extra_runtime_id` in Loki) |
| [metrics-bundle](https://github.com/msstc4symfony/metrics-bundle) | `/_/metrics` on every service: routes, statuses, latency, outbound HTTP, Doctrine queries per connection name, Messenger messages sent and handled, Elasticsearch requests of the FOSElasticaBundle client (`symfony_elastica_request_success{method="PUT",path="orders/_doc/<id>"}`), errors; opt-in `cache_request` hits and misses of gateway's `cache.app` and `messenger_queue_depth` of the orders and billing transports |
| [healthcheck-bundle](https://github.com/msstc4symfony/healthcheck-bundle) | `/_/healthcheck/liveliness` (used by the compose healthchecks) and `/_/healthcheck/readiness` (Redis, Postgres, RabbitMQ, lock store, Elasticsearch through `fos_elastica.client.default`; `?_format=json` for JSON) |
| [profiling-bundle](https://github.com/msstc4symfony/profiling-bundle) | A span for every request and consumed message; automatic `sql <type> <table>` spans (orders, billing) and `http <METHOD> <host>` spans (gateway) |
| [metrics-bridge-profiling](https://github.com/msstc4symfony/metrics-bridge-profiling) | Span durations exported as the `symfony_profiling_span_duration_histogram_seconds` metric |
| [metrics-bridge-healthcheck](https://github.com/msstc4symfony/metrics-bridge-healthcheck) | Readiness and liveliness runs as `symfony_healthcheck_probe_run{probe,status}`, `symfony_healthcheck_checker_run{checker,probe,status}` (`degraded` for a failed non-critical checker) and `symfony_healthcheck_checker_duration_histogram_seconds` |
| [tracing-bridge-profiling](https://github.com/msstc4symfony/tracing-bridge-profiling) | Every non-detached profiling span runs in its own trace span; its `profiling` record carries `trace_span_id` and `trace_parent_span_id` in `context` |
| [tracing-bridge-otlp](https://github.com/msstc4symfony/tracing-bridge-otlp) | Every sampled profiling span exported over OTLP with the trace and span ids of the logs, HTTP calls as CLIENT spans that the callee's request span hangs under, plus the logs over OTLP; one export per request or message; a stopped collector costs one `OTLP span export failed` and one `OTLP log export failed` warning per 30 seconds and the OTLP records of that time — the stderr lines still reach Loki (e2e `@chaos`) |
| [logic-bundle](https://github.com/msstc4symfony/logic-bundle) | orders creates an order through an `Action` (`src/Application/Order/Create/`): `Start/Finish application action` log records around it and validation of its `Input`. No lock: the gateway idempotency store already serialises duplicate `POST /orders`, and a non-blocking lock would answer 409 instead of the stored response |
| [slo-bundle](https://github.com/msstc4symfony/slo-bundle) | Four SLOs (see [SLOs](#slos)), the coverage gate of `make check`, `symfony_slo_events_total{slo,objective,revision,result}` on `/_/metrics`, and the generated recording rules, alerts and Grafana dashboards loaded by Prometheus and Grafana |
| [slo-bridge-logic](https://github.com/msstc4symfony/slo-bridge-logic) | orders: `SloMiddleware` in the Action chain measures `orders.create_order` on the create Action |
| [slo-bridge-profiling](https://github.com/msstc4symfony/slo-bridge-profiling) | orders: every measured operation (`orders.search_index`) runs in a profiling span `operation orders.search_index` |

## Elasticsearch in orders

orders uses FOSElasticaBundle 7.2 with Elastica 8. The `orders-search-index` one-shot creates the `orders`
index with its mapping (`app:search:create-index` runs `fos:elastica:create` unless the index exists). After the order is
committed, `POST /orders` indexes it (`PUT orders/_doc/<id>`); a failed indexing call is logged and the order
is still created. The bundles find the FOSElasticaBundle client on their own: metrics-bundle measures its
requests and healthcheck-bundle adds `Elastica connection (fos_elastica.client.default)` to readiness. orders
creates and serves orders without Elasticsearch, so the check is `non_critical`: the `@chaos` scenario stops
Elasticsearch and expects orders to stay ready with a warning, accept orders, and pass the check again after
the restart. The index holds the order as it was created; a document whose indexing failed is not re-indexed.

## SLOs

Each app declares its SLOs with [slo-bundle](https://github.com/msstc4symfony/slo-bundle) (window 30 days):

| SLO | Measured at | Objectives | Strategy (availability, latency) |
|---|---|---|---|
| `gateway.create_order` | route `gateway_create_order` (`POST /orders`) | availability 99.5, latency 200ms / 99 | dedicated, dedicated |
| `billing.process_payment` | `ProcessPaymentHandler` (`ProcessPayment`, billing-worker) | availability 99.9, latency 1s / 99 | existing_series, existing_series |
| `orders.create_order` | the create Action `App\Application\Order\Create\Action` | availability 99.9, latency 300ms / 99 | existing_series, dedicated |
| `orders.search_index` | operation in `ElasticaOrderIndex::add()` (`msstc4symfony_slo.operations`) | availability 99, latency 100ms / 99 | dedicated, dedicated |

Every other route and handler carries `#[IgnoreMonitoring(reason)]`. Orders' `CreateOrderController` is one of them:
the `orders.create_order` Action SLO covers it, while `gateway.create_order` also catches the 422 and 5xx answers that
never reach the Action. The generation selector is
`{application: showcase, components: [<app>]}`, so each app generates its own set into
`apps/<app>/infrastructure/observability/`:

| File | Loaded as |
|---|---|
| `prometheus/slo-recording.rules.yml`, `prometheus/slo-alerts.rules.yml` | Prometheus `rule_files`, mounted at `/etc/prometheus/slo/<app>/` |
| `grafana/dashboards/slo-*.json` (6 per app) | Grafana provider `slo` (`docker/observability/grafana/provisioning/dashboards/dashboards.yaml`), mounted at `/var/lib/grafana/slo/<app>/`, folder "SLO" |
| `grafana/provisioning/dashboards/slo.yaml` | committed, **not mounted** (see below) |
| `alertmanager/slo-alertmanager-routes.yml` | the routes and inhibit rules pasted into `docker/observability/alertmanager.yml` (see [SLO alerts](#slo-alerts)) |
| `alertmanager/slo-alertmanager.yml` | committed, not mounted: the full config with empty receivers, checked by `amtool` |

After changing an SLO or updating slo-bundle, run `make slo-generate` and commit the result. `make check` ends with
`make slo-check`: `slo:validate`, `slo:coverage` and `slo:generate --check` per app (stale files fail it), then
`make promtool`, which runs `promtool check config` on `prometheus.yml` with the same mounts as compose, and so checks
every rule file it lists, then `amtool check-config` on the assembled `alertmanager.yml` and on each app's full `slo-alertmanager.yml`. `promtool` and `amtool` run in Docker; `slo-generate` and `slo-check` also run the host `php` (a local
PHP 8.4 with the app extensions: redis, amqp, intl, pdo_pgsql), unlike the Docker-based targets.

The generated `slo.yaml` points its provider at `/var/lib/grafana/dashboards`, a fixed path that is already the
directory of the showcase provider. Two providers on one directory provision every dashboard twice, so the showcase
declares its own `slo` provider on `/var/lib/grafana/slo` instead; Grafana's file provider reads the per-app
subdirectories. The dashboard uids and titles include the component (`SLO: HTTP Routes (showcase: orders)`), so the three
apps' 18 dashboards do not collide although they share `application="showcase"`.

Prometheus keeps 61 days (`--storage.tsdb.retention.time=61d` in `compose.yaml`): retention must be at least the longest
SLO window plus one hour, and here it also holds the backfilled history. With less, `slo:window:coverage_ratio` never
reaches 1, the SLOs stay in warming up and the ticket alerts never fire.

A fresh stack would need 30 days of uptime before a ticket alert can fire, so the one-shot service `prometheus-backfill`
writes 60 days of raw `orders.search_index` availability counters into the empty TSDB before Prometheus starts:
`app:slo:backfill` of orders prints them as OpenMetrics (2 events per minute, 0.2 % bad, 3 % bad in the last 4 days;
labels read from the generated recording rules, plus `instance="backfill"`), and `promtool tsdb create-blocks-from
openmetrics` turns them into blocks. The SLO is then covered from the start and its `ticket` alert fires. The same
service then backfills what that objective's recording rules record over the history, so its dashboards show the 60
days too: a throwaway Prometheus on the staged blocks evaluates each rule as a range query at a 5-minute step
(`app:slo:backfill-recorded`), and the results become blocks the same way. A rule that reads another rule's record
(`slo:error_budget:remaining` reads `slo:sli:ratio`) runs in a second pass, after a restart of the throwaway
Prometheus has loaded the first pass's blocks. Only that objective's rules are backfilled; the other SLOs' recorded
series begin at the stack's start. A second `make up` leaves a non-empty TSDB untouched (`prometheus-backfill:
skipped`); `make down` drops the volume, and is needed after a revision change of the SLO for the history to carry the
new revision.

The 30-day recording rules are evaluated every 5 minutes (group `slo-<name>-window`), the
short-window rules and the alerts every minute (group `slo-<name>`).

### SLO alerts

Prometheus sends its alerts to Alertmanager (`alertmanager:9093`). The showcase does what an application would do with
slo-bundle's `slo-alertmanager-routes.yml`: paste it into its own config. `docker/observability/alertmanager.base.yml`
holds the showcase part (root receiver `null`, receivers `slo-page` and `slo-ticket` as webhooks to `alert-recorder`,
`group_wait` 5s, `group_interval` 10s, `repeat_interval` 1h); `make slo-generate` runs
`docker/observability/assemble-alertmanager.php`, which appends the fragment's `routes` under `route` and its
`inhibit_rules`, and writes `docker/observability/alertmanager.yml` (committed, do not edit). The three apps share
`application: showcase`, so their fragments are identical and pasted once; the assembler refuses to run when they
differ. `make slo-check` fails when `alertmanager.yml` is stale. Alertmanager reads the file only at start, and
`make up` does not recreate it for a changed file: after `make slo-generate`, run `docker compose restart alertmanager`
(or `curl -XPOST localhost:9093/-/reload`).

`alert-recorder` (`docker/alert-recorder/recorder.php`) stores every webhook notification: `GET /` returns them as a
JSON list, oldest first, each `{"receivedAt", "receiver", "payload"}` with the Alertmanager body as `payload`;
`DELETE /` forgets them (for manual debugging; e2e never resets it). Inside the compose network: `curl http://alert-recorder:8080/`.

`e2e/features/alerting.feature` proves the path end to end: the backfilled slow burn of `orders.search_index` reaches
`slo-ticket`; stopping Elasticsearch (`@chaos`) burns it fast enough to reach `slo-page`, and Alertmanager then shows
the ticket inhibited by the page. Run it on a stack younger than about 6 hours (`make down` first otherwise): the
ticket resolves once `rate6h` no longer sees the history.

## How bundle releases flow

1. A bundle tags a release (`vX.Y.Z`).
2. The `bundle-updates` workflow (daily at 06:00 UTC, or run by hand) runs `composer update 'msstc4symfony/*'`
   in every app and commits the result to the `bundle-updates` branch. Dependabot cannot do this: it does not see
   releases of packages installed from `vcs` repositories. It still handles Symfony, Docker images and actions.
3. The same run gates that commit with `quality` (PHPStan and PHPUnit per app) and `e2e` (the full stack plus
   Behat, chaos included), called as reusable workflows.
4. When both pass, the run fast-forwards `main` to the commit and deletes the branch. Otherwise the branch stays
   and the run fails; its summary names the update and the failed gate.

`quality` also gates the SLO definitions: `slo:validate`, `slo:coverage` and `slo:generate --check` per app, and
`promtool` on every rule file, the staleness check of `alertmanager.yml` and `amtool`. A slo-bundle release that changes the generated output therefore fails `quality` on the
`bundle-updates` branch. That is intended: run `make slo-generate`, commit and push to that branch.

No pull request is involved: GitHub Actions may not open pull requests in this organisation. A push made with
`GITHUB_TOKEN` does not start the `main` push workflows; the gate has already run on that exact commit.

Each app requires a bundle at the release it relies on, a minor or a patch (`^2.3` for metrics, `^2.2` for
profiling-bundle `^2.3.1`, `^2.3` for healthcheck, `^2.1` for logger, `^2.4` for tracing, `^1.3` for
tracing-bridge-profiling, `^1.2.2` for tracing-bridge-otlp, `^1.1` for slo-bundle, `^1.0` for both SLO bridges), so every later release of the same major is
picked up by that run. A major release goes through an rc tag first (`vX.0.0-rc.N`) and gets its final tag only after
e2e is green here: until then the app requires it as `^X.0@rc` (logic-bundle 2.0.0 went this way), and the final
tag replaces that with `^X.0`.

A bug found here is fixed in the bundle's own repository, with a test and a patch release, and is
never patched in this repository.

## Development

```bash
docker compose up -d postgres   # app tests use the *_test databases
make check                      # PHPStan + PHPUnit per app, PHPStan for e2e, cs-fixer, Rector, SLO gates + promtool (Docker)
make fix                        # Rector, then cs-fixer
```

## Security notes

This is a local stand. Grafana grants anonymous Admin access, RabbitMQ uses `guest/guest`, and
Loki has no authentication; Loki's port is not published. Alloy and Prometheus reach Docker only
through `docker-proxy` (read-only container endpoints). The `e2e` container mounts the docker socket
itself for the `@chaos` scenarios — a read-only mount does not limit the Docker API, so do not run
the e2e profile on a shared host.
