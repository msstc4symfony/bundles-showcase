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

Infrastructure: PostgreSQL 17, Redis 7, RabbitMQ 4, Elasticsearch 8 (single node, used by orders). Observability: Prometheus, Loki, Alloy and Grafana.

## Run it

```bash
make up            # build and start everything, wait until healthy
make demo-traffic  # ~60 orders over a minute; prints a request id to follow
make e2e           # Behat scenarios; @chaos (stops Redis / orders / Elasticsearch) runs last
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
| [metrics-bundle](https://github.com/msstc4symfony/metrics-bundle) | `/_/metrics` on every service: routes, statuses, latency, outbound HTTP, Doctrine queries per connection name, Messenger messages sent and handled, Elasticsearch requests of the FOSElasticaBundle client (`symfony_elastica_request_success{method="PUT",path="orders/_doc/<id>"}`), errors |
| [healthcheck-bundle](https://github.com/msstc4symfony/healthcheck-bundle) | `/_/healthcheck/liveliness` (used by the compose healthchecks) and `/_/healthcheck/readiness` (Redis, Postgres, RabbitMQ, lock store, Elasticsearch through `fos_elastica.client.default`; `?_format=json` for JSON) |
| [profiling-bundle](https://github.com/msstc4symfony/profiling-bundle) | A span for every request and consumed message |
| [metrics-bridge-profiling](https://github.com/msstc4symfony/metrics-bridge-profiling) | Span durations exported as the `symfony_profiling_span_duration_histogram_seconds` metric |

## Elasticsearch in orders

orders uses FOSElasticaBundle 7.2 with Elastica 8. The `orders-search-index` one-shot creates the `orders`
index with its mapping (`app:search:create-index` runs `fos:elastica:create` unless the index exists). After the order is
committed, `POST /orders` indexes it (`PUT orders/_doc/<id>`); a failed indexing call is logged and the order
is still created. The bundles find the FOSElasticaBundle client on their own: metrics-bundle measures its
requests and healthcheck-bundle adds `Elastica connection (fos_elastica.client.default)` to readiness. orders
creates and serves orders without Elasticsearch, so the check is `non_critical`: the `@chaos` scenario stops
Elasticsearch and expects orders to stay ready with a warning, accept orders, and pass the check again after
the restart. The index holds the order as it was created; a document whose indexing failed is not re-indexed.

## How bundle releases flow

1. A bundle tags a release (`vX.Y.Z`).
2. The `bundle-updates` workflow (daily at 06:00 UTC, or run by hand) runs `composer update 'msstc4symfony/*'`
   in every app and commits the result to the `bundle-updates` branch. Dependabot cannot do this: it does not see
   releases of packages installed from `vcs` repositories. It still handles Symfony, Docker images and actions.
3. The same run gates that commit with `quality` (PHPStan and PHPUnit per app) and `e2e` (the full stack plus
   Behat, chaos included), called as reusable workflows.
4. When both pass, the run fast-forwards `main` to the commit and deletes the branch. Otherwise the branch stays
   and an issue labelled `bundle-updates` is opened, or commented on if one is open already.

No pull request is involved: GitHub Actions may not open pull requests in this organisation. A push made with
`GITHUB_TOKEN` does not start the `main` push workflows; the gate has already run on that exact commit.

The bundles are required at `^1.0`, so every release of the 1.x line is picked up by that run. A major release
goes through an rc tag first (`vX.0.0-rc.N`) and gets its final tag only after e2e is green here.

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
Loki has no authentication; Loki's port is not published. Alloy and Prometheus reach Docker only
through `docker-proxy` (read-only container endpoints). The `e2e` container mounts the docker socket
itself for the `@chaos` scenarios — a read-only mount does not limit the Docker API, so do not run
the e2e profile on a shared host.
