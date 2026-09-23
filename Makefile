.PHONY: up down logs migrate seed test lint quality shell-api shell-frontend build

COMPOSE = docker compose

up:
	$(COMPOSE) up -d --build

down:
	$(COMPOSE) down

logs:
	$(COMPOSE) logs -f

migrate:
	$(COMPOSE) exec api php bin/console doctrine:migrations:migrate --no-interaction

seed:
	$(COMPOSE) exec api php bin/console app:seed-identity
	$(COMPOSE) exec api php bin/console app:seed-catalog
	$(COMPOSE) exec api php bin/console app:seed-inventory
	$(COMPOSE) exec api php bin/console app:seed-production-config

test: test-backend test-frontend

test-backend:
	$(COMPOSE) exec api php bin/phpunit

test-frontend:
	cd frontend && npm run test

lint: lint-backend lint-frontend

lint-backend:
	$(COMPOSE) exec api composer cs-check
	$(COMPOSE) exec api composer phpstan

lint-frontend:
	cd frontend && npm run lint && npm run typecheck

quality:
	$(COMPOSE) exec api composer quality

build:
	$(COMPOSE) build
	cd frontend && npm run build

shell-api:
	$(COMPOSE) exec api sh

shell-frontend:
	$(COMPOSE) exec frontend sh
