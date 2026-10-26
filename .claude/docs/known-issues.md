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
- Ограничения бандлов в приложениях — нижние границы минора, от которого приложение зависит (см. architecture.md); `composer update 'msstc4symfony/*'` двигает lock в пределах мажора.
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
- В Loki trace id — `trace_id` на верхнем уровне записи (с 2026-10-23 UTC, `json_top_level_extra` logger-bundle 2.1); `runtime_id`/`caller` остались в `extra_*`. С tracing 2.0 request id нет: e2e ищет по trace id своего `traceparent` (случайный на сценарий).
- Логи Action в e2e и в `LogicBridgesTest` опознаются по тексту `application action` из `LoggingMiddleware`
  logic-bundle (констант для текста нет); если logic-bundle поменяет сообщения, оба теста упадут явно.
- Метрики: `symfony_http_request`, `symfony_http_response{status}`, `symfony_request_duration_histogram_seconds`,
  `symfony_http_connection_request/response{host,method,path,status}`, `symfony_doctrine_query_execute{connection,type,table}`
  (`connection` — всегда имя соединения DoctrineBundle, `"default"`), `symfony_profiling_span_duration_histogram_seconds{message}`,
  `symfony_error{level}`. У billing HTTP-метрик маршрутов нет (только служебные маршруты).

## e2e (Behat 4)

- Behat 4 читает только `behat.php`; YAML-конфиг не поддерживается.
- Без `--no-snippets --strict` неопределённый шаг переводит Behat в интерактивный выбор контекста → прогон висит.
  С `--strict` прогон без сценариев (`--tags=@chaos` до появления chaos) — exit 1.
- `\"` внутри кавычек параметра шага не матчится → PromQL в фичах с одинарными кавычками.
- Ключи идемпотентности в фичах получают суффикс прогона (`RunScoped`) — иначе повторный прогон за 24 ч
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
- `RedisConnectionGuard` gateway проверяет соединение кеша и lock store: они на одном Redis, но у lock своё
  соединение, и оно может остаться сломанным, пока кешевое живо (см. ниже, 2026-10-25). Пока Redis лежит, RoadRunner перезагружает ядро после каждого запроса, а Messenger-воркер
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
- PR из Actions в организации запрещён выше уровня репозитория (`Resource not accessible by integration`, хотя
  в репо `can_approve_pull_request_reviews: true`; 2026-10-04..06 ночные прогоны падали на create-pull-request).
  С 2026-10-07 UTC (решение владельца, вариант «без PR»): bundle-updates коммитит в ветку `bundle-updates`,
  вызывает `quality.yml`/`e2e.yml` через `workflow_call` с входом `ref` = SHA коммита, при обоих зелёных делает
  обычный (не force) push SHA в `main` — GitHub отклонит его, если main ушёл вперёд — и удаляет ветку. Иначе ветка
  остаётся, job `report` пишет сводку в summary и валит прогон (issues в репозитории выключены). Push от `GITHUB_TOKEN` не запускает
  push-workflow на main — это ожидаемо, гейт уже прошёл на том же SHA. Concurrency в quality/e2e — по
  `inputs.ref || github.ref`, чтобы вызов из bundle-updates не делил группу с прогоном main.

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
- Метрика индексации: `symfony_elastica_request_success{component="orders",method="PUT",path="orders/_doc/:id"}` —
  Elastica 8 `Index::addDocument()` с id идёт через index API (PUT); с metrics 1.2.0 id в метке заменён на `:id`.
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

## metrics 1.2.0: `:id` в метке path Elastica (2026-10-07 UTC)

- Первый прогон нового bundle-updates (run 37574199163) упал на e2e: сценарий ждал
  `path='orders/_doc/<id заказа>'`, а metrics 1.2.0 пишет `orders/_doc/:id`. Гейт сработал как задумано — main
  не тронут. Изменение лейбла бандла требует правки e2e в том же коммите, что и обновление lock: правка на main
  отдельно сломала бы e2e на старой версии. Порядок: cherry-pick коммита с ветки `bundle-updates` + правка e2e →
  push в ветку → `gh workflow run quality.yml/e2e.yml --ref bundle-updates` → fast-forward main.


## logic-bundle в orders (2026-10-10 UTC)

- `symfony/lock` приходит зависимостью logic-bundle, его Flex-рецепт кладёт `config/packages/lock.yaml` и
  `LOCK_DSN=flock` — в orders удалены: lockable Actions нет, а flock в нескольких репликах ничего не защищает.
  Если появится `#[LockableAction]` — `framework.lock` на Redis, как в gateway.
