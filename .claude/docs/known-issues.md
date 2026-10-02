# Известные проблемы и грабли

Пополнять, не переписывать целиком. Баги бандлов чинятся в самих бандлах (тест + патч-релиз), здесь — только след.

## Найденные полигоном баги бандлов (все исправлены)

- **logger-bundle ≤1.1.1**: `JsonFormatter` без `appendNewline` — в stream-хендлерах (CLI-воркеры) JSON-записи
  склеивались в одну строку `…}{…`, Loki видел мусор. Под RoadRunner маскировалось. Исправлено в **v1.1.2**.
- **metrics-bundle ≤1.2.0**: DBAL-middleware не подключался вообще (пасс не матчил `ChildDefinition` соединений
  DoctrineBundle, не было тега `doctrine.middleware`, порядок пассов зависел от порядка бандлов) + запросы без
  параметров (`query/exec`) не считались → ноль Doctrine-метрик. Исправлено в **v1.2.1**. Правки ревью (ленивая
  регулярка таблицы — сейчас бывает `table="partitioned"` на системных запросах Postgres, fallback без DoctrineBundle)
  — коммит `be83f03` в metrics-bundle, релиз v1.2.2 ждёт push (2026-10-02 UTC).
- **healthcheck-bundle ≤1.1.1**: `LockStoreDetector` проверял предопределённые FrameworkBundle 8.1 `.lock.semaphore.store`
  / `.lock.flock.store`; `SemaphoreStore` без `ext-sysvsem` бросал в конструкторе при сборке списка чекеров →
  **liveliness 500** (readiness-зависимость ломала liveness). Исправлено в **v1.1.2** (ленивые readiness-цели +
  только `lock.store`-теги), маскирование кредов в сообщениях проб — **v1.1.3**.
- **healthcheck-bundle**: `?_format=json` игнорируется (контроллер берёт `_format` маршрута) → e2e парсит текст
  (`Result: up`, строки `... passed`). Кандидат этапа B. В текстовом выводе нет `warnings` non-critical чекеров.

## Окружение и инструменты

- Песочница Claude не пускает TCP на `127.0.0.1:5432` → `make check` и тесты приложений запускать вне песочницы.
- `cache:warmup` при сборке образа требует все env, на которые ссылается конфиг на этапе компиляции → дефолты
  `REDIS_CACHE_DSN` в закоммиченных `.env` (compose их переопределяет).
- Без `.dockerignore` `COPY apps/<app>/` затирал `--no-dev` vendor хостовым (с dev-зависимостями).
- Composer в приложениях бампит ограничения (`^1.1` → `^1.1.2`) при `composer update` — это нормально.
- PHPStan анализирует против **test**-контейнера: `container->get()` есть только в тестах, test-only сервисы
  (`OrdersStub`) в dev-контейнере отсутствуют.
- `make fix` = Rector, потом cs-fixer (Rector добавляет импорты, которые cs-fixer должен отсортировать).

## Symfony / RoadRunner

- Symfony 8 падает на Messenger routing к несуществующим классам — классы сообщений создаются вместе с конфигом.
- `messenger.transport.symfony_serializer` требует `symfony/serializer` + `property-access`.
- Рецепт `baldinof/roadrunner-bundle` лежит в recipes-contrib → `allow-contrib` + `recipes:install`.
- `services_resetter` очищает `ArrayAdapter` между запросами (и в `KernelBrowser` без reboot) → в тестах gateway
  `cache.app` = filesystem; в проде Redis reset не трогает.
- Приватный scoped `orders.client` нельзя подменить в тестах → `framework.http_client.mock_response_factory` (`OrdersStub`).
- Без `format: 'json'` на маршрутах orders ошибки валидации рендерились HTML (gateway выдавал их как JSON).
- phpstan-doctrine запрещает `positive-int` на `int`-колонке → инвариант `amount > 0` проверяется в конструкторе `Order`.

## Observability

- Alloy без фильтра по compose-проекту тащил логи **всех** контейнеров хоста и бэкфилл старых строк
  (Loki: `entry too far behind`).
- В Loki поле request id — `extra_request_id` (не `request_id`).
- Метрики: `symfony_http_request`, `symfony_http_response{status}`, `symfony_request_duration_histogram_seconds`,
  `symfony_http_connection_request/response{host,method,path,status}`, `symfony_doctrine_query_execute{connection,type,table}`
  (`connection` = `host:dbname`, не имя соединения — этап B), `symfony_profiling_span_duration_histogram_seconds{message}`,
  `symfony_error{level}`. У billing HTTP-метрик маршрутов нет (только служебные маршруты).

## e2e (Behat 4)

- Behat 4 читает только `behat.php`; YAML-конфиг не поддерживается.
- Без `--no-snippets --strict` неопределённый шаг переводит Behat в интерактивный выбор контекста → прогон висит.
  С `--strict` прогон без сценариев (`--tags=@chaos` до появления chaos) — exit 1.
