# Stage C: bundles-showcase Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `msstc4symfony/bundles-showcase` — three Symfony 8 services (gateway → orders → billing) on RoadRunner with all msstc4symfony bundles, a production-like observability stack, and Behat e2e scenarios that gate bundle releases.

**Architecture:** Three independent Symfony apps under `apps/` (own `composer.json`/`composer.lock`), one shared PHP/RoadRunner Docker image, docker compose with PostgreSQL, Redis, RabbitMQ, Prometheus, Loki, Alloy, Grafana. Black-box Behat suite in `e2e/` runs in its own container against the compose network.

**Tech Stack:** PHP 8.4, Symfony 8.x, `baldinof/roadrunner-bundle` ^3.4 (RoadRunner 2025.1), Doctrine ORM 3 + PostgreSQL 17, Symfony Messenger + AMQP (RabbitMQ 4), Redis 7, Prometheus, Grafana, Loki, Grafana Alloy, Behat, PHPStan 2 / PHP-CS-Fixer / Rector.

**Spec:** `docs/superpowers/specs/2026-10-01-stage-c-bundles-showcase-design.md` (read it before starting).

## Global Constraints

- Repository: `msstc4symfony/bundles-showcase` (public; created by the owner before Task 1). Local path: `/home/user/PhpstormProjects/msstc4symfony/bundles-showcase`.
- Bundles come **only from released tags** via public GitHub `vcs` repositories (`https://github.com/msstc4symfony/<repo>`); never path repositories, never `dev-main`.
- Bundle constraints: `msstc4symfony/logger-bundle ^1.1`, `msstc4symfony/tracing-bundle ^1.0`, `msstc4symfony/metrics-bundle ^1.2`, `msstc4symfony/healthcheck-bundle ^1.1.1`, `msstc4symfony/profiling-bundle ^1.0`, `msstc4symfony/metrics-bridge-profiling ^1.0`.
- One configuration: Symfony `8.*`, PHP `8.4` (Docker base `php:8.4-cli`).
- Every app installs **all six** bundles.
- Redis DB numbers: gateway cache 0 / metrics 1; orders 2 / 3; billing 4 / 5.
- Monolog writes JSON (logger-bundle `SwitchFormatter`, `HUMAN_READABLE` unset) to **`php://stderr`**; RoadRunner `logs.mode: raw`.
- Messenger transports: `process_payment` (orders → billing, message `App\Message\ProcessPayment`) and `payment_processed` (billing → orders, message `App\Message\PaymentProcessed`); serializer `messenger.transport.symfony_serializer`; same FQCNs in both apps.
- Tracing middleware on the default bus: `OutgoingStampMiddleware`, `IncomingStampMiddleware` (tracing `doc/messenger.yaml`).
- Billing declines amounts **above 100000** (minor units) — env `BILLING_DECLINE_ABOVE=100000`.
- RoadRunner HTTP pool: `num_workers: 2` in every app.
- Code quality: PHPStan level 9 (`treatPhpDocTypesAsCertain: false`, `phpVersion: 80400`), PHP-CS-Fixer and Rector configs copied from `bundle-standard/templates/` (`.php-cs-fixer.dist.php`, `rector.php`); global typing/DTO/entity rules apply; comments in English, minimal.
- Commit messages: one English line, ≤ 20 words, no trailers; squash consecutive similar commits before pushing.
- Times/dates in docs: UTC.

## Review Focus

1. **Duplicate POST under concurrency** — two `POST /orders` with the same `Idempotency-Key` sent at once (possibly to different RoadRunner workers) must still create one order. `CacheInterface::get()` locks only within one process, so the parallel e2e decides: if it creates two orders, `IdempotencyStore` switches to Redis `SET NX`. Test in Task 5 (`IdempotencyStoreTest`) and Task 8 (`idempotency.feature`, concurrent scenario).
2. **Order amount validation** — non-positive, non-integer or missing `amount` must return 422, not 500 or a stored order. Test in Task 4 (`CreateOrderControllerTest`) and Task 5 (gateway passes 422 through).
3. **Unknown order id** — `GET /orders/{id}` for a missing or malformed id must return 404 through gateway, not 500. Test in Task 4 and Task 5.
4. **Billing redelivers the same message** — a redelivered `ProcessPayment` (same order id) must not create a second payment. Test in Task 3 (`ProcessPaymentHandlerTest::testRedeliveryDoesNotPayTwice`).
5. **orders is down while gateway is up** — gateway must answer 502 with a JSON error, and its readiness stays green (orders is not a gateway dependency checked by healthcheck). Test in Task 5 (`OrdersClientTest`) and Task 10 (`@chaos` scenario stopping `orders`).

---

## File Structure

```
bundles-showcase/
├── apps/
│   ├── billing/
│   │   ├── composer.json, composer.lock, .rr.yaml, phpstan.dist.neon, phpunit.xml.dist
│   │   ├── config/{bundles.php, packages/*.yaml, routes.yaml, services.yaml}
│   │   ├── migrations/Version20261001000000.php
│   │   ├── src/{Kernel.php, Entity/Payment.php, Message/ProcessPayment.php, Message/PaymentProcessed.php,
│   │   │        MessageHandler/ProcessPaymentHandler.php, Payment/DeclinePolicy.php, Repository/PaymentRepository.php}
│   │   └── tests/{Unit,Integration}/...
│   ├── orders/
│   │   ├── (same skeleton files)
│   │   ├── migrations/Version20261001000000.php
│   │   ├── src/{Kernel.php, Entity/Order.php, Entity/OrderStatus.php, Message/*, MessageHandler/PaymentProcessedHandler.php,
│   │   │        Controller/CreateOrderController.php, Controller/ShowOrderController.php, Order/CreateOrderRequest.php,
│   │   │        Order/OrderView.php, Repository/OrderRepository.php}
│   │   └── tests/...
│   └── gateway/
│       ├── (same skeleton files)
│       ├── src/{Kernel.php, Controller/OrdersController.php, Orders/OrdersClient.php, Orders/OrdersUnavailable.php,
│       │        Idempotency/IdempotencyStore.php}
│       └── tests/...
├── e2e/
│   ├── composer.json, composer.lock, behat.yml, phpstan.dist.neon
│   ├── features/{order_lifecycle,idempotency,tracing,worker_reset,metrics,health}.feature
│   └── src/{Context/*.php, Client/{Gateway,Prometheus,Loki,RabbitMq,Docker}.php, Wait.php}
├── docker/
│   ├── php/Dockerfile
│   ├── postgres/init.sql
│   └── observability/{prometheus.yml, loki.yaml, alloy.alloy, grafana/provisioning/**, grafana/dashboards/*.json}
├── compose.yaml
├── Makefile
├── .php-cs-fixer.dist.php, rector.php        # shared, copied from bundle-standard templates
├── .github/{workflows/quality.yml, workflows/e2e.yml, dependabot.yml}
├── .claude/docs/{README.md, architecture.md, known-issues.md}
└── README.md
```

---

### Task 1: Repository skeleton, shared image, RoadRunner proof

**Files:**
- Create: `compose.yaml`, `docker/php/Dockerfile`, `Makefile`, `.gitignore`, `.php-cs-fixer.dist.php`, `rector.php`
- Create: `apps/gateway/` (Symfony skeleton + `baldinof/roadrunner-bundle`), `apps/gateway/.rr.yaml`, `apps/gateway/phpstan.dist.neon`, `apps/gateway/phpunit.xml.dist`
- Create: `apps/orders/`, `apps/billing/` (same skeleton, no domain code yet)
- Create: `.github/workflows/quality.yml`

