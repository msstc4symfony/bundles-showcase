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
  `COMPOSE_PROJECT_NAME` проверяется лениво, при первом stop/start/exec; сценарии без Docker переменную не требуют.

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
  `CreateOrderActionWiringTest` проверяет порядок middleware через `MiddlewareChainInterface::middlewares()`
  (logic-bundle 2.1.0; приложения требуют `^2.1`).
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

## OTLP client spans and logs (2026-10-27 UTC)

- Alloy's config is a bind mount: `make up` (`compose up --build`) does not restart Alloy when `alloy.alloy` changes.
  A running stack kept the old pipeline without `logs`, every OTLP log export got 404 — `docker compose restart alloy`.
  That 404 exposed tracing-bridge-otlp 1.2.0/1.2.1's shared pause (a log failure stopped the spans); fixed in 1.2.2.
- tracing-bridge-otlp 1.2.0 resolved `service_name` (`%env(COMPONENT_NAME)%`) when Monolog built its handler, so
  `make check`'s `cache:warmup --env=test` (no COMPONENT_NAME) failed; fixed in 1.2.1 — the showcase was not patched.
- The log call tree (`tracing.feature`) follows `trace_span_id` of profiling records too: since tracing-bundle 2.4 the
  orders request's `parent_span_id` is the gateway CLIENT span, which only the profiling record names.
- OTLP logs land in Loki under `service_name` (resource) with `trace_id` structured metadata; `LokiClient::query()` uses
  `{service=~".+"}`, so the stderr-line checks never see them.
- Loki 3 derives `service_name` from the stderr streams' `service` label too (`discover_service_name`): the OTLP check
  selects `{service_name="…", service=""}` and must not rely on `trace_id` structured metadata being OTLP-only.

## metrics-bundle 2.3 (2026-11-22 UTC)

- 2.3.0 records router 404s and error pages rendered through Symfony's error sub-request (before, the sub-request
  reset the start time and `http_response` never got them). A router 404 is labelled `route="unknown"`; the
  `metrics.feature` scenario "A request no route matches…" pins it. No existing assertion changed: only
  `symfony_http_request{route='gateway_create_order'}` is asserted, and e2e sends no method that would become `OTHER`.
- 2.3.0 drops `metrics-get` and the healthcheck routes from `http_*` by default (`msstc4symfony_metrics.http.ignored_routes`);
  probe counts come from healthcheck-bundle's own `symfony_healthcheck_*` series, so e2e is unaffected.
- `cache:clear --env=test` regenerates `config/reference.php` (new `http.ignored_routes` key) — commit it with the lock.
- `make check` runs `composer audit --locked`: Symfony advisories published against 8.1.10 and older broke it on `main`
  too (http-client, serializer, yaml, validator, config, filesystem); fixed by `composer update <flagged> -w` (8.1.11).

## slo-bundle 1.0.0-rc.3 в приложениях (2026-11-22 UTC)

- **Находка бандла, исправлена в slo-bundle 1.0.0-rc.4 (приложения требуют `^1.0.0-rc.4`):** в rc.3 discovery считал
  хендлер асинхронным, только если **это же** приложение маршрутизирует его сообщение. У потребителей (billing —
  `ProcessPayment`, orders — `PaymentProcessed`) routing нет, поэтому `slo:coverage` писал `unsupported … sync handling
  is not measured`, а `slo:validate` — ложный `WARNING … the SLO never records events` для `billing.process_payment`.
  С rc.4 хендлер считается потребляемым воркером, если `from_transport` указывает не-sync и не-failure транспорт, или
  сообщение маршрутизировано здесь на не-sync sender, или оно здесь не маршрутизировано, а в приложении есть транспорт
  только на приём (не `schedule://`, не failure) и класс не из `Symfony\`. Теперь `ProcessPaymentHandler` — `monitored`,
  `PaymentProcessedHandler` — `ignored`, и coverage-гейт `messenger_handler` реально проверяет эти хендлеры.
  `fromTransport` в `#[AsMessageHandler]` не понадобился.
- `KernelTestCase` (FrameworkBundle 8.1) уже содержит статический `runCommand()` (`ConsoleCommandAssertionsTrait`) —
  свой хелпер с таким именем даёт фатал «Cannot make static method … non static».
- slo-bridge-logic добавляет `SloMiddleware` в цепочку Action → `CreateOrderActionWiringTest` ожидает его между
  `MetricsMiddleware` и `LoggingMiddleware`.
- Flex ставит бандлы auto-generated рецептом в конец `bundles.php` (после `MetricsBundle`, как требует slo-bundle);
  `composer require` снова записал `git@github.com:` в `source.url` lock-файлов — нормализовано sed-ом. Он же
  выкидывает блоки `support` у части пакетов (метаданные GitHub API vs git) — безвредный шум, на `content-hash` не влияет.
- В test-окружении хранилище метрик `inmemory://` (`.env.test`) — отдельного стаба для slo-bundle не понадобилось.

