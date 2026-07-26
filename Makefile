.PHONY: help install test coverage start stop restart db-reset logs

help: ## Show this help message
	@echo 'Usage: make [target]'
	@echo ''
	@echo 'Available targets:'
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  %-15s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

install: ## Install dependencies
	composer install

test: ## Run tests
	vendor/bin/phpunit --testdox

coverage: ## Generate code coverage report
	vendor/bin/phpunit --coverage-html coverage --coverage-text

phpstan: ## Run static analysis
	vendor/bin/phpstan analyse src --level=8

cs-check: ## Check code style
	vendor/bin/phpcs --standard=PSR12 src

cs-fix: ## Fix code style
	vendor/bin/phpcbf --standard=PSR12 src

start: ## Start Docker containers
	docker-compose up -d
	@echo "API available at http://localhost:8080"
	@echo "PHPMyAdmin available at http://localhost:8081"
	@echo "Waiting for services to be ready..."
	@sleep 5

stop: ## Stop Docker containers
	docker-compose down

restart: ## Restart Docker containers
	docker-compose restart

build: ## Rebuild Docker containers
	docker-compose build --no-cache

logs: ## Show Docker logs
	docker-compose logs -f

logs-php: ## Show PHP-FPM logs
	docker-compose logs -f php

logs-caddy: ## Show Caddy logs
	docker-compose logs -f caddy

logs-db: ## Show Database logs
	docker-compose logs -f db

db-reset: ## Reset database
	docker-compose exec db mysql -uroot -psecret betting_game < database/schema.sql

db-shell: ## Open database shell
	docker-compose exec db mysql -uroot -psecret betting_game

php-shell: ## Open PHP container shell
	docker-compose exec php sh

caddy-shell: ## Open Caddy container shell
	docker-compose exec caddy sh

composer-install: ## Install composer dependencies in container
	docker-compose exec php composer install

composer-update: ## Update composer dependencies in container
	docker-compose exec php composer update

test-docker: ## Run tests inside Docker container
	docker-compose exec php vendor/bin/phpunit --testdox

all-tests: ## Run all quality checks
	@echo "Running PHPStan..."
	@make phpstan
	@echo "\nRunning Code Style Check..."
	@make cs-check
	@echo "\nRunning Tests..."
	@make test

quality: all-tests ## Alias for all-tests

clean: ## Clean up containers and volumes
	docker-compose down -v
	rm -rf vendor/ coverage/

fresh: clean start ## Fresh install (clean + start)
	@make composer-install

fix-caddy: ## Fix Caddy configuration issues
	@echo "🔧 Fixing Caddy..."
	@bash fix-caddy.sh

fix-php-fpm: ## Fix PHP-FPM configuration issues
	@echo "🔧 Fixing PHP-FPM..."
	@bash fix-php-fpm.sh

fix-all: ## Fix all configuration issues
	@echo "🔧 Fixing all services..."
	@bash fix-php-fpm.sh
	@bash fix-caddy.sh
