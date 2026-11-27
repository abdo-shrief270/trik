# Trik developer commands. Run `make help` for the list.
SHELL := /bin/bash
.DEFAULT_GOAL := help

API := apps/api
WEB := apps/web
LANDING := apps/landing
MOBILE := apps/mobile
SERVICES := webhook-intake message-writer dispatcher

help: ## Show this help
	@grep -hE '^[a-zA-Z0-9_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

# ---------------------------------------------------------------- stack
up: ## Start the local stack
	docker compose up -d

down: ## Stop the local stack
	docker compose down

reset: ## Stop the stack and delete all local data
	docker compose down -v

ps: ## Show stack status
	docker compose ps

logs: ## Follow stack logs
	docker compose logs -f --tail=100

# ---------------------------------------------------------------- first-time scaffolding
scaffold-api: ## Create the Laravel app in apps/api
	./scripts/scaffold-api.sh

scaffold-web: ## Create the Nuxt app in apps/web
	./scripts/scaffold-web.sh

landing: ## Run the marketing site (trik.chat) on http://localhost:3100
	cd $(LANDING) && pnpm dev

scaffold-mobile: ## Create the Flutter app in apps/mobile
	./scripts/scaffold-mobile.sh

migrate: ## Run API migrations (as the table owner)
	cd $(API) && php artisan migrate --database=owner

module: ## Create a backend module: make module name=Orders
	./scripts/new-module.sh $(name)

# ---------------------------------------------------------------- quality
lint: ## Lint everything that exists
	@if [ -f $(API)/composer.json ]; then cd $(API) && vendor/bin/pint --test && vendor/bin/phpstan analyse; fi
	@if [ -f $(WEB)/package.json ]; then cd $(WEB) && pnpm lint && pnpm typecheck; fi
	@if [ -f $(LANDING)/package.json ]; then cd $(LANDING) && pnpm lint && pnpm typecheck; fi
	@for s in $(SERVICES); do if [ -f services/$$s/go.mod ]; then (cd services/$$s && go vet ./...); fi; done
	@if [ -f $(MOBILE)/pubspec.yaml ]; then cd $(MOBILE) && flutter analyze; fi

test: ## Run all test suites that exist
	@if [ -f $(API)/composer.json ]; then cd $(API) && vendor/bin/pest; fi
	@if [ -f $(WEB)/package.json ]; then cd $(WEB) && pnpm test; fi
	@if [ -f $(LANDING)/package.json ]; then cd $(LANDING) && pnpm test; fi
	@for s in $(SERVICES); do if [ -f services/$$s/go.mod ]; then (cd services/$$s && go test -race ./...); fi; done
	@if [ -f $(MOBILE)/pubspec.yaml ]; then cd $(MOBILE) && flutter test; fi

fmt: ## Format code
	@if [ -f $(API)/composer.json ]; then cd $(API) && vendor/bin/pint; fi
	@if [ -f $(WEB)/package.json ]; then cd $(WEB) && pnpm format; fi
	@if [ -f $(LANDING)/package.json ]; then cd $(LANDING) && pnpm format; fi
	@for s in $(SERVICES); do if [ -f services/$$s/go.mod ]; then (cd services/$$s && gofmt -w .); fi; done
	@if [ -f $(MOBILE)/pubspec.yaml ]; then cd $(MOBILE) && dart format .; fi

.PHONY: help up down reset ps logs migrate landing scaffold-api scaffold-web scaffold-mobile module lint test fmt