## Сгенерированные SLO-правила и дашборды в стеке (2026-11-22 UTC)

- `prometheus.yml` и provisioning Grafana — bind-mount: на запущенном стеке нужен
  `docker compose up -d --force-recreate prometheus grafana` (как с Alloy); новые файлы в уже смонтированных каталогах
  Prometheus подхватывает только после рестарта (`docker compose restart prometheus`): в стеке нет
  `--web.enable-lifecycle`, поэтому эндпоинта reload нет; Grafana — сама (file provider опрашивает каталог).
- Сразу после рестарта группы `slo-<name>-window` (5m) имеют health `unknown` до первой оценки — это не ошибка.
- `make promtool` и `slo-check` требуют Docker и не пропускаются без него (глобальное правило: тесты не скипаются молча).
- Сломанное правило (`expr: (`) роняет `make promtool` с `could not parse expression … unclosed left parenthesis` —
  проверено вручную.
- Повторный `make slo-generate` не меняет файлы (байт-в-байт) — генерация детерминирована.

## e2e SLO-сценарии (slo.feature, 2026-11-22 UTC)

- Правила окна (`slo-<name>-window`, interval 5m: `slo:sli:ratio`, `slo:window:coverage_ratio`) шаги не ждут:
  `SloContext` берёт загруженное правило (`/api/v1/rules?type=record&rule_name[]=…`, ровно одно по `slo`+`objective`)
  и вычисляет его `query` сейчас (`PrometheusClient::sample`: пустой вектор → null, NaN → ошибка, не 0). Записанные
  серии 1m-группы (`slo:error_ratio:rate5m`) проверяются как серии (до 150 с). Одна ступень ждёт до 330 с первую
  оценку обеих групп.
- **`slo:window:coverage_ratio` — NoData до первой целой границы часа UTC после первого события**: формула slo-bundle
  `min_over_time(timestamp(sum(…))[<окно+1h>:1h])` (spec 4.8) — шаги подзапроса на сетке целых часов UTC,
  `timestamp(sum())` даёт время шага; события 11:19 UTC → значение только с 12:00 UTC. Это корректное состояние
  warming-up (решение контроллера: шаг 5m над 721h ≈ 8.6k точек на оценку), поэтому шаг «has no value yet or evaluates
  to at least 0 and below 1» принимает пустой вектор или [0, 1) и падает на ≥ 1 или NaN; доказательство warming-up —
  отсутствие ticket-алерта. Шаг печатает, какая ветка прошла («no value yet (warming up until …)» / «value …»), и
  требует значение, если `lastEvaluation` правила позже первой целой границы часа после первого события серии из
  `timestamp(sum(…))` правила.
- NoData: t0 = `min(min_over_time(timestamp(symfony_slo_events_total{slo=…})[6h:5s])) - 10`, при этом
  `max(up{service=…})` в t0 = 1, иначе шаг падает («run on a fresh stack»). Работает и на повторном прогоне в пределах
  6h от первого события стека. Сценарии NoData, warming-up и @chaos 5xx сами создают заказ — запуск по `--name` на свежем стеке проходит. Подзапрос `[6h:5s]` пуст, пока у только что появившейся серии нет шага сетки 5 с → t0 опрашивается до 20 с. Сразу после `make up` (образ e2e в кеше) первые сценарии слали заказы раньше, чем
  Prometheus успевал скрейпнуть orders (первое событие через 5 с после первого скрейпа orders → шаг падал) →
  `#[BeforeSuite] SteadyScrapesContext::waitForSteadyScrapes` (`ScrapeBarrier::awaitTargetsScrapedFor`) ждёт, пока каждый сервис отвечает на все скрейпы последних 20 с.
- Redis-раскладка promphp (metrics-bundle): hash `PROMETHEUS_:counter:<metric>` в DB метрик приложения (gateway 1),
  поле — JSON-список значений меток в порядке `labelNames` из поля `__meta`. e2e ищет поле по значениям (slo,
  objective, revision), не по позиции. Redis не опубликован → `MetricsStorageClient` = `docker exec redis redis-cli`.
- Инъекция ревизии `deadbeef`: новое поле сначала создаётся с 0 (`HINCRBYFLOAT … 0`) и дожидается скрейпа, потом +1000:
  серия счётчика, появившаяся сразу с 1000, даёт `increase() = 0` (Prometheus не считает первый сэмпл от нуля), и
  расширенный фильтр `revision=~"<текущая>|deadbeef"` не опускал бы SLI.
- `#[AfterScenario]` `SloContext::deleteInjectedEvents` удаляет (`HDEL`) все вставленные поля, даже после падения шага,
  иначе 1000 bad остались бы в стенде и в каждом следующем прогоне.
- `ScrapeBarrier` (idle RabbitMQ + свежий успешный scrape) общий для `MetricsContext` и «remember»-шагов `SloContext`.
- PHPStan не видит by-ref присваивание в замыкании `Wait::until` → состояние последнего опроса для текста таймаута
  хранится в свойстве (`SloContext::$lastPoll`).