- `\"` внутри кавычек параметра шага не матчится → PromQL в фичах с одинарными кавычками.
- Ключи идемпотентности и request id в фичах получают суффикс прогона (`RunScoped`) — иначе повторный прогон за 24 ч
  проходит concurrent-сценарий тривиально.
- «Метрика выросла на N»: перед снимком ждём свежий scrape всех таргетов, иначе в рост попадает трафик прошлых сценариев.
- Исходящий HTTP gateway → orders считать с `method='POST'`: шаг «становится paid» опрашивает GET через gateway.
- В e2e-образе только docker CLI без compose-плагина → `DockerClient` ищет контейнеры по compose-меткам.

## Решения по ревью этапа C (2026-10-02 UTC)

- `?int $amount` вместо `mixed` + `Type('integer')` **хуже**: денормализатор приводит 12.5 к 12 (deprecation
  «Implicit conversion from float»). Оставлен `mixed` + `Type('integer')`.
- Без `acceptFormat: 'json'` MapRequestPayload принимал form-данные (`amount=5000` → 201).
- `JsonApiListener` на все пути ломал текстовый healthcheck → исключение для `/_/`.
- PHPStan не видит by-ref присваивание в замыкании → ошибка `$create` проносится через `@internal CreationFailed`.
- PHPUnit 13.4 объявил `executionOrder="depends"` и старую схему deprecated → `failOnPhpunitDeprecation` ронял
  minimal-джоб бандлов; bundle-standard v1.7.2 (`resolveDependencies="true"`), все бандлы переведены.

## Этап B в showcase (2026-10-02 UTC)

- `Lock` с `autoRelease: true` повторяет неудавшийся `release()` из деструктора — вне любого catch, фатал при GC
  (в тестах всплывал в чужом тесте). `IdempotencyStore` создаёт lock с `autoRelease: false` и сам освобождает его.
- Кеш-адаптеры Symfony глотают ошибки хранилища (чтение → промах, `save()` → false). Поэтому `IdempotencyStore`
  пишет маркер до необратимого вызова и даёт 503, если `save()` вернул false.
- Rector в `make fix` удаляет «пустые» методы реализаций интерфейсов в тестовых даблах → методы должны иметь тело
  и `#[Override]`.
- RabbitMQ `.erlang.cookie: eacces` после прерванного `down -v` (остался старый том) → пересоздать тома.
  Образы инфраструктуры закреплены точными версиями (rabbitmq 4.3.6-management, redis 7.4.11, postgres 17.11).
- metrics-bundle 1.3: маршрут `/_/metrics` грузится через `routing.controllers` (Symfony ≥ 7.4) — ручной
  `routes/metrics.yaml` удалён. `metrics.doctrine.connection_label: name` → `connection="default"`.
- tracing-bundle 1.1: корень конфига `msstc4symfony_tracing`; `w3c_trace_context.messenger: true` безопасно только
  когда все консьюмеры на ≥ 1.1 (в showcase — да).
- healthcheck-bundle 1.2: системные кеш-пулы больше не пробуются; в тексте появилась секция `Warnings:` в конце.

## Redis-рестарт в долгоживущих процессах (2026-10-02 UTC)

- phpredis 6.3: клиент, чья команда попала на простой Redis, остаётся FAILED («went away») навсегда — нужен
  явный `connect()`. Symfony свои Redis-соединения (cache, lock) не пересоздаёт. `App\Infrastructure\RedisConnectionGuard`
  (не в test-окружении) пингует `cache.default_redis_provider`: после HTTP-ответа при ошибке шлёт
  `ForceKernelRebootEvent` (RoadRunner пересоздаёт ядро), в Messenger-воркере останавливает воркер
  (compose `restart: unless-stopped` поднимает заново). Проверено chaos-сценарием (readiness и метрики оживают).
- metrics-bundle 1.3.0 «восстанавливался» только побочно: 500 на `/_/metrics` при скрейпе перезагружал ядро.
  1.3.1–1.3.2 пропускать: без backoff запросы во время простоя Redis тормозили до 6–14 с. 1.3.3: circuit breaker
  (`metrics.storage.reconnect_backoff_seconds`, по умолчанию 5 с), `/_/metrics` → 503 при недоступном хранилище.
- Prometheus со статическими таргетами `gateway:8080` после пересоздания контейнеров скрейпил старые IP —
  под именем gateway отвечал billing. Теперь `docker_sd_configs` (через docker socket, `user: root`).
- Очередь метрик в e2e: снимок «до» берётся только после опустошения очередей RabbitMQ (management API) и
  свежего **успешного** скрейпа каждого instance — иначе асинхронные сообщения прошлых сценариев засчитываются как рост.
- PromQL-ловушка: `timestamp(up == 1)` возвращает время вычисления запроса, а не скрейпа (сравнение теряет исходные
  таймстемпы) → ожидание «свежего скрейпа» проходило мгновенно. Правильно: `timestamp(up) and up == 1`.
