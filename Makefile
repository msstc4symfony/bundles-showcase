APPS := gateway orders billing

up: ## Build and start the whole stack
	docker compose up --build --wait --wait-timeout 300

down: ## Stop the stack and drop its volumes
	docker compose down -v

check: ## Quality gate: PHPStan + PHPUnit per app, then cs-fixer and Rector
	@for a in $(APPS); do \
		(cd apps/$$a && php bin/console cache:warmup -q && vendor/bin/phpstan analyse --no-progress --memory-limit=512M && vendor/bin/phpunit) || exit 1; \
	done
	vendor/bin/php-cs-fixer check
	vendor/bin/rector process --dry-run

fix: ## Apply cs-fixer and Rector
	vendor/bin/php-cs-fixer fix
	vendor/bin/rector process

help: ## List targets
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "%-14s %s\n", $$1, $$2}'

.DEFAULT_GOAL := help
.PHONY: up down check fix help
