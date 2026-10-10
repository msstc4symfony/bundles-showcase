# Архитектура полигона

## Сервисы и процессы

| Сервис compose | Образ | Процесс | Назначение |
|---|---|---|---|
| `gateway` | `app` (APP=gateway) | RoadRunner, 2 воркера | прокси в orders + идемпотентность `POST /orders` |
| `orders` | `app` (APP=orders) | RoadRunner | создание заказа, `GET /orders/{id}` |
| `orders-worker` | `app` (APP=orders) | `messenger:consume payment_processed` | применяет результат оплаты |
| `orders-migrate` | `app` (APP=orders) | one-shot `doctrine:migrations:migrate` | схема до старта `orders` |
| `orders-search-index` | `app` (APP=orders) | one-shot `app:search:create-index` | индекс `orders` с маппингом до старта `orders` |
| `elasticsearch` | `elasticsearch:8.19.22` | single-node, без security | поиск заказов (только orders) |
| `billing` | `app` (APP=billing) | RoadRunner (только `/_/metrics`, `/_/healthcheck/*`) | — |
| `billing-worker` | `app` (APP=billing) | `messenger:consume process_payment` | платёж + ответ `PaymentProcessed` |
| `billing-migrate` | `app` (APP=billing) | one-shot миграции | — |
| `e2e` (profile `e2e`) | `e2e` | Behat 4 (`behat.php`) | сценарии; docker CLI для `@chaos` |

Три приложения независимы (свой `composer.json/lock`, свой Kernel), общий только `docker/php/Dockerfile`.

## Транспорты и сообщения

- `ProcessPayment(orderId, amount)`: orders → RabbitMQ `process_payment` → billing-worker.
- `PaymentProcessed(orderId, approved)`: billing → `payment_processed` → orders-worker.
- Классы сообщений дублируются в обоих приложениях с одинаковым FQCN (`App\Message\…`), сериализатор — `symfony_serializer` (JSON).
- orders в `wrapInTransaction` делает `flush()` и только потом `dispatch(ProcessPayment)`: ошибка БД не публикует
  сообщение, упавший AMQP откатывает заказ. Публикация всё равно раньше COMMIT → `PaymentProcessed` может обогнать
  коммит: handler бросает `OrderNotVisibleYet`, транспорт `payment_processed` ретраит 5× (500 мс ×2, ~15 с).
  Исчерпавшие ретраи сообщения уходят в failure transport `failed` (Doctrine, таблица `messenger_messages`,
  `FAILED_TRANSPORT_DSN`; в тестах in-memory) — `messenger:failed:show|retry`. Outbox не сделан.
- `amount` ограничен `App\Application\Order\Create\Input::MAX_AMOUNT` (INT4 Postgres) → 422, а не 500.
- Создание заказа — `App\Application\Order\Create\Action` (logic-bundle): контроллер получает его как
  `ActionInterface` через `#[Autowire(service: Action::class)]` — под этим id лежит `ActionPipeline` (логирование +
  валидация `Input`), а не сам класс. Ответ 422 по-прежнему даёт `MapRequestPayload`, middleware валидации — второй
  рубеж для вызовов не из HTTP. Lock не используется (идемпотентность — в gateway). `OutputLogBuilder` кладёт
  `order_id/status/amount` в запись `Finish application action`.
- Сервисы и воркеры с `restart: unless-stopped`: `messenger:consume --time-limit=3600` штатно выходит раз в час.
- Повторная доставка: billing отвечает сохранённым исходом (уникальность по `order_id`), orders не переводит уже `paid/declined` заказ.

## Elasticsearch (orders)

- FOSElasticaBundle ^7.2 + `ruflin/elastica` ^8.0 (явно, чтобы не взять Elastica 9). Клиент `fos_elastica.client.default`
  (`ELASTICSEARCH_URL`), индекс `orders`: `id`/`status` keyword, `amount` scaled_float (×100), `createdAt` date.
- Порт `App\Search\OrderIndex`, адаптер `ElasticaOrderIndex` (сервис `fos_elastica.index.orders`). Action создания заказа
  индексирует **после** коммита транзакции; любой `Throwable` логируется (`Order … was not indexed`) и не ломает 201.
- Семантика снимка: документ — заказ на момент создания, `PaymentProcessed` индекс не обновляет; документ, чья
  индексация упала, потерян — команды переиндексации (populate) нет. Doctrine-listener FOS не используется.
- Индекс создаёт one-shot `orders-search-index`: `app:search:create-index` запускает публичную `fos:elastica:create
  --index=orders` через консольное приложение, только если индекса нет (`create` падает на существующем, `reset`
  удаляет документы).