**Interfaces:**
- Produces: image `bundles-showcase-php` built with `--build-arg APP=<gateway|orders|billing>`; command `rr serve -c .rr.yaml` (HTTP) and `bin/console messenger:consume ...` (workers); `make up`, `make down`, `make check`, `make fix`.

- [ ] **Step 1: Clone and create the three Symfony apps**

```bash
cd /home/user/PhpstormProjects/msstc4symfony
git clone git@github.com:msstc4symfony/bundles-showcase.git
cd bundles-showcase
for app in gateway orders billing; do
  composer create-project symfony/skeleton:"8.*" apps/$app --no-interaction
  rm -rf apps/$app/.git
done
```

- [ ] **Step 2: Install RoadRunner bundle and the six msstc4symfony bundles in each app**

```bash
for app in gateway orders billing; do
  cd apps/$app
  for b in logger-bundle tracing-bundle metrics-bundle healthcheck-bundle profiling-bundle metrics-bridge-profiling; do
    composer config repositories.$b vcs https://github.com/msstc4symfony/$b
  done
  composer require --no-interaction baldinof/roadrunner-bundle:^3.4 symfony/monolog-bundle symfony/http-client \
    msstc4symfony/logger-bundle:^1.1 msstc4symfony/tracing-bundle:^1.0 msstc4symfony/metrics-bundle:^1.2 \
    msstc4symfony/healthcheck-bundle:^1.1.1 msstc4symfony/profiling-bundle:^1.0 msstc4symfony/metrics-bridge-profiling:^1.0
  composer require --dev --no-interaction phpunit/phpunit phpstan/phpstan phpstan/phpstan-strict-rules \
    phpstan/phpstan-symfony phpstan/extension-installer friendsofphp/php-cs-fixer rector/rector symfony/browser-kit
  cd ../..
done
```

Expected: Flex auto-registers every bundle in `config/bundles.php` (auto-generated recipes). If `baldinof/roadrunner-bundle` fails to resolve on Symfony 8, stop and switch to `runtime/roadrunner-symfony-nyholm` (spec, risk 1), recording it in `.claude/docs/known-issues.md`.

- [ ] **Step 3: Shared Dockerfile `docker/php/Dockerfile`**

```dockerfile
FROM ghcr.io/roadrunner-server/roadrunner:2025.1 AS roadrunner

FROM php:8.4-cli AS base
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev librabbitmq-dev libicu-dev unzip git \
    && pecl install redis amqp \
    && docker-php-ext-enable redis amqp \
    && docker-php-ext-install pdo_pgsql intl opcache sockets \
    && rm -rf /var/lib/apt/lists/*
COPY --from=roadrunner /usr/bin/rr /usr/local/bin/rr
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

FROM base AS app
ARG APP
WORKDIR /app
COPY apps/${APP}/composer.json apps/${APP}/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY apps/${APP}/ ./
RUN composer dump-autoload --classmap-authoritative --no-dev \
    && APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup
ENV APP_ENV=prod APP_DEBUG=0
CMD ["rr", "serve", "-c", ".rr.yaml"]
```

- [ ] **Step 4: `.rr.yaml` (identical in each app)**

```yaml
version: "3"
server:
  command: "php public/index.php"
  env:
    - APP_RUNTIME: Baldinof\RoadRunnerBundle\Runtime\Runtime
http:
  address: 0.0.0.0:8080
  pool:
    num_workers: 2
logs:
  mode: raw
  level: info
```

- [ ] **Step 5: Minimal `compose.yaml` with the three HTTP services**

```yaml
name: bundles-showcase
x-app: &app
  build: { context: ., dockerfile: docker/php/Dockerfile, target: app }
  environment: &app-env
    APPLICATION_NAME: showcase
services:
  gateway:
    <<: *app
    build: { context: ., dockerfile: docker/php/Dockerfile, target: app, args: { APP: gateway } }
    environment: { <<: *app-env, COMPONENT_NAME: gateway }
    ports: ["8080:8080"]
    healthcheck: &hc { test: ["CMD", "php", "-r", "exit(@file_get_contents('http://127.0.0.1:8080/_/healthcheck/liveliness') === false ? 1 : 0);"], interval: 5s, retries: 20 }
  orders:
    <<: *app
    build: { context: ., dockerfile: docker/php/Dockerfile, target: app, args: { APP: orders } }
    environment: { <<: *app-env, COMPONENT_NAME: orders }
    healthcheck: *hc
  billing:
    <<: *app
    build: { context: ., dockerfile: docker/php/Dockerfile, target: app, args: { APP: billing } }
    environment: { <<: *app-env, COMPONENT_NAME: billing }
    healthcheck: *hc
```

- [ ] **Step 6: Run and verify RoadRunner serves the healthcheck**

Run: `docker compose up --build --wait && curl -s localhost:8080/_/healthcheck/ping && docker compose exec orders php -r "echo file_get_contents('http://127.0.0.1:8080/_/healthcheck/liveliness');"`
Expected: `pong`-style 200 body from gateway; `Result: up` from orders. All three containers `healthy`.

- [ ] **Step 7: Quality tooling**

Copy `../bundle-standard/templates/.php-cs-fixer.dist.php` and `../bundle-standard/templates/rector.php` to the repo root; in `rector.php` set `withPaths([__DIR__ . '/apps/*/src', __DIR__ . '/apps/*/tests', __DIR__ . '/e2e/src'])`. Each app gets `phpstan.dist.neon`:

```neon
parameters:
    level: 9
    treatPhpDocTypesAsCertain: false
    phpVersion: 80400
    paths: [src/, tests/]
    symfony:
        containerXmlPath: var/cache/dev/App_KernelDevDebugContainer.xml
```

`Makefile`:

```make
APPS := gateway orders billing
up: ; docker compose up --build --wait
down: ; docker compose down -v
check:
	@for a in $(APPS); do (cd apps/$$a && php bin/console cache:warmup -q && vendor/bin/phpstan analyse --no-progress && vendor/bin/phpunit) || exit 1; done
	vendor/bin/php-cs-fixer check && vendor/bin/rector process --dry-run
fix: ; vendor/bin/php-cs-fixer fix && vendor/bin/rector process
.PHONY: up down check fix
```

Root `composer.json` (tools only): `friendsofphp/php-cs-fixer`, `rector/rector` in `require-dev`.

`.github/workflows/quality.yml`: matrix over `[gateway, orders, billing]` — `shivammathur/setup-php@v2` (PHP 8.4, extensions `redis, amqp, intl, pdo_pgsql`), `composer install` in the app, `bin/console cache:warmup`, `vendor/bin/phpstan analyse`, `vendor/bin/phpunit`; plus a job running root `php-cs-fixer check` and `rector --dry-run`.

- [ ] **Step 8: Run quality gate locally**

Run: `make check`
Expected: PHPStan `[OK] No errors` for each app, PHPUnit `No tests executed!` acceptable at this point (or remove empty suites), cs-fixer/rector clean.

- [ ] **Step 9: Commit and push**

```bash
git add -A
git commit -m "Add three Symfony 8 apps on RoadRunner with all msstc4symfony bundles and quality gate"
git push origin main
```

---

### Task 2: Infrastructure services and shared bundle configuration