- 500 при создании заказа логируется дважды: `Fail application action …` (ERROR, LoggingMiddleware) и CRITICAL
  от `ErrorListener` Symfony. Цена логирования на уровне Action (вне HTTP второго лога нет).
- `Input` валидируется дважды: `MapRequestPayload` (даёт 422) и ValidationMiddleware бандла (для вызовов не из HTTP;
  его `ValidationFailedException` без маппинга — 500). Оставлено намеренно (§8 спеки: работают Logging и Validation).
- `Action` пока зависит от `EntityManagerInterface`/`MessageBusInterface`/`App\Entity\Order` напрямую — перенос 1:1
  из контроллера; порты (репозиторий заказов, запрос оплаты) — отдельной итерацией. `OrderView` остался в `App\Order`.
- Бриджи logic (2026-10-12 UTC): бандлы в `bundles.php` прописал Flex auto-generated recipe — руками не трогать.
  `CreateOrderActionWiringTest` проверяет порядок middleware через приватное `ActionPipeline::$middlewares`
  (публичного API порядка нет) — при падении после обновления logic-bundle сначала проверить имя свойства.
  `LogicBridgesTest` ищет лейблы метрики по имени через `$family->getLabelNames()` (`Sample::getLabelNames()`
  у `_count` пуст). e2e фильтрует span-метрику по `message`, поэтому новые спаны `action …` сценарии не задевают.

## bundle-updates: merge не проходил (исправлено 2026-10-10 UTC)

- С 2026-10-07 каждый `merge` падал `! [rejected] HEAD -> main (fetch first)`: checkout с `ref: <sha>` неглубокий,
  коммита `main` локально нет, и git отказывает до проверки fast-forward. Фикс — `fetch-depth: 0` в merge-джобе.

## PHP 8.5, Postgres 18, Elasticsearch 9 (2026-10-13 UTC)

- Образ приложений `php:8.5.10-cli`; quality-CI и локальный `make check` по-прежнему на PHP 8.4 (lock-файлы
  резолвятся под 8.4, рантайм 8.5 — e2e подтверждает совместимость).
- Postgres 18: `serverVersion=18` в `DATABASE_URL` (compose, `.env`, `.env.test`) и `postgres:18` в quality.yml.
  Тома данных у сервиса нет, поэтому перенос `PGDATA` в образе 18 (`/var/lib/postgresql/18/docker`) не мешает;
  если появится том — монтировать `/var/lib/postgresql`, а не `.../data`.
- Elasticsearch 9.5.5 работает с клиентом `ruflin/elastica` ^8: создание индекса, индексация и поиск заказов проходят e2e.

## baggage.allowed_hosts в gateway (2026-10-21 UTC)

- `allowed_hosts: ['%env(string:key:host:url:ORDERS_URL)%']` — `string:` обязателен: у `key:` тип результата
  `bool|int|float|string|array`, и `stringPrototype()` бандла валит сборку (`Expected "string", but got one of …`).
- Пробы `/_probe/baggage` (gateway) и `/_probe/redirect` (orders) — 404 без `BAGGAGE_ECHO_URL`; в compose
  переменная только у gateway и orders. Через orders проверяется редирект с разрешённого хоста (2.1.1-фикс):
  его ведёт `RedirectFollower` бандла, а не транспорт.
- Тест gateway видит baggage через `OrdersStub` (третий аргумент — опции запроса); caller там
  `showcase:unknown` — `COMPONENT_NAME` в `.env.test` не задан.
- `orders-search-index` однажды упал на свежем ES (2026-10-21 UTC): `exists()` → false, затем `fos:elastica:create`
  → `resource_already_exists_exception` — похоже, повтор PUT клиентом после медленного ответа только что поднятого
  ES. Повторный `make up` проходит (команда видит индекс). Если повторится — ловить 400 already_exists как успех.
- `php -S` в `baggage-echo` пишет в stderr свои access-строки (не JSON, в т.ч. healthcheck): e2e они не мешают
  (`| json | trace_id=` их отсекает), подавить флагом у встроенного сервера нельзя.

## Обновление на healthcheck 2.2 / logger 2.1 / metrics 2.2 / profiling 2.2 / logic 2.0.0-rc.1 (2026-10-23 UTC)

- metrics-bundle 2.2 жёстко требует `msstc4symfony/sql-labeller`, а он тоже только на GitHub: composer не читает vcs-репозитории
  зависимостей → `sql-labeller` прописан в `repositories` всех трёх приложений (иначе «could not be found»). Так же — оба новых моста.