- В test-окружении `OrderIndex` = `App\Tests\Support\RecordingOrderIndex` (`when@test`); `ElasticaOrderIndex` там
  публичен только для `ElasticsearchWiringTest`, который проверяет продовую сборку и что ни загрузка ядра, ни сборка
  индекса/чекера readiness не ходят в Elasticsearch (локальный сокет без ответа).
- Readiness: чекер `healthcheck.checker.fos_elastica.client.default` в `non_critical`
  (`config/packages/msstc4symfony_healthcheck.yaml`) — в отличие от Redis, Elasticsearch не нужен, чтобы создавать и
  отдавать заказы. При отказе readiness остаётся up, в `warnings` строка `Elastica connection (…) failed (<причина>)`.
- Пул узлов: приложение переопределяет сервис FOS `FOS\ElasticaBundle\Elastica\NodePool\RoundRobinResurrect`
  (`SimpleNodePool(RoundRobin, App\Search\BoundedPingResurrect)`) — связка с внутренним id FOS; переименование в FOS
  ловит `ElasticsearchWiringTest` в `make check`.

## Redis

| DB | gateway | orders | billing |
|---|---|---|---|
| кеш (`REDIS_CACHE_DSN`) | 0 | 2 | 4 |
| метрики (`METRICS_STORAGE_DSN`) | 1 | 3 | 5 |
| lock (`LOCK_DSN`) | 0 | — | — |

HTTP-процесс и воркер одного приложения пишут метрики в одну DB → `/_/metrics` сервиса показывает и метрики воркера.

## Идемпотентность gateway

`IdempotencyStore::remember()` = `symfony/lock` (Redis) вокруг `cache.app->get()`. Lock нужен, т.к. stampede-lock кеша
работает только внутри процесса, а воркеров RoadRunner два. Хранится `{status, body}` ответа orders; 5xx/недоступность
orders → `OrdersUnavailable` → 502 и **не** запоминается.

## Ответы gateway

- Ключ идемпотентности хранит отпечаток тела (sha256): тот же ключ с другим телом → 422 `idempotency_key_reused`.
- Redis/lock недоступен → 503 `idempotency_unavailable` (POST без ключа идёт мимо хранилища).
- `JsonApiListener` ставит формат запроса `json` для всего, кроме `/_/…`: ошибки роутера (404/405) — problem JSON;
  служебные маршруты healthcheck/metrics сохраняют свой текстовый формат.
- orders принимает только JSON (`acceptFormat: 'json'`): form → 415, битый JSON → 400, скаляр/массив/null → 422.

## Образ

Multi-stage: `runtime` (расширения через install-php-extensions с закреплёнными redis-6.3.0 / amqp-2.2.0, build-зависимости
удаляются), `tools` (+composer, git, unzip), `build` (composer install + cache:warmup), `app` (копия из build, `USER www-data`),
`e2e` (от `tools`, root — нужен docker socket).

## Логи

Monolog → `php://stderr` (stdout у RoadRunner — канал протокола воркера), RoadRunner `logs.mode: raw`. Формат —
logger-bundle `SwitchFormatter` → JSON одной строкой. Alloy читает docker-логи **только** контейнеров проекта
(`com.docker.compose.project=bundles-showcase`), метка `service` = имя compose-сервиса. В LogQL поля `extra`
плющатся: `extra_request_id`, `extra_runtime_id`, `extra_request_from`.

## Порты

Все опубликованные порты на `127.0.0.1`, переопределяются `SHOWCASE_*_PORT` (дефолты 8080/3000/9090/15672/5432).
Loki не опубликован. Локально у владельца 8080 и 3000 заняты → `SHOWCASE_GATEWAY_PORT=18080`, `SHOWCASE_GRAFANA_PORT=13000`,
`SHOWCASE_PROMETHEUS_PORT=19090`, `SHOWCASE_RABBITMQ_PORT=25672`.

## Качество

- `make check`: на каждое приложение `cache:warmup --env=test`, test-БД + миграции, PHPStan (level 9, контейнер **test**),
  PHPUnit; затем PHPStan e2e, cs-fixer, Rector dry-run. Нужен Postgres на `127.0.0.1:5432`.
- CI: `quality.yml` (матрица приложений + e2e-static + style), `e2e.yml` (весь стек + Behat, `@chaos` отдельным прогоном).
- Версии бандлов — релизные теги из публичных GitHub-репозиториев (`vcs`), обновляет bundle-updates.
  Ограничение во всех приложениях — `^1.0`.