**Files:**
- Modify: `compose.yaml` (postgres, redis, rabbitmq, `depends_on`, env per app)
- Create: `docker/postgres/init.sql`
- Create in each app: `config/packages/monolog.yaml`, `config/packages/cache.yaml`, `config/packages/msstc4symfony_profiling.yaml`, `config/routes/metrics.yaml`, `config/packages/messenger.yaml` (orders, billing)
- Test: `apps/*/tests/Integration/KernelBootTest.php`

**Interfaces:**
- Produces: env vars per app — `DATABASE_URL` (orders, billing), `MESSENGER_TRANSPORT_DSN=amqp://guest:guest@rabbitmq:5672/%2f`, `REDIS_CACHE_DSN=redis://redis:6379/<cache db>`, `METRICS_STORAGE_DSN=redis://redis:6379?database=<metrics db>`, `BILLING_DECLINE_ABOVE` (billing).

- [ ] **Step 1: Write the failing kernel boot test (per app)**

`apps/gateway/tests/Integration/KernelBootTest.php` (same shape in orders/billing, namespace `App\Tests\Integration`):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Msstc4Symfony\ProfilingBundle\Framework\ProfilingFactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class KernelBootTest extends KernelTestCase
{
    public function testAllShowcaseBundlesAreWired(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertTrue($container->has(ProfilingFactoryInterface::class));
        self::assertTrue($container->has('Msstc4Symfony\TracingBundle\Storage\RequestIdServiceInterface'));
        self::assertSame('showcase', $container->getParameter('metrics_bundle.applicationName'));
    }
}
```

Run: `cd apps/gateway && APPLICATION_NAME=showcase vendor/bin/phpunit tests/Integration/KernelBootTest.php`
Expected: FAIL until the config below exists (e.g. `metrics_bundle.applicationName` is `unknown`) — then pass once env is set in `.env.test`.

- [ ] **Step 2: Shared bundle config in each app**

`config/packages/monolog.yaml`:

```yaml
monolog:
  handlers:
    main:
      type: stream
      path: php://stderr
      level: info
      channels: ["!event", "!deprecation"]
      formatter: Msstc4Symfony\LoggerBundle\Monolog\Formatter\SwitchFormatter
```

`config/packages/cache.yaml`:

```yaml
framework:
  cache:
    app: cache.adapter.redis
    default_redis_provider: '%env(REDIS_CACHE_DSN)%'
```

`config/routes/metrics.yaml`:

```yaml
metrics:
  resource: '@MetricsBundle/Presentation/Controller/'
  type: attribute
```

`config/packages/msstc4symfony_profiling.yaml` (gateway; orders adds its routes and `App\Message\PaymentProcessed`; billing adds `App\Message\ProcessPayment`):

```yaml
msstc4symfony_profiling:
  routes: ['gateway_create_order', 'gateway_show_order']
```

`.env.test` in each app: `APPLICATION_NAME=showcase`, `REDIS_CACHE_DSN=redis://localhost:6379/15`, `METRICS_STORAGE_DSN=inmemory://`.

- [ ] **Step 3: Messenger config (orders and billing)**

`config/packages/messenger.yaml` (orders; billing swaps routing and the consumed transport):

```yaml
framework:
  messenger:
    serializer:
      default_serializer: messenger.transport.symfony_serializer
    buses:
      messenger.bus.default:
        middleware:
          - Msstc4Symfony\TracingBundle\Messenger\Middleware\OutgoingStampMiddleware
          - Msstc4Symfony\TracingBundle\Messenger\Middleware\IncomingStampMiddleware
    transports:
      process_payment:
        dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
        options: { exchange: { name: process_payment }, queues: { process_payment: ~ } }
      payment_processed:
        dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
        options: { exchange: { name: payment_processed }, queues: { payment_processed: ~ } }
    routing:
      App\Message\ProcessPayment: process_payment
```

`.env.test` for orders/billing: `MESSENGER_TRANSPORT_DSN=in-memory://`.

- [ ] **Step 4: Infrastructure in `compose.yaml`**

```yaml
  postgres:
    image: postgres:17
    environment: { POSTGRES_USER: showcase, POSTGRES_PASSWORD: showcase, POSTGRES_DB: postgres }
    volumes: ["./docker/postgres/init.sql:/docker-entrypoint-initdb.d/init.sql:ro"]
    healthcheck: { test: ["CMD", "pg_isready", "-U", "showcase"], interval: 3s, retries: 30 }
  redis:
    image: redis:7
    healthcheck: { test: ["CMD", "redis-cli", "ping"], interval: 3s, retries: 30 }
  rabbitmq:
    image: rabbitmq:4-management
    ports: ["15672:15672"]
    healthcheck: { test: ["CMD", "rabbitmq-diagnostics", "-q", "ping"], interval: 5s, retries: 30 }
```

`docker/postgres/init.sql`: `CREATE DATABASE orders; CREATE DATABASE billing;`

App env (add `depends_on: {postgres|redis|rabbitmq: {condition: service_healthy}}` as needed):
- gateway: `REDIS_CACHE_DSN=redis://redis:6379/0`, `METRICS_STORAGE_DSN=redis://redis:6379?database=1`, `ORDERS_URL=http://orders:8080`
- orders: `DATABASE_URL=postgresql://showcase:showcase@postgres:5432/orders?serverVersion=17&charset=utf8`, `REDIS_CACHE_DSN=redis://redis:6379/2`, `METRICS_STORAGE_DSN=redis://redis:6379?database=3`, `MESSENGER_TRANSPORT_DSN=amqp://guest:guest@rabbitmq:5672/%2f`
- billing: same with database `billing`, Redis 4/5, `BILLING_DECLINE_ABOVE=100000`

Declare each app's variables once as a top-level anchor (`x-orders-env: &orders-env {...}`, `x-billing-env: &billing-env {...}`) and reuse them for the HTTP service and its worker:

```yaml
  orders-worker:
    build: { context: ., dockerfile: docker/php/Dockerfile, target: app, args: { APP: orders } }
    command: ["php", "bin/console", "messenger:consume", "payment_processed", "--time-limit=3600", "-vv"]
    environment: *orders-env
    depends_on: { orders: { condition: service_healthy } }
  billing-worker:
    build: { context: ., dockerfile: docker/php/Dockerfile, target: app, args: { APP: billing } }
    command: ["php", "bin/console", "messenger:consume", "process_payment", "--time-limit=3600", "-vv"]
    environment: *billing-env
    depends_on: { billing: { condition: service_healthy } }
```

- [ ] **Step 5: Verify**