- Docker-сборка (`composer install` в Dockerfile) обошлась без `COMPOSER_AUTH`: репозитории публичные. Локальный
  `composer update` с `gh auth token` работает только вне песочницы Claude (keyring недоступен внутри).
- `msstc4symfony/logic-bundle: ^2.0@rc` (только orders) — флаг стабильности нужен, пока нет финального тега; после тега v2.0.0 (2026-10-23 UTC) заменено на `^2.0`.
- Пул `metrics.cache` (`config/packages/cache.yaml`, адаптер `cache.adapter.redis`) добавлен во все приложения: без него throttle
  метрик падает на `cache.app`, и в `cache_request` попадали бы его лукапы. Побочный эффект: в readiness появился чекер
  `healthcheck.checker.cache.pool.metrics.cache`. Autowiring `$metricsCache` по имени давал INFO-deprecation Symfony 8.1 («use the #[Target] attribute») при сборке контейнера на
  metrics-bundle 2.2.0 — дефект бандла, исправлен в metrics-bundle 2.2.1 (`AliasThrottleCachePass` передаёт пул throttle явно); приложения требуют `^2.2.1`, предупреждение при сборке исчезло.
- `cache.pools` перечислен только у gateway (идемпотентность читает `cache.app`): у orders/billing `cache.app` не читается
  приложением, серия бы не росла. Очередь: `queue_depth` у orders и billing, транспорты `process_payment`/`payment_processed`/`failed` (Doctrine, умеет считать), `period: PT5S`
  (по умолчанию PT1M — e2e ждал бы минуту). Сэмплируют `kernel.terminate` и холостой цикл воркера, поэтому у остановленного
  consumer глубину пишет HTTP-ядро или воркер другого процесса того же приложения.
- tracing-bridge-profiling не открывает trace span для detached-спанов: у `http <METHOD> <host>` (gateway) в `context` нет
  `trace_span_id`, он есть только у `sql ...` и прочих обычных спанов; запись спана несёт `span_id` вокруг, не свой.
  e2e проверяет `trace_span_id` на SQL-спанах, HTTP-спан — только наличие записи.
- `auto_spans.http_client` включён только в gateway: Elasticsearch-запросы orders идут через собственный
  `HttpClient::create()` (Elastica), а не `http_client.transport`, спанов `http ...` у orders нет.
- Статус `degraded` у `healthcheck_checker_run` — упавший non_critical-чекер (Elasticsearch в chaos), проба при этом `passed`.
- `baggage-echo` монтирует `echo.php` одним файлом: после правки контейнер может видеть старую версию → `docker compose up -d --force-recreate baggage-echo`.
- `sampling.feature`: решение по trace id (младшие 14 hex ≥ `80000000000000` при ratio 0.5) e2e считает сам и сверяет с
  флагами `traceresponse` и echo. `/via-orders` отправляет gateway (его HttpClient идёт по 307 orders), а не orders —
  продолжение трассы в orders видно только в Tempo (спаны orders есть лишь у сэмплированных). У трасс probe-маршрутов
  нет корневых спанов запроса: profiling строит их только для `routes:` (`gateway_create_order`, …), поэтому в Tempo
  лишь `http GET …` и `sql …` — проверка «все родители в трассе» к ним неприменима.
- Tempo на неизвестный trace id отвечает `200 {"trace":{}}`, не 404: `TraceLookup::$notFound` = 404 или пустая трасса;
  ошибка транспорта/5xx его не ставит (иначе «dropped-трассы нет в Tempo» прошло бы впустую).
- e2e-образ копирует код: после правки `e2e/` запускать с `--build` (`make e2e` это делает), иначе Behat гоняет старый код.
- Метка `checker` — id сервиса чекера (`healthcheck.checker.doctrine.dbal.default_connection`), readiness-пробу из e2e считать
  `grows by 1`: liveliness дёргает compose healthcheck каждые 5 с, у него точных ассертов нет.
- «grows by 0» проходит на первом же опросе по старому скрейпу → нулевой рост проверяется шагом `has not grown after the next scrape`
  (ждёт скрейп новее шага).
- Readiness при остановленном Redis стал отвечать за 5–20 с (чекеров Redis-пулов теперь больше: `cache.app`, `property_access`, `metrics.cache`,
  плюс lock), e2e-клиент с таймаутом 10 с видел «unreachable», а не «down», и chaos-шаг «readiness did not go down» мигал. Поэтому в
  compose у `REDIS_CACHE_DSN` и `METRICS_STORAGE_DSN` стоит `timeout=1&read_timeout=1` (рекомендация README healthcheck-bundle: границы — на клиенте).
  С healthcheck 2.2 ответы orders при лежащем Redis всё равно бывали 5–17 с, и `HealthClient` временно ждал 30 с.
  healthcheck 2.3.0 пробует пулы на одном подключении один раз за прогон (остальные: `shared connection probe via
  Cache (... : cache.app) connection failed`), readiness при лежащем Redis — 0,1–1,7 с (замер 2026-10-23 UTC), поэтому
  `HealthClient` снова ждёт 10 с. `timeouts.probe_ms` в приложениях не задан: с общей пробой он не нужен.

## Экспорт спанов в Tempo (2026-10-25 UTC)

- Путь: apps (tracing-bridge-otlp 1.0.1, `OTEL_EXPORTER_OTLP_ENDPOINT=http://alloy:4318` в `x-common-env`,
  `service_name: %env(COMPONENT_NAME)%`) → Alloy `otelcol.receiver.otlp` (http 4318) → `otelcol.exporter.otlp` → Tempo
  2.10.8 (gRPC 4317, single binary, storage в контейнере, `usage_report` выключен); в Alloy `otelcol.processor.batch`.
  Grafana: datasource Tempo (`tracesToLogsV2` с custom query по `trace_id`: `service.name` спана — `orders`, а метка
  Loki — `orders-worker`, фильтр по тегам терял бы логи воркеров), Loki derived field `"trace_id":"<32 hex>"` → Tempo.
- **Tempo JSON отдаёт id в base64** (`/api/v2/traces/{id}`): `TraceTree` декодирует `bin2hex(base64_decode())`. 404 —
  трейса ещё нет.
- **Alloy после правки `alloy.alloy` надо пересоздать** (`up -d --force-recreate alloy`): bind-mount конфига не
  перечитывается сам.
- **Alloy возит и логи**: в `@chaos` (Alloy остановлен) warning `OTLP span export failed` попадает в Loki только после
  `I start "alloy"` (позиции docker-источника сохраняются в контейнере). Спаны, отправленные во время остановки,
  теряются — так и задумано (пауза 30 с в мосте).
- **Первый прогон с мостом 1.0.0 сломал `metrics.feature:43`**: мост читал общую паузу из `cache.app`, а metrics-bundle
  считает обращения к нему (miss рос на 2). Исправлено в мосте 1.0.1 (свой FilesystemAdapter), showcase бандлы не
  чинит.
- Tempo 3.x не взят: v3.1.0 — новая архитектура, монолитный конфиг не проверен; 2.10.8 — последний 2.x старше двух
  недель на 2026-10-25.
- **После `@chaos` traces.feature каждый процесс на паузе до 30 с**: сценарий заканчивается шагом «trace of a new
  order reaches Tempo from every service» (заказы шлются, пока один трейс не дойдёт целиком), так что следующие
  сценарии/прогоны не видят потерянных спанов. `TempoClient` трактует любой не-200 и ошибку транспорта как «ещё нет».
- **e2e проверяет и строгие связи**: у каждого спана трейса родитель в трейсе или span клиента (корней нет, клиент
  слал traceparent), и каждый `span_id` из логов трейса есть в Tempo. Все шаги про Tempo опрашивают (`within N
  seconds`): процессы одного трейса (orders и orders-worker с одним `service.name`) экспортируют в конце своих единиц
  работы, Tempo заполняется за несколько секунд. Lock-файлы держат https-URL пакетов (локальный `insteadOf` на SSH
  иначе протекает в `source.url`).
- **Повторный `make e2e` на том же стенде после Redis-chaos (2026-10-25 UTC)**: в части RoadRunner-воркеров gateway
  соединение lock store (`LOCK_DSN`) остаётся «went away», а кеш-соединение живо, поэтому `RedisConnectionGuard`
  (пингует только `cache.default_redis_provider`) ядро не перезагружает → idempotency и readiness gateway падают
  (`Lock store (lock.default) failed`). **Исправлено 2026-10-26 UTC**: guard gateway проверяет и lock store —
  `LockFactory::createLock('redis-connection-guard', autoRelease: false)->isAcquired()` (одна команда к
  хранилищу; id store FrameworkBundle скрытый `.lock.default.store.<hash>`, поэтому через фабрику). С `flock`
  (dev/test) проверка всегда проходит. `UnreachableLockStore` в тестах теперь бросает и в `exists()`.
  Проверено: два `make e2e` подряд с `@chaos` без рестарта gateway.