- `ElasticaOrderIndex::add()` оборачивает каждый вызов монитора операций (start/succeed/fail/end) — контракт slo-bundle
  fail-open, но с бросающим монитором `add()` бросал бы из `start()` (до try) и из `finally`; ошибка монитора →
  warning-лог, индексация и 201 не страдают (`ElasticaOrderIndexTest::testABrokenMonitorNeverMakesAddThrow`).
- `make promtool`: `check config` молча проходит, если glob `rule_files` ничего не нашёл → цель сначала проверяет наличие
  `slo-{recording,alerts}.rules.yml` каждого приложения, затем `promtool check rules` по явному списку (проверено:
  убранный `apps/orders/.../slo-alerts.rules.yml` → exit 2 «… is missing (run make slo-generate)»).

## slo-bundle / SLO bridges 1.0 final (2026-11-22 UTC)

- `msstc4symfony/slo-bundle`, `slo-bridge-logic` and `slo-bridge-profiling` are required as `^1.0` (v1.0.0); the rc constraints are gone. Orders keeps the explicit slo-bundle require because it uses the bundle directly. The final has no generator change after rc.4: `slo:generate --check` reported no diff.

## Alertmanager и alert-recorder (2026-11-23 UTC)

- `alert-recorder` декодирует тело как объекты (`json_decode(..., false)`): с `assoc=true` пустые `annotations: {}`
  и `routeLabels: {}` Alertmanager превращаются при повторном кодировании в `[]`.
- Ручная проверка доставки: POST `[{"labels":{"alertname":"SloErrorBudgetBurn","severity":"page","application":"showcase",...}}]`
  на `alertmanager:9093/api/v2/alerts` → в recorder через ~5 с (`group_wait`) уведомление `receiver: slo-page`.
- `docker -v` с несуществующим исходным файлом создаёт каталог → `make promtool` сначала проверяет, что собранный
  `alertmanager.yml` и полные файлы трёх приложений существуют (иначе «… is missing (run make slo-generate)»).
- Обновление slo-bundle, меняющее фрагмент, требует `make slo-generate` (пересобирает и `alertmanager.yml`), иначе
  `quality` (job `slo-rules`) падает на `--check` сборщика.
- Пины `prom/prometheus` и `prom/alertmanager` — в `compose.yaml`; `Makefile` читает их оттуда (`PROMETHEUS_IMAGE`,
  `ALERTMANAGER_IMAGE`). Второе место `prom/prometheus` — `docker/php/Dockerfile` (promtool образа backfill):
  Dependabot двигает его и compose отдельными PR, поэтому `make image-pins` (часть `make check`) падает при
  расхождении — в PR Dependabot нужно поднять второе место.
- `alertmanager.yml` смонтирован одним файлом и читается Alertmanager только при старте; `make up` после
  `make slo-generate` контейнер не пересоздаёт (конфиг compose не менялся) → `docker compose restart alertmanager`
  (или `curl -XPOST localhost:9093/-/reload`).
- Сборщик `assemble-alertmanager.php` намеренно строг к раскладке фрагмента (ключи верхнего уровня ровно `inhibit_rules`
  и `routes`, отступ 2 пробела): он текстовый, и любое отклонение генератора должно падать, а не склеиваться молча.

## Backfill истории orders.search_index (2026-11-23 UTC)

- Сервис `prometheus-backfill` (образ `backfill` = orders + promtool, root) до старта Prometheus пишет в **пустой** том
  `prometheus-data` 60 дней сырых счётчиков `symfony_slo_events_total` для `orders.search_index` availability; Prometheus
  ждёт его `service_completed_successfully`. Непустой том (второй `make up` без `down`) → лог
  `prometheus-backfill: skipped, /prometheus is not empty` и exit 0: повторная заливка с новым «сейчас» перекрыла бы
  первую историю, и счётчики читались бы как сбросы. Блоки собираются в `/prometheus/.backfill` (тот же том) и
  переносятся rename-ом; каталог удаляется последним. Оставшийся `.backfill` (прерванный запуск) → exit 1
  «half-written backfill … run make down», Prometheus не стартует.
- Профиль по умолчанию (`app:slo:backfill`): **2 события в минуту** (120/ч), **0.2 % bad** до последних 4 дней,
  **3 % bad в последние 4 дня** (≈ 3.6 bad/ч); 172 802 сэмпла, 2 серии (`result` good/bad), последний сэмпл = «сейчас»
  команды. Итог на старте: `coverage_ratio` = 1, `slo:error_ratio:rate6h` ≈ 0.031, `rate1h` ≈ 0.025–0.042 (bad
  дискретны), `rate3d` ≈ 0.03, `slo:sli:ratio` ≈ 0.9943 → ticket (1× бюджета 1 %) горит, page (14.4 % / 6 %) — нет.
