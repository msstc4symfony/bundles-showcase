# Этап C: `bundles-showcase` — интеграционный стенд и витрина бандлов msstc4symfony

- **Дата:** 2026-10-01 UTC
- **Статус:** дизайн согласован с владельцем, ожидает ревью спека
- **Репозиторий:** `msstc4symfony/bundles-showcase` (публичный; создаёт владелец)
- **Предыдущий этап:** A — `docs/superpowers/specs/2026-09-18-bundle-standard-phase-a-design.md` (закрыт по всем 5 пунктам DoD)
- **Следующий этап:** B — конвенции и мажорные версии, под защитой e2e этого стенда (D7)

---

## 1. Цель

Один репозиторий, который одновременно:

1. **Интеграционный стенд** — ловит баги совместной работы бандлов в реалистичном окружении
   (пример класса таких багов: в этапе A детекторы healthcheck роняли `cache:clear` в реальном
   Symfony 8.1, а в тестовом ядре бандла — нет).
2. **Витрина** — показывает, что дают бандлы вместе: сквозная трасса, логи, метрики, health,
   профилирование — с дашбордами «как в проде».
3. **Гейт для этапа B** — мажорные переработки бандлов выпускаются только после зелёного e2e здесь.

## 2. Решения

| # | Решение |
|---|---|
| C1 | Стенд **и** витрина (уточнение D1 спека A). |
| C2 | Ставятся **только выпущенные теги** бандлов, зафиксированные в `composer.lock`; источник — публичные GitHub-репозитории (`vcs`), Packagist не используется. |
| C3 | Наблюдаемость «как в проде»: Prometheus + Grafana + Loki + Grafana Alloy; всё локально, без публичного развёртывания. |
| C4 | Инфраструктура: PostgreSQL, Redis (кеш и метрики), RabbitMQ. |
| C5 | Одна конфигурация: Symfony 8.x на PHP 8.4. Матрицу версий покрывают CI бандлов. |
| C6 | E2E — **Behat** (сценарии — одновременно документация витрины). |
| C7 | Обновление бандлов — **Dependabot**: PR на новые теги, e2e на PR, слияние только зелёных. |
| C8 | Архитектура — **три независимых Symfony-приложения** со своими `composer.json`/`composer.lock`. |
| C9 | Правило релиза мажоров (этап B): сначала rc-тег (`vX.0.0-rc.N`) → зелёный e2e в showcase → финальный тег. |

## 3. Сервисы и поток

```
client ──HTTP──▶ gateway ──HTTP──▶ orders ──AMQP: ProcessPayment──▶ billing
                                     ▲                                 │
                                     └──── AMQP: PaymentProcessed ─────┘
```

| Сервис | Ответственность | Процессы | Хранилище |
|---|---|---|---|
| **gateway** | Публичный API `POST /orders`, `GET /orders/{id}`; проксирует в orders через HttpClient (scoped client); идемпотентность `POST /orders` по заголовку `Idempotency-Key` (ключ → id заказа в кеше). Без Doctrine и Messenger. | RoadRunner HTTP | Redis (кеш идемпотентности) |
| **orders** | Создаёт заказ (`pending`) и в той же транзакции отправляет `ProcessPayment`; по `PaymentProcessed` переводит заказ в `paid`/`declined`; отдаёт статус. | RoadRunner HTTP + `messenger:consume` | PostgreSQL (БД `orders`), Redis (кеш) |
| **billing** | Обрабатывает `ProcessPayment`: пишет платёж, отвечает `PaymentProcessed`. Сумма выше порога (конфиг) — `declined` (детерминированный сценарий). HTTP — только `/_/metrics` и `/_/healthcheck/*`. | RoadRunner HTTP (служебные маршруты) + `messenger:consume` | PostgreSQL (БД `billing`), Redis (кеш) |

- **Во всех трёх** стоят все пакеты линейки: logger, tracing, metrics, healthcheck, profiling,
  metrics-bridge-profiling. Сервисы различаются сторонним окружением (gateway без Doctrine/Messenger,
  billing почти без HTTP) — проверяются три сочетания «наши бандлы + чужой стек».
