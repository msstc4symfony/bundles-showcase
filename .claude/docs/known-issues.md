# Известные проблемы и грабли

Пополнять, не переписывать целиком. Баги бандлов чинятся в самих бандлах (тест + патч-релиз), здесь — только след.

## Найденные полигоном баги бандлов (все исправлены до v1.0.0)

- logger-bundle: `JsonFormatter` без `appendNewline` склеивал JSON-записи stream-хендлеров (CLI-воркеры) в одну
  строку `…}{…`, Loki видел мусор. Под RoadRunner маскировалось.
- metrics-bundle: DBAL-middleware не подключался (пасс не матчил `ChildDefinition` соединений DoctrineBundle, не было
  тега `doctrine.middleware`, порядок пассов зависел от порядка бандлов), запросы без параметров (`query/exec`) не
  считались → ноль Doctrine-метрик. `table="partitioned"` на системных запросах Postgres остаётся (регулярка берёт
  первый FROM).
- healthcheck-bundle: `LockStoreDetector` проверял предопределённые FrameworkBundle 8.1 `.lock.semaphore.store` /
  `.lock.flock.store`; `SemaphoreStore` без `ext-sysvsem` бросал в конструкторе при сборке списка чекеров →
  **liveliness 500** (readiness-зависимость ломала liveness). Теперь readiness-цели ленивые, берутся только
  `lock.store`-теги, креды в сообщениях проб маскируются, `?_format=json` отдаёт JSON (e2e читает readiness/liveliness
  как JSON, `HealthReport::fromResponse`).

## Окружение и инструменты

- Песочница Claude не пускает TCP на `127.0.0.1:5432` → `make check` и тесты приложений запускать вне песочницы.
- `cache:warmup` при сборке образа требует все env, на которые ссылается конфиг на этапе компиляции → дефолты
  `REDIS_CACHE_DSN` в закоммиченных `.env` (compose их переопределяет).
- Без `.dockerignore` `COPY apps/<app>/` затирал `--no-dev` vendor хостовым (с dev-зависимостями).
- Ограничения бандлов в приложениях — `^1.0`; `composer update 'msstc4symfony/*'` двигает lock в пределах 1.x.
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
  (`connection` — всегда имя соединения DoctrineBundle, `"default"`), `symfony_profiling_span_duration_histogram_seconds{message}`,
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
- metrics-bundle: маршрут `/_/metrics` грузится через `routing.controllers` (Symfony ≥ 7.4) — ручной
  `routes/metrics.yaml` не нужен. Метка `connection` — имя соединения DoctrineBundle (`connection="default"`).
- tracing-bundle: корень конфига `msstc4symfony_tracing`; W3C trace context (включая Messenger) включён всегда.
- healthcheck-bundle: системные кеш-пулы не пробуются; в тексте есть секция `Warnings:` в конце.

## Redis-рестарт в долгоживущих процессах (2026-10-02 UTC)

- phpredis 6.3: клиент, чья команда попала на простой Redis, остаётся FAILED («went away») навсегда — нужен
  явный `connect()`. Symfony свои Redis-соединения (cache, lock) не пересоздаёт. `App\Infrastructure\RedisConnectionGuard`
  (не в test-окружении) пингует `cache.default_redis_provider`: после HTTP-ответа при ошибке шлёт
  `ForceKernelRebootEvent` (RoadRunner пересоздаёт ядро), в Messenger-воркере останавливает воркер
  (compose `restart: unless-stopped` поднимает заново). Проверено chaos-сценарием (readiness и метрики оживают).
- metrics-bundle: circuit breaker хранилища (`metrics.storage.reconnect_backoff_seconds`, по умолчанию 5 с);
  `/_/metrics` → 503 при недоступном хранилище. Без backoff запросы во время простоя Redis тормозили до 6–14 с.
- Prometheus со статическими таргетами `gateway:8080` после пересоздания контейнеров скрейпил старые IP —
  под именем gateway отвечал billing. Теперь `docker_sd_configs` (через docker socket, `user: root`).
- Очередь метрик в e2e: снимок «до» берётся только после опустошения очередей RabbitMQ (management API) и
  свежего **успешного** скрейпа каждого instance — иначе асинхронные сообщения прошлых сценариев засчитываются как рост.
