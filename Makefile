APPS := gateway orders billing

up: ## Build and start the whole stack
	docker compose up --build --wait --wait-timeout 300

down: ## Stop the stack and drop its volumes
	docker compose down -v

check: ## Quality gate (needs Postgres on 127.0.0.1:5432: docker compose up -d postgres)
	@for a in $(APPS); do \
		(cd apps/$$a && php bin/console cache:warmup --env=test -q \
			&& { [ ! -d migrations ] || { php bin/console doctrine:database:create --env=test --if-not-exists -q && php bin/console doctrine:migrations:migrate --env=test -n -q; }; } \
			&& vendor/bin/phpstan analyse --no-progress --memory-limit=512M && vendor/bin/phpunit) || exit 1; \
	done
	cd e2e && vendor/bin/phpstan analyse --no-progress
	vendor/bin/php-cs-fixer check
	vendor/bin/rector process --dry-run

fix: ## Apply Rector, then cs-fixer (Rector's imports must be re-sorted)
	vendor/bin/rector process
	vendor/bin/php-cs-fixer fix

e2e: ## Behat scenarios against the running stack (make up first); @chaos runs last
	docker compose run --rm --build e2e --tags='~@chaos'
	docker compose run --rm e2e --tags='@chaos' --allow-no-tests

help: ## List targets
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "%-14s %s\n", $$1, $$2}'

.DEFAULT_GOAL := help
.PHONY: up down check fix e2e help
