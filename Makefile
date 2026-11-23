APPS := gateway orders billing

up: ## Build and start the whole stack
	docker compose up --build --wait --wait-timeout 300

down: ## Stop the stack and drop its volumes
	docker compose down -v

check: ## Quality gate (needs Postgres on 127.0.0.1:5432: docker compose up -d postgres)
	@for a in $(APPS); do \
		(cd apps/$$a && composer validate --strict --no-check-publish -q && composer audit --locked \
			&& php bin/console cache:warmup --env=test -q \
			&& { [ ! -d migrations ] || { php bin/console doctrine:database:create --env=test --if-not-exists -q && php bin/console doctrine:migrations:migrate --env=test -n -q; }; } \
			&& vendor/bin/phpstan analyse --no-progress --memory-limit=512M && vendor/bin/phpunit) || exit 1; \
	done
	cd e2e && composer validate --strict --no-check-publish -q && composer audit --locked && vendor/bin/phpstan analyse --no-progress
	vendor/bin/php-cs-fixer check
	$(MAKE) --no-print-directory rector RECTOR_FLAGS=--dry-run
	$(MAKE) --no-print-directory slo-check

fix: ## Apply Rector, then cs-fixer (Rector's imports must be re-sorted)
	$(MAKE) --no-print-directory rector
	vendor/bin/php-cs-fixer fix

e2e: ## Behat scenarios against the running stack (make up first); @chaos runs last
	docker compose run --rm --build e2e --tags='~@chaos'
	docker compose run --rm e2e --tags='@chaos' --allow-no-tests

demo-traffic: ## About 60 orders over a minute, then a trace id to follow in Loki
	docker compose run --rm --build --entrypoint php e2e bin/demo-traffic

slo-generate: ## Regenerate each app's SLO rules, dashboards, Alertmanager routes and alertmanager.yml (commit the result)
	@for a in $(APPS); do (cd apps/$$a && php bin/console slo:generate --env=test) || exit 1; done
	php docker/observability/assemble-alertmanager.php

slo-check: ## SLO catalog gates per app, stale generated files fail; then promtool (needs Docker)
	@for a in $(APPS); do \
		(cd apps/$$a && php bin/console slo:validate --env=test && php bin/console slo:coverage --env=test \
			&& php bin/console slo:generate --check --env=test) || exit 1; \
	done
	php docker/observability/assemble-alertmanager.php --check
	$(MAKE) --no-print-directory promtool

SLO_RULE_FILES = $(foreach a,$(APPS),$(wildcard apps/$(a)/infrastructure/observability/prometheus/slo-*.rules.yml))

# The mounts mirror compose.yaml, so "check config" also checks every rule file prometheus.yml lists. A rule_files
# glob that matches nothing passes "check config", hence the per-app guard and "check rules" on the explicit list.
promtool: ## promtool check of prometheus.yml and every generated rule file, amtool check of both Alertmanager configs (needs Docker)
	@for f in $(foreach a,$(APPS),$(foreach k,recording alerts,apps/$(a)/infrastructure/observability/prometheus/slo-$(k).rules.yml)) \
		apps/gateway/infrastructure/observability/alertmanager/slo-alertmanager.yml docker/observability/alertmanager.yml; do \
		[ -f $$f ] || { echo "$$f is missing (run make slo-generate)" >&2; exit 1; }; \
	done
	docker run --rm --entrypoint promtool \
		-v $(CURDIR)/docker/observability/prometheus.yml:/etc/prometheus/prometheus.yml:ro \
		$(foreach a,$(APPS),-v $(CURDIR)/apps/$(a)/infrastructure/observability/prometheus:/etc/prometheus/slo/$(a):ro) \
		prom/prometheus:v3.15.0 check config /etc/prometheus/prometheus.yml
	docker run --rm --entrypoint promtool -v $(CURDIR)/apps:/apps:ro prom/prometheus:v3.15.0 \
		check rules $(SLO_RULE_FILES:apps/%=/apps/%)
	docker run --rm --entrypoint amtool \
		-v $(CURDIR)/docker/observability/alertmanager.yml:/etc/alertmanager/alertmanager.yml:ro \
		-v $(CURDIR)/apps/gateway/infrastructure/observability/alertmanager/slo-alertmanager.yml:/etc/alertmanager/slo-alertmanager.yml:ro \
		prom/alertmanager:v0.34.0 check-config /etc/alertmanager/alertmanager.yml /etc/alertmanager/slo-alertmanager.yml

# Per project with its own autoloader: the apps share class names (App\\...) with different signatures,
# so one Rector run over all of them resolves a class to the wrong app.
rector:
	@for p in $(APPS:%=apps/%) e2e; do \
		vendor/bin/rector process $(RECTOR_FLAGS) --no-progress-bar --autoload-file $$p/vendor/autoload.php $$p/src $$([ -d $$p/tests ] && echo $$p/tests) || exit 1; \
	done

help: ## List targets
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "%-14s %s\n", $$1, $$2}'

.DEFAULT_GOAL := help
.PHONY: up down check fix rector e2e demo-traffic slo-generate slo-check promtool help
