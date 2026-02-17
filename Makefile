.PHONY: lint lint-php lint-js format format-check lint-fix test test-php build dev clean package sign appstore help

app_name := synaplan_integration
cert_dir := $(HOME)/.nextcloud/certificates
build_dir := release-work/build

## Quality

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

## Testing

test: test-php ## Run all tests

test-php: ## Run PHPUnit tests
	composer run test

## Build

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

## Help

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}'