- `cache.app` всех сервисов — Redis; healthcheck сам обнаруживает пулы кеша.
- Сообщения Messenger — JSON (сериализатор Symfony); классы сообщений дублируются в orders и
  billing (общий пакет — YAGNI).
- RoadRunner — через `baldinof/roadrunner-bundle` (D6); Messenger-воркеры — классический
  `messenger:consume` (самый распространённый вариант; именно он проверяет
  `WorkerRunningEvent`/`ResetServicesListener`).

## 4. Инфраструктура и наблюдаемость

| Контейнер | Назначение | Порт наружу |
|---|---|---|
| `gateway` | RoadRunner HTTP | 8080 |
| `orders`, `orders-worker` | один образ: RoadRunner HTTP / `messenger:consume` | — |
| `billing`, `billing-worker` | один образ: RoadRunner (служебные маршруты) / `messenger:consume` | — |
| `postgres` | одна инстанция, БД `orders` и `billing` (init-скрипт) | — |
| `redis` | кеш и хранилище metrics-bundle; номера БД разведены | — |
| `rabbitmq` | с management-плагином (API для e2e, UI) | 15672 |
| `prometheus` | scrape `/_/metrics` трёх сервисов каждые 5 с | 9090 |
| `loki`, `alloy` | Alloy через Docker-сокет собирает stdout/stderr контейнеров, метка `service` | — |
| `grafana` | provisioning источников и дашбордов из репозитория | 3000 |
| `e2e` | Behat-раннер (профиль compose `e2e`), с Docker-сокетом для `@chaos` | — |

**Номера БД Redis:** gateway — кеш 0 / метрики 1; orders — 2 / 3; billing — 4 / 5.

**Ключевые решения:**
- **Метрики воркеров:** воркер пишет в тот же номер БД Redis, что и HTTP-процесс его сервиса, —
  метрики `orders-worker` видны в `/_/metrics` у `orders`.
- **Логи под RoadRunner:** stdout PHP-воркера — канал протокола RoadRunner, поэтому Monolog
  (JSON-форматтер logger-bundle) пишет в **stderr**, RoadRunner — `logs.mode: raw`. Иначе строки
  оборачиваются и поиск по `request_id` в Loki ломается.
- **Образы:** один общий Dockerfile (PHP 8.4 + `pdo_pgsql`, `redis`, `amqp`, `intl`, `opcache` +
  бинарник RoadRunner), аргумент сборки — приложение. Docker healthcheck —
  `/_/healthcheck/liveliness`; старт по `depends_on: condition: service_healthy`.
- **Дашборды Grafana** (JSON в репозитории): «Сервисы» (RPS, латентность, ошибки по маршрутам,
  исходящий HTTP gateway → orders, запросы Doctrine) и «Профилирование» (гистограммы span'ов через
  мост). README — готовые запросы Loki, в том числе «цепочка по `request_id`».

## 5. Репозиторий

```
bundles-showcase/
├── apps/
│   ├── gateway/        # Symfony 8.x: composer.json/lock, config/, src/, .rr.yaml
│   ├── orders/
│   └── billing/
├── e2e/                # Behat: composer.json/lock, behat.yml, features/, src/Context/
├── docker/
│   ├── php/Dockerfile  # общий образ, ARG APP
│   ├── postgres/init.sql
│   └── observability/  # prometheus.yml, loki, alloy, grafana provisioning + dashboards
├── compose.yaml
├── Makefile            # up, down, e2e, demo-traffic, check, fix
├── .github/
│   ├── workflows/{e2e.yml, quality.yml}
│   └── dependabot.yml
└── README.md           # витрина: walkthrough, ссылки на Grafana/Loki/RabbitMQ UI
```

**Гейт качества** (код приложений и контексты Behat): PHPStan level 9, PHP-CS-Fixer, Rector,
`composer validate`/`audit` — конфиги копируются из шаблонов `bundle-standard` (верификатор
бандлов к showcase не применяется: это не бандл). Глобальные правила типизации и DTO действуют.

## 6. E2E (Behat)

Чёрный ящик против запущенного compose: HTTP к gateway, служебные маршруты сервисов, API
Prometheus, Loki и RabbitMQ management, PostgreSQL. Асинхронные проверки — только ожидание с
таймаутом (опрос), без `sleep`.