- Для page поверх backfill (alerting.feature, page): история видна в `rate[1h]` ещё час после старта стека, в `rate[6h]` — шесть.
  Худший случай (сразу после старта): 1h-доля = (3.6 + N_bad) / (120 + N_bad + N_good) > 0.144 → нужно N_bad > 16 при
  N_good = 0 (и > 0.144 в 5m-окне, где истории уже нет). Ticket гаснет сам через ~6 ч работы стека (`rate6h` без
  новой истории падает ниже 1 %).
- Алерты и `slo:window:coverage_ratio` (`min_over_time(timestamp(sum(raw))[721h:1h])`) считаются из сырых счётчиков
  вживую; recorded-серии этой цели тоже залиты задним числом — см. «Backfill recorded-серий» ниже.
- Почему `instance="backfill"`: живые серии несут адрес контейнера в `instance`; общий набор меток склеил бы историю
  с живым счётчиком (живой 0 после исторических 170 тыс. = сброс). Метки SLO (`application, component, slo,
  objective, revision`) команда берёт из селектора `slo:events:rate5m` в `slo-recording.rules.yml` образа — новая
  ревизия попадает в историю только после `make slo-generate` **и `make down`**: без `down` backfill пропускается,
  история остаётся со старой ревизией, правила новой её не видят → `coverage_ratio` < 1 и ticket гаснет.
  `job`/`service` — как у скрейпа.
- e2e: шаги «grows by» по `orders.search_index` фильтруют `instance!='backfill'` (историческая серия видна 5 мин
  lookback после конца истории и, уйдя в stale, уменьшила бы сумму); сценарий NoData перенесён на
  `orders.create_order` latency — у `orders.search_index` с историей значение SLI есть с первой секунды.
- promtool читает весь вход заново на каждый блок: с `--max-block-duration` 24h — 81 блок за 23 с, с 1464h — 2 блока
  меньше чем за 1 с. Сервис целиком (генерация ≈ 38 МБ OpenMetrics, 38 106 116 байт + promtool + chown) — ≈ 2 с; на `make up` это
  ≈ +2 с перед стартом Prometheus (образ `backfill` — слой поверх кешированного `app` orders).
- Live bad-серия, появившаяся сразу с N > 0, даёт `increase() = 0` (см. инъекцию `deadbeef` выше) — для живой поломки
  это не мешает, пока серия `result="bad"` уже существует или растёт после первого скрейпа.
- После полного e2e (non-chaos + chaos) горят ticket `orders.search_index` (backfill) и page `gateway.create_order`
  (5xx @chaos-сценария), а при повторном прогоне может гореть и ticket/page `billing.reconcile_payments` (bad-запуски
  scheduler.feature, см. «e2e scheduler.feature»). Шаги `alerting.feature` всегда фильтруют по `alertname, application,
  severity, slo, objective` (см. ниже).
- Замер 2026-11-23 UTC: `make down` + `docker compose up --build --wait` (образы в кеше) — 49 с; контейнер
  `prometheus-backfill` от старта до выхода ≈ 1.7 с; ticket горит через ≈ 3 мин после старта Prometheus (первая
  оценка 5m-группы окна, затем 1m-группы алертов). Второй `make up` без `down` — 40 с, backfill `skipped`.

## Backfill recorded-серий orders.search_index (2026-11-23 UTC)

- Тот же сервис `prometheus-backfill` после сырых блоков заливает 60 дней всех 14 recording rules
  `orders.search_index` availability (`app:slo:backfill-recorded`), чтобы дашборды показывали историю сразу после
  `make up`. Только этой цели: у остальных SLO (и у latency этой SLO) истории нет, их recorded-серии начинаются со
  старта стека.
- **Почему не `promtool tsdb create-blocks-from rules`** (замерено 2026-11-23 UTC): он пишет отдельный блок на
  каждое правило × окно блока, и каждый блок стоит ≈ 1 с sys даже на tmpfs (2h-блок одного `vector(0.99)` — 1.17 с);
  API отдаёт не больше 11 000 точек на серию (`exceeded maximum resolution of 11,000 points`), так что шаг 5m при
  60 днях требует блоков 486h → 4 окна × 13 правил = 52 блока, 26–32 с даже с 13 параллельными promtool. Скрытый
  флаг `--max-block-duration` у `create-blocks-from` есть и для rules, но 1458h при шаге 5m > 11 000 точек.
  Поэтому команда сама делает то же, что promtool (range query выражения правила, метки правила поверх меток
  результата, `__name__` = record), кусками ≤ 11 000 точек, пишет OpenMetrics `gauge`, и блоки делает тот же
  `create-blocks-from openmetrics --max-block-duration=1464h` (по 2 блока на проход).