- PromQL-ловушка: `timestamp(up == 1)` возвращает время вычисления запроса, а не скрейпа (сравнение теряет исходные
  таймстемпы) → ожидание «свежего скрейпа» проходило мгновенно. Правильно: `timestamp(up) and up == 1`.

## Идемпотентность gateway: границы гарантий (2026-10-02 UTC)

- Маркер «в процессе» (TTL 60 с) защищает от повторного вызова orders: тот же ключ и тело → 409
  `idempotency_in_progress`, другое тело → 422. Если ответ не удалось сохранить (Redis отказал после вызова) или
  процесс упал во время вызова, ключ блокируется на 60 с, затем повтор снова вызовет orders.
- Инвариант таймаутов: `orders.client.max_duration` (10 с) < lock TTL (30 с) < TTL маркера (60 с). Нарушишь
  порядок — медленный ответ orders переживёт лок или маркер, и повтор создаст дубль.
- Ошибка вызова orders (включая таймаут) снимает маркер, и повтор разрешён. При таймауте заказ мог создаться в
  orders — у orders нет своей идемпотентности, поэтому дубль в этом случае возможен (осознанная граница showcase).
- `RedisConnectionGuard` пингует только соединение кеша: lock и кеш живут на одном Redis, поэтому ломаются
  вместе. Пока Redis лежит, RoadRunner перезагружает ядро после каждого запроса, а Messenger-воркер
  перезапускается — это ожидаемо.

## Инструменты: Rector и docker-proxy (2026-10-02 UTC)

- Rector нельзя запускать одним прогоном по всем приложениям: FQCN `App\...` совпадают, а сигнатуры разные
  (`RedisConnectionGuard` в gateway — 2 аргумента, в orders/billing — 3). Rector резолвил класс из чужого
  приложения и «удалял лишние» аргументы в тестах. `make rector` гоняет его по каждому проекту со своим
  `--autoload-file`; CI использует ту же цель.
- Prometheus/Alloy ходят в Docker через `tecnativa/docker-socket-proxy` (только GET containers и networks):
  `docker_sd` и `discovery.docker` без `NETWORKS=1` получают 403 и молча не находят ни одного таргета.

## Обновления бандлов: Dependabot не видит vcs-релизы (2026-10-02 UTC)

- Dependabot отработал после релиза tracing-bundle (run 2026-10-02T08:42Z, success), но PR не открыл —
  риск 4 спека подтвердился. Обновления бандлов делает `.github/workflows/bundle-updates.yml`.
- PR от `GITHUB_TOKEN` не запускает `pull_request`-workflow → `quality.yml`/`e2e.yml` получили `workflow_dispatch`,
  и bundle-updates сам запускает их на ветке.
- Создание PR требует настройки репозитория «Allow GitHub Actions to create and approve pull requests»
  (сейчас выключена: `Resource not accessible by integration`). Без неё ветка пушится и гейт гоняется, мёрж —
  вручную (fast-forward в main). Первый прогон: quality и e2e зелёные, влит в main.

## Showcase на линии бандлов с 1.0.0 (2026-10-04 UTC)

- История шести бандлов перезапущена: один «init commit» и тег `v1.0.0`; все приложения требуют `^1.0`, lock —
  `v1.0.0`. Dependabot/bundle-updates снова работают на `^1.0` (релизы 1.x подхватываются).
- `composer update` с SSH-настройкой GitHub пишет в lock `source.url` вида `git@github.com:…` — в lock оставлены
  `https://github.com/…`, иначе CI без SSH-ключа не сможет взять source. После обновления проверять `grep git@`.
- Корни конфигов: `msstc4symfony_logger`, `msstc4symfony_tracing`, `msstc4symfony_metrics`. Файлы
  `msstc4symfony_tracing.yaml` и `metrics.yaml` в приложениях не нужны — хватает дефолтов из env
  (`APPLICATION_NAME`, `COMPONENT_NAME`, `METRICS_STORAGE_DSN`). Неизвестный ключ конфига → ошибка сборки.
