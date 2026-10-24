.PHONY: ci-local lint lint-php lint-js format format-check lint-fix stan typecheck test test-php test-unit-js test-contract openapi l10n build dev clean package sign appstore dev-up dev-down dev-reset dev-occ dev-logs dev-shell e2e-up test-e2e e2e-down help

app_name := synaplan_integration
cert_dir := $(HOME)/.nextcloud/certificates
build_dir := release-work/build

## Quality

ci-local: lint stan test test-contract typecheck test-unit-js package ## Every CI job except e2e — run before each push

lint: lint-php lint-js format-check ## Run all linters + format check

lint-php: ## PHP code style check (PSR-12)
	composer run lint

lint-js: ## JavaScript/TypeScript lint
	npm run lint

format-check: ## Check frontend code formatting (Prettier)
	npm run format:check

format: ## Auto-format frontend code (Prettier)
	npm run format

lint-fix: ## Auto-fix lint issues
	composer run lint:fix
	npm run lint:fix
	npm run format

stan: ## PHPStan (level in phpstan.neon)
	composer run stan

typecheck: ## vue-tsc type check
	npm run check:types

## Testing

test: test-php ## Run PHPUnit tests

test-php: ## Run PHPUnit tests
	composer run test

test-unit-js: ## Run Vitest unit tests
	npm run test:unit

openapi: ## Dump the pinned Synaplan's OpenAPI spec and routes (SYNAPLAN_SRC_CONTAINER=synaplan-backend: the dev stack)
	scripts/synaplan-openapi.sh

test-contract: openapi ## Check every call the app makes against the pinned Synaplan
	composer run test:contract

## Build

l10n: ## Write l10n/<locale>.js from l10n/<locale>.json after editing translations
	node scripts/l10n-js.mjs

build: ## Build frontend for production
	npm run build

dev: ## Start frontend dev mode (watch)
	npm run dev

## Release

clean: ## Remove build artifacts
	rm -rf js/ css/ node_modules/ vendor/ .phpunit.cache/ $(build_dir)
	rm -f $(app_name).tar.gz

package: build ## Create release tarball (unsigned)
	rm -rf $(build_dir)
	mkdir -p $(build_dir)/$(app_name)
	cp -r appinfo img js lib templates $(build_dir)/$(app_name)/
	test -d l10n && cp -r l10n $(build_dir)/$(app_name)/ || true
	cp CHANGELOG.md LICENSE README.md $(build_dir)/$(app_name)/ 2>/dev/null || true
	cd $(build_dir) && tar -czf ../../$(app_name).tar.gz $(app_name)
	rm -rf $(build_dir)
	@echo ""
	@echo "Created: $(app_name).tar.gz"
	@tar -tzf $(app_name).tar.gz | head -10
	@echo "..."

sign: package ## Create signed release tarball (requires certificate)
	@if [ ! -f $(cert_dir)/$(app_name).key ]; then \
		echo "ERROR: Private key not found at $(cert_dir)/$(app_name).key"; \
		echo "See release-work/RELEASE-PLAN.md Phase 1 for setup."; \
		exit 1; \
	fi
	@echo "Signing tarball..."
	openssl dgst -sha512 \
		-sign $(cert_dir)/$(app_name).key \
		$(app_name).tar.gz | openssl base64 > $(app_name).tar.gz.sig
	@echo "Signature written to $(app_name).tar.gz.sig"
	@echo ""
	@echo "Upload to App Store:"
	@echo "  Tarball:   $(app_name).tar.gz"
	@echo "  Signature: $$(cat $(app_name).tar.gz.sig)"

appstore: sign ## Build, sign, and prepare for App Store upload
	@echo ""
	@echo "=== Ready for App Store ==="
	@echo "1. Upload $(app_name).tar.gz to a GitHub Release"
	@echo "2. Go to https://apps.nextcloud.com/developer/apps/releases/new"
	@echo "3. Paste the download URL and signature above"
	@echo ""

## Local Synaplan stack (WSL)
# NC_IMAGE picks the Nextcloud version (default: the top of the supported range);
# NC_OIDC=0 skips organisation login. Both apply to a fresh install (dev-reset).

COMPOSE_DEV := docker compose -f docker-compose.dev.yml
NC_EXEC := $(COMPOSE_DEV) exec -T -u www-data nextcloud

# A Windows docker.exe called from WSL sees only the variables WSLENV names.
export WSLENV := $(if $(WSLENV),$(WSLENV):)NC_IMAGE:NC_OIDC