Run: `make check && docker compose up --build --wait && curl -s 'localhost:8080/_/healthcheck/readiness?_format=json'`
Expected: tests pass; JSON `{"success":true,...}`; `docker compose ps` all healthy.

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "Wire Postgres, Redis, RabbitMQ and shared bundle configuration into all services"
```

---

### Task 3: billing — process payments

**Files:**
- Create: `apps/billing/src/Message/ProcessPayment.php`, `apps/billing/src/Message/PaymentProcessed.php`, `apps/billing/src/Entity/Payment.php`, `apps/billing/src/Payment/DeclinePolicy.php`, `apps/billing/src/Repository/PaymentRepository.php`, `apps/billing/src/MessageHandler/ProcessPaymentHandler.php`, `apps/billing/migrations/Version20261001000000.php`
- Modify: `apps/billing/config/packages/doctrine.yaml` (installed by `composer require symfony/orm-pack`)
- Test: `apps/billing/tests/Unit/Payment/DeclinePolicyTest.php`, `apps/billing/tests/Integration/ProcessPaymentHandlerTest.php`

**Interfaces:**
- Produces: `App\Message\ProcessPayment(string $orderId, int $amount)`, `App\Message\PaymentProcessed(string $orderId, bool $approved)` — **identical FQCN and constructor in orders** (Task 4 copies them).

- [ ] **Step 1: Install ORM** — `cd apps/billing && composer require symfony/orm-pack doctrine/doctrine-migrations-bundle symfony/amqp-messenger`

- [ ] **Step 2: Failing tests**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment;

use App\Payment\DeclinePolicy;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class DeclinePolicyTest extends TestCase
{
    #[TestWith([100000, true])]
    #[TestWith([100001, false])]
    #[TestWith([1, true])]
    public function testApprovesUpToTheLimit(int $amount, bool $approved): void
    {
        self::assertSame($approved, new DeclinePolicy(100000)->approves($amount));
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Message\PaymentProcessed;
use App\Message\ProcessPayment;
use App\MessageHandler\ProcessPaymentHandler;
use App\Repository\PaymentRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ProcessPaymentHandlerTest extends KernelTestCase
{
    public function testApprovesAndReplies(): void
    {
        self::bootKernel();
        $handler = self::getContainer()->get(ProcessPaymentHandler::class);
        self::assertInstanceOf(ProcessPaymentHandler::class, $handler);

        $handler(new ProcessPayment('0190e0f6-0000-7000-8000-000000000001', 5000));

        $sent = $this->replies();
        self::assertCount(1, $sent);
        self::assertEquals(new PaymentProcessed('0190e0f6-0000-7000-8000-000000000001', true), $sent[0]);
    }

    public function testRedeliveryDoesNotPayTwice(): void
    {
        self::bootKernel();
        $handler = self::getContainer()->get(ProcessPaymentHandler::class);
        self::assertInstanceOf(ProcessPaymentHandler::class, $handler);

        $message = new ProcessPayment('0190e0f6-0000-7000-8000-000000000002', 5000);
        $handler($message);
        $handler($message);

        $payments = self::getContainer()->get(PaymentRepository::class);
        self::assertInstanceOf(PaymentRepository::class, $payments);
        self::assertSame(1, $payments->count(['orderId' => '0190e0f6-0000-7000-8000-000000000002']));
        self::assertCount(2, $this->replies(), 'a redelivery still answers so orders is not left pending');
    }

    /** @return list<object> */
    private function replies(): array
    {
        $transport = self::getContainer()->get('messenger.transport.payment_processed');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_map(static fn ($e): object => $e->getMessage(), $transport->getSent());
    }
}
```

Integration tests use a test database: `.env.test` `DATABASE_URL=postgresql://showcase:showcase@127.0.0.1:5432/billing_test?serverVersion=17`; `tests/bootstrap.php` is not used — run `bin/console doctrine:database:create --env=test --if-not-exists && bin/console doctrine:migrations:migrate --env=test -n` in `make check` before PHPUnit (CI service container `postgres:17`).

Run: `vendor/bin/phpunit` → FAIL (classes missing).

- [ ] **Step 3: Implementation**

```php
<?php // src/Message/ProcessPayment.php
declare(strict_types=1);
namespace App\Message;

final readonly class ProcessPayment
{
    public function __construct(
        public string $orderId,
        public int $amount,
    ) {
    }
}
```

```php
<?php // src/Message/PaymentProcessed.php
declare(strict_types=1);
namespace App\Message;

final readonly class PaymentProcessed
{
    public function __construct(
        public string $orderId,
        public bool $approved,
    ) {
    }
}
```

```php
<?php // src/Payment/DeclinePolicy.php
declare(strict_types=1);
namespace App\Payment;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class DeclinePolicy
{
    /**
     * @param positive-int $declineAbove
     */
    public function __construct(
        #[Autowire(env: 'int:BILLING_DECLINE_ABOVE')]
        private int $declineAbove,
    ) {
    }

    public function approves(int $amount): bool
    {
        return $amount <= $this->declineAbove;
    }
}
```

```php
<?php // src/Entity/Payment.php
declare(strict_types=1);
namespace App\Entity;

use App\Repository\PaymentRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\UniqueConstraint(columns: ['order_id'])]
final class Payment
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private readonly string $id;

    #[ORM\Column]
    private readonly DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Column(type: 'guid')]
        private readonly string $orderId,
        #[ORM\Column]
        private readonly int $amount,
        #[ORM\Column]
        private readonly bool $approved,
    ) {
        $this->id = Uuid::v7()->toRfc4122();
        $this->createdAt = new DateTimeImmutable();
    }

    public function isApproved(): bool
    {
        return $this->approved;
    }
}
```

```php
<?php // src/MessageHandler/ProcessPaymentHandler.php
declare(strict_types=1);
namespace App\MessageHandler;

use App\Entity\Payment;
use App\Message\PaymentProcessed;
use App\Message\ProcessPayment;
use App\Payment\DeclinePolicy;
use App\Repository\PaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class ProcessPaymentHandler
{
    public function __construct(
        private PaymentRepository $payments,
        private EntityManagerInterface $entityManager,
        private DeclinePolicy $policy,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(ProcessPayment $message): void
    {
        // Redelivery: answer again with the stored outcome instead of paying twice.
        $payment = $this->payments->findOneBy(['orderId' => $message->orderId]);
        if (!$payment instanceof Payment) {
            $payment = new Payment($message->orderId, $message->amount, $this->policy->approves($message->amount));
            $this->entityManager->persist($payment);
            $this->entityManager->flush();
        }

        $this->bus->dispatch(new PaymentProcessed($message->orderId, $payment->isApproved()));
    }
}
```

`PaymentRepository` — standard `ServiceEntityRepository<Payment>` generated by `bin/console make:entity` style (`@extends ServiceEntityRepository<Payment>`). Routing in billing `messenger.yaml`: `App\Message\PaymentProcessed: payment_processed`. Migration: `bin/console doctrine:migrations:diff` then rename to `Version20261001000000`.

- [ ] **Step 4: Run tests** — `make check` → PASS.

- [ ] **Step 5: Commit** — `git commit -am "Process payments in billing with idempotent redelivery handling"` (add new files first).

---

### Task 4: orders — create orders and apply payment results

**Files:**
- Create: `apps/orders/src/Message/{ProcessPayment,PaymentProcessed}.php` (copies of Task 3, identical), `apps/orders/src/Entity/{Order,OrderStatus}.php`, `apps/orders/src/Repository/OrderRepository.php`, `apps/orders/src/Order/{CreateOrderRequest,OrderView}.php`, `apps/orders/src/Controller/{CreateOrderController,ShowOrderController}.php`, `apps/orders/src/MessageHandler/PaymentProcessedHandler.php`, `apps/orders/migrations/Version20261001000000.php`
- Test: `apps/orders/tests/Integration/{CreateOrderControllerTest,ShowOrderControllerTest,PaymentProcessedHandlerTest}.php`

**Interfaces:**
- Consumes: messages from Task 3.
- Produces: HTTP `POST /orders` (JSON `{"amount": int}` → 201 `{"id": uuid, "status": "pending", "amount": int}`; 422 on invalid amount), `GET /orders/{id}` (200 `OrderView` JSON or 404). Route names `orders_create`, `orders_show`.