- **Почему временный Prometheus, а не живой**: блоки, перенесённые в каталог работающего Prometheus, он подхватывает
  без рестарта (`reloadBlocks` раз в `BlockReloadInterval` = 1 мин; проверено: 46 с, затем сразу вертикальная
  компакция перекрывающихся блоков). Но правило, читающее другой record (`slo:error_budget:remaining` ←
  `slo:sli:ratio`), видит только загруженные блоки → 2 прохода = до 2 мин ожидания перезагрузки, плюс риск
  перекрыть живые записи head. Поэтому `backfill.sh` поднимает внутри контейнера свой `prometheus` (бинарник из того же
  `prom/prometheus` образа, `global: {}`, `127.0.0.1:9091`, retention 61d) на staging-TSDB, рестартует его между
  проходами (новые блоки загружаются на старте, ~1 с) и переносит в том только каталоги с `meta.json` (WAL/head
  временного остаются в staging и удаляются). Настоящий Prometheus стартует уже со всеми блоками.
- Проходы: `RecordingRules::passes()` — правило ждёт прохода, если его `expr` упоминает record другого правила набора
  (по границам имени метрики; с запасом: лишнее совпадение = лишний проход). Цикл → ошибка. Для текущих правил 2
  прохода: 13 правил, затем `slo:error_budget:remaining`. `--passes` печатает число проходов.
- Окно: `backfill.sh` задаёт его один раз — `days=60`, `now=$(date +%s)` → `app:slo:backfill --days --now` и
  `app:slo:backfill-recorded --start=now−60d --end=now` (команды сами проверяют окно). Шаг каждого правила = `interval` его
  группы из файла правил (`1m` / `5m`, как вживую; группа без целого `Ns|Nm|Nh` → ошибка): 1m-группа над 60 днями =
  86 400 точек → 8 кусков по ≤ 11 000. Замерено 2026-11-23 UTC: с 1m-шагом сервис 25.6 с (load ≈ 25) — как прежние
  21–37 с с единым 5m, т.к. время съедает `slo:sli:ratio` 5m-группы. Последняя точка ≤ «сейчас» backfill-а < первой
  живой оценки → без перекрытия с head.
- Сбой внутри `backfill.sh` (trap на EXIT; INT → exit 130, TERM → exit 143): временный Prometheus останавливается, в stderr «failed in pass N of the
  recorded series»; пока ни один блок не перенесён в `/prometheus`, staging удаляется (следующий `make up` начнёт
  заново с пустого тома), после начала переноса staging остаётся → «half-written backfill», нужен `make down`.
  Проверено подменой `curl` (временный Prometheus «не готов»): сообщение с `pass 1`, том пустой.
- Память `app:slo:backfill-recorded` (php:8.5-cli, `memory_limit` 128M по умолчанию), замер 2026-11-23 UTC
  `memory_get_peak_usage(true)` против живого Prometheus: проход 1 держал все 13 правил до первого `yield` — 112 MiB
  (почти лимит, 9 правил × 86 401 точка 1m-шага); после потоковой выдачи по family (record) — 57 MiB, проход 2 — 14 MiB.
  Пик теперь — одно правило: склеенные куски JSON-ответа и его значения.
- Описания значений в ошибках — `Diagnostics::describe()` (JSON, иначе `var_export` скаляра или тип): `interval: .inf`
  из YAML — float INF, `json_encode` с `JSON_THROW_ON_ERROR` сам падал бы. Интервал проверяется на переполнение
  (`max_range = intdiv(PHP_INT_MAX, единица)`), а не умножением.
- phpunit orders: сьюты `unit` (`tests/Unit`, фикстуры) и `integration` (`tests/Integration`, в т. ч. контракт с
  реальным `slo-recording.rules.yml`); `vendor/bin/phpunit` без опций (make check, CI quality.yml) гоняет обе,
  `--testsuite unit` — без контрактных проверок.
- Правило с `record`, но без `expr` (или не-массив) в файле → ошибка с именем файла и группы; `alert:` пропускаются.
- Каждое правило обязано дать хоть одну серию, иначе команда падает («found nothing … is the history it reads
  loaded?»): иначе пустая история (не та ревизия, не загрузились блоки) прошла бы молча.
- Метки backfill-серий совпадают с живыми (`sum()` снимает `instance`, остаются метки правила) → на графике одна
  непрерывная серия (проверено range query через старт стека: одна серия, история до момента backfill, дальше живые).
- Влияние на алерты: ticket горит **раньше** — его выражение (`last_over_time(rate3d[10m])`, `rate6h`,
  `last_over_time(coverage_ratio[10m])`) сразу видит последние backfill-точки через lookback, не дожидаясь первой
  оценки 5m-группы окна: 2026-11-23 UTC ticket `firing` через 25 с после старта Prometheus (было до ~5 мин). Шаги
  alerting.feature ждут «within 420 seconds» — раньше не мешает. Page backfill-ом не зажигается: в истории
  `rate1h` ≈ 0.03 < 0.144, `rate6h` ≈ 0.03 < 0.06. coverage-шаги вычисляют `query` правила сейчас — от recorded-истории
  не зависят; NoData/warming-up сценарии — на других SLO.