- Параметры контейнера: `msstc4symfony_metrics.application_name`.
- healthcheck: lock-проверка подписана `Lock store (lock.default)` (id `healthcheck.checker.lock.default`); текст —
  «Title:» и строки с `\t`, пустая секция — `Title: none`. e2e читает JSON, поэтому формат текста его не задевает.
- Span-метрика одна: `symfony_profiling_span_duration_histogram_seconds` (её регистрирует только metrics-bridge-profiling).

## FOSElasticaBundle + Elasticsearch в orders (2026-10-06 UTC)

- Бандлы видят FOS 7.2 без доработок: healthcheck v1.0.1 — чекер `healthcheck.checker.fos_elastica.client.default`
  (`Elastica connection (fos_elastica.client.default) passed (cluster status: yellow)`), metrics v1.1.0 — `TimingHttpClient`
  в `transport_config.http_client` клиента (ChildDefinition от `fos_elastica.client_prototype`, аргумент `$config`).
- Метрика индексации: `symfony_elastica_request_success{component="orders",method="PUT",path="orders/_doc/<id>"}` —
  Elastica 8 `Index::addDocument()` с id идёт через index API (PUT).
- **Открытая проблема metrics-bundle:** метка `path` содержит id документа → неограниченная кардинальность
  (новая серия на каждый заказ). Чинить в metrics-bundle (нормализация пути), здесь не патчится.
- elastic-transport по умолчанию (`SimpleNodePool` + `NoResurrect`) **никогда** не воскрешает узел, помеченный мёртвым:
  после рестарта Elasticsearch воркер RoadRunner навсегда отвечал бы `No alive nodes`. Поэтому
  `connection_strategy: RoundRobin` (пул FOS с пингом мёртвого узла).
- Сток `ElasticsearchResurrect` пингует клиентом без лимита времени, а у HTTP-клиента FOS idle timeout 30 с.
  После `docker stop` в долгоживущем процессе остаются keep-alive соединения и закешированный curl адрес мёртвого IP:
  readiness отвечал 3–38 с (`ElasticaConnectionChecker exceeded budget`), создание заказа ждало ~3 с. Исправлено
  в приложении: `client_options: { timeout: 2 }` (в FOS 7.2 собственный ключ `timeout` deprecated, опции HTTP-клиента —
  через `client_options`; для Symfony HttpClient `timeout` — idle timeout) и подмена пула FOS на
  `SimpleNodePool(RoundRobin, App\Search\BoundedPingResurrect)` (пинг ≤ 1 с, любой `Throwable` = узел мёртв).
  Восстановление после старта ~35 с.
- Живой, но медленный узел (ответ дольше 2 с idle timeout) помечается мёртвым, и на это окно запросы падают
  `No alive nodes`, пока пинг не вернёт его.
- Пока узел помечен мёртвым, запросы падают **без HTTP-вызова** → `symfony_elastica_request_failed` растёт только для
  первого неудачного запроса процесса. e2e это не проверяет.
- Сразу после рестарта Elasticsearch чекер проходит с `cluster status: red` (статус кластера не оценивается).
  Хаос-сценарий ждёт `green|yellow`, чтобы следующий прогон не унаследовал красный кластер.
- `max_duration: 1` на основном клиенте оказался слишком жёстким при load average ~50 на хосте: `HEAD /orders` в
  `app:search:create-index` не уложился → `make up` упал. Поэтому idle `timeout: 2`, а не `max_duration`.
- Отказ non-critical чекера в readiness — строка в `warnings`; проба может закончиться и `exceeded budget`, поэтому
  хаос-шаг опрашивает readiness до строки `Elastica connection (fos_elastica.client.default) failed (…)`.
- e2e: в шагах «grows by» плейсхолдер `{order}` заменяется id заказа, созданного в сценарии.
- Рецепт `friendsofsymfony/elastica-bundle` (recipes-contrib, версия рецепта 5.0) кладёт `url:` в конфиг клиента —
  в 7.x ключа нет (`hosts: [...]`), `cache:clear` после `composer require` падал до правки. Рецепт
  `php-http/discovery` (1.18) добавил `config/packages/http_discovery.yaml`.
