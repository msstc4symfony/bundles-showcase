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
| `orders` | Stores the order as `pending`, then sends `ProcessPayment`. Applies `PaymentProcessed` and moves the order to `paid` or `declined`. | RoadRunner + `messenger:consume payment_processed` |
| `billing` | Records the payment. Amounts above `BILLING_DECLINE_ABOVE` (100000) are declined. | RoadRunner (service routes) + `messenger:consume process_payment` |

Infrastructure: PostgreSQL 17, Redis 7, RabbitMQ 4. Observability: Prometheus, Loki, Alloy and Grafana.

## Run it

```bash
make up            # build and start everything, wait until healthy
make demo-traffic  # ~60 orders over a minute; prints a request id to follow
make e2e           # Behat scenarios; @chaos (stops Redis / orders) runs last
make down          # stop and drop volumes
```

| What | URL |
|---|---|
| Gateway API | http://localhost:8080/orders |
| Grafana (anonymous admin) | http://localhost:3000, dashboards "Showcase — services" and "Showcase — profiling spans" |
| Prometheus | http://localhost:9090 |
| RabbitMQ management | http://localhost:15672 (guest / guest) |

All ports bind to `127.0.0.1` only. When a port is already taken, override it:
`SHOWCASE_GATEWAY_PORT`, `SHOWCASE_GRAFANA_PORT`, `SHOWCASE_PROMETHEUS_PORT`,
`SHOWCASE_RABBITMQ_PORT`, `SHOWCASE_POSTGRES_PORT`.

## Follow one request

```bash
curl -s -XPOST localhost:8080/orders -H 'Content-Type: application/json' \
     -H 'Request-Id: my-first-order' -d '{"amount":5000}'
```

In Grafana, open Explore → Loki and run:

```logql
{service=~".+"} | json | extra_request_id="my-first-order"
```

A W3C `traceparent` header works too: search Loki with `extra_trace_id="<trace-id>"`.

The same request id shows up in four processes: `gateway`, `orders`, `billing-worker` and
`orders-worker`. In each of them, `extra_request_from` names the caller
(`showcase:gateway`, `showcase:orders`, `showcase:billing`).

## What each bundle contributes

| Bundle | Seen here as |
|---|---|
| [logger-bundle](https://github.com/msstc4symfony/logger-bundle) | One JSON log record per line on stderr, with `request_id`, `runtime_id`, `request_from` and the container id in `extra` |
| [tracing-bundle](https://github.com/msstc4symfony/tracing-bundle) | `Request-Id` carried across HTTP and RabbitMQ in both directions; a fresh runtime id for every request and message on reused workers; W3C `traceparent`/`tracestate` continued end to end (`extra_trace_id` in Loki) |
| [metrics-bundle](https://github.com/msstc4symfony/metrics-bundle) | `/_/metrics` on every service: routes, statuses, latency, outbound HTTP, Doctrine queries per connection name, Messenger messages sent and handled, errors |
| [healthcheck-bundle](https://github.com/msstc4symfony/healthcheck-bundle) | `/_/healthcheck/liveliness` (used by the compose healthchecks) and `/_/healthcheck/readiness` (Redis, Postgres, RabbitMQ, lock store; `?_format=json` for JSON) |
| [profiling-bundle](https://github.com/msstc4symfony/profiling-bundle) | A span for every request and consumed message |
| [metrics-bridge-profiling](https://github.com/msstc4symfony/metrics-bridge-profiling) | Span durations exported as the `symfony_profiling_span_duration_histogram_seconds` metric |

## How bundle releases flow

1. A bundle tags a release (`vX.Y.Z`).
2. Dependabot opens a PR here: one group for `msstc4symfony/*` and one for `symfony/*`.
3. The `quality` workflow (PHPStan and PHPUnit per app) and the `e2e` workflow (the full stack plus Behat) run on that PR.
4. Merge when both are green.

A major release goes through an rc tag first (`vX.0.0-rc.N`). It gets its final tag only after e2e is green here.

A bug found here is fixed in the bundle's own repository, with a test and a patch release, and is
never patched in this repository.

## Development

```bash
docker compose up -d postgres   # app tests use the *_test databases
make check                      # PHPStan + PHPUnit per app, PHPStan for e2e, cs-fixer, Rector
make fix                        # Rector, then cs-fixer
```

## Security notes

This is a local stand. Grafana grants anonymous Admin access, RabbitMQ uses `guest/guest`, and
Loki has no authentication; Loki's port is not published. The `alloy`, `prometheus` and `e2e` containers mount
the docker socket: Alloy uses it to discover the showcase containers, and e2e uses it for the
`@chaos` scenarios. A read-only mount does not limit the Docker API, so do not run this stack on
a shared host.