- e2e (slo.feature, первый сценарий): записанные `slo:error_ratio:rate1h` ∈ [0, 0.05] и `slo:error_budget:remaining`
  ∈ [0.7, 0.9] (0.2 % bad → 0.8 бюджета) 30 дней назад (`time` = сейчас − 30d) — второе доказывает, что второй проход
  видел записи первого.
- Логи `http_client` (INFO, по 2 строки на запрос) команды идут в stderr и видны в `docker compose logs
  prometheus-backfill` — stdout только OpenMetrics. `-q` не использовать: он глушит и `writeln(OUTPUT_RAW)`.
- Замеры 2026-11-23 UTC (машина под чужой нагрузкой, load average 20–77, образы в кеше, `make down` перед каждым):
  контейнер `prometheus-backfill` — main 5.3 / 5.5 с, ветка 29.2 / 36.7 / 21.2 с (+16…31 с). Из них сырой шаг
  ≈ 5–8 с, проход 1 (13 правил) 12–19 с, перенос в блоки ≈ 3 с, проход 2 ≈ 2 с; ≈ 8 с из прохода 1 — один
  `slo:sli:ratio` (`increase[30d]` на каждом 5m-шаге; без нагрузки ≈ 3.8 с на кусок в 38 дней). HTTP-таймаут
  запроса — 120 с (Prometheus ничего не шлёт до конца вычисления; его `--query.timeout` 2m). `make up` целиком:
  main 92 / 101 с, ветка 180 / 190 / 91 с — разброс от нагрузки больше самой добавки: критический путь `make up` —
  Elasticsearch (healthy через 76 с в последнем прогоне) → orders → gateway, backfill идёт параллельно и в спокойном
  прогоне закончился раньше ES (+43 с от старта). Параллельные запросы правил (все сразу) оказались медленнее
  последовательных (10–18 с против 7 с) — Prometheus упирается в CPU, поэтому правила идут по одному, куски одного
  правила — вместе. Образ `backfill` стал больше на бинарник `prometheus`.
- Флейк этой машины под нагрузкой (2026-11-23 UTC, не связан с backfill): шаг «within 90 seconds "orders" readiness
  has a line matching …passed (cluster status: (green|yellow))» после `I start "elasticsearch"` (health.feature:62,
  alerting.feature:37) падает, когда перезапуск ES дольше 90 с — замерено 113 с при load average ≈ 46. Так же падал
  на main 7f545d5 (health.feature:62, 6/7 chaos-сценариев).
  Разобрано 2026-11-27 UTC: при load average ≈ 5 ES после `docker compose start` отвечает green/yellow через 27 с, и
  readiness orders видит его в ту же секунду (`BoundedPingResurrect` не отстаёт). Значит, флейк — только время холодного
  старта ES (CPU), DNS и код orders ни при чём. Ожидание поднято до 180 с в health.feature и alerting.feature: шаг
  опрашивает, на быстром стенде это бесплатно.

## e2e alerting.feature: ticket, page, inhibition (2026-11-23 UTC)

- Ticket (`@alerting`, идёт в non-chaos прогоне, первым по алфавиту): ждёт до 420 с уведомление `slo-ticket`. 5m-группа
  окна (`rate3d`, `coverage_ratio`) впервые считается в слоте по настенным часам (`hash(group) mod 5m`), т. е. до 5 мин
  после старта Prometheus, затем 1m-группа алертов и `group_wait` 5 с — замер «≈3 мин» из backfill был удачей слота.
- Page + inhibition — один сценарий (`@alerting @chaos`, первый в chaos-прогоне): отдельный сценарий inhibition
  зависел бы от порядка. Предусловия по порядку: page этой SLO в Alertmanager нет (page прошлого
  прогона подавляет ticket; шаг ждёт до 1800 с — на 2026-11-23 UTC page горел ещё ~30 мин после возврата ES, —
  затем падает → `make down`), затем ticket активен и не подавлен. Поломка — `I stop "elasticsearch"`: заказы
  создаются (201), каждое событие `orders.search_index` availability — bad. Сначала 1 заказ и ожидание live-серии
  `result="bad"` (серия, появившаяся сразу > 0, не даёт `increase()`), затем 50 заказов: 1h-доля ≥ (3.6 + 51) /
  (120 истории + ≤ 100 живых good + 51) ≈ 20 % > 14.4 %; на свежем стеке хватило бы 16. Граница «≤ 100 живых good
  за час» проверяется шагом-предусловием (`… is at most 100`), а не предполагается. Бóльший burst не выход: при
  остановленном ES заказ orders каждые ~5 с зависает на ~4 с (поиск имени `elasticsearch` уходит на недоступный внешний DNS, см. «Losing Redis» ниже), рядом с 5-секундным
  таймаутом gateway→orders; прогон с 80 заказами 2026-11-23 UTC получил один 502 (orders ответил 201 за 5012 мс).