# The gitignored copy follows the example: created when missing, and refreshed
# when the example is newer, with the old copy kept as docker-compose.dev.yml.bak.
docker-compose.dev.yml: docker-compose.dev.yml.example
	@if [ -f $@ ]; then \
		cp $@ $@.bak; \
		echo "docker-compose.dev.yml was older than the example: refreshed, your copy is docker-compose.dev.yml.bak."; \
		echo "An existing install keeps its data and its old setup. 'make dev-reset dev-up' deletes the local"; \
		echo "Nextcloud data and installs the new setup."; \
	fi
	cp $< $@

dev-up: docker-compose.dev.yml ## Start Nextcloud on :8081 with MariaDB, cron and the test people (joins the Synaplan network)
	@if [ ! -f js/synaplan_integration-files-init.mjs ]; then \
		echo "Building the frontend (js/ is missing)…"; \
		test -d node_modules || npm install; \
		$(MAKE) build; \
	fi
	$(COMPOSE_DEV) up -d --wait
	$(NC_EXEC) sh /mnt/synaplan_integration/docker/dev-seed.sh
	@echo ""
	@echo "Nextcloud:  http://localhost:8081  (admin / admin; ada, bob, carol / <name>-dev-pass)"
	@echo "Synaplan:   http://localhost:5173  (from the Nextcloud containers: http://backend)"

dev-down: docker-compose.dev.yml ## Stop local Nextcloud (keeps the volumes)
	$(COMPOSE_DEV) down

dev-reset: docker-compose.dev.yml ## Remove the Nextcloud and database volumes — destroys local data; needed to change the major version
	$(COMPOSE_DEV) down -v

dev-occ: docker-compose.dev.yml ## Run occ in the dev container, e.g. make dev-occ CMD="user:list"
	$(NC_EXEC) php occ $(CMD)

dev-logs: docker-compose.dev.yml ## Follow the Nextcloud container logs
	$(COMPOSE_DEV) logs -f nextcloud

dev-shell: docker-compose.dev.yml ## Open a shell in the Nextcloud container as www-data
	$(COMPOSE_DEV) exec -u www-data nextcloud bash

## e2e journeys (Playwright)
# A Nextcloud from docker-compose.ci.yml on E2E_NC_PORT next to the Synaplan
# test stack of the synaplan checkout in SYNAPLAN_DIR, run from the Synaplan
# image pinned in CI. E2E_SYNAPLAN_FILES adds compose files to the Synaplan
# stack, e.g. one that drops a host port that is already taken.

SYNAPLAN_DIR ?= ../synaplan
E2E_NC_PORT ?= 8091
export APP_IMAGE ?= ghcr.io/metadist/synaplan@$(shell grep -oE 'SYNAPLAN_IMAGE_DIGEST:[[:space:]]+sha256:[a-f0-9]+' .github/workflows/ci.yml | awk '{print $$2}')
export NC_PORT := $(E2E_NC_PORT)
export WSLENV := $(WSLENV):APP_IMAGE:NC_PORT
COMPOSE_E2E := docker compose -p synaplan-nextcloud-e2e -f docker-compose.ci.yml
COMPOSE_E2E_SYNAPLAN := docker compose -p synaplan-nextcloud-e2e-synaplan \
	-f $(SYNAPLAN_DIR)/docker-compose.test.yml \
	-f $(SYNAPLAN_DIR)/docker-compose.test.ci.yml \
	-f tests/e2e/synaplan-test-stack.yml $(foreach file,$(E2E_SYNAPLAN_FILES),-f $(file))

e2e-up: build ## Start the e2e stacks: Synaplan test stack on :8001, Nextcloud (NC_IMAGE) on E2E_NC_PORT
	docker pull -q $(APP_IMAGE)
	$(COMPOSE_E2E_SYNAPLAN) up -d --wait
	$(COMPOSE_E2E) up -d --wait
	$(COMPOSE_E2E) exec -T -u www-data nextcloud sh /mnt/synaplan_integration/docker/dev-seed.sh

test-e2e: ## Run the e2e journeys against the stacks of e2e-up
	NC_URL=http://localhost:$(E2E_NC_PORT) npx playwright test

e2e-down: ## Stop the e2e stacks and delete their data
	$(COMPOSE_E2E) down -v
	$(COMPOSE_E2E_SYNAPLAN) down -v

## Help

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}'