- [ ] **Step 1: Failing tests** (WebTestCase; same test DB pattern as Task 3 with database `orders_test`)

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Message\ProcessPayment;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class CreateOrderControllerTest extends WebTestCase
{
    public function testCreatesPendingOrderAndRequestsPayment(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/orders', ['amount' => 5000]);

        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('pending', $body['status']);

        $transport = self::getContainer()->get('messenger.transport.process_payment');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        self::assertEquals(new ProcessPayment((string) $body['id'], 5000), $sent[0]->getMessage());
    }

    #[TestWith([['amount' => 0]])]
    #[TestWith([['amount' => -5]])]
    #[TestWith([['amount' => '12']])]
    #[TestWith([[]])]
    public function testRejectsInvalidAmount(array $payload): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/orders', $payload);

        self::assertResponseStatusCodeSame(422);
    }
}
```

`ShowOrderControllerTest`: 404 for `GET /orders/0190e0f6-0000-7000-8000-00000000ffff` and for `GET /orders/not-a-uuid`; 200 with the created order.
`PaymentProcessedHandlerTest`: created order + `PaymentProcessed(id, true)` → status `paid`; `false` → `declined`; unknown id → no exception (logged at warning).

Run → FAIL.

- [ ] **Step 2: Implementation**

```php
<?php // src/Entity/OrderStatus.php
declare(strict_types=1);
namespace App\Entity;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Declined = 'declined';
}
```

```php
<?php // src/Entity/Order.php
declare(strict_types=1);
namespace App\Entity;

use App\Repository\OrderRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use DomainException;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'orders')]
final class Order
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private readonly string $id;

    #[ORM\Column(enumType: OrderStatus::class)]
    private OrderStatus $status = OrderStatus::Pending;

    #[ORM\Column]
    private readonly DateTimeImmutable $createdAt;

    /**
     * @param positive-int $amount
     */
    public function __construct(
        #[ORM\Column]
        private readonly int $amount,
    ) {
        $this->id = Uuid::v7()->toRfc4122();
        $this->createdAt = new DateTimeImmutable();
    }

    public function id(): string { return $this->id; }
    public function amount(): int { return $this->amount; }
    public function status(): OrderStatus { return $this->status; }

    public function applyPayment(bool $approved): void
    {
        if ($this->status !== OrderStatus::Pending) {
            return; // a redelivered result must not flip a settled order
        }

        $this->status = $approved ? OrderStatus::Paid : OrderStatus::Declined;
    }
}
```

```php
<?php // src/Order/CreateOrderRequest.php
declare(strict_types=1);
namespace App\Order;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateOrderRequest
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Type('integer')]
        #[Assert\Positive]
        public mixed $amount = null,
    ) {
    }
}
```

```php
<?php // src/Order/OrderView.php
declare(strict_types=1);
namespace App\Order;

use App\Entity\Order;

final readonly class OrderView
{
    public function __construct(
        public string $id,
        public string $status,
        public int $amount,
    ) {
    }

    public static function of(Order $order): self
    {
        return new self($order->id(), $order->status()->value, $order->amount());
    }
}
```

```php
<?php // src/Controller/CreateOrderController.php
declare(strict_types=1);
namespace App\Controller;

use App\Entity\Order;
use App\Message\ProcessPayment;
use App\Order\CreateOrderRequest;
use App\Order\OrderView;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Webmozart\Assert\Assert;

final readonly class CreateOrderController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
    ) {
    }

    #[Route('/orders', name: 'orders_create', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload(validationFailedStatusCode: 422)] CreateOrderRequest $request): JsonResponse
    {
        // Already validated by the request constraints; the assertion narrows the type.
        Assert::positiveInteger($request->amount);
        $order = new Order($request->amount);

        // A failed AMQP send throws inside the transaction and rolls the order back.
        $this->entityManager->wrapInTransaction(function () use ($order): void {
            $this->entityManager->persist($order);
            $this->bus->dispatch(new ProcessPayment($order->id(), $order->amount()));
        });

        return new JsonResponse(OrderView::of($order), 201);
    }
}
```

Add `webmozart/assert` to orders (`composer require webmozart/assert`).

`ShowOrderController`: `#[Route('/orders/{id}', name: 'orders_show', methods: ['GET'], requirements: ['id' => Requirement::UUID])]`, `find($id)` → 404 `JsonResponse(['error' => 'not_found'], 404)` when null, else `OrderView`.

`PaymentProcessedHandler`: `#[AsMessageHandler]`, loads order, `applyPayment($message->approved)`, `flush()`; unknown id → `$logger->warning('Payment result for unknown order', ['order_id' => ...])`.

Routing in orders `messenger.yaml`: `App\Message\ProcessPayment: process_payment` (done in Task 2); worker consumes `payment_processed`.

Profiling config for orders: `routes: ['orders_create', 'orders_show']`, `messages: ['App\Message\PaymentProcessed']`; billing: `messages: ['App\Message\ProcessPayment']`.

- [ ] **Step 3: Run** — `make check` → PASS. Then `make up` and `curl -s -XPOST localhost:8080/...` is not possible yet (gateway proxy is Task 5); verify via `docker compose exec orders` + `curl -s -XPOST -H 'Content-Type: application/json' -d '{"amount":5000}' http://127.0.0.1:8080/orders` and poll `GET` until `paid`.

- [ ] **Step 4: Commit** — `git commit -m "Create orders, request payment through RabbitMQ and apply payment results"`.

---

### Task 5: gateway — proxy and idempotency

**Files:**
- Create: `apps/gateway/src/Orders/OrdersClient.php`, `apps/gateway/src/Orders/OrdersUnavailable.php`, `apps/gateway/src/Idempotency/IdempotencyStore.php`, `apps/gateway/src/Controller/OrdersController.php`, `apps/gateway/config/packages/framework.yaml` (scoped client)
- Test: `apps/gateway/tests/Unit/Orders/OrdersClientTest.php`, `apps/gateway/tests/Unit/Idempotency/IdempotencyStoreTest.php`, `apps/gateway/tests/Integration/OrdersControllerTest.php`

**Interfaces:**
- Consumes: orders HTTP API (Task 4).
- Produces: gateway `POST /orders` (header `Idempotency-Key` optional), `GET /orders/{id}`; route names `gateway_create_order`, `gateway_show_order`. Responses mirror orders status codes/bodies; orders unreachable → 502 `{"error":"orders_unavailable"}`.

- [ ] **Step 1: Scoped client** — `framework.http_client.scoped_clients.orders.client: { base_uri: '%env(ORDERS_URL)%', timeout: 5 }` (injected as `HttpClientInterface $ordersClient`).

- [ ] **Step 2: Failing tests**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Idempotency;

use App\Idempotency\IdempotencyStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class IdempotencyStoreTest extends TestCase
{
    public function testFirstWriterWinsAndLaterCallsGetItsValue(): void
    {
        $store = new IdempotencyStore(new ArrayAdapter());
        $calls = 0;
        $create = static function () use (&$calls): string { ++$calls; return 'order-' . $calls; };

        self::assertSame('order-1', $store->remember('key-a', $create));
        self::assertSame('order-1', $store->remember('key-a', $create));
        self::assertSame('order-2', $store->remember('key-b', $create));
        self::assertSame(2, $calls);
    }
}
```

`OrdersClientTest` (with `MockHttpClient`): forwards status/body for 201/422/404; `TransportException` → throws `OrdersUnavailable`.
`OrdersControllerTest` (WebTestCase with `MockHttpClient` registered for `orders.client`): same `Idempotency-Key` twice → one upstream POST, identical bodies; orders down → 502 JSON; malformed id → 404.

- [ ] **Step 3: Implementation**

```php
<?php // src/Idempotency/IdempotencyStore.php
declare(strict_types=1);
namespace App\Idempotency;