- Ticket-сценарий заканчивается шагом «Alertmanager has a "ticket" alert» (любое состояние: page прошлого прогона
  может его подавлять): на старом стеке recorder хранит давно погасший firing-ticket, и шаг по recorder прошёл бы сразу. Таймаут ticket-шага называет причину (стек старше ~6 ч).
- Recorder общий и **не сбрасывается**: ticket доставляется один раз при старте (`repeat_interval` 1h), `DELETE /`
  после него сломал бы повторный ticket-сценарий. «Новые» уведомления — после запомненного числа (`I remember the
  notifications the alert recorder got so far`). «Горит ли сейчас» и подавление читаются из
  `/api/v2/alerts?filter=…` (повторяющийся параметр `filter`; HttpClient кодировал бы массив как `filter[0]`).
  Подавленный алерт: `status.state = suppressed`, `status.inhibitedBy` содержит fingerprint page-алерта.
- «Losing Redis» (health.feature, @chaos) на этой машине 2026-11-23 UTC падает на свежем стеке и **без** alerting
  (проверено на fa6c0a8): readiness orders при лежащем Redis отвечает 0,5–21 с, окно «within 20 seconds readiness
  is down» и таймаут gateway→orders впритык. В CI зелёный. Не связано с алертингом и не баг бандлов.
  Причина (замерено 2026-11-23 UTC): имя остановленного контейнера пропадает из встроенного DNS Docker
  (`127.0.0.11`), тот пересылает запрос на внешние `dns` из `/etc/docker/daemon.json` (8.8.8.8, 1.1.1.1), а из
  контейнерной сети этой машины (VPN, mtu 1280) они не отвечают: `getent hosts nonexistent-xyz` ≈ 4,5 с,
  `gethostbyname('redis')` ≈ 8 с (A + AAAA). `timeout=1` phpredis на getaddrinfo не распространяется, и каждый
  Redis-клиент (cache-пулы, lock, метрики после окна backoff 5 с на воркер) платит этот поиск. То же самое у
  остановленного Elasticsearch (~4 с на заказ, см. выше). Лечится на хосте: внутренний/доступный DNS в
  `daemon.json`; в коде showcase/бандлов чинить нечего.
- Все шаги фильтруют по `alertname=SloErrorBudgetBurn, application=showcase, severity, slo, objective` — после chaos
  горит ещё page `gateway.create_order`.
- `slo.feature`: `coverage_ratio` для `orders.search_index` availability строго `== 1` (шаг `evaluates to exactly`);
  для остальных SLO «нет значения» по-прежнему допустимо.

## slo-bundle 1.2: SLO на задаче Scheduler в billing (2026-11-25 UTC)

- Расписание `billing` исполняется только там, где читают транспорт `scheduler_billing` → отдельный сервис
  `billing-scheduler` (`messenger:consume scheduler_billing`). `billing-worker` его не читает: без нового сервиса
  `ReconcilePayments` не запускается, и SLO молчит (не bad — «задача не запускается» bundle не ловит).
- `task` в метриках — `<service id>::<method>`, у автоконфигурированного класса id = FQCN:
  `task="App\\Scheduler\\ReconcilePayments::__invoke"` (в PromQL обратный слеш экранируется).
- `scrape_interval_seconds: 5` в `msstc4symfony_slo.yaml` (с 2026-11-25 UTC во всех трёх приложениях) = `global.scrape_interval` в `prometheus.yml`; при
  дефолте 60 `slo:validate` отверг бы deadline 5s (порог latency) — меняешь scrape interval стенда, меняй и это.
- Поломка задачи: `redis-cli -n 4 set showcase:billing:reconcile:fail 1` (DB кеша billing). Проверено 2026-11-25 UTC:
  3 запуска → `finished_total{outcome="error"}` и `events_total{objective="availability",result="bad"}` +3; latency
  ошибки не считает (bad только по длительности/незавершённым). Ретраев нет, failure transport пуст
  (`Removing from transport after 0 retries`).
- После апгрейда `slo:generate --check` дрейфует (новый `slo-scheduled-tasks.json` у всех трёх приложений, строка
  `scheduled_task` в coverage-дашборде) — перегенерировать. `slo:coverage` billing показывает
  новый `ServiceCallMessageHandler` как `unsupported … sync handling` (как уже `RunCommandMessageHandler`) — гейт не
  валит, `messenger_handler` «measured by universal metrics» упал с 25 % до 20 %. Flex-рецепт `symfony/scheduler`
  (дефолтный `src/Schedule.php`) намеренно не установлен: задача объявлена атрибутом.
- Lock-и обновлены `composer:2.11` в Docker с `--no-install --no-scripts --ignore-platform-req='ext-*'` (PHP образа
  8.5, частичное обновление не трогает остальные пакеты); `symfony/scheduler` встал как 8.1.12 при messenger 8.1.8.
  `composer require` в одном запуске с ещё не обновлённым в lock `slo-bundle ^1.2` падает («fixed to v1.1.0 by a
  partial update») — сначала `update msstc4symfony/slo-bundle`, потом `require`.