| Фича | Сценарии |
|---|---|
| `order_lifecycle` | заказ → `paid`; сумма выше порога → `declined` |
| `idempotency` | повтор `POST /orders` с тем же `Idempotency-Key` → тот же заказ, второго нет |
| `tracing` | `request-id` клиента возвращается в ответе и есть в логах всех четырёх процессов цепочки (gateway → orders → billing-worker → orders-worker); без заголовка — генерируется; `request-from` показывает вызывающего |
| `worker_reset` | пул RoadRunner = 2 воркера, серия запросов переиспользует воркер: у каждого запроса свой `runtime_id`, trace не протекает, число span'ов = числу запросов; то же для сообщений `messenger:consume` |
| `metrics` | метрики маршрутов gateway в Prometheus; исходящий HTTP gateway → orders посчитан ровно один раз; метрики Doctrine в orders; метрики воркеров видны через `/_/metrics` их сервиса; span'ы через мост |
| `health` | readiness трёх сервисов зелёная и включает Postgres, Redis, RabbitMQ; `@chaos`: остановка Redis → readiness красная, liveliness зелёная; запуск → readiness снова зелёная |

Запуск: `make e2e` (локально и в CI одинаково) — `docker compose run --rm e2e`; сначала сценарии
без `@chaos`, затем `@chaos`.

## 7. CI и обновления

- **`e2e.yml`** (push, PR, PR Dependabot): сборка образов → `docker compose up --wait` → Behat →
  при падении артефакт с `docker compose logs`. Ресурсы Loki и Grafana ограничены (раннер 16 ГБ).
- **`quality.yml`**: гейт качества по каждому приложению и `e2e/`.
- **Dependabot** (ежедневно): `composer` для `apps/*` и `e2e` с группой `msstc4symfony/*` (один PR
  на релиз бандла), `docker`, `github-actions`.

## 8. Порядок работ

Каждый шаг заканчивается зелёным состоянием.

1. Скелет репозитория, три приложения, общий Dockerfile, compose с Postgres/Redis/RabbitMQ, гейт
   качества в CI. **Первым делом** — проверка `baldinof/roadrunner-bundle` на Symfony 8.x.
2. orders + billing: Doctrine, Messenger в обе стороны, все бандлы линейки.
3. gateway: проксирование, кеш идемпотентности.
4. Наблюдаемость: Prometheus, Loki, Alloy, Grafana с дашбордами.
5. Behat: все фичи, включая `@chaos`; CI e2e с артефактом логов.
6. Dependabot, README-витрина, `make demo-traffic` (генератор трафика для дашбордов).

## 9. Definition of Done

1. Из чистого клона `docker compose up --wait` поднимает все контейнеры здоровыми.
2. `make e2e` зелёный, включая `@chaos`.
3. CI зелёный: `e2e.yml` и `quality.yml`.
4. После `make demo-traffic` оба дашборда Grafana показывают данные; запрос Loki по `request_id`
   возвращает записи всех четырёх процессов цепочки.
5. Dependabot открыл хотя бы один PR, e2e на нём отработал.

## 10. Риски

| Риск | Мера |
|---|---|
| `baldinof/roadrunner-bundle` не поддерживает Symfony 8.x | Проверка на шаге 1; запасной вариант — `runtime/roadrunner-symfony-nyholm` (Symfony Runtime), требования к сбросу состояния те же. |
| Нестабильные асинхронные e2e | Только опрос с таймаутом; артефакт логов при падении. |
| Память раннера CI (12+ контейнеров) | Лимиты ресурсов Loki/Grafana в compose. |
| Dependabot не видит теги `vcs`-репозиториев | Проверить на первом релизе бандла; запасной вариант — workflow по расписанию с `composer update 'msstc4symfony/*'` и PR. |

## 11. Вне этапа C

Packagist; публичное развёртывание; матрица версий Symfony/PHP; Kafka, Mongo, Elastica;
аутентификация API; собственный roadrunner-bundle.

**Находки для этапа B:**
- у metrics-bundle нет метрик Messenger (обработано / ошибки / длительность) — асинхронная часть
  видна только через span'ы и логи;
- маршруты metrics-bundle требуют ручного импорта, healthcheck — подключает сам (разнобой);
- W3C Trace Context для tracing (D4).