use Closure;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Key → created resource id. CacheInterface::get() computes once per key and locks the
 * computation (stampede protection), so concurrent duplicates wait for the first result.
 */
final readonly class IdempotencyStore
{
    private const int TTL_SECONDS = 86400;

    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    /**
     * @param Closure(): string $create
     */
    public function remember(string $key, Closure $create): string
    {
        return $this->cache->get('idempotency_' . hash('sha256', $key), static function (ItemInterface $item) use ($create): string {
            $item->expiresAfter(self::TTL_SECONDS);

            return $create();
        });
    }
}
```

Concurrency note (Review Focus 1): Symfony's cache `get()` uses a local lock per process only. Two RoadRunner workers can race. The controller therefore stores the **upstream response body** (not just id) and the e2e parallel scenario asserts a single order: if it fails, switch `IdempotencyStore` to Redis `SET key value NX EX` via `\Redis` (`$redis->set($key, $placeholder, ['nx', 'ex' => 86400])`) with the loser polling the value. Implement with Redis `SET NX` from the start if the parallel e2e is flaky.

`OrdersController::create(Request)`: read `Idempotency-Key`; if absent → forward directly; else `remember(key, fn () => json_encode([status, body]))` and replay. `show(string $id)` with `Requirement::UUID` → forward; non-UUID → 404 by routing.

- [ ] **Step 4: Run** — `make check` → PASS; `make up`; `curl -s -XPOST localhost:8080/orders -H 'Content-Type: application/json' -H 'Idempotency-Key: k1' -d '{"amount":5000}'` twice → same id; poll `GET /orders/{id}` → `paid` within seconds.

- [ ] **Step 5: Commit** — `git commit -m "Proxy orders through gateway with Redis-backed idempotency keys"`.

---

### Task 6: Observability stack

**Files:**
- Create: `docker/observability/prometheus.yml`, `docker/observability/loki.yaml`, `docker/observability/alloy.alloy`, `docker/observability/grafana/provisioning/datasources/datasources.yaml`, `docker/observability/grafana/provisioning/dashboards/dashboards.yaml`, `docker/observability/grafana/dashboards/services.json`, `docker/observability/grafana/dashboards/profiling.json`
- Modify: `compose.yaml`

- [ ] **Step 1: `prometheus.yml`**

```yaml
global: { scrape_interval: 5s }
scrape_configs:
  - job_name: showcase
    metrics_path: /_/metrics
    static_configs:
      - targets: ['gateway:8080', 'orders:8080', 'billing:8080']
```

- [ ] **Step 2: Loki (single binary, filesystem) and Alloy**

`alloy.alloy`:

```alloy
discovery.docker "containers" { host = "unix:///var/run/docker.sock" }
discovery.relabel "services" {
  targets = discovery.docker.containers.targets
  rule { source_labels = ["__meta_docker_container_label_com_docker_compose_service"] target_label = "service" }
}
loki.source.docker "logs" {
  host = "unix:///var/run/docker.sock"
  targets = discovery.relabel.services.output
  forward_to = [loki.write.local.receiver]
}
loki.write "local" { endpoint { url = "http://loki:3100/loki/api/v1/push" } }
```

`loki.yaml`: `auth_enabled: false`, `common: {path_prefix: /loki, replication_factor: 1, ring: {kvstore: {store: inmemory}}}`, `schema_config` with `tsdb` + `filesystem`, `limits_config: {allow_structured_metadata: true}`.

compose:

```yaml
  prometheus: { image: prom/prometheus:v3.5.0, volumes: ["./docker/observability/prometheus.yml:/etc/prometheus/prometheus.yml:ro"], ports: ["9090:9090"] }
  loki: { image: grafana/loki:3.5.0, command: ["-config.file=/etc/loki/loki.yaml"], volumes: ["./docker/observability/loki.yaml:/etc/loki/loki.yaml:ro"], mem_limit: 512m }
  alloy: { image: grafana/alloy:v1.10.0, command: ["run", "/etc/alloy/config.alloy"], volumes: ["./docker/observability/alloy.alloy:/etc/alloy/config.alloy:ro", "/var/run/docker.sock:/var/run/docker.sock:ro"] }
  grafana: { image: grafana/grafana:12.1.0, ports: ["3000:3000"], environment: { GF_AUTH_ANONYMOUS_ENABLED: "true", GF_AUTH_ANONYMOUS_ORG_ROLE: Admin }, volumes: ["./docker/observability/grafana/provisioning:/etc/grafana/provisioning:ro", "./docker/observability/grafana/dashboards:/var/lib/grafana/dashboards:ro"], mem_limit: 512m }
```

(Pin to the latest patch versions available at implementation time; Dependabot keeps them current.)

- [ ] **Step 3: Grafana provisioning** — datasources `Prometheus` (`http://prometheus:9090`, uid `prometheus`) and `Loki` (`http://loki:3100`, uid `loki`); dashboard provider pointing at `/var/lib/grafana/dashboards`.

- [ ] **Step 4: Dashboards** — `services.json` panels (Prometheus uid `prometheus`):
  - RPS by service/route: `sum by (component, route) (rate(symfony_http_request[1m]))`
  - Error rate: `sum by (component) (rate(symfony_http_response{status=~"5.."}[1m]))`
  - p95 latency: `histogram_quantile(0.95, sum by (le, component, route) (rate(symfony_request_duration_histogram_seconds_bucket[5m])))`
  - Outbound HTTP gateway → orders: `sum by (host, status) (rate(symfony_http_connection_response[1m]))`
  - Doctrine queries: `sum by (component) (rate(symfony_doctrine_query_duration_histogram_seconds_count[1m]))` (verify the exact metric name with `curl orders:8080/_/metrics` and adjust).
  `profiling.json`: `histogram_quantile(0.95, sum by (le, component, message) (rate(symfony_profiling_span_duration_histogram_seconds_bucket[5m])))` and span count per message.
  Build them in Grafana UI, export JSON ("Export for sharing externally" off), commit.

- [ ] **Step 5: Verify** — `make up`; generate a few orders; `curl -s 'localhost:9090/api/v1/query?query=symfony_http_request'` has three components; Grafana `localhost:3000` shows data; Loki query `{service="orders"} | json | request_id != ""` returns lines (via Grafana Explore or `curl -G localhost:3100/loki/api/v1/query_range --data-urlencode 'query={service="orders"}'` from inside the network).

- [ ] **Step 6: Commit** — `git commit -m "Add Prometheus, Loki, Alloy and Grafana with provisioned dashboards"`.

---

### Task 7: Behat harness and order lifecycle

**Files:**
- Create: `e2e/composer.json`, `e2e/behat.yml`, `e2e/phpstan.dist.neon`, `e2e/src/Wait.php`, `e2e/src/Client/GatewayClient.php`, `e2e/src/Context/OrderContext.php`, `e2e/features/order_lifecycle.feature`
- Modify: `compose.yaml` (service `e2e`, profile `e2e`), `Makefile` (`e2e` target), `docker/php/Dockerfile` (target `e2e`)

**Interfaces:**
- Produces: `App\E2e\Wait::until(Closure(): bool $condition, float $timeoutSeconds, string $failureMessage): void`; `App\E2e\Client\GatewayClient::createOrder(int $amount, ?string $idempotencyKey = null, array<string,string> $headers = []): array{status:int, body: array<string,mixed>, headers: array<string, list<string>>}` and `showOrder(string $id): array{status:int, body: array<string,mixed>}`; shared state class `App\E2e\Context\Scenario` (last response, last order id, request id).