## e2e scheduler.feature (2026-11-25 UTC)

- billing-scheduler шлёт `ServiceCallMessage` каждые 15 с, и он попадает в `symfony_messenger_message_handled{component='billing'}`
  (`transport='scheduler_billing'`). Шаги «grows by N» по billing без `transport='process_payment'` теперь ловят лишний рост
  (так было в health.feature «Losing Redis», исправлено). Новые шаги по billing всегда фильтровать по транспорту.
- В PromQL фичи метка задачи пишется как `task='App\\Scheduler\\ReconcilePayments::__invoke'`: Gherkin не экранирует, Prometheus
  читает `\\` как один `\`.
- Fail-switch задачи ставится через `SET … EX 20` (чуть больше периода 15 с: запуск может стартовать до 1 с позже) и снимается,
  как только bad-запуск попал в scrape: 1–2 bad-запуска. Истечение снимает поломку, даже если прогон убит до `#[AfterScenario]`.
  DB берётся из `REDIS_CACHE_DSN` контейнера `billing-scheduler`, ключ — копия `ReconcilePayments::FAIL_SWITCH` (кода приложений
  в e2e-образе нет).
- Эти bad-события настоящие. Первый bad на стеке — новая серия сразу с 1, её `increase()` не видит (2026-11-25 UTC: после
  сценария `ALERTS{slo="billing.reconcile_payments"}` пуст). Если bad-серия уже есть (повторный прогон на том же стеке),
  1–2 bad из ~30–40 запусков молодого стека (сценарий идёт после 420-секундного ticket-сценария) могут зажечь ticket
  `billing.reconcile_payments` availability (rate6h и rate30m > 6 %), а на стеке моложе нескольких минут и page. Он
  уходит в `slo-ticket`/`slo-page` и держится часами, как page `gateway.create_order` после @chaos. Шаги alerting.feature фильтруют по
  `slo`, поэтому им это не мешает.
- `started_total` и `finished_total` проверяются на равный рост (`… and … have grown equally`). Базовая разность берётся как
  `min_over_time` за 30 с до снимка, потому что запуск может быть «в полёте» в любом scrape, а текущая разность опрашивается.
  Обе стороны читаются одним запросом, поэтому гонки между двумя чтениями нет.
- При остановленном Redis `RedisConnectionGuard` останавливает и billing-scheduler (`Stopping worker.`, compose поднимает заново,
  2 рестарта за chaos-прогон). `ReconcilePayments` читает переключатель fail-open: `RedisException` = «ключа нет». Так короткий
  сбой Redis (окно до 5 с, пока guard не заметил «went away») не сжигает SLO задачи.
- `scrape_interval_seconds: 5` задан во всех трёх приложениях, а не только в billing: у gateway и orders задач пока нет, но
  новые задачи будут проверяться против scrape interval стенда, а не против дефолта 60. Сгенерированные файлы от этого
  не изменились.
- `billing-scheduler` — ровно одна реплика: у расписания нет state и lock, второй consumer `scheduler_billing` удвоит запуски.
- «Losing Redis» (health.feature, @chaos) 2026-11-25 UTC снова падает локально (1 из 2 прогонов, и на main cceec28).
  Liveliness orders при лежащем Redis отвечает 0,04–7,9 с при 10-секундном таймауте клиента e2e, сценарий падает на
  разных шагах. Причина та же, что 2026-11-23 (DNS), только хвост теперь редкий: поиск несуществующего имени через
  встроенный DNS Docker обычно 0,02–0,1 с, но примерно 1 из 15–30 запросов ждёт ~4 с ответа внешних 8.8.8.8/1.1.1.1
  (замер 2026-11-25 UTC: `gethostbynamel('nonexistent-xyz')` даёт тот же хвост 4,1 с и при **работающем** Redis).
  Запрос orders при лежащем Redis делает десяток поисков `redis` (метрики, пулы кеша, guard), поэтому медленные
  ответы кратны 4 с (4,0 / 7,9 / 3,8 с), а в логе между двумя Redis-ошибками одного воркера пауза ровно ~4 с.
  Одиночный замер `gethostbyname` (0,08 с) хвост не ловит — мерить сериями. Лечится на хосте (`dns` в
  `/etc/docker/daemon.json`), код бандлов и showcase ни при чём.

## Локальный `composer update` и git@ в lock (2026-11-27 UTC)

- Глобальный `github-protocols` на машине автора `["https","ssh"]`: локальный `composer update` записал `source.url`
  пакетов msstc4symfony как `git@github.com:…` (их VCS-репозитории). После любого локального update:
  `sed -i -E 's#"git@github\.com:([^"]+)"#"https://github.com/\1"#' apps/*/composer.lock` (content-hash не меняется).
- `symfony/flex` 2.12 при `composer update 'symfony/*'` сам меняет `"symfony/flex": "^2"` на `"^2.12"` в composer.json —
  откатить и `composer update --lock`.
