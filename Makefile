.PHONY: up down test lint lint-fix analyse check install kphp-check

# Start development environment
up:
	docker compose up -d

# Stop development environment
down:
	docker compose down

# Install composer dependencies (inside Docker)
install:
	docker compose run --rm composer install

# Run unit tests (inside Docker)
test:
	docker compose run --rm php vendor/bin/phpunit --colors=always

# Run linter check (inside Docker)
lint:
	docker compose run --rm php vendor/bin/php-cs-fixer fix --dry-run --diff --allow-risky=yes

# Run linter and auto-fix (inside Docker)
lint-fix:
	docker compose run --rm php vendor/bin/php-cs-fixer fix --allow-risky=yes

# Run static analysis (inside Docker)
analyse:
	docker compose run --rm php vendor/bin/phpstan analyse --memory-limit=256M

# Run all checks (lint + analyse + test)
check: lint analyse test

# Build KPHP binary + PHAR and verify both succeed
# Requires pre-installed vendor/ (run `make install` first)
kphp-check:
	docker compose run --rm composer install --no-dev --no-progress --prefer-dist --optimize-autoloader --no-interaction
	docker build -f Dockerfile.check -t lphenom-queue-check .
	@echo "=== kphp-check: ALL STAGES PASSED ==="