- [ ] **Step 1: e2e image and compose service**

Dockerfile target:

```dockerfile
FROM base AS e2e
RUN apt-get update && apt-get install -y --no-install-recommends docker-cli && rm -rf /var/lib/apt/lists/*
WORKDIR /e2e
COPY e2e/composer.json e2e/composer.lock ./
RUN composer install --no-interaction --prefer-dist
COPY e2e/ ./
ENTRYPOINT ["vendor/bin/behat"]
```

compose:

```yaml
  e2e:
    build: { context: ., dockerfile: docker/php/Dockerfile, target: e2e }
    profiles: [e2e]
    environment: { GATEWAY_URL: http://gateway:8080, PROMETHEUS_URL: http://prometheus:9090, LOKI_URL: http://loki:3100, RABBITMQ_URL: http://rabbitmq:15672, COMPOSE_PROJECT_NAME: bundles-showcase }
    volumes: ["/var/run/docker.sock:/var/run/docker.sock"]
```

Makefile: `e2e: ; docker compose run --rm --build e2e --tags='~@chaos' && docker compose run --rm e2e --tags='@chaos'`

`e2e/composer.json` requires `behat/behat` (latest 3.x or 4.x that installs), `symfony/http-client`, `webmozart/assert`; dev: phpstan stack; autoload `App\E2e\` → `src/`.

- [ ] **Step 2: Feature first (it is the failing test)**

```gherkin
Feature: Order lifecycle
  An order is created through the gateway, paid by billing asynchronously and its final
  status is visible through the gateway.

  Scenario: An affordable order gets paid
    When I create an order for 5000
    Then the response status is 201
    And the order becomes "paid" within 15 seconds

  Scenario: An order above the billing limit is declined
    When I create an order for 150000
    Then the order becomes "declined" within 15 seconds

  Scenario: An invalid amount is rejected
    When I create an order for 0
    Then the response status is 422

  Scenario: An unknown order is not found
    When I look up the order "0190e0f6-0000-7000-8000-00000000ffff"
    Then the response status is 404
```

Run: `make e2e` → FAIL (undefined steps).

- [ ] **Step 3: Implementation**

```php
<?php // e2e/src/Wait.php
declare(strict_types=1);
namespace App\E2e;

use Closure;
use RuntimeException;

final class Wait
{
    /**
     * @param Closure(): bool $condition
     */
    public static function until(Closure $condition, float $timeoutSeconds, string $failureMessage): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            if ($condition()) {
                return;
            }
            usleep(250_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException($failureMessage);
    }
}
```

`GatewayClient` wraps `HttpClientInterface` (`GATEWAY_URL`), never throws on 4xx/5xx (`$response->getContent(false)`), decodes JSON into `array<string,mixed>`.

`OrderContext` (attribute steps `#[When('I create an order for :amount')]`, `#[Then('the response status is :status')]`, `#[Then('the order becomes :status within :seconds seconds')]`, `#[When('I look up the order :id')]`) using `Scenario` state and `Wait::until` polling `showOrder`.

- [ ] **Step 4: Run** — `make up && make e2e` → PASS (4 scenarios).

- [ ] **Step 5: Commit** — `git commit -m "Add Behat e2e harness and order lifecycle scenarios"`.

---

### Task 8: Idempotency and tracing features

**Files:**
- Create: `e2e/features/idempotency.feature`, `e2e/features/tracing.feature`, `e2e/src/Client/LokiClient.php`, `e2e/src/Context/TracingContext.php`, `e2e/src/Context/IdempotencyContext.php`

**Interfaces:**
- Consumes: `GatewayClient`, `Wait`, `Scenario` (Task 7).
- Produces: `LokiClient::linesFor(string $requestId, float $sinceUnix): list<array{service:string, extra: array<string,mixed>, message:string}>` (query `{service=~".+"} | json | extra_request_id="<id>"`, falling back to a line filter `|= "<id>"` then decoding JSON lines).

- [ ] **Step 1: Features**

```gherkin
Feature: Idempotent order creation
  Scenario: Repeating a request with the same key returns the same order
    When I create an order for 5000 with idempotency key "showcase-key-1"
    And I create an order for 5000 with idempotency key "showcase-key-1"
    Then both responses carry the same order id

  Scenario: Concurrent duplicates still create one order
    When I send 5 concurrent order requests for 5000 with idempotency key "showcase-key-2"
    Then all responses carry the same order id
```

```gherkin
Feature: One trace across HTTP and RabbitMQ
  Scenario: The client's request id follows the order through every process
    When I create an order for 5000 with request id "trace-showcase-1"
    Then the response header "request-id" is "trace-showcase-1"
    And the order becomes "paid" within 15 seconds
    And within 15 seconds logs with request id "trace-showcase-1" come from "gateway", "orders", "billing-worker" and "orders-worker"
    And the "orders" logs for request id "trace-showcase-1" have request from "showcase:gateway"

  Scenario: A request without a request id gets one
    When I create an order for 5000
    Then the response has a non-empty "request-id" header
```

- [ ] **Step 2: Run** → FAIL (undefined steps). **Step 3:** implement `IdempotencyContext` (concurrent requests via `HttpClientInterface::request` ×5 then reading all responses — Symfony HttpClient multiplexes them), `TracingContext` + `LokiClient`. Before writing the Loki matcher, inspect one real log line: `docker compose logs orders | head -5` — logger-bundle's JSON puts tracing values under `extra` (`extra.request_id`, `extra.request_from`, `extra.runtime_id`); pin field paths accordingly.

- [ ] **Step 4: Run** — `make e2e` → PASS. If the concurrent scenario fails, apply the Redis `SET NX` fallback from Task 5 and add the unit test for it.

- [ ] **Step 5: Commit** — `git commit -m "Cover idempotency and cross-service tracing in e2e"`.

---

### Task 9: Worker reset and metrics features

**Files:**
- Create: `e2e/features/worker_reset.feature`, `e2e/features/metrics.feature`, `e2e/src/Client/PrometheusClient.php`, `e2e/src/Context/WorkerResetContext.php`, `e2e/src/Context/MetricsContext.php`

**Interfaces:**
- Produces: `PrometheusClient::value(string $promQl): float` (instant query, sum of the result vector, 0.0 when empty).

- [ ] **Step 1: Features**

```gherkin
Feature: Long-running workers do not leak state
  Scenario: Every request on reused RoadRunner workers gets its own runtime id
    When I send 6 order lookups with request ids "reset-1" to "reset-6"
    Then within 15 seconds the gateway logs show 6 distinct runtime ids for those request ids
    And no gateway log line for "reset-2" carries request id "reset-1"

  Scenario: Every consumed message gets its own trace
    When I create 3 orders with request ids "msg-1", "msg-2" and "msg-3"
    Then within 15 seconds each "billing-worker" log line for "msg-2" carries request id "msg-2" only
```

```gherkin
Feature: Metrics from every service reach Prometheus
  Scenario: A paid order is visible in metrics
    Given I remember the current metric values
    When I create an order for 5000
    And the order becomes "paid" within 15 seconds
    Then within 20 seconds "symfony_http_request{component=\"gateway\",route=\"gateway_create_order\"}" grows by 1
    And within 20 seconds "symfony_http_connection_request{component=\"gateway\",host=\"orders\"}" grows by 1
    And within 20 seconds "symfony_profiling_span_duration_histogram_seconds_count{component=\"orders\",message=\"request_orders_create\"}" grows by 1
    And within 20 seconds the Doctrine query count of "orders" grows by at least 1
    And within 20 seconds "symfony_profiling_span_duration_histogram_seconds_count{component=\"billing\"}" grows by at least 1
```

(The Doctrine step reads the metrics-bundle DBAL query counter for `component="orders"`; pin its exact name from a live `curl orders:8080/_/metrics` in `MetricsContext`. The worker metric line proves `billing-worker` metrics surface through `billing`'s `/_/metrics`; the outbound line proves the HTTP client is counted exactly once — "grows by 1", not 2.)

- [ ] **Step 2–4:** Run → FAIL; implement `PrometheusClient`, contexts (remember values keyed by query; "grows by N" polls `value()` until `after - before == N` or timeout; on timeout report before/after); run → PASS. Check metric names against a live `/_/metrics` before pinning queries.

- [ ] **Step 5: Commit** — `git commit -m "Cover worker state reset and Prometheus metrics in e2e"`.

---

### Task 10: Health and chaos features

**Files:**
- Create: `e2e/features/health.feature`, `e2e/src/Client/DockerClient.php`, `e2e/src/Context/HealthContext.php`

**Interfaces:**
- Produces: `DockerClient::stop(string $service): void`, `start(string $service): void` (shells out to `docker compose -p $COMPOSE_PROJECT_NAME stop|start <service>` via `Symfony\Component\Process\Process`; add `symfony/process` to e2e).

- [ ] **Step 1: Features**

```gherkin
Feature: Readiness reflects real dependencies

  Scenario Outline: Every service is ready with its dependencies checked
    When I ask "<service>" for readiness
    Then readiness is up
    And readiness mentions <dependencies>

    Examples:
      | service | dependencies                   |
      | gateway | "redis"                        |
      | orders  | "redis", "doctrine", "amqp"    |
      | billing | "redis", "doctrine", "amqp"    |

  @chaos
  Scenario: Losing Redis makes services not ready but still alive
    When I stop "redis"
    Then within 20 seconds "orders" readiness is down
    And "orders" liveliness is up
    When I start "redis"
    Then within 30 seconds "orders" readiness is up

  @chaos
  Scenario: Gateway survives orders being down
    When I stop "orders"
    And I create an order for 5000
    Then the response status is 502
    And within 10 seconds "gateway" readiness is up
    When I start "orders"
    Then within 30 seconds "orders" readiness is up
```

Readiness is read as JSON: `GET http://<service>:8080/_/healthcheck/readiness?_format=json` → `{"success": bool, "errors": [...], "messages": [...]}`, HTTP 406 when down. Before pinning the "mentions" words, print one real JSON response per service and use substrings that actually occur in `messages` (checker names are generated from service ids).

- [ ] **Step 2–4:** Run → FAIL; implement; run `make e2e` → PASS (chaos runs last, separately).

- [ ] **Step 5: Commit** — `git commit -m "Cover readiness and dependency outages in e2e"`.

---

### Task 11: CI, Dependabot, README showcase, demo traffic, knowledge base

**Files:**
- Create: `.github/workflows/e2e.yml`, `.github/dependabot.yml`, `README.md`, `bin/demo-traffic` (PHP script), `.claude/docs/{README.md,architecture.md,known-issues.md}`
- Modify: `Makefile` (`demo-traffic`)

- [ ] **Step 1: `.github/workflows/e2e.yml`**

```yaml
name: e2e
on:
  push: { branches: [main] }
  pull_request:
concurrency: { group: e2e-${{ github.ref }}, cancel-in-progress: true }
jobs:
  e2e:
    runs-on: ubuntu-latest
    timeout-minutes: 30
    steps:
      - uses: actions/checkout@v5
      - run: docker compose up --build --wait --wait-timeout 300
      - run: docker compose run --rm --build e2e --tags='~@chaos' --format=progress
      - run: docker compose run --rm e2e --tags='@chaos' --format=progress
      - if: failure()
        run: docker compose logs --no-color > compose-logs.txt
      - if: failure()
        uses: actions/upload-artifact@v6
        with: { name: compose-logs, path: compose-logs.txt, retention-days: 14 }
```

- [ ] **Step 2: `.github/dependabot.yml`**

```yaml
version: 2
updates:
  - package-ecosystem: composer
    directories: ["/apps/gateway", "/apps/orders", "/apps/billing", "/e2e", "/"]
    schedule: { interval: daily }
    groups:
      msstc4symfony: { patterns: ["msstc4symfony/*"] }
      symfony: { patterns: ["symfony/*"] }
  - package-ecosystem: docker
    directories: ["/docker/php"]
    schedule: { interval: weekly }
  - package-ecosystem: docker-compose
    directories: ["/"]
    schedule: { interval: weekly }
  - package-ecosystem: github-actions
    directory: "/"
    schedule: { interval: weekly }
```

After merging, confirm in the repository's "Dependabot" tab that composer updates resolve the `vcs` repositories (spec risk 4). If Dependabot cannot see the tags, add `.github/workflows/bundle-updates.yml` (daily `composer update 'msstc4symfony/*'` in each app + `peter-evans/create-pull-request`).

- [ ] **Step 3: `bin/demo-traffic`** — PHP CLI script (runs in the e2e image: `docker compose run --rm --entrypoint php e2e /e2e/../bin/demo-traffic` — or copy into e2e image) creating ~60 orders over 60 s with mixed amounts (≈20 % above the limit), random `Idempotency-Key` repeats, a few invalid payloads; prints a sample `request-id` to search in Loki. `make demo-traffic` wraps it.

- [ ] **Step 4: README** — sections: what this is (stand + showcase + release gate), architecture diagram from the spec, `make up` / `make demo-traffic` / links (`localhost:8080`, Grafana `localhost:3000`, Prometheus `localhost:9090`, RabbitMQ `localhost:15672` guest/guest), "follow one request" walkthrough with the Loki query `{service=~".+"} | json | extra_request_id="<id>"`, what each bundle contributes (one line each with a link), how releases flow (Dependabot PR → e2e → merge; majors via rc tags — spec C9), `make e2e`, `make check`.

- [ ] **Step 5: Knowledge base** — `.claude/docs/architecture.md` (services, transports, Redis DB map, ports, why stderr + raw logs), `known-issues.md` (every surprise met during Tasks 1–10, e.g. RoadRunner/baldinof notes, metric names, Loki field paths), `README.md` index.

- [ ] **Step 6: Verify DoD** (spec §9)
  1. Fresh clone: `docker compose up --build --wait` → all healthy.
  2. `make e2e` → green including `@chaos`.
  3. Push → `quality.yml` and `e2e.yml` green.
  4. `make demo-traffic` → both dashboards show data; Loki query by the printed `request-id` returns lines from gateway, orders, billing-worker, orders-worker.
  5. Dependabot opened at least one PR and `e2e.yml` ran on it.

- [ ] **Step 7: Commit and push** — `git commit -m "Add e2e CI, Dependabot, demo traffic and README showcase"` then `git push`.

---

## Notes for the executor

- Bundle versions: `composer show 'msstc4symfony/*'` in each app must list released tags only.
- Any discovered bundle bug is fixed **in the bundle repository** (with a test, released as a patch tag), never patched in the showcase; record it in `.claude/docs/known-issues.md` here and in the bundle's own docs.
